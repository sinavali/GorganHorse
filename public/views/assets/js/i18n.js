/* ============================================================================
   i18n + Jalali calendar + Persian digits + date formatting.
   Exposes window.I18N. Default fa-IR (RTL). Also en-US (LTR).
   Jalali conversion follows the standard 33-year cycle algorithm.
   ============================================================================ */
(function(){
'use strict';

var JALALI_MONTHS=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
var JALALI_MONTHS_EN=['Farvardin','Ordibehesht','Khordad','Tir','Mordad','Shahrivar','Mehr','Aban','Azar','Dey','Bahman','Esfand'];
var WEEK=['ش','ی','د','س','چ','پ','ج'];
var FA_DIGITS='۰۱۲۳۴۵۶۷۸۹';

function div(a,b){return Math.floor(a/b);}
function jalaliToGregorian(jy,jm,jd){
  jy+=1595;
  var days=-355668+(365*jy)+(~~(jy/33)*8)+~~(((jy%33)+3)/4)+jd+((jm<7)?(jm-1)*31:((jm-7)*30)+186);
  var gy=400*div(days,146097);
  days%=146097;
  if(days>36524){
    gy+=100*div(--days,36524);
    days%=36524;
    if(days>=365){days++;}
  }
  gy+=4*div(days,1461);
  days%=1461;
  if(days>365){gy+=div(days-1,365);days=(days-1)%365;}
  var gd=days+1;
  var sal_a=[0,31,(gy%4===0&&gy%100!==0)||(gy%400===0)?29:28,31,30,31,30,31,31,30,31,30,31];
  var gm;
  for(gm=0;gm<13&&gd>sal_a[gm];gm++){gd-=sal_a[gm];}
  return [gy,gm,gd];
}
function gregorianToJalali(gy,gm,gd){
  var g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
  var jy=(gy<=1600)?0:979;
  gy-=(gy<=1600)?621:1600;
  var gy2=(gm>2)?(gy+1):gy;
  var days=(365*gy)+div(gy2+3,4)-div(gy2+99,100)+div(gy2+399,400)-80+gd+g_d_m[gm-1];
  jy+=33*div(days,12053);
  days%=12053;
  jy+=4*div(days,1461);
  days%=1461;
  if(days>365){jy+=div(days-1,365);days=(days-1)%365;}
  var jm=(days<186)?1+div(days,31):7+div(days-186,30);
  var jd=1+((days<186)?(days%31):((days-186)%30));
  return [jy,jm,jd];
}

function I18N(code){this.set(code);}
I18N.prototype.set=function(code){
  this.code=(code==='en-US')?'en-US':'fa-IR';
  this.rtl=this.code==='fa-IR';
  document.documentElement.lang=this.code;
  document.documentElement.dir=this.rtl?'rtl':'ltr';
  document.documentElement.classList.toggle('rtl',this.rtl);
  document.documentElement.setAttribute('data-lang',this.code);
  try{localStorage.setItem('ghf.lang',this.code);}catch(e){}
};
I18N.prototype.t=function(key){
  return this.rtl?key:key;
};
I18N.prototype.num=function(v){
  var s=String(v==null?'':v);
  if(!this.rtl){return s;}
  return s.replace(/[0-9]/g,function(d){return FA_DIGITS[+d];});
};
I18N.prototype.latn=function(s){return String(s==null?'':s).replace(/[۰-۹]/g,function(d){return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));});};
I18N.prototype.isRTL=function(){return this.rtl;};

/* ISO (UTC) date string -> Jalali parts (in the panel timezone) */
I18N.TZ='Asia/Tehran';
I18N.TZ_OFFSET_MIN=210; /* Iran: UTC+3:30, no DST since 2022 */
I18N.prototype.local=function(iso){
  if(!iso){return null;}
  var d=new Date(iso.length===10?iso+'T00:00:00Z':iso);
  if(isNaN(d.getTime())){return null;}
  return new Date(d.getTime()+I18N.TZ_OFFSET_MIN*60000);
};
I18N.prototype.jParts=function(iso){
  var d=this.local(iso);
  if(!d){return null;}
  return gregorianToJalali(d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate());
};
I18N.prototype.gParts=function(iso){
  var d=this.local(iso);
  if(!d){return null;}
  return [d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate()];
};
I18N.prototype.fmtDate=function(iso){
  var p=this.jParts(iso);
  if(!p){return '—';}
  return this.num(p[0]+'/'+String(p[1]).padStart(2,'0')+'/'+String(p[2]).padStart(2,'0'));
};
I18N.prototype.fmtDateLong=function(iso){
  var p=this.jParts(iso);
  if(!p){return '—';}
  return this.num(p[2])+' '+(this.rtl?JALALI_MONTHS[p[1]-1]:JALALI_MONTHS_EN[p[1]-1])+' '+this.num(p[0]);
};
/* Gregorian long date (used when the active culture is not fa-IR). */
I18N.prototype.fmtDateLongG=function(iso){
  var g=this.gParts(iso);
  if(!g){return '—';}
  var m=['January','February','March','April','May','June','July','August','September','October','November','December'];
  return this.num(g[2])+' '+m[g[1]-1]+' '+this.num(g[0]);
};
I18N.prototype.fmtDateTime=function(iso){
  if(!iso){return '—';}
  var d=this.local(iso);
  if(!d){return '—';}
  var hm=String(d.getUTCHours()).padStart(2,'0')+':'+String(d.getUTCMinutes()).padStart(2,'0');
  return this.fmtDate(iso)+' '+this.num(hm);
};
/* Coarse "time ago" label for notification and message feeds, where an exact
   timestamp is less useful than "۳ ساعت پیش". Falls back to fmtDateTime once
   the gap stops being interesting. */
I18N.prototype.fmtRelative=function(iso){
  if(!iso){return '—';}
  var d=this.local(iso);
  if(!d){return '—';}
  var secs=Math.floor((Date.now()-d.getTime())/1000);
  if(secs<0){return this.fmtDateTime(iso);}
  var m=Math.floor(secs/60), h=Math.floor(m/60), day=Math.floor(h/24);
  if(m<1){return this.rtl?'همین حالا':'just now';}
  if(m<60){return this.rtl?this.num(m)+' دقیقه پیش':this.num(m)+'m ago';}
  if(h<24){return this.rtl?this.num(h)+' ساعت پیش':this.num(h)+'h ago';}
  if(day<7){return this.rtl?this.num(day)+' روز پیش':this.num(day)+'d ago';}
  return this.fmtDate(iso);
};
/* Jalali (or Gregorian) <-> ISO for picker inputs, honouring Tehran time. */
I18N.prototype.toIso=function(y,m,d,h,min){
  var g=this.rtl?jalaliToGregorian(y,m,d):[y,m,d];
  var hh=String(h==null?0:h).padStart(2,'0');
  var mm=String(min==null?0:min).padStart(2,'0');
  var utc=new Date(Date.UTC(g[0],g[1]-1,g[2],+hh,+min||0)-I18N.TZ_OFFSET_MIN*60000);
  return utc.toISOString().replace(/\.\d+Z$/,'Z');
};
I18N.prototype.fromIso=function(iso){
  var d=this.local(iso);
  if(!d){return null;}
  return {
    y:this.rtl?gregorianToJalali(d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate())[0]:d.getUTCFullYear(),
    m:this.rtl?gregorianToJalali(d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate())[1]:d.getUTCMonth()+1,
    d:this.rtl?gregorianToJalali(d.getUTCFullYear(),d.getUTCMonth()+1,d.getUTCDate())[2]:d.getUTCDate(),
    h:d.getUTCHours(),
    min:d.getUTCMinutes()
  };
};
I18N.prototype.monthLength=function(y,m){
  if(!this.rtl){
    return [31,28,31,30,31,30,31,31,30,31,30,31][m-1];
  }
  if(m<=6){return 31;}
  if(m<=11){return 30;}
  return ((y%33)%4===1)?30:29;
};
I18N.prototype.money=function(v){
  var n=Math.round(Number(v)||0);
  return this.num(n.toLocaleString('en-US'))+(this.rtl?' تومان':' Toman');
};
I18N.prototype.todayJalali=function(){
  var now=new Date(Date.now()+I18N.TZ_OFFSET_MIN*60000);
  var p=gregorianToJalali(now.getUTCFullYear(),now.getUTCMonth()+1,now.getUTCDate());
  return p[0]+'/'+String(p[1]).padStart(2,'0')+'/'+String(p[2]).padStart(2,'0');
};
I18N.prototype.months=function(){
  if(this.rtl){return JALALI_MONTHS;}
  return ['January','February','March','April','May','June','July','August','September','October','November','December'];
};
I18N.prototype.monthNames=function(){return this.rtl?JALALI_MONTHS:JALALI_MONTHS_EN;};
I18N.prototype.week=function(){return this.rtl?WEEK:['S','M','T','W','T','F','S'];};
I18N.prototype.J2G=function(jy,jm,jd){return jalaliToGregorian(jy,jm,jd);};
I18N.prototype.G2J=function(gy,gm,gd){return gregorianToJalali(gy,gm,gd);};

var inst=new I18N((function(){try{return localStorage.getItem('ghf.lang');}catch(e){return null;}})());

window.I18N=inst;
window.j2g=jalaliToGregorian;
window.g2j=gregorianToJalali;
})();
