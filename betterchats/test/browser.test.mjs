// End-to-end in real browsers (Playwright/Chromium): what a user sees, what DevTools and the
// network see. Skipped automatically if Playwright is not installed.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { startServer } from './helpers.mjs';

let chromium;
try { ({ chromium } = createRequire(import.meta.url)('playwright')); } catch { /* not installed */ }
const skip = !chromium && 'playwright not installed';

let s, browser;
before(async () => { if (skip) return; s = await startServer(); browser = await chromium.launch(); });
after(async () => { if (skip) return; await browser.close(); await s.stop(); });

const errs = [], wire = [];
async function page() {
  const p = await (await browser.newContext()).newPage();
  p.on('pageerror', (e) => errs.push(String(e)));
  p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
  p.on('websocket', (w) => { w.on('framesent', (f) => wire.push(f.payload)); w.on('framereceived', (f) => wire.push(f.payload)); });
  p.on('dialog', (d) => d.accept());
  await p.goto(`http://127.0.0.1:${s.port}/`);
  return p;
}
async function reg(p, name, pw) {
  await p.click('#tabReg'); await p.fill('#name', name); await p.fill('#pin', pw); await p.click('#authBtn');
  await p.waitForSelector('#app:not([hidden])', { timeout: 10_000 });
}

test('full user journey + nothing readable in DOM or network', { skip, timeout: 120_000 }, async () => {
  const a = await page(), b = await page(), c = await page();
  await reg(a, 'alice', '482913'); await reg(b, 'bob', '739204'); await reg(c, 'carl', '582910');
  await c.close();

  // offline person: message, no chat
  await a.fill('#newName', 'carl'); await a.click('#newChat button'); await a.waitForTimeout(500);
  assert.equal(await a.textContent('#newErr'), 'carl ist offline.');
  assert.equal(await a.isVisible('#conv'), false);

  await a.fill('#newName', 'bob'); await a.click('#newChat button'); await a.waitForSelector('#conv:not([hidden])');
  for (const t of ['Hallo Bob, streng geheim!', 'Zweite\nZeile']) { await a.fill('#text', t); await a.press('#text', 'Enter'); }
  await b.waitForSelector('#chatList li'); await b.click('#chatList li');
  await b.waitForFunction(() => document.querySelectorAll('.msgs li.theirs canvas').length === 2);
  await b.fill('#text', 'Antwort'); await b.press('#text', 'Enter');
  await a.waitForSelector('.msgs li.theirs canvas');

  // DevTools "Elements": no message text in the DOM
  const dom = await b.content();
  assert.ok(!dom.includes('streng geheim') && !dom.includes('Zweite'));
  // DevTools "Network": no plaintext, no names, no password
  const w = wire.map((p) => (typeof p === 'string' ? p : p.toString('latin1'))).join('');
  for (const secret of ['streng geheim', 'Antwort', '482913', '739204', 'alice', '"bob"']) assert.ok(!w.includes(secret), secret);
  // no timestamps anywhere
  assert.ok(!/\d\d:\d\d/.test(await a.$$eval('.msgs li', (l) => l.map((x) => x.textContent).join(' '))));
  // safety numbers match on both sides
  await a.click('#safetyBtn'); await b.click('#safetyBtn');
  assert.equal(await a.textContent('#safetyNum'), await b.textContent('#safetyNum'));

  // draft gets sealed when leaving and restored when coming back
  await a.fill('#text', 'Entwurf');
  await a.evaluate(() => window.dispatchEvent(new Event('blur'))); await a.waitForTimeout(200);
  assert.equal(await a.inputValue('#text'), '');
  await a.evaluate(() => window.dispatchEvent(new Event('focus'))); await a.waitForTimeout(200);
  assert.equal(await a.inputValue('#text'), 'Entwurf');

  // bob leaves -> alice's chat is gone
  await b.close(); await a.waitForTimeout(800);
  assert.equal(await a.$$eval('#chatList li', (l) => l.length), 0);
  assert.match(await a.textContent('#newErr'), /offline gegangen/);
  assert.deepEqual(errs, []);
});

test('login: weak PIN refused, wrong password, unknown user, delete account', { skip, timeout: 60_000 }, async () => {
  const p = await page();
  await p.click('#tabReg'); await p.fill('#name', 'dora'); await p.fill('#pin', '123456'); await p.click('#authBtn'); await p.waitForTimeout(300);
  assert.match(await p.textContent('#authErr'), /zu leicht/);
  await p.fill('#pin', '739204'); await p.click('#authBtn'); await p.waitForSelector('#app:not([hidden])');
  await p.reload();
  await p.fill('#name', 'dora'); await p.fill('#pin', '000001'); await p.click('#authBtn'); await p.waitForFunction(() => document.querySelector('#authErr').textContent);
  assert.equal(await p.textContent('#authErr'), 'Name oder Passwort falsch.');
  await p.fill('#name', 'gibtsnicht'); await p.click('#authBtn'); await p.waitForTimeout(1500);
  assert.equal(await p.textContent('#authErr'), 'Name oder Passwort falsch.');
  await p.fill('#name', 'dora'); await p.fill('#pin', '739204'); await p.click('#authBtn'); await p.waitForSelector('#app:not([hidden])');
  await p.click('#delToggle'); await p.fill('#delPin', '111111'); await p.click('#delForm button');
  await p.waitForFunction(() => document.querySelector('#delErr').textContent);
  assert.equal(await p.textContent('#delErr'), 'Passwort falsch.');
  await p.fill('#delPin', '739204'); await p.click('#delForm button'); await p.waitForSelector('#auth:not([hidden])');
  assert.deepEqual(errs, []);
});
