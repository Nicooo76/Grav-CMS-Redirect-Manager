/**
 * Behavioural checks against the dev harness (mock API): inline edit, toggles,
 * bulk actions, delete + undo, reorder (keyboard + pointer), shortcuts, editor
 * dirty guard, virtualisation with 10,000 rules, URL state.
 *   node e2e/interact.mjs            (starts its own Vite server)
 *   RM_URL=http://localhost:5199 node e2e/interact.mjs
 */
import { chromium } from '@playwright/test';
import { createServer } from 'vite';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
let server = null;
let base = process.env.RM_URL;
if (!base) {
  server = await createServer({ root, configFile: join(root, 'vite.dev.config.ts'), server: { port: 5191, strictPort: false }, logLevel: 'error' });
  await server.listen();
  base = server.resolvedUrls.local[0].replace(/\/$/, '');
}
const browser = await chromium.launch();
let failed = 0;
const check = (name, cond, extra = '') => {
  if (!cond) failed++;
  console.log(`${cond ? 'ok  ' : 'FAIL'} ${name}${extra ? '  ' + extra : ''}`);
};

async function open(hash = '#/rules', query = '') {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && !/Failed to load resource/.test(m.text()) && errors.push(m.text()));
  await page.goto(`${base}/dev/index.html?chrome=0&latency=0${query}${hash}`);
  await page.waitForSelector('grav-redirect-manager--page tbody tr[data-id]', { timeout: 8000 });
  return { ctx, page, errors };
}
const rows = (page) => page.locator('grav-redirect-manager--page tbody tr[data-id]');
const rowIds = (page) => rows(page).evaluateAll((els) => els.map((e) => e.dataset.id));

/* ---- inline edit, toggle, rollback ---- */
{
  const { ctx, page, errors } = await open();
  const first = rows(page).first();
  const id = await first.getAttribute('data-id');
  await first.locator('.cell-edit', { has: page.locator('.mono') }).first().click();
  const input = first.locator('input.input');
  await input.fill('/inline-edited');
  await input.press('Enter');
  await page.waitForTimeout(300);
  check('inline edit saves target with Enter', (await first.textContent()).includes('/inline-edited'));
  const stored = await page.evaluate((i) => window.__RM_MOCK.state.rules.find((r) => r.id === i).target, id);
  check('inline edit reached the API', stored === '/inline-edited', stored);

  await first.locator('.cell-edit.code').click();
  await first.locator('select').selectOption('302');
  await page.waitForTimeout(300);
  check('inline status change saves', (await first.locator('.c-code').textContent()).includes('302'));

  await first.locator('.cell-edit', { has: page.locator('.mono') }).first().click();
  await first.locator('input.input').fill('/never');
  await first.locator('input.input').press('Escape');
  check('Esc cancels the inline edit', !(await first.textContent()).includes('/never'));

  const sw = first.getByRole('switch');
  const before = await sw.getAttribute('aria-checked');
  await sw.click();
  check('toggle flips optimistically', (await sw.getAttribute('aria-checked')) !== before);

  // rollback: make the next PATCH fail
  await page.evaluate(() => {
    const orig = window.fetch;
    window.fetch = (u, o) => (o?.method === 'PATCH' ? Promise.resolve(new Response(JSON.stringify({ status: 422, title: 'Nope', detail: 'Target host is not allowed', errors: [{ field: 'target', code: 'unsafe_target', message: 'Target host is not allowed', severity: 'error' }] }), { status: 422, headers: { 'Content-Type': 'application/problem+json' } })) : orig(u, o));
  });
  await first.locator('.cell-edit', { has: page.locator('.mono') }).first().click();
  await first.locator('input.input').fill('https://evil.example');
  await first.locator('input.input').press('Enter');
  await page.waitForTimeout(400);
  check('failed PATCH rolls back', (await first.textContent()).includes('/inline-edited'));
  check('failed PATCH shows an error toast', (await page.locator('.fake-toast.error').count()) > 0);
  check('no page errors', errors.length === 0, errors.join('|'));
  await ctx.close();
}

/* ---- selection, bulk, delete + undo ---- */
{
  const { ctx, page } = await open();
  const ids = await rowIds(page);
  const boxes = page.locator('grav-redirect-manager--page tbody input[type=checkbox]');
  await boxes.nth(1).check();
  await boxes.nth(4).click({ modifiers: ['Shift'] });
  check('shift-click selects a range', (await page.locator('grav-redirect-manager--page .bulk .count').textContent()).includes('4'));
  await page.locator('grav-redirect-manager--page .bulk').getByRole('button', { name: 'Disable' }).click();
  await page.waitForTimeout(400);
  check('bulk disable reaches the API', (await page.evaluate((s) => s.map((i) => window.__RM_MOCK.state.rules.find((r) => r.id === i).enabled), ids.slice(1, 5))).every((v) => v === false));
  await page.locator('grav-redirect-manager--page .bulk').getByRole('button', { name: 'Delete' }).click();
  await page.waitForTimeout(300);
  const after = await rowIds(page);
  check('deleted rows disappear immediately', !ids.slice(1, 5).some((i) => after.includes(i)));
  const toast = page.locator('.fake-toast', { hasText: 'Deleted 4 rules' });
  check('undo toast is shown', (await toast.count()) === 1);
  await toast.getByRole('button', { name: 'Undo' }).click();
  await page.waitForTimeout(700);
  const restored = await rowIds(page);
  check('undo restores the rules', ids.slice(1, 5).every((i) => restored.includes(i)));
  await ctx.close();
}

/* ---- keyboard shortcuts + editor dirty guard ---- */
{
  const { ctx, page } = await open();
  await page.locator('body').click({ position: { x: 900, y: 12 } });
  await page.keyboard.press('/');
  const focused = await page.evaluate(() => document.querySelector('grav-redirect-manager--page').shadowRoot.activeElement?.getAttribute('role'));
  check('/ focuses the search box', focused === 'searchbox', String(focused));
  await page.keyboard.type('shop');
  await page.waitForTimeout(600);
  check('search is written to the URL', (await page.evaluate(() => location.hash)).includes('q=shop'));
  await page.keyboard.press('Escape');
  await page.locator('body').click({ position: { x: 900, y: 12 } });
  await page.keyboard.press('n');
  await page.waitForTimeout(400);
  check('n opens the editor', (await page.locator('grav-redirect-manager--page [role=dialog][aria-modal=true]').count()) === 1);
  const activeIsSource = await page.evaluate(() => document.querySelector('grav-redirect-manager--page').shadowRoot.activeElement?.id === 'rm-source');
  check('editor focuses the source field', activeIsSource);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  check('Esc closes a clean editor', (await page.locator('grav-redirect-manager--page [role=dialog][aria-modal=true]').count()) === 0);
  check('focus returns to the page after closing', await page.evaluate(() => !!document.querySelector('grav-redirect-manager--page').shadowRoot.activeElement || document.activeElement === document.body));
  await page.locator('body').click({ position: { x: 900, y: 12 } });
  await page.keyboard.press('n');
  await page.waitForTimeout(300);
  await page.keyboard.type('/typed');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('Esc with unsaved changes asks first (host dialog)', (await page.locator('.fake-dlg').count()) === 1);
  await page.locator('.fake-dlg button', { hasText: 'Keep editing' }).click();
  check('keep editing leaves the editor open', (await page.locator('grav-redirect-manager--page [role=dialog][aria-modal=true]').count()) === 1);
  await page.waitForTimeout(200);
  check('focus is back inside the editor after the dialog', await page.evaluate(() => !!document.querySelector('grav-redirect-manager--page').shadowRoot.activeElement?.closest('[role=dialog]')));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  await page.locator('.fake-dlg button', { hasText: 'Discard changes' }).click();
  await page.waitForTimeout(500);
  check('discard closes the editor', (await page.locator('grav-redirect-manager--page [role=dialog][aria-modal=true]').count()) === 0);
  await ctx.close();
}

/* ---- focus trap in the slide-over, Tab never leaves it ---- */
{
  const { ctx, page } = await open('#/rules/new');
  await page.waitForTimeout(500);
  let outside = 0;
  for (let i = 0; i < 70; i++) {
    await page.keyboard.press(i % 9 === 8 ? 'Shift+Tab' : 'Tab');
    const inside = await page.evaluate(() => {
      const sr = document.querySelector('grav-redirect-manager--page').shadowRoot;
      const a = sr.activeElement;
      return !!a?.closest('.so-panel') || !!document.querySelector('.fake-dlg');
    });
    if (!inside) outside++;
  }
  check('Tab and Shift+Tab stay inside the open slide-over', outside === 0, `left ${outside} times`);
  await ctx.close();
}

/* ---- Ctrl+Z restores the last deletion ---- */
{
  const { ctx, page } = await open();
  const ids = await rowIds(page);
  await rows(page).first().locator('button[aria-haspopup=menu]').click();
  await page.locator('grav-redirect-manager--page [role=menuitem]', { hasText: 'Delete' }).click();
  await page.waitForTimeout(400);
  check('row menu delete removes the row', !(await rowIds(page)).includes(ids[0]));
  await page.locator('body').click({ position: { x: 900, y: 12 } });
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(700);
  check('Ctrl+Z brings the deleted rule back', (await rowIds(page)).includes(ids[0]));
  await ctx.close();
}

/* ---- without host toast/dialog globals the in-page fallbacks work ---- */
{
  const { ctx, page } = await open('#/rules', '&nohost=1');
  const ids = await rowIds(page);
  await rows(page).first().locator('button[aria-haspopup=menu]').click();
  await page.locator('grav-redirect-manager--page [role=menuitem]', { hasText: 'Delete' }).click();
  await page.waitForTimeout(400);
  const toast = page.locator('grav-redirect-manager--page .toast', { hasText: 'Deleted 1 rule' });
  check('fallback toast appears with an Undo button', (await toast.count()) === 1 && (await toast.getByRole('button', { name: 'Undo' }).count()) === 1);
  await toast.getByRole('button', { name: 'Undo' }).click();
  await page.waitForTimeout(700);
  check('fallback Undo restores the rule', (await rowIds(page)).includes(ids[0]));
  await page.locator('body').click({ position: { x: 900, y: 12 } });
  await page.keyboard.press('n');
  await page.waitForTimeout(300);
  await page.keyboard.type('/x');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  check('fallback confirm dialog opens for unsaved changes', (await page.locator('grav-redirect-manager--page .dlg[role=alertdialog]').count()) === 1);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  check('Esc closes only the dialog, the editor stays', (await page.locator('grav-redirect-manager--page .dlg').count()) === 0 && (await page.locator('grav-redirect-manager--page .so-panel').count()) === 1);
  await ctx.close();
}

/* ---- the API token is re-read on every request ---- */
{
  const { ctx, page } = await open('#/rules');
  const tokens = await page.evaluate(async () => {
    const seen = [];
    const orig = window.fetch;
    window.fetch = (u, o) => { if (String(u).includes('/redirects/')) seen.push(o?.headers?.['X-API-Token']); return orig(u, o); };
    window.__GRAV_API_TOKEN = 'rotated-token-A';
    document.querySelector('grav-redirect-manager--page').shadowRoot.querySelector('.sort-btn').click();
    await new Promise((r) => setTimeout(r, 400));
    window.__GRAV_API_TOKEN = 'rotated-token-B';
    document.querySelector('grav-redirect-manager--page').shadowRoot.querySelectorAll('.sort-btn')[1].click();
    await new Promise((r) => setTimeout(r, 400));
    return seen;
  });
  check('the rotating token is picked up per request', tokens.includes('rotated-token-A') && tokens.includes('rotated-token-B'), tokens.join(','));
  await ctx.close();
}

/* ---- create through the editor ---- */
{
  const { ctx, page } = await open('#/rules/new');
  await page.waitForTimeout(400);
  await page.getByLabel('Source', { exact: true }).fill('/created-by-test');
  await page.getByLabel('Target', { exact: true }).fill('/somewhere');
  await page.waitForTimeout(600);
  await page.getByRole('button', { name: 'Create redirect' }).click();
  await page.waitForTimeout(700);
  const has = await page.evaluate(() => window.__RM_MOCK.state.rules.some((r) => r.source === '/created-by-test'));
  check('editor creates a rule', has);
  check('editor closes and returns to the list URL', (await page.evaluate(() => location.hash)).startsWith('#/rules') && !(await page.evaluate(() => location.hash)).includes('new'));
  await ctx.close();
}

/* ---- loop is blocked in the editor ---- */
{
  const { ctx, page } = await open('#/rules/new');
  await page.getByLabel('Source', { exact: true }).fill('/loop-a');
  await page.getByLabel('Target', { exact: true }).fill('/loop-a');
  await page.waitForTimeout(700);
  const alert = await page.locator('grav-redirect-manager--page [role=alert]').count();
  const disabled = await page.getByRole('button', { name: 'Create redirect' }).isDisabled();
  await page.keyboard.press('Control+Enter');
  await page.waitForTimeout(400);
  const exists = await page.evaluate(() => window.__RM_MOCK.state.rules.some((r) => r.source === '/loop-a'));
  check('a loop shows an error and blocks saving', alert > 0 && disabled && !exists, `alerts=${alert} disabled=${disabled} saved=${exists}`);
  await ctx.close();
}

/* ---- reorder: keyboard, menu, pointer ---- */
{
  const { ctx, page } = await open();
  const ids = await rowIds(page);
  const handle = rows(page).nth(1).locator('.drag');
  await handle.focus();
  await page.keyboard.press('ArrowDown');
  await page.waitForTimeout(600);
  const a = await rowIds(page);
  check('ArrowDown on the handle moves the row down', a[2] === ids[1] && a[1] === ids[2], a.slice(0, 4).join(','));
  check('handle keeps focus after the move', await page.evaluate(() => document.querySelector('grav-redirect-manager--page').shadowRoot.activeElement?.classList.contains('drag')));
  // the panels above the table push it down: bring the rows into the middle of the viewport before dragging
  await rows(page).nth(4).evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(200);
  const box = await rows(page).nth(2).locator('.drag').boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 20, { steps: 4 });
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 44 * 4, { steps: 8 });
  await page.mouse.up();
  await page.waitForTimeout(700);
  const b = await rowIds(page);
  check('pointer drag moves a row four places', b[6] === a[2], b.slice(0, 8).join(','));
  await page.getByRole('button', { name: 'Priority' }).count();
  await ctx.close();
}

/* ---- sorting disables reordering, URL state ---- */
{
  const { ctx, page } = await open();
  await page.locator('grav-redirect-manager--page th .sort-btn', { hasText: 'Source' }).click();
  await page.waitForTimeout(500);
  check('sorting by source updates the URL', (await page.evaluate(() => location.hash)).includes('sort=source'));
  check('drag handles are disabled off priority order', (await rows(page).first().locator('.drag').getAttribute('aria-disabled')) === 'true');
  await page.evaluate(() => (location.hash = '#/rules?q=shop&page=1'));
  await page.waitForTimeout(600);
  check('back/forward style hash changes drive the list', (await rows(page).count()) > 0 && (await page.evaluate(() => document.querySelector('grav-redirect-manager--page').shadowRoot.querySelector('input[role=searchbox]').value)) === 'shop');
  await ctx.close();
}

/* ---- 10,000 rows ---- */
{
  const { ctx, page } = await open('#/rules?per_page=10000', '&rows=10000');
  await page.waitForTimeout(800);
  const domRows = await rows(page).count();
  check('10,000 rules render as a window of rows', domRows > 10 && domRows < 80, `dom rows=${domRows}`);
  const t0 = Date.now();
  await page.evaluate(() => { const s = document.querySelector('grav-redirect-manager--page').shadowRoot.querySelector('.rules-scroll'); s.scrollTop = 200000; });
  await page.waitForTimeout(200);
  const domRows2 = await rows(page).count();
  const idx = await rows(page).first().getAttribute('aria-rowindex');
  check('scrolling deep keeps the DOM small', domRows2 < 80 && Number(idx) > 3000, `rows=${domRows2} firstIndex=${idx} ${Date.now() - t0}ms`);
  const frames = await page.evaluate(async () => {
    const s = document.querySelector('grav-redirect-manager--page').shadowRoot.querySelector('.rules-scroll');
    const times = [];
    let last = performance.now();
    return await new Promise((res) => {
      const step = () => {
        const now = performance.now();
        times.push(now - last);
        last = now;
        s.scrollTop += 900;
        if (times.length < 90) requestAnimationFrame(step);
        else {
          times.shift();
          times.sort((a, b) => a - b);
          res({ p50: Math.round(times[Math.floor(times.length * 0.5)]), p95: Math.round(times[Math.floor(times.length * 0.95)]), worst: Math.round(times[times.length - 1]) });
        }
      };
      requestAnimationFrame(step);
    });
  });
  check('scrolling 10,000 rows stays smooth (p95 frame < 40 ms)', frames.p95 < 40, `p50 ${frames.p50} ms, p95 ${frames.p95} ms, worst ${frames.worst} ms`);
  await ctx.close();
}

await browser.close();
await server?.close();
console.log(failed ? `\n${failed} check(s) failed` : '\nall checks passed');
process.exit(failed ? 1 : 0);
