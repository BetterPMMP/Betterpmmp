'use strict';
/*
 * BetterChats server
 *
 * Stores on disk ONLY:
 *   - HMAC-SHA256(username)          (the name itself is never written in plain text)
 *   - an OPAQUE registration record  (RFC 9807: the server NEVER sees the password, not even
 *                                     at login; the record is useless without the server's
 *                                     OPRF key, which is itself encrypted with the pepper)
 *
 * Messages are end-to-end encrypted in the browser (hybrid post-quantum:
 * ECDH P-256 + ML-KEM-768, per-message keys, AES-256-GCM). The server only relays
 * ciphertext to an online recipient and forgets it right away.
 *
 * Stealth / firewall layer:
 *   - silent drop (no response at all) for scanners, wrong Host/SNI, unknown paths, garbage
 *   - auto-ban of IPs that probe, scan or brute-force (RAM only, keyed hash, no logs)
 *   - every frame padded to a fixed size + random cover traffic in both directions,
 *     so even someone watching the encrypted connection can't tell when you chat
 */
const http = require('http');
const https = require('https');
const tls = require('tls');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');
const opaque = require('@serenity-kit/opaque');

const env = process.env;
const PORT = Number(env.PORT || 30);
const HOST = env.HOST || '0.0.0.0';
const DATA_DIR = env.DATA_DIR || path.join(__dirname, 'data');
const PUBLIC_DIR = path.join(__dirname, 'public');
const USERS_FILE = path.join(DATA_DIR, 'users.json');
const SECRET_FILE = path.join(DATA_DIR, 'server.key');
// Comma separated host names the server answers to (e.g. "chat.example.com,abc…xyz.onion").
// Anything else (scans by IP, wrong SNI) gets no answer at all.
const DOMAINS = (env.DOMAIN || '').split(',').map((s) => s.trim().toLowerCase()).filter(Boolean);
const COVER = env.COVER_TRAFFIC !== '0';
// Origins of the official apps (Electron: app://betterchats, Capacitor Android: https://localhost,
// Capacitor iOS: capacitor://localhost). Their code ships inside the signed app, not from this server.
const APP_ORIGINS = (env.APP_ORIGINS ?? 'app://betterchats,https://localhost,capacitor://localhost')
  .split(',').map((s) => s.trim()).filter(Boolean);

const NAME_RE = /^[a-z0-9_]{3,20}$/;
const MAX_CT = 16 * 1024;
const MAX_USERS = Number(env.MAX_USERS || 100_000);
const MAX_CONN_PER_IP = 10;
const MAX_CONN_TOTAL = 5000;
const BUCKETS = [4096, 8192, 16384, 32768];

// ---------- persistent store (hashed names + OPAQUE records) ----------
fs.mkdirSync(DATA_DIR, { recursive: true, mode: 0o700 });
if (!fs.existsSync(SECRET_FILE)) fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32), { mode: 0o600 });
// Optional pepper from the environment: then the data folder alone is worthless to a thief.
const SERVER_KEY = crypto.createHash('sha256')
  .update(fs.readFileSync(SECRET_FILE)).update(env.BETTERCHATS_PEPPER || '').digest();
const nameId = (name) => crypto.createHmac('sha256', SERVER_KEY).update('name:' + name).digest('hex');

// OPAQUE server setup (contains the OPRF key + server key pair), stored AES-256-GCM encrypted
const SETUP_FILE = path.join(DATA_DIR, 'opaque.setup');
const setupKey = crypto.createHmac('sha256', SERVER_KEY).update('opaque-setup').digest();
function loadSetup() {
  if (fs.existsSync(SETUP_FILE)) {
    const b = fs.readFileSync(SETUP_FILE);
    const d = crypto.createDecipheriv('aes-256-gcm', setupKey, b.subarray(0, 12));
    d.setAuthTag(b.subarray(b.length - 16));
    return Buffer.concat([d.update(b.subarray(12, b.length - 16)), d.final()]).toString('utf8');
  }
  const setup = opaque.server.createSetup();
  const iv = crypto.randomBytes(12);
  const c = crypto.createCipheriv('aes-256-gcm', setupKey, iv);
  fs.writeFileSync(SETUP_FILE, Buffer.concat([iv, c.update(setup, 'utf8'), c.final(), c.getAuthTag()]), { mode: 0o600 });
  return setup;
}
let serverSetup = null;

const users = new Map(); // nameId -> { r: OPAQUE registration record }
const B64URL = /^[A-Za-z0-9_-]+$/;
try {
  for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(USERS_FILE, 'utf8')))) {
    if (/^[0-9a-f]{64}$/.test(k) && v && typeof v.r === 'string' && B64URL.test(v.r)) users.set(k, { r: v.r });
  }
} catch { /* fresh start */ }
function saveUsers() {
  const tmp = USERS_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(Object.fromEntries(users)), { mode: 0o600 });
  fs.renameSync(tmp, USERS_FILE);
}
const opq = (s, max) => typeof s === 'string' && s.length > 0 && s.length <= max && B64URL.test(s);

// ---------- firewall: IPs only as keyed hash in RAM, key rotates daily ----------
let ipKey = crypto.randomBytes(32);
const bans = new Map();      // ipHash -> until
const strikes = new Map();   // ipHash -> { n, reset }
setInterval(() => { ipKey = crypto.randomBytes(32); bans.clear(); strikes.clear(); }, 86400_000).unref();
function rawIp(socket, req) {
  if (env.TRUST_PROXY && req) { const f = String(req.headers['x-forwarded-for'] || '').split(',')[0].trim(); if (f) return f; }
  return socket.remoteAddress || '';
}
const hashIp = (raw) => crypto.createHmac('sha256', ipKey).update(raw).digest('base64');
const banned = (h) => { const u = bans.get(h); if (!u) return false; if (u > Date.now()) return true; bans.delete(h); return false; };
// weight: how suspicious the event was. 10 points within an hour -> banned for an hour.
function strike(h, weight = 1) {
  let s = strikes.get(h);
  if (!s || s.reset < Date.now()) { s = { n: 0, reset: Date.now() + 3600_000 }; strikes.set(h, s); }
  s.n += weight;
  if (s.n >= 10) bans.set(h, Date.now() + 3600_000);
}
const drop = (socket) => { try { socket.destroy(); } catch {} };

// ---------- brute-force / abuse protection ----------
const failsByName = new Map();
const ipCounters = new Map();
function ipc(ip) {
  let c = ipCounters.get(ip);
  if (!c || c.reset < Date.now()) { c = { fails: 0, regs: 0, reset: Date.now() + 3600_000 }; ipCounters.set(ip, c); }
  return c;
}
function nameLocked(id) {
  const f = failsByName.get(id);
  return f && f.until > Date.now() ? Math.ceil((f.until - Date.now()) / 1000) : 0;
}
function registerFail(id, ip, suspicious = true) {
  const f = failsByName.get(id) || { count: 0, until: 0 };
  f.count++;
  if (f.count >= 5) f.until = Date.now() + Math.min(2 ** (f.count - 5), 1440) * 60_000;
  failsByName.set(id, f);
  ipc(ip).fails++;
  if (suspicious) strike(ip, 0.5);
}
setInterval(() => {
  const now = Date.now();
  for (const [k, v] of ipCounters) if (v.reset < now) ipCounters.delete(k);
  for (const [k, v] of failsByName) if (v.until < now - 86400_000) failsByName.delete(k);
  for (const [k, v] of strikes) if (v.reset < now) strikes.delete(k);
  for (const [k, v] of bans) if (v < now) bans.delete(k);
}, 600_000).unref();

// ---------- static files ----------
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml' };
const SEC_HEADERS = {
  'Content-Security-Policy': [
    "default-src 'none'", "script-src 'self' 'wasm-unsafe-eval'", "style-src 'self'", "img-src 'self'", "connect-src 'self'",
    "base-uri 'none'", "form-action 'none'", "frame-ancestors 'none'",
    "require-trusted-types-for 'script'", "trusted-types 'none'",
    // only when we really serve HTTPS – on plain http it would make the browser fetch CSS/JS via https and fail
    ...(env.TLS_CERT || env.TRUST_PROXY ? ['upgrade-insecure-requests'] : []),
  ].join('; '),
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'no-referrer',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Embedder-Policy': 'require-corp',
  'Cross-Origin-Resource-Policy': 'same-origin',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=(), serial=(), hid=(), clipboard-read=(), display-capture=(), interest-cohort=()',
  'Cache-Control': 'no-store, max-age=0',
  'X-DNS-Prefetch-Control': 'off',
  'X-Robots-Tag': 'noindex, nofollow, noarchive',
};
if (env.TLS_CERT || env.TRUST_PROXY) SEC_HEADERS['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains; preload';
const files = new Map();
for (const f of fs.readdirSync(PUBLIC_DIR)) {
  if (/^[a-z0-9_-]+\.(html|js|css|svg)$/.test(f)) files.set(f, fs.readFileSync(path.join(PUBLIC_DIR, f)));
}
function hostOk(req) {
  if (!DOMAINS.length) return true;
  const h = String(req.headers.host || '').toLowerCase().replace(/:\d+$/, '');
  return DOMAINS.includes(h);
}
function handler(req, res) {
  const ip = req.socket.ipHash;
  if (!hostOk(req)) { strike(ip, 2); return drop(req.socket); }
  if (req.method !== 'GET' && req.method !== 'HEAD') { strike(ip, 2); return drop(req.socket); }
  const url = String(req.url || '').split('?')[0];
  const name = url === '/' ? 'index.html' : url.slice(1);
  const body = files.get(name);
  if (!body) {
    if (url !== '/favicon.ico') strike(ip, 1.5); // /wp-admin, /.env, /phpmyadmin … -> banned after a few
    return drop(req.socket);
  }
  res.removeHeader('Date');
  res.writeHead(200, { ...SEC_HEADERS, 'Content-Type': TYPES[path.extname(name)], 'Content-Length': body.length });
  res.end(req.method === 'HEAD' ? undefined : body);
}

let server;
if (env.TLS_CERT) {
  const ctx = tls.createSecureContext({ cert: fs.readFileSync(env.TLS_CERT), key: fs.readFileSync(env.TLS_KEY), minVersion: 'TLSv1.3' });
  const opts = { minVersion: 'TLSv1.3', SNICallback: (sn, cb) => (!DOMAINS.length || DOMAINS.includes(String(sn).toLowerCase()) ? cb(null, ctx) : cb(new Error('x'))) };
  // Without a configured domain we still need a default cert. With one, handshakes without the
  // right SNI (= scans by IP) fail and never even reveal the certificate.
  if (!DOMAINS.length) Object.assign(opts, { cert: fs.readFileSync(env.TLS_CERT), key: fs.readFileSync(env.TLS_KEY) });
  server = https.createServer(opts, handler);
  server.on('tlsClientError', (e, socket) => { if (socket.ipHash) strike(socket.ipHash, 1); drop(socket); });
} else {
  server = http.createServer(handler);
}
server.headersTimeout = 10_000;
server.requestTimeout = 15_000;
server.keepAliveTimeout = 5_000;
server.maxHeadersCount = 40;
// raw TCP level: banned IPs are cut off before a single byte is answered
server.on('connection', (socket) => {
  socket.ipHash = hashIp(rawIp(socket));
  if (banned(socket.ipHash)) return drop(socket);
  socket.setTimeout(20_000, () => drop(socket));
});
// garbage / non-HTTP probes (nmap -sV, banner grabbing): no "400 Bad Request", just silence
server.on('clientError', (e, socket) => { if (socket.ipHash) strike(socket.ipHash, 1); drop(socket); });

// ---------- websocket relay ----------
const online = new Map();    // name -> { ws, pub, kpub, watchers: Set<name> } (RAM only, gone on disconnect)
// tell everyone who had a chat open with `name` that it is gone -> their chat gets deleted
function announceGone(name, entry) {
  for (const w of entry.watchers) { const o = online.get(w); if (o) send(o.ws, { t: 'gone', name }); }
  entry.watchers.clear();
}
const watch = (entry, name) => { if (entry.watchers.size < 1000) entry.watchers.add(name); };
const connsByIp = new Map();
const wss = new WebSocketServer({ noServer: true, maxPayload: 64 * 1024, perMessageDeflate: false });
server.on('upgrade', (req, socket, head) => {
  const ip = env.TRUST_PROXY ? hashIp(rawIp(socket, req)) : socket.ipHash;
  if (banned(ip)) return drop(socket);
  if (!hostOk(req) || req.url !== '/') { strike(ip, 2); return drop(socket); }
  let okOrigin = false;
  try { okOrigin = new URL(req.headers.origin).host === req.headers.host; } catch {}
  if (!okOrigin && APP_ORIGINS.includes(String(req.headers.origin))) okOrigin = true;
  if (!okOrigin) { strike(ip, 3); return drop(socket); }
  if ((connsByIp.get(ip) || 0) >= MAX_CONN_PER_IP || wss.clients.size >= MAX_CONN_TOTAL) return drop(socket);
  socket.setTimeout(0);
  wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, ip));
});

// every frame is padded to a fixed bucket size -> sizes reveal nothing
function frame(obj) {
  const base = Buffer.byteLength(JSON.stringify({ ...obj, _: '' }));
  const size = BUCKETS.find((b) => b >= base) || base;
  return Buffer.from(JSON.stringify({ ...obj, _: '0'.repeat(size - base) }));
}
/*
 * Tunnel: on top of TLS, every frame is encrypted again with AES-256-GCM using keys from a fresh
 * ECDH handshake per connection. Nonces are strict counters (no replay, no reordering).
 * In the browser's DevTools you only see equal-sized binary blobs.
 */
function tunnelKeys(serverEcdh, clientPubB64) {
  const shared = serverEcdh.computeSecret(Buffer.from(clientPubB64, 'base64'));
  const okm = Buffer.from(crypto.hkdfSync('sha256', shared, Buffer.alloc(32), 'BetterChats tunnel v1', 64));
  shared.fill(0);
  return { c2s: okm.subarray(0, 32), s2c: okm.subarray(32, 64), inN: 0n, outN: 0n };
}
const nonce = (n) => { const b = Buffer.alloc(12); b.writeBigUInt64BE(n, 4); return b; };
function seal(t, plain) {
  const iv = nonce(t.outN++);
  const c = crypto.createCipheriv('aes-256-gcm', t.s2c, iv);
  return Buffer.concat([c.update(plain), c.final(), c.getAuthTag()]);
}
function open(t, buf) {
  if (buf.length < 17) throw new Error('short');
  const c = crypto.createDecipheriv('aes-256-gcm', t.c2s, nonce(t.inN));
  c.setAuthTag(buf.subarray(buf.length - 16));
  const out = Buffer.concat([c.update(buf.subarray(0, buf.length - 16)), c.final()]);
  t.inN++;
  return out;
}
const send = (ws, obj) => { if (ws.readyState === 1 && ws.tunnel) ws.send(seal(ws.tunnel, frame(obj))); };
const B64 = /^[A-Za-z0-9+/]+={0,2}$/;
const b64ok = (s, min, max) => typeof s === 'string' && s.length >= min && s.length <= max && B64.test(s);
const validPub = (p) => b64ok(p, 88, 88) && Buffer.from(p, 'base64')[0] === 4;   // P-256 uncompressed point
const validKpub = (p) => b64ok(p, 1580, 1580);                                   // ML-KEM-768 public key (1184 bytes)
const GENERIC_FAIL = 'Name oder Passwort falsch.';

wss.on('connection', (ws, ip) => {
  connsByIp.set(ip, (connsByIp.get(ip) || 0) + 1);
  const serverEcdh = crypto.createECDH('prime256v1');
  ws.send(JSON.stringify({ t: 'hello', spub: serverEcdh.generateKeys().toString('base64') }));
  const handshakeTimer = setTimeout(() => { if (!ws.tunnel) ws.terminate(); }, 10_000);
  let me = null;
  let tokens = 30;
  let lookupTokens = 20;
  let tick = 0;
  let junk = 0; // malformed frames
  const refill = setInterval(() => {
    tokens = Math.min(30, tokens + 5);
    if (++tick % 3 === 0) lookupTokens = Math.min(20, lookupTokens + 1);
  }, 1000);
  // cover traffic towards the client: random dummy frames, same size as real ones
  let coverTimer = null;
  const cover = () => {
    coverTimer = setTimeout(() => { send(ws, { t: 'c' }); cover(); }, 800 + Math.floor(-Math.log(1 - Math.random()) * 2500));
  };
  if (COVER) cover();
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('error', () => {});
  const bad = () => { if (++junk > 5) { strike(ip, 3); ws.terminate(); } };

  // OPAQUE state of this connection (only one auth flow at a time)
  let flow = null; // { kind: 'reg'|'login'|'del', id, name, state?, at }
  const flowAlive = () => flow && Date.now() - flow.at < 30_000;
  function becomeOnline(name, pub, kpub) {
    const prev = online.get(name);
    if (prev) { announceGone(name, prev); send(prev.ws, { t: 'kicked' }); prev.ws.close(); }
    me = name;
    online.set(name, { ws, pub, kpub, watchers: new Set() });
    send(ws, { t: 'ok', name });
  }

  ws.on('message', async (raw, isBinary) => {
    if (--tokens < 0) return;
    if (!ws.tunnel) {
      let h;
      try { h = JSON.parse(raw.toString('utf8')); } catch { return bad(); }
      if (isBinary || !h || h.t !== 'hello' || !validPub(h.cpub)) { strike(ip, 3); return ws.terminate(); }
      try { ws.tunnel = tunnelKeys(serverEcdh, h.cpub); } catch { strike(ip, 3); return ws.terminate(); }
      clearTimeout(handshakeTimer);
      return;
    }
    if (!isBinary) return bad();
    let m;
    try { m = JSON.parse(open(ws.tunnel, raw).toString('utf8')); } catch { strike(ip, 3); return ws.terminate(); }
    if (!m || typeof m !== 'object' || Array.isArray(m)) return bad();
    if (m.t === 'c') return; // client cover traffic

    // ---- registration (OPAQUE): reg1 -> reg1, reg2 -> ok ----
    if (m.t === 'reg1' && !me) {
      const name = typeof m.name === 'string' ? m.name.toLowerCase() : '';
      if (!NAME_RE.test(name)) return send(ws, { t: 'err', msg: 'Name: 3–20 Zeichen, nur a–z, 0–9 und _' });
      if (!opq(m.req, 200)) return bad();
      const c = ipc(ip);
      if (c.regs >= 5) return send(ws, { t: 'err', msg: 'Zu viele neue Konten. Bitte später erneut.' });
      if (users.size >= MAX_USERS) return send(ws, { t: 'err', msg: 'Registrierung gerade nicht möglich.' });
      const id = nameId(name);
      if (users.has(id)) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
      try {
        const { registrationResponse } = opaque.server.createRegistrationResponse({ serverSetup, userIdentifier: id, registrationRequest: m.req });
        flow = { kind: 'reg', id, name, at: Date.now() };
        return send(ws, { t: 'reg1', res: registrationResponse });
      } catch { return bad(); }
    }
    if (m.t === 'reg2' && !me) {
      if (!flowAlive() || flow.kind !== 'reg' || !opq(m.rec, 400) || !validPub(m.pub) || !validKpub(m.kpub)) { flow = null; return bad(); }
      const { id, name } = flow; flow = null;
      if (users.has(id)) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
      users.set(id, { r: m.rec });
      saveUsers();
      ipc(ip).regs++;
      return becomeOnline(name, m.pub, m.kpub);
    }

    // ---- login (OPAQUE): log1 -> log1, log2 -> ok. The password never leaves the device. ----
    if (m.t === 'log1' && !me) {
      const name = typeof m.name === 'string' ? m.name.toLowerCase() : '';
      if (!NAME_RE.test(name)) return send(ws, { t: 'err', msg: 'Name: 3–20 Zeichen, nur a–z, 0–9 und _' });
      if (!opq(m.req, 200)) return bad();
      if (ipc(ip).fails >= 30) return send(ws, { t: 'err', msg: 'Zu viele Fehlversuche. Bitte später erneut.' });
      const id = nameId(name);
      const wait = nameLocked(id);
      if (wait) return send(ws, { t: 'err', msg: `Zu viele Fehlversuche. Warte ${wait} Sekunden.` });
      try {
        // unknown names get an indistinguishable fake response (no user enumeration through login)
        const { loginResponse, serverLoginState } = opaque.server.startLogin({
          serverSetup, userIdentifier: id, registrationRecord: users.get(id)?.r ?? null, startLoginRequest: m.req,
        });
        registerFail(id, ip, false); // counts as a failed attempt until log2 proves the password
        flow = { kind: 'login', id, name, state: serverLoginState, at: Date.now() };
        return send(ws, { t: 'log1', res: loginResponse });
      } catch { return bad(); }
    }
    if (m.t === 'log2' && !me) {
      if (!flowAlive() || flow.kind !== 'login' || !opq(m.fin, 400) || !validPub(m.pub) || !validKpub(m.kpub)) { flow = null; return bad(); }
      const { id, name, state } = flow; flow = null;
      try { opaque.server.finishLogin({ finishLoginRequest: m.fin, serverLoginState: state }); } catch { return send(ws, { t: 'err', msg: GENERIC_FAIL }); }
      if (!users.has(id)) return send(ws, { t: 'err', msg: GENERIC_FAIL });
      failsByName.delete(id); // correct password proven -> counter reset
      const c = ipc(ip); c.fails = Math.max(0, c.fails - 1);
      return becomeOnline(name, m.pub, m.kpub);
    }
    if (!me) return;

    if (m.t === 'lookup') {
      const name = typeof m.name === 'string' ? m.name.toLowerCase() : '';
      if (!NAME_RE.test(name)) return bad();
      if (--lookupTokens < 0) return send(ws, { t: 'lookup', name, exists: false, limited: true });
      const o = online.get(name);
      if (o && name !== me) watch(o, me);
      return send(ws, { t: 'lookup', name, exists: users.has(nameId(name)), online: !!o, pub: o ? o.pub : null, kpub: o ? o.kpub : null });
    }

    if (m.t === 'msg') {
      const to = typeof m.to === 'string' ? m.to.toLowerCase() : '';
      if (!NAME_RE.test(to) || !b64ok(m.iv, 16, 16) || !b64ok(m.ct, 4, MAX_CT) || !b64ok(m.id, 16, 16) || !b64ok(m.k, 1452, 1452)
        || !Number.isSafeInteger(m.n) || m.n < 0 || m.n > 1e7) return bad();
      const o = online.get(to);
      if (!o) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'offline' });
      if (m.pub !== o.pub || m.kpub !== o.kpub) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'newkey', pub: o.pub, kpub: o.kpub });
      const self = online.get(me);
      watch(o, me); watch(self, to);
      send(o.ws, { t: 'msg', from: me, pub: self.pub, kpub: self.kpub, k: m.k, n: m.n, iv: m.iv, ct: m.ct });
      return send(ws, { t: 'delivered', id: m.id, to });
    }

    // ---- delete own account: needs a fresh OPAQUE proof of the password ----
    if (m.t === 'del1') {
      if (!opq(m.req, 200)) return bad();
      const id = nameId(me);
      if (nameLocked(id)) return send(ws, { t: 'delete', ok: false, msg: 'Zu viele Fehlversuche.' });
      try {
        const { loginResponse, serverLoginState } = opaque.server.startLogin({ serverSetup, userIdentifier: id, registrationRecord: users.get(id)?.r ?? null, startLoginRequest: m.req });
        registerFail(id, ip, false);
        flow = { kind: 'del', id, state: serverLoginState, at: Date.now() };
        return send(ws, { t: 'del1', res: loginResponse });
      } catch { return bad(); }
    }
    if (m.t === 'del2') {
      if (!flowAlive() || flow.kind !== 'del' || !opq(m.fin, 400)) { flow = null; return bad(); }
      const { id, state } = flow; flow = null;
      try { opaque.server.finishLogin({ finishLoginRequest: m.fin, serverLoginState: state }); } catch { return send(ws, { t: 'delete', ok: false, msg: 'Passwort falsch.' }); }
      failsByName.delete(id);
      users.delete(id);
      saveUsers();
      send(ws, { t: 'delete', ok: true });
      return ws.close();
    }
    bad();
  });

  ws.on('close', () => {
    clearInterval(refill);
    clearTimeout(handshakeTimer);
    if (ws.tunnel) { ws.tunnel.c2s.fill(0); ws.tunnel.s2c.fill(0); }
    clearTimeout(coverTimer);
    const n = (connsByIp.get(ip) || 1) - 1;
    if (n <= 0) connsByIp.delete(ip); else connsByIp.set(ip, n);
    const entry = me && online.get(me);
    if (entry && entry.ws === ws) { online.delete(me); announceGone(me, entry); }
  });
});

setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) { ws.terminate(); continue; }
    ws.isAlive = false;
    ws.ping();
  }
}, 30_000).unref();

process.on('uncaughtException', () => { console.error('Interner Fehler (Details absichtlich nicht geloggt)'); });
server.on('error', (e) => {
  if (e.code === 'EACCES') console.error(`Port ${PORT} braucht Rechte (Ports unter 1024). Siehe README: setcap oder systemd-Dienst.`);
  else console.error('Serverfehler:', e.code);
  process.exit(1);
});
opaque.ready.then(() => {
  serverSetup = loadSetup();
  server.listen(PORT, HOST, () => console.log(`BetterChats läuft auf ${HOST}:${PORT} · Server-Schlüssel: ${opaque.server.getPublicKey(serverSetup)}`));
});
