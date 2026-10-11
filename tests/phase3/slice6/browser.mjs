// Headless Chromium proof of slice 6 (docs/build-specs/sources-pulls.md "Proof": 375 and 1280): the four screens — the list (cards), a source's page (header, the three
// tabs by anchor), Add source (the five connector cards, then the form) and the edit form — at 375 x 740 and 1280 x 800: 200, stamped, their element there, no sideways
// scroll, no console error, a screenshot each, the header's buttons at least 44 px tall on the phone; at 1280 the HTMX flows — a connector card opens its form in place,
// a source is added, Test answers in a notice, Pull now is taken by the worker while the Pulls tab polls itself to "Succeeded", a credential is sealed and only its last
// four show, Pause and Resume, the record picker chooses the raw dataset, Edit saves, Delete (confirmed) leaves the list with its notice — and without JavaScript the add
// form, Test and Pause post as plain forms.
// Run through tests/phase3/slice6/run.sh (the servers, the fixtures server and the environment). Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice6';
const KEY = process.env.ACTION_TOKEN_KEY;
const DB = process.env.DB_NAME;
const FX_STATE = process.env.FX_STATE;
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

// a file for the browser's source to fetch, beside whatever the other proofs put in the fixtures server's state
const state = fs.existsSync(FX_STATE) ? JSON.parse(fs.readFileSync(FX_STATE, 'utf8') || '{}') : {};
state.files = { ...(state.files || {}), 'browser.csv': { file: 'sales_a.csv', etag: '"b1"' } };
fs.writeFileSync(FX_STATE, JSON.stringify(state));

const TAG = crypto.randomBytes(2).toString('hex');                                // a new dataset name each run: the proofs may run twice on one database
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
const text = (page, sel) => page.locator(sel).first().innerText();

// the sources of the other proofs, and one of our own
const id = (name) => sql(`SELECT id FROM sources WHERE name = '${name.replace(/'/g, "''")}' ORDER BY id DESC LIMIT 1`);
const hasData = id('Pull sales') !== '';
const http = hasData ? id('Pull sales') : sql(`INSERT INTO sources (name, connector, settings, schedule_minutes) VALUES ('Browser seed', 'http_file', '{"url": "http://127.0.0.1:9004/data/browser.csv", "format": "csv"}', 60) RETURNING id`).split('\n')[0];
const k7 = hasData ? id('GL account balances') : http;
const pgs = hasData ? id('Outside orders (pull)') : http;

const screens = [
  ['source-list', '/sources', '#source-list-results'], ['source-view', `/sources/${http}`, '#source-settings-dl'], ['source-view', `/sources/${http}?tab=pulls`, '#source-pulls'],
  ['source-view', `/sources/${k7}?tab=feeds`, '#source-feeds'], ['source-view', `/sources/${pgs}`, '#source-credential'],
  ['source-add', '/sources/new', '#source-connector-cards'], ['source-add', '/sources/new?connector=k7', '#source-form-field-rows_path'], ['source-edit', `/sources/${http}/edit`, '#source-form-field-name'],
];
for (const [label, viewport, t] of [['375 x 740', { width: 375, height: 740 }, 'phone'], ['1280 x 800', { width: 1280, height: 800 }, 'desk']]) {
  console.log(`${label} — the four screens`);
  const { ctx, page, errors } = await session(27, viewport);
  let n = 0;
  for (const [screen, path, sel] of screens) {
    const r = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${SHOTS}/${screen}-${++n}-${t}.png`, fullPage: true });
    ok(r.status() === 200 && await stamp(page) === screen && await page.locator(sel).count() > 0 && await fits(page),
       `${screen} ${path}: 200, stamped, ${sel} there, no sideways scroll`);
  }
  ok(errors.length === 0, `no console error${errors.length ? ' — ' + errors.join(' | ') : ''}`);
  await page.goto(BASE + `/sources/${http}`, { waitUntil: 'networkidle' });
  const heights = await page.evaluate(() => [...document.querySelectorAll('#source-view-actions .btn')].map((b) => Math.round(b.getBoundingClientRect().height)));
  ok(heights.length >= 3 && heights.every((h) => h >= 44), `the header's buttons are touch targets (${heights.join(', ')} px)`);
  await page.goto(BASE + '/sources', { waitUntil: 'networkidle' });
  const cardH = await page.evaluate(() => [...document.querySelectorAll('#source-list-connectors .btn')].map((b) => Math.round(b.getBoundingClientRect().height)));
  ok(cardH.every((h) => h >= 30), `the connector chips are tappable (${cardH.join(', ')} px)`);
  await ctx.close();
}

console.log('1280 x 800 — the HTMX flows');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  page.on('dialog', (d) => d.accept());
  await page.goto(BASE + '/sources/new', { waitUntil: 'networkidle' });
  await page.click('#source-connector-http_file-link');
  await page.waitForSelector('#source-form-field-url', { timeout: 10000 }).catch(() => {});
  ok(await shown(page, '#source-form-field-url') && page.url().includes('connector=http_file') && await stamp(page) === 'source-add', 'a connector card opens its form in place (HTMX, the URL pushed)');
  // the record picker chooses the raw dataset
  await page.click('#source-form-field-target_dataset-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 }).catch(() => {});
  const rows = await page.locator('#record-picker-results .record-picker-row').count();
  ok(rows >= 1 || !hasData, `the raw-dataset picker lists the raw datasets Marco may write (${rows})`);
  if (rows >= 1) {
    await page.locator('#record-picker-results .record-picker-row').first().click();
  } else {
    await page.keyboard.press('Escape');                       // a database with nothing loaded yet: nothing to choose
  }
  await page.waitForTimeout(500);
  ok(rows < 1 || (await page.inputValue('#source-form-field-target_dataset')) !== '', 'choosing a row fills the target');
  await page.evaluate(() => { document.getElementById('source-form-field-target_dataset').value = ''; });       // …and a NEW dataset is named instead
  await page.fill('#source-form-field-name', 'Browser file');
  await page.fill('#source-form-field-url', 'http://127.0.0.1:9004/data/browser.csv');
  await page.fill('#source-form-field-target_name', `browser_file_${TAG}`);
  await page.screenshot({ path: `${SHOTS}/add-form-filled-desk.png`, fullPage: true });
  await Promise.all([page.waitForURL(/\/sources\/\d+\?notice=created/, { timeout: 15000 }).catch(() => {}), page.click('#source-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  const sid = (page.url().match(/\/sources\/(\d+)/) || [])[1] || '';
  ok(sid !== '' && sql(`SELECT count(*) FROM sources WHERE id = ${sid} AND name = 'Browser file' AND connector = 'http_file'`) === '1' && await shown(page, '#notice-banner') && (await text(page, '#source-view-name')) === 'Browser file',
     'Add the source: it lands on its page with the notice');
  ok(sql(`SELECT key FROM datasets WHERE source_id = ${sid}`) === `raw.browser_file_${TAG}`, 'the new raw dataset was made and linked');
  // Test answers in a notice
  await Promise.all([page.waitForURL(/notice=test_ok/, { timeout: 15000 }).catch(() => {}), page.click(`#source-${sid}-test`)]);
  await page.waitForLoadState('networkidle');
  ok(page.url().includes('said=') && (await text(page, '#notice-banner')).includes('The file answers'), 'Test: the notice says what the file answered — ' + (await text(page, '#notice-banner')).trim().slice(0, 60));
  // a credential: only its last four show
  await page.goto(BASE + `/sources/${sid}?tab=settings`, { waitUntil: 'networkidle' });
  await page.fill('#source-credential-field-label', 'Browser bearer');
  await page.fill('#source-credential-field-secret', 'browser-secret-1234');
  await Promise.all([page.waitForURL(/notice=credential/, { timeout: 15000 }).catch(() => {}), page.click('#source-credential-save-btn')]);
  await page.waitForLoadState('networkidle');
  const body = await page.content();
  ok((await text(page, '#source-credential-last4')) === '1234' && !body.includes('browser-secret-1234') && sql(`SELECT count(*) FROM source_credentials WHERE source_id = ${sid}`) === '1', 'the credential is sealed: the page shows its last four (1234) and never the secret');
  await page.screenshot({ path: `${SHOTS}/source-credential-desk.png`, fullPage: true });
  // Pause and Resume
  await Promise.all([page.waitForURL(/notice=paused/, { timeout: 15000 }).catch(() => {}), page.click(`#source-${sid}-pause`)]);
  await page.waitForLoadState('networkidle');
  ok((await text(page, '#source-view-state')).trim() === 'Paused' && await shown(page, `#source-${sid}-resume`) && !(await shown(page, `#source-${sid}-pull`)), 'Pause: the page says Paused and offers Resume, not Pull now');
  await Promise.all([page.waitForURL(/notice=resumed/, { timeout: 15000 }).catch(() => {}), page.click(`#source-${sid}-resume`)]);
  await page.waitForLoadState('networkidle');
  ok((await text(page, '#source-view-state')).trim() === 'Active', 'Resume: Active again');
  // Pull now: queued, the Pulls tab polls, the worker takes it, the table follows
  sql(`UPDATE sources SET paused_at = now(), paused_reason = 'browser isolation' WHERE id <> ${sid} AND paused_at IS NULL`);
  try {
    await Promise.all([page.waitForURL(/notice=queued/, { timeout: 15000 }).catch(() => {}), page.click(`#source-${sid}-pull`)]);
    await page.waitForLoadState('networkidle');
    await page.waitForSelector('#source-pulls-busy', { state: 'visible', timeout: 5000 }).catch(() => {});
    const busy = [page.url().includes('tab=pulls'), await shown(page, '#source-pulls-busy'), await page.getAttribute('#source-pulls', 'hx-trigger')];
    if (!busy[1]) { busy.push(await page.evaluate(() => { const e = document.getElementById('source-pulls-busy'); return e ? JSON.stringify([e.getBoundingClientRect().height, document.getElementById('source-tab-pulls')?.className, document.getElementById('source-pulls')?.innerText.slice(0, 80)]) : 'no element: ' + (document.getElementById('source-pulls')?.innerText || 'no #source-pulls').slice(0, 200); })); }
    ok(busy[0] && busy[1] && busy[2] === 'every 3s', `Pull now: queued — the Pulls tab shows a pull running and polls itself (${busy.join(', ')} ${page.url()})`);
    const out = execFileSync('php', ['bin/worker.php', '--passes=schedules'], { encoding: 'utf8', cwd: new URL('../../../', import.meta.url).pathname });
    ok(JSON.parse(out.trim().split('\n').pop()).ran.schedules.sources.queued === 1, 'the worker takes the queued pull');
    await page.waitForFunction(() => /Succeeded/.test(document.getElementById('source-pulls-table')?.innerText || ''), null, { timeout: 20000 }).catch(() => {});
    ok(/Succeeded/.test(await text(page, '#source-pulls')) && !(await shown(page, '#source-pulls-busy')), 'the polled table turns to Succeeded by itself, and stops polling');
    const lid = sql(`SELECT id FROM loads WHERE source_id = ${sid} ORDER BY id LIMIT 1`);
    ok(lid !== '' && (await page.locator(`#source-pulls-table a[href="/loads/${lid}"]`).count()) >= 1, 'the pull row links the load it made');
    await page.screenshot({ path: `${SHOTS}/source-pulls-desk.png`, fullPage: true });
  } finally {
    sql(`UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE paused_reason = 'browser isolation'`);
  }
  // the Feeds tab, once the pull made its edge
  await page.goto(BASE + `/sources/${sid}?tab=feeds`, { waitUntil: 'networkidle' });
  ok((await text(page, '#source-feeds')).includes(`raw.browser_file_${TAG}`), 'the Feeds tab names the dataset it feeds');
  // tabs by anchor: with JavaScript the pane switches in place
  await page.click('#source-tab-settings-link');
  await page.waitForTimeout(300);
  ok(await shown(page, '#source-settings-dl') && !(await shown(page, '#source-feeds')), 'a tab switches the pane in place');
  // Edit
  await page.click('#source-more-btn');
  await page.click('#source-edit-link');
  await page.waitForSelector('#source-form-field-name', { timeout: 10000 });
  await page.fill('#source-form-field-name', 'Browser file (renamed)');
  await Promise.all([page.waitForURL(/notice=updated/, { timeout: 15000 }).catch(() => {}), page.click('#source-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  ok((await text(page, '#source-view-name')) === 'Browser file (renamed)' && (await text(page, '#notice-banner')).includes('Saved'), 'Edit: saved, the page shows the new name');
  // Delete (confirmed)
  await page.click('#source-more-btn');
  await Promise.all([page.waitForURL(/\/sources\?notice=deleted/, { timeout: 15000 }).catch(() => {}), page.click('#source-delete-btn')]);
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT count(*) FROM sources WHERE id = ${sid}`) === '0' && (await text(page, '#notice-banner')).includes('Deleted') && sql(`SELECT count(*) FROM datasets WHERE key = 'raw.browser_file_${TAG}'`) === '1', 'Delete (confirmed): back on the list with its notice — the dataset stays');
  ok(errors.length === 0, `no console error in the flows${errors.length ? ' — ' + errors.join(' | ') : ''}`);
  await ctx.close();
}

console.log('Without JavaScript (1280 x 800) — plain forms');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(BASE + '/sources/new', { waitUntil: 'load' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#source-connector-http_file-link')]);
  ok(page.url().includes('connector=http_file') && await shown(page, '#source-form-field-url'), 'a connector card is a plain link to its form');
  await page.fill('#source-form-field-name', 'Plain form file');
  await page.fill('#source-form-field-url', 'http://127.0.0.1:9004/data/browser.csv');
  await page.fill('#source-form-field-target_name', `plain_form_file_${TAG}`);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#source-form-save-btn')]);
  const sid = (page.url().match(/\/sources\/(\d+)/) || [])[1] || '';
  ok(sid !== '' && page.url().includes('notice=created') && sql(`SELECT count(*) FROM sources WHERE id = ${sid}`) === '1', 'the add form posts as a plain form and lands on the source with the notice');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(`#source-${sid}-test`)]);
  ok(page.url().includes('notice=test_ok') && (await text(page, '#notice-banner')).includes('The file answers'), 'Test answers as the notice on the page');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(`#source-${sid}-pause`)]);
  ok(page.url().includes('notice=paused') && sql(`SELECT count(*) FROM sources WHERE id = ${sid} AND paused_at IS NOT NULL`) === '1', 'Pause posts as a plain form');
  await page.goto(BASE + `/sources/${sid}?tab=pulls`, { waitUntil: 'load' });
  ok(await shown(page, '#source-tab-pulls') && !(await shown(page, '#source-tab-settings')), 'without JavaScript ?tab= chooses the pane that shows');
  await page.screenshot({ path: `${SHOTS}/source-nojs.png`, fullPage: true });
  sql(`DELETE FROM sources WHERE id = ${sid}`);
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}
if (!hasData) { sql(`DELETE FROM sources WHERE id = ${http}`); }
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
