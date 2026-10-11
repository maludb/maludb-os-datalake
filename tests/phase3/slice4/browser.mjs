// Headless Chromium proof of slice 4 — access, policies, groups and personal data (access-policies.md "Proof" 9): at 375 x 740 FIRST (the owner's
// one-tap decision on a phone: To decide, the impact line following the level, Approve) and then at 1280 x 800, the twelve screens and the
// dataset page's Access tab — 200, stamped, never scrolling sideways, no console error, a screenshot each; the grant form's Check (the row
// filter's dry run through the query service: "valid", then the node it refuses); without JavaScript (at 1280) the inline decision is a plain form.
// Run through tests/phase3/slice4/run.sh (servers, environment, the slice's lake). Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice4';
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
  // reducedMotion (slice 5): Bootstrap scrolls smoothly otherwise, and a click the driver scrolls into view lands while the page still moves
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, reducedMotion: 'reduce', ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  await page.mouse.move(viewport.width - 20, viewport.height - 120);
  return { ctx, page, errors };
}
const fits = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const stamp = (page) => page.evaluate(() => document.getElementById('page-content')?.dataset.screen || '');

const id = (k) => sql(`SELECT id FROM datasets WHERE key = '${k}'`);
const deals = id('raw.crm_deals'), payroll = id('curated.payroll'), orders = id('curated.sales_orders'), hr = id('curated.hr_reviews');
const gid = sql("SELECT id FROM groups WHERE key = 'smoke-finance-analysts'");
sql("UPDATE access_requests SET status = 'withdrawn' WHERE status = 'pending'");
// a request waiting for the owner: Priya (who reads payroll masked since proof 2) asks to read it unmasked
sql(`INSERT INTO access_requests (requester_id, dataset_id, level, reason) VALUES (26, ${payroll}, 'read_unmasked', 'Commissions need the real salaries this month.')`);
const req = sql(`SELECT max(id) FROM access_requests WHERE requester_id = 26 AND status = 'pending'`);
ok(req !== '', `a request waits for the owner (${req})`);

const screens = [
  ['my-access', 26, '/access', '#my-access-content'], ['requests', 1, '/access/requests?box=history', '#requests-list'], ['request-add', 26, `/access/requests/new?dataset=${hr}`, '#request-form'],
  ['grants', 1, '/access/grants', '#grants-table'], ['grant-add', 27, `/access/grants/new?dataset=${deals}`, '#grant-form'], ['groups', 1, '/groups', `#group-card-${gid}`],
  ['group-view', 1, `/groups/${gid}`, '#group-view-members'], ['group-add', 1, '/groups/new', '#group-form'], ['group-edit', 1, `/groups/${gid}/edit`, '#group-form'],
  ['policies', 1, '/access/policies', '#policy-row-email'], ['personal-data', 27, '/access/personal-data', '#personal-data-table'], ['admin-read', 1, '/access/break-glass', '#breakglass-form'],
  ['dataset-view', 27, `/datasets/${deals}?tab=access`, '#dataset-access-table'], ['dataset-view', 26, `/datasets/${orders}?tab=access`, '#access-explain'],
];

for (const [label, viewport, t] of [['375 x 740', { width: 375, height: 740 }, 'phone'], ['1280 x 800', { width: 1280, height: 800 }, 'desk']]) {
  if (t === 'phone') {
    console.log(`${label} — the owner decides on a phone: To decide first`);
    const { ctx, page, errors } = await session(1, viewport);
    await page.goto(BASE + '/access/requests', { waitUntil: 'networkidle' });
    ok(await page.evaluate(() => document.querySelector('#requests-box-to_decide')?.classList.contains('active')), 'with something to decide, Requests opens on To decide');
    ok(await shown(page, `#request-card-${req}`) && await shown(page, `#request-${req}-approve`) && await shown(page, `#request-${req}-deny`), 'the card with Approve and Deny inline');
    const before = await page.locator(`#request-${req}-impact`).innerText();
    await page.selectOption(`#request-${req}-level`, 'read');
    await page.waitForFunction((r) => (document.querySelector(`#request-${r}-impact`)?.innerText || '').includes('read (masked)'), req, { timeout: 5000 }).catch(() => {});
    const after = await page.locator(`#request-${req}-impact`).innerText();
    ok(before.includes('no column stays masked') && after.includes('2 columns stay masked'), `the impact line follows the level: "${before.trim().slice(0, 60)}…" → "${after.trim().slice(0, 70)}…"`);
    await page.selectOption(`#request-${req}-level`, 'read_unmasked');
    await page.screenshot({ path: `${SHOTS}/decide-${t}.png`, fullPage: true });
    await Promise.all([page.waitForURL(/notice=approved/, { timeout: 10000 }).catch(() => {}), page.click(`#request-${req}-approve`)]);
    await page.waitForLoadState('networkidle');
    ok(sql(`SELECT status FROM access_requests WHERE id = ${req}`) === 'approved' && sql(`SELECT g.level FROM grants g JOIN access_requests r ON r.grant_id = g.id WHERE r.id = ${req}`) === 'read_unmasked',
       'one tap on Approve: approved, a read-unmasked grant');
    ok(await fits(page) && errors.length === 0, `no sideways scroll, no console error${errors.length ? ': ' + errors.join(' | ') : ''}`);
    await ctx.close();
  }
  console.log(`${label} — the twelve screens and the Access tab`);
  for (const [screen, member, path, sel] of screens) {
    const { ctx, page, errors } = await session(member, viewport);
    const r = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const name = screen + (path.includes('tab=access') ? '-tab-' + member : '');
    await page.screenshot({ path: `${SHOTS}/${name}-${t}.png`, fullPage: true });
    ok(r.status() === 200 && await stamp(page) === screen && await page.locator(sel).count() > 0 && await fits(page) && errors.length === 0,
       `${name}: 200, stamped, ${sel} there, no sideways scroll, no console error${errors.length ? ' — ' + errors.join(' | ') : ''}`);
    if (screen === 'grant-add') {
      await page.fill('#grant-form-field-row_filter', 'department_id = ANY(');
      await page.click('button[data-insert="{departments}"]');
      await page.type('#grant-form-field-row_filter', ')');
      ok(await page.inputValue('#grant-form-field-row_filter') === 'department_id = ANY({departments})', 'the token buttons insert at the cursor');
      await page.click('#row-filter-check');
      await page.waitForSelector('#grant-form-row-filter-valid, #grant-form-row-filter-error', { timeout: 10000 }).catch(() => {});
      ok(await shown(page, '#grant-form-row-filter-valid') && (await page.locator('#grant-form-row-filter-valid').innerText()).includes('ARRAY[7]'), 'Check: "Valid — for you it reads … ARRAY[7] …"');
      await page.fill('#grant-form-field-row_filter', 'department_id IN (SELECT 1)');
      await page.click('#row-filter-check');
      await page.waitForSelector('#grant-form-row-filter-error', { timeout: 10000 }).catch(() => {});
      ok(await shown(page, '#grant-form-row-filter-error') && (await page.locator('#grant-form-row-filter-error').innerText()).includes('Subquery'), 'Check: the node it refuses, on the field');
      await page.selectOption('#grant-form-field-principal_kind', 'everyone');
      await page.waitForFunction(() => document.querySelectorAll('#grant-form-field-level option').length === 2, null, { timeout: 5000 }).catch(() => {});
      ok(await page.locator('#grant-form-field-level option').count() === 2, 'the levels follow who: everyone — discover or read');
      ok(errors.length === 0, `the form's fragments raised no console error${errors.length ? ' — ' + errors.join(' | ') : ''}`);
    }
    await ctx.close();
  }
}

console.log('Without JavaScript (1280 x 800 — the theme\'s menu needs JavaScript to fold at 375) — the inline decision is a plain form');
sql(`INSERT INTO access_requests (requester_id, dataset_id, level, reason) VALUES (30, ${hr}, 'read', 'The reviews for my team, please.')`);
const req2 = sql(`SELECT max(id) FROM access_requests WHERE requester_id = 30 AND status = 'pending'`);
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(BASE + '/access/requests?box=to_decide', { waitUntil: 'load' });
  ok(await page.locator(`#request-${req2}-deny-form`).count() === 1, 'To decide renders the forms');
  await page.fill(`#request-${req2}-note`, 'HR keeps the reviews.');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(`#request-${req2}-deny`)]);   // the button itself: html's scroll-padding keeps it out from under the command bar (slice 5)
  ok(sql(`SELECT status FROM access_requests WHERE id = ${req2}`) === 'denied' && page.url().includes('notice=denied'), 'Deny posts as a plain form and lands back with the notice');
  await page.screenshot({ path: `${SHOTS}/denied-nojs.png`, fullPage: true });
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
