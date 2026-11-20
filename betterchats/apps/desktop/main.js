'use strict';
/*
 * BetterChats desktop app (Electron): shows the BetterChats website in its own hardened window.
 *  - only the BetterChats site may be loaded – every other address and request is blocked
 *  - sandboxed page, context isolation, no Node.js in the page, no DevTools in release builds
 *  - screenshots / screen recording of the window are blocked (setContentProtection)
 *  - no permissions (camera, mic, notifications…), no popups, no cache, nothing kept on disk
 */
const { app, BrowserWindow, session, Menu, shell } = require('electron');
const path = require('path');
const fs = require('fs');

const SITE = new URL(fs.readFileSync(path.join(__dirname, 'site.txt'), 'utf8').trim());

app.commandLine.appendSwitch('disable-http-cache');
app.commandLine.appendSwitch('disk-cache-size', '0');
app.commandLine.appendSwitch('disable-features', 'SpellCheck,AutofillServerCommunication,MediaRouter');
// Dev-only escape hatch for CI containers running as root; impossible in packaged builds.
if (!(process.env.BC_DEV_NO_SANDBOX === '1' && !app.isPackaged)) app.enableSandbox();
if (!app.requestSingleInstanceLock()) app.quit();

const sameSite = (u) => { try { const x = new URL(u); return x.host === SITE.host && (x.protocol === 'https:' || x.protocol === 'wss:'); } catch { return false; } };

const OFFLINE = `data:text/html;charset=utf-8,${encodeURIComponent(`<!doctype html><meta charset="utf-8"><title>BetterChats</title>
<body style="font:16px system-ui;background:#0f1115;color:#e8eaf0;display:grid;place-items:center;height:100vh;margin:0;text-align:center">
<div><h2>Keine Verbindung zu BetterChats</h2><p>Prüfe deine Internetverbindung.</p><p style="opacity:.6">Die App versucht es in 5 Sekunden erneut.</p></div></body>`)}`;

function createWindow() {
  const win = new BrowserWindow({
    width: 1100, height: 760, minWidth: 360, minHeight: 500,
    title: 'BetterChats', backgroundColor: '#0f1115', autoHideMenuBar: true,
    icon: path.join(__dirname, 'icon.png'),
    webPreferences: {
      sandbox: true, contextIsolation: true, nodeIntegration: false, nodeIntegrationInWorker: false,
      webviewTag: false, webSecurity: true, allowRunningInsecureContent: false, spellcheck: false,
      devTools: !app.isPackaged, navigateOnDragDrop: false, safeDialogs: true,
    },
  });
  win.setContentProtection(true); // no screenshots / screen recording of this window
  win.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
  win.webContents.on('will-navigate', (e, url) => { if (!sameSite(url)) e.preventDefault(); });
  win.webContents.on('will-redirect', (e, url) => { if (!sameSite(url)) e.preventDefault(); });
  win.webContents.on('will-attach-webview', (e) => e.preventDefault());
  win.webContents.on('did-fail-load', (e, code, desc, url, isMain) => {
    if (!isMain || code === -3) return; // -3 = aborted
    win.loadURL(OFFLINE);
    setTimeout(() => win.loadURL(SITE.href), 5000);
  });
  win.loadURL(SITE.href);
}

app.whenReady().then(() => {
  Menu.setApplicationMenu(null);
  const ses = session.defaultSession;
  ses.setPermissionRequestHandler((wc, perm, cb) => cb(false));
  ses.setPermissionCheckHandler(() => false);
  ses.setDevicePermissionHandler(() => false);
  ses.setSpellCheckerEnabled(false);
  // network firewall inside the app: only the BetterChats site (https + its WebSocket)
  ses.webRequest.onBeforeRequest((d, cb) => {
    const ok = sameSite(d.url) || d.url.startsWith('data:text/html') || d.url.startsWith('devtools:');
    cb({ cancel: !ok });
  });
  createWindow();
});

// leave nothing behind
app.on('before-quit', () => { session.defaultSession.clearStorageData(); session.defaultSession.clearCache(); });
app.on('window-all-closed', () => app.quit());
void shell;
