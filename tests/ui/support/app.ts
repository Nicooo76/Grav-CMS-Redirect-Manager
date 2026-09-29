import { expect, type Locator, type Page } from '@playwright/test';

export type Theme = 'light' | 'dark';

/** The Redirect Manager page inside Admin 2. Playwright's locators pierce the open shadow root of the custom element. */
export class App {
  constructor(
    readonly page: Page,
    readonly baseUrl: string,
  ) {}

  /** the plugin page (a custom element with a shadow root) */
  get root(): Locator {
    return this.page.locator('grav-redirect-manager--page');
  }

  /** rows of the rules table */
  get rows(): Locator {
    return this.root.locator('tbody tr[data-id]');
  }

  /** the row of the rule with exactly this source */
  row(source: string): Locator {
    return this.rows.filter({ has: this.page.getByRole('link', { name: source, exact: true }) });
  }

  /** the slide-over editor */
  get editor(): Locator {
    return this.root.getByRole('dialog').first();
  }

  get searchbox(): Locator {
    return this.root.getByRole('searchbox');
  }

  /** toasts belong to Admin 2 (the host), so they are outside the plugin's shadow root */
  get toasts(): Locator {
    return this.page.locator('li.grav-toast');
  }

  toast(text: string | RegExp): Locator {
    return this.toasts.filter({ hasText: text });
  }

  /** a section of the editor (a <details>) */
  section(title: string): Locator {
    return this.editor.locator('details.disc', { has: this.page.locator('summary', { hasText: title }) });
  }

  async openSection(title: string): Promise<void> {
    const section = this.section(title);
    if ((await section.getAttribute('open')) === null) await section.locator('summary').click();
  }

  /** open a route of the plugin page (`#/rules`, `#/404`, `#/tester?url=/x`, ...) in a fresh page load */
  async goto(hash = '#/rules', theme: Theme = 'light'): Promise<void> {
    await this.page.goto(`${this.baseUrl}/admin/plugin/redirect-manager${hash}`);
    await this.ready();
    await this.setTheme(theme);
  }

  /** reload the current route (after the test changed data behind the UI's back) */
  async reload(): Promise<void> {
    await this.page.reload();
    await this.ready();
  }

  /** the page is mounted and nothing is loading */
  async ready(): Promise<void> {
    await expect(this.root).toBeVisible({ timeout: 30_000 });
    // the tab bar (its name is translated, so the language must not matter here)
    await expect(this.root.getByRole('navigation').first()).toBeVisible();
    await expect(this.root.locator('[aria-busy="true"]')).toHaveCount(0);
  }

  /** Admin 2 keeps the colour mode per user; this clicks its toggle only when the mode differs. */
  async setTheme(theme: Theme): Promise<void> {
    const html = this.page.locator('html');
    const isDark = async () => (await html.getAttribute('class'))?.split(/\s+/).includes('dark') ?? false;
    if ((await isDark()) === (theme === 'dark')) return;
    await this.page.getByRole('button', { name: 'Toggle dark mode' }).click();
    if (theme === 'dark') await expect(html).toHaveClass(/(^|\s)dark(\s|$)/);
    else await expect(html).not.toHaveClass(/(^|\s)dark(\s|$)/);
  }

  /** Types into the rules search and waits for the list to settle. */
  async search(text: string): Promise<void> {
    await this.searchbox.fill(text);
    await expect(this.root.locator('[aria-busy="true"]')).toHaveCount(0);
  }

  /** opens the editor with the `n` shortcut */
  async openEditorWithKey(): Promise<void> {
    await this.root.getByRole('button', { name: 'New redirect' }).waitFor();
    await this.page.locator('body').press('n');
    await expect(this.editor).toBeVisible();
  }

  /** a labelled field of the editor */
  field(label: string): Locator {
    return this.editor.getByLabel(label, { exact: true });
  }
}
