import { test, expect } from '../support/test';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';
import { ageRules, allRules, shownSources } from '../support/rules-page';

const filtersToggle = (app: App) => app.root.getByRole('button', { name: /^Filters/ });
const filterPanel = (app: App) => app.root.getByRole('group', { name: 'Filters', exact: true });
const filterSelect = (app: App, label: string) => filterPanel(app).getByRole('combobox', { name: label, exact: true });
const quickFilters = (app: App) => app.root.getByRole('group', { name: 'Quick filters by badge' });
const pager = (app: App) => app.root.getByRole('navigation', { name: 'Pagination' });

async function openFilters(app: App): Promise<void> {
  if ((await filtersToggle(app).getAttribute('aria-expanded')) !== 'true') await filtersToggle(app).click();
  await expect(filterPanel(app)).toBeVisible();
}

/** The rows on screen are exactly what the API returns for the same query (default order: priority, high to low). */
async function expectListToMatchApi(app: App, api: ApiCall, query: string): Promise<string[]> {
  const expected = (await allRules(api, query)).map((r) => r.source);
  await expect.poll(() => shownSources(app)).toEqual(expected);
  return expected;
}

test.describe('rules list: search, filters, sorting, paging', () => {
  test('/ focuses the search box; typing filters the list and writes q into the URL', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await expect(app.rows).toHaveCount(32);
    await page.keyboard.press('/');
    await expect(app.searchbox).toBeFocused();
    // the slash itself is not typed into the box
    await expect(app.searchbox).toHaveValue('');

    await page.keyboard.type('zelt');
    const sources = await expectListToMatchApi(app, api, '&q=zelt');
    expect(sources).toEqual(expect.arrayContaining(['/produkt/zelt-alpin-3', '/a-zelte', '/aktion/sommer']));
    expect(sources.length).toBeLessThan(32);
    await expect(page).toHaveURL(/#\/rules\?.*q=zelt/);
    // a search counts as a filter for the empty state, not as an "active filter" of the panel
    await expect(app.root.getByLabel(/active filter/)).toHaveCount(0);
  });

  test('search reaches note, group and tag; Esc in the box clears it', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await app.search('relaunch'); // a tag
    const byTag = await expectListToMatchApi(app, api, '&q=relaunch');
    expect(byTag).toContain('/produkt/zelt-alpin-3');
    expect(byTag.length).toBeLessThan(5);

    await app.search('Kampagnen'); // a group
    const byGroup = await expectListToMatchApi(app, api, '&q=Kampagnen');
    expect(byGroup).toEqual(expect.arrayContaining(['/kontakt-alt', '/aktion/sommer', '/aktion/winter', '/partner']));

    await app.searchbox.press('Escape');
    await expect(app.searchbox).toHaveValue('');
    await expect(app.rows).toHaveCount(32);
    await expect(page).not.toHaveURL(/q=/);
  });

  test('no match shows the empty state; its Clear filters button brings the list back', async ({ app, page }) => {
    await app.goto('#/rules');
    await app.search('gibt-es-garantiert-nicht');
    await expect(app.root.getByRole('heading', { name: 'No rules match' })).toBeVisible();
    await expect(app.rows).toHaveCount(0);
    await app.root.getByRole('button', { name: 'Clear filters' }).click();
    await expect(app.rows).toHaveCount(32);
    await expect(app.searchbox).toHaveValue('');
    await expect(page).not.toHaveURL(/q=/);
  });

  test('match type filter', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await openFilters(app);
    await filterSelect(app, 'Match type').selectOption('wildcard');
    const sources = await expectListToMatchApi(app, api, '&match_type=wildcard');
    expect(sources).toEqual(['/produkte/*', '/altes-blog/*']);
    await expect(page).toHaveURL(/match_type=wildcard/);
    await expect(app.root.getByLabel('1 active filter')).toBeVisible();
    for (const row of await app.rows.all()) await expect(row.getByText('wildcard', { exact: true })).toBeVisible();

    await filterSelect(app, 'Match type').selectOption('regex');
    expect(await expectListToMatchApi(app, api, '&match_type=regex')).toEqual(['^/kategorie/(\\d+)$', '/regex-alt/(.+)']);
  });

  test('status code filter and state filter', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await openFilters(app);
    await filterSelect(app, 'Status code').selectOption('410');
    expect(await expectListToMatchApi(app, api, '&status=410')).toEqual(['/wp-login.php']);
    await expect(page).toHaveURL(/status=410/);

    await filterSelect(app, 'Status code').selectOption('302');
    expect(await expectListToMatchApi(app, api, '&status=302')).toEqual(['/news', '/aktion/sommer', '/aktion/winter', '/konflikt']);

    // combined with the state filter (AND)
    await filterSelect(app, 'State').selectOption('Disabled');
    expect(await expectListToMatchApi(app, api, '&status=302&state=disabled')).toEqual(['/aktion/winter']);
    await expect(app.root.getByLabel('2 active filters')).toBeVisible();

    await filterSelect(app, 'Status code').selectOption('All');
    expect(await expectListToMatchApi(app, api, '&state=disabled')).toEqual(['/aktion/winter']);
    await filterSelect(app, 'State').selectOption('Expired');
    expect(await expectListToMatchApi(app, api, '&state=expired')).toEqual(['/abgelaufen']);
    await filterSelect(app, 'State').selectOption('Scheduled');
    expect(await expectListToMatchApi(app, api, '&state=scheduled')).toEqual(['/bald']);
  });

  test('origin filter', async ({ app, api }) => {
    await app.goto('#/rules');
    await openFilters(app);
    await filterSelect(app, 'Origin').selectOption('Manual');
    // only the two rules created through the API/by hand, everything else was imported
    expect((await expectListToMatchApi(app, api, '&origin=manual')).sort()).toEqual(['/konflikt', '/schleife-b']);
    await filterSelect(app, 'Origin').selectOption('Import');
    const imported = await expectListToMatchApi(app, api, '&origin=import');
    expect(imported).toHaveLength(30);
    expect(imported).not.toContain('/schleife-b');
    await filterSelect(app, 'Origin').selectOption('Automatic');
    await expect(app.root.getByRole('heading', { name: 'No rules match' })).toBeVisible();
  });

  test('group filter', async ({ app, api }) => {
    await app.goto('#/rules');
    await openFilters(app);
    await filterSelect(app, 'Group').selectOption('Ketten');
    const sources = await expectListToMatchApi(app, api, '&group=Ketten');
    expect(sources).toEqual(['/kette-a', '/kette-b', '/kette-c', '/schleife-a', '/schleife-b']);
  });

  test('"No hits since" lists active rules that are old enough and got no hit in that time', async ({ app, api, site }) => {
    // a rule counts as unused when it is older than the period and has no hit inside it; the seed rules are all new
    ageRules(site.dir, ['/a-zelte', '/b-rucksaecke', '/news'], 400);
    await app.goto('#/rules');
    await expect(app.rows).toHaveCount(32);
    await openFilters(app);

    await filterSelect(app, 'No hits since').selectOption('30 days');
    // /news is old as well, but it has hits today
    expect((await expectListToMatchApi(app, api, '&unused_days=30')).sort()).toEqual(['/a-zelte', '/b-rucksaecke']);
    await expect(app.root.getByLabel('1 active filter')).toBeVisible();
    await expect(app.page).toHaveURL(/unused_days=30/);

    await filterSelect(app, 'No hits since').selectOption('365 days');
    expect((await expectListToMatchApi(app, api, '&unused_days=365')).sort()).toEqual(['/a-zelte', '/b-rucksaecke']);
  });

  test('"No hits since" without old rules shows the empty state', async ({ app }) => {
    await app.goto('#/rules?unused_days=90');
    await expect(app.root.getByRole('heading', { name: 'No rules match' })).toBeVisible();
    await expect(filterSelect(app, 'No hits since')).toHaveValue('90');
  });

  test('quick badge filters: Chain, Loop, Conflict; a second click removes the filter', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const chain = quickFilters(app).getByRole('button', { name: /^Chain/ });
    await expect(chain).toContainText('2');
    await expect(chain).toHaveAttribute('aria-pressed', 'false');

    await chain.click();
    await expect(chain).toHaveAttribute('aria-pressed', 'true');
    expect(await expectListToMatchApi(app, api, '&badge=chain')).toEqual(['/kette-a', '/kette-b']);
    await expect(page).toHaveURL(/badge=chain/);
    await expect(app.root.getByLabel('1 active filter')).toBeVisible();
    // the panel shows the same filter
    await openFilters(app);
    await expect(filterSelect(app, 'Badge')).toHaveValue('chain');

    await quickFilters(app).getByRole('button', { name: /^Loop/ }).click();
    expect((await expectListToMatchApi(app, api, '&badge=loop')).sort()).toEqual(['/schleife-a', '/schleife-b']);
    await expect(chain).toHaveAttribute('aria-pressed', 'false');

    await quickFilters(app).getByRole('button', { name: /^Conflict/ }).click();
    expect(await expectListToMatchApi(app, api, '&badge=conflict')).toEqual(['/konflikt', '/konflikt']);

    await quickFilters(app).getByRole('button', { name: /^Conflict/ }).click();
    await expect(app.rows).toHaveCount(32);
    await expect(page).not.toHaveURL(/badge=/);
  });

  test('sort by hits with the column header button; a second click flips the direction', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const header = app.root.getByRole('columnheader', { name: 'Hits' });
    await expect(header).toHaveAttribute('aria-sort', 'none');

    await header.getByRole('button', { name: 'Hits' }).click();
    await expect(header).toHaveAttribute('aria-sort', 'descending');
    await expect(page).toHaveURL(/sort=hits/);
    await expect.poll(async () => (await shownSources(app)).slice(0, 5)).toEqual(['/produkt/zelt-alpin-3', '/news', '/faq-alt', '/produkte/*', '/ueber-uns']);
    const ordered = (await api('GET', '/redirects/rules?per_page=500&sort=hits&dir=desc')).data.map((r: { source: string }) => r.source);
    expect((await shownSources(app)).slice(0, 5)).toEqual(ordered.slice(0, 5));

    await header.getByRole('button', { name: 'Hits' }).click();
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(page).toHaveURL(/dir=asc/);
    // the rules with hits are now at the end, most hits last
    await expect.poll(async () => (await shownSources(app)).slice(-5)).toEqual(['/ueber-uns', '/produkte/*', '/faq-alt', '/news', '/produkt/zelt-alpin-3']);

    // dragging only makes sense in priority order, so the handles say so
    await expect(app.rows.first().getByRole('button', { name: 'Sort by priority (high to low) to reorder rules.' })).toHaveAttribute('aria-disabled', 'true');
  });

  test('sort by source, ascending then descending, follows the API', async ({ app, api }) => {
    await app.goto('#/rules');
    const header = app.root.getByRole('columnheader', { name: 'Source' });
    await header.getByRole('button', { name: 'Source' }).click();
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    const asc = (await api('GET', '/redirects/rules?per_page=500&sort=source&dir=asc')).data.map((r: { source: string }) => r.source);
    await expect.poll(() => shownSources(app)).toEqual(asc);

    await header.getByRole('button', { name: 'Source' }).click();
    await expect(header).toHaveAttribute('aria-sort', 'descending');
    const desc = (await api('GET', '/redirects/rules?per_page=500&sort=source&dir=desc')).data.map((r: { source: string }) => r.source);
    await expect.poll(() => shownSources(app)).toEqual(desc);
  });

  test('pagination: default 50 per page, choose 25, next and previous page, the URL keeps page and size', async ({ app, page, api }) => {
    await app.goto('#/rules');
    const perPage = pager(app).getByRole('combobox', { name: 'Per page' });
    await expect(perPage).toHaveValue('50');
    await expect(pager(app)).toContainText('1–32 of 32');
    await expect(pager(app)).toContainText('Page 1 of 1');
    await expect(pager(app).getByRole('button', { name: 'Next page' })).toBeDisabled();
    await expect(pager(app).getByRole('button', { name: 'Previous page' })).toBeDisabled();

    await perPage.selectOption('25');
    await expect(app.rows).toHaveCount(25);
    await expect(pager(app)).toContainText('1–25 of 32');
    await expect(pager(app)).toContainText('Page 1 of 2');
    await expect(page).toHaveURL(/per_page=25/);

    await pager(app).getByRole('button', { name: 'Next page' }).click();
    await expect(app.rows).toHaveCount(7);
    await expect(pager(app)).toContainText('26–32 of 32');
    await expect(pager(app)).toContainText('Page 2 of 2');
    await expect(pager(app).getByRole('button', { name: 'Next page' })).toBeDisabled();
    await expect(page).toHaveURL(/[?&]page=2/);
    const second = (await api('GET', '/redirects/rules?sort=priority&dir=desc&per_page=25&page=2')).data.map((r: { source: string }) => r.source);
    expect(second).toHaveLength(7);
    await expect.poll(() => shownSources(app)).toEqual(second);
    expect(second[6]).toBe('/schleife-b');

    await pager(app).getByRole('button', { name: 'Previous page' }).click();
    await expect(app.rows).toHaveCount(25);
    await expect(pager(app)).toContainText('1–25 of 32');

    await perPage.selectOption('100');
    await expect(app.rows).toHaveCount(32);
    await expect(pager(app)).toContainText('Page 1 of 1');
  });

  test('a page and size in the URL are restored on load; a new filter goes back to page 1', async ({ app, page }) => {
    await app.goto('#/rules?per_page=25&page=2');
    await expect(app.rows).toHaveCount(7);
    await expect(pager(app).getByRole('combobox', { name: 'Per page' })).toHaveValue('25');
    await expect(pager(app)).toContainText('Page 2 of 2');

    await app.search('kette');
    await expect(app.rows).toHaveCount(5); // matches the sources of the three chain rules, plus two more of the group Ketten
    await expect(page).not.toHaveURL(/[?&]page=2/);
    await expect(page).toHaveURL(/q=kette/);
    await expect(page).toHaveURL(/per_page=25/);
  });

  test('filters in the URL open the panel with the selected values; unknown values are ignored', async ({ app, api }) => {
    await app.goto('#/rules?match_type=regex&state=active');
    await expect(filterPanel(app)).toBeVisible();
    await expect(filterSelect(app, 'Match type')).toHaveValue('regex');
    await expect(filterSelect(app, 'State')).toHaveValue('active');
    await expect(app.root.getByLabel('2 active filters')).toBeVisible();
    await expectListToMatchApi(app, api, '&match_type=regex&state=active');

    await app.goto('#/rules?match_type=bogus&per_page=abc&sort=nonsense');
    await expect(app.rows).toHaveCount(32);
    await expect(app.root.getByLabel(/active filter/)).toHaveCount(0);
  });

  test('Clear filters resets all filters and the search, and the URL', async ({ app, page }) => {
    await app.goto('#/rules');
    await openFilters(app);
    await filterSelect(app, 'Status code').selectOption('301');
    await filterSelect(app, 'Group').selectOption('Shop');
    await app.search('zelt');
    await expect(app.root.getByLabel('2 active filters')).toBeVisible();
    await expect.poll(() => shownSources(app)).toEqual(['/produkt/zelt-alpin-3', '/a-zelte']);
    await expect(page).toHaveURL(/status=301/);

    await filterPanel(app).getByRole('button', { name: 'Clear filters' }).click();
    await expect(app.rows).toHaveCount(32);
    await expect(filterSelect(app, 'Status code')).toHaveValue('');
    await expect(filterSelect(app, 'Group')).toHaveValue('');
    await expect(app.searchbox).toHaveValue('');
    await expect(app.root.getByLabel(/active filter/)).toHaveCount(0);
    await expect(filterPanel(app).getByRole('button', { name: 'Clear filters' })).toHaveCount(0);
    await expect(page).not.toHaveURL(/status=|group=|q=/);
  });
});
