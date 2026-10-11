// Headless Chromium proof of slice 7 (docs/build-specs/transforms-products-lineage.md "Proof"): the ten screens and the dataset page's Lineage tab at 375 x 740 and
// 1280 x 800 — 200, the screen stamped, their element there, no sideways scroll, no console error, a screenshot each (the metric page at 375 first: the chart is readable
// on a phone, twelve markers inside the card); at 1280 the HTMX flows — the record picker chooses a transform's output and a key button inserts into the SQL, Save lands
// on the transform, Run polls the writer to "Done" in the Runs tab, a metric's grain and "Cut by" pickers re-render the chart and the table in place, choosing a product on the
// metric form loads its columns, the graph opens from the Lineage tab — and without JavaScript the transform form, a metric's pickers and the tabs work as plain forms and links.
// Run through tests/phase3/slice7/run.sh (the servers and the environment). Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice7';
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
const sql = (q) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-d', DB, '-c', q], { encoding: 'utf8' }).trim().split('\n')[0];
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
const text = (page, sel) => page.evaluate((s) => (document.querySelector(s)?.innerText || '').trim(), sel);

// the slice's rows (the proofs before made them): a transform, a metric, a product to be made
const monthly = sql("SELECT id FROM datasets WHERE key = 'product.s7_monthly'");
const tmonthly = sql("SELECT id FROM transforms WHERE name = 's7_monthly_t'");
const metric = sql("SELECT id FROM metrics WHERE name = 's7_revenue' AND retired_at IS NULL");
const TAG = crypto.randomBytes(3).toString('hex');
sql(`INSERT INTO datasets (key, zone, name, title, description, owner_member_id, status, classification_floor, dedupe_key) VALUES ('product.s7_br_${TAG}', 'product', 's7_br_${TAG}', 'SMOKE S7 browser ${TAG}', 'The browser proof makes it.', 27, 'active', 'internal', '{}') ON CONFLICT (key) DO NOTHING`);
sql(`INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by) SELECT 'dataset', id, 'everyone', 'read', 'SMOKE for everyone', 1 FROM datasets WHERE key = 'product.s7_br_${TAG}'`);
ok(monthly !== '' && tmonthly !== '' && metric !== '', `the slice's rows are there (product ${monthly}, transform ${tmonthly}, metric ${metric})`);

const screens = [
  ['products', 26, '/products', '#products-list'], ['transform-list', 27, '/transforms', '#transform-list-results'], ['transform-view', 27, `/transforms/${tmonthly}`, '#transform-sql'],
  ['transform-view', 27, `/transforms/${tmonthly}?tab=runs`, '#transform-runs-table'], ['transform-view', 26, `/transforms/${tmonthly}`, '#transform-view-name'],
  ['transform-add', 27, '/transforms/new', '#transform-form'], ['transform-edit', 27, `/transforms/${tmonthly}/edit`, '#transform-form-field-sql'],
  ['metric-list', 26, '/metrics', '#metric-list-results'], ['metric-view', 26, `/metrics/${metric}`, '#metric-chart'], ['metric-view', 26, `/metrics/${metric}?by[]=region&grain=quarter`, '#metric-table'],
  ['metric-add', 27, '/metrics/new', '#metric-form'], ['metric-edit', 27, `/metrics/${metric}/edit`, '#metric-form-field-expression'],
  ['lineage-graph', 27, `/datasets/${monthly}/lineage`, '#lineage-graph-svg'], ['lineage-graph', 26, `/datasets/${monthly}/lineage?depth=3`, '#lineage-impact'],
  ['dataset-view', 26, '/datasets/product.s7_monthly?tab=lineage', '#dataset-lineage'],
];
console.log('375 x 740 — the metric page first: the chart on a phone');
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  const r = await page.goto(`${BASE}/metrics/${metric}`, { waitUntil: 'networkidle' });
  ok(r.status() === 200 && await stamp(page) === 'metric-view' && await shown(page, '#metric-chart-svg-compact') && !(await shown(page, '#metric-chart-svg')), 'the metric page at 375: stamped, the compact drawing shown (not the wide one)');
  const box = await page.locator('#metric-chart-svg-compact').boundingBox();
  ok(box && box.x >= 0 && box.x + box.width <= 376 && box.width > 250 && box.height > 180, `the chart fits the screen (${Math.round(box.width)} x ${Math.round(box.height)} px) and is not a thumbnail`);
  const pts = await page.locator('#metric-chart-svg-compact .metric-point').count();
  ok(pts === 12, `twelve markers (${pts})`);
  const fs = await page.evaluate(() => { const t = document.querySelector('#metric-chart-svg-compact .metric-axis'); const svg = document.querySelector('#metric-chart-svg-compact'); return parseFloat(getComputedStyle(t).fontSize) * svg.getBoundingClientRect().width / svg.viewBox.baseVal.width; });
  ok(fs >= 9, `the axis labels are readable on a phone (${fs.toFixed(1)} px on screen)`);
  ok(await fits(page) && errors.length === 0, `no sideways scroll, no console error${errors.length ? ': ' + errors.join(' | ') : ''}`);
  await page.screenshot({ path: `${SHOTS}/metric-view-phone-first.png`, fullPage: true });
  await ctx.close();
}
for (const [label, viewport, t] of [['375 x 740', { width: 375, height: 740 }, 'phone'], ['1280 x 800', { width: 1280, height: 800 }, 'desk']]) {
  console.log(`${label} — the ten screens and the Lineage tab`);
  let n = 0;
  for (const [screen, member, path, sel] of screens) {
    const { ctx, page, errors } = await session(member, viewport);
    const r = await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const name = `${String(++n).padStart(2, '0')}-${screen}-${member}`;
    await page.screenshot({ path: `${SHOTS}/${name}-${t}.png`, fullPage: true });
    ok(r.status() === 200 && await stamp(page) === screen && await page.locator(sel).count() > 0 && await fits(page) && errors.length === 0,
       `${name}: 200, stamped, ${sel} there, no sideways scroll, no console error${errors.length ? ' — ' + errors.join(' | ') : ''}`);
    await ctx.close();
  }
  const { ctx, page } = await session(27, viewport);
  await page.goto(`${BASE}/transforms/${tmonthly}`, { waitUntil: 'networkidle' });
  const heights = await page.evaluate(() => [...document.querySelectorAll('#transform-view-actions .btn')].map((b) => Math.round(b.getBoundingClientRect().height)));
  ok(heights.length >= 3 && heights.every((h) => h >= 44), `the transform page's buttons are touch targets (${heights.join(', ')} px)`);
  await page.goto(`${BASE}/metrics/${metric}`, { waitUntil: 'networkidle' });
  const sel = await page.evaluate(() => [...document.querySelectorAll('#metric-pickers select, #metric-pickers .form-check, #metric-pickers button')].map((b) => Math.round(b.getBoundingClientRect().height)));
  ok(sel.length >= 3 && sel.every((h) => h >= 44), `the metric's pickers are touch targets (${sel.join(', ')} px)`);
  await page.goto(`${BASE}/datasets/${monthly}/lineage?depth=3`, { waitUntil: 'networkidle' });
  const svg = await page.locator('#lineage-graph-svg').boundingBox();
  const cols = await page.locator('#lineage-graph-svg .lg-column').count();
  ok(cols === 7 && svg && svg.x >= 0 && svg.x + svg.width <= viewport.width + 1, `the graph (7 columns) scales into the screen (${Math.round(svg.width)} px of ${viewport.width}) — the page never scrolls sideways`);
  await ctx.close();
}

console.log('1280 x 800 — the HTMX flows');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  page.on('dialog', (d) => d.accept());
  // the transform form: the record picker chooses the output, a key button inserts into the SQL, a cron is chosen
  await page.goto(BASE + '/transforms/new', { waitUntil: 'networkidle' });
  await page.click('#transform-form-field-output_dataset-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 }).catch(() => {});
  await page.fill('#record-picker-search', `browser ${TAG}`);
  await page.waitForFunction((t) => { const r = [...document.querySelectorAll('#record-picker-results .record-picker-row')]; return r.length === 1 && r[0].innerText.includes(t); }, TAG, { timeout: 10000 }).catch(() => {});
  const rows = await page.locator('#record-picker-results .record-picker-row').count();
  ok(rows === 1, `the output picker (searching the new product) lists it alone (${rows})`);
  await page.locator('#record-picker-results .record-picker-row').first().click();
  await page.waitForTimeout(400);
  ok((await page.inputValue('#transform-form-field-output_dataset')) !== '', 'choosing a row fills the output');
  await page.fill('#transform-form-field-name', `s7_browser_${TAG}`);
  await page.click('#transform-form-field-sql');
  await page.locator('#transform-form-keys button[data-insert="curated.s7_orders"]').click();
  ok((await page.inputValue('#transform-form-field-sql')).includes('curated.s7_orders'), 'a key button inserts the dataset key into the SQL');
  await page.fill('#transform-form-field-sql', 'SELECT region, count(*) AS orders, sum(amount) AS revenue FROM curated.s7_orders GROUP BY region');
  await page.selectOption('#transform-form-field-schedule_kind', 'cron');
  await page.fill('#transform-form-field-cron', '0 6 * * *');
  await page.screenshot({ path: `${SHOTS}/transform-form-filled-desk.png`, fullPage: true });
  await Promise.all([page.waitForURL(/\/transforms\/\d+\?notice=created/, { timeout: 20000 }).catch(() => {}), page.click('#transform-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  const tid = (page.url().match(/\/transforms\/(\d+)/) || [])[1] || '';
  ok(tid !== '' && sql(`SELECT count(*) FROM transforms WHERE id = ${tid} AND name = 's7_browser_${TAG}' AND schedule = '0 6 * * *' AND frozen_policy IS NOT NULL`) === '1' && await shown(page, '#notice-banner') && (await text(page, '#transform-view-name')) === `s7_browser_${TAG}`,
     'Save: it lands on the transform with the notice — frozen, scheduled');
  ok((await text(page, '#transform-view-version')).includes('Every day at 06:00'), 'the schedule is in words: "Every day at 06:00"');
  // Run, and the Runs tab follows the writer
  await page.click(`#transform-${tid}-run`);
  await page.waitForURL(/tab=runs/, { timeout: 15000 }).catch(() => {});
  await page.waitForFunction(() => /Done/.test(document.getElementById('transform-runs-table')?.innerText || ''), null, { timeout: 90000 }).catch(() => {});
  const runWords = await text(page, '#transform-runs');
  ok(/Done/.test(runWords) && /\b4\b/.test(runWords), `Run: the Runs tab polls the writer to Done with its rows — "${runWords.replace(/\s+/g, ' ').slice(0, 90)}"`);
  ok(sql(`SELECT row_count FROM datasets WHERE key = 'product.s7_br_${TAG}'`) === '4', 'the product holds the 4 regions');
  await page.screenshot({ path: `${SHOTS}/transform-runs-desk.png`, fullPage: true });
  // the Lineage tab and the graph
  await page.goto(`${BASE}/datasets/product.s7_br_${TAG}?tab=lineage`, { waitUntil: 'networkidle' });
  ok((await text(page, '#dataset-lineage')).includes('curated.s7_orders') && await shown(page, '#dataset-lineage-maker'), 'the dataset\'s Lineage tab: one hop up is curated.s7_orders, made by the transform');
  await Promise.all([page.waitForURL(/\/lineage/, { timeout: 15000 }).catch(() => {}), page.click('#dataset-lineage-graph-link')]);
  await page.waitForLoadState('networkidle');
  ok(await stamp(page) === 'lineage-graph' && await shown(page, '#lineage-graph-svg'), 'Open the graph: the graph page (HTMX), the SVG shown');
  await Promise.all([page.waitForURL(/depth=3/, { timeout: 15000 }).catch(() => {}), page.click('#lineage-depth-3')]);
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#lineage-graph-svg .lg-column').count()) === 7 && await shown(page, '#lineage-graph-svg .lg-source'), 'three hops: seven columns, and a source appears');
  await page.click('#lineage-graph-svg a.lg-link');
  await page.waitForLoadState('networkidle');
  ok((await stamp(page)) === 'dataset-view', 'a node of the graph is a link to its dataset');
  // the metric pickers re-render in place
  await page.goto(`${BASE}/metrics/${metric}`, { waitUntil: 'networkidle' });
  const before = await text(page, '#metric-table');
  await page.check('#metric-by-region');
  await page.waitForFunction(() => document.getElementById('metric-result')?.dataset.by === 'region', null, { timeout: 15000 }).catch(() => {});
  const cut = await text(page, '#metric-table');
  ok(await page.locator('#metric-result').getAttribute('data-by') === 'region' && cut.includes('EMEA') && cut.length > before.length && page.url().endsWith(`/metrics/${metric}`), 'Cut by region re-renders the table in place (the URL unchanged, the chart kept)');
  await page.selectOption('#metric-grain', 'quarter');
  await page.waitForFunction(() => document.getElementById('metric-result')?.dataset.grain === 'quarter', null, { timeout: 15000 }).catch(() => {});
  ok(await page.locator('#metric-result').getAttribute('data-grain') === 'quarter' && (await page.locator('#metric-chart-svg .metric-point').count()) >= 4 && (await page.locator('#metric-chart-svg .metric-point').count()) < 12, 'the grain picker re-renders at quarter: the chart now has a marker per quarter that has rows (a gap for the others)');
  await page.screenshot({ path: `${SHOTS}/metric-cut-desk.png`, fullPage: true });
  // the metric form: choosing a product loads its columns
  await page.goto(BASE + '/metrics/new', { waitUntil: 'networkidle' });
  ok((await page.locator('#metric-form-columns input[name="dimensions[]"]').count()) === 0, 'the new metric form has no columns until a product is chosen');
  await page.click('#metric-form-field-dataset-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-results .record-picker-row', { timeout: 10000 }).catch(() => {});
  await page.fill('#record-picker-search', 'S7 monthly');
  await page.waitForFunction(() => { const r = [...document.querySelectorAll('#record-picker-results .record-picker-row')]; return r.length === 1 && r[0].innerText.includes('monthly'); }, null, { timeout: 10000 }).catch(() => {});
  await page.locator('#record-picker-results .record-picker-row').first().click();
  await page.waitForFunction(() => document.querySelectorAll('#metric-form-columns input[name="dimensions[]"]').length > 0, null, { timeout: 10000 }).catch(() => {});
  ok((await page.locator('#metric-form-columns input[name="dimensions[]"]').count()) === 4 && (await page.locator('#metric-form-field-time_column option').count()) === 5, 'choosing the product loads its four columns as dimensions and as the time column\'s choices (HTMX)');
  await page.fill('#metric-form-field-title', `Browser metric ${TAG}`);
  await page.fill('#metric-form-field-name', `s7_browser_m_${TAG}`);
  await page.fill('#metric-form-field-expression', 'sum(orders)');
  await page.selectOption('#metric-form-field-time_column', 'month');
  await page.check('#metric-dim-region');
  await Promise.all([page.waitForURL(/\/metrics\/\d+\?notice=created/, { timeout: 20000 }).catch(() => {}), page.click('#metric-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  const mid = (page.url().match(/\/metrics\/(\d+)/) || [])[1] || '';
  ok(mid !== '' && sql(`SELECT count(*) FROM metrics WHERE id = ${mid} AND dimensions = '{region}' AND time_column = 'month'`) === '1' && await shown(page, '#metric-chart'), 'Save the metric: it lands on its page with the chart');
  await Promise.all([page.waitForURL(/notice=certified/, { timeout: 15000 }).catch(() => {}), page.click(`#metric-${mid}-certify`)]);
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT certified FROM metrics WHERE id = ${mid}`) === 't' && (await text(page, '#metric-view-state')).includes('Certified'), 'Certify: the badge');
  await page.goto(`${BASE}/metrics/${mid}/edit`, { waitUntil: 'networkidle' });
  await page.fill('#metric-form-field-expression', 'sum(orders) + 1');
  await Promise.all([page.waitForURL(/notice=dropped/, { timeout: 20000 }).catch(() => {}), page.click('#metric-form-save-btn')]);
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT certified FROM metrics WHERE id = ${mid}`) === 'f' && (await text(page, '#notice-banner')).includes('no longer certified'), 'editing the expression drops the badge and the page says so');
  await page.click(`#metric-${mid}-retire`);
  await page.waitForURL(/\/metrics\?notice=retired/, { timeout: 15000 }).catch(() => {});
  ok(sql(`SELECT retired_at IS NOT NULL FROM metrics WHERE id = ${mid}`) === 't' && page.url().includes('notice=retired'), 'Retire (confirmed): back on the list');
  // delete the transform from its page (confirmed)
  await page.goto(`${BASE}/transforms/${tid}`, { waitUntil: 'networkidle' });
  await page.click('#transform-more-btn');
  await Promise.all([page.waitForURL(/\/transforms\?notice=deleted/, { timeout: 15000 }).catch(() => {}), page.click('#transform-delete-btn')]);
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT count(*) FROM transforms WHERE id = ${tid}`) === '0' && sql(`SELECT count(*) FROM datasets WHERE key = 'product.s7_br_${TAG}'`) === '1' && (await text(page, '#notice-banner')).includes('Deleted'), 'Delete (confirmed): back on the list — the product stays');
  ok(errors.length === 0, `no console error in the flows${errors.length ? ' — ' + errors.join(' | ') : ''}`);
  await ctx.close();
}

console.log('Without JavaScript (1280 x 800) — plain forms and links');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  const out = sql(`SELECT id FROM datasets WHERE key = 'product.s7_br_${TAG}'`);
  await page.goto(`${BASE}/transforms/new?output_dataset=${out}`, { waitUntil: 'load' });
  ok((await page.inputValue('#transform-form-field-output_dataset')) === out, 'the new transform form arrives with the output chosen (the link carried it)');
  await page.fill('#transform-form-field-name', `s7_plain_${TAG}`);
  await page.fill('#transform-form-field-sql', 'SELECT product_line, count(*) AS orders FROM curated.s7_orders GROUP BY product_line');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#transform-form-save-btn')]);
  const tid = (page.url().match(/\/transforms\/(\d+)/) || [])[1] || '';
  ok(tid !== '' && page.url().includes('notice=created') && sql(`SELECT count(*) FROM transforms WHERE id = ${tid}`) === '1', 'the transform form posts as a plain form and lands on the transform with the notice');
  await page.goto(`${BASE}/transforms/${tid}?tab=io`, { waitUntil: 'load' });
  ok(await shown(page, '#transform-tab-io') && !(await shown(page, '#transform-tab-sql')), 'without JavaScript ?tab= chooses the pane that shows');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click(`#transform-${tid}-run`)]);
  ok(page.url().includes('tab=runs') && page.url().includes('notice=queued'), 'Run posts as a plain form and lands on the Runs tab');
  await page.waitForTimeout(500);
  await page.goto(`${BASE}/metrics/${metric}`, { waitUntil: 'load' });
  await page.check('#metric-by-region');
  await page.selectOption('#metric-grain', 'year');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#metric-pickers-show')]);
  ok(page.url().includes('grain=year') && page.url().includes('by%5B%5D=region') && (await text(page, '#metric-table')).includes('EMEA'), 'the metric\'s pickers are a plain GET form: Show re-renders the page cut by region at year');
  await page.goto(`${BASE}/datasets/product.s7_monthly?tab=lineage`, { waitUntil: 'load' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#dataset-lineage-graph-link')]);
  ok(await shown(page, '#lineage-graph-svg') && page.url().includes('/lineage'), 'the Lineage tab\'s link opens the graph');
  await page.screenshot({ path: `${SHOTS}/lineage-nojs.png`, fullPage: true });
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}
sql(`DELETE FROM transforms WHERE name LIKE 's7\\_plain\\_%'`);
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
