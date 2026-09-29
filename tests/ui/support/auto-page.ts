import { expect, type Locator, type Page } from '@playwright/test';
import type { ApiCall } from './api';
import type { App } from './app';

/** The Redirects entry of the Admin 2 sidebar (host DOM, outside the plugin's shadow root). */
export function sidebarLink(page: Page): Locator {
  return page.locator('a[href$="/plugin/redirect-manager"]').first();
}

/** the sidebar pill shows the number of GET /redirects/badge (nothing or "0" for none) */
export async function expectSidebarBadge(page: Page, count: number): Promise<void> {
  const link = sidebarLink(page);
  if (count > 0) await expect(link).toHaveText(new RegExp(`Redirects\\s*${count}\\s*$`));
  else await expect(link).toHaveText(/^\s*Redirects\s*(0)?\s*$/);
}

/** the API's badge numbers (null counts as 0) */
export async function badge(api: ApiCall): Promise<{ count: number; unseen: number; pending: number }> {
  const d = (await api('GET', '/redirects/badge')).data;
  // the server sends null for "nothing to show"
  return { count: d.count ?? 0, unseen: d.unseen ?? 0, pending: d.pending ?? 0 };
}

/** waits until the API reports this count, then checks the sidebar shows the same */
export async function expectBadgeInSync(page: Page, api: ApiCall, count?: number): Promise<void> {
  if (count !== undefined) await expect.poll(async () => (await badge(api)).count).toBe(count);
  await expectSidebarBadge(page, (await badge(api)).count);
}

export function pendingPanel(app: App): Locator {
  return app.root.locator('[data-testid=pending-panel]');
}

/** the entry of a deleted page (by route) in the pending panel */
export function pendingItem(app: App, route: string): Locator {
  return pendingPanel(app).locator('li.item').filter({ has: app.page.getByText(route, { exact: true }) });
}

export function unseenPanel(app: App): Locator {
  return app.root.locator('[data-testid=unseen-panel]');
}

/** the auto rules (any origin filter is up to the caller) whose source starts with a route */
export async function rulesFor(api: ApiCall, route: string): Promise<Array<{ source: string; target: string; status: number; origin: string }>> {
  const rows = (await api('GET', `/redirects/rules?q=${encodeURIComponent(route)}&per_page=50`)).data as Array<{ source: string; target: string; status: number; origin: string }>;
  return rows.filter((r) => r.source === route || r.source.startsWith(route + '/')).sort((a, b) => a.source.localeCompare(b.source));
}
