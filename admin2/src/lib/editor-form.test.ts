import { describe, expect, it } from 'vitest';
import { advancedState, clientChecks, diffPayload, formToPayload, hasBlockingIssue, initialForm, isDirty, issueField, needsTarget } from './editor-form';
import type { Rule } from './types';

const stored = (over: Partial<Rule> = {}): Rule =>
  ({ id: 'r1', source: '/a', target: '/b', match_type: 'exact', status: 301, enabled: true, priority: 0, target_type: 'route', case_sensitive: false, ignore_trailing_slash: true, query_mode: 'ignore', query_params: {}, query_ignore: [], continue: false, only_if_not_found: false, active_from: null, expires_at: null, note: '', group: '', tags: [], origin: 'manual', conditions: { hosts: [], languages: [], schemes: [], rules: [] }, created_at: null, updated_at: null, stats: { total: 1, last_hit: null, daily: {} }, badges: ['active'], ...over }) as Rule;

describe('editor form', () => {
  it('starts a new rule with defaults plus the prefill', () => {
    const f = initialForm(null, { source: '/lost' });
    expect(f).toMatchObject({ source: '/lost', status: 301, enabled: true, match_type: 'exact', target_type: 'route' });
  });

  it('drops read-only fields when editing', () => {
    const f = initialForm(stored());
    expect('id' in f).toBe(false);
    expect('stats' in f).toBe(false);
    expect(f.source).toBe('/a');
  });

  it('tells clean from dirty and ignores whitespace noise', () => {
    const a = initialForm(stored());
    const b = initialForm(stored());
    expect(isDirty(a, b)).toBe(false);
    b.target = '  /b  ';
    expect(isDirty(a, b)).toBe(false);
    b.target = '/c';
    expect(isDirty(a, b)).toBe(true);
  });

  it('a prefill whose source and target differ only in case becomes case-sensitive (404 suggestion /shop/Zelte)', () => {
    const f = initialForm(null, { source: '/shop/Zelte', target: '/shop/zelte', match_type: 'exact' });
    expect(f.case_sensitive).toBe(true);
    // the caller's own choice wins, other targets and match types are left alone
    expect(initialForm(null, { source: '/shop/Zelte', target: '/shop/zelte', case_sensitive: false }).case_sensitive).toBe(false);
    expect(initialForm(null, { source: '/shop/Zelte', target: '/shop/zelt' }).case_sensitive).toBe(false);
    expect(initialForm(null, { source: '/shop/zelte', target: '/shop/zelte' }).case_sensitive).toBe(false);
    expect(initialForm(null, { source: '/shop/Zelte', target: 'https://example.org/shop/zelte' }).case_sensitive).toBe(false);
    expect(initialForm(null, { source: '/shop/*', target: '/shop/zelte', match_type: 'wildcard' }).case_sensitive).toBe(false);
  });

  it('builds a clean payload', () => {
    const f = initialForm(stored());
    f.source = ' /a ';
    f.tags = ['x', ' x ', '', 'y'];
    f.conditions.hosts = [' Example.ORG '];
    f.conditions.rules = [{ kind: 'header', name: ' ', operator: 'equals', value: 'v', negate: false }, { kind: 'cookie', name: 'sid', operator: 'exists', value: 'ignored', negate: true }];
    f.query_mode = 'params';
    f.query_params = { ' a ': '', b: '1', '': 'drop' };
    const p = formToPayload(f);
    expect(p.source).toBe('/a');
    expect(p.tags).toEqual(['x', 'y']);
    expect(p.conditions?.hosts).toEqual(['example.org']);
    expect(p.conditions?.rules).toEqual([{ kind: 'cookie', name: 'sid', operator: 'exists', value: '', negate: true }]);
    expect(p.query_params).toEqual({ a: null, b: '1' });
  });

  it('sends no target for 410 and 451 and no params outside params mode', () => {
    const f = initialForm(stored({ status: 410 as never, query_params: { a: '1' } }));
    expect(formToPayload(f).target).toBe('');
    expect(formToPayload(f).query_params).toEqual({});
    expect(needsTarget(410)).toBe(false);
    expect(needsTarget(200)).toBe(true);
  });

  it('diffs against the stored payload for PATCH', () => {
    const base = formToPayload(initialForm(stored()));
    const f = initialForm(stored());
    f.target = '/new';
    f.tags = ['t'];
    expect(diffPayload(base, formToPayload(f))).toEqual({ target: '/new', tags: ['t'] });
    expect(diffPayload(base, base)).toEqual({});
  });

  it('runs quick client checks', () => {
    const f = initialForm(null, {});
    expect(clientChecks(f).map((p) => p.field)).toEqual(['source', 'target']);
    f.source = 'no-slash';
    expect(clientChecks(f)[0].code).toBe('EDITOR.ERR_SOURCE_SLASH');
    f.match_type = 'regex';
    f.source = '(unclosed';
    expect(clientChecks(f)[0].code).toBe('EDITOR.ERR_REGEX_INVALID');
    f.source = '^/ok$';
    f.status = 410;
    expect(clientChecks(f)).toEqual([]);
    f.active_from = '2026-09-02T10:00:00Z';
    f.expires_at = '2026-09-01T10:00:00Z';
    expect(clientChecks(f)[0].field).toBe('expires_at');
  });

  it('maps server issues to fields and finds blockers', () => {
    expect(issueField({ code: 'regex_invalid', severity: 'error', message: '' })).toBe('source');
    expect(issueField({ code: 'target_scheme', severity: 'error', message: '' })).toBe('target');
    expect(issueField({ code: 'x', field: 'status', severity: 'error', message: '' } as never)).toBe('status');
    expect(issueField({ code: 'chain', severity: 'warning', message: '' })).toBe('general');
    expect(hasBlockingIssue([{ code: 'chain', severity: 'warning', message: '' }])).toBe(false);
    expect(hasBlockingIssue([{ code: 'loop', severity: 'error', message: '' }])).toBe(true);
  });

  it('opens only the sections that hold non-default values', () => {
    expect(Object.values(advancedState(initialForm(null, {}))).some(Boolean)).toBe(false);
    const f = initialForm(stored({ group: 'Blog', query_mode: 'pass' }));
    expect(advancedState(f)).toMatchObject({ organise: true, query: true, schedule: false });
  });
});
