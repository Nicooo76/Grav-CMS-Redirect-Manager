<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\RegexNode;
use Grav\Plugin\RedirectManager\Analysis\RegexSampler;
use Grav\Plugin\RedirectManager\Analysis\Sample;
use Grav\Plugin\RedirectManager\Analysis\SampleGenerator;
use Grav\Plugin\RedirectManager\Security\RegexSafety;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SampleGenerator::class)]
#[CoversClass(RegexSampler::class)]
#[CoversClass(RegexNode::class)]
#[CoversClass(Sample::class)]
final class SampleGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function regexDisplay(): iterable
    {
        yield 'literal' => ['^/about-us$', '/about-us'];
        yield 'digits' => ['^/post/(\d+)$', '/post/1'];
        yield 'segment' => ['^/blog/([^/]+)$', '/blog/example'];
        yield 'named' => ['^/blog/(?<slug>[^/]+)/?$', '/blog/example/'];
        yield 'named P' => ['^/a/(?P<id>\d+)$', '/a/1'];
        yield 'named quote' => ["^/a/(?'id'\\d+)$", '/a/1'];
        yield 'any' => ['^/old/(.*)$', '/old/example'];
        yield 'any plus' => ['/old/(.+)', '/old/example'];
        yield 'non digit' => ['^/nd/(\D+)$', '/nd/example'];
        yield 'word' => ['^/w/(\w+)$', '/w/example'];
        yield 'class letters' => ['^/c/([a-z0-9-]+)$', '/c/example'];
        yield 'upper class' => ['^/c/([A-Z]+)$', '/c/EXAMPLE'];
        yield 'digit class' => ['^/c/([0-9]+)$', '/c/1'];
        yield 'posix class' => ['^/c/([[:alpha:]]+)$', '/c/example'];
        yield 'escaped literals' => ['^/file\.html$', '/file.html'];
        yield 'escaped slash' => ['^\/a\/b$', '/a/b'];
        yield 'fixed width digits' => ['^/(\d{4})/(\d{2})/(.*)$', '/1111/11/example'];
        yield 'optional group' => ['^/a(/b)?$', '/a/b'];
        yield 'non capturing' => ['^/(?:de|en)/x$', '/de/x'];
        yield 'alternation first' => ['^/(one|two)$', '/one'];
        yield 'top level alternation' => ['^/a$|^/b$', '/a'];
        yield 'literal class' => ['^/a[_]b$', '/a_b'];
        yield 'quantified literal' => ['^/ab{2}c$', '/abbc'];
        yield 'star literal' => ['^/ab*c$', '/abc'];
        yield 'lazy quantifier' => ['^/a(.+?)$', '/aexample'];
        yield 'inline flag' => ['(?i)^/a$', '/a'];
        yield 'unicode property' => ['^/p/(\p{L}+)$', '/p/example'];
        yield 'unicode property braces' => ['^/p/\pL$', '/p/x'];
        yield 'word boundary' => ['^/a\b', '/a'];
        yield 'brace literal' => ['^/a{x}$', '/a{x}'];
        yield 'zero repeat' => ['^/a(b){0}c$', '/ac'];
        yield 'range in class with escape' => ['^/r/([\w\-]+)$', '/r/example'];
        yield 'negated digits' => ['^/n/([^0-9/]+)$', '/n/example'];
        yield 'sample does not match' => ['^/\\W+$', null];
        yield 'not starting with slash' => ['^a$', null];
        yield 'lookahead' => ['^/a(?=b)', null];
        yield 'backreference' => ['^/(a)\1$', null];
        yield 'unsupported escape' => ['^/a\sb$', null];
        yield 'invalid' => ['^/(a', null];
        yield 'empty' => ['', null];
        yield 'leading quantifier' => ['*/a', null];
        yield 'does not match itself' => ['^/a/(\d+)/(?<x>b)$', '/a/1/b'];
        yield 'only literal wrong' => ['^/[^/]$', '/x'];
    }

    #[DataProvider('regexDisplay')]
    public function testRegexDisplaySamples(string $pattern, ?string $expected): void
    {
        $sample = RegexSampler::sample($pattern, false, 'example', false);

        self::assertSame($expected, $sample?->path);
    }

    public function testEverySampleMatchesItsOwnPattern(): void
    {
        foreach (self::regexDisplay() as $case) {
            [$pattern, $expected] = $case;
            $sample = RegexSampler::sample($pattern, false);
            if ($expected === null) {
                continue;
            }
            self::assertNotNull($sample, $pattern);
            self::assertSame(1, preg_match(RegexSafety::compile($pattern, false), $sample->path), $pattern);
        }
    }

    public function testUniqueTokensAndPlaceholders(): void
    {
        $sample = RegexSampler::sample('^/blog/(?<year>\d{4})/(\d+)/([^/]+)/(.+)$', false);

        self::assertNotNull($sample);
        self::assertTrue($sample->templatable);
        self::assertSame('/blog/2222/9002/sample3/sample4', $sample->path);
        self::assertSame(
            [['token' => '2222', 'placeholder' => '{year}'], ['token' => '9002', 'placeholder' => '$2'], ['token' => 'sample3', 'placeholder' => '$3'], ['token' => 'sample4', 'placeholder' => '$4']],
            $sample->tokens,
        );
        self::assertSame('/x/{year}-$2/$3/$4', $sample->templatize('/x/2222-9002/sample3/sample4'));
    }

    public function testGroupsWithoutStandInMakeTheSampleNotTemplatable(): void
    {
        self::assertFalse(RegexSampler::sample('^/(a|b)/(\d+)$', false)?->templatable);
        self::assertFalse(RegexSampler::sample('^/x(a(b)?)$', false)?->templatable);
        self::assertFalse(RegexSampler::sample('^/(?:(one)|(two))$', false)?->templatable);
        self::assertTrue(RegexSampler::sample('^/(\d+)$', false)?->templatable);
        self::assertTrue(RegexSampler::sample('^/none$', false)?->templatable);
    }

    public function testUpperCaseGroupsAndTenthGroup(): void
    {
        $sample = RegexSampler::sample('^/([A-Z]{2})/([A-Z]+)$', true);

        self::assertNotNull($sample);
        self::assertSame('/AA/SAMPLEX', $sample->path);

        $pattern = '^' . str_repeat('/(\d+)', 10) . '$';
        $ten = RegexSampler::sample($pattern, false);
        self::assertNotNull($ten);
        self::assertSame('${10}', $ten->tokens[9]['placeholder']);
    }

    public function testTemplatizeKeepsFollowingDigitsApart(): void
    {
        $sample = new Sample('/x', [], [['token' => 'sample', 'placeholder' => '$1'], ['token' => 'sample2', 'placeholder' => '$2']]);

        self::assertSame('/a/$1/$2', $sample->templatize('/a/sample/sample2'));
        self::assertSame('/a/${1}7', $sample->templatize('/a/sample7'));
        self::assertSame('/plain', $sample->templatize('/plain'));
        self::assertSame('/plain', (new Sample('/x'))->templatize('/plain'));
    }

    public function testConditionValues(): void
    {
        self::assertSame('abc', RegexSampler::value('^ab+c$'));
        self::assertNull(RegexSampler::value('^(?=a)b'));
        self::assertNull(RegexSampler::value(''));
        self::assertNull(RegexSampler::value('('));
        self::assertNull(RegexSampler::value(str_repeat('a', 1001)));
        self::assertNull(RegexSampler::value('^a(?=b)'));
        self::assertSame('a', RegexSampler::value('^a|b$'));
    }

    public function testGenerateForExactWildcardAndRegexRules(): void
    {
        $exact = SampleGenerator::generate(AnalysisFixtures::rule('a', '/Old/Page/?tab=2', '/x'));
        self::assertSame('/Old/Page/', $exact?->path);
        self::assertSame(['tab' => '2'], $exact->query);

        $wild = SampleGenerator::generate(AnalysisFixtures::rule('a', '/a/*/b/**', '/x', ['match_type' => 'wildcard']));
        self::assertSame('/a/sample/b/sample2', $wild?->path);
        self::assertSame([['token' => 'sample', 'placeholder' => '$1'], ['token' => 'sample2', 'placeholder' => '$2']], $wild->tokens);

        $plain = SampleGenerator::generate(AnalysisFixtures::rule('a', '/a/*/b/*', '/x', ['match_type' => 'wildcard']), 'example', false);
        self::assertSame('/a/example/b/example', $plain?->path);

        self::assertNull(SampleGenerator::generate(AnalysisFixtures::rule('a', '', '/x')));
        self::assertNull(SampleGenerator::generate(AnalysisFixtures::rule('a', 'https://x.example/a', '/x')));
        self::assertNull(SampleGenerator::generate(AnalysisFixtures::rule('a', "/a\0", '/x')));
        self::assertNull(SampleGenerator::generate(AnalysisFixtures::rule('a', '^/(a', '/x', ['match_type' => 'regex'])));
    }

    public function testWildcardTenthStarPlaceholder(): void
    {
        $sample = SampleGenerator::generate(AnalysisFixtures::rule('a', '/' . implode('/', array_fill(0, 10, '*')), '/x', ['match_type' => 'wildcard']));

        self::assertSame('${10}', $sample?->tokens[9]['placeholder']);
    }

    public function testContextSatisfiesQueryModeAndConditions(): void
    {
        $rule = AnalysisFixtures::rule('a', '/x?keep=1', '/t', [
            'query_mode' => 'params',
            'query_params' => ['id' => '5', 'flag' => null],
            'conditions' => ['hosts' => ['*.example.org'], 'schemes' => ['http'], 'rules' => [
                ['kind' => 'header', 'name' => 'X-A', 'operator' => 'equals', 'value' => 'v'],
                ['kind' => 'cookie', 'name' => 'c', 'operator' => 'exists'],
                ['kind' => 'header', 'name' => 'X-B', 'operator' => 'regex', 'value' => '^b+$'],
                ['kind' => 'header', 'name' => 'X-C', 'operator' => 'exists', 'negate' => true],
            ]],
        ]);
        $sample = SampleGenerator::generate($rule);
        self::assertNotNull($sample);
        $ctx = SampleGenerator::context($rule, $sample, 'de');

        self::assertNotNull($ctx);
        self::assertSame(['keep' => '1', 'id' => '5', 'flag' => 'x'], $ctx->query);
        self::assertSame('www.example.org', $ctx->host);
        self::assertSame('http', $ctx->scheme);
        self::assertSame('de', $ctx->language);
        self::assertSame(['x-a' => 'v', 'x-b' => 'b'], $ctx->headers);
        self::assertSame(['c' => 'x'], $ctx->cookies);
        self::assertSame('other.example', SampleGenerator::context($rule, $sample, null, 'other.example', 'https')?->host);
    }

    public function testUrlSamples(): void
    {
        self::assertSame('/a?x=1', SampleGenerator::url(AnalysisFixtures::rule('a', ' /a?x=1 ', '/t')));
        self::assertSame('/blog/example/x', SampleGenerator::url(AnalysisFixtures::rule('a', '/blog/*/x', '/t', ['match_type' => 'wildcard'])));
        self::assertSame('/p/1', SampleGenerator::url(AnalysisFixtures::rule('a', '^/p/(\d+)$', '/t', ['match_type' => 'regex'])));
        self::assertNull(SampleGenerator::url(AnalysisFixtures::rule('a', '', '/t')));
        self::assertNull(SampleGenerator::url(AnalysisFixtures::rule('a', '^/(?=x', '/t', ['match_type' => 'regex'])));
        self::assertSame('', SampleGenerator::firstHost(AnalysisFixtures::rule('a', '/a')));
        self::assertSame('a.example', SampleGenerator::firstHost(AnalysisFixtures::rule('a', '/a', '', ['conditions' => ['hosts' => ['a.example']]])));
    }
}
