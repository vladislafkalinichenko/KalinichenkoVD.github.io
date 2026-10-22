"""Migration, foreign keys, joined dropdown queries and authenticated catalogue input."""
import os,pathlib,subprocess,tempfile,sqlite3,json,urllib.request,urllib.error,urllib.parse,http.cookiejar,socket,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
 ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n']+[a for n in ['pdo','pdo_sqlite'] for a in ['-d',f'extension={ext}/{n}.so']]
with tempfile.TemporaryDirectory() as td,tempfile.TemporaryFile() as log:
 env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+td+'/old.sqlite')
 old=sqlite3.connect(td+'/old.sqlite')
 old.executescript('CREATE TABLE news(id TEXT PRIMARY KEY,title TEXT,body TEXT,published_at TEXT,source TEXT,source_url TEXT,category TEXT);CREATE TABLE app_meta(name TEXT PRIMARY KEY,value TEXT);INSERT INTO app_meta VALUES ("seeded","1");')
 original=('existing','Прежняя публикация','Сохранить без изменений','2026-10-13T00:00:00Z','Свой сайт','','Обновления')
 old.execute('INSERT INTO news VALUES (?,?,?,?,?,?,?)',original);old.commit()
 def run(code):return subprocess.check_output(php+['-r',"require 'server/analytics.php';"+code],env=env,cwd=ROOT,text=True)
 assert run('echo db()->query("SELECT COUNT(*) FROM news")->fetchColumn();')=='51'
 assert run('echo db()->query("SELECT COUNT(*) FROM news")->fetchColumn();')=='51'
 assert old.execute('SELECT id,title,body,published_at,source,source_url,category FROM news WHERE id="existing"').fetchone()==original
 assert old.execute('SELECT author_id FROM news WHERE id="existing"').fetchone()[0]=='vlad-kalinichenko'
 assert 'authors'==old.execute('PRAGMA foreign_key_list(news)').fetchone()[2]
 assert run('try{db()->exec("UPDATE news SET author_id=\'missing\' WHERE id=\'existing\'");echo "BAD";}catch(PDOException $e){echo "REJECTED";}')=='REJECTED'
 data=json.loads(run('$p=analytics_params(["report"=>"filter","category"=>"Вокал","author"=>CATALOG_EDITOR]);echo json_encode(analytics_run(community_db(),$p));'))
 assert data['total']==8 and all(x['category']=='Вокал' and x['author_id']=='mixlab-editorial' for x in data['rows'])
 assert [a['id'] for a in data['authors']]==['mixlab-editorial'] and 'Обновления' not in data['categories']
 assert 'JOIN authors' in data['sql'] and 'a.id=?' in data['sql'] and data['params']==['Вокал','mixlab-editorial']
 assert data['metadata']['complete']==51 and data['metadata']['publications']==51 and data['metadata']['bytes']>0
 assert 'не отдельный человек' in next(x['bio'] for x in data['author_profiles'] if x['id']=='mixlab-editorial')
 password='Isolated-catalog-test-only';env['MIXLAB_ADMIN_PASSWORD_HASH']=subprocess.check_output(php+['-r','echo password_hash(trim(fgets(STDIN)),PASSWORD_DEFAULT);'],input=password,text=True)
 sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}/'
 proc=subprocess.Popen(php+['-d',f'session.save_path={td}','-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def get(path,body=None,token=None):
  h={'Content-Type':'application/json'} if body is not None else {}
  if token:h['X-CSRF-Token']=token
  req=urllib.request.Request(base+path,data=None if body is None else json.dumps(body).encode(),headers=h)
  try:
   with client.open(req,timeout=8) as r:return r.status,r.read().decode()
  except urllib.error.HTTPError as e:return e.code,e.read().decode()
 def call(action,body=None,token=None):
  status,raw=get('api/news.php?action='+action,body,token);return status,json.loads(raw)
 try:
  for _ in range(50):
   try:status,state=call('status');break
   except urllib.error.URLError:time.sleep(.1)
  else:raise AssertionError('PHP did not start')
  token=state['csrf'];assert call('catalog')[0]==200
  fields={'name':'Новый автор <script>alert(1)</script>','role':'Гость','bio':'Описание & профиль'}
  assert call('create_author',fields,token)[0]==401
  status,state=call('login',{'password':password},token);assert status==200;token=state['csrf']
  assert call('create_author',fields)[0]==403
  assert call('create_author',dict(fields,name=''),token)[0]==422
  status,data=call('create_author',fields,token);assert status==201;author=data['author']['id']
  article={'title':'Новая запись','body':'Новая запись из формы','category':'Новая категория','author_id':author}
  assert call('create',dict(article,author_id='missing'),token)[0]==422
  status,data=call('create',article,token);assert status==201;new=data['item']['id']
  listing=call('list')[1]['items'];assert any(x['id']==new and x['author_name']==fields['name'] for x in listing)
  params=urllib.parse.urlencode({'report':'filter','category':article['category'],'author':author})
  status,html=get('database.html?'+params);assert status==200 and 'id="report-total">1<' in html and 'Новая запись из формы' in html
  assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html
  assert '\\u003Cscript\\u003E' in html and 'database-pairs' in html
  assert 'id="database-count">52<' in html and 'Ввод данных' in html and 'admin/news.html' in html
  for path in ['database.html?author[]=x','database.html?report=filter&author=missing']:
   status,text=get(path);assert status==(422 if '[]' in path else 200)
  assert old.execute('SELECT COUNT(*) FROM news').fetchone()[0]==52
  # Persisted records and newly added authors remain after a fresh PHP process.
  assert run('echo db()->query("SELECT COUNT(*) FROM news")->fetchColumn();')=='52'
 finally:proc.terminate();proc.wait(timeout=5);old.close()
print('OK: old-schema migration preserves records; 50 unique publications seeded once; FK; metadata; JOIN filters/options; admin+CSRF author/publication input; Unicode/XSS; persistence.')
