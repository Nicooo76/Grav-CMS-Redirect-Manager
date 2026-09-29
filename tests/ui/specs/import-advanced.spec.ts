import { readFileSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect } from '../support/test';
import type { SiteControl } from '../support/test';
import { uiDir } from '../support/env';
import type { App } from '../support/app';

/** A real drop on the drop zone, manual column mapping of a headerless CSV, and Grav's site.yaml entries. */

const importPanel = (app: App) => app.root.getByRole('region', { name: 'Import redirects' });
const previewPanel = (app: App) => app.root.getByRole('region', { name: 'Preview' });
const previewRows = (app: App) => previewPanel(app).getByRole('table', { name: 'Rows found in the file, with problems marked' }).locator('tbody tr');
const tile = (app: App, label: string) => previewPanel(app).locator('dl > div').filter({ has: app.page.getByText(label, { exact: true }) }).locator('dd');

test.describe('import: drag and drop', () => {
  test('a file dropped on the zone is read like a chosen one; the zone shows the drag state and clears it', async ({ app, api }) => {
    await app.goto('#/import');
    const zone = importPanel(app).locator('label.drop');
    const csv = readFileSync(join(uiDir, 'fixtures', 'import-mixed.csv'), 'utf8');
    const data = await app.page.evaluateHandle(({ name, text }) => {
      const dt = new DataTransfer();
      dt.items.add(new File([text], name, { type: 'text/csv' }));
      return dt;
    }, { name: 'dropped-rules.csv', text: csv });

    // dragging a file over the zone highlights it, leaving un-highlights it again
    await zone.dispatchEvent('dragenter', { dataTransfer: data });
    await expect(zone).toHaveClass(/\bover\b/);
    await zone.dispatchEvent('dragover', { dataTransfer: data });
    await zone.dispatchEvent('dragleave', { dataTransfer: data });
    await expect(zone).not.toHaveClass(/\bover\b/);

    // the drop itself
    await zone.dispatchEvent('dragenter', { dataTransfer: data });
    await zone.dispatchEvent('dragover', { dataTransfer: data });
    await zone.dispatchEvent('drop', { dataTransfer: data });
    await expect(importPanel(app)).toContainText('dropped-rules.csv');
    await expect(importPanel(app).locator('option:checked').first()).toHaveText('Detected: CSV');
    await expect(tile(app, 'Rows')).toHaveText('9');
    await expect(tile(app, 'Valid')).toHaveText('6');
    await expect(tile(app, 'Errors')).toHaveText('3');
    // nothing was imported by the drop, only previewed
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(32);
  });

  test('a drag that carries no file (text) is ignored', async ({ app }) => {
    await app.goto('#/import');
    const zone = importPanel(app).locator('label.drop');
    const data = await app.page.evaluateHandle(() => {
      const dt = new DataTransfer();
      dt.setData('text/plain', '/a,/b');
      return dt;
    });
    await zone.dispatchEvent('dragenter', { dataTransfer: data });
    await expect(zone).not.toHaveClass(/\bover\b/);
    await zone.dispatchEvent('drop', { dataTransfer: data });
    await expect(previewPanel(app)).toHaveCount(0);
  });
});

test.describe('import: manual column mapping', () => {
  // no header; the columns are status, target, source, group (the guess takes the first URL column as the source)
  const FILE = ['301,/neu-eins,/alt-eins,Umzug', '302,/neu-zwei,/alt-zwei,Umzug', '307,/neu-drei,/alt-drei,Umzug'].join('\n') + '\n';

  test('the guess is wrong, the user maps the columns, the preview follows and the import creates the right rules', async ({ app, api, site, request }, testInfo) => {
    const file = testInfo.outputPath('unusual-order.csv');
    writeFileSync(file, FILE);
    await app.goto('#/import');
    await importPanel(app).locator('input[type=file]').setInputFiles(file);

    await expect(importPanel(app).getByRole('checkbox', { name: 'First row is a header' })).not.toBeChecked();
    const use = (n: number) => importPanel(app).getByRole('combobox', { name: `Use of column ${n}` });
    // what the guess made of it: status, source, target (wrong way round), and the rest ignored
    await expect(use(1)).toHaveValue('status');
    await expect(use(2)).toHaveValue('source');
    await expect(use(3)).toHaveValue('target');
    await expect(use(4)).toHaveValue('ignore');
    await expect(previewRows(app).first()).toContainText('/neu-eins');
    await expect(previewRows(app).first()).toContainText('/alt-eins');
    const wrong = await previewRows(app).first().locator('.pair .mono').allTextContents();
    expect(wrong).toEqual(['/neu-eins', '/alt-eins']);

    // re-map: column 2 is the target, column 3 the source, column 4 the group
    await use(2).selectOption('target');
    // a field sits on one column only: column 3 lost its target role when column 2 got it
    await expect(use(3)).toHaveValue('ignore');
    await use(3).selectOption('source');
    await use(4).selectOption('group');
    await expect(use(2)).toHaveValue('target');
    await expect(use(3)).toHaveValue('source');
    await expect(use(4)).toHaveValue('group');

    await expect(tile(app, 'Rows')).toHaveText('3');
    await expect.poll(async () => previewRows(app).first().locator('.pair .mono').allTextContents()).toEqual(['/alt-eins', '/neu-eins']);
    await expect(previewRows(app).nth(1).locator('.pair .mono')).toHaveText(['/alt-zwei', '/neu-zwei']);
    await expect(previewRows(app).nth(2)).toContainText('307');
    expect((await api('GET', '/redirects/rules?per_page=1')).meta.total).toBe(32);

    await previewPanel(app).getByRole('button', { name: /^Import 3 rules$/ }).click();
    await expect(app.toast('3 rules imported')).toBeVisible();

    // the seed itself came in through the importer: the group tells the new rules apart
    const rules = ((await api('GET', '/redirects/rules?origin=import&per_page=500')).data as any[]).filter((r) => r.group === 'Umzug');
    expect(rules.map((r) => [r.source, r.target, r.status, r.group]).sort()).toEqual([
      ['/alt-drei', '/neu-drei', 307, 'Umzug'],
      ['/alt-eins', '/neu-eins', 301, 'Umzug'],
      ['/alt-zwei', '/neu-zwei', 302, 'Umzug'],
    ]);
    const res = await request.get(`${site.baseUrl}/alt-zwei`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe('/neu-zwei');
    // the wrong reading would have made /neu-zwei the source
    expect((await request.get(`${site.baseUrl}/neu-zwei`, { maxRedirects: 0 })).status()).toBe(404);
  });

  test('a missing source or target column blocks the preview and says what to choose', async ({ app }, testInfo) => {
    const file = testInfo.outputPath('two-columns.csv');
    writeFileSync(file, '/alt-a,/neu-a\n/alt-b,/neu-b\n');
    await app.goto('#/import');
    await importPanel(app).locator('input[type=file]').setInputFiles(file);
    await expect(previewRows(app)).toHaveCount(2);
    await importPanel(app).getByRole('combobox', { name: 'Use of column 2' }).selectOption('ignore');
    await expect(app.root.getByRole('paragraph').filter({ hasText: 'Choose a source column and a target column to see the preview.' })).toBeVisible();
    // the rows are gone and the import button is off until the mapping is complete again
    await expect(previewRows(app)).toHaveCount(0);
    await expect(previewPanel(app).getByRole('button', { name: /^Import/ })).toBeDisabled();
    await importPanel(app).getByRole('combobox', { name: 'Use of column 2' }).selectOption('target');
    await expect(previewRows(app)).toHaveCount(2);
    await expect(previewPanel(app).getByRole('button', { name: 'Import 2 rules' })).toBeEnabled();
  });
});

/** Writes user/config/site.yaml (null: removes it); Grav's compiled configuration is dropped so the next request reads it. */
function setSiteYaml(site: SiteControl, text: string | null): void {
  const file = join(site.dir, 'user', 'config', 'site.yaml');
  if (text === null) rmSync(file, { force: true });
  else writeFileSync(file, text);
  rmSync(join(site.dir, 'cache', 'compiled'), { recursive: true, force: true });
}

test.describe('site configuration (site.redirects and site.routes)', () => {
  const SITE_YAML = [
    'redirects:',
    "  /legacy-a: /shop",
    "  /legacy-b: /info",
    'routes:',
    "  /pretty-c: /faq",
    '',
  ].join('\n');

  test('the panel lists the entries read-only, "Import" creates the rules and site.yaml stays as it is', async ({ app, api, site, request }) => {
    const file = join(site.dir, 'user', 'config', 'site.yaml');
    const before = existsSync(file) ? readFileSync(file, 'utf8') : null;
    setSiteYaml(site, SITE_YAML);
    try {
      await app.goto('#/export');
      const panel = app.root.getByRole('region', { name: 'Grav site configuration' });
      await expect(panel).toBeVisible();
      await expect(panel).toContainText('Your site.yaml stays as it is.');
      await expect(panel.locator('summary', { hasText: 'site.redirects' })).toContainText('2 entries');
      await expect(panel.locator('summary', { hasText: 'site.routes' })).toContainText('1 entry');
      const redirects = panel.getByRole('region', { name: 'site.redirects, 2 entries' });
      await expect(redirects.locator('tbody tr')).toHaveCount(2);
      await expect(redirects.locator('tbody tr').first()).toContainText('/legacy-a');
      await expect(redirects.locator('tbody tr').first()).toContainText('/shop');
      await expect(panel.getByRole('region', { name: 'site.routes, 1 entries' }).locator('tbody tr')).toContainText('/pretty-c');
      // read-only: the entries are text, there is nothing to type into
      await expect(panel.locator('input, textarea, select')).toHaveCount(0);
      expect((await api('GET', '/redirects/rules?q=legacy&per_page=50')).data).toEqual([]);

      await panel.getByRole('button', { name: 'Import into Redirect Manager' }).click();
      await expect(app.toast('3 imported, 0 skipped because they already exist.')).toBeVisible();

      const rules = (await api('GET', '/redirects/rules?per_page=500')).data as any[];
      const made = rules.filter((r) => ['/legacy-a', '/legacy-b', '/pretty-c'].includes(r.source));
      expect(made.map((r) => [r.source, r.target, r.status]).sort()).toEqual([
        // Grav's own default code for site.redirects (pages.redirect_default_code, 302) is what the rules get
        ['/legacy-a', '/shop', 302],
        ['/legacy-b', '/info', 302],
        ['/pretty-c', '/faq', 200],
      ]);
      const res = await request.get(`${site.baseUrl}/legacy-a`, { maxRedirects: 0 });
      expect(res.status()).toBe(302);
      expect(res.headers()['location']).toBe('/shop');
      expect(readFileSync(file, 'utf8')).toBe(SITE_YAML);

      // a second import finds the rules and creates nothing
      await panel.getByRole('button', { name: 'Import into Redirect Manager' }).click();
      await expect(app.toast('0 imported, 3 skipped because they already exist.')).toBeVisible();
    } finally {
      setSiteYaml(site, before);
    }
  });

  test('without entries the panel says so and offers no import', async ({ app, site }) => {
    const file = join(site.dir, 'user', 'config', 'site.yaml');
    const before = existsSync(file) ? readFileSync(file, 'utf8') : null;
    setSiteYaml(site, 'title: Test\n');
    try {
      await app.goto('#/export');
      const panel = app.root.getByRole('region', { name: 'Grav site configuration' });
      await expect(panel).toContainText('Your site.yaml defines no redirects or routes');
      await expect(panel.getByRole('button', { name: 'Import into Redirect Manager' })).toHaveCount(0);
    } finally {
      setSiteYaml(site, before);
    }
  });
});
