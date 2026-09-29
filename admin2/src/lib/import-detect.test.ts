import { describe, expect, it } from 'vitest';
import { FALLBACK_FORMATS, detectFormat, isAcceptedFile, isGzip, pickFormat, usesColumnMapping } from './import-detect';

describe('detectFormat by file name', () => {
  it('recognises names that say everything', () => {
    expect(detectFormat('_redirects', '/a /b 301')).toBe('netlify');
    expect(detectFormat('.htaccess', 'anything')).toBe('htaccess');
    expect(detectFormat('site.htaccess', 'anything')).toBe('htaccess');
    expect(detectFormat('C:\\tmp\\redirects.nginx.conf', 'x')).toBe('nginx');
  });
  it('is case-insensitive', () => {
    expect(detectFormat('REDIRECTS.JSON', '[]')).toBe('json');
  });
  it('returns null for an empty file, whatever the name', () => {
    expect(detectFormat('a.csv', '  \n')).toBeNull();
    expect(detectFormat('a.json', '')).toBeNull();
  });
  it('returns null for xml (a sitemap, not a redirect list)', () => {
    expect(detectFormat('sitemap.xml', '<urlset/>')).toBeNull();
  });
});

describe('detectFormat by content', () => {
  it('json: rules list and wordpress export', () => {
    expect(detectFormat('r.json', '[{"source":"/a","target":"/b"}]')).toBe('json');
    expect(detectFormat('r.json', '{"redirects":[{"url":"/a"}]}')).toBe('wordpress_json');
    expect(detectFormat('r.json', '{"rules":[]}')).toBe('json');
    expect(detectFormat('r.json', '{broken')).toBe('json');
  });
  it('sniffs json without a file name', () => {
    expect(detectFormat('', '  [1]')).toBe('json');
  });
  it('csv: plain, semicolon, tsv', () => {
    expect(detectFormat('r.csv', 'source,target,status\n/a,/b,301')).toBe('csv');
    expect(detectFormat('r.csv', 'from;to\n/a;/b')).toBe('csv');
    expect(detectFormat('r.tsv', '/a\t/b\n/c\t/d')).toBe('csv');
  });
  it('csv variants by header', () => {
    expect(detectFormat('c.csv', 'Source URL,Target URL\n/a,/b')).toBe('cloudflare_csv');
    expect(detectFormat('c.csv', 'Address,Status Code,Indexability\n/a,404,x')).toBe('crawler_csv');
    expect(detectFormat('c.csv', 'source,target,regex,code\n/a,/b,0,301')).toBe('wordpress_csv');
  });
  it('csv with a BOM', () => {
    expect(detectFormat('c.csv', '\uFEFFsource_url,target_url\n/a,/b')).toBe('cloudflare_csv');
  });
  it('htaccess and nginx by directive', () => {
    expect(detectFormat('x.txt', 'Redirect 301 /old /new')).toBe('htaccess');
    expect(detectFormat('x.txt', 'RewriteEngine On\nRewriteRule ^a$ /b [R=301,L]')).toBe('htaccess');
    expect(detectFormat('x.txt', 'RedirectMatch 301 ^/x/(.*)$ /y/$1')).toBe('htaccess');
    expect(detectFormat('x.txt', 'rewrite ^/a$ /b permanent;')).toBe('nginx');
    expect(detectFormat('x.conf', 'location = /a { return 301 /b; }')).toBe('nginx');
    expect(detectFormat('x.conf', 'Redirect 301 /a /b')).toBe('htaccess');
  });
  it('netlify _redirects pasted as text', () => {
    expect(detectFormat('r.txt', '# comment\n/old /new 301\n/blog/* /news/:splat 302')).toBe('netlify');
  });
  it('yaml: rules list, flat map, grav site', () => {
    expect(detectFormat('r.yaml', 'rules:\n  - source: /a\n    target: /b')).toBe('yaml');
    expect(detectFormat('r.yml', '/a: /b\n/c: /d')).toBe('yaml');
    expect(detectFormat('site.yaml', 'redirects:\n  /a: /b\nroutes:\n  /x: /y')).toBe('grav_site');
    expect(detectFormat('x.txt', 'redirects:\n  /a: /b')).toBe('grav_site');
  });
  it('does not mistake a flat yaml map for netlify', () => {
    expect(detectFormat('x.txt', '/a: /b\n/c: /d')).toBe('yaml');
  });
  it('gives up on prose', () => {
    expect(detectFormat('x.txt', 'hello world')).toBeNull();
  });
});

describe('helpers', () => {
  it('pickFormat only returns importable ids the server lists', () => {
    expect(pickFormat('csv', FALLBACK_FORMATS)).toBe('csv');
    expect(pickFormat('cloudflare_csv', FALLBACK_FORMATS)).toBeNull();
    expect(pickFormat('crawler_csv', FALLBACK_FORMATS)).toBeNull();
    expect(pickFormat(null, FALLBACK_FORMATS)).toBeNull();
  });
  it('column mapping is for csv only', () => {
    expect(usesColumnMapping('csv')).toBe(true);
    expect(usesColumnMapping('wordpress_csv')).toBe(false);
    expect(usesColumnMapping(null)).toBe(false);
  });
  it('accepts the documented extensions and special names', () => {
    for (const n of ['a.csv', 'a.JSON', 'a.yaml', 'a.yml', 'x.htaccess', 'nginx.conf', 'r.txt', 'sitemap.xml', '_redirects', '.htaccess']) {
      expect(isAcceptedFile(n)).toBe(true);
    }
    expect(isAcceptedFile('photo.png')).toBe(false);
    expect(isAcceptedFile('archive.zip')).toBe(false);
  });
  it('isGzip', () => {
    expect(isGzip('sitemap.xml.gz')).toBe(true);
    expect(isGzip('sitemap.xml')).toBe(false);
  });
});
