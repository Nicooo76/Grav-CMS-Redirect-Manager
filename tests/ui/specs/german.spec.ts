import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '../support/test';
import { uiDir } from '../support/env';
import { findLeaks, shadowTexts, uiDictionaries } from '../support/i18n-scan';
import { pendingPanel } from '../support/auto-page';
import type { App } from '../support/app';

/**
 * The admin user's language is German (Admin 2 stores it as the account preference `adminLanguage`). Every tab, the
 * editor and the dashboard widget are read in the browser: German texts are there, and no raw translation key, no
 * English fallback and no humanized key shows up anywhere (the whole shadow DOM text and its accessible attributes).
 */

const { de } = uiDictionaries();
const DE_TABS = ['Regeln', '404-Monitor', 'Vorschläge', 'Tester', 'Import / Export', 'Einstellungen'];

test.describe('German UI', () => {
  test.beforeEach(async ({ api }) => {
    expect((await api('PATCH', '/admin-next/preferences/user', { adminLanguage: 'de' })).status).toBe(200);
  });
  test.afterEach(async ({ api }) => {
    await api('DELETE', '/admin-next/preferences/user');
  });

  /** German texts are there, and nothing that looks like a leak */
  async function expectClean(app: App, label: string, skip = ''): Promise<string[]> {
    const texts = await shadowTexts(app.root, skip);
    expect(texts.length, label).toBeGreaterThan(5);
    const leaks = findLeaks(texts);
    expect(leaks, `${label}: leaked keys or English texts`).toEqual({ rawKeys: [], english: [], humanized: [] });
    return texts;
  }

  test('the leak detector itself: a raw key, an English string with and without placeholders and a humanized key are found', async () => {
    const leaks = findLeaks(['PLUGIN_REDIRECT_MANAGER.UI.TAB.RULES', 'TAB.RULES', 'Search redirects', '3 open suggestions', 'Filter Match', 'Regeln']);
    expect(leaks.rawKeys).toHaveLength(2);
    expect(leaks.english.map((l) => l.split('  ')[0])).toEqual(['Search redirects', '3 open suggestions']);
    expect(leaks.humanized.map((l) => l.split('  ')[0])).toEqual(['Filter Match']);
  });

  test('the tab bar and the rules tab are German: toolbar, table header, badges, pagination', async ({ app }) => {
    await app.goto('#/rules');
    const nav = app.root.getByRole('navigation', { name: de['APP.NAV'] });
    await expect(nav).toBeVisible();
    for (const tab of DE_TABS) await expect(nav.getByRole('link', { name: new RegExp(`^${tab}`) })).toBeVisible();
    await expect(app.root.getByRole('button', { name: 'Neue Weiterleitung' })).toBeVisible();
    await expect(app.searchbox).toHaveAccessibleName('Weiterleitungen durchsuchen');
    await expect(app.root.getByRole('columnheader', { name: 'Quelle' })).toBeVisible();
    await expect(app.row('/abgelaufen')).toContainText(de['BADGE.EXPIRED']);
    await expect(app.row('/kette-a')).toContainText(de['BADGE.CHAIN']);
    await expect(app.root.getByRole('navigation', { name: de['COMMON.PAGINATION'] })).toBeVisible();

    await app.root.getByRole('button', { name: /^Filter/ }).click();
    await app.root.getByRole('button', { name: de['RULES.COLUMNS'] }).click();
    await expect(app.root.getByRole('dialog', { name: de['RULES.COLUMNS_TITLE'] })).toBeVisible();
    await expectClean(app, 'rules with filters and column chooser');
  });

  test('rules: selection bar, row menu and the status code dropdown are German', async ({ app }) => {
    await app.goto('#/rules');
    await app.row('/faq-alt').getByRole('checkbox').check();
    await expect(app.root.getByRole('toolbar', { name: de['RULES.BULK_LABEL'] })).toBeVisible();
    await app.row('/faq-alt').getByRole('button', { name: /^Aktionen für/ }).click();
    await expect(app.page.getByRole('menuitem', { name: de['RULES.ACT_DUPLICATE'] })).toBeVisible();
    await expectClean(app, 'rules with selection and menu');
  });

  test('the rule editor (new and existing, every section opened) is German', async ({ app }) => {
    await app.goto('#/rules');
    await app.root.getByRole('button', { name: 'Neue Weiterleitung' }).click();
    await expect(app.editor).toBeVisible();
    await expect(app.editor.getByRole('heading', { level: 2 })).toHaveText(de['EDITOR.TITLE_NEW']);
    for (const summary of await app.editor.locator('details.disc > summary').all()) {
      const details = summary.locator('xpath=..');
      if ((await details.getAttribute('open')) === null) await summary.click();
    }
    await app.editor.getByRole('button', { name: de['EDITOR.STATUS_HELP_ALL'] }).click();
    await expectClean(app, 'editor, new rule');
    await app.page.keyboard.press('Escape');
    await app.page.keyboard.press('Escape');

    await app.goto('#/rules');
    await app.row('/kette-a').getByRole('link').click();
    await expect(app.editor).toBeVisible();
    for (const summary of await app.editor.locator('details.disc > summary').all()) {
      const details = summary.locator('xpath=..');
      if ((await details.getAttribute('open')) === null) await summary.click();
    }
    await expectClean(app, 'editor, chain rule');
  });

  test('404 monitor, suggestions, tester, import, export and settings are German', async ({ app }) => {
    await app.goto('#/404');
    await expect(app.root.locator('tr[data-path]').first()).toBeVisible();
    await expectClean(app, '404 monitor');
    await app.root.locator('tr[data-path] button.exp').first().click();
    await expect(app.root.locator('tr.detail')).toBeVisible();
    await expectClean(app, '404 monitor, entries of the first row');

    await app.goto('#/suggestions');
    await expectClean(app, 'suggestions');

    await app.goto('#/tester?url=/kette-a');
    await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
    await expectClean(app, 'tester with a chain');
    await app.goto('#/tester');
    await expectClean(app, 'tester, empty');

    await app.goto('#/import');
    await app.root.locator('input[type=file]').first().setInputFiles(join(uiDir, 'fixtures', 'import-mixed.csv'));
    await expect(app.root.getByRole('region', { name: de['IMPORTEXPORT.PREVIEW_TITLE'] })).toBeVisible();
    // the sample rows of the mapping table show the file's own cells ("exact", "regex"), not UI text
    await expectClean(app, 'import with preview', '.sample td');

    await app.goto('#/export');
    await expectClean(app, 'export');

    await app.goto('#/settings');
    await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
    await expectClean(app, 'settings');
  });

  test('panels for automatic redirects (pending and unseen) are German', async ({ app, api }) => {
    await api('DELETE', '/pages/shop/outlet');
    await app.goto('#/rules');
    await expect(pendingPanel(app)).toBeVisible();
    await expect(pendingPanel(app).getByRole('heading', { level: 2 })).toHaveText('1 gelöschte Seite wartet auf eine Entscheidung');
    await expectClean(app, 'pending panel');
  });

  test('dashboard widget is German', async ({ page, site }) => {
    await page.goto(`${site.baseUrl}/admin/`);
    const widget = page.locator('grav-widget-redirect-manager-overview');
    await expect(widget.locator('a.tile').first()).toBeVisible();
    await expect(widget.getByRole('region', { name: de['WIDGET.TITLE'] })).toBeVisible();
    const texts = await shadowTexts(widget);
    expect(texts).toContain('Weiterleitungsaufrufe, letzte 7 Tage');
    expect(texts).toContain('Offene Vorschläge');
    expect(findLeaks(texts)).toEqual({ rawKeys: [], english: [], humanized: [] });
  });

  test('axe finds nothing serious in German (rules, editor, 404 monitor, import, suggestions)', async ({ app, page }) => {
    const scan = async (label: string) => {
      const results = await new AxeBuilder({ page }).include('grav-redirect-manager--page').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']).analyze();
      const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.impact} ${v.id} (${v.nodes.length}x) ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`);
      expect(serious, label).toEqual([]);
    };
    await app.goto('#/rules');
    await expect(app.rows.first()).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', /^de/);
    await scan('rules');
    await app.root.getByRole('button', { name: 'Neue Weiterleitung' }).click();
    await expect(app.editor).toBeVisible();
    await scan('editor');
    await page.keyboard.press('Escape');
    await app.goto('#/404');
    await expect(app.root.locator('tr[data-path]').first()).toBeVisible();
    await scan('404 monitor');
    await app.goto('#/suggestions');
    await scan('suggestions');
    await app.goto('#/import');
    await scan('import');
  });

  test('at 1024 px nothing of the rules tab overflows the page horizontally', async ({ app, page }) => {
    await page.setViewportSize({ width: 1024, height: 768 });
    await app.goto('#/rules');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
