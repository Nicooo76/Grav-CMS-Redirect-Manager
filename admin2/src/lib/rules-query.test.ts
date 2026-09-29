import { describe, expect, it } from 'vitest';
import { DEFAULT_LIST, activeFilterCount, canReorder, fromHashQuery, listKey, moveItem, toApiQuery, toHashQuery, toggleSort } from './rules-query';

describe('rules list query', () => {
  it('keeps the URL empty for the default state', () => {
    expect(toHashQuery(DEFAULT_LIST)).toEqual({});
  });

  it('writes only what differs from the defaults', () => {
    expect(toHashQuery({ ...DEFAULT_LIST, q: 'shop', status: 301, page: 2, sort: 'hits', dir: 'desc' })).toEqual({ q: 'shop', status: '301', page: '2', sort: 'hits' });
  });

  it('round-trips through the hash', () => {
    const s = { ...DEFAULT_LIST, q: 'a b', match_type: 'regex' as const, badge: 'chain' as const, unused_days: 90, group: 'Blog', per_page: 250, sort: 'source' as const, dir: 'asc' as const };
    expect(fromHashQuery(toHashQuery(s))).toEqual(s);
  });

  it('sanitises junk from the URL', () => {
    const s = fromHashQuery({ match_type: 'nope', status: 'abc', page: '-4', per_page: '99999', sort: 'evil', dir: 'sideways', badge: 'x', unused_days: '0' });
    expect(s).toEqual(DEFAULT_LIST);
  });

  it('builds the API query with all parameters', () => {
    const q = toApiQuery({ ...DEFAULT_LIST, q: '  shop ', state: 'active', page: 3 });
    expect(q).toMatchObject({ q: 'shop', state: 'active', page: 3, per_page: 50, sort: 'priority', dir: 'desc' });
  });

  it('counts active filters but not search, sort or paging', () => {
    expect(activeFilterCount({ ...DEFAULT_LIST, q: 'x', sort: 'source', page: 4 })).toBe(0);
    expect(activeFilterCount({ ...DEFAULT_LIST, group: 'a', status: 301, badge: 'loop' })).toBe(3);
  });

  it('flips the direction on the same column and picks sane defaults for others', () => {
    expect(toggleSort(DEFAULT_LIST, 'priority')).toEqual({ sort: 'priority', dir: 'asc', page: 1 });
    expect(toggleSort(DEFAULT_LIST, 'source')).toEqual({ sort: 'source', dir: 'asc', page: 1 });
    expect(toggleSort(DEFAULT_LIST, 'hits')).toEqual({ sort: 'hits', dir: 'desc', page: 1 });
  });

  it('allows reordering only in ranking order', () => {
    expect(canReorder(DEFAULT_LIST)).toBe(true);
    expect(canReorder({ ...DEFAULT_LIST, dir: 'asc' })).toBe(false);
    expect(canReorder({ ...DEFAULT_LIST, sort: 'source' })).toBe(false);
  });

  it('produces a stable list key', () => {
    expect(listKey(DEFAULT_LIST)).toBe(listKey({ ...DEFAULT_LIST }));
    expect(listKey(DEFAULT_LIST)).not.toBe(listKey({ ...DEFAULT_LIST, page: 2 }));
  });

  it('moves items without mutating and clamps the target', () => {
    const a = ['a', 'b', 'c', 'd'];
    expect(moveItem(a, 0, 2)).toEqual(['b', 'c', 'a', 'd']);
    expect(moveItem(a, 3, 0)).toEqual(['d', 'a', 'b', 'c']);
    expect(moveItem(a, 1, 99)).toEqual(['a', 'c', 'd', 'b']);
    expect(moveItem(a, 9, 0)).toEqual(a);
    expect(a).toEqual(['a', 'b', 'c', 'd']);
  });
});
