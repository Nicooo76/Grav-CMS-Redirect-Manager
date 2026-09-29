import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { parse, stringify } from 'yaml';
import { join } from 'node:path';
import { apiClient } from './api';
import { dataDir, defaultPluginConfig, gravBin, pluginCli, writePluginConfig } from './site';

export const BROWSER_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
export const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
export const MONITOR_UA = 'UptimeRobot/2.0; http://www.uptimerobot.com/';

/** Hits on rules, as [path, count]: what the "hits" column and the sort show. */
export const RULE_HITS: Array<[string, number]> = [
  ['/produkt/zelt-alpin-3', 6],
  ['/news', 4],
  ['/faq-alt', 3],
  ['/produkte/regenjacken', 2],
  ['/ueber-uns', 1],
];

/** 404 requests, as [path, count, user agent, referer]. Fixed, so the monitor shows the same numbers every run. */
export const NOT_FOUND: Array<[string, number, string, string?]> = [
  ['/shop/rucksack', 12, BROWSER_UA, 'https://www.google.com/'],
  ['/blog/wintercamping-tips', 7, BROWSER_UA],
  ['/shop/Zelte', 4, BROWSER_UA],
  ['/Info', 3, BROWSER_UA],
  ['/shop/Rucksaecke', 2, BROWSER_UA],
  ['/about-us', 5, BROWSER_UA, 'https://example.org/links'],
  ['/kontakt/formular', 3, BROWSER_UA],
  ['/gibt-es-nicht', 2, BROWSER_UA],
  ['/alter-pfad/ohne-ziel', 1, BROWSER_UA],
  ['/wp-admin/setup.php', 20, BOT_UA],
  ['/xmlrpc.php', 8, BOT_UA],
  ['/health-check-pfad', 6, MONITOR_UA],
];

async function hit(baseUrl: string, path: string, count: number, ua: string, referer?: string): Promise<void> {
  for (let i = 0; i < count; i++) {
    const res = await fetch(baseUrl + path, { redirect: 'manual', headers: { 'user-agent': ua, ...(referer ? { referer } : {}) } });
    await res.arrayBuffer();
  }
}

/**
 * The API and the importer refuse a redirect loop, but a hand-edited rules.yaml can contain one (the badge and the
 * quick filter are for exactly that). /schleife-a -> /schleife-b comes from the fixture, this closes the circle.
 */
function addLoopRule(siteDir: string): void {
  const file = join(dataDir(siteDir), 'rules.yaml');
  const doc = parse(readFileSync(file, 'utf8')) as { version: number; rules: Array<Record<string, unknown>> };
  const now = new Date().toISOString().replace(/\.\d+Z$/, '+00:00');
  doc.rules.push({
    id: 'r5eed0000000000loop',
    source: '/schleife-b',
    target: '/schleife-a',
    match_type: 'exact',
    status: 301,
    enabled: true,
    priority: 5,
    group: 'Ketten',
    origin: 'manual',
    created_at: now,
    updated_at: now,
  });
  writeFileSync(file, stringify(doc));
}

export function snapshotDir(workDir: string): string {
  return join(workDir, 'snapshot');
}

/** Rules, 404 entries, hits, suggestions: everything the tests start from. */
export async function seedSite(siteDir: string, baseUrl: string, workDir: string, fixture: string, admin: { username: string; password: string }): Promise<void> {
  writePluginConfig(siteDir);
  const imported = await pluginCli(siteDir, ['import', fixture, '--skip-invalid', '--json']);
  if (imported.code !== 0) throw new Error('Importing the seed rules failed: ' + (imported.stdout + imported.stderr).slice(0, 600));

  addLoopRule(siteDir);
  // the importer skips a second rule for the same source, the API accepts it (with a conflict warning)
  const api = await apiClient(baseUrl, admin);
  const conflict = await api('POST', '/redirects/rules', { source: '/konflikt', target: '/blog', match_type: 'exact', status: 302, priority: 120, group: 'Konflikte' });
  if (conflict.status !== 201) throw new Error('Creating the conflicting seed rule failed: HTTP ' + conflict.status);

  for (const [path, count] of RULE_HITS) await hit(baseUrl, path, count, BROWSER_UA);
  for (const [path, count, ua, referer] of NOT_FOUND) await hit(baseUrl, path, count, ua, referer);

  const job = await gravBin(siteDir, ['scheduler', '--run=redirect-manager-maintenance']);
  if (job.code !== 0) throw new Error('The maintenance job failed: ' + (job.stdout + job.stderr).slice(0, 600));
  const suggest = await pluginCli(siteDir, ['suggest', '--no-accept', '--json']);
  if (suggest.code !== 0) throw new Error('Generating suggestions failed: ' + (suggest.stdout + suggest.stderr).slice(0, 600));

  const snap = snapshotDir(workDir);
  rmSync(snap, { recursive: true, force: true });
  mkdirSync(snap, { recursive: true });
  cpSync(dataDir(siteDir), join(snap, 'data'), { recursive: true });
  cpSync(join(siteDir, 'user', 'pages'), join(snap, 'pages'), { recursive: true });
}

/** Back to the seeded state (data files and default plugin config), without restarting the server. */
export function restoreSeed(siteDir: string, workDir: string, config: Record<string, unknown> = defaultPluginConfig): void {
  const snap = join(snapshotDir(workDir), 'data');
  if (!existsSync(snap)) throw new Error('No seed snapshot in ' + workDir);
  rmSync(dataDir(siteDir), { recursive: true, force: true });
  cpSync(snap, dataDir(siteDir), { recursive: true });
  rmSync(join(siteDir, 'cache', 'redirect-manager'), { recursive: true, force: true });
  writePluginConfig(siteDir, config);
  // pages the tests deleted or moved
  rmSync(join(siteDir, 'user', 'pages'), { recursive: true, force: true });
  cpSync(join(snapshotDir(workDir), 'pages'), join(siteDir, 'user', 'pages'), { recursive: true });
}
