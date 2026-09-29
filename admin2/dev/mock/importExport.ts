/** Import parsers (csv, json, yaml, htaccess, nginx, netlify, ...) and exporters. Pure text in, text out. */
import type { ImportFormat, ImportIssue, ImportOptions, Rule, RuleInput } from '../../src/lib/types';
import { escapeRe } from './util';

export const FORMATS: ImportFormat[] = [
  { id: 'csv', label: 'CSV', import: true, export: true, extension: 'csv' },
  { id: 'json', label: 'JSON', import: true, export: true, extension: 'json' },
  { id: 'yaml', label: 'YAML', import: true, export: true, extension: 'yaml' },
  { id: 'htaccess', label: 'Apache .htaccess', import: true, export: true, extension: 'htaccess' },
  { id: 'nginx', label: 'Nginx', import: false, export: true, extension: 'conf' },
  { id: 'cloudflare_csv', label: 'Cloudflare Bulk Redirects (CSV)', import: false, export: true, extension: 'csv' },
  { id: 'netlify', label: 'Netlify _redirects', import: true, export: true, extension: '' },
  { id: 'grav_site', label: 'Grav site.yaml', import: true, export: true, extension: 'yaml' },
  { id: 'redirection_plugin', label: 'Redirection (WordPress)', import: true, export: false, extension: 'csv' },
  { id: 'yoast_csv', label: 'Yoast SEO Premium (CSV)', import: true, export: false, extension: 'csv' },
  { id: 'screaming_frog', label: 'Screaming Frog (Crawl-Export)', import: true, export: false, extension: 'csv' },
];

export type Draft = RuleInput & { source: string; target: string };
export interface ParsedRow {
  line: number;
  raw: string;
  input: Draft;
  issues: ImportIssue[];
}
export interface ParseResult {
  rows: ParsedRow[];
  skipped: number;
  notFound: string[];
  errors: ImportIssue[];
}

const issue = (code: string, message: string): ImportIssue => ({ code, message });
const unquote = (s: string) => s.replace(/^(['"])(.*)\1$/, '$2');
const splitWs = (s: string) => (s.match(/"[^"]*"|'[^']*'|\S+/g) ?? []).map(unquote);
const urlToPath = (s: string) => {
  if (!/^https?:\/\//i.test(s)) return s;
  try {
    const u = new URL(s);
    return u.pathname + u.search;
  } catch {
    return s;
  }
};
function parseStatus(s: string | undefined, dflt: number): number {
  const v = (s ?? '').trim().toLowerCase();
  if (!v) return dflt;
  if (/^\d+$/.test(v)) return Number(v);
  return { permanent: 301, temp: 302, redirect: 302, seeother: 303, gone: 410 }[v] ?? 0;
}
const autoType = (src: string): 'exact' | 'wildcard' | 'regex' => (src.startsWith('^') || /\(.*\)|\$$/.test(src) ? 'regex' : src.includes('*') ? 'wildcard' : 'exact');

/** `^/a\.html$` -> exact `/a.html`; anything with real regex syntax stays a regex. */
function patternToRule(p: string, ci: boolean): Pick<Draft, 'source' | 'match_type' | 'case_sensitive'> {
  const plain = p.replace(/^\^/, '').replace(/\$$/, '').replace(/\\([./-])/g, '$1');
  if (/^[\w\-./%~]*$/.test(plain)) return { source: plain.startsWith('/') ? plain : '/' + plain, match_type: 'exact', case_sensitive: !ci };
  return { source: p.startsWith('^') && !p.startsWith('^/') ? '^/' + p.slice(1) : p, match_type: 'regex', case_sensitive: !ci };
}
const lineOf = (text: string, idx: number) => text.slice(0, idx).split('\n').length;

/* ---- detection ------------------------------------------------------------------------------ */

export function detectFormat(content: string, filename = ''): string | null {
  const f = filename.toLowerCase();
  const c = content.trim();
  if (!c) return null;
  if (f.endsWith('.htaccess') || f === 'htaccess') return 'htaccess';
  if (f.endsWith('_redirects')) return 'netlify';
  if (f.endsWith('.json') || /^[[{]/.test(c)) return 'json';
  if (f.endsWith('.conf')) return 'nginx';
  if (/^\s*(RewriteRule|RedirectMatch|Redirect(Permanent|Temp)?\s)/im.test(c)) return 'htaccess';
  if (/^\s*(rewrite\s+\S+\s+\S+.*;|location\s+.*\{)/im.test(c)) return 'nginx';
  if (/^(redirects|routes):\s*$/m.test(c)) return 'grav_site';
  if (/^rules:\s*$/m.test(c)) return 'yaml';
  if (f.endsWith('.yaml') || f.endsWith('.yml') || /^(- |\S.*:\s+\S)/m.test(c) && !c.includes(',') && !c.includes(';')) return 'yaml';
  if (/^\s*\/\S+\s+\/?\S+(\s+\d{3}!?)?\s*$/m.test(c) && !/[,;\t]/.test(c.split('\n')[0]!)) return 'netlify';
  const head = c.split('\n')[0]!.toLowerCase();
  if (head.includes('address') && head.includes('status code')) return 'screaming_frog';
  if (head.includes('origin') && head.includes('target') && head.includes('type')) return 'yoast_csv';
  if (head.includes('source') && head.includes('regex')) return 'redirection_plugin';
  return 'csv';
}

/* ---- csv ------------------------------------------------------------------------------------ */

export function parseCsvText(text: string, delim: string): { line: number; cells: string[]; raw: string }[] {
  const rows: { line: number; cells: string[]; raw: string }[] = [];
  let cells: string[] = [];
  let cur = '';
  let q = false;
  let line = 1;
  let start = 1;
  let rawStart = 0;
  const push = (end: number) => {
    cells.push(cur);
    if (cells.some((x) => x.trim() !== '')) rows.push({ line: start, cells, raw: text.slice(rawStart, end) });
    cells = [];
    cur = '';
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i]!;
    if (q) {
      if (ch === '"' && text[i + 1] === '"') { cur += '"'; i++; }
      else if (ch === '"') q = false;
      else { cur += ch; if (ch === '\n') line++; }
    } else if (ch === '"') q = true;
    else if (ch === delim) { cells.push(cur); cur = ''; }
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      push(i);
      line++; start = line; rawStart = i + 1;
    } else cur += ch;
  }
  push(text.length);
  return rows;
}

const ALIAS: Record<string, string[]> = {
  source: ['source', 'from', 'origin', 'source_url', 'source url', 'old', 'old_url', 'old url', 'url', 'address', 'request'],
  target: ['target', 'to', 'destination', 'target_url', 'target url', 'new', 'new_url', 'redirect_url', 'redirect url'],
  status: ['status', 'status_code', 'status code', 'code', 'http_code', 'type'],
  match_type: ['match_type', 'match', 'matchtype'],
  regex: ['regex', 'is_regex'],
  group: ['group', 'category'],
  note: ['note', 'notes', 'comment', 'description'],
  enabled: ['enabled', 'active'],
  priority: ['priority'],
};
const looksHeader = (cells: string[]) => cells.every((c) => !/^(\/|https?:)/i.test(c.trim())) && cells.some((c) => Object.values(ALIAS).some((a) => a.includes(c.trim().toLowerCase())));

function parseCsv(text: string, format: string, o: ImportOptions): ParseResult {
  const first = text.split('\n').find((l) => l.trim()) ?? '';
  const delim = o.delimiter || ([',', ';', '\t'] as const).map((d) => [d, first.split(d).length] as const).sort((a, b) => b[1] - a[1])[0]![0];
  const all = parseCsvText(text, delim);
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  if (!all.length) return res;
  const header = o.has_header ?? looksHeader(all[0]!.cells);
  let cols: Record<string, number | null> = { source: 0, target: 1, status: 2 };
  if (o.columns && Object.keys(o.columns).length) cols = { ...o.columns };
  else if (header) {
    cols = {};
    all[0]!.cells.forEach((h, i) => {
      const n = h.trim().toLowerCase();
      for (const [field, names] of Object.entries(ALIAS)) if (names.includes(n) && cols[field] === undefined) cols[field] = i;
    });
  }
  const cell = (row: string[], f: string) => (cols[f] == null ? undefined : row[cols[f]!]?.trim());
  for (const row of header ? all.slice(1) : all) {
    const src = urlToPath(cell(row.cells, 'source') ?? '');
    if (format === 'screaming_frog') {
      const st = Number(cell(row.cells, 'status'));
      if (st === 404 || st === 410) res.notFound.push(src.split('?')[0]!);
      else res.skipped++;
      continue;
    }
    const regex = /^(1|true|yes)$/i.test(cell(row.cells, 'regex') ?? '');
    const mt = (cell(row.cells, 'match_type') ?? '').toLowerCase();
    const d: Draft = {
      source: src, target: urlToPath(cell(row.cells, 'target') ?? '').trim(),
      status: parseStatus(cell(row.cells, 'status'), o.default_status ?? 301) as never,
      match_type: mt === 'wildcard' || mt === 'regex' || mt === 'exact' ? mt : regex ? 'regex' : autoType(src),
    };
    const t = cell(row.cells, 'target') ?? '';
    if (/^https?:\/\//i.test(t)) d.target = t;
    const g = cell(row.cells, 'group');
    if (g) d.group = g;
    const n = cell(row.cells, 'note');
    if (n) d.note = n;
    const en = cell(row.cells, 'enabled');
    if (en) d.enabled = !/^(0|false|no)$/i.test(en);
    const pr = cell(row.cells, 'priority');
    if (pr && /^-?\d+$/.test(pr)) d.priority = Number(pr);
    res.rows.push({ line: row.line, raw: row.raw, input: d, issues: [] });
  }
  return res;
}

/* ---- json / yaml ---------------------------------------------------------------------------- */

const FIELDS = ['source', 'target', 'status', 'match_type', 'enabled', 'priority', 'group', 'note', 'tags', 'case_sensitive', 'ignore_trailing_slash', 'query_mode', 'query_params', 'query_ignore', 'continue', 'only_if_not_found', 'active_from', 'expires_at', 'conditions', 'target_type'];

function fromObject(o: Record<string, unknown>, dflt: number): Draft {
  const d: Record<string, unknown> = {};
  for (const k of FIELDS) if (o[k] !== undefined) d[k] = o[k];
  d.source = String(o.source ?? o.from ?? '');
  d.target = String(o.target ?? o.to ?? '');
  d.status = parseStatus(o.status === undefined ? undefined : String(o.status), dflt);
  d.match_type = (d.match_type as string) || autoType(d.source as string);
  return d as Draft;
}

function parseJson(text: string, o: ImportOptions): ParseResult {
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch (e) {
    res.errors.push(issue('invalid_json', `JSON konnte nicht gelesen werden: ${(e as Error).message}`));
    return res;
  }
  const obj = data as Record<string, unknown>;
  const list: Record<string, unknown>[] = Array.isArray(data)
    ? (data as Record<string, unknown>[])
    : Array.isArray(obj.rules)
      ? (obj.rules as Record<string, unknown>[])
      : Object.entries(obj).map(([k, v]) => ({ source: k, target: String(v) }));
  list.forEach((item, i) => {
    if (item && typeof item === 'object') res.rows.push({ line: i + 1, raw: JSON.stringify(item), input: fromObject(item, o.default_status ?? 301), issues: [] });
    else res.skipped++;
  });
  return res;
}

const yamlScalar = (v: string): string | number | boolean => {
  const t = v.trim();
  if (/^(['"]).*\1$/.test(t)) return unquote(t);
  if (/^(true|false)$/i.test(t)) return t.toLowerCase() === 'true';
  if (/^-?\d+$/.test(t)) return Number(t);
  return t;
};
function splitKey(s: string): [string, string] | null {
  const m = /^(?:'([^']*)'|"([^"]*)"|([^:]*?))\s*:(?:\s+(.*)|\s*)$/.exec(s);
  return m ? [m[1] ?? m[2] ?? m[3] ?? '', (m[4] ?? '').trim()] : null;
}

/** Minimal YAML: flat `source: target` maps, `- source: ..` lists, and `redirects:` / `routes:` sections. */
function parseYaml(text: string, o: ImportOptions): ParseResult {
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  let section = '';
  let cur: { line: number; raw: string; o: Record<string, unknown> } | null = null;
  const flush = () => {
    if (cur) res.rows.push({ line: cur.line, raw: cur.raw, input: fromObject(cur.o, o.default_status ?? 301), issues: [] });
    cur = null;
  };
  const entry = (line: number, raw: string, key: string, val: string) => {
    const v = String(yamlScalar(val));
    const m = /^(.*?)\s*\[(\d{3})\]$/.exec(v);
    const target = m ? m[1]! : v;
    res.rows.push({ line, raw, input: fromObject({ source: key, target, status: m ? m[2] : section === 'routes' ? '200' : undefined, match_type: /^\^|\(/.test(key) ? 'regex' : undefined }, o.default_status ?? 301), issues: [] });
  };
  text.split('\n').forEach((raw, i) => {
    const t = raw.trim();
    if (!t || t.startsWith('#') || t === '---') return;
    const indent = raw.length - raw.trimStart().length;
    if (t.startsWith('- ')) {
      flush();
      const kv = splitKey(t.slice(2));
      cur = { line: i + 1, raw: t, o: {} };
      if (kv) {
        if (FIELDS.includes(kv[0]) || kv[0] === 'from' || kv[0] === 'to') cur.o[kv[0]] = yamlScalar(kv[1]);
        else { cur.o.source = kv[0]; cur.o.target = String(yamlScalar(kv[1])); }
      }
      return;
    }
    const kv = splitKey(t);
    if (!kv) { res.skipped++; return; }
    if (cur && indent > 0) { cur.o[kv[0]] = yamlScalar(kv[1]); cur.raw += ' ' + t; return; }
    flush();
    if (!kv[1]) { section = kv[0]; return; }
    if (indent === 0 && ['version', 'redirects', 'routes'].includes(kv[0])) return;
    entry(i + 1, t, kv[0], kv[1]);
  });
  flush();
  return res;
}

/* ---- server configs ------------------------------------------------------------------------- */

function parseHtaccess(text: string, o: ImportOptions): ParseResult {
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  text.split('\n').forEach((raw, i) => {
    const line = raw.trim();
    if (!line || line.startsWith('#')) return;
    const tk = splitWs(line);
    const cmd = tk[0]!.toLowerCase();
    if (cmd === 'redirect' || cmd === 'redirectmatch' || cmd === 'redirectpermanent' || cmd === 'redirecttemp') {
      const rest = tk.slice(1);
      let status = cmd === 'redirectpermanent' ? 301 : cmd === 'redirecttemp' ? 302 : 302;
      if (rest[0] && /^(\d{3}|permanent|temp|seeother|gone)$/i.test(rest[0])) status = parseStatus(rest.shift(), 302);
      const source = rest[0] ?? '';
      const d: Draft = { source, target: rest[1] ?? '', status: (status || o.default_status || 301) as never, match_type: cmd === 'redirectmatch' ? 'regex' : 'exact', case_sensitive: true };
      res.rows.push({ line: i + 1, raw: line, input: d, issues: [] });
    } else if (cmd === 'rewriterule') {
      const flags = tk[3] ?? '';
      const r = /\bR(?:=(\w+))?/i.exec(flags);
      if (!r && !/\bG\b/i.test(flags)) { res.skipped++; return; }
      const status = /\bG\b/i.test(flags) ? 410 : parseStatus(r?.[1], 302);
      res.rows.push({ line: i + 1, raw: line, input: { ...patternToRule(tk[1] ?? '', /NC/i.test(flags)), target: tk[2] === '-' ? '' : tk[2] ?? '', status: status as never }, issues: [] });
    } else res.skipped++;
  });
  return res;
}

function parseNginx(text: string): ParseResult {
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  for (const m of text.matchAll(/^\s*rewrite\s+(\S+)\s+(\S+)(?:\s+(\w+))?\s*;/gm)) {
    const flag = m[3] ?? '';
    if (flag !== 'permanent' && flag !== 'redirect') { res.skipped++; continue; }
    res.rows.push({ line: lineOf(text, m.index!), raw: m[0].trim(), input: { ...patternToRule(m[1]!, false), target: m[2]!, status: (flag === 'permanent' ? 301 : 302) as never }, issues: [] });
  }
  for (const m of text.matchAll(/location\s+(=|~\*?|\^~)?\s*(\S+)\s*\{[^}]*?return\s+(\d{3})\s*([^;]*);/g)) {
    const mod = m[1] ?? '';
    const d: Draft = mod === '=' ? { source: m[2]!, match_type: 'exact', target: m[4]!.trim(), status: Number(m[3]) as never }
      : mod.startsWith('~') ? { source: m[2]!, match_type: 'regex', case_sensitive: mod === '~', target: m[4]!.trim(), status: Number(m[3]) as never }
      : { source: m[2]!.replace(/\/?$/, '/*'), match_type: 'wildcard', target: m[4]!.trim(), status: Number(m[3]) as never };
    res.rows.push({ line: lineOf(text, m.index!), raw: m[0].replace(/\s+/g, ' '), input: d, issues: [] });
  }
  res.rows.sort((a, b) => a.line - b.line);
  return res;
}

function parseNetlify(text: string, o: ImportOptions): ParseResult {
  const res: ParseResult = { rows: [], skipped: 0, notFound: [], errors: [] };
  text.split('\n').forEach((raw, i) => {
    const line = raw.trim();
    if (!line || line.startsWith('#')) return;
    const tk = splitWs(line).filter((t) => !/^\w+=/.test(t));
    if (tk.length < 2) { res.skipped++; return; }
    const [from, to, st] = tk as [string, string, string | undefined];
    const status = parseStatus(st?.replace('!', ''), o.default_status ?? 301);
    const d: Draft = { source: from, target: to.replace(/:splat/g, '$1'), status: status as never };
    if (/:[A-Za-z_]\w*/.test(from)) {
      d.match_type = 'regex';
      d.source = '^' + from.split(/(:[A-Za-z_]\w*|\*)/).map((p) => (p === '*' ? '(.*)' : /^:/.test(p) ? `(?<${p.slice(1)}>[^/]+)` : escapeRe(p))).join('') + '$';
      d.target = d.target.replace(/:([A-Za-z_]\w*)/g, '{$1}');
    } else d.match_type = from.includes('*') ? 'wildcard' : 'exact';
    res.rows.push({ line: i + 1, raw: line, input: d, issues: [] });
  });
  return res;
}

export function parseImport(content: string, format: string, o: ImportOptions): ParseResult {
  const text = content.replace(/^﻿/, '');
  switch (format) {
    case 'json': return parseJson(text, o);
    case 'yaml': case 'grav_site': return parseYaml(text, o);
    case 'htaccess': return parseHtaccess(text, o);
    case 'nginx': return parseNginx(text);
    case 'netlify': return parseNetlify(text, o);
    default: return parseCsv(text, format, o);
  }
}

/* ---- export --------------------------------------------------------------------------------- */

export interface ExportOut {
  filename: string;
  mime: string;
  content: string;
  skipped: { id: string; source: string; reason: string }[];
}

const csvCell = (v: unknown) => {
  const s = String(v ?? '');
  return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
const yq = (s: string) => `'${s.replace(/'/g, "''")}'`;
/** wildcard source -> PCRE with `(.*)` groups */
const wildcardRegex = (s: string) => '^' + s.split('*').map(escapeRe).join('(.*)') + '$';
const hasConditions = (r: Rule) => r.conditions.hosts.length || r.conditions.languages.length || r.conditions.schemes.length || r.conditions.rules.length;

export function exportRules(rules: Rule[], format: string, host: string): ExportOut {
  const skipped: ExportOut['skipped'] = [];
  const lines: string[] = [];
  const skip = (r: Rule, reason: string) => skipped.push({ id: r.id, source: r.source, reason });
  const stamp = '# Redirect Manager export';
  let filename = 'redirects.txt';
  let mime = 'text/plain';
  switch (format) {
    case 'csv':
      filename = 'redirects.csv'; mime = 'text/csv';
      lines.push('source,target,status,match_type,enabled,priority,group,note');
      for (const r of rules) lines.push([r.source, r.target, r.status, r.match_type, r.enabled ? 1 : 0, r.priority, r.group, r.note].map(csvCell).join(','));
      break;
    case 'json':
      filename = 'redirects.json'; mime = 'application/json';
      return { filename, mime, skipped, content: JSON.stringify({ version: 1, rules: rules.map((r) => Object.fromEntries(FIELDS.map((k) => [k, r[k as keyof Rule]]))) }, null, 2) };
    case 'yaml':
      filename = 'redirects.yaml'; mime = 'application/yaml';
      lines.push(stamp, 'version: 1', 'rules:');
      for (const r of rules) {
        lines.push(`  - source: ${yq(r.source)}`, `    target: ${yq(r.target)}`, `    status: ${r.status}`, `    match_type: ${r.match_type}`, `    enabled: ${r.enabled}`, `    priority: ${r.priority}`);
        if (r.group) lines.push(`    group: ${yq(r.group)}`);
        if (r.note) lines.push(`    note: ${yq(r.note)}`);
      }
      break;
    case 'htaccess':
      filename = '.htaccess'; lines.push(stamp);
      for (const r of rules) {
        if (hasConditions(r)) { skip(r, 'Bedingungen (Host, Sprache, Header) lassen sich in .htaccess nicht abbilden'); continue; }
        if (r.source.includes('?') || r.query_mode !== 'ignore') { skip(r, 'Query-Modus wird von Redirect/RedirectMatch nicht unterstützt'); continue; }
        if (r.status === 200 || r.status === 451) { skip(r, `Status ${r.status} ist mit mod_alias nicht möglich`); continue; }
        const code = r.status === 410 ? 'gone' : r.status;
        if (r.match_type === 'exact') lines.push(`Redirect ${code} ${r.source}${r.status === 410 ? '' : ' ' + r.target}`);
        else lines.push(`RedirectMatch ${code} ${r.match_type === 'regex' ? r.source : wildcardRegex(r.source)}${r.status === 410 ? '' : ' ' + r.target}`);
      }
      break;
    case 'nginx':
      filename = 'redirects.conf'; lines.push(stamp, '# include inside a server { } block');
      for (const r of rules) {
        if (hasConditions(r) || r.source.includes('?') || r.query_mode !== 'ignore') { skip(r, 'Bedingungen oder Query-Modus werden nicht exportiert'); continue; }
        if (r.status === 200) { skip(r, 'Durchreichen (200) benötigt einen internen Rewrite'); continue; }
        const ret = r.status === 410 || r.status === 451 ? `return ${r.status};` : `return ${r.status} ${r.target};`;
        if (r.match_type === 'exact') lines.push(`location = ${r.source} { ${ret} }`);
        else if (r.status === 410 || r.status === 451) skip(r, 'Regex/Wildcard mit 410/451 braucht einen location-Block');
        else if (r.status === 301 || r.status === 302) lines.push(`rewrite ${r.match_type === 'regex' ? r.source : wildcardRegex(r.source)} ${r.target} ${r.status === 301 ? 'permanent' : 'redirect'};`);
        else lines.push(`location ~ ${r.match_type === 'regex' ? r.source : wildcardRegex(r.source)} { ${ret} }`);
      }
      break;
    case 'cloudflare_csv':
      filename = 'cloudflare-redirects.csv'; mime = 'text/csv';
      for (const r of rules) {
        if (r.match_type === 'regex') { skip(r, 'Cloudflare Bulk Redirects kennen keine regulären Ausdrücke'); continue; }
        if (![301, 302, 307, 308].includes(r.status)) { skip(r, `Status ${r.status} wird nicht unterstützt`); continue; }
        const wild = r.match_type === 'wildcard';
        if (wild && !/\/\*$/.test(r.source)) { skip(r, 'Wildcard nur am Pfadende möglich (Subpath Matching)'); continue; }
        const h = r.conditions.hosts[0] ?? host;
        lines.push([h + (wild ? r.source.slice(0, -2) : r.source), /^https?:/.test(r.target) ? r.target : `https://${h}${r.target.replace(/\$1$/, '')}`, r.status, 'FALSE', 'FALSE', wild ? 'TRUE' : 'FALSE', wild ? 'TRUE' : 'FALSE'].map(csvCell).join(','));
      }
      break;
    case 'netlify':
      filename = '_redirects'; lines.push(stamp);
      for (const r of rules) {
        if (r.match_type === 'regex') { skip(r, 'Netlify unterstützt keine regulären Ausdrücke'); continue; }
        if (r.status === 410 || r.status === 451) { skip(r, `Status ${r.status} wird von _redirects nicht unterstützt`); continue; }
        lines.push(`${r.source} ${r.target.replace(/\$1/g, ':splat')} ${r.status}`);
      }
      break;
    case 'grav_site': {
      filename = 'site-redirects.yaml'; mime = 'application/yaml';
      const red: string[] = [];
      const routes: string[] = [];
      for (const r of rules) {
        if (hasConditions(r)) { skip(r, 'Bedingungen werden von site.yaml nicht unterstützt'); continue; }
        if (r.status === 410 || r.status === 451) { skip(r, `Status ${r.status} ist in site.yaml nicht möglich`); continue; }
        const key = r.match_type === 'wildcard' ? wildcardRegex(r.source) : r.source;
        if (r.status === 200) routes.push(`  ${yq(key)}: ${yq(r.target)}`);
        else red.push(`  ${yq(key)}: ${yq(r.target + (r.status === 302 ? '' : `[${r.status}]`))}`);
      }
      lines.push('# Add to user/config/site.yaml', 'redirects:', ...red, 'routes:', ...routes);
      break;
    }
    default:
      lines.push(stamp);
  }
  return { filename, mime, skipped, content: lines.join('\n') + '\n' };
}
