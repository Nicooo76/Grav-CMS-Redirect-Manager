import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '../support/test';
import { uiDir } from '../support/env';
import type { App } from '../support/app';
import type { ApiCall } from '../support/api';
import type { Download } from '@playwright/test';

const fixture = (name: string) => join(uiDir, 'fixtures', name);

const importPanel = (app: App) => app.root.getByRole('region', { name: 'Import redirects' });
const previewPanel = (app: App) => app.root.getByRole('region', { name: 'Preview' });
const sitemapPanel = (app: App) => app.root.getByRole('region', { name: 'Compare with an old sitemap' });
const previewRows = (app: App) => previewPanel(app).getByRole('table', { name: 'Rows found in the file, with problems marked' }).locator('tbody tr');
const tile = (app: App, label: string) => previewPanel(app).locator('dl > div').filter({ has: app.page.getByText(label, { exact: true }) }).locator('dd');

/** the seeded rules number 32 */
const SEED_RULES = 32;

async function ruleCount(api: ApiCall): Promise<number> {
  return (await api('GET', '/redirects/rules?per_page=1')).meta.total;
}
async function importedRules(api: ApiCall): Promise<any[]> {
  return (await api('GET', '/redirects/rules?origin=import&per_page=500')).data as any[];
}
const bySource = (rules: any[], source: string) => rules.filter((r) => r.source === source);

async function upload(app: App, file: string): Promise<void> {
  await importPanel(app).locator('input[type=file]').setInputFiles(file);
}

async function readDownload(dl: Download): Promise<string> {
  return readFileSync(await dl.path(), 'utf8');
}

test.describe('import', () => {
  test('CSV with invalid rows: format detected, preview tiles, error and warning rows are marked with messages', async ({ app, api }) => {
    await app.goto('#/import');
    await upload(app, fixture('import-mixed.csv'));

    await expect(importPanel(app)).toContainText('import-mixed.csv');
    await expect(importPanel(app).getByRole('combobox', { name: 'Format' })).toHaveValue('');
    await expect(importPanel(app).locator('option:checked').first()).toHaveText('Detected: CSV');
    // the column mapping guessed the header row
    await expect(importPanel(app).getByRole('checkbox', { name: 'First row is a header' })).toBeChecked();
    await expect(importPanel(app).getByRole('combobox', { name: 'Use of column 2' })).toHaveValue('target');

    await expect(previewPanel(app)).toBeVisible();
    await expect(tile(app, 'Rows')).toHaveText('9');
    await expect(tile(app, 'Valid')).toHaveText('6');
    await expect(tile(app, 'Errors')).toHaveText('3');
    await expect(tile(app, 'Duplicates')).toHaveText('2');
    await expect(tile(app, 'Warnings')).toHaveText('2');
    await expect(app.root.getByRole('status').filter({ hasText: 'Preview ready: 9 rows, 3 with errors.' })).toHaveCount(1);

    await expect(previewRows(app)).toHaveCount(9);
    const errors = previewRows(app).filter({ hasText: 'Error:' });
    await expect(errors).toHaveCount(3);
    await expect(previewRows(app).locator('xpath=self::tr[contains(@class,"row-error")]')).toHaveCount(3);
    await expect(errors.filter({ hasText: '/e2e-ohne-ziel' })).toContainText('The target is empty.');
    await expect(errors.filter({ hasText: '/e2e-shop/(' })).toContainText('The regular expression is not valid.');
    await expect(errors.filter({ hasText: '/e2e-recht' })).toContainText('The status code "999" is not known or not supported.');
    // the lines refer to the file (the header is line 1)
    await expect(errors.filter({ hasText: '/e2e-ohne-ziel' }).locator('td').first()).toHaveText('5');

    const dupInFile = previewRows(app).filter({ hasText: 'Duplicate in file' });
    await expect(dupInFile).toHaveCount(1);
    await expect(dupInFile).toContainText('/e2e-alt-eins');
    await expect(dupInFile).toContainText('Warning:');
    const dupOfRule = previewRows(app).filter({ hasText: 'Duplicate of' });
    await expect(dupOfRule).toHaveCount(1);
    await expect(dupOfRule).toContainText('An identical rule already exists.');
    const existing = (await api('GET', '/redirects/rules?q=/produkt/zelt-alpin-3')).data.find((r: any) => r.source === '/produkt/zelt-alpin-3');
    await expect(dupOfRule.getByRole('link', { name: existing.id })).toHaveAttribute('href', `#/rules/${existing.id}`);

    // the row filter
    const filter = previewPanel(app).getByRole('radiogroup', { name: 'Show rows' });
    await filter.getByRole('radio', { name: /^Errors/ }).click();
    await expect(previewRows(app)).toHaveCount(3);
    await filter.getByRole('radio', { name: /^Warnings/ }).click();
    await expect(previewRows(app)).toHaveCount(2);
    await filter.getByRole('radio', { name: /^Valid/ }).click();
    await expect(previewRows(app)).toHaveCount(6);
    await filter.getByRole('radio', { name: /^All/ }).click();
    await expect(previewRows(app)).toHaveCount(9);

    // a preview creates nothing
    expect(await ruleCount(api)).toBe(SEED_RULES);
  });

  test('"Skip invalid rows" -> "Import 4 rules": done state with counts, rules have origin import', async ({ app, api, site, request }) => {
    await app.goto('#/import');
    await upload(app, fixture('import-mixed.csv'));
    await expect(tile(app, 'Rows')).toHaveText('9');

    await previewPanel(app).getByRole('checkbox', { name: 'Skip invalid rows' }).check();
    await previewPanel(app).getByRole('checkbox', { name: 'Skip duplicates' }).check();
    const commit = previewPanel(app).getByRole('button', { name: /^Import \d+ rules?$/ });
    await expect(commit).toHaveText('Import 4 rules');
    await commit.click();

    const done = app.root.getByRole('status').filter({ hasText: '4 rules imported' });
    await expect(done).toBeVisible();
    await expect(done).toContainText('5 rows were skipped.');
    await expect(app.toast('4 rules imported')).toBeVisible();
    await expect(previewPanel(app)).toHaveCount(0);

    const rules = await importedRules(api);
    const mine = rules.filter((r) => r.group === 'E2E Import');
    expect(mine.map((r) => [r.source, r.target, r.status, r.match_type, r.origin]).sort()).toEqual(
      [
        ['/e2e-alt-eins', '/about', 301, 'exact', 'import'],
        ['/e2e-alt-zwei', '/info', 302, 'exact', 'import'],
        ['/e2e-blog/2019/*', '/blog/$1', 301, 'wildcard', 'import'],
        ['/e2e-kontakt', '/kontakt', 307, 'exact', 'import'],
      ].sort(),
    );
    // the first of the two duplicates wins, the invalid rows and the existing rule were left out
    expect(bySource(rules, '/e2e-alt-eins')).toHaveLength(1);
    for (const source of ['/e2e-ohne-ziel', '/e2e-shop/(\\d+', '/e2e-recht']) expect(bySource(rules, source)).toHaveLength(0);
    expect(bySource((await api('GET', '/redirects/rules?q=/produkt/zelt-alpin-3')).data, '/produkt/zelt-alpin-3')).toHaveLength(1);
    expect(await ruleCount(api)).toBe(SEED_RULES + 4);
    expect(mine.find((r) => r.source === '/e2e-alt-eins')?.note).toBe('gueltig');

    const res = await request.get(`${site.baseUrl}/e2e-alt-zwei`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe('/info');
    const wild = await request.get(`${site.baseUrl}/e2e-blog/2019/hallo`, { maxRedirects: 0 });
    expect(wild.status()).toBe(301);
    expect(wild.headers()['location']).toBe('/blog/hallo');

    // "View rules" leads to the imported rules
    await app.root.getByRole('link', { name: 'View rules' }).click();
    await expect(app.page).toHaveURL(/#\/rules\?origin=import/);
    await expect(app.row('/e2e-alt-zwei')).toBeVisible();
  });

  test('without "Skip invalid rows" nothing can be imported, the hint says why', async ({ app, api }) => {
    await app.goto('#/import');
    await upload(app, fixture('import-mixed.csv'));
    await expect(tile(app, 'Errors')).toHaveText('3');

    await previewPanel(app).getByRole('checkbox', { name: 'Skip invalid rows' }).uncheck();
    await expect(previewPanel(app).getByRole('button', { name: /^Import/ })).toBeDisabled();
    await expect(previewPanel(app)).toContainText('Turn on “Skip invalid rows” or fix the file first.');
    expect(await ruleCount(api)).toBe(SEED_RULES);

    // "Import another file" / "Remove file" start over
    await importPanel(app).getByRole('button', { name: 'Remove file' }).click();
    await expect(previewPanel(app)).toHaveCount(0);
    await expect(importPanel(app).getByRole('button', { name: /Drop a file here/ })).toBeVisible();
  });

  test('.htaccess upload is detected and previewed, the import creates 301/302 rules', async ({ app, api }) => {
    await app.goto('#/import');
    await upload(app, fixture('import-old-site.htaccess'));
    await expect(importPanel(app).locator('option:checked').first()).toHaveText('Detected: Apache .htaccess');
    await expect(tile(app, 'Rows')).toHaveText('3');
    await expect(tile(app, 'Valid')).toHaveText('3');
    await expect(tile(app, 'Errors')).toHaveText('0');
    await expect(previewRows(app)).toHaveCount(3);
    await expect(previewRows(app).filter({ hasText: '/e2e-team' })).toContainText('/about');
    await expect(previewRows(app).filter({ hasText: '/e2e-jobs' })).toContainText('302');

    await previewPanel(app).getByRole('button', { name: 'Import 3 rules' }).click();
    await expect(app.root.getByRole('status').filter({ hasText: '3 rules imported' })).toBeVisible();
    const rules = await importedRules(api);
    expect(rules.filter((r) => r.source.startsWith('/e2e-')).map((r) => [r.source, r.target, r.status]).sort()).toEqual([
      ['/e2e-jobs', '/info', 302],
      ['/e2e-presse', '/kontakt', 301],
      ['/e2e-team', '/about', 301],
    ]);
  });

  test('a file named .htaccess (in memory) is detected as well', async ({ app }) => {
    await app.goto('#/import');
    await importPanel(app)
      .locator('input[type=file]')
      .setInputFiles({ name: '.htaccess', mimeType: 'text/plain', buffer: readFileSync(fixture('import-old-site.htaccess')) });
    await expect(importPanel(app)).toContainText('.htaccess');
    await expect(importPanel(app).locator('option:checked').first()).toHaveText('Detected: Apache .htaccess');
    await expect(previewRows(app)).toHaveCount(3);
  });

  test('"Paste text instead": pasted lines are detected, previewed and imported', async ({ app, api }) => {
    await app.goto('#/import');
    await importPanel(app).getByText('Paste text instead').click();
    await importPanel(app).getByLabel('Redirect list').fill('Redirect 301 /e2e-paste-1 /about\nRedirect 302 /e2e-paste-2 /info\n');
    await importPanel(app).getByRole('button', { name: 'Use this text' }).click();

    await expect(importPanel(app)).toContainText('Pasted text');
    await expect(importPanel(app).locator('option:checked').first()).toHaveText('Detected: Apache .htaccess');
    await expect(previewRows(app)).toHaveCount(2);
    await expect(previewRows(app).first()).toContainText('/e2e-paste-1');
    await previewPanel(app).getByRole('button', { name: 'Import 2 rules' }).click();
    await expect(app.root.getByRole('status').filter({ hasText: '2 rules imported' })).toBeVisible();
    const rules = await importedRules(api);
    expect(bySource(rules, '/e2e-paste-2')[0]).toMatchObject({ target: '/info', status: 302, origin: 'import' });
    expect(await ruleCount(api)).toBe(SEED_RULES + 2);
  });

  test('sitemap comparison lists the missing URLs and creates suggestions for them', async ({ app, api }) => {
    const before = (await api('GET', '/redirects/suggestions')).meta.counts;
    expect(before.open).toBe(7);

    await app.goto('#/import');
    await sitemapPanel(app).locator('input[type=file]').setInputFiles(fixture('sitemap-old.xml'));
    await expect(sitemapPanel(app)).toContainText('sitemap-old.xml');
    await sitemapPanel(app).getByRole('button', { name: 'Compare' }).click();

    const tiles = sitemapPanel(app).locator('dl > div');
    const value = (label: string) => tiles.filter({ has: app.page.getByText(label, { exact: true }) }).locator('dd');
    await expect(value('In old sitemap')).toHaveText('7');
    await expect(value('Still exist')).toHaveText('3');
    await expect(value('Already redirected')).toHaveText('1');
    await expect(value('Missing')).toHaveText('3');
    const missing = sitemapPanel(app).getByRole('list', { name: 'No page and no redirect' }).getByRole('listitem');
    await expect(missing).toHaveText(['/shop/rucksaecke-alt', '/blog/packliste-fuer-camper-alt', '/shop/outlet/sale-2019']);
    await expect(sitemapPanel(app)).toContainText('3 suggestions were created for these paths.');

    const list = await api('GET', '/redirects/suggestions');
    expect(list.meta.counts.open).toBe(10);
    const fromSitemap = (list.data as any[]).filter((s) => s.source === 'sitemap');
    expect(fromSitemap.map((s) => [s.path, s.target]).sort()).toEqual([
      ['/blog/packliste-fuer-camper-alt', '/blog/packliste-fuer-camper'],
      ['/shop/outlet/sale-2019', '/shop/outlet'],
      ['/shop/rucksaecke-alt', '/shop/rucksaecke'],
    ]);

    await sitemapPanel(app).getByRole('link', { name: 'Review suggestions' }).click();
    await expect(app.page).toHaveURL(/#\/suggestions/);
    await expect(app.root.getByRole('radio', { name: 'Open (10)' })).toBeChecked();
  });
});

test.describe('export', () => {
  const exportPanel = (app: App) => app.root.getByRole('region', { name: 'Export rules' });

  async function download(app: App, button: string): Promise<{ dl: Download; text: string }> {
    const [dl] = await Promise.all([app.page.waitForEvent('download'), exportPanel(app).getByRole('button', { name: button, exact: true }).click()]);
    return { dl, text: await readDownload(dl) };
  }

  test('one download button per exportable format', async ({ app, api }) => {
    const formats = ((await api('GET', '/redirects/import/formats')).data as any[]).filter((f) => f.export);
    await app.goto('#/export');
    await expect(exportPanel(app).getByRole('button', { name: /^Download / })).toHaveCount(formats.length);
    for (const f of formats) await expect(exportPanel(app).getByRole('button', { name: /^Download / }).filter({ hasText: f.label.split(' (')[0] })).not.toHaveCount(0);
  });

  test('CSV export: file name, header row and every seeded rule', async ({ app, api }) => {
    await app.goto('#/export');
    const { dl, text } = await download(app, 'Download CSV');
    expect(dl.suggestedFilename()).toBe('redirects.csv');
    await expect(app.toast('Downloaded redirects.csv')).toBeVisible();

    const lines = text.trim().split(/\r?\n/);
    expect(lines[0]).toBe('source,target,status,match_type,enabled,priority,group,note,query_mode,case_sensitive,ignore_trailing_slash,only_if_not_found,expires_at,tags');
    const rules = (await api('GET', '/redirects/rules?per_page=500')).data as any[];
    expect(rules).toHaveLength(SEED_RULES);
    expect(lines.length - 1).toBe(SEED_RULES);
    for (const source of ['/produkt/zelt-alpin-3', '/kette-a', '/schleife-a', '/wp-login.php', '/partner', '/konflikt', '/abgelaufen', '/bald']) {
      expect(lines.some((l) => l.startsWith(source + ',')), `${source} in the CSV`).toBe(true);
    }
    expect(text).toContain('/produkt/zelt-alpin-3,/shop/zelte,301,exact,true,310,Shop,Relaunch,');
    expect(text).toContain('/wp-login.php,,410,exact,');
  });

  test('the group filter narrows the file, "Only enabled rules" narrows it further', async ({ app, api }) => {
    const group = ((await api('GET', '/redirects/rules?group=Kampagnen&per_page=500')).data as any[]).map((r) => [r.source, r.enabled]);
    expect(group.length).toBeGreaterThan(2);
    const disabled = group.filter(([, enabled]) => !enabled).map(([source]) => source);
    expect(disabled).toEqual(['/aktion/winter']);

    await app.goto('#/export');
    await exportPanel(app).getByRole('combobox', { name: 'Group' }).selectOption('Kampagnen');
    const first = await download(app, 'Download CSV');
    const sourcesOf = (text: string) => text.trim().split(/\r?\n/).slice(1).map((l) => l.split(',')[0]);
    expect(sourcesOf(first.text).sort()).toEqual(group.map(([source]) => source as string).sort());
    expect(first.text).not.toContain('/produkt/zelt-alpin-3');

    await exportPanel(app).getByRole('checkbox', { name: 'Only enabled rules' }).check();
    const second = await download(app, 'Download CSV');
    expect(sourcesOf(second.text).sort()).toEqual(group.filter(([, enabled]) => enabled).map(([source]) => source as string).sort());
    expect(second.text).not.toContain('/aktion/winter');
    expect(sourcesOf(second.text).length).toBe(sourcesOf(first.text).length - 1);
  });

  test('the status filter exports just the 410 rule', async ({ app }) => {
    await app.goto('#/export');
    await exportPanel(app).getByRole('combobox', { name: 'Status code' }).selectOption({ label: '410 Gone' });
    const { text } = await download(app, 'Download CSV');
    const lines = text.trim().split(/\r?\n/);
    expect(lines).toHaveLength(2);
    expect(lines[1]).toContain('/wp-login.php,,410,exact');
  });

  test('a filter that matches no rule exports just the header row', async ({ app }) => {
    await app.goto('#/export');
    await exportPanel(app).getByRole('combobox', { name: 'Status code' }).selectOption({ label: '451 Unavailable for legal reasons' });
    const { text } = await download(app, 'Download CSV');
    expect(text.trim().split(/\r?\n/)).toEqual(['source,target,status,match_type,enabled,priority,group,note,query_mode,case_sensitive,ignore_trailing_slash,only_if_not_found,expires_at,tags']);
  });

  test('.htaccess export: RewriteRule lines for the seeded rules', async ({ app }) => {
    await app.goto('#/export');
    const { dl, text } = await download(app, 'Download Apache .htaccess');
    expect(dl.suggestedFilename()).toBe('redirects.htaccess');
    expect(text).toContain('RewriteEngine On');
    expect(text).toContain('RewriteRule ^produkt/zelt-alpin-3/?$ /shop/zelte [R=301,L,NC]');
    expect(text).toContain('RewriteRule ^produkte/(.*)$ /shop [R=301,L,NC]');
    expect(text).toContain('RewriteRule ^news/?$ /blog [R=302,L,NC]');
  });

  test('JSON export is a complete, parseable copy of the rules', async ({ app, api }) => {
    await app.goto('#/export');
    const { dl, text } = await download(app, 'Download JSON');
    expect(dl.suggestedFilename()).toBe('redirects.json');
    const doc = JSON.parse(text) as { version: number; rules: any[] };
    expect(doc.version).toBe(1);
    const rules = (await api('GET', '/redirects/rules?per_page=500')).data as any[];
    expect(doc.rules).toHaveLength(SEED_RULES);
    expect(doc.rules.map((r) => r.id).sort()).toEqual(rules.map((r) => r.id).sort());
    expect(doc.rules.find((r) => r.source === '/produkt/zelt-alpin-3')).toMatchObject({ target: '/shop/zelte', status: 301, group: 'Shop', note: 'Relaunch', tags: ['relaunch'] });
  });

  test('a format that cannot express every rule lists what it left out (Netlify)', async ({ app }) => {
    await app.goto('#/export');
    const { dl, text } = await download(app, 'Download Netlify _redirects');
    expect(dl.suggestedFilename()).toBe('_redirects.txt');
    expect(text).toContain('/produkt/zelt-alpin-3 /shop/zelte 301');
    expect(text).toContain('/altes-blog/* /blog/:splat 301');
    await expect(exportPanel(app).getByText(/Left out of the Netlify _redirects file: \d+ rules?/)).toBeVisible();
  });
});
