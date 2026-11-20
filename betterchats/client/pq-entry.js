import { ml_kem768 } from '@noble/post-quantum/ml-kem.js';
globalThis.BCPQ = Object.freeze({
  keygen: () => ml_kem768.keygen(),
  encapsulate: (pk) => ml_kem768.encapsulate(pk),
  decapsulate: (ct, sk) => ml_kem768.decapsulate(ct, sk),
});
