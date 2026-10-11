// Headless Chromium proof of slice 3 — the SQL editor, saved queries, a run's page and exports (query-service-editor.md "Proof" 11): at
// 1280 x 800 and 375 x 740, Dana (an Analyst) types in the editor — the completion offers her datasets' keys, Tab takes one — Ctrl+Enter runs
// it, the result comes back with the email column "masked for you" and quick stats (a table at 1280, records at 375), Explain shows the plan,
// a long cross join is stopped by Cancel; the six screens 200, stamped, never scrolling sideways, no console error, a screenshot each; without
// JavaScript the editor's form posts and the page comes back with the result.
// Run through tests/phase3/slice3/run.sh (servers, environment, the slice's lake). Playwright from the kernel's web/node_modules.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots/slice3';
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
const LONG = 'SELECT sum(a.id * b.id) FROM curated.big_events a, curated.big_events b WHERE a.kind <> b.kind';

const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 1, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  await page.mouse.move(viewport.width - 20, viewport.height - 120);      // off the menu (the theme widens it under the pointer)
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const stamp = (page) => page.evaluate(() => document.getElementById('page-content')?.dataset.screen || '');

const qid = sql("SELECT max(id) FROM saved_queries WHERE deleted_at IS NULL AND owner_member_id = 30 AND shared AND sql LIKE '%$region%'");
const run = sql("SELECT max(id) FROM query_runs WHERE caller_member_id = 30 AND status = 'ok'");
ok(qid !== '' && run !== '', `the earlier proofs left Dana a shared saved query (${qid}) and runs (${run})`);

for (const [label, viewport, t] of [['1280 x 800', { width: 1280, height: 800 }, 'desk'], ['375 x 740', { width: 375, height: 740 }, 'phone']]) {
  console.log(`${label} — the editor (Dana, an Analyst)`);
  const { ctx, page, errors } = await session(30, viewport);
  await page.goto(BASE + '/sql', { waitUntil: 'networkidle' });
  ok(await stamp(page) === 'sql' && await shown(page, '#sql-editor') && await shown(page, '#sql-run') && await shown(page, '#sql-catalog'), 'the editor, Run and my datasets');
  await page.fill('#sql-editor', '');
  await page.type('#sql-editor', 'SELECT order_id, customer_email, region FROM curated.sales_o');
  await page.waitForSelector('#sql-suggest:not(.d-none) .sql-suggestion', { timeout: 5000 }).catch(() => {});
  const sugg = await page.locator('#sql-suggest .sql-suggestion').allInnerTexts();
  ok(sugg.some((s) => s.startsWith('curated.sales_orders')), `completion offers her dataset: ${sugg.slice(0, 3).join(' | ')}`);
  await page.keyboard.press('Tab');
  await page.type('#sql-editor', ' ORDER BY order_id LIMIT 20');
  ok((await page.inputValue('#sql-editor')).includes('FROM curated.sales_orders ORDER BY'), 'Tab takes the suggestion');
  await page.screenshot({ path: `${SHOTS}/${t}-editor.png`, fullPage: true });
  await page.focus('#sql-editor');
  await page.keyboard.press('Control+Enter');
  await page.waitForSelector('#sql-result-card', { timeout: 30000 });
  ok(/20 rows/.test(await page.locator('#sql-row-count').innerText()), 'Ctrl+Enter runs it: 20 rows');
  if (t === 'desk') {
    ok(await shown(page, '#sql-result-table') && await shown(page, '#sql-masked-customer_email') && await shown(page, '#sql-stats-region'), 'a table: the email column marked "masked for you", quick stats under each header');
  } else {
    ok(await shown(page, '#sql-records') && await shown(page, '#sql-record-1') && !(await shown(page, '#sql-result-table')), 'at 375 the rows are records, not a table');
  }
  ok(await shown(page, '#sql-run-link'), 'the run is linked');
  ok(await overflow(page), 'nothing scrolls sideways');
  await page.mouse.move(viewport.width - 20, viewport.height - 120);
  await page.screenshot({ path: `${SHOTS}/${t}-result.png`, fullPage: true });
  await page.click('#sql-explain');
  await page.waitForSelector('#sql-plan', { timeout: 30000 });
  ok(/GROUP_BY|PROJECTION|TABLE_SCAN|SEQ_SCAN|ORDER_BY|TOP_N/.test(await page.locator('#sql-plan').innerText()), 'Explain shows the plan (a dry run)');
  await page.fill('#sql-editor', LONG);
  await page.click('#sql-run');
  await page.waitForSelector('#sql-cancel', { state: 'visible', timeout: 5000 });
  await page.waitForTimeout(800);
  const t0 = Date.now();
  await page.click('#sql-cancel');
  await page.waitForSelector('#sql-refusal-rule[data-rule="cancelled"]', { timeout: 10000 }).catch(() => {});
  ok(await page.locator('#sql-refusal-rule[data-rule="cancelled"]').count() === 1 && Date.now() - t0 < 4000, `Cancel stops the long query (${Date.now() - t0} ms): "Cancelled"`);
  ok(!(await shown(page, '#sql-cancel')), 'Cancel hides once the run has answered');
  await page.screenshot({ path: `${SHOTS}/${t}-cancelled.png`, fullPage: true });
  for (const [url, screen, sel] of [['/queries', 'queries', `#query-card-${qid}`], [`/queries/${qid}`, 'query-view', '#query-view-sql'], [`/queries/${qid}/edit`, 'query-edit', '#query-edit-form'],
                                    [`/runs/${run}`, 'query-run-view', '#run-view'], ['/exports', 'exports', '#exports-content']]) {
    const r = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    ok(r.status() === 200 && await stamp(page) === screen && await shown(page, sel) && await overflow(page), `${url}: 200, stamped ${screen}, ${sel} shown, no sideways scroll`);
    await page.screenshot({ path: `${SHOTS}/${t}-${screen}.png`, fullPage: true });
  }
  await page.goto(BASE + `/queries/${qid}`, { waitUntil: 'networkidle' });
  await page.fill('#query-form-field-region', 'AMER');
  await page.click('#query-run-btn');
  await page.waitForSelector('#query-result #sql-result-card', { timeout: 30000 });
  ok(/1 row/.test(await page.locator('#query-result #sql-row-count').innerText()), 'a saved query run from its page: its parameter, the result only');
  ok(errors.length === 0, 'no console error' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : ''));
  await ctx.close();
}

console.log('Without JavaScript — the editor\'s form posts, the page comes back with the result');
{
  const { ctx, page } = await session(30, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  await page.goto(BASE + '/sql?dataset=curated.big_events', { waitUntil: 'load' });
  ok((await page.inputValue('#sql-editor')).startsWith('SELECT * FROM curated.big_events LIMIT 100'), 'the dataset\'s Query this prefill');
  await page.click('#sql-run');
  await page.waitForLoadState('load');
  ok(await shown(page, '#sql-result-table') && /100 rows/.test(await page.locator('#sql-row-count').innerText()), 'the whole screen with the result (100 rows)');
  await page.screenshot({ path: `${SHOTS}/nojs-result.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
