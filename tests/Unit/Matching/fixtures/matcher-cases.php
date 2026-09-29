<?php

declare(strict_types=1);

/**
 * Case table for MatcherTableTest.
 *
 * Each case: rules (Rule::fromArray rows), request, expected outcome, options.
 *
 *  - request: a raw path string, or ['path' => raw path, 'query' => [], 'host' => ..., 'scheme' => ...,
 *    'lang' => ..., 'headers' => [lowercase name => value], 'cookies' => []]. The harness runs the raw path
 *    through PathNormalizer::normalize() exactly like the request factory does; a path the normalizer
 *    rejects (NUL, bad UTF-8, ...) means "never matched".
 *  - expect: null for "no rule applies", else [status, location, deciding rule id, optional list of all applied rule ids].
 *  - opts: phase (early|not_found|any), now (ISO date), default_lang, allowed_hosts, allow_any, max_chain, global_ignore.
 *
 * @return array<string, array{rules: list<array<string, mixed>>, request: array<string, mixed>|string, expect: array<int, mixed>|null, opts: array<string, mixed>}>
 */

$cases = [];

$add = static function (string $name, array $rules, array|string $request, ?array $expect, array $opts = []) use (&$cases): void {
    if (isset($cases[$name])) {
        throw new LogicException('Duplicate case name: ' . $name);
    }
    $cases[$name] = ['rules' => $rules, 'request' => $request, 'expect' => $expect, 'opts' => $opts];
};

/** Exact rule. */
$e = static fn (string $id, string $source, string $target = '/new', array $extra = []): array => array_merge(['id' => $id, 'source' => $source, 'target' => $target], $extra);
/** Wildcard rule. */
$w = static fn (string $id, string $source, string $target = '/new/$1', array $extra = []): array => array_merge(['id' => $id, 'source' => $source, 'target' => $target, 'match_type' => 'wildcard'], $extra);
/** Regex rule. */
$x = static fn (string $id, string $source, string $target = '/new/$1', array $extra = []): array => array_merge(['id' => $id, 'source' => $source, 'target' => $target, 'match_type' => 'regex'], $extra);

// ---------------------------------------------------------------- exact
$add('exact: match', [$e('r1', '/old')], '/old', [301, '/new', 'r1']);
$add('exact: other path does not match', [$e('r1', '/old')], '/other', null);
$add('exact: prefix is not a match', [$e('r1', '/old')], '/old/sub', null);
$add('exact: request with trailing slash (tolerant by default)', [$e('r1', '/old')], '/old/', [301, '/new', 'r1']);
$add('exact: source with trailing slash, request without', [$e('r1', '/old/')], '/old', [301, '/new', 'r1']);
$add('exact: strict slash, request has slash', [$e('r1', '/old', '/new', ['ignore_trailing_slash' => false])], '/old/', null);
$add('exact: strict slash, source has slash, request has slash', [$e('r1', '/old/', '/new', ['ignore_trailing_slash' => false])], '/old/', [301, '/new', 'r1']);
$add('exact: strict slash, source has slash, request has none', [$e('r1', '/old/', '/new', ['ignore_trailing_slash' => false])], '/old', null);
$add('exact: case-insensitive by default', [$e('r1', '/old')], '/OLD', [301, '/new', 'r1']);
$add('exact: case-insensitive, source in upper case', [$e('r1', '/OLD/Page')], '/old/page', [301, '/new', 'r1']);
$add('exact: case-sensitive rejects other case', [$e('r1', '/old', '/new', ['case_sensitive' => true])], '/OLD', null);
$add('exact: case-sensitive accepts same case', [$e('r1', '/Old', '/new', ['case_sensitive' => true])], '/Old', [301, '/new', 'r1']);
$add('exact: root path', [$e('r1', '/', '/home')], '/', [301, '/home', 'r1']);
$add('exact: source without leading slash', [$e('r1', 'old')], '/old', [301, '/new', 'r1']);
$add('exact: source with double slash is normalized', [$e('r1', '/a//b')], '/a/b', [301, '/new', 'r1']);
$add('exact: source with dot segments is normalized', [$e('r1', '/a/./x/../b')], '/a/b', [301, '/new', 'r1']);
$add('exact: source with surrounding blanks is trimmed', [$e('r1', '  /old ')], '/old', [301, '/new', 'r1']);
$add('exact: empty source is dropped', [$e('r1', '')], '/', null);
$add('exact: source with NUL byte is dropped', [$e('r1', "/old\0")], '/old', null);
$add('exact: source with invalid UTF-8 is dropped', [$e('r1', "/old%ff")], '/old', null);
$add('exact: many rules for the same path, higher priority first', [$e('r1', '/old', '/a', ['priority' => 1]), $e('r2', '/old', '/b', ['priority' => 2])], '/old', [301, '/b', 'r2']);

// ---------------------------------------------------------------- url encoding and normalization
$add('encoding: %20 in request', [$e('r1', '/my page')], '/my%20page', [301, '/new', 'r1']);
$add('encoding: %20 in source', [$e('r1', '/my%20page')], '/my page', [301, '/new', 'r1']);
$add('encoding: %20 on both sides', [$e('r1', '/my%20page')], '/my%20page', [301, '/new', 'r1']);
$add('encoding: plus stays plus', [$e('r1', '/a+b')], '/a+b', [301, '/new', 'r1']);
$add('encoding: plus is not a space', [$e('r1', '/a b')], '/a+b', null);
$add('encoding: %20 is not a plus', [$e('r1', '/a+b')], '/a%20b', null);
$add('encoding: umlaut percent-encoded', [$e('r1', '/über')], '/%C3%BCber', [301, '/new', 'r1']);
$add('encoding: umlaut percent-encoded in lower case hex', [$e('r1', '/über')], '/%c3%bcber', [301, '/new', 'r1']);
$add('encoding: umlaut in source encoded, request plain', [$e('r1', '/%C3%BCber')], '/über', [301, '/new', 'r1']);
$add('encoding: NFD request matches NFC source', [$e('r1', "/caf\u{e9}")], "/cafe\u{301}", [301, '/new', 'r1']);
$add('encoding: NFD encoded request matches NFC source', [$e('r1', "/caf\u{e9}")], '/cafe%CC%81', [301, '/new', 'r1']);
$add('encoding: NFC request matches NFD source', [$e('r1', "/cafe\u{301}")], "/caf\u{e9}", [301, '/new', 'r1']);
$add('encoding: case-insensitive with umlauts', [$e('r1', '/ÜBER')], '/%C3%BCber', [301, '/new', 'r1']);
$add('encoding: double encoded is decoded once', [$e('r1', '/a b')], '/a%2520b', null);
$add('encoding: double encoded on both sides is equal', [$e('r1', '/a%2520b')], '/a%2520b', [301, '/new', 'r1']);
$add('encoding: %2F acts as slash', [$e('r1', '/a/b')], '/a%2Fb', [301, '/new', 'r1']);
$add('encoding: backslash acts as slash', [$e('r1', '/a/b')], '/a%5Cb', [301, '/new', 'r1']);
$add('encoding: raw backslash acts as slash', [$e('r1', '/a/b')], '/a\\b', [301, '/new', 'r1']);
$add('encoding: double slash in request', [$e('r1', '/old')], '//old', [301, '/new', 'r1']);
$add('encoding: many slashes in the middle', [$e('r1', '/a/b/c')], '/a///b//c', [301, '/new', 'r1']);
$add('encoding: dot segments in request', [$e('r1', '/old')], '/x/../old', [301, '/new', 'r1']);
$add('encoding: single dot segments in request', [$e('r1', '/a/b')], '/a/./b', [301, '/new', 'r1']);
$add('encoding: traversal cannot leave the root', [$e('r1', '/old')], '/../../old', [301, '/new', 'r1']);
$add('encoding: traversal resolves to /etc/passwd, nothing more', [$e('r1', '/etc/passwd', '/blocked')], '/a/../../etc/passwd', [301, '/blocked', 'r1']);
$add('encoding: traversal does not match the traversal source', [$e('r1', '/a')], '/a/../../etc/passwd', null);
$add('encoding: encoded traversal', [$e('r1', '/old')], '/x/%2e%2e/old', [301, '/new', 'r1']);
$add('encoding: NUL byte in request', [$e('r1', '/old')], "/old\0", null);
$add('encoding: %00 in request', [$e('r1', '/old')], '/old%00', null);
$add('encoding: %00 in the middle of the request', [$e('r1', '/old')], '/ol%00d', null);
$add('encoding: CRLF in request', [$e('r1', '/old')], '/old%0d%0aSet-Cookie:x', null);
$add('encoding: invalid UTF-8 in request', [$e('r1', '/old')], '/old%ff', null);
$add('encoding: overlong request path', [$w('r1', '/*', '/new')], '/' . str_repeat('a', 3000), null);
$add('encoding: invalid percent sequence stays literal', [$e('r1', '/100%zz')], '/100%zz', [301, '/new', 'r1']);

// ---------------------------------------------------------------- wildcard
$add('wildcard: at the end', [$w('r1', '/blog/*', '/news/$1')], '/blog/post', [301, '/news/post', 'r1']);
$add('wildcard: at the end, empty rest', [$w('r1', '/blog/*', '/news/$1')], '/blog/', [301, '/news/', 'r1']);
$add('wildcard: at the end, request without slash (tolerant)', [$w('r1', '/blog/*', '/news/$1')], '/blog', [301, '/news/', 'r1']);
$add('wildcard: strict slash, request without slash', [$w('r1', '/blog/*', '/news/$1', ['ignore_trailing_slash' => false])], '/blog', null);
$add('wildcard: strict slash, empty rest', [$w('r1', '/blog/*', '/news/$1', ['ignore_trailing_slash' => false])], '/blog/', [301, '/news/', 'r1']);
$add('wildcard: star spans several segments', [$w('r1', '/blog/*', '/news/$1')], '/blog/a/b/c', [301, '/news/a/b/c', 'r1']);
$add('wildcard: keeps the request trailing slash', [$w('r1', '/blog/*', '/news/$1')], '/blog/a/', [301, '/news/a/', 'r1']);
$add('wildcard: does not match a sibling prefix', [$w('r1', '/blog/*', '/news/$1')], '/blogger/x', null);
$add('wildcard: at the start', [$w('r1', '*.pdf', '/files/$1.pdf')], '/docs/a.pdf', [301, '/files/docs/a.pdf', 'r1']);
$add('wildcard: at the start, no match', [$w('r1', '*.pdf', '/files/$1.pdf')], '/docs/a.txt', null);
$add('wildcard: in the middle', [$w('r1', '/a/*/c', '/b/$1/d')], '/a/x/c', [301, '/b/x/d', 'r1']);
$add('wildcard: in the middle spans segments', [$w('r1', '/a/*/c', '/b/$1/d')], '/a/x/y/c', [301, '/b/x/y/d', 'r1']);
$add('wildcard: double slash in the request does not feed the middle star', [$w('r1', '/a/*/c', '/b/$1/d')], '/a//c', null);
$add('wildcard: empty capture collapses the slash in the target', [$w('r1', '/a*/c', '/b/$1/d')], '/a/c', [301, '/b/d', 'r1']);
$add('wildcard: two stars, swapped', [$w('r1', '/a/*/b/*', '/n/$2/$1')], '/a/1/b/2', [301, '/n/2/1', 'r1']);
$add('wildcard: two stars, second empty', [$w('r1', '/a/*/b/*', '/n/$1-$2')], '/a/1/b/', [301, '/n/1-', 'r1']);
$add('wildcard: three stars', [$w('r1', '/*/*/*', '/$3/$2/$1')], '/a/b/c', [301, '/c/b/a', 'r1']);
$add('wildcard: ${n} syntax', [$w('r1', '/a/*', '/n/${1}x')], '/a/q', [301, '/n/qx', 'r1']);
$add('wildcard: $10 is $1 followed by 0', [$w('r1', '/a/*', '/n/$10')], '/a/q', [301, '/n/q0', 'r1']);
$add('wildcard: unknown group is empty', [$w('r1', '/a/*', '/n/$5x')], '/a/q', [301, '/n/x', 'r1']);
$add('wildcard: ${10} with ten stars', [$w('r1', '/*/*/*/*/*/*/*/*/*/*', '/${10}')], '/1/2/3/4/5/6/7/8/9/ten', [301, '/ten', 'r1']);
$add('wildcard: capture with question mark is encoded', [$w('r1', '/blog/*', '/news/$1')], '/blog/a%3Fb=1', [301, '/news/a%3Fb=1', 'r1']);
$add('wildcard: capture with hash is encoded', [$w('r1', '/blog/*', '/news/$1')], '/blog/a%23b', [301, '/news/a%23b', 'r1']);
$add('wildcard: capture with percent is encoded', [$w('r1', '/blog/*', '/news/$1')], '/blog/50%2525', [301, '/news/50%2525', 'r1']);
$add('wildcard: capture with space is encoded', [$w('r1', '/blog/*', '/news/$1')], '/blog/a%20b', [301, '/news/a%20b', 'r1']);
$add('wildcard: capture with umlaut is encoded', [$w('r1', '/blog/*', '/news/$1')], '/blog/caf%C3%A9', [301, '/news/caf%C3%A9', 'r1']);
$add('wildcard: capture in the query part is fully encoded', [$w('r1', '/s/*', '/search?q=$1')], '/s/a&b=c+d', [301, '/search?q=a%26b%3Dc%2Bd', 'r1']);
$add('wildcard: capture keeps case, rule ignores case', [$w('r1', '/BLOG/*', '/news/$1')], '/blog/MixedCase', [301, '/news/MixedCase', 'r1']);
$add('wildcard: case-sensitive rejects other case', [$w('r1', '/BLOG/*', '/news/$1', ['case_sensitive' => true])], '/blog/x', null);
$add('wildcard: case-sensitive accepts same case', [$w('r1', '/BLOG/*', '/news/$1', ['case_sensitive' => true])], '/BLOG/x', [301, '/news/x', 'r1']);
$add('wildcard: catch-all', [$w('r1', '/*', '/all/$1')], '/x/y', [301, '/all/x/y', 'r1']);
$add('wildcard: catch-all matches the root', [$w('r1', '/*', '/all/$1')], '/', [301, '/all/', 'r1']);
$add('wildcard: partial segment', [$w('r1', '/img/pic*', '/i/$1')], '/img/pic123.png', [301, '/i/123.png', 'r1']);
$add('wildcard: partial segment, no match', [$w('r1', '/img/pic*', '/i/$1')], '/img/other', null);
$add('wildcard: double star collapses', [$w('r1', '/a/**', '/n/$1')], '/a/x/y', [301, '/n/x/y', 'r1']);
$add('wildcard: no star behaves like exact', [$w('r1', '/lit/path', '/n')], '/lit/path', [301, '/n', 'r1']);
$add('wildcard: no star, other path', [$w('r1', '/lit/path', '/n')], '/lit/other', null);
$add('wildcard: regex characters in the source are literal', [$w('r1', '/a.b(c)+/*', '/n/$1')], '/a.b(c)+/z', [301, '/n/z', 'r1']);
$add('wildcard: regex characters do not act as regex', [$w('r1', '/a.b/*', '/n/$1')], '/axb/z', null);
$add('wildcard: hash character in the source', [$w('r1', '/t/#*', '/n/$1')], '/t/%23abc', [301, '/n/abc', 'r1']);
$add('wildcard: unicode source', [$w('r1', '/über/*', '/n/$1')], '/%C3%BCber/x', [301, '/n/x', 'r1']);
$add('wildcard: deeper prefix wins by priority', [$w('r1', '/a/*', '/one/$1'), $w('r2', '/a/b/*', '/two/$1', ['priority' => 5])], '/a/b/c', [301, '/two/c', 'r2']);
$add('wildcard: shallow rule still matches other paths', [$w('r1', '/a/*', '/one/$1'), $w('r2', '/a/b/*', '/two/$1', ['priority' => 5])], '/a/x', [301, '/one/x', 'r1']);
$add('wildcard: only the matching branch is a candidate', [$w('r1', '/a/*', '/one/$1'), $w('r2', '/b/*', '/two/$1')], '/b/x', [301, '/two/x', 'r2']);
$add('wildcard: source with query part is split off', [$w('r1', '/a/*?x=1', '/n/$1', ['query_mode' => 'params'])], ['path' => '/a/q', 'query' => ['x' => '1']], [301, '/n/q', 'r1']);
$add('wildcard: pass-through status', [$w('r1', '/a/*', '/n/$1', ['status' => 200])], '/a/q', [200, '/n/q', 'r1']);

// ---------------------------------------------------------------- regex
$add('regex: numbered group', [$x('r1', '^/product/(\d+)$', '/shop/$1')], '/product/42', [301, '/shop/42', 'r1']);
$add('regex: numbered group does not match letters', [$x('r1', '^/product/(\d+)$', '/shop/$1')], '/product/abc', null);
$add('regex: named groups', [$x('r1', '^/(?<year>\d{4})/(?<slug>[a-z-]+)$', '/blog/{year}/{slug}')], '/2024/hello-world', [301, '/blog/2024/hello-world', 'r1']);
$add('regex: named and numbered mixed', [$x('r1', '^/(?<a>\w+)/(\w+)$', '/{a}-$2')], '/x/y', [301, '/x-y', 'r1']);
$add('regex: case-insensitive by default', [$x('r1', '^/Blog/(\d+)$', '/b/$1')], '/blog/5', [301, '/b/5', 'r1']);
$add('regex: case-sensitive when asked', [$x('r1', '^/Blog/(\d+)$', '/b/$1', ['case_sensitive' => true])], '/blog/5', null);
$add('regex: case-sensitive, same case', [$x('r1', '^/Blog/(\d+)$', '/b/$1', ['case_sensitive' => true])], '/Blog/5', [301, '/b/5', 'r1']);
$add('regex: unanchored pattern', [$x('r1', '\.pdf$', '/pdf')], '/a/b.pdf', [301, '/pdf', 'r1']);
$add('regex: alternation inside a group', [$x('r1', '^/(en|de)/about$', '/$1/about-us')], '/de/about', [301, '/de/about-us', 'r1']);
$add('regex: top-level alternation', [$x('r1', '^/a$|^/b$', '/c')], '/b', [301, '/c', 'r1']);
$add('regex: top-level alternation, first branch', [$x('r1', '^/a$|^/b$', '/c')], '/a', [301, '/c', 'r1']);
$add('regex: invalid pattern is ignored', [$x('r1', '^/a(', '/c')], '/a', null);
$add('regex: invalid pattern does not block later rules', [$x('r1', '([', '/c', ['priority' => 10]), $e('r2', '/a', '/d')], '/a', [301, '/d', 'r2']);
$add('regex: unterminated named group is ignored', [$x('r1', '^/a(?<', '/c')], '/a', null);
$add('regex: pattern over 1000 characters is ignored', [$x('r1', '^/' . str_repeat('a', 1000), '/c')], '/' . str_repeat('a', 1000), null);
$add('regex: catastrophic backtracking returns quickly without a match', [$x('r1', '^/(a+)+$', '/c')], '/' . str_repeat('a', 2000) . '!', null);
$add('regex: catastrophic backtracking, unanchored start', [$x('r1', '(a+)+$', '/c')], '/' . str_repeat('a', 2000) . '!', null);
$add('regex: catastrophic alternation', [$x('r1', '^/(a|aa)+$', '/c')], '/' . str_repeat('a', 2000) . '!', null);
$add('regex: catastrophic rule does not block a later rule', [$x('r1', '^/(a+)+$', '/c', ['priority' => 5]), $w('r2', '/*', '/fallback')], '/' . str_repeat('a', 2000) . '!', [301, '/fallback', 'r2']);
$add('regex: catastrophic pattern still matches short input', [$x('r1', '^/(a+)+$', '/c')], '/aaa', [301, '/c', 'r1']);
$add('regex: match everything', [$x('r1', '.*', '/home')], '/anything/here', [301, '/home', 'r1']);
$add('regex: match everything, root', [$x('r1', '.*', '/home')], '/', [301, '/home', 'r1']);
$add('regex: match everything with capture', [$x('r1', '^(.*)$', '/x$1')], '/a', [301, '/x/a', 'r1']);
$add('regex: hash in pattern', [$x('r1', '^/tag/#(\w+)$', '/t/$1')], '/tag/%23abc', [301, '/t/abc', 'r1']);
$add('regex: trailing slash tolerated', [$x('r1', '^/p/(\d+)$', '/q/$1')], '/p/5/', [301, '/q/5', 'r1']);
$add('regex: strict slash rejects trailing slash', [$x('r1', '^/p/(\d+)$', '/q/$1', ['ignore_trailing_slash' => false])], '/p/5/', null);
$add('regex: strict slash accepts exact form', [$x('r1', '^/p/(\d+)$', '/q/$1', ['ignore_trailing_slash' => false])], '/p/5', [301, '/q/5', 'r1']);
$add('regex: unmatched optional group is empty', [$x('r1', '^/a(/b)?$', '/x$1')], '/a', [301, '/x', 'r1']);
$add('regex: bucket picks the rule for the first segment', [$x('r1', '^/blog/(.*)', '/b/$1'), $x('r2', '^/news/(.*)', '/n/$1')], '/news/x', [301, '/n/x', 'r2']);
$add('regex: bucket does not leak to other segments', [$x('r1', '^/blog/(.*)', '/b/$1')], '/blogs/x', null);
$add('regex: bucket, case-insensitive', [$x('r1', '^/Blog$', '/b')], '/BLOG', [301, '/b', 'r1']);
$add('regex: bucket, case-sensitive', [$x('r1', '^/Blog$', '/b', ['case_sensitive' => true])], '/blog', null);
$add('regex: bucket only for a complete segment', [$x('r1', '^/blog?', '/b')], '/blo', [301, '/b', 'r1']);
$add('regex: named group lang feeds {lang}', [$x('r1', '^/(?<lang>de|en)/x$', '/{lang}/y')], '/de/x', [301, '/de/y', 'r1']);
$add('regex: unicode', [$x('r1', '^/über/(.*)$', '/u/$1')], '/%C3%BCber/x', [301, '/u/x', 'r1']);
$add('regex: negative lookahead blocks', [$x('r1', '^/(?!admin)(.*)$', '/pub/$1')], '/admin/x', null);
$add('regex: negative lookahead passes', [$x('r1', '^/(?!admin)(.*)$', '/pub/$1')], '/user', [301, '/pub/user', 'r1']);
$add('regex: escaped dot', [$x('r1', '^/index\.html$', '/')], '/index.html', [301, '/', 'r1']);
$add('regex: escaped dot does not match other characters', [$x('r1', '^/index\.html$', '/')], '/indexxhtml', null);
$add('regex: inline flag', [$x('r1', '(?i)^/CASE$', '/c', ['case_sensitive' => true])], '/case', [301, '/c', 'r1']);
$add('regex: capture with slash injection is rejected', [$x('r1', '^/go(.*)$', '/$1')], '/go/evil.com', null);
$add('regex: regex source is not path-normalized', [$x('r1', '^/a//b$', '/c')], '/a//b', null);
$add('regex: quantified literal is not bucketed', [$x('r1', '^/ab*/x$', '/c')], '/a/x', [301, '/c', 'r1']);
$add('regex: bucket with group alternation stays generic', [$x('r1', '^/(blog|news)/x$', '/c')], '/news/x', [301, '/c', 'r1']);

// ---------------------------------------------------------------- query modes
$add('query ignore: query is dropped', [$e('r1', '/old')], ['path' => '/old', 'query' => ['x' => '1']], [301, '/new', 'r1']);
$add('query ignore: is the default with any params', [$e('r1', '/old')], ['path' => '/old', 'query' => ['a' => '1', 'b' => ['2', '3']]], [301, '/new', 'r1']);
$add('query pass: query is appended', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['x' => '1']], [301, '/new?x=1', 'r1']);
$add('query pass: order stays stable', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['z' => '1', 'a' => '2', 'm' => '3']], [301, '/new?z=1&a=2&m=3', 'r1']);
$add('query pass: no query, no question mark', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], '/old', [301, '/new', 'r1']);
$add('query pass: target params win on conflict', [$e('r1', '/old', '/new?a=1', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['a' => '2', 'b' => '3']], [301, '/new?a=1&b=3', 'r1']);
$add('query pass: target without conflict keeps own params first', [$e('r1', '/old', '/new?a=1', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['b' => '3']], [301, '/new?a=1&b=3', 'r1']);
$add('query pass: array params', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['a' => ['1', '2']]], [301, '/new?a%5B0%5D=1&a%5B1%5D=2', 'r1']);
$add('query pass: array params conflict by base name', [$e('r1', '/old', '/new?a[]=9', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['a' => ['1', '2'], 'b' => '1']], [301, '/new?a[]=9&b=1', 'r1']);
$add('query pass: empty value', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['a' => '']], [301, '/new?a=', 'r1']);
$add('query pass: fragment stays last', [$e('r1', '/old', '/new#top', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['x' => '1']], [301, '/new?x=1#top', 'r1']);
$add('query pass: unicode value is encoded', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['q' => 'café']], [301, '/new?q=caf%C3%A9', 'r1']);
$add('query pass: utm parameters are passed on', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['utm_source' => 'x']], [301, '/new?utm_source=x', 'r1']);
$add('query pass: wildcard with capture', [$w('r1', '/a/*', '/b/$1', ['query_mode' => 'pass'])], ['path' => '/a/x', 'query' => ['p' => '1']], [301, '/b/x?p=1', 'r1']);
$add('query pass: absolute target', [$e('r1', '/old', 'https://partner.example.org/new', ['query_mode' => 'pass', 'target_type' => 'url'])], ['path' => '/old', 'query' => ['x' => '1']], [301, 'https://partner.example.org/new?x=1', 'r1'], ['allowed_hosts' => ['partner.example.org']]);
$add('query exact: same query matches', [$e('r1', '/search?q=shoes', '/new', ['query_mode' => 'exact'])], ['path' => '/search', 'query' => ['q' => 'shoes']], [301, '/new', 'r1']);
$add('query exact: other value', [$e('r1', '/search?q=shoes', '/new', ['query_mode' => 'exact'])], ['path' => '/search', 'query' => ['q' => 'hats']], null);
$add('query exact: missing query', [$e('r1', '/search?q=shoes', '/new', ['query_mode' => 'exact'])], '/search', null);
$add('query exact: extra param', [$e('r1', '/search?q=shoes', '/new', ['query_mode' => 'exact'])], ['path' => '/search', 'query' => ['q' => 'shoes', 'page' => '2']], null);
$add('query exact: order does not matter', [$e('r1', '/s?a=1&b=2', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['b' => '2', 'a' => '1']], [301, '/new', 'r1']);
$add('query exact: utm parameters are ignored', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['q' => '1', 'utm_source' => 'x', 'utm_medium' => 'y']], [301, '/new', 'r1']);
$add('query exact: fbclid, gclid and msclkid are ignored', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['fbclid' => 'a', 'q' => '1', 'gclid' => 'b', 'msclkid' => 'c']], [301, '/new', 'r1']);
$add('query exact: ignored names are case-insensitive', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['q' => '1', 'UTM_Source' => 'x']], [301, '/new', 'r1']);
$add('query exact: rule-level ignore list', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact', 'query_ignore' => ['ref']])], ['path' => '/s', 'query' => ['q' => '1', 'ref' => 'x']], [301, '/new', 'r1']);
$add('query exact: rule-level ignore list with glob', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact', 'query_ignore' => ['track_*']])], ['path' => '/s', 'query' => ['q' => '1', 'track_a' => 'x', 'track_b' => 'y']], [301, '/new', 'r1']);
$add('query exact: parameter not in the ignore list', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact', 'query_ignore' => ['ref']])], ['path' => '/s', 'query' => ['q' => '1', 'other' => 'x']], null);
$add('query exact: source without query, request without query', [$e('r1', '/s', '/new', ['query_mode' => 'exact'])], '/s', [301, '/new', 'r1']);
$add('query exact: source without query, request with query', [$e('r1', '/s', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['x' => '1']], null);
$add('query exact: source without query, request with only ignored params', [$e('r1', '/s', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['utm_campaign' => 'x']], [301, '/new', 'r1']);
$add('query exact: array params in order', [$e('r1', '/s?a[]=1&a[]=2', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['a' => ['1', '2']]], [301, '/new', 'r1']);
$add('query exact: array params in other order', [$e('r1', '/s?a[]=1&a[]=2', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['a' => ['2', '1']]], null);
$add('query exact: keyed array params ignore key order', [$e('r1', '/s?a[x]=1&a[y]=2', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['a' => ['y' => '2', 'x' => '1']]], [301, '/new', 'r1']);
$add('query exact: empty value', [$e('r1', '/s?a=', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['a' => '']], [301, '/new', 'r1']);
$add('query exact: empty value is not a missing param', [$e('r1', '/s?a=', '/new', ['query_mode' => 'exact'])], '/s', null);
$add('query exact: numeric values compare as strings', [$e('r1', '/s?id=5', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['id' => 5]], [301, '/new', 'r1']);
$add('query exact: custom global ignore list', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['q' => '1', 'ref_id' => '3']], [301, '/new', 'r1'], ['global_ignore' => ['ref_*']]);
$add('query exact: default global list replaced', [$e('r1', '/s?q=1', '/new', ['query_mode' => 'exact'])], ['path' => '/s', 'query' => ['q' => '1', 'utm_source' => 'x']], null, ['global_ignore' => []]);
$add('query exact: target does not get the query', [$w('r1', '/s/*?q=1', '/n/$1', ['query_mode' => 'exact'])], ['path' => '/s/a', 'query' => ['q' => '1']], [301, '/n/a', 'r1']);
$add('query params: required param present', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => null]])], ['path' => '/p', 'query' => ['id' => '5']], [301, '/new', 'r1']);
$add('query params: required param missing', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => null]])], '/p', null);
$add('query params: required value equal', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => '5']])], ['path' => '/p', 'query' => ['id' => '5']], [301, '/new', 'r1']);
$add('query params: required value differs', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => '5']])], ['path' => '/p', 'query' => ['id' => '6']], null);
$add('query params: other params are ignored', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => '5']])], ['path' => '/p', 'query' => ['x' => '1', 'id' => '5', 'y' => '2']], [301, '/new', 'r1']);
$add('query params: several required params', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['a' => '1', 'b' => null]])], ['path' => '/p', 'query' => ['a' => '1', 'b' => 'zz']], [301, '/new', 'r1']);
$add('query params: one of several missing', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['a' => '1', 'b' => null]])], ['path' => '/p', 'query' => ['a' => '1']], null);
$add('query params: empty value counts as present', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => null]])], ['path' => '/p', 'query' => ['id' => '']], [301, '/new', 'r1']);
$add('query params: array value contains the required one', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['t' => 'b']])], ['path' => '/p', 'query' => ['t' => ['a', 'b']]], [301, '/new', 'r1']);
$add('query params: array value lacks the required one', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['t' => 'c']])], ['path' => '/p', 'query' => ['t' => ['a', 'b']]], null);
$add('query params: source query adds a requirement', [$e('r1', '/p?tab=1', '/new', ['query_mode' => 'params'])], ['path' => '/p', 'query' => ['tab' => '1']], [301, '/new', 'r1']);
$add('query params: source query requirement fails', [$e('r1', '/p?tab=1', '/new', ['query_mode' => 'params'])], ['path' => '/p', 'query' => ['tab' => '2']], null);
$add('query params: query is not passed on', [$e('r1', '/p', '/new', ['query_mode' => 'params', 'query_params' => ['id' => null]])], ['path' => '/p', 'query' => ['id' => '5']], [301, '/new', 'r1']);
$add('query: falls through to the next rule when params are missing', [$e('r1', '/p', '/with', ['query_mode' => 'params', 'query_params' => ['id' => null], 'priority' => 5]), $e('r2', '/p', '/without')], '/p', [301, '/without', 'r2']);

// ---------------------------------------------------------------- conditions
/** Condition helpers. */
$cond = static fn (array $extra): array => ['conditions' => $extra];
$h = static fn (string $name, string $op, string $value = '', bool $negate = false): array => ['kind' => 'header', 'name' => $name, 'operator' => $op, 'value' => $value, 'negate' => $negate];
$c = static fn (string $name, string $op, string $value = '', bool $negate = false): array => ['kind' => 'cookie', 'name' => $name, 'operator' => $op, 'value' => $value, 'negate' => $negate];

$add('host: exact host', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], ['path' => '/old', 'host' => 'example.com'], [301, '/new', 'r1']);
$add('host: other host', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], ['path' => '/old', 'host' => 'other.com'], null);
$add('host: host is case-insensitive', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], ['path' => '/old', 'host' => 'EXAMPLE.com'], [301, '/new', 'r1']);
$add('host: port is ignored', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], ['path' => '/old', 'host' => 'example.com:8443'], [301, '/new', 'r1']);
$add('host: trailing dot is ignored', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], ['path' => '/old', 'host' => 'example.com.'], [301, '/new', 'r1']);
$add('host: several hosts are alternatives', [$e('r1', '/old', '/new', $cond(['hosts' => ['a.com', 'b.com']]))], ['path' => '/old', 'host' => 'b.com'], [301, '/new', 'r1']);
$add('host: wildcard matches a subdomain', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com']]))], ['path' => '/old', 'host' => 'www.example.com'], [301, '/new', 'r1']);
$add('host: wildcard matches nested subdomains', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com']]))], ['path' => '/old', 'host' => 'a.b.example.com'], [301, '/new', 'r1']);
$add('host: wildcard does not match the apex', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com']]))], ['path' => '/old', 'host' => 'example.com'], null);
$add('host: wildcard does not match a look-alike', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com']]))], ['path' => '/old', 'host' => 'evilexample.com'], null);
$add('host: wildcard and apex together', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com', 'example.com']]))], ['path' => '/old', 'host' => 'example.com'], [301, '/new', 'r1']);
$add('host: condition without a request host', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com']]))], '/old', null);
$add('language: matches', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], ['path' => '/old', 'lang' => 'de'], [301, '/new', 'r1']);
$add('language: other language', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], ['path' => '/old', 'lang' => 'en'], null);
$add('language: list of languages', [$e('r1', '/old', '/new', $cond(['languages' => ['de', 'fr']]))], ['path' => '/old', 'lang' => 'fr'], [301, '/new', 'r1']);
$add('language: request language is case-insensitive', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], ['path' => '/old', 'lang' => 'DE'], [301, '/new', 'r1']);
$add('language: falls back to the default language', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], '/old', [301, '/new', 'r1'], ['default_lang' => 'de']);
$add('language: default language differs', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], '/old', null, ['default_lang' => 'en']);
$add('language: no language at all', [$e('r1', '/old', '/new', $cond(['languages' => ['de']]))], '/old', null);
$add('scheme: https only, https request', [$e('r1', '/old', '/new', $cond(['schemes' => ['https']]))], ['path' => '/old', 'scheme' => 'https'], [301, '/new', 'r1']);
$add('scheme: https only, http request', [$e('r1', '/old', '/new', $cond(['schemes' => ['https']]))], ['path' => '/old', 'scheme' => 'http'], null);
$add('scheme: http only', [$e('r1', '/old', '/new', $cond(['schemes' => ['http']]))], ['path' => '/old', 'scheme' => 'http'], [301, '/new', 'r1']);
$add('scheme: request scheme is case-insensitive', [$e('r1', '/old', '/new', $cond(['schemes' => ['https']]))], ['path' => '/old', 'scheme' => 'HTTPS'], [301, '/new', 'r1']);
$add('header: user agent contains Googlebot', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot')]]))], ['path' => '/old', 'headers' => ['user-agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']], [301, '/new', 'r1']);
$add('header: contains is case-insensitive', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'GOOGLEBOT')]]))], ['path' => '/old', 'headers' => ['user-agent' => 'x googlebot y']], [301, '/new', 'r1']);
$add('header: user agent without Googlebot', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot')]]))], ['path' => '/old', 'headers' => ['user-agent' => 'Mozilla/5.0 Firefox']], null);
$add('header: missing header', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot')]]))], '/old', null);
$add('header: negated contains (not Googlebot)', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot', true)]]))], ['path' => '/old', 'headers' => ['user-agent' => 'Firefox']], [301, '/new', 'r1']);
$add('header: negated contains blocks Googlebot', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot', true)]]))], ['path' => '/old', 'headers' => ['user-agent' => 'Googlebot']], null);
$add('header: negated contains with a missing header', [$e('r1', '/old', '/new', $cond(['rules' => [$h('User-Agent', 'contains', 'Googlebot', true)]]))], '/old', [301, '/new', 'r1']);
$add('header: name is case-insensitive', [$e('r1', '/old', '/new', $cond(['rules' => [$h('X-CUSTOM-Header', 'exists')]]))], ['path' => '/old', 'headers' => ['x-custom-header' => 'v']], [301, '/new', 'r1']);
$add('header: exists', [$e('r1', '/old', '/new', $cond(['rules' => [$h('X-Foo', 'exists')]]))], ['path' => '/old', 'headers' => ['x-foo' => '']], [301, '/new', 'r1']);
$add('header: exists, absent', [$e('r1', '/old', '/new', $cond(['rules' => [$h('X-Foo', 'exists')]]))], '/old', null);
$add('header: not exists (negate)', [$e('r1', '/old', '/new', $cond(['rules' => [$h('X-Foo', 'exists', '', true)]]))], '/old', [301, '/new', 'r1']);
$add('header: not exists (negate), header present', [$e('r1', '/old', '/new', $cond(['rules' => [$h('X-Foo', 'exists', '', true)]]))], ['path' => '/old', 'headers' => ['x-foo' => '1']], null);
$add('header: equals ignores case', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Accept-Language', 'equals', 'DE-de')]]))], ['path' => '/old', 'headers' => ['accept-language' => 'de-DE']], [301, '/new', 'r1']);
$add('header: equals is not contains', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Accept-Language', 'equals', 'de')]]))], ['path' => '/old', 'headers' => ['accept-language' => 'de-DE']], null);
$add('header: starts_with', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Accept-Language', 'starts_with', 'DE')]]))], ['path' => '/old', 'headers' => ['accept-language' => 'de-DE,de;q=0.9']], [301, '/new', 'r1']);
$add('header: starts_with, wrong start', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Accept-Language', 'starts_with', 'en')]]))], ['path' => '/old', 'headers' => ['accept-language' => 'de-DE,en;q=0.9']], null);
$add('header: referer domain regex', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', '^https://partner\.example\.org/')]]))], ['path' => '/old', 'headers' => ['referer' => 'https://partner.example.org/page?x=1']], [301, '/new', 'r1']);
$add('header: referer domain regex, other domain', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', '^https://partner\.example\.org/')]]))], ['path' => '/old', 'headers' => ['referer' => 'https://evil.com/partner.example.org/']], null);
$add('header: regex is case-sensitive without inline flag', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', '^HTTPS://')]]))], ['path' => '/old', 'headers' => ['referer' => 'https://a.com']], null);
$add('header: regex with inline case flag', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', '(?i)^HTTPS://')]]))], ['path' => '/old', 'headers' => ['referer' => 'https://a.com']], [301, '/new', 'r1']);
$add('header: negated regex', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', 'google', true)]]))], ['path' => '/old', 'headers' => ['referer' => 'https://bing.com']], [301, '/new', 'r1']);
$add('header: regex on a missing header does not match', [$e('r1', '/old', '/new', $cond(['rules' => [$h('Referer', 'regex', '.*')]]))], '/old', null);
$add('header: catastrophic regex fails closed, next rule applies', [$e('r1', '/old', '/hit', ['priority' => 5] + $cond(['rules' => [$h('Referer', 'regex', '^(a+)+$')]])), $e('r2', '/old', '/fallback')], ['path' => '/old', 'headers' => ['referer' => str_repeat('a', 3000) . '!']], [301, '/fallback', 'r2']);
$add('header: regex with invalid UTF-8 value fails closed', [$e('r1', '/old', '/hit', $cond(['rules' => [$h('X-Bin', 'regex', '^a')]]))], ['path' => '/old', 'headers' => ['x-bin' => "a\xff"]], null);
$add('header: invalid condition regex drops the rule', [$e('r1', '/old', '/hit', ['priority' => 5] + $cond(['rules' => [$h('Referer', 'regex', '([')]])), $e('r2', '/old', '/fallback')], ['path' => '/old', 'headers' => ['referer' => 'x']], [301, '/fallback', 'r2']);
$add('cookie: exists', [$e('r1', '/old', '/new', $cond(['rules' => [$c('session', 'exists')]]))], ['path' => '/old', 'cookies' => ['session' => 'abc']], [301, '/new', 'r1']);
$add('cookie: exists, absent', [$e('r1', '/old', '/new', $cond(['rules' => [$c('session', 'exists')]]))], '/old', null);
$add('cookie: name is case-sensitive', [$e('r1', '/old', '/new', $cond(['rules' => [$c('Session', 'exists')]]))], ['path' => '/old', 'cookies' => ['session' => 'abc']], null);
$add('cookie: equals', [$e('r1', '/old', '/new', $cond(['rules' => [$c('ab', 'equals', 'B')]]))], ['path' => '/old', 'cookies' => ['ab' => 'b']], [301, '/new', 'r1']);
$add('cookie: equals, other value', [$e('r1', '/old', '/new', $cond(['rules' => [$c('ab', 'equals', 'B')]]))], ['path' => '/old', 'cookies' => ['ab' => 'a']], null);
$add('cookie: contains', [$e('r1', '/old', '/new', $cond(['rules' => [$c('prefs', 'contains', 'dark')]]))], ['path' => '/old', 'cookies' => ['prefs' => 'x=1;theme=dark']], [301, '/new', 'r1']);
$add('cookie: regex', [$e('r1', '/old', '/new', $cond(['rules' => [$c('uid', 'regex', '^\d{3}$')]]))], ['path' => '/old', 'cookies' => ['uid' => '123']], [301, '/new', 'r1']);
$add('cookie: regex, no match', [$e('r1', '/old', '/new', $cond(['rules' => [$c('uid', 'regex', '^\d{3}$')]]))], ['path' => '/old', 'cookies' => ['uid' => '1234']], null);
$add('cookie: negated equals with absent cookie', [$e('r1', '/old', '/new', $cond(['rules' => [$c('ab', 'equals', 'B', true)]]))], '/old', [301, '/new', 'r1']);
$add('cookie: starts_with', [$e('r1', '/old', '/new', $cond(['rules' => [$c('lang', 'starts_with', 'de')]]))], ['path' => '/old', 'cookies' => ['lang' => 'de_AT']], [301, '/new', 'r1']);
$add('combined: cookie and header both hold', [$e('r1', '/old', '/new', $cond(['rules' => [$c('vip', 'equals', '1'), $h('User-Agent', 'contains', 'Mobile')]]))], ['path' => '/old', 'cookies' => ['vip' => '1'], 'headers' => ['user-agent' => 'Mobile Safari']], [301, '/new', 'r1']);
$add('combined: cookie holds, header does not', [$e('r1', '/old', '/new', $cond(['rules' => [$c('vip', 'equals', '1'), $h('User-Agent', 'contains', 'Mobile')]]))], ['path' => '/old', 'cookies' => ['vip' => '1'], 'headers' => ['user-agent' => 'Desktop']], null);
$add('combined: header holds, cookie does not', [$e('r1', '/old', '/new', $cond(['rules' => [$c('vip', 'equals', '1'), $h('User-Agent', 'contains', 'Mobile')]]))], ['path' => '/old', 'cookies' => ['vip' => '0'], 'headers' => ['user-agent' => 'Mobile']], null);
$add('combined: host, language and scheme together', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com'], 'languages' => ['de'], 'schemes' => ['https']]))], ['path' => '/old', 'host' => 'example.com', 'lang' => 'de', 'scheme' => 'https'], [301, '/new', 'r1']);
$add('combined: host, language and scheme, one fails', [$e('r1', '/old', '/new', $cond(['hosts' => ['example.com'], 'languages' => ['de'], 'schemes' => ['https']]))], ['path' => '/old', 'host' => 'example.com', 'lang' => 'de', 'scheme' => 'http'], null);
$add('combined: all condition kinds plus a header', [$e('r1', '/old', '/new', $cond(['hosts' => ['*.example.com'], 'schemes' => ['https'], 'rules' => [$h('Referer', 'starts_with', 'https://google.')]]))], ['path' => '/old', 'host' => 'shop.example.com', 'scheme' => 'https', 'headers' => ['referer' => 'https://google.de/search']], [301, '/new', 'r1']);
$add('conditions: rule with conditions falls through to the plain rule', [$e('r1', '/old', '/bots', ['priority' => 5] + $cond(['rules' => [$h('User-Agent', 'contains', 'bot')]])), $e('r2', '/old', '/humans')], ['path' => '/old', 'headers' => ['user-agent' => 'Firefox']], [301, '/humans', 'r2']);
$add('conditions: the same path serves different visitors', [$e('r1', '/old', '/bots', ['priority' => 5] + $cond(['rules' => [$h('User-Agent', 'contains', 'bot')]])), $e('r2', '/old', '/humans')], ['path' => '/old', 'headers' => ['user-agent' => 'Googlebot']], [301, '/bots', 'r1']);
$add('conditions: on a wildcard rule', [$w('r1', '/a/*', '/n/$1', $cond(['languages' => ['de']]))], ['path' => '/a/x', 'lang' => 'de'], [301, '/n/x', 'r1']);
$add('conditions: on a regex rule', [$x('r1', '^/a/(\d+)$', '/n/$1', $cond(['languages' => ['de']]))], ['path' => '/a/5', 'lang' => 'en'], null);

// ---------------------------------------------------------------- priority and ordering
$add('order: higher priority wins', [$e('r1', '/old', '/low', ['priority' => 1]), $e('r2', '/old', '/high', ['priority' => 9])], '/old', [301, '/high', 'r2']);
$add('order: input order does not matter', [$e('r2', '/old', '/high', ['priority' => 9]), $e('r1', '/old', '/low', ['priority' => 1])], '/old', [301, '/high', 'r2']);
$add('order: negative priority loses', [$e('r1', '/old', '/neg', ['priority' => -5]), $e('r2', '/old', '/zero')], '/old', [301, '/zero', 'r2']);
$add('order: tie exact before wildcard', [$w('r1', '/old*', '/wild'), $e('r2', '/old', '/exact')], '/old', [301, '/exact', 'r2']);
$add('order: tie wildcard before regex', [$x('r1', '^/old$', '/rx'), $w('r2', '/old*', '/wild')], '/old', [301, '/wild', 'r2']);
$add('order: tie exact before regex', [$x('r1', '^/old$', '/rx'), $e('r2', '/old', '/exact')], '/old', [301, '/exact', 'r2']);
$add('order: priority beats match type', [$x('r1', '^/old$', '/rx', ['priority' => 10]), $e('r2', '/old', '/exact')], '/old', [301, '/rx', 'r1']);
$add('order: wildcard priority beats exact', [$w('r1', '/old*', '/wild', ['priority' => 1]), $e('r2', '/old', '/exact')], '/old', [301, '/wild', 'r1']);
$add('order: older created_at first', [$e('r1', '/old', '/young', ['created_at' => '2026-05-01T00:00:00+00:00']), $e('r2', '/old', '/older', ['created_at' => '2026-01-01T00:00:00+00:00'])], '/old', [301, '/older', 'r2']);
$add('order: rule without created_at comes last', [$e('r1', '/old', '/none'), $e('r2', '/old', '/dated', ['created_at' => '2026-01-01T00:00:00+00:00'])], '/old', [301, '/dated', 'r2']);
$add('order: same created_at, lower id first', [$e('rb', '/old', '/b', ['created_at' => '2026-01-01T00:00:00+00:00']), $e('ra', '/old', '/a', ['created_at' => '2026-01-01T00:00:00+00:00'])], '/old', [301, '/a', 'ra']);
$add('order: no created_at, lower id first', [$e('rb', '/old', '/b'), $e('ra', '/old', '/a')], '/old', [301, '/a', 'ra']);
$add('order: created_at breaks ties between wildcards', [$w('r1', '/a/*', '/late/$1', ['created_at' => '2026-06-01T00:00:00+00:00']), $w('r2', '/a/b/*', '/early/$1', ['created_at' => '2026-01-01T00:00:00+00:00'])], '/a/b/c', [301, '/early/c', 'r2']);
$add('order: created_at compares across time zones', [$e('r1', '/old', '/first', ['created_at' => '2026-01-01T02:00:00+02:00']), $e('r2', '/old', '/second', ['created_at' => '2026-01-01T00:30:00+00:00'])], '/old', [301, '/first', 'r1']);
$add('order: priority ties across all three types resolve by type', [$x('r1', '^/old$', '/rx'), $w('r2', '/old*', '/wild'), $e('r3', '/old', '/exact')], '/old', [301, '/exact', 'r3']);
$add('order: higher-priority rule that does not match is skipped', [$e('r1', '/other', '/x', ['priority' => 9]), $e('r2', '/old', '/y')], '/old', [301, '/y', 'r2']);
$add('order: case-insensitive and case-sensitive rules share a path', [$e('r1', '/Old', '/cs', ['case_sensitive' => true, 'priority' => 2]), $e('r2', '/old', '/ci', ['priority' => 1])], '/old', [301, '/ci', 'r2']);
$add('order: strict and tolerant rules share a path', [$e('r1', '/old/', '/strict', ['ignore_trailing_slash' => false, 'priority' => 2]), $e('r2', '/old', '/tolerant', ['priority' => 1])], '/old', [301, '/tolerant', 'r2']);
$add('order: strict rule wins when it matches', [$e('r1', '/old/', '/strict', ['ignore_trailing_slash' => false, 'priority' => 2]), $e('r2', '/old', '/tolerant', ['priority' => 1])], '/old/', [301, '/strict', 'r1']);

// ---------------------------------------------------------------- continue chains
$add('chain: two rules', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1])], '/a', [301, '/c', 'r2', ['r1', 'r2']]);
$add('chain: three rules', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 3]), $e('r2', '/b', '/c', ['continue' => true, 'priority' => 2]), $e('r3', '/c', '/d', ['priority' => 1])], '/a', [301, '/d', 'r3', ['r1', 'r2', 'r3']]);
$add('chain: continue flag without a follow-up rule', [$e('r1', '/a', '/b', ['continue' => true])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: rule without continue stops the chain', [$e('r1', '/a', '/b', ['priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: only rules ranked after the current one are used', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 1]), $e('r2', '/b', '/c', ['priority' => 5])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: wildcard rewrite feeds the next rule', [$w('r1', '/old/*', '/mid/$1', ['continue' => true, 'priority' => 2]), $w('r2', '/mid/*', '/new/$1', ['priority' => 1])], '/old/x', [301, '/new/x', 'r2', ['r1', 'r2']]);
$add('chain: regex then exact', [$x('r1', '^/p/(\d+)$', '/product/$1', ['continue' => true, 'priority' => 2]), $e('r2', '/product/7', '/shop/seven', ['priority' => 1])], '/p/7', [301, '/shop/seven', 'r2', ['r1', 'r2']]);
$add('chain: last status decides (301 then 302)', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['status' => 302, 'priority' => 1])], '/a', [302, '/c', 'r2', ['r1', 'r2']]);
$add('chain: last status decides (302 then 301)', [$e('r1', '/a', '/b', ['continue' => true, 'status' => 302, 'priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1])], '/a', [301, '/c', 'r2', ['r1', 'r2']]);
$add('chain: ends in a 410', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '', ['status' => 410, 'priority' => 1])], '/a', [410, '', 'r2', ['r1', 'r2']]);
$add('chain: a 410 rule does not continue', [$e('r1', '/a', '', ['continue' => true, 'status' => 410, 'priority' => 2]), $e('r2', '/a', '/c', ['priority' => 1])], '/a', [410, '', 'r1', ['r1']]);
$add('chain: external target does not continue', [$e('r1', '/a', 'https://partner.example.org/x', ['continue' => true, 'target_type' => 'url', 'priority' => 2]), $e('r2', '/x', '/c', ['priority' => 1])], '/a', [301, 'https://partner.example.org/x', 'r1', ['r1']], ['allowed_hosts' => ['partner.example.org']]);
$add('chain: pass-through continues', [$e('r1', '/a', '/b', ['continue' => true, 'status' => 200, 'priority' => 2]), $e('r2', '/b', '/c', ['status' => 200, 'priority' => 1])], '/a', [200, '/c', 'r2', ['r1', 'r2']]);
$add('chain: query is passed along', [$e('r1', '/a', '/b', ['continue' => true, 'query_mode' => 'pass', 'priority' => 2]), $e('r2', '/b', '/c', ['query_mode' => 'pass', 'priority' => 1])], ['path' => '/a', 'query' => ['x' => '1']], [301, '/c?x=1', 'r2', ['r1', 'r2']]);
$add('chain: the target query does not break the rewritten path', [$e('r1', '/a', '/b?q=1', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1])], '/a', [301, '/c', 'r2', ['r1', 'r2']]);
$add('chain: self redirect is applied once', [$e('r1', '/a', '/a', ['continue' => true])], '/a', [301, '/a', 'r1', ['r1']]);
$add('chain: an unsafe follow-up does not replace the previous result', [$w('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $w('r2', '/b', '//evil.com', ['priority' => 1])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: the next rule respects its own phase', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['only_if_not_found' => true, 'priority' => 1])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: conditions apply to every step', [$e('r1', '/a', '/b', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1, 'conditions' => ['languages' => ['de']]])], '/a', [301, '/b', 'r1', ['r1']]);
$add('chain: trailing slash target', [$e('r1', '/a', '/b/', ['continue' => true, 'priority' => 2]), $e('r2', '/b', '/c', ['priority' => 1])], '/a', [301, '/c', 'r2', ['r1', 'r2']]);
$chain = [];
for ($i = 0; $i < 12; ++$i) {
    $chain[] = $e('c' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), '/s' . $i, '/s' . ($i + 1), ['continue' => true, 'priority' => 100 - $i]);
}
$add('chain: depth is capped at the default of 10', $chain, '/s0', [301, '/s10', 'c09', ['c00', 'c01', 'c02', 'c03', 'c04', 'c05', 'c06', 'c07', 'c08', 'c09']]);
$add('chain: depth cap is configurable', $chain, '/s0', [301, '/s3', 'c02', ['c00', 'c01', 'c02']], ['max_chain' => 3]);
$add('chain: cap of one applies just the first rule', $chain, '/s0', [301, '/s1', 'c00', ['c00']], ['max_chain' => 1]);
$add('chain: a shorter chain ends before the cap', array_slice($chain, 0, 4), '/s0', [301, '/s4', 'c03', ['c00', 'c01', 'c02', 'c03']]);

// ---------------------------------------------------------------- active window
$add('active: disabled rule is ignored', [$e('r1', '/old', '/new', ['enabled' => false])], '/old', null);
$add('active: disabled rule lets the next one through', [$e('r1', '/old', '/off', ['enabled' => false, 'priority' => 9]), $e('r2', '/old', '/on')], '/old', [301, '/on', 'r2']);
$add('active: expired rule', [$e('r1', '/old', '/new', ['expires_at' => '2026-09-01T00:00:00+00:00'])], '/old', null);
$add('active: not yet expired', [$e('r1', '/old', '/new', ['expires_at' => '2026-12-01T00:00:00+00:00'])], '/old', [301, '/new', 'r1']);
$add('active: expires exactly now', [$e('r1', '/old', '/new', ['expires_at' => '2026-09-29T12:00:00+00:00'])], '/old', null);
$add('active: expires one second from now', [$e('r1', '/old', '/new', ['expires_at' => '2026-09-29T12:00:01+00:00'])], '/old', [301, '/new', 'r1']);
$add('active: scheduled for the future', [$e('r1', '/old', '/new', ['active_from' => '2026-10-15T00:00:00+00:00'])], '/old', null);
$add('active: started in the past', [$e('r1', '/old', '/new', ['active_from' => '2026-09-01T00:00:00+00:00'])], '/old', [301, '/new', 'r1']);
$add('active: starts exactly now', [$e('r1', '/old', '/new', ['active_from' => '2026-09-29T12:00:00+00:00'])], '/old', [301, '/new', 'r1']);
$add('active: inside the window', [$e('r1', '/old', '/new', ['active_from' => '2026-09-01T00:00:00+00:00', 'expires_at' => '2026-12-01T00:00:00+00:00'])], '/old', [301, '/new', 'r1']);
$add('active: after the window', [$e('r1', '/old', '/new', ['active_from' => '2026-01-01T00:00:00+00:00', 'expires_at' => '2026-02-01T00:00:00+00:00'])], '/old', null);
$add('active: expired rule lets the fallback through', [$e('r1', '/old', '/promo', ['expires_at' => '2026-09-01T00:00:00+00:00', 'priority' => 5]), $e('r2', '/old', '/regular')], '/old', [301, '/regular', 'r2']);
$add('active: scheduled rule lets the fallback through', [$e('r1', '/old', '/promo', ['active_from' => '2027-01-01T00:00:00+00:00', 'priority' => 5]), $e('r2', '/old', '/regular')], '/old', [301, '/regular', 'r2']);
$add('active: time zone edge, expired (+02:00 offset vs UTC clock)', [$e('r1', '/old', '/new', ['expires_at' => '2026-10-01T00:00:00+02:00'])], '/old', null, ['now' => '2026-09-30T22:30:00+00:00']);
$add('active: time zone edge, still active half an hour earlier', [$e('r1', '/old', '/new', ['expires_at' => '2026-10-01T00:00:00+02:00'])], '/old', [301, '/new', 'r1'], ['now' => '2026-09-30T21:30:00+00:00']);
$add('active: time zone edge, clock in another zone', [$e('r1', '/old', '/new', ['expires_at' => '2026-10-01T00:00:00+02:00'])], '/old', null, ['now' => '2026-09-30T18:30:00-04:00']);
$add('active: scheduled start with offset', [$e('r1', '/old', '/new', ['active_from' => '2026-10-01T00:00:00+02:00'])], '/old', [301, '/new', 'r1'], ['now' => '2026-09-30T22:00:00+00:00']);
$add('active: scheduled start with offset, one second early', [$e('r1', '/old', '/new', ['active_from' => '2026-10-01T00:00:00+02:00'])], '/old', null, ['now' => '2026-09-30T21:59:59+00:00']);
$add('active: wildcard rule can expire', [$w('r1', '/a/*', '/n/$1', ['expires_at' => '2026-01-01T00:00:00+00:00'])], '/a/x', null);
$add('active: regex rule can be scheduled', [$x('r1', '^/a/(.*)$', '/n/$1', ['active_from' => '2027-01-01T00:00:00+00:00'])], '/a/x', null);

// ---------------------------------------------------------------- status codes
$add('status: 301', [$e('r1', '/old', '/new', ['status' => 301])], '/old', [301, '/new', 'r1']);
$add('status: 302', [$e('r1', '/old', '/new', ['status' => 302])], '/old', [302, '/new', 'r1']);
$add('status: 307', [$e('r1', '/old', '/new', ['status' => 307])], '/old', [307, '/new', 'r1']);
$add('status: 308', [$e('r1', '/old', '/new', ['status' => 308])], '/old', [308, '/new', 'r1']);
$add('status: 410 has an empty location', [$e('r1', '/old', '', ['status' => 410])], '/old', [410, '', 'r1']);
$add('status: 410 ignores a leftover target', [$e('r1', '/old', '/leftover', ['status' => 410])], '/old', [410, '', 'r1']);
$add('status: 451 has an empty location', [$e('r1', '/old', '', ['status' => 451])], '/old', [451, '', 'r1']);
$add('status: 410 on a wildcard', [$w('r1', '/gone/*', '', ['status' => 410])], '/gone/a/b', [410, '', 'r1']);
$add('status: 410 on a regex', [$x('r1', '^/gone/\d+$', '', ['status' => 410])], '/gone/12', [410, '', 'r1']);
$add('status: 410 is not blocked by an unsafe leftover target', [$e('r1', '/old', '//evil.com', ['status' => 410])], '/old', [410, '', 'r1']);
$add('status: 200 pass-through', [$e('r1', '/old', '/real', ['status' => 200])], '/old', [200, '/real', 'r1']);
$add('status: 200 with an external target is refused', [$e('r1', '/old', 'https://partner.example.org/x', ['status' => 200, 'target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('status: unknown status falls back to 301', [$e('r1', '/old', '/new', ['status' => 999])], '/old', [301, '/new', 'r1']);
$add('status: redirect without a target does nothing', [$e('r1', '/old', '')], '/old', null);
$add('status: redirect with a blank target does nothing', [$e('r1', '/old', '   ')], '/old', null);

// ---------------------------------------------------------------- open redirect and target safety
$add('security: protocol-relative route target', [$e('r1', '/old', '//evil.com')], '/old', null);
$add('security: backslash route target', [$e('r1', '/old', '/\\evil.com')], '/old', null);
$add('security: encoded slash route target', [$e('r1', '/old', '/%2F%2Fevil.com')], '/old', null);
$add('security: encoded backslash route target', [$e('r1', '/old', '/%5Cevil.com')], '/old', null);
$add('security: scheme without slashes as route target', [$e('r1', '/old', 'https:evil.com')], '/old', null);
$add('security: scheme with one slash as route target', [$e('r1', '/old', 'http:/evil.com')], '/old', null);
$add('security: javascript: as route target', [$e('r1', '/old', 'javascript:alert(1)')], '/old', null);
$add('security: absolute url in a route target', [$e('r1', '/old', 'https://evil.com/')], '/old', null, ['allow_any' => true]);
$add('security: relative route target without slash', [$e('r1', '/old', 'new-page')], '/old', null);
$add('security: route target with a tab inside', [$e('r1', '/old', "/\t/evil.com")], '/old', null);
$add('security: route target with a newline', [$e('r1', '/old', "/new\nSet-Cookie: x=1")], '/old', null);
$add('security: page target with protocol-relative route', [$e('r1', '/old', '//evil.com', ['target_type' => 'page'])], '/old', null);
$add('security: capture builds a protocol-relative target', [$w('r1', '/go*', '/$1')], '/go/evil.com', null);
$add('security: capture builds a protocol-relative target via backslash', [$w('r1', '/go*', '/$1')], '/go%5Cevil.com', null);
$add('security: capture builds a protocol-relative target via %2F', [$w('r1', '/go*', '/$1')], '/go%2Fevil.com', null);
$add('security: harmless capture on the same template', [$w('r1', '/go*', '/$1')], '/goto', [301, '/to', 'r1']);
$add('security: double slash cannot smuggle a host through a capture', [$w('r1', '/r/*', '/$1')], '/r//evil.com', [301, '/evil.com', 'r1']);
$add('security: capture in a regex rule', [$x('r1', '^/go/(.*)$', '/$1')], '/go//evil.com', [301, '/evil.com', 'r1']);
$add('security: leading-slash capture with named group', [$x('r1', '^/x(?<rest>/.*)$', '/{rest}')], '/x/evil.com/a', null);
$add('security: empty capture at the start of the target', [$w('r1', '/a/*', '/$1/page')], '/a/', null);
$add('security: external url without allowlist', [$e('r1', '/old', 'https://evil.com/', ['target_type' => 'url'])], '/old', null);
$add('security: external url on the allowlist', [$e('r1', '/old', 'https://partner.example.org/x', ['target_type' => 'url'])], '/old', [301, 'https://partner.example.org/x', 'r1'], ['allowed_hosts' => ['partner.example.org']]);
$add('security: external url not on the allowlist', [$e('r1', '/old', 'https://evil.com/x', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: allow any external', [$e('r1', '/old', 'https://anything.example.net/x', ['target_type' => 'url'])], '/old', [301, 'https://anything.example.net/x', 'r1'], ['allow_any' => true]);
$add('security: external url to the current host needs no allowlist', [$e('r1', '/old', 'https://example.com/new', ['target_type' => 'url'])], ['path' => '/old', 'host' => 'example.com'], [301, 'https://example.com/new', 'r1']);
$add('security: external url to the current host, port in request host', [$e('r1', '/old', 'https://example.com/new', ['target_type' => 'url'])], ['path' => '/old', 'host' => 'example.com:8443'], [301, 'https://example.com/new', 'r1']);
$add('security: wildcard allowlist entry matches a subdomain', [$e('r1', '/old', 'https://a.partner.org/x', ['target_type' => 'url'])], '/old', [301, 'https://a.partner.org/x', 'r1'], ['allowed_hosts' => ['*.partner.org']]);
$add('security: wildcard allowlist entry does not match the apex', [$e('r1', '/old', 'https://partner.org/x', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['*.partner.org']]);
$add('security: http url on the allowlist', [$e('r1', '/old', 'http://partner.example.org/x', ['target_type' => 'url'])], '/old', [301, 'http://partner.example.org/x', 'r1'], ['allowed_hosts' => ['partner.example.org']]);
$add('security: javascript: as url target', [$e('r1', '/old', 'javascript:alert(1)', ['target_type' => 'url'])], '/old', null, ['allow_any' => true]);
$add('security: data: as url target', [$e('r1', '/old', 'data:text/html,<script>1</script>', ['target_type' => 'url'])], '/old', null, ['allow_any' => true]);
$add('security: ftp: as url target', [$e('r1', '/old', 'ftp://partner.example.org/x', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: protocol-relative url target', [$e('r1', '/old', '//partner.example.org/x', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: scheme without slashes as url target', [$e('r1', '/old', 'https:partner.example.org', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: userinfo trick in a url target', [$e('r1', '/old', 'https://partner.example.org@evil.com/', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: backslash trick in a url target', [$e('r1', '/old', 'https://evil.com\\@partner.example.org/', ['target_type' => 'url'])], '/old', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: capture in the path of an allowed external target', [$w('r1', '/x/*', 'https://partner.example.org/$1', ['target_type' => 'url'])], '/x/a/b', [301, 'https://partner.example.org/a/b', 'r1'], ['allowed_hosts' => ['partner.example.org']]);
$add('security: capture with @ in the path of an external target stays in the path', [$w('r1', '/x/*', 'https://partner.example.org/$1', ['target_type' => 'url'])], '/x/@evil.com', [301, 'https://partner.example.org/@evil.com', 'r1'], ['allowed_hosts' => ['partner.example.org']]);
$add('security: capture right after the host of an external target', [$w('r1', '/x*', 'https://partner.example.org$1', ['target_type' => 'url'])], '/x.evil.com', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: capture inside the host of an external target', [$w('r1', '/x/*', 'https://$1.partner.example.org/', ['target_type' => 'url'])], '/x/evil.com', null, ['allowed_hosts' => ['partner.example.org']]);
$add('security: dot segments are resolved before a capture sees them', [$w('r1', '/f/*', '/files/$1')], '/f/a/../b', [301, '/files/b', 'r1']);
$add('security: traversal out of the wildcard prefix no longer matches it', [$w('r1', '/f/*', '/files/$1')], '/f/../../etc/passwd', null);
$add('security: encoded traversal out of the wildcard prefix', [$w('r1', '/f/*', '/files/$1')], '/f/%2e%2e/%2e%2e/etc/passwd', null);
$add('security: NUL in a wildcard request', [$w('r1', '/f/*', '/files/$1')], "/f/a%00b", null);
$add('security: CRLF in a wildcard request', [$w('r1', '/f/*', '/files/$1')], '/f/a%0d%0aLocation:%20//evil.com', null);
$add('security: query value cannot inject a header line', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['x' => "a\r\nSet-Cookie: b=1"]], [301, '/new?x=a%0D%0ASet-Cookie%3A%20b%3D1', 'r1']);
$add('security: query value with a host-looking string stays a value', [$e('r1', '/old', '/new', ['query_mode' => 'pass'])], ['path' => '/old', 'query' => ['next' => '//evil.com']], [301, '/new?next=%2F%2Fevil.com', 'r1']);

// ---------------------------------------------------------------- phases
$add('phase early: normal rule matches', [$e('r1', '/old')], '/old', [301, '/new', 'r1'], ['phase' => 'early']);
$add('phase early: only-if-not-found rule is skipped', [$e('r1', '/old', '/new', ['only_if_not_found' => true])], '/old', null, ['phase' => 'early']);
$add('phase early: only-if-not-found rule lets a lower rule through', [$e('r1', '/old', '/nf', ['only_if_not_found' => true, 'priority' => 9]), $e('r2', '/old', '/early')], '/old', [301, '/early', 'r2'], ['phase' => 'early']);
$add('phase not_found: only-if-not-found rule matches', [$e('r1', '/old', '/new', ['only_if_not_found' => true])], '/old', [301, '/new', 'r1'], ['phase' => 'not_found']);
$add('phase not_found: normal rule is skipped', [$e('r1', '/old')], '/old', null, ['phase' => 'not_found']);
$add('phase not_found: picks the only-if-not-found rule among both', [$e('r1', '/old', '/early', ['priority' => 9]), $e('r2', '/old', '/nf', ['only_if_not_found' => true])], '/old', [301, '/nf', 'r2'], ['phase' => 'not_found']);
$add('phase any: normal rule matches', [$e('r1', '/old')], '/old', [301, '/new', 'r1'], ['phase' => 'any']);
$add('phase any: only-if-not-found rule matches', [$e('r1', '/old', '/new', ['only_if_not_found' => true])], '/old', [301, '/new', 'r1'], ['phase' => 'any']);
$add('phase any: priority decides between both kinds', [$e('r1', '/old', '/early', ['priority' => 1]), $e('r2', '/old', '/nf', ['only_if_not_found' => true, 'priority' => 2])], '/old', [301, '/nf', 'r2'], ['phase' => 'any']);
$add('phase not_found: wildcard only-if-not-found', [$w('r1', '/a/*', '/n/$1', ['only_if_not_found' => true])], '/a/x', [301, '/n/x', 'r1'], ['phase' => 'not_found']);
$add('phase not_found: regex only-if-not-found', [$x('r1', '^/a/(\d+)$', '/n/$1', ['only_if_not_found' => true])], '/a/5', [301, '/n/5', 'r1'], ['phase' => 'not_found']);
$add('phase early is the default and skips wildcard not-found rules', [$w('r1', '/a/*', '/n/$1', ['only_if_not_found' => true])], '/a/x', null);

// ---------------------------------------------------------------- {lang} and placeholders
$add('lang: placeholder with request language', [$e('r1', '/old', '/{lang}/new')], ['path' => '/old', 'lang' => 'de'], [301, '/de/new', 'r1']);
$add('lang: placeholder falls back to the default language', [$e('r1', '/old', '/{lang}/new')], '/old', [301, '/en/new', 'r1'], ['default_lang' => 'en']);
$add('lang: request language beats the default', [$e('r1', '/old', '/{lang}/new')], ['path' => '/old', 'lang' => 'fr'], [301, '/fr/new', 'r1'], ['default_lang' => 'en']);
$add('lang: empty language drops the segment', [$e('r1', '/old', '/{lang}/new')], '/old', [301, '/new', 'r1']);
$add('lang: empty language in the middle', [$e('r1', '/old', '/x/{lang}/y')], '/old', [301, '/x/y', 'r1']);
$add('lang: empty language as the only segment', [$e('r1', '/old', '/{lang}')], '/old', [301, '/', 'r1']);
$add('lang: placeholder in the query', [$e('r1', '/old', '/new?l={lang}')], ['path' => '/old', 'lang' => 'de'], [301, '/new?l=de', 'r1']);
$add('lang: placeholder inside a segment', [$e('r1', '/old', '/new-{lang}')], ['path' => '/old', 'lang' => 'de'], [301, '/new-de', 'r1']);
$add('lang: placeholder inside a segment, empty', [$e('r1', '/old', '/new-{lang}')], '/old', [301, '/new-', 'r1']);
$add('lang: named group beats the language', [$x('r1', '^/(?<lang>xx)/a$', '/{lang}/b')], ['path' => '/xx/a', 'lang' => 'de'], [301, '/xx/b', 'r1']);
$add('lang: unknown placeholder stays literal', [$e('r1', '/old', '/new/{foo}')], '/old', [301, '/new/%7Bfoo%7D', 'r1']);
$add('lang: dollar without digit stays literal', [$e('r1', '/old', '/price$')], '/old', [301, '/price$', 'r1']);
$add('lang: $0 is empty', [$w('r1', '/a/*', '/n/$0x')], '/a/q', [301, '/n/x', 'r1']);
$add('lang: empty numbered capture collapses the double slash', [$w('r1', '/a/*', '/n/$1/end')], '/a/', [301, '/n/end', 'r1']);
$add('lang: group and named group in one target', [$x('r1', '^/(?<a>\w+)/(\w+)$', '/{a}/$1/$2')], '/x/y', [301, '/x/x/y', 'r1']);

// ---------------------------------------------------------------- encoding of the location
$add('location: space in the target is encoded', [$e('r1', '/old', '/new page')], '/old', [301, '/new%20page', 'r1']);
$add('location: umlaut in the target is encoded', [$e('r1', '/old', '/über')], '/old', [301, '/%C3%BCber', 'r1']);
$add('location: existing percent sequences are kept', [$e('r1', '/old', '/a%20b')], '/old', [301, '/a%20b', 'r1']);
$add('location: a lone percent sign is encoded', [$e('r1', '/old', '/100%')], '/old', [301, '/100%25', 'r1']);
$add('location: invalid percent sequence is encoded', [$e('r1', '/old', '/a%zz')], '/old', [301, '/a%25zz', 'r1']);
$add('location: reserved characters stay', [$e('r1', '/old', '/a/b?c=d&e=f#g')], '/old', [301, '/a/b?c=d&e=f#g', 'r1']);
$add('location: unsafe characters are encoded', [$e('r1', '/old', '/a"b<c>d')], '/old', [301, '/a%22b%3Cc%3Ed', 'r1']);
$add('location: page target is a route', [$e('r1', '/old', '/blog/post', ['target_type' => 'page'])], '/old', [301, '/blog/post', 'r1']);
$add('location: target trailing slash is kept', [$e('r1', '/old', '/new/')], '/old', [301, '/new/', 'r1']);
$add('location: root target', [$e('r1', '/old', '/')], '/old', [301, '/', 'r1']);
$add('location: idn host of an allowed external target', [$e('r1', '/old', 'https://bücher.example/x', ['target_type' => 'url'])], '/old', [301, 'https://xn--bcher-kva.example/x', 'r1'], ['allowed_hosts' => ['bücher.example']]);
$add('location: query in an absolute target is encoded', [$e('r1', '/old', 'https://partner.example.org/x?q=a b', ['target_type' => 'url'])], '/old', [301, 'https://partner.example.org/x?q=a%20b', 'r1'], ['allowed_hosts' => ['partner.example.org']]);

// ---------------------------------------------------------------- misc
$add('misc: no rules at all', [], '/old', null);
$add('misc: many disabled rules only', [$e('r1', '/a', '/x', ['enabled' => false]), $e('r2', '/b', '/y', ['enabled' => false])], '/a', null);
$add('misc: unicode wildcard prefix, case-insensitive', [$w('r1', '/ÜBER/*', '/n/$1')], '/%C3%BCber/x', [301, '/n/x', 'r1']);
$add('misc: rule with a huge number of stars stays bounded', [$w('r1', '/' . str_repeat('*a', 12), '/n')], '/' . str_repeat('a', 2000) . 'b', null);
$add('misc: rules on other paths do not interfere', [$e('r1', '/a', '/1'), $e('r2', '/b', '/2'), $w('r3', '/c/*', '/3/$1'), $x('r4', '^/d/(.+)$', '/4/$1')], '/d/z', [301, '/4/z', 'r4']);
$add('misc: wildcard with a case-insensitive trie and mixed case request', [$w('r1', '/Docs/Guide/*', '/g/$1')], '/DOCS/guide/Intro', [301, '/g/Intro', 'r1']);
$add('misc: case-sensitive trie rejects mixed case', [$w('r1', '/Docs/Guide/*', '/g/$1', ['case_sensitive' => true])], '/DOCS/guide/Intro', null);
$add('misc: exact rule with query part, mode ignore, matches path only', [$e('r1', '/old?x=1')], '/old', [301, '/new', 'r1']);
$add('misc: source query in wildcard is not a path part', [$w('r1', '/old*?x=1', '/n')], '/old', [301, '/n', 'r1']);

return $cases;
