import { describe, expect, it } from 'vitest';
import type { Stats } from './types';
import {
  adminUrl,
  changeVs,
  dailyAverage,
  endpointFor,
  isEmptyStats,
  layoutFor,
  normalizeStats,
  roundAverage,
  seriesOf,
  sparkPaths,
  tilesFor,
} from './widget';

const stats = (over: Partial<Stats> = {}): Stats => ({
  not_found_today: 0,
  not_found_7d: 0,
  not_found_by_day: {},
  hits_today: 0,
  hits_7d: 0,
  hits_by_day: {},
  rules_total: 0,
  rules_active: 0,
  open_suggestions: 0,
  dead_targets: 0,
  pending_deletes: 0,
  ...over,
});

describe('layoutFor', () => {
  it('folds the Admin 2 sizes onto our three layouts', () => {
    expect(layoutFor('xs')).toBe('sm');
    expect(layoutFor('sm')).toBe('sm');
    expect(layoutFor('md')).toBe('md');
    expect(layoutFor('lg')).toBe('lg');
    expect(layoutFor('xl')).toBe('lg');
    expect(layoutFor(' LG ')).toBe('lg');
  });
  it('defaults to md for missing or unknown sizes', () => {
    expect(layoutFor(null)).toBe('md');
    expect(layoutFor(undefined)).toBe('md');
    expect(layoutFor('huge')).toBe('md');
  });
});

describe('endpointFor', () => {
  it('defaults to the stats route and normalises the leading slash', () => {
    expect(endpointFor(null)).toBe('/redirects/stats');
    expect(endpointFor('  ')).toBe('/redirects/stats');
    expect(endpointFor('redirects/stats')).toBe('/redirects/stats');
    expect(endpointFor('/x/y')).toBe('/x/y');
  });
});

describe('seriesOf', () => {
  it('sorts by day and keeps the last N', () => {
    expect(seriesOf({ '2026-01-03': 3, '2026-01-01': 1, '2026-01-02': 2 })).toEqual([1, 2, 3]);
    expect(seriesOf({ '2026-01-03': 3, '2026-01-01': 1, '2026-01-02': 2 }, 2)).toEqual([2, 3]);
  });
  it('handles empty input and bad values', () => {
    expect(seriesOf(undefined)).toEqual([]);
    expect(seriesOf({})).toEqual([]);
    expect(seriesOf({ a: -1, b: NaN, c: 4 })).toEqual([0, 0, 4]);
  });
});

describe('averages and change', () => {
  it('reports a daily average only from one event per day', () => {
    expect(dailyAverage(6)).toBeNull();
    expect(dailyAverage(7)).toBe(1);
    expect(dailyAverage(70)).toBe(10);
    expect(dailyAverage(NaN)).toBeNull();
  });
  it('rounds averages for display', () => {
    expect(roundAverage(1.0)).toBe(1);
    expect(roundAverage(8.44)).toBe(8.4);
    expect(roundAverage(12.6)).toBe(13);
  });
  it('computes relative change and refuses a zero baseline', () => {
    expect(changeVs(12, 10)).toBeCloseTo(0.2);
    expect(changeVs(5, 10)).toBeCloseTo(-0.5);
    expect(changeVs(5, 0)).toBeNull();
    expect(changeVs(NaN, 3)).toBeNull();
  });
});

describe('tilesFor', () => {
  it('shows all four tiles at md and lg', () => {
    expect(tilesFor('md', stats())).toEqual(['notfound', 'hits', 'suggestions', 'dead']);
    expect(tilesFor('lg', stats())).toHaveLength(4);
  });
  it('picks the counter that needs attention at sm', () => {
    expect(tilesFor('sm', stats({ dead_targets: 2, open_suggestions: 5 }))).toEqual(['notfound', 'dead']);
    expect(tilesFor('sm', stats({ open_suggestions: 5 }))).toEqual(['notfound', 'suggestions']);
    expect(tilesFor('sm', stats())).toEqual(['notfound', 'dead']);
  });
});

describe('isEmptyStats', () => {
  it('is empty only when there are no rules and no traffic', () => {
    expect(isEmptyStats(stats())).toBe(true);
    expect(isEmptyStats(stats({ rules_total: 1 }))).toBe(false);
    expect(isEmptyStats(stats({ not_found_7d: 3 }))).toBe(false);
    expect(isEmptyStats(stats({ dead_targets: 1 }))).toBe(false);
  });
});

describe('adminUrl', () => {
  it('prefixes the admin base and tolerates a missing one', () => {
    expect(adminUrl('/404', '/admin')).toBe('/admin/plugin/redirect-manager#/404');
    expect(adminUrl('/rules?badge=dead_target', undefined)).toBe('/plugin/redirect-manager#/rules?badge=dead_target');
  });
});

describe('sparkPaths', () => {
  it('is flat for no data or all zeros', () => {
    expect(sparkPaths([]).flat).toBe(true);
    expect(sparkPaths([0, 0, 0]).flat).toBe(true);
    expect(sparkPaths([5]).flat).toBe(true);
  });
  it('draws a line from the first to the last point', () => {
    const p = sparkPaths([0, 2, 4], 100, 20, 0);
    expect(p.flat).toBe(false);
    expect(p.line).toBe('M0.0,20.0L50.0,10.0L100.0,0.0');
    expect(p.area.endsWith('Z')).toBe(true);
  });
});

describe('normalizeStats', () => {
  it('fills gaps with zeros and empty maps', () => {
    const s = normalizeStats({ not_found_7d: 4, dead_targets: '2', hits_by_day: null });
    expect(s.not_found_7d).toBe(4);
    expect(s.dead_targets).toBe(2);
    expect(s.hits_today).toBe(0);
    expect(s.hits_by_day).toEqual({});
  });
  it('survives garbage input', () => {
    expect(normalizeStats(null).rules_total).toBe(0);
    expect(normalizeStats('x').open_suggestions).toBe(0);
    expect(normalizeStats({ hits_7d: -3 }).hits_7d).toBe(0);
  });
});
