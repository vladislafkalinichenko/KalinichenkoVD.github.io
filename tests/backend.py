"""Integration test using real PHP/PDO SQLite, temporary storage and two sessions.
Usage: PHP_BIN=php PHP_EXTENSION_DIR=/optional/path python tests/backend.py
"""
import http.cookiejar, json, os, pathlib, socket, subprocess, tempfile, time, urllib.request, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_EXTENSION_DIR'):
    ext=os.environ['PHP_EXTENSION_DIR'];php+=['-n','-d',f'extension={ext}/pdo.so','-d',f'extension={ext}/pdo_sqlite.so']
with tempfile.TemporaryDirectory() as temp:
    test_password='test-only-'+os.urandom(12).hex()
    password_hash=subprocess.check_output(php+['-r',"echo password_hash(trim(fgets(STDIN)),PASSWORD_DEFAULT);"],input=(test_password+'\n').encode()).decode()
    env=dict(os.environ,MIXLAB_DB_DSN='sqlite:'+temp+'/news.sqlite',MIXLAB_ADMIN_PASSWORD_HASH=password_hash)
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    log=open(temp+'/server.log','w+')
    process=subprocess.Popen(php+['-d','session.save_path='+temp,'-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}/'
    admin=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    visitor=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def call(client,action,body=None,csrf=None):
        headers={}
        if body is not None:headers['Content-Type']='application/json'
        if csrf:headers['X-CSRF-Token']=csrf
        req=urllib.request.Request(base+'api/news.php?action='+action,data=json.dumps(body).encode() if body is not None else None,headers=headers)
        try:
            with client.open(req,timeout=5) as r:
                raw=r.read()
                try:return r.status,json.loads(raw)
                except ValueError:raise AssertionError(raw.decode()[:1500])
        except urllib.error.HTTPError as r:return r.code,json.load(r)
    try:
        for _ in range(50):
            try:status,data=call(admin,'status');break
            except urllib.error.URLError:time.sleep(.1)
        else:raise AssertionError('PHP server did not start')
        assert status==200,(status,data)
        token=data['csrf'];assert data['backend']=='php-pdo' and not data['authenticated']
        count=len(call(visitor,'list')[1]['items'])
        assert call(admin,'create',{'title':'test','body':'test'},token)[0]==401
        assert call(admin,'login',{'password':test_password})[0]==403
        assert call(admin,'login',{'password':'wrong'},token)[0]==401
        status,data=call(admin,'login',{'password':test_password},token);assert status==200
        token=data['csrf']
        assert call(admin,'create',{'title':'','body':'x'},token)[0]==422
        assert call(admin,'create',{'title':'я'*161,'body':'x'},token)[0]==422
        title="Тест ' SQL <img src=x>";body='Проверка сохранения\nВторая строка'
        status,data=call(admin,'create',{'title':title,'body':body},token);assert status==201,(status,data)
        item=data['item'];items=call(visitor,'list')[1]['items'];assert len(items)==count+1
        assert any(x['id']==item['id'] and x['title']==title and x['body']==body for x in items)
        assert len(call(visitor,'list')[1]['items'])==count+1 # seed is not duplicated
        assert call(admin,'logout',{},token)[0]==200
        assert call(admin,'create',{'title':'test','body':'test'},token)[0] in (401,403)
        for p in ['server/bootstrap.php','database/schema.mysql.sql','.git/config']:
            try:visitor.open(base+p);raise AssertionError('Private path is public: '+p)
            except urllib.error.HTTPError as r:assert r.code==404
        # A fresh PHP process reads the existing database rather than only session memory.
        process.terminate();process.wait(timeout=5)
        code="require 'server/bootstrap.php'; echo count(db()->query('SELECT * FROM news')->fetchAll());"
        result=subprocess.check_output(php+['-r',code],cwd=ROOT,env=env).decode();assert int(result)==count+1
        print('OK: PHP + real SQLite; login/CSRF; unauthenticated write rejection; validation; safe SQL; second visitor; persistence after restart; private paths; no duplicate seed.')
    except Exception:
        log.seek(0);print(log.read());raise
    finally:
        if process.poll() is None:process.terminate();process.wait(timeout=5)
        log.close()
