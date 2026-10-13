/* Real HTTP/PHP + independent browser contexts represented with linkedom. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {parseHTML}=require('linkedom');
const root=path.resolve(__dirname,'..'),base=process.env.MIXLAB_TEST_BASE;
function page(file){
 const {document}=parseHTML(fs.readFileSync(path.join(root,file),'utf8'));const cookie=new Map();
 document.currentScript={src:base+'assets/app.js'};
 for(const link of document.querySelectorAll('a[href]'))link.setAttribute('href',new URL(link.getAttribute('href'),new URL(file,base)).href);
 const c={document,window:null,location:new URL(file,base),URL,URLSearchParams,Date,Blob,AbortSignal,console,setTimeout,clearTimeout,addEventListener(){},localStorage:{getItem(){return null;},setItem(){}},fetch:async(url,opts={})=>{
  const headers=new Headers(opts.headers);if(cookie.size)headers.set('Cookie',[...cookie].map(([k,v])=>k+'='+v).join('; '));
  const r=await fetch(url,{...opts,headers});for(const row of r.headers.getSetCookie()){const [k,v]=row.split(';')[0].split('=');cookie.set(k,v);}return r;
 }};
 c.window=c;vm.createContext(c);const run=p=>vm.runInContext(fs.readFileSync(path.join(root,p),'utf8'),c,{filename:p});run('assets/app.js');
 return {c,document,run,cookie};
}
async function until(test){for(let i=0;i<100;i++){if(test())return;await new Promise(r=>setTimeout(r,30));}throw Error('UI did not finish');}
(async()=>{
 const a=page('services/guestbook.html'),b=page('services/guestbook.html');await Promise.all([a.run('assets/community.js'),b.run('assets/community.js')]);await Promise.all([a.c.MixLab.visitsReady,b.c.MixLab.visitsReady]);
 assert.ok(a.cookie.has('PHPSESSID')&&a.cookie.has('mixlab_visitor'));
 a.document.querySelector('#name').value='Проверка интерфейса';a.document.querySelector('#msg').value='<img src=x onerror=alert(1)> общая запись';
 const form=a.document.querySelector('#guest-form');form.onsubmit({preventDefault(){},currentTarget:form});
 await until(()=>a.document.querySelector('#guest-status').textContent==='Опубликовано.');
 await b.run('assets/community.js');assert.match(b.document.querySelector('#guestList').textContent,/общая запись/);assert.equal(b.document.querySelectorAll('#guestList img').length,0);
 const rating=page('services/rating.html');await rating.run('assets/community.js');const button=rating.document.querySelector('[data-score="5"]');assert.ok(button);rating.document.querySelector('#inputStars').onclick({target:button});
 await until(()=>rating.document.querySelector('#rating-status').textContent.includes('сохранена'));assert.match(rating.document.querySelector('#mine').textContent,/5/);rating.document.querySelector('#clear').onclick();await until(()=>rating.document.querySelector('#rating-status').textContent.includes('удалена'));
 const stats=page('services/stats.html');await stats.run('assets/community.js');assert.ok(Number(stats.document.querySelector('#total').textContent)>0);assert.ok(stats.document.querySelector('#pageStats a'));assert.notEqual(stats.document.querySelector('[data-total-visits]').textContent,'—');
 console.log('OK: shared session initialization, guestbook UI save/read from another context, safe HTML rendering, rating UI save/remove and public statistics.');
})().catch(e=>{console.error(e);process.exitCode=1;});
