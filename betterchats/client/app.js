'use strict';
import { createE2E, safetyNumber, b64, unb64 } from './e2e.js';
/*
 * BetterChats client.
 *
 * - Per page load: a fresh ECDH P-256 key pair (private key non-extractable) AND a fresh
 *   ML-KEM-768 post-quantum key pair. Both exist only in this tab's memory.
 * - Hybrid key agreement per contact and direction: ECDH secret + ML-KEM shared secret -> HKDF.
 *   An attacker has to break BOTH (classic and quantum-safe) to read anything.
 * - Every message uses its own key from a hash ratchet; used keys are wiped immediately
 *   (forward secrecy, replays can't be decrypted twice).
 * - AES-256-GCM, padding to 256-byte blocks; every WebSocket frame is additionally padded to a
 *   fixed size and mixed with random cover traffic, so even the encrypted connection doesn't
 *   reveal when or how much you write.
 * - Chat history in RAM is only kept encrypted; contacts and chats vanish on reload.
 *   No cookies, no localStorage, no IndexedDB.
 * - All content is inserted with textContent (never innerHTML) + Trusted Types CSP.
 * - Login uses OPAQUE (RFC 9807): the password never leaves this device, not even hashed.
 *   Optionally the server's OPAQUE key is pinned (apps), so a fake server is detected.
 */
(() => {
  const $ = (id) => document.getElementById(id);
  const enc = new TextEncoder();
  const dec = new TextDecoder();
  const NAME_RE = /^[a-z0-9_]{3,20}$/;
  const IDLE_WIPE_MS = 30 * 60_000;
  const BUCKETS = [4096, 8192, 16384, 32768];
  const TTL_LABEL = { 0: 'aus', 30: '30 s', 300: '5 min', 3600: '1 h' };


  const CFG = window.BC_CONFIG || {};           // apps: { server: 'wss://…', serverKey: '…' }
  const PW_MIN = 6, PW_MAX = 128;
  if (!window.isSecureContext || !window.crypto?.subtle || !window.BCPQ || !window.BCOPAQUE_READY) {
    $('authErr').textContent = 'Unsichere Verbindung: BetterChats funktioniert nur über HTTPS (oder als .onion).';
    $('authBtn').disabled = true;
    return;
  }

  // ---------- state (memory only) ----------
  let ws = null;
  let keyPair = null;
  let myPub = '';               // "<ecdh>.<mlkem>" public keys
  let kem = null;               // ML-KEM-768 key pair
  let me = '';
  let mode = 'login';
  let active = null;
  const chats = new Map();     // name -> { pub, online, msgs, unread, safety }
  let e2e = null;               // end-to-end engine (client/e2e.js), created after login
  const lookups = new Map();   // name -> [resolve]
  let vaultKey = null;         // per-tab AES key: chat history in RAM is only kept encrypted

  // ---------- RAM vault ----------
  async function seal(text) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, vaultKey, enc.encode(text));
    return { iv, ct };
  }
  async function unseal(box) {
    return dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: box.iv }, vaultKey, box.ct));
  }

  // ---------- crypto ----------
  async function makeKeys() {
    keyPair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
    kem = BCPQ.keygen();
    myPub = b64(await crypto.subtle.exportKey('raw', keyPair.publicKey)) + '.' + b64(kem.publicKey);
    vaultKey = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }

  // ---------- socket ----------
  // Tunnel: on top of TLS every frame is encrypted again (fresh ECDH per connection,
  // AES-256-GCM, counter nonces). DevTools -> Network only shows equal-sized binary blobs.
  let tunnel = null;
  const nonce = (n) => { const b = new Uint8Array(12); new DataView(b.buffer).setBigUint64(4, n); return b; };
  let sendQ = Promise.resolve();
  let recvQ = Promise.resolve();
  function connect() {
    return new Promise((resolve, reject) => {
      ws = new WebSocket(CFG.server || `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/`);
      ws.binaryType = 'arraybuffer';
      ws.onerror = () => reject(new Error('Keine Verbindung zum Server.'));
      ws.onclose = () => { tunnel = null; if (me) wipe('Verbindung getrennt. Aus Sicherheitsgründen wurde alles gelöscht.'); else reject(new Error('Verbindung abgelehnt.')); };
      ws.onmessage = async (e) => {
        if (!tunnel) {
          let h; try { h = JSON.parse(e.data); } catch { return ws.close(); }
          if (!h || h.t !== 'hello' || typeof h.spub !== 'string') return ws.close();
          const kp = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
          const spub = await crypto.subtle.importKey('raw', unb64(h.spub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
          const shared = await crypto.subtle.deriveBits({ name: 'ECDH', public: spub }, kp.privateKey, 256);
          const hk = await crypto.subtle.importKey('raw', shared, 'HKDF', false, ['deriveBits']);
          const okm = new Uint8Array(await crypto.subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: enc.encode('BetterChats tunnel v1') }, hk, 512));
          const imp = (b) => crypto.subtle.importKey('raw', b, 'AES-GCM', false, ['encrypt', 'decrypt']);
          tunnel = { c2s: await imp(okm.slice(0, 32)), s2c: await imp(okm.slice(32)), outN: 0n, inN: 0n };
          okm.fill(0);
          ws.send(JSON.stringify({ t: 'hello', cpub: b64(await crypto.subtle.exportKey('raw', kp.publicKey)) }));
          return resolve();
        }
        if (!(e.data instanceof ArrayBuffer)) return ws.close();
        const iv = nonce(tunnel.inN++);
        const t = tunnel;
        recvQ = recvQ.then(async () => {
          let m;
          try { m = JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, t.s2c, e.data))); } catch { return ws.close(); }
          if (m && typeof m === 'object' && m.t !== 'c') await onServer(m);
        }).catch(() => {});
      };
    });
  }
  // every frame padded to a fixed bucket size -> an observer can't tell a message from a lookup or noise
  function frame(obj) {
    const base = enc.encode(JSON.stringify({ ...obj, _: '' })).length;
    const size = BUCKETS.find((b) => b >= base) || base;
    return enc.encode(JSON.stringify({ ...obj, _: '0'.repeat(size - base) }));
  }
  function sendWs(o) {
    if (!ws || ws.readyState !== 1 || !tunnel) return;
    const t = tunnel, iv = nonce(t.outN++), data = frame(o);
    sendQ = sendQ.then(async () => { const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, t.c2s, data); if (ws.readyState === 1) ws.send(ct); }).catch(() => {});
  }
  // cover traffic: random dummy frames so real messages don't stand out in timing
  (function cover() {
    setTimeout(() => { if (me) sendWs({ t: 'c' }); cover(); }, 800 + Math.floor(-Math.log(1 - Math.random()) * 2500));
  })();
  const B64 = /^[A-Za-z0-9+/]+={0,2}$/;
  const peerKeyOf = (o) => (typeof o.pub === 'string' && typeof o.kpub === 'string' && B64.test(o.pub) && B64.test(o.kpub) ? o.pub + '.' + o.kpub : null);

  function lookup(name) {
    return new Promise((resolve) => {
      if (!lookups.has(name)) lookups.set(name, []);
      lookups.get(name).push(resolve);
      sendWs({ t: 'lookup', name });
    });
  }

  // request/response helper for the multi-step OPAQUE flows
  let pending = null;
  function request(obj, types) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => { pending = null; reject(new Error('Server antwortet nicht.')); }, 20_000);
      pending = { types, resolve: (m) => { clearTimeout(timer); pending = null; resolve(m); } };
      sendWs(obj);
    });
  }
  const nextFrame = () => new Promise((r) => requestAnimationFrame(() => setTimeout(r, 0)));
  function checkServerKey(key) {
    if (CFG.serverKey && key !== CFG.serverKey) {
      try { ws.close(); } catch {}
      throw new Error('⚠ Server-Identität stimmt nicht! Möglicher Angriff – Verbindung abgebrochen.');
    }
  }
  async function opaqueRegister(name, password, pub, kpub) {
    const O = BCOPAQUE;
    const { clientRegistrationState, registrationRequest } = O.client.startRegistration({ password });
    const r1 = await request({ t: 'reg1', name, req: registrationRequest }, ['reg1', 'err']);
    if (r1.t === 'err') return r1;
    await nextFrame();
    const { registrationRecord, serverStaticPublicKey } = O.client.finishRegistration({ clientRegistrationState, registrationResponse: r1.res, password });
    checkServerKey(serverStaticPublicKey);
    return request({ t: 'reg2', rec: registrationRecord, pub, kpub }, ['ok', 'err']);
  }
  // proves the password without ever sending it; returns finishLoginRequest or null (wrong password)
  async function opaqueProve(step1, password, extra = {}) {
    const O = BCOPAQUE;
    const { clientLoginState, startLoginRequest } = O.client.startLogin({ password });
    const r1 = await request({ ...extra, t: step1, req: startLoginRequest }, [step1, 'err', 'delete']);
    if (r1.t !== step1) return { err: r1.msg || 'Fehler.' };
    await nextFrame();
    const res = O.client.finishLogin({ clientLoginState, loginResponse: r1.res, password });
    if (!res) return { err: 'Name oder Passwort falsch.' };
    checkServerKey(res.serverStaticPublicKey);
    return { fin: res.finishLoginRequest };
  }

  async function onServer(m) {
    if (pending && pending.types.includes(m.t)) return pending.resolve(m);
    switch (m.t) {
      case 'kicked':
        wipe('Du hast dich woanders angemeldet. Diese Sitzung wurde beendet und alles gelöscht.');
        break;
      case 'lookup': {
        if (typeof m.name !== 'string') return;
        if (chats.has(m.name) && m.exists) setPeer(m.name, peerKeyOf(m), m.online);
        (lookups.get(m.name) || []).forEach((r) => r(m));
        lookups.delete(m.name);
        break;
      }
      case 'msg': {
        const pk = peerKeyOf(m);
        if (typeof m.from !== 'string' || !NAME_RE.test(m.from) || !pk || typeof m.k !== 'string') return;
        try {
          const o = await e2e.decryptFrom(m.from, pk, m.k, m.n, m.iv, m.ct);
          const text = o.m, ttl = TTL_LABEL[o.ttl] ? o.ttl : 0;
          const c = ensureChat(m.from);
          await setPeer(m.from, pk, true);
          await addMsg(m.from, { who: 'theirs', ttl }, text);
          if (active !== m.from || document.hidden) { c.unread++; renderList(); }
        } catch {
          if (chats.has(m.from)) addMsg(m.from, { who: 'sys' }, 'Eine ungültige oder doppelte Nachricht wurde verworfen.');
        }
        break;
      }
      case 'gone':
        if (typeof m.name === 'string' && chats.has(m.name)) dropChat(m.name, `${m.name} ist offline gegangen – der Chat wurde gelöscht.`);
        break;
      case 'delivered': markMsg(m.to, m.id, 'sent'); break;
      case 'undelivered': {
        if (m.reason === 'newkey' && peerKeyOf(m)) {
          await setPeer(m.to, peerKeyOf(m), true);
          const msg = chats.get(m.to)?.msgs.find((x) => x.id === m.id);
          if (msg && !msg.retried) { msg.retried = true; transmit(m.to, msg); return; }
        }
        if (m.reason === 'offline') { dropChat(m.to, `${m.to} ist offline – der Chat wurde gelöscht.`); break; }
        markMsg(m.to, m.id, 'failed', 'Nicht zugestellt');
        break;
      }
    }
  }

  // ---------- auth ----------
  function setMode(m) {
    mode = m;
    $('tabLogin').classList.toggle('active', m === 'login');
    $('tabReg').classList.toggle('active', m === 'register');
    $('authBtn').textContent = m === 'login' ? 'Anmelden' : 'Konto erstellen';
    $('authErr').textContent = '';
  }
  $('tabLogin').onclick = () => setMode('login');
  $('tabReg').onclick = () => setMode('register');
  const nameOnly = (e) => { e.target.value = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); };
  $('name').addEventListener('input', nameOnly);
  $('newName').addEventListener('input', nameOnly);

  $('authForm').onsubmit = async (e) => {
    e.preventDefault();
    const name = $('name').value.trim();
    const pw = $('pin').value;
    const err = $('authErr');
    if (!NAME_RE.test(name)) { err.textContent = 'Name: 3–20 Zeichen, nur a–z, 0–9 und _'; return; }
    if (pw.length < PW_MIN || pw.length > PW_MAX) { err.textContent = `Passwort: mindestens ${PW_MIN} Zeichen (z. B. 6-stellige PIN).`; return; }
    if (mode === 'register' && /^(\d)\1{5,}$|^(012345|123456|234567|345678|456789|987654|654321|111111|000000)$/.test(pw)) { err.textContent = 'Diese PIN ist zu leicht zu erraten.'; return; }
    $('authBtn').disabled = true;
    err.textContent = '';
    $('authBtn').textContent = 'Prüfe…';
    try {
      await window.BCOPAQUE_READY;
      if (!ws || ws.readyState !== 1) { await makeKeys(); await connect(); }
      const [pub, kpub] = myPub.split('.');
      let res;
      if (mode === 'register') res = await opaqueRegister(name, pw, pub, kpub);
      else {
        const p = await opaqueProve('log1', pw, { name });
        res = p.err ? { t: 'err', msg: p.err } : await request({ t: 'log2', fin: p.fin, pub, kpub }, ['ok', 'err']);
      }
      if (res.t === 'err') { err.textContent = res.msg; return; }
      me = res.name;
      e2e = createE2E({ me, myPub, ecdhPrivateKey: keyPair.privateKey, kemSecretKey: kem.secretKey, pq: BCPQ });
      $('pin').value = '';
      $('name').value = '';
      $('meName').textContent = me;
      $('auth').hidden = true;
      $('app').hidden = false;
      $('newName').focus();
    } catch (ex) {
      err.textContent = ex.message || 'Fehler.';
    } finally {
      $('authBtn').disabled = false;
      $('authBtn').textContent = mode === 'login' ? 'Anmelden' : 'Konto erstellen';
    }
  };

  // wipe everything we hold in memory and start over
  function wipe(reason) {
    me = '';
    for (const c of chats.values()) c.msgs.length = 0;
    chats.clear(); if (e2e) e2e.wipe(); e2e = null;
    if (kem) { kem.secretKey.fill(0); kem = null; }
    keyPair = null; vaultKey = null; tunnel = null;
    $('msgs').replaceChildren(); $('chatList').replaceChildren();
    try { ws && ws.close(); } catch {}
    if (reason) alert(reason);
    location.replace(location.pathname);
  }
  $('logout').onclick = () => { if (confirm('Abmelden? Alle Chats und Kontakte werden unwiderruflich gelöscht.')) wipe(); };
  window.addEventListener('beforeunload', (e) => { if (me && [...chats.values()].some((c) => c.msgs.length)) { e.preventDefault(); e.returnValue = ''; } });

  // auto-wipe after inactivity
  let lastActive = Date.now();
  for (const ev of ['keydown', 'pointerdown', 'touchstart']) addEventListener(ev, () => { lastActive = Date.now(); }, { passive: true });
  setInterval(() => { if (me && Date.now() - lastActive > IDLE_WIPE_MS) wipe('30 Minuten keine Aktivität – aus Sicherheitsgründen wurde alles gelöscht.'); }, 30_000);

  // hide chat content whenever the tab isn't visible (app switcher, screen sharing a different tab…)
  document.addEventListener('visibilitychange', () => document.body.classList.toggle('veil', document.hidden));

  // account deletion
  $('delToggle').onclick = () => { $('delForm').hidden = !$('delForm').hidden; $('delErr').textContent = ''; };
  $('delForm').onsubmit = async (e) => {
    e.preventDefault();
    const pw = $('delPin').value;
    if (pw.length < PW_MIN) { $('delErr').textContent = 'Passwort eingeben.'; return; }
    if (!confirm('Konto endgültig löschen? Dein Name wird wieder frei.')) return;
    $('delPin').value = '';
    let r;
    try {
      const p = await opaqueProve('del1', pw);
      r = p.err ? { ok: false, msg: p.err === 'Name oder Passwort falsch.' ? 'Passwort falsch.' : p.err } : await request({ t: 'del2', fin: p.fin }, ['delete']);
    } catch (ex) { r = { ok: false, msg: ex.message }; }
    if (!r.ok) { $('delErr').textContent = r.msg || 'Fehler.'; return; }
    wipe('Dein Konto wurde gelöscht.');
  };

  // ---------- chats ----------
  const COLORS = ['#3b5bfd', '#e0533d', '#1f9d55', '#b44bd6', '#e08a1e', '#1b9bb3', '#d6417f', '#6c7a12'];
  const colorFor = (n) => COLORS[[...n].reduce((a, c) => (a * 31 + c.charCodeAt(0)) >>> 0, 7) % COLORS.length];

  function ensureChat(name) {
    if (!chats.has(name)) { chats.set(name, { pub: null, online: false, msgs: [], unread: 0, safety: '', verified: false, ttl: 0 }); renderList(); }
    return chats.get(name);
  }
  async function setPeer(name, pub, isOnline) {
    const c = ensureChat(name);
    if (pub && c.pub && pub !== c.pub) {
      e2e.forgetPeer(name, c.pub);
      c.verified = false;
      addMsg(name, { who: 'sys' }, `${name} hat eine neue Sitzung gestartet – die Sicherheitsnummer hat sich geändert. Bitte neu vergleichen.`);
    }
    if (pub) { c.pub = pub; c.safety = await safetyNumber(myPub, pub); }
    c.online = isOnline;
    if (active === name) renderHead();
    renderList();
  }

  $('newChat').onsubmit = async (e) => {
    e.preventDefault();
    const name = $('newName').value.trim().toLowerCase();
    $('newErr').textContent = '';
    if (!NAME_RE.test(name)) { $('newErr').textContent = 'Ungültiger Name.'; return; }
    if (name === me) { $('newErr').textContent = 'Das bist du selbst.'; return; }
    const r = await lookup(name);
    if (r.limited) { $('newErr').textContent = 'Zu viele Anfragen – kurz warten.'; return; }
    if (!r.exists) { $('newErr').textContent = 'Diesen Nutzer gibt es nicht.'; return; }
    if (!r.online) { $('newErr').textContent = `${name} ist offline.`; return; }
    ensureChat(name);
    await setPeer(name, peerKeyOf(r), r.online);
    $('newName').value = '';
    openChat(name);
  };

  function renderList() {
    const ul = $('chatList');
    ul.replaceChildren();
    $('noContacts').hidden = chats.size > 0;
    for (const [name, c] of chats) {
      const li = document.createElement('li');
      if (name === active) li.className = 'active';
      const av = document.createElement('span');
      av.className = 'avatar'; av.style.background = colorFor(name); av.textContent = name[0];
      const info = document.createElement('div'); info.className = 'info';
      const nm = document.createElement('div'); nm.className = 'nm'; nm.textContent = name;
      const pv = document.createElement('div'); pv.className = 'pv';
      pv.textContent = c.unread ? `${c.unread} neue Nachricht${c.unread > 1 ? 'en' : ''}` : (c.online ? 'online' : 'offline');
      info.append(nm, pv);
      li.append(av, info);
      if (c.unread) { const b = document.createElement('span'); b.className = 'badge'; b.textContent = c.unread; li.append(b); }
      li.onclick = () => openChat(name);
      ul.append(li);
    }
  }

  // ---------- drafts: what you type is encrypted as soon as you look away ----------
  const DRAFT_PH = '🔒 Entwurf verschlüsselt – tippe hier zum Weiterschreiben';
  async function sealDraft() {
    const c = active && chats.get(active);
    const v = $('text').value;
    if (!c || !v) return;
    $('text').value = '';
    $('text').style.height = '';
    const box = await seal(v);
    c.draft = box;
    $('text').placeholder = DRAFT_PH;
  }
  async function unsealDraft() {
    const c = active && chats.get(active);
    $('text').placeholder = 'Nachricht…';
    if (!c || !c.draft) return;
    const box = c.draft; c.draft = null;
    $('text').value = (await unseal(box)) + $('text').value;
  }
  $('text').addEventListener('focus', unsealDraft);
  $('text').addEventListener('pointerdown', unsealDraft);
  $('text').addEventListener('keydown', () => { if (active && chats.get(active)?.draft) unsealDraft(); });
  window.addEventListener('focus', () => { if (document.activeElement === $('text')) unsealDraft(); });
  document.addEventListener('visibilitychange', () => { if (document.hidden) sealDraft(); });
  window.addEventListener('blur', sealDraft);

  // privacy screen: messages stay blurred until you hover / tap them
  let shield = true;
  document.body.classList.add('shield');
  $('shieldBtn').onclick = () => {
    shield = !shield;
    document.body.classList.toggle('shield', shield);
    $('shieldBtn').textContent = shield ? '👁 Sichtschutz an' : '👁 Sichtschutz aus';
  };
  $('msgs').addEventListener('click', (e) => {
    const li = e.target.closest('li');
    if (!li || !shield) return;
    li.classList.add('reveal');
    setTimeout(() => li.classList.remove('reveal'), 4000);
  });

  async function openChat(name) {
    if (active && active !== name) await sealDraft();
    active = name;
    $('text').placeholder = chats.get(name).draft ? DRAFT_PH : 'Nachricht…';
    chats.get(name).unread = 0;
    $('empty').hidden = true;
    $('conv').hidden = false;
    $('safety').hidden = true;
    $('app').classList.add('in-chat');
    renderHead();
    renderMsgs();
    renderList();
    $('text').focus();
    lookup(name); // refresh online state + key
  }
  function closeConv() { $('app').classList.remove('in-chat'); $('conv').hidden = true; $('empty').hidden = false; renderList(); }
  $('back').onclick = async () => { await sealDraft(); active = null; closeConv(); };
  // remove a chat completely: messages, draft, keys
  function dropChat(name, notice) {
    const c = chats.get(name);
    if (!c) return;
    for (const m of c.msgs) { clearTimeout(m.timer); m.iv = m.ct = m.el = m.meta = null; }
    c.msgs.length = 0; c.draft = null;
    if (c.pub) e2e.forgetPeer(name, c.pub);
    chats.delete(name);
    if (active === name) { $('text').value = ''; $('msgs').replaceChildren(); active = null; closeConv(); }
    renderList();
    if (notice) $('newErr').textContent = notice;
  }
  $('clearChat').onclick = () => {
    if (active && confirm(`Chat mit ${active} schließen und Verlauf löschen?`)) dropChat(active);
  };

  function renderHead() {
    const c = chats.get(active);
    $('peerName').textContent = active;
    const st = $('peerState');
    st.textContent = c.online ? 'online · Ende-zu-Ende-verschlüsselt' : 'offline – Nachrichten können nicht zugestellt werden';
    st.className = 'state' + (c.online ? ' on' : '');
    $('safetyNum').textContent = c.safety || '— (Person ist offline)';
    $('unverified').hidden = c.verified || !c.safety;
    $('verifyBtn').hidden = c.verified || !c.safety;
    $('verifiedNote').hidden = !c.verified;
    $('ttl').value = String(c.ttl);
  }
  $('safetyBtn').onclick = () => { $('safety').hidden = !$('safety').hidden; };
  $('unverified').onclick = () => { $('safety').hidden = false; };
  $('verifyBtn').onclick = () => {
    if (!confirm('Habt ihr die Nummer Ziffer für Ziffer verglichen und sie ist bei beiden gleich?')) return;
    chats.get(active).verified = true;
    renderHead();
  };
  $('ttl').onchange = () => {
    const c = chats.get(active);
    c.ttl = Number($('ttl').value) || 0;
    addMsg(active, { who: 'sys' }, c.ttl ? `Selbstzerstörung: deine Nachrichten verschwinden nach ${TTL_LABEL[c.ttl]} – bei dir und beim Empfänger.` : 'Selbstzerstörung aus.');
  };

  function decorate(m) {
    if (!m.el) return;
    m.el.className = m.who + (m.state ? ' ' + m.state : '');
    if (m.meta) m.meta.textContent = m.state === 'pending' ? '…' : m.state === 'failed' ? (m.note || 'Nicht zugestellt') : '✓';
  }
  // Message text is painted onto a <canvas> instead of being put into the DOM:
  // "Inspect element" shows no text at all, and extensions can't simply read it.
  function paint(text, who) {
    const ol = $('msgs');
    const css = getComputedStyle(document.body);
    const font = `15px ${css.fontFamily}`;
    const color = who === 'mine' ? (css.getPropertyValue('--mine-text').trim() || '#fff') : css.color;
    const maxW = Math.max(120, Math.min(ol.clientWidth * (innerWidth <= 720 ? 0.85 : 0.75), 560) - 24);
    const ctx0 = document.createElement('canvas').getContext('2d');
    ctx0.font = font;
    const lines = [];
    for (const para of text.split('\n')) {
      let line = '';
      for (const word of para.split(/(\s+)/)) {
        const tryLine = line + word;
        if (ctx0.measureText(tryLine).width <= maxW) { line = tryLine; continue; }
        if (line.trim()) lines.push(line.trimEnd());
        line = word.trimStart();
        while (ctx0.measureText(line).width > maxW) { // very long word: hard break
          let i = line.length;
          while (i > 1 && ctx0.measureText(line.slice(0, i)).width > maxW) i--;
          lines.push(line.slice(0, i)); line = line.slice(i);
        }
      }
      lines.push(line.trimEnd());
    }
    const lh = 22, dpr = window.devicePixelRatio || 1;
    const w = Math.ceil(Math.max(1, ...lines.map((l) => ctx0.measureText(l).width)));
    const h = lines.length * lh;
    const cv = document.createElement('canvas');
    cv.width = Math.ceil(w * dpr); cv.height = Math.ceil(h * dpr);
    cv.style.width = w + 'px'; cv.style.height = h + 'px';
    const ctx = cv.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.font = font; ctx.fillStyle = color; ctx.textBaseline = 'middle';
    lines.forEach((l, i) => ctx.fillText(l, 0, i * lh + lh / 2));
    return cv;
  }
  let resizeT;
  addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(() => { if (active) renderMsgs(); }, 200); });
  function msgEl(m, text) {
    const li = document.createElement('li');
    li.append(m.who === 'sys' ? document.createTextNode(text) : paint(text, m.who));
    m.el = li; m.meta = null;
    if (m.who === 'mine') { m.meta = document.createElement('span'); m.meta.className = 'meta'; li.append(m.meta); }
    decorate(m);
    return li;
  }
  let renderToken = 0;
  async function renderMsgs() {
    const name = active, token = ++renderToken;
    for (const c of chats.values()) for (const m of c.msgs) { m.el = null; m.meta = null; } // drop plaintext DOM of other chats
    const msgs = chats.get(name).msgs;
    const texts = await Promise.all(msgs.map(unseal));
    if (token !== renderToken || active !== name) return;
    const ol = $('msgs');
    ol.replaceChildren(...msgs.map((m, i) => msgEl(m, texts[i])));
    ol.scrollTop = ol.scrollHeight;
  }
  async function addMsg(name, m, text) {
    Object.assign(m, await seal(text)); // only ciphertext is kept in the chat history
    const c = ensureChat(name);
    c.msgs.push(m);
    if (c.msgs.length > 500) clearTimeout(c.msgs.shift().timer);
    if (m.ttl) m.timer = setTimeout(() => destroyMsg(name, m), m.ttl * 1000);
    if (active === name) { const ol = $('msgs'); ol.append(msgEl(m, text)); ol.scrollTop = ol.scrollHeight; }
    renderList();
  }
  function destroyMsg(name, m) {
    const c = chats.get(name);
    if (!c) return;
    const i = c.msgs.indexOf(m);
    if (i >= 0) c.msgs.splice(i, 1);
    if (m.el) m.el.remove();
    m.iv = m.ct = m.el = m.meta = null;
  }
  function markMsg(name, id, state, note = '') {
    const m = chats.get(name)?.msgs.find((x) => x.id === id);
    if (!m) return;
    m.state = state; m.note = note;
    decorate(m);
  }

  async function transmit(name, msg) {
    const c = chats.get(name);
    if (!c) return;
    if (!c.pub || !c.online) {
      const r = await lookup(name);
      if (!r.online) { dropChat(name, `${name} ist offline – der Chat wurde gelöscht.`); return; }
    }
    const peerKey = c.pub;
    if (!msg.ct) return; // self-destructed in the meantime
    const { n, k, iv, ct } = await e2e.encryptFor(name, peerKey, { m: await unseal(msg), ttl: msg.ttl || 0 });
    const [pub, kpub] = peerKey.split('.');
    sendWs({ t: 'msg', to: name, pub, kpub, k, n, iv, ct, id: msg.id });
  }

  $('composer').onsubmit = async (e) => {
    e.preventDefault();
    const text = $('text').value.trim();
    if (!text || !active) return;
    const to = active;
    $('text').value = '';
    $('text').style.height = '';
    const msg = { who: 'mine', id: b64(crypto.getRandomValues(new Uint8Array(12))), state: 'pending', ttl: chats.get(to).ttl };
    await addMsg(to, msg, text);
    transmit(to, msg);
  };
  $('text').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('composer').requestSubmit(); }
  });
  $('text').addEventListener('input', (e) => { e.target.style.height = ''; e.target.style.height = Math.min(e.target.scrollHeight, 140) + 'px'; });
})();
