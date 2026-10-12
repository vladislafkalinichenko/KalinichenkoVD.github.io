/* NODE_PATH=<external node_modules with linkedom> node tests/catalog.cjs */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {parseHTML}=require('linkedom');
const root=path.resolve(__dirname,'..');
const {document,window}=parseHTML(fs.readFileSync(path.join(root,'xml.html'),'utf8'));
const form=document.querySelector('#catalog-form');
form.elements={namedItem:k=>form.querySelector(`[name="${k}"]`)};
// linkedom does not implement browser select.value setters or form.elements.
for(const s of document.querySelectorAll('select'))Object.defineProperty(s,'value',{value:s.querySelector('[selected]')?.value||s.querySelector('option').value,writable:true});
const location=new URL('https://test.github.io/site/xml.html?category='+encodeURIComponent('Стерео')+'&level='+encodeURIComponent('Продвинутый'));
let popstate;
vm.runInNewContext(fs.readFileSync(path.join(root,'assets/catalog.js'),'utf8'),{document,location,URL,URLSearchParams,history:{pushState(_,__,url){location.href=url.href;}},window:{addEventListener(name,fn){if(name==='popstate')popstate=fn;}}});
const ids=()=>[...document.querySelectorAll('.catalog-item')].filter(x=>!x.hidden).map(x=>x.id);
const click=()=>document.querySelector('#catalog-reset').dispatchEvent(new window.Event('click',{cancelable:true}));
const submit=()=>form.dispatchEvent(new window.Event('submit',{cancelable:true}));
assert.deepEqual(ids(),['mix-15']);
click();assert.equal(ids().length,18);assert.equal(location.search,'');
form.elements.namedItem('q').value='ГОЛОС';submit();const upper=ids();assert.ok(upper.length>0);
form.elements.namedItem('q').value='голос';submit();assert.deepEqual(ids(),upper);
form.elements.namedItem('q').value='<script>alert(1)</script>';submit();assert.equal(ids().length,0);assert.equal(document.querySelector('#catalog-empty').hidden,false);
click();form.elements.namedItem('sort').value='minutes';form.elements.namedItem('order').value='ascending';submit();assert.equal(ids()[0],'mix-17');assert.ok(location.search.includes('sort=minutes'));
for(const sort of ['date','title','minutes'])for(const order of ['ascending','descending']){
 form.elements.namedItem('sort').value=sort;form.elements.namedItem('order').value=order;submit();
 const values=[...document.querySelectorAll('.catalog-item')].map(x=>sort==='minutes'?Number(x.dataset[sort]):x.dataset[sort]);
 assert.ok(values.every((x,i)=>i===0||(order==='ascending'?values[i-1]<=x:values[i-1]>=x)));
}
location.search='?category='+encodeURIComponent('Тембр');popstate();assert.equal(ids().length,3);
location.search='?sort=bad&level=bad';popstate();assert.equal(ids().length,18);
console.log('OK: Pages initial URL, combined filters, case-insensitive search, empty result, reset, six sorts and Back navigation.');
