/** 404 monitor state <-> API query <-> hash query, plus pure row helpers. Unit-tested. */
import type { Query } from './api';
import { lastDays } from './format';
import type { NotFoundRow, UaClass } from './types';

export type NotFoundSort = 'hits' | 'last' | 'first' | 'path';
export type RangeDays = 7 | 30 | 90;

export interface NotFoundState {
  days: RangeDays;
  /** true = bot traffic is part of the numbers */
  bots: boolean;
  class: UaClass | '';
  q: string;
  sort: NotFoundSort;
  dir: 'asc' | 'desc';
  page: number;
  per_page: number;
  include_resolved: boolean;
}

export const DEFAULT_NF: NotFoundState = {
  days: 30,
  bots: false,
  class: '',
  q: '',
  sort: 'hits',
  dir: 'desc',
  page: 1,
  per_page: 25,
  include_resolved: false,
};

export const RANGES: RangeDays[] = [7, 30, 90];
export const UA_CLASSES: UaClass[] = ['browser', 'bot', 'monitoring', 'unknown'];
export const NF_SORTS: NotFoundSort[] = ['hits', 'last', 'first', 'path'];
export const NF_PAGE_SIZES = [25, 50, 100, 250, 500];
export const MAX_PER_PAGE = 500;
/** A row counts as bot traffic when more than this share of its hits are bots. */
export const BOT_DOMINATED_SHARE = 0.5;

export function defaultDir(sort: NotFoundSort): 'asc' | 'desc' {
  return sort === 'path' ? 'asc' : 'desc';
}

/** Parameters for GET /redirects/404 (empty values are dropped by the api client). */
export function toApiQuery(s: NotFoundState): Query {
  return {
    days: s.days,
    bots: s.bots ? 1 : 0,
    class: s.class,
    q: s.q.trim(),
    sort: s.sort,
    dir: s.dir,
    page: s.page,
    per_page: s.per_page,
    include_resolved: s.include_resolved ? 1 : undefined,
  };
}

/** Parameters for GET /redirects/404/trend. */
export function toTrendQuery(s: Pick<NotFoundState, 'days' | 'bots'>): Query {
  return { days: s.days, bots: s.bots ? 1 : 0 };
}

/** Compact query for the URL hash: only values that differ from the defaults. */
export function toHashQuery(s: NotFoundState): Record<string, string> {
  const out: Record<string, string> = {};
  const d = DEFAULT_NF;
  if (s.days !== d.days) out.days = String(s.days);
  if (s.bots !== d.bots) out.bots = s.bots ? '1' : '0';
  if (s.class !== d.class) out.class = s.class;
  if (s.q.trim() !== d.q) out.q = s.q.trim();
  if (s.sort !== d.sort) out.sort = s.sort;
  if (s.dir !== defaultDir(s.sort)) out.dir = s.dir;
  if (s.page !== d.page) out.page = String(s.page);
  if (s.per_page !== d.per_page) out.per_page = String(s.per_page);
  if (s.include_resolved) out.include_resolved = '1';
  return out;
}

const int = (v: string | undefined, min: number, max: number): number | null => {
  if (v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isInteger(n) && n >= min && n <= max ? n : null;
};

/** Parses (and sanitises) hash query parameters. Unknown or out-of-range values fall back to the defaults. */
export function fromHashQuery(q: Record<string, string>): NotFoundState {
  const s: NotFoundState = { ...DEFAULT_NF };
  const days = int(q.days, 1, 365);
  if (days !== null && (RANGES as number[]).includes(days)) s.days = days as RangeDays;
  s.bots = q.bots === '1' || q.bots === 'true';
  if (UA_CLASSES.includes(q.class as UaClass)) s.class = q.class as UaClass;
  s.q = (q.q ?? '').slice(0, 300);
  if (NF_SORTS.includes(q.sort as NotFoundSort)) s.sort = q.sort as NotFoundSort;
  s.dir = q.dir === 'asc' || q.dir === 'desc' ? q.dir : defaultDir(s.sort);
  s.page = int(q.page, 1, 1_000_000) ?? 1;
  s.per_page = int(q.per_page, 1, MAX_PER_PAGE) ?? DEFAULT_NF.per_page;
  s.include_resolved = q.include_resolved === '1' || q.include_resolved === 'true';
  return s;
}

export function listKey(s: NotFoundState): string {
  return JSON.stringify(toHashQuery(s));
}

/** Filters that narrow the list. The time range and the bot switch only change what counts, they are not filters. */
export function activeFilterCount(s: NotFoundState): number {
  return (s.q.trim() ? 1 : 0) + (s.class ? 1 : 0) + (s.include_resolved ? 1 : 0);
}

export function hasAnyFilter(s: NotFoundState): boolean {
  return activeFilterCount(s) > 0;
}

/** Click on a column header: same column flips direction, another column starts at its default. */
export function toggleSort(s: NotFoundState, sort: NotFoundSort): Pick<NotFoundState, 'sort' | 'dir' | 'page'> {
  if (s.sort === sort) return { sort, dir: s.dir === 'asc' ? 'desc' : 'asc', page: 1 };
  return { sort, dir: defaultDir(sort), page: 1 };
}

/* ---------- rows ---------- */

/** Share (0..1) of a row's hits that come from bots. */
export function botShare(row: Pick<NotFoundRow, 'ua' | 'hits'>): number {
  const total = Object.values(row.ua ?? {}).reduce((a, b) => a + (b ?? 0), 0);
  if (total <= 0) return 0;
  return (row.ua.bot ?? 0) / total;
}

export function isBotDominated(row: Pick<NotFoundRow, 'ua' | 'hits'>): boolean {
  return botShare(row) > BOT_DOMINATED_SHARE;
}

/** Readable name of a referer URL; '' (no referer) gives null so the caller can say "Direct". */
export function refererLabel(referer: string): string | null {
  const r = referer.trim();
  if (!r) return null;
  try {
    const u = new URL(r);
    const path = u.pathname === '/' ? '' : u.pathname;
    return u.host + path;
  } catch {
    return r;
  }
}

/** First referer and how many more exist. */
export function summarizeReferers(list: NotFoundRow['top_referers'] | undefined): { first: string | null; direct: boolean; more: number } | null {
  if (!list || list.length === 0) return null;
  const first = list[0]!;
  return { first: refererLabel(first.referer), direct: first.referer.trim() === '', more: list.length - 1 };
}

/** `*` matches any run of characters; the rest is literal. Same idea as the ignore patterns on the server. */
export function globMatch(pattern: string, path: string): boolean {
  const p = pattern.trim();
  if (!p) return false;
  const re = new RegExp('^' + p.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$');
  return re.test(path);
}

export interface TrendPoint {
  day: string;
  count: number;
}

/** Dense series over the last `days` days; days the API left out count as 0. */
export function fillTrend(days: Record<string, number> | undefined, n: number, today?: Date): TrendPoint[] {
  return lastDays(n, today).map((day) => ({ day, count: Math.max(0, Number(days?.[day] ?? 0)) }));
}

/** Round axis: a maximum with 2 to 4 even steps that is at least `max`. */
export function niceScale(max: number): { max: number; ticks: number[] } {
  if (!(max > 0)) return { max: 4, ticks: [0, 2, 4] };
  const raw = max / 3;
  const pow = Math.pow(10, Math.floor(Math.log10(raw)));
  const frac = raw / pow;
  const step = (frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 5 ? 5 : 10) * pow;
  const top = Math.ceil(max / step) * step;
  const ticks: number[] = [];
  for (let v = 0; v <= top + step / 2; v += step) ticks.push(Math.round(v * 1e6) / 1e6);
  return { max: top, ticks };
}

export function trendSummary(points: TrendPoint[]): { total: number; peak: TrendPoint | null } {
  let total = 0;
  let peak: TrendPoint | null = null;
  for (const p of points) {
    total += p.count;
    if (p.count > 0 && (!peak || p.count > peak.count)) peak = p;
  }
  return { total, peak };
}
