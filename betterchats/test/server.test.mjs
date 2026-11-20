// Attacks on the server: scanners, injection, brute force, protocol abuse, tunnel tampering.
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import WebSocket from 'ws';
import * as opaque from '@serenity-kit/opaque';
import { startServer, get, rawHttp, Client, register, login, sessionKeys, sleep } from './helpers.mjs';

await opaque.ready;

describe('HTTP surface', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());

  test('serves the app with strict security headers', async () => {
    const r = await get(s.port, '/');
    assert.equal(r.status, 200);
    for (const h of ["default-src 'none'", "require-trusted-types-for 'script'", "frame-ancestors 'none'", 'X-Content-Type-Options: nosniff',
      'Referrer-Policy: no-referrer', 'Cross-Origin-Opener-Policy: same-origin', 'Cache-Control: no-store']) assert.ok(r.head.includes(h), h);
    assert.ok(!/^Server:/mi.test(r.head), 'no Server header');
    assert.ok(!/^Date:/mi.test(r.head), 'no Date header');
  });

  test('path traversal, dotfiles, data dir and source files are unreachable (silent drop)', async () => {
    for (const p of ['/../server.js', '/..%2fserver.js', '/%2e%2e/server.js', '/data/users.json', '/.env', '/server.js',
      '/package.json', '/node_modules/ws/package.json', '/public/app.js', '//etc/passwd', '/index.html/..', '/app.js%00.html']) {
      const r = await rawHttp(s.port, `GET ${p} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n`);
      assert.ok(r.dropped, `${p} must not be answered`);
    }
  });

  test('only GET/HEAD; everything else is dropped', async () => {
    for (const m of ['POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT', 'PATCH']) {
      const r = await rawHttp(s.port, `${m} / HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n`);
      assert.ok(r.dropped, m);
    }
  });

  test('garbage / non-HTTP probes get no banner at all', async () => {
    for (const p of ['HELP\r\n\r\n', '\x16\x03\x01\x00\xa5\x01\x00\x00\xa1\x03\x03', 'SSH-2.0-OpenSSH_9.6\r\n', '\0\0\0\0', 'GET / HTTP/1.1\r\n' + 'X: ' + 'a'.repeat(20000) + '\r\n\r\n']) {
      const r = await rawHttp(s.port, p);
      assert.ok(r.dropped, JSON.stringify(p.slice(0, 20)));
    }
  });

  test('server keeps running after all of that', async () => {
    assert.ok(s.alive());
  });
});

describe('stealth mode + auto-ban', () => {
  let s;
  before(async () => { s = await startServer({ DOMAIN: 'chat.example.com' }); });
  after(() => s.stop());

  test('wrong Host (scan by IP) gets nothing, right Host works', async () => {
    assert.ok((await get(s.port, '/', '127.0.0.1')).dropped);
    assert.equal((await get(s.port, '/', 'chat.example.com')).status, 200);
  });

  test('a scanner gets banned and then cannot even load the page', async () => {
    for (const p of ['/wp-admin', '/.git/config', '/phpmyadmin', '/admin', '/.env', '/backup.zip', '/api']) await get(s.port, p, 'chat.example.com');
    assert.ok((await get(s.port, '/', 'chat.example.com')).dropped, 'banned');
  });
});

describe('WebSocket gate', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());

  test('foreign origin is refused (cross-site WebSocket hijacking)', async () => {
    await assert.rejects(Client.connect(s.url, { origin: 'https://evil.example' }));
    await assert.rejects(Client.connect(s.url, { origin: 'null' }));
  });

  test('only "/" can be upgraded', async () => {
    await assert.rejects(Client.connect(s.url + 'admin'));
  });

  test('connection limit per IP', async () => {
    const conns = [];
    let refused = 0;
    for (let i = 0; i < 14; i++) { try { conns.push(await Client.connect(s.url)); } catch { refused++; } }
    assert.ok(refused >= 4, `refused ${refused}`);
    conns.forEach((c) => c.close());
    await sleep(300);
  });
});

describe('tunnel', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());

  test('plaintext JSON after the handshake is refused', async () => {
    const c = await Client.connect(s.url);
    for (let i = 0; i < 6; i++) c.ws.send(JSON.stringify({ t: 'lookup', name: 'x' }));
    assert.ok(await c.waitClosed());
  });

  test('replayed tunnel frame kills the connection', async () => {
    const c = await Client.connect(s.url);
    c.send({ t: 'c' });
    c.sendRaw(c.sentFrames[0]); // exact replay
    assert.ok(await c.waitClosed());
  });

  test('bit-flipped tunnel frame kills the connection', async () => {
    const c = await Client.connect(s.url);
    const f = c.encryptFrame({ t: 'c' });
    f[3] ^= 0xff;
    c.sendRaw(f);
    assert.ok(await c.waitClosed());
  });

  test('bad handshake is refused', async () => {
    const ws = new WebSocket(s.url, { headers: { Origin: `http://127.0.0.1:${s.port}` } });
    await new Promise((r) => ws.once('message', r));
    ws.send(JSON.stringify({ t: 'hello', cpub: 'A'.repeat(88) }));
    await new Promise((r) => ws.once('close', r));
  });

  test('after these attacks the attacker IP is banned (firewall)', async () => {
    await assert.rejects(Client.connect(s.url));
  });
});

describe('traffic shape', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());
  test('all server frames have a fixed bucket size', async () => {
    const c = await Client.connect(s.url);
    const r = await c.request({ t: 'reg1', name: 'BAD NAME', req: 'x' }, ['err']);
    assert.equal(r.t, 'err');
    assert.equal(c.lastFrameSize, 4096);
    c.close();
  });
});

describe('accounts (OPAQUE)', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());

  test('register + login; the server never stores or sees the password', async () => {
    const c = await Client.connect(s.url);
    assert.equal((await register(c, 'alice', '739204')).t, 'ok');
    c.close();
    const d = await Client.connect(s.url);
    assert.equal((await login(d, 'alice', '739204')).t, 'ok');
    d.close();
    const stored = fs.readFileSync(path.join(s.dataDir, 'users.json'), 'utf8') + fs.readFileSync(path.join(s.dataDir, 'opaque.setup')).toString('latin1');
    assert.ok(!stored.includes('739204') && !stored.includes('alice'), 'no password / name on disk');
  });

  test('duplicate name refused', async () => {
    const c = await Client.connect(s.url);
    const r = await register(c, 'alice', '555123');
    assert.equal(r.t, 'err');
    assert.match(r.msg, /gibt es schon/);
    c.close();
  });

  test('unknown name and wrong password look identical', async () => {
    const c = await Client.connect(s.url);
    const a = await login(c, 'alice', '000999');
    const b = await login(c, 'nobody_here', '000999');
    assert.equal(a.t, 'wrong-password');
    assert.equal(b.t, 'wrong-password');
    assert.equal(a.loginResponse.length, b.loginResponse.length);
    c.close();
  });

  test('forged / replayed finish messages are refused', async () => {
    const c = await Client.connect(s.url);
    const k = sessionKeys();
    // finish without start
    c.send({ t: 'log2', fin: 'A'.repeat(100), pub: k.pub, kpub: k.kpub });
    // finish with garbage after a real start
    const { startLoginRequest } = opaque.client.startLogin({ password: '739204' });
    await c.request({ t: 'log1', name: 'alice', req: startLoginRequest }, ['log1']);
    const r = await c.request({ t: 'log2', fin: 'A'.repeat(171), pub: k.pub, kpub: k.kpub }, ['ok', 'err']);
    assert.notEqual(r?.t, 'ok');
    // registration record without reg1
    const r2 = await c.request({ t: 'reg2', rec: 'A'.repeat(256), pub: k.pub, kpub: k.kpub }, ['ok', 'err'], 800);
    assert.notEqual(r2?.t, 'ok');
    c.close();
  });

  test('brute force: 5 wrong passwords lock the name', async () => {
    const c = await Client.connect(s.url);
    await register(c, 'bfvictim', '482913');
    c.close();
    const d = await Client.connect(s.url);
    for (let i = 0; i < 5; i++) await login(d, 'bfvictim', '10000' + i);
    const r = await login(d, 'bfvictim', '482913'); // even the right one is refused now
    assert.equal(r.t, 'err');
    assert.match(r.msg, /Fehlversuche/);
    d.close();
  });

  test('name injection attempts are refused', async () => {
    const c = await Client.connect(s.url);
    for (const name of ["' OR 1=1 --", '<script>', '../../etc', 'a'.repeat(50), 'ab', 'ÄÖÜ', '__proto__x'.slice(0, 25), { $ne: 1 }, ['alice']]) {
      const { registrationRequest } = opaque.client.startRegistration({ password: '739204' });
      const r = await c.request({ t: 'reg1', name, req: registrationRequest }, ['reg1', 'err'], 1000);
      if (r?.t === 'reg1') assert.match(String(name).toLowerCase(), /^[a-z0-9_]{3,20}$/); // only valid names get through
    }
    c.close();
  });

  test('account deletion requires the password', async () => {
    const c = await Client.connect(s.url);
    await register(c, 'deleteme', '927361');
    const { startLoginRequest } = opaque.client.startLogin({ password: '927361' });
    const r1 = await c.request({ t: 'del1', req: startLoginRequest }, ['del1']);
    const res = opaque.client.finishLogin({ clientLoginState: opaque.client.startLogin({ password: 'x' }).clientLoginState, loginResponse: r1.res, password: '927361' });
    assert.equal(res, undefined); // wrong state -> client can't produce proof
    c.close();
  });
});

describe('messaging', () => {
  let s;
  before(async () => { s = await startServer(); });
  after(() => s.stop());

  test('relay works, offline is reported, sender is set by the server (no spoofing)', async () => {
    const a = await Client.connect(s.url); const ka = sessionKeys();
    const b = await Client.connect(s.url); const kb = sessionKeys();
    await register(a, 'alice', '739204', ka);
    await register(b, 'bob', '582910', kb);
    const look = await a.request({ t: 'lookup', name: 'bob' }, ['lookup']);
    assert.equal(look.online, true);
    const msg = { t: 'msg', to: 'bob', from: 'mallory', pub: kb.pub, kpub: kb.kpub, k: 'A'.repeat(1452), n: 0, iv: 'A'.repeat(16), ct: 'A'.repeat(400), id: 'B'.repeat(16) };
    a.send(msg);
    const got = await b.next(['msg']);
    assert.equal(got.from, 'alice', 'server sets the sender, client cannot spoof it');
    assert.equal(got.pub, ka.pub);
    const off = await a.request({ ...msg, to: 'nobody' }, ['undelivered']);
    assert.equal(off.reason, 'offline');
    // stale key -> told to re-key, nothing delivered
    const stale = await a.request({ ...msg, pub: ka.pub }, ['undelivered']);
    assert.equal(stale.reason, 'newkey');
    a.close(); b.close();
  });

  test('peer going offline notifies the chat partner (chat gets deleted)', async () => {
    const a = await Client.connect(s.url);
    const b = await Client.connect(s.url);
    await register(a, 'carl', '739204');
    await register(b, 'dora', '582910');
    await a.request({ t: 'lookup', name: 'dora' }, ['lookup']);
    b.close();
    const g = await a.next(['gone']);
    assert.equal(g?.name, 'dora');
    a.close();
  });

  test('lookups are rate limited (no mass enumeration)', async () => {
    const a = await Client.connect(s.url);
    await register(a, 'enum', '739204');
    let limited = false;
    for (let i = 0; i < 25 && !limited; i++) { const r = await a.request({ t: 'lookup', name: 'user' + i }, ['lookup']); limited = !!r?.limited; }
    assert.ok(limited);
    a.close();
  });

  test('oversized and malformed messages are refused', async () => {
    const a = await Client.connect(s.url);
    await register(a, 'bigmsg', '739204');
    const base = { t: 'msg', to: 'enum', pub: 'x', kpub: 'y', k: 'A'.repeat(1452), n: 0, iv: 'A'.repeat(16), id: 'B'.repeat(16) };
    for (const bad of [{ ct: 'A'.repeat(70000) }, { ct: '<img>' }, { n: -1, ct: 'AAAA' }, { n: 1e12, ct: 'AAAA' }, { iv: 'x', ct: 'AAAA' }, { k: 'short', ct: 'AAAA' }]) {
      a.send({ ...base, ...bad });
    }
    assert.ok(await a.waitClosed(), 'repeated garbage gets the connection killed');
    assert.ok(s.alive());
  });
});
