/**
 * axe-core over every tab of the plugin page in the real Admin 2, light and dark, limited to the plugin's element
 * (the host has its own checks). Fails on serious or critical violations.
 *   node e2e/real-axe.mjs
 */
import AxeBuilder from '@axe-core/playwright';
import { launch, openAdmin, gotoPlugin, reporter } from './lib/real.mjs';

const { say, failed } = reporter();
const browser = await launch();
for (const theme of ['light', 'dark']) {
  const h = await openAdmin(browser, { theme });
  for (const [name, hash] of [['rules', '#/rules'], ['rules-editor', '#/rules/new'], ['404', '#/404'], ['suggestions', '#/suggestions'], ['tester', '#/tester?url=/faq'], ['import', '#/import'], ['export', '#/export'], ['settings', '#/settings']]) {
    await gotoPlugin(h, hash);
    await h.page.waitForTimeout(name === 'settings' ? 3000 : 1500);
    const res = await new AxeBuilder({ page: h.page }).include('grav-redirect-manager--page').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const bad = res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    say(`[${theme}] axe ${name}`, bad.length === 0, bad.map((v) => `${v.id} (${v.nodes.length}) ${v.nodes[0]?.target?.join(' ')}`).join(' | ') || `${res.violations.length} minor`);
  }
  say(`[${theme}] no console errors`, h.errors.length === 0, h.errors.slice(0, 2).join(' | '));
  await h.ctx.close();
}
await browser.close();
process.exit(failed().length ? 1 : 0);
