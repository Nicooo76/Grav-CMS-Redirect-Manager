import type { Page } from '@playwright/test';
import { test, expect } from '../support/test';
import { defaultPluginConfig } from '../support/site';
import { ruleOf } from '../support/rules-page';
import { seriesOf, sparkLine } from '../support/spark';

/**
 * Row badges (expired, chain, loop, conflict, dead target), the live check from the UI ("Check now") and what the
 * dashboard widget shows afterwards: the dead target tile and the sparklines.
 */

/** the live check calls the site itself; external targets would need the network and are skipped */
const CHECKER_ON = { ...defaultPluginConfig, checker: { enabled: true, check_external: false, manual_interval: 300, timeout: 5 } };
const DEAD_SOURCE = '/tot-ziel';
const DEAD_TARGET = '/dieses-ziel-gibt-es-nicht';

const badgesOf = (row: import('@playwright/test').Locator) => row.locator('td.c-status .badge');

test.describe('rule badges in the list', () => {
  test('Expired, Scheduled, Disabled, Chain, Loop, Conflict and Active are shown in the row', async ({ app }) => {
    await app.goto('#/rules');
    // state badges: exactly one per row
    await expect(badgesOf(app.row('/abgelaufen')).first()).toHaveText('Expired');
    await expect(badgesOf(app.row('/bald')).first()).toHaveText('Scheduled');
    await expect(badgesOf(app.row('/aktion/winter')).first()).toHaveText('Disabled');
    await expect(badgesOf(app.row('/faq-alt')).first()).toHaveText('Active');
    // problem badges follow the state badge
    await expect(badgesOf(app.row('/kette-a'))).toHaveText(['Active', 'Chain']);
    await expect(badgesOf(app.row('/kette-b'))).toHaveText(['Active', 'Chain']);
    await expect(badgesOf(app.row('/kette-c'))).toHaveText(['Active']);
    await expect(badgesOf(app.row('/schleife-a'))).toHaveText(['Active', 'Loop']);
    await expect(badgesOf(app.row('/schleife-b'))).toHaveText(['Active', 'Loop']);
    // two rules for /konflikt: both are marked
    const konflikt = app.rows.filter({ has: app.page.getByRole('link', { name: '/konflikt', exact: true }) });
    await expect(konflikt).toHaveCount(2);
    for (const row of await konflikt.all()) await expect(badgesOf(row)).toHaveText(['Active', 'Conflict']);
    // the expired rule is inactive: its row is dimmed and the switch still says "enabled"
    await expect(app.row('/abgelaufen')).toHaveClass(/disabled/);
  });

  test('every badge explains itself in a tooltip', async ({ app }) => {
    await app.goto('#/rules');
    const cases: Array<[string, RegExp]> = [
      ['/abgelaufen', /Its end date has passed, so it no longer matches\./],
      ['/kette-a', /The target is redirected again\. Visitors take more than one hop\./],
      ['/schleife-a', /The redirects lead back to this rule\. Visitors would never arrive\./],
    ];
    for (const [source, text] of cases) {
      const badge = badgesOf(app.row(source)).last();
      // the list is long: bring the row into view first, the tooltip is placed next to the badge on hover
      await badge.scrollIntoViewIfNeeded();
      await badge.hover();
      await expect(app.root.getByRole('tooltip').filter({ hasText: text })).toBeVisible();
      await app.page.mouse.move(0, 0);
    }
  });

  test('the quick filters count the badges and lead to the same rows', async ({ app }) => {
    await app.goto('#/rules');
    const quick = app.root.getByRole('group', { name: 'Quick filters by badge' });
    await expect(quick.getByRole('button', { name: /^Expired/ })).toContainText('1');
    await quick.getByRole('button', { name: /^Expired/ }).click();
    await expect(app.rows).toHaveCount(1);
    await expect(badgesOf(app.rows.first()).first()).toHaveText('Expired');
  });
});

test.describe('live check ("Check now") and the dead target badge', () => {
  test.beforeEach(async ({ site, api }) => {
    site.setConfig(CHECKER_ON);
    const made = await api('POST', '/redirects/rules', { source: DEAD_SOURCE, target: DEAD_TARGET, match_type: 'exact', status: 301 });
    expect(made.status).toBe(201);
  });

  test('before the first run: "not checked yet"; Check now reports the result, the dead rule gets the badge', async ({ app, api }) => {
    await app.goto('#/rules');
    const line = app.root.locator('.check[role="status"]');
    await expect(line).toContainText('The targets have not been checked yet.');
    await expect(badgesOf(app.row(DEAD_SOURCE))).toHaveText(['Active']);

    await app.root.getByRole('button', { name: 'Check now' }).click();
    // one request per target, spaced by the checker: give it time
    const toast = app.toast(/targets? checked, \d+ unreachable\./);
    await expect(toast).toBeVisible({ timeout: 45_000 });
    const summary = (await api('GET', '/redirects/checks')).data as { last_run: string; results: Array<{ rule_id?: string; ok: boolean; error?: string | null; source?: string }> };
    // skipped targets (a pattern with $1, an external host) are not dead: they carry an error code "skipped_..."
    const dead = summary.results.filter((r) => !r.ok && !String(r.error).startsWith('skipped'));
    expect(dead.map((r) => r.source)).toContain(DEAD_SOURCE);
    await expect(line).toContainText(`${dead.length} unreachable`);
    await expect(line).not.toContainText('not been checked');

    // the rules list refreshed: the badge is in the row, and the API agrees
    await expect(badgesOf(app.row(DEAD_SOURCE))).toHaveText(['Active', 'Dead target']);
    await expect(badgesOf(app.row('/faq-alt'))).toHaveText(['Active']);
    expect((await ruleOf(api, DEAD_SOURCE)).badges).toContain('dead_target');

    // the badge filter finds it
    await app.goto('#/rules?badge=dead_target');
    await expect(app.row(DEAD_SOURCE)).toBeVisible();
    await expect(app.rows).toHaveCount(dead.length);
  });

  test('a second run within the interval is refused with a friendly message, nothing changes', async ({ app }) => {
    await app.goto('#/rules');
    await app.root.getByRole('button', { name: 'Check now' }).click();
    await expect(app.toast(/targets? checked/)).toBeVisible({ timeout: 45_000 });
    await app.root.getByRole('button', { name: 'Check now' }).click();
    await expect(app.toast(/Checked a moment ago\. Try again in \d+ seconds?\./)).toBeVisible();
  });

  test('editing the dead rule drops its badge until the next check', async ({ app, api }) => {
    await app.goto('#/rules');
    await app.root.getByRole('button', { name: 'Check now' }).click();
    await expect(badgesOf(app.row(DEAD_SOURCE))).toHaveText(['Active', 'Dead target'], { timeout: 45_000 });
    // stored results and edits have a resolution of one second: a result only loses its meaning when the edit is
    // newer than the check (ApiSystemTest::testEditingARuleDropsItsDeadBadge sleeps for the same reason)
    const checks = (await api('GET', '/redirects/checks')).data as { results: Array<{ source?: string; checked_at: string }> };
    const checkedAt = Date.parse(checks.results.find((r) => r.source === DEAD_SOURCE)!.checked_at);
    await expect.poll(() => Date.now()).toBeGreaterThan(checkedAt + 2_000);
    const row = app.row(DEAD_SOURCE);
    await row.getByRole('button', { name: `Edit target: ${DEAD_TARGET}` }).click();
    const input = row.getByRole('textbox', { name: `Target of ${DEAD_SOURCE}` });
    await input.fill('/faq');
    await input.press('Enter');
    await expect(badgesOf(row)).toHaveText(['Active']);
    await expect.poll(async () => (await ruleOf(api, DEAD_SOURCE)).badges).not.toContain('dead_target');
  });
});

const widget = (page: Page) => page.locator('grav-widget-redirect-manager-overview');

test.describe('dashboard widget after a live check', () => {
  test('dead targets tile is not zero, the sparklines draw the series of the API, the tile leads to the dead rules', async ({ app, api, page, site }) => {
    site.setConfig(CHECKER_ON);
    expect((await api('POST', '/redirects/rules', { source: DEAD_SOURCE, target: DEAD_TARGET, match_type: 'exact', status: 301 })).status).toBe(201);
    const run = await api('POST', '/redirects/checks/run');
    expect(run.status).toBe(200);
    const deadCount = run.data.dead as number;
    expect(deadCount).toBeGreaterThanOrEqual(1);

    const statsResponse = page.waitForResponse((r) => r.request().method() === 'GET' && /\/redirects\/stats(\?|$)/.test(r.url()));
    await page.goto(`${site.baseUrl}/admin/`);
    const stats = (await (await statsResponse).json()).data;
    await expect(widget(page).locator('a.tile').first()).toBeVisible();
    expect(stats.dead_targets).toBe(deadCount);

    // the dead targets tile: the number, the alarm colour, the accessible name
    const dead = widget(page).getByRole('link', { name: deadCount === 1 ? '1 dead target' : `${deadCount} dead targets` });
    await expect(dead).toBeVisible();
    await expect(dead).toHaveClass(/\bbad\b/);
    await expect(dead.locator('.val')).toHaveText(String(deadCount));
    await expect(widget(page).getByRole('link', { name: 'No dead targets' })).toHaveCount(0);

    // sparklines: the first two tiles (404s and hits) draw a line for the API series
    const sparks = widget(page).locator('a.tile svg.spark');
    await expect(sparks).toHaveCount(2);
    for (const [index, key] of [[0, 'not_found_by_day'], [1, 'hits_by_day']] as const) {
      const series = seriesOf(stats[key]);
      expect(series.length, key).toBeGreaterThan(1);
      expect(Math.max(...series), key).toBeGreaterThan(0);
      const line = sparks.nth(index).locator('path[fill="none"]');
      await expect(line, key).toHaveCount(1);
      const d = (await line.getAttribute('d'))!;
      expect(d, key).toBe(sparkLine(series, 100, 24));
      // a real line: it starts with M, has a point per day and is not flat
      expect(d.startsWith('M')).toBe(true);
      expect(d.split('L').length).toBe(series.length);
      const ys = [...d.matchAll(/,([\d.]+)/g)].map((m) => Number(m[1]));
      expect(Math.max(...ys) - Math.min(...ys), key).toBeGreaterThan(5);
      // and a filled area below it
      await expect(sparks.nth(index).locator('path[opacity]')).toHaveCount(1);
    }
    // the tiles that carry no series draw none
    await expect(widget(page).getByRole('link', { name: /open suggestions/ }).locator('svg.spark')).toHaveCount(0);
    await expect(dead.locator('svg.spark')).toHaveCount(0);

    await dead.click();
    await expect(page).toHaveURL(/\/admin\/plugin\/redirect-manager#\/rules\?badge=dead_target$/);
    await app.ready();
    await expect(app.row(DEAD_SOURCE)).toBeVisible();
    await expect(app.rows).toHaveCount(deadCount);
    for (const row of await app.rows.all()) await expect(badgesOf(row).last()).toHaveText('Dead target');
  });
});
