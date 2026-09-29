import { test, expect } from '../support/test';

test.describe('rules: create, edit, delete', () => {
  test('create through the editor (n opens it), live preview, save, frontend redirects', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();

    await app.field('Source').fill('/e2e/alte-seite');
    await app.field('Target').fill('/shop/zelte');

    // live preview: the sample defaults to the source and the API says where it goes
    await expect(app.editor.locator('#pv-result')).toContainText('/shop/zelte');
    await expect(app.editor.locator('#pv-result')).toContainText('301');

    await app.editor.getByRole('button', { name: 'Create redirect' }).click();
    await expect(app.editor).toBeHidden();
    await expect(app.toast('Redirect created')).toBeVisible();

    await app.search('e2e/alte-seite');
    await expect(app.row('/e2e/alte-seite')).toBeVisible();

    const rule = (await api('GET', '/redirects/rules?q=e2e/alte-seite')).data[0];
    expect(rule).toMatchObject({ source: '/e2e/alte-seite', target: '/shop/zelte', status: 301, origin: 'manual', enabled: true });

    const res = await request.get(`${site.baseUrl}/e2e/alte-seite`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/shop/zelte');
  });

  test('inline edit of the target and the status code', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    const row = app.row('/a-zelte');
    await row.getByRole('button', { name: /^Edit target/ }).click();
    const input = row.locator('input.inline');
    await input.fill('/shop/outlet');
    await input.press('Enter');
    await expect.poll(async () => (await api('GET', '/redirects/rules?q=/a-zelte')).data[0].target).toBe('/shop/outlet');

    await row.getByRole('button', { name: /^Edit status code/ }).click();
    await row.locator('select').selectOption('308');
    await expect.poll(async () => (await api('GET', '/redirects/rules?q=/a-zelte')).data[0].status).toBe(308);

    const res = await request.get(`${site.baseUrl}/a-zelte`, { maxRedirects: 0 });
    expect(res.status()).toBe(308);
    expect(res.headers()['location']).toBe('/shop/outlet');
  });

  test('edit in the editor: change target and note, save', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    await app.row('/f-faq').getByRole('link', { name: '/f-faq', exact: true }).click();
    await expect(app.editor).toBeVisible();
    await expect(app.editor.getByRole('heading', { name: 'Edit redirect' })).toBeVisible();
    await app.field('Target').fill('/info');
    await app.openSection('Group, tags and note');
    await app.field('Note').fill('edited in the editor');
    await app.editor.getByRole('button', { name: 'Save changes' }).click();
    await expect(app.editor).toBeHidden();
    await expect(app.toast('Redirect saved')).toBeVisible();

    const rule = (await api('GET', '/redirects/rules?q=/f-faq')).data[0];
    expect(rule).toMatchObject({ target: '/info', note: 'edited in the editor' });
    const res = await request.get(`${site.baseUrl}/f-faq`, { maxRedirects: 0 });
    expect(res.headers()['location']).toBe('/info');
  });

  test('delete, then Undo within 10 s restores the rule', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = (await api('GET', '/redirects/rules?q=/g-kontakt')).data[0];
    await app.row('/g-kontakt').getByRole('button', { name: /^Actions for/ }).click();
    await app.root.getByRole('menuitem', { name: 'Delete' }).click();
    await expect(app.row('/g-kontakt')).toHaveCount(0);
    // the row goes at once (optimistic), the server a moment later
    await expect.poll(async () => (await api('GET', '/redirects/rules?q=/g-kontakt')).data).toHaveLength(0);

    await app.toasts.getByRole('button', { name: 'Undo' }).click();
    await expect(app.row('/g-kontakt')).toBeVisible();
    const after = (await api('GET', '/redirects/rules?q=/g-kontakt')).data[0];
    expect(after.id).toBe(before.id);
    expect(after).toMatchObject({ target: before.target, status: before.status, priority: before.priority });
  });

  test('delete without Undo is persisted; the Undo offer goes away after 10 s', async ({ app, api }) => {
    await app.goto('#/rules');
    await app.row('/h-about').getByRole('button', { name: /^Actions for/ }).click();
    await app.root.getByRole('menuitem', { name: 'Delete' }).click();
    const undo = app.toasts.getByRole('button', { name: 'Undo' });
    await expect(undo).toBeVisible();
    await expect(undo).toBeHidden({ timeout: 15_000 });

    await app.reload();
    await expect(app.row('/h-about')).toHaveCount(0);
    expect((await api('GET', '/redirects/rules?q=/h-about')).data).toHaveLength(0);
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(31);
  });
});
