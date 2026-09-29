import type { Locator, Page } from '@playwright/test';

/**
 * Confirm and form dialogs are rendered by Admin 2 (the host), outside the plugin's shadow root. The host's overlay is a
 * plain <div> without a role, appended to <body>; the title (its heading) picks it.
 * Suggested hook for the host: role="dialog" or "alertdialog" with aria-label = title.
 */
export function hostDialog(page: Page, title: string | RegExp): Locator {
  return page.locator('body > div').filter({ has: page.getByRole('heading', { name: title }) }).last();
}
