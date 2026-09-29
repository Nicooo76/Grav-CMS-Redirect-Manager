import { readFileSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import type { Download } from '@playwright/test';
import { test, expect } from '../support/test';
import { dataDir } from '../support/site';
import { allRules, bulkBar, ruleOf, selectRows } from '../support/rules-page';
import { dailySeries, sparkLine } from '../support/spark';

/**
 * Per-row sparkline of the 404 monitor, column chooser, "Export selected", the empty states, the skeleton loaders and
 * the motion of the slide-over.
 */

async function saved(dl: Download): Promise<string> {
  return readFileSync(await dl.path(), 'utf8');
}

test.describe('404 monitor: sparkline per row', () => {
  test('the line of a row is drawn from the daily counts of its path', async ({ app, api, site }) => {
    // /shop/rucksack has 12 hits today (seed); give it hits on three earlier days as well
    const dir = join(dataDir(site.dir), '404');
    mkdirSync(dir, { recursive: true });
    const line = (t: number) => JSON.stringify({ t, p: '/shop/rucksack', r: '', ua: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0', c: 'browser', ip: '127.0.0.0', h: '127.0.0.1', m: 'GET' });
    const now = Math.floor(Date.now() / 1000);
    for (const [daysAgo, count] of [[1, 4], [3, 7], [5, 2]] as const) {
      const t = now - daysAgo * 86_400;
      const day = new Date(t * 1000).toISOString().slice(0, 10);
      writeFileSync(join(dir, `${day}.jsonl`), Array.from({ length: count }, () => line(t)).join('\n') + '\n');
    }

    const rows = (await api('GET', '/redirects/404?days=30&per_page=100')).data as Array<{ path: string; hits: number; daily: Record<string, number> }>;
    const row = rows.find((r) => r.path === '/shop/rucksack')!;
    // the API fills every day of the range, days without hits count 0
    expect(Object.keys(row.daily).length).toBe(30);
    expect(Object.values(row.daily).filter((n) => n > 0).sort((a, b) => a - b)).toEqual([2, 4, 7, 12]);

    await app.goto('#/404');
    const spark = app.root.locator('tbody tr[data-path="/shop/rucksack"] td.c-trend svg.spark');
    await expect(spark).toBeVisible();
    const paths = spark.locator('path[fill="none"]');
    await expect(paths).toHaveCount(1);
    const d = (await paths.getAttribute('d'))!;
    // 30 days, one point each; the line is the one the UI draws for the API's series (56 x 18)
    expect(d.split('L').length).toBe(30);
    const expected = sparkLine(dailySeries(row.daily, 30), 56, 18);
    expect(d).toBe(expected);
    // the peak is the busiest day: exactly one point at the top, and the four days with hits stand out from the flat rest
    const ys = [...d.matchAll(/,([\d.]+)/g)].map((m) => Number(m[1]));
    expect(ys.filter((y) => y === Math.min(...ys))).toHaveLength(1);
    expect(ys.filter((y) => y < Math.max(...ys))).toHaveLength(4);
    expect(await spark.getAttribute('viewBox')).toBe('0 0 56 18');
  });
});

test.describe('rules table: column chooser', () => {
  const HEADERS: Record<string, string> = { status: 'Status', target: 'Target', code: 'Code', group: 'Group', hits: 'Hits', last_hit: 'Last hit', origin: 'Origin', priority: 'Priority' };
  const header = (app: import('../support/app').App, name: string) => app.root.locator('table.rules thead').getByRole('columnheader', { name, exact: true });
  // wide enough for every column: the table hides Origin and Priority below 76rem on its own
  test.use({ viewport: { width: 1920, height: 1080 } });

  test('hide and show columns; the choice survives a reload; Source and actions are always there', async ({ app, page }) => {
    await app.goto('#/rules');
    // defaults: everything but Priority
    for (const [key, name] of Object.entries(HEADERS)) {
      if (key === 'priority') await expect(header(app, name)).toHaveCount(0);
      else await expect(header(app, name)).toBeVisible();
    }
    await app.root.getByRole('button', { name: 'Columns' }).click();
    const chooser = app.root.getByRole('dialog', { name: 'Choose columns' });
    await expect(chooser).toContainText('Source and actions are always shown.');
    await expect(chooser.getByRole('checkbox')).toHaveCount(8);
    await expect(chooser.getByRole('checkbox', { name: 'Priority' })).not.toBeChecked();
    await expect(chooser.getByRole('checkbox', { name: 'Hits' })).toBeChecked();

    await chooser.getByRole('checkbox', { name: 'Hits' }).uncheck();
    await chooser.getByRole('checkbox', { name: 'Target' }).uncheck();
    await chooser.getByRole('checkbox', { name: 'Priority' }).check();
    await expect(header(app, 'Hits')).toHaveCount(0);
    await expect(header(app, 'Target')).toHaveCount(0);
    await expect(header(app, 'Priority')).toBeVisible();
    await expect(header(app, 'Source')).toBeVisible();
    // the rows follow the header: no target cell, no hits cell, a priority cell
    const row = app.row('/produkt/zelt-alpin-3');
    await expect(row.locator('td.c-target')).toHaveCount(0);
    await expect(row.locator('td.c-hits')).toHaveCount(0);
    await expect(row.locator('td.c-priority')).toHaveCount(1);

    // persisted in the browser, per user, and applied on the next load
    const stored = await page.evaluate(() => localStorage.getItem('rm.rules.columns.v1'));
    expect(JSON.parse(stored!)).toMatchObject({ hits: false, target: false, priority: true, status: true, code: true });
    await page.keyboard.press('Escape');
    await app.reload();
    await expect(header(app, 'Hits')).toHaveCount(0);
    await expect(header(app, 'Target')).toHaveCount(0);
    await expect(header(app, 'Priority')).toBeVisible();
    await app.root.getByRole('button', { name: 'Columns' }).click();
    await expect(app.root.getByRole('dialog', { name: 'Choose columns' }).getByRole('checkbox', { name: 'Hits' })).not.toBeChecked();

    // and back
    await app.root.getByRole('dialog', { name: 'Choose columns' }).getByRole('checkbox', { name: 'Hits' }).check();
    await app.root.getByRole('dialog', { name: 'Choose columns' }).getByRole('checkbox', { name: 'Target' }).check();
    await expect(header(app, 'Hits')).toBeVisible();
    await expect(header(app, 'Target')).toBeVisible();
    await expect(row.locator('td.c-target')).toContainText('/shop/zelte');
  });
});

test.describe('rules: export selected', () => {
  const PICK = ['/d-blog', '/e-info'];

  async function exportSelected(app: import('../support/app').App, format: string | RegExp): Promise<string> {
    await app.goto('#/rules');
    await selectRows(app, ...PICK);
    await expect(bulkBar(app)).toContainText('2 rules selected');
    await bulkBar(app).getByRole('button', { name: 'Export selected' }).click();
    const [dl] = await Promise.all([app.page.waitForEvent('download'), app.root.getByRole('menuitem', { name: format }).click()]);
    return saved(dl);
  }

  test('CSV: the file holds exactly the two selected rules', async ({ app, api }) => {
    const text = await exportSelected(app, /^CSV/);
    const lines = text.trim().split(/\r?\n/);
    expect(lines[0]).toMatch(/source/);
    expect(lines).toHaveLength(3);
    const all = await allRules(api);
    for (const source of PICK) expect(lines.filter((l) => l.includes(source + ','))).toHaveLength(1);
    for (const other of all.map((r) => r.source).filter((s) => !PICK.includes(s) && s !== '/faq-alt')) expect(text, other).not.toContain(other + ',');
    expect(lines.find((l) => l.startsWith('/d-blog,'))).toContain('/blog');
    expect(lines.find((l) => l.startsWith('/e-info,'))).toContain('/info');
    await expect(app.toast(/Downloaded .*\.csv/)).toBeVisible();
  });

  test('Apache .htaccess: only the two selected rules are in the file', async ({ app, api }) => {
    const text = await exportSelected(app, /htaccess/i);
    const directives = text.split(/\r?\n/).filter((l) => /^\s*(Redirect|RedirectMatch|RewriteRule)\b/i.test(l));
    expect(directives).toEqual(['RewriteRule ^d-blog/?$ /blog [R=301,L,NC]', 'RewriteRule ^e-info/?$ /info [R=301,L,NC]']);
    // no other rule of the site is in the file
    for (const s of (await allRules(api)).map((r) => r.source).filter((s) => !PICK.includes(s) && !s.includes('*') && !s.startsWith('^'))) {
      expect(text, s).not.toContain(s.slice(1));
    }
    // the selection is untouched by the export
    expect((await ruleOf(api, '/d-blog')).target).toBe('/blog');
  });
});

test.describe('empty states', () => {
  test('rules: no rules at all explains what a redirect is, shows an example and offers two ways to start', async ({ app, api }) => {
    const ids = (await allRules(api)).map((r) => r.id);
    expect((await api('POST', '/redirects/rules/bulk', { action: 'delete', ids })).status).toBe(200);
    await app.goto('#/rules');
    const empty = app.root.locator('.list-card');
    await expect(empty.getByRole('heading', { name: 'No redirects yet' })).toBeVisible();
    await expect(empty).toContainText('A redirect sends visitors from an old address to a new one');
    const example = empty.locator('.example');
    await expect(example).toContainText('/old-page');
    await expect(example).toContainText('/new-page');
    await expect(example).toContainText('301');
    await expect(app.rows).toHaveCount(0);
    await expect(empty.getByRole('button', { name: 'Create first redirect' })).toBeVisible();
    await empty.getByRole('link', { name: 'Import redirects' }).click();
    await expect(app.page).toHaveURL(/#\/import$/);
    await app.goto('#/rules');
    await empty.getByRole('button', { name: 'Create first redirect' }).click();
    await expect(app.editor.getByRole('heading', { level: 2 })).toHaveText('New redirect');
  });

  test('404 monitor: an empty log explains the screen and offers a longer range and the log settings', async ({ app, site }) => {
    rmSync(join(dataDir(site.dir), '404'), { recursive: true, force: true });
    await app.goto('#/404');
    const empty = app.root.locator('.list-card');
    await expect(empty.getByRole('heading', { name: 'No 404 errors logged' })).toBeVisible();
    await expect(empty).toContainText('The plugin logs every request for a missing page on its own.');
    await expect(empty).toContainText('Bot traffic is hidden. Turn on “Include bots” to see it.');
    await empty.getByRole('button', { name: 'Show 90 days' }).click();
    await expect(app.page).toHaveURL(/days=90/);
    await expect(empty.getByRole('button', { name: 'Show 90 days' })).toHaveCount(0);
    await empty.getByRole('link', { name: 'Log settings' }).click();
    await expect(app.page).toHaveURL(/#\/settings$/);
  });

  test('suggestions: everything reviewed says "No open suggestions", never generated says "No suggestions yet"', async ({ app, api }) => {
    const open = (await api('GET', '/redirects/suggestions?status=open')).data as Array<{ id: string }>;
    for (const s of open) expect((await api('POST', `/redirects/suggestions/${s.id}/reject`)).status).toBe(200);
    await app.goto('#/suggestions');
    const empty = app.root.locator('.list-card');
    await expect(empty.getByRole('heading', { name: 'No open suggestions' })).toBeVisible();
    await expect(empty).toContainText('You reviewed every suggestion. Generate new ones after more 404s show up.');
    await expect(empty.getByRole('button', { name: 'Generate suggestions' })).toBeVisible();
    await expect(empty.getByRole('link', { name: 'Open the 404 monitor' })).toBeVisible();
  });

  test('suggestions: a site that never generated any explains the feature and links to the 404 monitor', async ({ app, site }) => {
    rmSync(join(dataDir(site.dir), 'suggestions.json'), { force: true });
    await app.goto('#/suggestions');
    const empty = app.root.locator('.list-card');
    await expect(empty.getByRole('heading', { name: 'No suggestions yet' })).toBeVisible();
    await expect(empty).toContainText('The plugin compares your 404 paths with the pages of your site and proposes a target for each.');
    await empty.getByRole('link', { name: 'Open the 404 monitor' }).click();
    await expect(app.page).toHaveURL(/#\/404$/);
  });

  test('suggestions: the Accepted and Rejected tabs of a fresh site explain themselves and lead back to the open ones', async ({ app, site }) => {
    rmSync(join(dataDir(site.dir), 'suggestions.json'), { force: true });
    await app.goto('#/suggestions');
    const empty = app.root.locator('.list-card');
    await app.root.getByRole('radio', { name: /^Accepted/ }).click();
    await expect(empty.getByRole('heading', { name: 'Nothing accepted yet' })).toBeVisible();
    await expect(empty).toContainText('Suggestions you accept show up here after they became redirects.');
    await app.root.getByRole('radio', { name: /^Rejected/ }).click();
    await expect(empty.getByRole('heading', { name: 'Nothing rejected' })).toBeVisible();
    await empty.getByRole('button', { name: 'Show open suggestions' }).click();
    await expect(empty.getByRole('heading', { name: 'No suggestions yet' })).toBeVisible();
  });

  test('the panels for deleted pages and unseen automatic rules are simply absent when there is nothing to decide', async ({ app }) => {
    await app.goto('#/rules');
    await expect(app.rows.first()).toBeVisible();
    await expect(app.root.locator('[data-testid=pending-panel]')).toHaveCount(0);
    await expect(app.root.locator('[data-testid=unseen-panel]')).toHaveCount(0);
  });
});

test.describe('skeleton loaders', () => {
  /** answers the requests to `pattern` only after `release()` */
  async function delayed(page: import('@playwright/test').Page, pattern: RegExp) {
    let release!: () => void;
    const gate = new Promise<void>((r) => (release = r));
    await page.route(
      (url) => pattern.test(url.pathname),
      async (route) => {
        if (route.request().method() !== 'GET') return route.fallback();
        await gate;
        await route.fallback();
      },
    );
    return release;
  }

  test('rules: skeleton rows and aria-busy while the list request is pending, the rows replace them', async ({ page, app, site }) => {
    const release = await delayed(page, /\/redirects\/rules$/);
    await page.goto(`${site.baseUrl}/admin/plugin/redirect-manager#/rules`);
    await expect(app.root).toBeVisible();
    const table = app.root.getByRole('table', { name: 'Redirect rules' });
    await expect(table).toHaveAttribute('aria-busy', 'true');
    await expect(app.root.locator('tbody tr.sk')).toHaveCount(8);
    await expect(app.root.locator('tbody tr.sk .skeleton').first()).toBeVisible();
    await expect(app.rows).toHaveCount(0);
    // the skeleton is decoration: it has no text and is hidden from assistive technology
    await expect(app.root.locator('tbody tr.sk .skeleton').first()).toHaveAttribute('aria-hidden', 'true');
    await expect(app.root.locator('tbody tr.sk').first()).toHaveText('');

    release();
    await expect(app.rows.first()).toBeVisible();
    await expect(app.root.locator('tbody tr.sk')).toHaveCount(0);
    await expect(table).toHaveAttribute('aria-busy', 'false');
  });

  test('404 monitor: skeleton rows while the list is pending', async ({ page, app, site }) => {
    const release = await delayed(page, /\/redirects\/404$/);
    await page.goto(`${site.baseUrl}/admin/plugin/redirect-manager#/404`);
    await expect(app.root).toBeVisible();
    await expect(app.root.locator('table.nf')).toHaveAttribute('aria-busy', 'true');
    await expect(app.root.locator('tbody tr .skeleton').first()).toBeVisible();
    await expect(app.root.locator('tbody tr[data-path]')).toHaveCount(0);
    release();
    await expect(app.root.locator('tbody tr[data-path]').first()).toBeVisible();
    await expect(app.root.locator('tbody tr .skeleton')).toHaveCount(0);
  });

  test('the site configuration panel shows skeletons while it loads', async ({ page, app, site }) => {
    const release = await delayed(page, /\/redirects\/site-config$/);
    await page.goto(`${site.baseUrl}/admin/plugin/redirect-manager#/export`);
    const panel = app.root.getByRole('region', { name: 'Grav site configuration' });
    await expect(panel.locator('.skeleton').first()).toBeVisible();
    release();
    await expect(panel.locator('.skeleton')).toHaveCount(0);
  });
});

test.describe('slide-over motion', () => {
  /** milliseconds from the close request (Escape) until the panel is gone from the DOM */
  async function closeTime(app: import('../support/app').App): Promise<number> {
    const t0 = Date.now();
    await app.page.keyboard.press('Escape');
    await expect(app.root.locator('.so-panel')).toHaveCount(0);
    return Date.now() - t0;
  }

  test('with motion allowed the panel slides in and out over 200 ms', async ({ app, page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await app.goto('#/rules');
    await app.root.getByRole('button', { name: 'New redirect' }).click();
    const panel = app.root.locator('.so-panel');
    await expect(panel).toBeVisible();
    const open = await panel.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { name: cs.animationName, duration: cs.animationDuration, timing: cs.animationTimingFunction };
    });
    expect(open.name).toBe('rm-slide-in');
    expect(open.duration).toBe('0.2s');
    const backdrop = await app.root.locator('.so-backdrop').evaluate((el) => getComputedStyle(el).animationDuration);
    expect(backdrop).toBe('0.2s');

    // closing: the panel stays in the DOM for the length of the animation, then it is removed
    await page.keyboard.press('Escape');
    await expect(app.root.locator('.so-panel.closing')).toHaveCount(1);
    const closing = await app.root.locator('.so-panel.closing').evaluate((el) => {
      const cs = getComputedStyle(el);
      return { name: cs.animationName, duration: cs.animationDuration };
    });
    expect(closing).toEqual({ name: 'rm-slide-out', duration: '0.2s' });
    await expect(app.root.locator('.so-panel')).toHaveCount(0);

    // and the elapsed time is about 200 ms (generous bounds: slow machines, timers)
    await app.root.getByRole('button', { name: 'New redirect' }).click();
    await expect(panel).toBeVisible();
    const elapsed = await closeTime(app);
    expect(elapsed).toBeGreaterThanOrEqual(170);
    expect(elapsed).toBeLessThan(1_500);
  });

  test('with reduced motion the animations are effectively off and the panel closes at once', async ({ app, page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await app.goto('#/rules');
    await app.root.getByRole('button', { name: 'New redirect' }).click();
    const panel = app.root.locator('.so-panel');
    await expect(panel).toBeVisible();
    const seconds = async (selector: string, prop: 'animationDuration' | 'transitionDuration') => app.root.locator(selector).first().evaluate((el, p) => parseFloat(getComputedStyle(el)[p as 'animationDuration']), prop);
    // 0.001 ms: nothing to see, but the end state is reached
    expect(await seconds('.so-panel', 'animationDuration')).toBeLessThanOrEqual(0.001);
    expect(await seconds('.so-backdrop', 'animationDuration')).toBeLessThanOrEqual(0.001);
    expect(await seconds('.so-panel .btn', 'transitionDuration')).toBeLessThanOrEqual(0.001);
    const elapsed = await closeTime(app);
    expect(elapsed).toBeLessThan(180);
  });
});
