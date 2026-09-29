import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '../support/test';
import { runState } from '../support/env';

/**
 * 10,000 rules in the real UI: the virtualized table keeps the DOM small, scrolling to the end shows real rows,
 * search and filters answer fast. The budgets are generous (CI machines are slow) but real: a search that takes 2 s
 * on 10,000 rules would be a defect.
 */

const GENERATED = 10_000;
const SEEDED = 32;
const TOTAL = GENERATED + SEEDED;

function generateCsv(): string {
  const lines = ['source,target,status,match_type,group'];
  for (let i = 1; i <= GENERATED; i++) {
    const n = String(i).padStart(5, '0');
    const kind = i % 50 === 0 ? 'wildcard' : 'exact';
    const source = kind === 'wildcard' ? `/gen/w${n}/*` : `/gen/r${n}`;
    const target = kind === 'wildcard' ? `/shop/zelte/$1` : `/shop/zelte/${n}`;
    lines.push(`${source},${target},${i % 10 === 0 ? 302 : 301},${kind},gen-${(i % 7) + 1}`);
  }
  return lines.join('\n') + '\n';
}

test.describe('10,000 rules', () => {
  test.setTimeout(240_000);

  test.beforeEach(async ({ site, api }) => {
    const file = join(runState().workDir, 'gen-10000.csv');
    writeFileSync(file, generateCsv());
    // Ids::rule() is a millisecond timestamp plus 3 random bytes. The importer creates thousands of rules per
    // millisecond, so a batch of 10,000 collides now and then (about one run in thirty) and the CLI refuses the whole
    // file with "Duplicate rule id". Nothing is written then, so trying again is safe. Reported as a finding.
    let imported = await site.cli('import', file, '--json');
    for (let attempt = 0; attempt < 4 && imported.code !== 0 && /Duplicate rule id/.test(imported.stdout + imported.stderr); attempt++) {
      imported = await site.cli('import', file, '--json');
    }
    expect(imported.code, imported.stdout + imported.stderr).toBe(0);
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(TOTAL);
  });

  test('per page 500: first paint within budget, the DOM holds a window of rows, scrolling to the end renders the last row', async ({ app, page }) => {
    const started = Date.now();
    await app.goto('#/rules?per_page=500&sort=source&dir=asc');
    await expect(app.rows.first()).toBeVisible();
    const firstPaint = Date.now() - started;
    expect(firstPaint, `first paint of the 500-row page took ${firstPaint} ms`).toBeLessThan(8_000);

    const nav = app.root.getByRole('navigation', { name: 'Pagination' });
    await expect(nav).toContainText(`1–500 of 10,032`);
    const table = app.root.getByRole('table', { name: 'Redirect rules' });
    await expect(table).toHaveAttribute('aria-rowcount', String(TOTAL + 1));
    const scroller = app.root.locator('.rules-scroll');
    await expect(scroller).toHaveAttribute('data-virtual', 'true');

    // 500 rules on the page, a few dozen in the DOM
    const inDom = await app.rows.count();
    expect(inDom).toBeGreaterThan(10);
    expect(inDom).toBeLessThan(80);

    // to the end of the page: the window moves, the last rule of the page is a real row
    await scroller.evaluate((el) => (el.scrollTop = el.scrollHeight));
    const last = app.rows.last();
    await expect(last).toHaveAttribute('aria-rowindex', '501');
    await expect(last.locator('td.c-source a')).toHaveText(/^\/gen\/[rw]\d{5}/);
    expect(await app.rows.count()).toBeLessThan(80);
    // the rows that were on screen at the top are gone from the DOM
    await expect(app.rows.filter({ has: page.locator('td.c-source a', { hasText: '/gen/r00001' }) })).toHaveCount(0);

    // and back to the top
    await scroller.evaluate((el) => (el.scrollTop = 0));
    await expect(app.rows.first()).toHaveAttribute('aria-rowindex', '2');
  });

  test('scrolling through 500 rows stays smooth: frame times of a scripted scroll', async ({ app }) => {
    await app.goto('#/rules?per_page=500&sort=source&dir=asc');
    await expect(app.rows.first()).toBeVisible();
    const scroller = app.root.locator('.rules-scroll');
    const stats = await scroller.evaluate(async (el) => {
      const frames: number[] = [];
      const step = 44 * 6;
      let last = performance.now();
      for (let y = 0; y < el.scrollHeight - el.clientHeight; y += step) {
        el.scrollTop = y;
        await new Promise((r) => requestAnimationFrame(r));
        const now = performance.now();
        frames.push(now - last);
        last = now;
      }
      frames.sort((a, b) => a - b);
      return { count: frames.length, median: frames[Math.floor(frames.length / 2)], p95: frames[Math.floor(frames.length * 0.95)], max: frames[frames.length - 1] };
    });
    console.log(`[perf] scroll of 500 rows: ${stats.count} frames, median ${stats.median.toFixed(1)} ms, p95 ${stats.p95.toFixed(1)} ms, max ${stats.max.toFixed(1)} ms`);
    expect(stats.count).toBeGreaterThan(20);
    expect(stats.median).toBeLessThan(34);
    expect(stats.p95, 'p95 frame time while scrolling').toBeLessThan(120);
  });

  test('the page size select offers 500 as its maximum; choosing it loads a virtual page', async ({ app }) => {
    await app.goto('#/rules');
    await expect(app.rows.first()).toBeVisible();
    const size = app.root.getByRole('combobox', { name: 'Per page' });
    await expect(size.locator('option')).toHaveText(['25', '50', '100', '250', '500']);
    await size.selectOption('500');
    await expect(app.page).toHaveURL(/per_page=500/);
    await expect(app.root.getByRole('navigation', { name: 'Pagination' })).toContainText('1–500 of 10,032');
    await expect(app.root.locator('.rules-scroll')).toHaveAttribute('data-virtual', 'true');
  });

  test('the last page is reachable and lists the last rules', async ({ app }) => {
    await app.goto('#/rules?per_page=500&sort=source&dir=asc&page=21');
    await expect(app.root.getByRole('navigation', { name: 'Pagination' })).toContainText('10,001–10,032 of 10,032');
    await expect(app.rows).toHaveCount(32);
    await expect(app.root.getByRole('button', { name: 'Next page' })).toBeDisabled();
  });

  /**
   * The list request costs about 1.1 s on the server at 10,000 rules (it analyses chains and conflicts over all rules), so a
   * 2 s budget leaves little room and a busy machine can spoil a single measurement. Three tries: the fastest must meet the
   * budget (a real regression is slow every time), none may take longer than 6 s.
   */
  async function within(label: string, budgetMs: number, tries: Array<() => Promise<void>>): Promise<void> {
    const times: number[] = [];
    for (const attempt of tries) {
      const t0 = Date.now();
      await attempt();
      times.push(Date.now() - t0);
    }
    console.log(`[perf] ${label}: ${times.join(', ')} ms`);
    expect(Math.min(...times), `${label} took ${times.join(', ')} ms`).toBeLessThan(budgetMs);
    expect(Math.max(...times), `${label} took ${times.join(', ')} ms`).toBeLessThan(6_000);
  }

  test('search and filters answer within 2 seconds on 10,000 rules', async ({ app }) => {
    await app.goto('#/rules?per_page=50');
    await expect(app.rows.first()).toBeVisible();
    const pager = app.root.getByRole('navigation', { name: 'Pagination' });
    await expect(pager).toContainText('of 10,032');

    const search = (text: string, source: string) => async () => {
      await app.searchbox.fill(text);
      await expect(app.rows).toHaveCount(1);
      await expect(app.row(source)).toBeVisible();
    };
    await within('search for one rule in 10,032', 2_000, [search('/gen/r07777', '/gen/r07777'), search('/gen/r02345', '/gen/r02345'), search('/gen/r09876', '/gen/r09876')]);

    await app.searchbox.fill('');
    await expect(pager).toContainText('of 10,032');
    // the wildcard rules are every 50th: /gen/w00050/*, /gen/w00100/*, ... (200 of them, 19 below 1,000, 20 below 2,000);
    // each try ends in a different count, so the pager cannot still show the answer of the try before
    const count = (text: string, total: string) => async () => {
      await app.searchbox.fill(text);
      await expect(pager).toContainText(`of ${total}`);
    };
    await within('search "gen/w", "gen/w00", "gen/w01" (wildcard rules)', 2_000, [count('gen/w', '200'), count('gen/w00', '19'), count('gen/w01', '20')]);

    // a filter: status code 302 (every tenth generated rule plus the seeded 302s), with the page load included
    const filter = (status: string) => async () => {
      await app.goto(`#/rules?per_page=50&status=${status}`);
      await expect(app.rows.first()).toBeVisible();
    };
    await within('status filter incl. page load', 4_000, [filter('302'), filter('301'), filter('302')]);
    await app.goto('#/rules?per_page=50&status=302');
    await expect(pager).toContainText(/of 1,0\d\d/);
  });

  test('sorting the whole set by source responds within 2 seconds', async ({ app }) => {
    await app.goto('#/rules?per_page=50');
    await expect(app.rows.first()).toBeVisible();
    const sortBy = (name: string) => async () => {
      await app.root.getByRole('button', { name, exact: true }).click();
      await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
    };
    await within('sort by source, hits, source', 2_000, [sortBy('Source'), sortBy('Hits'), sortBy('Source')]);
    await expect(app.root.getByRole('columnheader', { name: /Source/ })).toHaveAttribute('aria-sort', /ascending|descending/);
  });
});
