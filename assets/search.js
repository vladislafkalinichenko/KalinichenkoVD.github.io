'use strict';
const raw=(new URLSearchParams(location.search).get('q')||'').trim().slice(0,120);
const normalize=s=>s.toLocaleLowerCase('ru-RU').replace(/ё/g,'е');
const tokens=normalize(raw).split(/\s+/).filter(Boolean);
const matches=SITE_SEARCH.filter(x=>tokens.every(t=>normalize(`${x.title} ${x.description} ${x.text}`).includes(t)));
document.querySelector('#search-title').textContent=raw?`Поиск: «${raw}»`:'Все страницы';
document.querySelector('#search-count').textContent=`Найдено страниц: ${matches.length}`;
document.querySelector('#results').innerHTML=matches.length?matches.map(x=>`<article class="search-item"><h3><a href="${MixLab.siteURL(x.url)}">${MixLab.esc(x.title)}</a></h3><p>${MixLab.esc(x.description)}</p></article>`).join(''):'<p class="empty">Попробуй другое слово: «голос», «громкость», «эхо».</p>';
