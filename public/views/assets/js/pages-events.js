/* ============================================================================
   Competition pages: staff list/CRUD/lifecycle/clone, competition-rades,
   results entry/publish, standings; rider browse + signup + payment.
   ============================================================================ */
(function(){
'use strict';

var COMP_STATUS=[{v:'draft',l:'پیش‌نویس'},{v:'open',l:'باز'},{v:'closed',l:'بسته'},{v:'running',l:'در حال اجرا'},{v:'finished',l:'پایان‌یافته'},{v:'cancelled',l:'لغوشده'}];
var SIGNUP_STATUS=[{v:'pending_payment',l:'در انتظار پرداخت'},{v:'paid',l:'پرداخت‌شده'},{v:'confirmed',l:'تأییدشده'},{v:'rejected',l:'ردشده'},{v:'cancelled',l:'لغوشده'},{v:'withdrawn',l:'کنارگذاشته'}];

/* Signup grid columns. Module scope on purpose: these used to be declared
   inside Pages.competitions.render(), where Pages.signups could not see
   them — its load() threw "SCOLS is not defined" inside the .then(), so a
   200 response still left the grid showing its loading skeleton forever. */
var SCOLS=[
  {label:'<input type="checkbox" id="selAll"/>',raw:true,render:function(r){return '<input type="checkbox" class="selrow" data-id="'+r.id+'"/>';}},
  {label:'سوارکار',sortKey:'rider_name',sortValue:function(r){return r.rider_name||r.rider_user_id;},render:function(r){return '<a class="b i13" href="/users/'+r.rider_user_id+'">'+UI.esc(r.rider_name||('سوارکار #'+UI.faNum(r.rider_user_id)))+'</a>';}},
  {label:'اسب',sortKey:'horse_name',sortValue:function(r){return r.horse_name||r.horse_id;},render:function(r){return r.horse_id?'<a href="/horses/'+r.horse_id+'">'+UI.esc(r.horse_name||'—')+'</a>':UI.esc(r.horse_name||'—');}},
  {label:'مسابقه',sortKey:'competition_title',sortValue:function(r){return r.competition_title||r.competition_id;},render:function(r){return r.competition_id?'<a href="/competitions/'+r.competition_id+'" class="wrap">'+UI.esc(r.competition_title||'—')+'</a>':UI.esc(r.competition_title||'—');}},
  {label:'رده',sortKey:'rade_name',sortValue:function(r){return r.rade_name||r.rade_id;},render:function(r){return UI.esc(r.rade_name||'—');}},
  {label:'مبلغ',sortKey:'amount',sortValue:function(r){return Number(r.payment_amount_irt_snapshot!=null?r.payment_amount_irt_snapshot:r.amount_irt);},render:function(r){return UI.money(r.payment_amount_irt_snapshot!=null?r.payment_amount_irt_snapshot:r.amount_irt);}},
  {label:'مقام',sortKey:'position',sortValue:function(r){return r.position==null?999:Number(r.position);},render:function(r){return r.position!=null?UI.faNum(r.position):'—';}},
  {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status);}},
  {label:'عملیات',render:function(r){return '<div class="row gap1">'
    +(r.status==='paid'?'<button class="btn btn-s btn-sm" data-ok="'+r.id+'">تأیید</button>':'')
    +(r.status==='confirmed'?'<button class="btn btn-g btn-sm" data-pos="'+r.id+'">مقام</button>':'')
    +(r.status!=='rejected'?'<button class="btn btn-d btn-sm" data-no="'+r.id+'">رد</button>':'')+'</div>';}}
];

function isStaff(ctx){return ['admin','manager'].indexOf(ctx.user.role)>-1;}

/* Build the query object for an API call from a page's filter state.
   `sort` is a nested {key,dir} object (App.seed rebuilds it from the flattened
   ?sort=…&dir=… pair), and api.js' qs() stringifies nested objects as
   "[object Object]" — so flatten it into the two params the services read.
   Pagination and ordering therefore happen in SQL, not on the loaded page. */
function apiParams(filters){
  var p={};
  Object.keys(filters||{}).forEach(function(k){
    if(k!=='sort'){p[k]=filters[k];}
  });
  if(filters&&filters.sort&&filters.sort.key){
    p.sort=filters.sort.key;
    p.dir=filters.sort.dir||'asc';
  }
  return p;
}

function dateField(name,label,val){
  return '<div><label class="lbl req">'+UI.esc(label)+'</label>'
    +'<input class="inp" name="'+name+'" data-date readonly value="'+UI.esc(val?I18N.fmtDate(val):'')+'"'
    +(val?' data-iso="'+UI.esc(val)+'"':'')+'/></div>';
}
function collectCompDates(f,v){
  ['start_registration_at','end_registration_at','start_at','end_at'].forEach(function(k){
    var inp=f.querySelector('[name='+k+']');
    v[k]=inp&&inp.dataset.iso?inp.dataset.iso:null;
  });
}

function openCompDialog(c,ctx,onSaved,onCreated){
  var isNew=!c;
  c=c||{};
  API.get('/panel/clubs',{status:'active',per_page:250}).then(function(clubs){
    var clubOpts=[{v:'',l:'— انتخاب میدان —'}].concat(UI.rows(clubs).map(function(cl){return {v:cl.id,l:cl.name};}));
    var body=UI.el('<div class="pad-s"><form id="fC" class="frow">'
      +UI.inp('title','عنوان',c.title,null,{req:true,full:true})
      +UI.pickField({name:'venue_club_id',label:'میدان (باشگاه میزبان)',value:c.venue_club_id||'',
        selectedLabel:(clubOpts.filter(function(o){return String(o.v)===String(c.venue_club_id);})[0]||{}).l,
        options:clubOpts,placeholder:'جستجوی باشگاه میزبان…'})
      +UI.inp('city','شهر',c.city)
      +dateField('start_registration_at','شروع ثبت‌نام',c.start_registration_at)
      +dateField('end_registration_at','پایان ثبت‌نام',c.end_registration_at)
      +dateField('start_at','شروع مسابقه',c.start_at)
      +dateField('end_at','پایان مسابقه',c.end_at)
      +(isNew?UI.sel('status','وضعیت اولیه',c.status||'draft',COMP_STATUS,{full:true}):'')
      +UI.editor('description','دربارهٔ مسابقه',c.description,{full:true,
        placeholder:'این مسابقه دربارهٔ چیست؟ می‌توانید عنوان، فهرست و حتی تصویر بنویسید یا تصویر را روی متن بکشید.',
        hint:'همین متن عیناً در صفحه عمومی مسابقه برای همه نمایش داده می‌شود؛ پس تصویرها و عنوان‌ها همان‌جا هم دیده می‌شوند.'})
      +UI.editor('rules','آیین‌نامه و قوانین',c.rules,{full:true,
        placeholder:'شرایط حضور، تجهیزات، جرایم و مواردی که سوارکار باید بداند…',
        hint:'برای خوانایی، شرط‌ها را با فهرست نشانه‌دار بنویسید.'})
      +UI.editor('announcement','اطلاعیهٔ مسابقه (پیش از ثبت‌نام)',c.announcement,{full:true,
        placeholder:'مهم‌ترین نکته‌ای که سوارکار باید قبل از ثبت‌نام بداند…',
        hint:'اگر «خواندن اطلاعیه الزامی است» را انتخاب کنید، سوارکار باید پیش از ثبت‌نام این متن را بخواند و تأیید کند.'})
      +'<label class="full row gap2 i13"><input type="checkbox" name="announcement_required"'
        +(Number(c.announcement_required)?' checked':'')+'/> خواندن اطلاعیه الزامی است</label>'
      +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>');
    UI.modal(isNew?'مسابقه جدید':'ویرایش مسابقه',body);
    body.querySelectorAll('[data-date]').forEach(function(i){UI.attachDatepicker(i);});
    UI.initEditors(body);
    /* Keep "required" disabled while the announcement box is empty. */
    var annBox=body.querySelector('[data-edt="announcement"] .edt-body');
    var annReq=body.querySelector('[name=announcement_required]');
    if(annBox&&annReq){
      var syncAnn=function(){annReq.disabled=!annBox.textContent.trim();};
      annBox.addEventListener('input',syncAnn);
      syncAnn();
    }
    body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
    body.querySelector('[data-save]').addEventListener('click',function(){
      var f=body.querySelector('#fC');
      var v=UI.formValues(f);
      if(v.venue_club_id===''){delete v.venue_club_id;}
      collectCompDates(f,v);
      (isNew?API.post('/panel/competitions',v):API.put('/panel/competitions/'+c.id,v))
        .then(function(d){
          UI.closeDialog();UI.toast('ذخیره شد');
          if(isNew&&d&&d.id&&onCreated){onCreated(d.id);}
          else if(onSaved){onSaved();}
        })
        .catch(function(err){UI.showErrors(f,err.errors);});
    });
  });
}

/* Panel showing the shareable public competition link, with copy helpers. */
function publicUrlPanel(c){
  var url=location.origin+'/c/'+encodeURIComponent(c.slug||'');
  var shown=!!c.slug;
  return '<div class="panel pad mt4" id="pubCard" data-title="'+UI.esc(c.title||'مسابقه')+'">'
    +'<b class="i13 row gap2">'+UI.ic('i-share')+' لینک عمومی مسابقه</b>'
    +(shown
      ? '<p class="hint">این صفحه بدون ورود هم قابل مشاهده و به‌اشتراک‌گذاری است.</p>'
        +'<div class="row gap2 mt3 wrap">'
        +'<input class="inp ltr g1" id="pubUrl" readonly value="'+UI.esc(url)+'"/>'
        +'<button class="btn btn-p btn-sm" id="pubCopy">'+UI.ic('i-copy')+' کپی لینک</button>'
        +'<button class="btn btn-g btn-sm" id="pubOpen">'+UI.ic('i-eye')+' باز کردن</button>'
        +'<button class="btn btn-g btn-sm" id="pubShare">'+UI.ic('i-send')+' اشتراک‌گذاری</button>'
        +'</div>'
      : '<p class="hint">پس از ذخیره مسابقه، لینک عمومی ساخته می‌شود.</p>')
    +'</div>';
}

function initPublicUrlPanel(){
  var copy=document.getElementById('pubCopy');
  var inp=document.getElementById('pubUrl');
  function url(){return inp?inp.value:'';}
  if(copy){
    copy.addEventListener('click',function(){
      if(!inp){return;}
      inp.select();
      var done=function(){UI.toast('لینک کپی شد');};
      if(navigator.clipboard&&navigator.clipboard.writeText){
        navigator.clipboard.writeText(url()).then(done,function(){
          try{document.execCommand('copy');done();}catch(e){UI.toast('کپی نشد — دستی انتخاب کنید','w');}
        });
      }else{
        try{document.execCommand('copy');done();}catch(e){UI.toast('کپی نشد — دستی انتخاب کنید','w');}
      }
    });
  }
  var open=document.getElementById('pubOpen');
  if(open){open.addEventListener('click',function(){window.open(url(),'_blank','noopener');});}
  var share=document.getElementById('pubShare');
  if(share){
    share.addEventListener('click',function(){
      var card=document.getElementById('pubCard');
      var payload={title:(card&&card.dataset.title)||'مسابقه',text:'جزئیات مسابقه',url:url()};
      if(navigator.share){navigator.share(payload).catch(function(){});}
      else if(copy){copy.click();}
    });
  }
}

var Pages={};

/* ---------------- staff competitions ---------------- */
Pages.competitions={
  title:'مسابقات',icon:'i-trophy',sec:'عملیات',
  render:function(root,ctx){
    var filters=App.seed({status:'',search:'',page:1});
    var staff=isStaff(ctx);
    root.innerHTML=pageHead('مسابقات','مدیریت مسابقات، رده‌ها و ظرفیت‌ها.',
      (staff?'<button class="btn btn-p btn-sm" id="newC">'+UI.ic('i-plus')+' مسابقه جدید</button>':''))
      +'<div class="mb3" id="fbarHost"></div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'competitions',
      searchLabel:'جستجوی عنوان، شهر یا میدان…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'}].concat(COMP_STATUS)}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(6)+'</div>'));
    /* Total capacity across the competition's rades (0 = unlimited). */
    function capacityOf(r){return Number(r.total_capacity||0);}
    function capacityBar(used,total){
      if(!total){
        return UI.faNum(used)+' <span class="i11 mut">نامحدود</span>';
      }
      var pct=Math.min(100,Math.round((used/total)*100));
      var tone=pct>=100?'b-bad':pct>=80?'b-warn':'b-ok';
      return '<div class="capbar" title="'+UI.faNum(used)+' از '+UI.faNum(total)+'">'
        +'<div class="capbar-t"><span class="badge '+tone+'">'+UI.faNum(pct)+'٪</span>'
        +'<span class="i11 mut">'+UI.faNum(used)+' / '+UI.faNum(total)+'</span></div>'
        +'<div class="capbar-b"><i style="width:'+pct+'%;background:var(--'
        +(pct>=100?'danger':pct>=80?'gold':'brand')+')"></i></div></div>';
    }
    var CCOLS=[
      {label:'مسابقه',sortKey:'title',sortValue:function(r){return r.title;},render:function(r){return '<div class="b i13">'+UI.esc(r.title)+'</div><div class="i11 mut">'+UI.esc(r.city||'')+' · '+I18N.fmtDate(r.start_at)+'</div>';}},
      {label:'میدان',key:'venue_name',sortKey:'venue_name'},
      {label:'رده‌ها',sortKey:'rade_count',sortValue:function(r){return r.rade_count||0;},render:function(r){return UI.faNum(r.rade_count||0);}},
      {label:'ثبت‌نام',sortKey:'signup_count',sortValue:function(r){return r.signup_count||0;},render:function(r){return capacityBar(r.signup_count||0,capacityOf(r));}},
      {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status)+(Number(r.registration_paused)?' <span class="badge b-warn">توقف ثبت‌نام</span>':'');}},
      {label:'عملیات',render:function(r){return staff?'<div class="row gap1">'
        +(r.status==='draft'?'<button class="btn btn-s btn-sm" data-status="'+r.id+'" data-to="open">بازکردن</button>':'')
        +(r.status==='open'?'<button class="btn btn-g btn-sm" data-status="'+r.id+'" data-to="closed">بستن</button>':'')
        +'</div>':'';}}
    ];
    var nb=root.querySelector('#newC');
    if(nb){nb.addEventListener('click',function(){openCompDialog(null,ctx,load,function(id){App.go('/competitions/'+id);});});}
    function load(){
      API.get('/panel/competitions',filters).then(function(d){
        var rows=UI.sortRows(UI.rows(d),CCOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' مسابقه');
        root.querySelector('#tbl').innerHTML=UI.table(CCOLS,rows,{
          rowHref:function(r){return '/competitions/'+r.id;},
          pager:UI.pagerHtml(d.total||rows.length,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی مسابقه‌ای یافت نشد':'مسابقه‌ای ثبت نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        bindTableNav(root,function(h){App.go(h);});
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
        root.querySelectorAll('[data-status]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.put('/panel/competitions/'+b.dataset.status,{status:b.dataset.to}).then(load);});});
      });
    }
    load();
  }
};

/* Wide banner block for a competition. Served from the public /c/{slug}/banner
   route (uploads live outside the web root, and the public page is anonymous),
   so the same image shows on the panel page and the shared page. */
function compBannerBlock(c,staff,onChanged){
  var b=c.banner;
  var up='<label class="btn btn-s btn-sm">'+UI.ic('i-image','')+(b?' جایگزینی بنر':' افزودن بنر')+'<input type="file" accept="image/*" hidden data-bfile/></label>';
  var inner=b
    ? '<img src="/c/'+UI.esc(c.slug||'')+'/banner" alt="'+UI.esc(c.title||'')+'"/>'
      +'<div class="cb-cap"><b>'+UI.esc(c.title||'')+'</b></div>'
      +(staff?'<div class="cb-ops">'+up+'<button class="btn btn-d btn-sm" data-bdel>'+UI.ic('i-trash')+'</button></div>':'')
    : (staff
      ? '<div class="cb-empty">'+UI.ic('i-image','')
        +'<div>بنر مسابقه</div>'
        +'<div class="i11">تصویر عریض (پیشنهاد: ۱۶۰۰×۶۰۰ یا بزرگ‌تر) — روی صفحه عمومی مسابقه نمایش داده می‌شود.</div>'
        +up+'</div>'
      : '<div class="cb-empty">'+UI.ic('i-image','')+'<div>برای این مسابقه بنری ثبت نشده است</div></div>');
  var host=UI.el('<div class="comp-banner">'+inner+'</div>');
  var fin=host.querySelector('[data-bfile]');
  if(fin){fin.addEventListener('change',function(){
    var f=this.files&&this.files[0];
    if(!f){return;}
    UI.toast('در حال بارگذاری بنر…');
    API.upload('/panel/competitions/'+c.id+'/banner',f)
      .then(function(){UI.toast('بنر ذخیره شد');onChanged();})
      .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در بارگذاری','e');});
  });}
  var del=host.querySelector('[data-bdel]');
  if(del){del.addEventListener('click',function(){
    UI.confirm('بنر مسابقه حذف شود؟',function(){API.del('/panel/competitions/'+c.id+'/banner').then(function(){UI.toast('بنر حذف شد');onChanged();});});
  });}
  return host;
}

/* ---------------- signups inside the competition page ---------------- */
/*
   Staff used to be bounced out to /signups to see a competition's entries.
   They now stay on the competition page, where the entries are grouped by
   "rade" the way a jury sheet is actually read, with a toggle back to one
   flat sortable list. Grouped view = the UC that matters (capacity per rade,
   who is missing from a class), flat view = the admin view.
   View state lives in the URL (?su_view=&su_status=&su_q=) so a filtered view
   can be shared or survive a reload. */
function compSignupsSection(comp,ctx,onChanged){
  var id=comp.id;
  var rades=(comp.rades||[]).slice().sort(function(a,b){
    return (a.sort_order==null?0:a.sort_order)-(b.sort_order==null?0:b.sort_order);
  });
  var state={view:'rade',status:'',q:''};
  var q=App.query||{};
  if(q.su_view==='flat'){state.view='flat';}
  if(q.su_status){state.status=String(q.su_status);}
  if(q.su_q){state.q=String(q.su_q);}
  /* SCOLS minus the checkbox column (bulk approve lives on /signups) and
     minus the competition column (this grid is already inside one). */
  var COLS=SCOLS.filter(function(c){return !c.raw&&c.label!=='مسابقه';});

  function pushState(){
    App.replaceQuery({su_view:state.view==='flat'?'flat':'',su_status:state.status,su_q:state.q});
  }

  var host=UI.el('<div class="panel pad" id="suSec">'
    +'<div class="row jb gap2 wrap jc">'
    +'<b class="i13">'+UI.ic('i-users')+' ثبت‌نام‌های این مسابقه</b>'
    +'<div class="row gap1 wrap">'
    +'<button class="btn btn-s btn-sm" data-su-view="rade">'+UI.ic('i-list')+' گروهی بر اساس رده</button>'
    +'<button class="btn btn-g btn-sm" data-su-view="flat">فهرست یکپارچه</button>'
    +'<a class="btn btn-g btn-sm" href="/signups?competition_id='+id+'">صفحه کامل ثبت‌نام‌ها</a>'
    +'</div></div>'
    +'<div class="row gap2 wrap mt3 jc">'
    +'<select class="sel" id="suStatus" style="max-width:190px">'
    +'<option value="">همه وضعیت‌ها</option>'
    +SIGNUP_STATUS.map(function(s){return '<option value="'+s.v+'"'+(state.status===s.v?' selected':'')+'>'+s.l+'</option>';}).join('')
    +'</select>'
    +'<input class="inp g1" id="suQ" placeholder="جستجوی سوارکار یا اسب…" value="'+UI.esc(state.q)+'" style="max-width:260px"/>'
    +'<span class="i12 mut" id="suCount"></span>'
    +'</div>'
    +'<div class="mt3" id="suBody"><div class="spin"></div></div>'
    +'</div>');

  function matches(row){
    if(state.status&&row.status!==state.status){return false;}
    if(state.q){
      var term=state.q.toLowerCase();
      var hay=((row.rider_name||'')+' '+(row.horse_name||'')+' '+(row.club_name||'')).toLowerCase();
      if(hay.indexOf(term)<0){return false;}
    }
    return true;
  }

  function actions(){
    var b=host.querySelector('#suBody');
    b.querySelectorAll('[data-ok]').forEach(function(x){x.addEventListener('click',function(){API.post('/panel/signups/'+x.dataset.ok+'/confirm',{}).then(done);});});
    b.querySelectorAll('[data-no]').forEach(function(x){x.addEventListener('click',function(){UI.confirm('ثبت‌نام رد شود؟',function(){API.post('/panel/signups/'+x.dataset.no+'/reject',{reason:'manual'}).then(done);});});});
    b.querySelectorAll('[data-pos]').forEach(function(x){x.addEventListener('click',function(){
      var row=rows.filter(function(r){return String(r.id)===String(x.dataset.pos);})[0]||{};
      var body=UI.el('<div class="pad-s"><label class="lbl">مقام (۱ تا …)</label><input class="inp" id="pos" type="number" min="1" inputmode="numeric" value="'+UI.esc(row.position!=null?row.position:'')+'"/>'
        +'<label class="row gap2 mt2 i13"><input type="checkbox" id="win"'+(Number(row.is_winner)?' checked':'')+'/> برنده</label>'
        +'<div class="row jend mt3"><button class="btn btn-p" data-save>ثبت</button></div></div>');
      UI.modal('تعیین مقام',body);
      body.querySelector('[data-save]').addEventListener('click',function(){
        API.post('/panel/signups/'+x.dataset.pos+'/position',{position:body.querySelector('#pos').value,is_winner:body.querySelector('#win').checked})
          .then(function(){UI.closeDialog();done();});
      });
    });});
  }

  var rows=[];
  function done(){
    if(typeof onChanged==='function'){onChanged();}
    load();
  }

  function groupedView(){
    /* Rows whose rade is not in the competition's rade list still have to be
       visible, so they are collected under a catch-all group instead of
       silently disappearing. */
    var byRade={},order=[];
    rades.forEach(function(rd){
      var key=String(rd.rade_id!=null?rd.rade_id:rd.id);
      byRade[key]={label:rd.rade_name||rd.name||('رده #'+UI.faNum(rd.rade_id)),rows:[]};
      order.push(key);
    });
    var other=[];
    rows.forEach(function(r){
      var key=String(r.rade_id);
      if(byRade[key]){byRade[key].rows.push(r);}
      else{other.push(r);}
    });
    var totalShown=rows.length;
    if(other.length){
      byRade['__other']={label:'سایر رده‌ها',rows:other};
      order.push('__other');
    }
    if(!totalShown){
      return '<div class="empty">'+UI.ic('i-users','')
        +'<div>'+(state.status||state.q?'با این فیلترها ثبت‌نامی یافت نشد':'هنوز ثبت‌نامی برای این مسابقه ثبت نشده است')+'</div></div>';
    }
    return '<div class="stack">'+order.map(function(key,idx){
      var g=byRade[key];
      var cap=g.rows.length;
      var meta='';
      if(key!=='__other'){
        var rd=rades.filter(function(x){return String(x.rade_id!=null?x.rade_id:x.id)===key;})[0];
        if(rd){
          var capacity=rd.capacity?Number(rd.capacity):0;
          meta=capacity?(' ظرفیت '+UI.faNum(capacity)+' — '+(capacity-cap>0?UI.faNum(capacity-cap)+' جای باقی‌مانده':'ظرفیت تکمیل'))
            :(' ظرفیت نامحدود — '+(rd.amount_irt?UI.money(rd.amount_irt):'بدون هزینه'));
        }
      }
      return '<details class="rade-acc"'+(cap||idx<2?' open':'')+'>'
        +'<summary class="rade-acc-h"><span class="i13 b">'+UI.esc(g.label)+'</span>'
        +'<span class="badge b-mut">'+UI.faNum(cap)+' ثبت‌نام</span>'
        +'<span class="i11 mut">'+UI.esc(meta)+'</span>'
        +'<span class="g1"></span><span class="acc-i">'+UI.ic('i-cl')+'</span></summary>'
        +'<div class="rade-acc-b">'
        +(g.rows.length?UI.table(COLS,g.rows,{sort:state.sort,emptyText:'ثبت‌نامی در این رده نیست'})
          :'<div class="empty" style="padding:18px">در این رده ثبت‌نامی نشده است</div>')
        +'</div></details>';
    }).join('')+'</div>';
  }

  function load(){
    host.querySelector('#suBody').innerHTML='<div class="spin"></div>';
    var params={competition_id:id,per_page:250};
    if(state.status){params.status=state.status;}
    if(state.q){params.search=state.q;}
    if(state.sort&&state.sort.key){params.sort=state.sort.key;params.dir=state.sort.dir||'asc';}
    API.get('/panel/signups',params).then(function(d){
      rows=UI.rows(d).filter(matches);
      var total=(d&&d.total)||rows.length;
      host.querySelector('#suCount').textContent=UI.faNum(rows.length)+' ردیف از '+UI.faNum(total)+' ثبت‌نام'
        +(state.status||state.q?' (فیلتر فعال)':'');
      host.querySelectorAll('[data-su-view]').forEach(function(b){
        b.className='btn btn-sm '+(b.dataset.suView===state.view?'btn-s':'btn-g');
      });
      var body=host.querySelector('#suBody');
      if(state.view==='rade'){
        body.innerHTML=groupedView();
      }else{
        body.innerHTML=UI.table(COLS,rows,{
          sort:state.sort,
          emptyText:state.status||state.q?'با این فیلترها ثبت‌نامی یافت نشد':'هنوز ثبت‌نامی برای این مسابقه ثبت نشده است'
        });
        UI.bindSorting(host,state,function(key,dir){state.sort={key:key,dir:dir};pushState();load();});
        bindTableNav(host,function(){/* rows carry real links */});
      }
      actions();
    });
  }

  host.querySelectorAll('[data-su-view]').forEach(function(b){
    b.addEventListener('click',function(){state.view=b.dataset.suView;pushState();load();});
  });
  host.querySelector('#suStatus').addEventListener('change',function(){
    state.status=this.value;pushState();load();
  });
  host.querySelector('#suQ').addEventListener('input',UI.debounce(function(){
    state.q=this.value.trim();pushState();load();
  },320));
  load();
  return host;
}

Pages.competitionDetail={
  title:'مسابقه',icon:'i-trophy',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    API.get('/panel/competitions/'+id).then(function(c){
      var rades=c.rades||[];
      var staff=isStaff(ctx);
      var actions='<div class="row gap2 wrap">'
        +'<a class="btn btn-g btn-sm" href="/competitions">'+UI.ic('i-cr')+' بازگشت</a>'
        +(staff?'<button class="btn btn-g btn-sm" id="edit">'+UI.ic('i-edit')+' ویرایش</button>':'')
        +(staff?'<button class="btn btn-g btn-sm" id="results">'+UI.ic('i-list')+' ثبت نتایج</button>':'')
        +(staff&&Number(c.registration_paused)?'<button class="btn btn-s btn-sm" id="resumeC">ادامه ثبت‌نام</button>':'')
        +(staff&&!Number(c.registration_paused)&&c.status==='open'?'<button class="btn btn-g btn-sm" id="pauseC">توقف ثبت‌نام</button>':'')
        +(staff&&c.status!=='cancelled'?'<button class="btn btn-d btn-sm" id="cancelC">لغو مسابقه</button>':'')
        +(staff?'<button class="btn btn-g btn-sm" id="cloneC">'+UI.ic('i-file')+' کلون</button>':'')
        +'</div>';
      root.innerHTML=pageHead(c.title,(c.venue_name||'')+' · '+UI.esc(c.city||'')+' · '+UI.badge(c.status),actions)
        +'<div id="banHost"></div>'
        +'<div class="grid main31 mt4"><div>'
        +'<div id="radeSec">'+section('رده‌های این مسابقه',radeTable(rades),
          staff?'<button class="btn btn-s btn-sm" id="addR">'+UI.ic('i-plus')+' افزودن رده</button>':'')+'</div>'
        +section('توضیحات','<div class="rich i13">'+UI.richHtml(c.description)+'</div>')
        +(c.rules?section('آیین‌نامه و قوانین','<div class="rich i13">'+UI.richHtml(c.rules)+'</div>'):'')
        +'</div><div>'
        +section('اطلاعات',detail([
          ['میدان',c.venue_name||c.venue_club_id],
          ['شهر',c.city],
          ['شروع ثبت‌نام',I18N.fmtDateTime(c.start_registration_at)],
          ['پایان ثبت‌نام',I18N.fmtDateTime(c.end_registration_at)],
          ['شروع مسابقه',I18N.fmtDateTime(c.start_at)],
          ['پایان',c.end_at?I18N.fmtDateTime(c.end_at):'—'],
          ['ثبت‌نام',Number(c.registration_paused)?'<span class="badge b-warn">متوقف</span>':'<span class="badge b-ok">باز</span>']
        ]))
        +(staff?'<div class="panel pad mt4"><b class="i13">تغییر وضعیت مسابقه</b>'
          +'<div class="row gap2 mt3"><select class="sel" id="cStatus">'+COMP_STATUS.map(function(s){return '<option value="'+s.v+'"'+(c.status===s.v?' selected':'')+'>'+s.l+'</option>';}).join('')+'</select>'
          +'<button class="btn btn-p btn-sm" id="applyStatus">اعمال</button></div>'
          +'<p class="hint">لغو مسابقه (و بازگشت پرداخت‌ها) از دکمهٔ «لغو مسابقه» بالای صفحه انجام می‌شود.</p></div>':'')
        +'<a class="btn btn-p btn-w mt4" href="/rider/competitions/'+id+'" style="display:flex">مشاهده به‌عنوان سوارکار</a>'
        +publicUrlPanel(c)
        +'</div></div>'
        /* Full width, and BELOW the rades / info / settings columns: the signups
           table is the longest thing on the page and pushing the short settings
           block under it made the actual competition data the last thing read. */
        +'<div id="suHost" class="mt4"></div>';

      function refresh(){Pages.competitionDetail.render(root,ctx,id);}
      initPublicUrlPanel();
      /* Signups live on this page now (grouped per rade, toggleable to a flat
         list) instead of behind a link to /signups. Staff-only: the rider view
         of a competition is Pages.riderSignup. */
      var suHost=root.querySelector('#suHost');
      var banHost=root.querySelector('#banHost');
      if(banHost){banHost.appendChild(compBannerBlock(c,staff,refresh));}
      if(suHost&&staff){
        suHost.appendChild(compSignupsSection(c,ctx,function(){
          /* An approve/reject/position changes the per-rade counts, so refresh
             the rades table in place rather than re-rendering the whole page
             (which would throw away the chosen view/filters). */
          API.get('/panel/competitions/'+id).then(function(latest){
            rades=latest.rades||[];
            var host2=root.querySelector('#radeSec');
            if(host2){host2.innerHTML=section('رده‌های این مسابقه',radeTable(rades),
              staff?'<button class="btn btn-s btn-sm" id="addR">'+UI.ic('i-plus')+' افزودن رده</button>':'');
              var re=host2.querySelector('#addR');
              if(re){re.addEventListener('click',function(){addRadeDlg();});}
              host2.querySelectorAll('[data-cr-del]').forEach(function(b){
                b.addEventListener('click',function(){UI.confirm('رده از مسابقه حذف شود؟',function(){API.del('/panel/competitions/'+id+'/rades/'+b.dataset.crDel).then(refresh);});});
              });
            }
          });
        }));
      }
      var e=document.getElementById('edit');
      if(e){e.addEventListener('click',function(){openCompDialog(c,ctx,refresh);});}
      var res=document.getElementById('results');
      if(res){res.addEventListener('click',function(){App.go('/competitions/'+id+'/results');});}
      var applySt=document.getElementById('applyStatus');
      if(applySt){applySt.addEventListener('click',function(){
        var to=document.getElementById('cStatus').value;
        if(to===c.status){UI.toast('وضعیت تغییری نکرده است');return;}
        if(to==='cancelled'){UI.toast('برای لغو، از دکمهٔ «لغو مسابقه» استفاده کنید','w');return;}
        API.put('/panel/competitions/'+id,{status:to})
          .then(function(){UI.toast('وضعیت به‌روزرسانی شد');refresh();})
          .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
      });}
      var pc=document.getElementById('pauseC');
      if(pc){pc.addEventListener('click',function(){API.post('/panel/competitions/'+id+'/pause',{}).then(function(){UI.toast('ثبت‌نام متوقف شد');refresh();});});}
      var rc=document.getElementById('resumeC');
      if(rc){rc.addEventListener('click',function(){API.post('/panel/competitions/'+id+'/resume',{}).then(function(){UI.toast('ثبت‌نام ادامه یافت');refresh();});});}
      var xc=document.getElementById('cancelC');
      if(xc){xc.addEventListener('click',function(){UI.confirm('مسابقه لغو شود؟ پرداخت‌های انجام‌شده به «در انتظار استرداد» می‌روند.',function(){API.post('/panel/competitions/'+id+'/cancel',{}).then(refresh);});});}
      var cl=document.getElementById('cloneC');
      if(cl){cl.addEventListener('click',function(){API.post('/panel/competitions/'+id+'/clone',{}).then(function(d){UI.toast('کلون ساخته شد');if(d&&d.id){App.go('/competitions/'+d.id);}});});}
      var ar=document.getElementById('addR');
      if(ar){ar.addEventListener('click',function(){addRadeDlg();});}

      function addRadeDlg(){
        Promise.all([API.get('/panel/rades'),API.get('/panel/payments')]).then(function(rs){
          var radeOpts=UI.rows(rs[0]).map(function(r){return {v:r.id,l:r.name};});
          var payOpts=UI.rows(rs[1]).map(function(p){return {v:p.id,l:p.name+' ('+UI.money(p.amount_irt)+')'};});
          if(!radeOpts.length){UI.toast('ابتدا یک رده بسازید','w');return;}
          if(!payOpts.length){UI.toast('ابتدا یک الگوی پرداخت بسازید','w');return;}
          var body=UI.el('<div class="pad-s"><form id="fAR" class="frow">'
            +UI.pickField({name:'rade_id',label:'رده',value:'',options:[{v:'',l:'— انتخاب رده —'}].concat(radeOpts),placeholder:'جستجوی رده…',req:true})
            +UI.pickField({name:'payment_id',label:'الگوی پرداخت',value:'',options:[{v:'',l:'— انتخاب الگو —'}].concat(payOpts),placeholder:'جستجوی الگوی پرداخت…',req:true})
            +UI.inp('capacity','ظرفیت','',null,{ph:'بدون محدودیت اگر خالی',attrs:' inputmode="numeric"'})
            +UI.sel('signup_mode','حالت ثبت‌نام','per_rade',[{v:'per_rade',l:'به ازای هر رده'},{v:'per_competition',l:'به ازای کل مسابقه'}])
            +'<label class="full row gap2 i13"><input type="checkbox" name="auto_confirm"/> تأیید خودکار پس از پرداخت</label>'
            +'<label class="full row gap2 i13"><input type="checkbox" name="had_barrage"/> این رده باراژ دارد</label>'
            +'</form><div class="row jend mt3"><button class="btn btn-p" data-save>افزودن</button></div></div>');
          UI.modal('افزودن رده به مسابقه',body);
          UI.initPicks(body,{});
          body.querySelector('[data-save]').addEventListener('click',function(){
            var f=body.querySelector('#fAR');
            var v=UI.formValues(f);
            if(v.capacity===''){delete v.capacity;}else{v.capacity=+v.capacity;}
            API.post('/panel/competitions/'+id+'/rades',v).then(function(){UI.closeDialog();UI.toast('رده افزوده شد');refresh();}).catch(function(err){UI.showErrors(f,err.errors);});
          });
        });
      }
      function radeTable(rows){
        return UI.table([
          {label:'رده',render:function(r){return UI.esc(r.rade_name||r.name||('رده #'+UI.faNum(r.rade_id)));}},
          {label:'هزینه',render:function(r){return UI.money(r.amount_irt||0);}},
          {label:'ظرفیت',render:function(r){return r.capacity?UI.faNum(r.capacity):'∞';}},
          {label:'ثبت‌نام',render:function(r){return UI.faNum(r.signup_count||0);}},
          {label:'تأیید خودکار',render:function(r){return Number(r.auto_confirm)?'<span class="badge b-ok">بله</span>':'<span class="badge b-mut">خیر</span>';}},
          {label:'باراژ',render:function(r){return Number(r.had_barrage)?'<span class="badge b-warn">دارد</span>':'—';}},
          {label:'عملیات',render:function(r){return staff?'<div class="row gap1"><button class="btn-i" data-cr-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button><button class="btn-i" data-cr-bar="'+r.id+'" title="باراژ">'+UI.ic('i-flag')+'</button><button class="btn-i" data-cr-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button></div>':'';}}
        ],rows,{emptyText:'رده‌ای افزوده نشده'});
      }
      root.querySelectorAll('[data-cr-del]').forEach(function(b){
        b.addEventListener('click',function(){UI.confirm('رده از مسابقه حذف شود؟',function(){API.del('/panel/competitions/'+id+'/rades/'+b.dataset.crDel).then(refresh);});});
      });
      root.querySelectorAll('[data-cr-bar]').forEach(function(b){
        b.addEventListener('click',function(){
          var r=rades.filter(function(x){return String(x.id)===String(b.dataset.crBar);})[0];
          if(!r){return;}
          var body=UI.el('<div class="pad-s"><label class="lbl">یادداشت باراژ</label><input class="inp" id="bnote" value="'+UI.esc(r.barrage_notes||'')+'"/>'
            +'<label class="row gap2 mt3 i13"><input type="checkbox" id="bhad" '+(Number(r.had_barrage)?'checked':'')+'/> این رده باراژ دارد</label>'
            +'<div class="row jend mt3"><button class="btn btn-p" data-save>ذخیره</button></div></div>');
          UI.modal('باراژ — '+UI.esc(r.rade_name||''),body);
          body.querySelector('[data-save]').addEventListener('click',function(){
            API.post('/panel/competitions/'+id+'/rades/'+r.id+'/barrage',{had_barrage:body.querySelector('#bhad').checked,barrage_notes:body.querySelector('#bnote').value})
              .then(function(){UI.closeDialog();UI.toast('ذخیره شد');refresh();})
              .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
          });
        });
      });
      root.querySelectorAll('[data-cr-edit]').forEach(function(b){
        b.addEventListener('click',function(){
          var r=rades.filter(function(x){return String(x.id)===String(b.dataset.crEdit);})[0];
          if(!r){return;}
          var body=UI.el('<div class="pad-s"><form id="fCR" class="frow">'
            +UI.inp('capacity','ظرفیت',r.capacity,null,{attrs:' inputmode="numeric"'})
            +UI.inp('barrage_notes','یادداشت باراژ',r.barrage_notes,{full:true})
            +'<label class="row gap2 i13"><input type="checkbox" name="had_barrage" '+(Number(r.had_barrage)?'checked':'')+'/> این رده باراژ دارد</label>'
            +'</form><div class="row jend mt3"><button class="btn btn-p" data-save>ذخیره</button></div></div>');
          UI.modal('ویرایش رده مسابقه',body);
          body.querySelector('[data-save]').addEventListener('click',function(){
            var v=UI.formValues(body.querySelector('#fCR'));
            if(v.capacity===''){delete v.capacity;}else{v.capacity=+v.capacity;}
            API.put('/panel/competitions/'+id+'/rades/'+b.dataset.crEdit,v).then(function(){UI.closeDialog();UI.toast('ذخیره شد');refresh();});
          });
        });
      });
    });
  }
};

/* ---------------- rider browse + signup ---------------- */
Pages.riderCompetitions={
  title:'مسابقات باز',icon:'i-trophy',sec:'عملیات',
  render:function(root){
    root.innerHTML=pageHead('مسابقات','مسابقاتی که می‌توانید در آن‌ها ثبت‌نام کنید.')+'<div id="tbl"><div class="spin"></div></div>';
    API.get('/panel/rider/competitions').then(function(d){
      var rows=UI.rows(d);
      root.querySelector('#tbl').innerHTML=rows.map(function(c){
        return '<a class="panel pad lift mb2" style="display:block" href="/rider/competitions/'+c.id+'">'
          +'<div class="row jb gap3 wrap"><div><div class="bb" style="font-size:16px">'+UI.esc(c.title)+'</div>'
          +'<div class="i12 mut mt1">'+UI.esc(c.city||'')+' · '+I18N.fmtDateLong(c.start_at)+'</div></div>'
          +'<div class="row gap2">'+UI.badge(c.status)+UI.ic('i-cl')+'</div></div></a>';
      }).join('')||'<div class="panel"><div class="empty">مسابقه بازی وجود ندارد</div></div>';
    });
  }
};

Pages.riderSignup={
  title:'ثبت‌نام در مسابقه',icon:'i-trophy',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    API.get('/panel/rider/competitions/'+id).then(function(d){
      var comp=d.competition;
      var rades=(comp.rades||[]);
      var horses=d.horses||[];
      var clubs=d.clubs||[];
      if(!horses.length){
        root.innerHTML=pageHead(comp.title,'','<a class="btn btn-g btn-sm" href="/rider/competitions">بازگشت</a>')
          +'<div class="panel"><div class="empty">'+UI.ic('i-horse')+'<div>برای ثبت‌نام ابتدا باید اسبی ثبت کنید.</div><a class="btn btn-p mt3" href="/horses">افزودن اسب</a></div></div>';
        return;
      }
      root.innerHTML=pageHead(comp.title,(comp.venue_name||'')+' · '+I18N.fmtDateLong(comp.start_at),'<a class="btn btn-g btn-sm" href="/rider/competitions">بازگشت</a>')
        +'<div class="grid main31 mt4"><div>'
        +section('رده‌ها و هزینه‌ها',rades.map(function(r){
          /* The rade radios live in the left column, OUTSIDE <form id="fS">. Give them
           form="fS" so they still belong to the form (and to Enter-key submit),
           and always read the checked radio through `rade()` below — querying
           the form element only ever found nothing, so the page reported "یک
           رده انتخاب کنید" forever and never reached payment. */
          return '<div class="rl"><label class="row gap2 g1" style="cursor:pointer"><input type="radio" name="comp_rade_id" form="fS" value="'+r.id+'" '+(rades.length===1?'checked':'')+'/>'
            +'<span><b class="i13">'+UI.esc(r.rade_name||r.name)+'</b><span class="i11 mut"> — ظرفیت '+(r.capacity?UI.faNum(r.capacity):'∞')+'</span></span></label>'
            +'<b>'+UI.money(r.amount_irt)+'</b></div>';
        }).join('')||'<div class="empty">رده‌ای برای این مسابقه تعریف نشده</div>')
        +'<div class="mt4"><a class="btn btn-g btn-sm" href="/c/'+UI.esc(comp.slug||'')+'" target="_blank" rel="noopener">'+UI.ic('i-share')+' صفحه عمومی مسابقه</a></div>'
        +(comp.announcement?'<div class="panel soft pad-s mt3" style="border-inline-start:3px solid var(--gold)">'
          +'<b class="i13">'+UI.ic('i-flag')+' اطلاعیه مسابقه</b>'
          +'<div class="rich i13 mt2">'+UI.richHtml(comp.announcement)+'</div></div>':'')
        +((comp.description||comp.rules)?'<div class="mt4">'
          +(comp.description?'<b class="i13">درباره مسابقه</b><div class="rich i13 mt2">'+UI.richHtml(comp.description)+'</div>':'')
          +(comp.rules?'<b class="i13 mt3" style="display:block">آیین‌نامه</b><div class="rich i13 mt2">'+UI.richHtml(comp.rules)+'</div>':'')
          +'</div>':'')
        +'</div><div class="panel pad">'
        +'<form id="fS">'
        +UI.pickField({name:'horse_id',label:'اسب',value:'',options:[{v:'',l:'— انتخاب اسب —'}].concat(horses.map(function(h){return {v:h.id,l:h.name+(h.microchip_number?' — '+h.microchip_number:'')};})),placeholder:'جستجوی اسب…',req:true})
        +UI.pickField({name:'affiliation_club_id',label:'باشگاه نمایندگی',value:'',options:[{v:'',l:'— انتخاب باشگاه —'}].concat(clubs.map(function(cl){return {v:cl.id,l:cl.name};})),placeholder:'جستجوی باشگاه…',req:true})
        +'<div class="panel soft pad-s mt3 i12" id="sum">مبلغ پس از انتخاب رده محاسبه می‌شود.</div>'
          +'<p class="hint" id="radeErr" style="color:var(--danger)"></p>'
        +'<button class="btn btn-p btn-w mt3" type="submit">'+UI.ic('i-wallet')+' پرداخت و ثبت‌نام</button>'
        +'<p class="hint">پرداخت از طریق درگاه زرین‌پال انجام می‌شود. پس از پرداخت موفق، ثبت‌نام شما تأیید می‌شود.</p>'
        +'</form></div></div>';
      UI.initPicks(root,{});
      var f=document.getElementById('fS');
      function rade(){
        /* Walk the radio group explicitly rather than `f.elements` (the radios
           live outside the form element) or an `input[...]:checked`
           pseudo-selector: neither is dependable across the browser, the
           print layout and the test DOM. */
        var scope=root;
        var list=scope.querySelectorAll('input[name=comp_rade_id]');
        for(var i=0;i<list.length;i++){
          if(list[i].checked){
            var v=list[i].value;
            return rades.filter(function(x){return String(x.id)===String(v);})[0]||null;
          }
        }
        return null;
      }
      function updateSum(){
        var r=rade();
        var err=document.getElementById('radeErr');
        var sum=document.getElementById('sum');
        if(sum){
          sum.innerHTML=r?('مبلغ ثبت‌نام برای ردهٔ <b>'+UI.esc(r.rade_name||r.name||'')+'</b>: <b>'+UI.money(r.amount_irt)+'</b>'):'مبلغ پس از انتخاب رده محاسبه می‌شود.';
        }
        if(err){err.textContent=r?'':'یک رده را انتخاب کنید تا بتوانید به پرداخت بروید.';}
        Array.prototype.forEach.call(root.querySelectorAll('input[name=comp_rade_id]'),function(i){
          i.closest&&i.closest('.rl')&&i.closest('.rl').classList.toggle('sel',!!i.checked);
        });
        var go=document.querySelector('#fS button[type=submit]');
        if(go){go.disabled=!r;}
      }
      root.querySelectorAll('input[name=comp_rade_id]').forEach(function(i){i.addEventListener('change',updateSum);});
      updateSum();
      f.addEventListener('submit',function(e){
        e.preventDefault();
        var v=UI.formValues(f);
        var sel=rade();
        if(!sel){UI.toast('یک رده انتخاب کنید','w');return;}
        v.competition_rade_id=+sel.id;
        if(!v.horse_id){UI.toast('اسب خود را انتخاب کنید','w');return;}
        if(!v.affiliation_club_id){UI.toast('باشگاه نمایندگی را انتخاب کنید','w');return;}
        v.horse_id=+v.horse_id;
        v.affiliation_club_id=+v.affiliation_club_id;
        var btn=f.querySelector('button[type=submit]');btn.disabled=true;
        API.post('/panel/rider/competitions/'+id+'/signup',v).then(function(res){
          if(res.redirect&&/^https?:/i.test(res.redirect)){
            UI.toast('در حال انتقال به درگاه پرداخت…');
            setTimeout(function(){window.location.href=res.redirect;},600);
          }else{
            UI.modal('ثبت‌نام انجام شد','<div class="pad-s"><p class="i13">ثبت‌نام شما با موفقیت ثبت شد.</p><a class="btn btn-p btn-w mt3" href="/signups" data-close>مشاهده ثبت‌نام‌ها</a></div>');
            var b=document.querySelector('[data-close]');
            if(b){b.addEventListener('click',function(e2){e2.preventDefault();UI.closeDialog();App.go('/signups');});}
          }
        }).catch(function(err){
          btn.disabled=false;
          var msg=(err.errors&&err.errors[0].message)||'خطا';
          if(err.status===403){UI.toast('حساب شما هنوز تأیید نشده است','w');}
          else{UI.toast(msg,'e');}
        });
      });
    });
  }
};

/* ---------------- staff signups ---------------- */
Pages.signups={
  title:'ثبت‌نام‌ها',icon:'i-list',sec:'عملیات',roles:['admin','manager'],
  render:function(root,ctx){
    var filters=App.seed({status:'',search:'',page:1,competition_id:'',rade_id:''});
    if(filters.competition_id){filters.competition_id=+filters.competition_id;}
    root.innerHTML=pageHead('ثبت‌نام‌ها','همه ثبت‌نام‌های مسابقات — تأیید، رد و تعیین مقام.',
      '<div class="row gap1" id="bulkBar" style="display:none"><button class="btn btn-p btn-sm" id="bulkOk">تأیید گروهی</button><button class="btn btn-d btn-sm" id="bulkNo">رد گروهی</button></div>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div id="cchip" class="row gap1 wrap mb3"></div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'signups',
      searchLabel:'جستجوی سوارکار یا اسب…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'}].concat(SIGNUP_STATUS)},
        {key:'competition_id',label:'مسابقه',lazy:UI.spkFromApi('/panel/competitions','title',{per_page:300})},
        {key:'rade_id',label:'رده',lazy:UI.spkFromApi('/panel/rades','name')}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(8)+'</div>'));
    if(filters.competition_id){
      var chip=UI.el('<span class="chip on">مسابقه #'+UI.faNum(filters.competition_id)+' <b style="cursor:pointer">×</b></span>');
      chip.querySelector('b').addEventListener('click',function(){delete filters.competition_id;filters.competition_id='';chip.remove();App.setQuery(filters);load();});
      root.querySelector('#cchip').appendChild(chip);
    }

    document.getElementById('bulkOk').addEventListener('click',function(){bulk('confirm');});
    document.getElementById('bulkNo').addEventListener('click',function(){bulk('reject');});

    function selectedIds(){
      var tbl=root.querySelector('#tbl');
      return tbl?Array.prototype.map.call(tbl.querySelectorAll('.selrow:checked'),function(c){return +c.dataset.id;}):[];
    }
    function bulk(action){
      var ids=selectedIds();
      if(!ids.length){return;}
      API.post('/panel/signups/bulk',{ids:ids,action:action}).then(function(d){UI.toast(UI.faNum(d.processed||0)+' مورد انجام شد');load();});
    }
    function load(){
      API.get('/panel/signups',apiParams(filters)).then(function(d){
        var rows=UI.rows(d);
        fbar.setCount(UI.faNum(d.total||rows.length)+' ثبت‌نام');
        root.querySelector('#tbl').innerHTML=UI.table(SCOLS,rows,{
          pager:UI.pagerHtml(d.total||rows.length,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی ثبت‌نامی نیست':'ثبت‌نامی نیست',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};filters.page=1;App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        var tbl=root.querySelector('#tbl');
        tbl.querySelectorAll('[data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
        var selAll=tbl.querySelector('#selAll');
        if(selAll){selAll.addEventListener('change',function(){tbl.querySelectorAll('.selrow').forEach(function(c){c.checked=selAll.checked;});updateBulk();});}
        tbl.addEventListener('change',updateBulk);
        function updateBulk(){document.getElementById('bulkBar').style.display=tbl.querySelectorAll('.selrow:checked').length?'':'none';}
        tbl.querySelectorAll('[data-ok]').forEach(function(b){b.addEventListener('click',function(){API.post('/panel/signups/'+b.dataset.ok+'/confirm',{}).then(load);});});
        tbl.querySelectorAll('[data-no]').forEach(function(b){b.addEventListener('click',function(){UI.confirm('ثبت‌نام رد شود؟',function(){API.post('/panel/signups/'+b.dataset.no+'/reject',{reason:'manual'}).then(load);});});});
        tbl.querySelectorAll('[data-pos]').forEach(function(b){b.addEventListener('click',function(){
          var body=UI.el('<div class="pad-s"><label class="lbl">مقام (۱ تا …)</label><input class="inp" id="pos" type="number" min="1" inputmode="numeric"/><label class="row gap2 mt2 i13"><input type="checkbox" id="win"/> برنده</label><div class="row jend mt3"><button class="btn btn-p" data-save>ثبت</button></div></div>');
          UI.modal('تعیین مقام',body);
          body.querySelector('[data-save]').addEventListener('click',function(){
            API.post('/panel/signups/'+b.dataset.pos+'/position',{position:body.querySelector('#pos').value,is_winner:body.querySelector('#win').checked})
              .then(function(){UI.closeDialog();load();});
          });
        });});
      });
    }
    load();
  }
};

/* rider's own signups */
Pages.mySignups={
  title:'ثبت‌نام‌های من',icon:'i-list',sec:'عملیات',
  render:function(root){
    root.innerHTML=pageHead('ثبت‌نام‌های من','وضعیت ثبت‌نام و پرداخت‌های شما.')+'<div id="tbl"><div class="spin"></div></div>';
    API.get('/panel/rider/signups').then(function(d){
      var rows=UI.rows(d);
      root.querySelector('#tbl').innerHTML=UI.table([
        {label:'مسابقه',key:'competition_title',wrap:true},
        {label:'رده',key:'rade_name'},
        {label:'اسب',key:'horse_name'},
        {label:'مبلغ',render:function(r){return UI.money(r.payment_amount_irt_snapshot!=null?r.payment_amount_irt_snapshot:r.amount_irt);}},
        {label:'مقام',render:function(r){return r.position!=null?UI.faNum(r.position):'—';}},
        {label:'وضعیت',render:function(r){return UI.badge(r.status);}},
        {label:'عملیات',render:function(r){return r.status==='pending_payment'&&r.order_id?'<button class="btn btn-p btn-sm" data-pay="'+r.order_id+'">پرداخت</button>':'';}}
      ],rows,{emptyText:'ثبت‌نامی ندارید'});
      root.querySelectorAll('[data-pay]').forEach(function(b){
        b.addEventListener('click',function(){
          b.disabled=true;
          UI.toast('در حال انتقال به درگاه…');
          API.post('/panel/payment-orders/'+b.dataset.pay+'/pay',{}).then(function(d){
            if(d&&d.redirect){window.location.href=d.redirect;}
            else{UI.toast('آدرس درگاه دریافت نشد','e');b.disabled=false;}
          }).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');b.disabled=false;});
        });
      });
    });
  }
};

/* ---------------- results ---------------- */
Pages.results={
  title:'ثبت نتایج',icon:'i-list',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML=pageHead('نتایج مسابقه','ثبت مقام‌ها، تأیید و انتشار.')+'<div id="tbl"><div class="spin"></div></div>';
    API.get('/panel/competitions/'+id+'/results').then(function(grid){
      var rades=grid.rades||[];
      var comp=grid.competition||{};
      var html='<div class="row gap2 wrap mb3"><b class="i13">'+UI.esc(comp.title||'')+'</b>'+UI.badge(comp.status)+'<span class="g1"></span><a class="btn btn-g btn-sm" href="/competitions/'+id+'">بازگشت به مسابقه</a></div>'
        +rades.map(function(rd){
        return section(rd.rade_name||('رده #'+UI.faNum(rd.id)),
          UI.table([
            {label:'سوارکار',render:function(s){return UI.esc(s.rider_name||'—');}},
            {label:'اسب',key:'horse_name'},
            {label:'باشگاه',key:'club_name'},
            {label:'مقام',render:function(s){return '<input class="inp" style="max-width:80px" data-pos="'+s.id+'" type="number" min="1" inputmode="numeric" value="'+(s.position||'')+'"/>';}},
            {label:'برنده',render:function(s){return '<input type="checkbox" data-win="'+s.id+'" '+(Number(s.is_winner)?'checked':'')+'/>';}},
            {label:'یادداشت',render:function(s){return '<input class="inp" data-note="'+s.id+'" value="'+UI.esc(s.result_notes||'')+'"/>';}}
          ],rd.signups||[],{emptyText:'ثبت‌نامی در این رده نیست'}));
      }).join('')||'';
      if(!rades.length){html+='<div class="panel"><div class="empty">داده‌ای نیست — ابتدا رده و ثبت‌نام تأییدشده اضافه کنید</div></div>';}
      html+='<div class="row gap2 jend mt4 wrap"><button class="btn btn-p" id="saveD">ذخیره پیش‌نویس</button>'
        +'<button class="btn btn-g" id="confirmB">تأیید نتایج</button>'
        +'<button class="btn btn-s" id="pubB">'+UI.ic('i-send')+' انتشار نتایج</button>'
        +(ctx.user.role==='admin'?'<button class="btn btn-d" id="reopenB">بازگشایی نتایج</button>':'')+'</div>';
      root.querySelector('#tbl').innerHTML=html;
      function collect(){
        return {rades:rades.map(function(rd){
          return {comp_rade_id:rd.id,signups:(rd.signups||[]).map(function(s){
            var pos=root.querySelector('[data-pos="'+s.id+'"]');
            var win=root.querySelector('[data-win="'+s.id+'"]');
            var note=root.querySelector('[data-note="'+s.id+'"]');
            return {id:s.id,position:pos?pos.value:'',is_winner:win&&win.checked?1:0,result_notes:note?note.value:''};
          })};
        })};
      }
      document.getElementById('saveD').addEventListener('click',function(){API.post('/panel/competitions/'+id+'/results',collect()).then(function(){UI.toast('پیش‌نویس ذخیره شد');});});
      document.getElementById('confirmB').addEventListener('click',function(){
        API.post('/panel/competitions/'+id+'/results',collect())
          .then(function(){return API.post('/panel/competitions/'+id+'/results/confirm',{});})
          .then(function(){UI.toast('نتایج تأیید شد');});
      });
      document.getElementById('pubB').addEventListener('click',function(){
        UI.confirm('نتایج برای همه سوارکاران اعلان می‌شود. منتشر شود؟',function(){
          API.post('/panel/competitions/'+id+'/results/publish',{}).then(function(){UI.toast('نتایج منتشر شد');});
        });
      });
      var reopen=document.getElementById('reopenB');
      if(reopen){reopen.addEventListener('click',function(){
        UI.confirm('نتایج منتشرشده برای ویرایش بازگشایی شود؟',function(){
          API.post('/panel/competitions/'+id+'/results/reopen',{}).then(function(){UI.toast('نتایج بازگشایی شد');});
        });
      });}
    });
  }
};

/* ---------------- standings ---------------- */
/* ---------------- competition calendar ---------------- */
/*
   Month grid of competitions. Works in the active culture: Jalali months for
   fa-IR, Gregorian for en-US. Each day cell lists the competitions that day,
   with a capacity bar so a manager can see fill-rate at a glance.
*/
Pages.calendar={
  title:'تقویم مسابقات',icon:'i-calendar',sec:'عملیات',
  render:function(root,ctx){
    var filters=App.seed({status:'',venue_club_id:'',view:'month'});
    var cursor={y:0,m:0};
    var today=I18N.local(new Date().toISOString());
    if(today){
      var p=I18N.fromIso(today.toISOString());
      cursor.y=p.y;
      cursor.m=p.m;
    }
    root.innerHTML=pageHead('تقویم مسابقات','نمای ماهانه مسابقات، مهلت ثبت‌نام و ظرفیت رده‌ها.',
      '<div class="row gap2 wrap">'
      +'<button class="btn btn-g btn-sm" id="cPrev">'+UI.ic('i-cl')+' ماه قبل</button>'
      +'<button class="btn btn-g btn-sm" id="cToday">امروز</button>'
      +'<button class="btn btn-g btn-sm" id="cNext">ماه بعد '+UI.ic('i-cr')+'</button>'
      +'<a class="btn btn-g btn-sm" href="/competitions">'+UI.ic('i-list')+' فهرست مسابقات</a>'
      +'<a class="btn btn-g btn-sm" href="/calendar?view=list">'+UI.ic('i-list')+' نمای فهرستی</a>'
      +'</div>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div class="panel pad" id="calPanel"><div class="spin"></div></div>'
      +'<div class="panel pad mt4" id="calList" hidden></div>';

    var fbar=UI.filterBar({
      filters:filters,
      views:'calendar',
      search:false,
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'},{v:'draft',l:'پیش‌نویس'},{v:'open',l:'باز'},{v:'closed',l:'بسته'},{v:'running',l:'در حال اجرا'},{v:'finished',l:'پایان‌یافته'},{v:'cancelled',l:'لغوشده'}]},
        {key:'venue_club_id',label:'میدان',lazy:UI.spkFromApi('/panel/clubs','name')}
      ],
      onChange:function(){load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);

    function shiftMonth(delta){
      var m=cursor.m+delta;
      var y=cursor.y;
      var max=I18N.rtl?12:12;
      while(m>max){m-=max;y+=1;}
      while(m<1){m+=max;y-=1;}
      cursor.y=y;
      cursor.m=m;
      load();
    }
    root.querySelector('#cPrev').addEventListener('click',function(){shiftMonth(-1);});
    root.querySelector('#cNext').addEventListener('click',function(){shiftMonth(1);});
    root.querySelector('#cToday').addEventListener('click',function(){
      var p=I18N.fromIso(new Date().toISOString());
      cursor.y=p.y;
      cursor.m=p.m;
      load();
    });

    function monthWindow(y,m){
      var first=I18N.toIso(y,m,1,0,0);
      var len=I18N.monthLength(y,m);
      var last=I18N.toIso(y,m,len,23,59);
      return {from:first.slice(0,10),to:last.slice(0,10),label:I18N.num(y)+' — '+I18N.monthNames()[m-1]};
    }
    function toggleView(){
      var isList=filters.view==='list';
      root.querySelector('#calPanel').hidden=isList;
      root.querySelector('#calList').hidden=!isList;
    }
    function load(){
      var win=monthWindow(cursor.y,cursor.m);
      fbar.setCount('');
      API.get('/panel/competitions/calendar',{from:win.from,to:win.to,status:filters.status,venue_club_id:filters.venue_club_id})
        .then(function(d){
          var items=d.items||[];
          if(filters.view==='list'){renderList(items,win.label);}else{renderMonth(items,win);}
          toggleView();
        })
        .catch(function(){root.querySelector('#calPanel').innerHTML='<div class="empty">بارگذاری تقویم ناموفق بود</div>';});
    }

    function dayKey(iso){return I18N.local(iso).toISOString().slice(0,10);}
    function renderMonth(items,win){
      var byDay={};
      items.forEach(function(it){
        var start=dayKey(it.start_at);
        (byDay[start]=byDay[start]||[]).push(it);
      });
      var first=I18N.toIso(cursor.y,cursor.m,1,0,0);
      var leadDay=(new Date(first).getUTCDay()+1)%7; /* Saturday-first grid */
      var len=I18N.monthLength(cursor.y,cursor.m);
      var cells=[];
      for(var b=0;b<leadDay;b++){cells.push('<div class="cal-cell out"></div>');}
      var todayKey=I18N.local(new Date().toISOString()).toISOString().slice(0,10);
      for(var d=1;d<=len;d++){
        var key=I18N.toIso(cursor.y,cursor.m,d,0,0).slice(0,10);
        var list=byDay[key]||[];
        cells.push('<div class="cal-cell'+(key===todayKey?' today':'')+(list.length?' has':'')+'">'
          +'<div class="cal-n">'+UI.faNum(d)+'</div>'
          +list.map(calItem).join('')
          +'</div>');
      }
      var heads=(I18N.rtl?['ش','ی','د','س','چ','پ','ج']:['S','M','T','W','T','F','S']);
      root.querySelector('#calPanel').innerHTML=
        '<div class="row jb mb3"><b class="i15" style="font-size:16px">'+UI.esc(win.label)+'</b>'
        +'<span class="i12 mut">'+UI.faNum(items.length)+' مسابقه در این ماه</span></div>'
        +'<div class="cal-head">'+heads.map(function(h){return '<div>'+h+'</div>';}).join('')+'</div>'
        +'<div class="cal-grid">'+cells.join('')+'</div>';
    }
    function calItem(it){
      var pct=it.total_capacity>0?Math.min(100,Math.round((it.signup_count/it.total_capacity)*100)):null;
      return '<a class="cal-ev '+it.status+'" href="/competitions/'+it.id+'" title="'+UI.esc(it.title)+'">'
        +'<span class="trunc">'+UI.esc(it.title)+'</span>'
        +(pct!==null?'<span class="cal-ev-bar"><i style="width:'+pct+'%"></i></span>':'')
        +'</a>';
    }
    function renderList(items,label){
      var rows=items.slice().sort(function(a,b){return String(a.start_at).localeCompare(String(b.start_at));});
      root.querySelector('#calList').innerHTML='<div class="row jb mb3"><b style="font-size:16px">'+UI.esc(label)+'</b>'
        +'<span class="i12 mut">'+UI.faNum(rows.length)+' مسابقه</span></div>'
        +UI.table([
          {label:'مسابقه',sortKey:'title',sortValue:function(r){return r.title;},render:function(r){return '<div class="b i13">'+UI.esc(r.title)+'</div>'+(r.venue_name?'<div class="i11 mut">'+UI.esc(r.venue_name)+'</div>':'');}},
          {label:'شروع',sortKey:'start_at',sortValue:function(r){return r.start_at;},render:function(r){return I18N.fmtDateTime(r.start_at);}},
          {label:'پایان ثبت‌نام',sortKey:'end_registration_at',sortValue:function(r){return r.end_registration_at;},render:function(r){return '<div>'+I18N.fmtDate(r.end_registration_at)+'</div><div id="cd-'+r.id+'"></div>';}},
          {label:'ظرفیت',sortKey:'signup_count',sortValue:function(r){return r.signup_count;},render:function(r){return r.total_capacity>0?UI.faNum(r.signup_count)+' / '+UI.faNum(r.total_capacity):UI.faNum(r.signup_count)+' <span class="i11 mut">نامحدود</span>';}},
          {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status);}}
        ],rows,{rowHref:function(r){return '/competitions/'+r.id;},emptyText:'در این ماه مسابقه‌ای نیست'});
      UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
      bindTableNav(root,function(h){App.go(h);});
      rows.forEach(function(r){
        var slot=document.getElementById('cd-'+r.id);
        if(slot&&r.end_registration_at&&r.status==='open'){
          slot.appendChild(UI.countdown(r.end_registration_at,'مهلت'));
        }
      });
    }

    load();
  }
};

/* ---------------- cumulative rider ranking ---------------- */
Pages.ranking={
  title:'رده‌بندی کلی',icon:'i-star',sec:'تحلیل',
  render:function(root,ctx){
    var filters=App.seed({limit:100,sort:null});
    root.innerHTML=pageHead('رده‌بندی کلی سوارکاران','امتیاز تجمعی از همه مسابقات تأییدشده: اولی ۱۰، دومی ۶، سومی ۴ و سایر مقام‌ها ۲ امتیاز.',
      '<div class="row gap2 wrap">'
      +'<button class="btn btn-g btn-sm" id="rkExport">'+UI.ic('i-dl')+' خروجی CSV</button>'
      +'</div>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div id="tbl">'+UI.skeletonTable(8)+'</div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'ranking',
      search:false,
      selects:[{key:'limit',label:'تعداد نفرات',options:[{v:'25',l:'۲۵ نفر برتر'},{v:'100',l:'۱۰۰ نفر برتر'},{v:'250',l:'۲۵۰ نفر برتر'},{v:'500',l:'۵۰۰ نفر برتر'}]}],
      onChange:function(){App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    root.querySelector('#rkExport').addEventListener('click',function(){
      var btn=root.querySelector('#rkExport');
      btn.disabled=true;
      API.download('GET','/panel/standings/ranking?limit='+encodeURIComponent(filters.limit)+'&format=csv')
        .then(function(){UI.toast('خروجی ساخته شد');})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در ساخت خروجی','e');})
        .then(function(){btn.disabled=false;});
    });
    
    var RCOLS=[
      {label:'رتبه',render:function(r,i){},sortValue:function(r){return Number(r.points);},key:'rank'},
      {label:'سوارکار',sortKey:'rider_name',sortValue:function(r){return r.rider_name;},render:function(r){return '<a href="/users/'+r.rider_user_id+'" class="b i13">'+UI.esc(r.rider_name||r.username)+'</a>';}},
      {label:'امتیاز',sortKey:'points',sortValue:function(r){return Number(r.points);},render:function(r){return '<b>'+UI.faNum(r.points)+'</b>';}},
      {label:'قهرمانی',sortKey:'wins',sortValue:function(r){return Number(r.wins);},render:function(r){return r.wins?'<span class="badge b-ok">'+UI.ic('i-star')+' '+UI.faNum(r.wins)+'</span>':'—';}},
      {label:'اولی',sortKey:'firsts',sortValue:function(r){return Number(r.firsts);},render:function(r){return UI.faNum(r.firsts);}},
      {label:'دومی',sortKey:'seconds',sortValue:function(r){return Number(r.seconds);},render:function(r){return UI.faNum(r.seconds);}},
      {label:'سومی',sortKey:'thirds',sortValue:function(r){return Number(r.thirds);},render:function(r){return UI.faNum(r.thirds);}},
      {label:'شرکت‌ها',sortKey:'entries',sortValue:function(r){return Number(r.entries);},render:function(r){return UI.faNum(r.entries);}}
    ];
    function load(){
      API.get('/panel/standings/ranking',{limit:filters.limit}).then(function(d){
        var rows=UI.sortRows(UI.rows(d),RCOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' سوارکار');
        root.querySelector('#tbl').innerHTML=UI.table(RCOLS,rows,{
          sort:filters.sort,
          rowHref:function(r){return '/users/'+r.rider_user_id;},
          emptyText:'هنوز نتیجه تأییدشده‌ای ثبت نشده'
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
        bindTableNav(root,function(h){App.go(h);});
        /* Medal colouring for the podium. */
        Array.prototype.forEach.call(root.querySelectorAll('#tbl tbody tr'),function(tr,i){
          if(i===0){tr.classList.add('rk1');}
          if(i===1){tr.classList.add('rk2');}
          if(i===2){tr.classList.add('rk3');}
        });
      });
    }
    load();
  }
};

/* Standings grid. Paginated and ordered server-side (GET /panel/standings
   returns {rows,total,page,per_page}) — the confirmed-signup set is far too
   large to sort in the browser, and a client-only sort would reorder a single
   page while leaving the pager meaningless. */
var STCOLS=[
  {label:'سوارکار',sortKey:'rider_name',sortValue:function(r){return r.rider_name||'';},
    render:function(r){return r.rider_user_id?'<a class="b i13" href="/users/'+r.rider_user_id+'">'+UI.esc(r.rider_name||'—')+'</a>':UI.esc(r.rider_name||'—');}},
  {label:'اسب',sortKey:'horse_name',sortValue:function(r){return r.horse_name||'';},
    render:function(r){return r.horse_id?'<a href="/horses/'+r.horse_id+'">'+UI.esc(r.horse_name||'—')+'</a>':UI.esc(r.horse_name||'—');}},
  {label:'مسابقه',sortKey:'competition_title',sortValue:function(r){return r.competition_title||'';},wrap:true,
    render:function(r){return r.competition_id?'<a href="/competitions/'+r.competition_id+'" class="wrap">'+UI.esc(r.competition_title||'—')+'</a>':'—';}},
  {label:'رده',sortKey:'rade_name',sortValue:function(r){return r.rade_name||'';},render:function(r){return UI.esc(r.rade_name||'—');}},
  {label:'تاریخ',sortKey:'start_at',sortValue:function(r){return r.start_at||'';},render:function(r){return I18N.fmtDate(r.start_at);}},
  {label:'مقام',sortKey:'position',sortValue:function(r){return r.position==null?999:Number(r.position);},
    render:function(r){return r.position?UI.faNum(r.position):'—';}},
  {label:'برنده',sortKey:'is_winner',sortValue:function(r){return Number(r.is_winner);},
    render:function(r){return Number(r.is_winner)?'<span class="badge b-warn">'+UI.ic('i-star')+' برنده</span>':'—';}}
];

Pages.standings={
  title:'رده‌بندی',icon:'i-chart',sec:'تحلیل',
  render:function(root,ctx){
    var filters=App.seed({rider_user_id:'',horse_id:'',per_page:50,sort:null,page:1});
    if(filters.rider_user_id){filters.rider_user_id=+filters.rider_user_id;}
    if(filters.horse_id){filters.horse_id=+filters.horse_id;}
    root.innerHTML=pageHead('رده‌بندی','مقام‌های ثبت‌شده در همه مسابقات — با صفحه‌بندی و مرتب‌سازی صعودی/نزولی.',
      '<a class="btn btn-g btn-sm" href="/ranking">'+UI.ic('i-star')+' رتبه‌بندی کلی</a>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div id="tbl">'+UI.skeletonTable(8)+'</div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'standings',
      search:false,
      selects:[
        {key:'rider_user_id',label:'سوارکار',lazy:UI.spkFromApi('/panel/users',function(u){return (u.first_name||'')+' '+(u.last_name||'');},{role:'rider'})},
        {key:'horse_id',label:'اسب',lazy:UI.spkFromApi('/panel/horses','name',{})},
        {key:'per_page',label:'در هر صفحه',options:[{v:'25',l:'۲۵ ردیف'},{v:'50',l:'۵۰ ردیف'},{v:'100',l:'۱۰۰ ردیف'},{v:'200',l:'۲۰۰ ردیف'}]}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    function load(){
      API.get('/panel/standings',apiParams(filters)).then(function(d){
        var rows=UI.rows(d);
        fbar.setCount(UI.faNum(d.total||rows.length)+' نتیجه');
        root.querySelector('#tbl').innerHTML=UI.table(STCOLS,rows,{
          pager:UI.pagerHtml(d.total||rows.length,+(filters.page||1),+(filters.per_page||50)),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با این فیلترها نتیجه‌ای یافت نشد':'نتیجه‌ای ثبت نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};filters.page=1;App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
        bindTableNav(root,function(h){App.go(h);});
      });
    }
    load();
  }
};

window.Pages=Object.assign({},window.Pages,Pages);
})();
