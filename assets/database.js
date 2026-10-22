 'use strict';
(()=>{
 const data=document.getElementById('database-pairs'),category=document.getElementById('db-category'),author=document.getElementById('db-author');
 if(!data||!category||!author)return;
 const pairs=JSON.parse(data.textContent),allCategories=[...new Set(pairs.map(x=>x.category))],allAuthors=[...new Map(pairs.map(x=>[x.author_id,x.author_name])).entries()];
 function fill(select,values,empty){const chosen=select.value;select.replaceChildren(new Option(empty,''));for(const [value,label] of values)select.add(new Option(label,value));select.value=values.some(x=>x[0]===chosen)?chosen:'';}
 function sync(){
  const cat=category.value,person=author.value;
  fill(category,allCategories.filter(c=>!person||pairs.some(x=>x.category===c&&x.author_id===person)).map(c=>[c,c]),'Все категории');
  fill(author,allAuthors.filter(([id])=>!cat||pairs.some(x=>x.author_id===id&&x.category===cat)),'Все авторы');
 }
 category.addEventListener('change',sync);author.addEventListener('change',sync);sync();
})();
