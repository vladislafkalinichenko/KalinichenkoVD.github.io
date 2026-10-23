'use strict';
// Keep old bookmarked news anchors working after consolidation into one page.
(()=>{if(!location.hash.startsWith('#news-'))return;const url=new URL(location.href);if(url.searchParams.has('item'))return;let id;try{id=decodeURIComponent(location.hash.slice(6));}catch{return;}if(!/^[a-zA-Z0-9_-]{1,100}$/.test(id))return;url.searchParams.set('item',id);location.replace(url.href);})();
