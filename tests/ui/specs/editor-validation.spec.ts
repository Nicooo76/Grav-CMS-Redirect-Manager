import { test, expect } from '../support/test';
import type { App } from '../support/app';
import { defaultPluginConfig } from '../support/site';
import { allRules, hostModal, ruleOf } from '../support/rules-page';

const checks = (app: App) => app.editor.getByRole('list', { name: 'Checks' });
const createButton = (app: App) => app.editor.getByRole('button', { name: 'Create redirect' });
const sampleField = (app: App) => app.editor.getByRole('textbox', { name: 'Try a URL' });
const statusField = (app: App) => app.field('Status code');
const matchType = (app: App, type: 'exact' | 'wildcard' | 'regex') => app.editor.getByRole('radio', { name: type, exact: true });

async function openNewEditor(app: App): Promise<void> {
  await app.goto('#/rules');
  await app.root.getByRole('button', { name: 'New redirect' }).click();
  await expect(app.editor).toBeVisible();
  await expect(app.field('Source')).toBeFocused();
}

async function openRule(app: App, source: string, index = 0): Promise<void> {
  await app.goto('#/rules');
  await app.rows.filter({ has: app.page.getByRole('link', { name: source, exact: true }) }).nth(index).getByRole('link', { name: source, exact: true }).click();
  await expect(app.editor.getByRole('heading', { name: 'Edit redirect' })).toBeVisible();
  await expect(app.field('Source')).toHaveValue(source);
}

test.describe('rule editor: checks and validation', () => {
  test('a loop (A to B, then B to A) is refused with an inline error and cannot be saved', async ({ app, api }) => {
    expect((await api('POST', '/redirects/rules', { source: '/loop-a', target: '/loop-b' })).status).toBe(201);
    await openNewEditor(app);
    await app.field('Source').fill('/loop-b');
    await app.field('Target').fill('/loop-a');

    const loop = checks(app).getByRole('alert').filter({ hasText: /Redirect loop/ });
    await expect(loop).toBeVisible();
    await expect(loop).toContainText('/loop-a');
    await expect(loop).toContainText('/loop-b');
    await expect(createButton(app)).toBeDisabled();
    await expect(createButton(app)).toHaveAttribute('title', 'Fix the errors above before saving.');

    // the keyboard route to Save says the same
    await app.field('Target').press('ControlOrMeta+Enter');
    await expect(app.editor.getByRole('alert').filter({ hasText: 'Fix the errors above before saving.' })).toBeVisible();
    await expect(app.editor).toBeVisible();
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/loop-b');

    // pointing somewhere else lifts the block
    await app.field('Target').fill('/loop-c');
    await expect(loop).toHaveCount(0);
    await expect(createButton(app)).toBeEnabled();
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    expect((await ruleOf(api, '/loop-b')).target).toBe('/loop-c');
  });

  test('a case-only self redirect says so and the button makes the rule case-sensitive', async ({ app, api }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/Selbst-Test');
    await app.field('Target').fill('/selbst-test');
    const alert = checks(app).getByRole('alert');
    await expect(alert).toContainText('differs from the source only in letter case');
    await expect(createButton(app)).toBeDisabled();

    await alert.getByRole('button', { name: 'Make the rule case-sensitive' }).click();
    await expect(alert).toHaveCount(0);
    await expect(app.editor.getByRole('checkbox', { name: /Case sensitive/ })).toBeChecked();
    await expect(createButton(app)).toBeEnabled();
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    expect(await ruleOf(api, '/Selbst-Test')).toMatchObject({ target: '/selbst-test', case_sensitive: true });
  });

  test('the preview says "No match" (and never stays on "Checking...") when no rule matches the sample', async ({ app }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/blog/*');
    await app.field('Target').fill('/journal');
    const result = app.editor.locator('#pv-result');
    await expect(result).toContainText('No match');
    await expect(result).toContainText('No rule matches this URL');
    await expect(result).not.toContainText('Checking');

    // a sample that does match shows the result again
    await sampleField(app).fill('/blog/*');
    await matchType(app, 'wildcard').click();
    await expect(result).toContainText('/journal');
  });

  test('a rule that points at its own source is refused', async ({ app, api }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/selbst');
    await app.field('Target').fill('/selbst');
    await expect(checks(app).getByRole('alert')).toContainText(/loop|own source/i);
    await expect(createButton(app)).toBeDisabled();
    await app.editor.getByRole('button', { name: 'Cancel' }).click();
    await hostModal(app.page, 'Discard changes?').getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.editor).toBeHidden();
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/selbst');
  });

  test('chain warning on a stored rule: "Point to /faq directly" shortens it and saves', async ({ app, api }) => {
    await openRule(app, '/kette-a');
    const chain = checks(app).getByRole('listitem').filter({ hasText: /Redirect chain/ });
    await expect(chain).toBeVisible();
    await expect(chain).toContainText('/kette-b');
    await expect(chain).toContainText('/faq');
    await chain.getByRole('button', { name: 'Point to /faq directly' }).click();
    await expect(app.field('Target')).toHaveValue('/faq');
    // the check runs again on the new target and the warning is gone
    await expect(checks(app)).toHaveCount(0);

    await app.editor.getByRole('button', { name: 'Save changes' }).click();
    await expect(app.toast('Redirect saved')).toBeVisible();
    const rule = await ruleOf(api, '/kette-a');
    expect(rule.target).toBe('/faq');
    expect(rule.badges).not.toContain('chain');
  });

  test('chain warning on a new rule pointing into a chain', async ({ app, api }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/neu-vor-kette');
    await app.field('Target').fill('/kette-a');
    const chain = checks(app).getByRole('listitem').filter({ hasText: /Redirect chain/ });
    await expect(chain).toBeVisible();
    // a warning does not block saving
    await expect(createButton(app)).toBeEnabled();
    await chain.getByRole('button', { name: 'Point to /faq directly' }).click();
    await expect(app.field('Target')).toHaveValue('/faq');
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    expect((await ruleOf(api, '/neu-vor-kette')).target).toBe('/faq');
  });

  test('a warning does not stop the save: chain rule is created, the toast repeats the warning', async ({ app, api }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/neu-vor-kette-2');
    await app.field('Target').fill('/kette-a');
    await expect(checks(app).getByRole('listitem').filter({ hasText: /Redirect chain/ })).toBeVisible();
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    await expect(app.toast(/Redirect chain/)).toBeVisible();
    expect((await ruleOf(api, '/neu-vor-kette-2')).badges).toContain('chain');
  });

  test('conflict warning: "Open the other rule" opens the other rule of the same source', async ({ app }) => {
    await openRule(app, '/konflikt', 0);
    await expect(app.field('Target')).toHaveValue('/shop');
    const conflict = checks(app).getByRole('listitem').filter({ hasText: /Other rules match the same requests/ });
    await expect(conflict).toBeVisible();
    const open = conflict.getByRole('button', { name: 'Open the other rule: /konflikt' });
    await expect(open).toBeVisible();
    await open.click();

    // the same editor now shows the other rule (target /blog, status 302)
    await expect(app.field('Target')).toHaveValue('/blog');
    await expect(statusField(app)).toHaveValue('302');
    await expect(app.page).toHaveURL(/#\/rules\/r/);
    // this one is the lower-ranked rule: it is a conflict and it never applies, and both findings name the other rule
    await expect(checks(app).getByRole('listitem').filter({ hasText: /Other rules match/ }).getByRole('button', { name: 'Open the other rule: /konflikt' })).toBeVisible();
    await expect(checks(app).getByRole('listitem').filter({ hasText: /never applies/ })).toBeVisible();
  });

  test('conflict warning with unsaved changes asks before it opens the other rule', async ({ app }) => {
    await openRule(app, '/konflikt', 0);
    await app.field('Target').fill('/geaendert');
    await app.editor.getByRole('button', { name: 'Open the other rule: /konflikt' }).click();
    const dialog = hostModal(app.page, 'Discard changes?');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Keep editing' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.field('Target')).toHaveValue('/geaendert');

    await app.editor.getByRole('button', { name: 'Open the other rule: /konflikt' }).click();
    await hostModal(app.page, 'Discard changes?').getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.field('Target')).toHaveValue('/blog');
  });

  test('regex help popover lists the groups captured from the sample URL', async ({ app }) => {
    await openNewEditor(app);
    await expect(app.editor.getByRole('button', { name: 'Help: patterns and captured groups' })).toHaveCount(0); // exact has no help
    await matchType(app, 'regex').click();
    await app.field('Source').fill('^/produkt/(\\d+)/(.*)$');
    await app.field('Target').fill('/shop/$1/$2');
    await sampleField(app).fill('/produkt/42/zelt');
    await expect(app.editor.locator('#pv-result')).toContainText('/shop/42/zelt');

    await app.editor.getByRole('button', { name: 'Help: patterns and captured groups' }).click();
    const help = app.root.getByRole('dialog', { name: 'Help: patterns and captured groups' });
    await expect(help).toBeVisible();
    await expect(help).toContainText('Regular expressions');
    await expect(help).toContainText('Captured from the sample');
    const captures = help.locator('dl');
    await expect(captures).toContainText('$1');
    await expect(captures).toContainText('42');
    await expect(captures).toContainText('$2');
    await expect(captures).toContainText('zelt');

    // Esc closes the popover only, not the editor
    await help.press('Escape');
    await expect(help).toBeHidden();
    await expect(app.editor).toBeVisible();
  });

  test('wildcard help shows the wildcard captures', async ({ app }) => {
    await openNewEditor(app);
    await matchType(app, 'wildcard').click();
    await app.field('Source').fill('/blog/*/print');
    await app.field('Target').fill('/journal/$1');
    await sampleField(app).fill('/blog/2024/print');
    await expect(app.editor.locator('#pv-result')).toContainText('/journal/2024');
    await app.editor.getByRole('button', { name: 'Help: patterns and captured groups' }).click();
    const help = app.root.getByRole('dialog', { name: 'Help: patterns and captured groups' });
    await expect(help).toContainText('Wildcards');
    await expect(help.locator('dl')).toContainText('$1');
    await expect(help.locator('dl')).toContainText('2024');
  });

  test('exact -> wildcard hint with a one-click switch', async ({ app }) => {
    await openNewEditor(app);
    const hint = app.editor.getByTestId('match-hint');
    await app.field('Source').fill('/old-page');
    await expect(hint).toHaveCount(0);

    await app.field('Source').fill('/blog/*');
    await expect(hint).toBeVisible();
    await expect(hint).toContainText('This looks like a wildcard pattern');
    // the hint only offers, it does not switch by itself
    await expect(matchType(app, 'exact')).toBeChecked();
    await hint.getByRole('button', { name: 'Switch to wildcard' }).click();
    await expect(matchType(app, 'wildcard')).toBeChecked();
    await expect(hint).toHaveCount(0);
    await expect(app.field('Source')).toHaveValue('/blog/*');
  });

  test('other match-type hints: exact -> regex, wildcard -> regex, regex -> wildcard', async ({ app }) => {
    await openNewEditor(app);
    const hint = app.editor.getByTestId('match-hint');

    await app.field('Source').fill('^/produkt/(\\d+)$');
    await expect(hint).toContainText('This looks like a regular expression');
    await hint.getByRole('button', { name: 'Switch to regex' }).click();
    await expect(matchType(app, 'regex')).toBeChecked();
    await expect(hint).toHaveCount(0);

    await matchType(app, 'wildcard').click();
    await expect(hint).toContainText('only work as a regular expression');
    await expect(hint.getByRole('button', { name: 'Switch to regex' })).toBeVisible();

    await matchType(app, 'regex').click();
    await app.field('Source').fill('/blog/*');
    await expect(hint).toContainText('a lone * repeats the character before it');
    await hint.getByRole('button', { name: 'Switch to wildcard' }).click();
    await expect(matchType(app, 'wildcard')).toBeChecked();
    await expect(hint).toHaveCount(0);
  });

  test('required fields: source and target errors appear on save, and the first bad field gets the focus', async ({ app, api }) => {
    await openNewEditor(app);
    await createButton(app).click();
    await expect(app.editor.getByText('Enter the source path.')).toBeVisible();
    await expect(app.editor.getByText('Enter a target, or choose status 410 or 451 for pages that are gone.')).toBeVisible();
    await expect(app.field('Source')).toHaveAttribute('aria-invalid', 'true');
    await expect(app.field('Target')).toHaveAttribute('aria-invalid', 'true');
    await expect(app.field('Source')).toBeFocused();
    await expect(app.editor).toBeVisible();
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(32);

    // fixing a field takes its error away; a source without a slash is its own error
    await app.field('Source').fill('ohne-slash');
    await app.field('Target').fill('/irgendwo');
    await createButton(app).click();
    await expect(app.editor.getByText('A source path starts with a slash, for example /old-page.')).toBeVisible();
    await expect(app.editor.getByText('Enter a target, or choose status 410 or 451 for pages that are gone.')).toHaveCount(0);

    await app.field('Source').fill('/mit-slash');
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    expect((await ruleOf(api, '/mit-slash')).target).toBe('/irgendwo');
  });

  test('an invalid regular expression is flagged live and again on save', async ({ app, api }) => {
    await openNewEditor(app);
    await matchType(app, 'regex').click();
    await app.field('Source').fill('^/alt/(unfertig');
    await app.field('Target').fill('/neu');
    // live check by the server
    await expect(checks(app).getByRole('alert')).toContainText('The regular expression is invalid.');
    await expect(createButton(app)).toBeDisabled();
    await expect(app.field('Source')).toHaveAttribute('aria-invalid', 'true');

    // save with the keyboard: the field itself says what is wrong
    await app.field('Source').press('ControlOrMeta+Enter');
    await expect(app.editor.getByText('This is not a valid regular expression.')).toBeVisible();
    await expect(app.field('Source')).toBeFocused();
    expect((await allRules(api)).map((r) => r.source)).not.toContain('^/alt/(unfertig');

    await app.field('Source').fill('^/alt/(fertig)$');
    await expect(checks(app).getByRole('alert')).toHaveCount(0);
    await expect(app.editor.getByText('This is not a valid regular expression.')).toHaveCount(0);
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
  });

  test('status note for 302 and 307 on a new rule; "Use 301" sets the code back', async ({ app }) => {
    await openNewEditor(app);
    const note = app.editor.getByTestId('status-note');
    await expect(statusField(app)).toHaveValue('301');
    await expect(note).toHaveCount(0);

    await statusField(app).selectOption('302');
    await expect(note).toBeVisible();
    await expect(note).toContainText('302 tells search engines the move is temporary.');
    await note.getByRole('button', { name: 'Use 301' }).click();
    await expect(statusField(app)).toHaveValue('301');
    await expect(note).toHaveCount(0);

    await statusField(app).selectOption('307');
    await expect(note).toContainText('307 tells search engines the move is temporary.');
    await statusField(app).selectOption('308');
    await expect(note).toHaveCount(0);
  });

  test('when the site default is 302, the note says so', async ({ app, site }) => {
    site.setConfig({ ...defaultPluginConfig, redirects: { default_status: 302 } });
    await openNewEditor(app);
    await expect(statusField(app)).toHaveValue('302');
    const note = app.editor.getByTestId('status-note');
    await expect(note).toContainText('New rules start with 302, the default of this site.');
    await note.getByRole('button', { name: 'Use 301' }).click();
    await expect(statusField(app)).toHaveValue('301');
    await expect(note).toHaveCount(0);
  });

  test('no status note when editing a stored 302 rule', async ({ app }) => {
    await openRule(app, '/news');
    await expect(statusField(app)).toHaveValue('302');
    await expect(app.editor.getByTestId('status-note')).toHaveCount(0);
  });

  test('status 410 hides the target field and saves without a target', async ({ app, api, site }) => {
    await openNewEditor(app);
    await expect(app.field('Target')).toBeVisible();
    await statusField(app).selectOption('410');
    await expect(app.field('Target')).toHaveCount(0);
    await expect(app.editor.getByText('Status 410 needs no target.')).toBeVisible();

    // switching back brings the field (and its requirement) back
    await statusField(app).selectOption('301');
    await expect(app.field('Target')).toBeVisible();
    await expect(app.editor.getByText('Status 410 needs no target.')).toHaveCount(0);
    await statusField(app).selectOption('410');

    await app.field('Source').fill('/ist-weg');
    await createButton(app).click();
    await expect(app.toast('Redirect created')).toBeVisible();
    expect(await ruleOf(api, '/ist-weg')).toMatchObject({ status: 410, target: '' });
    const res = await site.get('/ist-weg');
    expect(res.status).toBe(410);
  });

  test('451 also needs no target', async ({ app }) => {
    await openNewEditor(app);
    await statusField(app).selectOption('451');
    await expect(app.field('Target')).toHaveCount(0);
    await expect(app.editor.getByText('Status 451 needs no target.')).toBeVisible();
  });

  test('discard changes: Cancel without changes closes at once; with changes it asks first', async ({ app, api }) => {
    await openNewEditor(app);
    await app.editor.getByRole('button', { name: 'Cancel' }).click();
    await expect(app.editor).toBeHidden();
    await expect(hostModal(app.page, 'Discard changes?')).toHaveCount(0);

    await app.root.getByRole('button', { name: 'New redirect' }).click();
    await app.field('Source').fill('/vergessen');
    await app.editor.getByRole('button', { name: 'Cancel' }).click();
    const dialog = hostModal(app.page, 'Discard changes?');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('You changed this redirect and have not saved it.');
    // the dialog is Admin 2's, outside the plugin's shadow root
    await expect(app.root.getByRole('heading', { name: 'Discard changes?' })).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Keep editing' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.editor).toBeVisible();
    await expect(app.field('Source')).toHaveValue('/vergessen');

    // the X in the header asks the same question; this time discard
    await app.editor.getByRole('button', { name: 'Close' }).click();
    await hostModal(app.page, 'Discard changes?').getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.editor).toBeHidden();
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(32);
  });

  test('discard changes on a stored rule: nothing is saved; undoing the edit by hand is no change', async ({ app, api }) => {
    await openRule(app, '/f-faq');
    await app.field('Target').fill('/anders');
    await app.editor.getByRole('button', { name: 'Close' }).click();
    await hostModal(app.page, 'Discard changes?').getByRole('button', { name: 'Discard changes' }).click();
    await expect(app.editor).toBeHidden();
    expect((await ruleOf(api, '/f-faq')).target).toBe('/faq');

    await app.root.getByRole('link', { name: '/f-faq', exact: true }).click();
    await expect(app.editor.getByRole('heading', { name: 'Edit redirect' })).toBeVisible();
    await expect(app.field('Target')).toHaveValue('/faq'); // the reopened editor has no leftovers
    await app.field('Target').fill('/anders');
    await app.field('Target').fill('/faq');
    await app.editor.getByRole('button', { name: 'Close' }).click();
    await expect(app.editor).toBeHidden();
    await expect(hostModal(app.page, 'Discard changes?')).toHaveCount(0);
  });
});
