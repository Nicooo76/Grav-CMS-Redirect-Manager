import { test, expect } from '../support/test';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';

const result = (app: App) => app.root.getByRole('region', { name: 'Test result' });
const headline = (app: App) => result(app).locator('.headline');
const chain = (app: App) => result(app).locator('ol.chain > li');
const traceRows = (app: App) => result(app).getByRole('region', { name: 'Evaluation order' }).locator('tbody tr');
const finalUrl = (app: App) => result(app).locator('.final code');
const urlField = (app: App) => app.root.getByRole('textbox', { name: 'URL or path' });

/** type a URL and press Test */
async function runTest(app: App, url: string): Promise<void> {
  await urlField(app).fill(url);
  await app.root.getByRole('button', { name: 'Test' }).click();
  await expect(result(app)).toBeVisible();
}

async function ruleId(api: ApiCall, source: string): Promise<string> {
  const rules = (await api('GET', `/redirects/rules?q=${encodeURIComponent(source)}&per_page=50`)).data as any[];
  const rule = rules.find((r) => r.source === source);
  expect(rule, `seeded rule ${source}`).toBeTruthy();
  return rule.id;
}

test.describe('tester', () => {
  test('empty state offers examples, an empty submit asks for a URL', async ({ app }) => {
    await app.goto('#/tester');
    await expect(app.root.getByText('See what happens to a URL')).toBeVisible();
    await expect(app.root.getByRole('link', { name: '/old-page', exact: true })).toBeVisible();
    await app.root.getByRole('button', { name: 'Test' }).click();
    await expect(app.root.getByText('Enter a URL or path to test.')).toBeVisible();
    await expect(result(app)).toHaveCount(0);
    await expect(urlField(app)).toBeFocused();

    await app.root.getByRole('link', { name: '/old-page', exact: true }).click();
    await expect(urlField(app)).toHaveValue('/old-page');
    await expect(headline(app)).toHaveText('Not found (404)');
  });

  test('chain of three hops: headline, chain list, final status, matched rule, trace table', async ({ app, api }) => {
    await app.goto('#/tester');
    await runTest(app, '/kette-a');

    await expect(headline(app)).toHaveText('Redirects, 301');
    await expect(result(app).locator('.card-h')).toContainText('301');
    await expect(finalUrl(app)).toHaveText('/faq');
    await expect(result(app)).toContainText('Visitors take 3 hops to get there.');
    await expect(app.page).toHaveURL(/#\/tester\?url=%2Fkette-a$/);

    // the chain: three redirecting hops and the final page
    await expect(chain(app)).toHaveCount(4);
    const ids = [await ruleId(api, '/kette-a'), await ruleId(api, '/kette-b'), await ruleId(api, '/kette-c')];
    for (const [i, [url, id]] of [['/kette-a', ids[0]], ['/kette-b', ids[1]], ['/kette-c', ids[2]]].entries()) {
      await expect(chain(app).nth(i)).toContainText(url!);
      await expect(chain(app).nth(i)).toContainText('301');
      await expect(chain(app).nth(i).getByRole('link', { name: id })).toHaveAttribute('href', `#/rules/${id}`);
    }
    await expect(chain(app).nth(3)).toContainText('/faq');
    await expect(chain(app).nth(3)).toContainText('200');
    await expect(chain(app).nth(3)).toContainText('Final');

    // matched rule = the first hop
    const rule = result(app).getByRole('region', { name: 'Matched rule' });
    await expect(rule).toContainText('/kette-a');
    await expect(rule).toContainText('/kette-b');
    await expect(rule).toContainText('exact');
    await expect(rule.getByRole('link', { name: 'Edit rule' })).toHaveAttribute('href', `#/rules/${ids[0]}`);

    // trace: the winning row is marked
    await expect(traceRows(app).first()).toContainText('/kette-a');
    await expect(traceRows(app).first()).toContainText('Yes');
    await expect(traceRows(app).first()).toContainText('The rule matches. It wins.');
    await expect(result(app).getByRole('table', { name: /^Evaluation of \d+ rules? in the order they were checked$/ })).toBeVisible();
  });

  test('single hop: /produkt/zelt-alpin-3 redirects once, no chain hint', async ({ app, api }) => {
    await app.goto('#/tester');
    await runTest(app, '/produkt/zelt-alpin-3');
    await expect(headline(app)).toHaveText('Redirects, 301');
    await expect(finalUrl(app)).toHaveText('/shop/zelte');
    await expect(chain(app)).toHaveCount(2);
    await expect(chain(app).first()).toContainText('/produkt/zelt-alpin-3');
    await expect(chain(app).last()).toContainText('/shop/zelte');
    await expect(chain(app).last()).toContainText('200');
    await expect(result(app)).not.toContainText('Visitors take');
    const id = await ruleId(api, '/produkt/zelt-alpin-3');
    await expect(result(app).getByRole('region', { name: 'Matched rule' }).getByRole('link', { name: 'Edit rule' })).toHaveAttribute('href', `#/rules/${id}`);
  });

  test('redirect loop is detected and explained', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, '/schleife-a');
    await expect(headline(app)).toHaveText('Redirect loop');
    await expect(result(app)).toContainText('The chain leads back to a URL it already visited.');
    await expect(chain(app)).toHaveCount(3);
    await expect(chain(app).nth(0)).toContainText('/schleife-a');
    await expect(chain(app).nth(1)).toContainText('/schleife-b');
    await expect(chain(app).nth(2)).toContainText('/schleife-a');
    await expect(chain(app).nth(2)).toContainText('Loop');
    await expect(chain(app).nth(2)).toContainText('Visited before');
    await expect(result(app).locator('.final')).toHaveCount(0);
  });

  test('gone: /wp-login.php answers 410', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, '/wp-login.php');
    await expect(headline(app)).toHaveText('Gone (410)');
    await expect(result(app).locator('.card-h')).toContainText('410');
    await expect(result(app)).toContainText('The rule tells browsers and search engines that this page is gone for good.');
    await expect(result(app).getByRole('region', { name: 'Matched rule' })).toContainText('/wp-login.php');
    await expect(result(app).getByRole('region', { name: 'Matched rule' })).toContainText('410');
  });

  test('external target: the tester flags it as not requested', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, '/partner');
    await expect(headline(app)).toHaveText('Redirects, 301');
    await expect(finalUrl(app)).toHaveText('https://example.org/partner');
    await expect(result(app)).toContainText('The target is on another site. The tester does not request it, so its answer is unknown.');
    await expect(chain(app)).toHaveCount(2);
    await expect(chain(app).last()).toContainText('https://example.org/partner');
    await expect(chain(app).last()).toContainText('External, not requested');
  });

  test('a page that exists: no redirect', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, '/shop');
    await expect(headline(app)).toHaveText('No redirect: page found (200)');
    await expect(result(app)).toContainText('A page exists here, no rule applies.');
    await expect(result(app).getByRole('region', { name: 'Matched rule' })).toHaveCount(0);
    await expect(result(app).getByRole('button', { name: 'Create a rule for this URL' })).toHaveCount(0);
  });

  test('not found: shows why no rule matched, "Create a rule for this URL" opens the editor with the source', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, '/gibt-es-nicht');
    await expect(headline(app)).toHaveText('Not found (404)');
    await expect(result(app).locator('.card-h')).toContainText('404');
    await expect(result(app)).toContainText('No rule matches and no page exists here. Visitors see the 404 page.');
    await expect(chain(app)).toHaveCount(1);
    await expect(chain(app).first()).toContainText('404');
    // the trace lists the candidates and why they did not match
    await expect(traceRows(app).first()).toContainText('No');
    await expect(traceRows(app).first()).toContainText('The path does not match the source.');

    await result(app).getByRole('button', { name: 'Create a rule for this URL' }).click();
    await expect(app.editor).toBeVisible();
    await expect(app.field('Source')).toHaveValue('/gibt-es-nicht');
  });

  test('a full URL with a query string is reduced to the path for the new rule', async ({ app }) => {
    await app.goto('#/tester');
    await runTest(app, 'https://example.org/gibt-es-nicht?x=1');
    await expect(headline(app)).toHaveText('Not found (404)');
    await result(app).getByRole('button', { name: 'Create a rule for this URL' }).click();
    await expect(app.field('Source')).toHaveValue('/gibt-es-nicht?x=1');
  });

  test('deep link #/tester?url=/kette-a runs the test by itself', async ({ app }) => {
    await app.goto('#/tester?url=%2Fkette-a');
    await expect(urlField(app)).toHaveValue('/kette-a');
    await expect(headline(app)).toHaveText('Redirects, 301');
    await expect(finalUrl(app)).toHaveText('/faq');
    await expect(chain(app)).toHaveCount(4);

    // a second deep link in the same page load runs again
    await app.page.evaluate(() => (location.hash = '#/tester?url=%2Fschleife-a'));
    await expect(urlField(app)).toHaveValue('/schleife-a');
    await expect(headline(app)).toHaveText('Redirect loop');
  });

  test('the request sends the URL and, from "Advanced", language, user agent, headers and cookies', async ({ app }) => {
    await app.goto('#/tester');
    await urlField(app).fill('/kette-a');

    await app.root.getByText('Advanced', { exact: true }).click();
    await app.root.getByRole('textbox', { name: 'Language' }).fill('de');
    await app.root.getByRole('textbox', { name: 'User agent' }).fill('E2E-Agent/1.0');
    await app.root.getByRole('button', { name: 'Add header' }).click();
    await app.root.getByRole('textbox', { name: 'Header name 1' }).fill('Referer');
    await app.root.getByRole('textbox', { name: 'Header value 1' }).fill('https://example.org/ausgang');
    await app.root.getByRole('button', { name: 'Add cookie' }).click();
    await app.root.getByRole('textbox', { name: 'Cookie name 1' }).fill('lang');
    await app.root.getByRole('textbox', { name: 'Cookie value 1' }).fill('de');

    const request = app.page.waitForRequest((r) => r.url().endsWith('/redirects/test') && r.method() === 'POST');
    await app.root.getByRole('button', { name: 'Test' }).click();
    const body = (await request).postDataJSON();
    expect(body).toEqual({
      url: '/kette-a',
      language: 'de',
      headers: { Referer: 'https://example.org/ausgang', 'User-Agent': 'E2E-Agent/1.0' },
      cookies: { lang: 'de' },
    });
    await expect(headline(app)).toHaveText('Redirects, 301');

    // collapsed, the summary counts the settings in use
    await app.root.getByText('Advanced', { exact: true }).click();
    await expect(app.root.getByLabel('4 advanced settings in use')).toBeVisible();
  });

  test('the language really changes the outcome for a rule limited to a language', async ({ app, api }) => {
    const created = await api('POST', '/redirects/rules', { source: '/e2e-nur-de', target: '/about', match_type: 'exact', conditions: { languages: ['de'] } });
    expect(created.status).toBe(201);

    await app.goto('#/tester');
    await runTest(app, '/e2e-nur-de');
    await expect(headline(app)).toHaveText('Not found (404)');
    const mine = traceRows(app).filter({ has: app.page.getByRole('link', { name: '/e2e-nur-de', exact: true }) });
    await expect(mine).toHaveCount(1);
    await expect(mine).toContainText('The language is not one of the rule’s languages.');

    await app.root.getByText('Advanced', { exact: true }).click();
    await app.root.getByRole('textbox', { name: 'Language' }).fill('de');
    await app.root.getByRole('button', { name: 'Test' }).click();
    await expect(headline(app)).toHaveText('Redirects, 301');
    await expect(finalUrl(app)).toHaveText('/about');
  });
});
