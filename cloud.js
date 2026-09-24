/* ═══════════════════════════════════════════════════════════════
   ONLINE MODE — login, group codes, shared contract, research events.
   Talks to the PHP backend in api/ on your own hosting.
   Active only when config.js has API_URL. Otherwise the app runs in
   the local pilot mode (no login, data stays in the browser).
   ═══════════════════════════════════════════════════════════════ */
(function(){
  const cfg = window.CC_CONFIG || {};
  if(cfg.API_URL==='auto'){
    const h=location.hostname;
    cfg.API_URL = (location.protocol.startsWith('http') && !h.endsWith('github.io')) ? 'api/' : '';
  }
  if(!cfg.API_URL){ window.CLOUD = null; return; }

  const C = window.CLOUD = { user:null, group:null, version:0, saving:false, pending:false, timer:null };
  const $ = id => document.getElementById(id);
  function msg(id, text, bad){ const el=$(id); if(!el) return; el.textContent=text||''; el.style.color = bad?'var(--danger)':'var(--accent-2)'; }
  function status(text){ const el=$('cloudStatus'); if(el) el.textContent=text; }

  async function api(action, body){
    let res;
    try{
      res = await fetch(cfg.API_URL.replace(/\/?$/,'/') + '?a=' + action, {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body||{}) });
    }catch(e){ return { error:'offline', status:0 }; }
    let data = {}; try{ data = await res.json(); }catch(e){}
    if(!res.ok) return { error: data.error || ('HTTP '+res.status), status:res.status };
    return data;
  }
  C.api = api;

  /* ---------- AUTH ---------- */
  C.signIn = async function(){
    const email=$('authEmail').value.trim(), pw=$('authPw').value;
    if(!email||!pw) return msg('authMsg','Please enter e-mail and password.',true);
    msg('authMsg','Signing in…');
    const r = await api('login', { email, password:pw });
    if(r.error) return msg('authMsg', r.error, true);
    afterLogin();
  };
  C.signUp = async function(){
    const email=$('authEmail').value.trim(), pw=$('authPw').value;
    if(!email||pw.length<8) return msg('authMsg','Use your e-mail and a password with at least 8 characters.',true);
    const r = await api('register', { email, password:pw });
    if(r.error) return msg('authMsg', r.error, true);
    afterLogin();
  };
  C.signOut = async function(){
    await flushNow();
    await api('logout');
    ['cc_state','cc_events','cc_seq','cc_outbox'].forEach(k=>localStorage.removeItem(k));
    location.href = location.pathname;
  };

  /* ---------- GROUP ---------- */
  C.join = async function(){
    const code=$('joinCode').value.trim().toUpperCase();
    if(!code) return msg('joinMsg','Enter the group code from your teacher.',true);
    if(!$('joinConsent').checked) return msg('joinMsg','Please tick the consent box to continue.',true);
    const r = await api('join', { code });
    if(r.error) return msg('joinMsg', r.error, true);
    await openGroup(r.group);
  };

  async function afterLogin(){
    const r = await api('me');
    if(r.error==='offline'){ msg('authMsg','No connection to the server. Try again.',true); window.go('auth'); return; }
    if(!r.user){ window.go('auth'); return; }
    C.user = r.user;
    document.querySelectorAll('.whoami').forEach(e=>e.textContent=r.user.email);
    if(r.groups && r.groups.length) await openGroup(r.groups[0]);
    else window.go('join');
  }

  async function openGroup(g){
    C.group = g;
    const r = await api('contract_get', { group:g.id });
    if(r.error){ alert('Could not load the contract: '+r.error); return; }
    const fresh = window.newState();
    if(r.state && Object.keys(r.state).length){ Object.assign(S, fresh, r.state); }
    else Object.assign(S, fresh);
    C.version = r.version||0;
    if(!S.groupName) S.groupName = g.name||'';
    S.course = g.course||S.course||''; S.cohort = g.cohort||S.cohort||'';
    S.sessionId = 'g'+g.id;   // one research session per group
    if(!S.members.some(m=>m.userId===C.user.id)){
      S.members.push({ playerId:window.uuid(), userId:C.user.id, name:C.user.name||C.user.email.split('@')[0],
        consent:true, consentTs:new Date().toISOString() });
      window.emit('consent_ack', { consentVersion:'2.1' });
      queueSave(true);
    }
    localStorage.setItem('cc_state', JSON.stringify(S));
    document.querySelectorAll('.groupcode').forEach(e=>e.textContent=g.code);
    status('✓ saved online');
    window.boot();
  }

  /* ---------- SAVE (debounced, version check, auto-merge) ---------- */
  function queueSave(now){
    C.pending = true; status('… saving');
    clearTimeout(C.timer); C.timer = setTimeout(flushNow, now?0:1200);
  }
  async function flushNow(){
    if(!C.group || !C.pending) return;
    if(C.saving){ C.timer=setTimeout(flushNow, 600); return; }
    C.saving=true; C.pending=false;
    const r = await api('contract_save', { group:C.group.id, state:S, expected:C.version });
    C.saving=false;
    if(r.error){
      if(r.status===409){
        const d = await api('contract_get', { group:C.group.id });
        Object.assign(S, mergeStates(d.state||{}, JSON.parse(JSON.stringify(S))));
        C.version = d.version||0; localStorage.setItem('cc_state', JSON.stringify(S));
        status('↻ merged with a teammate'); C.pending=true; C.timer=setTimeout(flushNow, 200);
        refreshScreen();
      } else if(r.status===401){ status('⚠ signed out'); window.go('auth'); }
      else { status('⚠ offline, will retry'); C.pending=true; C.timer=setTimeout(flushNow, 5000); }
      return;
    }
    C.version = r.version; status('✓ saved online');
  }
  // Remote wins where local is empty; local wins where it has a value. Members are unioned.
  function mergeStates(remote, local){
    function m(r, l){
      if(Array.isArray(l) || Array.isArray(r)) return (l && l.length) ? l : (r||[]);
      if(l && typeof l==='object' && r && typeof r==='object'){
        const o={...r}; Object.keys(l).forEach(k=>{ o[k]=m(r[k], l[k]); }); return o;
      }
      return (l!==undefined && l!=='' && l!==null) ? l : r;
    }
    const out = m(remote, local);
    const byId = {}; (remote.members||[]).concat(local.members||[]).forEach(x=>{ byId[x.userId||x.playerId]=x; });
    out.members = Object.values(byId);
    out.step = local.step; out.done = !!(remote.done||local.done); out.started = !!(remote.started||local.started);
    return out;
  }
  function refreshScreen(){
    const a=document.activeElement; if(a && (a.tagName==='TEXTAREA'||a.tagName==='INPUT')) return;
    if(!$('screen-setup').classList.contains('hidden')) window.renderMembers();
    else if(!$('screen-steps').classList.contains('hidden')) window.renderStep();
    else if(!$('screen-canvas').classList.contains('hidden')) window.renderCanvas();
  }
  C.save = ()=>{ if(C.group) queueSave(false); };
  C.reload = async ()=>{
    await flushNow();
    const d = await api('contract_get', { group:C.group.id });
    if(!d.error){ Object.assign(S, mergeStates(d.state||{}, JSON.parse(JSON.stringify(S)))); C.version=d.version||0; localStorage.setItem('cc_state', JSON.stringify(S)); }
    refreshScreen(); status('✓ up to date');
  };

  /* ---------- RESEARCH EVENTS: outbox → server ---------- */
  C.flushEvents = async function(){
    if(!C.group) return;
    const out = JSON.parse(localStorage.getItem('cc_outbox')||'[]');
    if(!out.length) return;
    const r = await api('events', { group:C.group.id, rows:out });
    if(!r.error){
      const sent=new Set(out.map(e=>e.seq));
      localStorage.setItem('cc_outbox', JSON.stringify(JSON.parse(localStorage.getItem('cc_outbox')||'[]').filter(e=>!sent.has(e.seq))));
    }
  };

  window.addEventListener('pagehide', ()=>{ if(C.pending) flushNow(); });
  C.start = afterLogin;
})();
