/** Pure helpers for the import preview and the export result: filtering, counts, request bodies, safe text. */
import type { ImportOptions, ImportPreview, ImportRow } from './types';

export type RowFilter = 'all' | 'errors' | 'warnings' | 'duplicates' | 'valid';

/** Rows shown in the preview table at most. */
export const ROW_CAP = 500;

export const isDuplicate = (r: ImportRow): boolean => !!r.duplicate_of || r.duplicate_in_file;

export function rowMatches(r: ImportRow, f: RowFilter): boolean {
  switch (f) {
    case 'errors':
      return r.errors.length > 0;
    case 'warnings':
      return r.warnings.length > 0;
    case 'duplicates':
      return isDuplicate(r);
    case 'valid':
      return r.errors.length === 0;
    default:
      return true;
  }
}

/** How many rows each filter would show. */
export function filterCounts(rows: readonly ImportRow[]): Record<RowFilter, number> {
  const out: Record<RowFilter, number> = { all: rows.length, errors: 0, warnings: 0, duplicates: 0, valid: 0 };
  for (const r of rows) {
    if (r.errors.length) out.errors++;
    else out.valid++;
    if (r.warnings.length) out.warnings++;
    if (isDuplicate(r)) out.duplicates++;
  }
  return out;
}

/** Filtered rows, cut at `cap`. `total` is the number before cutting. */
export function visibleRows(rows: readonly ImportRow[], f: RowFilter, cap = ROW_CAP): { rows: ImportRow[]; total: number } {
  const all = f === 'all' ? rows : rows.filter((r) => rowMatches(r, f));
  return { rows: all.length > cap ? all.slice(0, cap) : (all as ImportRow[]), total: all.length };
}

/**
 * How many rules a commit would create with the current switches.
 * Exact when the preview lists every row; otherwise estimated from the counts.
 */
export function importCount(p: ImportPreview, skipDuplicates: boolean, skipInvalid: boolean): number {
  const c = p.counts;
  if (p.rows.length >= c.total) {
    return p.rows.filter((r) => (skipInvalid ? r.errors.length === 0 : true) && !(skipDuplicates && isDuplicate(r))).length;
  }
  const base = skipInvalid ? c.valid : c.total;
  return Math.max(0, base - (skipDuplicates ? c.duplicates : 0));
}

export interface ImportRequestInput {
  content: string;
  filename: string;
  /** only set when the user overrides the detection */
  format: string;
  /** CSV column mapping etc., already built */
  csv?: { columns?: Record<string, number>; delimiter?: string; has_header: boolean } | null;
  defaultGroup: string;
  defaultStatus: string;
}

/** Body for POST /redirects/import/preview (and commit, plus the skip flags). */
export function buildImportBody(i: ImportRequestInput): Record<string, unknown> {
  const options: ImportOptions & Record<string, unknown> = {};
  if (i.csv) Object.assign(options, i.csv);
  const group = i.defaultGroup.trim();
  if (group) options.default_group = group;
  const status = Number(i.defaultStatus);
  if (Number.isFinite(status) && status > 0) options.default_status = status;
  return {
    content: i.content,
    ...(i.filename ? { filename: i.filename } : {}),
    ...(i.format ? { format: i.format } : {}),
    options,
  };
}

/** One line of text for an export `skipped` entry, whatever shape the backend sent. */
export function describeSkipped(item: unknown): string {
  if (item === null || item === undefined) return '';
  if (typeof item === 'string') return item;
  if (typeof item === 'number' || typeof item === 'boolean') return String(item);
  if (typeof item === 'object') {
    const o = item as Record<string, unknown>;
    const text = [o.reason, o.message, o.detail, o.code].find((v) => typeof v === 'string' && v !== '') as string | undefined;
    const who = [o.source, o.rule_id, o.id].find((v) => typeof v === 'string' && v !== '') as string | undefined;
    if (who && text) return `${who}: ${text}`;
    if (text || who) return (text ?? who) as string;
    try {
      return JSON.stringify(item).slice(0, 200);
    } catch {
      return '';
    }
  }
  return '';
}

/** Up to `max` readable lines and how many were left out. */
export function skippedLines(skipped: readonly unknown[], max = 10): { lines: string[]; more: number } {
  const lines = skipped.map(describeSkipped).filter(Boolean);
  return { lines: lines.slice(0, max), more: Math.max(0, skipped.length - max) };
}

const EXPORT_ORDER = ['csv', 'json', 'yaml', 'htaccess', 'nginx', 'cloudflare_csv', 'netlify', 'grav_site'];

/** Export formats in a stable, familiar order: the well-known ones first, then the rest as sent. */
export function sortExportFormats<T extends { id: string; export: boolean }>(formats: readonly T[]): T[] {
  const list = formats.filter((f) => f.export);
  const rank = (id: string) => {
    const i = EXPORT_ORDER.indexOf(id);
    return i === -1 ? EXPORT_ORDER.length : i;
  };
  return list.map((f, i) => [f, i] as const).sort((a, b) => rank(a[0].id) - rank(b[0].id) || a[1] - b[1]).map(([f]) => f);
}

/** `source -> target` pairs of a site.yaml map, in file order. */
export function mapEntries(map: Record<string, string> | null | undefined): [string, string][] {
  if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
  return Object.entries(map).map(([k, v]) => [k, typeof v === 'string' ? v : String(v ?? '')]);
}

/** Standard base64 of raw bytes, in chunks so large files do not blow the call stack. */
export function bytesToBase64(bytes: Uint8Array): string {
  let bin = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) bin += String.fromCharCode(...bytes.subarray(i, i + chunk));
  return btoa(bin);
}
