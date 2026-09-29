import { describe, expect, it } from 'vitest';
import {
  buildTestBody,
  hasLoop,
  matchedIndex,
  normalizeTestUrl,
  pathOfUrl,
  pairsToRecord,
  reasonCode,
  statusVariant,
  summarize,
  truncateTrace,
} from './tester';
import type { TestResponse, TraceStep } from './types';

function resp(over: Partial<TestResponse>): TestResponse {
  return {
    input: { url: '/a' },
    context: {},
    result: null,
    chain: [],
    final: { url: '/a', status: 404 },
    trace: [],
    page_exists: false,
    ...over,
  };
}

describe('normalizeTestUrl', () => {
  it('keeps paths and absolute URLs', () => {
    expect(normalizeTestUrl('/old')).toBe('/old');
    expect(normalizeTestUrl('  https://example.org/old?x=1 ')).toBe('https://example.org/old?x=1');
  });
  it('prefixes a bare path and keeps empty input empty', () => {
    expect(normalizeTestUrl('old-page')).toBe('/old-page');
    expect(normalizeTestUrl('   ')).toBe('');
  });
});

describe('statusVariant', () => {
  it('classifies by range', () => {
    expect(statusVariant(200)).toBe('ok');
    expect(statusVariant(301)).toBe('info');
    expect(statusVariant(410)).toBe('warn');
    expect(statusVariant(404)).toBe('bad');
    expect(statusVariant(508)).toBe('bad');
    expect(statusVariant(null)).toBe('muted');
  });
});

describe('summarize', () => {
  it('single redirect: one hop, two steps, terminal is the destination', () => {
    const o = summarize(
      resp({
        chain: [{ url: '/old', status: 301, rule_id: 'r1' }],
        final: { url: '/new', status: 200 },
        page_exists: true,
      }),
    );
    expect(o.kind).toBe('redirect');
    expect(o.status).toBe(301);
    expect(o.hops).toBe(1);
    expect(o.headlineKey).toBe('TESTER.HEAD_REDIRECT');
    expect(o.variant).toBe('info');
    expect(o.steps.map((s) => s.url)).toEqual(['/old', '/new']);
    expect(o.steps[0].ruleId).toBe('r1');
    expect(o.steps[1].terminal).toBe(true);
    expect(o.finalUrl).toBe('/new');
    expect(o.destinationMissing).toBe(false);
  });

  it('counts hops of a chain and names the first status', () => {
    const o = summarize(
      resp({
        chain: [
          { url: '/a', status: 302, rule_id: 'r1' },
          { url: '/b', status: 301, rule_id: 'r2' },
        ],
        final: { url: '/c', status: 200 },
      }),
    );
    expect(o.hops).toBe(2);
    expect(o.status).toBe(302);
    expect(o.steps).toHaveLength(3);
  });

  it('flags a redirect whose destination returns 404', () => {
    const o = summarize(resp({ chain: [{ url: '/a', status: 301, rule_id: 'r1' }], final: { url: '/gone', status: 404 } }));
    expect(o.kind).toBe('redirect');
    expect(o.destinationMissing).toBe(true);
  });

  it('detects a loop from repeated URLs', () => {
    const o = summarize(
      resp({
        chain: [
          { url: '/a', status: 301, rule_id: 'r1' },
          { url: '/b', status: 301, rule_id: 'r2' },
        ],
        final: { url: '/a', status: 508 },
      }),
    );
    expect(o.kind).toBe('loop');
    expect(o.loop).toBe(true);
    expect(o.headlineKey).toBe('TESTER.HEAD_LOOP');
    expect(o.steps[o.steps.length - 1]).toMatchObject({ url: '/a', loops: true, terminal: true, status: null });
  });

  it('ignores a trailing slash when comparing URLs', () => {
    expect(hasLoop(['/a', '/b', '/a/'])).toBe(true);
    expect(hasLoop(['/a', '/b'])).toBe(false);
  });

  it('detects the depth limit', () => {
    const chain = Array.from({ length: 10 }, (_, i) => ({ url: `/p${i}`, status: 301, rule_id: `r${i}` }));
    const o = summarize(resp({ chain, final: { url: '/p10', status: 200 } }));
    expect(o.kind).toBe('depth');
    expect(o.depth).toBe(true);
    expect(o.loop).toBe(false);
  });

  it('classifies pass-through, gone and legal rules', () => {
    const pass = summarize(resp({ chain: [{ url: '/x', status: 200, rule_id: 'r1' }], final: { url: '/x', status: 200 } }));
    expect(pass.kind).toBe('passthrough');
    expect(pass.steps).toHaveLength(1);
    expect(pass.variant).toBe('ok');
    const gone = summarize(resp({ chain: [{ url: '/x', status: 410, rule_id: 'r1' }], final: { url: '/x', status: 410 } }));
    expect(gone.kind).toBe('gone');
    expect(gone.variant).toBe('warn');
    const legal = summarize(resp({ chain: [{ url: '/x', status: 451, rule_id: 'r1' }], final: { url: '/x', status: 451 } }));
    expect(legal.kind).toBe('legal');
  });

  it('no rule: page found or not found', () => {
    const found = summarize(resp({ final: { url: '/p', status: 200 }, page_exists: true }));
    expect(found.kind).toBe('found');
    expect(found.headlineKey).toBe('TESTER.HEAD_FOUND');
    expect(found.variant).toBe('ok');
    expect(found.steps).toHaveLength(1);
    expect(found.hops).toBe(0);
    const nf = summarize(resp({ final: { url: '/p', status: 404 } }));
    expect(nf.kind).toBe('notfound');
    expect(nf.variant).toBe('bad');
  });

  it('falls back to page_exists when the final status is missing', () => {
    const o = summarize(resp({ final: { url: '/p', status: 0 }, page_exists: true }));
    expect(o.kind).toBe('found');
    expect(o.status).toBe(200);
  });
});

describe('truncateTrace', () => {
  const rows = (n: number) => Array.from({ length: n }, (_, i) => ({ rule_id: `r${i}` }));
  it('keeps short traces whole', () => {
    expect(truncateTrace(rows(20), false)).toMatchObject({ hidden: 0, truncated: false });
    expect(truncateTrace(rows(20), false).rows).toHaveLength(20);
  });
  it('cuts long traces at 20 and reports the rest', () => {
    const r = truncateTrace(rows(35), false);
    expect(r.rows).toHaveLength(20);
    expect(r.hidden).toBe(15);
    expect(r.truncated).toBe(true);
  });
  it('shows everything on request', () => {
    const r = truncateTrace(rows(35), true);
    expect(r.rows).toHaveLength(35);
    expect(r.truncated).toBe(false);
  });
});

describe('matchedIndex', () => {
  const t = (id: string, matched: boolean): TraceStep => ({ rule_id: id, source: '/', match_type: 'exact', priority: 0, matched, reason: '', path: '/' });
  it('finds the matched row of the winning rule', () => {
    expect(matchedIndex([t('a', false), t('b', true), t('c', false)], 'b')).toBe(1);
    expect(matchedIndex([t('a', false)], 'a')).toBe(-1);
    expect(matchedIndex([], null)).toBe(-1);
  });
});

describe('reasonCode', () => {
  it('passes known codes', () => {
    expect(reasonCode('no_match')).toBe('no_match');
    expect(reasonCode('regex_error')).toBe('regex_error');
  });
  it('maps English phrases', () => {
    expect(reasonCode('matched: exact path, continue')).toBe('matched');
    expect(reasonCode('skipped: disabled')).toBe('disabled');
    expect(reasonCode('skipped: expired on 2026-01-01')).toBe('expired');
    expect(reasonCode('no match: required query param "a" missing')).toBe('query');
    expect(reasonCode('no match')).toBe('no_match');
    expect(reasonCode('skipped: only if not found (page exists)')).toBe('phase');
  });
  it('returns null for unknown text', () => {
    expect(reasonCode('something else')).toBeNull();
    expect(reasonCode('')).toBeNull();
  });
});

describe('request body', () => {
  it('drops blank keys and empty maps', () => {
    expect(pairsToRecord([{ key: ' ', value: 'x' }])).toBeUndefined();
    expect(pairsToRecord([{ key: 'A', value: '1' }, { key: 'A', value: '2' }])).toEqual({ A: '2' });
  });
  it('sends only what differs from the defaults', () => {
    const base = { method: 'GET', language: '', userAgent: '', headers: [], cookies: [] };
    expect(buildTestBody('old', base)).toEqual({ url: '/old' });
  });
  it('adds method, language, user agent, headers and cookies', () => {
    const body = buildTestBody('/x', {
      method: 'HEAD',
      language: ' de ',
      userAgent: 'Googlebot',
      headers: [{ key: 'Referer', value: 'https://e.org' }],
      cookies: [{ key: 'lang', value: 'de' }],
    });
    expect(body).toEqual({
      url: '/x',
      method: 'HEAD',
      language: 'de',
      headers: { Referer: 'https://e.org', 'User-Agent': 'Googlebot' },
      cookies: { lang: 'de' },
    });
  });
  it('lets an explicit User-Agent header win over the field', () => {
    const body = buildTestBody('/x', { method: 'GET', language: '', userAgent: 'A', headers: [{ key: 'user-agent', value: 'B' }], cookies: [] });
    expect(body.headers).toEqual({ 'user-agent': 'B' });
  });
});

describe('pathOfUrl', () => {
  it('extracts path and query', () => {
    expect(pathOfUrl('https://example.org/old?x=1#top')).toBe('/old?x=1');
    expect(pathOfUrl('/old#a')).toBe('/old');
    expect(pathOfUrl('old')).toBe('/old');
    expect(pathOfUrl('https://example.org')).toBe('/');
    expect(pathOfUrl('')).toBe('');
  });
});
