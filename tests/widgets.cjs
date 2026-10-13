const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {parseHTML}=require('linkedom');
const root=path.resolve(__dirname,'..'),{document}=parseHTML(fs.readFileSync(path.join(root,'index.html'),'utf8'));
const select=document.querySelector('#city');Object.defineProperty(select,'value',{value:'obninsk',writable:true});
const snapshot=JSON.parse(fs.readFileSync(path.join(root,'data/weather.json'),'utf8'));
const original=snapshot.cities.obninsk,checked='2026-10-13T18:20:37Z';let mode='unchanged',requests=[];
const response=()=>({city:{...original,updated_at:checked},checked_at:checked,served_at:checked,status:mode});
const storage=new Map();
const sandbox={document,URLSearchParams,AbortSignal,Date,console,fetch:async url=>{requests.push(String(url));if(String(url).includes('api/weather.php'))return {ok:true,json:async()=>response()};return {ok:true,json:async()=>JSON.parse(fs.readFileSync(path.join(root,'data',String(url).split('/').at(-1)),'utf8'))};},MixLab:{isStatic:false,siteURL:p=>'https://example.test/'+p,getJSON:(k,f)=>storage.get(k)??f,setJSON:(k,v)=>storage.set(k,v),formatDate:s=>s}};
(async()=>{
 vm.runInNewContext(fs.readFileSync(path.join(root,'assets/widgets.js'),'utf8'),sandbox);await new Promise(r=>setTimeout(r,10));
 const button=document.querySelector('#weather-refresh'),status=document.querySelector('#weather-status');
 await button.onclick();assert.ok(requests.some(s=>s.includes('city=obninsk&refresh=1')));assert.match(status.textContent,/те же данные/);assert.match(document.querySelector('#weather').textContent,/Источник проверен:/);assert.match(document.querySelector('#weather').textContent,/21:20:37/);assert.equal(button.disabled,false);
 mode='recent';await button.onclick();assert.match(status.textContent,/менее минуты/);
 mode='error';await button.onclick();assert.match(status.textContent,/последние доступные данные/);assert.match(document.querySelector('#weather').textContent,/°C/);assert.match(status.className,/warning/);
 select.value='moscow';await select.onchange();assert.equal(storage.get('mixlab_city'),'moscow');assert.ok(requests.at(-1).includes('city=moscow&refresh=0'));
 console.log('OK: selected-city source refresh, unchanged/recent/error messages, second-precision check time, retained weather and city switch.');
})().catch(e=>{console.error(e);process.exitCode=1;});
