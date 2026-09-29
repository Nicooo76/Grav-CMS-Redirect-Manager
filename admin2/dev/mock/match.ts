/**
 * Pure rule matching, following docs/ARCHITECTURE.md: match types, query modes, conditions,
 * placeholders, `continue`, `only_if_not_found`, and the tester (trace + redirect chain).
 */
import type {
  Condition,
  MatchResult,
  PreviewResult,
  Rule,
  RuleState,
  TestResponse,
  TraceStep,
} from '../../src/lib/types';
import { dayKey, escapeRe, globTest } from './util';

export type Phase = 'early' | 'not_found';

export interface MatchContext {
  host: string;
  scheme: string;
  /** normalized path without language prefix */
  path: string;
  query: string;
  language: string;
  defaultLanguage: string;
  headers: Record<string, string>;
  cookies: Record<string, string>;
  now: number;
  phase: Phase;
  pageExists: boolean;
}

export interface Caps {
  list: string[];
  named: Record<string, string>;
}
export interface RuleMatch {
  matched: boolean;
  reason: string;
  caps?: Caps;
  location?: string;
}

const EMPTY: Caps = { list: [], named: {} };
const GLOBAL_QUERY_IGNORE = ['utm_*', 'fbclid', 'gclid', 'msclkid'];
const UNSAFE_REGEX = /\([^()]*[+*][^()]*\)[+*{]/;
const TYPE_RANK = { exact: 0, wildcard: 1, regex: 2 } as const;

export function normalizePath(p: string): string {
  let s = p;
  try {
    s = decodeURIComponent(p);
  } catch {
    /* keep raw */
  }
  const out: string[] = [];
  for (const seg of s.normalize('NFC').split('/')) {
    if (seg === '' || seg === '.') continue;
    if (seg === '..') out.pop();
    else out.push(seg);
  }
  return '/' + out.join('/') + (out.length && s.endsWith('/') ? '/' : '');
}

export function stateOf(r: Pick<Rule, 'enabled' | 'expires_at' | 'active_from'>, now: number): RuleState {
  if (!r.enabled) return 'disabled';
  if (r.expires_at && Date.parse(r.expires_at) <= now) return 'expired';
  if (r.active_from && Date.parse(r.active_from) > now) return 'scheduled';
  return 'active';
}

/* ---- compilation ---------------------------------------------------------------------------- */

interface Compiled {
  test(path: string): Caps | null;
  /** query part of an exact source (`/index.php?id=3`) */
  query: string;
  error?: string;
}
type Compilable = Pick<Rule, 'source' | 'match_type' | 'case_sensitive' | 'ignore_trailing_slash'>;
const compiled = new Map<string, Compiled>();

export function compileSource(rule: Compilable): Compiled {
  const key = `${rule.match_type}\0${rule.case_sensitive ? 1 : 0}${rule.ignore_trailing_slash ? 1 : 0}\0${rule.source}`;
  let c = compiled.get(key);
  if (!c) {
    c = build(rule);
    compiled.set(key, c);
  }
  return c;
}

function build(rule: Compilable): Compiled {
  const cs = rule.case_sensitive;
  const itl = rule.ignore_trailing_slash;
  const fail = (error: string): Compiled => ({ query: '', test: () => null, error });
  const trim = (s: string) => (itl && s.length > 1 ? s.replace(/\/+$/, '') || '/' : s);
  const candidates = (path: string) => (itl && path.length > 1 && path.endsWith('/') ? [path, trim(path)] : [path]);

  if (rule.match_type === 'exact') {
    const qi = rule.source.indexOf('?');
    const want = trim(normalizePath(qi < 0 ? rule.source : rule.source.slice(0, qi)));
    const fold = (s: string) => trim(cs ? s : s.toLowerCase());
    const wantFolded = cs ? want : want.toLowerCase();
    return { query: qi < 0 ? '' : rule.source.slice(qi + 1), test: (p) => (fold(p) === wantFolded ? EMPTY : null) };
  }

  let re: RegExp;
  try {
    if (rule.match_type === 'wildcard') {
      let pat = rule.source.split('?')[0]!.replace(/\/{2,}/g, '/');
      if (!pat.endsWith('*')) pat = trim(pat);
      re = new RegExp('^' + pat.split('*').map(escapeRe).join('(.*)') + '$', cs ? '' : 'i');
    } else {
      if (UNSAFE_REGEX.test(rule.source)) return fail('nested quantifiers (catastrophic backtracking risk)');
      re = new RegExp(rule.source.replace(/\(\?P</g, '(?<'), cs ? '' : 'i');
    }
  } catch (e) {
    return fail((e as Error).message);
  }
  return {
    query: '',
    test(path) {
      for (const c of candidates(path)) {
        const m = re.exec(c);
        if (m) return { list: m.slice(1).map((x) => x ?? ''), named: { ...(m.groups ?? {}) } };
      }
      return null;
    },
  };
}

/** Number of capture groups a source defines, for placeholder checks. */
export function captureCount(rule: Compilable): number {
  if (rule.match_type === 'exact') return 0;
  if (rule.match_type === 'wildcard') return rule.source.split('*').length - 1;
  try {
    return (new RegExp(rule.source.replace(/\(\?P</g, '(?<') + '|').exec('') ?? []).length - 1;
  } catch {
    return 0;
  }
}

export function regexError(rule: Compilable): string | null {
  return rule.match_type === 'exact' ? null : compileSource(rule).error ?? null;
}

/* ---- single rule ---------------------------------------------------------------------------- */

const hostMatch = (pattern: string, host: string): boolean => {
  const p = pattern.toLowerCase();
  return p.startsWith('*.') ? host.toLowerCase().endsWith(p.slice(1)) : p === host.toLowerCase();
};

function conditionOk(c: Condition, ctx: MatchContext): boolean {
  const v = c.kind === 'header' ? ctx.headers[c.name.toLowerCase()] : ctx.cookies[c.name];
  let r: boolean;
  switch (c.operator) {
    case 'exists':
      r = v !== undefined;
      break;
    case 'equals':
      r = v === c.value;
      break;
    case 'contains':
      r = v !== undefined && v.includes(c.value);
      break;
    case 'starts_with':
      r = v !== undefined && v.startsWith(c.value);
      break;
    default:
      try {
        r = v !== undefined && new RegExp(c.value, 'i').test(v);
      } catch {
        r = false;
      }
  }
  return c.negate ? !r : r;
}

function queryPairs(q: string, ignore: string[]): [string, string][] {
  const out: [string, string][] = [];
  const patterns = [...GLOBAL_QUERY_IGNORE, ...ignore];
  new URLSearchParams(q).forEach((v, k) => {
    if (!patterns.some((p) => globTest(p, k))) out.push([k, v]);
  });
  return out;
}

/** Returns a failure reason or null when the query satisfies the rule's query mode. */
function queryMismatch(rule: Rule, sourceQuery: string, requestQuery: string): string | null {
  if (rule.query_mode === 'exact') {
    const norm = (a: [string, string][]) => a.map(([k, v]) => `${k}=${v}`).sort().join('&');
    if (norm(queryPairs(sourceQuery, rule.query_ignore)) !== norm(queryPairs(requestQuery, rule.query_ignore))) {
      return 'query differs from source';
    }
  } else if (rule.query_mode === 'params') {
    const req = queryPairs(requestQuery, rule.query_ignore);
    for (const [k, v] of Object.entries(rule.query_params)) {
      const hit = req.find((x) => x[0] === k);
      if (!hit) return `required query param "${k}" missing`;
      if (v !== null && hit[1] !== v) return `query param "${k}" is not "${v}"`;
    }
  }
  return null;
}

export function buildLocation(rule: Rule, caps: Caps, ctx: Pick<MatchContext, 'language' | 'defaultLanguage' | 'query'>): string {
  let t = rule.target.replace(/\$\{(\d+)\}|\$(\d)|\{([A-Za-z_]\w*)\}/g, (m, a: string, b: string, name: string) => {
    if (a || b) return caps.list[Number(a || b) - 1] ?? '';
    if (name === 'lang') return ctx.language || ctx.defaultLanguage;
    return caps.named[name] ?? m;
  });
  if (rule.query_mode === 'pass') {
    const q = queryPairs(ctx.query, rule.query_ignore).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
    if (q) t += (t.includes('?') ? '&' : '?') + q;
  }
  return t;
}

/**
 * Evaluates one rule. `preview` ignores state, phase and header/cookie conditions
 * (what the editor's live preview shows: "does this pattern hit the sample?").
 */
export function matchRule(rule: Rule, ctx: MatchContext, preview = false): RuleMatch {
  const no = (reason: string): RuleMatch => ({ matched: false, reason });
  if (!preview) {
    const st = stateOf(rule, ctx.now);
    if (st === 'disabled') return no('skipped: disabled');
    if (st === 'expired') return no(`skipped: expired on ${dayKey(Date.parse(rule.expires_at!))}`);
    if (st === 'scheduled') return no(`skipped: scheduled from ${dayKey(Date.parse(rule.active_from!))}`);
    if (rule.only_if_not_found && ctx.phase === 'early') {
      return no(ctx.pageExists ? 'skipped: only if not found (page exists)' : 'skipped: only if not found');
    }
    const c = rule.conditions;
    if (c.hosts.length && !c.hosts.some((h) => hostMatch(h, ctx.host))) return no(`skipped: host ${ctx.host} not in [${c.hosts.join(', ')}]`);
    if (c.languages.length && !c.languages.includes(ctx.language)) return no(`skipped: language ${ctx.language} not in [${c.languages.join(', ')}]`);
    if (c.schemes.length && !c.schemes.includes(ctx.scheme)) return no(`skipped: scheme ${ctx.scheme} not in [${c.schemes.join(', ')}]`);
    for (const cond of c.rules) {
      if (!conditionOk(cond, ctx)) return no(`skipped: ${cond.kind} "${cond.name}" ${cond.negate ? 'not ' : ''}${cond.operator} condition failed`);
    }
  }
  const cs = compileSource(rule);
  if (cs.error) return no(`no match (invalid pattern: ${cs.error})`);
  const caps = cs.test(ctx.path);
  if (!caps) return no('no match');
  const qm = queryMismatch(rule, cs.query, ctx.query);
  if (qm) return no(`no match: ${qm}`);
  const kind = rule.match_type === 'exact' ? 'exact path' : rule.match_type;
  const cond = preview && (rule.conditions.rules.length > 0) ? ' (header/cookie conditions not evaluated)' : '';
  return { matched: true, reason: `matched: ${kind}${rule.continue ? ', continue' : ''}${cond}`, caps, location: buildLocation(rule, caps, ctx) };
}

export const flattenCaps = (c: Caps): Record<string, string> => {
  const out: Record<string, string> = {};
  c.list.forEach((v, i) => (out[String(i + 1)] = v));
  return { ...out, ...c.named };
};

/* ---- rule set ------------------------------------------------------------------------------- */

export function sortRules(rules: readonly Rule[]): Rule[] {
  return [...rules].sort(
    (a, b) =>
      b.priority - a.priority ||
      TYPE_RANK[a.match_type] - TYPE_RANK[b.match_type] ||
      (a.created_at ?? '').localeCompare(b.created_at ?? '') ||
      a.id.localeCompare(b.id),
  );
}

export interface EvalOut {
  result: MatchResult | null;
  trace: TraceStep[];
}

/** Walks the sorted rules once. `continue` rules rewrite the path and matching goes on. */
export function evaluate(sorted: readonly Rule[], base: MatchContext, cap = 60): EvalOut {
  const trace: TraceStep[] = [];
  const hit: Rule[] = [];
  let ctx = base;
  let caps: Caps = EMPTY;
  let status = 0;
  let location = '';
  let examined = 0;
  for (const r of sorted) {
    if (base.phase === 'not_found' && !r.only_if_not_found) continue;
    const m = matchRule(r, ctx);
    if (m.matched || examined++ < cap) {
      trace.push({ rule_id: r.id, source: r.source, match_type: r.match_type, priority: r.priority, matched: m.matched, reason: m.reason, path: ctx.path });
    }
    if (!m.matched) continue;
    hit.push(r);
    caps = m.caps ?? EMPTY;
    location = m.location ?? '';
    status = r.status;
    if (!r.continue || hit.length >= 10 || !location.startsWith('/')) break;
    const [p, q = ''] = location.split('?');
    ctx = { ...ctx, path: normalizePath(p!), query: q };
  }
  if (!hit.length) return { result: null, trace };
  const last = hit[hit.length - 1]!;
  return { result: { rule_id: last.id, status, location, rules: hit.map((r) => r.id), captures: flattenCaps(caps), trace }, trace };
}

/* ---- tester --------------------------------------------------------------------------------- */

export interface TestBody {
  url: string;
  method?: string;
  headers?: Record<string, string>;
  cookies?: Record<string, string>;
  language?: string;
  phase?: string;
}

export function parseUrl(url: string): URL {
  const s = url.trim();
  try {
    if (!s || s.startsWith('/')) return new URL(s || '/', 'https://example.test');
    return /^[a-z][a-z0-9+.-]*:\/\//i.test(s) ? new URL(s) : new URL('https://' + s);
  } catch {
    return new URL('https://example.test/');
  }
}

const LANGS = ['de', 'en'];
export function splitLanguage(pathname: string): { lang: string | null; path: string } {
  const m = /^\/([a-z]{2})(\/.*)?$/i.exec(pathname);
  if (m && LANGS.includes(m[1]!.toLowerCase())) return { lang: m[1]!.toLowerCase(), path: m[2] || '/' };
  return { lang: null, path: pathname };
}

export const pageKey = (p: string): string => normalizePath(p).toLowerCase().replace(/(.)\/$/, '$1');

/** Early pass, then (when Grav would 404) the not-found pass for "only if not found" rules. */
function flow(sorted: readonly Rule[], ctx: MatchContext, phase?: string): EvalOut {
  if (phase === 'not_found') return evaluate(sorted, { ...ctx, phase: 'not_found' });
  const early = evaluate(sorted, { ...ctx, phase: 'early' });
  if (early.result || ctx.pageExists || phase === 'early') return early;
  const late = evaluate(sorted, { ...ctx, phase: 'not_found' });
  const trace = [...early.trace, ...late.trace];
  return { result: late.result ? { ...late.result, trace } : null, trace };
}

export function runTest(sorted: readonly Rule[], pageExists: (key: string) => boolean, body: TestBody, now: number, defaultLanguage = 'de'): TestResponse {
  const u0 = parseUrl(body.url);
  const host = u0.host;
  const scheme = u0.protocol.replace(':', '');
  const headers: Record<string, string> = {};
  for (const [k, v] of Object.entries(body.headers ?? {})) headers[k.toLowerCase()] = v;
  const cookies = body.cookies ?? {};

  const chain: TestResponse['chain'] = [];
  const seen = new Set<string>();
  let first: EvalOut | null = null;
  let cur = u0;
  let loop = false;
  let final = { url: u0.href, status: 404 };
  let lastExists = false;
  let language = body.language ?? '';

  for (let i = 0; ; i++) {
    const { lang, path } = splitLanguage(cur.pathname);
    if (i === 0) language = body.language ?? lang ?? defaultLanguage;
    else language = lang ?? language;
    const key = cur.host + cur.pathname + cur.search;
    if (seen.has(key) || i >= 10) {
      loop = true;
      final = { url: cur.href, status: 508 };
      break;
    }
    seen.add(key);
    const exists = pageExists(pageKey(cur.pathname));
    lastExists = exists;
    const ctx: MatchContext = {
      host: cur.host, scheme, path: normalizePath(path), query: cur.search.replace(/^\?/, ''), language, defaultLanguage,
      headers, cookies, now, phase: 'early', pageExists: exists,
    };
    const out = flow(sorted, ctx, i === 0 ? body.phase : undefined);
    if (i === 0) first = out;
    const res = out.result;
    if (!res) {
      final = { url: cur.href, status: exists ? 200 : 404 };
      break;
    }
    chain.push({ url: cur.href, status: res.status, rule_id: res.rule_id });
    if (res.status === 200 || res.status >= 400) {
      final = { url: cur.href, status: res.status };
      break;
    }
    const next = parseUrl(res.location);
    if (!res.location.startsWith('/') && next.host !== host) {
      final = { url: res.location, status: 200 };
      break;
    }
    cur = res.location.startsWith('/') ? new URL(res.location, cur.origin) : next;
  }

  return {
    input: { url: body.url, method: body.method ?? 'GET', headers: body.headers ?? {}, cookies, language: body.language ?? null, phase: body.phase ?? null },
    context: { host, scheme, path: splitLanguage(u0.pathname).path, query: u0.search.replace(/^\?/, ''), language: body.language ?? splitLanguage(u0.pathname).lang ?? defaultLanguage, default_language: defaultLanguage, loop },
    result: first?.result ?? null,
    chain,
    final,
    trace: first?.trace ?? [],
    page_exists: lastExists,
  };
}

/** Editor preview: what one (possibly unsaved) rule does with a sample URL. */
export function buildRulePreview(rule: Rule, sample: string, language?: string): PreviewResult {
  const u = parseUrl(sample);
  const { lang, path } = splitLanguage(u.pathname);
  const ctx: MatchContext = {
    host: u.host, scheme: u.protocol.replace(':', ''), path: normalizePath(path), query: u.search.replace(/^\?/, ''),
    language: language ?? lang ?? 'de', defaultLanguage: 'de', headers: {}, cookies: {}, now: Date.now(), phase: 'early', pageExists: false,
  };
  const m = matchRule(rule, ctx, true);
  return {
    sample,
    result: m.matched
      ? { matched: true, status: rule.status, location: m.location ?? '', captures: flattenCaps(m.caps ?? EMPTY), reason: m.reason }
      : { matched: false, reason: m.reason },
  };
}
