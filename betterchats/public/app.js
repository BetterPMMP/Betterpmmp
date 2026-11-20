'use strict';
/*
 * BetterChats client.
 *
 * - Per page load a fresh ECDH P-256 key pair is generated. The private key is
 *   non-extractable and exists only in this tab's memory.
 * - Per contact, ECDH + HKDF derive two chain keys (one per direction). Every message
 *   uses its own key from a hash ratchet; used keys are deleted immediately
 *   (forward secrecy inside a session, replayed messages can't be decrypted twice).
 * - AES-256-GCM, sender/recipient/keys/counter bound as additional data, padding to
 *   256-byte blocks so the server can't even see the message length.
 * - Contacts and chats exist only in JS memory: reload / close / logout = gone.
 *   No cookies, no localStorage, no IndexedDB.
 * - All content is inserted with textContent (never innerHTML) + Trusted Types CSP.
 */
(() => {
  const $ = (id) => document.getElementById(id);
  const enc = new TextEncoder();
  const dec = new TextDecoder();
  const NAME_RE = /^[a-z0-9_]{3,20}$/;
  const IDLE_WIPE_MS = 30 * 60_000;
  const MAX_SKIP = 500;

  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)));
  const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

  if (!window.isSecureContext || !window.crypto?.subtle) {
    $('authErr').textContent = 'Unsichere Verbindung: BetterChats funktioniert nur über HTTPS (oder als .onion).';
    $('authBtn').disabled = true;
    return;
  }

  // ---------- state (memory only) ----------
  let ws = null;
  let keyPair = null;
  let myPub = '';
  let me = '';
  let mode = 'login';
  let active = null;
  const chats = new Map();     // name -> { pub, online, msgs, unread, safety }
  const sessions = new Map();  // name|peerPub -> Promise<{ send, recv }>
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
    myPub = b64(await crypto.subtle.exportKey('raw', keyPair.publicKey));
    vaultKey = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  }
  async function hmac(keyBytes, byte) {
    const k = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    return new Uint8Array(await crypto.subtle.sign('HMAC', k, new Uint8Array([byte])));
  }
  // one step of the hash ratchet: message key + next chain key; the old chain key is wiped
  async function step(ck) {
    const mk = await hmac(ck, 1);
    const next = await hmac(ck, 2);
    ck.fill(0);
    return [mk, next];
  }
  function session(peer, peerPub) {
    const id = peer + '|' + peerPub;
    if (!sessions.has(id)) {
      sessions.set(id, (async () => {
        const pk = await crypto.subtle.importKey('raw', unb64(peerPub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
        const bits = await crypto.subtle.deriveBits({ name: 'ECDH', public: pk }, keyPair.privateKey, 256);
        const hk = await crypto.subtle.importKey('raw', bits, 'HKDF', false, ['deriveBits']);
        const chain = async (from, fromPub, to, toPub) => new Uint8Array(await crypto.subtle.deriveBits(
          { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: enc.encode(`BetterChats v2|${from}|${fromPub}|${to}|${toPub}`) }, hk, 256));
        return {
          send: { ck: await chain(me, myPub, peer, peerPub), n: 0, q: Promise.resolve() },
          recv: { ck: await chain(peer, peerPub, me, myPub), n: 0, skipped: new Map(), q: Promise.resolve() },
        };
      })());
    }
    return sessions.get(id);
  }
  const serial = (ch, fn) => { const r = ch.q.then(fn); ch.q = r.catch(() => {}); return r; };
  const aad = (from, fromPub, to, toPub, n) => enc.encode(`${from}|${fromPub}|${to}|${toPub}|${n}`);
  const aesKey = (mk, use) => crypto.subtle.importKey('raw', mk, 'AES-GCM', false, [use]);

  async function encryptFor(peer, peerPub, text) {
    const s = await session(peer, peerPub);
    return serial(s.send, async () => {
      const n = s.send.n;
      const [mk, next] = await step(s.send.ck);
      s.send.ck = next; s.send.n++;
      let pt = JSON.stringify({ m: text });
      pt += ' '.repeat(256 - (enc.encode(pt).length % 256)); // hide length
      const iv = crypto.getRandomValues(new Uint8Array(12));
      const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: aad(me, myPub, peer, peerPub, n) }, await aesKey(mk, 'encrypt'), enc.encode(pt));
      mk.fill(0);
      return { n, iv: b64(iv), ct: b64(ct) };
    });
  }
  async function decryptFrom(peer, peerPub, n, iv, ct) {
    const s = await session(peer, peerPub);
    const r = s.recv;
    return serial(r, async () => {
      // work on copies; only commit if the message really decrypts (forged counters can't break the chain)
      let ck = r.ck.slice(), cn = r.n, mk;
      const newSkipped = [];
      if (n < cn) {
        mk = r.skipped.get(n);
        if (!mk) throw new Error('replay');
      } else {
        if (n - cn > MAX_SKIP) throw new Error('gap');
        while (cn < n) { const [k, nx] = await step(ck); newSkipped.push([cn, k]); ck = nx; cn++; }
        const [k, nx] = await step(ck); mk = k; ck = nx; cn++;
      }
      const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(iv), additionalData: aad(peer, peerPub, me, myPub, n) }, await aesKey(mk, 'decrypt'), unb64(ct));
      // commit
      if (n < r.n) r.skipped.delete(n);
      else { r.ck.fill(0); r.ck = ck; r.n = cn; for (const [i, k] of newSkipped) r.skipped.set(i, k); }
      while (r.skipped.size > MAX_SKIP) { const [i, k] = r.skipped.entries().next().value; k.fill(0); r.skipped.delete(i); }
      mk.fill(0);
      const o = JSON.parse(dec.decode(pt));
      if (typeof o.m !== 'string') throw new Error('bad');
      return o.m;
    });
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
      ws = new WebSocket(`${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/`);
      ws.onopen = () => resolve();
      ws.onerror = () => reject(new Error('Keine Verbindung zum Server.'));
      ws.onmessage = (e) => { let m; try { m = JSON.parse(e.data); } catch { return; } if (m && typeof m === 'object') onServer(m); };
      ws.onclose = () => { if (me) wipe('Verbindung getrennt. Aus Sicherheitsgründen wurde alles gelöscht.'); };
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
  let deleteResolve = null;
  async function onServer(m) {
    switch (m.t) {
      case 'ok': case 'err':
        if (authResolve) { authResolve(m); authResolve = null; }
        break;
      case 'kicked':
        wipe('Du hast dich woanders angemeldet. Diese Sitzung wurde beendet und alles gelöscht.');
        break;
      case 'delete':
        if (deleteResolve) { deleteResolve(m); deleteResolve = null; }
        break;
      case 'lookup': {
        if (typeof m.name !== 'string') return;
        if (chats.has(m.name) && m.exists) setPeer(m.name, m.pub, m.online);
        (lookups.get(m.name) || []).forEach((r) => r(m));
        lookups.delete(m.name);
        break;
      }
      case 'msg': {
        if (typeof m.from !== 'string' || !NAME_RE.test(m.from) || typeof m.pub !== 'string') return;
        try {
          const text = await decryptFrom(m.from, m.pub, m.n, m.iv, m.ct);
          const c = ensureChat(m.from);
          await setPeer(m.from, m.pub, true);
          await addMsg(m.from, { who: 'theirs', ts: Date.now() }, text);
          if (active !== m.from || document.hidden) { c.unread++; renderList(); }
        } catch {
          if (chats.has(m.from)) addMsg(m.from, { who: 'sys' }, 'Eine ungültige oder doppelte Nachricht wurde verworfen.');
        }
        break;
      }
      case 'delivered': markMsg(m.to, m.id, ''); break;
      case 'undelivered': {
        if (m.reason === 'newkey' && typeof m.pub === 'string') {
          await setPeer(m.to, m.pub, true);
          const msg = chats.get(m.to)?.msgs.find((x) => x.id === m.id);
          if (msg && !msg.retried) { msg.retried = true; transmit(m.to, msg); return; }
        }
        if (m.reason === 'offline') setPeer(m.to, null, false);
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
  const digitsOnly = (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); };
  const nameOnly = (e) => { e.target.value = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''); };
  $('pin').addEventListener('input', digitsOnly);
  $('delPin').addEventListener('input', digitsOnly);
  $('name').addEventListener('input', nameOnly);
  $('newName').addEventListener('input', nameOnly);

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
      $('name').value = '';
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

  // wipe everything we hold in memory and start over
  function wipe(reason) {
    me = '';
    for (const c of chats.values()) c.msgs.length = 0;
    chats.clear(); sessions.clear(); keyPair = null; vaultKey = null;
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
    const pin = $('delPin').value;
    if (!/^\d{6}$/.test(pin)) { $('delErr').textContent = '6 Ziffern eingeben.'; return; }
    if (!confirm('Konto endgültig löschen? Dein Name wird wieder frei.')) return;
    const r = await new Promise((res) => { deleteResolve = res; sendWs({ t: 'delete', pin }); });
    $('delPin').value = '';
    if (!r.ok) { $('delErr').textContent = r.msg || 'Fehler.'; return; }
    wipe('Dein Konto wurde gelöscht.');
  };

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
      sessions.delete(name + '|' + c.pub);
      addMsg(name, { who: 'sys' }, `${name} hat eine neue Sitzung gestartet – die Sicherheitsnummer hat sich geändert.`);
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
    ensureChat(name);
    await setPeer(name, r.pub, r.online);
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

  function openChat(name) {
    active = name;
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
  $('back').onclick = () => { active = null; $('app').classList.remove('in-chat'); $('conv').hidden = true; $('empty').hidden = false; renderList(); };
  $('clearChat').onclick = () => {
    if (!active || !confirm(`Chat mit ${active} schließen und Verlauf löschen?`)) return;
    const c = chats.get(active);
    c.msgs.length = 0;
    if (c.pub) sessions.delete(active + '|' + c.pub);
    chats.delete(active);
    $('msgs').replaceChildren();
    $('back').onclick();
  };

  function renderHead() {
    const c = chats.get(active);
    $('peerName').textContent = active;
    const st = $('peerState');
    st.textContent = c.online ? 'online · Ende-zu-Ende-verschlüsselt' : 'offline – Nachrichten können nicht zugestellt werden';
    st.className = 'state' + (c.online ? ' on' : '');
    $('safetyNum').textContent = c.safety || '— (Person ist offline)';
  }
  $('safetyBtn').onclick = () => { $('safety').hidden = !$('safety').hidden; };

  function decorate(m) {
    if (!m.el) return;
    m.el.className = m.who + (m.state ? ' ' + m.state : '');
    if (m.meta) m.meta.textContent = new Date(m.ts).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + (m.note ? ' · ' + m.note : '');
  }
  function msgEl(m, text) {
    const li = document.createElement('li');
    li.append(document.createTextNode(text));
    m.el = li; m.meta = null;
    if (m.who !== 'sys') { m.meta = document.createElement('span'); m.meta.className = 'meta'; li.append(m.meta); }
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
    if (c.msgs.length > 500) c.msgs.shift();
    if (active === name) { const ol = $('msgs'); ol.append(msgEl(m, text)); ol.scrollTop = ol.scrollHeight; }
    renderList();
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
      if (!r.online) { markMsg(name, msg.id, 'failed', 'Nicht zugestellt – offline'); return; }
    }
    const pub = c.pub;
    const { n, iv, ct } = await encryptFor(name, pub, await unseal(msg));
    sendWs({ t: 'msg', to: name, pub, n, iv, ct, id: msg.id });
  }

  $('composer').onsubmit = async (e) => {
    e.preventDefault();
    const text = $('text').value.trim();
    if (!text || !active) return;
    const to = active;
    $('text').value = '';
    $('text').style.height = '';
    const msg = { who: 'mine', ts: Date.now(), id: b64(crypto.getRandomValues(new Uint8Array(12))), state: 'pending' };
    await addMsg(to, msg, text);
    transmit(to, msg);
  };
  $('text').addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('composer').requestSubmit(); }
  });
  $('text').addEventListener('input', (e) => { e.target.style.height = ''; e.target.style.height = Math.min(e.target.scrollHeight, 140) + 'px'; });
})();
