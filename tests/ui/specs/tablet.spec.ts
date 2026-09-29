import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { test, expect } from '../support/test';
import type { App } from '../support/app';

/** Responsive down to tablet size: 768 x 1024 (portrait) and 1024 x 768 (landscape). */

const SIZES = [
  { name: 'portrait 768 x 1024', width: 768, height: 1024 },
  { name: 'landscape 1024 x 768', width: 1024, height: 768 },
];

/** pixels the document is wider than the window (0 when there is no horizontal page scroll) */
async function pageOverflow(page: Page): Promise<number> {
  return page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) - document.documentElement.clientWidth);
}

const displayOf = (app: App, selector: string) => app.root.locator(selector).first().evaluate((el) => getComputedStyle(el).display);

async function serious(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).include('grav-redirect-manager--page').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']).analyze();
  return results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.impact} ${v.id} (${v.nodes.length}x) ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`);
}

for (const size of SIZES) {
  test.describe(`tablet, ${size.name}`, () => {
    test.use({ viewport: { width: size.width, height: size.height } });

    test('rules table collapses its secondary columns, keeps source and target, no horizontal page scroll', async ({ app, page }) => {
      await app.goto('#/rules');
      await expect(app.rows.first()).toBeVisible();
      // the columns that give way first (origin and priority, then group and last hit)
      expect(await displayOf(app, 'th.c-origin')).toBe('none');
      expect(await displayOf(app, 'th.c-group')).toBe('none');
      expect(await displayOf(app, 'th.c-last')).toBe('none');
      expect(await displayOf(app, 'th.c-source')).not.toBe('none');
      expect(await displayOf(app, 'th.c-target')).not.toBe('none');
      expect(await displayOf(app, 'th.c-code')).not.toBe('none');
      // the row still tells the whole story of a rule: state, source, target, code
      const row = app.row('/produkt/zelt-alpin-3');
      await expect(row.locator('td.c-source')).toBeVisible();
      await expect(row.locator('td.c-target')).toContainText('/shop/zelte');
      await expect(row.locator('td.c-code')).toContainText('301');
      // the badge label stays in the DOM for screen readers
      await expect(app.row('/abgelaufen').locator('td.c-status')).toContainText('Expired');
      expect(await pageOverflow(page)).toBeLessThanOrEqual(0);
      // the table never grows wider than its card
      const fits = await app.root.locator('.table-wrap').first().evaluate((el) => el.scrollWidth <= el.clientWidth + 1);
      expect(fits).toBe(true);
    });

    test('the editor slide-over is as wide as the window and everything in it stays inside', async ({ app, page }) => {
      await app.goto('#/rules');
      await app.root.getByRole('button', { name: 'New redirect' }).click();
      await expect(app.editor).toBeVisible();
      await expect(app.editor.getByRole('heading', { level: 2 })).toHaveText('New redirect');
      const box = await app.editor.boundingBox();
      if (size.width <= 900) expect(Math.round(box!.width)).toBe(size.width);
      else expect(box!.width).toBeLessThanOrEqual(size.width);
      expect(Math.round(box!.x + box!.width)).toBe(size.width);
      expect(await pageOverflow(page)).toBeLessThanOrEqual(0);
      const inside = await app.editor.evaluate((el) => {
        const body = el.querySelector('.so-body') as HTMLElement;
        return body.scrollWidth <= body.clientWidth + 1;
      });
      expect(inside).toBe(true);
      await expect(app.editor.getByRole('button', { name: 'Close' })).toBeVisible();
      await expect(app.editor.getByRole('button', { name: /^Create|^Save/ }).first()).toBeVisible();
      expect(await serious(page)).toEqual([]);
    });

    test('every tab can be reached and none of them scrolls the page sideways', async ({ app, page }) => {
      await app.goto('#/rules');
      const nav = app.root.getByRole('navigation', { name: 'Redirect manager sections' });
      const tabs: Array<{ link: RegExp; hash: string }> = [
        { link: /^Rules/, hash: '#/rules' },
        { link: /^404 monitor/, hash: '#/404' },
        { link: /^Suggestions/, hash: '#/suggestions' },
        { link: /^Tester/, hash: '#/tester' },
        { link: /^Import \/ Export/, hash: '#/import' },
        { link: /^Settings/, hash: '#/settings' },
      ];
      for (const tab of tabs) {
        const link = nav.getByRole('link', { name: tab.link });
        await link.scrollIntoViewIfNeeded();
        await expect(link).toBeVisible();
        await link.click();
        await expect(page).toHaveURL(new RegExp(tab.hash));
        await expect(link).toHaveAttribute('aria-current', 'page');
        await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
        expect(await pageOverflow(page), tab.hash).toBeLessThanOrEqual(0);
      }
    });

    test('404 monitor, import and rules list have no horizontal scroll and axe finds nothing serious', async ({ app, page }) => {
      await app.goto('#/404');
      await expect(app.root.locator('tr[data-path]').first()).toBeVisible();
      expect(await pageOverflow(page)).toBeLessThanOrEqual(0);
      expect(await serious(page)).toEqual([]);

      await app.goto('#/import');
      await expect(app.root.getByRole('region', { name: 'Import redirects' })).toBeVisible();
      expect(await pageOverflow(page)).toBeLessThanOrEqual(0);
      expect(await serious(page)).toEqual([]);

      await app.goto('#/rules');
      await expect(app.rows.first()).toBeVisible();
      expect(await serious(page)).toEqual([]);
    });
  });
}
