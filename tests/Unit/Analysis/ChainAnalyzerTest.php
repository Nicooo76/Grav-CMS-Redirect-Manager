<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\AnalysisReport;
use Grav\Plugin\RedirectManager\Analysis\ChainAnalyzer;
use Grav\Plugin\RedirectManager\Analysis\ChainOutcome;
use Grav\Plugin\RedirectManager\Analysis\ChainWalker;
use Grav\Plugin\RedirectManager\Analysis\IssueFactory;
use Grav\Plugin\RedirectManager\Analysis\Severity;
use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainAnalyzer::class)]
#[CoversClass(ChainWalker::class)]
#[CoversClass(ChainOutcome::class)]
#[CoversClass(AnalysisReport::class)]
#[CoversClass(IssueFactory::class)]
final class ChainAnalyzerTest extends TestCase
{
    /**
     * @param list<Rule>           $rules
     * @param array<string, mixed> $options
     */
    private function analyze(array $rules, array $options = []): AnalysisReport
    {
        return AnalysisFixtures::analyzer($options)->analyze($rules);
    }

    private static function r(string $id, string $source, string $target = '', array $extra = []): Rule
    {
        return AnalysisFixtures::rule($id, $source, $target, $extra);
    }

    public function testChainAToBToCReportsShortcut(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/b'), self::r('b', '/b', '/c')]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'chain');
        self::assertNotNull($issue);
        self::assertSame(Severity::Warning, $issue->severity);
        self::assertSame(['/a', '/b', '/c'], $issue->params['chain']);
        self::assertSame('/c', $issue->params['shortcut']);
        self::assertSame(['a', 'b'], $issue->params['rule_ids']);
        self::assertSame([], $report->issuesFor('b'));
        self::assertSame(['active', 'chain'], $report->badges('a'));
        self::assertSame(['active'], $report->badges('b'));
        self::assertSame([['rule_ids' => ['a', 'b'], 'paths' => ['/a', '/b', '/c'], 'shortcut' => '/c', 'shortcut_status' => null]], $report->chains());
        self::assertCount(1, $report->chainsFor('a'));
    }

    public function testThreeHopChainKeepsSubchainsSeparate(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/b'), self::r('b', '/b', '/c'), self::r('c', '/c', '/d')]);

        self::assertSame('/d', AnalysisFixtures::find($report->issuesFor('a'), 'chain')?->params['shortcut']);
        self::assertSame('/d', AnalysisFixtures::find($report->issuesFor('b'), 'chain')?->params['shortcut']);
        self::assertSame([], $report->issuesFor('c'));
    }

    public function testLoopReportsBothRules(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/b'), self::r('b', '/b', '/a')]);

        foreach (['a', 'b'] as $id) {
            $issue = AnalysisFixtures::find($report->issuesFor($id), 'loop');
            self::assertNotNull($issue, $id);
            self::assertSame(Severity::Error, $issue->severity);
            self::assertEqualsCanonicalizing(['a', 'b'], $issue->params['cycle']);
            self::assertContains('loop', $report->badges($id));
        }
        self::assertCount(1, $report->loops());
        self::assertEqualsCanonicalizing(['a', 'b'], $report->loops()[0]['rule_ids']);
    }

    public function testRuleLeadingIntoLoopIsFlagged(): void
    {
        $report = $this->analyze([self::r('x', '/x', '/a'), self::r('a', '/a', '/b'), self::r('b', '/b', '/a')]);

        $issue = AnalysisFixtures::find($report->issuesFor('x'), 'loop');
        self::assertNotNull($issue);
        self::assertEqualsCanonicalizing(['a', 'b'], $issue->params['cycle']);
        self::assertCount(1, $report->loops());
    }

    public function testSelfReference(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/a')]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'self_redirect');
        self::assertNotNull($issue);
        self::assertSame(Severity::Error, $issue->severity);
        self::assertContains('loop', $report->badges('a'));
    }

    public function testSelfReferenceThroughCaseInsensitiveMatch(): void
    {
        $report = $this->analyze([self::r('a', '/About', '/about')]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('a'), 'self_redirect'));
    }

    public function testCaseOnlySelfReferenceSaysSoAndOtherOnesDoNot(): void
    {
        $issue = AnalysisFixtures::find($this->analyze([self::r('a', '/About', '/about')])->issuesFor('a'), 'self_redirect');
        self::assertNotNull($issue);
        self::assertTrue($issue->params['case_only'] ?? false, 'the editor offers "make the rule case-sensitive"');
        self::assertStringContainsString('letter case', $issue->message);
        self::assertStringContainsString('case-sensitive', $issue->message);

        $plain = AnalysisFixtures::find($this->analyze([self::r('a', '/a', '/a')])->issuesFor('a'), 'self_redirect');
        self::assertNotNull($plain);
        self::assertArrayNotHasKey('case_only', $plain->params);
        self::assertStringNotContainsString('letter case', $plain->message);
    }

    public function testCaseSensitiveRuleDoesNotLoopOnCaseChange(): void
    {
        $report = $this->analyze([self::r('a', '/About', '/about', ['case_sensitive' => true])]);

        self::assertSame([], $report->issuesFor('a'));
    }

    public function testWildcardGrowthIsALoop(): void
    {
        $report = $this->analyze([self::r('a', '/blog/*', '/blog/2024/$1', ['match_type' => 'wildcard'])]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('a'), 'self_redirect'));
    }

    public function testChainViaWildcardResolvesCaptures(): void
    {
        $report = $this->analyze([
            self::r('w1', '/old/*', '/mid/$1', ['match_type' => 'wildcard']),
            self::r('w2', '/mid/*', '/new/$1', ['match_type' => 'wildcard']),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('w1'), 'chain');
        self::assertNotNull($issue);
        self::assertSame(['/old/sample', '/mid/sample', '/new/sample'], $issue->params['chain']);
        self::assertSame('/new/$1', $issue->params['shortcut']);
        self::assertSame([], $report->issuesFor('w2'));
    }

    public function testChainViaWildcardWithTwoCaptures(): void
    {
        $report = $this->analyze([
            self::r('w1', '/a/*/x/*', '/b/$2/$1', ['match_type' => 'wildcard']),
            self::r('w2', '/b/*', '/c/$1', ['match_type' => 'wildcard']),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('w1'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/c/$2/$1', $issue->params['shortcut']);
    }

    public function testChainViaRegexWithNamedGroups(): void
    {
        $report = $this->analyze([
            self::r('rx', '^/blog/(?<year>\d{4})/(?<slug>[^/]+)$', '/journal/{year}/{slug}', ['match_type' => 'regex']),
            self::r('w', '/journal/*', '/articles/$1', ['match_type' => 'wildcard']),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('rx'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/articles/{year}/{slug}', $issue->params['shortcut']);
        self::assertSame(['rx', 'w'], $issue->params['rule_ids']);
    }

    public function testChainViaRegexEndsInExactRule(): void
    {
        $report = $this->analyze([
            self::r('rx', '^/p/(\d+)$', '/product/$1', ['match_type' => 'regex']),
            self::r('e', '/product/9001', '/shop', []),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('rx'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/shop', $issue->params['shortcut']);
    }

    public function testRegexTooComplexToSampleIsSkippedWithInfo(): void
    {
        $report = $this->analyze([self::r('rx', '^/a/(?!x)(.*)$', '/b/$1', ['match_type' => 'regex'])]);

        $issue = AnalysisFixtures::find($report->issuesFor('rx'), 'analysis_skipped');
        self::assertNotNull($issue);
        self::assertSame(Severity::Info, $issue->severity);
    }

    public function testChainAcrossLanguageVariants(): void
    {
        $report = $this->analyze([
            self::r('x', '/x', '/y', ['conditions' => ['languages' => ['de', 'en']]]),
            self::r('y', '/y', '/z', ['conditions' => ['languages' => ['de']]]),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('x'), 'chain');
        self::assertNotNull($issue);
        self::assertSame(['de'], $issue->params['languages']);
        self::assertCount(1, $report->chains());
        self::assertSame('de', $report->chains()[0]['language']);
    }

    public function testLanguageVariantsWithSameResultAreMerged(): void
    {
        $report = $this->analyze([
            self::r('x', '/x', '/y', ['conditions' => ['languages' => ['de', 'en']]]),
            self::r('y', '/y', '/z'),
        ]);

        $issues = array_filter($report->issuesFor('x'), static fn ($i): bool => $i->code === 'chain');
        self::assertCount(1, $issues);
        self::assertSame(['de', 'en'], array_values($issues)[0]->params['languages']);
        self::assertCount(2, $report->chains());
    }

    public function testMaximumDepthExceeded(): void
    {
        $rules = [];
        for ($i = 0; $i < 6; ++$i) {
            $rules[] = self::r('p' . $i, '/p' . $i, '/p' . ($i + 1));
        }
        $report = $this->analyze($rules, ['depth' => 3]);

        $deep = AnalysisFixtures::find($report->issuesFor('p0'), 'chain_too_deep');
        self::assertNotNull($deep);
        self::assertSame(Severity::Error, $deep->severity);
        self::assertSame(3, $deep->params['max_depth']);
        self::assertNull(AnalysisFixtures::find($report->issuesFor('p0'), 'chain'));
        self::assertContains('chain', $report->badges('p0'));
        // p3 reaches /p6 in exactly three hops: a chain, not too deep
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('p3'), 'chain'));
        self::assertNull(AnalysisFixtures::find($report->issuesFor('p3'), 'chain_too_deep'));
    }

    public function testChainEndingInGoneSuggestsGone(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/b'), self::r('b', '/b', '', ['status' => 410])]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'chain');
        self::assertNotNull($issue);
        self::assertNull($issue->params['shortcut']);
        self::assertSame(410, $issue->params['shortcut_status']);
        self::assertSame(['/a', '/b'], $issue->params['chain']);
    }

    public function testChainEndingInExternalUrl(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b'),
            self::r('b', '/b', 'https://www.example.org/x', ['target_type' => 'url']),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('https://www.example.org/x', $issue->params['shortcut']);
    }

    public function testExternalTargetAloneIsNoChain(): void
    {
        $report = $this->analyze([self::r('a', '/a', 'https://www.example.org/x', ['target_type' => 'url'])]);

        self::assertSame([], $report->issuesFor('a'));
    }

    public function testDisabledAndExpiredRulesDoNotFormChains(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b'),
            self::r('b', '/b', '/c', ['enabled' => false]),
            self::r('c', '/c', '/d'),
            self::r('d', '/d', '/e', ['expires_at' => '2026-01-01T00:00:00+00:00']),
            self::r('e', '/e', '/f', ['active_from' => '2027-01-01T00:00:00+00:00']),
        ]);

        self::assertNull(AnalysisFixtures::find($report->issuesFor('a'), 'chain'));
        self::assertNull(AnalysisFixtures::find($report->issuesFor('c'), 'chain'));
        self::assertSame(['disabled'], $report->badges('b'));
        self::assertSame(['expired'], $report->badges('d'));
        self::assertSame(['scheduled'], $report->badges('e'));
        self::assertSame(['d'], $report->expired());
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('d'), 'expired'));
    }

    public function testPassThroughEndsChainButCanReferenceItself(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b'),
            self::r('b', '/b', '/c', ['status' => 200]),
            self::r('c', '/c', '/d'),
            self::r('p', '/p', '/p', ['status' => 200]),
            self::r('q', '/q', '/other', ['status' => 200]),
        ]);

        self::assertNull(AnalysisFixtures::find($report->issuesFor('a'), 'chain'));
        self::assertNull(AnalysisFixtures::find($report->issuesFor('b'), 'chain'));
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('p'), 'self_redirect'));
        self::assertSame([], $report->issuesFor('q'));
    }

    public function testRulesWithoutTargetAreNotAnalyzed(): void
    {
        $report = $this->analyze([self::r('g', '/gone', 'ignored', ['status' => 410]), self::r('e', '/empty', '')]);

        self::assertSame([], $report->issues());
    }

    public function testContinueRuleUsesTheMatchersOwnChaining(): void
    {
        $report = $this->analyze([
            self::r('c1', '/x', '/y', ['continue' => true, 'priority' => 10]),
            self::r('c2', '/y', '/z', ['priority' => 5]),
            self::r('c3', '/z', '/end'),
            self::r('c4', '/other', '/q', ['continue' => true, 'priority' => 1]),
        ]);

        // /x -> /y -> (continue) /z in one response; /z is another rule -> a chain of two hops
        $issue = AnalysisFixtures::find($report->issuesFor('c1'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/end', $issue->params['shortcut']);
        self::assertSame(['c1', 'c2', 'c3'], $issue->params['rule_ids']);
    }

    public function testQueryTargetsAreCarriedIntoNextHop(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b?tab=2'),
            self::r('b', '/b?tab=2', '/c', ['query_mode' => 'exact']),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/c', $issue->params['shortcut']);
    }

    public function testConflictsDuplicatesAndShadowedAreReported(): void
    {
        $report = $this->analyze([
            self::r('hi', '/a', '/x', ['priority' => 10]),
            self::r('lo', '/a', '/y'),
            self::r('same', '/a', '/x', ['priority' => 5]),
        ]);

        self::assertSame([['hi', 'lo', 'same']], array_map(static fn (array $g): array => array_values($g), $report->conflicts()));
        self::assertSame(['conflict', 'shadowed'], AnalysisFixtures::codes($report->issuesFor('lo')));
        self::assertSame(['conflict', 'duplicate'], AnalysisFixtures::codes($report->issuesFor('hi')));
        self::assertSame(['same'], AnalysisFixtures::find($report->issuesFor('hi'), 'duplicate')?->params['rule_ids']);
        self::assertSame(['lo'], AnalysisFixtures::find($report->issuesFor('hi'), 'conflict')?->params['rule_ids']);
        self::assertSame('hi', AnalysisFixtures::find($report->issuesFor('lo'), 'shadowed')?->params['rule_id']);
        self::assertContains('conflict', $report->badges('lo'));
    }

    public function testExactRuleShadowedByHigherWildcard(): void
    {
        $report = $this->analyze([
            self::r('w', '/docs/*', '/manual/$1', ['match_type' => 'wildcard', 'priority' => 5]),
            self::r('e', '/docs/page', '/elsewhere'),
        ]);

        self::assertSame('w', AnalysisFixtures::find($report->issuesFor('e'), 'shadowed')?->params['rule_id']);
    }

    public function testQueryRuleIsNotShadowedByPlainRuleOfLowerRank(): void
    {
        $report = $this->analyze([
            self::r('q', '/a?x=1', '/y', ['query_mode' => 'exact', 'priority' => 10]),
            self::r('p', '/a', '/z'),
            self::r('q2', '/b?x=1', '/y', ['query_mode' => 'exact']),
            self::r('p2', '/b', '/z', ['priority' => 10]),
        ]);

        self::assertNull(AnalysisFixtures::find($report->issuesFor('p'), 'shadowed'));
        // /b?x=1 with query mode exact is more specific but ranked lower than the general /b rule
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('q2'), 'shadowed'));
    }

    public function testOnlyIfNotFoundRuleIsShadowedByEarlyRule(): void
    {
        $report = $this->analyze([
            self::r('early', '/a', '/x', ['priority' => 10]),
            self::r('late', '/a', '/y', ['only_if_not_found' => true]),
            self::r('late2', '/b', '/y', ['only_if_not_found' => true, 'priority' => 10]),
            self::r('early2', '/b', '/x'),
        ]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('late'), 'shadowed'));
        // an early rule always runs before a not-found rule, whatever the priority
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('late2'), 'shadowed'));
        self::assertNull(AnalysisFixtures::find($report->issuesFor('early2'), 'shadowed'));
    }

    public function testNotFoundRulesAreShadowedByHigherNotFoundRules(): void
    {
        $report = $this->analyze([
            self::r('nf1', '/a', '/x', ['only_if_not_found' => true, 'priority' => 10]),
            self::r('nf2', '/a', '/y', ['only_if_not_found' => true]),
        ]);

        self::assertNull(AnalysisFixtures::find($report->issuesFor('nf1'), 'shadowed'));
        self::assertSame('nf1', AnalysisFixtures::find($report->issuesFor('nf2'), 'shadowed')?->params['rule_id']);
    }

    public function testTargetWithUnusablePathEndsTheWalk(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/x%00y'), self::r('u', 'https://x.example/a', '/b')]);

        self::assertSame([], $report->issuesFor('a'));
        self::assertSame([], $report->issuesFor('u'));
    }

    public function testRulesThatCannotWorkAreReported(): void
    {
        $report = $this->analyze([
            self::r('rx', '^/(unclosed', '/x', ['match_type' => 'regex']),
            self::r('long', str_repeat('a', 1001), '/x', ['match_type' => 'regex']),
            self::r('src', "/a\0b", '/x'),
            self::r('cond', '/c', '/x', ['conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '(']]]]),
        ]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('rx'), 'regex_invalid'));
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('long'), 'regex_too_long'));
        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('src'), 'source_invalid'));
        $condition = AnalysisFixtures::find($report->issuesFor('cond'), 'regex_invalid');
        self::assertSame('conditions', $condition?->field);
    }

    public function testHeaderConditionsOfTheStartRuleAreSatisfied(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b', ['conditions' => ['hosts' => ['*.example.org'], 'schemes' => ['http'], 'rules' => [
                ['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'bot'],
                ['kind' => 'cookie', 'name' => 'seen', 'operator' => 'exists'],
                ['kind' => 'header', 'name' => 'X-Test', 'operator' => 'regex', 'value' => '^ab+c$'],
                ['kind' => 'header', 'name' => 'X-Never', 'operator' => 'exists', 'negate' => true],
            ]]]),
            self::r('b', '/b', '/c', ['conditions' => ['hosts' => ['*.example.org']]]),
        ]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('a'), 'chain'));
    }

    public function testUnsatisfiableConditionSkipsTheRule(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b', ['conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '^(?=a)b']]]]),
            self::r('b', '/b', '/c'),
        ]);

        self::assertSame([], $report->issuesFor('a'));
    }

    public function testPlaceholderTargetsUseTheMatcher(): void
    {
        $report = $this->analyze([
            self::r('a', '/a', '/b/{lang}', ['conditions' => ['languages' => ['de']]]),
            self::r('b', '/b/de', '/c'),
        ]);

        $issue = AnalysisFixtures::find($report->issuesFor('a'), 'chain');
        self::assertNotNull($issue);
        self::assertSame('/c', $issue->params['shortcut']);
    }

    public function testUnsafeTargetProducesNoChain(): void
    {
        $report = $this->analyze([self::r('a', '/a', '//evil.example'), self::r('b', '/evil.example', '/c'), self::r('c', '/a2', 'a-relative')]);

        self::assertSame([], $report->issuesFor('a'));
        self::assertSame([], $report->issuesFor('c'));
    }

    public function testDoubleSlashesInTargetsAreCollapsed(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/x//b'), self::r('b', '/x/b', '/c')]);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('a'), 'chain'));
    }

    public function testReportToArrayAndUnknownRule(): void
    {
        $report = $this->analyze([self::r('a', '/a', '/b'), self::r('b', '/b', '/c')]);
        $array = $report->toArray();

        self::assertSame(['chains', 'loops', 'conflicts', 'expired', 'issues'], array_keys($array));
        self::assertSame('chain', $array['issues']['a'][0]['code']);
        self::assertSame(['code', 'severity', 'field', 'message', 'params'], array_keys($array['issues']['a'][0]));
        self::assertSame(['active'], $report->badges('missing'));
        self::assertSame([], $report->issuesFor('missing'));
        self::assertSame(['a'], array_keys($report->issues()));
    }

    public function testEmptyRuleList(): void
    {
        $report = $this->analyze([]);

        self::assertSame([], $report->issues());
        self::assertSame([], $report->chains());
    }
}
