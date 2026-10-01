/* ============================================================================
   Finance pages: payment templates (CRUD), payment orders (verify/refund/
   bulk), ZarinPal reconciliation CSV upload.
   ============================================================================ */
(function(){
'use strict';

var Pages={};

Pages.payments={
  title:'الگوهای پرداخت',icon:'i-wallet',sec:'مالی',
  render:function(root,ctx){
    root.innerHTML=pageHead('الگوهای پرداخت','قیمت‌های استاندارد رده‌ها (IRT).',
      (['admin','manager'].includes(ctx.user.role)?'<button class="btn btn-p btn-sm" id="newP">'+UI.ic('i-plus')+' الگوی جدید</button>':''))
      +'<div id="tbl"><div class="spin"></div></div>';
    var nb=root.querySelector('#newP');
    if(nb){nb.addEventListener('click',function(){dlg(null);});}
    function load(){
      API.get('/panel/payments').then(function(rows){
        rows=UI.rows(rows);
        root.querySelector('#tbl').innerHTML=UI.table([
          {label:'نام',render:function(r){return '<div class="b i13">'+UI.esc(r.name)+'</div>'+(r.description?'<div class="i11 mut">'+UI.esc(r.description)+'</div>':'');}},
          {label:'مبلغ',render:function(r){return UI.money(r.amount_irt);}},
          {label:'فعال',render:function(r){return UI.badge(Number(r.is_active)?'active':'closed');}},
          {label:'عملیات',render:function(r){return '<div class="row gap1"><button class="btn-i" data-edit="'+r.id+'">'+UI.ic('i-edit')+'</button>'+(['admin','manager'].includes(ctx.user.role)?'<button class="btn-i" data-del="'+r.id+'">'+UI.ic('i-trash')+'</button>':'')+'</div>';}}
        ],rows,{emptyText:'الگویی ثبت نشده'});
        root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(){API.get('/panel/payments/'+b.dataset.edit).then(dlg);});});
        root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(){UI.confirm('الگو حذف شود؟',function(){API.del('/panel/payments/'+b.dataset.del).then(load);});});});
      });
    }
    function dlg(p){
      var isNew=!p;
      p=p||{};
      var body=UI.el('<div class="pad-s"><form id="fP" class="frow">'
        +UI.inp('name','نام الگو',p.name,null,{req:true,full:true})
        +UI.ta('description','توضیحات',p.description,{full:true})
        +UI.inp('amount_irt','مبلغ (تومان)',p.amount_irt,'number',{req:true})
        +'<label class="full row gap2 i13"><input type="checkbox" name="is_active" '+(isNew||Number(p.is_active)?'checked':'')+'/> فعال</label>'
        +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-save>ذخیره</button></div></div>');
      UI.modal(isNew?'الگوی پرداخت جدید':'ویرایش الگو',body);
      body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
      body.querySelector('[data-save]').addEventListener('click',function(){
        var f=body.querySelector('#fP');
        var v=UI.formValues(f);
        v.amount_irt=+v.amount_irt||0;
        (isNew?API.post('/panel/payments',v):API.put('/panel/payments/'+p.id,v))
          .then(function(){UI.closeDialog();UI.toast('ذخیره شد');load();})
          .catch(function(err){UI.showErrors(f,err.errors);});
      });
    }
    load();
  }
};

Pages.paymentOrders={
  title:'سفارش‌های پرداخت',icon:'i-wallet',sec:'مالی',
  render:function(root,ctx){
    var filters=App.seed({status:'',page:1,competition_id:'',rider_user_id:'',ref_id:''});
    var staff=['admin','manager'].indexOf(ctx.user.role)>-1;
    root.innerHTML=pageHead('سفارش‌های پرداخت','همه تراکنش‌های ثبت‌نام — تأیید دستی، استرداد و تطبیق.',
      (staff?'<div class="row gap1" id="pbBar" style="display:none">'
      +'<button class="btn btn-g btn-sm" id="pbPending">صف استرداد</button>'
      +'<button class="btn btn-s btn-sm" id="pbDone">استرداد شد</button>'
      +'<button class="btn btn-g btn-sm" id="pbUnmark">لغو استرداد</button></div>':'')
      +(staff?'<div class="row gap2">'
      +'<a class="btn btn-g btn-sm" href="/reconciliation">'+UI.ic('i-swap')+' تطبیق زرین‌پال</a></div>':''))
      +'<div class="mb3" id="fbarHost"></div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'payment-orders',
      searchLabel:'جستجوی شناسه پیگیری زرین‌پال…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه وضعیت‌ها'}].concat(
          ['pending','paid','failed','pending_refund','refunded'].map(function(s){
            return {v:s,l:({pending:'در انتظار',paid:'پرداخت‌شده',failed:'ناموفق',pending_refund:'در انتظار استرداد',refunded:'مستردشده'})[s]};
          }))},
        {key:'competition_id',label:'مسابقه',lazy:UI.spkFromApi('/panel/competitions','title',{per_page:300})},
        {key:'rider_user_id',label:'سوارکار',lazy:UI.spkFromApi('/panel/users',function(u){return firstLast(u)+' ('+u.username+')';},{role:'rider'})}
      ],
      onChange:function(){
        /* map the search box onto the gateway reference filter */
        if(filters.search){filters.ref_id=filters.search;delete filters.search;}else{delete filters.ref_id;}
        filters.page=1;App.setQuery(filters);load();
      }
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    var tblHost=root.appendChild(UI.el('<div id="tbl">'+UI.skeletonTable(7)+'</div>'));
    var OCOLS=[
      {label:'شناسه',key:'id',sortKey:'id',sortValue:function(r){return Number(r.id);},render:function(r){return '<span class="kbd">'+UI.faNum(r.id)+'</span>';}},
      {label:'سوارکار',key:'rider_name',sortKey:'rider_name'},
      {label:'مسابقه',key:'competition_title',sortKey:'competition_title',wrap:true},
      {label:'مبلغ',sortKey:'amount_irt',sortValue:function(r){return Number(r.amount_irt);},render:function(r){return UI.money(r.amount_irt);}},
      {label:'مرجع',key:'ref_id',sortKey:'ref_id',render:function(r){return '<span class="ltr i11">'+UI.esc(r.ref_id||'—')+'</span>';}},
      {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status);}},
      {label:'عملیات',render:function(r){return '<div class="row gap1">'
        +(staff&&r.status==='pending'?'<button class="btn btn-s btn-sm" data-verify="'+r.id+'">تأیید دستی</button>':'')
        +(staff&&r.status==='paid'?'<button class="btn btn-g btn-sm" data-refund="'+r.id+'">استرداد</button>':'')
        +(staff&&r.status==='pending_refund'?'<button class="btn btn-s btn-sm" data-done="'+r.id+'">استرداد شد</button><button class="btn btn-i" data-unmark="'+r.id+'" title="لغو استرداد">'+UI.ic('i-x')+'</button>':'')
        +'</div>';}}
    ];
    function load(){
      API.get('/panel/payment-orders',filters).then(function(d){
        /* Column set is declared once so the client-side sort can use it. */
        var rows=UI.sortRows(UI.rows(d),OCOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' سفارش');
        var pcols=[];
        if(staff){pcols.push({label:'<input type="checkbox" id="selAllP"/>',raw:true,render:function(r){return '<input type="checkbox" class="selrow" data-id="'+r.id+'"/>';}});}
        pcols=pcols.concat(OCOLS);
        root.querySelector('#tbl').innerHTML=UI.table(pcols,rows,{
          rowHref:function(r){return '/payment-orders/'+r.id;},
          pager:UI.pagerHtml(d.total||rows.length,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی سفارشی نیست':'سفارشی نیست',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        bindTableNav(root,function(h){App.go(h);});
        var tbl=root.querySelector('#tbl');
        var selAll=tbl.querySelector('#selAllP');
        if(selAll){selAll.addEventListener('change',function(){tbl.querySelectorAll('.selrow').forEach(function(c){c.checked=selAll.checked;});updateBulk();});}
        tbl.addEventListener('change',updateBulk);
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
        tbl.querySelectorAll('[data-verify]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.post('/panel/payment-orders/'+b.dataset.verify+'/verify',{}).then(function(){UI.toast('تأیید شد');load();});});});
        tbl.querySelectorAll('[data-refund]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.post('/panel/payment-orders/'+b.dataset.refund+'/mark-pending-refund',{}).then(function(){UI.toast('به صف استرداد رفت');load();});});});
        tbl.querySelectorAll('[data-done]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.post('/panel/payment-orders/'+b.dataset.done+'/mark-refunded',{}).then(load);});});
        tbl.querySelectorAll('[data-unmark]').forEach(function(b){b.addEventListener('click',function(e){e.stopPropagation();API.post('/panel/payment-orders/'+b.dataset.unmark+'/unmark-refund',{}).then(load);});});
      });
    }
    function selectedIds(){
      var tbl=root.querySelector('#tbl');
      return tbl?Array.prototype.map.call(tbl.querySelectorAll('.selrow:checked'),function(c){return +c.dataset.id;}):[];
    }
    function updateBulk(){
      var bar=document.getElementById('pbBar');
      if(bar){bar.style.display=root.querySelectorAll('#tbl .selrow:checked').length?'':'none';}
    }
    function bulk(action){
      var ids=selectedIds();
      if(!ids.length){UI.toast('ابتدا حداقل یک سفارش انتخاب کنید','w');return;}
      API.post('/panel/payment-orders/bulk',{ids:ids,action:action})
        .then(function(d){UI.toast(UI.faNum(d.processed||0)+' مورد پردازش شد');load();})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
    }
    var bp=document.getElementById('pbPending');
    if(bp){bp.addEventListener('click',function(){bulk('pending_refund');});}
    var bd=document.getElementById('pbDone');
    if(bd){bd.addEventListener('click',function(){bulk('refunded');});}
    var bu=document.getElementById('pbUnmark');
    if(bu){bu.addEventListener('click',function(){bulk('unmark_refund');});}
    load();
  }
};

Pages.paymentOrderDetail={
  title:'سفارش پرداخت',icon:'i-wallet',hideNav:true,
  render:function(root,ctx,id){
    root.innerHTML='<div class="spin"></div>';
    var staff=['admin','manager'].indexOf(ctx.user.role)>-1;
    API.get('/panel/payment-orders/'+id).then(function(o){
      var riderName='—',compTitle='—';
      var jobs=[];
      if(o.rider_user_id){jobs.push(API.get('/panel/users/'+o.rider_user_id).then(function(u){riderName=[u.first_name,u.last_name].filter(Boolean).join(' ')||u.username;}).catch(function(){}));}
      if(o.competition_id){jobs.push(API.get('/panel/competitions/'+o.competition_id).then(function(c){compTitle=c.title;}).catch(function(){}));}
      return Promise.all(jobs).then(function(){draw(o,riderName,compTitle);});
    });
    function draw(o,riderName,compTitle){
      root.innerHTML=pageHead('سفارش #'+UI.faNum(o.id),UI.badge(o.status),'<a class="btn btn-g btn-sm" href="/payment-orders">بازگشت</a>')
        +'<div class="grid c2"><div class="panel pad">'+detail([
          ['مبلغ',UI.money(o.amount_irt)],
          ['وضعیت',UI.badge(o.status)],
          ['سوارکار',UI.esc(riderName)],
          ['مسابقه',UI.esc(compTitle)],
          ['authority','<span class="ltr">'+UI.esc(o.authority||'—')+'</span>'],
          ['ref_id','<span class="ltr">'+UI.esc(o.ref_id||'—')+'</span>'],
          ['کارت','<span class="ltr">'+UI.esc(o.card_pan||'—')+'</span>'],
          ['شناسه یکتا','<span class="ltr i11">'+UI.esc(o.uuid||'—')+'</span>'],
          ['ایجاد',I18N.fmtDateTime(o.created_at)],
          ['تأیید',I18N.fmtDateTime(o.verified_at)]
        ])+'</div><div><div class="panel pad"><b class="i13">وابستگی‌ها</b><div class="row gap2 wrap mt3">'
        +(o.rider_user_id?'<a class="btn btn-g btn-sm" href="/users/'+o.rider_user_id+'">'+UI.ic('i-user')+' پروفایل سوارکار</a>':'<span class="hint">—</span>')
        +(o.competition_id?'<a class="btn btn-g btn-sm" href="/competitions/'+o.competition_id+'">'+UI.ic('i-trophy')+' مسابقه</a>':'')
        +'</div></div>'
        +(staff?'<div class="panel pad mt4"><b class="i13">عملیات مدیریتی</b><div class="row gap2 wrap mt3">'
          +(o.status==='pending'?'<button class="btn btn-s btn-sm" id="v">تأیید دستی</button>':'')
          +(o.status==='paid'?'<button class="btn btn-g btn-sm" id="r1">شروع استرداد</button>':'')
          +(o.status==='pending_refund'?'<button class="btn btn-s btn-sm" id="r2">تکمیل استرداد</button><button class="btn btn-g btn-sm" id="r3">لغو استرداد</button>':'')
          +((o.status!=='pending'&&o.status!=='paid'&&o.status!=='pending_refund')?'<span class="hint">عملیات مدیریتی برای این وضعیت در دسترس نیست.</span>':'')
        +'</div></div>':'<div class="panel pad mt4 i12 mut">در صورت نیاز به تأیید یا استرداد با پشتیبانی هیئت تماس بگیرید.</div>')
        +'</div></div>';
      var v=document.getElementById('v');
      if(v){v.addEventListener('click',function(){API.post('/panel/payment-orders/'+id+'/verify',{}).then(function(){Pages.paymentOrderDetail.render(root,ctx,id);});});}
      var r1=document.getElementById('r1');
      if(r1){r1.addEventListener('click',function(){API.post('/panel/payment-orders/'+id+'/mark-pending-refund',{}).then(function(){Pages.paymentOrderDetail.render(root,ctx,id);});});}
      var r2=document.getElementById('r2');
      if(r2){r2.addEventListener('click',function(){API.post('/panel/payment-orders/'+id+'/mark-refunded',{}).then(function(){Pages.paymentOrderDetail.render(root,ctx,id);});});}
      var r3=document.getElementById('r3');
      if(r3){r3.addEventListener('click',function(){API.post('/panel/payment-orders/'+id+'/unmark-refund',{}).then(function(){Pages.paymentOrderDetail.render(root,ctx,id);});});}
    }
  }
};

Pages.reconciliation={
  title:'تطبیق زرین‌پال',icon:'i-swap',sec:'مالی',roles:['admin','manager'],
  render:function(root){
    root.innerHTML=pageHead('تطبیق سفارش‌ها با گزارش زرین‌پال','فایل CSV گزارش تراکنش‌های زرین‌پال را بارگذاری کنید تا مغایرت‌ها مشخص شوند.')
      +'<div class="panel pad" style="max-width:640px">'
      +'<input type="file" accept=".csv" class="inp" id="csv"/>'
      +'<button class="btn btn-p mt3" id="go">مقایسه و تطبیق</button>'
      +'<div id="out" class="mt4"></div></div>';
    document.getElementById('go').addEventListener('click',function(){
      var f=document.getElementById('csv').files[0];
      if(!f){UI.toast('فایل CSV انتخاب کنید','w');return;}
      var fd=new FormData();
      fd.append('file',f);
      API.post('/panel/payment-orders/reconciliation',fd).then(function(d){
        var mism=d.mismatched||[];
        var missing=d.missing_local||[];
        var out='<div class="grid c3 mb3">'
          +kpiCard('منطبق',UI.faNum(d.matched||0),'i-ccheck','b-ok')
          +kpiCard('مغایرت',UI.faNum(mism.length),'i-flag','b-warn')
          +kpiCard('یافت‌نشده',UI.faNum(missing.length),'i-info','b-bad')+'</div>';
        out+='<b class="i13">مغایرت‌ها</b>'+UI.table([
          {label:'authority',render:function(r){return '<span class="ltr i11">'+UI.esc(r.authority||'—')+'</span>';}},
          {label:'مرجع محلی',render:function(r){return '<span class="ltr i11">'+UI.esc(r.local_ref||'—')+'</span>';}},
          {label:'مرجع زرین‌پال',render:function(r){return '<span class="ltr i11">'+UI.esc(r.remote_ref||'—')+'</span>';}}
        ],mism,{emptyText:'مغایرتی یافت نشد — همه سفارش‌ها منطبق‌اند ✓'});
        if(missing.length){
          out+='<b class="i13 mt4" style="display:block">تراکنش‌های بدون سفارش محلی</b>'
            +'<div class="panel pad-s" style="max-height:220px;overflow:auto">'+missing.map(function(a){return '<div class="ltr i12 kbd" style="margin:3px 0">'+UI.esc(a)+'</div>';}).join('')+'</div>';
        }
        document.getElementById('out').innerHTML=out;
        UI.toast('تطبیق انجام شد');
      }).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در پردازش فایل','e');});
    });
  }
};

window.Pages=Object.assign({},window.Pages,Pages);
})();
