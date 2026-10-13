'use strict';
(() => {
 const root=document.querySelector('#catalog');
 if(!root || root.dataset.mode!=='static') return; // PHP handles form submissions on the server.
 const form=document.querySelector('#catalog-form'),list=document.querySelector('#catalog-list');
 const cards=[...list.querySelectorAll('.catalog-item')];
 const normalize=s=>s.replace(/[A-ZА-ЯЁ]/g,c=>c.toLowerCase()).replace(/ё/g,'е').replace(/\s+/g,' ').trim();
 const fields=['q','category','level','sort','order'];
 const defaults={q:'',category:'',level:'',sort:'date',order:'descending'};
 function restore(){const params=new URLSearchParams(location.search);for(const key of fields){const field=form.elements.namedItem(key),value=params.get(key)??defaults[key];field.value=field.tagName==='SELECT'?[...field.options].some(x=>x.value===value)?value:defaults[key]:value.slice(0,120);}}
 function render(updateURL){
  const values=Object.fromEntries(fields.map(k=>[k,form.elements.namedItem(k).value]));
  const query=normalize(values.q);let count=0;
  // Sort uses the same values and deterministic ID tie-breaker as the XSL sheet.
  const sorted=[...cards].sort((a,b)=>{let diff;
   if(values.sort==='minutes') diff=Number(a.dataset.minutes)-Number(b.dataset.minutes);
   else {const x=a.dataset[values.sort],y=b.dataset[values.sort];diff=x<y?-1:x>y?1:0;}
   return (values.order==='descending'?-diff:diff)||a.id.localeCompare(b.id);
  });
  for(const card of sorted){card.hidden=!!((values.category&&card.dataset.category!==values.category)||(values.level&&card.dataset.level!==values.level)||!normalize([card.dataset.title,card.dataset.date,card.dataset.date.split('-').reverse().join('.'),card.dataset.category,card.dataset.level,card.dataset.minutes,card.dataset.summary,card.dataset.link].join(' ')).includes(query));if(!card.hidden)count++;list.append(card);}
  document.querySelectorAll('.sort-button').forEach(button=>{const active=button.value===values.sort,th=button.closest('th');if(active)th.setAttribute('aria-sort',values.order);else th.removeAttribute('aria-sort');button.textContent=button.textContent.replace(/ [↑↓↕]$/,'')+' '+(active?(values.order==='ascending'?'↑':'↓'):'↕');});
  document.querySelector('#catalog-count').textContent=`Найдено: ${count} из ${cards.length}`;
  document.querySelector('#catalog-empty').hidden=count>0;
  if(updateURL){const url=new URL(location.href);url.search='';for(const k of fields)if(values[k]!==defaults[k])url.searchParams.set(k,values[k]);history.pushState(null,'',url);}
 }
 form.addEventListener('submit',e=>{e.preventDefault();const column=e.submitter?.value;if(e.submitter?.name==='column'){const sort=form.elements.namedItem('sort'),order=form.elements.namedItem('order');order.value=sort.value===column&&order.value==='ascending'?'descending':'ascending';sort.value=column;}render(true);});
 document.querySelector('#catalog-reset').addEventListener('click',e=>{e.preventDefault();for(const k of fields)form.elements.namedItem(k).value=defaults[k];render(true);});
 document.querySelectorAll('.sort-button').forEach(button=>button.addEventListener('click',e=>{e.preventDefault();const sort=form.elements.namedItem('sort'),order=form.elements.namedItem('order');order.value=sort.value===button.value&&order.value==='ascending'?'descending':'ascending';sort.value=button.value;render(true);}));
 window.addEventListener('popstate',()=>{restore();render(false);});
 restore();render(false);
})();
