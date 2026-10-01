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

const { en, de } = uiDictionaries();
const UI_PREFIX = 'PLUGIN_REDIRECT_MANAGER.UI.';
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

  /**
   * What the server hands the browser, not what the page makes of it. Grav reads languages.yaml with the libyaml
   * extension when the server has it, and libyaml reads a bare `YES:` or `NO:` key as a boolean: COMMON.YES then
   * vanished from the German dictionary and the page showed the English backfill (CI, where the extension is loaded,
   * failed on "Yes" in the tester trace; a server without it never shows it). Whichever PHP runs the site, the
   * dictionary has to be the file.
   */
  for (const [lang, code, expected] of [['English', 'en-US', en], ['German', 'de-DE', de]] as const) {
    test(`the server's ${lang} dictionary carries every UI string of languages.yaml`, async ({ site }) => {
      const res = await fetch(`${site.baseUrl}/api/v1/translations/${code}`);
      expect(res.status).toBe(200);
      const body = (await res.json()) as { strings?: Record<string, string>; data?: { strings?: Record<string, string> } };
      const strings = body.strings ?? body.data?.strings ?? {};
      const served = Object.fromEntries(Object.entries(strings).filter(([k]) => k.startsWith(UI_PREFIX)).map(([k, v]) => [k.slice(UI_PREFIX.length), v]));
      const missing = Object.keys(expected).filter((k) => !(k in served));
      expect(missing, 'keys of languages.yaml the server does not serve').toEqual([]);
      expect(Object.keys(served).filter((k) => !(k in expected)), 'keys the server serves that are not in languages.yaml').toEqual([]);
      expect(Object.keys(expected).filter((k) => served[k] !== expected[k])).toEqual([]);
    });
  }

  test('a dictionary that arrives after the first render turns every text German, the tester result and its live region included', async ({ app, page }) => {
    await page.route('**/api/v1/translations/**', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1500));
      await route.continue();
    });
    // a deep link tests on mount: the result is rendered long before the German texts are there
    await page.goto(`${app.baseUrl}/admin/plugin/redirect-manager#/tester?url=/kette-a`);
    await expect(app.root).toBeVisible({ timeout: 30_000 });
    await expect(app.root.locator('table.trace')).toBeVisible();
    await expect(app.root.getByRole('navigation').first()).toContainText('Rules');

    await expect(app.root.getByRole('navigation').first()).toContainText(de['TAB.RULES'], { timeout: 15_000 });
    await expect
      .poll(async () => findLeaks(await shadowTexts(app.root)), { message: 'texts that stayed English or became keys', timeout: 10_000 })
      .toEqual({ rawKeys: [], english: [], humanized: [] });
    await expect(app.root.locator('table.trace td .yes')).toHaveText(de['COMMON.YES']);
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

  test('the panel in the page editor is German (unseen notice, lists, form)', async ({ page, site, api }) => {
    // a slug change through the API is what the editor's save sends; the plugin creates the automatic rule
    expect((await api('PATCH', '/pages/blog/wintercamping-tipps', { header: { slug: 'wintercamping-guide' } })).status).toBe(200);
    await page.goto(`${site.baseUrl}/admin/pages/edit/blog/wintercamping-guide`);
    // the button's tooltip is translated by the server in the site's language (English here), Admin 2 shows it verbatim
    const trigger = page.locator(`button[title="Redirects for this page"], button[title="${de['PANEL.TITLE']}"]`);
    await expect(trigger).toBeVisible();
    await expect(trigger.locator('span')).toHaveText('1');
    await trigger.click();
    const panel = page.locator('grav-redirect-manager--panel');
    await expect(panel.getByRole('heading', { level: 2 })).toHaveText('Weiterleitungen dieser Seite');
    await expect(panel.getByTestId('rm-panel-unseen').getByRole('heading')).toHaveText('Gerade automatisch angelegt');
    await expect(panel.getByRole('button', { name: 'Als gesehen markieren' })).toBeVisible();
    await expect(panel.getByRole('heading', { level: 3, name: 'Weiterleitungen auf diese Seite' })).toBeVisible();
    await panel.getByLabel('Alte URL', { exact: true }).fill('/alte-adresse');
    await expect(panel.getByRole('button', { name: 'Weiterleitung anlegen' })).toBeEnabled();
    await expect(panel.getByRole('link', { name: 'Im Redirect Manager öffnen' })).toBeVisible();
    const texts = await shadowTexts(panel);
    expect(texts.length).toBeGreaterThan(8);
    expect(findLeaks(texts)).toEqual({ rawKeys: [], english: [], humanized: [] });
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
