"""Run real PHP/XSLT integration tests: PHP_BIN=php PHP_XML_EXT_DIR=... python tests/xml_catalog.py."""
import os,subprocess,pathlib,socket,tempfile,time,urllib.request,urllib.error,urllib.parse,xml.etree.ElementTree as ET,re
ROOT=pathlib.Path(__file__).resolve().parents[1]
php=[os.environ.get('PHP_BIN','php')]
if os.environ.get('PHP_XML_EXT_DIR'):
    ext=os.environ['PHP_XML_EXT_DIR'];php+=['-n','-d',f'extension={ext}/dom.so','-d',f'extension={ext}/xsl.so']
raw=(ROOT/'data/mixing-news.xml').read_bytes()
text=raw.decode('utf-8-sig',errors='strict')
assert text.startswith('<?xml version="1.0" encoding="UTF-8"?>')
xml=ET.fromstring(raw);rows=xml.findall('news')
assert rows[0].findtext('title')=='Баланс громкости: с чего начать'
assert rows[0].findtext('category')=='Основы' and rows[0].findtext('level')=='Начальный'
assert all('\ufffd' not in (field.text or '') for row in rows for field in row)
for name in ['mixing-news.xsl','mixing-news.xsd']:
    raw=(ROOT/'data'/name).read_bytes();raw.decode('utf-8-sig',errors='strict');ET.fromstring(raw)
assert len(rows)==18 and len({x.get('id') for x in rows})==18
assert all([y.tag for y in x]==['title','date','category','level','minutes','summary','link'] for x in rows)
assert all((ROOT/x.findtext('link')).is_file() for x in rows)
# Build twice: checked-in static page must be a reproducible PHP/XSLT output.
before=(ROOT/'xml.html').read_bytes()
subprocess.run(php+['tools/build_xml.php'],cwd=ROOT,check=True)
assert (ROOT/'xml.html').read_bytes()==before
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
with tempfile.TemporaryFile() as log:
    proc=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','server/router.php'],cwd=ROOT,stdout=log,stderr=log)
    def get(params=None,path='xml.php'):
        url=f'http://127.0.0.1:{port}/{path}?'+urllib.parse.urlencode(params or {})
        try:
            with urllib.request.urlopen(url,timeout=5) as r:return r.status,r.read().decode()
        except urllib.error.HTTPError as e:return e.code,e.read().decode()
    def ids(html):return re.findall(r'<tr[^>]+id="(mix-\d+)"',html)
    try:
        for _ in range(50):
            try:status,body=get();break
            except urllib.error.URLError:time.sleep(.1)
        else:raise AssertionError('Server not started')
        assert status==200 and len(ids(body))==18 and 'data-mode="server"' in body
        assert get(path='xml.html')[0]==200 and 'data-mode="server"' in get(path='xml.html')[1]
        for cat in ['Основы','Тембр','Динамика','Пространство','Стерео','Проверка']:
            status,body=get({'category':cat});assert status==200
            assert set(ids(body))=={x.get('id') for x in rows if x.findtext('category')==cat}
        for level in ['Начальный','Средний','Продвинутый']:
            assert set(ids(get({'level':level})[1]))=={x.get('id') for x in rows if x.findtext('level')==level}
        for sort in ['date','title','category','level','minutes','summary','link']:
            for order in ['ascending','descending']:
                expected=sorted(rows,key=lambda x:int(x.findtext(sort)) if sort=='minutes' else x.findtext(sort),reverse=order=='descending')
                assert ids(get({'sort':sort,'order':order})[1])==[x.get('id') for x in expected],(sort,order)
        for column in ['date','title','category','level','minutes','summary','link']:
            first=get({'column':column,'sort':column,'order':'descending'})[1]
            assert f'aria-sort="ascending"' in first
            assert ids(first)==ids(get({'sort':column,'order':'ascending'})[1])
            assert ids(get({'column':column,'sort':column,'order':'ascending'})[1])==ids(get({'sort':column,'order':'descending'})[1])
        def normalize(text):return text.lower().replace('ё','е')
        def allfields(row):
            date=row.findtext('date');return normalize(' '.join([y.text for y in row]+['.'.join(reversed(date.split('-')))]))
        for query in ['Основы','Продвинутый','eq.html','01.10.2026','2026-10-01','7','оригинала']:
            assert set(ids(get({'q':query})[1]))=={x.get('id') for x in rows if normalize(query) in allfields(x)},query
        assert '<table' in get()[1] and len(re.findall('<th scope=',get()[1]))==7
        assert 'href="data/mixing-news.xml?v=20261014-utf8"' in get()[1]
        assert ids(get({'category':'Стерео','level':'Продвинутый'})[1])==['mix-15']
        assert ids(get({'q':'ГОЛОС'})[1])==ids(get({'q':'голос'})[1]) and ids(get({'q':'голос'})[1])
        assert ids(get({'q':'ПРИЕМ'})[1])==ids(get({'q':'ПРИЁМ'})[1])
        assert not ids(get({'q':'такойзаметкинесуществует'})[1])
        status,body=get({'q':'<script>alert(1)</script>'});assert status==200 and '<script>alert(1)</script>' not in body
        for params in [{'sort':'../../etc/passwd'},{'column':'bad'},{'column[]':'date'},{'category[]':'Тембр'},{'q[]':'x'},{'order':'wrong'},{'q':'a'*121}]:assert get(params)[0]==400,params
        assert get(path='server/xml_catalog.php')[0]==404
        print('OK: valid UTF-8 and Cyrillic, 18 objects / 7 fields, schema validation, reproducible XSL build, PHP routing, filters, search, fourteen sorts, empty results, escaping and invalid input.')
    finally:proc.terminate();proc.wait(timeout=5)
