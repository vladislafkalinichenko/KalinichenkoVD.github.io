'use strict';
// News permalinks point into the complete mixed list, including legacy item URLs.
(()=>{const url=new URL(location.href);let id=url.searchParams.get('item');try{if(!id&&location.hash.startsWith('#news-'))id=decodeURIComponent(location.hash.slice(6));}catch{return;}if(!id||!/^[a-zA-Z0-9_-]{1,100}$/.test(id))return;const target=document.getElementById('news-'+id);if(target){target.scrollIntoView();return;}for(const key of ['source','q','rss_url','page','item'])url.searchParams.delete(key);url.hash='news-'+id;if(url.href!==location.href)location.replace(url.href);})();
