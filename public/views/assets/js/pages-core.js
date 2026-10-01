/* ============================================================================
   Core pages: dashboard, profile, users, clubs, rades, notifications, search.
   Each page = { title, icon, render(root, ctx), mount? }.
   ============================================================================ */
(function(){
'use strict';

/* Every page gets a print control automatically: the printed sheet carries the
   federation letterhead, the document title and the filters that produced the
   rows (see UI.preparePrint). Pass opts.noPrint to opt out. */
function pageHead(title,sub,actions,opts){
  opts=opts||{};
  var print='';
  if(!opts.noPrint&&String(actions||'').indexOf('data-print')===-1){
    print=UI.printButton(opts.printLabel||'چاپ',{title:title});
  }
  return '<div class="row jb gap4 wrap mb4"><div><h2 style="font-size:21px">'+UI.esc(title)+'</h2>'+(sub?'<p class="mut i13 mt1">'+sub+'</p>':'')+'</div><div class="row gap2 wrap">'+(actions||'')+print+'</div></div>';
}
function kpiCard(label,value,icon,tone,sub){
  return '<div class="panel pad lift"><div class="kpi"><div><div class="klabel">'+UI.esc(label)+'</div><div class="kv">'+value+'</div>'+(sub?'<div class="mut i11 mt1">'+sub+'</div>':'')+'</div><span class="badge '+(tone||'b-ok')+' kic">'+UI.ic(icon)+'</span></div></div>';
}
function stat(label,value){return '<div><div class="klabel">'+UI.esc(label)+'</div><div class="b" style="font-size:17px">'+value+'</div></div>';}
function section(title,bodyHtml,actions){
  return '<div class="panel mt4"><div class="row jb gap3 pad-s" style="border-bottom:1px solid var(--border)"><b class="i13">'+UI.esc(title)+'</b><span class="row gap2">'+(actions||'')+'</span></div><div class="pad-s">'+bodyHtml+'</div></div>';
}
function detail(d){/* d = array of [k,v] */
  return '<dl class="grid-dl">'+d.map(function(x){return '<dt>'+UI.esc(x[0])+'</dt><dd>'+(x[1]==null?'—':x[1])+'</dd>';}).join('')+'</dl>';
}
/* Row click navigation; ignores clicks on interactive controls inside the row. */
function bindTableNav(root,fn){
  root.querySelectorAll('tr[data-href]').forEach(function(tr){
    tr.addEventListener('click',function(e){
      if(e.target.closest('input,button,a,select,label,textarea')){return;}
      fn(tr.dataset.href);
    });
  });
}
/* Resolve a user's avatar: uploaded image when present, else initials. */
function avatarFor(u,size){
  var s=size||34;
  if(u&&u.avatar_media_id){return '<img class="av" src="/media/'+u.avatar_media_id+'" style="width:'+s+'px;height:'+s+'px;object-fit:cover" alt=""/>';}
  return UI.av(firstLast(u),s);
}
/* Read-only Jalali date field bound to the shared datepicker. */
function pDate(name,label,val){
  return '<div><label class="lbl">'+UI.esc(label)+'</label>'
    +'<input class="inp" name="'+name+'" data-date readonly value="'+UI.esc(val?I18N.fmtDate(val):'')+'"'
    +(val?' data-iso="'+UI.esc(val)+'"':'')+'/></div>';
}

var Pages={};

/* ============================== DASHBOARD ============================== */
Pages.dashboard={
  title:'داشبورد',icon:'i-grid',sec:'عملیات',
  render:function(root,ctx){
    root.innerHTML=pageHead('داشبورد','نمای کلی سامانه بر اساس نقش شما.','<button class="btn btn-g btn-sm" data-reload>'+UI.ic('i-refresh')+' به‌روزرسانی</button>',{noPrint:true})+'<div id="dashBody"><div class="spin"></div></div>';
    root.querySelector('[data-reload]').addEventListener('click',function(){Pages.dashboard.render(root,ctx);});
    API.get('/panel/dashboard/data').then(function(d){
      var b=root.querySelector('#dashBody');
      var role=d.role;
      var k=d.kpi||{};
      if(role==='rider'){b.innerHTML=riderDash(k,ctx);}
      else if(role==='club'){b.innerHTML=clubDash(k,ctx);}
      else{b.innerHTML=staffDash(k,role);}
    }).catch(function(err){root.querySelector('#dashBody').innerHTML='<div class="panel pad" style="color:var(--danger)">'+UI.esc((err.errors&&err.errors[0].message)||'خطا')+'</div>';});
  }
};
function staffDash(k,role){
  var attention=(k.attention||[]).length
    ? '<div class="panel pad mt4"><div class="row jb gap2 mb3"><b class="i13">'+UI.ic('i-flag')+' نیازمند توجه شما</b>'
      +'<span class="i11 mut">'+UI.faNum(k.attention.length)+' مورد باز</span></div>'
      +'<div class="row gap2 wrap">'+k.attention.map(function(a){
        return '<a class="attn '+a.tone+'" href="'+UI.esc(a.path)+'">'+UI.ic(a.icon)
          +'<span class="g1"><span class="i13">'+UI.esc(a.label)+'</span></span>'
          +'<span class="badge '+a.tone+'">'+UI.faNum(a.count)+'</span></a>';
      }).join('')+'</div></div>'
    : '<div class="panel pad mt4"><div class="row gap2"><span class="badge b-ok">'+UI.ic('i-ccheck')+'</span>'
      +'<span class="i13">همه‌چیز مرتب است — کار باز‌مانده‌ای ندارید.</span></div></div>';
  var cards=''
    +kpiCard('سوارکاران',UI.faNum(k.total_riders),'i-users','b-info','+'+UI.faNum(k.riders_delta||0)+' در ۷ روز')
    +kpiCard('اسب‌های فعال',UI.faNum(k.total_horses),'i-horse','b-ok','+'+UI.faNum(k.horses_delta||0)+' در ۷ روز')
    +kpiCard('باشگاه‌ها',UI.faNum(k.total_clubs),'i-book','b-mut','')
    +kpiCard('مسابقات باز',UI.faNum(k.active_competitions),'i-trophy','b-warn','');
  var fin=''
    +kpiCard('درآمد این ماه',UI.money(k.revenue_month),'i-wallet','b-ok','')
    +kpiCard('درآمد هفته',UI.money(k.revenue_week),'i-wallet','b-info','')
    +kpiCard('ثبت‌نام امروز',UI.faNum(k.signups_today),'i-list','b-mut','')
    +kpiCard('مسابقات امروز',UI.faNum(k.todays_competitions),'i-calendar','b-warn','');
  var queue=''
    +kpiCard('تأیید سوارکاران',UI.faNum(k.pending_verifications),'i-user','b-warn','')
    +kpiCard('تأیید ثبت‌نام',UI.faNum(k.pending_confirmations),'i-ccheck','b-warn','')
    +kpiCard('پرداخت‌های معلق',UI.faNum(k.pending_payments),'i-clock','b-info','')
    +kpiCard('استردادها',UI.faNum(k.pending_refunds),'i-swap','b-bad','');
  var quick='<div class="row gap2 wrap">'
    +'<a class="btn btn-s btn-sm" href="/competitions">'+UI.ic('i-plus')+' مسابقه جدید</a>'
    +'<a class="btn btn-g btn-sm" href="/calendar">'+UI.ic('i-calendar')+' تقویم</a>'
    +'<a class="btn btn-g btn-sm" href="/users">'+UI.ic('i-users')+' کاربران</a>'
    +'<a class="btn btn-g btn-sm" href="/ranking">'+UI.ic('i-star')+' رده‌بندی کلی</a>'
    +'<a class="btn btn-g btn-sm" href="/reports">'+UI.ic('i-chart')+' گزارش‌ها</a>'
    +(k.is_admin?'<a class="btn btn-g btn-sm" href="/backups">'+UI.ic('i-db')+' پشتیبان‌گیری</a>':'')
    +'</div>';
  return '<div class="grid c4">'+cards+'</div><div class="grid c4 mt4">'+fin+'</div><div class="grid c4 mt4">'+queue+'</div>'
    +'<div class="grid main31 mt4"><div>'+attention
    +section('عملیات سریع',quick)
    +section('روند ثبت‌نام (۳۰ روز)',sparkline(k.signups_over_time))
    +section('روند درآمد (۳۰ روز)',sparkline(k.revenue_over_time))
    +'</div><div>'+section('محبوب‌ترین رده‌ها (۹۰ روز)',radePopularity(k.rade_popularity))
    +section('آخرین رویدادها',changelogList(k.recent_changelog))
    +'</div></div>';
}
function sparkline(series){
  if(!series||!series.length){return '<div class="empty">داده‌ای نیست</div>';}
  var vals=series.map(function(p){return Number(p.value||p.count||0);});
  var max=Math.max.apply(null,vals.concat([1]));
  var w=Math.min(100,Math.ceil(100/Math.max(1,series.length)))||1;
  return '<div class="row gap1" style="height:64px;align-items:flex-end">'+series.map(function(p,i){
    var h=Math.max(3,Math.round(vals[i]/max*100));
    return '<div style="flex:1;height:'+h+'%;background:var(--brand);opacity:'+(0.35+0.65*(i/series.length))+';border-radius:3px" title="'+UI.esc(p.date||'')+': '+UI.faNum(vals[i])+'"></div>';
  }).join('')+'</div><div class="row jb i11 mut mt1"><span>'+UI.esc(series[0].date||'')+'</span><span>'+UI.esc(series[series.length-1].date||'')+'</span></div>';
}
function radePopularity(rows){
  if(!rows||!rows.length){return '<div class="empty">داده‌ای نیست</div>';}
  var max=Math.max.apply(null,rows.map(function(r){return Number(r.signups||r.count||1);}))||1;
  return rows.slice(0,6).map(function(r){
    var v=Number(r.signups||r.count||0);
    return '<div class="mt2"><div class="row jb i12"><span>'+UI.esc(r.name||r.rade||'—')+'</span><b>'+UI.faNum(v)+'</b></div><div class="bar mt1"><i style="width:'+Math.round(v/max*100)+'%"></i></div></div>';
  }).join('');
}
function changelogList(rows){
  if(!rows||!rows.length){return '<div class="empty">رویدادی ثبت نشده</div>';}
  return rows.slice(0,8).map(function(r){
    return '<div class="rl"><div class="g1"><div class="i13 b">'+UI.esc(r.summary||r.action)+'</div><div class="i11 mut mt1">'+I18N.fmtDateTime(r.created_at)+'</div></div></div>';
  }).join('')||'<div class="empty">—</div>';
}
function riderDash(k,ctx){
  var cards=''
    +kpiCard('اسب‌های من',UI.faNum(k.my_horses),'i-horse','b-ok','')
    +kpiCard('ثبت‌نام‌های در جریان',UI.faNum(k.my_signups_pending),'i-clock','b-warn','')
    +kpiCard('تأییدشده',UI.faNum(k.my_signups_confirmed),'i-ccheck','b-ok','')
    +kpiCard('مقام‌ها',UI.faNum(k.my_wins),'i-trophy','b-warn','');
  var horses='<div class="grid c2 mt2">'+(k.my_horses_list||[]).map(function(h){
    return '<a class="panel pad-s lift" style="display:block" href="/horses/'+h.id+'"><div class="row gap2"><span class="av" style="width:34px;height:34px">'+UI.ic('i-horse')+'</span><div><div class="b i13">'+UI.esc(h.name)+'</div><div class="i11 mut ltr">'+UI.esc(h.microchip_number||'')+'</div></div></div></a>';
  }).join('')+'</div>'||'';
  var results=(k.recent_results||[]).map(function(r){
    return '<div class="rl"><div class="g1"><div class="i13 b">'+UI.esc(r.competition)+' · '+UI.esc(r.rade)+'</div><div class="i11 mut mt1">'+UI.esc(r.horse)+'</div></div><span class="badge '+(Number(r.is_winner)?'b-warn':'b-info')+'">'+(r.position?UI.faNum(r.position):'—')+'</span></div>';
  }).join('')||'<div class="empty">هنوز نتیجه‌ای ثبت نشده</div>';
  var alerts='';
  if(k.pending_shares>0){alerts+='<a class="btn btn-warn btn-sm" style="background:var(--gold-soft);color:var(--gold)" href="/horse-shares">'+UI.faNum(k.pending_shares)+' درخواست اشتراک اسب</a>';}
  if(ctx.user&&ctx.user.verification_status==='pending'){alerts+='<span class="badge b-warn">حساب شما در انتظار تأیید است</span>';}
  return cards+'<div class="grid c4 mt4">'
    +kpiCard('مسابقات پیش‌رو',UI.faNum(k.upcoming_competitions),'i-calendar','b-info','')
    +'<div></div><div></div><div></div></div>'
    +(alerts?'<div class="row gap2 wrap mt4">'+alerts+'</div>':'')
    +'<div class="grid main31 mt4"><div>'+section('اسب‌های من',horses,'<a class="btn btn-s btn-sm" href="/horses">'+UI.ic('i-plus')+' مدیریت اسب‌ها</a>')+section('آخرین نتایج',results)+'</div><div>'+section('ثبت‌نام سریع','<a class="btn btn-p btn-w" href="/rider/competitions">'+UI.ic('i-trophy')+' مشاهده مسابقات باز</a><p class="mut i12 mt3">برای ثبت‌نام در مسابقه، اسب و باشگاه خود را انتخاب کنید و هزینه را از طریق زرین‌پال بپردازید.</p>')+'</div></div>';
}
function clubDash(k,ctx){
  return '<div class="grid c4">'
    +kpiCard('مسابقات در میادین ما',UI.faNum(k.venue_competitions),'i-trophy','b-info','')
    +kpiCard('سوارکاران وابسته',UI.faNum(k.affiliated_riders),'i-users','b-ok','')
    +kpiCard('درآمد میادین (ماه)',UI.money(k.revenue_month),'i-wallet','b-ok','')
    +kpiCard('ممنوعیت‌های فعال',UI.faNum(k.active_bans),'i-ban','b-bad','')
    +'</div>'
    +section('آخرین ممنوعیت‌ها',(k.recent_bans||[]).map(function(bn){
      return '<div class="rl"><div class="g1"><div class="i13 b">'+UI.esc(bn.target_type==='rider'?'سوارکار':'اسب')+' #'+UI.faNum(bn.target_id)+'</div><div class="i11 mut mt1">'+UI.esc(bn.reason||'')+'</div></div>'+UI.badge(bn.is_active?'active':'expired')+'</div>';
    }).join('')||'<div class="empty">—</div>');
}

/* ============================== PROFILE ============================== */
Pages.profile={
  title:'پروفایل من',icon:'i-user',sec:'حساب',
  render:function(root){
    root.innerHTML=pageHead('پروفایل من')+'<div id="pf"><div class="spin"></div></div>';
    API.get('/panel/profile').then(function(u){
      var body=root.querySelector('#pf');
      var canAvatar=['rider','club'].indexOf(u.role)>-1;
      var rp=u.rider_profile||{};
      var isRider=u.role==='rider';
      body.innerHTML='<div class="grid c2">'
        +'<div class="panel pad"><form id="fProf">'
        +'<div class="row gap3 mb4">'+avatarFor(u,52)+'<div><div class="b">'+UI.esc(firstLast(u))+'</div><div class="i12 mut">'+UI.esc(u.username)+' · '+roleLabel(u.role)+'</div><div class="mt1">'+UI.badge(u.verification_status)+'</div>'
        +(canAvatar?'<label class="btn btn-g btn-sm mt2">'+UI.ic('i-plus')+' تصویر پروفایل<input type="file" accept="image/*" hidden id="avUp"/></label>':'')+'</div></div>'
        +'<div class="frow">'
        +UI.inp('first_name','نام',u.first_name,{req:true})
        +UI.inp('last_name','نام خانوادگی',u.last_name,{req:true})
        +UI.inp('phone','موبایل',u.phone)
        +UI.inp('email','ایمیل',u.email)
        +UI.inp('national_id','کد ملی',u.national_id)
        +'</div>'
        +(isRider?'<div class="mt4"><b class="i13">اطلاعات سوارکار</b><div class="frow mt3">'
          +UI.sel('rp_gender','جنسیت',rp.gender,[{v:'',l:'— نامشخص —'},{v:'male',l:'مرد'},{v:'female',l:'زن'}])
          +pDate('rp_birth_date','تاریخ تولد',rp.birth_date)
          +UI.inp('rp_insurance_number','شماره بیمه ورزشی',rp.insurance_number)
          +UI.inp('rp_province','استان',rp.province)
          +UI.inp('rp_city','شهر',rp.city)
          +UI.inp('rp_emergency_name','نام تماس اضطراری',rp.emergency_name)
          +UI.inp('rp_emergency_phone','تلفن اضطراری',rp.emergency_phone)
          +UI.ta('rp_address','نشانی',rp.address,{full:true})
          +UI.ta('rp_bio','معرفی کوتاه',rp.bio,{full:true})
          +'</div></div>':'')
        +'<button class="btn btn-p mt4">ذخیره تغییرات</button></form></div>'
        +'<div>'
        +(isRider?'<div class="panel pad mb4"><b class="i13">کد اشتراک‌گذاری اسب</b><div class="row gap2 mt3"><span class="kbd ltr" style="font-size:16px">'+UI.esc(rp.my_share_code||'—')+'</span><button class="btn btn-g btn-sm" id="cpShare">کپی</button></div><p class="hint">این کد ۶ رقمی را در اختیار مالکان اسب قرار دهید تا اسب خود را با شما به اشتراک بگذارند.</p></div>':'')
        +(u.role==='club'&&u.club?'<div class="panel pad mb4"><b class="i13">باشگاه من</b><div class="mt3"><a class="btn btn-g btn-sm" href="/clubs/'+u.club.id+'">'+UI.esc(u.club.name)+'</a></div></div>':'')
        +'<div class="panel pad mb4"><b class="i13">تغییر گذرواژه</b><form id="fPass" class="mt3">'
        +UI.inp('current_password','گذرواژه فعلی','','password',{req:true})
        +'<div class="frow mt3">'+UI.inp('password','گذرواژه جدید','','password',{req:true})+UI.inp('password_confirm','تکرار گذرواژه','','password',{req:true})+'</div>'
        +'<button class="btn btn-g mt3">تغییر گذرواژه</button></form></div>'
        +'<div class="panel pad"><div class="row jb"><b class="i13">نشست‌های فعال</b><button class="btn btn-d btn-sm" id="revokeAll">خروج از همه دستگاه‌ها</button></div><div id="sess" class="mt3"><div class="spin"></div></div></div>'
        +'</div></div>';
      var f=document.getElementById('fProf');
      f.querySelectorAll('[data-date]').forEach(function(i){UI.attachDatepicker(i);});
      var cp=document.getElementById('cpShare');
      if(cp){cp.addEventListener('click',function(){
        try{navigator.clipboard.writeText(String(rp.my_share_code||''));UI.toast('کد کپی شد');}catch(e){UI.toast('کپی نشد — کد را دستی یادداشت کنید','w');}
      });}
      f.addEventListener('submit',function(e){
        e.preventDefault();
        var v=UI.formValues(f);
        var payload={first_name:v.first_name,last_name:v.last_name,phone:v.phone,email:v.email,national_id:v.national_id};
        if(isRider){
          var bd=f.querySelector('[name=rp_birth_date]');
          payload.rider_profile={
            gender:v.rp_gender,
            birth_date:(bd&&bd.dataset.iso)?bd.dataset.iso:null,
            insurance_number:v.rp_insurance_number,
            province:v.rp_province,
            city:v.rp_city,
            address:v.rp_address,
            bio:v.rp_bio,
            emergency_name:v.rp_emergency_name,
            emergency_phone:v.rp_emergency_phone
          };
        }
        API.post('/panel/profile',payload).then(function(){
          UI.toast('پروفایل ذخیره شد');
          Pages.profile.render(root);
        }).catch(function(err){UI.showErrors(f,err.errors);});
      });
      var av=f.querySelector('#avUp');
      if(av){av.addEventListener('change',function(){
        var file=av.files[0];if(!file){return;}
        API.upload('/panel/profile/avatar',file).then(function(){UI.toast('تصویر به‌روزرسانی شد');Pages.profile.render(root);})
          .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در بارگذاری','e');});
      });}
      var fp=document.getElementById('fPass');
      fp.addEventListener('submit',function(e){
        e.preventDefault();
        API.post('/panel/profile/password',UI.formValues(fp)).then(function(){
          UI.toast('گذرواژه تغییر کرد؛ نشست‌ها تازه شد');
        }).catch(function(err){UI.showErrors(fp,err.errors);});
      });
      API.get('/panel/profile/sessions').then(function(d){
        var s=document.getElementById('sess');
        s.innerHTML=UI.rows(d).map(function(row){
          return '<div class="rl"><div class="g1 i12"><div class="b ltr">'+UI.esc((row.user_agent||'').slice(0,40)||'دستگاه')+'</div><div class="mut mt1">'+I18N.fmtDateTime(row.created_at)+(row.id===d.current?' · <b>این دستگاه</b>':'')+'</div></div>'+(row.id===d.current?'':'<button class="btn btn-d btn-sm" data-rev="'+UI.esc(row.id)+'">لغو</button>')+'</div>';
        }).join('')||'<div class="empty">—</div>';
        s.querySelectorAll('[data-rev]').forEach(function(btn){
          btn.addEventListener('click',function(){
            API.post('/panel/profile/sessions/'+btn.dataset.rev+'/revoke',{}).then(function(){Pages.profile.render(root);});
          });
        });
      });
      document.getElementById('revokeAll').addEventListener('click',function(){
        UI.confirm('از همه دستگاه‌ها خارج می‌شوید؟',function(){
          API.post('/panel/profile/sessions/revoke-all',{}).then(function(){App.enter();});
        });
      });
    });
  }
};
function firstLast(u){return [u.first_name,u.last_name].filter(Boolean).join(' ')||u.username;}
function roleLabel(r){return {admin:'مدیر ارشد',manager:'مدیر',rider:'سوارکار',club:'باشگاه'}[r]||r;}

/* ============================== USERS ============================== */
Pages.users={
  title:'کاربران',icon:'i-users',sec:'عملیات',roles:['admin','manager'],
  render:function(root,ctx){
    /* Filters come from (and are written back to) the URL so a reload or a
       shared link restores the exact same result set. */
    var filters=App.seed({role:'',status:'',search:'',page:1});
    var ROLES=[{v:'rider',l:'سوارکار'},{v:'club',l:'باشگاه'},{v:'manager',l:'مدیر'},{v:'admin',l:'مدیر ارشد'}];
    var STATUSES=[{v:'pending',l:'در انتظار تأیید'},{v:'verified',l:'تأییدشده'},{v:'rejected',l:'ردشده'},{v:'disabled-limited',l:'محدود'},{v:'disabled-full',l:'مسدود'}];
    root.innerHTML=pageHead('کاربران','مدیریت سوارکاران، مدیران و باشگاه‌ها.',
      '<div class="row gap1" id="bulkBar" style="display:none">'
      +'<button class="btn btn-s btn-sm" id="bulkV">تأیید گروهی</button>'
      +'<button class="btn btn-g btn-sm" id="bulkL">محدودسازی</button>'
      +'<button class="btn btn-g btn-sm" id="bulkE">رفع محدودیت</button>'
      +(ctx.user.role==='admin'?'<button class="btn btn-d btn-sm" id="bulkD">حذف گروهی</button>':'')+'</div>'
      +'<button class="btn btn-p btn-sm" id="newUser">'+UI.ic('i-plus')+' کاربر جدید</button>');
    var barHost=root.appendChild(UI.el('<div class="mb3"></div>'));
    var fbar=UI.filterBar({
      filters:filters,
      views:'users',
      searchLabel:'جستجوی نام، موبایل، نام کاربری…',
      selects:[
        {key:'role',label:'نقش',options:[{v:'',l:'همه نقش‌ها'}].concat(ROLES)},
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'}].concat(STATUSES)}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    barHost.appendChild(fbar);
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(6)+'</div>'));
    root.querySelector('#newUser').addEventListener('click',function(){newUserDlg();});
    var UCOLS=[
      {label:'<input type="checkbox" id="selAllUsers"/>',raw:true,render:function(r){return '<input type="checkbox" class="selrow" data-id="'+r.id+'"/>';}},
      {label:'کاربر',sortKey:'name',sortValue:function(r){return firstLast(r);},render:function(r){return '<div class="row gap2">'+avatarFor(r,30)+'<div><div class="b i13">'+UI.esc(firstLast(r))+'</div><div class="i11 mut ltr">'+UI.esc(r.username)+'</div></div></div>';}},
      {label:'نقش',sortKey:'role',render:function(r){return roleChip(r.role);}},
      {label:'موبایل',key:'phone',sortKey:'phone',render:function(r){return '<span class="ltr">'+UI.esc(r.phone||'—')+'</span>';}},
      {label:'وضعیت',sortKey:'verification_status',render:function(r){return UI.badge(r.verification_status)+(r.disable_state!=='none'?' '+UI.badge(r.disable_state):'');}},
      {label:'آخرین ورود',sortKey:'last_login_at',render:function(r){return r.last_login_at?I18N.fmtDate(r.last_login_at):'—';}},
      {label:'عضویت',key:'created_at',sortKey:'created_at',render:function(r){return I18N.fmtDate(r.created_at);}},
      {label:'عملیات',render:function(r){return '<div class="row gap1"><button class="btn-i" data-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'+(ctx.user.role==='admin'?'<button class="btn-i" data-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button>':'')+'</div>';}}
    ];
    function load(){
      API.get('/panel/users',filters).then(function(d){
        var rows=UI.sortRows(UI.rows(d)||[],UCOLS,filters.sort);
        var total=d.total!=null?d.total:rows.length;
        fbar.setCount(UI.faNum(total)+' کاربر');
        root.querySelector('#tbl').innerHTML=UI.table(UCOLS,rows,{
          rowHref:function(r){return '/users/'+r.id;},
          pager:UI.pagerHtml(total,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی کاربری یافت نشد':'هنوز کاربری ثبت نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){
          filters.sort={key:key,dir:dir};
          App.setQuery(filters);
          load();
        });
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){
          b.addEventListener('click',function(){fbar.onClear();});
        });
        bindTableNav(root,function(h){App.go(h);});
        var tbl=root.querySelector('#tbl');
        var selAll=tbl.querySelector('#selAllUsers');
        if(selAll){selAll.addEventListener('change',function(){tbl.querySelectorAll('.selrow').forEach(function(c){c.checked=selAll.checked;});updateBulk();});}
        tbl.addEventListener('change',updateBulk);
        root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();editUser(b.dataset.edit);});});
        root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();UI.confirm('این کاربر حذف شود؟ این عملیات برگشت‌ناپذیر است.',function(){API.del('/panel/users/'+b.dataset.del).then(function(){load();UI.toast('کاربر حذف شد');});});});});
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){
          filters.page=parseInt(b.dataset.page,10);
          App.setQuery(filters);
          load();
        });});
      });
    }
    function updateBulk(){
      var bar=document.getElementById('bulkBar');
      if(bar){bar.style.display=root.querySelectorAll('#tbl .selrow:checked').length?'':'none';}
    }
    function selectedIds(){
      var tbl=root.querySelector('#tbl');
      return tbl?Array.prototype.map.call(tbl.querySelectorAll('.selrow:checked'),function(c){return +c.dataset.id;}):[];
    }
    function bulk(action){
      var ids=selectedIds();
      if(!ids.length){UI.toast('ابتدا حداقل یک کاربر انتخاب کنید','w');return;}
      var run=function(){API.post('/panel/users/bulk',{ids:ids,action:action})
        .then(function(d){UI.toast(UI.faNum(d.processed||0)+' مورد پردازش شد');load();})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});};
      if(action==='delete'){UI.confirm('حذف گروهی کاربران انتخابی برگشت‌ناپذیر است. ادامه؟',run,'حذف');}
      else{run();}
    }
    function roleChip(r){
      var m={admin:['b-bad','مدیر ارشد'],manager:['b-info','مدیر'],rider:['b-ok','سوارکار'],club:['b-warn','باشگاه']}[r]||['b-mut',r];
      return '<span class="badge '+m[0]+'">'+m[1]+'</span>';
    }
    function userForm(u){
      var isNew=!u;
      u=u||{};
      /* The role is always editable for Admins: an admin may promote/demote any
         other user (managers cannot, and nobody may change their own role). */
      var roles=[{v:'rider',l:'سوارکار'},{v:'manager',l:'مدیر'},{v:'admin',l:'مدیر ارشد'},{v:'club',l:'باشگاه'}];
      var isSelf=u.id&&u.id===ctx.user.id;
      var roleField;
      if(isNew){
        roleField=UI.sel('role','نقش',u.role||'rider',roles,{req:true});
      }else if(ctx.user.role==='admin'&&!isSelf){
        roleField=UI.sel('role','نقش',u.role||'rider',roles,{req:true,
          hint:'تغییر نقش بلافاصله اعمال می‌شود؛ نام کاربری سوارکاران به یک کد عددی تبدیل می‌شود.'});
      }else{
        roleField='<div><label class="lbl">نقش</label><div class="inp" style="background:var(--surface-3);color:var(--muted)">'+UI.esc(roleLabel(u.role))+'</div>'
          +(isSelf?'<div class="hint">نقش حساب خودتان قابل تغییر نیست.</div>':'<div class="hint">فقط مدیر ارشد می‌تواند نقش را تغییر دهد.</div>')+'</div>';
      }
      return '<div class="pad-s"><form id="fUser" class="frow">'
        +roleField
        +UI.inp('username','نام کاربری',u.username,null,{req:true})
        +UI.inp('first_name','نام',u.first_name,{req:true})
        +UI.inp('last_name','نام خانوادگی',u.last_name,{req:true})
        +UI.inp('phone','موبایل',u.phone)
        +UI.inp('national_id','کد ملی',u.national_id)
        +UI.inp('email','ایمیل',u.email)
        +(isNew?UI.inp('password','گذرواژه','','password',{req:true}):'')
        +'<div class="full row jb"><span class="i12 mut">وضعیت تأیید: '+UI.badge(u.verification_status||'pending')+'</span></div>'
        +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>';
    }
    function newUserDlg(){
      var body=UI.el(userForm(null));
      UI.modal('کاربر جدید',body);
      body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
      body.querySelector('[data-save]').addEventListener('click',function(){
        var f=body.querySelector('#fUser');
        API.post('/panel/users',UI.formValues(f)).then(function(){
          UI.closeDialog();UI.toast('کاربر ساخته شد');load();
        }).catch(function(err){UI.showErrors(f,err.errors);});
      });
    }
    function editUser(id,onDone){
      API.get('/panel/users/'+id).then(function(u){
        var body=UI.el(userForm(u));
        UI.modal('ویرایش کاربر — '+firstLast(u),body);
        body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
        body.querySelector('[data-save]').addEventListener('click',function(){
          var f=body.querySelector('#fUser');
          var vals=UI.formValues(f);
          if(!vals.role){delete vals.role;}
          API.put('/panel/users/'+id,vals).then(function(){UI.closeDialog();UI.toast('ذخیره شد');if(onDone){onDone();}else{load();}})
            .catch(function(err){UI.showErrors(f,err.errors);});
        });
        /* admin actions row */
        if(ctx.user.role==='admin'){
          var bar=UI.el('<div class="row gap2 wrap pad-s" style="border-top:1px solid var(--border)">'
            +'<button class="btn btn-s btn-sm" data-a="verify">تأیید حساب</button>'
            +'<button class="btn btn-g btn-sm" data-a="reject">رد حساب</button>'
            +'<button class="btn btn-g btn-sm" data-a="disable">محدودسازی</button>'
            +'<button class="btn btn-g btn-sm" data-a="enable">رفع محدودیت</button>'
            +'<button class="btn btn-g btn-sm" data-a="impersonate">ورود جایگزین</button>'
            +'<button class="btn btn-g btn-sm" data-a="revoke">لغو نشست‌ها</button></div>');
          body.appendChild(bar);
          bar.querySelectorAll('[data-a]').forEach(function(btn){
            btn.addEventListener('click',function(){
              var a=btn.dataset.a;
              var p;
              if(a==='verify'){p=API.post('/panel/users/'+id+'/verify',{});}
              else if(a==='reject'){p=API.post('/panel/users/'+id+'/reject',{});}
              else if(a==='disable'){p=API.post('/panel/users/'+id+'/disable',{state:'limited',reason:'manual'});}
              else if(a==='enable'){p=API.post('/panel/users/'+id+'/enable',{});}
              else if(a==='impersonate'){p=API.post('/panel/users/'+id+'/impersonate',{}).then(function(){App.enter();throw {silent:true};});}
              else if(a==='revoke'){p=API.post('/panel/users/'+id+'/sessions/revoke-all',{});}
              p&&p.then(function(){UI.closeDialog();UI.toast('انجام شد');load();}).catch(function(err){if(!err.silent){UI.showErrors(body,err.errors);}});
            });
          });
        }
      });
    }
    document.getElementById('bulkV').addEventListener('click',function(){bulk('verify');});
    document.getElementById('bulkL').addEventListener('click',function(){bulk('disable_limited');});
    document.getElementById('bulkE').addEventListener('click',function(){bulk('enable');});
    Pages.users.openEditor=editUser;
    var bulkDel=document.getElementById('bulkD');
    if(bulkDel){bulkDel.addEventListener('click',function(){bulk('delete');});}
    load();
  }
};

/* user detail page */
Pages.userDetail={
  title:'کاربر',icon:'i-user',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    var staff=['admin','manager'].indexOf(ctx.user.role)>-1;
    var isAdmin=ctx.user.role==='admin';
    Promise.all([
      API.get('/panel/users/'+id),
      API.get('/panel/horses',{owner_user_id:id}).catch(function(){return {rows:[]};}),
    ]).then(function(res){
      var u=res[0];
      var horses=UI.rows(res[1]);
      var rp=u.rider_profile||{};
      var acts=staff?'<div class="panel pad mt4"><b class="i13">عملیات مدیریتی</b><div class="row gap2 wrap mt3">'
        +'<button class="btn btn-s btn-sm" data-op="verify">تأیید حساب</button>'
        +'<button class="btn btn-g btn-sm" data-op="reject">رد حساب</button>'
        +'<button class="btn btn-g btn-sm" data-op="disable">محدودسازی</button>'
        +'<button class="btn btn-g btn-sm" data-op="enable">رفع محدودیت</button>'
        +'<button class="btn btn-g btn-sm" data-op="password">بازنشانی گذرواژه</button>'
        +(isAdmin?'<button class="btn btn-g btn-sm" data-op="impersonate">ورود جایگزین</button>':'')
        +(isAdmin?'<button class="btn btn-g btn-sm" data-op="revoke">لغو همه نشست‌ها</button>':'')
        +'</div></div>':'';
      root.innerHTML=pageHead(firstLast(u),roleLabel(u.role)+' · '+UI.badge(u.verification_status)+(u.disable_state&&u.disable_state!=='none'?' '+UI.badge(u.disable_state):''),
        '<div class="row gap2"><a class="btn btn-g btn-sm" href="/users">'+UI.ic('i-cr')+' بازگشت</a>'
        +(staff?'<button class="btn btn-g btn-sm" id="udEdit">'+UI.ic('i-edit')+' ویرایش</button>':'')+'</div>')
        +'<div class="grid c2"><div class="panel pad">'+detail([
          ['نام کاربری','<span class="ltr">'+UI.esc(u.username||'—')+'</span>'],
          ['موبایل','<span class="ltr">'+UI.esc(u.phone||'—')+'</span>'],
          ['ایمیل','<span class="ltr">'+UI.esc(u.email||'—')+'</span>'],
          ['کد ملی','<span class="ltr">'+UI.esc(u.national_id||'—')+'</span>'],
          ['نقش',roleLabel(u.role)],
          ['وضعیت تأیید',UI.badge(u.verification_status)],
          ['وضعیت حساب',(!u.disable_state||u.disable_state==='none')?'<span class="badge b-ok">عادی</span>':UI.badge(u.disable_state)],
          ['دلیل محدودیت',u.disable_reason||'—'],
          ['آخرین ورود',u.last_login_at?I18N.fmtDateTime(u.last_login_at):'—'],
          ['عضویت',I18N.fmtDate(u.created_at)]
        ])
        +(u.role==='rider'?'<div class="mt4"><b class="i13">اطلاعات سوارکار</b>'+detail([
          ['کد اشتراک اسب','<span class="kbd ltr">'+UI.esc(rp.my_share_code||'—')+'</span>'],
          ['شماره بیمه','<span class="ltr">'+UI.esc(rp.insurance_number||'—')+'</span>'],
          ['جنسیت',rp.gender||'—'],
          ['تولد',rp.birth_date?I18N.fmtDate(rp.birth_date):'—'],
          ['استان / شهر',[rp.province,rp.city].filter(Boolean).join(' / ')||'—'],
          ['تماس اضطراری',UI.esc(rp.emergency_name||'—')+(rp.emergency_phone?' · <span class="ltr">'+UI.esc(rp.emergency_phone)+'</span>':'')]
        ])+'</div>':'')
        +(u.role==='club'&&u.club?'<div class="mt4"><b class="i13">باشگاه</b><div class="mt2"><a class="btn btn-g btn-sm" href="/clubs/'+u.club.id+'">'+UI.esc(u.club.name)+'</a></div></div>':'')
        +'</div>'
        +'<div><div class="panel pad"><b class="i13">اسب‌های این کاربر</b>'+UI.table([{label:'نام',key:'name'},{label:'ریزتراشه',key:'microchip_number'},{label:'وضعیت',render:function(r){return UI.badge(r.status);}}],horses,{emptyText:'اسبی ندارد'})+'</div></div></div>'
        +acts;
      function refresh(){Pages.userDetail.render(root,ctx,id);}
      var ed=document.getElementById('udEdit');
      if(ed){ed.addEventListener('click',function(){
        if(Pages.users&&Pages.users.openEditor){Pages.users.openEditor(id,refresh);}
        else{App.go('/users');}
      });}
      root.querySelectorAll('[data-op]').forEach(function(b){
        b.addEventListener('click',function(){
          var op=b.dataset.op;
          if(op==='impersonate'){
            UI.confirm('با حساب این کاربر وارد می‌شوید؟ پس از پایان، از نوار بالای صفحه خارج شوید.',function(){
              API.post('/panel/users/'+id+'/impersonate',{}).then(function(){App.enter();});
            });
            return;
          }
          if(op==='password'){
            var pbody=UI.el('<div class="pad-s"><label class="lbl req">گذرواژه جدید</label><input class="inp ltr" id="npw" type="password" autocomplete="new-password"/><p class="hint">حداقل ۸ نویسه.</p><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ثبت</button></div></div>');
            UI.modal('بازنشانی گذرواژه — '+firstLast(u),pbody);
            pbody.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
            pbody.querySelector('[data-save]').addEventListener('click',function(){
              var pw=pbody.querySelector('#npw').value;
              if(String(pw).length<8){UI.toast('گذرواژه باید حداقل ۸ نویسه باشد','w');return;}
              API.post('/panel/users/'+id+'/reset-password',{password:pw})
                .then(function(){UI.closeDialog();UI.toast('گذرواژه بازنشانی شد');})
                .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
            });
            return;
          }
          if(op==='disable'){
            var dbody=UI.el('<div class="pad-s"><label class="lbl">نوع محدودیت</label><select class="sel" id="dst"><option value="limited">محدود</option><option value="full">مسدود</option></select><label class="lbl mt3">دلیل</label><input class="inp" id="drn"/><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-d" data-save>اعمال</button></div></div>');
            UI.modal('محدودسازی کاربر',dbody);
            dbody.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
            dbody.querySelector('[data-save]').addEventListener('click',function(){
              API.post('/panel/users/'+id+'/disable',{state:dbody.querySelector('#dst').value,reason:dbody.querySelector('#drn').value})
                .then(function(){UI.closeDialog();UI.toast('اعمال شد');refresh();})
                .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
            });
            return;
          }
          var run;
          if(op==='verify'){run=API.post('/panel/users/'+id+'/verify',{});}
          else if(op==='reject'){run=API.post('/panel/users/'+id+'/reject',{});}
          else if(op==='enable'){run=API.post('/panel/users/'+id+'/enable',{});}
          else if(op==='revoke'){run=API.post('/panel/users/'+id+'/sessions/revoke-all',{});}
          if(!run){return;}
          var go=function(){run.then(function(d){UI.toast(op==='revoke'?(UI.faNum((d&&d.revoked)||0)+' نشست لغو شد'):'انجام شد');refresh();})
            .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});};
          if(op==='reject'){UI.confirm('حساب این کاربر رد شود؟',go);}
          else if(op==='revoke'){UI.confirm('همه نشست‌های این کاربر لغو شود؟',go);}
          else{go();}
        });
      });
    });
  }
};

/* ============================== CLUBS ============================== */
Pages.clubs={
  title:'باشگاه‌ها',icon:'i-book',sec:'عملیات',
  render:function(root,ctx){
    var filters=App.seed({search:'',status:'',city:'',page:1});
    root.innerHTML=pageHead('باشگاه‌ها','باشگاه‌های عضو هیئت.',
      (['admin','manager'].includes(ctx.user.role)?'<button class="btn btn-p btn-sm" id="newClub">'+UI.ic('i-plus')+' باشگاه جدید</button>':''))
      +'<div class="mb3" id="fbarHost"></div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'clubs',
      searchLabel:'جستجوی باشگاه، مسئول یا تلفن…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'},{v:'active',l:'فعال'},{v:'closed',l:'غیرفعال'}]}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(5)+'</div>'));
    var CCOLS=[
      {label:'باشگاه',sortKey:'name',sortValue:function(r){return r.name;},render:function(r){return '<div class="b i13">'+UI.esc(r.name)+'</div><div class="i11 mut">'+UI.esc(r.city||'')+'</div>';}},
      {label:'مسئول',key:'contact_person',sortKey:'contact_person'},
      {label:'تلفن',key:'phone',sortKey:'phone',render:function(r){return '<span class="ltr">'+UI.esc(r.phone||'—')+'</span>';}},
      {label:'وضعیت',sortKey:'is_active',render:function(r){return UI.badge(r.is_active?'active':'closed');}},
      {label:'عملیات',render:function(r){return '<div class="row gap1"><button class="btn-i" data-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'+(['admin'].includes(ctx.user.role)?'<button class="btn-i" data-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button>':'')+'</div>';}}
    ];
    var nb=root.querySelector('#newClub');
    if(nb){nb.addEventListener('click',function(){clubDlg(null);});}
    function load(){
      API.get('/panel/clubs',filters).then(function(d){
        var rows=UI.sortRows(UI.rows(d),CCOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' باشگاه');
        root.querySelector('#tbl').innerHTML=UI.table(CCOLS,rows,{
          rowHref:function(r){return '/clubs/'+r.id;},
          pager:UI.pagerHtml(d.total||rows.length,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی باشگاهی یافت نشد':'باشگاهی ثبت نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        bindTableNav(root,function(h){App.go(h);});
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
        root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.get('/panel/clubs/'+b.dataset.edit).then(clubDlg);});});
        root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();UI.confirm('باشگاه حذف شود؟',function(){API.del('/panel/clubs/'+b.dataset.del).then(function(){load();});});});});
      });
    }
    function clubDlg(c){
      var isNew=!c;
      c=c||{};
      var pre=isNew?API.get('/panel/users',{role:'club',per_page:250}).catch(function(){return {rows:[]};}):Promise.resolve({rows:[]});
      pre.then(function(users){
        var clubUsers=users.rows||[];
        var ownerBlock=isNew
          ?(clubUsers.length
            ?UI.pickField({name:'user_id',label:'حساب کاربری باشگاه',value:'',full:true,req:true,
              placeholder:'جستجوی کاربر باشگاه…',
              hint:'هر باشگاه به یک کاربر با نقش «باشگاه» متصل است.',
              options:[{v:'',l:'— انتخاب کاربر —'}].concat(clubUsers.map(function(u){return {v:u.id,l:firstLast(u)+' ('+u.username+')'};}))})
            :'<div class="full"><label class="lbl">حساب کاربری باشگاه</label><div class="hint" style="color:var(--danger)">کاربری با نقش «باشگاه» وجود ندارد. ابتدا از بخش کاربران یک کاربر «باشگاه» بسازید.</div></div>')
          :'';
        var body=UI.el('<div class="pad-s"><form id="fClub" class="frow">'
          +ownerBlock
          +UI.inp('name','نام باشگاه',c.name,null,{req:true,full:true})
          +UI.inp('city','شهر',c.city)+UI.inp('province','استان',c.province)
          +UI.inp('contact_person','مسئول',c.contact_person)+UI.inp('phone','تلفن',c.phone)
          +UI.inp('email','ایمیل',c.email)+UI.inp('address','نشانی',c.address,{full:true})
          +UI.ta('description','توضیحات',c.description,{full:true})
          +'</form><div class="row jend gap2 mt3">'
          +((isNew&&!clubUsers.length)?'<button class="btn btn-g" data-gousers>ساخت کاربر باشگاه</button>':'')
          +'<button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>');
        UI.modal(isNew?'باشگاه جدید':'ویرایش باشگاه',body);
        UI.initPicks(body,{});
        body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
        var gu=body.querySelector('[data-gousers]');
        if(gu){gu.addEventListener('click',function(){UI.closeDialog();App.go('/users');});}
        body.querySelector('[data-save]').addEventListener('click',function(){
          var f=body.querySelector('#fClub');
          var vals=UI.formValues(f);
          if(isNew&&!vals.user_id){UI.toast('ابتدا حساب باشگاه را انتخاب کنید','w');return;}
          if(vals.user_id){vals.user_id=+vals.user_id;}
          (isNew?API.post('/panel/clubs',vals):API.put('/panel/clubs/'+c.id,vals))
            .then(function(){UI.closeDialog();UI.toast('ذخیره شد');load();})
            .catch(function(err){UI.showErrors(f,err.errors);});
        });
      });
    }
    load();
  }
};
Pages.clubDetail={
  title:'باشگاه',icon:'i-book',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    API.get('/panel/clubs/'+id).then(function(c){
      var bansT=UI.table([{label:'هدف',render:function(r){return r.target_type==='rider'?'سوارکار #'+UI.faNum(r.target_id):'اسب #'+UI.faNum(r.target_id);}},{label:'دلیل',key:'reason',wrap:true},{label:'وضعیت',render:function(r){return UI.badge(r.is_active?'active':'expired');}}],[],{emptyText:'بدون ممنوعیت'});
      root.innerHTML=pageHead(c.name,UI.esc(c.city||'')+' · '+UI.badge(c.is_active?'active':'closed'),
        '<a class="btn btn-g btn-sm" href="/clubs">'+UI.ic('i-cr')+' بازگشت</a>')
        +'<div class="grid c2"><div class="panel pad">'+detail([['مسئول',c.contact_person],['تلفن',c.phone],['ایمیل',c.email],['نشانی',c.address],['استان',c.province],['توضیحات',c.description]])+'</div>'
        +'<div class="panel pad"><div class="row jb mb3"><b class="i13">ممنوعیت‌ها</b><button class="btn btn-s btn-sm" id="addBan">'+UI.ic('i-ban')+' ممنوعیت جدید</button></div><div id="bans">'+bansT+'</div></div></div>';
      loadBans();
      var canBan=['admin','manager','club'].indexOf(ctx.user.role)>-1;
      function loadBans(){
        API.get('/panel/clubs/'+id+'/bans').then(function(d){
          var list=UI.rows(d);
          document.getElementById('bans').innerHTML=UI.table([
            {label:'هدف',render:function(r){return UI.esc(r.target_name||((r.target_type==='rider'?'سوارکار #':'اسب #')+UI.faNum(r.target_id)));}},
            {label:'نوع',render:function(r){return r.target_type==='rider'?'سوارکار':'اسب';}},
            {label:'دلیل',key:'reason',wrap:true},
            {label:'انقضا',render:function(r){return r.expires_at?I18N.fmtDate(r.expires_at):'—';}},
            {label:'وضعیت',render:function(r){return UI.badge(r.is_active?'active':'expired');}},
            {label:'',render:function(r){return canBan?'<button class="btn btn-d btn-sm" data-delban="'+r.id+'">حذف</button>':'';}}
          ],list,{emptyText:'بدون ممنوعیت'});
          root.querySelectorAll('[data-delban]').forEach(function(b){
            b.addEventListener('click',function(){
              UI.confirm('این ممنوعیت برداشته شود؟',function(){API.del('/panel/clubs/'+id+'/bans/'+b.dataset.delban).then(function(){UI.toast('برداشته شد');loadBans();});});
            });
          });
        }).catch(function(){document.getElementById('bans').innerHTML='<div class="empty">امکان نمایش ممنوعیت‌ها نیست</div>';});
      }
      document.getElementById('addBan').addEventListener('click',function(){
        var body=UI.el('<div class="pad-s"><form id="fBan" class="frow">'
          +UI.sel('target_type','نوع هدف','rider',[{v:'rider',l:'سوارکار'},{v:'horse',l:'اسب'}])
          +'<div><label class="lbl req">جستجوی هدف</label><input class="inp" id="bSearch" placeholder="نام یا موبایل…" autocomplete="off"/></div>'
          +UI.inp('reason','دلیل','',null,{full:true})
          +'<input type="hidden" name="target_id"/>'
          +'<div class="full" id="bRes"><span class="hint">نتیجه‌ای انتخاب نشده است.</span></div>'
          +'<div><label class="lbl">یا شناسه را دستی وارد کنید</label><input class="inp ltr" id="bManual" inputmode="numeric"/></div>'
          +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ثبت ممنوعیت</button></div></div>');
        UI.modal('ممنوعیت جدید',body);
        var f=body.querySelector('#fBan');
        var resHost=body.querySelector('#bRes');
        function pick(rid,label){
          f.querySelector('[name=target_id]').value=rid;
          body.querySelector('#bManual').value=rid;
          resHost.innerHTML='<span class="badge b-ok">انتخاب شد: '+UI.esc(label||'')+' #'+UI.faNum(rid)+'</span>';
        }
        function runSearch(){
          var term=body.querySelector('#bSearch').value.trim();
          if(term.length<2){resHost.innerHTML='<span class="hint">حداقل ۲ نویسه وارد کنید.</span>';return;}
          var type=f.querySelector('[name=target_type]').value;
          API.get(type==='rider'?'/panel/users':'/panel/horses',{search:term,per_page:10}).then(function(d){
            var rows=UI.rows(d);
            if(!rows.length){resHost.innerHTML='<span class="hint">نتیجه‌ای یافت نشد — شناسه را دستی وارد کنید.</span>';return;}
            resHost.innerHTML='<div class="row gap1 wrap">'+rows.map(function(r){
              var label=type==='rider'?firstLast(r):(r.name||'');
              return '<button type="button" class="btn btn-g btn-sm" data-pick="'+r.id+'" data-picklabel="'+UI.esc(label)+'">'+UI.esc(label)+' #'+UI.faNum(r.id)+'</button>';
            }).join('')+'</div>';
            resHost.querySelectorAll('[data-pick]').forEach(function(b){
              b.addEventListener('click',function(){pick(b.dataset.pick,b.dataset.picklabel);});
            });
          }).catch(function(){resHost.innerHTML='<span class="hint">جستجو در دسترس نیست — شناسه را دستی وارد کنید.</span>';});
        }
        var st=f.querySelector('[name=target_type]');
        st.addEventListener('change',function(){resHost.innerHTML='<span class="hint">نتیجه‌ای انتخاب نشده است.</span>';});
        var t;body.querySelector('#bSearch').addEventListener('input',function(){clearTimeout(t);t=setTimeout(runSearch,320);});
        body.querySelector('#bManual').addEventListener('input',function(){f.querySelector('[name=target_id]').value=this.value.replace(/[^0-9]/g,'');});
        body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
        body.querySelector('[data-save]').addEventListener('click',function(){
          var v=UI.formValues(f);
          if(!v.target_id||!Number(v.target_id)){UI.toast('ابتدا هدف را انتخاب یا شناسه را وارد کنید','w');return;}
          v.target_id=+v.target_id;
          v.reason=v.reason||'';
          API.post('/panel/clubs/'+id+'/bans',v).then(function(){UI.closeDialog();UI.toast('ثبت شد');loadBans();}).catch(function(err){UI.showErrors(f,err.errors);});
        });
      });
    });
  }
};

/* ============================== RADES ============================== */
Pages.rades={
  title:'رده‌ها',icon:'i-list',sec:'عملیات',
  render:function(root,ctx){
    root.innerHTML=pageHead('رده‌ها','کلاس‌های استاندارد مسابقه (قابل استفاده در همه مسابقات).',
      (['admin','manager'].includes(ctx.user.role)?'<button class="btn btn-p btn-sm" id="newR">'+UI.ic('i-plus')+' رده جدید</button>':''))
      +'<div class="mb3" id="fbarHost"></div><div id="tbl"><div class="spin"></div></div>';
    var filters=App.seed({search:'',status:''});
    var fbar=UI.filterBar({
      filters:filters,
      searchLabel:'جستجوی نام یا توضیح رده…',
      selects:[{key:'status',label:'وضعیت',options:[{v:'',l:'همه'},{v:'active',l:'فقط فعال'},{v:'closed',l:'فقط غیرفعال'}]}],
      onChange:function(){App.setQuery(filters);paint();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    var allRows=[];
    var nb=root.querySelector('#newR');
    if(nb){nb.addEventListener('click',function(){radeDlg(null);});}
    function paint(){
      var q=String(filters.search||'').toLowerCase();
      var rows=allRows.filter(function(r){
        var okText=!q||String(r.name||'').toLowerCase().indexOf(q)>-1||String(r.description||'').toLowerCase().indexOf(q)>-1;
        var okStat=!filters.status||(filters.status==='active'?!!Number(r.is_active):!Number(r.is_active));
        return okText&&okStat;
      });
      fbar.setCount(UI.faNum(rows.length)+' از '+UI.faNum(allRows.length)+' رده');
      root.querySelector('#tbl').innerHTML=UI.table([
          {label:'رده',render:function(r){return '<div class="b i13">'+UI.esc(r.name)+'</div>'+(r.description?'<div class="i11 mut">'+UI.esc(r.description)+'</div>':'');}},
          {label:'سن',render:function(r){return r.age_min||r.age_max?(UI.faNum(r.age_min||0)+'–'+UI.faNum(r.age_max||0)):'—';}},
          {label:'ترتیب',key:'sort_order'},
          {label:'فعال',render:function(r){return UI.badge(r.is_active?'active':'closed');}},
          {label:'عملیات',render:function(r){return '<div class="row gap1"><button class="btn-i" data-edit="'+r.id+'">'+UI.ic('i-edit')+'</button>'+(['admin','manager'].includes(ctx.user.role)?'<button class="btn-i" data-del="'+r.id+'">'+UI.ic('i-trash')+'</button>':'')+'</div>';}}
        ],rows,{emptyText:'رده‌ای ثبت نشده'});
      root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(){API.get('/panel/rades/'+b.dataset.edit).then(radeDlg);});});
      root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(){UI.confirm('رده حذف شود؟',function(){API.del('/panel/rades/'+b.dataset.del).then(load);});});});
    }
    function load(){
      API.get('/panel/rades').then(function(rows){
        allRows=UI.rows(rows);
        paint();
      });
    }
    function radeDlg(r){
      var isNew=!r;
      r=r||{};
      var body=UI.el('<div class="pad-s"><form id="fR" class="frow">'
        +UI.inp('name','نام رده',r.name,null,{req:true,full:true})
        +UI.ta('description','توضیحات',r.description,{full:true})
        +UI.inp('age_min','حداقل سن',r.age_min,'number')
        +UI.inp('age_max','حداکثر سن',r.age_max,'number')
        +UI.inp('sort_order','ترتیب نمایش',r.sort_order,'number')
        +'<label class="row gap2 i13 mt2"><input type="checkbox" name="age_enforced" '+(isNew?1:Number(r.age_enforced)?'checked':'')+'/> اعمال محدودیت سنی در ثبت‌نام</label>'
        +'<label class="row gap2 i13 mt2"><input type="checkbox" name="is_active" '+(isNew||Number(r.is_active)?'checked':'')+'/> فعال باشد</label>'
        +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>');
      UI.modal(isNew?'رده جدید':'ویرایش رده',body);
      body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
      body.querySelector('[data-save]').addEventListener('click',function(){
        var f=body.querySelector('#fR');
        var v=UI.formValues(f);
        ['age_min','age_max','sort_order'].forEach(function(k){if(v[k]!==''){v[k]=+v[k];}else{delete v[k];}});
        (isNew?API.post('/panel/rades',v):API.put('/panel/rades/'+r.id,v))
          .then(function(){UI.closeDialog();UI.toast('ذخیره شد');load();})
          .catch(function(err){UI.showErrors(f,err.errors);});
      });
    }
    load();
  }
};

/* ============================== NOTIFICATIONS ============================== */
Pages.notifications={
  title:'اعلان‌ها',icon:'i-bell',sec:'حساب',
  render:function(root){
    root.innerHTML=pageHead('اعلان‌ها','','<button class="btn btn-g btn-sm" id="readAll">خواندن همه</button>')
      +'<div class="mb3" id="fbarHost"></div><div id="tbl"><div class="spin"></div></div>';
    var nFilters=App.seed({search:'',status:''});
    var nbar=UI.filterBar({
      filters:nFilters,
      searchLabel:'جستجوی متن اعلان…',
      selects:[{key:'status',label:'وضعیت',options:[{v:'',l:'همه'},{v:'unread',l:'خوانده‌نشده'},{v:'read',l:'خوانده‌شده'}]}],
      onChange:function(){App.setQuery(nFilters);paint();}
    });
    root.querySelector('#fbarHost').appendChild(nbar);
    var allNotes=[];
    load();
    document.getElementById('readAll').addEventListener('click',function(){
      API.post('/panel/notifications/read-all',{}).then(function(){if(App.refreshBell){App.refreshBell();}load();});
    });
    function hashFor(link){
      var m=String(link||'').match(/^\/panel\/([a-z-]+)(?:\/(\d+))?/);
      if(!m){return null;}
      var map={'payment-orders':'payment-orders',messages:'messages',reports:'reports',users:'users',clubs:'clubs',horses:'horses',competitions:'competitions',signups:'signups','horse-shares':'horse-shares','sms-log':'sms-log'};
      return '/'+(map[m[1]]||m[1])+(m[2]?'/'+m[2]:'');
    }
    function load(){
      API.get('/panel/notifications').then(function(d){
        allNotes=UI.rows(d);
        paint();
      });
    }
    function paint(){
      var q=String(nFilters.search||'').toLowerCase();
      var list=allNotes.filter(function(n){
        var okText=!q||String(n.title||'').toLowerCase().indexOf(q)>-1||String(n.body||'').toLowerCase().indexOf(q)>-1;
        var unread=!Number(n.is_read);
        var okStat=!nFilters.status||(nFilters.status==='unread'?unread:!unread);
        return okText&&okStat;
      });
      nbar.setCount(UI.faNum(list.length)+' از '+UI.faNum(allNotes.length)+' اعلان');
      root.querySelector('#tbl').innerHTML=list.map(function(n){
          return '<a class="panel pad-s lift mb2" style="display:block;'+(Number(n.is_read)?'':'border-inline-start:3px solid var(--brand)')+'" href="#" data-nid="'+n.id+'" data-link="'+UI.esc(n.link||'')+'">'
            +'<div class="row jb gap2"><div class="g1"><div class="b i13">'+UI.esc(n.title)+'</div><div class="i12 mut mt1">'+UI.esc(n.body||'')+'</div></div>'
            +'<div class="col jend i11 mut">'+I18N.fmtDateTime(n.created_at)+(Number(n.is_read)?'':' <span class="badge b-info">جدید</span>')+'</div></div></a>';
        }).join('')||'<div class="panel"><div class="empty">اعلانی ندارید</div></div>';
      root.querySelectorAll('#tbl a[data-nid]').forEach(function(a){
          a.addEventListener('click',function(e){
            e.preventDefault();
            API.post('/panel/notifications/'+a.dataset.nid+'/read',{}).then(function(){if(App.refreshBell){App.refreshBell();}}).catch(function(){});
            var h=hashFor(a.dataset.link);
            if(h&&h!==App.path){App.go(h);}
            else{load();}
          });
        });
    }
  }
};

/* ============================== MESSAGES (broadcast) ============================== */
Pages.messages={
  title:'پیام‌ها',icon:'i-mail',sec:'حساب',
  render:function(root,ctx){
    var isStaff=['admin','manager'].includes(ctx.user.role);
    root.innerHTML=pageHead('پیام‌های همگانی','',
      (isStaff?'<button class="btn btn-p btn-sm" id="compose">'+UI.ic('i-send')+' پیام جدید</button>':''))
      +'<div id="tbl"><div class="spin"></div></div>';
    var c=root.querySelector('#compose');        if(c){c.addEventListener('click',compose);}
    function load(){
      API.get('/panel/messages').then(function(d){
        var rows=UI.rows(d);
        root.querySelector('#tbl').innerHTML=rows.map(function(m){
          return '<a class="panel pad-s lift mb2" style="display:block" href="/messages/'+m.id+'"><div class="row jb gap2"><div class="g1"><div class="b i13">'+UI.esc(m.subject)+'</div>'+(isStaff?'<div class="i11 mut mt1">گیرندگان: '+UI.faNum(m.recipient_count||0)+'</div>':'<div class="i11 mut mt1">'+I18N.fmtDateTime(m.created_at)+'</div>')+'</div>'+I18N.fmtDate(m.created_at)+'</div></a>';
        }).join('')||'<div class="panel"><div class="empty">پیامی نیست</div></div>';
      });
    }
    function compose(){
      API.get('/panel/competitions').then(function(comps){
        var compOpts=[{v:'',l:'— بدون مسابقه —'}].concat(UI.rows(comps).map(function(c){return {v:c.id,l:c.title};}));
        var body=UI.el('<div class="pad-s"><form id="fMsg" class="frow">'
          +UI.inp('subject','موضوع','',null,{req:true,full:true})
          +UI.ta('body','متن پیام','',null,{req:true,full:true})
          +UI.sel('scope','دامنه','global',[{v:'global',l:'همه کاربران'},{v:'role',l:'نقش مشخص'},{v:'competition',l:'سوارکاران یک مسابقه'},{v:'users',l:'کاربران منتخب'}])
          +'<div id="scopeRole" style="display:none" class="full">'+UI.sel('role','نقش هدف','rider',[{v:'rider',l:'سوارکاران'},{v:'manager',l:'مدیران'},{v:'club',l:'باشگاه‌ها'}])+'</div>'
          +'<div id="scopeComp" style="display:none" class="full">'+UI.sel('competition_id','مسابقه','',compOpts)+'</div>'
          +'<div id="scopeUsers" style="display:none" class="full"><label class="lbl">شناسه‌های کاربر (با کاما)</label><input class="inp" name="user_ids"/></div>'
          +'<label class="full row gap2 i13"><input type="checkbox" name="via_sms"/> ارسال پیامک نیز</label>'
          +'</form><div class="row jend mt3"><button class="btn btn-p" data-send>ارسال</button></div></div>');
        UI.modal('پیام همگانی',body);
        var f=body.querySelector('#fMsg');
        f.querySelector('[name=scope]').addEventListener('change',function(){
          var s=f.querySelector('[name=scope]').value;
          body.querySelector('#scopeRole').style.display=s==='role'?'':'none';
          body.querySelector('#scopeComp').style.display=s==='competition'?'':'none';
          body.querySelector('#scopeUsers').style.display=s==='users'?'':'none';
        });
        body.querySelector('[data-send]').addEventListener('click',function(){
          var v=UI.formValues(f);
          v.user_ids=(v.user_ids||'').split(',').map(function(x){return +x.trim();}).filter(Boolean);
          if(v.scope==='role'){v.role=f.querySelector('[name=role]').value;}
          API.post('/panel/messages',v).then(function(d){UI.closeDialog();UI.toast('به '+UI.faNum(d.recipients||0)+' کاربر ارسال شد');load();})
            .catch(function(err){UI.showErrors(f,err.errors);});
        });
      });
    }
  }
};
Pages.messageDetail={
  title:'پیام',icon:'i-mail',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    API.get('/panel/messages/'+id).then(function(m){
      root.innerHTML=pageHead(m.subject,I18N.fmtDateTime(m.created_at),'<a class="btn btn-g btn-sm" href="/messages">'+UI.ic('i-cr')+' بازگشت</a>')
        +'<div class="panel pad" style="line-height:2">'+UI.esc(m.body)+'</div>';
      API.post('/panel/messages/'+id+'/read',{}).catch(function(){});
    });
  }
};

/* ============================== helpers ============================== */
function debounce(fn,ms){
  var t;
  return function(){clearTimeout(t);var a=arguments,self=this;t=setTimeout(function(){fn.apply(self,a);},ms);};
}

window.Pages=Object.assign({},window.Pages,Pages);
window.pageHead=pageHead;
window.kpiCard=kpiCard;
window.stat=stat;
window.section=section;
window.detail=detail;
window.bindTableNav=bindTableNav;
window.roleLabel=roleLabel;
window.debounce=debounce;
window.firstLast=firstLast;
})();
