/** CSV column mapping for the import screen: guess, edit, and turn into `options.columns`. */

export const MAP_FIELDS = ['source', 'target', 'status', 'match_type', 'group', 'note'] as const;
export type MapField = (typeof MAP_FIELDS)[number];
/** What a column is used for; 'ignore' leaves it out. */
export type ColumnRole = MapField | 'ignore';

export type DelimiterChoice = 'auto' | 'comma' | 'semicolon' | 'tab';

/** Header names per field, in the same spirit as the server's synonym list (English and German). */
const SYNONYMS: Record<MapField, string[]> = {
  source: ['source', 'from', 'old', 'url', 'quelle', 'alt', 'old_url', 'oldurl', 'source_url', 'alte_url', 'request', 'request_url', 'address', 'origin'],
  target: ['target', 'to', 'new', 'destination', 'ziel', 'neu', 'target_url', 'new_url', 'newurl', 'redirect_to', 'redirect', 'neue_url', 'ziel_url'],
  status: ['status', 'code', 'status_code', 'statuscode', 'http_code', 'http_status', 'type'],
  match_type: ['match_type', 'match', 'matchtype', 'mode'],
  group: ['group', 'gruppe', 'category', 'kategorie'],
  note: ['note', 'notiz', 'notes', 'comment', 'kommentar', 'description'],
};

const STATUS_CODES = new Set(['200', '301', '302', '303', '307', '308', '410', '451']);
const MATCH_TYPES = new Set(['exact', 'wildcard', 'regex']);

const headerKey = (s: string) => s.trim().toLowerCase().replace(/[\s-]+/g, '_');
const looksLikeUrl = (s: string) => /^(\/|https?:\/\/)/i.test(s.trim());
const looksLikeStatus = (s: string) => STATUS_CODES.has(s.trim());
const knownHeader = (s: string) => {
  const k = headerKey(s);
  return MAP_FIELDS.some((f) => SYNONYMS[f].includes(k)) || k === 'regex' || k === 'enabled' || k === 'priority';
};

/** True when the first row reads like column titles rather than data. */
export function guessHasHeader(rows: readonly (readonly string[])[]): boolean {
  const first = rows[0];
  if (!first || first.length === 0) return false;
  if (first.some((c) => looksLikeUrl(c) || looksLikeStatus(c))) return false;
  if (first.some(knownHeader)) return true;
  // titles we do not know: a header when the next row has data-looking cells and this one does not
  const second = rows[1];
  return !!second && second.some((c) => looksLikeUrl(c));
}

/** Width of the widest row. */
export function columnCount(rows: readonly (readonly string[])[]): number {
  return rows.reduce((n, r) => Math.max(n, r.length), 0);
}

function guessByHeader(header: readonly string[]): ColumnRole[] {
  const out: ColumnRole[] = header.map(() => 'ignore');
  const taken = new Set<MapField>();
  header.forEach((h, i) => {
    const k = headerKey(h);
    for (const f of MAP_FIELDS) {
      if (!taken.has(f) && SYNONYMS[f].includes(k)) {
        out[i] = f;
        taken.add(f);
        break;
      }
    }
  });
  return out;
}

/** Guess by what the cells hold: URLs are source then target, 3-digit codes are the status, and so on. */
function guessByValues(data: readonly (readonly string[])[], width: number, out: ColumnRole[]): ColumnRole[] {
  const taken = new Set<MapField>(out.filter((r): r is MapField => r !== 'ignore'));
  const share = (col: number, test: (s: string) => boolean) => {
    const cells = data.map((r) => r[col] ?? '').filter((c) => c.trim() !== '');
    return cells.length ? cells.filter(test).length / cells.length : 0;
  };
  for (let i = 0; i < width; i++) {
    if (out[i] !== 'ignore') continue;
    if (!taken.has('status') && share(i, looksLikeStatus) >= 0.8) {
      out[i] = 'status';
      taken.add('status');
    } else if (!taken.has('match_type') && share(i, (s) => MATCH_TYPES.has(s.trim().toLowerCase())) >= 0.8) {
      out[i] = 'match_type';
      taken.add('match_type');
    } else if (share(i, looksLikeUrl) >= 0.6) {
      const f: MapField | null = !taken.has('source') ? 'source' : !taken.has('target') ? 'target' : null;
      if (f) {
        out[i] = f;
        taken.add(f);
      }
    }
  }
  return out;
}

/** One role per column. Header names win, values fill the gaps, and source/target fall back to columns 1 and 2. */
export function guessMapping(rows: readonly (readonly string[])[], hasHeader: boolean): ColumnRole[] {
  const width = columnCount(rows);
  if (width === 0) return [];
  const data = hasHeader ? rows.slice(1) : rows;
  let roles: ColumnRole[] = hasHeader ? guessByHeader(Array.from({ length: width }, (_, i) => rows[0][i] ?? '')) : Array(width).fill('ignore');
  roles = guessByValues(data, width, roles);
  if (!roles.includes('source') && width > 0) {
    const i = roles.indexOf('ignore');
    if (i !== -1) roles[i] = 'source';
  }
  if (!roles.includes('target') && width > 1) {
    const i = roles.indexOf('ignore');
    if (i !== -1) roles[i] = 'target';
  }
  return roles;
}

/** Sets one column's role. A field can sit on one column only, so the previous holder is set to ignore. */
export function assignRole(roles: readonly ColumnRole[], index: number, role: ColumnRole): ColumnRole[] {
  return roles.map((r, i) => (i === index ? role : role !== 'ignore' && r === role ? 'ignore' : r));
}

/** `options.columns` for the API: field -> 0-based column index; ignored columns are left out. */
export function buildColumns(roles: readonly ColumnRole[]): Record<string, number> {
  const out: Record<string, number> = {};
  roles.forEach((r, i) => {
    if (r !== 'ignore' && !(r in out)) out[r] = i;
  });
  return out;
}

/** What is missing before the mapping can work: source and target are required. */
export function missingFields(roles: readonly ColumnRole[]): MapField[] {
  return (['source', 'target'] as const).filter((f) => !roles.includes(f));
}

const DELIMITERS: Record<DelimiterChoice, string | undefined> = { auto: undefined, comma: ',', semicolon: ';', tab: '\t' };

/** The character the API expects for `options.delimiter` (undefined = let the server detect). */
export function delimiterChar(choice: DelimiterChoice): string | undefined {
  return DELIMITERS[choice];
}

export interface CsvOptions {
  columns?: Record<string, number>;
  delimiter?: string;
  has_header: boolean;
}

/** The CSV part of `options` for preview and commit. */
export function buildCsvOptions(roles: readonly ColumnRole[], delimiter: DelimiterChoice, hasHeader: boolean): CsvOptions {
  const columns = buildColumns(roles);
  const d = delimiterChar(delimiter);
  return {
    ...(Object.keys(columns).length ? { columns } : {}),
    ...(d ? { delimiter: d } : {}),
    has_header: hasHeader,
  };
}
