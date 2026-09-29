import { test, expect } from '../support/test';
import { ruleOf } from '../support/rules-page';

/** The editor's "Page" target type (search picker, follows the page) and the help for every status code. */

const createButton = (app: import('../support/app').App) => app.editor.getByRole('button', { name: 'Create redirect' });

async function openNewEditor(app: import('../support/app').App): Promise<void> {
  await app.goto('#/rules');
  await app.root.getByRole('button', { name: 'New redirect' }).click();
  await expect(app.editor).toBeVisible();
}

test.describe('rule editor: target type "Page"', () => {
  test('search the page picker, pick a page, save: the rule is a page target and the site redirects to that page', async ({ app, api, site, request, page }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/promo-zelte');
    await app.editor.getByRole('radio', { name: 'Page', exact: true }).click();
    await expect(app.editor).toContainText('A page of this site. The rule follows the page when it moves.');

    // the picker searches title and route: "zelt" finds the tents page and shows its title
    const picker = app.editor.getByRole('combobox', { name: 'Target' });
    await picker.fill('zelt');
    const option = app.root.getByRole('option', { name: /\/shop\/zelte/ });
    await expect(option).toBeVisible();
    await expect(option).toContainText('Zelte');
    // the list opens under the field, inside the window (it once opened by the offset of the slide-over, off screen)
    const box = (await option.boundingBox())!;
    const panel = (await app.editor.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(panel.x);
    expect(box.x + box.width).toBeLessThanOrEqual(panel.x + panel.width);
    expect(box.y).toBeGreaterThan(0);
    expect(box.y + box.height).toBeLessThanOrEqual(page.viewportSize()!.height);
    // "Sale" lives below /shop/outlet: it is not offered for "zelt"
    await expect(app.root.getByRole('option', { name: /\/shop\/outlet\/sale/ })).toHaveCount(0);
    await option.click();
    await expect(picker).toHaveValue('/shop/zelte');

    await createButton(app).click();
    await expect(app.toast(/created|saved/i)).toBeVisible();
    const rule = await ruleOf(api, '/promo-zelte');
    expect(rule).toMatchObject({ target: '/shop/zelte', status: 301 });
    expect((await api('GET', `/redirects/rules/${rule.id}`)).data.target_type).toBe('page');

    const res = await request.get(`${site.baseUrl}/promo-zelte`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/shop/zelte');
    // the list marks it as a page target
    await expect(app.row('/promo-zelte').locator('td.c-target')).toContainText('/shop/zelte');
  });

  test('an unknown search finds no page and offers to use the typed text', async ({ app }) => {
    await openNewEditor(app);
    await app.editor.getByRole('radio', { name: 'Page', exact: true }).click();
    const picker = app.editor.getByRole('combobox', { name: 'Target' });
    await picker.fill('gibt-es-wirklich-nicht');
    // the picker keeps what the user typed (a route or a page that is not in the index yet)
    await expect(app.root.getByRole('status').filter({ hasText: 'Use “gibt-es-wirklich-nicht”' })).toBeVisible();
    await expect(app.root.getByRole('option', { name: /\/shop\// })).toHaveCount(0);
  });

  test('after the page is renamed through the API the rule target follows; a route target does not', async ({ app, api, site, request }) => {
    await openNewEditor(app);
    await app.field('Source').fill('/promo-zelte');
    await app.editor.getByRole('radio', { name: 'Page', exact: true }).click();
    const picker = app.editor.getByRole('combobox', { name: 'Target' });
    await picker.fill('/shop/zelte');
    await app.root.getByRole('option', { name: /\/shop\/zelte/ }).click();
    await createButton(app).click();
    await expect(app.row('/promo-zelte')).toBeVisible();
    // a plain route rule at the same page, for contrast
    expect((await api('POST', '/redirects/rules', { source: '/plain-zelte', target: '/shop/zelte', target_type: 'route' })).status).toBe(201);

    const renamed = await api('PATCH', '/pages/shop/zelte', { header: { slug: 'campingzelte' } });
    expect(renamed.status).toBe(200);

    await expect.poll(async () => (await ruleOf(api, '/promo-zelte')).target).toBe('/shop/campingzelte');
    expect((await ruleOf(api, '/plain-zelte')).target).toBe('/shop/zelte');
    const res = await request.get(`${site.baseUrl}/promo-zelte`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/shop/campingzelte');
    // the page under its old address is redirected by the automatic rule as well, so the visitor ends up on the page
    expect((await request.get(`${site.baseUrl}/shop/campingzelte`, { maxRedirects: 0 })).status()).toBe(200);

    // the editor shows the new target as a page target
    await app.goto('#/rules');
    await app.row('/promo-zelte').getByRole('link', { name: '/promo-zelte', exact: true }).click();
    await expect(app.editor.getByRole('radio', { name: 'Page', exact: true })).toBeChecked();
    await expect(app.editor.getByRole('combobox', { name: 'Target' })).toHaveValue('/shop/campingzelte');
  });
});

/** the seven codes with the names and explanations of the UI (admin2/src/i18n/en/common.ts) */
const CODES: Array<[number, string, RegExp]> = [
  [301, 'Moved permanently', /The page moved for good\. Browsers and search engines remember it/],
  [302, 'Found (temporary)', /A temporary detour\. Search engines keep the old URL indexed/],
  [307, 'Temporary redirect', /Temporary, and the browser must repeat the request with the same method/],
  [308, 'Permanent redirect', /Permanent, and the browser must repeat the request with the same method/],
  [410, 'Gone', /The page is gone and will not come back/],
  [451, 'Unavailable for legal reasons', /Blocked for legal reasons, for example a court order/],
  [200, 'Pass-through', /No redirect: the visitor keeps the old URL and sees the target page/],
];

test.describe('rule editor: status code help', () => {
  test('"Explain all status codes" lists all seven codes with a name and an explanation', async ({ app }) => {
    await openNewEditor(app);
    await app.editor.getByRole('button', { name: 'Explain all status codes' }).click();
    const help = app.editor.getByRole('dialog', { name: 'Explain all status codes' });
    await expect(help).toBeVisible();
    await expect(help.locator('dt')).toHaveCount(7);
    await expect(help.locator('dd')).toHaveCount(7);
    for (const [code, name, text] of CODES) {
      const term = help.locator('dt').filter({ hasText: String(code) });
      await expect(term, `${code}`).toContainText(name);
      await expect(term.locator('xpath=following-sibling::dd[1]'), `${code}`).toContainText(text);
    }
    // Escape closes the popover but not the editor
    await app.page.keyboard.press('Escape');
    await expect(help).toHaveCount(0);
    await expect(app.editor).toBeVisible();
  });

  test('the hint under the status field explains the selected code, for each code', async ({ app }) => {
    await openNewEditor(app);
    const status = app.field('Status code');
    for (const [code, name, text] of CODES) {
      await status.selectOption(String(code));
      await expect(app.editor.locator('#rm-status-hint, [id^="rm-status"]').filter({ hasText: text }).first(), `${code} ${name}`).toBeVisible();
    }
  });
});
