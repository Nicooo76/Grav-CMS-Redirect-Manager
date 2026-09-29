import { describe, expect, it } from 'vitest';
import {
  DEFAULT_NF,
  activeFilterCount,
  botShare,
  fillTrend,
  fromHashQuery,
  globMatch,
  isBotDominated,
  listKey,
  niceScale,
  refererLabel,
  summarizeReferers,
  toApiQuery,
  toHashQuery,
  toTrendQuery,
  toggleSort,
  trendSummary,
} from './notfound-query';

describe('defaults and API query', () => {
  it('hides bots by default and always sends the bots flag', () => {
    const q = toApiQuery(DEFAULT_NF);
    expect(q).toMatchObject({ days: 30, bots: 0, sort: 'hits', dir: 'desc', page: 1, per_page: 25 });
    expect(q.include_resolved).toBeUndefined();
    expect(toTrendQuery(DEFAULT_NF)).toEqual({ days: 30, bots: 0 });
  });
  it('maps switches to 0/1', () => {
    const q = toApiQuery({ ...DEFAULT_NF, bots: true, include_resolved: true, class: 'bot', q: '  old ' });
    expect(q).toMatchObject({ bots: 1, include_resolved: 1, class: 'bot', q: 'old' });
  });
});

describe('hash query', () => {
  it('is empty for the defaults', () => {
    expect(toHashQuery(DEFAULT_NF)).toEqual({});
    expect(listKey(DEFAULT_NF)).toBe('{}');
  });
  it('writes only non-default values', () => {
    const s = { ...DEFAULT_NF, days: 90 as const, bots: true, class: 'browser' as const, q: 'blog', sort: 'path' as const, dir: 'asc' as const, page: 3, include_resolved: true };
    expect(toHashQuery(s)).toEqual({ days: '90', bots: '1', class: 'browser', q: 'blog', sort: 'path', page: '3', include_resolved: '1' });
  });
  it('keeps a non-default direction', () => {
    expect(toHashQuery({ ...DEFAULT_NF, dir: 'asc' })).toEqual({ dir: 'asc' });
  });
  it('round-trips', () => {
    const s = { ...DEFAULT_NF, days: 7 as const, class: 'bot' as const, sort: 'last' as const, dir: 'asc' as const, page: 2, per_page: 100, q: '/x' };
    expect(fromHashQuery(toHashQuery(s))).toEqual(s);
  });
  it('sanitises garbage', () => {
    const s = fromHashQuery({ days: '14', bots: 'yes', class: 'robot', sort: 'hax', dir: 'up', page: '-3', per_page: '99999', q: 'ok' });
    expect(s).toEqual({ ...DEFAULT_NF, q: 'ok' });
  });
  it('takes the default direction of the sort column', () => {
    expect(fromHashQuery({ sort: 'path' }).dir).toBe('asc');
    expect(fromHashQuery({ sort: 'first' }).dir).toBe('desc');
  });
});

describe('filters and sorting', () => {
  it('counts filters but not the range', () => {
    expect(activeFilterCount({ ...DEFAULT_NF, days: 90 })).toBe(0);
    expect(activeFilterCount({ ...DEFAULT_NF, q: 'a', class: 'bot', bots: true, include_resolved: true })).toBe(3);
    expect(activeFilterCount({ ...DEFAULT_NF, bots: true })).toBe(0);
  });
  it('flips the direction on the same column and resets the page', () => {
    expect(toggleSort({ ...DEFAULT_NF, page: 4 }, 'hits')).toEqual({ sort: 'hits', dir: 'asc', page: 1 });
    expect(toggleSort(DEFAULT_NF, 'path')).toEqual({ sort: 'path', dir: 'asc', page: 1 });
    expect(toggleSort(DEFAULT_NF, 'last')).toEqual({ sort: 'last', dir: 'desc', page: 1 });
  });
});

describe('row helpers', () => {
  it('flags bot dominated rows above 50 percent', () => {
    expect(botShare({ hits: 10, ua: { bot: 6, browser: 4 } })).toBeCloseTo(0.6);
    expect(isBotDominated({ hits: 10, ua: { bot: 6, browser: 4 } })).toBe(true);
    expect(isBotDominated({ hits: 10, ua: { bot: 5, browser: 5 } })).toBe(false);
    expect(isBotDominated({ hits: 0, ua: {} })).toBe(false);
  });
  it('shortens referers and marks direct traffic', () => {
    expect(refererLabel('')).toBeNull();
    expect(refererLabel('https://www.google.com/')).toBe('www.google.com');
    expect(refererLabel('https://example.org/blog/a')).toBe('example.org/blog/a');
    expect(refererLabel('not a url')).toBe('not a url');
    expect(summarizeReferers([])).toBeNull();
    expect(summarizeReferers([{ referer: '', hits: 3 }, { referer: 'https://a.test/', hits: 1 }])).toEqual({ first: null, direct: true, more: 1 });
    expect(summarizeReferers([{ referer: 'https://a.test/', hits: 1 }])).toEqual({ first: 'a.test', direct: false, more: 0 });
  });
  it('matches ignore patterns with * wildcards', () => {
    expect(globMatch('/wp-admin/*', '/wp-admin/setup.php')).toBe(true);
    expect(globMatch('/wp-admin/*', '/blog/wp-admin/x')).toBe(false);
    expect(globMatch('/a.php', '/a.php')).toBe(true);
    expect(globMatch('/a.php', '/aXphp')).toBe(false);
    expect(globMatch('*.env', '/x/.env')).toBe(true);
    expect(globMatch('', '/x')).toBe(false);
  });
});

describe('trend', () => {
  const today = new Date(2026, 2, 3);
  it('fills missing days with zero, oldest first', () => {
    const pts = fillTrend({ '2026-03-02': 4, '2026-03-03': 7, '2026-02-20': 9 }, 4, today);
    expect(pts).toEqual([
      { day: '2026-02-28', count: 0 },
      { day: '2026-03-01', count: 0 },
      { day: '2026-03-02', count: 4 },
      { day: '2026-03-03', count: 7 },
    ]);
  });
  it('summarises total and peak', () => {
    const { total, peak } = trendSummary(fillTrend({ '2026-03-02': 4, '2026-03-03': 7 }, 3, today));
    expect(total).toBe(11);
    expect(peak).toEqual({ day: '2026-03-03', count: 7 });
    expect(trendSummary([]).peak).toBeNull();
  });
  it('builds a round axis', () => {
    expect(niceScale(0)).toEqual({ max: 4, ticks: [0, 2, 4] });
    const s = niceScale(37);
    expect(s.max).toBeGreaterThanOrEqual(37);
    expect(s.ticks[0]).toBe(0);
    expect(s.ticks[s.ticks.length - 1]).toBe(s.max);
    expect(s.ticks.length).toBeLessThanOrEqual(6);
    expect(niceScale(1200).max).toBeGreaterThanOrEqual(1200);
  });
});
