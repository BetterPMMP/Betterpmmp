'use strict';
// Builds the chat client for the apps and copies it into the desktop + mobile projects.
//   BETTERCHATS_SERVER=wss://chat.example.com:30/ [BETTERCHATS_SERVER_KEY=<OPAQUE server key>] node apps/prepare.js
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const server = process.env.BETTERCHATS_SERVER;
if (!server) { console.error('BETTERCHATS_SERVER fehlt, z. B. wss://chat.example.com:30/'); process.exit(1); }
const u = new URL(server);
if (u.protocol !== 'wss:' && !(u.protocol === 'ws:' && ['127.0.0.1', 'localhost'].includes(u.hostname))) {
  console.error('Apps verbinden sich nur über wss:// (TLS). ws:// ist nur für localhost-Tests erlaubt.'); process.exit(1);
}
if (!process.env.BETTERCHATS_SERVER_KEY) console.warn('HINWEIS: BETTERCHATS_SERVER_KEY nicht gesetzt – Server-Identität wird nicht fest geprüft (Pinning aus).');

execFileSync(process.execPath, [path.join(__dirname, '..', 'build.js')], { stdio: 'inherit', env: { ...process.env, APP_BUILD: '1' } });
const www = path.join(__dirname, 'www');
for (const target of ['desktop/www', 'mobile/www']) {
  const dst = path.join(__dirname, target);
  if (!fs.existsSync(path.dirname(dst))) continue;
  fs.rmSync(dst, { recursive: true, force: true });
  fs.cpSync(www, dst, { recursive: true });
}
fs.writeFileSync(path.join(__dirname, 'desktop', 'server.txt'), server + '\n');
console.log('App-Client gebaut für', server);
