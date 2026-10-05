/* ============================================================================
   App shell: install gate, payment-result landing, session bootstrap, history
   router (clean URLs, no hash), role-aware sidebar, topbar (search,
   notifications, language, theme, user menu). Entry point — loads last.
   ============================================================================ */
(function(){
'use strict';

var App={user:null,pages:{},sections:null,query:{},path:'/',cultures:[]};

/* Path segments that belong to the JSON API / static files and must never be
   handled by the SPA router (they are resolved server-side). */
var API_PREFIXES=['/api/','/auth/','/panel/','/media/','/captcha/','/c/','/payment/callback'];

var PAGE_KEYS=[
  'dashboard','profile','users','userDetail','clubs','clubDetail','rades',
  'horses','horseDetail','horseShares','competitions','competitionDetail','lookups',
  'riderCompetitions','riderSignup','signups','mySignups','results','standings','calendar','ranking',
  'payments','paymentOrders','paymentOrderDetail','reconciliation',
  'reports','settings','sms','smsLog','audit','backups','messages',
  'messageDetail','notifications','search'
];

function collectPages(){
  PAGE_KEYS.forEach(function(k){
    if(window.Pages&&window.Pages[k]){App.pages[k]=window.Pages[k];}
  });
}

/* ---------------- navigation ---------------- */
function navFor(role){
  var staff=['admin','manager'].indexOf(role)>-1;
  if(staff){
    var nav=[
      ['dashboard','عملیات'],['users','عملیات'],['clubs','عملیات'],['rades','عملیات'],
      ['horses','عملیات'],['competitions','عملیات'],['calendar','عملیات'],['signups','عملیات'],['lookups','عملیات'],
      ['payments','مالی'],['paymentOrders','مالی'],['reconciliation','مالی'],
      ['reports','تحلیل'],['standings','تحلیل'],['ranking','تحلیل'],
      ['messages','حساب'],['notifications','حساب'],['profile','حساب']
    ];
    if(role==='admin'){
      nav=nav.concat([['settings','سیستم'],['sms','سیستم'],['smsLog','سیستم'],['audit','سیستم'],['backups','سیستم']]);
    }
    return nav;
  }
  if(role==='rider'){
    return [
      ['dashboard','عملیات'],['riderCompetitions','عملیات'],['mySignups','عملیات'],
      ['horses','عملیات'],['horseShares','عملیات'],
      ['notifications','حساب'],['messages','حساب'],['profile','حساب']
    ];
  }
  if(role==='club'){
    return [
      ['dashboard','عملیات'],['competitions','عملیات'],['clubs','عملیات'],
      ['notifications','حساب'],['messages','حساب'],['profile','حساب']
    ];
  }
  return [['dashboard','عملیات'],['profile','حساب']];
}

function firstLast(u){return [u&&u.first_name,u&&u.last_name].filter(Boolean).join(' ')||(u&&u.username)||'کاربر';}
function roleLabel(r){return {admin:'مدیر ارشد',manager:'مدیر',rider:'سوارکار',club:'باشگاه'}[r]||r;}
function avatar(u,size){
  if(u&&u.avatar_media_id){return '<img class="av" src="/media/'+u.avatar_media_id+'" style="width:'+size+'px;height:'+size+'px;object-fit:cover" alt=""/>';}
  return UI.av(firstLast(u),size);
}

/* ---------------- shell ---------------- */
function renderShell(){
  var u=App.user;
  var app=document.getElementById('app');
  app.innerHTML=UI.sprite
    +'<a class="skip" href="#view">پرش به محتوای اصلی</a>'
    +'<div id="side">'
    +'<div style="padding:14px 14px 10px"><div class="row gap2 jb">'
    +'<div class="row gap2">'
    +'<span class="av" style="width:34px;height:34px;border-radius:10px;background:linear-gradient(140deg,#1d8f5f,#0c3b28)">'+UI.ic('i-horse')+'</span>'
    +'<div><div style="color:#fff;font-weight:700;font-size:13px">هیئت سوارکاری گلستان</div><div style="color:var(--side-muted);font-size:10px">سامانه مدیریت</div></div>'
    +'</div>'
    +'<button class="btn-i side-close-btn" id="sideClose" style="color:var(--side-muted)" title="بستن منو">'+UI.ic('i-x2')+'</button>'
    +'</div></div>'
    +'<div class="scroll" style="padding:4px 10px;flex:1" id="navHost"></div>'
    +'<div class="sideprof"><div class="row gap2">'
    +avatar(u,32)
    +'<div class="g1" style="min-width:0"><div style="color:#fff;font-size:12px;font-weight:600" class="trunc">'+UI.esc(firstLast(u))+'</div><div style="color:var(--side-muted);font-size:10.5px">'+UI.esc(roleLabel(u.role))+'</div></div>'
    +'<button class="btn-i" id="logoutB" style="color:var(--side-muted)" title="خروج">'+UI.ic('i-logout')+'</button>'
    +'</div></div>'
    +'</div>'
    +'<div id="sbd"></div>'
    +'<div id="main">'
    +'<div id="top"><div id="topIn">'
    +'<button class="btn-i" id="burger">'+UI.ic('i-menu')+'</button>'+'<b style="font-size:14px" id="pageTitle">—</b>'
      +'<span class="g1"></span>'
      +'<button class="btn-i" id="cmdk" title="جستجوی سریع (Ctrl+K)">'+UI.ic('i-search')+'<span class="i11" style="margin-inline-start:4px">Ctrl K</span></button>'
      +'<button class="btn-i" id="searchB" title="جستجوی پیشرفته">'+UI.ic('i-filter')+'</button>'
    +'<button class="btn-i" id="langB" title="زبان">'+UI.ic('i-globe')+'</button>'
    +'<div class="dd" id="langDD"></div>'
    +'<button class="btn-i" id="bellB" style="position:relative" title="اعلان‌ها">'+UI.ic('i-bell')+'<span id="bellN" class="nbadge" style="display:none;position:absolute;top:2px;inset-inline-end:2px">0</span></button>'+'<button class="btn-i" id="densB" title="فشردگی جدول">'+UI.ic('i-list')+'</button>'
      +'<button class="btn-i" id="themeB" title="پوسته">'+UI.ic('i-moon')+'</button>'
      +'</div>'
      +'<div id="crumbs" class="crumbs"></div></div>'
    +(API.meta&&API.meta.impersonating?'<div id="impB" class="impbar"><span>'+UI.ic('i-eye')+' در حال مشاهده به‌عنوان کاربر دیگر</span><button class="btn btn-sm btn-g" id="impStop">پایان مشاهده</button></div>':'')
    +'<div id="content"><div id="view"></div></div>'
    +'</div>';

  drawNav();
  function toggleSidebar(open){
    var side=document.getElementById('side');
    var sbd=document.getElementById('sbd');
    var isOpen=typeof open==='boolean'?open:!side.classList.contains('open');
    if(side){side.classList.toggle('open',isOpen);}
    if(sbd){sbd.classList.toggle('on',isOpen);}
    document.body.classList.toggle('side-open',isOpen);
  }
  document.getElementById('burger').addEventListener('click',function(){toggleSidebar();});
  document.getElementById('sbd').addEventListener('click',function(){toggleSidebar(false);});
  var sideClose=document.getElementById('sideClose');
  if(sideClose){sideClose.addEventListener('click',function(){toggleSidebar(false);});}
  document.getElementById('logoutB').addEventListener('click',function(){
    UI.confirm('از سامانه خارج می‌شوید؟',function(){
      API.post('/auth/logout',{}).then(function(){App.enter();});
    });
  });
  var imp=document.getElementById('impStop');
  if(imp){imp.addEventListener('click',function(){
    API.post('/panel/impersonation/stop',{})
      .then(function(){UI.toast('مشاهده پایان یافت');App.enter();})
      .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در پایان مشاهده','e');});
  });}
  document.getElementById('searchB').addEventListener('click',function(){App.go('/search');});
  document.getElementById('cmdk').addEventListener('click',openPalette);
  var densB=document.getElementById('densB');
  if(densB){densB.addEventListener('click',toggleDensity);}
  document.addEventListener('keydown',function(e){
    if((e.ctrlKey||e.metaKey)&&String(e.key).toLowerCase()==='k'){
      e.preventDefault();
      openPalette();
    }
  });
  document.getElementById('bellB').addEventListener('click',function(){App.go('/notifications');});
  var langB=document.getElementById('langB');
  if(langB){langB.addEventListener('click',toggleLangMenu);}
  document.getElementById('themeB').addEventListener('click',toggleTheme);
  refreshBell();
  window.addEventListener('popstate',route);
  route();
}
function drawNav(){
  var host=document.getElementById('navHost');
  if(!host||!App.user){return;}
  /* Pages are collected before the nav is drawn; without this the sidebar can
     render empty (the first render after login happened before collectPages). */
  collectPages();
  var html='';
  var items=0;
  var collapsed=collapsedSections();
  var current=null;
  var buf=[];
  function flush(){
    if(!current){return;}
    var open=collapsed.indexOf(current)<0;
    html+='<button class="nsec" data-sec="'+UI.esc(current)+'" aria-expanded="'+open+'"'
      +(open?'':' aria-expanded="false"')+'>'
      +'<span>'+UI.esc(current)+'</span>'+UI.ic('i-cr')+'</button>'
      +'<div class="nsec-items"'+(open?'':' hidden')+'>'+buf.join('')+'</div>';
    buf=[];
  }
  navFor(App.user.role).forEach(function(item){
    var key=item[0],sec=item[1];
    var p=App.pages[key];
    if(!p){return;}
    items++;
    if(sec!==current){flush();current=sec;}
    buf.push('<button class="ni" data-nav="'+key+'" title="'+UI.esc(p.title)+'">'
      +UI.ic(p.icon||'i-file')+'<span>'+UI.esc(p.title)+'</span></button>');
  });
  flush();
  host.innerHTML=items?html:loadingNav();
  host.querySelectorAll('[data-nav]').forEach(function(b){
    b.addEventListener('click',function(){
      /* routePathFor() maps page keys to their real URLs. Using '/'+key
         directly produced /paymentOrders, /smsLog, /mySignups … which the
         pageMap does not know, so those pages 404'd. */
      App.go(routePathFor(b.dataset.nav));
      closeSidebar();
    });
  });
  host.querySelectorAll('[data-sec]').forEach(function(b){
    b.addEventListener('click',function(){
      var itemsBox=b.nextElementSibling;
      if(!itemsBox){return;}
      var open=itemsBox.hasAttribute('hidden');
      if(open){itemsBox.removeAttribute('hidden');}else{itemsBox.setAttribute('hidden','');}
      b.setAttribute('aria-expanded',open?'true':'false');
      toggleSection(b.dataset.sec,!open);
    });
  });
  markActive();
}
/* Remember which sidebar sections the user folded away. */
function collapsedSections(){
  try{return JSON.parse(localStorage.getItem('ghf.nav.collapsed')||'[]');}catch(e){return [];}
}
function toggleSection(name,collapse){
  var list=collapsedSections();
  var i=list.indexOf(name);
  if(collapse&&i<0){list.push(name);}
  if(!collapse&&i>-1){list.splice(i,1);}
  try{localStorage.setItem('ghf.nav.collapsed',JSON.stringify(list));}catch(e){}
}
function loadingNav(){
  var sec='';
  for(var i=0;i<6;i++){sec+='<div class="ni skel" aria-hidden="true"></div>';}
  return sec;
}
function closeSidebar(){
  var side=document.getElementById('side');
  var sbd=document.getElementById('sbd');
  if(side){side.classList.remove('open');}
  if(sbd){sbd.classList.remove('on');}
  document.body.classList.remove('side-open');
}
function markActive(){
  var key=currentKey();
  document.querySelectorAll('#navHost .ni[data-nav]').forEach(function(b){
    b.classList.toggle('on',b.dataset.nav===key);
  });
}
function currentKey(){
  var parts=parseRoute().parts;
  var map={
    users:'users',clubs:'clubs',horses:'horses',competitions:'competitions',signups:'signups','payment-orders':'paymentOrders',messages:'messages',
    rider:'riderCompetitions','horse-shares':'horseShares','sms-log':'smsLog',
    rades:'rades',payments:'payments',reconciliation:'reconciliation',
    reports:'reports',settings:'settings',sms:'sms',audit:'audit',lookups:'lookups',
    backups:'backups',notifications:'notifications',profile:'profile',
    standings:'standings','my-signups':'mySignups'
  };
  var k=parts[0]||'dashboard';
  if(k==='rider'){if(parts[1]==='signups'){return 'mySignups';}return 'riderCompetitions';}
  return map[k]||k;
}
function refreshBell(){
  API.get('/panel/notifications').then(function(d){
    var el=document.getElementById('bellN');
    if(!el){return;}
    var unread=(typeof d.unread==='number')?d.unread:UI.rows(d).filter(function(n){return !Number(n.is_read);}).length;
    el.textContent=UI.faNum(unread);
    el.style.display=unread?'':'none';
  }).catch(function(){});
}

/* ---------------- language switch ---------------- */
var culturesCache=null;
function loadCultures(force){
  if(culturesCache&&!force){return Promise.resolve(culturesCache);}
  return API.get('/panel/cultures').then(function(d){
    culturesCache=(d&&d.cultures)||[];
    App.cultures=culturesCache;
    if(d&&d.active){I18N.set(d.active);}
    return culturesCache;
  }).catch(function(){return culturesCache||[];});
}
function toggleLangMenu(){
  var dd=document.getElementById('langDD');
  if(!dd){return;}
  if(dd.classList.contains('on')){dd.classList.remove('on');return;}
  var btn=document.getElementById('langB');
  var r=btn.getBoundingClientRect();
  dd.style.top=(r.bottom+window.scrollY+6)+'px';
  dd.style.left=Math.max(8,r.left+window.scrollX)+'px';
  dd.classList.add('on');
  loadCultures().then(function(list){
    dd.innerHTML=(list.length?list:[{code:'fa-IR',name:'فارسی'},{code:'en-US',name:'English'}]).map(function(c){
      return '<button type="button" data-lang="'+UI.esc(c.code)+'">'
        +(c.code===I18N.code?UI.ic('i-check'):'<span style="width:15px;display:inline-block"></span>')
        +UI.esc(c.name||c.code)+'</button>';
    }).join('');
    dd.querySelectorAll('[data-lang]').forEach(function(b){
      b.addEventListener('click',function(){setCulture(b.dataset.lang);});
    });
  });
  setTimeout(function(){document.addEventListener('click',outsideLang);});
  function outsideLang(e){
    if(!dd.contains(e.target)&&e.target!==btn){
      dd.classList.remove('on');
      document.removeEventListener('click',outsideLang);
    }
  }
}
function setCulture(code){
  var dd=document.getElementById('langDD');
  if(dd){dd.classList.remove('on');}
  I18N.set(code);
  try{document.cookie='culture='+encodeURIComponent(code)+';path=/;max-age=31536000;samesite=Lax';}catch(e){}
  return API.post('/panel/culture',{culture:code})
    .then(function(){window.location.reload();})
    .catch(function(err){
      UI.toast((err.errors&&err.errors[0].message)||'تغییر زبان ذخیره نشد','e');
    });
}
App.setCulture=setCulture;
App.loadCultures=loadCultures;
function toggleTheme(){
  document.documentElement.classList.toggle('dark');
  try{localStorage.setItem('ghf.dark',document.documentElement.classList.contains('dark')?'1':'0');}catch(e){}
}
/* Compact / comfortable table density, remembered per browser. */
function toggleDensity(){
  var cur=document.documentElement.getAttribute('data-density')==='compact';
  var next=cur?'':'compact';
  if(next){document.documentElement.setAttribute('data-density',next);}
  else{document.documentElement.removeAttribute('data-density');}
  try{localStorage.setItem('ghf.density',next||'cozy');}catch(e){}
  UI.toast(next?'نمایش فشرده فعال شد':'نمایش راحت فعال شد');
}
function applyStoredPrefs(){
  try{
    if(localStorage.getItem('ghf.dark')==='1'){document.documentElement.classList.add('dark');}
    var d=localStorage.getItem('ghf.density');
    if(d==='compact'){document.documentElement.setAttribute('data-density','compact');}
    var cs=localStorage.getItem('ghf.nav.collapsed');
    if(cs){try{document.documentElement.setAttribute('data-nav-collapsed',cs);}catch(e){}}
  }catch(e){}
}
applyStoredPrefs();

/* ---------------- command palette (Ctrl/⌘+K) ---------------- */
/*
   One keyboard entry point for non-technical users: type to jump to any page
   or search any rider / horse / club / competition.
*/
var paletteOpen=false;
function openPalette(){
  if(paletteOpen){return;}
  paletteOpen=true;
  var box=UI.el('<div class="pal"><div class="pal-in">'
    +'<div class="pal-head"><span class="pal-ic">'+UI.ic('i-search')+'</span>'
    +'<input class="pal-inp" placeholder="به کجا می‌خواهید بروید؟ نام سوارکار، اسب، باشگاه یا مسابقه…" autocomplete="off"/>'
    +'<button class="btn-i" data-close>'+UI.ic('i-x')+'</button></div>'
    +'<div class="pal-res"><div class="i12 mut" style="padding:16px">برای دیدن نتایج، عبارتی بنویسید.</div></div>'
    +'<div class="pal-foot"><span><b>↑↓</b> جابه‌جایی</span><span><b>Enter</b> انتخاب</span><span><b>Esc</b> بستن</span></div>'
    +'</div>');
  document.body.appendChild(box);
  var inp=box.querySelector('.pal-inp');
  var res=box.querySelector('.pal-res');
  var items=[];
  var sel=0;

  function close(){
    paletteOpen=false;
    box.remove();
    document.removeEventListener('keydown',onKey,true);
  }
  function onKey(e){
    if(!paletteOpen){return;}
    if(e.key==='Escape'){e.preventDefault();close();}
    else if(e.key==='ArrowDown'){e.preventDefault();move(1);}
    else if(e.key==='ArrowUp'){e.preventDefault();move(-1);}
    else if(e.key==='Enter'){
      e.preventDefault();
      if(items[sel]){items[sel].run();close();}
    }
  }
  function move(d){
    if(!items.length){return;}
    sel=Math.max(0,Math.min(items.length-1,sel+d));
    paintSel();
  }
  function paintSel(){
    res.querySelectorAll('[data-i]').forEach(function(b,i){
      b.classList.toggle('hi',i===sel);
      if(i===sel){b.scrollIntoView({block:'nearest'});}
    });
  }
  function render(list,emptyMsg){
    items=list||[];
    sel=0;
    if(!items.length){
      res.innerHTML='<div class="i12 mut" style="padding:16px">'+UI.esc(emptyMsg||'نتیجه‌ای یافت نشد.</div>')+'';
      return;
    }
    res.innerHTML=items.map(function(it,i){
      return '<button type="button" class="pal-row" data-i="'+i+'"><span class="pal-ic">'+UI.ic(it.icon||'i-cr')+'</span>'
        +'<span class="g1"><span class="b">'+UI.esc(it.title)+'</span>'
        +(it.sub?'<span class="i11 mut">'+UI.esc(it.sub)+'</span>':'')+'</span>'
        +'<span class="i11 mut">'+UI.esc(it.kind||'')+'</span></button>';
    }).join('');
    res.querySelectorAll('[data-i]').forEach(function(b,i){
      b.addEventListener('click',function(){items[i].run();close();});
      b.addEventListener('mousemove',function(){sel=i;paintSel();});
    });
    paintSel();
  }
  function go(path){close();App.go(path);}
  function pages(term){
    var out=[];
    navFor(App.user.role).forEach(function(item){
      var p=App.pages[item[0]];
      if(!p){return;}
      if(term&&String(p.title).toLowerCase().indexOf(term)===-1){return;}
      out.push({title:p.title,sub:item[1],kind:'صفحه',icon:p.icon||'i-cr',
        run:function(){go(routePathFor(item[0]));}});
    });
    return out;
  }
  function entities(term){
    if(!App.user){return Promise.resolve([]);}
    var q={per_page:6};
    if(term){q.search=term;}
    var roles=App.user.role==='rider'?['rider']:(App.user.role==='club'?['club','rider']:['rider','manager','club']);
    return Promise.all(roles.map(function(role){
      return API.get('/panel/users',{role:role,per_page:6,search:term||''}).catch(function(){return {rows:[]};});
    })).then(function(res){
      var out=[];
      res.forEach(function(d){
        UI.rows(d).forEach(function(u){
          out.push({title:[u.first_name,u.last_name].filter(Boolean).join(' ')||u.username,
            sub:'کاربر · '+u.phone,kind:'کاربران',icon:'i-user',run:function(){go('/users/'+u.id);}});
        });
      });
      return out;
    });
  }
  var quick=pages('');
  render(quick);
  inp.focus();
  var runSearch=UI.debounce(function(term){
    term=String(term||'').trim();
    if(!term){
      render(pages(''));
      return;
    }
    var low=term.toLowerCase();
    var local=pages(term);
    render(local);
    entities(term).then(function(list){
      /* Refresh only if the user has not typed anything else. */
      if(inp.value.trim().toLowerCase()!==low){return;}
      render(local.concat(list));
    });
  },220);
  inp.addEventListener('input',function(){runSearch(this.value);});
  box.addEventListener('mousedown',function(e){if(e.target===box){close();}});
  box.querySelector('[data-close]').addEventListener('click',close);
  document.addEventListener('keydown',onKey,true);
}
App.openPalette=openPalette;

/* ---------------- breadcrumbs ---------------- */
App.crumbs=[];
function renderCrumbs(){
  var host=document.getElementById('crumbs');
  if(!host){return;}
  var list=App.crumbs||[];
  if(!list.length){host.innerHTML='';host.style.display='none';return;}
  host.style.display='';
  host.innerHTML=list.map(function(c,i){
    var last=i===list.length-1;
    if(last||!c.path){
      return '<span class="cb'+(last?' on':'')+'">'+UI.esc(c.label)+'</span>';
    }
    return '<a class="cb" href="'+UI.esc(c.path)+'" data-crumb="'+UI.esc(c.path)+'">'+UI.esc(c.label)+'</a>'
      +'<span class="cb-sep">/</span>';
  }).join('');
  host.querySelectorAll('[data-crumb]').forEach(function(a){
    a.addEventListener('click',function(e){e.preventDefault();App.go(a.dataset.crumb);});
  });
}

/* ---------------- router ---------------- */
/* Parse the current location into { parts, query } from the real path
   (/users/65?status=pending) instead of a hash fragment. */
function parseRoute(){
  var path=location.pathname||'/';
  var search=location.search||'';
  var query={};
  search.replace(/^\?/,'').split('&').forEach(function(kv){
    if(!kv){return;}
    var p=kv.split('=');
    try{query[decodeURIComponent(p[0])]=decodeURIComponent((p[1]||'').replace(/\+/g,' '));}
    catch(e){query[p[0]]=p[1]||'';}
  });
  return {parts:path.split('/').filter(Boolean),query:query};
}

/* Build a query string from an object (skips empty values).
   Nested objects such as sort={key,dir} are flattened to key=value pairs. */
function buildQuery(obj){
  var parts=[];
  function put(k,v){
    if(v===undefined||v===null||v==='')return;
    parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v));
  }
  Object.keys(obj||{}).forEach(function(k){
    var v=obj[k];
    if(v&&typeof v==='object'&&!Array.isArray(v)){
      Object.keys(v).forEach(function(sk){put(sk,v[sk]);});
      return;
    }
    put(k,v);
  });
  return parts.length?('?'+parts.join('&')):'';
}

/* Navigate to a clean path. push=true adds a history entry; push=false
   replaces the current one (used when filters change). */
App.go=function(path,query,push){
  var target=(path||'/')+buildQuery(query);
  if(target===location.pathname+location.search){route();return;}
  if(push===false){history.replaceState({},'',target);}
  else{history.pushState({},'',target);}
  route();
};

/* Merge filters into the current URL without adding history entries. */
App.setQuery=function(query){
  App.go(App.path,query,false);
};

/* Same as setQuery but WITHOUT re-running the router. Pages that redraw
   themselves in place (the report engine) use this so a filter change does
   not rebuild the whole page and re-fetch its presets. */
App.replaceQuery=function(query){
  var target=(App.path||location.pathname)+buildQuery(query);
  if(target!==location.pathname+location.search){
    history.replaceState({},'',target);
  }
  return target;
};

/* Seed a page's filter object from the URL. Plain keys are copied verbatim;
   the flattened sort/dir pair is rebuilt into filters.sort. */
App.seed=function(filters){
  var q=App.query||{};
  Object.keys(q).forEach(function(k){
    if(k==='sort'||k==='dir'){return;}
    if(Object.prototype.hasOwnProperty.call(filters,k)){filters[k]=q[k];}
  });
  filters.sort=q.sort?{key:q.sort,dir:q.dir||'asc'}:null;
  return filters;
};

function route(){
  var view=document.getElementById('view');
  if(!view||!window.Pages){return;}
  collectPages();
  UI.closeDialog();
  var parsed=parseRoute();
  var parts=parsed.parts;
  App.query=parsed.query;
  App.path='/'+parts.join('/');
  var key=parts[0]||'dashboard';
  var id=parts[1];
  var sub=parts[2];

  /* rider sub-routes */
  if(key==='rider'){
    if(id==='competitions'&&sub){return renderInto('riderSignup',sub);}
    if(id==='signups'){return renderInto('mySignups');}
    return renderInto('riderCompetitions');
  }
  /* competition results sub-route */
  if(key==='competitions'&&id&&sub==='results'){return renderInto('results',id);}

  var pageMap={
    '':'dashboard',dashboard:'dashboard',profile:'profile',
    users:'users',clubs:'clubs',rades:'rades',horses:'horses','horse-shares':'horseShares',lookups:'lookups',
    competitions:'competitions',signups:'signups','my-signups':'mySignups',
    calendar:'calendar',ranking:'ranking',
    payments:'payments','payment-orders':'paymentOrders',reconciliation:'reconciliation',
    reports:'reports',settings:'settings',sms:'sms','sms-log':'smsLog',audit:'audit',backups:'backups',
    messages:'messages',notifications:'notifications',search:'search',standings:'standings'
  };
  /* A path with an id is a RECORD page (/users/12), which must win over the
     collection page of the same name (/users). This test used to be
     `if(id&&!pageKey)`, but pageKey is always set for these collections, so
     the mapping never ran and clicking a row re-rendered the grid with the id
     still sitting in the URL. */
  var dmap={users:'userDetail',clubs:'clubDetail',horses:'horseDetail',
    competitions:'competitionDetail','payment-orders':'paymentOrderDetail',messages:'messageDetail'};
  if(id&&dmap[key]&&App.pages[dmap[key]]){return renderInto(dmap[key],id);}
  var pageKey=pageMap[key];
  if(!pageKey||!App.pages[pageKey]){
    view.innerHTML='<div class="panel"><div class="empty">'+UI.ic('i-help')+'<div>این صفحه یافت نشد.</div><a class="btn btn-g mt3" href="/dashboard">داشبورد</a></div></div>';
    document.getElementById('pageTitle').textContent='۴۰۴';
    return;
  }
  renderInto(pageKey,id,sub);
}
function renderInto(key,id,sub){
  var p=App.pages[key];
  if(!p){return;}
  var view=document.getElementById('view');
  if(p.roles&&p.roles.indexOf(App.user.role)<0){
    view.innerHTML='<div class="panel"><div class="empty">'+UI.ic('i-lock')+'<div>دسترسی به این بخش برای نقش شما مجاز نیست.</div></div></div>';
    document.getElementById('pageTitle').textContent=p.title;
    renderCrumbs();
    return;
  }
  /* An unsaved-changes guard from the page we are leaving. */
  if(App.guard&&App.guard.isDirty()&&!App.guard.confirmLeave()){
    history.pushState({},'',App.lastPath||location.pathname+location.search);
    return;
  }
  if(App.guard){App.guard.dispose();App.guard=null;}
  App.lastPath=location.pathname+location.search;
  document.getElementById('pageTitle').textContent=p.title;
  setAutoCrumbs(key,id);
  markActive();
  UI.closeDialog();
  closeSidebar();
  view.className='fade-in';
  /* A throwing page must never leave a blank screen: show a recoverable
     panel with the reason and a retry button instead. */
  try{
    p.render(view,App,id,sub);
  }catch(err){
    renderFailure(view,err);
  }
}
function renderFailure(view,err){
  var msg=(err&&(err.errors&&err.errors[0].message||err.message))||'خطای ناشناخته';
  if(window.console&&console.error){console.error('[page]',err);}
  view.innerHTML='<div class="panel pad" style="text-align:center">'
    +'<span class="badge b-bad" style="font-size:30px;padding:14px">'+UI.ic('i-help')+'</span>'
    +'<h3 class="mt3">این صفحه بارگذاری نشد</h3>'
    +'<p class="mut i13 mt2">'+UI.esc(String(msg))+'</p>'
    +'<div class="row jc gap2 mt4">'
    +'<button class="btn btn-p" id="pgRetry">'+UI.ic('i-refresh')+' تلاش دوباره</button>'
    +'<button class="btn btn-g" id="pgHome">بازگشت به داشبورد</button>'
    +'<button class="btn btn-g" id="pgReload">'+UI.ic('i-refresh')+' بارگذاری مجدد صفحه</button>'
    +'</div></div>';
  var retry=view.querySelector('#pgRetry');
  if(retry){retry.addEventListener('click',function(){route();});}
  var home=view.querySelector('#pgHome');
  if(home){home.addEventListener('click',function(){App.go('/dashboard');});}
  var rl=view.querySelector('#pgReload');
  if(rl){rl.addEventListener('click',function(){location.reload();});}
}
/* Breadcrumbs are derived from the route; detail pages can override by
   assigning App.crumbs before finishing their render. */
function setAutoCrumbs(key,id){
  var p=App.pages[key];
  if(!p){App.crumbs=[];renderCrumbs();return;}
  var pathOf=routePathFor(key);
  App.crumbs=[{label:'داشبورد',path:'/dashboard'}];
  if(pathOf!=='/dashboard'){
    App.crumbs.push({label:p.title,path:pathOf});
  }
  if(id){
    App.crumbs.push({label:'جزئیات',path:pathOf+'/'+id});
  }
  renderCrumbs();
}
/* Page key -> real URL. Lives at module scope on purpose: the sidebar, the
   command palette and the breadcrumbs all need it, and a copy nested inside
   openPalette() left App.go(routePathFor(...)) as a ReferenceError there.
   Keys not listed fall back to kebab-case, which is what most pages use. */
function routePathFor(key){
  var m={paymentOrders:'/payment-orders',horseShares:'/horse-shares',smsLog:'/sms-log',
    sms:'/sms',mySignups:'/my-signups',riderCompetitions:'/rider/competitions',
    riderSignup:'/rider/competitions',lookups:'/lookups',calendar:'/calendar',ranking:'/ranking'};
  return m[key]||('/'+key.replace(/[A-Z]/g,function(c){return '-'+c.toLowerCase();}));
}
/* Kept under its old name for anything already using the public helper. */
App.pathFor=routePathFor;

/* ---------------- payment result landing ---------------- */
function renderPaymentResult(){
  var ok=location.pathname.indexOf('/payment/success')>-1;
  API.get(ok?'/payment/success':'/payment/failed',ok?{}:{reason:new URLSearchParams(location.search).get('reason')||''})
    .then(function(d){
      var app=document.getElementById('app');
      app.innerHTML=UI.sprite+'<div style="min-height:100vh;display:grid;place-items:center;padding:24px">'
        +'<div class="panel pad" style="max-width:460px;text-align:center">'
        +'<span class="badge '+(ok?'b-ok':'b-bad')+'" style="font-size:34px;padding:16px">'+UI.ic(ok?'i-ccheck':'i-info')+'</span>'
        +'<h2 class="mt4">'+(ok?'پرداخت با موفقیت انجام شد':'پرداخت ناموفق بود')+'</h2>'
        +'<p class="mut i13 mt2">'+UI.esc(d.message||(ok?'ثبت‌نام شما پس از تأیید پرداخت نهایی می‌شود.':'در صورت کسر مبلغ، تا ۷۲ ساعت بازگردانده می‌شود.'))+'</p>'
        +'<a class="btn btn-p btn-w mt4" href="/">بازگشت به سامانه</a>'
        +'</div></div>';
    }).catch(function(){location.href='/';});
}

/* ---------------- session bootstrap ---------------- */
App.enter=function(){
  API.get('/panel/requirements').then(function(info){
    if(info&&!info.installed){App.user=null;Auth.renderInstall(info);return;}
    loadSession();
  }).catch(function(){loadSession();});
};

/* The install state lives in the app.installed setting. When an admin wipes
   the database from the maintenance page the flag flips to false, so the next
   authenticated request has to bounce the browser back to the installer
   instead of leaving a dead shell on screen. */
function watchInstallState(){
  setInterval(function(){
    if(!App.user){return;}
    API.get('/panel/requirements').then(function(info){
      if(info&&info.installed===false){location.href='/install';}
    }).catch(function(){});
  },60000);
}

function loadSession(){
  API.get('/panel/profile').then(function(u){
    App.user=u;
    try{if(localStorage.getItem('ghf.dark')==='1'){document.documentElement.classList.add('dark');}}catch(e){}
    renderShell();
    watchInstallState();
  }).catch(function(){
    App.user=null;
    Auth.renderLogin();
  });
}

API.on401(function(){
  App.user=null;
  if(document.getElementById('app')){Auth.renderLogin();}
});

App.refreshBell=refreshBell;

window.addEventListener('unhandledrejection',function(e){
  var err=e.reason||{};
  if(err&&err.errors&&err.errors.length){UI.toast(err.errors[0].message||'خطا','e');}
  if(e.preventDefault){e.preventDefault();}
});

document.addEventListener('DOMContentLoaded',function(){
  if(location.pathname.indexOf('/payment/')===0){renderPaymentResult();return;}
  if(location.pathname==='/install'){
    /* The installer is its own screen: show it whatever the stored flag says. */
    API.get('/panel/requirements').then(function(info){
      Auth.renderInstall(info);
    }).catch(function(){
      Auth.renderInstall({installed:false,requirements:[]});
    });
    return;
  }
  App.enter();
});

/* Internal links are handled by the router: clean URLs, no full page reload.
   External links, downloads, API/media paths and modified clicks pass through. */
document.addEventListener('click',function(e){
  var a=e.target.closest?e.target.closest('a[href]'):null;
  if(!a){return;}
  var href=a.getAttribute('href')||'';
  if(!href||href.charAt(0)==='#'){return;}
  if(a.target==='_blank'||a.hasAttribute('download')){return;}
  if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||e.button!==0){return;}
  if(API_PREFIXES.some(function(p){return href===p.replace(/\/$/,'')||href.indexOf(p)===0;})){return;}
  if(href.indexOf('//')===0||/^(https?:|mailto:|tel:)/i.test(href)){return;}
  e.preventDefault();
  App.go(href);
});

window.App=App;
})();
