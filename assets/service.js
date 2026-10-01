// Независимые клиентские сервисы MIXLAB: не требуют серверной БД.
const $=s=>document.querySelector(s); const $$=s=>document.querySelectorAll(s);
function clamp(n,a,b){return Math.min(b,Math.max(a,n))}
function pct(n){return `${Math.round(n)}%`}
