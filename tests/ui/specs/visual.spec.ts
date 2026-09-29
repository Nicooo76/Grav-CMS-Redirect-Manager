import { existsSync } from 'node:fs';
import { join } from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { test, expect } from '../support/test';
import type { App, Theme } from '../support/app';
import { uiDir } from '../support/env';

/**
 * Screenshot comparison of the main screens in both themes. Baselines live per platform
 * (specs/__screenshots__/<platform>/visual.spec.ts/), because fonts and text rendering differ between macOS and Linux.
 * Without baselines for the current platform the tests are skipped, so CI does not fail on a platform nobody generated
 * baselines for. RM_VISUAL=1 forces them (and creates missing baselines with --update-snapshots), RM_VISUAL=0 skips them.
 * See README.md.
 */
const baselineDir = join(uiDir, 'specs', '__screenshots__', process.platform, 'visual.spec.ts');
const enabled = process.env.RM_VISUAL === '1' || (process.env.RM_VISUAL !== '0' && existsSync(baselineDir));
test.skip(!enabled, `No visual baselines for ${process.platform} (${baselineDir}); set RM_VISUAL=1 --update-snapshots to create them.`);

const THEMES: Theme[] = ['light', 'dark'];

/** Relative times ("Last hit", "First seen", "Last seen") change with the time between seeding and the test. */
const relativeTimes = (app: App): Locator[] => [app.root.locator('td.c-last, td.c-first')];
/** The 404 trend chart has a date axis that moves every day. */
const trendChart = (app: App): Locator[] => [app.root.locator('.plot')];

async function shot(target: Page | Locator, app: App, name: string, theme: Theme, mask: Locator[] = []): Promise<void> {
  await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
  await expect(target as Page).toHaveScreenshot(`${name}-${theme}.png`, { mask, maskColor: '#ff00ff' });
}

for (const theme of THEMES) {
  test.describe(`visual (${theme}) @visual`, () => {
    test('rules', async ({ app, page }) => {
      await app.goto('#/rules', theme);
      await expect(app.rows.first()).toBeVisible();
      await shot(page, app, 'rules', theme, relativeTimes(app));
    });

    test('editor: new rule with preview', async ({ app, page }) => {
      await app.goto('#/rules', theme);
      await app.openEditorWithKey();
      await app.field('Source').fill('/blog/*');
      await app.editor.getByRole('button', { name: 'Switch to wildcard' }).click();
      await app.field('Target').fill('/journal/$1');
      await expect(app.editor.locator('#pv-result')).toContainText('/journal/example');
      await shot(app.editor, app, 'editor-new', theme);
    });

    test('editor: rule with a chain warning', async ({ app, page }) => {
      await app.goto('#/rules', theme);
      await app.row('/kette-a').getByRole('link', { name: '/kette-a', exact: true }).click();
      await expect(app.editor.getByRole('list', { name: 'Checks' })).toBeVisible();
      await expect(app.editor.locator('#pv-result')).toContainText('/kette-b');
      await shot(app.editor, app, 'editor-chain', theme, [app.editor.locator('.meta')]);
    });

    test('404 monitor', async ({ app, page }) => {
      await app.goto('#/404', theme);
      await expect(app.root.locator('tbody tr[data-path]').first()).toBeVisible();
      await shot(page, app, '404', theme, [...relativeTimes(app), ...trendChart(app)]);
    });

    test('suggestions', async ({ app, page }) => {
      await app.goto('#/suggestions', theme);
      await expect(app.root.locator('tbody tr').first()).toBeVisible();
      await shot(page, app, 'suggestions', theme);
    });

    test('tester with a chain', async ({ app, page }) => {
      await app.goto('#/tester?url=%2Fkette-a', theme);
      await expect(app.root.locator('ol.chain > li').first()).toBeVisible();
      await shot(page, app, 'tester', theme);
    });

    test('import', async ({ app, page }) => {
      await app.goto('#/import', theme);
      await shot(page, app, 'import', theme);
    });
  });
}
