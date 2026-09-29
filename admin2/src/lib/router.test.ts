import { describe, expect, it } from 'vitest';
import { buildHash, parseHash, tabOf } from './router';

describe('hash router', () => {
  it('parses the known routes', () => {
    expect(parseHash('#/rules').name).toBe('rules');
    expect(parseHash('#/rules/new').name).toBe('rule-new');
    expect(parseHash('#/rules/r0192f3')).toMatchObject({ name: 'rule-edit', id: 'r0192f3' });
    expect(parseHash('#/404').name).toBe('notfound');
    expect(parseHash('#/suggestions').name).toBe('suggestions');
    expect(parseHash('#/import').name).toBe('import');
    expect(parseHash('#/export').name).toBe('export');
    expect(parseHash('#/settings').name).toBe('settings');
  });

  it('parses the tester deep link with its query', () => {
    const r = parseHash('#/tester?url=%2Fold%3Fa%3D1');
    expect(r.name).toBe('tester');
    expect(r.query.url).toBe('/old?a=1');
  });

  it('falls back to the rules list for empty or unknown hashes', () => {
    expect(parseHash('').name).toBe('rules');
    expect(parseHash('#').name).toBe('rules');
    expect(parseHash('#/nonsense/1')).toMatchObject({ name: 'rules', query: {} });
    expect(parseHash('#anchor').name).toBe('rules');
  });

  it('survives malformed percent encoding', () => {
    expect(parseHash('#/rules/%E0%A4%A').id).toBe('%E0%A4%A');
  });

  it('builds hashes and drops empty query values', () => {
    expect(buildHash({ name: 'rules' })).toBe('#/rules');
    expect(buildHash({ name: 'notfound', query: { days: 30, bots: false, q: '' } })).toBe('#/404?days=30');
    expect(buildHash({ name: 'rule-edit', id: 'a/b' })).toBe('#/rules/a%2Fb');
    expect(buildHash({ name: 'tester', query: { url: '/x y' } })).toBe('#/tester?url=%2Fx+y');
  });

  it('round-trips', () => {
    const h = buildHash({ name: 'rules', query: { q: 'shop', page: 3 } });
    expect(parseHash(h)).toMatchObject({ name: 'rules', query: { q: 'shop', page: '3' } });
  });

  it('maps routes to tabs', () => {
    expect(tabOf(parseHash('#/rules/new'))).toBe('rules');
    expect(tabOf(parseHash('#/import'))).toBe('importexport');
    expect(tabOf(parseHash('#/export'))).toBe('importexport');
    expect(tabOf(parseHash('#/404'))).toBe('notfound');
  });
});
