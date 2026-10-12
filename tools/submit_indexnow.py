"""Submit the public sitemap to IndexNow; acceptance is NOT proof of indexing."""
import argparse,json,pathlib,urllib.request,urllib.parse,xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[1]
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--base-url',required=True);args=parser.parse_args()
    base=args.base_url.rstrip('/')+'/'
    u=urllib.parse.urlsplit(base)
    if u.scheme!='https' or not u.netloc or u.query or u.fragment or u.username:raise SystemExit('Use a public HTTPS site base URL.')
    key=(ROOT/'indexnow-key.txt').read_text().strip()
    location=urllib.parse.urljoin(base,'indexnow-key.txt')
    with urllib.request.urlopen(location,timeout=30) as response:
        if response.read().decode().strip()!=key:raise SystemExit('Published IndexNow key does not match; deploy it first.')
    # Read the deployed sitemap, not guessed or stale local URLs.
    with urllib.request.urlopen(urllib.parse.urljoin(base,'sitemap.xml'),timeout=30) as response:
        sitemap=ET.fromstring(response.read())
    urls=[x.text for x in sitemap.findall('{*}url/{*}loc') if x.text and x.text.startswith(base)]
    # Submit real content, excluding administration, placeholders and utility screens.
    excluded={'xml.php','rss.html','database.html','widgets.html','services/search.html','services/stats.html','services/rating.html'}
    urls=[x for x in urls if x[len(base):] not in excluded and not x[len(base):].startswith('admin/')]
    if not urls:raise SystemExit('No eligible URLs for this site base in published sitemap.')
    payload={'host':u.netloc,'key':key,'keyLocation':location,'urlList':urls}
    request=urllib.request.Request('https://api.indexnow.org/indexnow',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json; charset=utf-8'},method='POST')
    with urllib.request.urlopen(request,timeout=30) as response:
        print(json.dumps({'status':response.status,'submitted_urls':len(urls),'base_url':base,'meaning':'URLs received; indexing is not guaranteed.'},ensure_ascii=False))
if __name__=='__main__':main()
