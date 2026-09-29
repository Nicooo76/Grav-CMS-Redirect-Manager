/**
 * The UI for a user who may read redirects but not change them (api.access + api.redirects.read):
 * every write control is gone or disabled, a note says why, and the API refuses writes.
 * Needs the account from _testsite/.test-credentials-readonly (bin/plugin login new-user, read permission only).
 *   node e2e/real-readonly.mjs
 */
import { launch, openAdmin, gotoPlugin, apiClient, reporter, bad } from './lib/real.mjs';

const { say, step, failed } = reporter();
const api = await apiClient({ readonly: true });
const browser = await launch();
const h = await openAdmin(browser, { theme: 'light', readonly: true });
const { page, pageEl } = h;
const P = () => pageEl();
const wait = (ms) => page.waitForTimeout(ms);

await step('read-only: /redirects/stats reports manage=false', async () => {
  const r = await api('GET', '/redirects/stats');
  return r.status === 200 && r.meta?.permissions?.manage === false && r.meta?.permissions?.read === true ? JSON.stringify(r.meta.permissions) : bad(JSON.stringify(r.meta));
});
await step('read-only: the API refuses a write with 403', async () => (await api('POST', '/redirects/rules', { source: '/x', target: '/y' })).status === 403);

await gotoPlugin(h, '#/rules');
await wait(1500);
await step('read-only: the note "You can view redirects but not change them" is shown', async () => (await P().locator('[data-testid=read-only-note]').textContent())?.includes('view redirects but not change'));
await step('read-only: no "New redirect" button, no bulk write buttons', async () => {
  const add = await P().getByRole('button', { name: 'New redirect' }).count();
  await P().locator('tbody tr[data-id] input[type=checkbox]').first().check();
  await wait(300);
  const bar = P().getByRole('toolbar', { name: 'Bulk actions' });
  const del = await bar.getByRole('button', { name: 'Delete' }).count();
  const dis = await bar.getByRole('button', { name: 'Disable' }).count();
  const exp = await bar.getByRole('button', { name: 'Export selected' }).count();
  return add === 0 && del === 0 && dis === 0 && exp === 1 ? 'only "Export selected" is left' : bad(`new=${add} delete=${del} disable=${dis} export=${exp}`);
});
await step('read-only: switches, inline edits and drag handles are disabled', async () => {
  const row = P().locator('tbody tr[data-id]').first();
  const sw = await row.getByRole('switch').isDisabled();
  const edit = await row.locator('button.cell-edit').count();
  const drag = await row.locator('button.drag').getAttribute('aria-disabled');
  return sw && edit === 0 && drag === 'true' ? 'ok' : bad(`switchDisabled=${sw} editButtons=${edit} dragDisabled=${drag}`);
});
await step('read-only: the row menu offers View and Test only', async () => {
  await P().locator('tbody tr[data-id]').first().locator('button[aria-haspopup=menu]').click();
  const items = await P().getByRole('menuitem').allTextContents();
  await page.keyboard.press('Escape');
  return items.length === 2 && items.some((t) => /View/.test(t)) ? items.join(', ') : bad(items.join(', '));
});
await step('read-only: the editor opens as a read-only view without Save/Delete', async () => {
  await P().locator('tbody tr[data-id] a.src').first().click();
  await wait(1200);
  const save = await P().getByRole('button', { name: /Save|Create redirect/ }).count();
  const del = await P().getByRole('button', { name: 'Delete' }).count();
  const disabled = await P().evaluate((el) => el.shadowRoot.querySelector('fieldset.ro')?.disabled);
  await page.keyboard.press('Escape');
  await wait(500);
  return save === 0 && del === 0 && disabled === true ? 'ok' : bad(`save=${save} delete=${del} fieldsetDisabled=${disabled}`);
});
await step('read-only: pending decisions cannot be resolved', async () => {
  await gotoPlugin(h, '#/rules');
  const items = P().locator('[data-testid=pending-panel] li.item');
  const n = await items.count();
  if (!n) return 'no pending items (skipped)';
  return (await items.first().getByRole('button', { name: /Gone/ }).isDisabled()) ? `${n} items, buttons disabled` : false;
});
await step('read-only: 404 monitor has no create or ignore actions', async () => {
  await gotoPlugin(h, '#/404');
  const create = await P().getByRole('button', { name: /Create redirect for/ }).count();
  await P().locator('tbody tr[data-path] button[aria-haspopup=menu]').first().click();
  const items = await P().getByRole('menuitem').allTextContents();
  await page.keyboard.press('Escape');
  return create === 0 && items.length === 1 ? items.join(', ') : bad(`create=${create} menu=${items.join(', ')}`);
});
await step('read-only: suggestions list has no accept, reject or generate', async () => {
  await gotoPlugin(h, '#/suggestions');
  const a = await P().getByRole('button', { name: /Accept the suggestion/ }).count();
  const g = await P().getByRole('button', { name: 'Generate suggestions' }).count();
  const bulk = await P().getByRole('button', { name: /Review and accept/ }).count();
  return a === 0 && g === 0 && bulk === 0;
});
await step('read-only: the tester still works', async () => {
  await gotoPlugin(h, '#/tester?url=/faq');
  await wait(1500);
  return (await P().locator('ol.chain > li').count()) === 3;
});
await step('read-only: import is replaced by a notice, export works', async () => {
  await gotoPlugin(h, '#/import');
  const note = await P().getByText(/permission to change redirects/).count();
  await gotoPlugin(h, '#/export');
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 8000 }), P().getByRole('button', { name: 'Download CSV' }).click()]);
  return note >= 1 && (await dl.suggestedFilename()) ? dl.suggestedFilename() : bad(`note=${note}`);
});
await step('read-only: the dashboard widget renders', async () => {
  await page.goto(`${h.base}/admin/`);
  await wait(3500);
  const text = await page.locator('grav-widget-redirect-manager-overview').first().evaluate((el) => el.shadowRoot?.querySelector('section')?.textContent?.replace(/\s+/g, ' ').trim().slice(0, 100)).catch(() => '');
  return /404|suggest|rule/i.test(text) ? text : bad(`text=${text}`);
});

const unexpected = h.failed.filter((f) => !/^403 /.test(f));
say('read-only: no unexpected failed API calls', unexpected.length === 0, [...new Set(h.failed)].join(', '));
say('read-only: no console errors', h.errors.length === 0, h.errors.slice(0, 3).join(' | '));
await browser.close();
const notOk = failed();
console.log(`${notOk.length} of steps failed`);
process.exit(notOk.length ? 1 : 0);
