<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\ShadowDetector;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Tests\Unit\Matching\MatcherHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShadowDetector::class)]
#[Group('analysis')]
final class ShadowDetectorTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $askedPaths filled with every path the detector asked a matcher for
     *
     * @return callable(string): Matcher
     */
    private function matcherFor(array $rows, array &$askedPaths = []): callable
    {
        $matcher = MatcherHarness::matcher(MatcherHarness::compile($rows));

        return static function (string $path) use ($matcher, &$askedPaths): Matcher {
            $askedPaths[] = $path;

            return $matcher;
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rule(array $row): Rule
    {
        return Rule::fromArray($row);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unsupportedProvider(): iterable
    {
        yield 'wildcard' => [['id' => 'w', 'source' => '/a/*', 'target' => '/b', 'match_type' => 'wildcard']];
        yield 'regex' => [['id' => 'r', 'source' => '^/a$', 'target' => '/b', 'match_type' => 'regex']];
        yield 'empty source' => [['id' => 'e', 'source' => '', 'target' => '/b']];
        yield 'blank source' => [['id' => 'e', 'source' => "  \t", 'target' => '/b']];
        yield 'absolute url source' => [['id' => 'u', 'source' => 'https://example.com/a', 'target' => '/b']];
        yield 'unsatisfiable header condition' => [[
            'id' => 'c', 'source' => '/a', 'target' => '/b',
            'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X-Any', 'operator' => 'regex', 'value' => '(?=a)(?!a)a']]],
        ]];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('unsupportedProvider')]
    public function testRulesItCannotJudgeAreNeverShadowed(array $row): void
    {
        $asked = [];
        $others = [['id' => 'top', 'source' => '/a', 'target' => '/other', 'priority' => 100]];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($row), $this->matcherFor($others, $asked)));
        self::assertSame([], $asked, 'no matcher is built for a rule that cannot be sampled');
    }

    public function testAHigherPriorityRuleWithTheSameSourceShadowsTheRule(): void
    {
        $rows = [
            ['id' => 'low', 'source' => '/old', 'target' => '/low', 'priority' => 0],
            ['id' => 'high', 'source' => '/old', 'target' => '/high', 'priority' => 10],
        ];

        self::assertSame('high', (new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[1]), $this->matcherFor($rows)), 'the winner is not shadowed');
    }

    public function testAWildcardCanShadowAnExactRuleOfLowerPriority(): void
    {
        $rows = [
            ['id' => 'exact', 'source' => '/blog/post', 'target' => '/a', 'priority' => 0],
            ['id' => 'wild', 'source' => '/blog/*', 'target' => '/b', 'match_type' => 'wildcard', 'priority' => 5],
        ];

        self::assertSame('wild', (new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testTheMatcherIsAskedForTheSamplePath(): void
    {
        $asked = [];
        $rows = [['id' => 'a', 'source' => '/Blog//Post/', 'target' => '/b']];

        (new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows, $asked));

        self::assertCount(1, $asked);
        self::assertStringStartsWith('/', $asked[0]);
        self::assertStringNotContainsString('//', $asked[0]);
    }

    public function testARuleThatIsNotInvolvedInTheResultIsNotShadowed(): void
    {
        $rows = [
            ['id' => 'mine', 'source' => '/old', 'target' => '/new'],
            ['id' => 'unrelated', 'source' => '/elsewhere', 'target' => '/x', 'priority' => 50],
        ];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testDisabledRuleIsNotShadowedByItself(): void
    {
        $rows = [['id' => 'off', 'source' => '/old', 'target' => '/new', 'enabled' => false]];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)), 'nothing answers the path at all');
    }

    public function testARuleReachedThroughAChainIsNotShadowed(): void
    {
        $rows = [
            ['id' => 'first', 'source' => '/a', 'target' => '/b', 'continue' => true, 'priority' => 10],
            ['id' => 'second', 'source' => '/b', 'target' => '/c'],
            ['id' => 'mine', 'source' => '/a', 'target' => '/d'],
        ];

        // "first" answers /a, continues to /b and "second" decides; "mine" is never reached.
        self::assertSame('first', (new ShadowDetector())->shadowedBy($this->rule($rows[2]), $this->matcherFor($rows)));
        // ... but "second" takes part in the chain the sample of "first" starts.
        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testQueryModeExactRulesAreSampledWithTheirQuery(): void
    {
        $rows = [
            ['id' => 'q', 'source' => '/search?tab=1', 'target' => '/s1', 'query_mode' => 'exact'],
            ['id' => 'plain', 'source' => '/search', 'target' => '/s2', 'priority' => 5],
        ];

        self::assertSame('plain', (new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testRuleWithAQueryConditionIsNotShadowedByARuleThatNeedsAnotherQuery(): void
    {
        $rows = [
            ['id' => 'mine', 'source' => '/search', 'target' => '/s1'],
            ['id' => 'other', 'source' => '/search?tab=2', 'target' => '/s2', 'query_mode' => 'exact', 'priority' => 5],
        ];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testTheFirstLanguageConditionSelectsTheSampleLanguage(): void
    {
        $rows = [
            ['id' => 'de', 'source' => '/a', 'target' => '/de', 'conditions' => ['languages' => ['de']]],
            ['id' => 'en', 'source' => '/a', 'target' => '/en', 'conditions' => ['languages' => ['en', 'de']], 'priority' => 10],
            ['id' => 'any', 'source' => '/a', 'target' => '/any', 'priority' => -10],
        ];
        $detector = new ShadowDetector();

        self::assertSame('en', $detector->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)), 'a german request is answered by the rule that also lists de');
        self::assertNull($detector->shadowedBy($this->rule($rows[1]), $this->matcherFor($rows)), 'sampled in en: only itself answers');
        self::assertNull($detector->shadowedBy($this->rule($rows[2]), $this->matcherFor($rows)), 'sampled without a language: the conditional rules do not apply');
    }

    public function testARuleForAnotherLanguageDoesNotShadow(): void
    {
        $rows = [
            ['id' => 'de', 'source' => '/a', 'target' => '/de', 'conditions' => ['languages' => ['de']]],
            ['id' => 'fr', 'source' => '/a', 'target' => '/fr', 'conditions' => ['languages' => ['fr']], 'priority' => 10],
        ];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testEarlyRulesAreNotShadowedByRulesOfTheNotFoundPhase(): void
    {
        $rows = [
            ['id' => 'early', 'source' => '/a', 'target' => '/b'],
            ['id' => 'late', 'source' => '/a', 'target' => '/c', 'only_if_not_found' => true, 'priority' => 100],
        ];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testAnOnlyIfNotFoundRuleIsShadowedByAnyEarlyRuleRegardlessOfPriority(): void
    {
        $rows = [
            ['id' => 'late', 'source' => '/a', 'target' => '/c', 'only_if_not_found' => true, 'priority' => 100],
            ['id' => 'early', 'source' => '/a', 'target' => '/b', 'priority' => -100],
        ];

        self::assertSame('early', (new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testAnOnlyIfNotFoundRuleCanBeShadowedByAnotherOfTheSamePhase(): void
    {
        $rows = [
            ['id' => 'late-low', 'source' => '/a', 'target' => '/c', 'only_if_not_found' => true, 'priority' => 0],
            ['id' => 'late-high', 'source' => '/a', 'target' => '/d', 'only_if_not_found' => true, 'priority' => 9],
        ];
        $detector = new ShadowDetector();

        self::assertSame('late-high', $detector->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
        self::assertNull($detector->shadowedBy($this->rule($rows[1]), $this->matcherFor($rows)));
    }

    public function testAnOnlyIfNotFoundRuleWithoutCompetitionIsNotShadowed(): void
    {
        $rows = [['id' => 'late', 'source' => '/a', 'target' => '/c', 'only_if_not_found' => true]];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }

    public function testAnOnlyIfNotFoundRuleThatNothingAnswersIsNotShadowed(): void
    {
        $rows = [['id' => 'late', 'source' => '/a', 'target' => '/c', 'only_if_not_found' => true, 'enabled' => false]];

        self::assertNull((new ShadowDetector())->shadowedBy($this->rule($rows[0]), $this->matcherFor($rows)));
    }
}
