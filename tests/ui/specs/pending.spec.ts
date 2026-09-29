import { test, expect } from '../support/test';
import { defaultPluginConfig } from '../support/site';
import { badge, expectBadgeInSync, pendingItem, pendingPanel, rulesFor, unseenPanel } from '../support/auto-page';

const PACKLISTE = '/blog/packliste-fuer-camper';
const OUTLET = '/shop/outlet';

test.describe('automatic redirects: deleted pages and unseen rules', () => {
  test('policy ask: a deleted page waits in the pending panel, the sidebar badge counts it', async ({ app, api, page, site, request }) => {
    expect((await api('DELETE', '/pages' + OUTLET)).status).toBe(204);
    expect((await api('DELETE', '/pages' + PACKLISTE)).status).toBe(204);

    const pending = (await api('GET', '/redirects/pending')).data;
    expect(pending.map((p: { route: string }) => p.route).sort()).toEqual([PACKLISTE, OUTLET]);
    const outlet = pending.find((p: { route: string }) => p.route === OUTLET);
    expect(outlet).toMatchObject({ title: 'Outlet', children: ['/shop/outlet/sale'], suggested_parent: '/shop' });
    expect((await api('GET', '/redirects/badge')).data).toEqual({ count: 2, unseen: 0, pending: 2 });

    // nothing is written before somebody decides: the old address answers 404
    expect(await rulesFor(api, OUTLET)).toEqual([]);
    expect((await request.get(`${site.baseUrl}${OUTLET}`, { maxRedirects: 0 })).status()).toBe(404);

    await app.goto('#/rules');
    await expect(pendingPanel(app).getByRole('heading', { level: 2 })).toHaveText('2 deleted pages need a decision');
    await expect(pendingPanel(app).locator('li.item')).toHaveCount(2);
    const item = pendingItem(app, OUTLET);
    await expect(item).toContainText('Outlet');
    await item.locator('summary').click();
    await expect(item.getByText('/shop/outlet/sale', { exact: true })).toBeVisible();
    await expect(unseenPanel(app)).toHaveCount(0);
    await expectBadgeInSync(page, api, 2);
  });

  test('"To parent page" writes a 301 to the parent, panel and badge update', async ({ app, api, page, site, request }) => {
    await api('DELETE', '/pages' + PACKLISTE);
    await api('DELETE', '/pages' + OUTLET);
    await app.goto('#/rules');
    await expectBadgeInSync(page, api, 2);

    await pendingItem(app, PACKLISTE).getByRole('button', { name: 'To parent page' }).click();
    await expect(app.toast('Saved for Packliste fuer Camper')).toBeVisible();
    await expect(pendingItem(app, PACKLISTE)).toHaveCount(0);
    await expect(pendingItem(app, OUTLET)).toHaveCount(1);
    await expect(pendingPanel(app).getByRole('heading', { level: 2 })).toHaveText('1 deleted page needs a decision');

    const rules = await rulesFor(api, PACKLISTE);
    expect(rules).toHaveLength(1);
    expect(rules[0]).toMatchObject({ source: PACKLISTE, target: '/blog', status: 301, origin: 'auto' });
    const res = await request.get(`${site.baseUrl}${PACKLISTE}`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/blog');

    expect((await api('GET', '/redirects/pending')).data).toHaveLength(1);
    await expectBadgeInSync(page, api);
    // the sidebar also agrees after a fresh page load
    await app.reload();
    await expectBadgeInSync(page, api);
  });

  test('"Gone" writes a 410 for the page and, for a page with children, for its descendants', async ({ app, api, page, site, request }) => {
    await api('DELETE', '/pages' + OUTLET);
    await app.goto('#/rules');
    await expectBadgeInSync(page, api, 1);

    await pendingItem(app, OUTLET).getByRole('button', { name: /^Gone/ }).click();
    await expect(app.toast('Saved for Outlet')).toBeVisible();
    await expect(pendingPanel(app)).toHaveCount(0);

    const rules = await rulesFor(api, OUTLET);
    expect(rules.map((r) => `${r.source} ${r.status} ${r.origin}`)).toEqual([`${OUTLET} 410 auto`, `${OUTLET}/* 410 auto`]);
    for (const path of [OUTLET, `${OUTLET}/sale`]) {
      expect((await request.get(`${site.baseUrl}${path}`, { maxRedirects: 0 })).status(), path).toBe(410);
    }
    // the parent is not touched
    expect((await request.get(`${site.baseUrl}/shop`, { maxRedirects: 0 })).status()).toBe(200);
    await expectBadgeInSync(page, api);
  });

  test('"Redirect to..." picks a target with the page picker and creates the rule', async ({ app, api, page, site, request }) => {
    await api('DELETE', '/pages' + PACKLISTE);
    await app.goto('#/rules');
    const item = pendingItem(app, PACKLISTE);
    await item.getByRole('button', { name: /^Redirect to/ }).click();

    // the picker starts with the parent page and the button needs a valid target
    const combo = item.getByRole('combobox', { name: 'Target page or URL' });
    await expect(combo).toHaveValue('/blog');
    await combo.fill('nonsense');
    await expect(item.getByRole('button', { name: 'Create redirect' })).toBeDisabled();
    await combo.fill('/info');
    await expect(item.getByRole('button', { name: 'Create redirect' })).toBeEnabled();
    await item.getByRole('button', { name: 'Create redirect' }).click();

    await expect(app.toast('Saved for Packliste fuer Camper')).toBeVisible();
    await expect(pendingPanel(app)).toHaveCount(0);
    const rules = await rulesFor(api, PACKLISTE);
    expect(rules).toHaveLength(1);
    expect(rules[0]).toMatchObject({ source: PACKLISTE, target: '/info', status: 301, origin: 'auto' });
    const res = await request.get(`${site.baseUrl}${PACKLISTE}`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/info');
    await expectBadgeInSync(page, api);
  });

  test('"Redirect to..." can be cancelled without writing anything', async ({ app, api }) => {
    await api('DELETE', '/pages' + PACKLISTE);
    await app.goto('#/rules');
    const item = pendingItem(app, PACKLISTE);
    await item.getByRole('button', { name: /^Redirect to/ }).click();
    await expect(item.getByRole('combobox', { name: 'Target page or URL' })).toBeVisible();
    await item.getByRole('button', { name: 'Cancel' }).click();
    await expect(item.getByRole('combobox')).toHaveCount(0);
    expect(await rulesFor(api, PACKLISTE)).toEqual([]);
    expect((await api('GET', '/redirects/pending')).data).toHaveLength(1);
  });

  test('"Dismiss" forgets the decision and creates no rule', async ({ app, api, page, site, request }) => {
    await api('DELETE', '/pages' + PACKLISTE);
    await app.goto('#/rules');
    await expectBadgeInSync(page, api, 1);

    await pendingItem(app, PACKLISTE).getByRole('button', { name: 'Dismiss' }).click();
    await expect(app.toast('Packliste fuer Camper dismissed')).toBeVisible();
    await expect(pendingPanel(app)).toHaveCount(0);
    expect((await api('GET', '/redirects/pending')).data).toEqual([]);
    expect(await rulesFor(api, PACKLISTE)).toEqual([]);
    expect((await request.get(`${site.baseUrl}${PACKLISTE}`, { maxRedirects: 0 })).status()).toBe(404);
    await expect.poll(async () => (await badge(api)).count).toBe(0);
    await expectBadgeInSync(page, api);
  });

  test('policy parent: the rules show up in the unseen panel, "Mark as seen" clears it and the badge', async ({ app, api, page, site, request }) => {
    site.reset({ ...defaultPluginConfig, auto_redirect: { enabled: true, status: 301, children: 'wildcard', on_delete: 'parent' } });
    expect((await api('DELETE', '/pages' + OUTLET)).status).toBe(204);

    // no decision needed, the rules exist and are not seen yet
    expect((await api('GET', '/redirects/pending')).data).toEqual([]);
    expect((await api('GET', '/redirects/badge')).data).toEqual({ count: 2, unseen: 2, pending: 0 });
    const res = await request.get(`${site.baseUrl}${OUTLET}/sale`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/shop');

    await app.goto('#/rules');
    await expect(pendingPanel(app)).toHaveCount(0);
    const panel = unseenPanel(app);
    await expect(panel.getByRole('heading', { level: 2 })).toHaveText('2 new automatic redirects');
    await expect(panel.locator('li.item')).toHaveCount(2);
    await expect(panel.locator('li.item', { hasText: `${OUTLET}/*` })).toContainText('/shop');
    await expect(panel.locator('li.item', { hasText: OUTLET }).first()).toContainText('301');
    await expectBadgeInSync(page, api, 2);

    await panel.getByRole('button', { name: 'Mark as seen' }).click();
    await expect(panel).toHaveCount(0);
    await expect.poll(async () => await badge(api)).toEqual({ count: 0, unseen: 0, pending: 0 });
    await expectBadgeInSync(page, api);
    // the rules stay
    expect(await rulesFor(api, OUTLET)).toHaveLength(2);
    await app.reload();
    await expect(unseenPanel(app)).toHaveCount(0);
  });
});
