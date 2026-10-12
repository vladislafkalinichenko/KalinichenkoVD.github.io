'use strict';
(async()=>{
 if(MixLab.isStatic){const script=document.createElement('script');script.src=MixLab.siteURL('assets/community-local.js');document.head.append(script);return;}
 const $=s=>document.querySelector(s),e=MixLab.esc;
 const stamp=x=>`<time datetime="${e(x.created_at)}">${MixLab.formatDate(x.created_at)}</time>`;
 const status=(el,text)=>{if(el)el.textContent=text;};
 const error=(el,err)=>status(el,err.message||'Не удалось выполнить действие. Попробуй позже.');
 async function submit(form,target,action,data,after){
  const button=form.querySelector('button[type="submit"],button');if(button)button.disabled=true;
  try{await MixLab.api(action,data);status(target,'Опубликовано.');await after();}catch(err){error(target,err);}finally{if(button)button.disabled=false;}
 }
 if($('#guest-form')){
  const render=async()=>{const d=await MixLab.api('guestbook');$('#guestList').innerHTML=d.items.length?d.items.map(x=>`<article class="search-item" id="message-${e(x.id)}"><h3>${e(x.author)}</h3>${stamp(x)}<p class="news-body">${e(x.body)}</p></article>`).join(''):'<p class="empty">Сообщений пока нет. Оставь первое.</p>';};
  $('#guest-form').onsubmit=ev=>{ev.preventDefault();submit(ev.currentTarget,$('#guest-status'),'guest_create',{author:$('#name').value.trim(),body:$('#msg').value.trim()},async()=>{$('#msg').value='';await render();});};
  try{await render();}catch(err){error($('#guestList'),err);}
  window.addEventListener('focus',()=>render().catch(()=>{}));
 }
 if($('#forum-form')){
  const render=async()=>{const d=await MixLab.api('forum');$('#topics').innerHTML=d.items.length?d.items.map(x=>`<article class="card spaced" id="topic-${e(x.id)}"><h3>${e(x.title)}</h3><p class="small muted">${e(x.author)} · ${stamp(x)}</p><p class="news-body">${e(x.body)}</p>${x.replies.map(r=>`<div class="reply"><b>${e(r.author)}</b> · ${stamp(r)}<p class="news-body">${e(r.body)}</p></div>`).join('')}<details><summary>Ответить</summary><form class="reply-form" data-topic="${e(x.id)}"><div class="field"><label for="reply-name-${e(x.id)}">Имя</label><input id="reply-name-${e(x.id)}" name="author" required maxlength="40"></div><div class="field"><label for="reply-body-${e(x.id)}">Ответ</label><textarea id="reply-body-${e(x.id)}" name="body" required maxlength="2000"></textarea></div><button class="btn" type="submit">Добавить ответ</button><p class="reply-status" role="status"></p></form></details></article>`).join(''):'<p class="empty">Пока нет тем. Начни обсуждение.</p>';};
  $('#forum-form').onsubmit=ev=>{ev.preventDefault();submit(ev.currentTarget,$('#forum-status'),'topic_create',{title:$('#topic').value.trim(),author:$('#author').value.trim(),body:$('#body').value.trim()},async()=>{$('#topic').value='';$('#body').value='';await render();});};
  $('#topics').onsubmit=ev=>{const f=ev.target;if(!f.matches('.reply-form'))return;ev.preventDefault();submit(f,f.querySelector('.reply-status'),'reply_create',{parent_id:f.dataset.topic,author:f.elements.author.value.trim(),body:f.elements.body.value.trim()},render);};
  try{await render();}catch(err){error($('#topics'),err);}
  window.addEventListener('focus',()=>render().catch(()=>{}));
 }
 if($('#pageStats')){
  try{await MixLab.visitsReady;const s=await MixLab.api('stats');$('#total').textContent=s.total;$('#today').textContent=s.today;$('#pages').textContent=s.pages.length;$('#visitors').textContent=s.visitors;$('#visitors-today').textContent=s.visitors_today;const labels=await fetch(MixLab.siteURL('data/pages.json')).then(r=>r.json());const names=Object.fromEntries(labels.map(x=>[x.url,x.title]));$('#pageStats').innerHTML=s.pages.length?s.pages.map(x=>`<div class="inline-stats"><a href="${MixLab.siteURL(x.page)}">${e(names[x.page]||x.page)}</a><b>${Number(x.views)}</b></div>`).join(''):'<p>Посещений пока нет.</p>';}catch(err){error($('#pageStats'),err);}
 }
 if($('#inputStars')){
  const render=d=>{$('#avg').textContent=d.average===null?'—':d.average.toLocaleString('ru-RU',{maximumFractionDigits:2})+' / 5';$('#count').textContent='Оценок посетителей: '+d.votes;$('#mine').textContent=d.mine?'Твоя оценка: '+d.mine+' / 5':'Выбери свою оценку';$('#inputStars').innerHTML=[1,2,3,4,5].map(i=>`<button class="star ${i<=d.mine?'active':''}" aria-label="Оценить на ${i} из 5" aria-pressed="${i===d.mine}" data-score="${i}">★</button>`).join('');};
  let saving=false;const rate=async(score)=>{if(saving)return;saving=true;try{render(await MixLab.api('rate',{score}));status($('#rating-status'),score?'Спасибо! Оценка сохранена.':'Твоя оценка удалена.');}catch(err){error($('#rating-status'),err);}finally{saving=false;}};
  $('#inputStars').onclick=ev=>{const b=ev.target.closest('[data-score]');if(b)rate(Number(b.dataset.score));};$('#clear').onclick=()=>rate(0);
  try{render(await MixLab.api('rating'));}catch(err){error($('#rating-status'),err);}
 }
})();
