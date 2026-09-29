/** Rule construction and field validation (the parts that never depend on other rules). */
import type { MatchType, QueryMode, Rule, RuleInput, StatusCode, TargetType } from '../../src/lib/types';
import type { FieldError } from './util';
import { captureCount, regexError } from './match';

export const STATUS_CODES: readonly StatusCode[] = [200, 301, 302, 307, 308, 410, 451];
export const MATCH_TYPES: readonly MatchType[] = ['exact', 'wildcard', 'regex'];
export const QUERY_MODES: readonly QueryMode[] = ['ignore', 'pass', 'exact', 'params'];
export const TARGET_TYPES: readonly TargetType[] = ['route', 'url', 'page'];
export const REDIRECT_STATUS: readonly number[] = [301, 302, 307, 308];
/** `security.allowed_hosts` of the mock site; other external hosts are unsafe. */
export const ALLOWED_HOSTS = ['example.test', '*.example.test', 'shop.example-partner.de', 'www.facebook.com', 'www.instagram.com', 'github.com', 'maps.google.com'];

export interface FieldIssue extends FieldError {
  params?: Record<string, unknown>;
}

const hostAllowed = (host: string): boolean =>
  ALLOWED_HOSTS.some((h) => (h.startsWith('*.') ? host.endsWith(h.slice(1)) : h === host));

export function makeRule(input: RuleInput, id: string, nowIso: string): Rule {
  const source = String(input.source ?? '').trim();
  const match_type = input.match_type ?? 'exact';
  const target = String(input.target ?? '').trim();
  return {
    id,
    source: match_type !== 'regex' && source && !source.startsWith('/') ? '/' + source : source,
    target,
    match_type,
    status: (input.status ?? 301) as StatusCode,
    enabled: input.enabled ?? true,
    priority: input.priority ?? 0,
    target_type: input.target_type ?? (/^https?:\/\//i.test(target) ? 'url' : 'route'),
    case_sensitive: input.case_sensitive ?? false,
    ignore_trailing_slash: input.ignore_trailing_slash ?? true,
    query_mode: input.query_mode ?? 'ignore',
    query_params: input.query_params ?? {},
    query_ignore: input.query_ignore ?? [],
    continue: input.continue ?? false,
    only_if_not_found: input.only_if_not_found ?? false,
    active_from: input.active_from || null,
    expires_at: input.expires_at || null,
    note: input.note ?? '',
    group: input.group ?? '',
    tags: input.tags ?? [],
    origin: input.origin ?? 'manual',
    conditions: {
      hosts: input.conditions?.hosts ?? [],
      languages: input.conditions?.languages ?? [],
      schemes: input.conditions?.schemes ?? [],
      rules: input.conditions?.rules ?? [],
    },
    created_at: nowIso,
    updated_at: nowIso,
    stats: { total: 0, last_hit: null, daily: {} },
    badges: [],
    issues: [],
  };
}

export const emptyRuleStats = (): NonNullable<Rule['stats']> => ({ total: 0, last_hit: null, daily: {} });

/** Errors (severity `error`) for a complete rule; warnings for suspicious but storable input. */
export function validateFields(r: Rule): FieldIssue[] {
  const out: FieldIssue[] = [];
  const err = (field: string, code: string, message: string, params?: Record<string, unknown>, severity: FieldIssue['severity'] = 'error') =>
    out.push({ field, code, message, severity, ...(params ? { params } : {}) });

  if (!MATCH_TYPES.includes(r.match_type)) err('match_type', 'invalid_match_type', `Unbekannter Match-Typ "${String(r.match_type)}".`);
  if (!STATUS_CODES.includes(r.status)) err('status', 'invalid_status', `Status ${String(r.status)} ist nicht erlaubt (200, 301, 302, 307, 308, 410, 451).`);
  if (!QUERY_MODES.includes(r.query_mode)) err('query_mode', 'invalid_query_mode', `Unbekannter Query-Modus "${String(r.query_mode)}".`);
  if (!TARGET_TYPES.includes(r.target_type)) err('target_type', 'invalid_target_type', `Unbekannter Zieltyp "${String(r.target_type)}".`);
  if (!r.source) err('source', 'source_empty', 'Die Quelle darf nicht leer sein.');
  else if (MATCH_TYPES.includes(r.match_type)) {
    const re = regexError(r);
    if (re) err('source', r.match_type === 'regex' ? 'invalid_regex' : 'invalid_pattern', `Ungültiger Ausdruck: ${re}`);
  }
  if (r.active_from && r.expires_at && Date.parse(r.active_from) >= Date.parse(r.expires_at)) {
    err('expires_at', 'invalid_period', 'Das Ablaufdatum liegt vor dem Startdatum.');
  }

  const needsTarget = STATUS_CODES.includes(r.status) && r.status !== 410 && r.status !== 451;
  if (!r.target) {
    if (needsTarget) err('target', 'target_empty', 'Ein Ziel ist erforderlich.');
  } else if (/[\u0000-\u001f]/.test(r.target)) {
    err('target', 'unsafe_target', 'Das Ziel enthält Steuerzeichen.');
  } else if (r.target_type === 'url') {
    let u: URL | null = null;
    try {
      u = new URL(r.target);
    } catch {
      /* handled below */
    }
    if (!u || !/^https?:$/.test(u.protocol)) err('target', 'unsafe_target', 'Externe Ziele müssen mit http:// oder https:// beginnen.');
    else if (!hostAllowed(u.hostname)) err('target', 'unsafe_target', `Host ${u.hostname} steht nicht in der Liste erlaubter Hosts.`, { host: u.hostname });
    if (r.status === 200) err('status', 'passthrough_external', 'Status 200 (Durchreichen) geht nur mit einem internen Ziel.');
  } else if (r.target.startsWith('//')) {
    err('target', 'unsafe_target', 'Protokollrelative Ziele (//host) sind nicht erlaubt.');
  } else if (!r.target.startsWith('/')) {
    err('target', 'target_invalid', 'Interne Ziele müssen mit / beginnen.');
  }

  if (r.target && MATCH_TYPES.includes(r.match_type)) {
    const n = captureCount(r);
    const max = Math.max(0, ...[...r.target.matchAll(/\$\{?(\d+)\}?/g)].map((m) => Number(m[1])));
    if (max > n) err('target', 'unknown_capture', `Das Ziel verwendet $${max}, die Quelle liefert nur ${n} Treffergruppe(n).`, { max, available: n }, 'warning');
  }
  return out;
}
