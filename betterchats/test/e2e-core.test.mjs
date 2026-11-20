// Attacks on the end-to-end encryption itself, as a malicious server or network would try them.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ml_kem768 } from '@noble/post-quantum/ml-kem.js';
import { createE2E, safetyNumber, b64 } from '../client/e2e.js';

const pq = { encapsulate: (pk) => ml_kem768.encapsulate(pk), decapsulate: (ct, sk) => ml_kem768.decapsulate(ct, sk) };

async function party(name) {
  const kp = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
  const kem = ml_kem768.keygen();
  const myPub = b64(await crypto.subtle.exportKey('raw', kp.publicKey)) + '.' + b64(kem.publicKey);
  return { name, myPub, e2e: createE2E({ me: name, myPub, ecdhPrivateKey: kp.privateKey, kemSecretKey: kem.secretKey, pq }) };
}
async function pair() { return [await party('alice'), await party('bob')]; }
const flip = (s, i = 5) => { const b = Buffer.from(s, 'base64'); b[i % b.length] ^= 1; return b.toString('base64'); };

test('roundtrip in both directions', async () => {
  const [a, b] = await pair();
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'hallo' });
  assert.equal((await b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct)).m, 'hallo');
  const r = await b.e2e.encryptFor('alice', a.myPub, { m: 'zurück' });
  assert.equal((await a.e2e.decryptFrom('bob', b.myPub, r.k, r.n, r.iv, r.ct)).m, 'zurück');
});

test('replay of an already delivered message is rejected', async () => {
  const [a, b] = await pair();
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'einmal' });
  await b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct);
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct));
});

test('out-of-order delivery works, each message exactly once', async () => {
  const [a, b] = await pair();
  const ms = [];
  for (let i = 0; i < 5; i++) ms.push(await a.e2e.encryptFor('bob', b.myPub, { m: 'm' + i }));
  for (const i of [3, 0, 4, 1, 2]) assert.equal((await b.e2e.decryptFrom('alice', a.myPub, ms[i].k, ms[i].n, ms[i].iv, ms[i].ct)).m, 'm' + i);
  for (const m of ms) await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct));
});

test('tampered ciphertext, iv, counter or KEM ciphertext is rejected and does not break the chain', async () => {
  const [a, b] = await pair();
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'echt' });
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, flip(m.ct)));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, flip(m.iv), m.ct));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, m.n + 1, m.iv, m.ct));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, flip(m.k, 100), m.n, m.iv, m.ct));
  // the genuine message still decrypts afterwards
  assert.equal((await b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct)).m, 'echt');
});

test('server cannot re-attribute a message to another sender', async () => {
  const [a, b] = await pair();
  const c = await party('carl');
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'von alice' });
  await assert.rejects(b.e2e.decryptFrom('carl', c.myPub, m.k, m.n, m.iv, m.ct));
  await assert.rejects(b.e2e.decryptFrom('carl', a.myPub, m.k, m.n, m.iv, m.ct)); // right keys, wrong name
});

test('message for bob cannot be read by carl (even with all public data)', async () => {
  const [a, b] = await pair();
  const c = await party('carl');
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'nur für bob' });
  await assert.rejects(c.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct));
});

test('huge counter gap (forged) is refused without hanging', async () => {
  const [a, b] = await pair();
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'x' });
  const t = Date.now();
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, 10_000_000, m.iv, m.ct));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, -1, m.iv, m.ct));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, m.k, 1.5, m.iv, m.ct));
  assert.ok(Date.now() - t < 2000);
});

test('garbage keys / inputs are rejected, never crash', async () => {
  const [a, b] = await pair();
  for (const bad of ['', 'x', 'a.b.c', 'äöü.ß', null, 42, '....']) {
    await assert.rejects(b.e2e.decryptFrom('alice', bad, 'AAAA', 0, 'AAAAAAAAAAAAAAAA', 'AAAA'));
  }
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, '!!!', 0, 'AAAAAAAAAAAAAAAA', 'AAAA'));
  await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, 'AAAA', 0, '<script>', 'AAAA'));
});

test('ciphertext length does not reveal message length (256-byte padding)', async () => {
  const [a, b] = await pair();
  const short = await a.e2e.encryptFor('bob', b.myPub, { m: 'a' });
  const longer = await a.e2e.encryptFor('bob', b.myPub, { m: 'a'.repeat(200) });
  assert.equal(short.ct.length, longer.ct.length);
});

test('each message uses a fresh key: identical plaintexts give different ciphertexts', async () => {
  const [a, b] = await pair();
  const x = await a.e2e.encryptFor('bob', b.myPub, { m: 'gleich' });
  const y = await a.e2e.encryptFor('bob', b.myPub, { m: 'gleich' });
  assert.notEqual(x.ct, y.ct);
  assert.equal(y.n, x.n + 1);
});

test('safety number is symmetric and changes with keys', async () => {
  const [a, b] = await pair();
  const c = await party('carl');
  assert.equal(await safetyNumber(a.myPub, b.myPub), await safetyNumber(b.myPub, a.myPub));
  assert.notEqual(await safetyNumber(a.myPub, b.myPub), await safetyNumber(a.myPub, c.myPub));
  assert.match(await safetyNumber(a.myPub, b.myPub), /^(\d{5} ){11}\d{5}$/);
});

test('flooding with many fake KEM ciphertexts stays bounded', async () => {
  const [a, b] = await pair();
  for (let i = 0; i < 260; i++) {
    const fake = Buffer.from(ml_kem768.encapsulate(ml_kem768.keygen().publicKey).cipherText).toString('base64');
    await assert.rejects(b.e2e.decryptFrom('alice', a.myPub, fake, 0, 'AAAAAAAAAAAAAAAA', 'AAAAAAAAAAAAAAAAAAAAAAAA'));
  }
  const m = await a.e2e.encryptFor('bob', b.myPub, { m: 'noch da' });
  assert.equal((await b.e2e.decryptFrom('alice', a.myPub, m.k, m.n, m.iv, m.ct)).m, 'noch da');
});
