import { describe, expect, it, vi } from 'vitest';
import {
  announceBadge,
  contextOf,
  hasError,
  normalizeContext,
  oldUrlBody,
  pushSidebarBadge,
  rulesHash,
  sourceFromInput,
  unseenRules,
} from './panel';

const attrs = (o: Record<string, string>) => ({ getAttribute: (n: string) => o[n] ?? null });

describe('contextOf', () => {
  it('takes route and language from the attributes the host sets', () => {
    expect(contextOf(attrs({ route: '/blog/news', lang: 'de', type: 'pages' }), {})).toEqual({ route: '/blog/news', lang: 'de' });
  });

  it('falls back to the globals of the page editor', () => {
    expect(contextOf(attrs({}), { __GRAV_PAGE_ROUTE: '/about', __GRAV_CONTENT_LANG: 'en' })).toEqual({ route: '/about', lang: 'en' });
    expect(contextOf(attrs({ route: '/a' }), { __GRAV_PAGE_ROUTE: '/b', __GRAV_CONTENT_LANG: 'en' })).toEqual({ route: '/a', lang: 'en' });
  });

  it('gives an empty route when nothing is known, and adds the leading slash', () => {
    expect(contextOf(attrs({}), {})).toEqual({ route: '', lang: '' });
    expect(contextOf(attrs({ route: ' blog/news ' }), {}).route).toBe('/blog/news');
  });
});

describe('normalizeContext', () => {
  it('survives garbage', () => {
    for (const raw of [null, undefined, 42, 'x', [], {}]) {
      const c = normalizeContext(raw);
      expect(c.incoming).toEqual([]);
      expect(c.created).toEqual([]);
      expect(c.pending).toEqual([]);
      expect(c.not_found).toEqual([]);
      expect(c.outgoing).toBeNull();
      expect(c.unseen).toBe(0);
    }
  });

  it('keeps well-formed rules, drops rows without id or source and fills defaults', () => {
    const c = normalizeContext({
      route: '/news',
      language: 'de',
      incoming: [{ id: 'a', source: '/blog', target: '/news', origin: 'auto', unseen: true, hits: 3, last_hit: '2026-09-29T09:00:00+00:00' }, { source: '/x' }, null, 'no'],
      incoming_total: 1,
      created: [{ id: 'a', source: '/blog', unseen: true }, { id: 'b', source: '/old', unseen: false, recent: true }],
      pending: [{ id: 'd1', title: 'Post', route: '/news/post', children_count: 2, deleted_at: '' }, { title: 'no id' }],
      not_found: [{ path: '/blog', hits: 4, last_seen: '' }, { hits: 1 }],
      outgoing: { id: 'o', source: '/news', location: '/elsewhere', status: 302 },
    });
    expect(c.route).toBe('/news');
    expect(c.language).toBe('de');
    expect(c.incoming).toHaveLength(1);
    expect(c.incoming[0]).toMatchObject({ id: 'a', status: 301, match_type: 'exact', state: 'active', hits: 3, unseen: true });
    expect(c.created).toHaveLength(2);
    expect(c.unseen).toBe(1);
    expect(unseenRules(c).map((r) => r.id)).toEqual(['a']);
    expect(c.pending).toHaveLength(1);
    expect(c.not_found).toHaveLength(1);
    expect(c.outgoing).toMatchObject({ id: 'o', location: '/elsewhere', status: 302 });
  });

  it('trusts the server total unless the rows say more', () => {
    expect(normalizeContext({ incoming: [{ id: 'a', source: '/a' }], incoming_total: 60 }).incoming_total).toBe(60);
    expect(normalizeContext({ incoming: [{ id: 'a', source: '/a' }], incoming_total: 0 }).incoming_total).toBe(1);
  });

  it('prefers the server count of unseen rules', () => {
    expect(normalizeContext({ created: [{ id: 'a', source: '/a', unseen: true }], unseen: 7 }).unseen).toBe(7);
  });
});

describe('sourceFromInput', () => {
  it.each([
    ['', ''],
    ['   ', ''],
    ['/old-page', '/old-page'],
    ['old-page', '/old-page'],
    ['  /old page/ ', '/old page/'],
    ['https://example.com/old-page', '/old-page'],
    ['http://example.com', '/'],
    ['https://example.com/old?x=1#top', '/old?x=1'],
    ['//example.com/old', '/old'],
    ['/a//b', '/a/b'],
    ['/old#frag', '/old'],
  ])('%j -> %j', (input, want) => {
    expect(sourceFromInput(input)).toBe(want);
  });
});

describe('oldUrlBody', () => {
  it('is a 301 exact rule to the page', () => {
    expect(oldUrlBody('/old', '/news', 'Added in the page editor')).toEqual({
      source: '/old',
      target: '/news',
      target_type: 'page',
      match_type: 'exact',
      status: 301,
      enabled: true,
      note: 'Added in the page editor',
    });
  });
});

describe('hasError', () => {
  it('is true for errors only', () => {
    const issue = (severity: 'error' | 'warning' | 'info') => ({ code: 'x', severity, message: '' });
    expect(hasError([issue('warning'), issue('info')])).toBe(false);
    expect(hasError([issue('warning'), issue('error')])).toBe(true);
    expect(hasError([])).toBe(false);
  });
});

describe('badges', () => {
  it('announces the count to the host on the panel element', () => {
    const el = new EventTarget();
    const seen: unknown[] = [];
    el.addEventListener('badge', (e) => seen.push((e as CustomEvent).detail));
    announceBadge(el, 2);
    expect(seen).toEqual([{ count: 2 }]);
  });

  it('pushes the sidebar count, and 0 for null (Admin 2 ignores a missing value)', () => {
    const fn = vi.fn();
    window.addEventListener('grav:sidebar:badge', (e) => fn((e as CustomEvent).detail));
    pushSidebarBadge(3);
    pushSidebarBadge(null);
    expect(fn.mock.calls).toEqual([[{ id: 'redirect-manager', count: 3 }], [{ id: 'redirect-manager', count: 0 }]]);
  });
});

describe('rulesHash', () => {
  it('filters the Rules tab to the page', () => {
    expect(rulesHash('/blog/news')).toBe('/rules?q=%2Fblog%2Fnews');
  });
});
