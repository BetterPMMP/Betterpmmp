// Third-party crypto, bundled locally (no CDN): ML-KEM-768 (post-quantum) and OPAQUE (RFC 9807).
import { ml_kem768 } from '@noble/post-quantum/ml-kem.js';
import * as opaque from '@serenity-kit/opaque';

globalThis.BCPQ = Object.freeze({
  keygen: () => ml_kem768.keygen(),
  encapsulate: (pk) => ml_kem768.encapsulate(pk),
  decapsulate: (ct, sk) => ml_kem768.decapsulate(ct, sk),
});
globalThis.BCOPAQUE_READY = opaque.ready.then(() => { globalThis.BCOPAQUE = opaque; });
