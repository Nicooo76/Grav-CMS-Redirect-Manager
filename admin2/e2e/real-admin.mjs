/**
 * Loads the built bundles into the real Admin 2 of the Grav 2 test site
 * (http://127.0.0.1:8088). Credentials come from _testsite/.test-credentials and
 * are never printed. The plugin's REST routes may not exist yet: the UI must
 * then show a clean error state instead of breaking.
 *   node e2e/real-admin.mjs
 */
import { chromium } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'screenshots');
mkdirSync(out, { recursive: true });
const credFile = join(root, '..', '..', '_testsite', '.test-credentials');
const cred = Object.fromEntries(readFileSync(credFile, 'utf8').split('\n').filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')).trim(), l.slice(l.indexOf('=') + 1).trim()]));
const BASE = cred.GRAV_URL || 'http://127.0.0.1:8088';

const browser = await chromium.launch();
const report = [];
const say = (name, ok, extra = '') => {
  report.push({ name, ok, extra });
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${extra ? '  ' + extra : ''}`);
};

for (const theme of ['light', 'dark']) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: theme });
  const page = await ctx.newPage();
  const errors = [];
  const api404 = new Set();
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text().slice(0, 300));
  });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('response', (r) => {
    if (r.status() >= 400 && r.url().includes('/redirects')) api404.add(`${r.status()} ${new URL(r.url()).pathname.replace(/^.*\/api\/v1/, '')}`);
  });
  const applyTheme = () => page.evaluate((t) => document.documentElement.classList.toggle('dark', t === 'dark'), theme);
  const pageEl = () => page.locator('grav-redirect-manager--page');

  await page.goto(`${BASE}/admin`);
  await page.waitForSelector('input', { timeout: 20000 });
  await page.locator('input[type=text], input[name=username], input[autocomplete=username]').first().fill(cred.GRAV_ADMIN_USER);
  await page.locator('input[type=password]').first().fill(cred.GRAV_ADMIN_PASS);
  await page.locator('button[type=submit]').first().click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(2500);

  /* dashboard widget */
  await page.goto(`${BASE}/admin/`);
  await page.waitForTimeout(3500);
  await applyTheme();
  const widget = page.locator('[class], div').locator('grav-widget-redirect-manager-overview');
  const widgetThere = (await widget.count()) > 0;
  say(`[${theme}] dashboard widget element mounted`, widgetThere);
  if (widgetThere) {
    await widget.first().scrollIntoViewIfNeeded();
    await page.waitForTimeout(600);
    const box = await widget.first().boundingBox();
    const text = await widget.first().evaluate((el) => Array.from(el.shadowRoot?.querySelectorAll('h2, h3, p, a, button') ?? []).map((n) => n.textContent.trim()).filter(Boolean).join(' | ').slice(0, 160));
    // either the figures (API present) or a clean error state (API missing); never a blank card
    say(`[${theme}] widget renders figures or a clean error state`, /could not load|try again|404s|suggestions/i.test(text), text);
    await page.screenshot({ path: join(out, `real-widget-${theme}.png`) });
    void box;
  }

  /* page */
  await page.goto(`${BASE}/admin/plugin/redirect-manager`);
  await page.waitForTimeout(3500);
  await applyTheme();
  await page.waitForTimeout(500);
  say(`[${theme}] page element mounted with shadow root`, await pageEl().evaluate((el) => !!el.shadowRoot));
  const btnBg = await pageEl().evaluate((el) => getComputedStyle(el.shadowRoot.querySelector('.btn.primary')).backgroundColor);
  say(`[${theme}] primary button has a fill from the host token`, btnBg !== 'rgba(0, 0, 0, 0)', btnBg);
  const fontFam = await pageEl().evaluate((el) => getComputedStyle(el.shadowRoot.querySelector('.tab')).fontFamily);
  say(`[${theme}] inherits the host font`, /Google Sans|Inter|system-ui/.test(fontFam), fontFam.slice(0, 40));
  await page.screenshot({ path: join(out, `real-page-${theme}.png`) });

  /* tabs and hash routing inside the host SPA */
  await pageEl().locator('nav.tabs a', { hasText: '404 monitor' }).click();
  await page.waitForTimeout(700);
  say(`[${theme}] tab click sets the hash`, (await page.evaluate(() => location.hash)) === '#/404', await page.evaluate(() => location.hash));
  say(`[${theme}] host path unchanged by the hash`, (await page.evaluate(() => location.pathname)).endsWith('/plugin/redirect-manager'));
  await page.screenshot({ path: join(out, `real-404-${theme}.png`) });
  await pageEl().locator('nav.tabs a', { hasText: 'Tester' }).click();
  await page.waitForTimeout(500);
  await page.goBack();
  await page.waitForTimeout(700);
  say(`[${theme}] browser back returns to the previous tab`, (await page.evaluate(() => location.hash)) === '#/404', await page.evaluate(() => location.hash));
  say(`[${theme}] the host page is still mounted after back`, (await pageEl().count()) === 1);

  /* settings: real <grav-blueprint-form> */
  await page.evaluate(() => (location.hash = '#/settings'));
  await page.waitForTimeout(3500);
  const form = await pageEl().evaluate((el) => {
    // the form must live in the light DOM (host Tailwind styles do not reach into a shadow root) and be slotted in
    const f = el.querySelector(':scope > grav-blueprint-form[slot=rm-settings]');
    const slot = el.shadowRoot.querySelector('slot[name=rm-settings]');
    return { present: !!f && !!slot && slot.assignedElements().includes(f), defined: !!customElements.get('grav-blueprint-form'), inputs: f ? (f.shadowRoot ?? f).querySelectorAll('input, select, textarea, button').length : 0 };
  });
  say(`[${theme}] settings embeds the host blueprint form`, form.present && form.defined, JSON.stringify(form));
  await page.screenshot({ path: join(out, `real-settings-${theme}.png`) });

  /* editor slide-over above the host chrome; shortcuts; discard dialog */
  await page.evaluate(() => (location.hash = '#/rules'));
  await page.waitForTimeout(800);
  await page.locator('body').click({ position: { x: 700, y: 20 } });
  await page.keyboard.press('n');
  await page.waitForTimeout(700);
  const editorOpen = (await pageEl().locator('[role=dialog][aria-modal=true]').count()) === 1;
  say(`[${theme}] "n" opens the editor in the real host`, editorOpen);
  if (editorOpen) {
    const covers = await pageEl().evaluate((el) => {
      const panel = el.shadowRoot.querySelector('.so-panel');
      const r = panel.getBoundingClientRect();
      const top = document.elementFromPoint(r.left + 40, r.top + 200);
      const sidebarTop = document.elementFromPoint(60, 300);
      return { panelRight: Math.round(r.right), viewport: innerWidth, hitIsPlugin: top === el, sidebarCovered: sidebarTop === el };
    });
    say(`[${theme}] slide-over sits above the host header and content`, covers.hitIsPlugin, JSON.stringify(covers));
    await page.getByLabel('Source', { exact: true }).fill('/blog/2024/*');
    await page.getByLabel('Target', { exact: true }).fill('/journal/2024/$1');
    await page.waitForTimeout(800);
    await page.screenshot({ path: join(out, `real-editor-${theme}.png`) });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(700);
    const hostDialog = await page.getByText('Discard changes?').count();
    await page.screenshot({ path: join(out, `real-discard-${theme}.png`) });
    say(`[${theme}] discard confirmation uses the host dialog`, hostDialog > 0, `dialogs=${hostDialog}`);
    // keep editing via the host dialog's cancel button
    const cancel = page.getByRole('button', { name: /keep editing/i });
    if (await cancel.count()) await cancel.first().click();
    await page.waitForTimeout(500);
    const back = await pageEl().evaluate((el) => !!el.shadowRoot.activeElement?.closest?.('[role=dialog]'));
    say(`[${theme}] focus returns into the editor after the host dialog`, back);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    const discard = page.getByRole('button', { name: /discard changes/i });
    if (await discard.count()) await discard.first().click();
    await page.waitForTimeout(600);
  }

  /* host toast with an action button (undo) */
  await page.evaluate(() => window.__GRAV_TOAST.success('Deleted 3 rules', { duration: 20000, action: { label: 'Undo', onClick: () => (window.__undoClicked = true) } }));
  await page.waitForTimeout(700);
  const undoBtn = page.getByRole('button', { name: 'Undo' });
  say(`[${theme}] host toast renders the Undo action`, (await undoBtn.count()) > 0);
  await page.screenshot({ path: join(out, `real-toast-${theme}.png`) });
  if (await undoBtn.count()) {
    await undoBtn.first().click();
    say(`[${theme}] Undo action calls back`, await page.evaluate(() => window.__undoClicked === true));
  }

  /* every tab against whatever the backend answers: data or a clean error state, never a crash */
  for (const [hash, label] of [['#/rules', 'rules'], ['#/404', '404 monitor'], ['#/suggestions', 'suggestions'], ['#/tester?url=/alte-seite', 'tester'], ['#/import', 'import'], ['#/export', 'export']]) {
    await page.evaluate((h) => (location.hash = h), hash);
    await page.waitForTimeout(1800);
    const state = await pageEl().evaluate((el) => {
      const r = el.shadowRoot;
      return { error: r.querySelector('.empty[role=alert] h3')?.textContent ?? null, rows: r.querySelectorAll('tbody tr').length, boundary: !!r.textContent?.includes('could not be shown') };
    });
    say(`[${theme}] ${label} renders without a crash`, !state.boundary, state.error ? `error state: ${state.error}` : `rows=${state.rows}`);
  }

  /* optional contract smoke test that writes one rule and removes it again (RM_WRITE=1, light theme only) */
  if (process.env.RM_WRITE === '1' && theme === 'light') {
    await page.evaluate(() => (location.hash = '#/rules/new'));
    await page.waitForTimeout(900);
    await page.getByLabel('Source', { exact: true }).fill('/rm-ui-smoke-test/*');
    await page.getByRole('radio', { name: 'wildcard' }).click();
    await page.getByLabel('Target', { exact: true }).fill('/journal/$1');
    await page.waitForTimeout(1200);
    const preview = await pageEl().evaluate((el) => el.shadowRoot.querySelector('#pv-result')?.textContent?.replace(/\s+/g, ' ').trim());
    say(`[${theme}] live preview answers from the real API`, /journal\/example|→|redirects to/i.test(preview ?? '') || /example/.test(preview ?? ''), preview);
    await page.screenshot({ path: join(out, 'real-editor-preview-light.png') });
    await page.getByRole('button', { name: 'Create redirect' }).click();
    await page.waitForTimeout(1500);
    await page.evaluate(() => (location.hash = '#/rules?q=rm-ui-smoke-test'));
    await page.waitForTimeout(1500);
    const created = await pageEl().evaluate((el) => el.shadowRoot.querySelectorAll('tbody tr[data-id]').length);
    say(`[${theme}] created rule shows up in the list`, created === 1, `rows=${created}`);
    if (created === 1) {
      await pageEl().locator('tbody tr[data-id] button[aria-haspopup=menu]').first().click();
      await pageEl().locator('[role=menuitem]', { hasText: 'Delete' }).click();
      await page.waitForTimeout(1200);
      const left = await pageEl().evaluate((el) => el.shadowRoot.querySelectorAll('tbody tr[data-id]').length);
      say(`[${theme}] smoke-test rule deleted again`, left === 0, `rows=${left}`);
    }
  }

  say(`[${theme}] no console errors besides the missing API`, errors.length === 0, errors.slice(0, 3).join(' | '));
  console.log(`     missing API routes seen: ${[...api404].join(', ') || 'none'}`);
  await ctx.close();
}
await browser.close();
process.exit(report.some((r) => !r.ok) ? 1 : 0);
