import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from 'yaml';
import { test, expect } from '../support/test';
import { hostDialog } from '../support/host-dialog';
import { BOT_UA } from '../support/seed';
import { defaultPluginConfig } from '../support/site';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';

/** seeded 404 paths that show by default: path -> hits (bots are hidden, monitoring traffic is not) */
const DEFAULT_PATHS: Record<string, number> = {
  '/shop/rucksack': 12,
  '/blog/wintercamping-tips': 7,
  '/health-check-pfad': 6,
  '/about-us': 5,
  '/shop/Zelte': 4,
  '/Info': 3,
  '/kontakt/formular': 3,
  '/gibt-es-nicht': 2,
  '/shop/Rucksaecke': 2,
  '/alter-pfad/ohne-ziel': 1,
};
const DEFAULT_HITS = Object.values(DEFAULT_PATHS).reduce((a, b) => a + b, 0); // 45
/** logged by the bot test itself, see there */
const BOT_PATHS: Record<string, number> = { '/wp-admin/e2e-setup.php': 12, '/e2e-bot-probe.php': 3 };

const nfRow = (app: App, path: string) => app.root.locator(`tbody tr[data-path="${path}"]`);
const nfRows = (app: App) => app.root.locator('tbody tr[data-path]');
const summary = (app: App) => app.root.getByRole('status').filter({ hasText: /hits in the last/ });
const stat = (app: App, term: string) => app.root.locator('dl > div').filter({ has: app.page.getByText(term, { exact: true }) }).locator('dd');

async function openMenu(app: App, path: string, item: string | RegExp): Promise<void> {
  await nfRow(app, path).getByRole('button', { name: `More actions for ${path}` }).click();
  await app.root.getByRole('menuitem', { name: item }).click();
}

async function nfApi(api: ApiCall, query = ''): Promise<Record<string, any>> {
  const res = await api('GET', `/redirects/404?per_page=500${query}`);
  return Object.fromEntries((res.data as any[]).map((r) => [r.path, r]));
}

function ignorePatterns(siteDir: string): string[] {
  const file = join(siteDir, 'user', 'config', 'plugins', 'redirect-manager.yaml');
  const cfg = (parse(readFileSync(file, 'utf8')) ?? {}) as { log?: { ignore_patterns?: string[] } };
  return cfg.log?.ignore_patterns ?? [];
}

test.describe('404 monitor', () => {
  test('grouped list shows the seeded paths with hit counts, bots stay hidden; trend card shows the totals', async ({ app }) => {
    await app.goto('#/404');
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length);
    for (const [path, hits] of Object.entries(DEFAULT_PATHS)) {
      await expect(nfRow(app, path).locator('td.c-hits')).toHaveText(String(hits));
    }
    // sorted by hits, the busiest path first
    await expect(nfRows(app).first()).toHaveAttribute('data-path', '/shop/rucksack');
    for (const path of Object.keys(BOT_PATHS)) await expect(nfRow(app, path)).toHaveCount(0);

    // referrer and suggestion columns
    await expect(nfRow(app, '/shop/rucksack')).toContainText('www.google.com');
    await expect(nfRow(app, '/about-us')).toContainText('example.org/links');
    await expect(nfRow(app, '/Info').getByRole('button', { name: /Create a redirect to \/info \(97% match\)/ })).toBeVisible();

    // trend card
    const card = app.root.getByRole('region', { name: '404 hits per day' });
    await expect(card).toBeVisible();
    await expect(card.getByRole('radio', { name: '30 days' })).toBeChecked();
    await expect(stat(app, 'Hits')).toHaveText(String(DEFAULT_HITS));
    await expect(stat(app, 'Paths')).toHaveText(String(Object.keys(DEFAULT_PATHS).length));
    await expect(card.getByRole('img', { name: /Area chart of 404 hits per day over the last 30 days\. Total 45\./ })).toBeVisible();
    await expect(summary(app)).toHaveText(`${Object.keys(DEFAULT_PATHS).length} paths, ${DEFAULT_HITS} hits in the last 30 days`);
    // the chart's table has one row per day, and the days add up to the total
    const cells = card.getByRole('table', { name: '404 hits per day' }).locator('tbody td');
    await expect(cells).toHaveCount(30);
    const sum = (await cells.allTextContents()).reduce((a, b) => a + Number(b), 0);
    expect(sum).toBe(DEFAULT_HITS);
  });

  test('"Include bots" adds bot traffic to the list and the totals, switching it off hides it again', async ({ app, site }) => {
    // The seeded bot paths (/wp-admin/..., /xmlrpc.php) are on the plugin's default ignore list and never get logged,
    // so this test logs its own bot hits with the default ignores off.
    site.setConfig({ ...defaultPluginConfig, log: { ...defaultPluginConfig.log, use_default_ignores: false } });
    for (const [path, count] of Object.entries(BOT_PATHS)) {
      for (let i = 0; i < count; i++) expect((await site.get(path, { 'user-agent': BOT_UA })).status).toBe(404);
    }

    await app.goto('#/404');
    for (const path of Object.keys(BOT_PATHS)) await expect(nfRow(app, path)).toHaveCount(0);
    await expect(stat(app, 'Hits')).toHaveText(String(DEFAULT_HITS));
    const bots = app.root.getByRole('switch', { name: 'Include bots' });
    await expect(bots).not.toBeChecked();

    await bots.click();
    await expect(bots).toBeChecked();
    for (const [path, hits] of Object.entries(BOT_PATHS)) {
      await expect(nfRow(app, path).locator('td.c-hits')).toHaveText(String(hits));
      await expect(nfRow(app, path)).toContainText('Bots'); // the bot-dominated badge
    }
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length + 2);
    await expect(stat(app, 'Hits')).toHaveText(String(DEFAULT_HITS + 12 + 3));
    await expect(stat(app, 'Paths')).toHaveText('12');
    expect(app.page.url()).toContain('bots=1');

    await bots.click();
    for (const path of Object.keys(BOT_PATHS)) await expect(nfRow(app, path)).toHaveCount(0);
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length);
    await expect(stat(app, 'Hits')).toHaveText(String(DEFAULT_HITS));
  });

  test('period toggle changes the chart range and the URL', async ({ app, page }) => {
    await app.goto('#/404');
    const card = app.root.getByRole('region', { name: '404 hits per day' });
    const chartRows = card.getByRole('table', { name: '404 hits per day' }).locator('tbody tr');
    await expect(chartRows).toHaveCount(30);

    await card.getByRole('radio', { name: '7 days' }).click();
    await expect(card.getByRole('radio', { name: '7 days' })).toBeChecked();
    await expect(chartRows).toHaveCount(7);
    await expect(card.getByRole('img', { name: /over the last 7 days\. Total 45\./ })).toBeVisible();
    await expect(summary(app)).toContainText('in the last 7 days');
    expect(page.url()).toContain('days=7');
    // everything was logged today, so the list is the same
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length);

    await card.getByRole('radio', { name: '90 days' }).click();
    await expect(chartRows).toHaveCount(90);
    await expect(summary(app)).toContainText('in the last 90 days');
    expect(page.url()).toContain('days=90');

    // a deep link with the range selects it
    await app.goto('#/404?days=7');
    await expect(card.getByRole('radio', { name: '7 days' })).toBeChecked();
  });

  test('search and traffic-type filter narrow the list, "Clear filters" resets', async ({ app }) => {
    await app.goto('#/404');
    await app.searchbox.fill('shop/');
    await expect(nfRows(app)).toHaveCount(3);
    for (const path of ['/shop/rucksack', '/shop/Zelte', '/shop/Rucksaecke']) await expect(nfRow(app, path)).toBeVisible();
    await expect(summary(app)).toHaveText('3 paths, 18 hits in the last 30 days');

    await app.searchbox.fill('kein-treffer-xyz');
    await expect(nfRows(app)).toHaveCount(0);
    await expect(app.root.getByText('No 404s match')).toBeVisible();
    await app.root.getByRole('button', { name: 'Clear filters' }).click();
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length);
    await expect(app.searchbox).toHaveValue('');

    await app.root.getByRole('combobox', { name: 'Traffic type' }).selectOption({ label: 'Monitoring' });
    await expect(nfRows(app)).toHaveCount(1);
    await expect(nfRow(app, '/health-check-pfad')).toBeVisible();
  });

  test('raw entries expand and collapse for a path', async ({ app }) => {
    await app.goto('#/404');
    const toggle = app.root.getByRole('button', { name: 'Show raw entries for /about-us' });
    await toggle.click();
    const detail = app.root.locator('tr.detail');
    await expect(detail).toHaveCount(1);
    const table = detail.getByRole('table', { name: 'Latest entries for /about-us' });
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(5);
    await expect(table.locator('tbody tr').first()).toContainText('example.org/links');
    await expect(table.locator('tbody tr').first()).toContainText('Browsers');
    await expect(table.getByRole('columnheader')).toHaveText(['Time', 'Referrer', 'Traffic', 'Language', 'Host']);

    // the same through the row menu
    await app.root.getByRole('button', { name: 'Hide raw entries for /about-us' }).click();
    await expect(detail).toHaveCount(0);
    await openMenu(app, '/Info', 'View raw entries');
    await expect(app.root.getByRole('table', { name: 'Latest entries for /Info' }).locator('tbody tr')).toHaveCount(3);
  });

  test('"Create redirect" opens the editor prefilled with the suggestion, saving creates the rule', async ({ app, api, site, request }) => {
    await app.goto('#/404');
    await nfRow(app, '/about-us').getByRole('button', { name: 'Create redirect for /about-us' }).click();
    await expect(app.editor).toBeVisible();
    await expect(app.field('Source')).toHaveValue('/about-us');
    await expect(app.field('Target')).toHaveValue('/about');

    await app.editor.getByRole('button', { name: 'Create redirect' }).click();
    await expect(app.editor).toBeHidden();
    await expect(app.toast('Redirect created for /about-us.')).toBeVisible();

    const rule = (await api('GET', '/redirects/rules?q=/about-us')).data.find((r: any) => r.source === '/about-us');
    expect(rule).toMatchObject({ source: '/about-us', target: '/about', status: 301, match_type: 'exact', enabled: true });
    const res = await request.get(`${site.baseUrl}/about-us`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/about');

    // the monitor knows the path has a rule now
    await expect(nfRow(app, '/about-us')).toContainText('Has rule');
    await expect(nfRow(app, '/about-us').getByRole('link', { name: 'Edit the rule for /about-us' })).toBeVisible();
  });

  test('"Create redirect" for a path that differs from its suggestion only in case creates a case-sensitive rule', async ({ app, api }) => {
    await app.goto('#/404');
    await nfRow(app, '/shop/Zelte').getByRole('button', { name: 'Create redirect for /shop/Zelte' }).click();
    await expect(app.field('Source')).toHaveValue('/shop/Zelte');
    await expect(app.field('Target')).toHaveValue('/shop/zelte');
    await expect(app.editor.getByRole('list', { name: 'Checks' }).getByRole('alert')).toHaveCount(0);
    await expect(app.editor.getByRole('checkbox', { name: /Case sensitive/ })).toBeChecked();

    await app.editor.getByRole('button', { name: 'Create redirect' }).click();
    await expect(app.editor).toBeHidden();
    await expect(app.toast('Redirect created for /shop/Zelte.')).toBeVisible();
    const rule = (await api('GET', '/redirects/rules?q=/shop/Zelte')).data.find((r: any) => r.source === '/shop/Zelte');
    expect(rule).toMatchObject({ target: '/shop/zelte', case_sensitive: true });
  });

  test('clicking the suggestion badge creates a redirect the same way', async ({ app, api }) => {
    await app.goto('#/404');
    await nfRow(app, '/blog/wintercamping-tips').getByRole('button', { name: /Create a redirect to \/blog\/wintercamping-tipps/ }).click();
    await expect(app.field('Source')).toHaveValue('/blog/wintercamping-tips');
    await expect(app.field('Target')).toHaveValue('/blog/wintercamping-tipps');
    await app.editor.getByRole('button', { name: 'Create redirect' }).click();
    await expect(app.editor).toBeHidden();
    await expect
      .poll(async () => ((await api('GET', '/redirects/rules?q=wintercamping-tips')).data as any[]).map((r) => [r.source, r.target]))
      .toContainEqual(['/blog/wintercamping-tips', '/blog/wintercamping-tipps']);
  });

  test('"Ignore" removes matching paths, writes the pattern to the configuration and stops logging', async ({ app, api, site }) => {
    await app.goto('#/404');
    await openMenu(app, '/shop/rucksack', /^Ignore/);
    const dialog = hostDialog(app.page, 'Ignore 404 pattern');
    await expect(dialog).toBeVisible();
    const pattern = dialog.getByLabel('Pattern');
    await expect(pattern).toHaveValue('/shop/rucksack');
    await pattern.fill('/shop/*');
    await dialog.getByRole('button', { name: 'Ignore and remove entries' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.toast(/Ignoring \/shop\/\*\. 3 paths removed from the log\./)).toBeVisible();

    for (const path of ['/shop/rucksack', '/shop/Zelte', '/shop/Rucksaecke']) await expect(nfRow(app, path)).toHaveCount(0);
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length - 3);

    expect(ignorePatterns(site.dir)).toContain('/shop/*');
    const rows = await nfApi(api);
    expect(Object.keys(rows).sort()).toEqual(Object.keys(DEFAULT_PATHS).filter((p) => !p.startsWith('/shop/')).sort());

    // New requests to an ignored path are not logged. Grav re-reads its config files at most every 2 s, so the first
    // requests may still be logged: probe until one is not. A control request to a path that is not ignored proves that
    // logging itself works, so "not logged" is not just "not written yet".
    let n = 0;
    await expect
      .poll(
        async () => {
          const probe = `/shop/e2e-probe-${++n}`;
          const control = `/e2e-kontrolle-${n}`;
          expect((await site.get(probe)).status).toBe(404);
          expect((await site.get(control)).status).toBe(404);
          await expect.poll(async () => Object.keys(await nfApi(api)).includes(control)).toBe(true);
          return !Object.keys(await nfApi(api)).includes(probe);
        },
        { timeout: 12_000, intervals: [500] },
      )
      .toBe(true);
  });

  test('"Ignore" with the default pattern (the exact path) removes just that path', async ({ app, api, site }) => {
    await app.goto('#/404');
    await openMenu(app, '/gibt-es-nicht', /^Ignore/);
    const dialog = hostDialog(app.page, 'Ignore 404 pattern');
    await dialog.getByRole('button', { name: 'Ignore and remove entries' }).click();
    await expect(app.toast(/Ignoring \/gibt-es-nicht\. 1 path removed from the log\./)).toBeVisible();
    await expect(nfRow(app, '/gibt-es-nicht')).toHaveCount(0);
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length - 1);
    expect(ignorePatterns(site.dir)).toContain('/gibt-es-nicht');
    expect(Object.keys(await nfApi(api))).not.toContain('/gibt-es-nicht');
  });

  test('"Ignore" can be cancelled: nothing changes', async ({ app, api, site }) => {
    await app.goto('#/404');
    await openMenu(app, '/Info', /^Ignore/);
    const dialog = hostDialog(app.page, 'Ignore 404 pattern');
    await dialog.getByRole('button', { name: 'Cancel' }).first().click();
    await expect(dialog).toBeHidden();
    await expect(nfRow(app, '/Info')).toBeVisible();
    expect(ignorePatterns(site.dir)).not.toContain('/Info');
    expect(Object.keys(await nfApi(api))).toContain('/Info');
  });

  test('"Mark as done" hides the path, "Show done" lists it, "Reopen" brings it back', async ({ app, api }) => {
    await app.goto('#/404');
    await openMenu(app, '/about-us', 'Mark as done');
    await expect(app.toast('Marked /about-us as done.')).toBeVisible();
    await expect(nfRow(app, '/about-us')).toHaveCount(0);
    expect(Object.keys(await nfApi(api))).not.toContain('/about-us');
    expect((await nfApi(api, '&include_resolved=1'))['/about-us']).toMatchObject({ resolved: true, hits: 5 });

    await app.root.getByRole('switch', { name: 'Show done' }).click();
    const row = nfRow(app, '/about-us');
    await expect(row).toBeVisible();
    await expect(row).toContainText('Done');
    await openMenu(app, '/about-us', 'Reopen');
    await expect(app.toast('Reopened /about-us.')).toBeVisible();
    await expect(row).not.toContainText('Done');
    await expect.poll(async () => (await nfApi(api))['/about-us']?.resolved).toBeFalsy();
    expect(Object.keys(await nfApi(api))).toContain('/about-us');
  });

  test('the "Marked as done" toast offers Undo', async ({ app, api }) => {
    await app.goto('#/404');
    await openMenu(app, '/Info', 'Mark as done');
    await expect(nfRow(app, '/Info')).toHaveCount(0);
    await app.toasts.getByRole('button', { name: 'Undo' }).click();
    await expect(nfRow(app, '/Info')).toBeVisible();
    await expect.poll(async () => Object.keys(await nfApi(api))).toContain('/Info');
  });

  test('"Delete log entries" asks for confirmation; cancelling keeps them, confirming removes them', async ({ app, api }) => {
    await app.goto('#/404');
    await openMenu(app, '/kontakt/formular', 'Delete log entries');
    const dialog = hostDialog(app.page, 'Delete log entries?');
    await expect(dialog).toContainText('All logged 404 entries for /kontakt/formular are deleted.');
    await dialog.getByRole('button', { name: 'Cancel' }).first().click();
    await expect(dialog).toBeHidden();
    await expect(nfRow(app, '/kontakt/formular')).toBeVisible();
    expect((await api('GET', '/redirects/404/entries?path=/kontakt/formular')).data).toHaveLength(3);

    await openMenu(app, '/kontakt/formular', 'Delete log entries');
    await dialog.getByRole('button', { name: 'Delete entries' }).click();
    await expect(app.toast('Deleted the log entries for /kontakt/formular.')).toBeVisible();
    await expect(nfRow(app, '/kontakt/formular')).toHaveCount(0);
    await expect(nfRows(app)).toHaveCount(Object.keys(DEFAULT_PATHS).length - 1);
    expect((await api('GET', '/redirects/404/entries?path=/kontakt/formular')).data).toHaveLength(0);
    expect(Object.keys(await nfApi(api))).not.toContain('/kontakt/formular');
    // the other paths keep their entries
    expect((await api('GET', '/redirects/404/entries?path=/about-us')).data).toHaveLength(5);
  });
});
