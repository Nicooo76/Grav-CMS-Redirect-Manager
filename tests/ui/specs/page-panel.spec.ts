import AxeBuilder from '@axe-core/playwright';
import { resolve } from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/test';
import { expectBadgeInSync, rulesFor } from '../support/auto-page';
import type { Theme } from '../support/app';
import { repoRoot } from '../support/env';

/**
 * The context panel of Admin 2's page editor (admin-next/panels/redirect-manager.js): the toolbar button with its
 * badge, the panel that lists the redirects of the open page, and what the panel does. The pages are edited in the
 * host's own page editor, nothing is mocked.
 */

const OLD = '/blog/wintercamping-tipps';
const NEW = '/blog/wintercamping-guide';
const LABEL = 'Redirects for this page';

/** the toolbar button Admin 2 adds for the panel (host DOM); its badge is the number inside */
const trigger = (page: Page): Locator => page.locator(`button[title="${LABEL}"]`);
const panel = (page: Page): Locator => page.locator('grav-redirect-manager--panel');

async function openEditor(page: Page, baseUrl: string, route: string): Promise<void> {
  await page.goto(`${baseUrl}/admin/pages/edit${route}`);
  await page.getByRole('button', { name: 'Advanced', exact: true }).waitFor();
  await expect(trigger(page)).toBeVisible();
}

async function renameFolder(page: Page, name: string): Promise<void> {
  await page.getByRole('button', { name: 'Advanced', exact: true }).click();
  await page.getByRole('textbox', { name: 'folder-name' }).fill(name);
  await page.getByRole('button', { name: 'Save', exact: true }).click();
}

async function openPanel(page: Page): Promise<Locator> {
  await trigger(page).click();
  const p = panel(page);
  await expect(p.getByRole('heading', { level: 2, name: LABEL })).toBeVisible();
  await expect(p.locator('[aria-busy="true"]')).toHaveCount(0);

  return p;
}

async function setTheme(page: Page, theme: Theme): Promise<void> {
  const html = page.locator('html');
  const isDark = async () => (await html.getAttribute('class'))?.split(/\s+/).includes('dark') ?? false;
  if ((await isDark()) === (theme === 'dark')) return;
  await page.getByRole('button', { name: 'Toggle dark mode' }).click();
  await expect.poll(isDark).toBe(theme === 'dark');
}

test.describe('page editor panel', () => {
  test('the toolbar button opens the panel for the open page, and closes with Escape, the close button and the backdrop', async ({ page, site }) => {
    await openEditor(page, site.baseUrl, OLD);
    // the seed has one rule that leads here: a disabled campaign redirect
    const p = await openPanel(page);
    await expect(p.getByTestId('rm-panel-route')).toHaveText(OLD);
    const incoming = p.getByTestId('rm-panel-incoming');
    await expect(incoming.getByRole('heading', { level: 3 })).toHaveText('Redirects to this page');
    await expect(incoming.getByRole('listitem')).toHaveCount(1);
    await expect(incoming).toContainText('/aktion/winter');
    await expect(incoming).toContainText('302');
    await expect(incoming).toContainText('Disabled');
    await expect(p.getByTestId('rm-panel-unseen')).toHaveCount(0);
    await expect(p.getByTestId('rm-panel-outgoing')).toHaveCount(0);
    await expect(trigger(page).locator('span')).toHaveCount(0);

    await page.keyboard.press('Escape');
    await expect(p).toHaveCount(0);
    await openPanel(page);
    await panel(page).getByRole('button', { name: 'Close' }).click();
    await expect(panel(page)).toHaveCount(0);
    await openPanel(page);
    await page.mouse.click(20, 400);
    await expect(panel(page)).toHaveCount(0);
  });

  test('renaming the page in the editor: the toolbar badge shows 1, the panel lists the new automatic redirect, "Mark as seen" clears both', async ({ page, site, api }) => {
    await openEditor(page, site.baseUrl, OLD);
    await renameFolder(page, 'wintercamping-guide');
    await expect(page).toHaveURL(new RegExp(`/admin/pages/edit${NEW}$`));

    // the host asks the badge endpoint again for the new route
    await expect(trigger(page).locator('span')).toHaveText('1');
    const p = await openPanel(page);
    await expect(p.getByTestId('rm-panel-route')).toHaveText(NEW);
    const unseen = p.getByTestId('rm-panel-unseen');
    await expect(unseen.getByRole('heading')).toHaveText('Created automatically just now');
    await expect(unseen.getByRole('listitem')).toHaveCount(1);
    await expect(unseen.getByRole('listitem')).toContainText(OLD);
    await expect(unseen.getByRole('listitem')).toContainText(NEW);
    await expect(unseen.getByRole('listitem')).toContainText('301');

    const incoming = p.getByTestId('rm-panel-incoming').getByRole('listitem');
    await expect(incoming).toHaveCount(1);
    await expect(incoming.first()).toContainText(OLD);
    await expect(incoming.first()).toContainText('Automatic');
    await expect(incoming.first()).toContainText('No hits yet');

    // the sidebar counts the same rule
    await expectBadgeInSync(page, api, 1);

    await unseen.getByRole('button', { name: 'Mark as seen' }).click();
    await expect(unseen).toHaveCount(0);
    await expect(trigger(page).locator('span')).toHaveCount(0);
    await expect(p.getByRole('status')).toHaveText('Marked as seen.');
    await expectBadgeInSync(page, api, 0);
    expect((await api('GET', `/redirects/page-context/badge?route=${encodeURIComponent(NEW)}`)).data.count).toBeNull();
    // still listed as a redirect to this page
    await expect(p.getByTestId('rm-panel-incoming')).toContainText(OLD);
  });

  test('a page that a rule redirects away shows the warning with the rule', async ({ page, site, api }) => {
    expect((await api('POST', '/redirects/rules', { source: OLD, target: '/shop', match_type: 'exact', status: 302 })).status).toBe(201);
    await openEditor(page, site.baseUrl, OLD);
    const p = await openPanel(page);

    const warning = p.getByTestId('rm-panel-outgoing');
    await expect(warning.getByText('This page is redirected away')).toBeVisible();
    await expect(warning).toContainText(`${OLD} sends visitors to /shop (302)`);
    await expect(warning.getByRole('link', { name: 'Open the rule' })).toHaveAttribute('href', /\/admin\/plugin\/redirect-manager#\/rules\/[\w-]+$/);
  });

  test('adding an old URL from the panel checks it while typing, creates a 301 exact rule to the page and lists it', async ({ page, site, api }) => {
    await openEditor(page, site.baseUrl, OLD);
    const p = await openPanel(page);
    const add = p.getByTestId('rm-panel-add');
    const input = add.getByLabel('Old URL', { exact: true });
    const submit = add.getByRole('button', { name: 'Add redirect' });
    await expect(submit).toBeDisabled();

    // the page's own address is a loop: the check says so before anything is saved
    await input.fill(OLD);
    await expect(add.getByRole('alert')).toBeVisible();
    await expect(submit).toBeDisabled();
    expect(await rulesFor(api, OLD)).toEqual([]); // nothing was written

    await input.fill('https://example.com/winter-tipps-alt');
    await expect(add.getByRole('alert')).toHaveCount(0);
    await expect(submit).toBeEnabled();
    await submit.click();

    await expect(page.locator('li.grav-toast').filter({ hasText: 'Redirect added: /winter-tipps-alt now leads to this page.' })).toBeVisible();
    await expect(input).toHaveValue('');
    const list = p.getByTestId('rm-panel-incoming').getByRole('listitem');
    await expect(list).toHaveCount(2);
    await expect(list.filter({ hasText: '/winter-tipps-alt' })).toContainText('301');

    const rule = (await api('GET', '/redirects/rules?q=' + encodeURIComponent('/winter-tipps-alt'))).data.find((r: any) => r.source === '/winter-tipps-alt');
    expect(rule).toMatchObject({ target: OLD, target_type: 'page', match_type: 'exact', status: 301, origin: 'manual', enabled: true });
    expect(rule.note).toBe('Added in the page editor');

    // the public site follows it
    const res = await site.get('/winter-tipps-alt');
    expect(res.status).toBe(301);
    expect(res.headers.get('location')).toBe(OLD);
    expect((await site.get(OLD)).status).toBe(200);
  });

  test('"Open in Redirect Manager" closes the panel and opens the plugin page filtered to this page', async ({ page, site }) => {
    await openEditor(page, site.baseUrl, OLD);
    const p = await openPanel(page);
    await p.getByRole('link', { name: 'Open in Redirect Manager' }).click();

    await expect(page).toHaveURL(new RegExp(`/admin/plugin/redirect-manager#/rules\\?q=${encodeURIComponent(OLD)}$`));
    await expect(panel(page)).toHaveCount(0);
    await expect(page.locator('grav-redirect-manager--page')).toBeVisible();
  });
});

/** WCAG 2.x A/AA plus best practices on the panel; only `serious` and `critical` findings fail. */
async function scan(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page })
    .include('grav-redirect-manager--panel')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
    .analyze();

  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.impact} ${v.id}: ${v.help} (${v.nodes.length}x) ${v.nodes.map((n) => `${n.target.join(' ')} [${(n.any[0]?.message ?? '').slice(0, 160)}]`).join(' | ')}`);
}

for (const theme of ['light', 'dark'] as Theme[]) {
  test(`a11y of the panel with a new automatic redirect, a warning and an error in the form (${theme})`, async ({ page, site, api }) => {
    // the page is redirected away (warning), was renamed (unseen rule) and its form shows an error
    await openEditor(page, site.baseUrl, OLD);
    await renameFolder(page, 'wintercamping-guide');
    await expect(page).toHaveURL(new RegExp(`${NEW}$`));
    expect((await api('POST', '/redirects/rules', { source: NEW, target: '/shop', match_type: 'exact', status: 302 })).status).toBe(201);
    await page.reload();
    await expect(trigger(page)).toBeVisible();
    await setTheme(page, theme);

    const p = await openPanel(page);
    await expect(p.getByTestId('rm-panel-unseen')).toBeVisible();
    await expect(p.getByTestId('rm-panel-outgoing')).toBeVisible();
    await p.getByLabel('Old URL', { exact: true }).fill(NEW);
    await expect(p.getByTestId('rm-panel-add').getByRole('alert')).toBeVisible();
    expect(await scan(page)).toEqual([]);
    // the colour mode is kept per user: leave it as found
    await page.keyboard.press('Escape');
    await expect(panel(page)).toHaveCount(0);
    await setTheme(page, 'light');
  });
}

// README screenshots: `RM_SHOTS=1 npx playwright test page-panel -g screenshot` writes docs/screenshots/page-panel-<theme>.png
test('screenshot of the panel for the README', async ({ page, site }) => {
  test.skip(!process.env.RM_SHOTS, 'only with RM_SHOTS=1');
  const out = resolve(repoRoot, 'docs', 'screenshots');
  for (let i = 0; i < 3; i++) await site.get('/winter-tipps-alt');
  await openEditor(page, site.baseUrl, OLD);
  await setTheme(page, 'light');
  await renameFolder(page, 'wintercamping-guide');
  await expect(page).toHaveURL(new RegExp(`${NEW}$`));
  for (let i = 0; i < 4; i++) await site.get(OLD);
  const p = await openPanel(page);
  await p.getByLabel('Old URL', { exact: true }).fill('/winter-tipps-alt');
  await expect(p.getByRole('button', { name: 'Add redirect' })).toBeEnabled();
  await p.getByRole('button', { name: 'Add redirect' }).click();
  await expect(p.getByTestId('rm-panel-notfound')).toBeVisible();
  await expect(p.getByTestId('rm-panel-unseen')).toBeVisible();
  // the host's toast would cover the footer
  await expect(page.locator('li.grav-toast')).toHaveCount(0, { timeout: 15_000 });
  await page.waitForTimeout(600);
  await page.screenshot({ path: resolve(out, 'page-panel-light.png') });
  await page.keyboard.press('Escape');
  await expect(panel(page)).toHaveCount(0);
  await setTheme(page, 'dark');
  await openPanel(page);
  await page.waitForTimeout(600);
  await page.screenshot({ path: resolve(out, 'page-panel-dark.png') });
  await page.keyboard.press('Escape');
  await setTheme(page, 'light');
});
