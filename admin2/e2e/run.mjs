/**
 * Screenshots + axe-core for every screen, in both themes and two viewports,
 * against the dev harness (mock API). Writes admin2/screenshots/*.png.
 *
 *   node e2e/run.mjs              all scenarios
 *   node e2e/run.mjs rules 404    only scenarios whose name contains a filter
 *   RM_URL=http://localhost:5199 node e2e/run.mjs   reuse a running dev server
 *   RM_BUNDLE=1 node e2e/run.mjs  test the committed, minified bundles instead of the sources
 */
import { chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createServer } from 'vite';
import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

let server = null;
let base = process.env.RM_URL;
if (!base) {
  server = await createServer({ root, configFile: join(root, 'vite.dev.config.ts'), server: { port: 5190, strictPort: false }, logLevel: 'error' });
  await server.listen();
  base = server.resolvedUrls.local[0].replace(/\/$/, '');
}

const fixture = (f) => join(root, 'e2e/fixtures', f);
const waitIdle = (p) => p.waitForFunction(() => !document.querySelector('grav-redirect-manager--page')?.shadowRoot?.querySelector('[aria-busy="true"]'), null, { timeout: 8000 }).catch(() => {});

/** name, url (relative to /dev/), optional setup(page) run after load */
const scenarios = [
  { name: 'rules', url: 'index.html#/rules' },
  { name: 'rules-filters', url: 'index.html#/rules?state=active&badge=chain', setup: async (p) => p.waitForTimeout(400) },
  { name: 'rules-selected', url: 'index.html#/rules', setup: async (p) => { await p.locator('grav-redirect-manager--page input[type=checkbox]').nth(2).check(); await p.locator('grav-redirect-manager--page input[type=checkbox]').nth(4).check(); } },
  { name: 'rules-menu', url: 'index.html#/rules', setup: async (p) => { await p.locator('grav-redirect-manager--page button[aria-haspopup=menu]').first().click(); } },
  {
    name: 'rules-empty',
    url: 'index.html#/rules',
    setup: async (p) => {
      // delete every rule through the mock API, then reload the list
      await p.evaluate(async () => {
        const ids = window.__RM_MOCK.state.rules.map((r) => r.id);
        await fetch('/api/v1/redirects/rules/bulk', { method: 'POST', headers: { 'X-API-Token': 'x', 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ action: 'delete', ids }) });
        location.hash = '#/rules?per_page=25';
      });
      await p.waitForTimeout(800);
    },
  },
  { name: 'rules-error', url: 'index.html?fail=1#/rules' },
  { name: 'editor-new', url: 'index.html#/rules/new', setup: async (p) => { await p.getByLabel('Source', { exact: true }).fill('/blog/2024/*'); await p.getByLabel('Target', { exact: true }).fill('/journal/2024/$1'); await p.waitForTimeout(700); } },
  { name: 'editor-edit', url: 'index.html#/rules/pick', setup: async (p) => { await p.waitForTimeout(500); } },
  { name: '404', url: 'index.html#/404' },
  { name: 'suggestions', url: 'index.html#/suggestions' },
  { name: 'tester', url: 'index.html#/tester?url=/blog/fehler-video' },
  { name: 'import', url: 'index.html#/import' },
  { name: 'import-preview', url: 'index.html#/import', setup: async (p) => { await p.locator('grav-redirect-manager--page input[type=file]').first().setInputFiles(fixture('import.csv')); await p.waitForTimeout(1500); } },
  { name: 'export', url: 'index.html#/export' },
  { name: 'settings', url: 'index.html#/settings' },
  { name: 'widget', url: 'widget.html', widget: true },
  { name: 'widget-error', url: 'widget.html?fail=1', widget: true },
];

const themes = ['light', 'dark'];
const viewports = [
  { w: 1440, h: 900 },
  { w: 900, h: 1100 },
];
const filters = process.argv.slice(2);
const browser = await chromium.launch();
const results = [];
let hardFail = false;

for (const sc of scenarios) {
  if (filters.length && !filters.some((f) => sc.name.includes(f))) continue;
  for (const theme of themes) {
    for (const vp of viewports) {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, colorScheme: theme, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      const problems = [];
      page.on('console', (m) => {
        if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) problems.push('console: ' + m.text());
      });
      page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
      let url = `${base}/dev/${sc.url}`;
      const [path, hash = ''] = url.split('#');
      url = `${path}${path.includes('?') ? '&' : '?'}theme=${theme}&chrome=0&latency=0${process.env.RM_BUNDLE ? '&bundle=1' : ''}${hash ? '#' + hash : ''}`;
      await page.goto(url);
      await page.waitForTimeout(900);
      await waitIdle(page);
      try {
        if (sc.name === 'editor-edit') {
          const id = await page.evaluate(() => window.__RM_MOCK.state.rules[3].id);
          await page.evaluate((i) => (location.hash = `#/rules/${i}`), id);
          await page.waitForTimeout(900);
        } else if (sc.setup) await sc.setup(page);
      } catch (e) {
        problems.push('setup: ' + e.message.split('\n')[0]);
      }
      await page.waitForTimeout(500);
      const file = `${sc.name}-${theme}-${vp.w}.png`;
      await page.screenshot({ path: join(outDir, file) });
      const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).disableRules(sc.widget ? ['landmark-unique'] : []).analyze();
      const bad = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      const minor = axe.violations.filter((v) => !(v.impact === 'serious' || v.impact === 'critical'));
      results.push({ file, bad, minor, problems });
      if (bad.length || problems.length) hardFail = true;
      console.log(`${bad.length || problems.length ? 'FAIL' : 'ok  '} ${file}  serious/critical=${bad.length} minor=${minor.length}${problems.length ? '  ' + problems.join(' | ') : ''}`);
      for (const v of bad) console.log(`     [${v.impact}] ${v.id}: ${v.help}  (${v.nodes.length} nodes)  e.g. ${v.nodes[0]?.target?.join(' ')}\n       ${(v.nodes[0]?.failureSummary ?? '').split('\n').slice(0, 3).join(' ')}`);
      await ctx.close();
    }
  }
}
await browser.close();
await server?.close();
const total = results.reduce((n, r) => n + r.bad.length, 0);
console.log(`\n${results.length} screenshots, ${total} serious/critical axe violations, ${results.filter((r) => r.problems.length).length} runs with console errors`);
process.exit(hardFail ? 1 : 0);
