"""Integration checks against real PHP/PDO and independent cookie jars."""
import http.cookiejar,json,os,pathlib,subprocess,tempfile,time,urllib.request,urllib.error,socket
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[x for name in ['pdo','pdo_sqlite','dom','xsl'] for x in ['-d',f'extension={ext}/{name}.so']]
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
with tempfile.TemporaryDirectory() as td,tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/test.sqlite')
 password='Only-for-isolated-test-2026'
 result=subprocess.run(php+['-r','echo password_hash(trim(fgets(STDIN)),PASSWORD_DEFAULT);'],input=password,text=True,capture_output=True,check=True)
 env['MIXLAB_ADMIN_PASSWORD_HASH']=result.stdout
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}/'
 def start():return subprocess.Popen(php+['-d',f'session.save_path={td}','-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 proc=start();a,b,admin=client(),client(),client()
 def request(c,action,body=None,api='community',csrf=True):
  headers={}
  if body is not None:
   if csrf:headers['X-CSRF-Token']=request(c,'status',api=api)[1]['csrf']
   headers['Content-Type']='application/json'
  req=urllib.request.Request(base+f'api/{api}.php?action='+action,data=None if body is None else json.dumps(body).encode(),headers=headers)
  try:
   with c.open(req,timeout=5) as r:return r.status,json.load(r)
  except urllib.error.HTTPError as e:return e.code,json.load(e)
 try:
  for _ in range(50):
   try:request(a,'status');break
   except urllib.error.URLError:time.sleep(.1)
  else:raise AssertionError('PHP did not start')
  r=request(a,'guest_create',{'author':'A','body':'test'},csrf=False);assert r[0]==403,r
  assert request(a,'guest_create',{'author':'','body':'test'})[0]==422
  status,d=request(a,'guest_create',{'author':'Первый посетитель','body':'<img src=x onerror=alert(1)>'});assert status==201;guest=d['id']
  assert any(x['id']==guest for x in request(b,'guestbook')[1]['items'])
  status,d=request(a,'topic_create',{'author':'A','title':'Сведение вокала','body':'Как настроить громкость?'});assert status==201;topic=d['id']
  assert request(b,'reply_create',{'author':'B','parent_id':topic,'body':'Начни с баланса.'})[0]==201
  assert request(a,'forum')[1]['items'][0]['replies'][0]['body']=='Начни с баланса.'
  assert request(b,'reply_create',{'author':'B','parent_id':'missing','body':'test'})[0]==404
  request(a,'rate',{'score':5});d=request(b,'rate',{'score':3})[1];assert d['votes']==2 and d['average']==4 and d['mine']==3
  d=request(a,'rate',{'score':1})[1];assert d['votes']==2 and d['average']==2
  d=request(b,'rate',{'score':0})[1];assert d['votes']==1 and d['average']==1
  assert request(b,'rate',{'score':6})[0]==422
  for c,page in [(a,'index.html'),(a,'eq.html'),(b,'index.html')]:assert request(c,'visit',{'page':page})[0]==200
  d=request(b,'stats')[1];assert d['total']==3 and d['visitors']==2 and d['today']==3 and d['visitors_today']==2
  assert request(a,'visit',{'page':'../../etc/passwd'})[0]==422
  assert request(a,'moderation')[0]==401
  assert request(a,'visibility',{'id':guest,'hidden':True})[0]==401
  assert request(admin,'login',{'password':password},api='news')[0]==200
  assert request(admin,'moderation')[0]==200
  request(admin,'visibility',{'id':guest,'hidden':True});assert not request(b,'guestbook')[1]['items']
  request(admin,'visibility',{'id':guest,'hidden':False});assert len(request(b,'guestbook')[1]['items'])==1
  request(admin,'words',{'words':['ЗАПРЕЩЁННОЕ']});assert request(b,'guest_create',{'author':'B','body':'запрещённое выражение'})[0]==422
  request(admin,'words',{'words':[]});request(admin,'block',{'id':guest});assert request(b,'guest_create',{'author':'B','body':'test'})[0]==403
  token=request(admin,'moderation')[1]['blocks'][0];request(admin,'unblock',{'host_token':token});assert request(b,'guest_create',{'author':'B','body':'Новая запись'})[0]==201
  assert request(b,'create',{'title':'Новость','body':'test'},api='news')[0]==401
  status,d=request(admin,'create',{'title':'Новость','body':'Общая публикация'},api='news');assert status==201;news=d['item']['id']
  assert any(x['id']==news for x in request(b,'list',api='news')[1]['items'])
  with b.open(base+'services/news.html') as response:
   html=response.read().decode();assert f'id="news-{news}"' in html and 'Общая публикация' in html
  with b.open(base+'services/news.php') as response:assert 'Общая публикация' in response.read().decode()
  proc.terminate();proc.wait(timeout=5);proc=start()
  for _ in range(50):
   try:assert any(x['id']==guest for x in request(client(),'guestbook')[1]['items']);break
   except urllib.error.URLError:time.sleep(.1)
  assert request(client(),'stats')[1]['total']==3
  assert request(client(),'rating')[1]['average']==1
  if os.environ.get('MIXLAB_UI_TEST'):
   subprocess.run(['node','tests/community-ui.cjs'],cwd=ROOT,env=dict(os.environ,MIXLAB_TEST_BASE=base),check=True)
  print('OK: shared guestbook, topics/replies, ratings, unique visitors, persistence across restart, admin authentication, moderation/restore, forbidden expressions, host blocks, CSRF, validation and shared news.')
 finally:
  proc.terminate();proc.wait(timeout=5)
  if __import__('sys').exc_info()[0]:log.seek(0);print(log.read().decode()[-4500:])
