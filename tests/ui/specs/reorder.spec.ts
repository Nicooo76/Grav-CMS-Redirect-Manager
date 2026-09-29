import { test, expect } from '../support/test';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';
import { allRules, handleOf, openRowMenu, shownSources, type ApiRule } from '../support/rules-page';

/** `list` with the item at `from` moved to `to`, like the list itself does it. */
function moved<T>(list: T[], from: number, to: number): T[] {
  const out = list.slice();
  const [item] = out.splice(from, 1);
  out.splice(to, 0, item);
  return out;
}

/** The API order (priority, high to low) is the expected new order, and the priority values stay in their slots. */
async function expectOrder(api: ApiCall, before: ApiRule[], expectedIds: string[]): Promise<ApiRule[]> {
  await expect.poll(async () => (await allRules(api)).map((r) => r.id)).toEqual(expectedIds);
  const after = await allRules(api);
  // the same set of priorities, in the same descending slots: the rules swapped values, nothing else changed
  expect(after.map((r) => r.priority)).toEqual(before.map((r) => r.priority));
  return after;
}

function countReorderRequests(app: App): () => number {
  let n = 0;
  app.page.on('request', (r) => {
    if (r.method() === 'POST' && r.url().includes('/redirects/rules/reorder')) n++;
  });
  return () => n;
}

test.describe('reordering rules', () => {
  test('arrow keys on the handle move the rule one place; focus stays on the handle', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const ids = before.map((r) => r.id);
    const from = before.findIndex((r) => r.source === '/f-faq');
    expect(from).toBeGreaterThan(20);

    const handle = handleOf(app, '/f-faq');
    await handle.focus();
    await handle.press('ArrowUp');
    await expect(app.root.getByText(`Moved to position ${from}`)).toBeAttached();
    await expectOrder(api, before, moved(ids, from, from - 1));
    await expect.poll(() => shownSources(app)).toEqual(moved(before.map((r) => r.source), from, from - 1));
    await expect(handle).toBeFocused();

    await handle.press('ArrowUp');
    await expectOrder(api, before, moved(ids, from, from - 2));
    await expect(handle).toBeFocused();

    // and back down, one place at a time
    await handle.press('ArrowDown');
    await expectOrder(api, before, moved(ids, from, from - 1));
    await handle.press('ArrowDown');
    await expectOrder(api, before, ids);
    await expect(handle).toBeFocused();
  });

  test('the first row cannot go up and the last cannot go down (no request is sent)', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const requests = countReorderRequests(app);

    const top = handleOf(app, before[0].source);
    await top.focus();
    await top.press('ArrowUp');
    const last = handleOf(app, before[31].source);
    await last.focus();
    await last.press('ArrowDown');

    // a real move afterwards proves the earlier key presses were handled and sent nothing
    await top.focus();
    await top.press('ArrowDown');
    await expectOrder(api, before, moved(before.map((r) => r.id), 0, 1));
    expect(requests()).toBe(1);
  });

  test('row menu: Move down and Move up; the ends are disabled', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const ids = before.map((r) => r.id);

    await openRowMenu(app, '/produkt/zelt-alpin-3');
    await expect(app.root.getByRole('menuitem', { name: 'Move up' })).toBeDisabled();
    await expect(app.root.getByRole('menuitem', { name: 'Move down' })).toBeEnabled();
    await app.root.getByRole('menuitem', { name: 'Move down' }).click();
    await expectOrder(api, before, moved(ids, 0, 1));
    await expect.poll(async () => (await shownSources(app)).slice(0, 2)).toEqual(['/produkt/rucksack-trail', '/produkt/zelt-alpin-3']);

    await openRowMenu(app, '/d-blog');
    await app.root.getByRole('menuitem', { name: 'Move up' }).click();
    const d = before.findIndex((r) => r.source === '/d-blog');
    await expectOrder(api, before, moved(moved(ids, 0, 1), d, d - 1));

    const last = before[31].source;
    await openRowMenu(app, last);
    await expect(app.root.getByRole('menuitem', { name: 'Move down' })).toBeDisabled();
    await expect(app.root.getByRole('menuitem', { name: 'Move up' })).toBeEnabled();
    await app.page.keyboard.press('Escape');
  });

  test('drag and drop: drag a row up, then another down', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const ids = before.map((r) => r.id);
    expect(before[6].source).toBe('/faq-alt');

    // up: /faq-alt (7th row) onto the place of the 3rd row
    const handle = handleOf(app, '/faq-alt');
    const start = (await handle.boundingBox())!;
    const target = (await app.rows.nth(2).boundingBox())!;
    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(start.x + start.width / 2, start.y - 30, { steps: 4 });
    await page.mouse.move(start.x + start.width / 2, target.y + target.height / 2, { steps: 10 });
    await expect(app.rows.nth(2)).toHaveClass(/drop-before/);
    await page.mouse.up();
    await expectOrder(api, before, moved(ids, 6, 2));
    await expect.poll(async () => (await shownSources(app)).slice(0, 8)).toEqual(moved(before.map((r) => r.source), 6, 2).slice(0, 8));

    // down: the 2nd row onto the place of the 5th
    const current = await allRules(api);
    const handle2 = handleOf(app, current[1].source);
    const s2 = (await handle2.boundingBox())!;
    const t2 = (await app.rows.nth(4).boundingBox())!;
    await page.mouse.move(s2.x + s2.width / 2, s2.y + s2.height / 2);
    await page.mouse.down();
    await page.mouse.move(s2.x + s2.width / 2, s2.y + 30, { steps: 4 });
    await page.mouse.move(s2.x + s2.width / 2, t2.y + t2.height / 2, { steps: 10 });
    await expect(app.rows.nth(4)).toHaveClass(/drop-after/);
    await page.mouse.up();
    await expectOrder(api, before, moved(moved(ids, 6, 2), 1, 4));
  });

  test('a click on the handle without dragging changes nothing', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const requests = countReorderRequests(app);
    const box = (await handleOf(app, '/faq-alt').boundingBox())!;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2 + 2);
    await page.mouse.up();
    // then a real move, so we know the click was over
    await handleOf(app, '/faq-alt').press('ArrowUp');
    await expectOrder(api, before, moved(before.map((r) => r.id), 6, 5));
    expect(requests()).toBe(1);
  });

  test('the new order is what the list shows after a reload', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    await handleOf(app, '/e-info').press('ArrowUp');
    await handleOf(app, '/e-info').press('ArrowUp');
    const from = before.findIndex((r) => r.source === '/e-info');
    await expectOrder(api, before, moved(before.map((r) => r.id), from, from - 2));
    await app.reload();
    await expect.poll(() => shownSources(app)).toEqual(moved(before.map((r) => r.source), from, from - 2));
  });

  test('moving one of two rules with the same source decides which one the site uses', async ({ app, api, site, request }) => {
    const first = await request.get(`${site.baseUrl}/konflikt`, { maxRedirects: 0 });
    expect(first.status()).toBe(301);
    expect(first.headers()['location']).toBe('/shop');

    await app.goto('#/rules');
    const before = await allRules(api);
    const conflicts = before.map((r, i) => ({ r, i })).filter((x) => x.r.source === '/konflikt');
    expect(conflicts.map((x) => x.r.target)).toEqual(['/shop', '/blog']);
    const [, second] = conflicts;

    await app.rows.nth(second.i).getByRole('button', { name: /^Move \/konflikt/ }).press('ArrowUp');
    await expectOrder(api, before, moved(before.map((r) => r.id), second.i, second.i - 1));
    await expect
      .poll(async () => {
        const res = await request.get(`${site.baseUrl}/konflikt`, { maxRedirects: 0 });
        return `${res.status()} ${res.headers()['location']}`;
      })
      .toBe('302 /blog');
  });

  test('with a filter only the rules on screen change places; all other priorities stay', async ({ app, api }) => {
    await app.goto('#/rules?group=Shop');
    const before = await allRules(api);
    const shop = before.filter((r) => r.group === 'Shop');
    expect(shop.map((r) => r.source)).toEqual(['/produkt/zelt-alpin-3', '/produkt/rucksack-trail', '/produkte/*', '^/kategorie/(\\d+)$', '/a-zelte', '/b-rucksaecke', '/c-outlet']);
    await expect.poll(() => shownSources(app)).toEqual(shop.map((r) => r.source));

    await handleOf(app, '/c-outlet').press('ArrowUp');
    await expect
      .poll(async () => (await allRules(api)).filter((r) => r.group === 'Shop').map((r) => r.source))
      .toEqual(['/produkt/zelt-alpin-3', '/produkt/rucksack-trail', '/produkte/*', '^/kategorie/(\\d+)$', '/a-zelte', '/c-outlet', '/b-rucksaecke']);
    const after = await allRules(api);
    const priority = (list: ApiRule[], source: string) => list.find((r) => r.source === source)!.priority;
    // the two swapped values, every other rule kept its own (by id: /konflikt exists twice)
    expect(priority(after, '/c-outlet')).toBe(priority(before, '/b-rucksaecke'));
    expect(priority(after, '/b-rucksaecke')).toBe(priority(before, '/c-outlet'));
    const swapped = new Set(['/c-outlet', '/b-rucksaecke']);
    for (const r of before.filter((x) => !swapped.has(x.source))) expect(after.find((x) => x.id === r.id)!.priority).toBe(r.priority);
  });

  test('not in priority order: handles are disabled, arrow keys and the menu offer no move', async ({ app, api }) => {
    await app.goto('#/rules?sort=source&dir=asc');
    const before = await allRules(api);
    const requests = countReorderRequests(app);
    const first = app.rows.first();
    const handle = first.getByRole('button', { name: 'Sort by priority (high to low) to reorder rules.' });
    await expect(handle).toHaveAttribute('aria-disabled', 'true');
    const order = await shownSources(app);
    await handle.focus();
    await handle.press('ArrowDown');
    await first.getByRole('button', { name: /^Actions for/ }).click();
    await expect(app.root.getByRole('menuitem', { name: 'Delete' })).toBeVisible();
    await expect(app.root.getByRole('menuitem', { name: /^Move (up|down)$/ })).toHaveCount(0);
    await app.page.keyboard.press('Escape');
    expect(await shownSources(app)).toEqual(order);
    expect(requests()).toBe(0);
    expect((await allRules(api)).map((r) => r.id)).toEqual(before.map((r) => r.id));

    // ascending priority is not the ranking order either
    await app.goto('#/rules?sort=priority&dir=asc');
    await expect(app.rows.first().getByRole('button', { name: 'Sort by priority (high to low) to reorder rules.' })).toHaveAttribute('aria-disabled', 'true');
  });
});
