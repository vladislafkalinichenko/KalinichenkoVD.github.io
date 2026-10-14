'use strict';
(async()=>{
 const $=s=>document.querySelector(s),e=MixLab.esc;
 function info(message){$('#moderation-status').textContent=message;}
 const ready=await NewsStore.ready;
 async function render(){
  const state=await NewsStore.status();$('#moderation-login').hidden=state.authenticated;$('#moderation-panel').hidden=!state.authenticated;$('#moderation-logout').hidden=!state.authenticated;if(!state.authenticated){$('#moderation-list').replaceChildren();$('#rating-list').replaceChildren();return;}
  const d=await MixLab.api('moderation');$('#forbidden-words').value=d.words.join('\n');
  const labels={guest:'Гостевая книга',topic:'Тема форума',reply:'Ответ на форуме'};
  $('#moderation-list').innerHTML=d.items.length?d.items.map(x=>`<article class="card spaced"><p class="small">${labels[x.kind]} · ${MixLab.formatDate(x.created_at)}${Number(x.hidden)?' · Скрыто':''}</p><h3>${e(x.title||x.author)}</h3><p>${e(x.author)}</p><p class="news-body">${e(x.body)}</p><div class="actions"><button class="btn" data-visibility="${e(x.id)}" data-hidden="${Number(x.hidden)?'false':'true'}">${Number(x.hidden)?'Восстановить':'Скрыть'}</button><button class="btn" data-block="${e(x.id)}">Ограничить публикации автора</button>${Number(x.hidden)?`<button class="btn error" data-post-delete="${e(x.id)}" data-kind="${e(x.kind)}">Удалить навсегда</button>`:''}</div></article>`).join(''):'<p>Сообщений пока нет.</p>';
  $('#blocked-authors').innerHTML=d.blocks.length?d.blocks.map((token,i)=>`<div class="inline-stats"><span>Подключение ${i+1}</span><button class="btn" data-unblock="${e(token)}">Снять ограничение</button></div>`).join(''):'<p>Ограничений нет.</p>';
  $('#rating-summary').textContent=`Оценок: ${d.rating.votes}. Средний балл: ${d.rating.average===null?'—':d.rating.average.toLocaleString('ru-RU',{maximumFractionDigits:2})+' / 5'}.`;
  $('#rating-list').innerHTML=d.rating_items.length?d.rating_items.map(x=>`<article class="card spaced"><h3>Оценка ${e(x.id.slice(0,8))}</h3><p>Балл: <strong>${x.score} / 5</strong></p><button class="btn error" data-rating-delete="${e(x.id)}" data-score="${x.score}">Удалить оценку</button></article>`).join(''):'<p>Оценок пока нет.</p>';
 }
 $('#moderation-login').onsubmit=async ev=>{ev.preventDefault();try{await NewsStore.login($('#moderation-password').value);$('#moderation-password').value='';info('Вход выполнен.');await render();}catch(err){info(err.message);}};
 $('#moderation-logout').onclick=async()=>{try{await NewsStore.logout();await render();info('Выход выполнен.');}catch(err){info(err.message);}};
 $('#word-form').onsubmit=async ev=>{ev.preventDefault();try{await MixLab.api('words',{words:$('#forbidden-words').value.split('\n').map(x=>x.trim()).filter(Boolean)});info('Правила сохранены.');await render();}catch(err){info(err.message);}};
 $('#moderation-panel').onclick=async ev=>{const b=ev.target.closest('button');if(!b)return;let action,data,message='Изменения сохранены.';if(b.dataset.postDelete){
  const text=b.dataset.kind==='topic'?'Удалить эту тему и все её ответы навсегда? Они исчезнут с форума и из управления. Восстановить их не получится.':'Удалить это сообщение навсегда? Оно исчезнет с сайта и из управления. Восстановить его не получится.';
  if(!window.confirm(text))return;action='post_delete';data={id:b.dataset.postDelete};message='Сообщение удалено навсегда.';
 }else if(b.dataset.ratingDelete){
  if(!window.confirm(`Удалить оценку ${b.dataset.score} / 5 навсегда? Средний балл и число оценок будут пересчитаны. Восстановить этот голос не получится.`))return;action='rating_delete';data={id:b.dataset.ratingDelete};message='Оценка удалена. Рейтинг пересчитан.';
 }else if(b.dataset.visibility){action='visibility';data={id:b.dataset.visibility,hidden:b.dataset.hidden==='true'};}else if(b.dataset.block){action='block';data={id:b.dataset.block};}else if(b.dataset.unblock){action='unblock';data={host_token:b.dataset.unblock};}else return;
  b.disabled=true;try{await MixLab.api(action,data);info(message);await render();}catch(err){info(err.message);}finally{b.disabled=false;}
 };
 if(ready.backend!=='php-pdo'){info('Управление сообщениями доступно на основном сайте.');return;}
 try{await render();}catch(err){info(err.message);}
})();
