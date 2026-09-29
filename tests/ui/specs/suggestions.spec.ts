import { test, expect } from '../support/test';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';

/** the seeded open suggestions: path -> [target, score as the table shows it (0.779 is shown as 77%)] */
const OPEN: Record<string, [string, string]> = {
  '/Info': ['/info', '97%'],
  '/shop/Rucksaecke': ['/shop/rucksaecke', '97%'],
  '/shop/Zelte': ['/shop/zelte', '97%'],
  '/about-us': ['/about', '80%'],
  '/blog/wintercamping-tips': ['/blog/wintercamping-tipps', '77%'],
  '/shop/rucksack': ['/shop/rucksaecke', '58%'],
  '/kontakt/formular': ['/kontakt', '50%'],
};
const HIGH = ['/Info', '/shop/Rucksaecke', '/shop/Zelte'];

const sgRows = (app: App) => app.root.locator('table[aria-label="Redirect suggestions"] tbody tr');
const sgRow = (app: App, path: string) => sgRows(app).filter({ has: app.page.getByRole('link', { name: path, exact: true }) });
const tab = (app: App, name: 'Open' | 'Accepted' | 'Rejected') => app.root.getByRole('radio', { name: new RegExp(`^${name} \\(`) });

async function counts(api: ApiCall): Promise<{ open: number; accepted: number; rejected: number }> {
  return (await api('GET', '/redirects/suggestions')).meta.counts;
}

async function suggestionId(api: ApiCall, path: string): Promise<string> {
  const rows = (await api('GET', '/redirects/suggestions?status=all')).data as any[];
  const row = rows.find((r) => r.path === path);
  expect(row, `stored suggestion for ${path}`).toBeTruthy();
  return row.id;
}

async function ruleFor(api: ApiCall, source: string): Promise<any> {
  const rules = (await api('GET', `/redirects/rules?q=${encodeURIComponent(source)}&per_page=50`)).data as any[];
  return rules.find((r) => r.source === source);
}

test.describe('suggestions', () => {
  test('tab counts, sidebar count and the open list with score, reason, target and hits', async ({ app, api }) => {
    await app.goto('#/suggestions');
    await expect(tab(app, 'Open')).toHaveText('Open (7)');
    await expect(tab(app, 'Open')).toBeChecked();
    await expect(tab(app, 'Accepted')).toHaveText('Accepted (0)');
    await expect(tab(app, 'Rejected')).toHaveText('Rejected (0)');
    await expect(app.root.getByRole('link', { name: 'Suggestions 7 open suggestions' })).toBeVisible();
    expect(await counts(api)).toEqual({ open: 7, accepted: 0, rejected: 0 });

    await expect(sgRows(app)).toHaveCount(7);
    for (const [path, [target, score]] of Object.entries(OPEN)) {
      const row = sgRow(app, path);
      await expect(row).toHaveCount(1);
      await expect(row.getByRole('button', { name: `Change target ${target}` })).toBeVisible();
      await expect(row).toContainText(score);
      await expect(row.getByRole('button', { name: `Accept the suggestion for ${path}` })).toBeVisible();
      await expect(row.getByRole('button', { name: `Reject the suggestion for ${path}` })).toBeVisible();
    }
    // hits come from the 404 log, and the best score comes first
    await expect(sgRow(app, '/shop/Zelte').locator('td.c-hits')).toHaveText('4');
    await expect(sgRow(app, '/about-us')).toContainText('Title match');
    await expect(sgRow(app, '/Info')).toContainText('Similar route');
    await expect(sgRows(app).last()).toContainText('/kontakt/formular');
    // the path links to the 404 monitor, filtered
    await expect(sgRow(app, '/about-us').getByRole('link', { name: '/about-us' })).toHaveAttribute('href', '#/404?q=%2Fabout-us');
  });

  test('filter by path and by minimum score', async ({ app }) => {
    await app.goto('#/suggestions');
    await app.root.getByRole('searchbox', { name: 'Filter suggestions by path' }).fill('shop/');
    await expect(sgRows(app)).toHaveCount(3);
    await app.root.getByRole('searchbox', { name: 'Filter suggestions by path' }).fill('');
    await expect(sgRows(app)).toHaveCount(7);

    await app.root.getByRole('combobox', { name: 'Minimum score' }).selectOption({ label: '90% or higher' });
    await expect(sgRows(app)).toHaveCount(3);
    for (const path of HIGH) await expect(sgRow(app, path)).toBeVisible();
    await app.root.getByRole('combobox', { name: 'Minimum score' }).selectOption({ label: '70% or higher' });
    await expect(sgRows(app)).toHaveCount(5);
  });

  test('accept one: creates a rule with origin suggestion, moves the count from Open to Accepted', async ({ app, api, site, request }) => {
    await app.goto('#/suggestions');
    await sgRow(app, '/about-us').getByRole('button', { name: 'Accept the suggestion for /about-us' }).click();
    await expect(app.toast('Redirect created: /about-us → /about')).toBeVisible();
    await expect(sgRow(app, '/about-us')).toHaveCount(0);
    await expect(sgRows(app)).toHaveCount(6);
    await expect(tab(app, 'Open')).toHaveText('Open (6)');
    await expect(tab(app, 'Accepted')).toHaveText('Accepted (1)');

    expect(await counts(api)).toEqual({ open: 6, accepted: 1, rejected: 0 });
    expect(await ruleFor(api, '/about-us')).toMatchObject({ source: '/about-us', target: '/about', origin: 'suggestion', status: 301, enabled: true });
    const res = await request.get(`${site.baseUrl}/about-us`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/about');

    // the toast offers the new rule
    await app.toasts.getByRole('button', { name: 'Open rule' }).click();
    await expect(app.editor).toBeVisible();
    await expect(app.field('Source')).toHaveValue('/about-us');
  });

  test('edit the target inline, then accept: the rule gets the edited target', async ({ app, api }) => {
    await app.goto('#/suggestions');
    const row = sgRow(app, '/kontakt/formular');
    await row.getByRole('button', { name: 'Change target /kontakt' }).click();
    const input = row.getByRole('textbox', { name: 'Target for /kontakt/formular' });
    await input.fill('/faq');
    await input.press('Enter');
    await expect(row.getByRole('button', { name: 'Change target /faq' })).toBeVisible();
    await expect(row).toContainText('Edited');

    await row.getByRole('button', { name: 'Accept the suggestion for /kontakt/formular' }).click();
    await expect(app.toast('Redirect created: /kontakt/formular → /faq')).toBeVisible();
    await expect(row).toHaveCount(0);
    expect(await ruleFor(api, '/kontakt/formular')).toMatchObject({ target: '/faq', origin: 'suggestion' });
    expect(await counts(api)).toEqual({ open: 6, accepted: 1, rejected: 0 });
  });

  test('Escape leaves the inline edit without changing the target', async ({ app }) => {
    await app.goto('#/suggestions');
    const row = sgRow(app, '/about-us');
    await row.getByRole('button', { name: 'Change target /about' }).click();
    const input = row.getByRole('textbox', { name: 'Target for /about-us' });
    await input.fill('/shop');
    await input.press('Escape');
    await expect(row.getByRole('button', { name: 'Change target /about' })).toBeVisible();
    await expect(row).not.toContainText('Edited');
  });

  test('reject one: no rule is created, the count moves to Rejected', async ({ app, api }) => {
    await app.goto('#/suggestions');
    await sgRow(app, '/shop/rucksack').getByRole('button', { name: 'Reject the suggestion for /shop/rucksack' }).click();
    await expect(app.toast('Rejected the suggestion for /shop/rucksack.')).toBeVisible();
    await expect(sgRow(app, '/shop/rucksack')).toHaveCount(0);
    await expect(tab(app, 'Open')).toHaveText('Open (6)');
    await expect(tab(app, 'Rejected')).toHaveText('Rejected (1)');

    expect(await counts(api)).toEqual({ open: 6, accepted: 0, rejected: 1 });
    expect(await ruleFor(api, '/shop/rucksack')).toBeUndefined();
    const stored = ((await api('GET', '/redirects/suggestions?status=rejected')).data as any[]).map((r) => r.path);
    expect(stored).toEqual(['/shop/rucksack']);
  });

  test('the Accepted and Rejected tabs list their rows without action buttons', async ({ app, api }) => {
    expect((await api('POST', `/redirects/suggestions/${await suggestionId(api, '/about-us')}/accept`, {})).status).toBe(201);
    expect((await api('POST', `/redirects/suggestions/${await suggestionId(api, '/Info')}/reject`, {})).status).toBe(200);
    expect((await api('POST', `/redirects/suggestions/${await suggestionId(api, '/shop/rucksack')}/reject`, {})).status).toBe(200);

    await app.goto('#/suggestions');
    await expect(tab(app, 'Open')).toHaveText('Open (4)');
    await expect(tab(app, 'Accepted')).toHaveText('Accepted (1)');
    await expect(tab(app, 'Rejected')).toHaveText('Rejected (2)');

    await tab(app, 'Accepted').click();
    await expect(tab(app, 'Accepted')).toBeChecked();
    await expect(sgRows(app)).toHaveCount(1);
    await expect(sgRow(app, '/about-us')).toContainText('/about');
    await expect(app.root.getByRole('button', { name: /^Accept the suggestion/ })).toHaveCount(0);

    await tab(app, 'Rejected').click();
    await expect(sgRows(app)).toHaveCount(2);
    await expect(sgRow(app, '/Info')).toBeVisible();
    await expect(sgRow(app, '/shop/rucksack')).toBeVisible();
    await expect(app.root.getByRole('button', { name: /^Reject the suggestion/ })).toHaveCount(0);

    await tab(app, 'Open').click();
    await expect(sgRows(app)).toHaveCount(4);
  });

  test('"Generate suggestions" reports what it found, a new 404 path gets a suggestion', async ({ app, api, site }) => {
    expect((await site.get('/blog/packliste-fuer-camper-2')).status).toBe(404);
    await expect.poll(async () => ((await api('GET', '/redirects/404?per_page=500')).data as any[]).map((r) => r.path)).toContain('/blog/packliste-fuer-camper-2');

    await app.goto('#/suggestions');
    await expect(sgRows(app)).toHaveCount(7);
    await app.root.getByRole('button', { name: 'Generate suggestions' }).click();
    await expect(app.toast(/new suggestions?, \d+ improved, \d+ without a match\./)).toBeVisible();

    await expect(sgRow(app, '/blog/packliste-fuer-camper-2')).toBeVisible();
    await expect(sgRows(app)).toHaveCount(8);
    await expect(tab(app, 'Open')).toHaveText('Open (8)');
    const added = ((await api('GET', '/redirects/suggestions')).data as any[]).find((r) => r.path === '/blog/packliste-fuer-camper-2');
    expect(added.target).toBe('/blog/packliste-fuer-camper');
    expect((await counts(api)).open).toBe(8);
  });

  test('bulk accept: the default threshold 90% offers the 3 seeded 97% suggestions, the preview lists them, confirm creates the rules', async ({ app, api }) => {
    await app.goto('#/suggestions');
    const panel = app.root.getByRole('region', { name: 'Accept many at once' });
    await expect(panel.getByRole('slider', { name: 'Score threshold' })).toHaveValue('0.9');
    await expect(panel.getByRole('status')).toHaveText('90%');
    await expect(panel.getByText('3 suggestions at or above 90%')).toBeVisible();

    await panel.getByRole('button', { name: 'Review and accept…' }).click();
    const dialog = app.root.getByRole('dialog', { name: 'Create 3 redirects?' });
    await expect(dialog).toBeVisible();
    const rows = dialog.getByRole('region', { name: 'Redirects to create' }).locator('tbody tr');
    await expect(rows).toHaveCount(3);
    for (const path of HIGH) {
      const row = rows.filter({ hasText: path });
      await expect(row).toHaveCount(1);
      await expect(row).toContainText(OPEN[path]![0]);
      await expect(row).toContainText('97%');
    }
    // nothing is created before the confirmation
    expect((await api('GET', '/redirects/rules?origin=suggestion&per_page=1')).meta.total).toBe(0);

    await dialog.getByRole('button', { name: 'Create 3 redirects' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.toast('3 redirects created.')).toBeVisible();
    await expect(sgRows(app)).toHaveCount(4);
    await expect(tab(app, 'Open')).toHaveText('Open (4)');
    await expect(tab(app, 'Accepted')).toHaveText('Accepted (3)');

    const rules = ((await api('GET', '/redirects/rules?origin=suggestion&per_page=50')).data as any[]).map((r) => [r.source, r.target, r.origin]);
    expect(rules.sort()).toEqual([
      ['/Info', '/info', 'suggestion'],
      ['/shop/Rucksaecke', '/shop/rucksaecke', 'suggestion'],
      ['/shop/Zelte', '/shop/zelte', 'suggestion'],
    ]);
    expect(await counts(api)).toEqual({ open: 4, accepted: 3, rejected: 0 });
    // the slider now has fewer suggestions to offer
    // nothing is left at 90% or higher
    await expect(app.root.getByRole('region', { name: 'Accept many at once' }).getByText('0 suggestions at or above 90%')).toBeVisible();
    await expect(app.root.getByRole('button', { name: 'Review and accept…' })).toBeDisabled();
  });

  test('bulk accept with a lower threshold offers more, Cancel in the preview creates nothing', async ({ app, api }) => {
    await app.goto('#/suggestions');
    const panel = app.root.getByRole('region', { name: 'Accept many at once' });
    await panel.getByRole('slider', { name: 'Score threshold' }).fill('0.7');
    await expect(panel.getByRole('status')).toHaveText('70%');
    await expect(panel.getByText('5 suggestions at or above 70%')).toBeVisible();

    await panel.getByRole('button', { name: 'Review and accept…' }).click();
    const dialog = app.root.getByRole('dialog', { name: 'Create 5 redirects?' });
    await expect(dialog.getByRole('region', { name: 'Redirects to create' }).locator('tbody tr')).toHaveCount(5);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();

    expect((await api('GET', '/redirects/rules?origin=suggestion&per_page=1')).meta.total).toBe(0);
    expect(await counts(api)).toEqual({ open: 7, accepted: 0, rejected: 0 });
    await expect(sgRows(app)).toHaveCount(7);
  });
});
