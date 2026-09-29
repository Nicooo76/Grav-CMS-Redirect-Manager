import { expect, type Locator, type Page } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { parse, stringify } from 'yaml';
import type { ApiCall } from './api';
import type { App } from './app';
import { dataDir } from './site';

/** The fields of a rule the specs look at (the API returns more). */
export interface ApiRule {
  id: string;
  source: string;
  target: string;
  status: number;
  priority: number;
  enabled: boolean;
  match_type: string;
  group: string;
  tags: string[];
  origin: string;
  badges: string[];
  issues?: Array<{ code: string; severity: string; params?: Record<string, unknown> }>;
}

/** Every rule, in the order the list shows by default (priority, high to low). */
export async function allRules(api: ApiCall, query = ''): Promise<ApiRule[]> {
  const res = await api('GET', `/redirects/rules?per_page=500&sort=priority&dir=desc${query}`);
  expect(res.status).toBe(200);
  return res.data;
}

/** The first rule with exactly this source. */
export async function ruleOf(api: ApiCall, source: string): Promise<ApiRule> {
  const found = (await allRules(api)).find((r) => r.source === source);
  if (!found) throw new Error(`No rule with the source ${source}`);
  return found;
}

export async function ruleById(api: ApiCall, id: string): Promise<ApiRule> {
  const res = await api('GET', `/redirects/rules/${id}`);
  expect(res.status).toBe(200);
  return res.data;
}

/** The sources of the rows the list shows right now, top to bottom. */
export async function shownSources(app: App): Promise<string[]> {
  return app.rows.getByRole('link').allTextContents();
}

/** The Bulk actions toolbar (only there while rows are selected). */
export function bulkBar(app: App): Locator {
  return app.root.getByRole('toolbar', { name: 'Bulk actions' });
}

/** Ticks the checkboxes of these rules. */
export async function selectRows(app: App, ...sources: string[]): Promise<void> {
  for (const source of sources) await app.row(source).getByRole('checkbox', { name: `Select rule ${source}` }).check();
}

/** Opens the "Actions for <source>" menu of a row. */
export async function openRowMenu(app: App, source: string): Promise<void> {
  await app.row(source).getByRole('button', { name: `Actions for ${source}` }).click();
}

/** The drag handle of a row. */
export function handleOf(app: App, source: string): Locator {
  return app.row(source).getByRole('button', { name: `Move ${source}. Drag, or use the arrow keys.` });
}

/**
 * A confirm or form dialog of Admin 2 (the host). It is plain markup in the light DOM without dialog roles, so it is
 * found by its heading.
 */
export function hostModal(page: Page, title: string): Locator {
  return page.locator('body > div').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

/** The list settled: no request in flight, row count as expected. */
export async function expectRowCount(app: App, n: number): Promise<void> {
  await expect(app.rows).toHaveCount(n);
  await expect(app.root.locator('[aria-busy="true"]')).toHaveCount(0);
}

/**
 * Makes rules look old by rewriting `created_at` in rules.yaml (the API cannot set it): "unused for N days" only counts
 * rules that are older than N days. The plugin re-reads the file when it changes.
 */
export function ageRules(siteDir: string, sources: string[], days: number): void {
  const file = join(dataDir(siteDir), 'rules.yaml');
  const doc = parse(readFileSync(file, 'utf8')) as { rules: Array<Record<string, unknown>> };
  const past = new Date(Date.now() - days * 86_400_000).toISOString().replace(/\.\d+Z$/, '+00:00');
  for (const rule of doc.rules) if (sources.includes(String(rule.source))) rule.created_at = past;
  writeFileSync(file, stringify(doc));
}
