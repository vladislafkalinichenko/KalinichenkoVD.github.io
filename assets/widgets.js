'use strict';
(async()=>{
 const $=s=>document.querySelector(s),fmt=(x,d=1)=>Number(x).toLocaleString('ru-RU',{maximumFractionDigits:d,minimumFractionDigits:d});
 function condition(c){if(c===0)return 'Ясно';if(c<=3)return 'Облачно';if([45,48].includes(c))return 'Туман';if(c>=51&&c<=57)return 'Морось';if(c>=61&&c<=67)return 'Дождь';if(c>=71&&c<=77)return 'Снег';if(c>=80&&c<=82)return 'Ливень';if(c>=85&&c<=86)return 'Снегопад';if(c>=95)return 'Гроза';return 'Погодные условия';}
 function old(value,hours){const age=Date.now()-Date.parse(value);return !Number.isFinite(age)||age>hours*3600000;}
 async function load(file){const r=await fetch(MixLab.siteURL('data/'+file),{cache:'no-store',signal:AbortSignal.timeout(15000)});if(!r.ok)throw new Error();return r.json();}
 const city=$('#city'),button=$('#weather-refresh'),status=$('#weather-status');
 const cities=new Map();let requestId=0;
 const date=value=>new Date(value).toLocaleString('ru-RU',{dateStyle:'medium',timeStyle:'medium',timeZone:'Europe/Moscow'});
 const saved=MixLab.getJSON('mixlab_city','obninsk');if([...city.options].some(x=>x.value===saved))city.value=saved;
 function render(){
  const d=cities.get(city.value),c=d?.city;if(!c)return;
  $('#weather').innerHTML=`<div class="weather-value">${fmt(c.temperature)} °C</div><p>${condition(c.code)}<br>Ощущается как ${fmt(c.feels_like)} °C · ветер ${fmt(c.wind)} м/с</p><p class="small ${old(c.observed_at,8)?'warning':'muted'}">${old(c.observed_at,8)?'Данные устарели. ':''}Данные на: ${date(c.observed_at)}</p><p class="small muted">Источник проверен: ${d.checked_at?date(d.checked_at):'время не указано'}</p><p class="small muted">Данные: <a class="source" href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a> · <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a></p>`;
  $('#weather-source').href='https://yandex.ru/pogoda/?text='+encodeURIComponent(c.name);
 }
 async function seed(){const d=await load('weather.json');for(const [key,c] of Object.entries(d.cities)){const previous=cities.get(key)?.city;if(!previous||Date.parse(c.observed_at)>Date.parse(previous.observed_at)||Date.parse(c.observed_at)===Date.parse(previous.observed_at)&&Date.parse(c.updated_at)>Date.parse(previous.updated_at))cities.set(key,{city:c,checked_at:c.updated_at,status:'cached'});}render();}
 async function weather(refresh=false){
  const id=++requestId,key=city.value;
  MixLab.setJSON('mixlab_city',key);render();
  if(refresh){status.hidden=false;button.disabled=true;button.textContent='Проверяем…';status.textContent='Запрашиваем погоду…';status.className='small muted spaced';$('#weather').setAttribute('aria-busy','true');}
  try {
   let d;
   if(MixLab.isStatic){await seed();d=cities.get(key);if(!d)throw new Error();}
   else {const r=await fetch(MixLab.siteURL('api/weather.php')+'?'+new URLSearchParams({city:key,refresh:refresh?'1':'0'}),{cache:'no-store',signal:AbortSignal.timeout(15000)});d=await r.json();if(!r.ok||!d.city?.observed_at)throw new Error();}
   if(id!==requestId)return;
   cities.set(key,d);render();
   if(refresh){const messages={updated:'Погода обновлена.',unchanged:'Проверка завершена. У источника пока те же данные.',recent:'Данные уже проверены менее минуты назад.',busy:'Погода уже обновляется. Повтори проверку чуть позже.',error:'Не удалось связаться с источником. Показаны последние доступные данные.'};status.textContent=MixLab.isStatic?'Получена последняя версия погоды на сайте.':messages[d.status]||'Проверка завершена.';status.className='small '+(d.status==='error'?'warning':'muted')+' spaced';}
  } catch {
   if(id!==requestId)return;
   status.hidden=false;status.textContent='Не удалось проверить погоду. '+(cities.has(key)?'Показаны последние доступные данные.':'Попробуй позже или открой подробный прогноз.');status.className='small warning spaced';
  } finally {
   if(id===requestId){button.disabled=false;button.textContent='Обновить погоду';$('#weather').removeAttribute('aria-busy');}
  }
 }
 city.onchange=()=>{MixLab.setJSON('mixlab_city',city.value);status.textContent='';status.hidden=true;return weather(false);};
 button.onclick=()=>weather(true);
 async function initialWeather(){const before=requestId;try{await seed();}catch{}if(requestId===before)await weather(false);}
 async function rates(){try{const d=await load('currency.json');const labels={USD:'Доллар',EUR:'Евро',CNY:'Юань'};if(Object.keys(labels).some(k=>!Number.isFinite(d.rates[k])))throw new Error();$('#rates').innerHTML=Object.entries(labels).map(([k,name])=>`<div class="rate-row"><span>${name}</span><strong>${fmt(d.rates[k],2)} ₽</strong></div>`).join('')+`<p class="small spaced">Курс на ${new Date(d.effective_date+'T12:00:00').toLocaleDateString('ru-RU')}</p><p class="small ${old(d.updated_at,36)?'warning':'muted'}">${old(d.updated_at,36)?'Проверка данных задерживается. ':''}Проверено: ${MixLab.formatDate(d.updated_at)}</p>`;}catch{$('#rates').innerHTML='<p class="warning">Курсы временно недоступны. Актуальные значения можно посмотреть на сайте Банка России.</p>';}}
 await Promise.allSettled([initialWeather(),rates()]);
})();
