"""Include public assets only; PHP, DB schemas and developer tools remain in GitHub."""
from pathlib import Path
import shutil
root=Path(__file__).resolve().parents[1]
out=root/'_site'
if out.exists():shutil.rmtree(out)
out.mkdir()
for item in root.iterdir():
    if item.name in {'assets','data','services','admin'}:
        shutil.copytree(item,out/item.name,ignore=shutil.ignore_patterns('*.php'))
    elif item.is_file() and (item.suffix in {'.html','.svg','.xml'} or item.name in {'robots.txt','.nojekyll','indexnow-key.txt'}):
        shutil.copy2(item,out/item.name)
