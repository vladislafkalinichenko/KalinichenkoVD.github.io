'use strict';
window.NewsStore=(()=>{
 let mode='loading',db=null,csrf='',serverError='',serverUnavailable=false;
 const api=MixLab.siteURL('api/news.php');
 async function request(action,body){await MixLab.serverReady;const r=await fetch(api+(action?'?action='+action:''),{method:body?'POST':'GET',headers:body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},body:body?JSON.stringify(body):undefined,credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(15000)});if(!r.headers.get('content-type')?.includes('application/json'))throw new Error('Не удалось загрузить новости. Попробуй позже.');const d=await r.json();if(!r.ok){const err=new Error(d.error||'Не удалось выполнить действие. Попробуй позже.');err.backend=d.backend;throw err;}if(d.csrf)csrf=d.csrf;return d;}
 function transaction(access,operation){return new Promise((resolve,reject)=>{const tx=db.transaction('news',access),req=operation(tx.objectStore('news'));let result;req.onsuccess=()=>result=req.result;tx.oncomplete=()=>resolve(result);tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error||new Error('Запись отменена'));});}
 function normalize(x){if(!x||typeof x.id!=='string'||typeof x.title!=='string'||typeof x.body!=='string'||!Number.isFinite(Date.parse(x.published_at)))return null;return {id:x.id,title:x.title,body:x.body,published_at:x.published_at,source:typeof x.source==='string'?x.source:'Свой сайт',source_url:typeof x.source_url==='string'?x.source_url:'',category:typeof x.category==='string'?x.category:'Обновления',author_id:typeof x.author_id==='string'?x.author_id:'vlad-kalinichenko',author_name:typeof x.author_name==='string'?x.author_name:'Калиниченко Владислав Дмитриевич'};}
 const ready=(async()=>{
  // Pages cannot execute PHP. Other hosts are probed to enable the server mode automatically.
  if(!location.hostname.endsWith('.github.io')&&location.protocol!=='file:'){
   try{const d=await request('status');if(d.backend==='php-pdo'){mode='server';return d;}}catch(err){serverError=err.message;mode='server';serverUnavailable=true;return {backend:'php-pdo',authenticated:false,error:err.message};}
  }
  mode='local';
  try{db=await new Promise((resolve,reject)=>{const r=indexedDB.open('mixlab-news',1);r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains('news'))r.result.createObjectStore('news',{keyPath:'id'});};r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);r.onblocked=()=>reject(new Error('Закрой старую вкладку сайта и повтори.'));});
   // Idempotent migration keeps old local news. Never erase the user's legacy storage.
   const old=MixLab.getJSON('mixlab_news',[]);for(let i=0;i<old.length;i++){const x=old[i];if(typeof x.h!=='string'||typeof x.b!=='string')continue;const ts=Number(x.ts)||Date.now(),id=`legacy-${i}-${ts}`;if(!await transaction('readonly',s=>s.get(id)))await transaction('readwrite',s=>s.put({id,title:x.h,body:x.b,published_at:new Date(ts).toISOString(),source:'Свой сайт',source_url:'',category:'Обновления'}));}
  }catch(err){serverError='Не удалось сохранить запись. Попробуй ещё раз.';db=null;}
  return {backend:'local',authenticated:false};
 })();
 async function list(){await ready;if(mode==='server'){const d=await request('list');return d.items.map(normalize).filter(Boolean);}
  let published=[];try{const r=await fetch(MixLab.siteURL('data/news.json'),{cache:'no-store'});if(!r.ok)throw new Error();const d=await r.json();published=(d.items||[]).map(normalize).filter(Boolean);}catch{throw new Error('Не удалось загрузить новости. Проверь подключение к интернету и попробуй ещё раз.');}
  const local=db?(await transaction('readonly',s=>s.getAll())).map(normalize).filter(Boolean):[];
  const ids=new Set(published.map(x=>x.id));return [...published,...local.filter(x=>!ids.has(x.id)).map(x=>({...x,local:true}))].sort((a,b)=>Date.parse(b.published_at)-Date.parse(a.published_at));
 }
 async function add(title,body,options={}){await ready;title=title.trim();body=body.trim();if(!title||!body||title.length>160||body.length>10000)throw new Error('Введи заголовок до 160 и текст до 10 000 символов.');if(mode==='server')return (await request('create',{title,body,...options})).item;if(!db)throw new Error(serverError);const item={id:crypto.randomUUID?crypto.randomUUID():'local-'+Date.now()+'-'+Math.random().toString(36).slice(2),title,body,published_at:new Date().toISOString(),source:'Свой сайт',source_url:'',category:'Обновления'};await transaction('readwrite',s=>s.put(item));return item;}
 async function render(){let items;try{items=await list();}catch(err){document.querySelectorAll('[data-news-feed]').forEach(el=>el.textContent=err.message);return;}
 document.querySelectorAll('[data-news-feed]').forEach(el=>{const max=Number(el.dataset.limit)||items.length;el.innerHTML=items.length?items.slice(0,max).map(x=>`<article class="news-item" id="news-${MixLab.esc(x.id)}"><h3><a href="${MixLab.siteURL('services/news.html')}#news-${encodeURIComponent(x.id)}">${MixLab.esc(x.title)}</a></h3><time datetime="${MixLab.esc(x.published_at)}">${MixLab.formatDate(x.published_at)}</time>${x.author_name?`<p class="small">${MixLab.esc(x.category)} · ${MixLab.esc(x.author_name)}</p>`:''}${x.local?'<span class="local-tag">Личная запись</span>':''}<p class="news-body">${MixLab.esc(el.dataset.limit&&x.body.length>150?x.body.slice(0,150)+'…':x.body)}</p></article>`).join(''):'<p>Новостей пока нет.</p>';});
 if(location.hash.startsWith('#news-'))document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView();
 }
 return {ready,list,add,render,catalog:()=>request('catalog'),addAuthor:fields=>request('create_author',fields),get mode(){return mode;},get canSave(){return (mode==='server'&&!serverUnavailable)||!!db;},login:password=>request('login',{password}),logout:()=>request('logout',{}),status:()=>request('status')};
})();
NewsStore.render();
window.addEventListener('pageshow',e=>{if(e.persisted)NewsStore.render();});
window.addEventListener('focus',()=>NewsStore.render());
