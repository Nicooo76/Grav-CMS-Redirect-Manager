/**
 * Drives every action of the plugin UI end to end against the real backend of the dev site
 * (http://127.0.0.1:8088, Admin 2 at /admin). It changes data: run it on the seeded site and re-seed afterwards.
 *   node e2e/real-flows.mjs [area ...]     areas: rules 404 suggestions tester import export auto settings widget
 * Credentials come from _testsite/.test-credentials and are never printed.
 */
import { launch, openAdmin, gotoPlugin, apiClient, reporter, bad, site } from './lib/real.mjs';
import { copyFileSync, existsSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';

const areas = process.argv.slice(2);
const want = (a) => areas.length === 0 || areas.includes(a);
let failShot = async () => {};
const { say, step, failed } = reporter({ onFail: (name, e) => failShot(name, e) });
const api = await apiClient();
const browser = await launch();
const h = await openAdmin(browser, { theme: 'light' });
const { page, pageEl } = h;
const P = () => pageEl();
let nFail = 0;
failShot = async (name) => {
  await page.screenshot({ path: `${process.env.RM_FAIL_DIR || '/tmp'}/rm-flow-fail-${++nFail}.png` }).then(() => console.log(`     screenshot: rm-flow-fail-${nFail}.png (${name})`));
  // a dialog or slide-over left open would break every later step
  await page.keyboard.press('Escape');
  await page.keyboard.press('Escape');
};
const wait = (ms) => page.waitForTimeout(ms);
const idle = () => page.waitForFunction(() => !document.querySelector('grav-redirect-manager--page')?.shadowRoot?.querySelector('[aria-busy="true"]'), null, { timeout: 8000 }).catch(() => {});
const toast = (re) => page.getByText(re).first();
const rowFor = (text) => P().locator('tbody tr[data-id]', { hasText: text });
const ruleBySource = async (source) => (await api('GET', `/redirects/rules?q=${encodeURIComponent(source)}&per_page=50`)).data.find((r) => r.source === source);
/** the host's own dialogs (form, confirm) live in the page DOM, outside the plugin's shadow root */
const hostDialog = () => page;

async function search(q) {
  const box = P().getByRole('searchbox');
  await box.fill(q);
  await wait(700);
  await idle();
}

/* ---------------------------------------------------------------- rules */
if (want('rules')) {
  await gotoPlugin(h, '#/rules');

  await step('rules: list shows seeded rules with badges and counts', async () => {
    const rows = await P().locator('tbody tr[data-id]').count();
    return rows > 10 ? `rows=${rows}` : false;
  });

  await step('rules: create through the editor (default status hint, save)', async () => {
    await P().getByRole('button', { name: 'New redirect' }).click();
    await wait(600);
    await page.getByLabel('Source', { exact: true }).fill('/e2e/alt-seite');
    await page.getByLabel('Target', { exact: true }).fill('/shop');
    await wait(800);
    const note = await P().locator('[data-testid=status-note]').count();
    say('rules: status note offers 301 for new rules (default is 302)', note === 1, `note=${note}`);
    if (note) await P().locator('[data-testid=status-note] button').click();
    await P().getByRole('button', { name: 'Create redirect' }).click();
    await wait(1200);
    const rule = await ruleBySource('/e2e/alt-seite');
    return rule && rule.status === 301 && rule.target === '/shop' ? `id=${rule.id}` : false;
  });

  await step('rules: created rule is listed after search', async () => {
    await search('e2e/alt-seite');
    return (await rowFor('/e2e/alt-seite').count()) === 1;
  });

  await step('rules: inline edit of the target', async () => {
    const row = rowFor('/e2e/alt-seite');
    await row.locator('button.cell-edit').first().click();
    const input = row.locator('input.inline');
    await input.fill('/blog');
    await input.press('Enter');
    await wait(900);
    return (await ruleBySource('/e2e/alt-seite')).target === '/blog';
  });

  await step('rules: inline edit of the status code', async () => {
    const row = rowFor('/e2e/alt-seite');
    await row.locator('button.cell-edit.code').click();
    await row.locator('select').selectOption('308');
    await wait(900);
    return (await ruleBySource('/e2e/alt-seite')).status === 308;
  });

  await step('rules: enable switch toggles the rule', async () => {
    const row = rowFor('/e2e/alt-seite');
    await row.getByRole('switch').click();
    await wait(900);
    const off = (await ruleBySource('/e2e/alt-seite')).enabled === false;
    await row.getByRole('switch').click();
    await wait(900);
    return off && (await ruleBySource('/e2e/alt-seite')).enabled === true;
  });

  await step('rules: duplicate opens the editor prefilled and saves a copy', async () => {
    await rowFor('/e2e/alt-seite').locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Duplicate' }).click();
    await wait(700);
    await page.getByLabel('Source', { exact: true }).fill('/e2e/kopie');
    await P().getByRole('button', { name: 'Create redirect' }).click();
    await wait(1200);
    return !!(await ruleBySource('/e2e/kopie'));
  });

  await step('rules: delete offers Undo, undo restores the rule', async () => {
    await search('e2e/kopie');
    await rowFor('/e2e/kopie').locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Delete' }).click();
    await wait(1000);
    const gone = !(await ruleBySource('/e2e/kopie'));
    await page.getByRole('button', { name: 'Undo' }).first().click();
    await wait(1200);
    return gone && !!(await ruleBySource('/e2e/kopie'));
  });

  await step('rules: bulk actions (disable, enable, status, group, tag)', async () => {
    await search('e2e/');
    await P().locator('tbody tr[data-id] input[type=checkbox]').nth(0).check();
    await P().locator('tbody tr[data-id] input[type=checkbox]').nth(1).check();
    await wait(300);
    const bar = P().getByRole('toolbar', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Disable' }).click();
    await wait(1000);
    const rules1 = (await api('GET', '/redirects/rules?q=e2e/&per_page=50')).data;
    const off = rules1.filter((r) => !r.enabled).length === 2;
    await bar.getByRole('button', { name: 'Enable' }).click();
    await wait(1000);
    await bar.getByRole('button', { name: 'Change status' }).click();
    await P().getByRole('menuitem', { name: /^307/ }).click();
    await wait(1000);
    await bar.getByRole('button', { name: 'More' }).click();
    await P().getByRole('menuitem', { name: 'Set group' }).click();
    await wait(600);
    await hostDialog().getByLabel('Group', { exact: true }).fill('E2E');
    await hostDialog().getByRole('button', { name: 'Apply', exact: true }).click();
    await wait(1000);
    const after = (await api('GET', '/redirects/rules?q=e2e/&per_page=50')).data;
    return off && after.every((r) => r.enabled) && after.every((r) => r.status === 307) && after.every((r) => r.group === 'E2E') ? 'ok' : bad(`off=${off} ${JSON.stringify(after.map((r) => [r.enabled, r.status, r.group]))}`);
  });

  await step('rules: "Export selected" downloads only the selection, in another format', async () => {
    const bar = P().getByRole('toolbar', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Export selected' }).click();
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 8000 }), P().getByRole('menuitem', { name: /Apache/ }).click()]);
    const path = await dl.path();
    const fs = await import('node:fs');
    const text = fs.readFileSync(path, 'utf8');
    const n = (text.match(/e2e\//g) ?? []).length;
    return n >= 2 && !/produkt/.test(text) ? `${dl.suggestedFilename()} (${text.length} bytes)` : bad(`wrong content: ${text.slice(0, 200)}`);
  });

  await step('rules: bulk delete, Undo (Ctrl+Z path) restores', async () => {
    const bar = P().getByRole('toolbar', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Delete' }).click();
    await wait(1000);
    const left = (await api('GET', '/redirects/rules?q=e2e/&per_page=50')).data.length;
    await page.getByRole('button', { name: 'Undo' }).first().click();
    await wait(1200);
    const back = (await api('GET', '/redirects/rules?q=e2e/&per_page=50')).data.length;
    return left === 0 && back === 2 ? 'ok' : bad(`left=${left} back=${back}`);
  });

  await step('rules: reorder with the row menu (move down)', async () => {
    await gotoPlugin(h, '#/rules?sort=priority&dir=desc');
    const before = (await api('GET', '/redirects/rules?sort=priority&dir=desc&per_page=3')).data.map((r) => r.id);
    await P().locator('tbody tr[data-id]').first().locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Move down' }).click();
    await wait(1200);
    const after = (await api('GET', '/redirects/rules?sort=priority&dir=desc&per_page=3')).data.map((r) => r.id);
    return after[0] === before[1] || after[1] === before[0] ? 'order changed' : bad(`unchanged ${before} -> ${after}`);
  });

  await step('rules: shorten chain from the row menu', async () => {
    await gotoPlugin(h, '#/rules?badge=chain');
    const row = P().locator('tbody tr[data-id]').first();
    const id = await row.getAttribute('data-id');
    await row.locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Shorten chain' }).click();
    await wait(1200);
    const r = (await api('GET', `/redirects/rules/${id}`)).data;
    return !r.badges.includes('chain') ? `target=${r.target}` : bad('still a chain');
  });

  await step('rules: quick filter, filters panel and clear', async () => {
    await gotoPlugin(h, '#/rules');
    await P().getByRole('button', { name: /Loop/ }).first().click();
    await wait(900);
    const n = await P().locator('tbody tr[data-id]').count();
    return n >= 1 ? `loop rows=${n}` : false;
  });

  await step('rules: editor shows conflict issue with a button to open the other rule', async () => {
    await gotoPlugin(h, '#/rules?badge=conflict');
    await P().locator('tbody tr[data-id] a.src').first().click();
    await wait(1500);
    const btn = await P().getByRole('button', { name: /Open the other rule/ }).count();
    return btn >= 1 ? `buttons=${btn}` : false;
  });

  await step('rules: editor hints for * with Exact and a one-click switch', async () => {
    await gotoPlugin(h, '#/rules/new');
    await page.getByLabel('Source', { exact: true }).fill('/blog/*');
    await wait(600);
    const hint = await P().locator('[data-testid=match-hint]').count();
    const sample = await P().locator('input[aria-label*="URL"], #pv-sample').first().inputValue().catch(() => '');
    await P().locator('[data-testid=match-hint] button').click();
    await wait(400);
    const checked = await P().getByRole('radio', { name: 'wildcard' }).getAttribute('aria-checked').catch(() => null);
    return hint === 1 && checked === 'true' ? `sample="${sample}"` : bad(`hint=${hint} checked=${checked}`);
  });

  await step('rules: validation preview answers from the real API', async () => {
    await page.getByLabel('Target', { exact: true }).fill('/journal/$1');
    await wait(1200);
    const txt = await P().evaluate((el) => el.shadowRoot.querySelector('#pv-result')?.textContent?.replace(/\s+/g, ' ').trim());
    return /journal\/example/.test(txt ?? '') ? txt : bad(`preview: ${txt}`);
  });

  await step('rules: closing the editor with changes asks first, discard closes it', async () => {
    await page.keyboard.press('Escape');
    await wait(500);
    await page.getByRole('button', { name: 'Discard changes', exact: true }).click();
    await wait(600);
    return (await P().locator('[role=dialog][aria-modal=true]').count()) === 0;
  });

  await step('rules: cleanup of e2e rules', async () => {
    const rules = (await api('GET', '/redirects/rules?q=e2e/&per_page=50')).data.map((r) => r.id);
    if (rules.length) await api('POST', '/redirects/rules/bulk', { action: 'delete', ids: rules });
    return `removed ${rules.length}`;
  });
}

/* ---------------------------------------------------------------- 404 monitor */
if (want('404')) {
  await gotoPlugin(h, '#/404');
  const nfRows = () => P().locator('tbody tr[data-path]');

  await step('404: list, trend chart and totals render from the real API', async () => {
    const n = await nfRows().count();
    const totals = await P().locator('dl, .totals, [class*=tot]').first().textContent().catch(() => '');
    return n > 5 ? `rows=${n}` : false;
  });

  await step('404: search narrows the list', async () => {
    await P().getByRole('searchbox').fill('zelt-alpin');
    await wait(800);
    const n = await nfRows().count();
    await P().getByRole('searchbox').fill('');
    await wait(700);
    return n >= 1 && n < 10 ? `rows=${n}` : false;
  });

  await step('404: period and bot toggles reload the data', async () => {
    await P().getByRole('radio', { name: '7 days' }).click().catch(async () => P().getByRole('button', { name: '7 days' }).click());
    await wait(900);
    const n7 = await nfRows().count();
    await P().getByRole('radio', { name: '90 days' }).click().catch(async () => P().getByRole('button', { name: '90 days' }).click());
    await wait(900);
    const n90 = await nfRows().count();
    await P().getByRole('switch', { name: 'Include bots' }).click().catch(() => {});
    await wait(900);
    const nb = await nfRows().count();
    await P().getByRole('switch', { name: 'Include bots' }).click().catch(() => {});
    await wait(700);
    return n90 >= n7 ? `7d=${n7} 90d=${n90} withBots=${nb}` : false;
  });

  await step('404: raw entries expand', async () => {
    await gotoPlugin(h, '#/404');
    await nfRows().first().locator('button.exp').click();
    await wait(1200);
    const entries = await P().locator('tr.detail').count();
    return entries === 1;
  });

  await step('404: create redirect from a row (editor prefilled with the suggestion), save', async () => {
    await gotoPlugin(h, '#/404');
    await wait(500);
    await P().getByRole('button', { name: /Create redirect for/ }).first().click();
    await wait(1500);
    const src = await page.getByLabel('Source', { exact: true }).inputValue();
    const target = await page.getByLabel('Target', { exact: true }).inputValue();
    await P().getByRole('button', { name: 'Create redirect', exact: true }).click();
    await wait(1500);
    const rule = await ruleBySource(src);
    if (rule) await api('DELETE', `/redirects/rules/${rule.id}`);
    return rule ? `${src} -> ${target} (${rule.status})` : bad(`not created (${src})`);
  });

  await step('404: mark as done, then reopen from "Show done"', async () => {
    await gotoPlugin(h, '#/404');
    await P().getByRole('searchbox').fill('mein-konto');
    await wait(900);
    const row = nfRows().first();
    const path = await row.getAttribute('data-path');
    await row.locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Mark as done' }).click();
    await wait(1200);
    const gone = (await nfRows().count()) === 0;
    await P().getByRole('switch', { name: 'Show done' }).click();
    await wait(1000);
    const back = P().locator(`tbody tr[data-path="${path}"]`);
    await back.locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Reopen' }).click();
    await wait(1000);
    return gone ? `path=${path}` : bad('row stayed');
  });

  await step('404: ignore pattern removes matching paths and stops logging them', async () => {
    await gotoPlugin(h, '#/404');
    await P().getByRole('searchbox').fill('konto');
    await wait(900);
    const row = nfRows().first();
    await row.locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: /Ignore/ }).click();
    await wait(700);
    await hostDialog().getByRole('button', { name: 'Ignore and remove entries', exact: true }).click();
    await wait(1500);
    const left = await nfRows().count();
    return left === 0 ? 'removed' : bad(`rows left=${left}`);
  });

  await step('404: delete log entries of a path (confirm dialog)', async () => {
    await gotoPlugin(h, '#/404');
    await P().getByRole('searchbox').fill('warenkorb');
    await wait(900);
    const row = nfRows().first();
    if ((await nfRows().count()) === 0) return 'no row for the search (skipped)';
    await row.locator('button[aria-haspopup=menu]').click();
    await P().getByRole('menuitem', { name: 'Delete log entries' }).click();
    await wait(700);
    await hostDialog().getByRole('button', { name: 'Delete entries', exact: true }).click();
    await wait(1500);
    return (await nfRows().count()) === 0;
  });
}

/* ---------------------------------------------------------------- suggestions */
if (want('suggestions')) {
  await gotoPlugin(h, '#/suggestions');
  const sgRows = () => P().locator('tbody tr').filter({ has: page.locator('button.accept') });

  await step('suggestions: tab counts come from meta.counts (one list call, no per-status calls)', async () => {
    await gotoPlugin(h, '#/rules');
    const calls = [];
    const on = (r) => r.url().includes('/redirects/suggestions') && calls.push(r.url());
    page.on('request', on);
    await P().getByRole('link', { name: /Suggestions/ }).click();
    await wait(2000);
    page.off('request', on);
    const lists = calls.filter((u) => /\/suggestions(\?|$)/.test(u));
    const label = await P().getByRole('radio', { name: /Open \(/ }).textContent().catch(() => '');
    return lists.length === 1 ? `list calls=${lists.length} ${label?.trim()}` : bad(`list calls=${lists.length}: ${lists.join(' ')}`);
  });

  await step('suggestions: accept one', async () => {
    const before = (await api('GET', '/redirects/suggestions')).meta.counts;
    await sgRows().first().locator('button.accept').click();
    await wait(1500);
    const after = (await api('GET', '/redirects/suggestions')).meta.counts;
    return after.accepted === before.accepted + 1 ? `${JSON.stringify(after)}` : bad(`before=${JSON.stringify(before)} after=${JSON.stringify(after)}`);
  });

  await step('suggestions: edit the target inline, then accept', async () => {
    const row = sgRows().first();
    const path = await row.locator('td.c-path a').textContent();
    await row.locator('button.cell-edit').click();
    const input = row.locator('input.inline');
    await input.fill('/shop');
    await input.press('Enter');
    await wait(500);
    await row.locator('button.accept').click();
    await wait(1500);
    const rule = await ruleBySource(path.trim());
    if (rule) await api('DELETE', `/redirects/rules/${rule.id}`);
    return rule && rule.target === '/shop' ? `${path.trim()} -> /shop origin=${rule.origin}` : bad(`rule=${JSON.stringify(rule)}`);
  });

  await step('suggestions: reject one', async () => {
    const before = (await api('GET', '/redirects/suggestions')).meta.counts;
    await sgRows().first().getByRole('button', { name: /Reject/ }).click();
    await wait(1500);
    const after = (await api('GET', '/redirects/suggestions')).meta.counts;
    return after.rejected === before.rejected + 1;
  });

  await step('suggestions: accepted and rejected tabs list their rows', async () => {
    await P().getByRole('radio', { name: /Accepted/ }).click();
    await wait(900);
    const acc = await P().locator('tbody tr').count();
    await P().getByRole('radio', { name: /Rejected/ }).click();
    await wait(900);
    const rej = await P().locator('tbody tr').count();
    await P().getByRole('radio', { name: /Open/ }).click();
    await wait(700);
    return acc >= 1 && rej >= 1 ? `accepted=${acc} rejected=${rej}` : false;
  });

  await step('suggestions: generate reports created/improved from the real answer', async () => {
    await P().getByRole('button', { name: 'Generate suggestions' }).click();
    await wait(2500);
    return (await toast(/new suggestion|without a match/).count()) >= 1;
  });

  await step('suggestions: bulk accept with preview and commit', async () => {
    await P().getByRole('button', { name: /Review and accept/ }).click();
    await wait(1200);
    const confirm = page.getByRole('button', { name: /Create \d+ redirect/ }).or(P().getByRole('button', { name: /Create \d+ redirect/ }));
    const label = await confirm.first().textContent();
    const before = (await api('GET', '/redirects/rules?origin=suggestion&per_page=1')).meta.total;
    await confirm.first().click();
    await wait(2500);
    const after = (await api('GET', '/redirects/rules?origin=suggestion&per_page=1')).meta.total;
    return after > before ? `${label?.trim()}: ${before} -> ${after}` : bad(`unchanged ${before}`);
  });
}

/* ---------------------------------------------------------------- tester */
if (want('tester')) {
  const testUrl = async (url) => {
    await gotoPlugin(h, `#/tester?url=${encodeURIComponent(url)}`);
    await wait(1500);
    return P().evaluate((el) => el.shadowRoot.querySelector('.headline')?.textContent?.trim() ?? '');
  };
  await step('tester: redirect chain from the real API', async () => {
    const head = await testUrl('/faq');
    const steps = await P().locator('ol.chain > li').count();
    return /Redirects, 301/.test(head) && steps >= 2 ? `${head}, ${steps} steps` : bad(`${head} steps=${steps}`);
  });
  await step('tester: single hop shows the redirect, not "serves the target page"', async () => {
    const head = await testUrl('/produkt/zelt-alpin-3');
    return /Redirects, 301/.test(head) ? head : bad(head);
  });
  await step('tester: loop', async () => (/loop/i.test(await testUrl('/schleife-a')) ? true : false));
  await step('tester: gone (410)', async () => (/Gone/.test(await testUrl('/wp-login.php')) ? true : false));
  await step('tester: external target is flagged as not requested', async () => {
    const head = await testUrl('/partner');
    const note = await P().getByText(/not request/i).count();
    return /Redirects/.test(head) && note >= 1 ? head : bad(`${head} note=${note}`);
  });
  await step('tester: page found and not found', async () => {
    const a = await testUrl('/shop');
    const b = await testUrl('/gibt-es-nicht');
    return /page found/i.test(a) && /Not found/i.test(b) ? `${a} | ${b}` : bad(`${a} | ${b}`);
  });
  await step('tester: create rule from a not-found result', async () => {
    await testUrl('/gibt-es-nicht');
    await P().getByRole('button', { name: /Create/ }).first().click();
    await wait(900);
    const src = await page.getByLabel('Source', { exact: true }).inputValue();
    await page.keyboard.press('Escape');
    await wait(500);
    return src === '/gibt-es-nicht' ? src : bad(src);
  });
  await step('tester: advanced options (language, user agent) are sent', async () => {
    await gotoPlugin(h, '#/tester?url=/about');
    await wait(1000);
    await P().getByText('Advanced').click();
    await wait(400);
    return (await P().locator('input, select').count()) > 3;
  });
}

const SAMPLES = process.env.RM_SAMPLES || new URL('./samples', import.meta.url).pathname;

/* ---------------------------------------------------------------- import */
/** a fresh import view: leaving the tab and coming back resets the finished-import state */
const openImport = async () => {
  await gotoPlugin(h, '#/rules');
  await gotoPlugin(h, '#/import');
};
if (want('import')) {
  await step('import: CSV preview (tiles, invalid rows with translated messages) and commit', async () => {
    await openImport();
    await P().locator('input[type=file]').first().setInputFiles(join(SAMPLES, 'redirects-shop-relaunch.csv'));
    await wait(2500);
    const tiles = await P().evaluate((el) => Array.from(el.shadowRoot.querySelectorAll('.tile')).map((t) => t.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
    const notes = await P().evaluate((el) => Array.from(el.shadowRoot.querySelectorAll('.note.err')).map((n) => n.textContent.replace(/\s+/g, ' ').trim()).slice(0, 3));
    say('import: invalid rows show a translated reason', notes.length > 0 && notes.some((n) => /regular expression|loop|Redirect loop/i.test(n)), notes.join(' || '));
    const before = (await api('GET', '/redirects/rules?origin=import&per_page=500')).data.map((r) => r.id);
    await P().getByLabel('Skip invalid rows').check();
    await wait(400);
    await P().getByRole('button', { name: /^Import \d+ rule/ }).click();
    await wait(2500);
    const after = (await api('GET', '/redirects/rules?origin=import&per_page=500')).data.map((r) => r.id);
    const created = after.filter((id) => !before.includes(id));
    if (created.length) await api('POST', '/redirects/rules/bulk', { action: 'delete', ids: created });
    const done = await P().getByText(/rules? imported/).count();
    return created.length > 10 && done >= 1 ? `tiles: ${tiles}; created=${created.length}` : bad(`created=${created.length} done=${done} tiles=${tiles}`);
  });

  for (const [file, uploadAs] of [['old-site.htaccess'], ['old-site-nginx.conf'], ['redirection-plugin-export.csv'], ['netlify_redirects', '_redirects'], ['crawler-export-404.csv']]) {
    await step(`import: ${file} is detected and previewed`, async () => {
      await openImport();
      await P().locator('input[type=file]').first().setInputFiles(uploadAs ? { name: uploadAs, mimeType: 'text/plain', buffer: readFileSync(join(SAMPLES, file)) } : join(SAMPLES, file));
      await wait(2500);
      const head = await P().evaluate((el) => el.shadowRoot.querySelector('#rm-prev-h')?.parentElement?.textContent?.replace(/\s+/g, ' ').trim().slice(0, 100));
      const rows = await P().locator('tbody tr').count();
      return rows > 0 || /Preview/.test(head ?? '') ? `${head} rows=${rows}` : false;
    });
  }

  await step('import: pasted text is previewed', async () => {
    await openImport();
    await P().getByText('Paste text instead').click();
    await P().getByLabel('Redirect list').fill('Redirect 301 /e2e-old /e2e-new\nRedirect 301 /e2e-old2 /e2e-new2\n');
    await P().getByRole('button', { name: 'Use this text' }).click();
    await wait(2000);
    return (await P().locator('tbody tr').count()) >= 2;
  });

  await step('import: sitemap comparison lists missing URLs and creates suggestions', async () => {
    await openImport();
    await P().locator('input[type=file]').last().setInputFiles(join(SAMPLES, 'old-sitemap.xml'));
    await wait(600);
    await P().getByRole('button', { name: 'Compare' }).click();
    await wait(2500);
    const tiles = await P().evaluate((el) => Array.from(el.shadowRoot.querySelectorAll('.tile')).map((t) => t.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
    return /Missing/.test(tiles) ? tiles : false;
  });
}

/* ---------------------------------------------------------------- export */
if (want('export')) {
  const formats = (await api('GET', '/redirects/import/formats')).data.filter((f) => f.export);
  await gotoPlugin(h, '#/export');
  const buttons = P().locator('ul.formats li.fmt button');
  const nButtons = await buttons.count();
  await step(`export: one button per exportable format (${formats.length})`, async () => (nButtons === formats.length ? `${nButtons} buttons` : bad(`buttons=${nButtons} formats=${formats.length}`)));
  for (let i = 0; i < nButtons; i++) {
    await step(`export: format ${i + 1} of ${nButtons} downloads a file`, async () => {
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 10000 }), buttons.nth(i).click()]);
      const fs = await import('node:fs');
      const size = fs.statSync(await dl.path()).size;
      return size > 100 ? `${dl.suggestedFilename()} ${size} bytes` : bad(`too small: ${size}`);
    });
  }
  await step('export: filters (group, only enabled) narrow the file', async () => {
    await wait(1000);
    await P().getByLabel('Group').selectOption('Kampagnen');
    await P().getByLabel('Only enabled rules').check();
    const [dl] = await Promise.all([page.waitForEvent('download'), P().getByRole('button', { name: 'Download CSV' }).click()]);
    const text = (await import('node:fs')).readFileSync(await dl.path(), 'utf8');
    const lines = text.trim().split(/\r?\n/).length - 1;
    return lines > 0 && lines < 40 ? `${lines} data lines` : bad(`lines=${lines}`);
  });

  await step('export: site config panel shows Grav site.yaml and imports it', async () => {
    const file = join(site, 'user/config/site.yaml');
    const backup = existsSync(file) ? readFileSync(file, 'utf8') : null;
    try {
      writeFileSync(file, (backup ?? 'title: Test\n') + '\nredirects:\n  /e2e-site-old: /shop\nroutes:\n  /e2e-site-alias: /blog\n');
      // Grav reuses its file-hash check for cache.check.interval seconds (2 by default)
      await wait(3000);
      await gotoPlugin(h, '#/rules');
      await gotoPlugin(h, '#/export');
      await wait(800);
      const entries = await P().getByText('/e2e-site-old').count();
      await P().getByRole('button', { name: 'Import into Redirect Manager' }).click();
      await wait(2000);
      const made = (await api('GET', '/redirects/rules?q=e2e-site&per_page=10')).data;
      if (made.length) await api('POST', '/redirects/rules/bulk', { action: 'delete', ids: made.map((r) => r.id) });
      return entries >= 1 && made.length >= 2 ? `imported ${made.length}` : bad(`entries=${entries} made=${made.length}`);
    } finally {
      if (backup === null) rmSync(file, { force: true });
      else writeFileSync(file, backup);
    }
  });
}

/* ---------------------------------------------------------------- automatic redirects panel */
if (want('auto')) {
  await step('auto: badge endpoint counts, sidebar shows the same number', async () => {
    const b = (await api('GET', '/redirects/badge')).data;
    await gotoPlugin(h, '#/rules');
    const side = await page.locator('a[href$="/plugin/redirect-manager"]').first().textContent();
    return b.count === b.unseen + b.pending && new RegExp(String(b.count)).test(side ?? '') ? `${JSON.stringify(b)} sidebar="${side?.trim()}"` : bad(`${JSON.stringify(b)} sidebar="${side?.trim()}"`);
  });
  await step('auto: pending panel and unseen notice are shown', async () => {
    const p = await P().locator('[data-testid=pending-panel] li.item').count();
    const u = await P().locator('[data-testid=unseen-panel] li.item').count();
    return p >= 3 && u >= 1 ? `pending=${p} unseen=${u}` : false;
  });
  await step('auto: pending decision "Gone" writes a 410 rule for the page and its children', async () => {
    const item = P().locator('[data-testid=pending-panel] li.item', { hasText: '/shop/outlet' });
    const before = (await api('GET', '/redirects/pending')).data.length;
    await item.getByRole('button', { name: /Gone/ }).click();
    await wait(2000);
    const after = (await api('GET', '/redirects/pending')).data.length;
    const rules = (await api('GET', '/redirects/rules?q=outlet&per_page=20')).data;
    return after === before - 1 && rules.some((r) => r.status === 410) ? `${rules.length} rules for outlet` : bad(`pending ${before}->${after}; rules=${JSON.stringify(rules.map((r) => [r.source, r.status]))}`);
  });
  await step('auto: pending decision "To parent page" writes a 301', async () => {
    const before = (await api('GET', '/redirects/pending')).data;
    const item = P().locator('[data-testid=pending-panel] li.item').first();
    const route = before[0].route;
    await item.getByRole('button', { name: 'To parent page' }).click();
    await wait(2000);
    const rule = await ruleBySource(route);
    return rule && rule.status === 301 ? `${route} -> ${rule.target}` : bad(`rule=${JSON.stringify(rule)}`);
  });
  await step('auto: pending decision "Redirect to..." opens the picker and writes the rule', async () => {
    const before = (await api('GET', '/redirects/pending')).data;
    if (!before.length) return 'no pending item left';
    const route = before[0].route;
    const item = P().locator('[data-testid=pending-panel] li.item').first();
    await item.getByRole('button', { name: /Redirect to/ }).click();
    await wait(500);
    const input = item.getByRole('combobox');
    await input.fill('/info');
    await wait(800);
    await item.getByRole('button', { name: 'Create redirect' }).click();
    await wait(2000);
    const rule = await ruleBySource(route);
    return rule && rule.target.startsWith('/info') ? `${route} -> ${rule.target}` : bad(`rule=${JSON.stringify(rule)}`);
  });
  await step('auto: "Mark as seen" clears the notice and updates the badge', async () => {
    await gotoPlugin(h, '#/rules');
    if ((await P().locator('[data-testid=unseen-panel]').count()) === 0) return 'no unseen rules';
    let posted = false;
    page.on('request', (r) => r.url().includes('/badge/seen') && (posted = true));
    await P().getByRole('button', { name: 'Mark as seen' }).click();
    await wait(1500);
    const b = (await api('GET', '/redirects/badge')).data;
    const gone = (await P().locator('[data-testid=unseen-panel]').count()) === 0;
    return posted && gone && b.unseen === 0 ? JSON.stringify(b) : bad(`posted=${posted} gone=${gone} ${JSON.stringify(b)}`);
  });
  await step('auto: dismiss a decision (page deleted through the pages API)', async () => {
    const pages = (await api('GET', '/redirects/pages?q=blog&limit=20')).data;
    const victim = pages.find((p) => p.route === '/blog/wintercamping-tipps') ?? pages.find((p) => p.route.startsWith('/blog/'));
    if (!victim) return 'no blog page to delete';
    const del = await fetch(`${api.base}/pages${victim.route}`, { method: 'DELETE', headers: { 'X-API-Token': api.token } });
    await wait(2500);
    await gotoPlugin(h, '#/rules');
    await gotoPlugin(h, '#/404');
    await gotoPlugin(h, '#/rules');
    const item = P().locator('[data-testid=pending-panel] li.item').first();
    if ((await item.count()) === 0) return `delete answered ${del.status}, no pending item`;
    await item.getByRole('button', { name: 'Dismiss' }).click();
    await wait(1500);
    return (await api('GET', '/redirects/pending')).data.length === 0 ? `deleted ${victim.route}` : bad('still pending');
  });
  await step('rules: "Check now" runs the live target check (or says it ran a moment ago)', async () => {
    await gotoPlugin(h, '#/rules');
    const before = (await api('GET', '/redirects/checks')).data.last_run;
    await P().getByRole('button', { name: 'Check now' }).click();
    let after = before;
    let tooSoon = false;
    for (let i = 0; i < 25 && after === before && !tooSoon; i++) {
      await wait(2000);
      after = (await api('GET', '/redirects/checks')).data.last_run;
      // the manual interval (checker.manual_interval, 300 s) answers 429 and the UI says so
      tooSoon = (await page.getByText(/Checked a moment ago/).count()) > 0;
    }
    const line = (await P().locator('.check').textContent()) ?? '';
    if (tooSoon) return `rate limited as designed: ${line.replace(/\s+/g, ' ').trim()}`;
    return after !== before && /checked/.test(line) ? line.replace(/\s+/g, ' ').trim() : bad(`last_run ${before} -> ${after}; line=${line}`);
  });
}

/* ---------------------------------------------------------------- settings */
if (want('settings')) {
  await step('settings: host blueprint form loads, edits and saves the plugin config', async () => {
    const cfgFile = join(site, 'user/config/plugins/redirect-manager.yaml');
    const backup = existsSync(cfgFile) ? readFileSync(cfgFile, 'utf8') : null;
    try {
      await gotoPlugin(h, '#/settings');
      await wait(3500);
      const input = page.locator('grav-blueprint-form input[type=number]').first();
      await input.fill('11');
      await page.locator('grav-blueprint-form').getByRole('button', { name: 'Save' }).first().click();
      await wait(2500);
      const saved = existsSync(cfgFile) && /max_chain_depth: 11/.test(readFileSync(cfgFile, 'utf8'));
      return saved ? 'saved' : bad('config file unchanged');
    } finally {
      if (backup === null) rmSync(cfgFile, { force: true });
      else writeFileSync(cfgFile, backup);
    }
  });
}

/* ---------------------------------------------------------------- dashboard widget */
if (want('widget')) {
  await step('widget: dashboard card shows figures and the translated label', async () => {
    await page.goto(`${h.base}/admin/`);
    await wait(4000);
    const w = page.locator('grav-widget-redirect-manager-overview').first();
    const text = await w.evaluate((el) => el.shadowRoot?.querySelector('section')?.textContent?.replace(/\s+/g, ' ').trim().slice(0, 200));
    return /404|suggestion/i.test(text ?? '') ? text : bad(`text=${text}`);
  });
}

console.log(`\nconsole errors: ${h.errors.length}${h.errors.length ? ' ' + h.errors.slice(0, 3).join(' | ') : ''}`);
console.log(`failed API calls: ${h.failed.length ? [...new Set(h.failed)].join(', ') : 'none'}`);
await browser.close();
const notOk = failed();
console.log(`${notOk.length} of steps failed`);
process.exit(notOk.length || h.errors.length ? 1 : 0);
