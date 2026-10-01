const SITE_PAGES = [
 {title:'Главная',url:'index.html',keywords:'сведение музыки миксинг mastering eq compression reverb'},
 {title:'О проекте',url:'about.html',keywords:'автор проект студия звук'},
 {title:'Пайплайн сведения',url:'process.html',keywords:'процесс сведения gain staging mix этапы'},
 {title:'Эквализация',url:'eq.html',keywords:'eq эквалайзер частоты фильтры'},
 {title:'Динамика',url:'dynamics.html',keywords:'compressor compressor gate limiter динамика'},
 {title:'Пространство',url:'reverb.html',keywords:'reverb delay пространство реверберация'},
 {title:'Стерео',url:'stereo.html',keywords:'stereo panorama phase mid side'},
 {title:'Референсы',url:'references.html',keywords:'референс трек ссылки плагины'},
 {title:'Глоссарий',url:'glossary.html',keywords:'термины lufs true peak crest factor'},
 {title:'Чек-лист',url:'checklist.html',keywords:'проверка микса checklist'},
 {title:'Сервисы',url:'services/index.html',keywords:'сервисы инструменты'},
 {title:'Веб-виджеты',url:'widgets.html',keywords:'погода курсы валют внешний поиск веб-сервисы'},
 {title:'Гостевая книга',url:'services/guestbook.html',keywords:'гостевая книга сообщения посетителей'},
 {title:'Форум',url:'services/forum.html',keywords:'форум обсуждение микс'},
 {title:'Новости',url:'services/news.html',keywords:'новости обновления'},
 {title:'Статистика',url:'services/stats.html',keywords:'счетчик статистика посещаемость'},
 {title:'Рейтинг',url:'services/rating.html',keywords:'рейтинг оценка сайта'},
 {title:'LUFS-калькулятор',url:'services/lufs.html',keywords:'lufs loudness громкость'},
 {title:'Compressor Assistant',url:'services/compressor.html',keywords:'compressor компрессор ratio attack release'},
 {title:'Stereo Checker',url:'services/stereo.html',keywords:'stereo stereo width mono phase'},
 {title:'BPM / Такты / Delay',url:'services/tempo.html',keywords:'bpm tempo delay ms такт'},
 {title:'Mix Planner',url:'services/mixplanner.html',keywords:'mix planner планирование сведения'},
];

function esc(s=''){return s.replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}
function formatDate(ts=Date.now()){return new Date(ts).toLocaleString('ru-RU',{dateStyle:'medium',timeStyle:'short'})}
function getJSON(k, fallback){try{return JSON.parse(localStorage.getItem(k)) ?? fallback}catch{return fallback}}
function setJSON(k,v){localStorage.setItem(k,JSON.stringify(v))}
function initNav(){const path=location.pathname.split('/').pop()||'index.html';document.querySelectorAll('[data-nav]').forEach(a=>{const href=a.getAttribute('href').split('/').pop();if(href===path)a.classList.add('active')})}
function renderFooter(){const el=document.querySelector('[data-footer]');if(!el)return;const inServices=location.pathname.includes('/services/');const base=inServices?'../':'';el.innerHTML=`<footer class="footer"><div class="container footer-grid"><div><div class="brand"><span class="logo"></span><span>MIXLAB</span></div><p>Практические материалы и инструменты для сведения музыки.</p></div><div><div class="kicker">Навигация</div><p><a href="${base}index.html">Главная</a><br><a href="${base}process.html">Процесс сведения</a><br><a href="${base}references.html">Референсы</a></p></div><div><div class="kicker">Сервисы</div><p><a href="${base}services/index.html">Все сервисы</a><br><a href="${base}services/search.html">Поиск по сайту</a><br><a href="${base}services/stats.html">Статистика</a></p></div></div></footer>`}
function trackVisit(){const today=new Date().toISOString().slice(0,10);let st=getJSON('mixlab_stats',{total:0,days:{},pages:{}});st.total++;st.days[today]=(st.days[today]||0)+1;const p=location.pathname.split('/').filter(Boolean).join('/')||'index.html';st.pages[p]=(st.pages[p]||0)+1;setJSON('mixlab_stats',st);const els=document.querySelectorAll('[data-total-visits]');els.forEach(e=>e.textContent=st.total)}

document.addEventListener('DOMContentLoaded',()=>{initNav();renderFooter();trackVisit()});
window.MixLab={SITE_PAGES,esc,formatDate,getJSON,setJSON,trackVisit};
