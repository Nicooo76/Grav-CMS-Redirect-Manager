/** Pure logic of the URL tester: input handling, chain summary, headline classification, trace truncation. */
import type { FinalInfo, TestResponse, TraceStep } from './types';

export type StatusVariant = 'ok' | 'info' | 'warn' | 'bad' | 'muted';

export type OutcomeKind =
  | 'redirect'
  | 'passthrough'
  | 'found'
  | 'notfound'
  | 'gone'
  | 'legal'
  | 'loop'
  | 'depth'
  | 'excluded'
  | 'error';

export interface ChainStep {
  /** 1-based position */
  n: number;
  url: string;
  /** null when the API gave no status for the step */
  status: number | null;
  ruleId: string | null;
  /** the last step: where the visitor ends up */
  terminal: boolean;
  /** the step is an external URL the tester does not request */
  external?: boolean;
  /** the step that closes a loop (its URL was visited before) */
  loops: boolean;
  variant: StatusVariant;
}

export interface Outcome {
  kind: OutcomeKind;
  /** the status the headline names (first hop for redirects) */
  status: number | null;
  variant: StatusVariant;
  /** i18n key (TESTER.*) */
  headlineKey: string;
  finalUrl: string;
  /** number of redirect hops (3xx) in the chain */
  hops: number;
  loop: boolean;
  depth: boolean;
  /** the redirect leaves the site: the target was not requested */
  external: boolean;
  /** the chain ends in a redirect whose last URL returns an error status */
  destinationMissing: boolean;
  steps: ChainStep[];
}

export const TRACE_LIMIT = 20;
export const MAX_CHAIN_DEPTH = 10;

export function statusVariant(status: number | null | undefined): StatusVariant {
  if (status === null || status === undefined || !Number.isFinite(status)) return 'muted';
  if (status >= 200 && status < 300) return 'ok';
  if (status >= 300 && status < 400) return 'info';
  if (status === 404 || status >= 500) return 'bad';
  if (status >= 400) return 'warn';
  return 'muted';
}

/** `old-page`, `/old`, `https://example.org/old?x=1` -> what the API expects. Empty input stays empty. */
export function normalizeTestUrl(input: string): string {
  const s = input.trim();
  if (!s) return '';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) return s;
  if (s.startsWith('/')) return s;
  return `/${s}`;
}

function sameUrl(a: string, b: string): boolean {
  const norm = (u: string) => u.replace(/#.*$/, '').replace(/(.)\/+$/, '$1');
  return norm(a) === norm(b);
}

/** True when a URL shows up more than once in the visited list. */
export function hasLoop(urls: readonly string[]): boolean {
  for (let i = 0; i < urls.length; i++) {
    for (let j = i + 1; j < urls.length; j++) if (sameUrl(urls[i], urls[j])) return true;
  }
  return false;
}

const isRedirect = (status: number) => status >= 300 && status < 400;

/**
 * Turns the API response into the pieces the result card shows.
 *
 * The API's `chain` has one entry per rule hop (`rule_id` set) and, when the walk ended on a page or a 404,
 * a last entry without rule (`rule_id: null`, status 200 or 404). It ends without such an entry when a rule
 * answered for good (410, 451, pass-through), when the target is external, and when the walk stopped early;
 * `final` then carries the flag (`external`, `loop`, `truncated`, `excluded`).
 */
export function summarize(resp: TestResponse, maxDepth = MAX_CHAIN_DEPTH): Outcome {
  const chain = Array.isArray(resp.chain) ? resp.chain : [];
  const final: FinalInfo = resp.final ?? { url: resp.input?.url ?? '', status: 0 };
  const first = chain[0];
  const lastEntry = chain[chain.length - 1];
  const hops = chain.filter((c) => c.rule_id && isRedirect(c.status)).length;

  const loop = final.loop === true || (resp.context as { loop?: unknown } | undefined)?.loop === true;
  const depth = !loop && (final.truncated === true || (chain.length > maxDepth && !!lastEntry?.rule_id && isRedirect(lastEntry.status)));
  const excluded = final.excluded === true || (resp.context as { excluded?: unknown } | undefined)?.excluded === true;
  const external = final.external === true;

  const steps: ChainStep[] = chain.map((c, i) => ({
    n: i + 1,
    url: c.url,
    status: c.status,
    ruleId: c.rule_id,
    terminal: false,
    loops: false,
    variant: statusVariant(c.status),
  }));
  const endsOnRule = !!lastEntry && !!lastEntry.rule_id;
  if (loop) {
    steps.push({ n: steps.length + 1, url: final.url, status: null, ruleId: null, terminal: true, loops: true, variant: 'bad' });
  } else if (endsOnRule && isRedirect(lastEntry.status) && !depth) {
    // the walk left the chain: an external target (not requested) or a URL the visitor ends up on
    steps.push({ n: steps.length + 1, url: final.url, status: external ? null : final.status, ruleId: null, terminal: true, loops: false, variant: external ? 'muted' : statusVariant(final.status), external });
  } else if (steps.length) {
    steps[steps.length - 1].terminal = true;
  }

  let kind: OutcomeKind;
  let status: number | null;
  let headlineKey: string;
  let variant: StatusVariant;

  if (loop) {
    kind = 'loop';
    status = null;
    headlineKey = 'TESTER.HEAD_LOOP';
    variant = 'bad';
  } else if (depth) {
    kind = 'depth';
    status = null;
    headlineKey = 'TESTER.HEAD_DEPTH';
    variant = 'bad';
  } else if (first?.rule_id) {
    status = first.status;
    if (status === 410) {
      kind = 'gone';
      headlineKey = 'TESTER.HEAD_GONE';
    } else if (status === 451) {
      kind = 'legal';
      headlineKey = 'TESTER.HEAD_LEGAL';
    } else if (status === 200) {
      kind = 'passthrough';
      headlineKey = 'TESTER.HEAD_PASSTHROUGH';
    } else if (isRedirect(status)) {
      kind = 'redirect';
      headlineKey = 'TESTER.HEAD_REDIRECT';
    } else {
      kind = 'error';
      headlineKey = 'TESTER.HEAD_ERROR';
    }
    variant = statusVariant(status);
  } else {
    // no rule applied
    status = first?.status ?? (final.status || (resp.page_exists ? 200 : 404));
    if (excluded) {
      kind = 'excluded';
      headlineKey = 'TESTER.HEAD_EXCLUDED';
      variant = 'muted';
    } else if (status >= 200 && status < 300) {
      kind = 'found';
      headlineKey = 'TESTER.HEAD_FOUND';
      variant = statusVariant(status);
    } else if (status === 404) {
      kind = 'notfound';
      headlineKey = 'TESTER.HEAD_NOTFOUND';
      variant = statusVariant(status);
    } else {
      kind = 'error';
      headlineKey = 'TESTER.HEAD_ERROR';
      variant = statusVariant(status);
    }
  }

  const destinationMissing = kind === 'redirect' && !external && final.status >= 400;

  return { kind, status, variant, headlineKey, finalUrl: final.url, hops, loop, depth, external, destinationMissing, steps };
}

/** First `limit` trace rows unless the user asked for all of them. */
export function truncateTrace<T extends Pick<TraceStep, 'rule_id'>>(
  trace: readonly T[],
  showAll: boolean,
  limit = TRACE_LIMIT,
): { rows: readonly T[]; hidden: number; truncated: boolean } {
  if (showAll || trace.length <= limit) return { rows: trace, hidden: 0, truncated: false };
  return { rows: trace.slice(0, limit), hidden: trace.length - limit, truncated: true };
}

/** Index of the matched trace row that produced the result, or -1. */
export function matchedIndex(trace: readonly TraceStep[], ruleId: string | null | undefined): number {
  if (!ruleId) return -1;
  for (let i = trace.length - 1; i >= 0; i--) if (trace[i].matched && trace[i].rule_id === ruleId) return i;
  return -1;
}

const REASON_CODES = [
  'matched',
  'no_match',
  'disabled',
  'expired',
  'scheduled',
  'phase',
  'query',
  'host',
  'language',
  'scheme',
  'condition',
  'regex_error',
  'unsafe_target',
] as const;

/**
 * Maps a trace `reason` to one of the codes we have a sentence for. The backend sends codes
 * (`no_match`), some builds send short English phrases (`skipped: disabled`); anything else is null
 * and the UI prints it unchanged.
 */
export function reasonCode(reason: string | null | undefined): string | null {
  const r = (reason ?? '').trim().toLowerCase();
  if (!r) return null;
  if ((REASON_CODES as readonly string[]).includes(r)) return r;
  if (r.startsWith('matched')) return 'matched';
  if (r.startsWith('query') || (r.startsWith('no match:') && r.includes('query'))) return 'query';
  if (r.startsWith('no match')) return 'no_match';
  if (r.startsWith('skipped: disabled')) return 'disabled';
  if (r.startsWith('skipped: expired')) return 'expired';
  if (r.startsWith('skipped: scheduled')) return 'scheduled';
  if (r.startsWith('skipped: only if not found')) return 'phase';
  if (r.startsWith('skipped: host')) return 'host';
  if (r.startsWith('skipped: language')) return 'language';
  if (r.startsWith('skipped: scheme')) return 'scheme';
  if (r.startsWith('skipped:') && r.includes('condition')) return 'condition';
  return null;
}

export interface Pair {
  key: string;
  value: string;
}

/** Key/value rows -> object. Blank keys are dropped, the last duplicate wins. Empty result -> undefined. */
export function pairsToRecord(rows: readonly Pair[]): Record<string, string> | undefined {
  const out: Record<string, string> = {};
  for (const r of rows) {
    const k = r.key.trim();
    if (k) out[k] = r.value;
  }
  return Object.keys(out).length ? out : undefined;
}

export interface TestOptions {
  method: string;
  language: string;
  userAgent: string;
  headers: readonly Pair[];
  cookies: readonly Pair[];
}

/** Request body for POST /redirects/test. Only non-default values are sent. */
export function buildTestBody(url: string, o: TestOptions): Record<string, unknown> {
  const body: Record<string, unknown> = { url: normalizeTestUrl(url) };
  if (o.method && o.method !== 'GET') body.method = o.method;
  const lang = o.language.trim();
  if (lang) body.language = lang;
  const headers = pairsToRecord(o.headers) ?? {};
  const ua = o.userAgent.trim();
  if (ua && !Object.keys(headers).some((k) => k.toLowerCase() === 'user-agent')) headers['User-Agent'] = ua;
  if (Object.keys(headers).length) body.headers = headers;
  const cookies = pairsToRecord(o.cookies);
  if (cookies) body.cookies = cookies;
  return body;
}

/** Path (with query) of a tested URL, for prefilling a new rule's source. */
export function pathOfUrl(url: string): string {
  const s = normalizeTestUrl(url);
  if (!s) return '';
  if (s.startsWith('/')) return s.replace(/#.*$/, '');
  try {
    const u = new URL(s);
    return (u.pathname || '/') + u.search;
  } catch {
    return s;
  }
}
