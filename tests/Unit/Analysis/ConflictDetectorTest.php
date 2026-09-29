<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\ConflictDetector;
use Grav\Plugin\RedirectManager\Analysis\PathKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConflictDetector::class)]
#[CoversClass(PathKey::class)]
final class ConflictDetectorTest extends TestCase
{
    /**
     * @param list<\Grav\Plugin\RedirectManager\Domain\Rule> $rules
     * @return list<list<string>>
     */
    private function idGroups(array $rules): array
    {
        return array_map(
            static fn (array $group): array => array_map(static fn ($r): string => $r->id, $group),
            AnalysisFixtures::conflicts()->detect($rules),
        );
    }

    private static function r(string $id, string $source, string $target = '/t', array $extra = [])
    {
        return AnalysisFixtures::rule($id, $source, $target, $extra);
    }

    public function testSameSourceDifferentTargets(): void
    {
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x', '/1'), self::r('b', '/x', '/2'), self::r('c', '/y')]));
    }

    public function testCaseInsensitiveDuplicates(): void
    {
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/About'), self::r('b', '/about')]));
    }

    public function testCaseSensitiveRulesDoNotCollideOnCase(): void
    {
        $rules = [self::r('a', '/About', '/t', ['case_sensitive' => true]), self::r('b', '/about', '/t', ['case_sensitive' => true])];

        self::assertSame([], $this->idGroups($rules));
    }

    public function testOneCaseInsensitiveRuleOverlapsBoth(): void
    {
        $rules = [self::r('a', '/About', '/t', ['case_sensitive' => true]), self::r('b', '/about')];

        self::assertSame([['a', 'b']], $this->idGroups($rules));
    }

    public function testTrailingSlashTolerance(): void
    {
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/a'), self::r('b', '/a/')]));
    }

    public function testStrictTrailingSlashRulesAreDifferent(): void
    {
        $strict = ['ignore_trailing_slash' => false];

        self::assertSame([], $this->idGroups([self::r('a', '/a', '/t', $strict), self::r('b', '/a/', '/t', $strict)]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/a', '/t', $strict), self::r('b', '/a/')]));
    }

    public function testDifferentHostsDoNotConflict(): void
    {
        $a = self::r('a', '/x', '/t', ['conditions' => ['hosts' => ['a.example']]]);
        $b = self::r('b', '/x', '/t', ['conditions' => ['hosts' => ['b.example']]]);

        self::assertSame([], $this->idGroups([$a, $b]));
    }

    public function testOverlappingHostListsConflict(): void
    {
        $a = self::r('a', '/x', '/t', ['conditions' => ['hosts' => ['a.example', 'b.example']]]);
        $b = self::r('b', '/x', '/t', ['conditions' => ['hosts' => ['b.example', 'c.example']]]);

        self::assertSame([['a', 'b']], $this->idGroups([$a, $b]));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, bool}>
     */
    public static function hostPairs(): iterable
    {
        yield 'wildcard covers subdomain' => [['*.example.org'], ['www.example.org'], true];
        yield 'wildcard not apex' => [['*.example.org'], ['example.org'], false];
        yield 'two wildcards nested' => [['*.example.org'], ['*.blog.example.org'], true];
        yield 'two wildcards apart' => [['*.example.org'], ['*.example.com'], false];
        yield 'wildcard second' => [['www.example.org'], ['*.example.org'], true];
        yield 'plain different' => [['a.example.org'], ['b.example.org'], false];
        yield 'one side any' => [[], ['b.example.org'], true];
    }

    /**
     * @param list<string> $hostsA
     * @param list<string> $hostsB
     */
    #[DataProvider('hostPairs')]
    public function testHostPatterns(array $hostsA, array $hostsB, bool $expected): void
    {
        $a = self::r('a', '/x', '/t', ['conditions' => ['hosts' => $hostsA]]);
        $b = self::r('b', '/x', '/t', ['conditions' => ['hosts' => $hostsB]]);

        self::assertSame($expected, AnalysisFixtures::conflicts()->overlaps($a, $b));
    }

    public function testLanguagesAndSchemes(): void
    {
        $de = self::r('a', '/x', '/t', ['conditions' => ['languages' => ['de']]]);
        $en = self::r('b', '/x', '/t', ['conditions' => ['languages' => ['en']]]);
        $both = self::r('c', '/x', '/t', ['conditions' => ['languages' => ['de', 'en']]]);
        $http = self::r('d', '/x', '/t', ['conditions' => ['schemes' => ['http']]]);
        $https = self::r('e', '/x', '/t', ['conditions' => ['schemes' => ['https']]]);

        self::assertSame([['a', 'c'], ['b', 'c']], $this->pairsOf([$de, $en, $both]));
        self::assertSame([], $this->idGroups([$http, $https]));
    }

    /**
     * Groups are connected components; list the members' pairwise overlaps to see who conflicts with whom.
     *
     * @param list<\Grav\Plugin\RedirectManager\Domain\Rule> $rules
     * @return list<list<string>>
     */
    private function pairsOf(array $rules): array
    {
        $detector = AnalysisFixtures::conflicts();
        $pairs = [];
        foreach ($detector->detect($rules) as $group) {
            foreach ($group as $i => $a) {
                foreach (array_slice($group, $i + 1) as $b) {
                    if ($detector->overlaps($a, $b)) {
                        $pairs[] = [$a->id, $b->id];
                    }
                }
            }
        }

        return $pairs;
    }

    public function testHeaderConditionsAreComparedLiterally(): void
    {
        $bot = ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'bot']]];
        $mobile = ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'mobile']]];

        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x', '/t', ['conditions' => $bot]), self::r('b', '/x', '/t', ['conditions' => $bot])]));
        self::assertSame([], $this->idGroups([self::r('a', '/x', '/t', ['conditions' => $bot]), self::r('b', '/x', '/t', ['conditions' => $mobile])]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x', '/t', ['conditions' => $bot]), self::r('b', '/x')]));
    }

    public function testQueryRequirementMustMatch(): void
    {
        $exact = ['query_mode' => 'exact'];

        self::assertSame([], $this->idGroups([self::r('a', '/x?a=1', '/t', $exact), self::r('b', '/x?a=2', '/t', $exact)]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x?a=1&b=2', '/t', $exact), self::r('b', '/x?b=2&a=1', '/t', $exact)]));
        self::assertSame([], $this->idGroups([self::r('a', '/x?a=1', '/t', $exact), self::r('b', '/x')]));
        // ignored parameters do not count
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x?utm_source=1', '/t', $exact), self::r('b', '/x?fbclid=9', '/t', $exact)]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x?keep=1&sid=1', '/t', $exact + ['query_ignore' => ['sid']]), self::r('b', '/x?keep=1', '/t', $exact + ['query_ignore' => ['sid']])]));
        // query mode "pass" behaves like "ignore"
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/x', '/t', ['query_mode' => 'pass']), self::r('b', '/x')]));
    }

    public function testParamsModeKeyIncludesRequiredParameters(): void
    {
        $a = self::r('a', '/x', '/t', ['query_mode' => 'params', 'query_params' => ['id' => '1']]);
        $b = self::r('b', '/x?id=1', '/t', ['query_mode' => 'params']);
        $c = self::r('c', '/x', '/t', ['query_mode' => 'params', 'query_params' => ['id' => '2']]);
        $d = self::r('d', '/x?flag', '/t', ['query_mode' => 'params']);
        $e = self::r('e', '/x', '/t', ['query_mode' => 'params', 'query_params' => ['flag' => null]]);

        self::assertSame([['a', 'b'], ['d', 'e']], $this->idGroups([$a, $b, $c, $d, $e]));
    }

    public function testWildcardAndRegexRules(): void
    {
        self::assertSame([['a', 'b']], $this->idGroups([
            self::r('a', '/docs/*', '/1', ['match_type' => 'wildcard']),
            self::r('b', '/DOCS/*', '/2', ['match_type' => 'wildcard']),
        ]));
        self::assertSame([['a', 'b']], $this->idGroups([
            self::r('a', '^/x/(\d+)$', '/1', ['match_type' => 'regex']),
            self::r('b', '^/x/(\d+)$', '/2', ['match_type' => 'regex']),
        ]));
        self::assertSame([], $this->idGroups([
            self::r('a', '^/x/(\d+)$', '/1', ['match_type' => 'regex']),
            self::r('b', '^/x/(\d+)$', '/2', ['match_type' => 'regex', 'case_sensitive' => true]),
        ]));
        // an exact and a wildcard rule with the same text are different match types
        self::assertSame([], $this->idGroups([self::r('a', '/x'), self::r('b', '/x', '/t', ['match_type' => 'wildcard'])]));
    }

    public function testDisabledExpiredAndUnusableRulesAreIgnored(): void
    {
        $rules = [
            self::r('a', '/x'),
            self::r('b', '/x', '/t', ['enabled' => false]),
            self::r('c', '/x', '/t', ['expires_at' => '2026-01-01T00:00:00+00:00']),
            self::r('d', '/x', '/t', ['active_from' => '2027-01-01T00:00:00+00:00']),
            self::r('e', '', '/t'),
            self::r('f', "/\0", '/t'),
            self::r('g', "/\0", '/t'),
            self::r('h', '', '/t', ['match_type' => 'regex']),
        ];

        self::assertSame([['a', 'd']], $this->idGroups($rules));
    }

    public function testNormalizesSources(): void
    {
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/a//b/./c'), self::r('b', '/a/b/c/')]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', '/caf%C3%A9'), self::r('b', '/CAFÉ')]));
        self::assertSame([['a', 'b']], $this->idGroups([self::r('a', 'x'), self::r('b', '/x')]));
    }

    public function testGroupsAreTransitive(): void
    {
        $de = self::r('a', '/x', '/1', ['conditions' => ['languages' => ['de']]]);
        $any = self::r('b', '/x', '/2');
        $en = self::r('c', '/x', '/3', ['conditions' => ['languages' => ['en']]]);

        self::assertSame([['a', 'b', 'c']], $this->idGroups([$de, $any, $en]));
    }

    public function testOverlapNeedsSameTypeAndUsableSources(): void
    {
        $detector = AnalysisFixtures::conflicts();

        self::assertFalse($detector->overlaps(self::r('a', '/x'), self::r('b', '/x', '/t', ['match_type' => 'wildcard'])));
        self::assertFalse($detector->overlaps(self::r('a', "/x\0"), self::r('b', '/x')));
        self::assertNull($detector->bucketKey(self::r('a', '')));
    }

    public function testPathKey(): void
    {
        self::assertSame('/a/b', PathKey::normalized('/a/b'));
        self::assertSame('/a/b', PathKey::normalized('/a//b'));
        self::assertSame('/a b', PathKey::normalized('/a%20b'));
        self::assertSame('/', PathKey::normalized('/x/..'));
        self::assertNull(PathKey::normalized(''));
        self::assertNull(PathKey::normalized("/a\0"));
        self::assertNull(PathKey::normalized('/' . str_repeat('a', 5000)));
        self::assertSame('/café', PathKey::fold('/CAFÉ/'));
        self::assertSame('/', PathKey::fold('/'));
        self::assertSame('/a/b', PathKey::pathOf('/a/b?x=1#f'));
        self::assertSame(['x' => '1', 'y' => ['2']], PathKey::queryOf('/a?x=1&y[]=2#frag'));
        self::assertSame([], PathKey::queryOf('/a'));
        self::assertSame([], PathKey::parseQuery(''));
    }
}
