<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleCompiler::class)]
#[CoversClass(CompiledRuleSet::class)]
final class RuleCompilerTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private static function rule(string $id, string $source, string $type = 'exact', array $extra = []): Rule
    {
        return Rule::fromArray(array_merge(['id' => $id, 'source' => $source, 'target' => '/t/' . $id, 'match_type' => $type], $extra));
    }

    /**
     * @param CompiledRuleSet $set
     * @return list<string>
     */
    private static function ids(CompiledRuleSet $set): array
    {
        return array_map(static fn (Rule $r): string => $r->id, $set->rules());
    }

    public function testEmptyList(): void
    {
        $set = (new RuleCompiler())->compile([]);

        self::assertSame(0, $set->count());
        self::assertSame([], $set->candidates('/anything'));
        self::assertSame([], $set->skipped());
    }

    public function testRulesAreStoredInRankOrder(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('regex-hi', '^/a$', 'regex', ['priority' => 5]),
            self::rule('exact-lo', '/a', 'exact', ['priority' => 1]),
            self::rule('wild-0', '/a*', 'wildcard'),
            self::rule('regex-0', '^/a$', 'regex'),
            self::rule('exact-0-late', '/a', 'exact', ['created_at' => '2026-06-01T00:00:00+00:00']),
            self::rule('exact-0-early', '/a', 'exact', ['created_at' => '2026-01-01T00:00:00+00:00']),
            self::rule('exact-0-none-b', '/a'),
            self::rule('exact-0-none-a', '/a'),
        ]);

        self::assertSame(
            ['regex-hi', 'exact-lo', 'exact-0-early', 'exact-0-late', 'exact-0-none-a', 'exact-0-none-b', 'wild-0', 'regex-0'],
            self::ids($set),
        );
        self::assertSame(8, $set->count());
    }

    public function testCandidatesAreSortedByRank(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('c', '/a/*', 'wildcard'),
            self::rule('a', '/a/x', 'exact', ['priority' => 9]),
            self::rule('b', '^/a/', 'regex', ['priority' => 5]),
        ]);

        $ids = array_map(static fn (int $i): string => $set->rule($i)->id, $set->candidates('/a/x'));

        self::assertSame(['a', 'b', 'c'], $ids);
    }

    public function testExactIndexVariants(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('cs-tol', '/Cs/Tol/', 'exact', ['case_sensitive' => true]),
            self::rule('cs-strict', '/Cs/Strict/', 'exact', ['case_sensitive' => true, 'ignore_trailing_slash' => false]),
            self::rule('ci-tol', '/Ci/Tol/', 'exact'),
            self::rule('ci-strict', '/Ci/Strict/', 'exact', ['ignore_trailing_slash' => false]),
        ]);

        $find = static fn (string $path): array => array_map(static fn (int $i): string => $set->rule($i)->id, $set->candidates($path));

        self::assertSame(['cs-tol'], $find('/Cs/Tol'));
        self::assertSame(['cs-tol'], $find('/Cs/Tol/'));
        self::assertSame([], $find('/cs/tol'));
        self::assertSame(['cs-strict'], $find('/Cs/Strict/'));
        self::assertSame([], $find('/Cs/Strict'));
        self::assertSame(['ci-tol'], $find('/ci/tol'));
        self::assertSame(['ci-tol'], $find('/CI/TOL/'));
        self::assertSame(['ci-strict'], $find('/ci/strict/'));
        self::assertSame([], $find('/ci/strict'));
    }

    public function testExactRulesWithTheSameSourceShareAKey(): void
    {
        $set = (new RuleCompiler())->compile([self::rule('a', '/x'), self::rule('b', '/x/'), self::rule('c', '/X')]);

        self::assertSame([0, 1, 2], $set->candidates('/x'));
    }

    public function testExactSourceQueryIsStoredSeparately(): void
    {
        $set = (new RuleCompiler())->compile([self::rule('a', '/search?q=shoes&a[]=1&a[]=2', 'exact', ['query_mode' => 'exact']), self::rule('b', '/plain')]);

        self::assertSame('/search', $set->exactPath(0));
        self::assertSame(['q' => 'shoes', 'a' => ['1', '2']], $set->sourceQuery(0));
        self::assertNull($set->sourceQuery(1));
        self::assertSame('/plain', $set->exactPath(1));
        self::assertSame([0], $set->candidates('/search'));
    }

    public function testAccessorsOnTheWrongKindOfRuleReturnEmptyValues(): void
    {
        $set = (new RuleCompiler())->compile([self::rule('a', '/x'), self::rule('b', '^/r$', 'regex')]);

        self::assertSame('', $set->pattern(0));
        self::assertSame('', $set->exactPath(1));
        self::assertNotSame('', $set->pattern(1));
    }

    public function testWildcardTrie(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('root', '*.pdf', 'wildcard'),
            self::rule('a', '/a/*', 'wildcard'),
            self::rule('ab', '/a/b/*', 'wildcard'),
            self::rule('partial', '/a/b/pre*', 'wildcard'),
            self::rule('ci', '/CI/Deep/*', 'wildcard'),
            self::rule('cs', '/CS/Deep/*', 'wildcard', ['case_sensitive' => true]),
        ]);
        $find = static fn (string $path): array => array_map(static fn (int $i): string => $set->rule($i)->id, $set->candidates($path));

        self::assertSame(['root'], $find('/x.pdf'));
        self::assertEqualsCanonicalizing(['root', 'a'], $find('/a/x'));
        self::assertEqualsCanonicalizing(['root', 'a', 'ab', 'partial'], $find('/a/b/prefix'));
        self::assertEqualsCanonicalizing(['root', 'a'], $find('/a'));
        self::assertEqualsCanonicalizing(['root', 'ci'], $find('/ci/deep/x'));
        self::assertEqualsCanonicalizing(['root', 'cs'], $find('/CS/Deep/x'));
        self::assertSame(['root'], $find('/cs/deep/x'));
    }

    public function testRegexBuckets(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('blog', '^/blog/(.*)', 'regex'),
            self::rule('news-cs', '^/News$', 'regex', ['case_sensitive' => true]),
            self::rule('alt', '^/a$|^/b$', 'regex'),
            self::rule('group', '^/(x|y)/z$', 'regex'),
            self::rule('unanchored', 'z$', 'regex'),
            self::rule('quantified', '^/ab?/x', 'regex'),
            self::rule('escaped-alt', '^/c\|d/x', 'regex'),
            self::rule('class-alt', '^/e[|]f$', 'regex'),
            self::rule('bracket', '^/g[)(]h', 'regex'),
        ]);
        $find = static fn (string $path): array => array_map(static fn (int $i): string => $set->rule($i)->id, $set->candidates($path));

        self::assertEqualsCanonicalizing(['blog', 'alt', 'group', 'unanchored', 'quantified', 'escaped-alt', 'class-alt', 'bracket'], $find('/blog/x'));
        self::assertNotContains('news-cs', $find('/news'));
        self::assertContains('news-cs', $find('/News'));
        self::assertNotContains('blog', $find('/other/x'));
        self::assertNotContains('blog', $find('/'));
    }

    public function testInvalidRulesAreSkippedAndReported(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('bad-regex', '^/a(', 'regex'),
            self::rule('long-regex', str_repeat('a', 1001), 'regex'),
            self::rule('empty-regex', '', 'regex'),
            self::rule('empty-source', '', 'exact'),
            self::rule('blank-wildcard', '   ', 'wildcard'),
            self::rule('nul-source', "/a\0b", 'exact'),
            self::rule('bad-utf8', '/a%ff', 'wildcard'),
            self::rule('bad-condition', '/x', 'exact', ['conditions' => ['rules' => [['kind' => 'header', 'name' => 'Referer', 'operator' => 'regex', 'value' => '(']]]]),
            self::rule('good', '/ok'),
            self::rule('empty-condition-regex', '/y', 'exact', ['conditions' => ['rules' => [['kind' => 'header', 'name' => 'Referer', 'operator' => 'regex', 'value' => '']]]]),
        ]);

        self::assertSame(['empty-condition-regex', 'good'], array_values(array_map(static fn (Rule $r): string => $r->id, $set->rules())));
        $skipped = $set->skipped();
        ksort($skipped);
        self::assertSame([
            'bad-condition' => RuleCompiler::SKIP_CONDITION_REGEX,
            'bad-regex' => 'regex_invalid',
            'bad-utf8' => RuleCompiler::SKIP_SOURCE,
            'blank-wildcard' => RuleCompiler::SKIP_SOURCE,
            'empty-regex' => 'regex_invalid',
            'empty-source' => RuleCompiler::SKIP_SOURCE,
            'long-regex' => 'regex_too_long',
            'nul-source' => RuleCompiler::SKIP_SOURCE,
        ], $skipped);
    }

    public function testDisabledRulesAreCountedButNotIndexed(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('a-on', '/a'),
            self::rule('off-exact', '/a', 'exact', ['enabled' => false]),
            self::rule('off-wild', '/a*', 'wildcard', ['enabled' => false]),
            self::rule('off-regex', '^/a$', 'regex', ['enabled' => false]),
        ]);

        self::assertSame(4, $set->count());
        self::assertSame(3, $set->disabledCount());
        self::assertSame([1, 2, 3], $set->disabledIndexes());
        self::assertSame('a-on', $set->rule(0)->id);
        self::assertSame([0], $set->candidates('/a'));
    }

    public function testRulesAreHydratedLazilyAndMemoized(): void
    {
        $set = (new RuleCompiler())->compile([self::rule('a', '/a'), self::rule('b', '/b')]);

        self::assertSame($set->rule(1), $set->rule(1));
        self::assertNotSame($set->rule(0), $set->rule(1));
        self::assertSame('b', $set->rule(1)->id);
    }

    public function testHydratedRulesEqualTheOriginals(): void
    {
        $original = self::rule('a', '/a', 'exact', [
            'priority' => 3,
            'status' => 302,
            'expires_at' => '2026-12-01T00:00:00+02:00',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'query_mode' => 'params',
            'query_params' => ['id' => null, 'x' => '1'],
            'conditions' => ['hosts' => ['*.example.com'], 'rules' => [['kind' => 'cookie', 'name' => 'c', 'operator' => 'equals', 'value' => 'v', 'negate' => true]]],
        ]);

        $set = MatcherHarness::exported((new RuleCompiler())->compile([$original]));

        self::assertEquals($original->toArray(), $set->rule(0)->toArray());
        self::assertEquals($original->expiresAt, $set->rule(0)->expiresAt);
    }

    public function testToArrayContainsOnlyScalarsAndArrays(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('a', '/a?x=1', 'exact', ['expires_at' => '2026-12-01T00:00:00+00:00', 'conditions' => ['hosts' => ['a.com'], 'rules' => [['kind' => 'header', 'name' => 'A', 'operator' => 'exists']]]]),
            self::rule('b', '/b/*', 'wildcard'),
            self::rule('c', '^/c/(.*)$', 'regex'),
            self::rule('d', '/d', 'exact', ['enabled' => false]),
        ]);

        $check = static function (mixed $value) use (&$check): bool {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (!$check($item)) {
                        return false;
                    }
                }

                return true;
            }

            return $value === null || is_scalar($value);
        };

        self::assertTrue($check($set->toArray()));
    }

    public function testExportRoundTripKeepsTheArrayIdentical(): void
    {
        $set = (new RuleCompiler())->compile([
            self::rule('a', '/a?x=1'),
            self::rule('b', '/b/*', 'wildcard', ['case_sensitive' => true]),
            self::rule('c', '^/123/(.*)$', 'regex'),
            self::rule('d', '/2024', 'exact'),
        ]);

        self::assertSame($set->toArray(), MatcherHarness::exported($set)->toArray());
    }

    public function testFromArrayRejectsAnUnknownVersion(): void
    {
        $data = (new RuleCompiler())->compile([])->toArray();
        $data['v'] = 99;

        $this->expectException(InvalidArgumentException::class);
        CompiledRuleSet::fromArray($data);
    }

    public function testFromArrayRejectsMissingParts(): void
    {
        $data = (new RuleCompiler())->compile([])->toArray();
        unset($data['exact']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact');
        CompiledRuleSet::fromArray($data);
    }

    public function testFromArrayRejectsAnArrayWithoutVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CompiledRuleSet::fromArray(['rules' => []]);
    }

    public function testRoundTripGivesTheSameMatchResults(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; ++$i) {
            $rows[] = ['id' => sprintf('e%02d', $i), 'source' => "/e/$i", 'target' => "/t/e$i", 'priority' => $i % 5, 'query_mode' => $i % 3 === 0 ? 'pass' : 'ignore'];
            $rows[] = ['id' => sprintf('w%02d', $i), 'source' => "/w$i/*", 'target' => "/t/w$i/$1", 'match_type' => 'wildcard', 'case_sensitive' => $i % 2 === 0];
            $rows[] = ['id' => sprintf('r%02d', $i), 'source' => "^/r$i/(\\d+)$", 'target' => "/t/r$i/$1", 'match_type' => 'regex', 'priority' => $i % 4];
        }
        $rows[] = ['id' => 'catchall', 'source' => '*', 'target' => '/home', 'match_type' => 'wildcard', 'priority' => -1];
        $set = MatcherHarness::compile($rows);
        $restored = MatcherHarness::exported($set);
        $first = MatcherHarness::matcher($set);
        $second = MatcherHarness::matcher($restored);

        $requests = ['/e/3', '/e/3/', '/E/59', '/w4/x/y', '/W4/x', '/w5/x', '/W5/x', '/r10/77', '/r10/x', '/R10/5', '/nothing', '/', '/e/60'];
        $compared = 0;
        foreach ($requests as $path) {
            $ctx = MatcherHarness::context(['path' => $path, 'query' => ['q' => '1']]);
            self::assertNotNull($ctx);
            $a = $first->match($ctx, MatchPhase::Any, true);
            $b = $second->match($ctx, MatchPhase::Any, true);
            self::assertEquals($a?->toArray(), $b?->toArray(), $path);
            ++$compared;
        }

        self::assertSame(count($requests), $compared);
    }
}
