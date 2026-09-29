import { test, expect } from '../support/test';
import { allRules, bulkBar, hostModal, ruleOf, selectRows, shownSources } from '../support/rules-page';

const trio = ['/a-zelte', '/b-rucksaecke', '/c-outlet'];

test.describe('bulk actions on selected rules', () => {
  test('selecting rows shows the bulk bar with the count; select all and clear selection', async ({ app }) => {
    await app.goto('#/rules');
    await expect(bulkBar(app)).toHaveCount(0);

    await selectRows(app, ...trio);
    await expect(bulkBar(app)).toBeVisible();
    await expect(bulkBar(app)).toContainText('3 rules selected');
    await expect(app.row('/a-zelte')).toHaveClass(/selected/);

    await app.row('/a-zelte').getByRole('checkbox').uncheck();
    await expect(bulkBar(app)).toContainText('2 rules selected');

    await bulkBar(app).getByRole('button', { name: 'Clear selection' }).click();
    await expect(bulkBar(app)).toHaveCount(0);
    await expect(app.rows.locator('input:checked')).toHaveCount(0);

    // the header checkbox selects every row of the page (the seed has 32 rules, all on one page)
    await app.root.getByRole('checkbox', { name: 'Select all rules on this page' }).check();
    await expect(bulkBar(app)).toContainText('32 rules selected');
    await app.root.getByRole('checkbox', { name: 'Select all rules on this page' }).uncheck();
    await expect(bulkBar(app)).toHaveCount(0);
  });

  test('shift-click selects a range of rows', async ({ app }) => {
    await app.goto('#/rules');
    const first = app.row('/produkt/zelt-alpin-3').getByRole('checkbox');
    await first.click();
    await app.rows.nth(3).getByRole('checkbox').click({ modifiers: ['Shift'] });
    await expect(bulkBar(app)).toContainText('4 rules selected');
    const sources = await shownSources(app);
    for (const s of sources.slice(0, 4)) await expect(app.row(s)).toHaveClass(/selected/);
    await expect(app.row(sources[4])).not.toHaveClass(/selected/);
  });

  test('Disable, then Enable several rules; the public site follows', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    await selectRows(app, ...trio);

    await bulkBar(app).getByRole('button', { name: 'Disable' }).click();
    await expect(app.toast('3 rules updated')).toBeVisible();
    await expect.poll(async () => (await allRules(api)).filter((r) => !r.enabled).map((r) => r.source).sort()).toEqual([...trio, '/aktion/winter'].sort());
    for (const s of trio) {
      await expect(app.row(s).getByRole('switch', { name: `Enabled: ${s}` })).not.toBeChecked();
      await expect(app.row(s)).toContainText('Disabled');
    }
    // the rest is untouched
    await expect(app.row('/d-blog').getByRole('switch')).toBeChecked();
    expect((await request.get(`${site.baseUrl}/a-zelte`, { maxRedirects: 0 })).status()).toBe(404);

    // the selection survives the change, so Enable works on the same three
    await expect(bulkBar(app)).toContainText('3 rules selected');
    await bulkBar(app).getByRole('button', { name: 'Enable' }).click();
    await expect.poll(async () => (await allRules(api)).filter((r) => !r.enabled).map((r) => r.source)).toEqual(['/aktion/winter']);
    for (const s of trio) await expect(app.row(s).getByRole('switch', { name: `Enabled: ${s}` })).toBeChecked();
    const res = await request.get(`${site.baseUrl}/a-zelte`, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(res.headers()['location']).toBe('/shop/zelte');
  });

  test('Change status menu sets the code of all selected rules', async ({ app, api, site, request }) => {
    await app.goto('#/rules');
    await selectRows(app, '/d-blog', '/e-info');

    await bulkBar(app).getByRole('button', { name: 'Change status' }).click();
    await app.root.getByRole('menuitem', { name: /^302 · / }).click();
    await expect(app.toast('2 rules updated')).toBeVisible();
    await expect.poll(async () => (await ruleOf(api, '/d-blog')).status).toBe(302);
    expect((await ruleOf(api, '/e-info')).status).toBe(302);
    expect((await ruleOf(api, '/f-faq')).status).toBe(301);
    await expect(app.row('/d-blog').getByRole('button', { name: /^Edit status code: 302/ })).toBeVisible();
    const res = await request.get(`${site.baseUrl}/e-info`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()['location']).toBe('/info');

    // and another code on the same selection
    await bulkBar(app).getByRole('button', { name: 'Change status' }).click();
    await app.root.getByRole('menuitem', { name: /^308 · / }).click();
    await expect.poll(async () => (await ruleOf(api, '/d-blog')).status).toBe(308);
    expect((await ruleOf(api, '/e-info')).status).toBe(308);
  });

  test('More > Set group: a host dialog, applied to the selection', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await selectRows(app, '/f-faq', '/g-kontakt');

    await bulkBar(app).getByRole('button', { name: 'More' }).click();
    await app.root.getByRole('menuitem', { name: 'Set group' }).click();
    const dialog = hostModal(page, 'Set group');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('Applies to 2 selected rules.');
    // the dialog belongs to the host, not to the plugin's shadow root
    await expect(app.root.getByRole('heading', { name: 'Set group' })).toHaveCount(0);

    await dialog.getByLabel('Group').fill('Bulk group');
    await dialog.getByRole('button', { name: 'Apply' }).click();
    await expect(dialog).toBeHidden();
    await expect(app.toast('2 rules updated')).toBeVisible();
    await expect.poll(async () => (await ruleOf(api, '/f-faq')).group).toBe('Bulk group');
    expect((await ruleOf(api, '/g-kontakt')).group).toBe('Bulk group');
    expect((await ruleOf(api, '/h-about')).group).toBe('');

    // the new group is now offered in the Group filter
    await app.root.getByRole('button', { name: 'Filters' }).click();
    await expect(app.root.getByRole('combobox', { name: 'Group' }).getByRole('option', { name: 'Bulk group' })).toHaveCount(1);
  });

  test('Set group with an empty value removes the group; Cancel changes nothing', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await selectRows(app, '/produkt/zelt-alpin-3');

    await bulkBar(app).getByRole('button', { name: 'More' }).click();
    await app.root.getByRole('menuitem', { name: 'Set group' }).click();
    // the dialog has an X button titled "Cancel" as well: the text button is the one with the text
    await hostModal(page, 'Set group').getByText('Cancel', { exact: true }).click();
    await expect(hostModal(page, 'Set group')).toBeHidden();
    expect((await ruleOf(api, '/produkt/zelt-alpin-3')).group).toBe('Shop');

    await bulkBar(app).getByRole('button', { name: 'More' }).click();
    await app.root.getByRole('menuitem', { name: 'Set group' }).click();
    await hostModal(page, 'Set group').getByRole('button', { name: 'Apply' }).click();
    await expect.poll(async () => (await ruleOf(api, '/produkt/zelt-alpin-3')).group).toBe('');
    // tags stay
    expect((await ruleOf(api, '/produkt/zelt-alpin-3')).tags).toContain('relaunch');
  });

  test('More > Add tag and Remove tag', async ({ app, page, api }) => {
    await app.goto('#/rules');
    await selectRows(app, '/a-zelte', '/produkt/zelt-alpin-3');

    await bulkBar(app).getByRole('button', { name: 'More' }).click();
    await app.root.getByRole('menuitem', { name: 'Add tag' }).click();
    const add = hostModal(page, 'Add tag');
    await add.getByLabel('Tag').fill('kampagne-2026');
    await add.getByRole('button', { name: 'Apply' }).click();
    await expect.poll(async () => (await ruleOf(api, '/a-zelte')).tags).toEqual(['kampagne-2026']);
    expect((await ruleOf(api, '/produkt/zelt-alpin-3')).tags).toEqual(expect.arrayContaining(['relaunch', 'kampagne-2026']));

    await bulkBar(app).getByRole('button', { name: 'More' }).click();
    await app.root.getByRole('menuitem', { name: 'Remove tag' }).click();
    const remove = hostModal(page, 'Remove tag');
    await remove.getByLabel('Tag').fill('kampagne-2026');
    await remove.getByRole('button', { name: 'Apply' }).click();
    await expect.poll(async () => (await ruleOf(api, '/a-zelte')).tags).toEqual([]);
    expect((await ruleOf(api, '/produkt/zelt-alpin-3')).tags).toEqual(['relaunch']);
  });

  test('bulk Delete removes the selection; Undo restores it with the same ids and priorities', async ({ app, api }) => {
    await app.goto('#/rules');
    const before = await allRules(api);
    const doomed = before.filter((r) => trio.includes(r.source));
    expect(doomed).toHaveLength(3);

    await selectRows(app, ...trio);
    await bulkBar(app).getByRole('button', { name: 'Delete' }).click();
    await expect(app.toast('Deleted 3 rules')).toBeVisible();
    for (const s of trio) await expect(app.row(s)).toHaveCount(0);
    await expect(bulkBar(app)).toHaveCount(0);
    await expect.poll(async () => (await allRules(api)).length).toBe(29);
    expect((await allRules(api)).map((r) => r.source)).not.toContain('/a-zelte');

    await app.toasts.getByRole('button', { name: 'Undo' }).click();
    await expect(app.toast('Restored 3 rules')).toBeVisible();
    for (const s of trio) await expect(app.row(s)).toBeVisible();
    const after = await allRules(api);
    expect(after).toHaveLength(32);
    for (const d of doomed) {
      const back = after.find((r) => r.id === d.id);
      expect(back, `restored ${d.source}`).toMatchObject({ source: d.source, target: d.target, status: d.status, priority: d.priority, group: d.group, enabled: d.enabled });
    }
    // the order is the old one again
    expect(after.map((r) => r.id)).toEqual(before.map((r) => r.id));
  });

  test('bulk Delete without Undo stays deleted after a reload', async ({ app, api }) => {
    await app.goto('#/rules');
    await selectRows(app, '/e-info', '/f-faq');
    await bulkBar(app).getByRole('button', { name: 'Delete' }).click();
    await expect(app.toast('Deleted 2 rules')).toBeVisible();
    await expect.poll(async () => (await allRules(api)).length).toBe(30);
    await app.reload();
    await expect(app.rows).toHaveCount(30);
    await expect(app.row('/e-info')).toHaveCount(0);
  });
});
