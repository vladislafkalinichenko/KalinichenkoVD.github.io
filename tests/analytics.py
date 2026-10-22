"""Real PHP/PDO integration tests, exclusively against a disposable database."""
import os, pathlib, subprocess, tempfile, socket, time, urllib.request, urllib.error, urllib.parse, sqlite3, json
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[x for name in ['pdo','pdo_sqlite'] for x in ['-d',f'extension={ext}/{name}.so']]
with tempfile.TemporaryDirectory() as td, tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/test.sqlite')
 subprocess.run(php+['-r',"require 'server/bootstrap.php';require 'server/community.php';community_db();"],cwd=ROOT,env=env,check=True)
 db=sqlite3.connect(td+'/test.sqlite');db.execute('DELETE FROM news')
 fixtures=[('a','ВОКАЛ и Ёлка','Чистый текст','2026-01-01T00:00:00Z','Свой сайт','','Звук'),('b','100%_готово','<script>alert(1)</script>','2026-01-31T23:59:59Z','Свой сайт','','Звук'),('c','Компрессор','Инструменты','2026-03-01T00:00:00Z','Свой сайт','','Техника')]
 db.executemany('INSERT INTO news (id,title,body,published_at,source,source_url,category) VALUES (?,?,?,?,?,?,?)',fixtures)
 db.executemany('INSERT INTO site_ratings VALUES (?,?)',[('a',1),('b',5),('c',3)]);db.commit()
 def run(**p):
  code="require 'server/analytics.php';$p=analytics_params(json_decode(stream_get_contents(STDIN),true));echo json_encode(analytics_run(community_db(),$p));"
  r=subprocess.run(php+['-r',code],input=json.dumps(p),text=True,capture_output=True,env=env,cwd=ROOT,check=True);return json.loads(r.stdout)
 assert run(q='вокал')['total']==1
 assert run(q='ёлка')['total']==1
 assert run(q='%_')['total']==1
 assert run(q="' OR 1=1 --")['total']==0
 assert run(q='инструменты')['rows'][0]['id']=='c'
 assert run(report='filter',category='Звук',**{'from':'2026-01-31','to':'2026-01-31'})['total']==1
 assert [x['id'] for x in run(sort='oldest')['rows']]==['a','b','c']
 assert run(report='categories')['rows'][0]['amount']==2
 assert run(report='categories',category='Техника')['total']==1
 timeline=run(report='timeline')['rows'];assert [int(x['amount']) for x in timeline]==[2,0,1]
 rating=run(report='ratings');assert rating['total']==3 and float(rating['summary']['average'])==3 and rating['summary']['minimum']==1 and rating['summary']['maximum']==5
 assert len(rating['rows'])==5
 db.executemany('INSERT INTO news (id,title,body,published_at,source,source_url,category) VALUES (?,?,?,?,?,?,?)',[(f'n{i}',f'Запись {i}','Текст','2026-04-01T00:00:00Z','Свой сайт','','Техника') for i in range(25)]);db.commit()
 assert len(run()['rows'])==20 and len(run(page='2')['rows'])==8
 assert run(page='999')['page']==2
 db.execute('DELETE FROM site_ratings');db.commit();assert run(report='ratings')['summary']['average'] is None
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
 proc=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 def get(params=None,path='database.html'):
  url=f'http://127.0.0.1:{port}/'+path+('?' +urllib.parse.urlencode(params) if params else '')
  try:
   with urllib.request.urlopen(url,timeout=5) as r:return r.status,r.read().decode()
  except urllib.error.HTTPError as e:return e.code,e.read().decode()
 try:
  for _ in range(50):
   try:get();break
   except urllib.error.URLError:time.sleep(.1)
  for report in ['search','filter','categories','timeline','ratings']:
   status,html=get({'report':report});assert status==200 and 'SQL этого запроса' in html and 'Результат запроса' in html
  status,html=get({'q':'100%_'});assert '&lt;script&gt;' in html and '<script>alert' not in html and 'news-b' in html
  for bad in [{'from':'2026-02-30'},{'from':'2026-03-01','to':'2026-01-01'},{'report':'DROP TABLE'},{'sort':'title;DELETE'},{'page':'0'},{'q[]':'x'}]:assert get(bad)[0]==422,bad
  assert get(path='database.php')[0]==200
  (ROOT/'tests/validation').mkdir(exist_ok=True)
  for report in ['search','filter','categories','timeline','ratings']:
   (pathlib.Path(td)/f'{report}.html').write_text(get({'report':report})[1])
  vnu=os.environ.get('VNU_JAR')
  if vnu:subprocess.run(['java','-jar',vnu,'--format','json','--stdout']+[str(pathlib.Path(td)/f'{report}.html') for report in ['search','filter','categories','timeline','ratings']],check=True)
  # Durable source DB, requery sees updates; no browser cache or fixture-based rendering.
  db.execute('INSERT INTO site_ratings VALUES (?,?)',('new',4));db.commit();assert '4,00 / 5' in get({'report':'ratings'})[1]
 finally:proc.terminate();proc.wait(timeout=5);db.close()
print('OK: five PHP/PDO reports; Unicode/literal search; dates; grouping; chart gaps; ratings; pagination; live changes; escaping; invalid input; server-rendered routes.')
