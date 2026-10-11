// Headless Chromium proof of slice 5 (docs/build-specs/contracts-quality.md "Proof" 9): the nine screens and the dataset page's Quality tab at
// 375 x 740 and 1280 x 800 — 200, stamped, their element there, no sideways scroll, no console error, a screenshot each (the incident page at 375
// FIRST: Acknowledge with one tap); at 1280 the HTMX flows — a template card opens the check form and the check saves; Promote now polls the
// writer to its words; the contract draft lists a breaking change live when a type changes; a reason chip filters the quarantine — and without
// JavaScript the contract draft and an incident's note post as plain forms.
// Run through tests/phase3/slice5/run.sh (the servers and the environment). Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice5';
const KEY = process.env.ACTION_TOKEN_KEY;
const DB = process.env.DB_NAME;
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

const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  // reducedMotion: Bootstrap scrolls smoothly otherwise, and a click the driver scrolls into view lands while the page still moves ("not stable")
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, reducedMotion: 'reduce', ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const stamp = (page) => page.evaluate(() => document.getElementById('page-content')?.dataset.screen || '');

// a fresh incident for the phone: a warn check on the orders that fails (recorded as the writer would — through the database)
const cur = sql("SELECT id FROM datasets WHERE key = 'curated.orders'");
const chk = sql(`INSERT INTO checks (dataset_id, column_name, kind, params, dimension, severity, source) VALUES (${cur}, 'amount', 'range', '{"max": 1}', 'accuracy', 'warn', 'person') RETURNING id`).split('\n')[0];
sql(`SELECT dl_check_result_record(${chk}, NULL, NULL, 9, '0', false, 9, '["SO-001"]'::jsonb, 3, NULL)`);
const inc = sql(`SELECT id FROM incidents WHERE check_id = ${chk} AND status = 'new'`);
ok(inc !== '', `an incident waits (${inc})`);

console.log('375 x 740 — the incident page first: Acknowledge with one tap');
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 });
  const r = await page.goto(`${BASE}/incidents/${inc}`, { waitUntil: 'networkidle' });
  ok(r.status() === 200 && await stamp(page) === 'incident-view' && await shown(page, `#incident-${inc}-ack`), 'the incident page at 375: stamped, Acknowledge shown');
  const ackBox = await page.locator(`#incident-${inc}-ack`).boundingBox();
  const statusBox = await page.locator('#incident-status').boundingBox();
  ok(ackBox && statusBox && ackBox.y < 740 && ackBox.height >= 44, 'Acknowledge is on the first screen, a full-width touch target');
  await page.screenshot({ path: `${SHOTS}/incident-view-phone.png`, fullPage: true });
  await Promise.all([page.waitForURL(/notice=acknowledged/, { timeout: 10000 }).catch(() => {}), page.click(`#incident-${inc}-ack`)]);
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT status FROM incidents WHERE id = ${inc}`) === 'acknowledged' && await shown(page, '#notice-banner'), 'one tap: acknowledged, the notice shown');
  ok(await fits(page) && errors.length === 0, `no sideways scroll, no console error${errors.length ? ': ' + errors.join(' | ') : ''}`);
  await ctx.close();
}

const screens = [
  ['quality', 27, '/quality', '#quality-incidents'], ['incident-list', 27, '/incidents', '#incident-list-table'], ['incident-view', 26, `/incidents/${inc}`, '#incident-opened-by'],
  ['checks', 27, '/datasets/curated.orders/checks', `#check-strip-${chk}`], ['check-add', 27, '/datasets/curated.orders/checks/new', '#check-templates'],
  ['check-edit', 27, `/checks/${chk}/edit`, '#check-form-form'], ['contract-view', 26, '/datasets/curated.orders/contract', '#contract-schema'],
  ['contract-edit', 27, '/datasets/curated.orders/contract/edit', '#contract-columns'], ['quarantine', 27, '/datasets/curated.orders/quarantine', '#quarantine-table'],
  ['dataset-view', 27, '/datasets/curated.orders?tab=quality', '#quality-checks'], ['dataset-view', 26, '/datasets/curated.orders?tab=quality', '#quality-health'],
];
for (const [label, viewport, t] of [['375 x 740', { width: 375, height: 740 }, 'phone'], ['1280 x 800', { width: 1280, height: 800 }, 'desk']]) {
  console.log(`${label} — the nine screens and the Quality tab`);
  for (const [screen, member, path, sel] of screens) {
    const { ctx, page, errors } = await session(member, viewport);
    const r = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const name = screen + (path.includes('tab=quality') ? '-quality-' + member : '');
    await page.screenshot({ path: `${SHOTS}/${name}-${t}.png`, fullPage: true });
    ok(r.status() === 200 && await stamp(page) === screen && await page.locator(sel).count() > 0 && await fits(page) && errors.length === 0,
       `${name}: 200, stamped, ${sel} there, no sideways scroll, no console error${errors.length ? ' — ' + errors.join(' | ') : ''}`);
    await ctx.close();
  }
}

console.log('1280 x 800 — the HTMX flows');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  // a template card opens the form in place; the check saves and lands on the checks
  await page.goto(BASE + '/datasets/curated.orders/checks/new', { waitUntil: 'networkidle' });
  await page.click('#check-template-row_count');
  await page.waitForSelector('#check-form-form', { timeout: 10000 }).catch(() => {});
  ok(await shown(page, '#check-form-form') && page.url().includes('kind=row_count'), 'the Row count card opens its form (HTMX, the URL pushed)');
  await page.fill('#check-form-min', '2');
  await page.fill('#check-form-max', '500');
  const before = Number(sql(`SELECT count(*) FROM checks WHERE dataset_id = ${cur}`));
  await Promise.all([page.waitForURL(/\/checks(\?|#|$)/, { timeout: 10000 }).catch(() => {}), page.click('#check-form-save')]);
  await page.waitForLoadState('networkidle');
  ok(Number(sql(`SELECT count(*) FROM checks WHERE dataset_id = ${cur}`)) === before + 1 && await shown(page, '#checks-table'), 'saved — back on the checks with the new one');
  // Promote now, and the tab follows the writer
  await page.goto(BASE + '/datasets/curated.orders?tab=quality', { waitUntil: 'networkidle' });
  await Promise.all([page.waitForURL(/notice=promoting/, { timeout: 10000 }).catch(() => {}), page.click('#promote-btn')]);
  await page.waitForFunction(() => /Promoted|Nothing new|Rebuilt/.test(document.getElementById('quality-promotion')?.innerText || ''), null, { timeout: 30000 }).catch(() => {});
  const words = await page.locator('#quality-promotion').innerText();
  ok(/Promoted|Nothing new|Rebuilt/.test(words), `Promote now: the tab polls the writer to its words — "${words.trim().slice(0, 80)}"`);
  // the contract draft lists a breaking change live
  await page.goto(BASE + '/datasets/curated.orders/contract/edit', { waitUntil: 'networkidle' });
  const idx = await page.evaluate(() => [...document.querySelectorAll('input[id$="-name"]')].findIndex((i) => i.value === 'order_date'));
  await page.fill(`#contract-column-${idx}-physical_type`, 'VARCHAR');
  await page.dispatchEvent(`#contract-column-${idx}-physical_type`, 'change');
  await page.waitForFunction(() => (document.getElementById('contract-breaking')?.innerText || '').includes('order_date retyped'), null, { timeout: 10000 }).catch(() => {});
  ok((await page.locator('#contract-breaking').innerText()).includes('order_date retyped DATE → VARCHAR'), 'changing a type lists the breaking change live (order_date retyped DATE → VARCHAR)');
  // a reason chip filters the quarantine
  await page.goto(BASE + '/datasets/curated.orders/quarantine', { waitUntil: 'networkidle' });
  await page.click('#quarantine-reason-cast-order_date');
  await page.waitForURL(/reason=cast/, { timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  const rows = await page.locator('#quarantine-table tbody tr').count();
  ok(page.url().includes('reason=cast') && rows >= 1 && (await page.locator('#quarantine-table tbody').innerText()).includes('cast:order_date'), `the cast:order_date chip filters the table (${rows} row(s))`);
  ok(errors.length === 0, `no console error in the flows${errors.length ? ' — ' + errors.join(' | ') : ''}`);
  await ctx.close();
}

console.log('Without JavaScript (1280 x 800) — plain forms');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(BASE + '/datasets/curated.orders/contract/edit', { waitUntil: 'load' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#contract-form-save')]);
  ok(page.url().includes('/datasets/curated.orders/contract') && page.url().includes('notice=drafted'), 'the contract draft saves as a plain form and lands on the contract with the notice');
  await page.goto(`${BASE}/incidents/${inc}`, { waitUntil: 'load' });
  await page.fill('#incident-note-body', 'Looked at it without JavaScript.');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#incident-note-btn')]);
  ok(page.url().includes('notice=noted') && sql(`SELECT count(*) FROM incident_notes WHERE incident_id = ${inc} AND body = 'Looked at it without JavaScript.'`) === '1', 'an incident\'s note posts as a plain form');
  await page.screenshot({ path: `${SHOTS}/incident-nojs.png`, fullPage: true });
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
