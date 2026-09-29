import type { Page, Route } from '@playwright/test';
import { test, expect } from '../support/test';
import { allRules, handleOf, openRowMenu, ruleOf, selectRows, shownSources, bulkBar } from '../support/rules-page';

/**
 * Optimistic updates and their rollback. The API answer is replaced through `page.route`: it is held back until the
 * test has seen the optimistic value in the UI, then released as a failure. The UI must go back to the previous value
 * and say why in an error toast; the server (which never saw the request) keeps its data.
 */

interface Failure {
  status: number;
  body?: Record<string, unknown>;
}
const SERVER_ERROR: Failure = { status: 500, body: { title: 'Internal Server Error', status: 500 } };
const SERVER_ERROR_TEXT = 'The server reported an error (500).';

/** Holds every request for which `match` is true; `release()` answers them all with the failure. */
async function failWhenReleased(page: Page, method: string, urlPart: RegExp, failure: Failure = SERVER_ERROR) {
  const held: Route[] = [];
  let released = false;
  await page.route(
    (url) => urlPart.test(url.pathname + url.search),
    async (route) => {
      if (route.request().method() !== method) return route.fallback();
      if (released) return route.fulfill({ status: failure.status, contentType: 'application/json', body: JSON.stringify(failure.body ?? {}) });
      held.push(route);
    },
  );
  return {
    /** requests waiting for the answer */
    async waiting(): Promise<number> {
      await expect.poll(() => held.length).toBeGreaterThan(0);
      return held.length;
    },
    async release(): Promise<void> {
      released = true;
      for (const route of held.splice(0)) await route.fulfill({ status: failure.status, contentType: 'application/json', body: JSON.stringify(failure.body ?? {}) });
    },
  };
}

test.describe('optimistic updates roll back when the server refuses', () => {
  test('inline edit of the target: the new value shows first, then the old one is back with an error toast', async ({ app, api, page }) => {
    await app.goto('#/rules');
    const gate = await failWhenReleased(page, 'PATCH', /\/redirects\/rules\/[^/]+$/);
    const row = app.row('/faq-alt');
    await row.getByRole('button', { name: 'Edit target: /faq' }).click();
    const input = row.getByRole('textbox', { name: 'Target of /faq-alt' });
    await input.fill('/kontakt');
    await input.press('Enter');

    // optimistic: the row already shows the new target while the request is pending
    await gate.waiting();
    await expect(row.getByRole('button', { name: 'Edit target: /kontakt' })).toBeVisible();

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(row.getByRole('button', { name: 'Edit target: /faq' })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Edit target: /kontakt' })).toHaveCount(0);
    expect((await ruleOf(api, '/faq-alt')).target).toBe('/faq');
  });

  test('inline edit of the status code rolls back, and a 409 (someone else changed the rule) shows the server text', async ({ app, api, page }) => {
    await app.goto('#/rules');
    const gate = await failWhenReleased(page, 'PATCH', /\/redirects\/rules\/[^/]+$/, {
      status: 409,
      body: { title: 'Conflict', status: 409, detail: 'The rule was changed by someone else. Reload the list.' },
    });
    const row = app.row('/faq-alt');
    await row.getByRole('button', { name: /^Edit status code: 301/ }).click();
    await row.getByRole('combobox', { name: 'Status code of /faq-alt' }).selectOption('302');
    await gate.waiting();
    await expect(row.getByRole('button', { name: /^Edit status code: 302/ })).toBeVisible();

    await gate.release();
    await expect(app.toast('The rule was changed by someone else. Reload the list.')).toBeVisible();
    await expect(row.getByRole('button', { name: /^Edit status code: 301/ })).toBeVisible();
    expect((await ruleOf(api, '/faq-alt')).status).toBe(301);
  });

  test('the enabled switch flips first and flips back; the public site never stopped redirecting', async ({ app, api, page, site }) => {
    await app.goto('#/rules');
    const gate = await failWhenReleased(page, 'PATCH', /\/redirects\/rules\/[^/]+$/);
    const row = app.row('/faq-alt');
    const toggle = row.getByRole('switch', { name: 'Enabled: /faq-alt' });
    await expect(toggle).toBeChecked();
    await toggle.click();
    await gate.waiting();
    await expect(toggle).not.toBeChecked();
    await expect(row).toContainText('Disabled');

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(toggle).toBeChecked();
    await expect(row).toContainText('Active');
    expect((await ruleOf(api, '/faq-alt')).enabled).toBe(true);
    expect((await site.get('/faq-alt')).status).toBe(301);
  });

  test('bulk Disable: the selected rows show Disabled first, then everything is Active again', async ({ app, api, page }) => {
    await app.goto('#/rules');
    await selectRows(app, '/d-blog', '/e-info', '/f-faq');
    const gate = await failWhenReleased(page, 'POST', /\/redirects\/rules\/bulk$/);
    await bulkBar(app).getByRole('button', { name: 'Disable' }).click();
    await gate.waiting();
    for (const s of ['/d-blog', '/e-info', '/f-faq']) await expect(app.row(s)).toContainText('Disabled');

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    for (const s of ['/d-blog', '/e-info', '/f-faq']) {
      await expect(app.row(s)).toContainText('Active');
      await expect(app.row(s).getByRole('switch')).toBeChecked();
    }
    expect((await allRules(api)).filter((r) => !r.enabled).map((r) => r.source)).toEqual(['/aktion/winter']);
  });

  test('bulk status change rolls back the codes', async ({ app, api, page }) => {
    await app.goto('#/rules');
    await selectRows(app, '/d-blog', '/e-info');
    const gate = await failWhenReleased(page, 'POST', /\/redirects\/rules\/bulk$/);
    await bulkBar(app).getByRole('button', { name: 'Change status' }).click();
    await app.root.getByRole('menuitem', { name: /^302 · / }).click();
    await gate.waiting();
    await expect(app.row('/d-blog').locator('td.c-code')).toContainText('302');

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(app.row('/d-blog').locator('td.c-code')).toContainText('301');
    await expect(app.row('/e-info').locator('td.c-code')).toContainText('301');
    expect((await ruleOf(api, '/d-blog')).status).toBe(301);
  });

  test('reorder with the arrow keys: the rule moves first, then returns to its place', async ({ app, api, page }) => {
    await app.goto('#/rules');
    const before = await shownSources(app);
    const server = (await allRules(api)).map((r) => r.id);
    const from = before.indexOf('/f-faq');
    const gate = await failWhenReleased(page, 'POST', /\/redirects\/rules\/reorder$/);
    const handle = handleOf(app, '/f-faq');
    await handle.focus();
    await handle.press('ArrowUp');
    await gate.waiting();
    const moved = before.slice();
    moved.splice(from - 1, 0, moved.splice(from, 1)[0]);
    await expect.poll(() => shownSources(app)).toEqual(moved);

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect.poll(() => shownSources(app)).toEqual(before);
    expect((await allRules(api)).map((r) => r.id)).toEqual(server);
  });

  test('delete: the row disappears first, comes back with an error toast and there is no Undo offer', async ({ app, api, page }) => {
    await app.goto('#/rules');
    const gate = await failWhenReleased(page, 'DELETE', /\/redirects\/rules\/[^/]+$/);
    await openRowMenu(app, '/faq-alt');
    await app.root.getByRole('menuitem', { name: 'Delete' }).click();
    await gate.waiting();
    await expect(app.row('/faq-alt')).toHaveCount(0);

    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(app.row('/faq-alt')).toBeVisible();
    await expect(app.toast('Deleted 1 rule')).toHaveCount(0);
    expect((await ruleOf(api, '/faq-alt')).source).toBe('/faq-alt');
  });

  test('suggestions: a failed Reject keeps the row, a failed Accept creates no rule', async ({ app, api, page }) => {
    await app.goto('#/suggestions');
    const rows = app.root.locator('table[aria-label="Redirect suggestions"] tbody tr');
    const row = (path: string) => rows.filter({ has: page.getByRole('link', { name: path, exact: true }) });
    await expect(rows).toHaveCount(7);

    const reject = await failWhenReleased(page, 'POST', /\/redirects\/suggestions\/[^/]+\/reject$/);
    await row('/about-us').getByRole('button', { name: 'Reject the suggestion for /about-us' }).click();
    await reject.waiting();
    await expect(row('/about-us')).toHaveCount(0);
    await reject.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(row('/about-us')).toBeVisible();
    await expect(rows).toHaveCount(7);

    const accept = await failWhenReleased(page, 'POST', /\/redirects\/suggestions\/[^/]+\/accept$/);
    await row('/shop/Zelte').getByRole('button', { name: 'Accept the suggestion for /shop/Zelte' }).click();
    await accept.waiting();
    await accept.release();
    await expect(row('/shop/Zelte')).toBeVisible();
    await expect(rows).toHaveCount(7);
    expect((await api('GET', '/redirects/suggestions')).meta.counts).toEqual({ open: 7, accepted: 0, rejected: 0 });
    expect((await api('GET', '/redirects/rules?q=/shop/Zelte&per_page=50')).data.filter((r: any) => r.source === '/shop/Zelte')).toEqual([]);
  });

  test('404 monitor: a failed "Mark as done" leaves the path in the list', async ({ app, api, page }) => {
    await app.goto('#/404');
    const row = app.root.locator('tbody tr[data-path="/about-us"]');
    await expect(row).toBeVisible();
    const gate = await failWhenReleased(page, 'POST', /\/redirects\/404\/resolve$/);
    await row.getByRole('button', { name: 'Actions for /about-us' }).click();
    await app.root.getByRole('menuitem', { name: 'Mark as done' }).click();
    await gate.waiting();
    await gate.release();
    await expect(app.toast(SERVER_ERROR_TEXT)).toBeVisible();
    await expect(row).toBeVisible();
    await expect(row).not.toContainText('Done');
    const rows = (await api('GET', '/redirects/404?per_page=100')).data as Array<{ path: string; resolved?: unknown }>;
    expect(rows.find((r) => r.path === '/about-us')?.resolved).toBeFalsy();
  });
});
