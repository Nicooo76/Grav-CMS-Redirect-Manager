/** Pure helpers for the suggestions review screen. Unit-tested. */
import type { Query } from './api';
import { formatPercent } from './format';
import type { StoredSuggestion, SuggestionReason } from './types';

export const DEFAULT_BULK_SCORE = 0.9;
export const BULK_MIN = 0.5;
export const BULK_MAX = 1;
export const BULK_STEP = 0.01;
/** Rows rendered in the review table; more are reachable through the score filter. */
export const MAX_ROWS = 200;
/** Rows listed in the bulk preview dialog. */
export const PREVIEW_ROWS = 200;

export const REASONS: SuggestionReason[] = [
  'same_slug',
  'other_language',
  'similar_route',
  'title_match',
  'taxonomy_match',
  'parent_fallback',
  'home_fallback',
];

export type SuggestionStatus = 'open' | 'accepted' | 'rejected';
export const STATUSES: SuggestionStatus[] = ['open', 'accepted', 'rejected'];
export const MIN_SCORE_OPTIONS = [0.5, 0.7, 0.8, 0.9];

export interface SuggestionsState {
  status: SuggestionStatus;
  /** server side filter, 0 = off */
  min: number;
  /** client side filter on path and target */
  q: string;
}

export const DEFAULT_SUGGESTIONS: SuggestionsState = { status: 'open', min: 0, q: '' };

export function toApiQuery(s: SuggestionsState): Query {
  return { status: s.status, min_score: s.min > 0 ? s.min : undefined };
}

export function toHashQuery(s: SuggestionsState): Record<string, string> {
  const out: Record<string, string> = {};
  if (s.status !== DEFAULT_SUGGESTIONS.status) out.status = s.status;
  if (s.min > 0) out.min = String(s.min);
  if (s.q.trim()) out.q = s.q.trim();
  return out;
}

export function fromHashQuery(q: Record<string, string>): SuggestionsState {
  const s: SuggestionsState = { ...DEFAULT_SUGGESTIONS };
  if (STATUSES.includes(q.status as SuggestionStatus)) s.status = q.status as SuggestionStatus;
  const min = Number(q.min);
  if (Number.isFinite(min) && min > 0 && min <= 1) s.min = Math.round(min * 100) / 100;
  s.q = (q.q ?? '').slice(0, 300);
  return s;
}

export function stateKey(s: SuggestionsState): string {
  return JSON.stringify(toHashQuery(s));
}

/** Whole percent, rounded down so that "90%" never hides a 0.895 that failed a 0.9 threshold. */
export function scorePercent(score: number): number {
  return Math.max(0, Math.min(100, Math.floor(score * 100 + 1e-9)));
}

export function formatScore(score: number, locale = 'en'): string {
  return formatPercent(scorePercent(score) / 100, locale);
}

export type ScoreBucket = 'high' | 'medium' | 'low';
export function scoreBucket(score: number): ScoreBucket {
  const p = scorePercent(score);
  return p >= 90 ? 'high' : p >= 70 ? 'medium' : 'low';
}

/** True when `score` reaches `min` (tolerates float noise like 0.8999999). */
export function meetsThreshold(score: number, min: number): boolean {
  return score + 1e-9 >= min;
}

/** How many open suggestions reach the threshold. */
export function countAtOrAbove(rows: readonly Pick<StoredSuggestion, 'score' | 'status'>[], min: number): number {
  let n = 0;
  for (const r of rows) if (r.status === 'open' && meetsThreshold(r.score, min)) n++;
  return n;
}

export function clampThreshold(v: number): number {
  if (!Number.isFinite(v)) return DEFAULT_BULK_SCORE;
  return Math.round(Math.max(BULK_MIN, Math.min(BULK_MAX, v)) * 100) / 100;
}

export function reasonKey(reason: string): string {
  return `SUGGESTIONS.REASON_${reason.toUpperCase()}`;
}

/** Client filter over path, target and page title. */
export function filterRows(rows: readonly StoredSuggestion[], q: string): StoredSuggestion[] {
  const term = q.trim().toLowerCase();
  if (!term) return rows.slice();
  return rows.filter((r) => r.path.toLowerCase().includes(term) || r.target.toLowerCase().includes(term) || (r.page_title ?? '').toLowerCase().includes(term));
}

export function limitRows<T>(rows: readonly T[], max = MAX_ROWS): { rows: T[]; hidden: number } {
  return { rows: rows.slice(0, max), hidden: Math.max(0, rows.length - max) };
}

/** Target as the user typed it: absolute URLs and paths stay, a bare word becomes a path. */
export function normalizeTarget(input: string): string {
  const v = input.trim();
  if (!v) return '';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(v) || v.startsWith('/')) return v;
  return '/' + v;
}

export function effectiveTarget(row: Pick<StoredSuggestion, 'id' | 'target'>, edits: Record<string, string>): string {
  return edits[row.id] ?? row.target;
}

export function isEdited(row: Pick<StoredSuggestion, 'id' | 'target'>, edits: Record<string, string>): boolean {
  return row.id in edits && edits[row.id] !== row.target;
}

/** Records an edit; an empty value or the original target removes it. Returns a new map. */
export function withEdit(edits: Record<string, string>, row: Pick<StoredSuggestion, 'id' | 'target'>, value: string): Record<string, string> {
  const next = { ...edits };
  const v = normalizeTarget(value);
  if (!v || v === row.target) delete next[row.id];
  else next[row.id] = v;
  return next;
}

/** Rows with the edited targets applied. */
export function applyEdits(rows: readonly StoredSuggestion[], edits: Record<string, string>): StoredSuggestion[] {
  return rows.map((r) => (r.id in edits ? { ...r, target: edits[r.id]! } : r));
}

/** Body of POST /redirects/suggestions/{id}/accept: the target only when it was changed. */
export function acceptBody(row: Pick<StoredSuggestion, 'id' | 'target'>, edits: Record<string, string>): { target?: string } {
  return isEdited(row, edits) ? { target: edits[row.id] } : {};
}

export function countsFromLists(lists: Partial<Record<SuggestionStatus, number>>): Record<SuggestionStatus, number> {
  return { open: lists.open ?? 0, accepted: lists.accepted ?? 0, rejected: lists.rejected ?? 0 };
}
