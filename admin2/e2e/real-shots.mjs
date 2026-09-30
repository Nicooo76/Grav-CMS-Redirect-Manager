/**
 * Screenshots of every screen in the real Admin 2 (1440x900, light and dark) with the seeded dev-site data,
 * written to docs/screenshots/<name>-<theme>.png for the README.
 *   node e2e/real-shots.mjs [name ...]
 * Seed the site first (see the seed script) and do not run real-flows.mjs in between: it changes the data.
 */
import { launch, openAdmin, gotoPlugin, apiClient, root } from './lib/real.mjs';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const out = join(root, '..', 'docs', 'screenshots');
mkdirSync(out, { recursive: true });
const only = process.argv.slice(2);
const SAMPLES = process.env.RM_SAMPLES || new URL('./samples', import.meta.url).pathname;

const api = await apiClient();
// a rule with a chain and a conflict shows the editor's checks at their most useful
const chainRule = (await api('GET', '/redirects/rules?badge=chain&q=/faq&per_page=5')).data?.find((r) => r.source === '/faq') ?? (await api('GET', '/redirects/rules?badge=chain&per_page=1')).data[0];
const browser = await launch();
for (const theme of ['light', 'dark']) {
  const h = await openAdmin(browser, { theme, viewport: { width: 1440, height: 900 } });
  const { page, pageEl } = h;
  const P = () => pageEl();
  const wait = (ms) => page.waitForTimeout(ms);
  const shot = async (name) => {
    if (only.length && !only.includes(name)) return;
    if (name === 'rules' && theme === 'dark') return; // taken in the light pass (see below)
    await wait(500);
    await page.screenshot({ path: join(out, `${name}-${theme}.png`) });
    console.log(`${theme} ${name}`);
  };

  await gotoPlugin(h, '#/rules');
  await wait(1200);
  await shot('rules');
  if (theme === 'light' && (!only.length || only.includes('rules'))) {
    // Leaving the Rules tab after a few seconds marks the automatic redirects as seen for everyone, so the dark shot
    // is taken on this same visit: swap the theme in place.
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await wait(600);
    await page.screenshot({ path: join(out, 'rules-dark.png') });
    console.log('dark rules');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
  }

  await gotoPlugin(h, `#/rules/${chainRule.id}`);
  await wait(2500);
  await shot('editor');
  await page.keyboard.press('Escape');
  await wait(500);

  await gotoPlugin(h, '#/404');
  await wait(1200);
  await shot('404');

  await gotoPlugin(h, '#/suggestions');
  await wait(1200);
  await shot('suggestions');

  await gotoPlugin(h, '#/tester?url=%2Ffaq');
  await wait(1800);
  await shot('tester');

  await gotoPlugin(h, '#/import');
  await P().locator('input[type=file]').first().setInputFiles(join(SAMPLES, 'redirects-shop-relaunch.csv'));
  await wait(3000);
  await shot('import');

  await gotoPlugin(h, '#/export');
  await wait(1200);
  await shot('export');

  await gotoPlugin(h, '#/settings');
  await wait(3500);
  await shot('settings');

  await page.goto(`${h.base}/admin/`);
  await wait(4000);
  await h.applyTheme();
  await page.locator('grav-widget-redirect-manager-overview').first().scrollIntoViewIfNeeded().catch(() => {});
  await wait(800);
  await shot('dashboard-widget');

  console.log(`${theme}: console errors ${h.errors.length}${h.errors.length ? ' ' + h.errors[0] : ''}; failed calls ${[...new Set(h.failed)].join(', ') || 'none'}`);
  await h.ctx.close();
}
await browser.close();
