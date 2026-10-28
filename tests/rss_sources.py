"""Isolated RSS source switching, public-address transport and unified news tests."""
import os,pathlib,subprocess,tempfile,socket,time,json,http.cookiejar,urllib.request,urllib.error,urllib.parse,re,xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[1];php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[v for n in ['pdo','pdo_sqlite','dom'] for v in ['-d',f'extension={ext}/{n}.so']]
A='https://one.example.net/news.xml';B='https://two.example.net/rss.xml';C='https://bad.example.net/rss.xml'
def fixture(title,text):return f'<rss version="2.0"><channel><title>{title}</title><link>https://example.net/</link><description>Новости</description><item><title>{text}</title><link>https://example.net/{text}</link><guid>{text}</guid><pubDate>Fri, 23 Oct 2026 10:00:00 GMT</pubDate></item></channel></rss>'
with tempfile.TemporaryDirectory() as td,tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/news.sqlite',MIXLAB_RSS_CACHE_DIR=td+'/rss')
 def run(code,data=''):
  p=subprocess.run(php+['-r',"require 'server/exchange.php';"+code],cwd=ROOT,env=env,input=data,text=True,capture_output=True,check=True);return p.stdout
 for url in ['file:///etc/passwd','ftp://example.net/a','http://localhost/rss','http://127.0.0.1/x','http://169.254.169.254/latest','http://10.0.0.1/x','http://[::1]/x','https://user:pass@example.net/rss','https://example.net:8443/x','https://example.net/\r\nHost: localhost','https://2130706433/x','https://example.net\\@127.0.0.1/x']:
  assert run('try{rss_validate_url(stream_get_contents(STDIN));echo "BAD";}catch(InvalidArgumentException $e){echo "REJECTED";}',url)=='REJECTED',url
 for ip in ['0.0.0.0','127.0.0.1','10.1.2.3','172.16.1.1','192.168.1.1','100.64.0.1','169.254.1.1','192.0.2.1','198.18.0.1','224.0.0.1','240.0.0.1','::ffff:127.0.0.1']:
  assert run('echo rss_public_ipv4(trim(stream_get_contents(STDIN)))?"BAD":"REJECTED";',ip)=='REJECTED',ip
 assert run('echo rss_public_ipv4("8.8.8.8")?"PUBLIC":"BAD";')=='PUBLIC'
 assert run('try{rss_resolve_ip("example.net",fn($h)=>["8.8.8.8","127.0.0.1"]);echo "BAD";}catch(InvalidArgumentException $e){echo "REJECTED";}')=='REJECTED'
 out=json.loads(run('''$dns=[];$req=[];$raw=stream_get_contents(STDIN);$out=rss_download("https://first.example.net/a",function($h)use(&$dns){$dns[]=$h;return ["8.8.8.8"];},function($u,$ip,$t)use(&$req,$raw){$req[]=[$u,$ip];return count($req)===1?["status"=>302,"location"=>"https://second.example.net/feed","body"=>""]:["status"=>200,"location"=>"","body"=>$raw];});echo json_encode([$dns,$req,$out]);''',fixture('A','first')))
 assert out[0]==['first.example.net','second.example.net'] and all(x[1]=='8.8.8.8' for x in out[1]) and out[2].startswith('<rss')
 assert run('''$n=0;try{rss_download("https://example.net/rss",fn($h)=>["8.8.8.8"],function()use(&$n){$n++;return ["status"=>302,"location"=>"http://169.254.169.254/x","body"=>""];});echo "BAD";}catch(InvalidArgumentException $e){echo "REJECTED:".$n;}''')=='REJECTED:1'
 assert run('''try{rss_download("https://example.net/rss",fn($h)=>["8.8.8.8"],fn()=>["status"=>200,"location"=>"","body"=>str_repeat("x",RSS_MAX_BYTES+1)]);echo "BAD";}catch(RuntimeException $e){echo "REJECTED";}''')=='REJECTED'
 for url,title,text in [(A,'Источник А','alpha'),(B,'Источник Б','bravo')]:run('$v=json_decode(stream_get_contents(STDIN),true);rss_external(true,fn()=>$v[1],$v[0]);',json.dumps([url,fixture(title,text)]))
 run('$url=stream_get_contents(STDIN);rss_external(true,function(){throw new RuntimeException("offline");},$url);',C)
 path=pathlib.Path(run('echo rss_cache_file(stream_get_contents(STDIN));',A));state=json.loads(path.read_text());state['checked_at']='2000-01-01T00:00:00Z';path.write_text(json.dumps(state))
 failed=json.loads(run('echo json_encode(rss_external(true,fn()=>"<html/>",stream_get_contents(STDIN)));',A));assert failed['items'][0]['title']=='alpha' and failed['error']
 failed['checked_at']='2000-01-01T00:00:00Z';path.write_text(json.dumps(failed));run('$v=json_decode(stream_get_contents(STDIN),true);rss_external(true,fn()=>$v[1],$v[0]);',json.dumps([A,fixture('Источник А','alpha')]))
 run('$raw=stream_get_contents(STDIN);rss_external(true,fn()=>$raw,RSS_SOURCE_URL);',fixture('Начальная лента','default'))
 password='Isolated-RSS-source-test';env['MIXLAB_ADMIN_PASSWORD_HASH']=subprocess.check_output(php+['-r','echo password_hash(trim(fgets(STDIN)),PASSWORD_DEFAULT);'],input=password,text=True)
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}/'
 proc=subprocess.Popen(php+['-d',f'session.save_path={td}','-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 visitor=client();pages=[]
 def get(path,data=None,headers=None,c=None):
  req=urllib.request.Request(base+path,data=data,headers=headers or {})
  try:
   with (c or visitor).open(req,timeout=10) as res:return res.status,res.read().decode(),res.geturl()
  except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.geturl()
 def token(c=None):return re.search(r'name="csrf" value="([a-f0-9]+)"',get('rss.html',c=c)[1]).group(1)
 def post(action,url='',password_value=None,csrf=None,c=None):
  data={'action':action,'rss_url':url,'csrf':csrf or token(c)}
  if password_value is not None:data['password']=password_value
  return get('rss.html',urllib.parse.urlencode(data).encode(),{'Content-Type':'application/x-www-form-urlencoded'},c)
 def api(action,body=None,c=None,csrf=None):
  headers={} if body is None else {'Content-Type':'application/json','X-CSRF-Token':csrf or ''}
  status,raw,_=get('api/news.php?action='+action,None if body is None else json.dumps(body).encode(),headers,c)
  return status,json.loads(raw)
 try:
  for _ in range(50):
   try:status,html,_=get('rss.html');break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and 'Адрес RSS-ленты' in html and 'Пароль администратора' in html and 'до удаления администратором' in html
  pages.append(html)
  assert post('source',A,csrf='wrong')[0]==403
  assert post('source',A)[0]==401
  assert post('remove_source',A)[0]==401
  assert get('rss.html?'+urllib.parse.urlencode({'rss_url':B}))[0]==422
  assert run('echo count(rss_sources());')=='1'
  admin=client()
  status,html,url=post('source',A,password_value=password,c=admin)
  assert status==200 and 'Источник А' in html and 'alpha' in html and 'default' in html and 'Удалить источник' in html;pages.append(html)
  assert run('echo count(rss_sources());')=='2'
  assert post('source',A,c=admin)[0]==422
  assert run('echo count(rss_sources());')=='2'
  status,html,_=post('source',B,c=admin);assert status==200 and all(x in html for x in ['alpha','bravo','default']);pages.append(html)
  fresh=client();public=get('rss.html',c=fresh)[1];assert 'alpha' in public and 'bravo' in public and 'Удалить источник' not in public
  assert post('source',C,c=admin)[0]==422 and run('echo count(rss_sources());')=='3'
  assert post('source','http://127.0.0.1/rss',c=admin)[0]==422
  assert post('source',A,csrf='old-session',c=admin)[0]==403
  status,raw,_=get('feed.php?source=external');items=ET.fromstring(raw).findall('./channel/item');assert len(items)==3
  status,raw,_=get('feed.php?'+urllib.parse.urlencode({'source':'external','rss_url':A}));items=ET.fromstring(raw).findall('./channel/item');assert len(items)==1 and items[0].findtext('title')=='alpha' and items[0].find('source').attrib['url']==A
  assert 'bravo' not in get('rss.html?'+urllib.parse.urlencode({'rss_url':A}))[1].split('id="news-list-title"')[1]
  # Duplicate article links across feeds appear only once in the combined list.
  run('rss_external(true,fn()=>stream_get_contents(STDIN),"https://duplicate.example.net/rss");rss_add_source("https://duplicate.example.net/rss","Дубль");',fixture('Дубль','alpha'))
  assert len(ET.fromstring(get('feed.php?source=external')[1]).findall('./channel/item'))==3
  run('rss_remove_source("https://duplicate.example.net/rss");')
  status,html,_=post('remove_source',B,c=admin);assert status==200 and 'bravo' not in html and 'Источник А' in html
  assert post('remove_source',A,c=fresh)[0]==401
  assert get('feed.php?'+urllib.parse.urlencode({'source':'external','rss_url':B}))[0]==422
  assert post('logout',c=admin)[0]==200
  assert post('source',B,c=admin)[0]==401
  assert post('login',password_value='wrong',c=admin)[0]==401
  assert post('login',password_value=password,c=admin)[0]==200
  # A second PHP process and new visitors read the same saved registry.
  assert json.loads(run('echo json_encode(array_column(rss_sources(),"url"));'))==['https://www.soundonsound.com/latest_news.xml',A]
  assert 'alpha' in get('rss.html',c=client())[1]
  # The existing API uses the same administrator session; creation and deletion need CSRF.
  csrf=api('status',c=admin)[1]['csrf'];article={'title':'Временная публикация','body':'Проверка создания и удаления','category':'Обновления','author_id':'vlad-kalinichenko'}
  status,data=api('create',article,admin,csrf);assert status==201;created=data['item']['id']
  assert api('delete',{'id':created},admin,'wrong')[0]==403
  assert api('delete',{'id':created},fresh,api('status',c=fresh)[1]['csrf'])[0]==401
  assert created in get('feed.php')[1]
  assert api('delete',{'id':created},admin,csrf)[0]==200
  assert all(x['id']!=created for x in api('list')[1]['items']) and created not in get('feed.php')[1]
  assert get('rss.html?item='+created)[0]==404
  assert api('delete',{'id':created},admin,csrf)[0]==404
  assert api('delete',{'id':"x' OR 1=1"},admin,csrf)[0]==422
  # The RSS section has its own real HTML publication form, with no JavaScript dependency.
  poster=client();before=len(api('list')[1]['items'])
  def publish(fields,client_used=poster,csrf_value=None):
   data={'action':'publish','csrf':csrf_value or token(client_used),**fields}
   return get('rss.html',urllib.parse.urlencode(data).encode(),{'Content-Type':'application/x-www-form-urlencoded'},client_used)
  draft={'title':'Новость из RSS-раздела','body':'Прямой ввод & <script>проверка</script>','category':'Обновления','author_id':'vlad-kalinichenko'}
  assert 'id="rss-news-form"' in get('rss.html')[1]
  assert publish(draft)[0]==401 and len(api('list')[1]['items'])==before
  assert publish(dict(draft,password=password),csrf_value='wrong')[0]==403
  assert publish(dict(draft,password='wrong'))[0]==401
  assert publish(dict(draft,password=password,author_id='missing'))[0]==422
  assert publish(dict(draft,title=''))[0]==422
  status,page,location=publish(draft);assert status==200 and '#news-' in location
  created_item=next(x for x in api('list')[1]['items'] if x['title']==draft['title'])
  assert created_item['body']==draft['body'] and created_item['author_id']=='vlad-kalinichenko'
  assert page.count('id="news-'+created_item['id']+'"')==1 and 'alpha' in page
  assert '&lt;script&gt;проверка&lt;/script&gt;' in page and '<script>проверка</script>' not in page
  assert 'Опубликовать новость' in page and 'id="own-password"' not in page;pages.append(page)
  fresh_page=get('rss.html',c=client())[1];assert draft['title'] in fresh_page and 'id="own-password"' in fresh_page
  assert len(api('list')[1]['items'])==before+1
  get('rss.html',c=poster);assert len(api('list')[1]['items'])==before+1
  csrf_poster=api('status',c=poster)[1]['csrf'];assert api('delete',{'id':created_item['id']},poster,csrf_poster)[0]==200
  own=json.loads(run('echo json_encode(exchange_own(db()));'));selected=own[0]
  status,detail,_=get('rss.html?'+urllib.parse.urlencode({'item':selected['id'],'source':'own'}));assert status==200 and selected['description'] in detail and detail.count('id="news-'+selected['id']+'"')==1;pages.append(detail)
  assert get('rss.html?item=missing')[0]==404
  for old in ['services/news.html','services/news.php']:
   status,html,url=get(old);assert status==200 and '/rss.html' in url and 'Внешние RSS-ленты' in html
  assert get('rss.html?rss_url[]=x')[0]==422
  for url in [A,'https://www.soundonsound.com/latest_news.xml']:assert post('remove_source',url,c=admin)[0]==200
  assert json.loads(run('echo json_encode(rss_sources());'))==[]
  assert 'Внешние источники пока не подключены' in get('rss.html',c=client())[1]
  assert not ET.fromstring(get('feed.php?source=external')[1]).findall('./channel/item')
  # Restart with the same private storage: neither removed sources nor deleted news reappear.
  proc.terminate();proc.wait(timeout=5)
  proc=subprocess.Popen(php+['-d',f'session.save_path={td}','-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
  for _ in range(50):
   try:status,html,_=get('rss.html',c=client());break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and 'Внешние источники пока не подключены' in html and created not in get('feed.php')[1];pages.append(html)
  if os.environ.get('VNU_JAR'):
   files=[]
   for i,html in enumerate(pages):
    f=pathlib.Path(td)/f'page-{i}.html';f.write_text(html);files.append(str(f))
   subprocess.run(['java','-jar',os.environ['VNU_JAR'],'--format','json','--stdout']+files,check=True)
 finally:proc.terminate();proc.wait(timeout=5)
for path in ROOT.rglob('*.html'):
 if path.name=='nav.html':continue
 nav=path.read_text().split('aria-label="Основное меню"',1)[1].split('</nav>',1)[0]
 assert nav.count('>Новости</a>')==1 and 'RSS-лента</a>' not in nav and 'services/news.html' not in nav,path
assert 'href="news.html"><span class="icon">01' in (ROOT/'admin/index.html').read_text()
assert 'publication-management' in (ROOT/'admin/news.html').read_text() and 'Удалить публикацию' in (ROOT/'assets/news-admin.js').read_text()
print('OK: administrator-only persistent RSS registry; multi-feed merge/dedup/filter/export; removal/restart/empty registry; shared login/CSRF; RSS HTML form publication/auth/CSRF/validation/PRG; publication create/delete; DNS/IP pinning; cache failure preservation; admin navigation and HTML.')
