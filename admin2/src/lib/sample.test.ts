import { describe, expect, it } from 'vitest';
import { sampleFromRegex, sampleFromSource } from './sample';

describe('sample URLs', () => {
  it('uses exact sources as they are', () => {
    expect(sampleFromSource('/old-page', 'exact')).toBe('/old-page');
    expect(sampleFromSource('old', 'exact')).toBe('/old');
    expect(sampleFromSource('  ', 'exact')).toBe('');
  });
  it('fills wildcards', () => {
    expect(sampleFromSource('/blog/*', 'wildcard')).toBe('/blog/example');
    expect(sampleFromSource('/*/print', 'wildcard')).toBe('/example/print');
  });
  it('generates paths that match simple regular expressions', () => {
    const cases: [string, RegExp][] = [
      ['^/blog/(\\d{4})/(.*)$', /^\/blog\/\d{4}\/.+$/],
      ['^/product/(?<slug>[^/]+)$', /^\/product\/[^/]+$/],
      ['^/(de|en)/about$', /^\/de\/about$/],
      ['^/news/(\\d+)/?$', /^\/news\/\d+\/?$/],
      ['^/shop/([a-z]+)-(\\d+)\\.html$', /^\/shop\/[a-z]+-\d+\.html$/],
    ];
    for (const [src, re] of cases) {
      const s = sampleFromRegex(src);
      expect(s, `${src} -> ${s}`).toMatch(re);
      expect(new RegExp(src).test(s), `${src} matches ${s}`).toBe(true);
    }
  });
  it('prefixes an origin when asked', () => {
    expect(sampleFromSource('/a', 'exact', 'https://example.org/')).toBe('https://example.org/a');
  });
});
