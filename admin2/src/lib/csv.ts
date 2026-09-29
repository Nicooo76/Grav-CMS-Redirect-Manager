/** Minimal RFC 4180 CSV helpers (client-side export of selected rules; CSV preview for the import mapping UI). */
import type { Rule } from './types';

export function csvCell(v: unknown): string {
  const s = v === null || v === undefined ? '' : String(v);
  return /[",\r\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

const RULE_COLUMNS = ['source', 'target', 'status', 'match_type', 'enabled', 'priority', 'group', 'tags', 'note'] as const;

export function rulesToCsv(list: Rule[]): string {
  const head = RULE_COLUMNS.join(',');
  const rows = list.map((r) =>
    RULE_COLUMNS.map((c) => {
      const v = (r as unknown as Record<string, unknown>)[c];
      return csvCell(Array.isArray(v) ? v.join(' ') : v);
    }).join(','),
  );
  return [head, ...rows].join('\r\n') + '\r\n';
}

/** Parses CSV text into rows of cells. Handles quotes, escaped quotes and CRLF. */
export function parseCsv(text: string, delimiter?: string): string[][] {
  const d = delimiter ?? detectDelimiter(text);
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = '';
  let inQ = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQ) {
      if (c === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else inQ = false;
      } else cell += c;
    } else if (c === '"') inQ = true;
    else if (c === d) {
      row.push(cell);
      cell = '';
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(cell);
      cell = '';
      if (row.length > 1 || row[0] !== '') rows.push(row);
      row = [];
    } else cell += c;
  }
  row.push(cell);
  if (row.length > 1 || row[0] !== '') rows.push(row);
  return rows;
}

export function detectDelimiter(text: string): string {
  const first = text.split(/\r?\n/, 1)[0] ?? '';
  const counts = [',', ';', '\t', '|'].map((d) => [d, first.split(d).length - 1] as const);
  counts.sort((a, b) => b[1] - a[1]);
  return counts[0][1] > 0 ? counts[0][0] : ',';
}
