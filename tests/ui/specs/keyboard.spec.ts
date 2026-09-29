import { test, expect } from '../support/test';
import type { App } from '../support/app';
import { allRules, hostModal, openRowMenu, ruleOf } from '../support/rules-page';

const newButton = (app: App) => app.root.getByRole('button', { name: 'New redirect' });
const discardDialog = (app: App) => hostModal(app.page, 'Discard changes?');

function countRequests(app: App, method: string, part: string): () => number {
  let n = 0;
  app.page.on('request', (r) => {
    if (r.method() === method && r.url().includes(part)) n++;
  });
  return () => n;
}

test.describe('keyboard shortcuts', () => {
  test('n opens the editor with the focus in the Source field', async ({ app }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await expect(app.editor.getByRole('heading', { name: 'New redirect' })).toBeVisible();
    await expect(app.field('Source')).toBeFocused();
  });

  test('/ focuses the search box and selects its text', async ({ app, page }) => {
    await app.goto('#/rules');
    await page.keyboard.press('/');
    await expect(app.searchbox).toBeFocused();
    await expect(app.searchbox).toHaveValue('');

    await page.keyboard.type('zelt');
    await expect(app.searchbox).toHaveValue('zelt');
    await app.searchbox.blur();
    await expect(app.searchbox).not.toBeFocused();
    await page.keyboard.press('/');
    await expect(app.searchbox).toBeFocused();
    // the old text is selected, so typing replaces it
    await page.keyboard.type('kette');
    await expect(app.searchbox).toHaveValue('kette');
  });

  test('n and / are ignored while typing in a field', async ({ app, page }) => {
    await app.goto('#/rules');
    await app.searchbox.click();
    await page.keyboard.type('n/n');
    await expect(app.searchbox).toHaveValue('n/n');
    await expect(app.editor).toBeHidden();

    // inside the editor as well: the letters land in the field
    await app.searchbox.fill('');
    await newButton(app).click();
    await expect(app.field('Source')).toBeFocused();
    await page.keyboard.type('n/n');
    await expect(app.field('Source')).toHaveValue('n/n');
    await app.field('Target').click();
    await page.keyboard.type('/n');
    await expect(app.field('Target')).toHaveValue('/n');
    await expect(app.searchbox).not.toBeFocused();
    await expect(app.root.getByRole('dialog')).toHaveCount(1);
  });

  test('with the editor open, n and / do nothing outside a field either', async ({ app, page }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await app.field('Source').fill('/eins');
    await app.editor.getByRole('button', { name: 'Cancel' }).focus();
    await page.keyboard.press('n');
    await page.keyboard.press('/');
    await expect(app.field('Source')).toHaveValue('/eins');
    await expect(app.root.getByRole('dialog')).toHaveCount(1);
    await expect(app.searchbox).not.toBeFocused();
    await expect(discardDialog(app)).toHaveCount(0);
  });

  test('Esc closes a clean editor without asking', async ({ app, page }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await page.keyboard.press('Escape');
    await expect(app.editor).toBeHidden();
    await expect(discardDialog(app)).toHaveCount(0);
  });

  test('Esc with unsaved changes asks "Discard changes?"; Keep editing keeps them, Discard changes closes', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await app.field('Source').fill('/nicht-speichern');
    await page.keyboard.press('Escape');

    const dialog = discardDialog(app);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Keep editing' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Discard changes' })).toBeVisible();
    // Admin 2's dialog, not part of the plugin's shadow root
    await expect(app.root.getByText('Discard changes?')).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Keep editing' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.editor).toBeVisible();
    await expect(app.field('Source')).toHaveValue('/nicht-speichern');

    await page.keyboard.press('Escape');
    await expect(discardDialog(app)).toBeVisible();
    await discardDialog(app).getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.editor).toBeHidden();
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/nicht-speichern');
  });

  test('Ctrl/Cmd+Enter saves a new rule from any field', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await app.field('Source').fill('/per-tastatur');
    await app.field('Target').fill('/shop/zelte');
    await app.field('Target').press('ControlOrMeta+Enter');
    await expect(app.toast('Redirect created')).toBeVisible();
    await expect(app.editor).toBeHidden();
    expect(await ruleOf(api, '/per-tastatur')).toMatchObject({ target: '/shop/zelte', status: 301, origin: 'manual' });
    const res = await request.get(`${site.baseUrl}/per-tastatur`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
  });

  test('Ctrl/Cmd+Enter saves changes to a stored rule; without changes it just closes', async ({ app, api }) => {
    await app.goto('#/rules');
    await app.row('/g-kontakt').getByRole('link').click();
    await expect(app.field('Source')).toHaveValue('/g-kontakt');
    await app.openSection('Group, tags and note');
    await app.field('Note').fill('per Tastatur');
    await app.field('Note').press('ControlOrMeta+Enter');
    await expect(app.toast('Redirect saved')).toBeVisible();
    await expect(app.editor).toBeHidden();
    expect((await ruleOf(api, '/g-kontakt')).target).toBe('/kontakt');
    expect((await api('GET', `/redirects/rules/${(await ruleOf(api, '/g-kontakt')).id}`)).data.note).toBe('per Tastatur');

    const patches = countRequests(app, 'PATCH', '/redirects/rules/');
    await app.row('/g-kontakt').getByRole('link').click();
    await expect(app.field('Source')).toHaveValue('/g-kontakt');
    await app.field('Source').press('ControlOrMeta+Enter');
    await expect(app.editor).toBeHidden();
    expect(patches()).toBe(0);
  });

  test('Ctrl/Cmd+Enter with an invalid form does not save', async ({ app, api }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await app.field('Source').fill('/ohne-ziel');
    await app.field('Source').press('ControlOrMeta+Enter');
    await expect(app.editor.getByText('Enter a target, or choose status 410 or 451 for pages that are gone.')).toBeVisible();
    await expect(app.field('Target')).toBeFocused();
    await expect(app.editor).toBeVisible();
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/ohne-ziel');
  });

  test('Ctrl/Cmd+Z brings back the rule that was just deleted', async ({ app, page, api }) => {
    await app.goto('#/rules');
    // nothing to undo yet: the shortcut does nothing
    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.toast(/Restored/)).toHaveCount(0);

    const before = await ruleOf(api, '/g-kontakt');
    await openRowMenu(app, '/g-kontakt');
    await app.root.getByRole('menuitem', { name: 'Delete' }).click();
    await expect(app.toast('Deleted 1 rule')).toBeVisible();
    await expect(app.row('/g-kontakt')).toHaveCount(0);
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/g-kontakt');

    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.toast('Restored 1 rule')).toBeVisible();
    await expect(app.row('/g-kontakt')).toBeVisible();
    const after = await ruleOf(api, '/g-kontakt');
    expect(after).toMatchObject({ id: before.id, target: before.target, priority: before.priority });

    // the undo entry is used up
    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.toast('Restored 1 rule')).toHaveCount(1);
  });

  test('Ctrl/Cmd+Z undoes the latest deletion first, then the one before', async ({ app, page, api }) => {
    await app.goto('#/rules');
    for (const source of ['/e-info', '/f-faq']) {
      await openRowMenu(app, source);
      await app.root.getByRole('menuitem', { name: 'Delete' }).click();
      await expect(app.row(source)).toHaveCount(0);
    }
    await expect.poll(async () => (await allRules(api)).length).toBe(30);

    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.row('/f-faq')).toBeVisible();
    await expect(app.row('/e-info')).toHaveCount(0);
    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.row('/e-info')).toBeVisible();
    expect(await allRules(api)).toHaveLength(32);
  });

  test('Ctrl/Cmd+Z in a text field is the browser\'s own undo, not the rule undo', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const restores = countRequests(app, 'POST', '/redirects/rules/restore');
    await openRowMenu(app, '/h-about');
    await app.root.getByRole('menuitem', { name: 'Delete' }).click();
    await expect(app.row('/h-about')).toHaveCount(0);

    await app.searchbox.click();
    await page.keyboard.type('abc');
    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.toast(/Restored/)).toHaveCount(0);
    expect(restores()).toBe(0);
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/h-about');

    // out of the field, the same keys restore the rule
    await app.searchbox.fill('');
    await app.searchbox.blur();
    await page.keyboard.press('ControlOrMeta+z');
    await expect(app.toast('Restored 1 rule')).toBeVisible();
    expect(restores()).toBe(1);
  });

  test('opening a stored rule moves the focus into the editor', async ({ app }) => {
    await app.goto('#/rules');
    await app.row('/a-zelte').getByRole('link', { name: '/a-zelte', exact: true }).click();
    await expect(app.field('Source')).toHaveValue('/a-zelte');
    // the focus is on the panel or a field of it, not left behind on the (inert) list
    await expect.poll(() => app.editor.evaluate((el) => el.contains((el.getRootNode() as ShadowRoot).activeElement))).toBe(true);
  });

  // BUG (product): the focus is not given back. Slideover.finish() calls opener.focus() while the list behind is still
  // `inert` (the effect that clears it runs after finish()), so the call does nothing and the focus falls to <body>.
  // Check: after Esc, document.activeElement is BODY. Remove the fixme once Slideover restores the focus.
  test.fixme('focus returns to the row link after the editor closes', async ({ app, page }) => {
    await app.goto('#/rules');
    const link = app.row('/a-zelte').getByRole('link', { name: '/a-zelte', exact: true });
    await link.click();
    await expect(app.editor.getByRole('heading', { name: 'Edit redirect' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(app.editor).toBeHidden();
    await expect(link).toBeFocused();
    await expect(page).toHaveURL(/#\/rules$/);
  });

  // BUG (product): same cause as above.
  test.fixme('focus returns to the New redirect button after Cancel, Esc and Discard', async ({ app, page }) => {
    await app.goto('#/rules');
    await newButton(app).click();
    await expect(app.field('Source')).toBeFocused();
    await app.editor.getByRole('button', { name: 'Cancel' }).click();
    await expect(app.editor).toBeHidden();
    await expect(newButton(app)).toBeFocused();

    await newButton(app).click();
    await page.keyboard.press('Escape');
    await expect(app.editor).toBeHidden();
    await expect(newButton(app)).toBeFocused();

    // through the confirm dialog too
    await newButton(app).click();
    await app.field('Source').fill('/x');
    await page.keyboard.press('Escape');
    await discardDialog(app).getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.editor).toBeHidden();
    await expect(newButton(app)).toBeFocused();
  });

  test('after the editor closed the shortcuts work again (n a second time, / for the search)', async ({ app, page }) => {
    await app.goto('#/rules');
    await app.openEditorWithKey();
    await page.keyboard.press('Escape');
    await expect(app.editor).toBeHidden();

    await page.keyboard.press('/');
    await expect(app.searchbox).toBeFocused();
    await app.searchbox.blur();
    await app.openEditorWithKey();
    await expect(app.field('Source')).toBeFocused();
  });

  test.describe('read-only user', () => {
    test.use({ asUser: 'readonly' });

    test('n does not open the editor, / still focuses the search', async ({ app, page }) => {
      await app.goto('#/rules');
      await expect(newButton(app)).toHaveCount(0);
      await page.keyboard.press('n');
      await expect(app.editor).toBeHidden();
      await page.keyboard.press('/');
      await expect(app.searchbox).toBeFocused();
    });
  });
});
