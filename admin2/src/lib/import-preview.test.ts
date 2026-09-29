import { describe, expect, it } from 'vitest';
import {
  ROW_CAP,
  buildImportBody,
  bytesToBase64,
  describeSkipped,
  filterCounts,
  importCount,
  mapEntries,
  rowMatches,
  skippedLines,
  sortExportFormats,
  visibleRows,
} from './import-preview';
import type { ImportPreview, ImportRow } from './types';

const issue = (message = 'x') => ({ code: 'c', message });
function row(line: number, over: Partial<ImportRow> = {}): ImportRow {
  return { line, raw: '', rule: null, errors: [], warnings: [], duplicate_of: null, duplicate_in_file: false, ...over };
}
const rows: ImportRow[] = [
  row(1),
  row(2, { errors: [issue()] }),
  row(3, { warnings: [issue()] }),
  row(4, { duplicate_of: 'r1' }),
  row(5, { duplicate_in_file: true, warnings: [issue()] }),
  row(6, { errors: [issue()], duplicate_of: 'r2' }),
];

function preview(over: Partial<ImportPreview> = {}): ImportPreview {
  return {
    format: 'csv',
    counts: { total: rows.length, valid: 4, errors: 2, duplicates: 3, warnings: 2, skipped: 0, not_found: 0 },
    errors: [],
    warnings: [],
    rows,
    not_found_paths: [],
    ...over,
  };
}

describe('row filters', () => {
  it('matches each filter', () => {
    expect(rows.filter((r) => rowMatches(r, 'errors')).map((r) => r.line)).toEqual([2, 6]);
    expect(rows.filter((r) => rowMatches(r, 'warnings')).map((r) => r.line)).toEqual([3, 5]);
    expect(rows.filter((r) => rowMatches(r, 'duplicates')).map((r) => r.line)).toEqual([4, 5, 6]);
    expect(rows.filter((r) => rowMatches(r, 'valid')).map((r) => r.line)).toEqual([1, 3, 4, 5]);
    expect(rows.filter((r) => rowMatches(r, 'all'))).toHaveLength(6);
  });
  it('counts per filter', () => {
    expect(filterCounts(rows)).toEqual({ all: 6, errors: 2, warnings: 2, duplicates: 3, valid: 4 });
    expect(filterCounts([])).toEqual({ all: 0, errors: 0, warnings: 0, duplicates: 0, valid: 0 });
  });
  it('caps the visible rows and reports the total', () => {
    const many = Array.from({ length: ROW_CAP + 40 }, (_, i) => row(i + 1));
    const v = visibleRows(many, 'all');
    expect(v.rows).toHaveLength(ROW_CAP);
    expect(v.total).toBe(ROW_CAP + 40);
    expect(visibleRows(rows, 'errors')).toMatchObject({ total: 2 });
  });
});

describe('importCount', () => {
  it('skips invalid and duplicate rows by default', () => {
    // valid non-duplicate rows: 1 and 3
    expect(importCount(preview(), true, true)).toBe(2);
  });
  it('keeps duplicates when asked to', () => {
    expect(importCount(preview(), false, true)).toBe(4);
  });
  it('counts invalid rows too when they are not skipped', () => {
    expect(importCount(preview(), false, false)).toBe(6);
    expect(importCount(preview(), true, false)).toBe(3);
  });
  it('estimates from the counts when the preview lists fewer rows', () => {
    const p = preview({ rows: rows.slice(0, 2), counts: { total: 100, valid: 90, errors: 10, duplicates: 15, warnings: 0, skipped: 0, not_found: 0 } });
    expect(importCount(p, true, true)).toBe(75);
    expect(importCount(p, false, true)).toBe(90);
    expect(importCount(p, false, false)).toBe(100);
  });
  it('never goes negative', () => {
    const p = preview({ rows: [], counts: { total: 3, valid: 1, errors: 2, duplicates: 5, warnings: 0, skipped: 0, not_found: 0 } });
    expect(importCount(p, true, true)).toBe(0);
  });
});

describe('buildImportBody', () => {
  const base = { content: 'a', filename: '', format: '', csv: null, defaultGroup: '', defaultStatus: '' };
  it('sends the minimum by default', () => {
    expect(buildImportBody(base)).toEqual({ content: 'a', options: {} });
  });
  it('adds filename, override, csv options and defaults', () => {
    const body = buildImportBody({
      content: 'a',
      filename: 'r.csv',
      format: 'csv',
      csv: { columns: { source: 0, target: 1 }, delimiter: ';', has_header: true },
      defaultGroup: ' Legacy ',
      defaultStatus: '302',
    });
    expect(body).toEqual({
      content: 'a',
      filename: 'r.csv',
      format: 'csv',
      options: { columns: { source: 0, target: 1 }, delimiter: ';', has_header: true, default_group: 'Legacy', default_status: 302 },
    });
  });
  it('ignores a bogus default status', () => {
    expect(buildImportBody({ ...base, defaultStatus: 'abc' }).options).toEqual({});
  });
});

describe('describeSkipped', () => {
  it('prints strings as they are', () => {
    expect(describeSkipped('regex rule /a/(.*)')).toBe('regex rule /a/(.*)');
  });
  it('reads the backend note shape', () => {
    expect(describeSkipped({ rule_id: 'r1', code: 'regex_not_representable', reason: 'Regex is not supported.' })).toBe('r1: Regex is not supported.');
    expect(describeSkipped({ source: '/a', message: 'no' })).toBe('/a: no');
    expect(describeSkipped({ code: 'only_code' })).toBe('only_code');
  });
  it('survives odd input', () => {
    expect(describeSkipped(null)).toBe('');
    expect(describeSkipped(42)).toBe('42');
    expect(describeSkipped({ a: 1 })).toBe('{"a":1}');
    expect(describeSkipped({ a: '<b>x</b>' })).toBe('{"a":"<b>x</b>"}');
  });
  it('limits the list', () => {
    const many = Array.from({ length: 14 }, (_, i) => `s${i}`);
    const r = skippedLines(many);
    expect(r.lines).toHaveLength(10);
    expect(r.more).toBe(4);
    expect(skippedLines(['a', ''])).toEqual({ lines: ['a'], more: 0 });
  });
});

describe('sortExportFormats', () => {
  it('drops import-only formats and orders the known ones', () => {
    const list = [
      { id: 'netlify', export: true },
      { id: 'x_import', export: false },
      { id: 'zzz', export: true },
      { id: 'csv', export: true },
      { id: 'grav_site', export: true },
      { id: 'aaa', export: true },
    ];
    expect(sortExportFormats(list).map((f) => f.id)).toEqual(['csv', 'netlify', 'grav_site', 'zzz', 'aaa']);
  });
});

describe('misc', () => {
  it('mapEntries keeps file order and tolerates junk', () => {
    expect(mapEntries({ '/a': '/b', '/c': '/d' })).toEqual([['/a', '/b'], ['/c', '/d']]);
    expect(mapEntries(null)).toEqual([]);
    expect(mapEntries([] as unknown as Record<string, string>)).toEqual([]);
  });
  it('bytesToBase64 matches btoa for large input', () => {
    const bytes = new Uint8Array(100000).map((_, i) => i % 251);
    let bin = '';
    for (const b of bytes) bin += String.fromCharCode(b);
    expect(bytesToBase64(bytes)).toBe(btoa(bin));
  });
  it('rowMatches valid excludes error rows', () => {
    expect(rowMatches(row(1, { errors: [issue()] }), 'valid')).toBe(false);
  });
});
