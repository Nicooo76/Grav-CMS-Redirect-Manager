import { test, expect } from '../support/test';
import { apiFor } from '../support/api';

// The account has api.access and api.redirects.read, nothing else.
test.use({ asUser: 'readonly' });

test.describe('read-only user', () => {
  test('rules list: note, no write controls, bulk bar only exports', async ({ app }) => {
    await app.goto('#/rules');
    await expect(app.root.locator('[data-testid=read-only-note]')).toContainText('You can view redirects but not change them');
    await expect(app.root.getByRole('button', { name: 'New redirect' })).toHaveCount(0);

    const row = app.rows.first();
    await expect(row).toBeVisible();
    await expect(row.getByRole('switch')).toBeDisabled();
    await expect(row.locator('button.cell-edit')).toHaveCount(0);
    await expect(row.locator('button.drag')).toHaveAttribute('aria-disabled', 'true');

    await row.getByRole('checkbox').check();
    const bar = app.root.getByRole('toolbar', { name: 'Bulk actions' });
    await expect(bar.getByRole('button', { name: 'Export selected' })).toBeVisible();
    await expect(bar.getByRole('button', { name: 'Delete' })).toHaveCount(0);
    await expect(bar.getByRole('button', { name: 'Disable' })).toHaveCount(0);
    await expect(bar.getByRole('button', { name: 'Enable' })).toHaveCount(0);
  });

  test('row menu offers View and Test only', async ({ app }) => {
    await app.goto('#/rules');
    await app.rows.first().getByRole('button', { name: /^Actions for/ }).click();
    const items = app.root.getByRole('menuitem');
    await expect(items).toHaveCount(2);
    await expect(items.nth(0)).toHaveText(/View/);
    await expect(items.nth(1)).toHaveText(/Test/);
  });

  test('editor opens read-only: disabled fieldset, no Save or Delete', async ({ app }) => {
    await app.goto('#/rules');
    await app.rows.first().getByRole('link').first().click();
    await expect(app.editor).toBeVisible();
    await expect(app.editor.locator('fieldset.ro')).toHaveAttribute('disabled', '');
    await expect(app.editor.getByRole('note')).toContainText('You can view redirects but not change them');
    await expect(app.field('Source')).toBeDisabled();
    await expect(app.editor.getByRole('button', { name: /^(Save|Create redirect)/ })).toHaveCount(0);
    await expect(app.editor.getByRole('button', { name: 'Delete' })).toHaveCount(0);
    await app.page.keyboard.press('Escape');
    await expect(app.editor).toBeHidden();
  });

  test('n does nothing and #/rules/new goes back to the list', async ({ app, page }) => {
    await app.goto('#/rules');
    await expect(app.rows.first()).toBeVisible();
    await page.locator('body').press('n');
    await expect(app.editor).toBeHidden();
    await expect(page).toHaveURL(/#\/rules$/);

    await app.goto('#/rules/new');
    await expect(app.editor).toBeHidden();
    await expect(page).toHaveURL(/#\/rules(\?.*)?$/);
    await expect(app.rows.first()).toBeVisible();
  });

  test('404 monitor: no create, ignore, done or delete actions', async ({ app }) => {
    await app.goto('#/404');
    const row = app.root.locator('tbody tr[data-path]').first();
    await expect(row).toBeVisible();
    await expect(app.root.getByRole('button', { name: /^Create redirect for/ })).toHaveCount(0);
    await row.locator('button[aria-haspopup=menu]').click();
    const items = app.root.getByRole('menuitem');
    await expect(items).toHaveCount(1);
    for (const gone of ['Ignore', 'Mark as done', 'Delete']) await expect(items.filter({ hasText: gone })).toHaveCount(0);
    // the suggestion badge of a row cannot be used to create a rule
    for (const badge of await app.root.locator('button.sugg').all()) await expect(badge).toBeDisabled();
  });

  test('suggestions: no accept, reject, generate or bulk review', async ({ app }) => {
    await app.goto('#/suggestions');
    await expect(app.root.locator('tbody tr').first()).toBeVisible();
    await expect(app.root.getByRole('button', { name: /^(Accept|Reject)/ })).toHaveCount(0);
    await expect(app.root.getByRole('button', { name: 'Generate suggestions' })).toHaveCount(0);
    await expect(app.root.getByRole('button', { name: /Review and accept/ })).toHaveCount(0);
  });

  test('import is replaced by a notice, export still works', async ({ app, page }) => {
    await app.goto('#/import');
    await expect(app.root.getByText('Import needs more permissions')).toBeVisible();
    await expect(app.root.getByText('This needs permission to change redirects.')).toBeVisible();
    await expect(app.root.locator('input[type=file]')).toHaveCount(0);

    await app.goto('#/export');
    const download = page.waitForEvent('download');
    await app.root.getByRole('button', { name: 'Download CSV' }).click();
    expect((await download).suggestedFilename()).toMatch(/\.csv$/);
  });

  test('pending decisions cannot be resolved by a read-only user', async ({ app }) => {
    const admin = await apiFor('admin');
    expect((await admin('DELETE', '/pages/blog/packliste-fuer-camper')).status).toBe(204);
    await app.goto('#/rules');
    const item = app.root.locator('[data-testid=pending-panel] li.item');
    await expect(item).toHaveCount(1);
    for (const name of [/Gone/, 'To parent page', /Redirect to/, 'Dismiss']) await expect(item.getByRole('button', { name })).toBeDisabled();
  });

  test('API: writes are refused with 403, stats say manage=false', async ({ api }) => {
    const stats = await api('GET', '/redirects/stats');
    expect(stats.status).toBe(200);
    expect(stats.meta.permissions).toMatchObject({ read: true, manage: false });

    const write = await api('POST', '/redirects/rules', { source: '/x', target: '/y' });
    expect(write.status).toBe(403);
    const list = await api('GET', '/redirects/rules?per_page=1');
    expect(list.status).toBe(200);
    expect(list.meta.total).toBe(32);
    expect((await api('DELETE', `/redirects/rules/${list.data[0].id}`)).status).toBe(403);
    expect((await api('POST', '/redirects/badge/seen')).status).toBe(403);
  });
});
