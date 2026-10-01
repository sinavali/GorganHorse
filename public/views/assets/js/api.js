/* ============================================================================
   API client for the GorganHorse JSON backend.

   The API is served at the site root (/auth/*, /panel/*, /api/*, /install,
   /payment/*), so every path is requested as-is. Every response is an envelope
   { ok, data, errors, meta } and CSRF is read from envelopes/meta and echoed on
   state-changing requests via the X-CSRF-Token header.

   Exposes window.API.
   ============================================================================ */
(function(){
'use strict';

var csrf=null;
var listeners=[];
/* A single in-flight probe decides whether the session is really gone. Several
   grid requests fail at once when a session dies; without this guard each one
   would re-render the login screen and clobber whatever the user was doing. */
var authProbe=null;
var lastAuthFailure=0;

function getCookie(name){
  var m=document.cookie.match(new RegExp('(?:^|; )'+name+'=([^;]*)'));
  return m?decodeURIComponent(m[1]):'';
}
function notify401(){
  /* Re-check with /panel/profile before tearing the UI down: a single 401 from
     a background refresh (bell, palette, secondary grid) must not log the user
     out of a perfectly valid session. */
  if(authProbe){return;}
  if(Date.now()-lastAuthFailure<3000){return;}
  lastAuthFailure=Date.now();
  authProbe=fetch('/panel/profile',{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(function(r){return r.ok;})
    .catch(function(){return false;})
    .then(function(alive){
      authProbe=null;
      if(alive){return;}
      listeners.forEach(function(fn){try{fn();}catch(e){}});
    });
}
function on401(fn){listeners.push(fn);}

function headers(token,extra){
  var h=extra||{};
  if(token){h['X-CSRF-Token']=token;}
  return h;
}
function currentToken(){return csrf||getCookie('guest_csrf');}

function parse(json,res){
  if(json&&json.meta){API.meta=json.meta;}
  /* The envelope contract places the CSRF token at the top level (Envelope::ok
     lifts meta['csrf'] to 'csrf'); meta.csrf is accepted for legacy safety. */
  if(json&&json.csrf){csrf=json.csrf;}
  else if(json&&json.meta&&json.meta.csrf){csrf=json.meta.csrf;}
  if(res.status===401){
    notify401();
    throw {status:401,errors:(json&&json.errors)||[{code:'AUTH_SESSION_EXPIRED',message:'نشست منقضی شده است'}]};
  }
  if(!res.ok||!json||(json.ok===false)){
    throw {
      status:res.status,
      errors:(json&&json.errors)||[{code:'SERVER_ERROR',message:'خطای سرور ('+res.status+')'}]
    };
  }
  /* Endpoints may legitimately answer with `data: null`. Grids and detail
     pages read properties off the result, so always hand back an object. */
  return json.data===null||json.data===undefined?{}:json.data;
}async function request(method,path,body,retried){

  var opts={method:method,headers:{},credentials:'same-origin'};
  if(body!==undefined&&body!==null){
    if(body instanceof FormData){
      opts.body=body;
    }else{
      opts.headers['Content-Type']='application/json';
      opts.body=JSON.stringify(body);
    }
  }
  if(method!=='GET'){opts.headers['X-CSRF-Token']=currentToken();}
  var res;
  try{
    res=await fetch(path,opts);
  }catch(e){
    throw {status:0,errors:[{code:'NETWORK',message:'خطای شبکه — اتصال برقرار نشد'}]};
  }
  var json=null;
  try{json=await res.json();}catch(e){json=null;}
  var isCsrf=res.status===403&&(json&&json.errors||[]).some(function(er){return er.code==='AUTH_CSRF_INVALID';});
  /* parse() stores meta.csrf (including from error envelopes) before throwing. */
  try{parse(json,res);}catch(err){
    /* Stale/missing CSRF token: adopt the fresh token the server returned and
       retry once. Covers expired guest cookies and pre-fix cached shells. */
    if(isCsrf&&!retried&&method!=='GET'&&csrf){
      return request(method,path,body,true);
    }
    throw err;
  }
  return json.data;
}

/* Download a binary response (CSV exports, backups, template files). */
async function fetchBlob(method,path,body){
  var opts={method:method,headers:{},credentials:'same-origin'};
  if(body!==undefined&&body!==null){
    if(body instanceof FormData){opts.body=body;}
    else{opts.headers['Content-Type']='application/json';opts.body=JSON.stringify(body);}
  }
  if(method!=='GET'){opts.headers['X-CSRF-Token']=currentToken();}
  var res;
  try{res=await fetch(path,opts);}catch(e){
    throw {status:0,errors:[{code:'NETWORK',message:'خطای شبکه — اتصال برقرار نشد'}]};
  }
  if(res.status===401){notify401();throw {status:401,errors:[{code:'AUTH_SESSION_EXPIRED',message:'نشست منقضی شده است'}]};}
  if(!res.ok){
    var msg='خطای سرور ('+res.status+')';
    throw {status:res.status,errors:[{code:'SERVER_ERROR',message:msg}]};
  }
  var dispo=res.headers.get('Content-Disposition')||'';
  var m=dispo.match(/filename\*?=(?:UTF-8''|")?([^\";]+)/i);
  var filename=m?decodeURIComponent(m[1].replace(/"/g,'')):('download-'+Date.now());
  return {blob:await res.blob(),filename:filename};
}

function triggerDownload(blob,filename){
  var url=URL.createObjectURL(blob);
  var a=document.createElement('a');
  a.href=url;a.download=filename||'download';
  document.body.appendChild(a);a.click();a.remove();
  setTimeout(function(){URL.revokeObjectURL(url);},2000);
}

function qs(params){
  if(!params){return '';}
  var parts=[];
  Object.keys(params).forEach(function(k){
    var v=params[k];
    if(v===undefined||v===null||v===''){return;}
    parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v));
  });
  return parts.length?('?'+parts.join('&')):'';
}

var API={
  meta:null,
  on401:on401,
  get:function(path,params){return request('GET',path+qs(params));},
  post:function(path,body){return request('POST',path,body);},
  put:function(path,body){return request('PUT',path,body);},
  del:function(path,body){return request('DELETE',path,body!==undefined?body:{});},
  /* Upload a File under `field` (default "file") with optional extra fields. */
  upload:function(path,file,extra,field){
    var fd=new FormData();
    fd.append(field||'file',file);
    if(extra){Object.keys(extra).forEach(function(k){if(extra[k]!==undefined&&extra[k]!==null){fd.append(k,extra[k]);}});}
    return request('POST',path,fd);
  },
  /* Download a CSV/binary response and hand the blob to the caller. */
  download:function(method,path,body){return fetchBlob(method,path,body).then(function(r){triggerDownload(r.blob,r.filename);return r;});},
  triggerDownload:triggerDownload,
  csrf:currentToken
};

window.API=API;
})();
