'use strict';
// Points the apps at your BetterChats website. The apps simply show that site in a hardened window.
//   BETTERCHATS_URL=https://147-189-169-3.sslip.io:30 node apps/prepare.js
const fs = require('fs');
const path = require('path');

const url = new URL(process.env.BETTERCHATS_URL || 'https://147-189-169-3.sslip.io:30');
if (url.protocol !== 'https:') { console.error('Die Apps laden BetterChats nur über https://'); process.exit(1); }
const site = url.origin;

// desktop
fs.writeFileSync(path.join(__dirname, 'desktop', 'site.txt'), site + '\n');

// mobile: Capacitor loads the site directly; www/ only holds the offline page
const cfgPath = path.join(__dirname, 'mobile', 'capacitor.config.json');
const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
cfg.server = { ...cfg.server, url: site, cleartext: false, allowNavigation: [url.hostname], errorPath: 'offline.html' };
fs.writeFileSync(cfgPath, JSON.stringify(cfg, null, 2) + '\n');
const www = path.join(__dirname, 'mobile', 'www');
fs.rmSync(www, { recursive: true, force: true });
fs.mkdirSync(www);
const page = `<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BetterChats</title></head><body style="font:16px system-ui;background:#0f1115;color:#e8eaf0;display:grid;place-items:center;height:100vh;margin:0;text-align:center">
<div><h2>Keine Verbindung zu BetterChats</h2><p>Prüfe deine Internetverbindung.</p>
<p><a style="color:#5b78ff" href="${site}/">Erneut versuchen</a></p></div></body></html>`;
fs.writeFileSync(path.join(www, 'index.html'), page);
fs.writeFileSync(path.join(www, 'offline.html'), page);
console.log('Apps zeigen jetzt:', site);
