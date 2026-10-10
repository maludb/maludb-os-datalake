// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport; the bottom tab bar (Home · Search · Ask · Me) on a phone and the sidebar on a
//   desktop; the offcanvas from the menu button and from Me; the one button for a new person on the empty lake; the home's search box with
//   Search and Ask and its tiles once the lake holds datasets (popular through the wall, each unbuilt tile naming its slice); the header
//   search on a desktop and the magnifier on a phone; a stub card for the confidential dataset; the placeholders reached by HTMX (title,
//   highlight, context stamp); the bell; the command bar answering the fake kernel and following a navigate; My settings saving; a token
//   and a key minted and shown once, the key rotated and revoked; every control >= 44 px; no console errors; the home whole with JavaScript
//   off; the web app manifest installable.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9001';
const SHOTS = process.env.SHOTS || '/tmp/dl-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.datalake.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get member() { return mint(26); }, get engineer() { return mint(27); }, get analyst() { return mint(30); }, get admin() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]), ' + s + ' select, ' + s + ' textarea, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: a new Member (Priya) on the empty lake — the first-run home ----
{
  const { ctx, page, errors } = await session(urls.member, { width: 375, height: 740 });
  console.log('375 x 740 — a new Member, the empty lake');
  ok(page.url() === BASE + '/', 'signed on and landed on Home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#tab-bar') && !(await shown(page, '#left-sidenav')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#tab-bar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = (await page.locator('#tab-bar .app-tab').allInnerTexts()).map((t) => t.trim());
  ok(tabs.join(',') === 'Home,Search,Ask,Me', `four tabs: ${tabs.join(', ')}`);
  const small = await smallControls(page, '#tab-bar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-first-run') && await shown(page, '#home-first-run-btn') && /upload a spreadsheet/i.test(await page.locator('#home-first-run-btn').innerText()), 'a new person sees the one button, Upload a spreadsheet');
  const btn = await page.locator('#home-first-run-btn').boundingBox();
  ok(btn.height >= 44 && btn.x >= 0 && btn.x + btn.width <= 375, `the button is ${Math.round(btn.height)} px tall and inside the screen (right edge ${Math.round(btn.x + btn.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Member'), 'the header badge reads Member');
  ok(await shown(page, '#header-search-link') && !(await shown(page, '#header-search')), 'on a phone the header carries the search magnifier, not the box');
  await page.screenshot({ path: `${SHOTS}/phone-home-first-run.png` });
  await page.click('#tab-me');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#left-sidenav') && (await page.locator('#nav-browse').count()) === 1 && (await page.locator('#nav-glossary').count()) === 1 && (await page.locator('#nav-sql').count()) === 0 && (await page.locator('#nav-admin').count()) === 0,
    'Me opens the sidebar: Browse … Glossary and Me; no SQL and no Admin for a Member');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-menu .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#left-sidenav')), 'the menu button closes it again');
  await page.click('#tab-ask');
  await page.waitForURL(BASE + '/ask');
  await page.waitForSelector('#ask-coming');
  ok((await page.title()).startsWith('Ask') && (await page.locator('#ask-coming').innerText()).includes('Slice 10'), 'the Ask tab opens its placeholder (slice 10), title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#tab-ask').classList.contains('active') && !document.querySelector('#tab-home').classList.contains('active') && document.getElementById('page-content').dataset.screen === 'ask'),
    'the tab is highlighted and the screen stamped ask for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the placeholder');
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-settings.png`, fullPage: true });
  await page.uncheck('#prefs-field-email');
  await page.check('#prefs-field-kind-schema_changed');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-email')) && (await page.isChecked('#prefs-field-kind-schema_changed')), 'email stayed off and "a dataset I follow changes its columns" is on');
  for (const p of ['/activity', '/notifications', '/settings/tokens/', '/search']) {
    await page.goto(BASE + p, { waitUntil: 'networkidle' });
    ok((await overflow(page)).sw === 375, 'no sideways scroll on ' + p);
  }
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send-btn')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'Which dataset has last month\'s orders?');
  ok(await shown(page, '#assistant-send-btn'), 'and shown once something is typed');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- the SMOKE lake exists from here ----
const seed = JSON.parse(execFileSync('php', [new URL('./seed.php', import.meta.url).pathname], { encoding: 'utf8' }).trim());
console.log(`seeded the lake: the orders ${seed['curated.sales_orders']}, the revenue ${seed['product.monthly_revenue']}, the payroll ${seed['curated.payroll']}`);

// ---- phone: the Member with a lake ----
{
  const { ctx, page, errors } = await session(urls.member, { width: 375, height: 740 });
  console.log('375 x 740 — a Member, the lake holds datasets');
  ok((await page.locator('#home-first-run').count()) === 0 && await shown(page, '#home-search') && await shown(page, '#home-search-btn') && await shown(page, '#home-ask-btn'), 'the first-run card is gone; the search box with Search and Ask leads the home');
  ok(await shown(page, '#home-popular') && (await page.locator('#home-popular .dataset-card').count()) === 2 && (await page.locator('#home-popular-' + seed['curated.payroll']).count()) === 0, 'popular this month: two datasets — not the payroll she may not see');
  ok((await page.locator('#home-products-pending').innerText()).includes('slice 7') && await shown(page, '#home-incidents-pending'), 'the tiles not built yet say which slice builds them');
  ok((await overflow(page)).sw === 375, 'no sideways scroll with the tiles');
  const cardW = (await page.locator('#home-popular').boundingBox()).width;
  ok(cardW >= 300 && cardW <= 375, `the popular tile takes the width of the phone (${Math.round(cardW)} px)`);
  const smallH = await smallControls(page, '#home-search');
  ok(smallH.length === 0, 'the search box\'s controls are at least 44 px' + (smallH.length ? ': ' + smallH.join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-home.png`, fullPage: true });
  await page.fill('#home-search-q', 'payroll');
  await page.click('#home-search-btn');
  await page.waitForURL(/\/search\?q=payroll/);
  ok(await shown(page, '#search-result-dataset-' + seed['curated.payroll']) && /request access/i.test(await page.locator('#search-result-dataset-' + seed['curated.payroll'] + '-request').innerText()), 'the payroll is a locked card with Request access');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the results');
  await page.screenshot({ path: `${SHOTS}/phone-search-stub.png`, fullPage: true });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.click('#header-search-link');
  await page.waitForURL(BASE + '/search');
  await page.fill('#search-field-q', 'sales orders');
  await page.click('#search-submit-btn');
  await page.waitForSelector('#search-result-dataset-' + seed['curated.sales_orders']);
  ok(page.url().startsWith(BASE + '/search?') && (await page.locator('#search-result-dataset-' + seed['curated.sales_orders'] + ' .dataset-key').innerText()) === 'curated.sales_orders', 'the magnifier opens the search; "sales orders" finds the dataset by its key\'s words');
  await page.screenshot({ path: `${SHOTS}/phone-search.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#tab-bar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-admin') && await shown(page, '#nav-sql') && await shown(page, '#nav-steward'), 'the sidebar is shown, with SQL, Steward and Admin');
  ok(await shown(page, '#header-search') && await shown(page, '#header-ask') && !(await shown(page, '#header-search-link')), 'the header carries the search box with Search and Ask on a desktop');
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  ok(await shown(page, '#home-admin-pending') && await shown(page, '#home-engineer-pending'), 'the admin sees the engine and engineer tiles, each saying slice 10 builds it');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.fill('#header-search', 'revenue');
  await page.press('#header-search', 'Enter');
  await page.waitForSelector('#search-result-dataset-' + seed['product.monthly_revenue']);
  ok(page.url().startsWith(BASE + '/search?q=revenue') && (await page.title()).startsWith('Search'), 'the header box searches: /search?q=revenue');
  ok((await page.locator('#search-result-dataset-' + seed['product.monthly_revenue'] + ' .badge.text-bg-success:not(.zone-chip)').innerText()).includes('Certified'), 'the certified product carries its badge');
  await page.screenshot({ path: `${SHOTS}/desktop-search.png` });
  await page.click('#nav-quality .nxl-link');
  await page.waitForURL(BASE + '/quality');
  await page.waitForSelector('#quality-coming');
  ok((await page.title()).startsWith('Quality') && (await page.locator('#quality-coming').innerText()).includes('Slice 5'), 'HTMX navigation: /quality pushed, the placeholder names slice 5');
  ok(await page.evaluate(() => document.querySelector('#nav-quality .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Home');
  await page.goBack();
  await page.waitForURL(/\/search\?q=revenue/);
  ok(true, 'the browser back button returns to the results');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'SMOKE proof token');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  await page.fill('#key-form-field-label', 'SMOKE proof key');
  await page.fill('#key-form-field-minute', '5');
  await page.check('#key-form-field-sql');
  await page.click('#key-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('dlk_') && (await page.locator('#tokens-minted').innerText()).includes('API key'), 'minting an API key shows it once (dlk_…)');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  const keyId = await page.evaluate(() => [...document.querySelectorAll('#key-list [id^="key-"][id$="-rotate-btn"]')].map((b) => b.id.split('-')[1])[0]);
  ok(await shown(page, `#key-${keyId}-sql-badge`) && (await page.locator(`#key-${keyId}`).innerText()).includes('5 a minute'), 'the key is listed with its rate and the SQL badge');
  page.once('dialog', (d) => d.accept());
  await page.click(`#key-${keyId}-rotate-btn`);
  await page.waitForSelector(`#key-${keyId}-replaced`);
  ok((await page.locator('#tokens-minted').innerText()).includes('keeps working for 24 hours') && await shown(page, `#key-${keyId}-replaced`), 'rotating it shows the new key once and marks the old one replaced, ending in 24 hours');
  const newId = await page.evaluate((old) => [...document.querySelectorAll('#key-list [id^="key-"][id$="-rotate-btn"]')].map((b) => b.id.split('-')[1]).find((i) => i !== old), keyId);
  page.once('dialog', (d) => d.accept());
  ok(newId !== undefined, 'the new key is listed beside the old one (key ' + newId + ')');
  await page.click(`#key-${newId}-revoke-btn`);
  await page.waitForSelector(`#key-${newId} .badge:has-text("revoked")`);
  ok(true, 'revoking the new key (with the confirm) marks it revoked');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-"][id$="-revoke-btn"]');
  await page.waitForSelector('#token-list .badge:has-text("revoked")');
  ok((await page.locator('#token-list .badge:has-text("revoked")').count()) >= 1, 'revoking the token marks it revoked');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#bell').count()) === 1 && (await page.locator('#bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening your keys.', actions: [], navigate: '/settings/tokens/' }; return s; });
  await page.fill('#assistant-input', 'open my keys');
  await page.click('#assistant-send-btn');
  await page.waitForURL(BASE + '/settings/tokens/');
  await page.waitForSelector('#key-list');
  ok(page.url() === BASE + '/settings/tokens/', 'a navigate answer from the command bar opened /settings/tokens/');
  kernelState((s) => { delete s.chat; return s; });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the Data engineer ----
{
  const { ctx, page, errors } = await session(urls.engineer, { width: 1280, height: 800 });
  console.log('1280 x 800 — the Data engineer');
  ok(await shown(page, '#nav-sources') && await shown(page, '#nav-loads') && !(await shown(page, '#nav-admin')), 'Sources and Loads in the sidebar, no Admin');
  ok(await shown(page, '#home-engineer-pending') && !(await shown(page, '#home-admin')), 'the engineer\'s tile (slice 10), not the admin\'s');
  ok((await overflow(page)).sw === 1280, 'no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/desktop-engineer-home.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.member, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(text.includes('SMOKE Sales orders') && text.includes('Popular this month') && text.includes('slice 7'), 'popular this month and the tiles\' lines are in the server-rendered page');
  ok((await page.locator('#tab-ask').getAttribute('href')) === '/ask' && (await page.locator('#tab-me').getAttribute('href')) === '/settings/', 'the tabs are plain links (Me opens My settings)');
  ok((await page.locator('a[href="/datasets/curated.sales_orders?back=%2F"]').count()) >= 1, 'a dataset is a plain link carrying ?back=');
  ok((await page.locator('#home-search').getAttribute('action')) === '/search' && (await page.locator('#home-ask-btn').getAttribute('formaction')) === '/ask', 'the search box is a plain GET form whose Ask button posts to /ask');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.member, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Data Lake', 'standalone, start_url /, named Data Lake');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
