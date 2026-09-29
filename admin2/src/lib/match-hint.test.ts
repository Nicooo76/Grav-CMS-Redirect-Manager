import { describe, expect, it } from 'vitest';
import { looksLikeRegex, suggestMatchType } from './match-hint';

describe('looksLikeRegex', () => {
  it('spots regular expression syntax', () => {
    for (const s of ['^/blog/(.*)$', '/blog/(\\d+)', '/a|/b', '/shop/[a-z]+', '/x/.*', '/news/\\d{4}', '/a$']) expect(looksLikeRegex(s), s).toBe(true);
  });
  it('leaves ordinary paths and wildcards alone', () => {
    for (const s of ['/old-page', '/blog/*', '/file.html', '/a/b/', '/café', '/a+b', '']) expect(looksLikeRegex(s), s).toBe(false);
  });
});

describe('suggestMatchType', () => {
  it('offers wildcard for an exact source with *', () => {
    expect(suggestMatchType('/blog/*', 'exact')).toBe('wildcard');
    expect(suggestMatchType('/old-page', 'exact')).toBeNull();
  });
  it('offers regex for anchors and groups, whatever the selection', () => {
    expect(suggestMatchType('^/blog/(.*)$', 'exact')).toBe('regex');
    expect(suggestMatchType('^/blog/(.*)$', 'wildcard')).toBe('regex');
    expect(suggestMatchType('^/blog/(.*)$', 'regex')).toBeNull();
  });
  it('offers wildcard for a regex-typed plain path with *', () => {
    expect(suggestMatchType('/blog/*', 'regex')).toBe('wildcard');
    expect(suggestMatchType('/blog/.*', 'regex')).toBeNull();
  });
  it('says nothing for an empty source', () => {
    expect(suggestMatchType('  ', 'exact')).toBeNull();
  });
});
