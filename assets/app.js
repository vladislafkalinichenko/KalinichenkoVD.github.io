'use strict';
const APP_ROOT = new URL('../', document.currentScript.src);
const esc = (value='') => String(value).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function getJSON(key,fallback){try{return JSON.parse(localStorage.getItem(key))??fallback;}catch{return fallback;}}
function setJSON(key,value){try{localStorage.setItem(key,JSON.stringify(value));return true;}catch{return false;}}
function dateString(value){const d=new Date(value);return Number.isNaN(d.getTime())?'Дата не указана':d.toLocaleString('ru-RU',{dateStyle:'medium',timeStyle:'short'});}
function localDay(){return new Date().toLocaleDateString('en-CA');}
function siteURL(path){return new URL(path,APP_ROOT).href;}
function downloadJSON(value,name){const url=URL.createObjectURL(new Blob([JSON.stringify(value,null,2)+'\n'],{type:'application/json;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),2000);}
const pagePath=decodeURIComponent(location.pathname).slice(APP_ROOT.pathname.length)||'index.html';
document.querySelectorAll('[data-nav]').forEach(a=>{const p=new URL(a.href).pathname.slice(APP_ROOT.pathname.length);const category=pagePath.startsWith('admin/')||['services/stats.html','services/rating.html'].includes(pagePath)?'admin/index.html':pagePath.startsWith('services/')?'services/index.html':['process.html','eq.html','dynamics.html','reverb.html','stereo.html','references.html','glossary.html','checklist.html'].includes(pagePath)?'materials.html':pagePath==='xml.php'?'xml.html':pagePath;if(p===category)a.setAttribute('aria-current','page');});
const query=new URLSearchParams(location.search).get('q');if(query&&document.querySelector('#site-query'))document.querySelector('#site-query').value=query;
window.MixLab={esc,getJSON,setJSON,formatDate:dateString,localDay,siteURL,downloadJSON,pagePath};
MixLab.isStatic=location.hostname.endsWith('.github.io')||location.protocol==='file:';
// Initialize one shared session before the page's parallel requests.
MixLab.serverReady=MixLab.isStatic?Promise.resolve(null):(async()=>{
 const r=await fetch(siteURL('api/community.php')+'?action=status',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(15000)});
 const data=await r.json();if(!r.ok)throw new Error(data.error||'Не удалось загрузить сайт. Попробуй позже.');return data;
})();
MixLab.api=async function(action,body){
 await MixLab.serverReady;
 const url=siteURL('api/community.php');let csrf='';
 if(body!==undefined){const s=await fetch(url+'?action=status',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(15000)});const data=await s.json();if(!s.ok)throw new Error(data.error||'Не удалось выполнить действие.');csrf=data.csrf;}
 const r=await fetch(url+'?action='+encodeURIComponent(action),{method:body===undefined?'GET':'POST',headers:body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},credentials:'same-origin',cache:'no-store',body:body===undefined?undefined:JSON.stringify(body),signal:AbortSignal.timeout(15000)});
 const data=await r.json();if(!r.ok)throw new Error(data.error||'Не удалось выполнить действие.');return data;
};
MixLab.visitsReady=(async()=>{
 if(MixLab.isStatic){let s=getJSON('mixlab_stats',{total:0,days:{},pages:{}});s.total++;s.days[localDay()]=(s.days[localDay()]||0)+1;s.pages[pagePath]=(s.pages[pagePath]||0)+1;setJSON('mixlab_stats',s);document.querySelectorAll('[data-total-visits]').forEach(e=>e.textContent=s.total);return;}
 try{const s=await MixLab.api('visit',{page:pagePath});document.querySelectorAll('[data-total-visits]').forEach(e=>e.textContent=s.total);return s;}catch{document.querySelectorAll('[data-total-visits]').forEach(e=>e.textContent='—');}
})();
