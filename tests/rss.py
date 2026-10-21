"""Isolated PHP/PDO RSS exchange tests. No writes to the production DB or remote source."""
import os,pathlib,subprocess,tempfile,socket,time,urllib.request,urllib.error,urllib.parse,http.cookiejar,sqlite3,json,xml.etree.ElementTree as ET,csv,io,re
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[x for name in ['pdo','pdo_sqlite','dom'] for x in ['-d',f'extension={ext}/{name}.so']]
fixture='''<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Внешние новости</title><link>https://example.org/</link><description>Тест</description>
<item><title>Вокал &amp; звук</title><link>https://example.org/vocal</link><guid>1</guid><pubDate>Wed, 21 Oct 2026 12:00:00 GMT</pubDate><description>&lt;img src=x onerror=alert(1)&gt;Описание</description></item>
<item><title>Повтор</title><link>https://example.org/vocal</link><guid>1</guid></item>
<item><title>Без даты</title><link>javascript:alert(1)</link><guid>2</guid><pubDate>not a date</pubDate><description>&lt;script&gt;alert(1)&lt;/script&gt;</description></item>
<item><title>&lt;img src=x onerror=alert(1)&gt;</title><guid>3</guid><description>Текст</description></item>
<item><title> </title></item>
</channel></rss>'''
with tempfile.TemporaryDirectory() as td,tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/test.sqlite',MIXLAB_RSS_CACHE_DIR=td+'/rss')
 def run(code,data=''):
  r=subprocess.run(php+['-r',"require 'server/exchange.php';"+code],cwd=ROOT,env=env,input=data,text=True,capture_output=True,check=True);return r.stdout
 run('db();')
 db=sqlite3.connect(td+'/test.sqlite');db.execute('DELETE FROM news')
 body='Кириллица: ё, & <b>буквальный тег</b>; "кавычки"\nВторая строка\tи вкладка 😀'
 db.executemany('INSERT INTO news VALUES (?,?,?,?,?,?,?)',[
  ('first','Свой вокал',body,'2026-10-01T10:00:00Z','Свой сайт','','Материалы'),
  ('second','=1+2','Другая запись','2026-10-20T10:00:00Z','Свой сайт','','Обновления')]);db.commit()
 # Parse hostile data as text, deduplicate, omit unsafe links, preserve missing dates.
 parsed=json.loads(run('echo json_encode(rss_parse(stream_get_contents(STDIN)));',fixture));assert len(parsed['items'])==3 and parsed['skipped']==2
 assert parsed['items'][1]['link']=='' and parsed['items'][1]['published_at']==''
 for invalid in ['<html/>','<rss version="2.0"><channel>', '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&xxe;</title></channel></rss>','<rss version="1.0"><channel/></rss>','x'*524289]:
  result=run('try{rss_parse(stream_get_contents(STDIN));echo "BAD";}catch(InvalidArgumentException $e){echo "REJECTED";}',invalid);assert result=='REJECTED'
 # The same forbidden DTD in UTF-16 must also be rejected, without depending on byte-pattern filters.
 result=run('$s=base64_decode(stream_get_contents(STDIN));try{rss_parse($s);echo "BAD";}catch(InvalidArgumentException $e){echo "REJECTED";}',__import__('base64').b64encode('<!DOCTYPE rss [<!ENTITY x "private">]><rss version="2.0"><channel><title>&x;</title></channel></rss>'.encode('utf-16')).decode());assert result=='REJECTED'
 # Shared cache success, TTL, failed-refresh preservation, next success without duplication.
 state=json.loads(run('$raw=stream_get_contents(STDIN);echo json_encode(rss_external(true,fn()=>$raw));',fixture));assert len(state['items'])==3 and not state['error']
 cached=json.loads(run('echo json_encode(rss_external(true,function(){throw new RuntimeException("must not run during cooldown");}));'));assert not cached['error']
 cachepath=pathlib.Path(td)/'rss/source.json';v=json.loads(cachepath.read_text());v['checked_at']='2000-01-01T00:00:00Z';cachepath.write_text(json.dumps(v))
 failed=json.loads(run('echo json_encode(rss_external(true,function(){throw new RuntimeException("offline");}));'));assert failed['items']==state['items'] and failed['updated_at']==state['updated_at'] and failed['error']
 v=failed;v['checked_at']='2000-01-01T00:00:00Z';cachepath.write_text(json.dumps(v));run('$raw=stream_get_contents(STDIN);rss_external(true,fn()=>$raw);',fixture)
 # Every output is based on live DB content, not on a checked-in fixture.
 own=json.loads(run('echo json_encode(exchange_own(db()));'));assert len(own)==2
 rss=run('echo exchange_rss(exchange_own(db()));');root=ET.fromstring(rss);assert root.tag=='rss' and root.attrib['version']=='2.0' and len(root.findall('./channel/item'))==2
 for item in root.findall('./channel/item'):assert item.findtext('guid').startswith('https://') and item.findtext('pubDate') and item.findtext('link')
 back=json.loads(run('echo json_encode(rss_parse(stream_get_contents(STDIN)));',rss));assert next(x for x in back['items'] if x['title']=='Свой вокал')['description']==body
 rawxml=run('echo exchange_xml(exchange_own(db()));');root=ET.fromstring(rawxml);assert root.find("./item[id='first']/description").text==body
 ascii_text=run('echo exchange_ascii(exchange_own(db()));');ascii_text.encode('ascii');lines=ascii_text.splitlines();assert lines[0].split('\t')==['id','title','description','published_at','link','source','category']
 ascii_rows=[[json.loads(cell) for cell in row.split('\t')] for row in lines[1:]];assert next(x for x in ascii_rows if x[0]=='first')[2]==body
 wrapped=run('echo exchange_wrapped(exchange_own(db()));');assert ET.fromstring(wrapped).findtext('payload')==ascii_text
 csv_text=run('echo exchange_csv(exchange_own(db()));');csv_rows=list(csv.reader(io.StringIO(csv_text.lstrip('\ufeff')),delimiter=';'));assert csv_rows[1][1]=="'=1+2" and csv_rows[2][2]==body
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}/'
 proc=subprocess.Popen(php+['-d',f'session.save_path={td}','-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(path,data=None,headers=None):
  req=urllib.request.Request(base+path,data=data,headers=headers or {})
  try:
   with client.open(req,timeout=10) as r:return r.status,r.read(),dict(r.headers)
  except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
 def upload(content,csrf):
  boundary='mixlab-test-boundary';raw=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf"\r\n\r\n{csrf}\r\n--{boundary}\r\nContent-Disposition: form-data; name="rss_file"; filename="feed.xml"\r\nContent-Type: application/rss+xml\r\n\r\n'.encode()+content.encode()+f'\r\n--{boundary}--\r\n'.encode())
  return get('rss.html',raw,{'Content-Type':'multipart/form-data; boundary='+boundary})
 try:
  for _ in range(50):
   try:status,html,headers=get('rss.html');break
   except urllib.error.URLError:time.sleep(.1)
  else:raise AssertionError('PHP did not start')
  text=html.decode();assert status==200 and 'data-origin="own"' in text and 'data-origin="external"' in text and 'Найдено новостей: <span id="rss-total">5</span>' in text
  assert '<img src=x' not in text and 'href="javascript:' not in text
  assert 'Лабораторная' not in text and 'методичк' not in text
  assert 'Найдено новостей: <span id="rss-total">2</span>' in get('rss.html?source=own')[1].decode()
  assert 'Найдено новостей: <span id="rss-total">3</span>' in get('rss.html?source=external')[1].decode()
  assert 'Найдено новостей: <span id="rss-total">2</span>' in get('rss.html?q='+urllib.parse.quote('ВОКАЛ'))[1].decode()
  assert 'Новостей по этим условиям пока нет' in get('rss.html?q=nomatch12345')[1].decode()
  for fmt in ['rss','xml','csv','txt','wrapped']:
   status,raw,h=get('feed.php?format='+fmt);assert status==200
   if fmt!='rss':assert 'attachment' in h['Content-Disposition']
   if fmt in ['rss','xml','wrapped']:ET.fromstring(raw)
   if fmt=='txt':raw.decode('ascii');assert 'charset=US-ASCII' in h['Content-Type']
  assert len(ET.fromstring(get('feed.php?source=all')[1]).findall('./channel/item'))==5
  assert len(ET.fromstring(get('feed.php?q='+urllib.parse.quote('Свой вокал'))[1]).findall('./channel/item'))==1
  for path in ['rss.html?sort=bad','rss.html?source[]=x','feed.php?format=bad','feed.php?source=bad']:assert get(path)[0]==422,path
  csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',text).group(1)
  assert upload(fixture,'wrong')[0]==403
  status,uploaded,_=upload(fixture,csrf);assert status==200 and 'Новости из файла' in uploaded.decode() and 'Записей: 3' in uploaded.decode()
  assert '<img src=x' not in uploaded.decode() and 'href="javascript:' not in uploaded.decode()
  assert upload('<broken>',csrf)[0]==422
  assert upload('x'*524289,csrf)[0] in [413,422]
  # Public preview never mutates the common news DB.
  assert db.execute('SELECT COUNT(*) FROM news').fetchone()[0]==2
  db.execute('INSERT INTO news VALUES (?,?,?,?,?,?,?)',('new','Новая публикация','Сохранено','2026-10-21T13:00:00Z','Свой сайт','','Обновления'));db.commit()
  assert len(ET.fromstring(get('feed.php')[1]).findall('./channel/item'))==3
  assert get('rss.php')[0]==200
  vnu=os.environ.get('VNU_JAR')
  if vnu:
   pages=[]
   for name,path in [('rss','rss.html'),('empty','rss.html?q=nomatch12345')]:
    f=pathlib.Path(td)/(name+'.html');f.write_bytes(get(path)[1]);pages.append(str(f))
   f=pathlib.Path(td)/'upload.html';f.write_bytes(upload(fixture,csrf)[1]);pages.append(str(f))
   subprocess.run(['java','-jar',vnu,'--format','json','--stdout']+pages,check=True)
 finally:proc.terminate();proc.wait(timeout=5);db.close()
print('OK: DB→RSS/XML/CSV/ASCII/wrapped XML; lossless Unicode roundtrip; external merge; cache TTL/failure; safe XML/links; upload+CSRF; search/source/sort; dynamic exports; valid HTML.')
