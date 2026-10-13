/* Install dev-only test dependencies outside the site: npm install linkedom fake-indexeddb.
   NODE_PATH=<directory>/node_modules node tests/frontend.cjs */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {parseHTML}=require('linkedom'),{IDBFactory}=require('fake-indexeddb'),{webcrypto}=require('node:crypto');
const root=path.resolve(__dirname,'..'),files=p=>fs.readFileSync(path.join(root,p),'utf8');
function context(file,opts={}){
 const {document}=parseHTML(files(file));const url=new URL(file+(opts.query||''),'https://test.github.io/project/');
 const storage=opts.storage||new Map();
 document.currentScript={src:'https://test.github.io/project/assets/app.js'};
 for(const f of document.querySelectorAll('form')){f.reportValidity=()=>true;f.reset=()=>f.querySelectorAll('input,textarea').forEach(x=>x.value='');}
 for(const a of document.querySelectorAll('a[href]'))a.setAttribute('href',new URL(a.getAttribute('href'),url).href);
 for(const s of document.querySelectorAll('select'))Object.defineProperty(s,'value',{value:s.querySelector('option')?.getAttribute('value')||s.querySelector('option')?.textContent,writable:true});
 const win={addEventListener(){}};
 const sandbox={document,location:url,window:win,URL,URLSearchParams,Blob,AbortSignal,crypto:webcrypto,Date,console,confirm:()=>true,setTimeout,clearTimeout, navigator:{clipboard:{writeText:async()=>{}}},localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v)},indexedDB:opts.db||new IDBFactory(),fetch:async u=>{const p=new URL(u).pathname.replace('/project/','');return {ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>JSON.parse(files(p))};}};
 sandbox.window=sandbox;sandbox.addEventListener=()=>{};const c=vm.createContext(sandbox);function run(p){vm.runInContext(files(p),c,{filename:p});if(win.MixLab)c.MixLab=win.MixLab;if(win.NewsStore)c.NewsStore=win.NewsStore;}
 run('assets/app.js');return {document,c,run,storage};
}
async function main(){
 let t=context('services/tempo.html');t.run('assets/tools.js');assert.match(t.document.querySelector('#out').textContent,/428,6/);t.document.querySelector('#bpm').value='120';t.document.querySelector('#tempo-form').onsubmit({preventDefault(){}});assert.match(t.document.querySelector('#out').textContent,/500/);assert.match(t.document.querySelector('#out').textContent,/2\s?000/);
 t=context('services/lufs.html');t.run('assets/tools.js');assert.match(t.document.querySelector('#out').textContent,/\+4/);t.document.querySelector('#target').value='-8';t.document.querySelector('#loudness-form').onsubmit({preventDefault(){}});assert.match(t.document.querySelector('#out').textContent,/перегрузке/);
 t=context('services/search.html',{query:'?q='+encodeURIComponent('голос')});t.run('assets/search-index.js');t.run('assets/search.js');assert.ok(t.document.querySelectorAll('.search-item').length>=4);assert.ok([...t.document.querySelectorAll('.search-item a')].every(a=>a.href.startsWith('https://test.github.io/project/')));
 t=context('services/search.html',{query:'?q='+encodeURIComponent('<img src=x onerror=alert(1)>')});t.run('assets/search-index.js');t.run('assets/search.js');assert.equal(t.document.querySelectorAll('#search-title img').length,0);
 const db=new IDBFactory(),storage=new Map();storage.set('mixlab_news',JSON.stringify([{h:'Старая запись',b:'Сохранённый текст',ts:1700000000000}]));
 t=context('admin/news.html',{db,storage});t.run('assets/news.js');await t.c.NewsStore.ready;assert.equal(t.c.NewsStore.mode,'local');let rows=await t.c.NewsStore.list();assert.ok(rows.some(x=>x.title==='Старая запись'&&x.local));
 const saved=await t.c.NewsStore.add('<img src=x onerror=alert(1)>','Текст\nвторой строкой');assert.ok(saved.id);await t.c.NewsStore.render();assert.equal(t.document.querySelectorAll('[data-news-feed] img').length,0);
 const other=context('services/news.html',{db,storage});other.run('assets/news.js');await other.c.NewsStore.ready;rows=await other.c.NewsStore.list();assert.ok(rows.some(x=>x.id===saved.id&&x.local));assert.equal(rows.filter(x=>x.title==='Старая запись').length,1);await other.c.NewsStore.render();assert.match(other.document.querySelector('[data-news-feed]').textContent,/Личная запись/);
 await assert.rejects(()=>other.c.NewsStore.add('','текст'));await assert.rejects(()=>other.c.NewsStore.add('x'.repeat(161),'текст'));
 const home=context('index.html',{db,storage});home.run('assets/news.js');await home.c.NewsStore.ready;await home.c.NewsStore.render();assert.equal(home.document.querySelectorAll('[data-news-feed] .news-item').length,2);assert.ok(home.document.querySelector('[data-news-feed]').textContent.includes(saved.title));
 const weather=context('index.html');weather.run('assets/widgets.js');await new Promise(r=>setTimeout(r,20));assert.equal(weather.document.querySelector('#city').value,'obninsk');assert.match(weather.document.querySelector('#weather').textContent,/°C/);assert.ok(weather.document.querySelector('#weather-source').href.includes(encodeURIComponent('Обнинск')));weather.document.querySelector('#city').value='moscow';weather.document.querySelector('#city').onchange();assert.equal(JSON.parse(weather.storage.get('mixlab_city')),'moscow');await weather.document.querySelector('#weather-refresh').onclick();assert.equal(weather.document.querySelector('#city').value,'moscow');assert.equal(weather.document.querySelector('#weather-refresh').disabled,false);
 console.log('OK: tempo and loudness calculations; full-text search and safe rendering; IndexedDB save/reload; legacy migration without duplicates; shared home/feed data; input validation; Obninsk default weather, city persistence and refresh.');
}
main().catch(err=>{console.error(err);process.exitCode=1;});
