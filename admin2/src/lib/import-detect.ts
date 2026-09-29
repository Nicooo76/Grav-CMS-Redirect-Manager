/**
 * Client-side guess of an import file's format, from the file name and the first lines.
 * The ids are the ones the backend uses (`GET /redirects/import/formats`). The server detects again
 * when no `format` is sent and reports what it found, so this only has to be good enough to decide
 * whether the CSV mapping UI is needed and what to show before the first preview.
 */
import { detectDelimiter, parseCsv } from './csv';
import type { ImportFormat } from './types';

export const ACCEPT = '.csv,.tsv,.json,.yaml,.yml,.htaccess,.conf,.txt,.xml';
export const MAX_IMPORT_BYTES = 5 * 1024 * 1024;
/** Only the start of a file is inspected. */
const SNIFF_BYTES = 65536;

/** Used when GET /redirects/import/formats fails. */
export const FALLBACK_FORMATS: ImportFormat[] = [
  { id: 'csv', label: 'CSV', import: true, export: true, extension: 'csv' },
  { id: 'json', label: 'JSON', import: true, export: true, extension: 'json' },
  { id: 'yaml', label: 'YAML', import: true, export: true, extension: 'yaml' },
  { id: 'htaccess', label: 'Apache .htaccess', import: true, export: true, extension: 'htaccess' },
  { id: 'nginx', label: 'Nginx', import: true, export: true, extension: 'conf' },
  { id: 'netlify', label: 'Netlify _redirects', import: true, export: true, extension: 'txt' },
  { id: 'grav_site', label: 'Grav site.yaml', import: true, export: true, extension: 'yaml' },
  { id: 'cloudflare_csv', label: 'Cloudflare Bulk Redirects (CSV)', import: false, export: true, extension: 'csv' },
];

/** Formats that use the column mapping UI. */
export function usesColumnMapping(id: string | null | undefined): boolean {
  return id === 'csv';
}

const stripBom = (s: string) => s.replace(/^﻿/, '');

function baseName(filename: string): string {
  return (filename.split(/[\\/]/).pop() ?? '').toLowerCase();
}

function extOf(base: string): string {
  const i = base.lastIndexOf('.');
  return i <= 0 ? '' : base.slice(i + 1);
}

function normKey(s: string): string {
  return s.trim().toLowerCase().replace(/[\s-]+/g, '_');
}

function detectCsv(text: string): string {
  const rows = parseCsv(text.slice(0, 32768), detectDelimiter(text)).slice(0, 6);
  for (const row of rows) {
    const cells = row.map(normKey);
    const has = (...names: string[]) => names.some((n) => cells.includes(n));
    if (cells.includes('source_url') && cells.includes('target_url')) return 'cloudflare_csv';
    if (has('address', 'url', 'destination') && has('status_code', 'http_status_code', 'response_code') && !cells.includes('target')) {
      return 'crawler_csv';
    }
    if (cells.includes('source') && cells.includes('target') && cells.includes('regex') && cells.includes('code')) return 'wordpress_csv';
  }
  return 'csv';
}

function detectJson(text: string): string {
  try {
    const data = JSON.parse(text);
    if (data && typeof data === 'object' && !Array.isArray(data) && Array.isArray((data as { redirects?: unknown }).redirects) && !('rules' in data)) {
      return 'wordpress_json';
    }
  } catch {
    // cut off or broken: look at the start only, the server reports the syntax error
    if (/^\s*\{\s*"redirects"\s*:\s*\[/.test(text)) return 'wordpress_json';
  }
  return 'json';
}

/** Content sniffing for files whose name says nothing (.txt, no extension, pasted text). */
function sniff(text: string): string | null {
  const t = text.trim();
  if (!t) return null;
  if (/^[[{]/.test(t)) return detectJson(t);
  if (/^\s*(RewriteRule|RewriteCond|RedirectMatch|Redirect(Permanent|Temp)?)\s/im.test(t)) return 'htaccess';
  if (/^\s*(rewrite\s+\S+\s+\S+.*;|location\s+.*\{|return\s+30[1278]\s+\S+;)/im.test(t)) return 'nginx';
  if (/^(redirects|routes)\s*:\s*$/m.test(t) && !/^rules\s*:/m.test(t)) return 'grav_site';
  if (/^rules\s*:\s*$/m.test(t)) return 'yaml';
  // Netlify: "/from /to 301", first token a path, no delimiters that would make it a CSV
  const lines = t.split(/\r?\n/).filter((l) => l.trim() && !l.trim().startsWith('#'));
  const yamlish = lines.some((l) => /^\/\S*:\s/.test(l.trim()));
  if (lines.length && !yamlish && lines.slice(0, 10).every((l) => /^\/\S*\s+\S+(\s+\d{3}!?)?(\s+\S+=\S+)*\s*$/.test(l.trim()))) return 'netlify';
  const first = lines[0] ?? '';
  if (/[,;\t]/.test(first) && lines.length > 0) return detectCsv(t);
  if (/^- /m.test(t) || /^\S[^:]*:\s+\S/m.test(t)) return 'yaml';
  return null;
}

/**
 * Best guess for the format id, or null when nothing fits.
 * Order: an unambiguous file name first, then the extension (refined by the content), then the content alone.
 */
export function detectFormat(filename: string, content: string): string | null {
  const text = stripBom(content.length > SNIFF_BYTES ? content.slice(0, SNIFF_BYTES) : content);
  const base = baseName(filename);
  const ext = extOf(base);

  if (base === '_redirects') return 'netlify';
  if (base === '.htaccess' || ext === 'htaccess' || base === 'htaccess') return 'htaccess';
  if (base.includes('nginx')) return 'nginx';
  if (!text.trim()) return null;

  switch (ext) {
    case 'csv':
    case 'tsv':
      return detectCsv(text);
    case 'json':
      return detectJson(text);
    case 'yaml':
    case 'yml': {
      const s = sniff(text);
      return s === 'grav_site' ? 'grav_site' : 'yaml';
    }
    case 'conf': {
      const s = sniff(text);
      return s === 'htaccess' ? 'htaccess' : 'nginx';
    }
    case 'xml':
      return null;
    default:
      return sniff(text);
  }
}

/** Picks the format id that exists in `formats` for a detected id. Returns null when the server does not list it. */
export function pickFormat(detected: string | null, formats: readonly ImportFormat[]): string | null {
  if (!detected) return null;
  return formats.some((f) => f.id === detected && f.import) ? detected : null;
}

/** File type check for the drop zone: by extension, since MIME types of these files are unreliable. */
export function isAcceptedFile(filename: string): boolean {
  const base = baseName(filename);
  if (base === '_redirects' || base === '.htaccess' || base === 'htaccess') return true;
  return ACCEPT.split(',').some((a) => base.endsWith(a));
}

export function isGzip(filename: string): boolean {
  return /\.gz$/i.test(filename);
}
