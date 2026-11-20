'use strict';
/*
 * BetterChats client.
 * - A fresh ECDH P-256 key pair is generated per page load; the private key is
 *   non-extractable and lives only in this tab's memory.
 * - Each message is encrypted with AES-256-GCM using a key derived (ECDH + HKDF)
 *   from your private key and the recipient's public key. The server only sees ciphertext.
 * - Chats exist only in JS memory. Reload / close = gone. No localStorage, no cookies.
 * - All user content is inserted with textContent (never innerHTML) -> no XSS.
 */
(() => {
  const $ = (id) => document.getElementById(id);
  const enc = new TextEncoder();
  const dec = new TextDecoder();
  const NAME_RE = /^[a-z0-9_]{3,20}$/;

  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)));
  const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

  // ---------- state (memory only) ----------
  let ws = null;
  let keyPair = null;
  let myPub = '';
  let me = '';
  let mode = 'login';
  let active = null;
  const chats = new Map();      // name -> { pub, online, msgs: [], unread, el }
  const aesCache = new Map();   // pub -> CryptoKey
  const lookups = new Map();    // name -> [resolve]

  // ---------- crypto ----------
  async function makeKeys() {
    keyPair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
    myPub = b64(await crypto.subtle.exportKey('raw', keyPair.publicKey));
  }
  async function sharedKey(peerPub) {
    if (aesCache.has(peerPub)) return aesCache.get(peerPub);
    const pk = await crypto.subtle.importKey('raw', unb64(peerPub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    const bits = await crypto.subtle.deriveBits({ name: 'ECDH', public: pk }, keyPair.privateKey, 256);
    const hk = await crypto.subtle.importKey('raw', bits, 'HKDF', false, ['deriveKey']);
    const key = await crypto.subtle.deriveKey(
      { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: enc.encode('BetterChats v1') },
      hk, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    aesCache.set(peerPub, key);
    return key;
  }
  async function encryptFor(peer, peerPub, text) {
    // pad to a multiple of 256 bytes so the server can't guess message length
    let pt = JSON.stringify({ m: text, ts: Date.now() });
    const len = enc.encode(pt).length;
    pt += ' '.repeat(256 - (len % 256));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ct = await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, additionalData: enc.encode(me + '>' + peer) },
      await sharedKey(peerPub), enc.encode(pt));
    return { iv: b64(iv), ct: b64(ct) };
  }
  async function decryptFrom(peer, peerPub, iv, ct) {
    const pt = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: unb64(iv), additionalData: enc.encode(peer + '>' + me) },
      await sharedKey(peerPub), unb64(ct));
    const o = JSON.parse(dec.decode(pt));
    if (typeof o.m !== 'string') throw new Error('bad');
    return o;
  }
  async function safetyNumber(pubA, pubB) {
    const [x, y] = [pubA, pubB].sort();
    const h = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(x + '|' + y)));
    let out = '';
    for (let i = 0; i < 12; i++) out += String(((h[i * 2] << 8) | h[i * 2 + 1]) % 100000).padStart(5, '0') + ' ';
    return out.trim();
  }

  // ---------- socket ----------
  function connect() {
    return new Promise((resolve, reject) => {
      const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
      ws = new WebSocket(`${proto}//${location.host}/`);
      ws.onopen = () => resolve();
      ws.onerror = () => reject(new Error('Keine Verbindung zum Server.'));
      ws.onmessage = (e) => { let m; try { m = JSON.parse(e.data); } catch { return; } onServer(m); };
      ws.onclose = () => {
        if (me) { wipe('Verbindung getrennt. Aus Sicherheitsgründen wurde alles gelöscht.'); }
      };
    });
  }
  const sendWs = (o) => ws && ws.readyState === 1 && ws.send(JSON.stringify(o));

  function lookup(name) {
    return new Promise((resolve) => {
      if (!lookups.has(name)) lookups.set(name, []);
      lookups.get(name).push(resolve);
      sendWs({ t: 'lookup', name });
    });
  }

  let authResolve = null;
  async function onServer(m) {
    switch (m.t) {
      case 'ok': case 'err':
        if (authResolve) { authResolve(m); authResolve = null; }
        else if (m.t === 'err') $('newErr').textContent = m.msg;
        break;
      case 'kicked':
        wipe('Du hast dich woanders angemeldet. Diese Sitzung wurde beendet.');
        break;
      case 'lookup': {
        const c = chats.get(m.name);
        if (c && m.exists) setPeer(m.name, m.pub, m.online);
        (lookups.get(m.name) || []).forEach((r) => r(m));
        lookups.delete(m.name);
        break;
      }
      case 'msg': {
        if (typeof m.from !== 'string' || !NAME_RE.test(m.from)) return;
        const c = ensureChat(m.from);
        setPeer(m.from, m.pub, true);
        try {
          const o = await decryptFrom(m.from, m.pub, m.iv, m.ct);
          addMsg(m.from, { who: 'theirs', text: o.m, ts: Date.now() });
          if (active !== m.from || document.hidden) { c.unread++; renderList(); }
        } catch {
          addMsg(m.from, { who: 'sys', text: 'Eine Nachricht konnte nicht entschlüsselt werden und wurde verworfen.' });
        }
        break;
      }
      case 'delivered': markMsg(m.to, m.id, ''); break;
      case 'undelivered': {
        if (m.reason === 'newkey' && typeof m.pub === 'string') {
          setPeer(m.to, m.pub, true);
          const msg = chats.get(m.to)?.msgs.find((x) => x.id === m.id);
          if (msg && !msg.retried) { msg.retried = true; transmit(m.to, msg); return; }
        }
        if (m.reason === 'offline') setPeer(m.to, chats.get(m.to)?.pub || null, false);
        markMsg(m.to, m.id, 'failed', m.reason === 'offline' ? 'Nicht zugestellt – offline' : 'Nicht zugestellt');
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
  $('pin').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });
  $('name').addEventListener('input', (e) => { e.target.value = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); });

  $('authForm').onsubmit = async (e) => {
    e.preventDefault();
    const name = $('name').value.trim();
    const pin = $('pin').value;
    const err = $('authErr');
    if (!NAME_RE.test(name)) { err.textContent = 'Name: 3–20 Zeichen, nur a–z, 0–9 und _'; return; }
    if (!/^\d{6}$/.test(pin)) { err.textContent = 'Passwort muss genau 6 Ziffern haben.'; return; }
    $('authBtn').disabled = true;
    err.textContent = '';
    try {
      if (!ws || ws.readyState !== 1) { await makeKeys(); await connect(); }
      const res = await new Promise((r) => { authResolve = r; sendWs({ t: mode, name, pin, pub: myPub }); });
      if (res.t === 'err') { err.textContent = res.msg; return; }
      me = res.name;
      $('pin').value = '';
      $('meName').textContent = me;
      $('auth').hidden = true;
      $('app').hidden = false;
      $('newName').focus();
    } catch (ex) {
      err.textContent = ex.message || 'Fehler.';
    } finally {
      $('authBtn').disabled = false;
    }
  };

  function wipe(reason) {
    me = '';
    chats.clear(); aesCache.clear(); keyPair = null;
    try { ws && ws.close(); } catch {}
    alert(reason);
    location.replace(location.pathname);
  }
  $('logout').onclick = () => {
    if (!confirm('Abmelden? Alle Chats werden unwiderruflich gelöscht.')) return;
    me = '';
    try { ws.close(); } catch {}
    location.replace(location.pathname);
  };
  window.addEventListener('beforeunload', (e) => { if (me && [...chats.values()].some((c) => c.msgs.length)) { e.preventDefault(); e.returnValue = ''; } });

  // ---------- chats ----------
  const COLORS = ['#3b5bfd', '#e0533d', '#1f9d55', '#b44bd6', '#e08a1e', '#1b9bb3', '#d6417f', '#6c7a12'];
  const colorFor = (n) => COLORS[[...n].reduce((a, c) => (a * 31 + c.charCodeAt(0)) >>> 0, 7) % COLORS.length];

  function ensureChat(name) {
    if (!chats.has(name)) { chats.set(name, { pub: null, online: false, msgs: [], unread: 0, safety: '' }); renderList(); }
    return chats.get(name);
  }
  async function setPeer(name, pub, isOnline) {
    const c = ensureChat(name);
    if (pub && c.pub && pub !== c.pub) {
      addMsg(name, { who: 'sys', text: `${name} hat eine neue Sitzung gestartet – die Sicherheitsnummer hat sich geändert.` });
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
    if (!r.exists) { $('newErr').textContent = 'Diesen Nutzer gibt es nicht.'; return; }
    ensureChat(name);
    setPeer(name, r.pub, r.online);
    $('newName').value = '';
    openChat(name);
  };

  function renderList() {
    const ul = $('chatList');
    ul.replaceChildren();
    for (const [name, c] of chats) {
      const li = document.createElement('li');
      if (name === active) li.className = 'active';
      const av = document.createElement('span');
      av.className = 'avatar'; av.style.background = colorFor(name); av.textContent = name[0];
      const info = document.createElement('div'); info.className = 'info';
      const nm = document.createElement('div'); nm.className = 'nm'; nm.textContent = name;
      const pv = document.createElement('div'); pv.className = 'pv';
      const last = [...c.msgs].reverse().find((x) => x.who !== 'sys');
      pv.textContent = last ? (last.who === 'mine' ? 'Du: ' : '') + last.text : (c.online ? 'online' : 'offline');
      info.append(nm, pv);
      li.append(av, info);
      if (c.unread) { const b = document.createElement('span'); b.className = 'badge'; b.textContent = c.unread; li.append(b); }
      li.onclick = () => openChat(name);
      ul.append(li);
    }
  }

  function openChat(name) {
    active = name;
    const c = chats.get(name);
    c.unread = 0;
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
  $('back').onclick = () => { active = null; $('app').classList.remove('in-chat'); $('conv').hidden = true; $('empty').hidden = false; renderList(); };

  function renderHead() {
    const c = chats.get(active);
    $('peerName').textContent = active;
    const st = $('peerState');
    st.textContent = c.online ? 'online · Ende-zu-Ende-verschlüsselt' : 'offline – Nachrichten können nicht zugestellt werden';
    st.className = 'state' + (c.online ? ' on' : '');
    $('safetyNum').textContent = c.safety || '— (Person ist offline)';
  }
  $('safetyBtn').onclick = () => { $('safety').hidden = !$('safety').hidden; };

  function msgEl(m) {
    const li = document.createElement('li');
    li.className = m.who + (m.state ? ' ' + m.state : '');
    li.append(document.createTextNode(m.text));
    if (m.who !== 'sys') {
      const meta = document.createElement('span');
      meta.className = 'meta';
      meta.textContent = new Date(m.ts).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + (m.note ? ' · ' + m.note : '');
      li.append(meta);
    }
    m.el = li;
    return li;
  }
  function renderMsgs() {
    const ol = $('msgs');
    ol.replaceChildren(...chats.get(active).msgs.map(msgEl));
    ol.scrollTop = ol.scrollHeight;
  }
  function addMsg(name, m) {
    const c = ensureChat(name);
    c.msgs.push(m);
    if (c.msgs.length > 500) c.msgs.shift();
    if (active === name) { const ol = $('msgs'); ol.append(msgEl(m)); ol.scrollTop = ol.scrollHeight; }
    renderList();
  }
  function markMsg(name, id, state, note = '') {
    const m = chats.get(name)?.msgs.find((x) => x.id === id);
    if (!m) return;
    m.state = state; m.note = note;
    if (m.el && active === name) m.el.replaceWith(msgEl(m));
  }

  async function transmit(name, msg) {
    const c = chats.get(name);
    if (!c.pub) {
      const r = await lookup(name);
      if (!r.online) { markMsg(name, msg.id, 'failed', 'Nicht zugestellt – offline'); return; }
    }
    const { iv, ct } = await encryptFor(name, c.pub, msg.text);
    sendWs({ t: 'msg', to: name, pub: c.pub, iv, ct, id: msg.id });
  }

  $('composer').onsubmit = (e) => {
    e.preventDefault();
    const text = $('text').value.trim();
    if (!text || !active) return;
    const msg = { who: 'mine', text, ts: Date.now(), id: b64(crypto.getRandomValues(new Uint8Array(12))), state: 'pending' };
    addMsg(active, msg);
    $('text').value = '';
    $('text').style.height = '';
    transmit(active, msg);
  };
  $('text').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('composer').requestSubmit(); }
  });
  $('text').addEventListener('input', (e) => { e.target.style.height = ''; e.target.style.height = Math.min(e.target.scrollHeight, 140) + 'px'; });
})();
