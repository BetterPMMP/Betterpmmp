'use strict';
/*
 * BetterChats server
 *
 * Stores on disk ONLY:
 *   - HMAC-SHA256(username)          (the name itself is never written in plain text)
 *   - scrypt(PIN) + random salt      (the PIN is never written in plain text)
 *
 * Messages are end-to-end encrypted in the browser (ECDH P-256 + AES-256-GCM).
 * The server only relays ciphertext to an online recipient and forgets it right away.
 * Nothing about messages is logged or written to disk.
 */
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const PORT = Number(process.env.PORT || 8080);
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
const PUBLIC_DIR = path.join(__dirname, 'public');
const USERS_FILE = path.join(DATA_DIR, 'users.json');
const SECRET_FILE = path.join(DATA_DIR, 'server.key');

const NAME_RE = /^[a-z0-9_]{3,20}$/;
const PIN_RE = /^\d{6}$/;
const MAX_MSG_BYTES = 16 * 1024;

// ---------- persistent store (names + PINs, both hashed) ----------
fs.mkdirSync(DATA_DIR, { recursive: true, mode: 0o700 });
if (!fs.existsSync(SECRET_FILE)) {
  fs.writeFileSync(SECRET_FILE, crypto.randomBytes(32), { mode: 0o600 });
}
const SERVER_KEY = fs.readFileSync(SECRET_FILE);
let users = {};
try { users = JSON.parse(fs.readFileSync(USERS_FILE, 'utf8')); } catch { users = {}; }

function saveUsers() {
  const tmp = USERS_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(users), { mode: 0o600 });
  fs.renameSync(tmp, USERS_FILE);
}
const nameId = (name) => crypto.createHmac('sha256', SERVER_KEY).update(name).digest('hex');
const SCRYPT = { N: 1 << 15, r: 8, p: 1, maxmem: 64 * 1024 * 1024 };
function hashPin(pin, salt) {
  return new Promise((res, rej) =>
    crypto.scrypt(pin, Buffer.concat([salt, SERVER_KEY]), 32, SCRYPT, (e, k) => (e ? rej(e) : res(k))));
}

// ---------- brute-force protection (6-digit PINs need this!) ----------
const failsByName = new Map(); // id -> { count, until }
const failsByIp = new Map();   // ip -> { count, reset }
function nameLocked(id) {
  const f = failsByName.get(id);
  return f && f.until > Date.now() ? Math.ceil((f.until - Date.now()) / 1000) : 0;
}
function ipBlocked(ip) {
  const f = failsByIp.get(ip);
  return f && f.reset > Date.now() && f.count >= 30;
}
function registerFail(id, ip) {
  const f = failsByName.get(id) || { count: 0, until: 0 };
  f.count++;
  if (f.count >= 5) f.until = Date.now() + Math.min(2 ** (f.count - 5), 1440) * 60_000; // 1,2,4.. min, max 24h
  failsByName.set(id, f);
  const g = failsByIp.get(ip);
  if (!g || g.reset < Date.now()) failsByIp.set(ip, { count: 1, reset: Date.now() + 3600_000 });
  else g.count++;
}
setInterval(() => {
  const now = Date.now();
  for (const [k, v] of failsByIp) if (v.reset < now) failsByIp.delete(k);
  for (const [k, v] of failsByName) if (v.until < now - 86400_000) failsByName.delete(k);
}, 600_000).unref();

// ---------- static files ----------
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml' };
const SEC_HEADERS = {
  'Content-Security-Policy': "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'no-referrer',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
  'Cache-Control': 'no-store',
};
function handler(req, res) {
  const url = req.url.split('?')[0];
  const file = url === '/' ? 'index.html' : url.slice(1);
  if (!/^[a-z0-9_-]+\.(html|js|css|svg)$/.test(file)) { res.writeHead(404, SEC_HEADERS); return res.end(); }
  fs.readFile(path.join(PUBLIC_DIR, file), (err, buf) => {
    if (err) { res.writeHead(404, SEC_HEADERS); return res.end(); }
    const h = { ...SEC_HEADERS, 'Content-Type': TYPES[path.extname(file)] };
    if (process.env.TLS_CERT) h['Strict-Transport-Security'] = 'max-age=31536000';
    res.writeHead(200, h);
    res.end(buf);
  });
}
const server = process.env.TLS_CERT
  ? https.createServer({ cert: fs.readFileSync(process.env.TLS_CERT), key: fs.readFileSync(process.env.TLS_KEY) }, handler)
  : http.createServer(handler);

// ---------- websocket relay ----------
const online = new Map(); // name -> { ws, pub }
const wss = new WebSocketServer({
  server,
  maxPayload: MAX_MSG_BYTES * 2,
  verifyClient: ({ req }) => {
    const origin = req.headers.origin;
    if (!origin) return false;
    try { return new URL(origin).host === req.headers.host; } catch { return false; }
  },
});
const send = (ws, obj) => { if (ws.readyState === 1) ws.send(JSON.stringify(obj)); };
const validPub = (p) => typeof p === 'string' && /^[A-Za-z0-9+/=]{80,200}$/.test(p);
const b64ok = (s, max) => typeof s === 'string' && s.length <= max && /^[A-Za-z0-9+/=]+$/.test(s);

wss.on('connection', (ws, req) => {
  const ip = (process.env.TRUST_PROXY && req.headers['x-forwarded-for']?.split(',')[0].trim()) || req.socket.remoteAddress;
  let me = null;      // logged-in name
  let busy = false;   // one auth attempt at a time
  let tokens = 30;    // simple message rate limit
  const refill = setInterval(() => { tokens = Math.min(30, tokens + 5); }, 1000);
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });

  ws.on('message', async (raw) => {
    if (--tokens < 0) return;
    let m;
    try { m = JSON.parse(raw.toString()); } catch { return; }
    if (!m || typeof m !== 'object') return;

    // ---- register / login ----
    if ((m.t === 'register' || m.t === 'login') && !me && !busy) {
      const name = String(m.name || '').toLowerCase();
      const pin = String(m.pin || '');
      if (!NAME_RE.test(name)) return send(ws, { t: 'err', msg: 'Name: 3–20 Zeichen, nur a–z, 0–9 und _' });
      if (!PIN_RE.test(pin)) return send(ws, { t: 'err', msg: 'Passwort muss genau 6 Ziffern haben.' });
      if (!validPub(m.pub)) return send(ws, { t: 'err', msg: 'Ungültiger Schlüssel.' });
      if (ipBlocked(ip)) return send(ws, { t: 'err', msg: 'Zu viele Fehlversuche. Bitte später erneut.' });
      const id = nameId(name);
      busy = true;
      try {
        if (m.t === 'register') {
          if (users[id]) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
          const salt = crypto.randomBytes(16);
          const hash = await hashPin(pin, salt);
          if (users[id]) return send(ws, { t: 'err', msg: 'Diesen Namen gibt es schon – nimm einen anderen.' });
          users[id] = { s: salt.toString('base64'), h: hash.toString('base64') };
          saveUsers();
        } else {
          const wait = nameLocked(id);
          if (wait) return send(ws, { t: 'err', msg: `Zu viele Fehlversuche. Warte ${wait} Sekunden.` });
          const u = users[id];
          const salt = u ? Buffer.from(u.s, 'base64') : crypto.randomBytes(16);
          const hash = await hashPin(pin, salt); // also runs for unknown names (same timing)
          if (!u || !crypto.timingSafeEqual(hash, Buffer.from(u.h, 'base64'))) {
            registerFail(id, ip);
            return send(ws, { t: 'err', msg: 'Name oder Passwort falsch.' });
          }
          failsByName.delete(id);
        }
        if (ws.readyState !== 1) return;
        const prev = online.get(name);
        if (prev) { send(prev.ws, { t: 'kicked' }); prev.ws.close(); }
        me = name;
        online.set(name, { ws, pub: m.pub });
        send(ws, { t: 'ok', name });
      } finally { busy = false; }
      return;
    }
    if (!me) return;

    // ---- look up a contact's current session key ----
    if (m.t === 'lookup') {
      const name = String(m.name || '').toLowerCase();
      if (!NAME_RE.test(name)) return send(ws, { t: 'lookup', name, exists: false });
      const exists = !!users[nameId(name)];
      const o = online.get(name);
      return send(ws, { t: 'lookup', name, exists, online: !!o, pub: o ? o.pub : null });
    }

    // ---- relay an encrypted message (never stored) ----
    if (m.t === 'msg') {
      const to = String(m.to || '').toLowerCase();
      if (!NAME_RE.test(to) || !b64ok(m.iv, 24) || !b64ok(m.ct, MAX_MSG_BYTES) || typeof m.id !== 'string' || m.id.length > 40) return;
      const o = online.get(to);
      if (!o) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'offline' });
      if (m.pub !== o.pub) return send(ws, { t: 'undelivered', id: m.id, to, reason: 'newkey', pub: o.pub });
      send(o.ws, { t: 'msg', from: me, pub: online.get(me).pub, iv: m.iv, ct: m.ct, id: m.id });
      return send(ws, { t: 'delivered', id: m.id, to });
    }
  });

  ws.on('close', () => {
    clearInterval(refill);
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

server.listen(PORT, () => console.log(`BetterChats läuft auf Port ${PORT}`));
