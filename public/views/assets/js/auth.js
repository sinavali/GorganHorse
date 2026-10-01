/* ============================================================================
   Auth screens: password login, OTP login, rider signup, captcha, guest CSRF,
   and the first-run installer wizard. Renders into #app.
   ============================================================================ */
(function(){
'use strict';

/* Fetch the guest login config, which also primes the CSRF token used by the
   state-changing auth requests. */
function loadLoginConfig(){
  return API.get('/auth/login');
}

function captchaBlock(){
  return '<div id="capRow" style="display:none">'
    +'<label class="lbl">کد امنیتی</label>'
    +'<div class="row gap2">'
    +'<img id="capImg" class="capimg" alt="captcha" title="برای تغییر کلیک کنید"/>'
    +'<input class="inp" name="captcha_answer" style="max-width:130px" placeholder="عدد تصویر" inputmode="numeric"/>'
    +'</div><input type="hidden" name="captcha_token"/></div>';
}
function issueCaptcha(root){
  return API.post('/captcha/issue',{}).then(function(d){
    var t=root.querySelector('[name=captcha_token]');
    var img=root.querySelector('#capImg');
    var row=root.querySelector('#capRow');
    if(t&&img&&row){t.value=d.token;img.src=d.image_url+'?t='+Date.now();row.style.display='';}
  }).catch(function(){});
}

function brandPanel(){
  return '<div id="lbrand">'
    +'<svg class="hpat" viewBox="0 0 800 900" preserveAspectRatio="none"><defs><pattern id="hp" width="86" height="86" patternUnits="userSpaceOnUse"><path d="M30 68V40a13 13 0 0 1 26 0v28" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></pattern></defs><rect width="800" height="900" fill="url(#hp)"/></svg>'
    +'<div style="position:relative;max-width:480px">'
    +'<div class="row gap3 mb4"><span class="av" style="width:46px;height:46px;border-radius:14px;background:linear-gradient(140deg,#1d8f5f,#0c3b28);border:1px solid rgba(224,172,58,.4)">'+UI.ic('i-horse')+'</span>'
    +'<div><div style="font-size:17px;font-weight:700;color:#fff">هیئت سوارکاری استان گلستان</div><div class="i12" style="color:#a9c3b5">سامانه مدیریت مسابقات و اسب‌ها</div></div></div>'
    +'<h1 style="font-size:clamp(24px,3vw,36px);line-height:1.25;color:#fff">سامانه یکپارچه ثبت اسب، مسابقات، ثبت‌نام و پرداخت</h1>'
    +'<p class="mt3" style="color:#a9c3b5;line-height:1.9;font-size:13.5px">دفتر نژاد اسب‌ها · مسابقات و رده‌ها · ثبت‌نام آنلاین سوارکاران · پرداخت زرین‌پال · نتایج و رده‌بندی · گزارش‌های مدیریتی · اعلان‌ها و پیامک</p>'
    +'</div></div>';
}

function loginCard(){
  return '<div class="lcard" id="lcard">'
    +'<h2 style="font-size:19px" class="mb2">ورود به سامانه</h2>'
    +'<p class="mut i13 mb4">با نام کاربری/شماره موبایل یا کد یکبارمصرف وارد شوید.</p>'
    +'<div class="row gap1 mb4" style="background:var(--surface-3);padding:4px;border-radius:11px">'
    +'<button class="btn btn-sm btn-p" data-tab="pw" style="flex:1">ورود با رمز</button>'
    +'<button class="btn btn-sm btn-g" data-tab="otp" style="flex:1">ورود با کد پیامکی</button>'
    +'</div>'
    +'<form id="fPw">'
    +'<div class="mb3"><label class="lbl req">نام کاربری یا موبایل</label><input class="inp" name="identifier" autocomplete="username"/></div>'
    +'<div class="mb3"><label class="lbl req">گذرواژه</label><input class="inp" name="password" type="password" autocomplete="current-password"/></div>'
    +captchaBlock()
    +'<button class="btn btn-p btn-w mt3">ورود</button>'
    +'<div class="row jb mt3 i12"><a href="#" data-go="signup">ساخت حساب سوارکار</a><a href="#" data-go="forgot">فراموشی گذرواژه</a></div>'
    +'</form>'
    +'<form id="fOtp" style="display:none">'
    +'<div class="mb3"><label class="lbl req">شماره موبایل</label><input class="inp ltr" name="phone" inputmode="tel" placeholder="09xxxxxxxxx"/></div>'
    +'<button class="btn btn-p btn-w" type="button" id="otpSend">ارسال کد</button>'
    +'<div id="otpStep2" style="display:none">'
    +'<div class="mb3 mt3"><label class="lbl req">کد یکبارمصرف</label><input class="inp ltr" name="code" inputmode="numeric"/></div>'
    +'<button class="btn btn-p btn-w">تأیید و ورود</button></div>'
    +'<p class="hint" id="otpTtl"></p>'
    +'</form>'
    +'</div>';
}

function renderLogin(){
  var app=document.getElementById('app');
  app.innerHTML=UI.sprite+'<div id="login">'+brandPanel()+'<div style="display:grid;place-items:center;padding:20px">'+loginCard()+'</div></div>';
  var card=document.getElementById('lcard');

  card.querySelectorAll('[data-tab]').forEach(function(b){
    b.addEventListener('click',function(){
      var pw=b.dataset.tab==='pw';
      card.querySelector('#fPw').style.display=pw?'':'none';
      card.querySelector('#fOtp').style.display=pw?'none':'';
      card.querySelector('[data-tab=pw]').className='btn btn-sm '+(pw?'btn-p':'btn-g');
      card.querySelector('[data-tab=otp]').className='btn btn-sm '+(pw?'btn-g':'btn-p');
    });
  });
  card.querySelectorAll('[data-go]').forEach(function(a){
    a.addEventListener('click',function(e){
      e.preventDefault();
      if(a.dataset.go==='signup'){renderSignup();}else{renderForgot();}
    });
  });
  card.querySelector('#capImg')&&card.querySelector('#capImg').addEventListener('click',function(){issueCaptcha(card);});

  var fPw=card.querySelector('#fPw');
  /* GET /auth/login primes CSRF and returns the guest configuration. */
  loadLoginConfig().then(function(cfg){
    if(cfg.captcha_on_login){issueCaptcha(fPw);}
    var su=card.querySelector('[data-go=signup]');
    if(su&&!cfg.allow_signup){su.style.display='none';}
  }).catch(function(){});

  fPw.addEventListener('submit',function(e){
    e.preventDefault();
    var btn=fPw.querySelector('button.btn-p');
    btn.disabled=true;
    API.post('/auth/login',UI.formValues(fPw)).then(function(){
      App.enter();
    }).catch(function(err){
      UI.showErrors(fPw,err.errors);
      btn.disabled=false;
      if((err.errors||[]).some(function(er){return er.code==='CAPTCHA_INVALID';})){issueCaptcha(fPw);}
    });
  });

  var fOtp=card.querySelector('#fOtp');
  fOtp.querySelector('#otpSend').addEventListener('click',function(){
    var phone=fOtp.querySelector('[name=phone]').value.trim();
    if(!phone){UI.toast('شماره موبایل الزامی است','w');return;}
    API.post('/auth/login/otp/request',{phone:phone}).then(function(d){
      fOtp.querySelector('#otpStep2').style.display='';
      fOtp.querySelector('#otpTtl').textContent='کد ارسال شد به '+d.phone+' (اعتبار '+UI.faNum(d.ttl)+' ثانیه)';
    }).catch(function(err){UI.showErrors(fOtp,err.errors);});
  });
  fOtp.addEventListener('submit',function(e){
    e.preventDefault();
    var vals=UI.formValues(fOtp);
    API.post('/auth/login/otp/verify',{phone:vals.phone,code:vals.code}).then(function(){
      App.enter();
    }).catch(function(err){UI.showErrors(fOtp,err.errors);});
  });
}

function renderSignup(){
  var app=document.getElementById('app');
  app.innerHTML=UI.sprite+'<div id="login">'+brandPanel()+'<div style="display:grid;place-items:center;padding:20px">'
    +'<div class="lcard"><h2 class="mb2" style="font-size:19px">ساخت حساب سوارکار</h2>'
    +'<p class="mut i13 mb4">پس از ثبت‌نام، حساب شما پس از بازبینی مدیر فعال می‌شود.</p>'
    +'<form id="fSu">'
    +'<div class="frow">'
    +UI.inp('first_name','نام','',null,{req:true})
    +UI.inp('last_name','نام خانوادگی','',null,{req:true})
    +UI.inp('username','نام کاربری','',null,{req:true,ph:'a-z0-9',attrs:' autocomplete="username"'})
    +UI.inp('phone','موبایل','',null,{req:true,ph:'09xxxxxxxxx'})
    +UI.inp('national_id','کد ملی','',null,{ph:'۱۰ رقم'})
    +UI.inp('password','گذرواژه','','password',{req:true})
    +'</div>'
    +captchaBlock()
    +'<button class="btn btn-p btn-w mt4">ثبت‌نام</button>'
    +'<div class="mt3 i12"><a href="#" data-back>بازگشت به ورود</a></div>'
    +'</form></div></div></div>';
  var f=document.getElementById('fSu');
  loadLoginConfig().then(function(cfg){
    if(cfg.captcha_on_signup){issueCaptcha(f);}
  }).catch(function(){});
  f.querySelector('[data-back]').addEventListener('click',function(e){e.preventDefault();renderLogin();});
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var vals=UI.formValues(f);
    vals.role='rider';
    API.post('/auth/signup',vals).then(function(d){
      UI.closeDialog();
      UI.modal('ثبت‌نام انجام شد','<div class="pad-s"><p class="i13">نام کاربری شما: <b>'+UI.esc(d.username)+'</b></p><p class="mut i12 mt2">'+(d.auto_verify_at?('تأیید خودکار: '+UI.esc(d.auto_verify_at)):'حساب شما پس از بازبینی مدیر فعال می‌شود.')+'</p><button class="btn btn-p btn-w mt4" data-close>رفتن به ورود</button></div>');
      var b=document.querySelector('[data-close]');
      if(b){b.addEventListener('click',function(){UI.closeDialog();renderLogin();});}
    }).catch(function(err){UI.showErrors(f,err.errors);});
  });
}

function renderForgot(){
  API.get('/auth/forgot').then(function(d){
    UI.modal('فراموشی گذرواژه','<div class="pad-s"><p class="i13" style="line-height:1.9">'+UI.esc(d.message||'بازنشانی گذرواژه فقط توسط مدیر یا مدیرفنی انجام می‌شود. با پشتیبانی تماس بگیرید.')+'</p><button class="btn btn-g btn-w mt3" data-close>فهمیدم</button></div>');
    document.querySelector('[data-close]').addEventListener('click',UI.closeDialog);
  }).catch(function(){renderLogin();});
}

/* ---------------- installer wizard ---------------- */
function renderInstall(info){
  var app=document.getElementById('app');
  var reqs=(info&&info.requirements)||[];
  var bad=reqs.filter(function(r){return !r.ok;});
  var installed=info&&info.installed;
  app.innerHTML=UI.sprite+'<div id="login">'+brandPanel()+'<div style="display:grid;place-items:center;padding:20px;width:100%">'
    +'<div class="lcard" style="max-width:560px"><h2 class="mb2" style="font-size:19px">راه‌اندازی اولیه سامانه</h2>'
    +'<p class="mut i13 mb4">پیش از استفاده، حساب مدیر ارشد را بسازید.</p>'
    +(installed?'<div class="panel soft pad-s mb3 i13">سامانه قبلاً نصب شده است. <a href="#" data-relogin>ورود</a></div>':'')
    +'<div class="panel soft pad-s mb3"><b class="i13">بررسی پیش‌نیازها</b><div class="mt2">'
    +reqs.map(function(r){return '<div class="row jb gap2 i12" style="padding:3px 0"><span>'+UI.esc(r.label)+'</span><span class="'+(r.ok?'b-ok':'b-bad')+'">'+UI.esc(r.detail)+'</span></div>';}).join('')
    +'</div></div>'
    +'<form id="fInst">'
    +'<div class="frow">'
    +UI.inp('admin_first_name','نام مدیر','',null,{req:true})
    +UI.inp('admin_last_name','نام خانوادگی مدیر','',null,{req:true})
    +UI.inp('admin_username','نام کاربری مدیر',info&&info.installed?'admin':'',null,{req:true,attrs:' autocomplete="username"'})
    +UI.inp('admin_phone','موبایل مدیر','',null,{req:true,ph:'09xxxxxxxxx'})
    +UI.inp('admin_password','گذرواژه مدیر','','password',{req:true})
    +'</div>'
    +'<label class="row gap2 i13 mt3"><input type="checkbox" name="seed_demo"/> بارگذاری داده‌های نمونه</label>'
    +'<button class="btn btn-p btn-w mt4"'+(bad.length||installed?' disabled':'')+'>نصب و شروع</button>'
    +(bad.length?'<p class="hint" style="color:var(--danger)">ابتدا پیش‌نیازهای ناموفق را برطرف کنید.</p>':'')
    +'</form>'
    +'<div class="mt3 i12"><a href="#" data-relogin>حساب مدیر دارم — ورود</a></div>'
    +'</div></div></div>';
  var a=app.querySelector('[data-relogin]');
  if(a){a.addEventListener('click',function(e){e.preventDefault();location.href='/';});}
  var f=document.getElementById('fInst');
  f.addEventListener('submit',function(e){
    e.preventDefault();
    var v=UI.formValues(f);
    API.post('/install/run',v).then(function(){
      UI.toast('نصب با موفقیت انجام شد');
      /* The app.installed flag now flipped: reload so the shell boots
         against a real session instead of the cached install screen. */
      setTimeout(function(){location.href='/auth/login';},600);
    }).catch(function(err){UI.showErrors(f,err.errors);});
  });
}

window.Auth={renderLogin:renderLogin,renderSignup:renderSignup,renderInstall:renderInstall,loadLoginConfig:loadLoginConfig};
})();
