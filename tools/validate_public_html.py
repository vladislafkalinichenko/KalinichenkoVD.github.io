"""Ask the W3C Nu checker to read public URLs; no local source is uploaded."""
import argparse,concurrent.futures,datetime,json,pathlib,time,urllib.error,urllib.parse,urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
def main():
 p=argparse.ArgumentParser();p.add_argument('--base-url',required=True);p.add_argument('--output',required=True);a=p.parse_args()
 base=a.base_url.rstrip('/')+'/'
 paths=sorted(str(x.relative_to(ROOT)) for x in ROOT.rglob('*.html') if not any(part.startswith('.') or part in {'_site','node_modules'} for part in x.relative_to(ROOT).parts) and x.name!='nav.html')
 def check(path):
  url=base+path;checker='https://validator.w3.org/nu/?'+urllib.parse.urlencode({'doc':url,'out':'json'})
  try:
   req=urllib.request.Request(checker,headers={'User-Agent':'MIXLAB-public-HTML-check/1.0','Accept':'application/json'})
   with urllib.request.urlopen(req,timeout=40) as response:data=json.load(response)
   result={'path':path,'url':url,'messages':data.get('messages',[])}
   result['errors']=sum(x.get('type')=='error' for x in result['messages'])
   print(path,'errors:',result['errors'],flush=True);return result
  except Exception as e:
   print(path,'check failed:',str(e),flush=True);return {'path':path,'url':url,'request_error':str(e)}
 with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:results=list(pool.map(check,paths))
 report={'checker':'https://validator.w3.org/nu/','base_url':base,'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'pages':results,'errors':sum(x.get('errors',0) for x in results),'failed_requests':sum('request_error' in x for x in results)}
 out=pathlib.Path(a.output);out.parent.mkdir(parents=True,exist_ok=True);out.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print('TOTAL:',len(results),'pages;',report['errors'],'errors;',report['failed_requests'],'failed requests',flush=True)
 if report['errors'] or report['failed_requests']:raise SystemExit(1)
if __name__=='__main__':main()
