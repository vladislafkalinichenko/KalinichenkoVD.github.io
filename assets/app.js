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
let stats=getJSON('mixlab_stats',{total:0,days:{},pages:{}});
if(!stats||typeof stats!=='object'||!stats.days||!stats.pages)stats={total:0,days:{},pages:{}};
stats.total=(Number(stats.total)||0)+1;stats.days[localDay()]=(Number(stats.days[localDay()])||0)+1;stats.pages[pagePath]=(Number(stats.pages[pagePath])||0)+1;
setJSON('mixlab_stats',stats);document.querySelectorAll('[data-total-visits]').forEach(e=>e.textContent=stats.total);
window.MixLab={esc,getJSON,setJSON,formatDate:dateString,localDay,siteURL,downloadJSON,pagePath};
