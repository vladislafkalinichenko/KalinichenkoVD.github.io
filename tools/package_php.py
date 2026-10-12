"""Stage public PHP deployment; never include credentials, databases, VCS or dev files."""
import argparse,pathlib,shutil,urllib.parse,xml.etree.ElementTree as ET
ROOT=pathlib.Path(__file__).resolve().parents[1]
def main():
    p=argparse.ArgumentParser();p.add_argument('--base-url',required=True);p.add_argument('--output',required=True);a=p.parse_args()
    base=a.base_url.rstrip('/')+'/'
    u=urllib.parse.urlsplit(base)
    if u.scheme!='https' or not u.netloc or u.username or u.query or u.fragment:raise SystemExit('Expected public HTTPS site URL.')
    out=pathlib.Path(a.output).resolve()
    if out.exists():raise SystemExit('Output must be a new directory; existing files will not be overwritten.')
    out.mkdir(parents=True)
    for name in ['assets','data','services','admin','api','server']:
        shutil.copytree(ROOT/name,out/name,ignore=shutil.ignore_patterns('__pycache__','*.sqlite','*.sqlite-*','config.php'))
    for f in ROOT.iterdir():
        if f.is_file() and (f.suffix in {'.html','.php','.svg','.xml'} or f.name in {'.htaccess','robots.txt','indexnow-key.txt'}):shutil.copy2(f,out/f.name)
    tree=ET.parse(out/'sitemap.xml');ns='{http://www.sitemaps.org/schemas/sitemap/0.9}'
    oldbase='https://vladislafkalinichenko.github.io/KalinichenkoVD.github.io/'
    for loc in tree.iter(ns+'loc'):
        if not loc.text.startswith(oldbase):raise SystemExit('Unexpected sitemap URL; inspect before deployment.')
        loc.text=base+loc.text[len(oldbase):]
    ET.register_namespace('',ns[1:-1]);tree.write(out/'sitemap.xml',encoding='utf-8',xml_declaration=True)
    (out/'robots.txt').write_text('User-agent: *\nAllow: /\nSitemap: '+base+'sitemap.xml\n')
    print('PHP deployment staged at '+str(out))
if __name__=='__main__':main()
