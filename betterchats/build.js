'use strict';
/*
 * Build: client/ (readable source) -> public/ (what the browser gets).
 *   - post-quantum library bundled + minified
 *   - app code obfuscated (renamed identifiers, encrypted string table, scrambled control flow)
 *   - CSS/HTML minified, everything in as few files as possible
 *   - SHA-256 of every shipped file written to BUILD-HASHES.txt so anyone can verify that
 *     the server delivers exactly this build.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const esbuild = require('esbuild');
const Obfuscator = require('javascript-obfuscator');

const SRC = path.join(__dirname, 'client');
const OUT = path.join(__dirname, 'public');
fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT);

const pq = esbuild.buildSync({
  entryPoints: [path.join(SRC, 'lib-entry.js')], bundle: true, minify: true, format: 'iife',
  target: 'es2020', write: false, legalComments: 'none',
}).outputFiles[0].text;

const appSrc = esbuild.buildSync({
  entryPoints: [path.join(SRC, 'app.js')], bundle: true, minify: false, format: 'iife',
  target: 'es2020', write: false, legalComments: 'none',
}).outputFiles[0].text;
const app = Obfuscator.obfuscate(appSrc, {
  compact: true,
  target: 'browser-no-eval',
  controlFlowFlattening: true,
  controlFlowFlatteningThreshold: 0.6,
  deadCodeInjection: true,
  deadCodeInjectionThreshold: 0.25,
  identifierNamesGenerator: 'hexadecimal',
  numbersToExpressions: true,
  simplify: true,
  splitStrings: true,
  splitStringsChunkLength: 5,
  stringArray: true,
  stringArrayEncoding: ['rc4'],
  stringArrayThreshold: 1,
  stringArrayRotate: true,
  stringArrayShuffle: true,
  stringArrayWrappersCount: 3,
  stringArrayWrappersType: 'function',
  transformObjectKeys: true,
  unicodeEscapeSequence: false,
  disableConsoleOutput: true,
  selfDefending: false,
  debugProtection: false, // needs eval, which our CSP forbids (and that's good)
  renameGlobals: false,
  sourceMap: false,
  seed: crypto.randomInt(1, 2 ** 31),
}).getObfuscatedCode();

// Apps get their server baked in (and its OPAQUE key pinned): BETTERCHATS_SERVER=wss://host:30/ BETTERCHATS_SERVER_KEY=…
const cfg = {};
if (process.env.BETTERCHATS_SERVER) cfg.server = process.env.BETTERCHATS_SERVER;
if (process.env.BETTERCHATS_SERVER_KEY) cfg.serverKey = process.env.BETTERCHATS_SERVER_KEY;
const cfgJs = `window.BC_CONFIG=Object.freeze(${JSON.stringify(cfg)});`;
fs.writeFileSync(path.join(OUT, 'app.js'), cfgJs + '\n' + pq + '\n' + app);
fs.writeFileSync(path.join(OUT, 'style.css'), esbuild.transformSync(fs.readFileSync(path.join(SRC, 'style.css'), 'utf8'), { loader: 'css', minify: true }).code);
const html = fs.readFileSync(path.join(SRC, 'index.html'), 'utf8')
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(/\s*<script src="pq\.js" defer><\/script>/, '')
  .replace(/>\s+</g, '><')
  .replace(/\s{2,}/g, ' ')
  .trim();
fs.writeFileSync(path.join(OUT, 'index.html'), html);
fs.copyFileSync(path.join(SRC, 'icon.svg'), path.join(OUT, 'icon.svg'));

const hashes = fs.readdirSync(OUT).sort().map((f) =>
  `${crypto.createHash('sha256').update(fs.readFileSync(path.join(OUT, f))).digest('hex')}  ${f}`);
fs.writeFileSync(path.join(__dirname, 'BUILD-HASHES.txt'), hashes.join('\n') + '\n');
console.log('Build fertig:\n' + hashes.join('\n'));
