import type { Page } from '@playwright/test';
import { test, expect } from '../support/test';
import type { App, Theme } from '../support/app';

/**
 * The plugin page uses Admin 2's design tokens: what it paints equals what the host's CSS variables say, in light and
 * dark, and follows a change of the user's accent colour and font. Icons are Lucide, the set of Admin 2 itself.
 */

/** the colour a host CSS value (a variable, a colour mix) resolves to, as getComputedStyle reports it (through a probe) */
async function hostColorOf(page: Page, value: string): Promise<string> {
  return page.evaluate((cssValue) => {
    const probe = document.createElement('div');
    probe.style.cssText = `position:fixed;visibility:hidden;background-color:${cssValue};`;
    document.body.appendChild(probe);
    const c = getComputedStyle(probe).backgroundColor;
    probe.remove();
    return c;
  }, value);
}
const hostColor = (page: Page, variable: string) => hostColorOf(page, `var(${variable})`);

/**
 * The solid colour of primary buttons and count badges: the host's --primary in light mode. In dark mode the plugin
 * takes a deeper shade of the same hue (the host's light primary is too light for white text: 3.6 to 1).
 */
const solidPrimary = (page: Page, theme: Theme) => (theme === 'light' ? hostColor(page, '--primary') : hostColorOf(page, 'color-mix(in oklab, var(--primary) 72%, #000)'));

const hostFont = (page: Page) => page.evaluate(() => getComputedStyle(document.body).fontFamily);

interface Painted {
  buttonBg: string;
  buttonFg: string;
  cardBg: string;
  cardBorder: string;
  text: string;
  font: string;
}

async function painted(app: App): Promise<Painted> {
  await expect(app.root.locator('button.btn.primary').first()).toBeVisible();
  return app.root.evaluate((el) => {
    const r = el.shadowRoot!;
    const button = getComputedStyle(r.querySelector('button.btn.primary') as HTMLElement);
    const card = getComputedStyle(r.querySelector('.card') as HTMLElement);
    const root = getComputedStyle(r.querySelector('.rm-root') as HTMLElement);
    return { buttonBg: button.backgroundColor, buttonFg: button.color, cardBg: card.backgroundColor, cardBorder: card.borderTopColor, text: root.color, font: root.fontFamily };
  });
}

for (const theme of ['light', 'dark'] as Theme[]) {
  test.describe(`host tokens, ${theme}`, () => {
    // switching the colour mode is saved in the account: leave it as it was
    test.afterEach(async ({ api }) => {
      await api('DELETE', '/admin-next/preferences/user');
    });

    test('button, card, border, text and font equal the host CSS variables', async ({ app, page }) => {
      await app.goto('#/rules', theme);
      const p = await painted(app);
      expect(p.buttonBg).toBe(await solidPrimary(page, theme));
      expect(p.buttonFg).toBe(await hostColor(page, '--primary-foreground'));
      expect(p.cardBg).toBe(await hostColor(page, '--card'));
      expect(p.cardBorder).toBe(await hostColor(page, '--border'));
      expect(p.font).toBe(await hostFont(page));
      // the text colour is the host's foreground
      const fg = await page.evaluate(() => {
        const probe = document.createElement('div');
        probe.style.cssText = 'position:fixed;visibility:hidden;color:var(--foreground);';
        document.body.appendChild(probe);
        const c = getComputedStyle(probe).color;
        probe.remove();
        return c;
      });
      expect(p.text).toBe(fg);
    });

    test('the two themes really differ, so the comparison above is not trivially true', async ({ app, page }) => {
      await app.goto('#/rules', 'light');
      const light = await painted(app);
      await app.setTheme('dark');
      const dark = await painted(app);
      expect(dark.cardBg).not.toBe(light.cardBg);
      expect(dark.text).not.toBe(light.text);
      expect(dark.cardBg).toBe(await hostColor(page, '--card'));
    });
  });
}

test.describe('host tokens follow the user preferences', () => {
  test.afterEach(async ({ api }) => {
    await api('DELETE', '/admin-next/preferences/user');
  });

  test('a different accent colour and font of the Admin 2 user show up in the plugin page', async ({ app, api, page }) => {
    // Admin 2 keeps the colour mode per user, so a test that switched to dark can leave it there: say which one
    await app.goto('#/rules', 'light');
    const before = await painted(app);
    const fontBefore = await hostFont(page);
    expect(before.buttonBg).toBe(await solidPrimary(page, 'light'));

    const set = await api('PATCH', '/admin-next/preferences/user', { accentHue: 145, accentSaturation: 70, fontFamily: 'inter' });
    expect(set.status).toBe(200);
    await app.reload();
    const after = await painted(app);
    const primary = await hostColor(page, '--primary');
    expect(primary).not.toBe(before.buttonBg);
    expect(after.buttonBg).toBe(primary);
    expect(after.buttonBg).not.toBe(before.buttonBg);
    const fontAfter = await hostFont(page);
    expect(fontAfter).not.toBe(fontBefore);
    expect(fontAfter).toMatch(/Inter/);
    expect(after.font).toBe(fontAfter);
  });
});

test.describe('icons', () => {
  test('the plugin draws Lucide icons like Admin 2 itself: 24 x 24 outline SVGs in currentColor', async ({ app, page }) => {
    await app.goto('#/rules');
    const icons = await app.root.evaluate((el) => {
      const svgs = Array.from(el.shadowRoot!.querySelectorAll('svg')).filter((s) => !s.classList.contains('spark'));
      return svgs.map((s) => ({ cls: s.getAttribute('class') ?? '', viewBox: s.getAttribute('viewBox'), stroke: s.getAttribute('stroke'), fill: s.getAttribute('fill'), width: s.getAttribute('stroke-width'), paths: s.querySelectorAll('path, circle, line, rect, polyline').length }));
    });
    expect(icons.length).toBeGreaterThan(10);
    for (const icon of icons) {
      expect(icon.cls).toMatch(/\blucide\b/);
      expect(icon.cls).toMatch(/\blucide-[a-z0-9-]+\b/);
      expect(icon.viewBox).toBe('0 0 24 24');
      expect(icon.stroke).toBe('currentColor');
      expect(icon.fill).toBe('none');
      expect(icon.paths).toBeGreaterThan(0);
    }
    // the host's sidebar uses the same class scheme
    const host = await page.evaluate(() => Array.from(document.querySelectorAll('aside svg[class*="lucide"]')).map((s) => s.getAttribute('class')));
    expect(host.length).toBeGreaterThan(5);
    for (const cls of host) expect(cls).toMatch(/\blucide-icon lucide lucide-/);
    // and the plugin's own icons carry the same "lucide-icon lucide lucide-<name>" class
    expect(icons.every((i) => /lucide-icon lucide lucide-/.test(i.cls))).toBe(true);
  });
});
