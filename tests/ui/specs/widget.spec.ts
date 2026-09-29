import { test, expect } from '../support/test';
import { pendingItem, pendingPanel } from '../support/auto-page';
import type { Page } from '@playwright/test';

/** the dashboard card, its numbers and where its links go */

const PLUGIN = '/admin/plugin/redirect-manager';

function widget(page: Page) {
  return page.locator('grav-widget-redirect-manager-overview');
}

/** opens the Admin 2 dashboard and returns the stats the widget itself loaded (the dashboard's own requests can add 404 entries) */
async function openDashboard(page: Page, baseUrl: string) {
  const statsResponse = page.waitForResponse((r) => r.request().method() === 'GET' && /\/redirects\/stats(\?|$)/.test(r.url()));
  await page.goto(`${baseUrl}/admin/`);
  const stats = (await (await statsResponse).json()).data;
  await expect(widget(page).getByRole('region', { name: 'Redirects' })).toBeVisible();
  await expect(widget(page).locator('a.tile').first()).toBeVisible();
  return stats;
}

async function expectDashboardNumbers(page: Page, stats: Record<string, number>, api: (m: string, p: string) => Promise<{ data: any }>) {
  const w = widget(page);
  const notFound = w.getByRole('link', { name: `${stats.not_found_7d} not found errors in the last 7 days` });
  await expect(notFound).toContainText('404s, last 7 days');
  await expect(notFound).toContainText(`${stats.not_found_today} today`);
  const hits = w.getByRole('link', { name: `${stats.hits_7d} redirect hits in the last 7 days` });
  await expect(hits).toContainText('Redirect hits, last 7 days');
  await expect(hits).toContainText(`${stats.hits_today} today`);
  const suggestions = w.getByRole('link', { name: `${stats.open_suggestions} open suggestions` });
  await expect(suggestions).toContainText('Open suggestions');
  await expect(suggestions).toContainText(String(stats.open_suggestions));
  const dead = w.getByRole('link', { name: 'No dead targets' });
  await expect(dead).toContainText('Dead targets');

  // the figures that do not depend on the dashboard's own requests match the stats endpoint
  const fresh = (await api('GET', '/redirects/stats')).data;
  for (const key of ['hits_7d', 'hits_today', 'open_suggestions', 'dead_targets', 'rules_total', 'rules_active', 'pending_deletes']) {
    expect(stats[key], key).toBe(fresh[key]);
  }
  expect(stats.not_found_7d).toBeGreaterThanOrEqual(45);
  expect(stats.hits_7d).toBe(16);
  expect(stats.open_suggestions).toBe(7);
}

const LINKS: Array<{ name: string; label: RegExp; hash: string }> = [
  { name: '404 tile goes to the 404 monitor', label: /not found errors/, hash: '#/404' },
  { name: 'hits tile goes to the rules', label: /redirect hits/, hash: '#/rules' },
  { name: 'suggestions tile goes to the suggestions', label: /open suggestions/, hash: '#/suggestions' },
  { name: 'dead targets tile goes to the rules filtered by dead targets', label: /dead targets/, hash: '#/rules?badge=dead_target' },
];

test.describe('dashboard widget (admin)', () => {
  test('shows the numbers of /redirects/stats', async ({ page, site, api }) => {
    const stats = await openDashboard(page, site.baseUrl);
    await expectDashboardNumbers(page, stats, api);
    // no pending deletions in the seed: no extra line
    await expect(widget(page).getByText(/waits? for a decision/)).toHaveCount(0);
  });

  for (const link of LINKS) {
    test(link.name, async ({ page, site, app }) => {
      await openDashboard(page, site.baseUrl);
      await widget(page).getByRole('link', { name: link.label }).click();
      await expect(page).toHaveURL(new RegExp(`${PLUGIN}${link.hash.replace(/[?]/g, '\\?')}$`));
      await app.ready();
    });
  }

  test('a deleted page that waits for a decision is announced and links to the pending panel', async ({ page, site, api, app }) => {
    expect((await api('DELETE', '/pages/blog/packliste-fuer-camper')).status).toBe(204);
    const stats = await openDashboard(page, site.baseUrl);
    expect(stats.pending_deletes).toBe(1);

    await widget(page).getByRole('link', { name: '1 deleted page waits for a decision' }).click();
    await expect(page).toHaveURL(new RegExp(`${PLUGIN}#/rules$`));
    await app.ready();
    await expect(pendingItem(app, '/blog/packliste-fuer-camper')).toBeVisible();
    await expect(pendingPanel(app)).toHaveCount(1);
  });

  test('the numbers follow the data: a new rule and a resolved page change the widget', async ({ page, site, api }) => {
    await api('DELETE', '/pages/shop/outlet');
    await api('DELETE', '/pages/blog/packliste-fuer-camper');
    let stats = await openDashboard(page, site.baseUrl);
    expect(stats.pending_deletes).toBe(2);
    await expect(widget(page).getByRole('link', { name: '2 deleted pages wait for a decision' })).toBeVisible();

    const pending = (await api('GET', '/redirects/pending')).data as Array<{ id: string }>;
    for (const p of pending) expect((await api('POST', `/redirects/pending/${p.id}/resolve`, { action: 'dismiss' })).status).toBe(200);
    stats = await openDashboard(page, site.baseUrl);
    expect(stats.pending_deletes).toBe(0);
    await expect(widget(page).getByText(/wait for a decision/)).toHaveCount(0);
  });
});

test.describe('dashboard widget (read-only user)', () => {
  test.use({ asUser: 'readonly' });

  // Product bug: onApiDashboardWidgets() in redirect-manager.php declares 'authorize' as an array
  // (['admin.super', 'api.super', 'api.redirects.read']). Sidebar items accept that, but the API plugin's
  // DashboardLayoutResolver passes the value to PermissionResolver::resolve(string) for every non-super user:
  // GET /dashboard/widgets answers 500 and the dashboard of this user is empty. Fix: a string,
  // 'api.redirects.read' (super users skip the check anyway). Remove the fixme when that is fixed.
  test.fixme('renders the same numbers, the links still work', async ({ page, site, api, app }) => {
    const stats = await openDashboard(page, site.baseUrl);
    await expectDashboardNumbers(page, stats, api);

    await widget(page).getByRole('link', { name: /open suggestions/ }).click();
    await expect(page).toHaveURL(new RegExp(`${PLUGIN}#/suggestions$`));
    await app.ready();
    await expect(app.root.locator('[data-testid=read-only-note]')).toBeVisible();
  });
});
