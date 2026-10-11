"""Check links, IDs, common navigation and required laboratory pages. No dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit,unquote
import json
ROOT=Path(__file__).resolve().parents[1]
class Page(HTMLParser):
    def __init__(self):super().__init__();self.ids=[];self.links=[];self.lang=None;self.search=False;self.h1=0
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if 'id' in a:self.ids.append(a['id'])
        if tag=='html':self.lang=a.get('lang')
        if tag=='h1':self.h1+=1
        if tag=='form' and a.get('role')=='search':self.search=True
        for k in ['src','href','action']:
            if k in a:self.links.append(a[k])
files=[p for p in ROOT.rglob('*.html') if not any(x.startswith('.') or x in {'_site','node_modules'} for x in p.relative_to(ROOT).parts) and p.name!='nav.html']
errors=[];parsed={}
for p in files:
    v=Page();v.feed(p.read_text());parsed[p.resolve()]=v
    if len(set(v.ids))!=len(v.ids):errors.append(f'{p}: duplicate IDs')
    if v.lang!='ru' or not v.search or v.h1!=1:errors.append(f'{p}: missing language/search or invalid h1 count')
for p,v in parsed.items():
    for url in v.links:
        u=urlsplit(url)
        if u.scheme or u.netloc:continue
        target=(p.parent/unquote(u.path)).resolve() if u.path else p
        if not target.exists():errors.append(f'{p.name}: missing {url}')
        elif u.fragment and target in parsed and unquote(u.fragment) not in parsed[target].ids:errors.append(f'{p.name}: missing anchor {url}')
for filename in ['news','currency','weather','pages']:
    json.loads((ROOT/'data'/f'{filename}.json').read_text())
for name in ['xml.html','database.html','rss.html','admin/index.html','admin/news.html']:
    if not (ROOT/name).is_file():errors.append('Missing '+name)
if len(files)<10:errors.append('Less than 10 pages')
if errors:raise SystemExit('\n'.join(errors))
print(f'OK: {len(files)} HTML pages, local links and anchors, unique IDs, Russian language, shared search, JSON data.')
