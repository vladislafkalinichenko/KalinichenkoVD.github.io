'use strict';
(async()=>{
 const $=s=>document.querySelector(s);const initial=await NewsStore.ready;
 function state(authenticated){const server=NewsStore.mode==='server';$('#news-mode').textContent='';$('#news-mode').hidden=true;$('#server-login').hidden=!server||authenticated;$('#logout').hidden=!server||!authenticated;$('#addNews').disabled=!NewsStore.canSave||(server&&!authenticated);$('#addNews').textContent=server?'Опубликовать':'Сохранить запись';$('#export-news').disabled=false;}
 state(initial.authenticated||false);if(initial.error)$('#news-status').textContent=initial.error;
 $('#login-form').onsubmit=async e=>{e.preventDefault();try{await NewsStore.login($('#admin-password').value);$('#admin-password').value='';$('#login-status').textContent='';state(true);}catch(err){$('#login-status').textContent=err.message;}};
 $('#logout').onclick=async()=>{try{await NewsStore.logout();state(false);}catch(err){$('#news-status').textContent=err.message;}};
 $('#news-form').onsubmit=async e=>{e.preventDefault();$('#addNews').disabled=true;try{const item=await NewsStore.add($('#headline').value,$('#newsbody').value);$('#news-form').reset();$('#news-status').textContent='Запись сохранена. Открой её в ленте по ссылке ниже.';$('#view-news').href=MixLab.siteURL('services/news.html')+'#news-'+encodeURIComponent(item.id);$('#view-news').hidden=false;$('#view-news').textContent='Посмотреть опубликованную новость →';await NewsStore.render();}catch(err){$('#news-status').textContent=err.message;}finally{$('#addNews').disabled=false;}};
 $('#export-news').onclick=async()=>{try{const items=(await NewsStore.list()).map(({local,...x})=>x);MixLab.downloadJSON({version:1,items},'news.json');$('#export-status').textContent='Лента скачана.';}catch(err){$('#export-status').textContent=err.message;}};
})();
