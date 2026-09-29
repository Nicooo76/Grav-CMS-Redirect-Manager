import { describe, expect, it } from 'vitest';
import {
  DEFAULT_BULK_SCORE,
  DEFAULT_SUGGESTIONS,
  acceptBody,
  applyEdits,
  clampThreshold,
  countAtOrAbove,
  effectiveTarget,
  filterRows,
  formatScore,
  fromHashQuery,
  isEdited,
  limitRows,
  meetsThreshold,
  normalizeTarget,
  reasonKey,
  scoreBucket,
  scorePercent,
  toApiQuery,
  toHashQuery,
  withEdit,
} from './suggestions';
import type { StoredSuggestion } from './types';

const row = (id: string, score: number, over: Partial<StoredSuggestion> = {}): StoredSuggestion => ({
  id,
  path: `/old/${id}`,
  target: `/new/${id}`,
  score,
  reason: 'same_slug',
  page_title: '',
  hits: 1,
  status: 'open',
  ...over,
});

describe('threshold', () => {
  it('defaults to 90 percent', () => {
    expect(DEFAULT_BULK_SCORE).toBe(0.9);
  });
  it('counts open rows at or above the threshold', () => {
    const rows = [row('a', 0.95), row('b', 0.9), row('c', 0.899), row('d', 0.5), row('e', 0.99, { status: 'accepted' })];
    expect(countAtOrAbove(rows, 0.9)).toBe(2);
    expect(countAtOrAbove(rows, 0.5)).toBe(4);
    expect(countAtOrAbove(rows, 1)).toBe(0);
    expect(countAtOrAbove([], 0.9)).toBe(0);
  });
  it('tolerates float noise', () => {
    expect(meetsThreshold(0.8999999999, 0.9)).toBe(true);
    expect(meetsThreshold(0.89, 0.9)).toBe(false);
  });
  it('clamps the slider value', () => {
    expect(clampThreshold(0.2)).toBe(0.5);
    expect(clampThreshold(3)).toBe(1);
    expect(clampThreshold(0.876)).toBe(0.88);
    expect(clampThreshold(NaN)).toBe(0.9);
  });
});

describe('score display', () => {
  it('rounds down to whole percent', () => {
    expect(scorePercent(0.899)).toBe(89);
    expect(scorePercent(0.9)).toBe(90);
    expect(scorePercent(0.29)).toBe(29);
    expect(scorePercent(1.4)).toBe(100);
    expect(scorePercent(-1)).toBe(0);
  });
  it('formats with the locale', () => {
    expect(formatScore(0.87, 'en')).toBe('87%');
    expect(formatScore(0.87, 'de').replace(/\s/g, '')).toBe('87%');
  });
  it('buckets scores', () => {
    expect(scoreBucket(0.95)).toBe('high');
    expect(scoreBucket(0.9)).toBe('high');
    expect(scoreBucket(0.75)).toBe('medium');
    expect(scoreBucket(0.4)).toBe('low');
  });
  it('builds reason keys', () => {
    expect(reasonKey('same_slug')).toBe('SUGGESTIONS.REASON_SAME_SLUG');
  });
});

describe('edits', () => {
  it('normalises typed targets', () => {
    expect(normalizeTarget('  about ')).toBe('/about');
    expect(normalizeTarget('/about')).toBe('/about');
    expect(normalizeTarget('https://example.org/x')).toBe('https://example.org/x');
    expect(normalizeTarget('   ')).toBe('');
  });
  it('records, replaces and drops edits without mutating', () => {
    const r = row('a', 0.9);
    const e1 = withEdit({}, r, 'contact');
    expect(e1).toEqual({ a: '/contact' });
    expect(withEdit(e1, r, '/team')).toEqual({ a: '/team' });
    expect(withEdit(e1, r, r.target)).toEqual({});
    expect(withEdit(e1, r, '')).toEqual({});
    expect(e1).toEqual({ a: '/contact' });
  });
  it('reads effective targets and the accept body', () => {
    const r = row('a', 0.9);
    expect(effectiveTarget(r, {})).toBe('/new/a');
    expect(effectiveTarget(r, { a: '/x' })).toBe('/x');
    expect(isEdited(r, { a: '/x' })).toBe(true);
    expect(isEdited(r, { a: '/new/a' })).toBe(false);
    expect(acceptBody(r, {})).toEqual({});
    expect(acceptBody(r, { a: '/x' })).toEqual({ target: '/x' });
  });
  it('applies edits to rows', () => {
    const rows = [row('a', 0.9), row('b', 0.8)];
    const out = applyEdits(rows, { b: '/z' });
    expect(out[0]).toBe(rows[0]);
    expect(out[1]!.target).toBe('/z');
    expect(rows[1]!.target).toBe('/new/b');
  });
});

describe('list helpers', () => {
  it('limits and reports what is hidden', () => {
    const rows = Array.from({ length: 5 }, (_, i) => row(String(i), 0.9));
    expect(limitRows(rows, 3)).toEqual({ rows: rows.slice(0, 3), hidden: 2 });
    expect(limitRows(rows, 10).hidden).toBe(0);
  });
  it('filters by path, target and title', () => {
    const rows = [row('a', 0.9, { page_title: 'Pricing' }), row('b', 0.9)];
    expect(filterRows(rows, 'pricing').map((r) => r.id)).toEqual(['a']);
    expect(filterRows(rows, '/old/b').map((r) => r.id)).toEqual(['b']);
    expect(filterRows(rows, ' ').length).toBe(2);
  });
  it('builds the API and hash query', () => {
    expect(toApiQuery(DEFAULT_SUGGESTIONS)).toEqual({ status: 'open', min_score: undefined });
    expect(toApiQuery({ status: 'rejected', min: 0.7, q: 'x' })).toEqual({ status: 'rejected', min_score: 0.7 });
    expect(toHashQuery(DEFAULT_SUGGESTIONS)).toEqual({});
    expect(toHashQuery({ status: 'accepted', min: 0.8, q: ' a ' })).toEqual({ status: 'accepted', min: '0.8', q: 'a' });
    expect(fromHashQuery({ status: 'accepted', min: '0.8', q: 'a' })).toEqual({ status: 'accepted', min: 0.8, q: 'a' });
    expect(fromHashQuery({ status: 'bogus', min: '7', q: '' })).toEqual(DEFAULT_SUGGESTIONS);
  });
});
