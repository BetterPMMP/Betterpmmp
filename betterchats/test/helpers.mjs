// Test helpers: start a throwaway server, speak the BetterChats protocol from Node (incl. tunnel
// and OPAQUE) – and misbehave on purpose.
import { spawn } from 'node:child_process';
import net from 'node:net';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import WebSocket from 'ws';
import * as opaque from '@serenity-kit/opaque';
import { ml_kem768 } from '@noble/post-quantum/ml-kem.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
export const BUCKETS = [4096, 8192, 16384, 32768];

export function freePort() {
  return new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
}

export async function startServer(extraEnv = {}) {
  const port = await freePort();
  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bc-test-'));
  const proc = spawn(process.execPath, [path.join(ROOT, 'server.js')], {
    env: { ...process.env, PORT: String(port), HOST: '127.0.0.1', DATA_DIR: dataDir, COVER_TRAFFIC: '0', ...extraEnv },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let out = '';
  await new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('server did not start: ' + out)), 10_000);
    proc.stdout.on('data', (d) => { out += d; if (out.includes('läuft')) { clearTimeout(t); resolve(); } });
    proc.stderr.on('data', (d) => { out += d; });
    proc.on('exit', (c) => reject(new Error('server exited ' + c + ': ' + out)));
  });
  const serverKey = out.match(/Server-Schlüssel: (\S+)/)?.[1];
  return {
    port, dataDir, serverKey, proc,
    url: `ws://127.0.0.1:${port}/`,
    alive: () => proc.exitCode === null,
    async stop() { proc.kill('SIGKILL'); await new Promise((r) => (proc.exitCode !== null ? r() : proc.once('exit', r))); fs.rmSync(dataDir, { recursive: true, force: true }); },
  };
}

// raw HTTP request; resolves { status, headers, body } or { dropped: true } when the server says nothing
export function rawHttp(port, payload, timeout = 2000) {
  return new Promise((resolve) => {
    const sock = net.connect(port, '127.0.0.1');
    let buf = Buffer.alloc(0);
    const done = () => {
      const s = buf.toString('latin1');
      if (!s) return resolve({ dropped: true });
      const status = Number(s.split(' ')[1]);
      const [head, ...rest] = s.split('\r\n\r\n');
      resolve({ status, head, body: rest.join('\r\n\r\n') });
    };
    sock.setTimeout(timeout, () => { sock.destroy(); done(); });
    sock.on('data', (d) => { buf = Buffer.concat([buf, d]); });
    sock.on('close', done);
    sock.on('error', () => {});
    sock.write(payload);
  });
}
export const get = (port, p = '/', host = '127.0.0.1') => rawHttp(port, `GET ${p} HTTP/1.1\r\nHost: ${host}\r\nConnection: close\r\n\r\n`);

const nonce = (n) => { const b = Buffer.alloc(12); b.writeBigUInt64BE(n, 4); return b; };

/** A protocol client. `raw` access lets tests send whatever they want. */
export class Client {
  static async connect(url, { origin, localAddress } = {}) {
    const u = new URL(url);
    const ws = new WebSocket(url, { headers: { Origin: origin ?? `http://${u.host}` }, localAddress });
    const c = new Client(ws);
    await c.handshake();
    return c;
  }
  constructor(ws) {
    this.ws = ws; this.inbox = []; this.waiters = []; this.closed = false; this.sentFrames = [];
    ws.on('close', () => { this.closed = true; this.waiters.forEach((w) => w.resolve(null)); });
    ws.on('error', () => {});
  }
  handshake() {
    return new Promise((resolve, reject) => {
      this.ws.once('unexpected-response', (_, res) => reject(new Error('rejected ' + res.statusCode)));
      this.ws.once('error', reject);
      this.ws.once('close', () => reject(new Error('closed during handshake')));
      this.ws.once('message', (data) => {
        const h = JSON.parse(data.toString());
        const ecdh = crypto.createECDH('prime256v1');
        const cpub = ecdh.generateKeys();
        const okm = Buffer.from(crypto.hkdfSync('sha256', ecdh.computeSecret(Buffer.from(h.spub, 'base64')), Buffer.alloc(32), 'BetterChats tunnel v1', 64));
        this.t = { c2s: okm.subarray(0, 32), s2c: okm.subarray(32), outN: 0n, inN: 0n };
        this.ws.send(JSON.stringify({ t: 'hello', cpub: cpub.toString('base64') }));
        this.ws.on('message', (d, bin) => this.onFrame(d, bin));
        setTimeout(resolve, 30);
      });
    });
  }
  onFrame(d, bin) {
    if (!bin) return;
    const iv = nonce(this.t.inN++);
    const dc = crypto.createDecipheriv('aes-256-gcm', this.t.s2c, iv);
    dc.setAuthTag(d.subarray(d.length - 16));
    const pt = Buffer.concat([dc.update(d.subarray(0, d.length - 16)), dc.final()]);
    this.lastFrameSize = pt.length;
    const m = JSON.parse(pt.toString());
    if (m.t === 'c') return;
    const i = this.waiters.findIndex((w) => w.types.includes(m.t));
    if (i >= 0) return this.waiters.splice(i, 1)[0].resolve(m);
    this.inbox.push(m);
  }
  encryptFrame(obj) {
    const c = crypto.createCipheriv('aes-256-gcm', this.t.c2s, nonce(this.t.outN++));
    const pt = Buffer.from(JSON.stringify(obj));
    return Buffer.concat([c.update(pt), c.final(), c.getAuthTag()]);
  }
  send(obj) { const f = this.encryptFrame(obj); this.sentFrames.push(f); this.ws.send(f); }
  sendRaw(buf) { this.ws.send(buf); }
  next(types, timeout = 3000) {
    const i = this.inbox.findIndex((m) => types.includes(m.t));
    if (i >= 0) return Promise.resolve(this.inbox.splice(i, 1)[0]);
    return new Promise((resolve) => {
      const w = { types, resolve: (m) => { clearTimeout(timer); resolve(m); } };
      const timer = setTimeout(() => { this.waiters.splice(this.waiters.indexOf(w), 1); resolve(null); }, timeout);
      this.waiters.push(w);
    });
  }
  request(obj, types, timeout) { this.send(obj); return this.next(types, timeout); }
  waitClosed(timeout = 3000) {
    if (this.closed) return Promise.resolve(true);
    return new Promise((r) => { const t = setTimeout(() => r(false), timeout); this.ws.once('close', () => { clearTimeout(t); r(true); }); });
  }
  close() { this.ws.terminate(); }
}

export function sessionKeys() {
  const ecdh = crypto.createECDH('prime256v1');
  const pub = ecdh.generateKeys().toString('base64');
  const kem = ml_kem768.keygen();
  return { pub, kpub: Buffer.from(kem.publicKey).toString('base64'), kem };
}

export async function register(c, name, password, keys = sessionKeys()) {
  await opaque.ready;
  const { clientRegistrationState, registrationRequest } = opaque.client.startRegistration({ password });
  const r1 = await c.request({ t: 'reg1', name, req: registrationRequest }, ['reg1', 'err']);
  if (!r1 || r1.t === 'err') return r1;
  const { registrationRecord } = opaque.client.finishRegistration({ clientRegistrationState, registrationResponse: r1.res, password });
  return c.request({ t: 'reg2', rec: registrationRecord, pub: keys.pub, kpub: keys.kpub }, ['ok', 'err']);
}

export async function login(c, name, password, keys = sessionKeys()) {
  await opaque.ready;
  const { clientLoginState, startLoginRequest } = opaque.client.startLogin({ password });
  const r1 = await c.request({ t: 'log1', name, req: startLoginRequest }, ['log1', 'err']);
  if (!r1 || r1.t === 'err') return r1;
  const res = opaque.client.finishLogin({ clientLoginState, loginResponse: r1.res, password });
  if (!res) return { t: 'wrong-password', loginResponse: r1.res };
  return c.request({ t: 'log2', fin: res.finishLoginRequest, pub: keys.pub, kpub: keys.kpub }, ['ok', 'err']);
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
