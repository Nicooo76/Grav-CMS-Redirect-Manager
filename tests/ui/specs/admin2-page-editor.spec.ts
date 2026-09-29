import type { Page } from '@playwright/test';
import { test, expect } from '../support/test';
import { hostDialog } from '../support/host-dialog';
import { expectBadgeInSync, pendingItem, pendingPanel, rulesFor, unseenPanel } from '../support/auto-page';

/**
 * The page changes of these tests are made in Admin 2's own page editor (the pages UI of the host), not through the
 * API. What the plugin does with them is asserted in its own Rules tab, on the public site and in the sidebar badge.
 */

const PLUGIN = '/admin/plugin/redirect-manager';

async function openPageEditor(page: Page, baseUrl: string, route: string): Promise<void> {
  await page.goto(`${baseUrl}/admin/pages/edit${route}`);
  await page.getByRole('button', { name: 'Advanced', exact: true }).waitFor();
}

/** Advanced tab, folder name field: the slug of the page (the folder is what the route is made of). */
async function renameFolder(page: Page, name: string): Promise<void> {
  await page.getByRole('button', { name: 'Advanced', exact: true }).click();
  await page.getByRole('textbox', { name: 'folder-name' }).fill(name);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
}

test.describe('automatic redirects: pages changed in the Admin 2 page editor', () => {
  test('renaming the folder in the page editor creates an automatic rule that shows in the Rules tab and redirects the old URL', async ({ page, app, api, site, request }) => {
    const OLD = '/blog/wintercamping-tipps';
    const NEW = '/blog/wintercamping-guide';
    await openPageEditor(page, site.baseUrl, OLD);
    await renameFolder(page, 'wintercamping-guide');

    // the host confirms the save and follows the page to its new address
    await expect(page.locator('li.grav-toast').filter({ hasText: 'Page saved and moved' })).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/admin/pages/edit${NEW}$`));

    const rules = await rulesFor(api, OLD);
    expect(rules).toHaveLength(1);
    expect(rules[0]).toMatchObject({ source: OLD, target: NEW, status: 301, origin: 'auto' });

    // the public site: old URL answers 301 to the new one, the new one is the page
    const old = await request.get(`${site.baseUrl}${OLD}`, { maxRedirects: 0 });
    expect(old.status()).toBe(301);
    expect(old.headers()['location']).toBe(NEW);
    expect((await request.get(`${site.baseUrl}${NEW}`, { maxRedirects: 0 })).status()).toBe(200);

    // our own Rules tab: the panel for new automatic redirects, the row, the sidebar badge
    await app.goto('#/rules');
    const panel = unseenPanel(app);
    await expect(panel.getByRole('heading', { level: 2 })).toHaveText('1 new automatic redirect');
    await expect(panel.locator('li.item')).toHaveCount(1);
    await expect(panel.locator('li.item')).toContainText(OLD);
    await expect(panel.locator('li.item')).toContainText(NEW);
    await expect(app.row(OLD)).toContainText('Automatic');
    await expectBadgeInSync(page, api, 1);

    await panel.getByRole('button', { name: 'Mark as seen' }).click();
    await expect(panel).toHaveCount(0);
    await expectBadgeInSync(page, api, 0);
  });

  test('renaming a folder back removes the rule again, no loop is left behind', async ({ page, app, api, site, request }) => {
    const OLD = '/blog/wintercamping-tipps';
    await openPageEditor(page, site.baseUrl, OLD);
    await renameFolder(page, 'wintercamping-guide');
    await expect(page).toHaveURL(/wintercamping-guide$/);
    expect(await rulesFor(api, OLD)).toHaveLength(1);

    await page.getByRole('button', { name: 'Advanced', exact: true }).click();
    await page.getByRole('textbox', { name: 'folder-name' }).fill('wintercamping-tipps');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/pages/edit${OLD}$`));

    await expect.poll(async () => (await rulesFor(api, OLD)).length).toBe(0);
    expect((await request.get(`${site.baseUrl}${OLD}`, { maxRedirects: 0 })).status()).toBe(200);
    await app.goto('#/rules');
    await expect(unseenPanel(app)).toHaveCount(0);
  });

  test('deleting a page with children in the page editor (policy ask) waits in our panel until it is resolved there', async ({ page, app, api, site, request }) => {
    const OUTLET = '/shop/outlet';
    await openPageEditor(page, site.baseUrl, OUTLET);
    await page.getByRole('button', { name: 'Delete Page', exact: true }).click();
    const dialog = hostDialog(page, 'Delete Page');
    await expect(dialog).toContainText('Delete "Outlet" at /shop/outlet?');
    await dialog.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/pages$/);

    // policy ask: nothing is written before somebody decides
    expect((await request.get(`${site.baseUrl}${OUTLET}`, { maxRedirects: 0 })).status()).toBe(404);
    expect(await rulesFor(api, OUTLET)).toEqual([]);
    const pending = (await api('GET', '/redirects/pending')).data;
    expect(pending).toHaveLength(1);
    expect(pending[0]).toMatchObject({ route: OUTLET, title: 'Outlet', children: ['/shop/outlet/sale'] });

    await page.goto(`${site.baseUrl}${PLUGIN}#/rules`);
    await app.ready();
    await expect(pendingPanel(app).getByRole('heading', { level: 2 })).toHaveText('1 deleted page needs a decision');
    await expectBadgeInSync(page, api, 1);

    await pendingItem(app, OUTLET).getByRole('button', { name: 'To parent page' }).click();
    await expect(app.toast('Saved for Outlet')).toBeVisible();
    await expect(pendingPanel(app)).toHaveCount(0);

    const rules = await rulesFor(api, OUTLET);
    expect(rules.map((r) => `${r.source} -> ${r.target} ${r.status} ${r.origin}`)).toEqual([`${OUTLET} -> /shop 301 auto`, `${OUTLET}/* -> /shop 301 auto`]);
    for (const path of [OUTLET, `${OUTLET}/sale`]) {
      const res = await request.get(`${site.baseUrl}${path}`, { maxRedirects: 0 });
      expect(res.status(), path).toBe(301);
      expect(res.headers()['location']).toBe('/shop');
    }
    await expectBadgeInSync(page, api);
  });
});
