(()=>{
'use strict';
const C=window.AstraeaVLP=window.AstraeaVLP||{};
if(!Array.isArray(C.services))C.services=[];
if(!C.categories)C.categories=['necessary','functional','statistics','marketing','external_media'];
if(!C.policyVersion)C.policyVersion=1;
if(!C.ajaxUrl){try{C.ajaxUrl=(location.origin||(location.protocol+'//'+location.host))+'/wp-admin/admin-ajax.php'}catch(e){C.ajaxUrl='/wp-admin/admin-ajax.php'}}
const choices={necessary:true,functional:false,statistics:false,marketing:false,external_media:false};
const services=Array.isArray(C.services)?C.services:[];
const siteHost=(location.hostname||'').toLowerCase();
let valid=false,trackingSent=false;
const getBanner=()=>document.getElementById('astraea-vlp');
const q=(s,r=document)=>r.querySelector(s);
const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const catForUrl=(v)=>{
try{
const u=new URL(v,location.href);
if(!/^https?:$/.test(u.protocol))return null;
for(const s of services){
for(const m of s.markers||[]){
if(String(m)&&String(v).toLowerCase().includes(String(m).toLowerCase()))return s.category||'functional'
}
}
if(u.hostname.toLowerCase()===siteHost)return null;
for(const s of services){
for(const h of s.hosts||[]){
const x=String(h).toLowerCase();
const host=u.hostname.toLowerCase();
if(host===x||host.endsWith('.'+x))return s.category||'functional'
}
}
return'functional'
}catch{return null}
};
const allowed=(c)=>!c||c==='necessary'||choices[c]===true;
const storeBlocked=(el,a,v,c)=>{
if(!el||!a||!v)return;
el.dataset.vlpBlocked='1';
el.dataset.vlpCategory=c||'functional';
el.setAttribute('data-vlp-original-'+a,v)
};
const styleCategory=(v)=>{
const ms=String(v||'').match(/https?:\/\/[^)\"'\s]+/gi)||[];
for(const u of ms){
const c=catForUrl(u);
if(c&&!allowed(c))return c
}
return null
};
const originalSet=Element.prototype.setAttribute;
Element.prototype.setAttribute=function(n,v){
const a=String(n).toLowerCase();
if(a==='style'){
const c=styleCategory(v);
if(c&&!allowed(c)){
storeBlocked(this,'style',String(v),c);
return
}
}
if(['src','href','srcset','poster','data'].includes(a)){
const c=catForUrl(String(v));
if(c&&!allowed(c)){
storeBlocked(this,a,String(v),c);
if(a==='src'&&this.tagName==='IFRAME')return originalSet.call(this,a,'about:blank');
return
}
}
return originalSet.call(this,n,v)
};
const necessaryCookie=(name)=>['astraea_vlp_consent','wordpress_','wordpress_sec_','wordpress_logged_in_','wp-settings-','comment_author_','comment_author_email_','comment_author_url_'].some(x=>name===x||name.startsWith(x));
const cookieCategory=(name)=>{
if(necessaryCookie(name))return'necessary';
for(const s of services){
for(const c of s.cookies||[]){
const x=String(c);
if(x&&(name===x||name.startsWith(x)))return s.category||'functional'
}
}
return'functional'
};
try{
const cd=Object.getOwnPropertyDescriptor(Document.prototype,'cookie');
if(cd&&cd.set&&cd.get&&cd.configurable)Object.defineProperty(Document.prototype,'cookie',{configurable:true,enumerable:cd.enumerable,get:cd.get,set:function(v){const name=String(v).split('=',1)[0].trim();const c=cookieCategory(name);if(!allowed(c))return;return cd.set.call(this,v)}})
}catch{}
try{
const sp=CSSStyleDeclaration.prototype.setProperty;
CSSStyleDeclaration.prototype.setProperty=function(name,value,priority){const c=styleCategory(value);if(c&&!allowed(c))return;return sp.call(this,name,value,priority)}
}catch{}
try{
const ir=CSSStyleSheet.prototype.insertRule;
CSSStyleSheet.prototype.insertRule=function(rule,index){const c=styleCategory(rule);if(c&&!allowed(c))throw new DOMException('Blocked by VLP consent policy','SecurityError');return ir.call(this,rule,index)}
}catch{}
const wrapCtor=(name)=>{
const Native=window[name];
if(typeof Native!=='function')return;
const Wrapped=function(url,...args){const c=catForUrl(String(url));if(c&&!allowed(c))throw new DOMException('Blocked by VLP consent policy','SecurityError');return new Native(url,...args)};
Wrapped.prototype=Native.prototype;
try{Object.setPrototypeOf(Wrapped,Native)}catch{}
window[name]=Wrapped
};
wrapCtor('WebSocket');
wrapCtor('EventSource');
const nativeFetch=window.fetch?.bind(window);
if(nativeFetch)window.fetch=(input,init)=>{const url=typeof input==='string'?input:(input&&input.url)||'';const c=catForUrl(url);if(c&&!allowed(c))return Promise.reject(new TypeError('Blocked by VLP consent policy'));return nativeFetch(input,init)};
const nativeBeacon=navigator.sendBeacon?.bind(navigator);
if(nativeBeacon)navigator.sendBeacon=(url,data)=>{const c=catForUrl(String(url));if(c&&!allowed(c))return false;return nativeBeacon(url,data)};
const xo=XMLHttpRequest.prototype.open;
XMLHttpRequest.prototype.open=function(method,url,...rest){const c=catForUrl(String(url));if(c&&!allowed(c))throw new DOMException('Blocked by VLP consent policy','SecurityError');return xo.call(this,method,url,...rest)};
const patchProp=(proto,prop,attr=prop)=>{
try{
const d=Object.getOwnPropertyDescriptor(proto,prop);
if(!d||!d.set||!d.get||!d.configurable)return;
Object.defineProperty(proto,prop,{configurable:true,enumerable:d.enumerable,get:d.get,set:function(v){const c=catForUrl(String(v));if(c&&!allowed(c)){storeBlocked(this,attr,String(v),c);if(this instanceof HTMLIFrameElement&&attr==='src')return d.set.call(this,'about:blank');return}return d.set.call(this,v)}})
}catch{}
};
[[HTMLScriptElement,'src'],[HTMLImageElement,'src'],[HTMLImageElement,'srcset'],[HTMLIFrameElement,'src'],[HTMLLinkElement,'href'],[HTMLSourceElement,'src'],[HTMLSourceElement,'srcset'],[HTMLVideoElement,'src'],[HTMLVideoElement,'poster'],[HTMLAudioElement,'src']].forEach(x=>patchProp(x[0].prototype,x[1]));
const catForInline=(v)=>{
const x=String(v||'').toLowerCase();
for(const s of services){
for(const m of s.markers||[]){
if(String(m)&&x.includes(String(m).toLowerCase()))return s.category||'functional'
}
}
return null
};
const sanitize=(node)=>{
if(!(node instanceof Element))return;
const nodes=[node,...qa('script,iframe,img,link,source,video,audio,track,embed,object,input[type=image]',node)];
for(const el of nodes){
if(el.tagName==='SCRIPT'&&!el.getAttribute('src')){
const c=catForInline(el.textContent);
if(c&&!allowed(c)){el.dataset.vlpBlocked='1';el.dataset.vlpCategory=c;el.type='text/plain'}
}
for(const a of ['src','href','srcset','poster','data']){
const v=el.getAttribute(a);
if(!v)continue;
const c=catForUrl(v);
if(c&&!allowed(c)){
storeBlocked(el,a,v,c);
if(a==='src'&&el.tagName==='IFRAME')originalSet.call(el,a,'about:blank');
else el.removeAttribute(a);
if(el.tagName==='SCRIPT')el.type='text/plain'
}
}
}
};
const append=Node.prototype.appendChild,insert=Node.prototype.insertBefore,replace=Node.prototype.replaceChild;
Node.prototype.appendChild=function(n){sanitize(n);return append.call(this,n)};
Node.prototype.insertBefore=function(n,r){sanitize(n);return insert.call(this,n,r)};
Node.prototype.replaceChild=function(n,o){sanitize(n);return replace.call(this,n,o)};
const activate=()=>{
qa('[data-vlp-blocked="1"]').forEach(el=>{
const c=el.dataset.vlpCategory||'functional';
if(!allowed(c))return;
if(el.tagName==='SCRIPT'){
const s=document.createElement('script');
for(const a of Array.from(el.attributes)){
if(a.name.startsWith('data-vlp-original-')||a.name==='data-vlp-blocked'||a.name==='type')continue;
s.setAttribute(a.name,a.value)
}
const src=el.getAttribute('data-vlp-original-src');
const type=el.getAttribute('data-vlp-original-type');
if(type)s.type=type;
if(src)s.src=src;
else s.text=el.textContent||'';
el.replaceWith(s);
return
}
for(const a of ['src','href','srcset','poster','data','style']){
const v=el.getAttribute('data-vlp-original-'+a);
if(v!==null)originalSet.call(el,a,v)
}
el.removeAttribute('data-vlp-blocked')
});
sendTrack()
};
const request=(action,method='GET',data=null)=>{
try{
const url=new URL(C.ajaxUrl,location.href);
url.searchParams.set('action',action);
if(method==='GET')return fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(r=>r.text()).then(t=>{try{return JSON.parse(t)}catch{return null}}).catch(()=>null);
const fd=new FormData();
fd.append('action',action);
fd.append('nonce',C.nonce||'');
if(data)for(const[k,v]of Object.entries(data))fd.append(k,v?'1':'0');
return fetch(C.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd,headers:{'Accept':'application/json'}}).then(r=>r.text()).then(t=>{try{return JSON.parse(t)}catch{return null}}).catch(()=>null);
}catch{
return Promise.resolve(null);
}
};
const hideBanner=()=>{
const b=getBanner();
if(b){
b.hidden=true;
b.setAttribute('hidden','hidden');
b.style.display='none';
}
};
const showBanner=()=>{
const b=getBanner();
if(b){
b.hidden=false;
b.removeAttribute('hidden');
b.style.display='';
}
};
const toggleDetail=()=>{
const b=getBanner();
if(!b)return;
const d=q('#avlp-detail',b);
if(!d)return;
const isHidden=d.hidden||d.hasAttribute('hidden')||d.style.display==='none';
if(isHidden){
d.hidden=false;
d.removeAttribute('hidden');
d.style.display='grid';
}else{
d.hidden=true;
d.setAttribute('hidden','hidden');
d.style.display='none';
}
const s=q('[data-vlp-action="save"]',b);
if(s){
s.hidden=!isHidden;
if(isHidden){s.removeAttribute('hidden');s.style.display=''}
else{s.setAttribute('hidden','hidden');s.style.display='none'}
}
};
const handleAction=(btn)=>{
if(!btn)return;
const act=btn.getAttribute('data-vlp-action');
if(act==='accept'){
save({necessary:true,functional:true,statistics:true,marketing:true,external_media:true});
}else if(act==='reject'){
save({necessary:true,functional:false,statistics:false,marketing:false,external_media:false});
}else if(act==='settings'){
toggleDetail();
}else if(act==='save'){
const n={necessary:true};
qa('[data-vlp-choice]',document).forEach(i=>n[i.dataset.vlpChoice]=Boolean(i.checked));
save(n);
}
};
document.addEventListener('click',e=>{
const btn=e.target&&e.target.closest?e.target.closest('[data-vlp-action]'):null;
if(btn){
e.preventDefault();
e.stopPropagation();
handleAction(btn);
}
},true);
document.addEventListener('click',e=>{
const btn=e.target&&e.target.closest?e.target.closest('[data-vlp-action]'):null;
if(btn)handleAction(btn);
},false);
const initBanner=()=>{
const b=getBanner();
if(!b)return;
const d=q('#avlp-detail',b);
if(d&&!b.dataset.vlpOpened){
d.hidden=true;
d.setAttribute('hidden','hidden');
d.style.display='none';
}
const s=q('[data-vlp-action="save"]',b);
if(s&&!b.dataset.vlpOpened){
s.hidden=true;
s.setAttribute('hidden','hidden');
s.style.display='none';
}
if(!b.dataset.vlpBound){
b.dataset.vlpBound='1';
q('[data-vlp-action="settings"]',b)?.addEventListener('click',e=>{e.preventDefault();toggleDetail()});
q('[data-vlp-action="accept"]',b)?.addEventListener('click',e=>{e.preventDefault();save({necessary:true,functional:true,statistics:true,marketing:true,external_media:true})});
q('[data-vlp-action="reject"]',b)?.addEventListener('click',e=>{e.preventDefault();save({necessary:true,functional:false,statistics:false,marketing:false,external_media:false})});
q('[data-vlp-action="save"]',b)?.addEventListener('click',e=>{
e.preventDefault();
const n={necessary:true};
qa('[data-vlp-choice]',b).forEach(i=>n[i.dataset.vlpChoice]=Boolean(i.checked));
save(n);
});
const svc=q('#avlp-services',b);
if(svc&&!svc.dataset.vlpLoaded){
svc.dataset.vlpLoaded='1';
for(const s of services){
const x=document.createElement('span');
x.className='avlp-service';
x.textContent=`${s.name} · ${s.category}`;
svc.appendChild(x);
}
}
}
qa('[data-vlp-choice]',b).forEach(i=>{
const cat=i.dataset.vlpChoice;
if(cat==='necessary'){i.checked=true;i.disabled=true}
else{i.checked=Boolean(choices[cat])}
});
if(valid){hideBanner()}else{showBanner()}
};
const apply=(st)=>{
valid=Boolean(st&&st.valid);
Object.assign(choices,{necessary:true},st&&st.choices||{});
initBanner();
activate();
};
const save=(next)=>{
valid=true;
Object.assign(choices,{necessary:true},next||{});
try{localStorage.setItem('astraea_vlp_consent',JSON.stringify({valid:true,policy_version:C.policyVersion||1,choices}))}catch(e){}
hideBanner();
try{activate()}catch(e){}
request('astraea_vlp_consent','POST',next).then(j=>{
if(j&&j.success&&j.data&&j.data.choices){
Object.assign(choices,j.data.choices);
}
}).catch(()=>null);
};
const sendTrack=()=>{
if(trackingSent||!valid||!choices.statistics||!C.dattrack)return;
trackingSent=true;
const url=new URL(C.ajaxUrl,location.href);
url.searchParams.set('action','astraea_vlp_dattrack');
fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':C.nonce||''},body:JSON.stringify({path:location.pathname,title:document.title,referrer:document.referrer,language:navigator.language||'',viewport_width:innerWidth,viewport_height:innerHeight})}).catch(()=>{});
};
const manage=document.createElement('button');
manage.type='button';
manage.className='avlp-manage';
manage.textContent='Privatsphäre';
manage.addEventListener('click',e=>{
e.preventDefault();
showBanner();
const b=getBanner();
if(b)b.dataset.vlpOpened='1';
const d=q('#avlp-detail',b);
if(d){d.hidden=false;d.removeAttribute('hidden');d.style.display='grid'}
const s=q('[data-vlp-action="save"]',b);
if(s){s.hidden=false;s.removeAttribute('hidden');s.style.display=''}
});
C.acceptAll=()=>save({necessary:true,functional:true,statistics:true,marketing:true,external_media:true});
C.rejectAll=()=>save({necessary:true,functional:false,statistics:false,marketing:false,external_media:false});
C.toggleSettings=()=>toggleDetail();
C.saveChoices=()=>{
const n={necessary:true};
qa('[data-vlp-choice]',document).forEach(i=>n[i.dataset.vlpChoice]=Boolean(i.checked));
save(n);
};
C.show=()=>showBanner();
C.hide=()=>hideBanner();
const attachManage=()=>{
try{
if(document.body&&!manage.isConnected&&!document.querySelector('.avlp-manage')){
document.body.appendChild(manage);
}
}catch(e){}
};
if(document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',()=>{attachManage();initBanner()},{once:true});
}else{
attachManage();
initBanner();
}
try{if(typeof window.addEventListener==='function')window.addEventListener('load',()=>{attachManage();initBanner()},{once:true})}catch(e){}
try{
const loc=JSON.parse(localStorage.getItem('astraea_vlp_consent')||'null');
if(loc&&loc.valid&&(loc.policy_version===(C.policyVersion||1)||loc.policyVersion===(C.policyVersion||1))){
valid=true;
Object.assign(choices,{necessary:true},loc.choices||{});
}
}catch(e){}
if(C.initialConsent)apply(C.initialConsent);
request('astraea_vlp_state').then(j=>{
if(!valid&&j&&j.success&&j.data&&j.data.valid)apply(j.data);
}).catch(()=>{});
try{
new MutationObserver(ms=>{
for(const m of ms)for(const n of m.addedNodes){try{sanitize(n)}catch(e){}}
if(getBanner())initBanner();
}).observe(document.documentElement,{childList:true,subtree:true});
}catch(e){}
})();

