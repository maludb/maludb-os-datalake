// Headless Chromium proof of slice 1 — the catalog (docs/build-specs/catalog.md "Proof" 11): the eleven screens at 375 x 740 and 1280 x 800
// (200, no sideways scroll, no console error, a screenshot each in $SHOTS); the dataset page's tabs switching in place with JavaScript and as
// plain links without it; the ⋯ menu (a <details>) opening with and without JavaScript; the Columns tab a table at 1280 and cards at 375;
// HTMX navigation from a Browse card (title, screen stamp, the menu's highlight); the tag field's slug preview; the record picker choosing a
// steward; a plain form POST (follow) without JavaScript landing on the page with its notice; a Member's stub. Run through
// tests/phase3/slice1/run.sh (servers, environment, fixtures). Playwright from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice1';
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

const orders = sql("SELECT id FROM datasets WHERE key = 'curated.sales_orders'");
const term = sql("SELECT id FROM glossary_terms WHERE status <> 'deprecated' ORDER BY id DESC LIMIT 1");
ok(orders !== '' && term !== '', `the fixtures are there (orders ${orders}, term ${term})`);
const screens = [
  ['browse', '/browse'], ['dataset-view', `/datasets/${orders}`], ['dataset-add', '/datasets/new'], ['dataset-edit', `/datasets/${orders}/edit`],
  ['column-view', `/datasets/${orders}/columns/region`], ['column-edit', `/datasets/${orders}/columns/region/edit`], ['glossary', '/glossary'],
  ['term-view', `/glossary/${term}`], ['term-add', '/glossary/new'], ['term-edit', `/glossary/${term}/edit`], ['tags', '/tags'],
];

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

for (const [label, viewport, tag] of [['375 x 740', { width: 375, height: 740 }, 'phone'], ['1280 x 800', { width: 1280, height: 800 }, 'desk']]) {
  console.log(`${label} — the eleven screens (an engineer)`);
  const { ctx, page, errors } = await session(27, viewport);
  const bad = [];
  for (const [id, url] of screens) {
    const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    const stamp = await page.evaluate(() => document.getElementById('page-content')?.dataset.screen || '');
    if (resp.status() !== 200 || o.sw > o.iw || stamp !== id) bad.push(`${id} (${resp.status()}, ${o.sw}/${o.iw}, ${stamp})`);
    await page.screenshot({ path: `${SHOTS}/${tag}-${id}.png`, fullPage: true });
  }
  ok(bad.length === 0, `every screen answers 200, stamps its screen id and never scrolls sideways at ${viewport.width} px` + (bad.length ? ' — wrong: ' + bad.join(', ') : ''));
  ok(errors.length === 0, 'no console error on any of them' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));

  await page.goto(BASE + `/datasets/${orders}`, { waitUntil: 'networkidle' });
  await page.click('#dataset-tab-columns-link');
  await page.waitForTimeout(300);
  ok(await shown(page, '#dataset-tab-columns') && !(await shown(page, '#dataset-tab-about')), 'the Columns tab switches in place (Bootstrap, no reload)');
  if (tag === 'desk') {
    ok(await shown(page, '#dataset-columns-table') && !(await shown(page, '#dataset-columns-cards')), 'at 1280 the columns are a table');
  } else {
    ok(await shown(page, '#dataset-columns-cards') && !(await shown(page, '#dataset-columns-table')), 'at 375 the columns are cards');
    const strip = await page.evaluate(() => { const e = document.getElementById('dataset-tabs'); return { sw: e.scrollWidth, cw: e.clientWidth }; });
    ok(strip.sw > strip.cw && (await overflow(page)).sw === 375, `the eight tabs scroll as one strip (${strip.sw} in ${strip.cw}); the page never does`);
  }
  await page.screenshot({ path: `${SHOTS}/${tag}-dataset-columns.png`, fullPage: true });
  await page.click('#dataset-tab-usage-link');
  await page.waitForTimeout(300);
  ok(await shown(page, '#usage-strip') && (await page.locator('#usage-strip .usage-cell').count()) === 30, 'the Usage tab: a strip of 30 cells');
  await page.click('#dataset-more-btn');
  ok(await shown(page, '#dataset-more-menu') && await shown(page, '#dataset-edit-link') && await shown(page, '#dataset-retire-btn'), 'the ⋯ menu opens: Edit, Tag, Deprecate, Retire');
  const box = await page.locator('#dataset-more-menu').boundingBox();
  ok(box.x >= 0 && box.x + box.width <= viewport.width, `…inside the screen (${Math.round(box.x)}–${Math.round(box.x + box.width)})`);
  const small = await page.evaluate(() => [...document.querySelectorAll('#dataset-header-actions .btn, #dataset-header-actions summary, #dataset-tabs .nav-link')]
    .filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height < 44; }).map((a) => a.id || a.textContent.trim()));
  ok(small.length === 0, 'the header\'s buttons and the tabs are at least 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/${tag}-dataset-more.png` });
  await ctx.close();
}

console.log('1280 x 800 — HTMX, the tag field, the record picker');
{
  const { ctx, page, errors } = await session(27, { width: 1280, height: 800 });
  await page.goto(BASE + '/browse', { waitUntil: 'networkidle' });
  await page.click(`#browse-card-${orders} a`);
  await page.waitForFunction(() => document.getElementById('page-content')?.dataset.screen === 'dataset-view');
  ok((await page.title()).startsWith('SMOKE Sales orders') && page.url().includes('/datasets/curated.sales_orders') && await page.evaluate(() => document.querySelector('#nav-browse .nxl-link')?.classList.contains('active')),
    'a Browse card opens the dataset by HTMX: the title, the URL by key, Browse highlighted');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.datasetKey) === 'curated.sales_orders', 'the page stamps its dataset key (the command bar\'s context)');
  await page.fill('#dataset-tag-field-tag', 'Month End Close');
  await page.waitForTimeout(100);
  ok((await page.locator('#dataset-tag-preview').innerText()).includes('#month-end-close'), 'the tag field previews the slug it will use: ' + (await page.locator('#dataset-tag-preview').innerText()));
  await page.goto(BASE + `/datasets/${orders}/edit`, { waitUntil: 'networkidle' });
  await page.click('#dataset-form-field-steward-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-results .record-picker-row');
  const rows = await page.locator('#record-picker-results .record-picker-row').allInnerTexts();
  ok(rows.some((r) => r.includes('SMOKE Marco')) && !rows.some((r) => r.includes('SMOKE Lee')), 'the steward picker opens and lists who may steward (Marco, not Lee)');
  await page.locator('#record-picker-results .record-picker-row', { hasText: 'SMOKE Dana' }).first().click().catch(async () => {
    await page.locator('#record-picker-results .record-picker-row').first().click();
  });
  await page.waitForTimeout(300);
  ok((await page.inputValue('#dataset-form-field-steward')) !== '', 'choosing a row fills the steward field');
  await page.screenshot({ path: `${SHOTS}/desk-dataset-edit-picker.png` });
  await page.goto(BASE + '/glossary/new', { waitUntil: 'networkidle' });
  await page.fill('#term-form-field-synonyms', 'turnover, top line');
  await page.waitForTimeout(100);
  ok((await page.locator('#term-form-synonyms-preview .tag-chip').count()) === 2, 'the synonyms field shows its values as chips');
  ok(errors.length === 0, 'no console error' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

console.log('375 x 740 — JavaScript off');
{
  const { ctx, page, errors } = await session(27, { width: 375, height: 740 }, { javaScriptEnabled: false });
  await page.goto(BASE + `/datasets/${orders}?tab=columns`, { waitUntil: 'load' });
  ok(await shown(page, '#dataset-tab-columns') && !(await shown(page, '#dataset-tab-about')) && await shown(page, '#dataset-columns-cards'), '?tab=columns renders the Columns pane server-side');
  await page.click('#dataset-tab-history-link');
  await page.waitForLoadState('load');
  ok(page.url().includes('tab=history') && await shown(page, '#dataset-tab-history'), 'a tab is a plain link: History reloads on its pane');
  await page.click('#dataset-more-btn');
  ok(await shown(page, '#dataset-more-menu'), 'the ⋯ menu opens without JavaScript (a <details>)');
  sql(`DELETE FROM dataset_follows WHERE member_id = 27 AND dataset_id = ${orders}`);
  await page.goto(BASE + `/datasets/${orders}`, { waitUntil: 'load' });
  await page.click('#follow-btn');
  await page.waitForLoadState('load');
  ok(page.url().includes('notice=followed') && await shown(page, '#notice-banner') && /unfollow/i.test(await page.locator('#follow-btn').innerText()), 'Follow is a plain form: it lands back on the page with its notice, the button now Unfollow');
  await page.click('#follow-btn');
  await page.waitForLoadState('load');
  ok(/^\s*follow\s*$/i.test(await page.locator('#follow-btn').innerText()) && page.url().includes('notice=unfollowed'), '…and Unfollow too');
  await page.goto(BASE + '/browse', { waitUntil: 'load' });
  ok(await shown(page, `#browse-card-${orders}`) && (await overflow(page)).sw === 375, 'Browse is whole without JavaScript');
  await page.goto(BASE + `/datasets/${orders}/edit`, { waitUntil: 'load' });
  ok(await shown(page, '#dataset-form') && await shown(page, '#dataset-form-save-btn'), 'the edit form is whole without JavaScript');
  await page.screenshot({ path: `${SHOTS}/phone-nojs-dataset-edit.png`, fullPage: true });
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}

console.log('375 x 740 — a Member\'s stub');
{
  const { ctx, page, errors } = await session(26, { width: 375, height: 740 });
  await page.goto(BASE + '/datasets/curated.payroll', { waitUntil: 'networkidle' });
  ok(await shown(page, '#dataset-stub') && await shown(page, '#dataset-stub-request-btn') && (await page.locator('#dataset-tabs').count()) === 0, 'Priya: the payroll is a stub — Request access, no tabs');
  const b = await page.locator('#dataset-stub-request-btn').boundingBox();
  ok(b.height >= 44 && b.x + b.width <= 375 && (await overflow(page)).sw === 375, 'the Request access button is a touch target inside the screen');
  await page.screenshot({ path: `${SHOTS}/phone-dataset-stub.png`, fullPage: true });
  ok(errors.length === 0, 'no console error');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
