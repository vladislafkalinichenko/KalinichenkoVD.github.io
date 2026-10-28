// Administrator controls: login, searchable list, cancellation and completed deletion.
const fs=require('fs'),vm=require('vm'),assert=require('assert');
class Element{
 constructor(){this.value='';this.children=[];this.hidden=false;this.disabled=false;this.textContent='';}
 append(...nodes){this.children.push(...nodes);}replaceChildren(...nodes){this.children=[...nodes];}add(node){this.children.push(node);}reset(){}
}
const ids=['news-mode','server-login','logout','export-news','add-author','author-management','author-login-hint','publication-management','news-status','publication-search','publication-list','publication-status','login-form','admin-password','login-status','author-form','author-name','author-role','author-bio','author-status','export-status'];
const nodes=Object.fromEntries(ids.map(x=>[x,new Element()]));
let items=[{id:'a',title:'Вокал <script>',category:'Вокал',author_name:'Автор',published_at:'2026-10-23'},{id:'b',title:'Бас',category:'Бас',author_name:'Другой',published_at:'2026-10-23'}],removed=[],confirmed=false,rendered=0,savedAuthor=null;
const store={ready:Promise.resolve({authenticated:false}),mode:'server',canSave:true,list:async()=>items,catalog:async()=>({authors:[{id:'owner',name:'Владелец'}],categories:['Вокал']}),addAuthor:async fields=>{savedAuthor=fields;},login:async()=>{},logout:async()=>{},remove:async id=>{removed.push(id);items=items.filter(x=>x.id!==id);},render:async()=>{rendered++;}};
(async()=>{
 await vm.runInNewContext(fs.readFileSync('assets/news-admin.js','utf8'),{document:{querySelector:s=>nodes[s.slice(1)],createElement:()=>new Element()},NewsStore:store,MixLab:{siteURL:x=>'https://example.org/'+x,formatDate:x=>x},Option:function(text,value){this.textContent=text;this.value=value;},confirm:()=>confirmed});
 assert(nodes['publication-management'].hidden);assert(nodes['add-author'].disabled);
 await nodes['login-form'].onsubmit({preventDefault(){}});assert(!nodes['publication-management'].hidden);assert(!nodes['add-author'].disabled);assert.equal(nodes['publication-list'].children.length,2);
 nodes['publication-search'].value='ВОКАЛ';nodes['publication-search'].oninput();assert.equal(nodes['publication-list'].children.length,1);const row=nodes['publication-list'].children[0];assert.equal(row.children[0].children[0].textContent,'Вокал <script>');
 await row.children[2].onclick();assert.equal(removed.length,0);
 confirmed=true;await row.children[2].onclick();assert.deepEqual(removed,['a']);assert.equal(rendered,1);assert.equal(nodes['publication-status'].textContent,'Публикация удалена.');
 nodes['author-name'].value='Новый автор';nodes['author-role'].value='Автор';nodes['author-bio'].value='Описание';await nodes['author-form'].onsubmit({preventDefault(){}});assert.equal(savedAuthor.name,'Новый автор');assert(nodes['author-status'].textContent.includes('можно выбрать'));
 await nodes.logout.onclick();assert(nodes['publication-management'].hidden);assert(nodes['add-author'].disabled);
 const admin=fs.readFileSync('admin/news.html','utf8'),rss=fs.readFileSync('rss.php','utf8'),template=fs.readFileSync('rss.html','utf8');
 assert(!admin.includes('id="news-form"')&&!admin.includes('id="addNews"'));
 assert(admin.indexOf('id="author-management"')<admin.indexOf('<h2>Выгрузка ленты</h2>')&&admin.indexOf('<h2>Выгрузка ленты</h2>')<admin.indexOf('id="publication-management"'));
 assert(rss.includes('href="admin/news.html#author-management"'));assert(template.includes('href="#page-top"')&&template.includes('id="page-top"'));
 console.log('OK: administrator login enables publication list; search and plain titles; deletion confirmation/cancel/refresh; logout hides controls.');
})().catch(err=>{console.error(err);process.exit(1);});
