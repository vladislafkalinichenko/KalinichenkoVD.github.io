"""Deploy the current checkout to the existing alwaysdata site, preserving private data."""
from pathlib import Path
import json,os,shutil,subprocess,sys,tempfile,datetime
ROOT=Path(__file__).resolve().parents[1]
ACCOUNT=Path('/home/vlad-mixlab')
LIVE=ACCOUNT/'mixlab-live'
def main():
    if not LIVE.is_dir():raise SystemExit('Existing live directory required.')
    subprocess.run(['php','tools/build_xml.php'],cwd=ROOT,check=True)
    subprocess.run(['python3','tools/build_search.py'],cwd=ROOT,check=True)
    subprocess.run(['python3','tests/xml_catalog.py'],cwd=ROOT,check=True)
    subprocess.run(['python3','tools/refresh_informers.py'],cwd=ROOT,check=True)
    subprocess.run(['python3','tools/check_site.py'],cwd=ROOT,check=True)
    subprocess.run(['python3','tests/community.py'],cwd=ROOT,check=True)
    with tempfile.TemporaryDirectory(prefix='mixlab-release-',dir=ACCOUNT) as temporary:
        staged=Path(temporary)/'site'
        subprocess.run(['python3','tools/package_php.py','--base-url','https://vlad-mixlab.alwaysdata.net/','--output',str(staged)],cwd=ROOT,check=True)
        # Keep the newest scheduled informer data, even if the checkout is older.
        for name in ['weather.json','currency.json']:
            if (LIVE/'data'/name).is_file():
                old=json.loads((LIVE/'data'/name).read_text());new=json.loads((staged/'data'/name).read_text())
                if name=='weather.json':
                    for key,city in old.get('cities',{}).items():
                        if key not in new['cities'] or city.get('observed_at','')>new['cities'][key].get('observed_at',''):new['cities'][key]=city
                    new['updated_at']=max(old.get('updated_at',''),new.get('updated_at',''))
                    (staged/'data'/name).write_text(json.dumps(new,ensure_ascii=False,indent=2)+'\n')
                elif old.get('updated_at','')>new.get('updated_at',''):shutil.copy2(LIVE/'data'/name,staged/'data'/name)
        commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
        (staged/'data/release.json').write_text(json.dumps({'commit':commit,'deployed_at':datetime.datetime.now(datetime.timezone.utc).isoformat()})+'\n')
        backup=ACCOUNT/('mixlab-before-'+commit[:12])
        if not backup.exists():shutil.copytree(LIVE,backup)
        # Atomic replacement of each file; do not delete unknown files or touch the private DB.
        for item in staged.rglob('*'):
            target=LIVE/item.relative_to(staged)
            if item.is_dir():target.mkdir(parents=True,exist_ok=True)
            else:
                pending=target.with_name(target.name+'.release-new')
                shutil.copy2(item,pending);os.replace(pending,target)
    subprocess.run(['php','-r',"require 'server/community.php';require 'server/bootstrap.php';community_db();echo 'Database ready\\n';"],cwd=LIVE,check=True)
    # The existing three-hour task executes this fixed source path. Keep its city list current.
    updater=ACCOUNT/'mixlab-source/tools/refresh_informers.py'
    if not updater.parent.is_dir():raise RuntimeError('Existing informer source required')
    pending=updater.with_name(updater.name+'.release-new')
    shutil.copy2(ROOT/'tools/refresh_informers.py',pending);os.replace(pending,updater)
    print('MIXLAB release deployed; private database preserved; informer updater refreshed.')
if __name__=='__main__':main()
