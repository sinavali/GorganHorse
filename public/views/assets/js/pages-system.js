/* ============================================================================
   System pages: reports (engine-driven grid + export), settings (registry-
   driven), SMS templates + log, audit viewer, backups/reset/demo, search.
   ============================================================================ */
(function(){
'use strict';

var Pages={};

/* ---------------- reports ---------------- */
/*
   Report filtering has two layers:
     - simple: a search box, quick date-range presets and the report's status
       as toggle chips. This covers daily use in two clicks.
     - advanced: one editable row per declared filter column (operator +
       value), so every filter the engine supports is reachable.
   Both layers compile into the same filters array the engine already takes,
   and the whole state (simple + advanced) lives in the URL.
*/
var OP_LABELS={eq:'برابر',ne:'نامساوی',contains:'شامل',gt:'بزرگ‌تر از',gte:'بزرگ‌تر یا مساوی',
  lt:'کوچک‌تر از',lte:'کوچک‌تر یا مساوی',in:'یکی از',between:'بین'};
var REPORT_ENUM_LABELS={pending_payment:'در انتظار پرداخت',paid:'پرداخت‌شده',confirmed:'تأییدشده',
  rejected:'ردشده',cancelled:'لغوشده',withdrawn:'انصراف',pending:'در انتظار',failed:'ناموفق',
  pending_refund:'در انتظار استرداد',refunded:'مستردشده',active:'فعال',sold_to_non_rider:'فروش به غیرسوارکار',
  soft_deleted:'حذف‌شده',verified:'تأییدشده',none:'بدون محدودیت',limited:'محدود',full:'مسدود',
  global:'سراسری',competition:'مسابقه',rade:'رده',rider:'سوارکار',horse:'اسب'};
var RANGE_PRESETS=[
  {v:'',l:'همه زمان‌ها'},
  {v:'month',l:'این ماه'},
  {v:'d30',l:'۳۰ روز اخیر'},
  {v:'d90',l:'۹۰ روز اخیر'},
  {v:'year',l:'سال جاری'},
  {v:'custom',l:'بازه دلخواه'}
];
function opLabel(op){return OP_LABELS[op]||op;}
function enumLabel(v){return REPORT_ENUM_LABELS[v]||v;}
function todayIso(){
  var t=I18N.todayJalali().split('/').map(Number);
  return UI.toIso(t[0],t[1],t[2]);
}
function shiftIso(iso,days){
  var d=new Date(iso);
  d.setUTCDate(d.getUTCDate()+days);
  return d.toISOString().replace(/\.\d+Z$/,'Z');
}
function dayStart(iso){return String(iso).slice(0,10)+'T00:00:00Z';}
function dayEnd(iso){return String(iso).slice(0,10)+'T23:59:59Z';}
Pages.reports={
  title:'گزارش‌ها',icon:'i-chart',sec:'تحلیل',
  render:function(root){
    root.innerHTML=pageHead('گزارش‌ها','فیلتر ساده و سریع؛ برای فیلترهای دقیق‌تر، بخش «فیلتر پیشرفته» را باز کنید.')+'<div id="rep"><div class="spin"></div></div>';
    API.get('/panel/reports').then(function(presets){
      if(!presets.length){
        root.querySelector('#rep').innerHTML='<div class="panel pad"><div class="empty">گزارشی تعریف نشده است.</div></div>';
        return;
      }
      var seed=App.seed({report:'',q:'',range:'',from:'',to:'',st:'',adv:'',advOpen:'',pp:''});
      var state={
        report:(presets.filter(function(p){return p.key===seed.report;})[0]||presets[0]).key,
        q:seed.q||'',range:seed.range||'',from:seed.from||'',to:seed.to||'',
        statuses:seed.st?String(seed.st).split(','):[],
        adv:[],
        advOpen:seed.advOpen==='1',
        page:1,
        per_page:parseInt(seed.pp,10)||50,
        sort:[],columns:null
      };
      if(seed.adv){
        try{
          var parsed=JSON.parse(decodeURIComponent(seed.adv));
          if(Array.isArray(parsed)){state.adv=parsed;}
        }catch(e){state.adv=[];}
      }
      var host=root.querySelector('#rep');
      /* ---- filter metadata helpers ---- */
      function defs(){return (preset()||{}).filters||[];}
      function defOf(key){return defs().filter(function(d){return d.key===key;})[0]||null;}
      function textDef(){return defs().filter(function(d){return (d.operators||[]).indexOf('contains')>-1;})[0]||null;}
      function enumDef(){return defs().filter(function(d){return (d.options||[]).length;})[0]||null;}
      function dateDef(){return defs().filter(function(d){return d.type==='datetime'&&(d.operators||[]).indexOf('between')>-1;})[0]||null;}
      /* Resolve the simple date control into [fromIso,toIso] (dates only). */
      function rangeBounds(){
        if(state.range==='month'){
          var t=I18N.todayJalali().split('/').map(Number);
          return [UI.toIso(t[0],t[1],1),todayIso()];
        }
        if(state.range==='d30'){return [shiftIso(todayIso(),-29),todayIso()];}
        if(state.range==='d90'){return [shiftIso(todayIso(),-89),todayIso()];}
        if(state.range==='year'){
          var y=I18N.todayJalali().split('/').map(Number)[0];
          return [UI.toIso(y,1,1),todayIso()];
        }
        if(state.range==='custom'){return [state.from||'',state.to||''];}
        return ['',''];
      }
      /* Compile the whole UI state into the engine's filter payload. */
      function buildFilters(){
        var out=[];
        var td=textDef();
        if(td&&state.q){out.push({col:td.key,op:'contains',value:state.q});}
        var dd=dateDef();
        if(dd){
          var b=rangeBounds();
          if(b[0]&&b[1]){out.push({col:dd.key,op:'between',value:[dayStart(b[0]),dayEnd(b[1])]});}
          else if(b[0]){out.push({col:dd.key,op:'gt',value:dayStart(b[0])});}
          else if(b[1]){out.push({col:dd.key,op:'lt',value:dayEnd(b[1])});}
        }
        var ed=enumDef();
        if(ed&&state.statuses.length){out.push({col:ed.key,op:'in',value:state.statuses.slice()});}
        state.adv.forEach(function(f){
          if(!f||!f.col||f.value===''||f.value==null){return;}
          out.push({col:f.col,op:f.op||'eq',value:f.op==='in'?String(f.value).split(','):f.value});
        });
        return out;
      }
      function syncUrl(){
        /* The reports page owns its own redraw, so only the URL is mirrored
           here (no router round-trip, no preset refetch on every keystroke). */
        App.replaceQuery({
          report:state.report,q:state.q,range:state.range,from:state.from,to:state.to,
          st:state.statuses.join(','),adv:state.adv.length?encodeURIComponent(JSON.stringify(state.adv)):'',
          advOpen:state.advOpen?'1':'',pp:state.per_page!==50?state.per_page:''
        });
      }
      function drawShell(){
        var p=preset();
        var td=textDef(),ed=enumDef();
        var pb=root.querySelector('[data-print]');
        if(pb){pb.setAttribute('data-print-title',p.label);}
        host.innerHTML='<div class="panel pad-s mb3 noprint">'
          +'<div class="row gap2 wrap mb3" style="align-items:center">'
          +'<select class="sel" id="rpt" style="width:auto">'+presets.map(function(x){
            return '<option value="'+UI.esc(x.key)+'"'+(x.key===state.report?' selected':'')+'>'+UI.esc(x.label)+'</option>';
          }).join('')+'</select>'
          +(td?'<div class="spk" data-spk-q="1" style="flex:1 1 220px;min-width:180px">'
            +'<div class="spk-ctl"><input class="inp spk-inp" type="text" placeholder="جستجو در '+UI.esc(td.label)+'…" value="'+UI.esc(state.q)+'"/></div></div>':'')
          +'<span class="g1"></span>'
          +'<button class="btn btn-g btn-sm" id="advT">'+UI.ic('i-filter')+' فیلتر پیشرفته'
            +(state.adv.length?' <span class="badge b-info">'+UI.faNum(state.adv.length)+'</span>':'')+'</button>'
          +'<button class="btn btn-g btn-sm" id="cols">'+UI.ic('i-grid')+' ستون‌ها</button>'
          +'<button class="btn btn-g btn-sm" id="sharesR">'+UI.ic('i-users')+' اشتراک‌ها</button>'
          +'<button class="btn btn-g btn-sm" id="shareR">'+UI.ic('i-send')+' اشتراک‌گذاری</button>'
          +'<button class="btn btn-p btn-sm" id="exp">'+UI.ic('i-dl')+' خروجی</button>'
          +'</div>'
          /* --- simple layer --- */
          +'<div class="row gap2 wrap" style="align-items:center">'
          +(dateDef()?'<span class="i12 mut">بازه زمانی</span><select class="sel" id="rng" style="width:auto">'
            +RANGE_PRESETS.map(function(r){return '<option value="'+r.v+'"'+(state.range===r.v?' selected':'')+'>'+UI.esc(r.l)+'</option>';}).join('')
            +'</select>'
            +(state.range==='custom'?'<input class="inp" id="dFrom" data-date readonly style="width:auto" placeholder="از تاریخ"'
              +(state.from?' data-iso="'+UI.esc(state.from)+'" value="'+UI.esc(I18N.fmtDate(state.from))+'"':'')+'/>'
              +'<input class="inp" id="dTo" data-date readonly style="width:auto" placeholder="تا تاریخ"'
              +(state.to?' data-iso="'+UI.esc(state.to)+'" value="'+UI.esc(I18N.fmtDate(state.to))+'"':'')+'/>':'')
            :'')
          +(ed?'<span class="i12 mut">'+UI.esc(ed.label)+'</span><span class="row gap1 wrap" id="stChips">'+ed.options.map(function(o){
            var on=state.statuses.indexOf(o)>-1;
            return '<button type="button" class="chip'+(on?' on':'')+'" data-st="'+UI.esc(o)+'">'+UI.esc(enumLabel(o))+'</button>';
          }).join('')+'</span>':'')
          +'<span class="g1"></span>'
          +'<select class="sel" id="pp" style="width:auto">'
          +[25,50,100,200].map(function(n){return '<option value="'+n+'"'+(state.per_page===n?' selected':'')+'>'+UI.faNum(n)+' ردیف</option>';}).join('')
          +'</select>'
          +'<button class="btn btn-g btn-sm" id="clrAll"'+(buildFilters().length?'':' disabled')+'>'+UI.ic('i-x2')+' پاک‌کردن فیلترها</button>'
          +'</div>'
          /* --- advanced layer --- */
          +'<div id="advBox" class="mt3"'+(state.advOpen?'':' hidden')+'>'
          +'<div class="row gap2 wrap mb2" style="align-items:center">'
          +'<b class="i13">فیلترهای پیشرفته</b>'
          +'<span class="i11 mut">هر ستون گزارش با عملگر دلخواه</span>'
          +'<span class="g1"></span>'
          +'<button class="btn btn-s btn-sm" id="advAdd">'+UI.ic('i-plus')+' افزودن فیلتر</button>'
          +'</div><div id="advRows" class="col gap2"></div></div>'
          +'<div id="flt" class="row gap1 wrap mt3"></div>'
          +'</div>'
          +'<div id="printScope"><div id="out"><div class="spin"></div></div></div>';
        UI.initPicks(host);
        host.querySelector('#rpt').addEventListener('change',function(){
          state.report=this.value;state.page=1;state.statuses=[];state.adv=[];state.columns=null;
          state.q='';state.range='';state.from='';state.to='';
          syncUrl();drawShell();
        });
        var rq=host.querySelector('[data-spk-q] .spk-inp');
        if(rq){
          rq.addEventListener('input',debounce(function(){
            state.q=this.value.trim();state.page=1;syncUrl();drawChips();run();
          },380));
        }
        var rng=host.querySelector('#rng');
        if(rng){
          rng.addEventListener('change',function(){
            state.range=this.value;state.page=1;
            if(state.range!=='custom'){state.from='';state.to='';}
            syncUrl();drawShell();
          });
        }
        var dF=host.querySelector('#dFrom'),dT=host.querySelector('#dTo');
        if(dF){
          dF.addEventListener('change',function(){
            state.from=this.dataset.iso||'';state.page=1;syncUrl();drawChips();run();
          });
        }
        if(dT){
          dT.addEventListener('change',function(){
            state.to=this.dataset.iso||'';state.page=1;syncUrl();drawChips();run();
          });
        }
        host.querySelector('#stChips')&&host.querySelectorAll('#stChips [data-st]').forEach(function(b){
          b.addEventListener('click',function(){
            var v=b.dataset.st;
            var at=state.statuses.indexOf(v);
            if(at>-1){state.statuses.splice(at,1);}else{state.statuses.push(v);}
            b.classList.toggle('on');
            state.page=1;syncUrl();drawChips();run();
          });
        });
        host.querySelector('#pp').addEventListener('change',function(){
          state.per_page=parseInt(this.value,10)||50;state.page=1;syncUrl();run();
        });
        var ca=host.querySelector('#clrAll');
        if(ca){
          ca.addEventListener('click',function(){
            state.q='';state.range='';state.from='';state.to='';state.statuses=[];state.adv=[];state.page=1;
            syncUrl();drawShell();
          });
        }
        host.querySelector('#advT').addEventListener('click',function(){
          state.advOpen=!state.advOpen;syncUrl();drawShell();
        });
        host.querySelector('#advAdd').addEventListener('click',function(){
          var list=defs();
          if(!list.length){UI.toast('این گزارش فیلتری ندارد','w');return;}
          state.advOpen=true;
          state.adv.push({col:list[0].key,op:(list[0].operators||['eq'])[0],value:''});
          syncUrl();drawShell();
        });
        host.querySelector('#cols').addEventListener('click',pickColumns);
        host.querySelector('#exp').addEventListener('click',exportCsv);
        host.querySelector('#shareR').addEventListener('click',shareReport);
        host.querySelector('#sharesR').addEventListener('click',showShares);
        drawAdv();
        drawChips();
        run();
      }
      function reportLabel(key){
        var p=presets.filter(function(x){return x.key===key;})[0];
        return p?p.label:key;
      }
      function shareReport(){
        var body=UI.el('<div class="pad-s"><p class="i12 mut mb3">گزارش «'+UI.esc(preset().label)+'» با فیلترهای فعلی به اشتراک گذاشته می‌شود.</p>'
          +'<label class="lbl req">جستجوی کاربر دریافت‌کننده</label><input class="inp" id="sUser" placeholder="نام، نام کاربری یا موبایل…" autocomplete="off"/>'
          +'<div id="sRes" class="mt2"><span class="hint">هنوز کاربری انتخاب نشده است.</span></div>'
          +'<div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-go>اشتراک‌گذاری</button></div></div>');
        UI.modal('اشتراک‌گذاری گزارش',body);
        var chosen=null;
        var host=body.querySelector('#sRes');
        var t;
        body.querySelector('#sUser').addEventListener('input',function(){
          var term=this.value.trim();
          clearTimeout(t);
          if(term.length<2){host.innerHTML='<span class="hint">حداقل ۲ نویسه وارد کنید.</span>';return;}
          t=setTimeout(function(){
            API.get('/panel/users',{search:term,per_page:10}).then(function(d){
              var rows=UI.rows(d);
              if(!rows.length){host.innerHTML='<span class="hint">کاربری یافت نشد.</span>';return;}
              host.innerHTML='<div class="row gap1 wrap">'+rows.map(function(r){
                return '<button type="button" class="btn btn-g btn-sm" data-pick="'+r.id+'" data-label="'+UI.esc(firstLast(r))+' ('+UI.esc(roleLabel(r.role))+')'+'">'+UI.esc(firstLast(r))+'</button>';
              }).join('')+'</div>';
              host.querySelectorAll('[data-pick]').forEach(function(b){
                b.addEventListener('click',function(){chosen=b.dataset.pick;host.innerHTML='<span class="badge b-ok">'+UI.esc(b.dataset.label)+'</span>';});
              });
            }).catch(function(){host.innerHTML='<span class="hint">جستجو در دسترس نیست.</span>';});
          },320);
        });
        body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
        body.querySelector('[data-go]').addEventListener('click',function(){
          if(!chosen){UI.toast('یک کاربر انتخاب کنید','w');return;}
          API.post('/panel/reports/share',{shared_to_user_id:+chosen,report:state.report,filters:buildFilters()})
            .then(function(){UI.closeDialog();UI.toast('گزارش به اشتراک گذاشته شد');})
            .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
        });
      }
      function showShares(){
        API.get('/panel/reports/shares').then(function(rows){
          rows=UI.rows(rows);
          var body=UI.el('<div class="pad-s">'+UI.table([
            {label:'گزارش',render:function(r){return UI.esc(reportLabel(r.report_key));}},
            {label:'اشتراک‌گذارنده',key:'owner_name'},
            {label:'تاریخ',render:function(r){return I18N.fmtDate(r.created_at);}},
            {label:'عملیات',render:function(r){return '<button class="btn btn-d btn-sm" data-rev="'+r.id+'">لغو</button>';}}
          ],rows,{emptyText:'اشتراک‌گذاری‌ای ثبت نشده'})+'</div>');
          UI.modal('گزارش‌های اشتراکی',body);
          body.querySelectorAll('[data-rev]').forEach(function(b){
            b.addEventListener('click',function(){
              API.post('/panel/reports/shares/'+b.dataset.rev+'/revoke',{})
                .then(function(){UI.closeDialog();UI.toast('لغو شد');})
                .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
            });
          });
        }).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
      }
      function preset(){return presets.filter(function(p){return p.key===state.report;})[0];}
      /* Value editor for one advanced row, chosen by column type + operator. */
      function valueEditor(def,f,idx){
        var op=f.op||'eq';
        var name='data-adv="'+UI.esc(f.col)+'"';
        var v=Array.isArray(f.value)?f.value:(f.value!=null?[f.value]:[]);
        if(def.type==='enum'&&(def.options||[]).length){
          return '<select class="sel" '+name+' style="width:auto">'
            +'<option value="">—</option>'
            +def.options.map(function(o){return '<option value="'+UI.esc(o)+'"'+((v.join(','))===o?' selected':'')+'>'+UI.esc(enumLabel(o))+'</option>';}).join('')
            +'</select>';
        }
        if(def.type==='entity'){
          var idKey='adv_'+f.col+'_'+idx;
          return '<div class="spk" data-spk="'+UI.esc(idKey)+'" data-adv-entity="'+UI.esc(def.entity)+'">'
            +'<input type="hidden" '+name+' value="'+UI.esc(v[0]||'')+'"/>'
            +'<div class="spk-ctl"><input class="inp spk-inp" type="text" autocomplete="off" role="combobox" aria-expanded="false" placeholder="جستجوی '+UI.esc(def.label)+'…"/>'
            +'<button type="button" class="spk-clear" title="پاک‌کردن" hidden>'+UI.ic('i-x2')+'</button>'
            +'<span class="spk-arrow">'+UI.ic('i-cr')+'</span></div>'
            +'<div class="spk-pop" hidden></div>'
            +'</div>';
        }
        if(op==='in'){
          return '<input class="inp" '+name+' style="width:auto" placeholder="مقدار،مقدار دوم" value="'+UI.esc(v.join(','))+'"/>';
        }
        if(op==='between'){
          if(def.type==='datetime'){
            return '<input class="inp" '+name+' data-date readonly data-di="0" style="width:auto" placeholder="از تاریخ" data-iso="'+UI.esc(v[0]||'')+'" value="'+UI.esc(I18N.fmtDate(v[0]||''))+'"/>'
              +'<input class="inp" '+name+' data-date readonly data-di="1" style="width:auto" placeholder="تا تاریخ" data-iso="'+UI.esc(v[1]||'')+'" value="'+UI.esc(I18N.fmtDate(v[1]||''))+'"/>';
          }
          var t=def.type==='int'?'number':'text';
          return '<input class="inp" type="'+t+'" '+name+' data-di="0" style="width:auto" value="'+UI.esc(String(v[0]||'').slice(0,10))+'"/>'
            +'<input class="inp" type="'+t+'" '+name+' data-di="1" style="width:auto" value="'+UI.esc(String(v[1]||'').slice(0,10))+'"/>';
        }
        if(def.type==='datetime'){
          return '<input class="inp" '+name+' data-date readonly style="width:auto" placeholder="انتخاب تاریخ" data-iso="'+UI.esc(v[0]||'')+'" value="'+UI.esc(I18N.fmtDate(v[0]||''))+'"/>';
        }
        var t=def.type==='int'?'number':'text';
        return '<input class="inp" type="'+t+'" '+name+' style="width:auto" placeholder="مقدار" value="'+UI.esc(v[0]||'')+'"/>';
      }
      function drawAdv(){
        var box=host.querySelector('#advRows');
        if(!box){return;}
        if(!state.adv.length){
          box.innerHTML='<div class="i12 mut">هنوز فیلتری اضافه نشده است. با «افزودن فیلتر» هر ستون گزارش را با عملگر دلخواه فیلتر کنید.</div>';
          return;
        }
        var list=defs();
        box.innerHTML=state.adv.map(function(f,i){
          var def=defOf(f.col)||{key:f.col,label:f.col,type:'string',operators:['eq']};
          var ops=def.operators||['eq'];
          return '<div class="row gap2 wrap" data-row="'+i+'" style="align-items:center">'
            +'<select class="sel" data-col style="width:auto">'+list.map(function(d){
              return '<option value="'+UI.esc(d.key)+'"'+(d.key===f.col?' selected':'')+'>'+UI.esc(d.label||d.key)+'</option>';
            }).join('')+'</select>'
            +'<select class="sel" data-op style="width:auto">'+ops.map(function(o){
              return '<option value="'+o+'"'+(o===f.op?' selected':'')+'>'+UI.esc(opLabel(o))+'</option>';
            }).join('')+'</select>'
            +valueEditor(def,f,i)
            +'<button type="button" class="btn btn-g btn-sm" data-del title="حذف">'+UI.ic('i-x2')+'</button>'
            +'</div>';
        }).join('');
        var advReg = {};
        box.querySelectorAll('[data-adv-entity]').forEach(function(spk){
          var entity = spk.dataset.advEntity;
          var key = spk.dataset.spk;
          if (entity === 'competitions') { advReg[key] = {load: UI.spkFromApi('/panel/competitions', function(c){return c.title;})}; }
          else if (entity === 'clubs') { advReg[key] = {load: UI.spkFromApi('/panel/clubs', function(c){return c.name;})}; }
          else if (entity === 'rades') { advReg[key] = {load: UI.spkFromApi('/panel/rades', function(r){return r.name;})}; }
          else if (entity === 'users') { advReg[key] = {load: UI.spkFromApi('/panel/users', function(u){return u.first_name+' '+u.last_name+' ('+u.username+')';})}; }
        });
        UI.initPicks(box, advReg);
        UI.initPickers(box);
        box.querySelectorAll('[data-row]').forEach(function(row){
          var i=+row.dataset.row;
          row.querySelector('[data-col]').addEventListener('change',function(){
            state.adv[i].col=this.value;
            var d=defOf(this.value)||{operators:['eq']};
            state.adv[i].op=(d.operators||['eq'])[0];
            state.adv[i].value='';
            syncUrl();drawAdv();drawChips();run();
          });
          row.querySelector('[data-op]').addEventListener('change',function(){
            state.adv[i].op=this.value;state.adv[i].value='';
            syncUrl();drawAdv();drawChips();run();
          });
          row.querySelectorAll('[data-adv]').forEach(function(inp){
            inp.addEventListener('change',function(){
              var f=state.adv[i];
              if(f.op==='between'){
                var a=Array.isArray(f.value)?f.value.slice():['',''];
                if(inp.hasAttribute('data-iso')) { a[+this.dataset.di]=this.dataset.iso||''; }
                else { a[+this.dataset.di]=this.value; }
                f.value=a;
              }else if(f.op==='in'){
                f.value=this.value;
              }else{
                var d=defOf(f.col);
                if(inp.hasAttribute('data-iso')){ f.value=this.dataset.iso||''; }
                else { f.value=(d&&d.type==='int')?(this.value===''?'':parseInt(this.value,10)):this.value; }
              }
              syncUrl();drawChips();run();
            });
          });
          row.querySelector('[data-del]').addEventListener('click',function(){
            state.adv.splice(i,1);syncUrl();drawAdv();drawChips();run();
          });
        });
      }
      /* One chip per active filter (simple + advanced), each removable. */
      function drawChips(){
        var fhost=host.querySelector('#flt');
        if(!fhost){return;}
        fhost.innerHTML='';
        function chip(label,onRemove){
          var c=UI.el('<span class="chip on">'+UI.esc(label)+' <b style="cursor:pointer" title="حذف">×</b></span>');
          c.querySelector('b').addEventListener('click',function(){onRemove();syncUrl();drawShell();});
          fhost.appendChild(c);
        }
        var td=textDef();
        if(td&&state.q){chip(td.label+': '+state.q,function(){state.q='';});}
        var dd=dateDef();
        if(dd){
          var b=rangeBounds();
          if(b[0]||b[1]){
            var lbl=dd.label+': '+(b[0]?I18N.fmtDate(b[0]):'…')+' تا '+(b[1]?I18N.fmtDate(b[1]):'…');
            chip(lbl,function(){state.range='';state.from='';state.to='';});
          }
        }
        var ed=enumDef();
        if(ed&&state.statuses.length){
          chip(ed.label+': '+state.statuses.map(enumLabel).join('، '),function(){state.statuses=[];});
        }
        state.adv.forEach(function(f,i){
          if(!f||!f.col||f.value===''||f.value==null){return;}
          var def=defOf(f.col)||{label:f.col};
          var v=Array.isArray(f.value)?f.value.join(' تا '):String(f.value);
          if(f.op==='in'){
            v=String(f.value).split(',').map(function(x){
              return enumLabel(x);
            }).join('، ');
          }
          chip((def.label||f.col)+' '+opLabel(f.op)+' '+v,function(){state.adv.splice(i,1);});
        });
      }
      function pickColumns(){
        var p=preset();
        var sel=state.columns||p.default_columns;
        var body=UI.el('<div class="pad-s">'+p.columns.map(function(c){
          return '<label class="row gap2 i13 mb2" style="display:flex"><input type="checkbox" data-col="'+c.key+'" '+(sel.indexOf(c.key)>-1?'checked':'')+'/> '+UI.esc(c.label||c.key)+'</label>';
        }).join('')+'<div class="row jend mt3"><button class="btn btn-p" data-go>اعمال</button></div></div>');
        UI.modal('ستون‌های نمایشی',body);
        body.querySelector('[data-go]').addEventListener('click',function(){
          state.columns=Array.prototype.map.call(body.querySelectorAll('[data-col]:checked'),function(c){return c.dataset.col;});
          UI.closeDialog();run();
        });
      }
      function exportCsv(){
        API.post('/panel/reports/export',{report:state.report,filters:buildFilters(),sort:state.sort,columns:state.columns,format:'csv'})
          .then(function(d){
            var a=document.createElement('a');
            a.href=d.url;
            a.download=d.filename;
            document.body.appendChild(a);a.click();a.remove();
          });
      }
      function run(){
        var out=host.querySelector('#out');
        if(!out){return;}
        out.innerHTML='<div class="spin"></div>';
        API.post('/panel/reports/data',{report:state.report,filters:buildFilters(),page:state.page,per_page:state.per_page,sort:state.sort,columns:state.columns})
          .then(function(d){
            var cols=d.columns||[];
            var html=UI.table(cols.map(function(c){
              return {label:c.label||c.key,key:c.key,sortKey:c.key,sortValue:function(r){return r[c.key];},render:function(r){
                var v=r[c.key];
                if(c.type==='int'){return UI.faNum(v==null?'—':v);}
                if(c.type==='bool'){return Number(v)?'✓':'—';}
                if(c.type==='datetime'){return I18N.fmtDateTime(v);}
                if(String(c.key).indexOf('amount')>-1||String(c.key).indexOf('revenue')>-1){return UI.money(v);}
                return UI.esc(v==null?'—':v);
              }};
            }),UI.rows(d),{
              pager:UI.pagerHtml(d.filtered||d.total||0,d.page,d.per_page),
              sort:state.sort[0]||null,
              emptyText:buildFilters().length?'با فیلترهای فعلی ردیفی یافت نشد':'ردیفی یافت نشد'
            });
            var sums=d.summary||{};
            var sumKeys=Object.keys(sums);
            var sumHtml=sumKeys.length?'<div class="grid c'+Math.min(4,sumKeys.length)+' mb3">'+sumKeys.map(function(k){
              var v=sums[k];
              return kpiCard(k,(typeof v==='number'&&(k.indexOf('amount')>-1||k.indexOf('revenue')>-1))?UI.money(v):UI.faNum(v),'i-chart','b-info');
            }).join('')+'</div>':'';
            out.innerHTML=sumHtml+html;
            /* Sorting is server-side: the engine whitelists sortable columns. */
            UI.bindSorting(out,{sort:state.sort[0]||null},function(key,dir){
              state.sort=[{col:key,dir:dir}];state.page=1;run();
            });
            out.querySelectorAll('[data-page]').forEach(function(b){
              b.addEventListener('click',function(){state.page=+b.dataset.page;run();});
            });
          }).catch(function(err){out.innerHTML='<div class="panel pad" style="color:var(--danger)">'+UI.esc((err.errors&&err.errors[0].message)||'خطا')+'</div>';});
      }
      drawShell();
    });
  }
};

/* ---------------- settings ---------------- */
Pages.settings={
  title:'تنظیمات',icon:'i-settings',sec:'سیستم',roles:['admin'],
  render:function(root){
    root.innerHTML=pageHead('تنظیمات سامانه','پیکربندی کامل سامانه — از برند تا پیامک و پرداخت.',null,{noPrint:true})
      +'<div class="panel pad mb4" id="cultureCard"><div class="row jb gap3 wrap">'
      +'<div><div class="row gap2"><span class="av" style="width:34px;height:34px">'+UI.ic('i-globe')+'</span>'
      +'<div><div class="b i14">زبان سامانه</div>'
      +'<div class="i12 mut">زبان پیش‌فرض که برای همه کاربران (و بازدیدکنندگان) اعمال می‌شود.</div></div></div></div>'
      +'<div id="culPick" style="min-width:260px"></div>'
      +'<div class="row gap2"><button class="btn btn-p" id="culSave">'+UI.ic('i-check')+' اعمال زبان</button>'
      +'<button class="btn btn-g" id="culRefresh">'+UI.ic('i-refresh')+' بازخوانی</button></div>'
      +'</div><div class="i11 mut mt2" id="culNow"></div></div>'
      +'<div id="set"><div class="spin"></div></div>';

    /* --- culture switcher (DB-backed, cached) --- */
    var culHost=root.querySelector('#culPick');
    var culBtn=root.querySelector('#culSave');
    App.loadCultures(true).then(function(list){
      list=list&&list.length?list:[{code:'fa-IR',name:'فارسی'},{code:'en-US',name:'English'}];
      culHost.innerHTML=UI.pickField({name:'culture',label:'زبان',value:I18N.code,
        options:list.map(function(c){return {v:c.code,l:c.name+' ('+c.code+')'};})});
      UI.initPicks(culHost,{});
      App.cultures=list;
      root.querySelector('#culNow').textContent='زبان فعال این نشست: '+I18N.code;
      culBtn.disabled=false;
    });
    culBtn.addEventListener('click',function(){
      var v=UI.formValues(culHost).culture;
      if(!v||v===I18N.code){UI.toast('زبان تغییری نکرده است','w');return;}
      culBtn.disabled=true;
      App.setCulture(v);
    });
    root.querySelector('#culRefresh').addEventListener('click',function(){
      App.loadCultures(true).then(function(list){
        UI.toast(UI.faNum((list||[]).length)+' زبان از پایگاه‌داده خوانده شد');
      });
    });

    API.get('/panel/settings').then(function(groups){
      var host=root.querySelector('#set');
      var tabs=Object.keys(groups).filter(function(k){return groups[k]&&groups[k].length;});
      var active=tabs[0];
      function draw(){
        host.innerHTML='<div class="tabs mb4">'+tabs.map(function(t){return '<button class="tabb'+(t===active?' on':'')+'" data-tab="'+t+'">'+UI.esc(groupLabel(t))+'</button>';}).join('')+'</div>';
        var g=groups[active]||[];
        var body=UI.el('<div class="panel pad"><form id="fS" class="frow">'+g.map(function(s){
          var v=s.value;
          if(s.type==='bool'){
            return '<div class="'+(s.help?'':'full')+'"><label class="lbl">'+UI.esc(s.label)+'</label><button type="button" class="sw'+(Number(v)?' on':'')+'" data-sw="'+UI.esc(s.key)+'"></button>'+(s.help?'<div class="hint">'+UI.esc(s.help)+'</div>':'')+'</div>';
          }
          if(s.type==='json'){
            return '<div class="full"><label class="lbl">'+UI.esc(s.label)+'</label><textarea class="ta ltr" name="'+UI.esc(s.key)+'" style="min-height:60px">'+UI.esc(JSON.stringify(v==null?[]:v))+'</textarea>'+(s.help?'<div class="hint">'+UI.esc(s.help)+'</div>':'')+'</div>';
          }
          if(s.type==='int'){
            return '<div><label class="lbl">'+UI.esc(s.label)+'</label><input class="inp ltr" type="number" name="'+UI.esc(s.key)+'" value="'+UI.esc(v==null?'':v)+'"/>'+(s.help?'<div class="hint">'+UI.esc(s.help)+'</div>':'')+'</div>';
          }
          if(s.key==='app.default_culture'){
            return '<div class="full" data-culkey="'+UI.esc(s.key)+'">'
              +'<label class="lbl">'+UI.esc(s.label)+'</label>'
              +'<div class="culslot"></div>'
              +(s.help?'<div class="hint">'+UI.esc(s.help)+' — از دکمه «زبان سامانه» بالای صفحه هم قابل تغییر است.</div>':'')
              +'</div>';
          }
          return '<div class="full"><label class="lbl">'+UI.esc(s.label)+'</label><input class="inp" name="'+UI.esc(s.key)+'" value="'+UI.esc(v==null?'':v)+'"/>'+(s.help?'<div class="hint">'+UI.esc(s.help)+'</div>':'')+'</div>';
        }).join('')+'</form><div class="row jend mt4"><button class="btn btn-p" id="saveSet">ذخیره تنظیمات</button></div></div>');
        host.appendChild(body);
        /* default_culture is rendered as a searchable select fed from the DB. */
        Array.prototype.forEach.call(body.querySelectorAll('[data-culkey]'),function(slot){
          var key=slot.getAttribute('data-culkey');
          var cur=((groups.general||[]).filter(function(s){return s.key===key;})[0]||{}).value||I18N.code;
          slot.querySelector('.culslot').innerHTML=UI.pickField({name:key,label:'',value:cur,
            options:App.cultures?App.cultures.map(function(c){return {v:c.code,l:c.name+' ('+c.code+')'};}):[{v:cur,l:cur}]});
          UI.initPicks(slot,{});
        });
        body.querySelectorAll('[data-sw]').forEach(function(b){
          b.addEventListener('click',function(){b.classList.toggle('on');});
        });
        body.querySelector('#saveSet').addEventListener('click',function(){
          var vals=UI.formValues(body);
          body.querySelectorAll('[data-sw]').forEach(function(b){vals[b.dataset.sw]=b.classList.contains('on');});
          var defs={};g.forEach(function(s){defs[s.key]=s;});
          Object.keys(vals).forEach(function(k){
            var s=defs[k];
            if(!s){return;}
            if(s.type==='int'){vals[k]=+vals[k]||0;}
            else if(s.type==='float'){vals[k]=parseFloat(vals[k])||0;}
            else if(s.type==='json'){
              try{vals[k]=JSON.parse(vals[k]||'[]');}catch(e){vals[k]=[];}
            }
          });
          API.post('/panel/settings',vals).then(function(){UI.toast('تنظیمات ذخیره شد');}).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
        });
      }
      host.addEventListener('click',function(e){
        var t=e.target.closest('[data-tab]');
        if(t){active=t.dataset.tab;draw();}
      });
      function groupLabel(k){
        return ({general:'عمومی',whitelabel:'برند',auth:'احراز هویت',uploads:'بارگذاری',clubs:'باشگاه',horses:'اسب',competitions:'مسابقه',payments:'پرداخت',sms:'پیامک',reports:'گزارش',cache:'کش',logs:'لاگ',backup:'پشتیبان',security:'امنیت',ui:'رابط',identity:'سیستم',scheduler:'زمان‌بند'})[k]||k;
      }
      draw();
    });
  }
};

/* ---------------- SMS ---------------- */
Pages.sms={
  title:'قالب‌های پیامک',icon:'i-send',sec:'سیستم',roles:['admin'],
  render:function(root){
    root.innerHTML=pageHead('قالب‌های پیامک','متن‌های پیامک با متغیرهای {name}.',
      '<div class="row gap2 wrap"><button class="btn btn-g btn-sm" id="testSms">'+UI.ic('i-send')+' ارسال آزمایشی</button><button class="btn btn-g btn-sm" id="quickSms">ارسال سریع</button><a class="btn btn-g btn-sm" href="/sms-log">لاگ ارسال</a><button class="btn btn-p btn-sm" id="newT">'+UI.ic('i-plus')+' قالب جدید</button></div>')
      +'<div id="tbl"><div class="spin"></div></div>';
    document.getElementById('newT').addEventListener('click',function(){dlg(null);});
    document.getElementById('quickSms').addEventListener('click',function(){
      API.get('/panel/sms/templates').then(function(rows){
        rows=UI.rows(rows).filter(function(t){return Number(t.is_active);});
        var body=UI.el('<div class="pad-s"><form id="fQs" class="frow">'
          +UI.inp('phone','شماره موبایل','',null,{req:true,ph:'09xxxxxxxxx',attrs:' inputmode="tel"'})
          +UI.sel('template_id','قالب (اختیاری)','',[{v:'',l:'— بدون قالب —'}].concat(rows.map(function(t){return {v:t.id,l:t.name};})))
          +UI.ta('message','متن پیام','',{req:true,full:true})
          +'</form><div class="row jend gap2 mt3"><button class="btn btn-g" data-cancel>انصراف</button><button class="btn btn-p" data-go>ارسال</button></div></div>');
        UI.modal('ارسال سریع پیامک',body);
        var f=body.querySelector('#fQs');
        f.querySelector('[name=template_id]').addEventListener('change',function(){
          var sel=f.querySelector('[name=template_id]').value;
          var t=rows.filter(function(x){return String(x.id)===String(sel);})[0];
          if(t&&t.body){f.querySelector('[name=message]').value=t.body;}
        });
        body.querySelector('[data-cancel]').addEventListener('click',UI.closeDialog);
        body.querySelector('[data-go]').addEventListener('click',function(){
          var v=UI.formValues(f);
          if(v.template_id===''){delete v.template_id;}else{v.template_id=+v.template_id;}
          API.post('/api/sms/send',v).then(function(d){
            UI.closeDialog();
            UI.toast(d.sent?'پیامک ارسال شد':'ارسال انجام نشد — تنظیمات پیامک را بررسی کنید',d.sent?'':'w');
          }).catch(function(err){UI.showErrors(f,err.errors);});
        });
      });
    });
    document.getElementById('testSms').addEventListener('click',function(){
      var body=UI.el('<div class="pad-s"><label class="lbl">شماره موبایل</label><input class="inp ltr" id="tPhone" placeholder="09xxxxxxxxx"/><div class="row jend mt3"><button class="btn btn-p" data-go>ارسال</button></div></div>');
      UI.modal('ارسال پیامک آزمایشی',body);
      body.querySelector('[data-go]').addEventListener('click',function(){
        API.post('/panel/settings/sms/test',{phone:body.querySelector('#tPhone').value.trim()})
          .then(function(d){UI.closeDialog();UI.toast(d.sent?'پیامک آزمایشی ارسال شد':'ارسال انجام نشد — تنظیمات پیامک را بررسی کنید',d.sent?'':'w');})
          .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});
      });
    });
    function load(){
      API.get('/panel/sms/templates').then(function(rows){
        rows=UI.rows(rows);
        root.querySelector('#tbl').innerHTML=UI.table([
          {label:'نام',key:'name'},
          {label:'متن',key:'body',wrap:true},
          {label:'فعال',render:function(r){return UI.badge(Number(r.is_active)?'active':'closed');}},
          {label:'عملیات',render:function(r){return '<div class="row gap1">'
            +'<button class="btn-i" data-edit="'+r.id+'" title="ویرایش">'+UI.ic('i-edit')+'</button>'
            +'<button class="btn-i" data-tog="'+r.id+'" title="'+(Number(r.is_active)?'غیرفعال کردن':'فعال کردن')+'">'+UI.ic(Number(r.is_active)?'i-x':'i-check')+'</button>'
            +'<button class="btn-i" data-del="'+r.id+'" title="حذف">'+UI.ic('i-trash')+'</button></div>';}}
        ],rows,{emptyText:'قالبی ثبت نشده'});
        root.querySelectorAll('[data-edit]').forEach(function(b){b.addEventListener('click',function(){API.get('/panel/sms/templates/'+b.dataset.edit).then(dlg);});});
        root.querySelectorAll('[data-tog]').forEach(function(b){b.addEventListener('click',function(){API.post('/panel/sms/templates/'+b.dataset.tog+'/toggle',{}).then(load).catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا','e');});});});
        root.querySelectorAll('[data-del]').forEach(function(b){b.addEventListener('click',function(){UI.confirm('این قالب حذف شود؟',function(){API.post('/panel/sms/templates/'+b.dataset.del+'/delete',{}).then(load);});});});
      });
    }
    function dlg(t){
      var isNew=!t;
      t=t||{};
      var body=UI.el('<div class="pad-s"><form id="fT" class="frow">'
        +UI.inp('name','نام قالب',t.name,null,{req:true,full:true})
        +UI.ta('body','متن پیامک',t.body,{req:true,full:true,hint:'متغیرها: {name} {competition} {date}'})
        +'</form><div class="row jend mt3"><button class="btn btn-p" data-save>ذخیره</button></div></div>');
      UI.modal(isNew?'قالب جدید':'ویرایش قالب',body);
      body.querySelector('[data-save]').addEventListener('click',function(){
        var f=body.querySelector('#fT');
        var v=UI.formValues(f);
        (isNew?API.post('/panel/sms/templates',v):API.post('/panel/sms/templates/'+t.id,v))
          .then(function(){UI.closeDialog();UI.toast('ذخیره شد');load();})
          .catch(function(err){UI.showErrors(f,err.errors);});
      });
    }
    load();
  }
};

Pages.smsLog={
  title:'لاگ پیامک',icon:'i-send',sec:'سیستم',roles:['admin'],
  render:function(root){
    var filters=App.seed({status:'',search:'',days:''});
    root.innerHTML=pageHead('گزارش تحویل پیامک','نرخ موفقیت ارسال و خطاهای درگاه پیامک.',
      '<button class="btn btn-g btn-sm" id="slExport">'+UI.ic('i-dl')+' خروجی گزارش تحویل</button>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div id="stats" class="grid c4 mb3"></div>'
      +'<div id="tbl">'+UI.skeletonTable(4)+'</div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'sms-log',
      searchLabel:'جستجوی شماره، متن پیام یا خطا…',
      selects:[
        {key:'status',label:'وضعیت',options:[{v:'',l:'همه'},{v:'sent',l:'ارسال‌شده'},{v:'failed',l:'ناموفق'},{v:'skipped',l:'نادیده'}]},
        {key:'days',label:'بازه',options:[{v:'',l:'همه زمان‌ها'},{v:'1',l:'۲۴ ساعت اخیر'},{v:'7',l:'۷ روز اخیر'},{v:'30',l:'۳۰ روز اخیر'},{v:'90',l:'۹۰ روز اخیر'}]}
      ],
      onChange:function(){load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    root.querySelector('#slExport').addEventListener('click',function(){
      var qs=[];
      Object.keys(filters).forEach(function(k){
        if(k!=='page'&&filters[k]!==''&&filters[k]!=null){qs.push(encodeURIComponent(k)+'='+encodeURIComponent(filters[k]));}
      });
      qs.push('format=csv');
      var btn=root.querySelector('#slExport');
      btn.disabled=true;
      API.download('GET','/panel/sms/log?'+qs.join('&'))
        .then(function(){UI.toast('گزارش تحویل آماده شد');})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در ساخت خروجی','e');})
        .then(function(){btn.disabled=false;});
    });
    load();
    function load(){
      API.get('/panel/sms/log',filters).then(function(d){
        var stats=d.stats||[];
        var sum=d.summary||{total:0,sent:0,failed:0,success_rate:0};
        var labels={sent:'ارسال‌شده',failed:'ناموفق',skipped:'نادیده'};
        var host=document.getElementById('stats');
        host.innerHTML=stats.length?stats.map(function(s){
          var tone=s.status==='failed'?'b-bad':(s.status==='skipped'?'b-warn':'b-ok');
          return kpiCard(labels[s.status]||s.status,UI.faNum(s.cnt),'i-send',tone);
        }).join('')+kpiCard('نرخ موفقیت',UI.faNum(sum.success_rate)+'٪','i-chart',
          sum.success_rate>=90?'b-ok':sum.success_rate>=70?'b-warn':'b-bad','از '+UI.faNum(sum.total)+' پیامک')
          :kpiCard('نرخ موفقیت',UI.faNum(sum.success_rate)+'٪','i-chart','b-mut','پیامکی ثبت نشده');
        fbar.setCount(UI.faNum(UI.rows(d).length)+' پیامک اخیر');
        root.querySelector('#tbl').innerHTML=UI.table([
          {label:'گیرنده',key:'recipient',sortKey:'recipient',render:function(r){return '<span class="ltr">'+UI.esc(r.recipient||r.phone||'—')+'</span>';}},
          {label:'قالب',key:'template',sortKey:'template'},
          {label:'متن',key:'body',wrap:true},
          {label:'وضعیت',sortKey:'status',render:function(r){return UI.badge(r.status);}},
          {label:'خطا',render:function(r){return r.error?'<span class="i11" style="color:var(--danger)">'+UI.esc(r.error)+'</span>':'—';}},
          {label:'زمان',key:'created_at',sortKey:'created_at',render:function(r){return I18N.fmtDateTime(r.created_at);}}
        ],UI.rows(d),{
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی پیامی نیست':'پیامی ارسال نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
      }).catch(function(err){root.querySelector('#tbl').innerHTML='<div class="panel pad" style="color:var(--danger)">'+UI.esc((err.errors&&err.errors[0].message)||'خطا')+'</div>';});
    }
  }
};

/* ---------------- audit ---------------- */
Pages.audit={
  title:'گزارش رویدادها',icon:'i-shield',sec:'سیستم',roles:['admin'],
  render:function(root){
    var filters=App.seed({action:'',actor_id:'',from:'',to:'',page:1});
    root.innerHTML=pageHead('گزارش رویدادها','تمام عملیات ثبت‌شده در سامانه (۱۸۰ روز نگهداری).',
      '<div class="row gap2 wrap">'
      +'<button class="btn btn-g btn-sm" id="aExport">'+UI.ic('i-dl')+' خروجی CSV</button>'
      +'</div>')
      +'<div class="mb3" id="fbarHost"></div>'
      +'<div id="tbl">'+UI.skeletonTable(5)+'</div>';
    var fbar=UI.filterBar({
      filters:filters,
      views:'audit',
      searchLabel:'جستجوی نام عملیات (مثلاً user.update)…',
      selects:[
        {key:'actor_id',label:'عامل',lazy:UI.spkFromApi('/panel/users',function(u){return firstLast(u)+' ('+u.username+')';})}
      ],
      onChange:function(){filters.page=1;load();}
    });
    root.querySelector('#fbarHost').appendChild(fbar);
    root.querySelector('#aExport').addEventListener('click',function(){
      var qs=[];
      Object.keys(filters).forEach(function(k){
        if(k!=='page'&&filters[k]!==''&&filters[k]!=null){qs.push(encodeURIComponent(k)+'='+encodeURIComponent(filters[k]));}
      });
      qs.push('format=csv');
      var btn=root.querySelector('#aExport');
      btn.disabled=true;
      API.download('GET','/panel/audit?'+qs.join('&'))
        .then(function(){UI.toast('فایل گزارش آماده شد');})
        .catch(function(err){UI.toast((err.errors&&err.errors[0].message)||'خطا در ساخت خروجی','e');})
        .then(function(){btn.disabled=false;});
    });
    
    var ACOLS=[
      {label:'زمان',sortKey:'created_at',sortValue:function(r){return r.created_at;},render:function(r){return I18N.fmtDateTime(r.created_at);}},
      {label:'عامل',sortKey:'actor_id',sortValue:function(r){return Number(r.actor_id);},render:function(r){return UI.faNum(r.actor_id||'—')+' ('+UI.esc(r.actor_role||'—')+')';}},
      {label:'عملیات',sortKey:'action',sortValue:function(r){return r.action;},render:function(r){return '<span class="kbd">'+UI.esc(r.action)+'</span>';}},
      {label:'هدف',sortKey:'target_type',sortValue:function(r){return r.target_type;},render:function(r){return UI.esc(r.target_type||'—')+' #'+UI.faNum(r.target_id||'—');}},
      {label:'نتیجه',sortKey:'result',sortValue:function(r){return r.result;},render:function(r){return r.result==='ok'?UI.badge('active'):UI.badge('rejected');}},
      {label:'جزئیات',render:function(r){var d=typeof r.diff_json==='string'?r.diff_json:JSON.stringify(r.diff_json||'');return '<span class="i11 mut ltr">'+UI.esc(d.slice(0,70))+'</span>';}}
    ];
    function load(){
      API.get('/panel/audit',filters).then(function(d){
        var rows=UI.sortRows(UI.rows(d),ACOLS,filters.sort);
        fbar.setCount(UI.faNum(d.total||rows.length)+' رویداد');
        root.querySelector('#tbl').innerHTML=UI.table(ACOLS,rows,{
          pager:UI.pagerHtml(d.total||0,filters.page,50),
          sort:filters.sort,
          emptyText:fbar.hasFilters()?'با فیلترهای فعلی رویدادی ثبت نشده':'رویدادی ثبت نشده',
          emptyAction:fbar.hasFilters()?fbar.clearAction:''
        });
        UI.bindSorting(root,filters,function(key,dir){filters.sort={key:key,dir:dir};App.setQuery(filters);load();});
        root.querySelectorAll('#tbl [data-clear-filters]').forEach(function(b){b.addEventListener('click',function(){fbar.onClear();});});
        root.querySelectorAll('#tbl [data-page]').forEach(function(b){b.addEventListener('click',function(){filters.page=+b.dataset.page;App.setQuery(filters);load();});});
      });
    }
    load();
  }
};

/* ---------------- backups / demo / reset ---------------- */
Pages.backups={
  title:'پشتیبان‌گیری',icon:'i-db',sec:'سیستم',roles:['admin'],
  render:function(root){
    root.innerHTML=pageHead('پشتیبان‌گیری و بازیابی','کپی کامل دیتابیس، رسانه‌ها و تنظیمات.',
      '<button class="btn btn-p btn-sm" id="mk">'+UI.ic('i-plus')+' پشتیبان جدید</button>')
      +'<div id="tbl" class="mb4"></div>'
      +'<div class="grid c3">'
      +section('داده‌های نمونه','<p class="i12 mut">بارگذاری مجموعه داده نمایشی (~۵۲ مسابقه، ~۱۸۰۰ ثبت‌نام).</p><div class="row gap2 mt3"><button class="btn btn-g btn-sm" id="seed">بارگذاری داده نمونه</button><button class="btn btn-d btn-sm" id="clearD">پاک‌کردن داده نمونه</button></div>')
      +section('بازنشانی سامانه','<p class="i12 mut">پاک‌کردن همه داده‌ها به‌جز تنظیمات و حساب مدیر فعلی.</p><button class="btn btn-d btn-sm mt3" id="resetB">بازنشانی کامل</button>')
      +section('کش','<p class="i12 mut">پاک‌کردن کش تنظیمات، فرهنگ‌ها و گزارش‌ها.</p><button class="btn btn-g btn-sm mt3" id="cacheB">پاک‌سازی کش</button>')
      +'</div>';
    load();
    document.getElementById('mk').addEventListener('click',function(){
      API.post('/panel/backups',{suffix:'manual'}).then(function(){UI.toast('پشتیبان ساخته شد');load();});
    });
    document.getElementById('seed').addEventListener('click',function(){
      UI.confirm('داده نمونه بارگذاری شود؟',function(){API.post('/panel/demo/seed',{}).then(function(){UI.toast('انجام شد');});});
    });
    document.getElementById('clearD').addEventListener('click',function(){
      UI.confirm('داده نمونه پاک شود؟',function(){API.post('/panel/demo/clear',{}).then(function(){UI.toast('انجام شد');});});
    });
    document.getElementById('resetB').addEventListener('click',function(){
      UI.typedConfirm('همهٔ داده‌ها (به‌جز تنظیمات و حساب مدیر فعلی) پاک می‌شوند. این عملیات برگشت‌ناپذیر است.',
        'RESET',
        function(){API.post('/panel/reset',{}).then(function(){UI.toast('بازنشانی شد');App.enter();});},
        'بازنشانی کامل');
    });
    document.getElementById('cacheB').addEventListener('click',function(){
      API.post('/panel/cache/clear',{}).then(function(){UI.toast('کش پاک شد');});
    });
    function load(){
      API.get('/panel/backups').then(function(list){
        list=UI.rows(list);
        document.getElementById('tbl').innerHTML=UI.table([
          {label:'نام فایل',key:'name',render:function(r){return '<span class="kbd ltr">'+UI.esc(r.name||r)+'</span>';}},
          {label:'حجم',render:function(r){return r.size?UI.faNum(Math.round(r.size/1024))+' KB':'—';}},
          {label:'تاریخ',render:function(r){return r.created_at?I18N.fmtDateTime(r.created_at):'—';}},
          {label:'عملیات',render:function(r){
            var name=r.name||r;
            return '<div class="row gap1"><a class="btn btn-g btn-sm" href="/panel/backups/'+encodeURIComponent(name)+'/download">'+UI.ic('i-dl')+' دانلود</a>'
              +'<button class="btn btn-s btn-sm" data-restore="'+name+'">بازیابی</button>'
              +'<button class="btn btn-d btn-sm" data-delete="'+name+'">حذف</button></div>';
          }}
        ],list,{emptyText:'پشتیبانی وجود ندارد'});
        root.querySelectorAll('[data-restore]').forEach(function(b){b.addEventListener('click',function(){
          UI.typedConfirm('بازیابی، همهٔ داده‌های فعلی را با نسخهٔ پشتیبان جایگزین می‌کند. تعداد زیادی رکورد جابه‌جا می‌شود.',
            'RESTORE',
            function(){API.post('/panel/backups/'+b.dataset.restore+'/restore',{}).then(function(){UI.toast('بازیابی شد');App.enter();});},
            'بازیابی');
        });});
        root.querySelectorAll('[data-delete]').forEach(function(b){b.addEventListener('click',function(){
          UI.confirm('این پشتیبان حذف شود؟',function(){API.del('/panel/backups/'+b.dataset.delete).then(load);});
        });});
      });
    }
  }
};

/* ---------------- global search ---------------- */
Pages.search={
  title:'جستجو',icon:'i-search',hideNav:true,
  render:function(root,q){
    var initial=(App.query&&App.query.q)||q||'';
    root.innerHTML=pageHead('جستجوی سراسری','در همه مسابقات، سوارکاران، اسب‌ها، باشگاه‌ها و ثبت‌نام‌ها.',null,{noPrint:true})
      +'<div class="qwrap mb4"><input class="inp" id="q" style="max-width:420px" value="'+UI.esc(initial)+'" placeholder="جستجو…"/><button class="btn btn-p" id="go">جستجو</button></div><div id="res"></div>';
    var input=root.querySelector('#q');
    input.focus();
    function run(){
      var term=input.value.trim();
      if(!term){return;}
      API.get('/api/search',{q:term}).then(function(d){
        var out=root.querySelector('#res');
        function block(title,rows,cols,href){
          if(!rows.length){return '';}
          return section(title,UI.table(cols,rows,{rowHref:href,emptyText:'—'}));
        }
        out.innerHTML=(block('مسابقات',d.competitions,[{label:'عنوان',key:'title'},{label:'شهر',key:'city'},{label:'وضعیت',render:function(r){return UI.badge(r.status);}}],function(r){return '/competitions/'+r.id;})
          +block('سوارکاران',d.riders,[{label:'نام',render:function(r){return UI.esc(firstLast(r));}},{label:'موبایل',key:'phone'}],function(r){return '/users/'+r.id;})
          +block('اسب‌ها',d.horses,[{label:'نام',key:'name'},{label:'ریزتراشه',key:'microchip_number'},{label:'مالک',key:'owner_name'}],function(r){return '/horses/'+r.id;})
          +block('باشگاه‌ها',d.clubs,[{label:'نام',key:'name'},{label:'شهر',key:'city'}],function(r){return '/clubs/'+r.id;})
          +block('ثبت‌نام‌ها',d.signups,[{label:'مسابقه',key:'competition_title'},{label:'سوارکار',render:function(r){return UI.esc((r.rider_first||'')+' '+(r.rider_last||''));}},{label:'وضعیت',render:function(r){return UI.badge(r.status);}}],function(r){return '/signups/'+r.id;})
          )||'<div class="panel"><div class="empty">نتیجه‌ای یافت نشد</div></div>';
        bindTableNav(out,function(h){App.go(h);});
      });
    }
    root.querySelector('#go').addEventListener('click',run);
    input.addEventListener('keydown',function(e){if(e.key==='Enter'){run();}});
    if(initial){input.value=initial;run();}
  }
};

window.Pages=Object.assign({},window.Pages,Pages);
})();
