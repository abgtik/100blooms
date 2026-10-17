/* English translation layer shared by the order page and Semak. Pages define the global LANG ('ms'|'en') before calling loadLang(). */
let I18N=null; // English dictionary (assets/i18n-en.json): x = exact text, r = [pattern, replacement] ($n translated group, %n kept as is)
function trCore(s,d){
  if(d>3||!s)return null;let o=I18N.x[s];if(o!==undefined)return o;
  if(s===s.toLowerCase()){o=I18N.lx[s];if(o!==undefined)return o.toLowerCase();}
  for(const [re,rp] of I18N.r){const m=s.match(re);if(m)return rp.replace(/([$%])(\d)/g,(_,k,i)=>{const g=m[+i]||'';if(k==='%')return g;const t=trCore(g,d+1);return t==null?g:t});}
  return null;
}
function tr(s){if(!I18N||!s)return s;const m=/^(\s*)([\s\S]*?)(\s*)$/.exec(s);if(!m[2])return s;const o=trCore(m[2],0);return o==null?s:m[1]+o+m[3];}
const TR_ATTR=['placeholder','aria-label','title','alt'];
function trDom(root){
  if(!I18N||!root)return;
  if(root.nodeType===3){const v=tr(root.nodeValue);if(v!==root.nodeValue)root.nodeValue=v;return;}
  if(root.nodeType!==1||root.closest&&root.closest('script,style,textarea,[data-notr]'))return;
  const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:n=>n.parentElement&&n.parentElement.closest('script,style,textarea,[data-notr]')?2:1});let n;const L=[];while(n=w.nextNode())L.push(n);
  L.forEach(t=>{const v=tr(t.nodeValue);if(v!==t.nodeValue)t.nodeValue=v});
  [root,...root.querySelectorAll('*')].forEach(e=>TR_ATTR.forEach(a=>{const v=e.getAttribute&&e.getAttribute(a);if(v){const t=tr(v);if(t!==v)e.setAttribute(a,t)}}));
}
async function loadLang(){
  if(LANG!=='en')return;
  try{const r=await fetch('/assets/i18n-en.json?v=1');const j=await r.json();
    j.r=j.r.map(([p,x])=>[new RegExp(p),x]);j.lx={};for(const k in j.x)j.lx[k.toLowerCase()]=j.x[k];I18N=j;}catch(e){return}
  document.documentElement.lang='en';trDom(document.body);
  new MutationObserver(ms=>ms.forEach(m=>{if(m.type==='characterData')trDom(m.target);else m.addedNodes.forEach(trDom)})).observe(document.body,{childList:true,subtree:true,characterData:true});
  ['alert','confirm','prompt'].forEach(f=>{const o=window[f].bind(window);window[f]=(msg,...a)=>o(tr(String(msg)),...a)});
}
