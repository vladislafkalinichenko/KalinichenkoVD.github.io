"""Every own/RSS item is rendered once in one globally sorted HTML list."""
import os,pathlib,tempfile,subprocess,sqlite3,json,socket,time,urllib.request,urllib.error,urllib.parse,re,datetime,html
ROOT=pathlib.Path(__file__).resolve().parents[1];php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[x for n in ['pdo','pdo_sqlite','dom'] for x in ['-d',f'extension={ext}/{n}.so']]
with tempfile.TemporaryDirectory() as td,tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/news.sqlite',MIXLAB_RSS_CACHE_DIR=td+'/rss')
 def run(code,data=''):
  return subprocess.check_output(php+['-r',"require 'server/exchange.php';"+code],input=data,env=env,cwd=ROOT,text=True)
 run('db();');db=sqlite3.connect(td+'/news.sqlite');db.execute('DELETE FROM news')
 start=datetime.datetime(2026,10,24,10,tzinfo=datetime.timezone.utc)
 body='Полный текст '+('Длинная публикация. '*35)+'<script>alert(1)</script> КОНЕЦ ПУБЛИКАЦИИ'
 own=[];external=[]
 for i in range(23):
  # +03:00 and Z must sort by their actual moments, not lexicographic strings.
  stamp=(start+datetime.timedelta(minutes=2*i)).astimezone(datetime.timezone(datetime.timedelta(hours=3)) if i%2 else datetime.timezone.utc).isoformat()
  own.append((f'own-{i:02}',f'Внутренняя {i:02}',body if i==0 else f'Текст {i}',stamp,'Свой сайт','','Категория','vlad-kalinichenko'))
 db.executemany('INSERT INTO news(id,title,body,published_at,source,source_url,category,author_id) VALUES(?,?,?,?,?,?,?,?)',own);db.commit()
 for i in range(24):
  stamp=(start+datetime.timedelta(minutes=2*i+1)).strftime('%a, %d %b %Y %H:%M:%S GMT')
  external.append(f'<item><title>Внешняя {i:02}</title><link>https://example.org/article-{i}</link><guid>ext-{i}</guid><pubDate>{stamp}</pubDate></item>')
 fixture='<rss version="2.0"><channel><title>Внешний источник</title><link>https://example.org/</link><description>Тест</description>'+''.join(external)+'</channel></rss>'
 state=json.loads(run('$raw=stream_get_contents(STDIN);echo json_encode(rss_external(true,fn()=>$raw));',fixture));assert len(state['items'])==24
 # A previous 15-item snapshot is refreshed even during the normal TTL.
 path=pathlib.Path(run('echo rss_cache_file(RSS_SOURCE_URL);'));old=json.loads(path.read_text());old['items']=old['items'][:15];old.pop('parser_version');path.write_text(json.dumps(old))
 assert len(json.loads(run('$raw=stream_get_contents(STDIN);echo json_encode(rss_external(false,fn()=>$raw));',fixture))['items'])==24
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}/'
 proc=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 def get(path):
  try:
   with urllib.request.urlopen(base+path,timeout=10) as r:return r.status,r.read().decode(),r.geturl()
  except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.geturl()
 def cards(page):return re.findall(r'<article class="rss-item".*?</article>',page,re.S)
 def titles(page):return [html.unescape(re.search(r'<h3>(?:<a[^>]*>)?([^<]+)',c).group(1)) for c in cards(page)]
 try:
  for _ in range(50):
   try:status,page,_=get('rss.html');break
   except urllib.error.URLError:time.sleep(.1)
  pages=[]
  for sort in ['newest','oldest','title']:
   status,page,_=get('rss.html?sort='+sort);assert status==200
   expected=json.loads(run('echo json_encode(exchange_select(array_merge(exchange_own(db()),rss_external()["items"]),exchange_params(["sort"=>"'+sort+'"])),JSON_UNESCAPED_UNICODE);'))
   assert len(cards(page))==47 and titles(page)==[x['title'] for x in expected]
   assert 'rss-pagination' not in page and 'Страница 1 из' not in page and 'КОНЕЦ ПУБЛИКАЦИИ' in page
   assert '<script>alert(1)</script>' not in page and '&lt;script&gt;' in page
   dated=[datetime.datetime.fromisoformat(re.search(r'datetime="([^"]+)"',c).group(1).replace('Z','+00:00')).timestamp() for c in cards(page)]
   if sort!='title':assert dated==sorted(dated,reverse=sort=='newest')
   if sort=='newest':assert ['data-origin="external"' in c for c in cards(page)][:6]==[True,True,False,True,False,True]
   pages.append(page)
  assert len(cards(get('rss.html?page=99')[1]))==47
  assert len(cards(get('rss.html?item=own-00')[1]))==47
  assert get('rss.html?item=missing')[0]==404
  for old in ['services/news.html','services/news.php']:
   status,page,url=get(old);assert status==200 and url==base+'rss.html' and len(cards(page))==47
  assert len(cards(get('rss.html?source=own')[1]))==23
  assert len(cards(get('rss.html?source=external')[1]))==24
  assert len(cards(get('rss.html?q='+urllib.parse.quote('Внешняя 17'))[1]))==1
  if os.environ.get('VNU_JAR'):
   files=[]
   for i,page in enumerate(pages):
    p=pathlib.Path(td)/f'unified-{i}.html';p.write_text(page);files.append(str(p))
   subprocess.run(['java','-jar',os.environ['VNU_JAR'],'--format','json','--stdout']+files,check=True)
 finally:proc.terminate();proc.wait(timeout=5);db.close()
print('OK: 47 own/external items on one HTML page; all RSS items; cache upgrade; interleaved chronological sorting/timezones; title order; full escaped text; filters/permalinks/legacy routes; valid HTML.')
