'use strict';
/*
 * BetterChats desktop app (Electron), hardened:
 *  - the chat code ships INSIDE the signed app (served from app://betterchats), the server only relays
 *  - sandboxed renderer, context isolation, no Node.js in the page, no DevTools in release builds
 *  - screenshots / screen recording of the window are blocked (setContentProtection)
 *  - every network request except the own files and the configured chat server is blocked
 *  - no permissions (camera, mic, notifications…), no navigation, no popups, no cache, nothing on disk
 */
const { app, BrowserWindow, protocol, session, Menu, net } = require('electron');
const path = require('path');
const fs = require('fs');

const WWW = path.join(__dirname, 'www');
const CSP = fs.readFileSync(path.join(WWW, 'csp.txt'), 'utf8');
const SERVER = new URL(fs.readFileSync(path.join(__dirname, 'server.txt'), 'utf8').trim());
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml' };

app.commandLine.appendSwitch('disable-http-cache');
app.commandLine.appendSwitch('disk-cache-size', '0');
app.commandLine.appendSwitch('disable-features', 'SpellCheck,AutofillServerCommunication,MediaRouter');
// Dev-only escape hatch for CI containers running as root; impossible in packaged builds.
if (!(process.env.BC_DEV_NO_SANDBOX === '1' && !app.isPackaged)) app.enableSandbox();
if (!app.requestSingleInstanceLock()) app.quit();

protocol.registerSchemesAsPrivileged([{ scheme: 'app', privileges: { standard: true, secure: true, supportFetchAPI: true } }]);

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
  win.webContents.on('will-navigate', (e, url) => { if (!url.startsWith('app://betterchats/')) e.preventDefault(); });
  win.webContents.on('will-attach-webview', (e) => e.preventDefault());
  win.loadURL('app://betterchats/index.html');
}

app.whenReady().then(() => {
  Menu.setApplicationMenu(null);
  const ses = session.defaultSession;
  ses.setPermissionRequestHandler((wc, perm, cb) => cb(false));
  ses.setPermissionCheckHandler(() => false);
  ses.setDevicePermissionHandler(() => false);
  ses.setSpellCheckerEnabled(false);
  // network firewall inside the app: only our own files + the chat server
  ses.webRequest.onBeforeRequest((d, cb) => {
    const u = new URL(d.url);
    const ok = u.protocol === 'app:' || ((u.protocol === 'wss:' || u.protocol === 'ws:') && u.host === SERVER.host) || u.protocol === 'devtools:';
    cb({ cancel: !ok });
  });
  protocol.handle('app', (req) => {
    const u = new URL(req.url);
    const file = path.normalize(path.join(WWW, decodeURIComponent(u.pathname)));
    if (u.host !== 'betterchats' || !file.startsWith(WWW + path.sep) || !TYPES[path.extname(file)] || !fs.existsSync(file)) {
      return new Response('', { status: 404 });
    }
    return new Response(fs.readFileSync(file), {
      headers: {
        'Content-Type': TYPES[path.extname(file)], 'Content-Security-Policy': CSP + "; frame-ancestors 'none'",
        'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store', 'Cross-Origin-Opener-Policy': 'same-origin',
      },
    });
  });
  createWindow();
});

// leave nothing behind
app.on('before-quit', () => { session.defaultSession.clearStorageData(); session.defaultSession.clearCache(); });
app.on('window-all-closed', () => app.quit());
app.on('web-contents-created', (e, wc) => {
  wc.on('will-redirect', (ev) => ev.preventDefault());
});
void net;
