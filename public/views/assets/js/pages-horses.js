/* ============================================================================
   Horse registry pages: list (role-scoped filters), detail (images, shares,
   transfers, history), CRUD dialogs, CSV import/export, shares inbox.
   ============================================================================ */
(function(){
'use strict';

var GENDERS=[{v:'مادیان',l:'مادیان'},{v:'نریان',l:'نریان'},{v:'اخته',l:'اخته'}];
var STATUSES=[{v:'active',l:'فعال'},{v:'soft_deleted',l:'حذف‌شده'},{v:'sold_to_non_rider',l:'فروش به غیرسوارکار'}];

/* ---------------------------------------------------------------- helpers */
function isStaff(ctx){return ['admin','manager'].indexOf(ctx.user.role)>-1;}
function mediaUrl(im){return '/media/'+(im&&im.id);}

function dateInput(name,label,val){
  return '<label class="lbl">'+UI.esc(label)+'</label>'
    +'<input class="inp" name="'+name+'" data-date readonly value="'+UI.esc(val?I18N.fmtDate(val):'')+'"'
    +(val?' data-iso="'+UI.esc(val)+'"':'')+'/>';
}

/* Lookups (genders/colors/races) come from the database so Managers can
   maintain them; the form offers searchable, pre-filled selects. */
var lookups={genders:[],colors:[],races:[]};
function loadLookups(){
  /* Lookup lists are optional metadata: an empty or failing response must
     never take the page down, so every field is coerced to an array. */
  return API.get('/panel/lookups').then(function(d){
    var src=d||{};
    var opt=function(v){return (Array.isArray(v)?v:[]).map(toOpt);};
    lookups={
      genders:opt(src.genders),
      colors:opt(src.colors),
      races:opt(src.races)
    };
    return lookups;
  }).catch(function(){return lookups;});
}
function toOpt(r){return {v:r&&r.name,l:(r&&r.name)||'—'};}
function labelOf(list,val){
  var hit=(Array.isArray(list)?list:[]).filter(function(o){return String(o.v)===String(val);})[0];
  return hit?hit.l:(val||'');
}

function horseFormHtml(h,staff,owners){
  var isNew=!h;
  h=h||{};
  var ownerLoader=function(term,cb){
    var q={role:'rider',per_page:20};
    if(term){q.search=term;}
    API.get('/panel/users',q).then(function(d){
      cb((UI.rows(d)).map(function(r){return {v:r.id,l:firstLast(r)};}));
    }).catch(function(){cb([]);});
  };
  return '<form id="fH" class="frow">'
    +(staff?UI.pickField({name:'owner_user_id',label:'مالک',value:h.owner_user_id||'',
      selectedLabel:labelOf((owners||[]).map(function(r){return {v:r.id,l:firstLast(r)};}),h.owner_user_id),
      load:ownerLoader,placeholder:'جستجوی سوارکار…',req:true}):'')
    +UI.inp('name','نام اسب',h.name,null,{req:true})
    +UI.inp('name_en','نام لاتین',h.name_en)
    +UI.pickField({name:'gender',label:'جنسیت',value:h.gender||'',
      selectedLabel:labelOf(lookups.genders,h.gender),
      options:[{v:'',l:'— انتخاب کنید —'}].concat(lookups.genders),placeholder:'جستجوی جنسیت…'})
    +UI.pickField({name:'race',label:'نژاد',value:h.race||'',
      selectedLabel:labelOf(lookups.races,h.race),
      options:[{v:'',l:'— انتخاب کنید —'}].concat(lookups.races),placeholder:'جستجوی نژاد…'})
    +UI.pickField({name:'color',label:'رنگ',value:h.color||'',
      selectedLabel:labelOf(lookups.colors,h.color),
      options:[{v:'',l:'— انتخاب کنید —'}].concat(lookups.colors),placeholder:'جستجوی رنگ…'})
    +'<div>'+dateInput('birth_date','تاریخ تولد (شمسی)',h.birth_date)+'</div>'
    +UI.inp('ghamari_birthday','تولد قمری',h.ghamari_birthday)
    +UI.inp('microchip_number','ریزتراشه (۱۵ رقم)',h.microchip_number,{attrs:' inputmode="numeric" maxlength="15"'})
    +UI.inp('ueln','UELN',h.ueln)
    +UI.inp('registration_no','شماره ثبت',h.registration_no)
    +UI.inp('sire_name','پدر',h.sire_name)
    +UI.inp('dam_name','مادر',h.dam_name)
    +UI.inp('breeder','پرورش‌دهنده',h.breeder)
    +UI.ta('notes','یادداشت',h.notes,{full:true})
    +(isNew?'':UI.sel('status','وضعیت',h.status,STATUSES,{full:true}))
    +'</form>';
}

function openHorseDialog(h,ctx,onSaved){
  var isNew=!h;
  var staff=isStaff(ctx);
  var start=Promise.all([
    loadLookups(),
    staff?API.get('/panel/users',{role:'rider',per_page:250}).catch(function(){return {rows:[]};}):Promise.resolve({rows:[]})
  ]);
  start.then(function(rs){
    var owners=rs[1].rows||[];
    var body=UI.el('<div class="pad-s">'+horseFormHtml(h,staff,owners)
      +'<div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>');
    UI.modal(isNew?'اسب جدید':'ویرایش اسب',body);
    UI.initPicks(body,{});
    UI.initPickers(body);
    body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
    body.querySelector('[data-save]').addEventListener('click',function(){
      var f=body.querySelector('#fH');
      var v=UI.formValues(f);
      var dateInp=f.querySelector('[name=birth_date]');
      if(dateInp&&dateInp.dataset.iso){v.birth_date=dateInp.dataset.iso;}else{delete v.birth_date;}
      if(!staff){delete v.owner_user_id;}
      (isNew?API.post('/panel/horses',v):API.put('/panel/horses/'+h.id,v))
        .then(function(){UI.closeDialog();UI.toast('ذخیره شد');if(onSaved){onSaved();}})
        .catch(function(err){UI.showErrors(f,err.errors);});
    });
  });
}

var Pages={};

/* ---------------------------------------------------------------- list */
/* Shared column definitions so the client-side sort has access to the same
   keys the renderer uses. */
function horseCols(){
  return [
    {label:'اسب',sortKey:'name',sortValue:function(r){return r.name;},render:function(r){return '<div class="row gap2"><span class="av" style="width:30px;height:30px">'+UI.ic('i-horse')+'</span><div><div class="b i13">'+UI.esc(r.name)+'</div>'+(r.race?'<div class="i11 mut">'+UI.esc(r.race)+'</div>':'')+'</div></div>';}},
    {label:'جنسیت',key:'gender',sortKey:'gender'},
    {label:'نژاد',key:'race',sortKey:'race'},
    {label:'رنگ',key:'color',sortKey:'color'},
    {label:'ریزتراشه',sortKey:'microchip_number',render:function(r){return '<span class="ltr i12">'+UI.esc(r.microchip_number||'—')+'</span>';}},
    {label:'مالک',key:'owner_name',sortKey:'owner_name'},
    {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status)+(Number(r.transfer_locked)?' <span class="badge b-warn">در انتقال</span>':'');}}
  ];
}
Pages.horses={
  title:'اسب‌ها',icon:'i-horse',sec:'عملیات',
  render:function(root,ctx){
    var filters=App.seed({status:'',search:'',microchip:'',page:1});
    var staff=isStaff(ctx);
    root.innerHTML=pageHead('اسب‌ها','دفتر نژاد اسب‌های ثبت‌شده.',
      (staff?'<div class="row gap1" id="bkBar" style="display:none">'
      +'<button class="btn btn-d btn-sm" id="bkDel">حذف گروهی</button>'
      +'<button class="btn btn-s btn-sm" id="bkRes">بازگردانی گروهی</button></div>':'')
      +'<div class="row gap2 wrap">'
      +'<a class="btn btn-g btn-sm" href="/panel/horses/export-template">'+UI.ic('i-dl')+' قالب CSV</a>'
      +'<a class="btn btn-g btn-sm" href="/lookups">'+UI.ic('i-list')+' نژادها، رنگ‌ها و جنسیت</a>'
      +'<button class="btn btn-g btn-sm" id="imp">'+UI.ic('i-up')+' ورود CSV</button>'
      +'<button class="btn btn-g btn-sm" id="exp">'+UI.ic('i-dl')+' خروجی CSV</button>'
      +'<button class="btn btn-p btn-sm" id="newH">'+UI.ic('i-plus')+' اسب جدید</button></div>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div class="row gap2 mb3" id="mcRow" style="'+(filters.microchip?'display:flex':'display:none')+'">'
      +'<label class="lbl" for="mcInput" style="margin:0">ریزتراشه</label>'
      +'<input class="inp ltr" id="mcInput" style="max-width:190px" value="'+UI.esc(filters.microchip||'')+'" placeholder="۱۵ رقم"/>'
      +'<button class="btn btn-g btn-sm" id="mcClear">'+UI.ic('i-x')+' حذف فیلتر ریزتراشه</button>'
      +'</div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'horses',
      searchLabel:'جستجوی نام اسب…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'}].concat(STATUSES)}
      ],
      onChange:function(){filters.page=1;App.setQuery(filters);load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    root.querySelector('#mcInput').addEventListener('input',debounce(function(){
      filters.microchip=root.querySelector('#mcInput').value.trim();
      filters.page=1;
      App.setQuery(filters);
      load();
    },350));
    root.querySelector('#mcClear').addEventListener('click',function(){
      root.querySelector('#mcInput').value='';
      filters.microchip='';
      filters.page=1;
      App.setQuery(filters);
      load();
    });
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(6)+'</div>'));
    var HCOLS=horseCols();
    root.querySelector('#newH').addEventListener('click',function(){openHorseDialog(null,ctx,load);});
    root.querySelector('#imp').addEventListener('click',importCsv);
    root.querySelector('#exp').addEventListener('click',function(){
      API.download('POST','/panel/horses/export',filters)
        .then(function(r){UI.toast('خروجی ساخته شد: '+r.filename);})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در ساخت خروجی','e');});
    });
    function load(){
      API.get('/panel/horses',filters).then(function(d){
        var rows=UI.sortRows(UI.rows(d),HCOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' اسب');
        var cols=HCOLS.slice();
        if(staff){cols=[{label:'<input type="checkbox" id="selAllH"/>',raw:true,render:function(r){return '<input type="checkbox" class="selrow" data-id="'+r.id+'"/>';}}].concat(cols);}
        cols.push({label:'عملیات',render:function(r){return '<div class="row gap1">'
          +'<button class="btn-i" data-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'
          +(staff&&r.status==='soft_deleted'?'<button class="btn btn-s btn-sm" data-rest="'+r.id+'">بازگردانی</button>':'')
          +'<button class="btn-i" data-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button></div>';}});
        root.querySelector('#tbl').innerHTML=UI.table(cols,rows,{
          rowHref:function(r){return '/horses/'+r.id;},
          pager:UI.pagerHtml(d.total||rows.length,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی اسبی یافت نشد':'اسبی ثبت نشده',
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
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){
          filters.page=+b.dataset.page;
          App.setQuery(filters);
          load();
        });});
        var mcRow=root.querySelector('#mcRow');
        if(mcRow){mcRow.style.display=filters.microchip?'flex':'none';}
        var tbl=root.querySelector('#tbl');
        var selAll=tbl.querySelector('#selAllH');
        if(selAll){selAll.addEventListener('change',function(){tbl.querySelectorAll('.selrow').forEach(function(c){c.checked=selAll.checked;});updateBulk();});}
        tbl.addEventListener('change',updateBulk);
        root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.get('/panel/horses/'+b.dataset.edit).then(function(d){openHorseDialog(d.horse||d,ctx,load);});});});
        root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();UI.confirm('اسب حذف شود؟ (حذف نرم — قابل بازگردانی)',function(){API.del('/panel/horses/'+b.dataset.del).then(function(){load();UI.toast('حذف شد');});});});});
        root.querySelectorAll('[data-rest]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();bulkAction('restore',[+b.dataset.rest]);});});
      });
    }
    function selectedIds(){
      var tbl=root.querySelector('#tbl');
      return tbl?Array.prototype.map.call(tbl.querySelectorAll('.selrow:checked'),function(c){return +c.dataset.id;}):[];
    }
    function updateBulk(){
      var bar=document.getElementById('bkBar');
      if(bar){bar.style.display=root.querySelectorAll('#tbl .selrow:checked').length?'':'none';}
    }
    function bulkAction(action,ids){
      ids=ids||selectedIds();
      if(!ids.length){UI.toast('ابتدا حداقل یک اسب انتخاب کنید','w');return;}
      var run=function(){API.post('/panel/horses/bulk',{ids:ids,action:action})
        .then(function(d){UI.toast(UI.faNum(d.processed||0)+' مورد پردازش شد');load();})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});};
      if(action==='delete'){UI.confirm('اسب‌های انتخابی حذف نرم می‌شوند (قابل بازگردانی). ادامه؟',run,'حذف');}
      else{run();}
    }
    var bkDel=document.getElementById('bkDel');
    if(bkDel){bkDel.addEventListener('click',function(){bulkAction('delete');});}
    var bkRes=document.getElementById('bkRes');
    if(bkRes){bkRes.addEventListener('click',function(){bulkAction('restore');});}
    function importCsv(){
      var body=UI.el('<div class="pad-s"><p class="i13 mut">فایل CSV را با قالب نمونه انتخاب کنید.</p><input type="file" accept=".csv" class="inp mt3" id="csvFile"/><div class="row jend mt3"><button class="btn btn-p" data-go>ورود</button></div></div>');
      UI.modal('ورود گروهی اسب‌ها',body);
      body.querySelector('[data-go]').addEventListener('click',function(){
        var f=body.querySelector('#csvFile').files[0];
        if(!f){UI.toast('فایلی انتخاب نشده','w');return;}
        API.upload('/panel/horses/import',f).then(function(d){
          UI.closeDialog();
          UI.toast(UI.faNum((d&&d.imported)||0)+' اسب وارد شد'+((d&&d.errors&&d.errors.length)?' — '+UI.faNum(d.errors.length)+' خطا':''));
          load();
        }).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
      });
    }
    load();
  }
};

/* ---------------------------------------------------------------- detail */
Pages.horseDetail={
  title:'اسب',icon:'i-horse',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    API.get('/panel/horses/'+id).then(function(d){
      var h=d.horse||d;
      var images=d.images||[];
      var shares=d.shares||[];
      var transfers=d.transfers||[];
      var history=d.history||[];
      var staff=isStaff(ctx);
      var actions='<div class="row gap2 wrap">'
        +'<a class="btn btn-g btn-sm" href="/horses">'+UI.ic('i-cr')+' بازگشت</a>'
        +'<button class="btn btn-g btn-sm" id="edit">'+UI.ic('i-edit')+' ویرایش</button>'
        +'<button class="btn btn-g btn-sm" id="share">'+UI.ic('i-users')+' اشتراک</button>'
        +'<button class="btn btn-g btn-sm" id="transfer">'+UI.ic('i-swap')+' انتقال مالکیت</button>'
        +'<button class="btn btn-g btn-sm" id="sold">'+UI.ic('i-bag')+' فروش به غیرسوارکار</button>'
        +'<label class="btn btn-g btn-sm">'+UI.ic('i-plus')+' تصویر<input type="file" accept="image/*" hidden id="addImg"/></label>'
        +'</div>';
      root.innerHTML=pageHead(h.name,(h.race||'')+' · '+UI.badge(h.status),actions)
        +'<div class="grid main31 mt4"><div>'
        +'<div class="panel pad">'+detail([
          ['مالک',h.owner_name||('#'+UI.faNum(h.owner_user_id))],
          ['جنسیت',h.gender],['نژاد',h.race],['رنگ',h.color],
          ['تولد',h.birth_date?I18N.fmtDate(h.birth_date):'—'],
          ['ریزتراشه','<span class="ltr">'+UI.esc(h.microchip_number||'—')+'</span>'],
          ['UELN','<span class="ltr">'+UI.esc(h.ueln||'—')+'</span>'],
          ['شماره ثبت',h.registration_no],
          ['پدر',h.sire_name],['مادر',h.dam_name],['پرورش‌دهنده',h.breeder],
          ['توضیحات',h.notes]
        ])+'</div>'
        +section('تصاویر ('+UI.faNum(images.length)+')','<div class="imgrow">'+images.map(function(im){
          return '<div class="imgc"><img src="'+UI.esc(mediaUrl(im))+'" alt=""/><button class="del" data-delimg="'+im.id+'">'+UI.ic('i-x')+'</button></div>';
        }).join('')+(images.length?'':'<div class="empty" style="padding:20px">تصویری نیست</div>')+'</div>')
        +section('سابقه مسابقات',historyTable(history))
        +section('سوابق بهداشتی و واکسیناسیون','<div id="healthHost"></div>',
          staff?'<button class="btn btn-s btn-sm" data-hadd>'+UI.ic('i-plus')+' رکورد جدید</button>':'')
        +'</div><div>'
        +section('اشتراک‌ها',shareList(shares))
        +section('انتقال‌ها',transferList(transfers))
        +section('کد اشتراک‌گذاری','<div class="row gap2"><span class="kbd ltr" style="font-size:15px">'+UI.esc(h.share_code||'—')+'</span><span class="i11 mut">این کد را به سوارکار مقصد بدهید.</span></div>')
        +'</div></div>';

      function refresh(){Pages.horseDetail.render(root,ctx,id);}
      document.getElementById('edit').addEventListener('click',function(){openHorseDialog(h,ctx,refresh);});
      document.getElementById('addImg').addEventListener('change',function(e){
        var f=e.target.files[0];
        if(!f){return;}
        API.upload('/panel/horses/'+id+'/images',f).then(function(){UI.toast('تصویر افزوده شد');refresh();})
          .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در بارگذاری','e');});
      });
      root.querySelectorAll('[data-delimg]').forEach(function(b){
        b.addEventListener('click',function(){API.del('/panel/horses/'+id+'/images/'+b.dataset.delimg).then(refresh);});
      });
      root.querySelectorAll('[data-revshare]').forEach(function(b){
        b.addEventListener('click',function(){UI.confirm('این اشتراک لغو شود؟',function(){API.del('/panel/horses/'+id+'/share/'+b.dataset.revshare).then(refresh);});});
      });
      document.getElementById('sold').addEventListener('click',function(){
        UI.confirm('اسب به‌عنوان فروش‌رفته به غیرسوارکار علامت‌گذاری شود؟',function(){
          API.post('/panel/horses/'+id+'/sold-to-non-rider',{}).then(refresh);
        });
      });
      document.getElementById('share').addEventListener('click',function(){
        var body=UI.el('<div class="pad-s"><label class="lbl">کد اشتراک سوارکار مقصد</label><input class="inp ltr" id="sc"/><p class="hint">کد ۶ رقمی موجود در پروفایل سوارکار.</p><div class="row jend mt3"><button class="btn btn-p" data-go>به اشتراک‌گذاری</button></div></div>');
        UI.modal('اشتراک‌گذاری اسب',body);
        body.querySelector('[data-go]').addEventListener('click',function(){
          API.post('/panel/horses/'+id+'/share',{share_code:body.querySelector('#sc').value.trim()})
            .then(function(){UI.closeDialog();UI.toast('اشتراک ثبت شد');refresh();})
            .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
        });
      });
      document.getElementById('transfer').addEventListener('click',function(){openTransferDialog(h,transfers,refresh,ctx);});
    });

    function historyTable(rows){
      return UI.table([
        {label:'مسابقه',key:'competition_title',wrap:true},
        {label:'رده',key:'rade_name'},
        {label:'تاریخ',render:function(r){return I18N.fmtDate(r.created_at);}},
        {label:'مقام',render:function(r){return r.position!=null?UI.faNum(r.position)+(Number(r.is_winner)?' '+UI.ic('i-star'):''):'—';}}
      ],rows||[],{emptyText:'سابقه‌ای ثبت نشده'});
    }
    function shareList(rows){
      return UI.table([
        {label:'سوارکار',render:function(r){return UI.esc(r.recipient_name||('#'+UI.faNum(r.recipient_user_id)));}},
        {label:'وضعیت',render:function(r){return UI.badge(r.status);}},
        {label:'',render:function(r){return r.status!=='revoked'?'<button class="btn btn-d btn-sm" data-revshare="'+r.id+'">لغو</button>':'';}}
      ],rows||[],{emptyText:'اشتراکی نیست'});
    }
    function transferList(rows){
      return UI.table([
        {label:'کد',render:function(r){return '<span class="kbd ltr">'+UI.esc(r.code||'—')+'</span>';}},
        {label:'وضعیت',render:function(r){return UI.badge(r.status);}},
        {label:'انقضا',render:function(r){return r.expires_at?I18N.fmtDateTime(r.expires_at):'—';}}
      ],rows||[],{emptyText:'انتقالی نیست'});
    }
  }
};

function openTransferDialog(h,transfers,refresh){
  var pending=(transfers||[]).filter(function(x){return x.status==='pending';})[0];
  var body=UI.el('<div class="pad-s">'
    +(pending?'<div class="row gap2 mb3"><span class="kbd ltr">'+UI.esc(pending.code||'')+'</span><span class="i12 mut">اعتبار تا '+I18N.fmtDateTime(pending.expires_at)+'</span></div>':'<p class="i12 mut mb3">انتقال فعالی وجود ندارد.</p>')
    +'<div class="row gap2 wrap mb3">'
    +'<button class="btn btn-p btn-sm" data-t="initiate">شروع/قفل انتقال</button>'
    +'<button class="btn btn-g btn-sm" data-t="accept">پذیرش (مالک)</button>'
    +'<button class="btn btn-g btn-sm" data-t="reject">رد (مالک)</button>'
    +'<button class="btn btn-d btn-sm" data-t="cancel">لغو</button>'
    +'</div>'
    +'<label class="lbl">مطالبه با کد (سوارکار مقصد)</label>'
    +'<div class="row gap2"><input class="inp ltr" id="tcode" placeholder="کد انتقال"/><button class="btn btn-s btn-sm" data-t="claim">مطالبه</button></div>'
    +'</div>');
  UI.modal('انتقال مالکیت اسب',body);
  body.querySelectorAll('[data-t]').forEach(function(b){
    b.addEventListener('click',function(){
      var a=b.dataset.t;
      var p;
      if(a==='claim'){p=API.post('/panel/horses/'+h.id+'/transfer/claim',{code:body.querySelector('#tcode').value.trim()});}
      else{p=API.post('/panel/horses/'+h.id+'/transfer/'+a,{});}
      p.then(function(){UI.closeDialog();UI.toast('انجام شد');refresh();})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
    });
  });
}

/* ---------------------------------------------------------------- shares inbox */
Pages.horseShares={
  title:'صندوق اشتراک اسب',icon:'i-swap',sec:'عملیات',
  render:function(root){
    root.innerHTML=pageHead('درخواست‌های اشتراک اسب','اسب‌هایی که مالکان برای شما به اشتراک گذاشته‌اند.')+'<div id="tbl"><div class="spin"></div></div>';
    load();
    function load(){
      API.get('/panel/horse-shares').then(function(rows){
        rows=UI.rows(rows);
        root.querySelector('#tbl').innerHTML=rows.map(function(s){
          var pending=s.status==='pending';
          return '<div class="panel pad-s mb2"><div class="row jb gap3 wrap">'
            +'<div class="row gap3"><span class="av" style="width:38px;height:38px">'+UI.ic('i-horse')+'</span>'
            +'<div><div class="b i13">'+UI.esc(s.horse_name||('اسب #'+UI.faNum(s.horse_id)))+'</div><div class="i11 mut">از: '+UI.esc(s.owner_name||'—')+' · '+I18N.fmtDate(s.created_at)+'</div></div></div>'
            +'<div class="row gap2">'+UI.badge(s.status)
            +(pending?'<button class="btn btn-p btn-sm" data-acc="'+s.id+'">پذیرش</button><button class="btn btn-d btn-sm" data-rej="'+s.id+'">رد</button>':'')
            +'</div></div></div>';
        }).join('')||'<div class="panel"><div class="empty">درخواستی ندارید</div></div>';
        root.querySelectorAll('[data-acc]').forEach(function(b){b.addEventListener('click',function(){API.post('/panel/horse-shares/'+b.dataset.acc+'/accept',{}).then(load);});});
        root.querySelectorAll('[data-rej]').forEach(function(b){b.addEventListener('click',function(){API.post('/panel/horse-shares/'+b.dataset.rej+'/reject',{}).then(load);});});
      });
    }
  }
};

/* ---------------------------------------------------------------- health */
/* Health / vaccination history for one horse. Staff can add, edit and remove
   records; riders see a read-only history of their own horse. */
function healthSection(horseId,staff){
  var host=document.getElementById('healthHost');
  if(!host){return;}
  host.innerHTML='<div class="spin"></div>';
  var types={vaccination:'واکسیناسیون',checkup:'معاینه',treatment:'درمان',injury:'آسیب‌دیدگی',fitness:'گواهی آمادگی'};
  function paint(rows){
    var total=0;
    rows.forEach(function(r){total+=Number(r.cost_irt||0);});
    host.innerHTML=rows.length
      ? '<div class="row jb gap2 mb3"><span class="i12 mut">'+UI.faNum(rows.length)+' رکورد'
        +(total?' · هزینه کل: '+UI.money(total):'')+'</span></div>'
        +UI.table([
          {label:'نوع',sortKey:'record_type',render:function(r){return '<span class="badge b-mut">'+UI.esc(types[r.record_type]||r.record_type)+'</span>';}},
          {label:'عنوان',key:'title',sortKey:'title',wrap:true},
          {label:'تاریخ انجام',sortKey:'performed_at',render:function(r){return r.performed_at?I18N.fmtDate(r.performed_at):'—';}},
          {label:'موعد بعدی',sortKey:'next_due_at',render:function(r){
            if(!r.next_due_at){return '—';}
            var overdue=Date.parse(r.next_due_at)<Date.now();
            return '<span class="badge '+(overdue?'b-bad':'b-ok')+'">'+I18N.fmtDate(r.next_due_at)+(overdue?' (گذشته)':'')+'</span>';
          }},
          {label:'دامپزشک',key:'vet_name'},
          {label:'هزینه',sortKey:'cost_irt',sortValue:function(r){return Number(r.cost_irt);},render:function(r){return r.cost_irt?UI.money(r.cost_irt):'—';}},
          {label:staff?'':' ',render:function(r){return staff
            ?'<div class="row gap1"><button class="btn-i" data-hedit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'
              +'<button class="btn-i" data-hdel="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button></div>'
            :'';}}
        ],rows,{emptyText:'رکورد بهداشتی ثبت نشده'})
      : '<div class="empty">'+UI.ic('i-shield')+'<div>رکورد بهداشتی ثبت نشده</div></div>';

    host.querySelectorAll('[data-hedit]').forEach(function(b){
      b.addEventListener('click',function(){
        healthDialog(horseId,rows.filter(function(x){return String(x.id)===b.dataset.hedit;})[0],types,reload);
      });
    });
    host.querySelectorAll('[data-hdel]').forEach(function(b){
      b.addEventListener('click',function(){
        UI.confirm('این رکورد حذف شود؟',function(){
          API.del('/panel/horses/health/'+b.dataset.hdel).then(function(){UI.toast('حذف شد');reload();});
        },'حذف');
      });
    });
  }
  function reload(){
    API.get('/panel/horses/'+horseId+'/health').then(function(d){paintUI.rows(d);});
  }
  reload();
  host.addEventListener('click',function(e){
    if(e.target.closest('[data-hadd]')){healthDialog(horseId,null,types,reload);}
  });
  return host;
}
function healthDialog(horseId,rec,types,reload){
  rec=rec||{};
  var isNew=!rec.id;
  var opts=[{v:'vaccination',l:'واکسیناسیون'},{v:'checkup',l:'معاینه'},{v:'treatment',l:'درمان'},{v:'injury',l:'آسیب‌دیدگی'},{v:'fitness',l:'گواهی آمادگی'}];
  var body=UI.el('<div class="pad-s"><form id="fH" class="frow">'
    +UI.pickField({name:'record_type',label:'نوع رکورد',value:rec.record_type||'vaccination',options:opts})
    +UI.inp('title','عنوان',rec.title,null,{req:true,full:true})
    +'<div><label class="lbl">تاریخ انجام (شمسی)</label><input class="inp" name="performed_at" data-date readonly value="'+UI.esc(rec.performed_at?I18N.fmtDate(rec.performed_at):'')+'"'+(rec.performed_at?' data-iso="'+UI.esc(rec.performed_at)+'"':'')+'/></div>'
    +'<div><label class="lbl">موعد بعدی (شمسی)</label><input class="inp" name="next_due_at" data-date readonly value="'+UI.esc(rec.next_due_at?I18N.fmtDate(rec.next_due_at):'')+'"'+(rec.next_due_at?' data-iso="'+UI.esc(rec.next_due_at)+'"':'')+'/></div>'
    +UI.inp('vet_name','دامپزشک / کلینیک',rec.vet_name)
    +UI.inp('cost_irt','هزینه (تومان)',rec.cost_irt||'',{attrs:' inputmode="numeric"'})
    +UI.ta('notes','یادداشت',rec.notes,{full:true})
    +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button>'
    +'<button class="btn btn-p" data-save>ذخیره</button></div></div>');
  UI.modal(isNew?'رکورد بهداشتی جدید':'ویرایش رکورد',body);
  UI.initPicks(body,{});
  UI.initPickers(body);
  body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
  body.querySelector('[data-save]').addEventListener('click',function(){
    var f=body.querySelector('#fH');
    var v=UI.formValues(f);
    ['performed_at','next_due_at'].forEach(function(k){
      var inp=f.querySelector('[name='+k+']');
      v[k]=inp&&inp.dataset.iso?inp.dataset.iso:null;
    });
    if(v.cost_irt===''){delete v.cost_irt;}
    var close=UI.busy('در حال ذخیره…');
    var p=isNew?API.post('/panel/horses/'+horseId+'/health',v):API.put('/panel/horses/health/'+rec.id,v);
    p.then(function(){close();UI.closeDialog();UI.toast('ذخیره شد');reload();})
      .catch(function(err){close();UI.showErrors(f,err.errors);});
  });
}

/* ---------------------------------------------------------------- lookups */
/* Genders / colours / races are controlled vocabulary stored in the database.
   Managers and Admins maintain them here; the horse forms read the same lists. */
var LOOKUP_TYPES=[
  {type:'genders',label:'جنسیت‌ها',singular:'جنسیت',icon:'i-users'},
  {type:'colors',label:'رنگ‌ها',singular:'رنگ',icon:'i-star'},
  {type:'races',label:'نژادها',singular:'نژاد',icon:'i-horse'}
];

Pages.lookups={
  title:'نژادها، رنگ‌ها و جنسیت',icon:'i-list',sec:'عملیات',roles:['admin','manager'],
  render:function(root,ctx){
    var active=LOOKUP_TYPES[0].type;
    if(App.query&&App.query.tab){active=App.query.tab;}
    var staff=['admin','manager'].indexOf(ctx.user.role)>-1;
    root.innerHTML=pageHead('نژادها، رنگ‌ها و جنسیت',
      'فهرست‌های مرجع اسب‌ها از پایگاه‌داده خوانده می‌شوند و در فرم اسب به‌صورت جستجوپذیر نمایش داده می‌شوند.',
      '<a class="btn btn-g btn-sm" href="/horses">'+UI.ic('i-cr')+' بازگشت به اسب‌ها</a>')
      +'<div class="row gap2 wrap mb3" id="lkTabs"></div>'
      +'<div class="panel pad" id="lkPanel"><div class="spin"></div></div>';
    var tabs=root.querySelector('#lkTabs');
    LOOKUP_TYPES.forEach(function(t){
      var b=document.createElement('button');
      b.className='btn btn-sm '+(t.type===active?'btn-p':'btn-g');
      b.textContent=t.label;
      b.addEventListener('click',function(){
        active=t.type;
        App.setQuery({tab:t.type});
        Array.prototype.forEach.call(tabs.children,function(x){x.className='btn btn-sm btn-g';});
        b.className='btn btn-sm btn-p';
        load();
      });
      tabs.appendChild(b);
    });
    function load(){
      var meta=LOOKUP_TYPES.filter(function(t){return t.type===active;})[0];
      var host=root.querySelector('#lkPanel');
      API.get('/panel/lookups/'+active).then(function(rows){
        rows=UI.rows(rows);
        host.innerHTML='<div class="row jb gap2 wrap mb3">'
          +'<div class="b i14">'+UI.esc(meta.label)+' <span class="mut i12">('+UI.faNum(rows.length)+' مورد)</span></div>'
          +(staff?'<div class="row gap2"><input class="inp" id="lkNew" style="max-width:210px" placeholder="افزودن '+UI.esc(meta.singular)+' جدید…"/>'
            +'<button class="btn btn-p btn-sm" id="lkAdd">'+UI.ic('i-plus')+' افزودن</button></div>':'')
          +'</div><div id="lkTbl"></div>';
        host.querySelector('#lkTbl').innerHTML=UI.table([
          {label:'عنوان',render:function(r){return '<div class="b i13">'+UI.esc(r.name)+'</div>';}},
          {label:'ترتیب',render:function(r){return '<span class="i12 mut">'+UI.faNum(r.sort_order||0)+'</span>';}},
          {label:'وضعیت',render:function(r){return Number(r.is_active)?UI.badge('active'):UI.badge('cancelled');}},
          {label:'عملیات',render:function(r){return staff?
            ('<div class="row gap1">'
            +'<button class="btn-i" data-up="'+r.id+'" title="افزایش ترتیب">'+UI.ic('i-up')+'</button>'
            +'<button class="btn-i" data-down="'+r.id+'" title="کاهش ترتیب">'+UI.ic('i-down')+'</button>'
            +'<button class="btn-i" data-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'
            +'<button class="btn-i" data-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button></div>'):'—';}}
        ],rows,{rowHref:null,emptyText:'موردی ثبت نشده'});

        if(staff){
          var addBtn=host.querySelector('#lkAdd');
          var addInp=host.querySelector('#lkNew');
          function add(){
            var name=(addInp.value||'').trim();
            if(!name){UI.toast('نام را وارد کنید','w');addInp.focus();return;}
            addBtn.disabled=true;
            API.post('/panel/lookups/'+active,{name:name}).then(function(){
              addInp.value='';
              load();
              lookups=[];
              loadLookups();
            }).catch(function(err){
              UI.toast((err.errors&&err.errors[0].message)||'خطا در افزودن','e');
            }).then(function(){addBtn.disabled=false;});
          }
          addBtn.addEventListener('click',add);
          addInp.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();add();}});
        }
        host.querySelectorAll('[data-edit]').forEach(function(b){
          b.addEventListener('click',function(e){
            e.stopPropagation();
            var row=rows.filter(function(x){return String(x.id)===b.dataset.edit;})[0];
            editDialog(meta,row,load);
          });
        });
        host.querySelectorAll('[data-del]').forEach(function(b){
          b.addEventListener('click',function(e){
            e.stopPropagation();
            UI.confirm('این مورد حذف شود؟ اگر در اسب‌ها استفاده شده باشد حذف انجام نمی‌شود.',function(){
              API.del('/panel/lookups/'+active+'/'+b.dataset.del).then(function(){
                load();lookups=[];loadLookups();
              }).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در حذف','e');});
            },'حذف');
          });
        });
        function shift(id,delta){
          var row=rows.filter(function(x){return String(x.id)===String(id);})[0];
          if(!row){return;}
          API.put('/panel/lookups/'+active+'/'+id,{sort_order:Number(row.sort_order||0)+delta}).then(load);
        }
        host.querySelectorAll('[data-up]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();shift(b.dataset.up,1);});});
        host.querySelectorAll('[data-down]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();shift(b.dataset.down,-1);});});
      }).catch(function(err){host.innerHTML='<div class="empty">'+UI.esc((err.message)||'خطا در بارگذاری')+'</div>';});
    }
    load();
  }
};

function editDialog(meta,row,done){
  var body=UI.el('<form id="lkF" class="frow">'
    +UI.inp('name','عنوان',row.name,{req:true})
    +UI.inp('sort_order','ترتیب نمایش',row.sort_order||0,{type:'number'})
    +'<label class="row gap2 mt3"><input type="checkbox" name="is_active"'+(Number(row.is_active)?' checked':'')+'/> فعال باشد</label>'
    +'<div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button>'
    +'<button class="btn btn-p" data-save>ذخیره</button></div></form>');
  UI.modal('ویرایش '+meta.singular,body);
  body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
  body.querySelector('[data-save]').addEventListener('click',function(){
    var f=body.querySelector('#lkF');
    var v=UI.formValues(f);
    v.is_active=f.querySelector('[name=is_active]').checked?1:0;
    API.put('/panel/lookups/'+meta.type+'/'+row.id,v).then(function(){
      UI.closeDialog();
      UI.toast('ذخیره شد');
      done();
      lookups=[];
      loadLookups();
    }).catch(function(err){UI.showErrors(f,err.errors);});
  });
}

window.Pages=Object.assign({},window.Pages,Pages);
})();
