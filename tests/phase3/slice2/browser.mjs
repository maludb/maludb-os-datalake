// Headless Chromium proof of slice 2 — uploads, loads and the Preview (docs/build-specs/uploads-loads.md "Proof" 12): at 375 x 740 and
// 1280 x 800, a REAL file chosen on the upload screen and sent in parts by assets/js/upload.js, "Map it", the mapping screen waiting for the
// writer's sniff, a personal proposal cleared, Confirm, the load page following the writer to "Loaded", the dataset's Preview tab read through
// the query service (a table at 1280, one record at a time at 375) — and a Member granted read on someone else's raw dataset seeing the email
// column "masked for you"; the six screens 200, stamped, never scrolling sideways, no console error, a screenshot each in $SHOTS; without
// JavaScript the form posts one file whole, the mapping confirms by a plain POST, the Preview renders on the server.
// Run through tests/phase3/slice2/run.sh (servers, environment, earlier proofs' datasets). Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice2';
const KEY = process.env.ACTION_TOKEN_KEY;
const DB = process.env.DB_NAME;
const FX = process.env.DL_DEV_STATE + '/fixtures';
fs.mkdirSync(SHOTS, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.datalake.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-d', DB, '-c', q], { encoding: 'utf8' }).trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const tag = crypto.randomBytes(3).toString('hex');

const masked = sql("SELECT d.id FROM datasets d JOIN grants g ON g.dataset_id = d.id AND g.principal_kind = 'member' AND g.principal_id = 26 AND g.revoked_at IS NULL WHERE d.key LIKE 'raw.pv\\_%' AND d.key NOT LIKE 'raw.pv\\_orders%' ORDER BY d.id DESC LIMIT 1");
ok(masked !== '', `an engineer's raw dataset with personal columns, read granted to Priya (${masked}) — the preview proof made it`);

const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const stamp = (page) => page.evaluate(() => document.getElementById('page-content')?.dataset.screen || '');

for (const [label, viewport, t] of [['1280 x 800', { width: 1280, height: 800 }, 'desk'], ['375 x 740', { width: 375, height: 740 }, 'phone']]) {
  console.log(`${label} — a spreadsheet, uploaded, mapped, loaded and previewed (Priya, a Member)`);
  const { ctx, page, errors } = await session(26, viewport);
  await page.goto(BASE + '/upload', { waitUntil: 'networkidle' });
  ok(await stamp(page) === 'upload' && await shown(page, '#upload-drop') && !(await shown(page, '#upload-submit-btn')), 'the upload screen: the drop zone (the one-file submit hidden — the script sends parts)');
  await page.setInputFiles('#upload-files-input', `${FX}/customers.csv`);
  await page.waitForSelector('#upload-map-1', { timeout: 30000 });
  const state = await page.locator('#upload-file-1 .upload-state').innerText();
  ok(/Landed|Already here/.test(state) && await shown(page, '#upload-file-1 .upload-bar'), `the file's row: its parts' progress, then "${state}"`);
  await page.screenshot({ path: `${SHOTS}/${t}-upload.png`, fullPage: true });
  await page.click('#upload-map-1');
  await page.waitForSelector('#map-form', { timeout: 30000 });
  ok(await stamp(page) === 'upload-map' && (await page.url()).includes('/map'), 'Map it: the mapping screen (after "Reading the file…")');
  ok(await shown(page, '#map-column-2-proposal') && /email addresses/.test(await page.locator('#map-column-2-proposal').innerText()), 'the email column: "Looks like email addresses — 100 %"');
  if (t === 'desk') {
    ok(await shown(page, '#map-columns-head'), 'at 1280 the columns read as a table (its header row)');
  } else {
    ok(!(await shown(page, '#map-columns-head')) && await shown(page, '#map-column-0'), 'at 375 each column is a card');
  }
  const o1 = await overflow(page);
  ok(o1.sw <= o1.iw, `the mapping never scrolls sideways (${o1.sw}/${o1.iw}); the sample rows scroll in their own box`);
  await page.selectOption('#map-column-1-personal', '');
  await page.fill('#map-name', `browser_${t}_${tag}`);
  await page.screenshot({ path: `${SHOTS}/${t}-upload-map.png`, fullPage: true });
  await page.click('#map-confirm-btn');
  await page.waitForURL(/\/loads\/\d+$/, { timeout: 20000 });
  await page.waitForFunction(() => /Loaded/.test(document.getElementById('load-status')?.innerText || ''), null, { timeout: 30000 });
  ok(await stamp(page) === 'load-view' && /7/.test(await page.locator('#load-rows-loaded').innerText()), 'Confirm: the load page followed the writer to Loaded (7 rows)');
  await page.waitForSelector('#load-rejects-table', { timeout: 15000 }).catch(() => {});
  ok(await shown(page, '#load-rejects-table'), 'its refused line is listed (the page reloaded its panels once the writer finished)');
  await page.screenshot({ path: `${SHOTS}/${t}-load-view.png`, fullPage: true });
  const key = `sandbox.m26_browser_${t}_${tag}`;
  ok(sql(`SELECT personal_kind IS NULL AND classification_confirmed_at IS NOT NULL FROM dataset_columns c JOIN datasets d ON d.id = c.dataset_id WHERE d.key = '${key}' AND c.name = 'name'`) === 't', 'the cleared proposal is recorded: not personal, confirmed by her');
  await page.goto(BASE + `/datasets/${key}`, { waitUntil: 'networkidle' });
  await page.click('#dataset-tab-preview-link');
  if (t === 'desk') {
    await page.waitForSelector('#preview-table', { timeout: 20000 });
    ok(await shown(page, '#preview-table') && (await page.locator('#preview-table tbody tr').count()) === 7, 'the Preview tab: 7 rows through the query service, as a table');
  } else {
    await page.waitForSelector('#preview-records', { timeout: 20000 });
    ok(await shown(page, '#preview-record-1') && !(await shown(page, '#preview-record-2')), 'at 375 one record at a time');
    await page.click('#preview-next-1');
    await page.waitForTimeout(200);
    ok(await shown(page, '#preview-record-2') && !(await shown(page, '#preview-record-1')), 'Next shows the second (no new read)');
  }
  await page.screenshot({ path: `${SHOTS}/${t}-dataset-preview.png`, fullPage: true });
  await page.goto(BASE + `/datasets/${masked}?tab=preview`, { waitUntil: 'networkidle' });
  if (t === 'desk') {
    ok(await shown(page, '#preview-masked-email') && /masked for you/i.test(await page.locator('#preview-masked-email').innerText()) && (await page.locator('#preview-masked-email').getAttribute('title')) === 'masked for you: partial_email',
      'someone else\'s dataset she may read: the email header "masked for you" (partial_email on hover)');
    ok(/a\*\*\*@example\.invalid/.test(await page.locator('#preview-table tbody tr').first().innerText()), '…and its cells a***@example.invalid');
  } else {
    ok(/masked for you/i.test(await page.locator('#preview-record-1').innerText()) && /a\*\*\*@example\.invalid/.test(await page.locator('#preview-record-1').innerText()), 'at 375 the record says masked for you, the value masked');
  }
  await page.screenshot({ path: `${SHOTS}/${t}-preview-masked.png`, fullPage: true });
  const upload = sql("SELECT max(id) FROM upload_sessions WHERE member_id = 26 AND status = 'done'");
  const load = sql(`SELECT max(l.id) FROM loads l JOIN datasets d ON d.id = l.dataset_id WHERE d.key = '${key}'`);
  const bad = [];
  for (const [id, url] of [['upload', '/upload'], ['upload-map', `/uploads/${upload}/map`], ['sandbox', '/sandbox'], ['load-view', `/loads/${load}`], ['templates', '/templates']]) {
    const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    if (resp.status() !== 200 || o.sw > o.iw || await stamp(page) !== id) bad.push(`${id} (${resp.status()}, ${o.sw}/${o.iw}, ${await stamp(page)})`);
    await page.screenshot({ path: `${SHOTS}/${t}-${id}.png`, fullPage: true });
  }
  ok(bad.length === 0, `her screens answer 200, stamp their id, never scroll sideways at ${viewport.width} px` + (bad.length ? ' — wrong: ' + bad.join(', ') : ''));
  ok(await shown(page, '#templates-content'), 'the saved mappings');
  await page.goto(BASE + '/sandbox', { waitUntil: 'networkidle' });
  ok(await shown(page, '#quota-bar') && (await page.locator('[id^="sandbox-card-"]').count()) > 0, 'my sandbox: the quota bar and my datasets as cards');
  ok(errors.length === 0, 'no console error' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();

  const m = await session(27, viewport);
  const r = await m.page.goto(BASE + '/loads', { waitUntil: 'networkidle' });
  const o2 = await overflow(m.page);
  ok(r.status() === 200 && await stamp(m.page) === 'load-list' && o2.sw <= o2.iw && await shown(m.page, '#load-list-table'), `the loads ledger (an engineer): a table, no sideways scroll (${o2.sw}/${o2.iw})`);
  await m.page.screenshot({ path: `${SHOTS}/${t}-load-list.png`, fullPage: true });
  ok(m.errors.length === 0, 'no console error on the ledger');
  await m.ctx.close();
}

console.log('without JavaScript — one file posted whole, a plain POST to confirm, the Preview rendered on the server');
{
  const { ctx, page } = await session(26, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(BASE + '/upload', { waitUntil: 'load' });
  ok(await shown(page, '#upload-submit-btn'), 'the form\'s own Upload button is there');
  await page.setInputFiles('#upload-files-input', `${FX}/products.parquet`);
  await page.click('#upload-submit-btn');
  await page.waitForURL(/\/uploads\/\d+\/map$/, { timeout: 20000 });
  ok(/\/uploads\/\d+\/map$/.test(page.url()), 'the file posted whole: on its mapping screen');
  for (let i = 0; i < 40 && !(await page.$('#map-form')); i++) { await page.waitForTimeout(500); await page.reload({ waitUntil: 'load' }); }
  ok(!!(await page.$('#map-form')), 'once read, the mapping (reloaded by hand — "Look again")');
  await page.fill('#map-name', `nojs_${tag}`);
  await page.click('#map-confirm-bottom', { force: true });
  await page.waitForURL(/\/loads\/\d+$/, { timeout: 20000 });
  for (let i = 0; i < 40 && !/Loaded/.test((await page.locator('#load-status').innerText()) || ''); i++) { await page.waitForTimeout(500); await page.reload({ waitUntil: 'load' }); }
  ok(/Loaded/.test(await page.locator('#load-status').innerText()), 'confirmed by a plain POST: the load page, Loaded');
  await page.goto(BASE + `/datasets/sandbox.m26_nojs_${tag}?tab=preview`, { waitUntil: 'load' });
  ok(await shown(page, '#preview-table') && (await page.locator('#preview-table tbody tr').count()) === 3, 'the Preview tab without JavaScript: rendered on the server (3 rows)');
  await page.screenshot({ path: `${SHOTS}/nojs-preview.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
