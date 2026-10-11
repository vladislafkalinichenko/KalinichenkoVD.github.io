"""Rebuild the local full-text search index after editing HTML."""
from html.parser import HTMLParser
from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[1]
class Text(HTMLParser):
    def __init__(self):super().__init__();self.inside=False;self.parts=[]
    def handle_starttag(self,tag,attrs):
        if tag=='main':self.inside=True
    def handle_endtag(self,tag):
        if tag=='main':self.inside=False
    def handle_data(self,data):
        if self.inside:self.parts.append(data)
rows=json.loads((ROOT/'data/pages.json').read_text())
for row in rows:
    parser=Text();parser.feed((ROOT/row['url']).read_text());row['text']=' '.join(' '.join(parser.parts).split())
(ROOT/'assets/search-index.js').write_text('const SITE_SEARCH = '+json.dumps(rows,ensure_ascii=False,separators=(',',':'))+';\n')
