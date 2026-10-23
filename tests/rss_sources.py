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
 def connect(url,csrf=None,global_default=False,c=None):
  data={'action':'source','rss_url':url,'csrf':csrf or token(c)}
  if global_default:data['site_default']='1'
  return get('rss.html',urllib.parse.urlencode(data).encode(),{'Content-Type':'application/x-www-form-urlencoded'},c)
 try:
  for _ in range(50):
   try:status,html,_=get('rss.html');break
   except urllib.error.URLError:time.sleep(.1)
  assert status==200 and 'Адрес RSS-ленты' in html and 'Подключить ленту' in html
  assert connect(A,csrf='wrong')[0]==403
  assert connect(A,global_default=True)[0]==401
  status,html,url=connect(A);assert status==200 and 'rss_url=' in url and 'Источник А' in html and 'alpha' in html and 'bravo' not in html;pages.append(html)
  assert 'Источник А' in get('rss.html')[1]
  status,html,_=connect(B);assert status==200 and 'Источник Б' in html and 'bravo' in html and 'alpha' not in html;pages.append(html)
  status,html,_=connect(C);assert status==422 and 'Прежний источник сохранён' in html and 'Источник Б' in html
  assert 'Источник Б' in get('rss.html')[1]
  assert connect('http://127.0.0.1/rss')[0]==422
  status,raw,_=get('feed.php?'+urllib.parse.urlencode({'source':'external','rss_url':A}));items=ET.fromstring(raw).findall('./channel/item');assert len(items)==1 and items[0].findtext('title')=='alpha' and items[0].find('source').attrib['url']==A
  admin=client();status,raw,_=get('api/news.php?action=status',c=admin);csrf=json.loads(raw)['csrf']
  status,raw,_=get('api/news.php?action=login',json.dumps({'password':password}).encode(),{'Content-Type':'application/json','X-CSRF-Token':csrf},admin);assert status==200
  assert connect(B,global_default=True,c=admin)[0]==200 and run('echo rss_default_url();')==B
  fresh=client();assert 'Источник Б' in get('rss.html',c=fresh)[1]
  assert 'name="site_default"' in get('rss.html',c=admin)[1] and 'name="site_default"' not in get('rss.html',c=fresh)[1]
  own=json.loads(run('echo json_encode(exchange_own(db()));'));selected=own[0]
  status,detail,_=get('rss.html?'+urllib.parse.urlencode({'item':selected['id'],'source':'own'}));assert status==200 and selected['description'] in detail and detail.count('id="news-'+selected['id']+'"')==1;pages.append(detail)
  assert get('rss.html?item=missing')[0]==404
  for old in ['services/news.html','services/news.php']:
   status,html,url=get(old);assert status==200 and '/rss.html' in url and 'Внешняя RSS-лента' in html
  assert get('rss.html?rss_url[]=x')[0]==422
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
print('OK: URL switching/session; admin default/CSRF; separate caches/failure; DNS/IP pinning/redirects; source exports; one news menu; details and legacy redirects.')
