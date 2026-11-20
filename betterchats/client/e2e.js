/*
 * BetterChats end-to-end encryption core. Pure WebCrypto + injected ML-KEM; runs in browsers and
 * in Node (>= 19) so it can be tested in isolation (see test/e2e-core.test.mjs).
 *
 * Per contact and direction:
 *   chainKey0 = HKDF-SHA256( ECDH-P256(me, peer) || ML-KEM-768 shared secret,
 *                            info = "BetterChats v4|" || SHA-256("from|fromKeys|to|toKeys") )
 *   messageKey_n = HMAC(chainKey_n, 0x01), chainKey_{n+1} = HMAC(chainKey_n, 0x02)   (keys wiped after use)
 *   ciphertext = AES-256-GCM(messageKey_n, iv, padded JSON, aad = "from|fromKeys|to|toKeys|n|kemCt")
 */
const enc = new TextEncoder();
const dec = new TextDecoder();
export const b64 = (buf) => { let s = ''; for (const x of new Uint8Array(buf)) s += String.fromCharCode(x); return btoa(s); };
export const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
const B64 = /^[A-Za-z0-9+/]+={0,2}$/;

async function hmac(keyBytes, byte) {
  const k = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return new Uint8Array(await crypto.subtle.sign('HMAC', k, new Uint8Array([byte])));
}
// one ratchet step: message key + next chain key; the old chain key is wiped
async function step(ck) {
  const mk = await hmac(ck, 1);
  const next = await hmac(ck, 2);
  ck.fill(0);
  return [mk, next];
}
const aesKey = (mk, use) => crypto.subtle.importKey('raw', mk, 'AES-GCM', false, [use]);
const aad = (from, fromKey, to, toKey, n, k) => enc.encode(`${from}|${fromKey}|${to}|${toKey}|${n}|${k}`);
const serial = (ch, fn) => { const r = ch.q.then(fn); ch.q = r.catch(() => {}); return r; };

export async function safetyNumber(keyA, keyB) {
  const [x, y] = [keyA, keyB].sort();
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(x + '|' + y)));
  let out = '';
  for (let i = 0; i < 12; i++) out += String(((h[i * 2] << 8) | h[i * 2 + 1]) % 100000).padStart(5, '0') + ' ';
  return out.trim();
}

/**
 * @param {object} o
 * @param {string} o.me            own name
 * @param {string} o.myPub         "<ecdh b64>.<ml-kem b64>"
 * @param {CryptoKey} o.ecdhPrivateKey
 * @param {Uint8Array} o.kemSecretKey
 * @param {{encapsulate:Function, decapsulate:Function}} o.pq
 */
export function createE2E({ me, myPub, ecdhPrivateKey, kemSecretKey, pq, maxSkip = 500, maxRecvChains = 200 }) {
  const sendChains = new Map();
  const recvChains = new Map();
  const ecdhCache = new Map();

  const parts = (peerKey) => {
    const p = typeof peerKey === 'string' ? peerKey.split('.') : [];
    if (p.length !== 2 || !B64.test(p[0]) || !B64.test(p[1])) throw new Error('bad key');
    return p;
  };
  function ecdh(peerKey) {
    const [ec] = parts(peerKey);
    if (!ecdhCache.has(ec)) {
      ecdhCache.set(ec, (async () => {
        const pk = await crypto.subtle.importKey('raw', unb64(ec), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
        return new Uint8Array(await crypto.subtle.deriveBits({ name: 'ECDH', public: pk }, ecdhPrivateKey, 256));
      })());
      ecdhCache.get(ec).catch(() => ecdhCache.delete(ec));
    }
    return ecdhCache.get(ec);
  }
  async function hybridChain(ecSecret, pqSecret, from, fromKey, to, toKey) {
    const ikm = new Uint8Array(64);
    ikm.set(ecSecret, 0); ikm.set(pqSecret, 32);
    const hk = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
    ikm.fill(0); pqSecret.fill(0);
    // HKDF info is limited to 1024 bytes in some implementations -> bind a hash of the full transcript
    const transcript = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(`${from}|${fromKey}|${to}|${toKey}`)));
    const info = new Uint8Array([...enc.encode('BetterChats v4|'), ...transcript]);
    return new Uint8Array(await crypto.subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info }, hk, 256));
  }
  function sendChain(peer, peerKey) {
    const id = peer + '|' + peerKey;
    if (!sendChains.has(id)) {
      const p = (async () => {
        const { cipherText, sharedSecret } = pq.encapsulate(unb64(parts(peerKey)[1]));
        const ck = await hybridChain(await ecdh(peerKey), sharedSecret, me, myPub, peer, peerKey);
        return { ck, n: 0, k: b64(cipherText), q: Promise.resolve() };
      })();
      sendChains.set(id, p);
      p.catch(() => sendChains.delete(id));
    }
    return sendChains.get(id);
  }
  function recvChain(peer, peerKey, k) {
    const id = peer + '|' + peerKey + '|' + k;
    if (!recvChains.has(id)) {
      if (recvChains.size >= maxRecvChains) { // bounded: a malicious server can't make us grow forever
        for (const key of [...recvChains.keys()].slice(0, Math.ceil(maxRecvChains / 4))) recvChains.delete(key);
      }
      const p = (async () => {
        if (!B64.test(k)) throw new Error('bad kem ct');
        const ss = pq.decapsulate(unb64(k), kemSecretKey);
        const ck = await hybridChain(await ecdh(peerKey), ss, peer, peerKey, me, myPub);
        return { ck, n: 0, skipped: new Map(), q: Promise.resolve() };
      })();
      recvChains.set(id, p);
      p.catch(() => recvChains.delete(id));
    }
    return recvChains.get(id);
  }

  async function encryptFor(peer, peerKey, payload) {
    const s = await sendChain(peer, peerKey);
    return serial(s, async () => {
      const n = s.n;
      const [mk, next] = await step(s.ck);
      s.ck = next; s.n++;
      let pt = JSON.stringify(payload);
      pt += ' '.repeat(256 - (enc.encode(pt).length % 256)); // hide length
      const iv = crypto.getRandomValues(new Uint8Array(12));
      const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: aad(me, myPub, peer, peerKey, n, s.k) }, await aesKey(mk, 'encrypt'), enc.encode(pt));
      mk.fill(0);
      return { n, k: s.k, iv: b64(iv), ct: b64(ct) };
    });
  }

  async function decryptFrom(peer, peerKey, k, n, iv, ct) {
    if (!Number.isSafeInteger(n) || n < 0) throw new Error('bad counter');
    if (typeof iv !== 'string' || typeof ct !== 'string' || !B64.test(iv) || !B64.test(ct)) throw new Error('bad input');
    const r = await recvChain(peer, peerKey, k);
    return serial(r, async () => {
      // work on copies; only commit if the message really decrypts (forged counters can't break the chain)
      let ck = r.ck.slice(), cn = r.n, mk;
      const newSkipped = [];
      if (n < cn) {
        mk = r.skipped.get(n);
        if (!mk) throw new Error('replay');
      } else {
        if (n - cn > maxSkip) throw new Error('gap');
        while (cn < n) { const [x, nx] = await step(ck); newSkipped.push([cn, x]); ck = nx; cn++; }
        const [x, nx] = await step(ck); mk = x; ck = nx; cn++;
      }
      let pt;
      try {
        pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(iv), additionalData: aad(peer, peerKey, me, myPub, n, k) }, await aesKey(mk, 'decrypt'), unb64(ct));
      } catch (e) {
        if (n >= r.n) { mk.fill(0); ck.fill(0); for (const [, x] of newSkipped) x.fill(0); }
        throw new Error('auth');
      }
      if (n < r.n) r.skipped.delete(n);
      else { r.ck.fill(0); r.ck = ck; r.n = cn; for (const [i, x] of newSkipped) r.skipped.set(i, x); }
      while (r.skipped.size > maxSkip) { const [i, x] = r.skipped.entries().next().value; x.fill(0); r.skipped.delete(i); }
      mk.fill(0);
      const o = JSON.parse(dec.decode(pt));
      if (!o || typeof o.m !== 'string') throw new Error('bad payload');
      return o;
    });
  }

  function forgetPeer(peer, peerKey) {
    for (const m of [sendChains, recvChains]) for (const k of [...m.keys()]) if (k.startsWith(peer + '|' + peerKey)) m.delete(k);
  }
  function wipe() { sendChains.clear(); recvChains.clear(); ecdhCache.clear(); }

  return { encryptFor, decryptFrom, forgetPeer, wipe };
}
