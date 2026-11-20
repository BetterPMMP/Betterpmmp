'use strict';
/*
 * BetterChats server
 *
 * Stores on disk ONLY:
 *   - HMAC-SHA256(username)          (the name itself is never written in plain text)
 *   - scrypt(PIN) + random salt      (the PIN is never written in plain text)
 *
 * Messages are end-to-end encrypted in the browser. The server only relays
 * ciphertext to an online recipient and forgets it right away.
 * No access logs, no message logs. IP addresses are never written anywhere and are
 * only held in RAM as a keyed hash (key rotates every 24 h) for brute-force protection.
 */
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const PORT = Number(process.env.PORT || 8080);
const HOST = process.env.HOST || '0.0.0.0';
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
const PUBLIC_DIR = path.join(__dirname, 'public');
const USERS_FILE = path.join(DATA_DIR, 'users.json');
const SECRET_FILE = path.join(DATA_DIR, 'server.key');

const NAME_RE = /^[a-z0-9_]{3,20}$/;
const PIN_RE = /^\d{6}$/;
const MAX_MSG_BYTES = 16 * 1024;
const MAX_USERS = Number(process.env.MAX_USERS || 100_000);
const MAX_CONN_PER_IP = 10;
const MAX_CONN_TOTAL = 5000;

// ---------- persistent store (names + PINs, both hashed) ----------
fs.mkdirSync(DATA_DIR, { recursive: true, mode: 0o700 });
if (!fs.existsSync(SECRET_FILE)) fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32), { mode: 0o600 });
// Optional pepper from the environment: then stealing the data folder alone is not enough
// to brute-force PINs offline.
const SERVER_KEY = crypto.createHash('sha256')
  .update(fs.readFileSync(SECRET_FILE)).update(process.env.BETTERCHATS_PEPPER || '').digest();

const users = new Map();
try {
  for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(USERS_FILE, 'utf8')))) {
    if (/^[0-9a-f]{64}$/.test(k) && v && typeof v.s === 'string' && typeof v.h === 'string') users.set(k, { s: v.s, h: v.h });
  }
} catch { /* fresh start */ }
function saveUsers() {
  const tmp = USERS_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(Object.fromEntries(users)), { mode: 0o600 });
  fs.renameSync(tmp, USERS_FILE);
}
const nameId = (name) => crypto.createHmac('sha256', SERVER_KEY).update('name:' + name).digest('hex');

const SCRYPT = { N: 1 << 15, r: 8, p: 1, maxmem: 64 * 1024 * 1024 };
function hashPin(pin, salt) {
  return new Promise((res, rej) =>
    crypto.scrypt(pin, Buffer.concat([salt, SERVER_KEY]), 32, SCRYPT, (e, k) => (e ? rej(e) : res(k))));
}
// scrypt is deliberately slow; cap how many run at once so login floods can't freeze the server.
let authRunning = 0;
const authQueue = [];
async function authSlot(fn) {
  if (authRunning >= 4) {
    if (authQueue.length >= 200) throw new Error('busy');
    await new Promise((r) => authQueue.push(r));
  }
  authRunning++;
  try { return await fn(); } finally { authRunning--; const next = authQueue.shift(); if (next) next(); }
}

// ---------- IPs: only as keyed hash in RAM, key rotates daily ----------
let ipKey = crypto.randomBytes(32);
setInterval(() => { ipKey = crypto.randomBytes(32); }, 86400_000).unref();
function ipOf(req) {
  const raw = (process.env.TRUST_PROXY && String(req.headers['x-forwarded-for'] || '').split(',')[0].trim()) || req.socket.remoteAddress || '';
  return crypto.createHmac('sha256', ipKey).update(raw).digest('base64');
}

// ---------- brute-force / abuse protection ----------
const failsByName = new Map(); // nameId -> { count, until }
const ipCounters = new Map();  // ipHash -> { fails, regs, reset }
function ipc(ip) {
  let c = ipCounters.get(ip);
  if (!c || c.reset < Date.now()) { c = { fails: 0, regs: 0, reset: Date.now() + 3600_000 }; ipCounters.set(ip, c); }
  return c;
}
function nameLocked(id) {
  const f = failsByName.get(id);
  return f && f.until > Date.now() ? Math.ceil((f.until - Date.now()) / 1000) : 0;
}
function registerFail(id, ip) {
  const f = failsByName.get(id) || { count: 0, until: 0 };
  f.count++;
  if (f.count >= 5) f.until = Date.now() + Math.min(2 ** (f.count - 5), 1440) * 60_000; // 1,2,4… min, max 24 h
  failsByName.set(id, f);
  ipc(ip).fails++;
}
setInterval(() => {
  const now = Date.now();
  for (const [k, v] of ipCounters) if (v.reset < now) ipCounters.delete(k);
  for (const [k, v] of failsByName) if (v.until < now - 86400_000) failsByName.delete(k);
}, 600_000).unref();

// ---------- static files ----------
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml' };
const SEC_HEADERS = {
  'Content-Security-Policy': [
    "default-src 'none'", "script-src 'self'", "style-src 'self'", "img-src 'self'", "connect-src 'self'",
    "base-uri 'none'", "form-action 'none'", "frame-ancestors 'none'",
    "require-trusted-types-for 'script'", "trusted-types 'none'",
  ].join('; '),
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'no-referrer',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Embedder-Policy': 'require-corp',
  'Cross-Origin-Resource-Policy': 'same-origin',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
  'Cache-Control': 'no-store',
  'X-DNS-Prefetch-Control': 'off',
};
const files = new Map(); // preloaded, so no filesystem access happens per request at all
for (const f of fs.readdirSync(PUBLIC_DIR)) {
  if (/^[a-z0-9_-]+\.(html|js|css|svg)$/.test(f)) files.set(f, fs.readFileSync(path.join(PUBLIC_DIR, f)));
}
function handler(req, res) {
  const h = { ...SEC_HEADERS };
  if (process.env.TLS_CERT || process.env.TRUST_PROXY) h['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains';
  if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405, h); return res.end(); }
  const url = String(req.url || '').split('?')[0];
  const name = url === '/' ? 'index.html' : url.slice(1);
  const body = files.get(name);
  if (!body) { res.writeHead(404, h); return res.end(); }
  h['Content-Type'] = TYPES[path.extname(name)];
  res.writeHead(200, h);
  res.end(req.method === 'HEAD' ? undefined : body);
}
const server = process.env.TLS_CERT
  ? https.createServer({ cert: fs.readFileSync(process.env.TLS_CERT), key: fs.readFileSync(process.env.TLS_KEY), minVersion: 'TLSv1.2' }, handler)
  : http.createServer(handler);
server.headersTimeout = 10_000;
server.requestTimeout = 15_000;
server.keepAliveTimeout = 5_000;

// ---------- websocket relay ----------
const online = new Map();   // name -> { ws, pub }
const connsByIp = new Map(); // ipHash -> count
const wss = new WebSocketServer({
  server,
  maxPayload: MAX_MSG_BYTES * 2,
  perMessageDeflate: false, // compression + secrets = CRIME/BREACH-style leaks; off.
  verifyClient: ({ req }, done) => {
    const origin = req.headers.origin;
    let okOrigin = false;
    try { okOrigin = !!origin && new URL(origin).host === req.headers.host; } catch {}
    if (!okOrigin) return done(false, 403);
    const ip = ipOf(req);
    if ((connsByIp.get(ip) || 0) >= MAX_CONN_PER_IP || wss.clients.size >= MAX_CONN_TOTAL) return done(false, 429);
    req.ipHash = ip;
    done(true);
  },
});
const send = (ws, obj) => { if (ws.readyState === 1) ws.send(JSON.stringify(obj)); };
const validPub = (p) => typeof p === 'string' && p.length === 88 && /^[A-Za-z0-9+/]+={0,2}$/.test(p) && Buffer.from(p, 'base64')[0] === 4;
const b64ok = (s, max) => typeof s === 'string' && s.length > 0 && s.length <= max && /^[A-Za-z0-9+/]+={0,2}$/.test(s);
const GENERIC_FAIL = 'Name oder Passwort falsch.';

wss.on('connection', (ws, req) => {
  const ip = req.ipHash;
  connsByIp.set(ip, (connsByIp.get(ip) || 0) + 1);
  let me = null;
  let busy = false;
  let tokens = 30;        // messages per connection (burst 30, +5/s)
  let lookupTokens = 20;  // lookups (burst 20, +1 per 3 s)
  let tick = 0;
  const refill = setInterval(() => {
    tokens = Math.min(30, tokens + 5);
    if (++tick % 3 === 0) lookupTokens = Math.min(20, lookupTokens + 1);
  }, 1000);
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('error', () => {});

  async function checkPin(id, pin) {
    const u = users.get(id);
    const salt = u ? Buffer.from(u.s, 'base64') : crypto.randomBytes(16);
    const hash = await authSlot(() => hashPin(pin, salt)); // same cost for unknown names
    return !!u && crypto.timingSafeEqual(hash, Buffer.from(u.h, 'base64'));
  }

  ws.on('message', async (raw, isBinary) => {
    if (isBinary || --tokens < 0) return;
    let m;
    try { m = JSON.parse(raw.toString('utf8')); } catch { return; }
    if (!m || typeof m !== 'object' || Array.isArray(m)) return;

    // ---- register / login ----
    if ((m.t === 'register' || m.t === 'login') && !me && !busy) {
      const name = typeof m.name === 'string' ? m.name.toLowerCase() : '';
      const pin = typeof m.pin === 'string' ? m.pin : '';
      if (!NAME_RE.test(name)) return send(ws, { t: 'err', msg: 'Name: 3–20 Zeichen, nur a–z, 0–9 und _' });
      if (!PIN_RE.test(pin)) return send(ws, { t: 'err', msg: 'Passwort muss genau 6 Ziffern haben.' });
      if (!validPub(m.pub)) return send(ws, { t: 'err', msg: 'Ungültiger Schlüssel.' });
      const c = ipc(ip);
      if (c.fails >= 30) return send(ws, { t: 'err', msg: 'Zu viele Fehlversuche. Bitte später erneut.' });
      const id = nameId(name);
      busy = true;
      try {
        if (m.t === 'register') {
          if (c.regs >= 5) return send(ws, { t: 'err', msg: 'Zu viele neue Konten. Bitte später erneut.' });
          if (users.size >= MAX_USERS) return send(ws, { t: 'err', msg: 'Registrierung gerade nicht möglich.' });
          if (users.has(id)) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
          const salt = crypto.randomBytes(16);
          const hash = await authSlot(() => hashPin(pin, salt));
          if (users.has(id)) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
          users.set(id, { s: salt.toString('base64'), h: hash.toString('base64') });
          saveUsers();
          c.regs++;
        } else {
          const wait = nameLocked(id);
          if (wait) return send(ws, { t: 'err', msg: `Zu viele Fehlversuche. Warte ${wait} Sekunden.` });
          if (!(await checkPin(id, pin))) { registerFail(id, ip); return send(ws, { t: 'err', msg: GENERIC_FAIL }); }
          failsByName.delete(id);
        }
        if (ws.readyState !== 1) return;
        const prev = online.get(name);
        if (prev) { send(prev.ws, { t: 'kicked' }); prev.ws.close(); }
        me = name;
        online.set(name, { ws, pub: m.pub });
        send(ws, { t: 'ok', name });
      } catch {
        send(ws, { t: 'err', msg: 'Server ist ausgelastet. Bitte gleich nochmal.' });
      } finally { busy = false; }
      return;
    }
    if (!me) return;

    // ---- look up a contact's current session key ----
    if (m.t === 'lookup') {
      const name = typeof m.name === 'string' ? m.name.toLowerCase() : '';
      if (!NAME_RE.test(name)) return;
      if (--lookupTokens < 0) return send(ws, { t: 'lookup', name, exists: false, limited: true });
      const o = online.get(name);
      return send(ws, { t: 'lookup', name, exists: users.has(nameId(name)), online: !!o, pub: o ? o.pub : null });
    }

    // ---- relay an encrypted message (never stored, never logged) ----
    if (m.t === 'msg') {
      const to = typeof m.to === 'string' ? m.to.toLowerCase() : '';
      if (!NAME_RE.test(to) || !b64ok(m.iv, 16) || !b64ok(m.ct, MAX_MSG_BYTES) || !b64ok(m.id, 16)
        || !Number.isSafeInteger(m.n) || m.n < 0 || m.n > 1e7) return;
      const o = online.get(to);
      if (!o) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'offline' });
      if (m.pub !== o.pub) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'newkey', pub: o.pub });
      send(o.ws, { t: 'msg', from: me, pub: online.get(me).pub, n: m.n, iv: m.iv, ct: m.ct });
      return send(ws, { t: 'delivered', id: m.id, to });
    }

    // ---- delete own account ----
    if (m.t === 'delete' && !busy) {
      const pin = typeof m.pin === 'string' ? m.pin : '';
      if (!PIN_RE.test(pin)) return send(ws, { t: 'delete', ok: false, msg: 'Passwort muss genau 6 Ziffern haben.' });
      const id = nameId(me);
      if (nameLocked(id)) return send(ws, { t: 'delete', ok: false, msg: 'Zu viele Fehlversuche.' });
      busy = true;
      try {
        if (!(await checkPin(id, pin))) { registerFail(id, ip); return send(ws, { t: 'delete', ok: false, msg: 'Passwort falsch.' }); }
        users.delete(id);
        saveUsers();
        send(ws, { t: 'delete', ok: true });
        ws.close();
      } catch {
        send(ws, { t: 'delete', ok: false, msg: 'Server ist ausgelastet.' });
      } finally { busy = false; }
    }
  });

  ws.on('close', () => {
    clearInterval(refill);
    const n = (connsByIp.get(ip) || 1) - 1;
    if (n <= 0) connsByIp.delete(ip); else connsByIp.set(ip, n);
    if (me && online.get(me)?.ws === ws) online.delete(me);
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

server.listen(PORT, HOST, () => console.log(`BetterChats läuft auf Port ${PORT}`));
