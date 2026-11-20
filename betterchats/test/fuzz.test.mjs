// Fuzzing: thousands of random, malformed and hostile frames. The server must never crash,
// never answer garbage with anything useful, and keep serving honest users afterwards.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { startServer, Client, register, login, rawHttp, sleep } from './helpers.mjs';

const ROUNDS = Number(process.env.FUZZ_ROUNDS || 60);
const rnd = (n) => crypto.randomInt(n);
const pick = (a) => a[rnd(a.length)];
const TYPES = ['reg1', 'reg2', 'log1', 'log2', 'del1', 'del2', 'lookup', 'msg', 'c', 'hello', 'ok', '__proto__', 'constructor', '', null];
function junkValue(depth = 0) {
  switch (rnd(depth > 2 ? 7 : 10)) {
    case 0: return crypto.randomBytes(rnd(40)).toString('base64');
    case 1: return 'A'.repeat(pick([0, 1, 15, 16, 17, 87, 88, 89, 1451, 1452, 1453, 1579, 1580, 1581, 20000, 70000]));
    case 2: return pick([-1, 0, 1, 1e7, 1e7 + 1, 2 ** 53, -0, NaN, Infinity, 1.5]);
    case 3: return pick([true, false, null]);
    case 4: return pick(["' OR 1=1--", '<script>alert(1)</script>', '../../etc/passwd', '%00', '${7*7}', '{{7*7}}', 'alice', 'ALICE', 'a\u0000b', '😀'.repeat(50)]);
    case 5: return crypto.randomBytes(rnd(300)).toString('latin1');
    case 6: return '';
    case 7: return Array.from({ length: rnd(5) }, () => junkValue(depth + 1));
    default: { const o = {}; for (let i = 0; i < rnd(6); i++) o[pick(['t', 'name', 'req', 'rec', 'fin', 'pub', 'kpub', 'to', 'k', 'n', 'iv', 'ct', 'id', '__proto__', 'constructor'])] = junkValue(depth + 1); return o; }
  }
}
function junkFrame() {
  const o = { t: pick(TYPES) };
  for (let i = 0; i < rnd(9); i++) o[pick(['name', 'req', 'rec', 'fin', 'pub', 'kpub', 'to', 'k', 'n', 'iv', 'ct', 'id', 'pin', 'from', '_'])] = junkValue();
  return o;
}

test(`fuzz: ${ROUNDS} hostile connections x 30 frames`, { timeout: 300_000 }, async () => {
  const s = await startServer({ HOST: '0.0.0.0' });
  try {
    let ip = 10;
    for (let r = 0; r < ROUNDS; r++) {
      const localAddress = `127.0.${Math.floor(ip / 250)}.${(ip++ % 250) + 2}`;
      let c;
      try { c = await Client.connect(s.url, { localAddress }); } catch { continue; }
      for (let i = 0; i < 30 && !c.closed; i++) {
        const mode = rnd(10);
        if (mode < 6) c.send(junkFrame());
        else if (mode < 7) c.sendRaw(crypto.randomBytes(rnd(2000)));
        else if (mode < 8) c.ws.send(crypto.randomBytes(rnd(500)).toString('latin1'));
        else if (mode < 9) c.send(junkValue());
        else { const f = c.encryptFrame(junkFrame()); f[rnd(f.length)] ^= 1 + rnd(255); c.sendRaw(f); }
      }
      await sleep(5);
      c.close();
      if (r % 20 === 0) {
        // raw HTTP fuzz in between
        await rawHttp(s.port, crypto.randomBytes(rnd(3000)).toString('latin1'), 300);
        await rawHttp(s.port, `${pick(['GET', 'G\0T', 'XYZ'])} /${crypto.randomBytes(rnd(50)).toString('hex')} HTTP/${pick(['1.1', '9.9', 'x'])}\r\nHost: ${pick(['x', '', '\0'])}\r\n\r\n`, 300);
      }
    }
    await sleep(300);
    assert.ok(s.alive(), 'server survived the fuzzing');
    // an honest user from a clean IP can still register, log in and chat
    const a = await Client.connect(s.url, { localAddress: '127.0.200.1' });
    assert.equal((await register(a, 'honest', '739204'))?.t, 'ok');
    a.close();
    const b = await Client.connect(s.url, { localAddress: '127.0.200.2' });
    assert.equal((await login(b, 'honest', '739204'))?.t, 'ok');
    b.close();
  } finally { await s.stop(); }
});
