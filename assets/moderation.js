'use strict';
(async()=>{
 const $=s=>document.querySelector(s),e=MixLab.esc;
 function info(message){$('#moderation-status').textContent=message;}
 const ready=await NewsStore.ready;
 async function render(){
  const state=await NewsStore.status();$('#moderation-login').hidden=state.authenticated;$('#moderation-panel').hidden=!state.authenticated;$('#moderation-logout').hidden=!state.authenticated;if(!state.authenticated)return;
  const d=await MixLab.api('moderation');$('#forbidden-words').value=d.words.join('\n');
  const labels={guest:'Гостевая книга',topic:'Тема форума',reply:'Ответ на форуме'};
  $('#moderation-list').innerHTML=d.items.length?d.items.map(x=>`<article class="card spaced"><p class="small">${labels[x.kind]} · ${MixLab.formatDate(x.created_at)}${Number(x.hidden)?' · Скрыто':''}</p><h3>${e(x.title||x.author)}</h3><p>${e(x.author)}</p><p class="news-body">${e(x.body)}</p><button class="btn" data-visibility="${e(x.id)}" data-hidden="${Number(x.hidden)?'false':'true'}">${Number(x.hidden)?'Восстановить':'Скрыть'}</button> <button class="btn" data-block="${e(x.id)}">Ограничить публикации автора</button></article>`).join(''):'<p>Сообщений пока нет.</p>';
  $('#blocked-authors').innerHTML=d.blocks.length?d.blocks.map((token,i)=>`<div class="inline-stats"><span>Подключение ${i+1}</span><button class="btn" data-unblock="${e(token)}">Снять ограничение</button></div>`).join(''):'<p>Ограничений нет.</p>';
 }
 $('#moderation-login').onsubmit=async ev=>{ev.preventDefault();try{await NewsStore.login($('#moderation-password').value);$('#moderation-password').value='';info('Вход выполнен.');await render();}catch(err){info(err.message);}};
 $('#moderation-logout').onclick=async()=>{try{await NewsStore.logout();await render();info('Выход выполнен.');}catch(err){info(err.message);}};
 $('#word-form').onsubmit=async ev=>{ev.preventDefault();try{await MixLab.api('words',{words:$('#forbidden-words').value.split('\n').map(x=>x.trim()).filter(Boolean)});info('Правила сохранены.');await render();}catch(err){info(err.message);}};
 $('#moderation-panel').onclick=async ev=>{const b=ev.target.closest('button');if(!b)return;let action,data;if(b.dataset.visibility){action='visibility';data={id:b.dataset.visibility,hidden:b.dataset.hidden==='true'};}else if(b.dataset.block){action='block';data={id:b.dataset.block};}else if(b.dataset.unblock){action='unblock';data={host_token:b.dataset.unblock};}else return;
  b.disabled=true;try{await MixLab.api(action,data);info('Изменения сохранены.');await render();}catch(err){info(err.message);}finally{b.disabled=false;}
 };
 if(ready.backend!=='php-pdo'){info('Управление сообщениями доступно на основном сайте.');return;}
 try{await render();}catch(err){info(err.message);}
})();
