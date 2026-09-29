/** Rule list state <-> API query <-> hash query. Pure, unit-tested. */
import type { Query } from './api';
import type { MatchType, RuleBadge, RuleSort, RuleState } from './types';

export interface RulesListState {
  q: string;
  match_type: MatchType | '';
  state: RuleState | '';
  status: number | '';
  group: string;
  origin: string;
  unused_days: number | '';
  badge: RuleBadge | '';
  sort: RuleSort;
  dir: 'asc' | 'desc';
  page: number;
  per_page: number;
}

export const DEFAULT_LIST: RulesListState = {
  q: '',
  match_type: '',
  state: '',
  status: '',
  group: '',
  origin: '',
  unused_days: '',
  badge: '',
  sort: 'priority',
  dir: 'desc',
  page: 1,
  per_page: 50,
};

export const FILTER_KEYS = ['match_type', 'state', 'status', 'group', 'origin', 'unused_days', 'badge'] as const;
export type FilterKey = (typeof FILTER_KEYS)[number];

const SORTS: RuleSort[] = ['priority', 'source', 'target', 'status', 'hits', 'last_hit', 'created_at', 'updated_at'];
const MATCH: MatchType[] = ['exact', 'wildcard', 'regex'];
const STATES: RuleState[] = ['active', 'disabled', 'expired', 'scheduled'];
const BADGES: RuleBadge[] = ['active', 'disabled', 'expired', 'scheduled', 'chain', 'loop', 'conflict', 'dead_target', 'unused'];
export const UNUSED_OPTIONS = [30, 90, 180, 365];
export const PAGE_SIZES = [25, 50, 100, 250, 500];
export const MAX_PER_PAGE = 10000;

/** Default direction when switching to a column. */
export function defaultDir(sort: RuleSort): 'asc' | 'desc' {
  return sort === 'priority' || sort === 'hits' || sort === 'last_hit' || sort === 'created_at' || sort === 'updated_at' ? 'desc' : 'asc';
}

/** Query string parameters for GET /redirects/rules (empty values are dropped by the api client). */
export function toApiQuery(s: RulesListState): Query {
  return {
    q: s.q.trim(),
    match_type: s.match_type,
    state: s.state,
    status: s.status,
    group: s.group,
    origin: s.origin,
    unused_days: s.unused_days,
    badge: s.badge,
    sort: s.sort,
    dir: s.dir,
    page: s.page,
    per_page: s.per_page,
  };
}

/** Compact query for the URL hash: only values that differ from the defaults. */
export function toHashQuery(s: RulesListState): Record<string, string> {
  const out: Record<string, string> = {};
  const d = DEFAULT_LIST;
  for (const k of Object.keys(d) as (keyof RulesListState)[]) {
    const v = s[k];
    if (v === '' || v === d[k]) continue;
    out[k] = String(v);
  }
  return out;
}

const int = (v: string | undefined, min: number, max: number): number | null => {
  if (v === undefined || v === '') return null;
  const n = Number(v);
  return Number.isInteger(n) && n >= min && n <= max ? n : null;
};

/** Parses (and sanitises) hash query parameters into a list state. */
export function fromHashQuery(q: Record<string, string>): RulesListState {
  const s: RulesListState = { ...DEFAULT_LIST };
  s.q = q.q ?? '';
  if (MATCH.includes(q.match_type as MatchType)) s.match_type = q.match_type as MatchType;
  if (STATES.includes(q.state as RuleState)) s.state = q.state as RuleState;
  if (BADGES.includes(q.badge as RuleBadge)) s.badge = q.badge as RuleBadge;
  s.status = int(q.status, 100, 599) ?? '';
  s.group = q.group ?? '';
  s.origin = q.origin ?? '';
  s.unused_days = int(q.unused_days, 1, 3650) ?? '';
  if (SORTS.includes(q.sort as RuleSort)) s.sort = q.sort as RuleSort;
  if (q.dir === 'asc' || q.dir === 'desc') s.dir = q.dir;
  s.page = int(q.page, 1, 1_000_000) ?? 1;
  s.per_page = int(q.per_page, 1, MAX_PER_PAGE) ?? DEFAULT_LIST.per_page;
  return s;
}

export function listKey(s: RulesListState): string {
  return JSON.stringify(toHashQuery(s));
}

export function activeFilterCount(s: RulesListState): number {
  return FILTER_KEYS.filter((k) => s[k] !== '').length;
}

export function hasAnyFilter(s: RulesListState): boolean {
  return activeFilterCount(s) > 0 || s.q.trim() !== '';
}

/** Click on a column header: same column flips direction, another column starts at its default. */
export function toggleSort(s: RulesListState, sort: RuleSort): Pick<RulesListState, 'sort' | 'dir' | 'page'> {
  if (s.sort === sort) return { sort, dir: s.dir === 'asc' ? 'desc' : 'asc', page: 1 };
  return { sort, dir: defaultDir(sort), page: 1 };
}

/** Reordering by drag and drop only makes sense in the ranking order. */
export function canReorder(s: RulesListState): boolean {
  return s.sort === 'priority' && s.dir === 'desc';
}

/** Moves the item at `from` to `to` (both indexes in the *result*), returns a new array. */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
  const out = list.slice();
  if (from < 0 || from >= out.length) return out;
  const t = Math.max(0, Math.min(out.length - 1, to));
  const [item] = out.splice(from, 1);
  out.splice(t, 0, item);
  return out;
}
