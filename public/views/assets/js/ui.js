/* ============================================================================
   UI kit: icon sprite, DOM helpers, toasts, modal/drawer, tables, forms,
   chips/badges, Jalali datepicker, avatar generator. Exposes window.UI.
   ============================================================================ */
(function(){
'use strict';

var SPRITE='<svg style="display:none" aria-hidden="true">'
+'<symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/></symbol>'
+'<symbol id="i-horse" viewBox="0 0 24 24"><path d="M6.5 21v-8.2a5.5 5.5 0 0 1 11 0V21"/><path d="M10 21v-8a2 2 0 0 1 4 0v8"/><path d="M4.5 21h3M16.5 21h3"/></symbol>'
+'<symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>'
+'<symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></symbol>'
+'<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="17" rx="2.5"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></symbol>'
+'<symbol id="i-trophy" viewBox="0 0 24 24"><path d="M8 21h8M12 17.2V21M7 3.8h10v4.7a5 5 0 0 1-10 0V3.8Z"/><path d="M7 5.5H4.2v1.8a3 3 0 0 0 2.8 3M17 5.5h2.8v1.8a3 3 0 0 1-2.8 3"/></symbol>'
+'<symbol id="i-wallet" viewBox="0 0 24 24"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18v3"/><path d="M3 7.5V18a2.5 2.5 0 0 0 2.5 2.5H19a2 2 0 0 0 2-2v-9H5.5A2.5 2.5 0 0 1 3 7.5Z"/><circle cx="17" cy="14" r="1.2"/></symbol>'
+'<symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><rect x="7" y="11" width="3.2" height="7" rx="1"/><rect x="12.4" y="7" width="3.2" height="11" rx="1"/><rect x="17.8" y="13" width="3.2" height="5" rx="1"/></symbol>'
+'<symbol id="i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.9 19.3a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.7 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6h.08A1.7 1.7 0 0 0 10.1 3.05V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v.08a1.7 1.7 0 0 0 1.56 1.02H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1.03Z"/></symbol>'
+'<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4-4"/></symbol>'
+'<symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8.5a6 6 0 1 0-12 0c0 7-3 8.5-3 8.5h18s-3-1.5-3-8.5"/><path d="M13.7 20.5a2 2 0 0 1-3.4 0"/></symbol>'
+'<symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2.2M12 19.8V22M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2 12h2.2M19.8 12H22M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/></symbol>'
+'<symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.9A9 9 0 1 1 11.1 3a7 7 0 0 0 9.9 9.9Z"/></symbol>'
+'<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>'
+'<symbol id="i-dl" viewBox="0 0 24 24"><path d="M21 15.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3.5"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5M12 15V3"/></symbol>'
+'<symbol id="i-cr" viewBox="0 0 24 24"><path d="m9.5 6 6 6-6 6"/></symbol>'
+'<symbol id="i-cl" viewBox="0 0 24 24"><path d="m14.5 6-6 6 6 6"/></symbol>'
+'<symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>'
+'<symbol id="i-check" viewBox="0 0 24 24"><path d="m20 6.5-11 11-5-5"/></symbol>'
+'<symbol id="i-ccheck" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.4 2.4 4.6-4.9"/></symbol>'
+'<symbol id="i-menu" viewBox="0 0 24 24"><path d="M3.5 6.5h17M3.5 12h17M3.5 17.5h17"/></symbol>'
+'<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 21.5s7.5-3.6 7.5-9.4V5.4L12 2.6 4.5 5.4v6.7c0 5.8 7.5 9.4 7.5 9.4Z"/><path d="m9.2 12 2 2 3.6-3.9"/></symbol>'
+'<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.2V12l3.2 2"/></symbol>'
+'<symbol id="i-mail" viewBox="0 0 24 24"><rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m3 7 9 6 9-6"/></symbol>'
+'<symbol id="i-phone" viewBox="0 0 24 24"><path d="M21.5 16.9v2.6a2 2 0 0 1-2.2 2 19.6 19.6 0 0 1-8.5-3 19.3 19.3 0 0 1-6-6 19.6 19.6 0 0 1-3-8.6 2 2 0 0 1 2-2.2h2.6a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L7.5 9.5a16 16 0 0 0 6 6l1.2-1.15a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7a2 2 0 0 1 1.7 2.05Z"/></symbol>'
+'<symbol id="i-edit" viewBox="0 0 24 24"><path d="M12 20.5h8.5"/><path d="M16.4 3.6a2.1 2.1 0 0 1 3 3L7.4 18.6l-4 1 1-4L16.4 3.6Z"/></symbol>'
+'<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3.5 6.5h17M9 6.5V4h6v2.5M18.5 6.5 17.6 20a1.5 1.5 0 0 1-1.5 1.4H7.9a1.5 1.5 0 0 1-1.5-1.4L5.5 6.5"/><path d="M10 11v6M14 11v6"/></symbol>'
+'<symbol id="i-logout" viewBox="0 0 24 24"><path d="M9.5 21H5.5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 16.5 4.5-4.5L16 7.5M20.5 12H9"/></symbol>'
+'<symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2.5H6.5a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V8l-5.5-5.5Z"/><path d="M14 2.5V8h5.5"/></symbol>'
+'<symbol id="i-book" viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2.5H20v19H6.5A2.5 2.5 0 0 1 4 19V5a2.5 2.5 0 0 1 2.5-2.5Z"/></symbol>'
+'<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20.5 11a8.5 8.5 0 1 0-.9 5"/><path d="M20.8 4.5V11h-6.3"/></symbol>'
+'<symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8h.01"/></symbol>'
+'<symbol id="i-print" viewBox="0 0 24 24"><path d="M6.5 9V3.5h11V9"/><rect x="3.5" y="9" width="17" height="7.5" rx="2"/><path d="M6.5 14h11v6.5h-11z"/></symbol>'
+'<symbol id="i-send" viewBox="0 0 24 24"><path d="M21.5 2.5 11 13M21.5 2.5l-6.8 19-3.7-8.5-8.5-3.7 19-6.8Z"/></symbol>'
+'<symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10.5" width="16" height="11" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></symbol>'
+'<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>'
+'<symbol id="i-swap" viewBox="0 0 24 24"><path d="M4 8h13l-3.5-3.5M20 16H7l3.5 3.5"/></symbol>'
+'<symbol id="i-star" viewBox="0 0 24 24"><path d="m12 2.8 2.9 5.9 6.5.95-4.7 4.6 1.1 6.45L12 17.65 6.2 20.7l1.1-6.45-4.7-4.6 6.5-.95L12 2.8Z"/></symbol>'
+'<symbol id="i-flag" viewBox="0 0 24 24"><path d="M5 21V4M5 4.5h11l-1.8 3.7L16 12H5"/></symbol>'
+'<symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></symbol>'
+'<symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .9-1 1.6v.3M12 17.2h.01"/></symbol>'
+'<symbol id="i-ban" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/></symbol>'
+'<symbol id="i-up" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></symbol>'
+'<symbol id="i-down" viewBox="0 0 24 24"><path d="M12 5v14M5 12l7 7 7-7"/></symbol>'
+'<symbol id="i-bag" viewBox="0 0 24 24"><path d="M6 8h12l1 13H5L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></symbol>'
+'<symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="m4 17 5-4 4 3 3-2 4 3"/></symbol>'
+'<symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="8.5" ry="3"/><path d="M3.5 5.5V18.5c0 1.66 3.8 3 8.5 3s8.5-1.34 8.5-3V5.5"/><path d="M3.5 12c0 1.66 3.8 3 8.5 3s8.5-1.34 8.5-3"/></symbol>'
+'<symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3.2 9h17.6M3.2 15h17.6"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18Z"/></symbol>'
+'<symbol id="i-filter" viewBox="0 0 24 24"><path d="M3 5.5h18l-7 8v5.5l-4 2v-7.5l-7-8Z"/></symbol>'
+'<symbol id="i-x2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></symbol>'
+'<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 13.5a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 0 0-5.7-5.7l-1.4 1.4"/><path d="M14 10.5a4 4 0 0 0-5.7 0l-2.8 2.8a4 4 0 0 0 5.7 5.7l1.4-1.4"/></symbol>'
+'<symbol id="i-copy" viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 5.5v-1a1 1 0 0 0-1-1h-10a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h1"/></symbol>'
+'<symbol id="i-share" viewBox="0 0 24 24"><circle cx="17.5" cy="5.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/><path d="m8.8 10.8 6.4-3.6M8.8 13.2l6.4 3.6"/></symbol>'
+'<symbol id="i-bold" viewBox="0 0 24 24"><path d="M7 4.5h5.8a3.5 3.5 0 0 1 0 7H7zM7 11.5h6.6a4 4 0 0 1 0 8H7z"/></symbol>'
+'<symbol id="i-italic" viewBox="0 0 24 24"><path d="M14.5 4.5h-5M14.5 19.5h-5M13.5 4.5l-3 15"/></symbol>'
+'<symbol id="i-h2" viewBox="0 0 24 24"><path d="M4 6v12M12 6v12M4 12h8M16.5 10.5a2.5 2.5 0 1 1 4.5-1.5l-4.5 8.5h5"/></symbol>'
+'<symbol id="i-ul" viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="18" r="1.2"/></symbol>'
+'<symbol id="i-ol" viewBox="0 0 24 24"><path d="M10 6h10M10 12h10M10 18h10M4 5.5h1v4M3.5 15.5c0-.7.5-1.2 1.2-1.2s1.3.5 1.3 1.2S4 18.5 3.2 19.5H6"/></symbol>'
+'<symbol id="i-underline" viewBox="0 0 24 24"><path d="M7 4v6a5 5 0 0 0 10 0V4M5 20h14"/></symbol>'
+'<symbol id="i-strike" viewBox="0 0 24 24"><path d="M4 12h16M7.5 8.2C8.4 6.7 10 6 12 6c3 0 5 1.4 5 3.4 0 1.2-.7 2.1-1.9 2.7M16.4 15c-.7 1.9-2.4 3-4.6 3-3.1 0-5.2-1.4-5.2-3.4"/></symbol>'
+'<symbol id="i-h4" viewBox="0 0 24 24"><path d="M4 6v12M12 6v12M4 12h8M20 10l-4.5 8h9"/></symbol>'
+'<symbol id="i-p" viewBox="0 0 24 24"><path d="M14 6H8v12M8 6h6M14 6h3a4 4 0 0 1 0 8h-3"/></symbol>'
+'<symbol id="i-quote" viewBox="0 0 24 24"><path d="M9 6H5v6h4v6H5M20 6h-4v6h4v6h-4"/></symbol>'
+'<symbol id="i-code" viewBox="0 0 24 24"><path d="M9 8l-4 4 4 4M15 8l4 4-4 4"/></symbol>'
+'<symbol id="i-hr" viewBox="0 0 24 24"><path d="M4 12h16M6 7h12M6 17h12"/></symbol>'
+'<symbol id="i-clear" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5h6v2M6.5 7l1 13h9l1-13"/></symbol>'
+'<symbol id="i-source" viewBox="0 0 24 24"><path d="M9 8l-4 4 4 4M15 8l4 4-4 4"/></symbol>'
+'<symbol id="i-sub" viewBox="0 0 24 24"><path d="M5 5l6 7M11 5L5 12"/><path d="M12 15h7M15.5 15v5"/></symbol>'
+'<symbol id="i-sup" viewBox="0 0 24 24"><path d="M5 14l6-7M11 14l-6-7"/><path d="M12 7h7M15.5 7v-4"/></symbol>'
+'<symbol id="i-indent" viewBox="0 0 24 24"><path d="M10 6h10M10 12h10M10 18h10M4 6h2M4 10h4v8H4z"/></symbol>'
+'<symbol id="i-outdent" viewBox="0 0 24 24"><path d="M10 6h10M10 12h10M10 18h10M4 6h2M4 18h4v-8H4z"/></symbol>'
+'</svg>';

function el(html){
  var d=document.createElement('div');
  d.innerHTML=html.trim();
  return d.firstElementChild;
}
function esc(v){
  return String(v==null?'':v).replace(/[&<>"']/g,function(c){
    return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
  });
}
function ic(name,cls){return '<svg class="ic'+(cls?' '+cls:'')+'"><use href="#'+name+'"/></svg>';}
function faNum(v){return window.I18N.num(v);}
function money(v){return window.I18N.money(v);}
/*
   Share codes are secrets: knowing one lets you hand somebody else's horse to
   yourself. They must never reach a printed page or a copied DOM, so they are
   masked by default and only written into the document when the user reveals
   them. `.secret` is additionally hidden by @media print.
*/
function secret(code,opts){
  opts=opts||{};
  var has=String(code||'').length>0;
  if(!has){return '<span class="mut">—</span>';}
  var id='s'+(secret._n=(secret._n||0)+1);
  return '<span class="secret" data-secret="'+id+'">'
    +'<span class="secret-v" style="filter:blur(5px)" aria-hidden="true">'+esc(code)+'</span>'
    +'<span class="secret-mask">••••••</span>'
    +'<button type="button" class="btn-i secret-t" data-secret-t="'+id+'" title="نمایش" aria-label="نمایش کد">'+ic('i-eye')+'</button>'
    +'<button type="button" class="btn-i secret-c" data-secret-c="'+id+'" title="کپی" aria-label="کپی کد">'+ic('i-copy')+'</button>'
    +'</span>';
}
function bindSecrets(root){
  (root||document).querySelectorAll('[data-secret-t]').forEach(function(b){
    if(b.dataset.secretBound){return;}
    b.dataset.secretBound='1';
    b.addEventListener('click',function(){
      var host=b.closest('.secret');
      if(!host){return;}
      var v=host.querySelector('.secret-v'),m=host.querySelector('.secret-mask');
      if(!v){return;}
      var on=v.style.filter!=='none';
      v.style.filter=on?'none':'';
      if(m){m.style.display=on?'none':'';}
      b.setAttribute('aria-label',on?'پنهان کردن کد':'نمایش کد');
      b.setAttribute('title',on?'پنهان کردن':'نمایش');
    });
  });
  (root||document).querySelectorAll('[data-secret-c]').forEach(function(b){
    if(b.dataset.secretBound){return;}
    b.dataset.secretBound='1';
    b.addEventListener('click',function(){
      var host=b.closest('.secret');
      var v=host&&host.querySelector('.secret-v');
      if(!v){return;}
      var text=String(v.textContent||'');
      if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){toast('کد کپی شد');},function(){toast('کپی نشد — کد را دستی یادداشت کنید','w');});
      }else{toast('کپی در این مرورگر پشتیبانی نمی‌شود','w');}
    });
  });
}
function ini(name){
  return String(name||'؟').trim().split(/\s+/).slice(0,2).map(function(w){return w[0];}).join('');
}
function av(name,sz){
  var s=sz||34;
  var h=0;
  String(name||'').split('').forEach(function(c){h=(h*31+c.charCodeAt(0))%360;});
  return '<span class="av" style="width:'+s+'px;height:'+s+'px;font-size:'+Math.round(s/2.6)+'px;background:linear-gradient(140deg,hsl('+h+',38%,40%),hsl('+((h+45)%360)+',42%,26%))">'+esc(ini(name))+'</span>';
}

/* ---------- toasts ---------- */
function toast(msg,type,action){
  var host=document.getElementById('toasts');
  if(!host){
    host=el('<div id="toasts"></div>');
    document.body.appendChild(host);
  }
  var t=el('<div class="toast'+(type==='e'?' e':type==='w'?' w':'')+'">'+ic(type==='e'?'i-info':type==='w'?'i-flag':'i-ccheck')+'<div class="g1 i13">'+esc(msg)+'</div></div>');
  if(action&&action.label&&typeof action.onClick==='function'){
    var b=el('<button class="btn btn-sm btn-g toast-undo">'+esc(action.label)+'</button>');
    b.addEventListener('click',function(){t.remove();action.onClick();});
    t.appendChild(b);
  }
  host.appendChild(t);
  setTimeout(function(){
    t.style.opacity='0';
    t.style.transition='opacity .2s';
    setTimeout(function(){t.remove();},220);
  },action?6000:3200);
}

/* Show a blocking "please wait" overlay for slow writes so users don't
   double-submit. Safe to call repeatedly; each call returns a closer. */
function busy(label){
  var host=document.getElementById('busyHost');
  if(!host){
    host=el('<div id="busyHost"></div>');
    document.body.appendChild(host);
  }
  var b=el('<div class="busy"><div class="spin"></div><div class="i13">'+esc(label||'در حال ذخیره…')+'</div></div>');
  host.appendChild(b);
  return function(){b.remove();};
}

/* ---------- modal + drawer ---------- */
var overlay=null,dialogHost=null;
function closeDialog(){
  if(dialogHost){
    dialogHost.remove();
    dialogHost=null;
  }
  if(overlay){
    overlay.classList.remove('on');
    setTimeout(function(){if(overlay&&!dialogHost){overlay.remove();overlay=null;}},220);
  }
  document.removeEventListener('keydown',onEsc);
}
function onEsc(e){if(e.key==='Escape'){closeDialog();}}
function showDialog(inner,mode){
  closeDialog();
  if(!overlay){
    overlay=el('<div class="ov" id="ovl"></div>');
    overlay.addEventListener('click',closeDialog);
    document.body.appendChild(overlay);
  }
  requestAnimationFrame(function(){overlay.classList.add('on');});
  if(mode==='drawer'){
    dialogHost=el('<div id="drw" class="on" role="dialog"><div class="row jb gap3 pad" style="border-bottom:1px solid var(--border)"><div class="row gap2" id="drwTitle"></div><button class="btn-i" id="drwX">'+ic('i-x')+'</button></div><div class="dbody"></div></div>');
    document.body.appendChild(dialogHost);
    dialogHost.querySelector('.dbody').appendChild(inner);
    dialogHost.querySelector('#drwX').addEventListener('click',closeDialog);
  }else{
    dialogHost=el('<div class="mw on"><div class="mdl on" role="dialog"></div></div>');
    dialogHost.querySelector('.mdl').appendChild(inner);
    document.body.appendChild(dialogHost);
  }
  document.addEventListener('keydown',onEsc);
  return dialogHost;
}
function drawer(title,bodyEl){
  var host=showDialog(bodyEl,'drawer');
  host.querySelector('#drwTitle').innerHTML='<b>'+esc(title)+'</b>';
  return host;
}
function modal(title,bodyHtml){
  var body=typeof bodyHtml==='string'?el('<div>'+bodyHtml+'</div>'):bodyHtml;
  var box=el('<div class="pad"><div class="row jb gap3 mb4"><b style="font-size:15px">'+esc(title)+'</b><button class="btn-i" data-x>'+ic('i-x')+'</button></div></div>');
  box.appendChild(body);
  box.querySelector('[data-x]').addEventListener('click',closeDialog);
  showDialog(box,'modal');
  return box;
}

/* ---------- confirm ---------- */
function confirmDlg(message,onYes,yesLabel){
  var body=el('<div class="pad-s"><p class="i13" style="margin:0 0 16px">'+esc(message)+'</p><div class="row jend gap2"><button class="btn btn-g" data-no>انصراف</button><button class="btn btn-d" data-yes>'+(esc(yesLabel||'تأیید'))+'</button></div></div>');
  modal('تأیید عملیات',body);
  body.querySelector('[data-no]').addEventListener('click',closeDialog);
  body.querySelector('[data-yes]').addEventListener('click',function(){closeDialog();onYes();});
}

/* ---------- typed confirmation (destructive actions) ---------- */
function typedConfirm(message, word, onYes, yesLabel){
  var body=el('<div class="pad-s"><p class="i13" style="margin:0 0 12px;line-height:1.8">'+esc(message)+'</p>'
    +'<label class="lbl">برای تأیید، عبارت <b class="kbd ltr">'+esc(word)+'</b> را بنویسید</label>'
    +'<input class="inp ltr" id="tcInp" autocomplete="off" autocapitalize="off"/>'
    +'<div class="row jend gap2 mt3"><button class="btn btn-g" data-no>انصراف</button><button class="btn btn-d" data-yes disabled>'+(esc(yesLabel||'تأیید'))+'</button></div></div>');
  modal('تأیید عملیات حساس',body);
  var input=body.querySelector('#tcInp');
  var yes=body.querySelector('[data-yes]');
  input.focus();
  input.addEventListener('input',function(){
    yes.disabled=input.value.trim().toUpperCase()!==String(word).toUpperCase();
  });
  body.querySelector('[data-no]').addEventListener('click',closeDialog);
  yes.addEventListener('click',function(){closeDialog();onYes();});
}

/* ---------- badge map ---------- */
var TONES={
  active:'b-ok',verified:'b-ok',confirmed:'b-ok',paid:'b-ok',published:'b-ok',open:'b-info',
  accepted:'b-ok','1':'b-ok',
  pending:'b-warn',pending_payment:'b-warn',draft:'b-mut',pending_refund:'b-warn',closed:'b-mut',
  rejected:'b-bad',failed:'b-bad',cancelled:'b-bad',full:'b-bad',expired:'b-bad',revoked:'b-bad',
  refunded:'b-info',running:'b-info',finished:'b-mut',sold_to_non_rider:'b-warn',soft_deleted:'b-mut',
  limited:'b-warn',none:'b-ok',sent:'b-ok',skipped:'b-warn',banned:'b-bad'
};
function badge(status){
  var label=({pending_payment:'در انتظار پرداخت',pending:'در انتظار',draft:'پیش‌نویس',confirmed:'تأییدشده',
    rejected:'ردشده',cancelled:'لغوشده',withdrawn:'کنارگذاشته',paid:'پرداخت‌شده',failed:'ناموفق',
    pending_refund:'در انتظار استرداد',refunded:'مستردشده',verified:'تأییدشده',open:'باز',closed:'بسته',
    running:'در حال اجرا',finished:'پایان‌یافته',active:'فعال',soft_deleted:'حذف‌شده',sold_to_non_rider:'فروش به غیرسوارکار',
    limited:'محدود',full:'مسدود',none:'عادی',accepted:'پذیرفته‌شده',sent:'ارسال‌شده',skipped:'نادیده',expired:'منقضی'})[status]||status;
  return '<span class="badge '+(TONES[String(status)]||'b-mut')+'">'+esc(label||'—')+'</span>';
}

/* ---------- notification kinds ---------- */
/*
   Notifications arrive with a machine `type`; the feed needs a human label or
   the list reads as a wall of identical tags.
*/
var NOTIF_LABELS={
  'horse.shared':'اشتراک اسب','horse.transfer':'انتقال اسب','horse.health':'سلامت اسب',
  'signup.created':'ثبت‌نام جدید','signup.paid':'پرداخت ثبت‌نام','signup.confirmed':'ثبت‌نام تأیید‌شده',
  'signup.cancelled':'لغو ثبت‌نام','signup.waitlist':'ذخیره در فهرست انتظار',
  'competition.published':'انتشار مسابقه','competition.deadline':'مهلت ثبت‌نام',
  'payment.paid':'پرداخت موفق','payment.refunded':'استرداد وجه','payment.failed':'پرداخت ناموفق',
  'result.published':'انتشار نتایج','deadline':'یادآوری مهلت','message':'پیام جدید',
  'verification':'تأیید حساب','ban':'محدودیت حساب','system':'اعلان سیستم'
};
function notifLabel(type){
  return NOTIF_LABELS[type]||(type||'اعلان');
}

/* ---------- form helpers ---------- */
function field(labelText,inputHtml,opts){
  opts=opts||{};
  return '<div class="'+(opts.full?'full':'')+'"><label class="lbl'+(opts.req?' req':'')+'">'+esc(labelText)+'</label>'+inputHtml+(opts.hint?'<div class="hint">'+esc(opts.hint)+'</div>':'')+'</div>';
}
function debounce(fn,ms){
  var t;
  return function(){clearTimeout(t);var a=arguments,self=this;t=setTimeout(function(){fn.apply(self,a);},ms||300);};
}
function inp(name,label,val,type,opts){
  /* Tolerate opts passed in the `type` slot: UI.inp(name,label,val,{req:true}). */
  if(type&&typeof type==='object'){opts=type;type=null;}
  opts=opts||{};
  return field(label,'<input class="inp" name="'+name+'" type="'+(type||'text')+'" value="'+esc(val==null?'':val)+'"'+(opts.ph?' placeholder="'+esc(opts.ph)+'"':'')+(opts.attrs||'')+'/>',opts);
}
function sel(name,label,val,options,opts){
  if(options&&!Array.isArray(options)&&typeof options==='object'){opts=options;options=[];}
  opts=opts||{};
  var o=options.map(function(op){
    var v=(op&&op.v!==undefined)?op.v:op;
    var l=(op&&op.l!==undefined)?op.l:op;
    return '<option value="'+esc(v)+'"'+(String(val)===String(v)?' selected':'')+'>'+esc(l)+'</option>';
  }).join('');
  return field(label,'<select class="sel" name="'+name+'">'+o+'</select>',opts);
}
function ta(name,label,val,opts){
  opts=opts||{};
  return field(label,'<textarea class="ta" name="'+name+'">'+esc(val==null?'':val)+'</textarea>',opts);
}
function formValues(root){
  var out={};
  root.querySelectorAll('input[name],select[name],textarea[name]').forEach(function(i){
    if(i.type==='checkbox'){out[i.name]=i.checked;}
    else{out[i.name]=i.value;}
  });
  return out;
}
function showErrors(root,errors){
  root.querySelectorAll('.ferr').forEach(function(e){e.remove();});
  (errors||[]).forEach(function(er){
    var input=root.querySelector('[name="'+er.field+'"]');
    if(input){
      input.classList.add('err');
      var msg=el('<div class="ferr" style="color:var(--danger);font-size:11px;margin-top:4px">'+esc(er.message||er.code)+'</div>');
      input.parentElement.appendChild(msg);
    }else{
      toast(er.message||er.code||'خطا','e');
    }
  });
  if(!errors||!errors.length){toast('خطای نامشخص','e');}
}

/* ---------- skeletons (loading placeholders) ---------- */
/* Placeholder rows shown while a grid loads; far calmer than a lone spinner. */
function skeletonTable(cols,rows){
  rows=rows||6;
  var cells=[];
  for(var i=0;i<cols;i++){cells.push('<td><span class="sk sk-t" style="width:'+(45+((i*17)%45))+'%"></span></td>');}
  var body='';
  for(var r=0;r<rows;r++){body+='<tr>'+cells.join('')+'</tr>';}
  return '<div class="tw panel"><table class="tb"><tbody>'+body+'</tbody></table></div>';
}
function skeletonCards(n){
  n=n||4;
  var out='';
  for(var i=0;i<n;i++){out+='<div class="panel pad"><span class="sk sk-t" style="width:55%"></span><span class="sk sk-t" style="width:80%;margin-top:8px"></span><span class="sk sk-t" style="width:35%;margin-top:8px"></span></div>';}
  return '<div class="grid c2">'+out+'</div>';
}

/* ---------- unsaved-changes guard ---------- */
/*
   Wrap a container (page or dialog). Any input/change marks it dirty; leaving
   the route or closing the dialog then warns instead of losing work silently.
*/
function guard(root,isEnabled){
  var noop={clear:function(){},confirmLeave:function(){return true;},isDirty:function(){return false;}};
  if(!root){return noop;}
  var dirty=false;
  var isOn=typeof isEnabled==='function'?isEnabled():(isEnabled!==false);
  /* Only edits inside a <form> or an explicit [data-guard] block count, so
     filtering or opening a menu never trips the warning. */
  function mark(e){
    var t=e.target;
    if(t&&t.closest&&t.closest('form,[data-guard]')){dirty=true;}
  }
  root.addEventListener('input',mark,true);
  root.addEventListener('change',mark,true);
  function confirmLeave(){
    if(!dirty||!isOn){return true;}
    return window.confirm('تغییرات ذخیره‌نشده دارید. خروج ادامه می‌یابد؟');
  }
  var onBeforeUnload=function(e){
    if(!dirty||!isOn){return undefined;}
    e.preventDefault();
    e.returnValue='';
    return '';
  };
  window.addEventListener('beforeunload',onBeforeUnload);
  return {
    clear:function(){dirty=false;},
    confirmLeave:confirmLeave,
    isDirty:function(){return dirty;},
    dispose:function(){window.removeEventListener('beforeunload',onBeforeUnload);}
  };
}

/* ---------- printing ---------- */
/*
   Print is a first-class output in this panel: jury sheets, sign-up lists,
   receipts and reports are printed on paper. printButton() injects a floating
   toolbar before printing and restores the screen afterwards, so the printed
   page carries the federation header, the document title and the filters used.
*/
var printRestore=null;
function printButton(label,opts){
  opts=opts||{};
  return '<button type="button" class="btn btn-g btn-sm noprint" data-print'
    +(opts.title?' data-print-title="'+esc(opts.title)+'"':'')
    +(opts.scope?' data-print-scope="'+esc(opts.scope)+'"':'')
    +' title="چاپ این صفحه">'
    +ic('i-print')+' '+(label||'چاپ')+'</button>';
}
function printHtml(doc,sub,meta){
  /* Build the print-only header block. */
  return '<div id="printHead" class="print-only">'
    +'<div class="print-brand">هیئت سوارکاری استان گلستان</div>'
    +'<div class="print-doc">'+esc(doc||'گزارش')+'</div>'
    +(sub?'<div class="print-sub">'+esc(sub)+'</div>':'')
    +'<div class="print-meta">'+faNum(I18N.fmtDateLongG(new Date().toISOString()))
    +(meta?' — '+esc(meta):'')+'</div>'
    +'</div>';
}
/* Active filter chips are worth printing: the paper sheet must say which
   slice of data the reader is holding. */
function printFilterSummary(){
  var chips=document.querySelectorAll('#view .fbar-chips .chip');
  var out=[];
  chips.forEach(function(c){
    var t=(c.textContent||'').replace(/\s*×\s*$/,'').trim();
    if(t){out.push(t);}
  });
  return out.join(' · ');
}
/* Row / page count line, so a paginated sheet states what part it shows. */
function printCountSummary(){
  var pager=document.querySelector('#view .pager .mut');
  if(pager){return pager.textContent.trim();}
  var tb=document.querySelector('#view table.tb');
  if(tb){return faNum(tb.querySelectorAll('tbody tr').length)+' ردیف';}
  return '';
}
function preparePrint(docTitle,scopeSel){
  var view=document.getElementById('view');
  if(!view){return;}
  if(printRestore){printRestore();printRestore=null;}
  /* Mark the element that describes what is being printed. */
  var target=(scopeSel&&document.querySelector(scopeSel))||document.getElementById('printScope')||view;
  var prev=target.getAttribute('data-print-title')||'';
  target.setAttribute('data-print-title',docTitle||document.getElementById('pageTitle')?.textContent||'گزارش');
  if(view.querySelector('#printHead')){view.querySelector('#printHead').remove();}
  view.insertAdjacentHTML('afterbegin',printHtml(docTitle,printFilterSummary(),printCountSummary()));
  var before=document.title;
  document.title=(docTitle||'گزارش')+' — هیئت سوارکاری گلستان';
  printRestore=function(){
    document.title=before;
    var h=document.getElementById('printHead');
    if(h){h.remove();}
    if(prev){target.setAttribute('data-print-title',prev);}
    else{target.removeAttribute('data-print-title');}
  };
}
function doPrint(docTitle,scopeSel){
  preparePrint(docTitle,scopeSel);
  window.print();
}
/* Single delegated handler: any [data-print] button prints its page. */
document.addEventListener('click',function(e){
  var b=e.target&&e.target.closest?e.target.closest('[data-print]'):null;
  if(!b){return;}
  e.preventDefault();
  var title=b.getAttribute('data-print-title')||document.getElementById('pageTitle')?.textContent||'گزارش';
  doPrint(title,b.getAttribute('data-print-scope')||'');
});

/* ---------- list payload normaliser ---------- */
/*
   Grids must never break because an endpoint answered `null`, an empty object
   or a bare array. This helper always yields an array:
     UI.rows({rows:[…]})      -> [ … ]
     UI.rows([…])             -> [ … ]
     UI.rows(null|{})         -> []
     UI.rows({total:0, rows:[]}) -> []
*/
function rows(payload){
  if(Array.isArray(payload)){return payload;}
  if(payload==null){return [];}
  if(Array.isArray(payload.rows)){return payload.rows;}
  if(Array.isArray(payload.data)){return payload.data;}
  if(payload.data&&Array.isArray(payload.data.rows)){return payload.data.rows;}
  if(Array.isArray(payload.items)){return payload.items;}
  if(typeof payload.id!=='undefined'){return [payload];}
  return [];
}

/* ---------- table builder ---------- */
/*
   opts.sort  = { key, dir }           current client-side sort
   opts.onSort(key, dir)               called when a sortable header is clicked
   opts.emptyAction                    HTML button rendered inside the empty state
*/
function table(columns,rows,opts){
  opts=opts||{};
  var sort=opts.sort||null;
  var head=columns.map(function(c){
    var isSorted=sort&&c.sortKey&&String(sort.key)===String(c.sortKey);
    var arrow=isSorted?(sort.dir==='asc'?' <span class="srt a">▲</span>':' <span class="srt d">▼</span>'):'';
    var cls=[];
    if(c.sortable||c.sortKey){cls.push('sortable');}
    if(isSorted){cls.push('sorted-'+(sort.dir||'asc'));}
    var label=c.raw?c.label:esc(c.label);
    var attrs=' class="'+cls.join(' ')+'"';
    if(c.sortable||c.sortKey){attrs+=' data-sort="'+esc(c.sortKey||c.key)+'" tabindex="0" role="button" aria-label="مرتب‌سازی بر اساس '+esc(c.label)+'"';}
    /* Selection checkboxes and action buttons are meaningless on paper and
       cost a whole column each in a printed grid. */
    if(c.noPrint){attrs+=' data-no-print="1"';}
    return '<th'+attrs+'>'+label+arrow+'</th>';
  }).join('');
  var body;
  if(!rows.length){
    body='<tr><td colspan="'+columns.length+'"><div class="empty">'+ic(opts.emptyIcon||'i-file','')
      +'<div>'+(esc(opts.emptyText||'موردی یافت نشد'))+'</div>'
      +(opts.emptyAction?'<div class="mt3">'+opts.emptyAction+'</div>':'')+'</div></td></tr>';
  }else{
    body=rows.map(function(r){
      return '<tr'+(opts.rowHref?' data-href="'+esc(opts.rowHref(r))+'"':'')+'>'+columns.map(function(c){
        /* One bad cell must not blank the whole grid. */
        var cell;
        try{
          cell=c.render?c.render(r):esc(r[c.key]==null?'—':r[c.key]);
        }catch(e){
          cell='<span class="mut i11" title="'+esc(String(e&&e.message||e))+'">—</span>';
        }
        return '<td'+(c.wrap?' class="wrap"':'')+(c.noPrint?' data-no-print="1"':'')+'>'+cell+'</td>';
      }).join('')+'</tr>';
    }).join('');
  }
  return '<div class="tw panel"><table class="tb"><thead><tr>'+head+'</tr></thead><tbody>'+body+'</tbody></table>'
    +(opts.pager||'')+'</div>';
}
function pagerHtml(total,page,perPage){
  var pages=Math.max(1,Math.ceil(total/perPage));
  return '<div class="pager"><div class="mut i12">'+faNum(total?((page-1)*perPage+1):0)+'–'+faNum(Math.min(page*perPage,total))+' از '+faNum(total)+'</div>'
    +'<div class="row gap1"><button class="btn btn-sm btn-g" data-page="'+(page-1)+'"'+(page<=1?' disabled':'')+'>'+ic('i-cl')+'</button>'
    +'<span class="i12 mut" style="padding:0 6px">'+faNum(page)+' / '+faNum(pages)+'</span>'
    +'<button class="btn btn-sm btn-g" data-page="'+(page+1)+'"'+(page>=pages?' disabled':'')+'>'+ic('i-cr')+'</button></div></div>';
}

/* Attach click + keyboard handling to sortable headers produced by table(). */
function bindSorting(root,state,onSort){
  (root||document).querySelectorAll('th[data-sort]').forEach(function(th){
    function fire(){
      var key=th.dataset.sort;
      var dir=(state.sort&&state.sort.key===key&&state.sort.dir==='asc')?'desc':'asc';
      onSort(key,dir);
    }
    th.addEventListener('click',fire);
    th.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){e.preventDefault();fire();}
    });
  });
}
/* Client-side sort over an already-loaded row array (no server round-trip). */
function sortRows(rows,cols,sort){
  if(!sort||!sort.key){return rows;}
  var col=(cols||[]).filter(function(c){return String(c.sortKey||c.key)===String(sort.key);})[0];
  if(!col){return rows;}
  var dir=sort.dir==='desc'?-1:1;
  var val=col.sortValue||function(r){
    return r[col.key==null?col.sortKey:col.key];
  };
  return rows.slice().sort(function(a,b){
    var x=val(a),y=val(b);
    if(x==null){x='';}
    if(y==null){y='';}
    if(typeof x==='string'&&typeof y==='string'){
      return x.localeCompare(y,'fa',{numeric:true})*dir;
    }
    if(typeof x==='number'&&typeof y==='number'){return (x-y)*dir;}
    return String(x).localeCompare(String(y),'fa',{numeric:true})*dir;
  });
}

/* ---------- saved views (per page, stored in the browser) ---------- */
/*
   config: { key, filters, selects }
   Returns a small control: a dropdown of saved presets, plus "ذخیره نما"
   which snapshots the current filter state so non-technical users can jump
   straight back to a view they use every week.
*/
function savedViews(cfg){
  var key=cfg.key||'ghf';
  var store='ghf.views.'+key;
  function read(){
    try{return JSON.parse(localStorage.getItem(store)||'[]');}catch(e){return [];}
  }
  function write(list){
    try{localStorage.setItem(store,JSON.stringify(list.slice(0,12)));}catch(e){}
  }
  function snapshot(){
    var f=cfg.filters||{};
    var o={};
    Object.keys(f).forEach(function(k){if(k!=='page'&&f[k]!==''&&f[k]!=null){o[k]=f[k];}});
    return o;
  }
  var host=el('<span class="sv-host"></span>');
  function draw(){
    var list=read();
    var menu='<div class="dd sv-dd" hidden>'
      +(list.length?list.map(function(v,i){
          return '<button type="button" data-sv="'+i+'">'+esc(v.name)+'</button>';
        }).join(''):'<div class="i11 mut" style="padding:8px 10px">هنوز نمایی ذخیره نشده</div>')
      +'<hr class="hr" style="margin:6px 0"/>'
      +'<button type="button" data-save-view>'+ic('i-star')+' ذخیره نمای فعلی</button>'
      +'<button type="button" data-del-view>'+ic('i-trash')+' حذف نماها</button>'
      +'</div>';
    host.innerHTML='<button type="button" class="btn btn-sm btn-g" data-sv-toggle>'+ic('i-star')+' نماهای ذخیره‌شده</button>'+menu;
    var dd=host.querySelector('.sv-dd');
    var btn=host.querySelector('[data-sv-toggle]');
    function close(){dd.hidden=true;document.removeEventListener('click',outside);}
    function outside(e){if(!host.contains(e.target)){close();}}
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      if(!dd.hidden){close();return;}
      dd.hidden=false;
      document.addEventListener('click',outside);
    });
    host.querySelectorAll('[data-sv]').forEach(function(b){
      b.addEventListener('click',function(){
        close();
        if(cfg.onApply){cfg.onApply(list[+b.dataset.sv]);}
      });
    });
    host.querySelector('[data-save-view]').addEventListener('click',function(){
      close();
      var defName=(nview.list.length+1)+'- نمای منظم';
      var name=window.prompt('نام این نما:',defName)||'';
      if(!name.trim()){return;}
      nview.list.push({name:name.trim(),filters:snapshot()});
      write(nview.list);
      toast('نما ذخیره شد');
      draw();
    });
    host.querySelector('[data-del-view]').addEventListener('click',function(){
      close();
      nview.list=[];
      write(nview.list);
      toast('نماها حذف شد','w');
      draw();
    });
  }
  var nview={list:read()};
  draw();
  return host;
}

/* ---------- live countdown ---------- */
/*
   Renders "closes in 2d 4h" from a UTC ISO timestamp and keeps itself up to
   date. Returns the element; pass {deadline:true} to keep counting past zero.
*/
function countdown(iso,label,opts){
  opts=opts||{};
  var target=Date.parse(iso);
  var box=el('<span class="countdown"></span>');
  var timer=null;
  function paint(){
    if(isNaN(target)){box.textContent='';return;}
    var diff=target-Date.now();
    var past=diff<0;
    if(past&&!opts.keep){
      box.className='countdown over';
      box.innerHTML='<b>'+esc(label||'پایان یافته')+'</b>';
      if(timer){clearInterval(timer);timer=null;}
      return;
    }
    var abs=Math.abs(diff);
    var d=Math.floor(abs/86400000);
    var h=Math.floor((abs%86400000)/3600000);
    var m=Math.floor((abs%3600000)/60000);
    var s=Math.floor((abs%60000)/1000);
    var text=(d>0?faNum(d)+' روز ':'')+(h>0||d>0?faNum(h)+' ساعت ':'')+faNum(m)+' دقیقه';
    if(d===0&&h===0){text=faNum(s)+' ثانیه';}
    box.className='countdown'+(past?' over':(abs<86400000?' soon':''));
    box.innerHTML=(label?'<span class="i11 mut">'+esc(label)+'</span> ':'')+'<b>'+esc(past?('گذشته: '+text):text)+'</b>';
    if(past&&!opts.keep){if(timer){clearInterval(timer);timer=null;}}
  }
  paint();
  if(!timer){timer=setInterval(paint,30000);}
  box.dispose=function(){if(timer){clearInterval(timer);timer=null;}};
  return box;
}

/* ---------- filter bar ---------- */
/*
   Non-technical friendly list filter: a search box, optional selects, active
   filter chips with individual + full clear, and a live result count. Filter
   values are mirrored into the URL query so a reload (or a shared link)
   restores the exact same result set.
   config: { search?, selects: [{key,label,options}], chips: [k=>label],
             onChange(filters), filters }
*/
function filterBar(cfg){
  var state=cfg.filters||{};
  var host=el('<div class="fbar"></div>');
  /* Sibling of chips(), not nested in draw(): chips() reads the label that the
     picker has actually rendered, and draw() re-creates the markup on every
     change, so this must stay reachable from outside draw()'s scope. */
  function liveLabel(key){
    var b=host.querySelector('[data-spk="__sel_'+key+'"] .spk-inp');
    return b?String(b.value||'').trim():'';
  }
  function chips(){
    var active=[];
    if(state.search){active.push({k:'search',l:'جستجو: '+state.search});}
    (cfg.selects||[]).forEach(function(s){
      if(state[s.key]){
        var opt=(s.options||[]).filter(function(o){return String(o.v)===String(state[s.key]);})[0];
        /* Lazy filters resolve their label from the rendered select instead. */
        var live=liveLabel(s.key);
        active.push({k:s.key,l:(s.label||s.key)+': '+(opt?opt.l:(live||state[s.key]))});
      }
    });
    if((cfg.chips||[]).length){
      (cfg.chips||[]).forEach(function(fn){
        var c=fn(state);
        if(c&&c.l){active.push({k:c.k,l:c.l});}
      });
    }
    return active;
  }
  function draw(){
    /* The search box borrows the .spk styling but is NOT a picker: it has no
       hidden value and no dropdown, so it must not carry data-spk (which
       initPicks would try to wire up). */
    var searchHtml=cfg.search===false?'':
      '<div class="spk" data-spk-q="1" style="flex:1 1 240px;min-width:200px">'
      +'<div class="spk-ctl"><input class="inp spk-inp" type="text" placeholder="'+(cfg.searchLabel||'جستجو…')+'" value="'+esc(state.search||'')+'"/></div></div>';
    var selHtml=(cfg.selects||[]).map(function(s){
      return '<div class="spk" data-spk="__sel_'+esc(s.key)+'" style="flex:0 1 190px;min-width:150px">'
        +'<input type="hidden" value="'+esc(state[s.key]||'')+'"/>'
        +'<div class="spk-ctl"><input class="inp spk-inp" type="text" placeholder="'+esc(s.label||'')+'"'
        +' data-label="'+(function(){
            var o=(s.options||[]).filter(function(x){return String(x.v)===String(state[s.key]||'');})[0];
            return o?esc(o.l):'';
          })()+'"/><span class="spk-arrow">'+ic('i-cr')+'</span></div>'
        +'<div class="spk-pop" hidden></div></div>';
    }).join('');
    var activeChips=chips();
    host.innerHTML='<div class="row gap2 wrap mb2" style="align-items:stretch">'
      +searchHtml+selHtml
      +'<button type="button" class="btn btn-g btn-sm fbar-apply" style="align-self:center">'+ic('i-refresh')+' اعمال</button>'
      +'<button type="button" class="btn btn-g btn-sm fbar-clear" style="align-self:center"'+(activeChips.length?'':' disabled')+'>'+ic('i-x2')+' پاک‌کردن همه</button>'
      +(cfg.views?'<span class="fbar-views" style="align-self:center"></span>':'')
      +'<span class="g1"></span><span class="i12 mut fbar-count" style="align-self:center"></span>'
      +'</div><div class="fbar-chips row gap1 wrap mb2"></div>';
    var registry={};
    (cfg.selects||[]).forEach(function(s){
      registry['__sel_'+s.key]=typeof s.lazy==='function'?{load:s.lazy}:{options:s.options||[]};
    });
    initPicks(host,registry);
    if(cfg.views){
      var vh=host.querySelector('.fbar-views');
      if(vh){
        vh.appendChild(savedViews({key:cfg.views,filters:state,onApply:function(v){
          Object.keys(state).forEach(function(k){if(k!=='page'){delete state[k];}});
          Object.keys(v.filters||{}).forEach(function(k){state[k]=v.filters[k];});
          state.page=1;
          cfg.onChange(state);
          draw();
        }}));
      }
    }
    renderChips();
    /* Lazy pickers resolve their label asynchronously; refresh chips then. */
    setTimeout(function(){renderChips();},400);
    /* Search box: debounced text input. */
    var sinp=host.querySelector('[data-spk-q] .spk-inp');
    if(sinp){
      sinp.addEventListener('input',debounce(function(){
        state.search=this.value.trim();
        state.page=1;
        cfg.onChange(state);
        draw();
      },380));
    }
    (cfg.selects||[]).forEach(function(s){
      var block=host.querySelector('[data-spk="__sel_'+s.key+'"]');
      if(!block){return;}
      var hid=block.querySelector('input[type=hidden]');
      if(!hid){return;}
      hid.name='__f_'+s.key;
      hid.value=state[s.key]||'';
      block.querySelector('.spk-inp').addEventListener('change',function(){
        var v=block.querySelector('input[type=hidden]').value;
        if(v){state[s.key]=v;}else{delete state[s.key];}
        state.page=1;
        cfg.onChange(state);
        draw();
      });
    });
    var clear=host.querySelector('.fbar-clear');
    if(clear){clear.addEventListener('click',function(){
      Object.keys(state).forEach(function(k){if(k!=='page')delete state[k];});
      state.page=1;
      cfg.onChange(state);
      draw();
    });}
    var apply=host.querySelector('.fbar-apply');
    if(apply){apply.addEventListener('click',function(){cfg.onChange(state,true);});}

    function renderChips(){
      var wrap=host.querySelector('.fbar-chips');
      if(!wrap){return;}
      var list=chips();
      wrap.innerHTML=list.length?list.map(function(c){
        return '<span class="chip on" data-chip="'+esc(c.k)+'">'+esc(c.l)+' <b style="cursor:pointer">×</b></span>';
      }).join(''):'';
      var clearBtn=host.querySelector('.fbar-clear');
      if(clearBtn){clearBtn.disabled=!list.length;}
      wrap.querySelectorAll('[data-chip]').forEach(function(chip){
        chip.addEventListener('click',function(){
          var k=chip.dataset.chip;
          if(k==='page'){state.page=1;}else{delete state[k];}
          cfg.onChange(state);
          draw();
        });
      });
    }
  }
  draw();
  /* These helpers must hang off the element that is RETURNED, because every
     page does `var fbar=UI.filterBar({…}); fbar.setCount(…)`. Attaching them
     to a separate wrapper made setCount undefined and threw mid-load(). */
  host.setCount=function(n){var c=host.querySelector('.fbar-count');if(c){c.textContent=n;}};
  /* Empty-state CTA that clears the active filters of this bar. */
  host.clearAction='<button type="button" class="btn btn-g btn-sm" data-clear-filters>'+ic('i-filter')+' پاک‌کردن فیلترها</button>';
  host.hasFilters=function(){
    var any=false;
    Object.keys(state).forEach(function(k){if(k!=='page'&&state[k]!==''&&state[k]!=null){any=true;}});
    return any;
  };
  host.onClear=function(){
    Object.keys(state).forEach(function(k){if(k!=='page'){delete state[k];}});
    state.page=1;
    cfg.onChange(state);
    draw();
  };
  host.redraw=draw;
  return host;
}

/* ---------- searchable select (select2-like, lazy-load capable) ---------- */
/*
   options: {
     id?, name, label, value, placeholder,
     options: [{v,l}]            static list,
     load: (term, cb) => void    lazy loader; cb(rows) renders results,
     allowClear?, allowCustom?, hint?, req?, full?, attrs?
   }
   Renders a native <select> (so UI.formValues keeps working) plus a search
   input and a result list; typing filters, lazy lists call load(term).
*/
function hostJson(host,key){
  var raw=host.getAttribute('data-spk-opts');
  if(!raw){return null;}
  try{return JSON.parse(raw);}catch(e){return null;}
}

/* pickField() registers its options/loader here so initPicks() can find them
   even when the caller passes an empty registry. */
var pickRegistry={};

function pickField(opts){
  opts=opts||{};
  var staticOpts=opts.options||[];
  pickRegistry[opts.id||opts.name]={options:staticOpts,load:opts.load};
  var isLazy=typeof opts.load==='function';
  var allowClear=opts.allowClear!==false;
  return field(opts.label||'',
    '<div class="spk" data-spk="'+UI.esc(opts.id||opts.name)+'" data-spk-opts="'+esc(JSON.stringify(staticOpts.map(function(o){return {v:o.v,l:o.l};})))+'">'
    +'<input type="hidden" name="'+esc(opts.name)+'" value="'+esc(opts.value==null?'':opts.value)+'"/>'
    +'<div class="spk-ctl"><input class="inp spk-inp" type="text" autocomplete="off" role="combobox" aria-expanded="false"'
    +' placeholder="'+esc(opts.placeholder||'جستجو و انتخاب…')+'"'
    +' data-label="'+(opts.selectedLabel?esc(opts.selectedLabel):'')+'"/>'
    +(allowClear?'<button type="button" class="spk-clear" title="پاک‌کردن" hidden>'+ic('i-x2')+'</button>':'')
    +'<span class="spk-arrow">'+ic('i-cr')+'</span></div>'
    +'<div class="spk-pop" hidden></div>'
    +'</div>', opts);
}

/* Wire every [data-spk] block inside root. */
function initPicks(root,registry){
  registry=registry||{};
  (root||document).querySelectorAll('[data-spk]').forEach(function(host){
    if(host.dataset.spkOn){return;}
    host.dataset.spkOn='1';
    var hidden=host.querySelector('input[type=hidden]');
    var input=host.querySelector('.spk-inp');
    var pop=host.querySelector('.spk-pop');
    var clear=host.querySelector('.spk-clear');
    var key=host.dataset.spk;
    /* A picker block needs all four nodes. A partial one (a bare text input
       that only borrows the .spk styling) is left alone instead of throwing
       on hidden.value and blanking the page. */
    if(!hidden||!input||!pop){
      host.dataset.spkOn='';
      delete host.dataset.spkOn;
      return;
    }
    /* Options declared in the markup are the default; a registry entry with a
       loader (or an explicit option list) overrides them. */
    var cfg=registry[key]||pickRegistry[key]||{};
    var rows=(cfg.options||hostJson(host,'spkOpts')||[]).slice();
    var open=false;

    function labelFor(v){
      for(var i=0;i<rows.length;i++){if(String(rows[i].v)===String(v))return rows[i].l;}
      return v?String(v):'';
    }
    function setValue(v,label){
      hidden.value=(v==null?'':v);
      input.value=(label!=null?label:labelFor(v));
      input.dataset.label=input.value;
      if(clear){clear.hidden=!hidden.value;}
      input.dispatchEvent(new Event('change',{bubbles:true}));
    }
    function renderList(list,term){
      pop.innerHTML='';
      if(!list.length){
        pop.appendChild(el('<div class="spk-none">موردی یافت نشد</div>'));
      }
      list.forEach(function(op){
        var b=el('<button type="button" class="spk-opt" data-v="'+esc(op.v)+'"><span class="g1 trunc">'+esc(op.l)+'</span></button>');
        b.addEventListener('mousedown',function(e){
          e.preventDefault();
          setValue(op.v,op.l);
          closeList();
        });
        pop.appendChild(b);
      });
      pop.hidden=false;
      input.setAttribute('aria-expanded','true');
      open=true;
    }
    function closeList(){pop.hidden=true;input.setAttribute('aria-expanded','false');open=false;}
    function search(term){
      term=String(term||'').trim();
      if(cfg.load){
        pop.innerHTML='<div class="spk-none">در حال جستجو…</div>';
        pop.hidden=false;open=true;
        cfg.load(term,function(list){rows=list||[];renderList(rows,term);});
      }else{
        var t=term.toLowerCase();
        renderList(rows.filter(function(op){return !t||String(op.l).toLowerCase().indexOf(t)>-1||String(op.v).toLowerCase().indexOf(t)>-1;}),term);
      }
    }
    /* Pre-selected label: resolve it from the first load. */
    if(cfg.load){
      cfg.load(hidden.value?String(labelFor(hidden.value)):'',function(list){
        rows=list||[];
        if(hidden.value){input.value=labelFor(hidden.value);}
      });
    }else if(hidden.value){
      input.value=labelFor(hidden.value);
    }
    input.addEventListener('focus',function(){search('');});
    input.addEventListener('input',function(){search(this.value);});
    input.addEventListener('blur',function(){setTimeout(closeList,140);});
    input.addEventListener('keydown',function(e){
      var opts2=Array.prototype.slice.call(pop.querySelectorAll('.spk-opt'));
      var idx=opts2.findIndex(function(b){return b.classList.contains('hi');});
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){
        e.preventDefault();
        if(!opts2.length){return;}
        var next=e.key==='ArrowDown'?Math.min(opts2.length-1,idx+1):Math.max(0,idx-1);
        opts2.forEach(function(b){b.classList.remove('hi');});
        opts2[next].classList.add('hi');
        opts2[next].scrollIntoView({block:'nearest'});
      }else if(e.key==='Enter'){
        if(opts2.length){e.preventDefault();var pickEl=(idx>-1?opts2[idx]:opts2[0]);pickEl.dispatchEvent(new MouseEvent('mousedown',{cancelable:true,bubbles:true}));}
      }else if(e.key==='Escape'){closeList();}
    });
    if(clear){
      clear.hidden=!hidden.value;
      clear.addEventListener('click',function(){setValue('','');input.focus();search('');});
    }
  });
}

/* Loader factory: search any list endpoint and map rows to {v,l}.
   `label` may be a field name ('title'), a function, or a dot path
   ('venue.name'). A plain string used to be called as a function, and the
   TypeError was swallowed by the .catch below, so the dropdown rendered
   "موردی یافت نشد" against a 200 response. */
function spkFromApi(path,label,extra){
  var toLabel=(typeof label==='function')
    ? label
    : function(r){
        var parts=String(label||'').split('.');
        var v=r;
        for(var i=0;i<parts.length;i++){
          if(v==null){return '';}
          v=v[parts[i]];
        }
        return v==null?'':String(v);
      };
  return function(term,cb){
    var q={per_page:20,search:term||''};
    if(extra){Object.keys(extra).forEach(function(k){q[k]=extra[k];});}
    API.get(path,q).then(function(d){
      cb(rows(d).map(function(r){return {v:r.id,l:toLabel(r)};}).filter(function(o){return String(o.l)!=='';}));
    }).catch(function(){cb([]);});
  };
}

/* ---------- rich text editor ---------- */
/*
   Quill 2 (vendored, MIT-free BSD-3) is the real editor: non-technical staff
   need drag-and-drop images, lists and headings without learning HTML. It is
   loaded lazily so pages without an editor never pay for it, and the original
   contenteditable markup stays in the DOM as a fallback — if the vendor file
   is missing or blocked the field still works.
*/
var QUILL_SRC='/views/assets/vendor/quill.js';
/*
   Toolbar is custom DOM rather than Quill's array shorthand, for two reasons:
   the target audience is Persian-speaking and non-technical, so every control
   needs a Persian tooltip (Quill's tooltip module is switched off) and a label
   that reads on its own; and the array shorthand hides the `hr` divider and the
   HTML source view, which are the two controls staff actually ask for.
   Quill wires any `[data-format]` control it finds inside the container. */
function edtToolbar(){
  var b=function(fmt,val,icon,title,cls){
    return '<button type="button" class="edt-b'+(cls?' '+cls:'')+'" data-format="'+fmt+'"'
      +(val!=null?' data-value="'+esc(val)+'"':'')+' title="'+esc(title)+'" aria-label="'+esc(title)+'">'
      +ic(icon)+'</button>';
  };
  return '<div class="edt-toolbar" data-edt-toolbar>'
    +'<span class="edt-group">'
    +b('undo',null,'i-refresh','واگرد (Ctrl+Z)','edt-wide')
    +b('redo',null,'i-refresh','ازنو (Ctrl+Y)','edt-wide edt-flip')
    +'</span>'
    +'<span class="edt-sep"></span>'
    +'<span class="edt-group edt-head">'
    +'<select class="edt-pick" data-format="header" title="سبک متن" aria-label="سبک متن">'
    +'<option value="">متن عادی</option>'
    +'<option value="2">عنوان اصلی</option>'
    +'<option value="3">زیرعنوان</option>'
    +'<option value="4">عنوان کوچک</option>'
    +'</select></span>'
    +'<span class="edt-sep"></span>'
    +'<span class="edt-group">'
    +b('bold',null,'i-bold','ضخیم (Ctrl+B)')
    +b('italic',null,'i-italic','مورب (Ctrl+I)')
    +b('underline',null,'i-underline','زیرخط (Ctrl+U)')
    +b('strike',null,'i-strike','خط‌خورده')
    +b('script','sub','i-sub','زیرنویس')
    +b('script','super','i-sup','بالانویس')
    +b('code',null,'i-code','کد درون‌خطی')
    +'</span>'
    +'<span class="edt-sep"></span>'
    +'<span class="edt-group">'
    +b('blockquote',null,'i-quote','نقل قول')
    +b('code-block',null,'i-code','بلوک کد')
    +'</span>'
    +'<span class="edt-sep"></span>'
    +'<span class="edt-group">'
    +b('list','bullet','i-ul','فهرست نشانه‌دار')
    +b('list','ordered','i-ol','فهرست شماره‌دار')
    +b('indent',null,'i-indent','تورفتگی بیشتر')
    +b('outdent',null,'i-outdent','کاهش تورفتگی')
    +b('divider',null,'i-hr','خط جداکننده')
    +'</span>'
    +'<span class="edt-sep"></span>'
    +'<span class="edt-group">'
    +b('link',null,'i-link','درج پیوند (Ctrl+K)')
    +b('image',null,'i-image','درج تصویر')
    +b('clean',null,'i-clear','حذف قالب‌بندی')
    +'</span>'
    +'<span class="edt-group edt-end">'
    +'<button type="button" class="edt-b edt-wide" data-edt-src title="ویرایش کد HTML" aria-label="ویرایش کد HTML">'+ic('i-source')+'<span class="edt-bt">کد</span></button>'
    +'<button type="button" class="edt-b edt-wide" data-edt-help title="راهنمای قالب‌بندی" aria-label="راهنمای قالب‌بندی">'+ic('i-info')+'<span class="edt-bt">راهنما</span></button>'
    +'</span>'
    +'</div>';
}

function ensureQuill(cb){
  if(window.Quill){cb(window.Quill);return;}
  var pending=(ensureQuill._q=ensureQuill._q||[]);
  pending.push(cb);
  if(ensureQuill._loading){return;}
  ensureQuill._loading=true;
  var s=document.createElement('script');
  s.src=QUILL_SRC;
  s.async=true;
  s.onload=function(){var P=ensureQuill._q||[];ensureQuill._q=[];P.forEach(function(f){try{f(window.Quill);}catch(e){}});};
  s.onerror=function(){var P=ensureQuill._q||[];ensureQuill._q=[];ensureQuill._failed=true;P.forEach(function(f){try{f(null);}catch(e){}});};
  document.head.appendChild(s);
}

/* Upload an image chosen in, dropped on, or pasted into the editor. */
function edtUploadImage(file,cb){
  API.upload('/panel/media',file).then(function(d){
    cb(d&&d.url?d.url:null);
  }).catch(function(err){
    toast((err.errors&&err.errors[0].message)||'بارگذاری تصویر ناموفق بود','e');
    cb(null);
  });
}
function edtPickImage(cb){
  var i=document.createElement('input');
  i.type='file';
  i.accept='image/*';
  i.onchange=function(){
    var f=i.files&&i.files[0];
    if(f){edtUploadImage(f,cb);}
  };
  i.click();
}
function initQuillEditor(host,ta,initial,opts){
  ensureQuill(function(Quill){
    if(!Quill||!host.isConnected&&!host.parentNode){return;}
    var body=host.querySelector('.edt-body');
    var mount=host.querySelector('.edt-q');
    var count=host.querySelector('.edt-count');
    if(!mount||mount.dataset.quillOn){return;}
    mount.dataset.quillOn='1';
    var toolbar=host.querySelector('[data-edt-toolbar]');
    var q=new Quill(mount,{
      theme:null,
      /* tooltip:false — our own Persian titles stay on the controls. */
      modules:{toolbar:toolbar||undefined,tooltip:false},
      placeholder:opts.placeholder||'متن را اینجا بنویسید…'
    });
    mount.setAttribute('dir','rtl');
    q.root.setAttribute('dir','rtl');
    /* Seed through the clipboard API: Quill strips plain root.innerHTML. */
    if(initial){q.clipboard.dangerouslyPasteHTML(initial);}

    function updateCount(){
      if(!count){return;}
      var text=String(q.getText()||'').replace(/\s+/g,' ').trim();
      count.textContent=faNum(text?text.split(' ').length:0)+' واژه · '+faNum(text.length)+' نویسه';
    }
    q.on('text-change',function(){
      /* getSemanticHTML() emits clean tags (no Quill's ql-* spans/classes), so
         HtmlSanitizer's whitelist and richHtml() both accept it. */
      ta.value=q.getSemanticHTML();
      updateCount();
    });
    function insertImage(url){
      if(!url){return;}
      var range=q.getSelection(true);
      q.insertEmbed(range.index,'image',url,'user');
      q.setSelection(range.index+1,'silent');
      ta.value=q.getSemanticHTML();
    }
    var tb=q.getModule('toolbar');
    if(tb&&tb.addHandler){
      tb.addHandler('image',function(){edtPickImage(insertImage);return false;});
      /* The divider blot has no default handler, and Quill 2 does not ship an
         arrow-key nudge handler either. */
      tb.addHandler('divider',function(){
        var r=q.getSelection(true);
        q.insertEmbed(r.index,'divider','user');
        q.setSelection(r.index+1,'silent');
        return false;
      });
    }
    /* HTML source view: staff occasionally need to paste ready-made markup. */
    var srcBtn=host.querySelector('[data-edt-src]');
    if(srcBtn){
      srcBtn.addEventListener('mousedown',function(e){e.preventDefault();});
      srcBtn.addEventListener('click',function(){
        var on=srcBtn.getAttribute('aria-pressed')!=='true';
        if(on){
          ta.value=q.getSemanticHTML();
          ta.classList.remove('hide');
          ta.classList.add('edt-src-on');
          ta.style.display='';
          ta.focus();
        }else{
          q.clipboard.dangerouslyPasteHTML(ta.value);
          ta.classList.add('hide');
          ta.classList.remove('edt-src-on');
          ta.style.display='none';
          q.focus();
        }
        srcBtn.setAttribute('aria-pressed',on?'true':'false');
        srcBtn.classList.toggle('on',on);
        ta.value=on?ta.value:q.getSemanticHTML();
      });
    }
    var helpBtn=host.querySelector('[data-edt-help]');
    if(helpBtn){
      helpBtn.addEventListener('click',function(){
        UI.modal('راهنمای نوشتن متن', '<div class="pad-s i13" style="line-height:2">'
          +'<p>متن را هرطور دوست دارید بنویسید؛ قالب‌بندی اختیاری است.</p>'
          +'<ul style="padding-inline-start:20px;margin:8px 0">'
          +'<li><b>تصویر</b> را می‌توانید از روی دسکتاپ روی متن بکشید و رها کنید، یا آن را کپی و اینجا بچسبانید، یا از نوار ابزار «درج تصویر» را بزنید.</li>'
          +'<li><b>عنوان‌ها</b> با «سبک متن» انتخاب می‌شوند و در صفحه عمومی هم درشت‌تر نمایش داده می‌شوند.</li>'
          +'<li><b>فهرست‌های تودرتو</b> را با «تورفتگی بیشتر» و «کاهش تورفتگی» تنظیم کنید.</li>'
          +'<li>برای حذف قالب‌بندی، روی متن انتخاب‌شده «حذف قالب‌بندی» را بزنید.</li>'
          +'<li>دکمهٔ «کد» کل متن را به شکل HTML نشان می‌دهد؛ اگر اشتباهی کردید همان‌جا اصلاح کنید.</li>'
          +'</ul>'
          +'<p class="mut i12">همین متن بدون تغییر در پنل و در صفحه عمومی مسابقه نمایش داده می‌شود.</p>'
          +'<div class="row jend mt3"><button class="btn btn-p" data-close-edt>متوجه شدم</button></div>'
          +'</div>');
        var c=document.querySelector('[data-close-edt]');
        if(c){c.addEventListener('click',closeDialog);}
      });
    }
    /* Drag & drop and paste both carry a File — upload instead of dropping it. */
    q.root.addEventListener('drop',function(e){
      var f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0];
      if(f&&/^image\//.test(f.type)){e.preventDefault();edtUploadImage(f,insertImage);}
    });
    q.root.addEventListener('paste',function(e){
      var items=e.clipboardData&&e.clipboardData.files;
      var f=items&&items[0];
      if(f&&/^image\//.test(f.type)){e.preventDefault();edtUploadImage(f,insertImage);}
    });
    if(body){body.style.display='none';}
    var fb=host.querySelector('[data-edt-fallback]');
    if(fb){fb.style.display='none';}
    if(ta.classList){ta.classList.remove('edt-src-on');}
    ta.style.display='none';
    updateCount();
    ta.value=q.getSemanticHTML();
  });
}

function editor(name,label,value,opts){
  opts=opts||{};
  return field(label,
    '<div class="edt edt-rich" data-edt="'+esc(name)+'">'
    +edtToolbar()
    +'<div class="edt-q"></div>'
    +'<div class="edt-bar" data-edt-fallback>'
    +'<button type="button" data-cmd="bold" title="ضخیم (Ctrl+B)">'+ic('i-bold')+'</button>'
    +'<button type="button" data-cmd="italic" title="مورب (Ctrl+I)">'+ic('i-italic')+'</button>'
    +'<button type="button" data-cmd="underline" title="زیرخط">'+ic('i-underline')+'</button>'
    +'<button type="button" data-cmd="strikeThrough" title="خط‌خورده">'+ic('i-strike')+'</button>'
    +'<span class="edt-sep"></span>'
    +'<button type="button" data-cmd="formatBlock" data-val="h3" title="عنوان">'+ic('i-h2')+'</button>'
    +'<button type="button" data-cmd="formatBlock" data-val="h4" title="زیرعنوان">'+ic('i-h4')+'</button>'
    +'<button type="button" data-cmd="formatBlock" data-val="p" title="پاراگراف">'+ic('i-p')+'</button>'
    +'<button type="button" data-cmd="formatBlock" data-val="blockquote" title="نقل‌قول">'+ic('i-quote')+'</button>'
    +'<span class="edt-sep"></span>'
    +'<button type="button" data-cmd="insertUnorderedList" title="فهرست">'+ic('i-ul')+'</button>'
    +'<button type="button" data-cmd="insertOrderedList" title="فهرست شماره‌دار">'+ic('i-ol')+'</button>'
    +'<a class="btn-i" data-cmd="createLink" title="پیوند" style="padding:5px">'+ic('i-link')+'</a>'
    +'<button type="button" data-cmd="unlink" title="حذف پیوند" style="padding:5px">'+ic('i-x2')+'</button>'
    +'<a class="btn-i" data-cmd="insertImage" title="تصویر (نشانی)" style="padding:5px">'+ic('i-image')+'</a>'
    +'<a class="btn-i" data-cmd="insertHorizontalRule" title="خط جداکننده" style="padding:5px">'+ic('i-hr')+'</a>'
    +'<span class="edt-sep"></span>'
    +'<button type="button" data-cmd="removeFormat" title="حذف قالب‌بندی">'+ic('i-clear')+'</button>'
    +'<button type="button" data-edt-src title="نمایش کد HTML">'+ic('i-source')+'</button>'
    +'</div>'
    +'<div class="edt-body" contenteditable="true" role="textbox" aria-multiline="true" data-ph="'+(opts.placeholder||'متن را اینجا بنویسید…')+'">'+esc(value||'')+'</div>'
    +'<textarea class="ta edt-src-ta hide" name="'+esc(name)+'" spellcheck="false" dir="ltr" style="display:none">'+esc(value||'')+'</textarea>'
    +'<div class="edt-foot"><span class="i11 mut edt-count"></span>'
    +'<span class="i11 mut">'+ic('i-image')+' تصویرها را می‌توانید روی متن بکشید و رها کنید. همین متن در صفحه عمومی مسابقه هم نمایش داده می‌شود.</span></div>'
    +'</div>',opts);
}
function initEditors(root){
  (root||document).querySelectorAll('[data-edt]').forEach(function(host){
    if(host.dataset.edtOn){return;}
    host.dataset.edtOn='1';
    var ta0=host.querySelector('.edt-src-ta');
    /* Prefer Quill; the contenteditable editor below stays as a fallback and
       takes over only if Quill never arrives. */
    if(ta0&&host.querySelector('.edt-q')){
      initQuillEditor(host,ta0,String(ta0.value||''),{placeholder:host.querySelector('.edt-body')?host.querySelector('.edt-body').dataset.ph:''});
    }
    var body=host.querySelector('.edt-body');
    var ta=host.querySelector('.edt-src-ta');
    var count=host.querySelector('.edt-count');
    /* Scope to the fallback bar: the Quill toolbar has its own [data-edt-src]
       button, and an unscoped querySelector would hand it to this legacy
       handler as well as to Quill's. */
    var fbBar=host.querySelector('[data-edt-fallback]');
    var srcBtn=fbBar?fbBar.querySelector('[data-edt-src]'):null;

    function plain(){
      /* innerText is unavailable in the Node DOM stub, so fall back to tags. */
      return String(body.innerText!=null?body.innerText:body.textContent||'')
        .replace(/<[^>]*>/g,' ').replace(/&nbsp;/g,' ');
    }
    function updateCount(){
      if(!count){return;}
      var text=plain().replace(/\s+/g,' ').trim();
      var words=text?text.split(' ').length:0;
      count.textContent=faNum(words)+' واژه · '+faNum(text.length)+' نویسه';
    }
    /* Plain text paste keeps the stored HTML clean. */
    body.addEventListener('paste',function(e){
      e.preventDefault();
      var text=(e.clipboardData||window.clipboardData).getData('text/plain');
      document.execCommand('insertText',false,text);
    });
    var sync=function(){ta.value=body.innerHTML.trim();updateCount();};
    body.addEventListener('input',sync);

    /* HTML source view: lets an author paste ready-made markup, which is the
       point of a "flexible content" field for a competition description. */
    function setSource(on){
      if(on){
        ta.value=body.innerHTML.trim();
        body.style.display='none';
        ta.style.display='';
        ta.classList.remove('hide');
        ta.classList.add('edt-src-on');
        ta.focus();
      }else{
        body.innerHTML=ta.value.trim();
        ta.style.display='none';
        ta.classList.add('hide');
        ta.classList.remove('edt-src-on');
        body.style.display='';
        body.focus();
      }
      if(srcBtn){srcBtn.setAttribute('aria-pressed',on?'true':'false');}
      sync();
    }
    if(srcBtn){
      srcBtn.addEventListener('click',function(){
        setSource(srcBtn.getAttribute('aria-pressed')!=='true');
      });
    }
    ta.addEventListener('input',function(){if(srcBtn&&srcBtn.getAttribute('aria-pressed')==='true'){updateCount();}});

    host.querySelectorAll('[data-edt-fallback] [data-cmd]').forEach(function(b){
      b.addEventListener('mousedown',function(e){e.preventDefault();});
      b.addEventListener('click',function(){
        var cmd=b.dataset.cmd;
        if(srcBtn&&srcBtn.getAttribute('aria-pressed')==='true'){setSource(false);}
        if(cmd==='createLink'){
          var url=window.prompt('نشانی پیوند (http/https)','https://');
          if(!url){return;}
          document.execCommand('createLink',false,url);
        }else if(cmd==='insertImage'){
          var src=window.prompt('نشانی تصویر (http/https)','https://');
          if(!src){return;}
          document.execCommand('insertImage',false,src);
        }else if(cmd==='formatBlock'){
          document.execCommand('formatBlock',false,b.dataset.val);
        }else{
          document.execCommand(cmd,false,null);
        }
        body.focus();
        sync();
      });
    });
    sync();
  });
}

/* ---------- culture-aware date / datetime picker ---------- */
/*
   fa-IR -> Jalali (Shamsi) calendar, en-US -> Gregorian. The input always shows
   a localized display value and keeps the UTC ISO value in data-iso, so the
   backend keeps receiving ISO-8601 while users type and read Persian dates.
   Pass {time:true} for datetime fields (hour/minute in Tehran time).
*/
function attachDatepicker(input,opts){
  opts=opts||{};
  var withTime=!!opts.time;
  input.readOnly=true;
  input.classList.add('ghf-dp-input');
  input.addEventListener('click',openPicker);

  function current(){
    var iso=input.dataset.iso;
    var p=I18N.fromIso(iso);
    if(p){return p;}
    var t=I18N.todayJalali().split('/').map(Number);
    return {y:t[0],m:t[1],d:t[2],h:0,min:0};
  }
  function commit(y,m,d,h,min){
    var padded=y+'/'+String(m).padStart(2,'0')+'/'+String(d).padStart(2,'0');
    if(withTime){padded+=' '+String(h).padStart(2,'0')+':'+String(min).padStart(2,'0');}
    input.value=padded;
    input.dataset.iso=I18N.toIso(y,m,d,h,min);
    input.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function openPicker(){
    closePicker();
    var c=current();
    var y=c.y,m=c.m;
    var pop=el('<div class="ghf-dp'+(withTime?' with-time':'')+'"></div>');
    function render(){
      var months=I18N.monthNames();
      pop.innerHTML='<div class="ghf-dp-head">'
        +'<button class="ghf-dp-nav" type="button" data-n="-1" title="ماه قبل">'+ic('i-cl')+'</button>'
        +'<div class="ghf-dp-title">'+I18N.num(m)+' '+esc(months[m-1])+' '+I18N.num(y)+'</div>'
        +'<button class="ghf-dp-nav" type="button" data-n="1" title="ماه بعد">'+ic('i-cr')+'</button>'
        +'</div>'
        +'<div class="ghf-dp-week">'+I18N.week().map(function(w){return '<span>'+esc(w)+'</span>';}).join('')+'</div>'
        +'<div class="ghf-dp-grid"></div>'
        +(withTime?'<div class="ghf-dp-time">'
          +'<label>ساعت<input class="inp ltr" type="number" min="0" max="23" data-h value="'+String(c.h).padStart(2,'0')+'"/></label>'
          +'<label>دقیقه<input class="inp ltr" type="number" min="0" max="59" data-min value="'+String(c.min).padStart(2,'0')+'"/></label>'
          +'</div>':'')
        +'<div class="ghf-dp-foot"><button class="ghf-dp-clear" type="button">پاک‌کردن</button>'
        +'<button class="ghf-dp-today" type="button">امروز</button>'
        +'<button class="ghf-dp-set" type="button">ثبت</button></div>';
      var grid=pop.querySelector('.ghf-dp-grid');
      var firstDow=(I18N.rtl?6:0); /* Saturday-first in Iran, Sunday-first in en */
      var g=I18N.rtl?window.j2g(y,m,1):[y,m,1];
      var dow=new Date(Date.UTC(g[0],g[1]-1,g[2])).getUTCDay();
      var shift=(dow-firstDow+7)%7;
      for(var i=0;i<shift;i++){grid.appendChild(el('<span></span>'));}
      var len=I18N.monthLength(y,m);
      var t=I18N.todayJalali().split('/').map(Number);
      for(var d=1;d<=len;d++){
        (function(d){
          var sel=(d===c.d);
          var today=(y===t[0]&&m===t[1]&&d===t[2]);
          var b=el('<button type="button" class="ghf-dp-d'+(sel?' sel':'')+(today?' today':'')+'">'+I18N.num(d)+'</button>');
          b.addEventListener('click',function(){
            var h=pop.querySelector('[data-h]'),mi=pop.querySelector('[data-min]');
            commit(y,m,d,h?+h.value:c.h,mi?+mi.value:c.min);
            if(!withTime){closePicker();}
          });
          grid.appendChild(b);
        })(d);
      }
      pop.querySelectorAll('.ghf-dp-nav').forEach(function(b){
        b.addEventListener('click',function(){
          m+=parseInt(b.dataset.n,10);
          if(m<1){m=12;y--;}
          if(m>12){m=1;y++;}
          render();
        });
      });
      pop.querySelector('.ghf-dp-clear').addEventListener('click',function(){
        input.value='';
        delete input.dataset.iso;
        input.dispatchEvent(new Event('change',{bubbles:true}));
        closePicker();
      });
      pop.querySelector('.ghf-dp-today').addEventListener('click',function(){
        var t2=I18N.todayJalali().split('/').map(Number);
        var h=pop.querySelector('[data-h]'),mi=pop.querySelector('[data-min]');
        commit(t2[0],t2[1],t2[2],h?+h.value:c.h,mi?+mi.value:c.min);
        if(!withTime){closePicker();}
        else{render();}
      });
      var setB=pop.querySelector('.ghf-dp-set');
      if(setB){setB.addEventListener('click',function(){
        var h=pop.querySelector('[data-h]'),mi=pop.querySelector('[data-min]');
        commit(y,m,c.d,h?+h.value:c.h,mi?+mi.value:c.min);
        closePicker();
      });}
    }
    render();
    document.body.appendChild(pop);
    var r=input.getBoundingClientRect();
    var top=r.bottom+6;
    /* Flip above the field when there is no room below. */
    if(top+pop.offsetHeight>window.innerHeight&&r.top>pop.offsetHeight){
      top=r.top-pop.offsetHeight-6;
    }
    pop.style.top=Math.max(6,top+window.scrollY)+'px';
    pop.style.left=Math.max(8,Math.min(r.left+window.scrollX,window.innerWidth-pop.offsetWidth-8))+'px';
    if(I18N.rtl){pop.style.left='auto';pop.style.right=Math.max(8,window.innerWidth-r.right+window.scrollX)+'px';}
    setTimeout(function(){document.addEventListener('click',outside);});
    function outside(e){
      if(!pop.contains(e.target)&&e.target!==input){closePicker();}
    }
    window.__closeGhfPicker=function(){
      pop.remove();
      document.removeEventListener('click',outside);
      window.__closeGhfPicker=null;
    };
  }
}

/* Attach pickers to every date input inside a root (idempotent). */
function initPickers(root){
  (root||document).querySelectorAll('[data-date]').forEach(function(i){
    if(i.dataset.dpOn){return;}
    i.dataset.dpOn='1';
    attachDatepicker(i,{time:i.hasAttribute('data-datetime')});
  });
}
function closePicker(){if(window.__closeGhfPicker){window.__closeGhfPicker();}}

/* Render stored rich text safely: only inline formatting, headings, lists,
   paragraphs and http(s) links survive; everything else is dropped. */
/* Tags the rich editor may produce and richHtml() will render. Keep in sync
   with HtmlSanitizer::ALLOWED_TAGS so what the editor writes survives the
   server-side whitelist. */
var RICH_TAGS={B:1,STRONG:1,I:1,EM:1,U:1,S:1,SUB:1,SUP:1,MARK:1,H1:1,H2:1,H3:1,H4:1,H5:1,H6:1,P:1,BR:1,UL:1,OL:1,LI:1,DIV:1,SPAN:1,A:1,
  BLOCKQUOTE:1,CODE:1,PRE:1,HR:1,IMG:1,FIGURE:1,FIGCAPTION:1,TABLE:1,THEAD:1,TBODY:1,TR:1,TH:1,TD:1};
function richHtml(html){
  if(!html){return '<span class="mut">—</span>';}
  if(window.DOMParser){
    var doc=new DOMParser().parseFromString('<div>'+String(html)+'</div>','text/html');
    var box=doc.body.firstChild;
    (function walk(node){
      var kids=Array.prototype.slice.call(node.childNodes);
      kids.forEach(function(child){
        if(child.nodeType===3){return;}
        if(child.nodeType!==1){child.remove();return;}
        if(!RICH_TAGS[child.tagName]){child.remove();return;}
        if(child.tagName==='A'){
          var href=child.getAttribute('href')||'';
          if(!/^https?:\/\//i.test(href)){child.removeAttribute('href');}
          child.setAttribute('target','_blank');
          child.setAttribute('rel','noopener noreferrer');
        }
        if(child.tagName==='IMG'){
          var src=child.getAttribute('src')||'';
          /* javascript:/data: URLs never reach an <img src>, and dropping the
             attribute leaves the broken-image marker visible instead. */
          if(!/^https?:\/\//i.test(src)){child.remove();return;}
        }
        Array.prototype.slice.call(child.attributes).forEach(function(at){
          var keep=(at.name==='href'||at.name==='target'||at.name==='rel'||at.name==='src'||at.name==='alt'||at.name==='width'||at.name==='height'||at.name==='colspan'||at.name==='rowspan');
          if(!keep){child.removeAttribute(at.name);}
        });
        walk(child);
      });
    })(box);
    return box.innerHTML||'<span class="mut">—</span>';
  }
  return esc(String(html).replace(/<[^>]*>/g,''));
}
function toIso(jy,jm,jd){return I18N.toIso(jy,jm,jd,0,0);}

window.UI={
  sprite:SPRITE,el:el,esc:esc,ic:ic,faNum:faNum,money:money,av:av,
  toast:toast,busy:busy,drawer:drawer,modal:modal,confirm:confirmDlg,typedConfirm:typedConfirm,closeDialog:closeDialog,
  badge:badge,inp:inp,sel:sel,ta:ta,field:field,formValues:formValues,showErrors:showErrors,
  secret:secret,bindSecrets:bindSecrets,
  notifLabel:notifLabel,
  table:table,pagerHtml:pagerHtml,attachDatepicker:attachDatepicker,toIso:toIso,
  pickField:pickField,initPicks:initPicks,spkFromApi:spkFromApi,editor:editor,initEditors:initEditors,
  filterBar:filterBar,debounce:debounce,initPickers:initPickers,initDatepicker:attachDatepicker,richHtml:richHtml,
  skeletonTable:skeletonTable,skeletonCards:skeletonCards,guard:guard,sortRows:sortRows,bindSorting:bindSorting,
  savedViews:savedViews,countdown:countdown,rows:rows,printButton:printButton,printHtml:printHtml,
  preparePrint:preparePrint,doPrint:doPrint,printFilterSummary:printFilterSummary,
  printCountSummary:printCountSummary
};
})();
