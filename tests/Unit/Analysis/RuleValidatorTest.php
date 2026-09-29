<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\IssueFactory;
use Grav\Plugin\RedirectManager\Analysis\PartialRuleSet;
use Grav\Plugin\RedirectManager\Analysis\RuleIndex;
use Grav\Plugin\RedirectManager\Analysis\RuleValidator;
use Grav\Plugin\RedirectManager\Analysis\Severity;
use Grav\Plugin\RedirectManager\Analysis\ShadowDetector;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Analysis\ValidationResult;
use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleValidator::class)]
#[CoversClass(ValidationIssue::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(RuleIndex::class)]
#[CoversClass(PartialRuleSet::class)]
#[CoversClass(ShadowDetector::class)]
#[CoversClass(IssueFactory::class)]
final class RuleValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private static function r(string $id, string $source, string $target = '', array $extra = []): Rule
    {
        return AnalysisFixtures::rule($id, $source, $target, $extra);
    }

    /**
     * @param list<Rule> $existing
     */
    private function check(Rule $candidate, array $existing = []): ValidationResult
    {
        return AnalysisFixtures::validator()->validate($candidate, $existing);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function fieldCases(): iterable
    {
        yield 'empty source' => [['source' => '', 'target' => '/x'], 'source_empty', 'error', 'source'];
        yield 'blank source' => [['source' => '  ', 'target' => '/x'], 'source_empty', 'error', 'source'];
        yield 'query only source' => [['source' => '?a=1', 'target' => '/x'], 'source_empty', 'error', 'source'];
        yield 'full url source' => [['source' => 'https://example.org/a', 'target' => '/x'], 'source_invalid', 'error', 'source'];
        yield 'nul source' => [['source' => '/a%00b', 'target' => '/x', 'match_type' => 'wildcard'], 'source_invalid', 'error', 'source'];
        yield 'invalid regex' => [['source' => '^/(a', 'target' => '/x', 'match_type' => 'regex'], 'regex_invalid', 'error', 'source'];
        yield 'catastrophic regex' => [['source' => '^(a+)+$', 'target' => '/x', 'match_type' => 'regex'], 'regex_catastrophic', 'error', 'source'];
        yield 'long regex' => [['source' => str_repeat('a', 1001), 'target' => '/x', 'match_type' => 'regex'], 'regex_too_long', 'error', 'source'];
        yield 'target required' => [['source' => '/a', 'target' => ''], 'target_required', 'error', 'target'];
        yield 'target required blank' => [['source' => '/a', 'target' => '   ', 'status' => 302], 'target_required', 'error', 'target'];
        yield 'pass-through needs target' => [['source' => '/a', 'target' => '', 'status' => 200], 'target_required', 'error', 'target'];
        yield 'gone with target' => [['source' => '/a', 'target' => '/x', 'status' => 410], 'target_not_allowed_for_status', 'warning', 'target'];
        yield '451 with target' => [['source' => '/a', 'target' => '/x', 'status' => 451], 'target_not_allowed_for_status', 'warning', 'target'];
        yield 'protocol relative' => [['source' => '/a', 'target' => '//evil.example'], 'target_protocol_relative', 'error', 'target'];
        yield 'encoded protocol relative' => [['source' => '/a', 'target' => '/%2F%2Fevil.example'], 'target_protocol_relative', 'error', 'target'];
        yield 'scheme in route' => [['source' => '/a', 'target' => 'javascript:alert(1)'], 'target_scheme', 'error', 'target'];
        yield 'ftp url' => [['source' => '/a', 'target' => 'ftp://example.org/x', 'target_type' => 'url'], 'target_scheme', 'error', 'target'];
        yield 'host not allowed' => [['source' => '/a', 'target' => 'https://evil.example/x', 'target_type' => 'url'], 'target_host_not_allowed', 'error', 'target'];
        yield 'relative route' => [['source' => '/a', 'target' => 'no-slash'], 'target_invalid', 'error', 'target'];
        yield 'control chars' => [['source' => '/a', 'target' => "/x\ny"], 'target_invalid', 'error', 'target'];
        yield 'page relative' => [['source' => '/a', 'target' => 'page', 'target_type' => 'page'], 'target_invalid', 'error', 'target'];
        yield 'pass-through url type' => [['source' => '/a', 'target' => 'https://www.example.org/x', 'target_type' => 'url', 'status' => 200], 'passthrough_external', 'error', 'target'];
        yield 'pass-through absolute' => [['source' => '/a', 'target' => 'https://www.example.org/x', 'status' => 200], 'passthrough_external', 'error', 'target'];
        yield 'placeholder too high' => [['source' => '/a/*/*', 'target' => '/x/$3', 'match_type' => 'wildcard'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'placeholder braces' => [['source' => '/a/*', 'target' => '/x/${2}', 'match_type' => 'wildcard'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'placeholder zero' => [['source' => '/a/*', 'target' => '/x/$0', 'match_type' => 'wildcard'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'placeholder on exact' => [['source' => '/a', 'target' => '/x/$1'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'named placeholder undefined' => [['source' => '/a/*', 'target' => '/x/{name}', 'match_type' => 'wildcard'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'regex numbered too high' => [['source' => '^/a/(\d+)/(\d+)$', 'target' => '/x/$3', 'match_type' => 'regex'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'regex unknown name' => [['source' => '^/a/(?<id>\d+)$', 'target' => '/x/{slug}', 'match_type' => 'regex'], 'target_placeholder_unknown', 'warning', 'target'];
        yield 'inverted dates' => [['source' => '/a', 'target' => '/x', 'active_from' => '2026-10-01T00:00:00+00:00', 'expires_at' => '2026-09-30T00:00:00+00:00'], 'dates_inverted', 'error', 'expires_at'];
        yield 'equal dates' => [['source' => '/a', 'target' => '/x', 'active_from' => '2026-10-01T00:00:00+00:00', 'expires_at' => '2026-10-01T00:00:00+00:00'], 'dates_inverted', 'error', 'expires_at'];
        yield 'expired' => [['source' => '/a', 'target' => '/x', 'expires_at' => '2026-01-01T00:00:00+00:00'], 'expired', 'warning', 'expires_at'];
        yield 'condition regex invalid' => [['source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '(']]]], 'regex_invalid', 'error', 'conditions'];
        yield 'condition regex empty' => [['source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '']]]], 'regex_invalid', 'error', 'conditions'];
        yield 'condition regex catastrophic' => [['source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '^(a+)+$']]]], 'regex_catastrophic', 'error', 'conditions'];
        yield 'condition without name' => [['source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'cookie', 'name' => '', 'operator' => 'exists']]]], 'condition_invalid', 'error', 'conditions'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('fieldCases')]
    public function testFieldValidation(array $data, string $code, string $severity, string $field): void
    {
        $result = $this->check(Rule::fromArray($data + ['id' => 'c']));
        $issue = AnalysisFixtures::find($result->issues, $code);

        self::assertNotNull($issue, $code . ' expected, got ' . implode(',', AnalysisFixtures::codes($result->issues)));
        self::assertSame($severity, $issue->severity->value);
        self::assertSame($field, $issue->field);
        self::assertNotSame('', $issue->message);
        self::assertSame($severity === 'error', $result->hasErrors() && in_array($issue, $result->errors(), true));
    }

    public function testValidRulesHaveNoIssues(): void
    {
        foreach ([
            self::r('c', '/old', '/new'),
            self::r('c', '/old/*', '/new/$1', ['match_type' => 'wildcard']),
            self::r('c', '/old/*/x/*', '/new/$2/$1', ['match_type' => 'wildcard']),
            self::r('c', '^/a/(?<id>\d+)/([^/]+)$', '/x/{id}/$2/{lang}', ['match_type' => 'regex']),
            self::r('c', '/gone', '', ['status' => 410]),
            self::r('c', '/ext', 'https://www.example.org/x', ['target_type' => 'url']),
            self::r('c', '/Ünï', '/café'),
            self::r('c', '/page', '/target', ['target_type' => 'page', 'status' => 302]),
        ] as $rule) {
            $result = $this->check($rule);
            self::assertSame([], $result->toArray(), $rule->source);
            self::assertFalse($result->hasErrors());
        }
    }

    public function testPlaceholderIssueParams(): void
    {
        $result = $this->check(self::r('c', '/a/*/*', '/x/$3/$3/${4}', ['match_type' => 'wildcard']));
        $unknown = array_values(array_filter($result->issues, static fn (ValidationIssue $i): bool => $i->code === 'target_placeholder_unknown'));

        self::assertCount(2, $unknown);
        self::assertSame('$3', $unknown[0]->params['placeholder']);
        self::assertSame(2, $unknown[0]->params['groups']);
        self::assertSame('${4}', $unknown[1]->params['placeholder']);
    }

    public function testPlaceholderWithBrokenRegexIsNotDoubleReported(): void
    {
        $result = $this->check(self::r('c', '^/(a', '/x/$1', ['match_type' => 'regex']));

        self::assertSame(['regex_invalid'], AnalysisFixtures::codes($result->issues));
    }

    public function testLangPlaceholderIsKnown(): void
    {
        self::assertSame([], $this->check(self::r('c', '/a', '/{lang}/x'))->issues);
    }

    public function testResultHelpers(): void
    {
        $result = $this->check(self::r('c', '', '/x', ['status' => 301, 'expires_at' => '2026-01-01T00:00:00+00:00']));

        self::assertTrue($result->hasErrors());
        self::assertCount(1, $result->errors());
        $result = $this->check(self::r('c', '/a', '/x', ['expires_at' => '2026-01-01T00:00:00+00:00']));
        self::assertFalse($result->hasErrors());
        self::assertCount(1, $result->warnings());
        self::assertSame([], $result->infos());
        self::assertSame('expired', $result->toArray()[0]['code']);
        self::assertSame(['code', 'severity', 'field', 'message', 'params'], array_keys($result->toArray()[0]));
    }

    public function testIssueFactoryMethodsAndSeverity(): void
    {
        self::assertSame(Severity::Info, ValidationIssue::info('x', 'f', 'm')->severity);
        self::assertTrue(ValidationIssue::error('x', 'f', 'm')->isError());
        self::assertFalse(ValidationIssue::warning('x', 'f', 'm')->isError());
    }

    public function testLoopThroughExistingRule(): void
    {
        $result = $this->check(self::r('c', '/a', '/b'), [self::r('e', '/b', '/a')]);

        $loop = AnalysisFixtures::find($result->issues, 'loop');
        self::assertNotNull($loop);
        self::assertSame('error', $loop->severity->value);
        self::assertEqualsCanonicalizing(['c', 'e'], $loop->params['cycle']);
        self::assertTrue($result->hasErrors());
    }

    public function testSelfRedirect(): void
    {
        self::assertNotNull(AnalysisFixtures::find($this->check(self::r('c', '/a', '/a/'))->issues, 'self_redirect'));
        self::assertNotNull(AnalysisFixtures::find($this->check(self::r('c', '/About', '/about'))->issues, 'self_redirect'));
    }

    public function testChainStartingAtCandidate(): void
    {
        $result = $this->check(self::r('c', '/a', '/b'), [self::r('e1', '/b', '/c'), self::r('e2', '/c', '/d')]);

        $chain = AnalysisFixtures::find($result->issues, 'chain');
        self::assertNotNull($chain);
        self::assertSame('/d', $chain->params['shortcut']);
        self::assertSame(['/a', '/b', '/c', '/d'], $chain->params['chain']);
        self::assertArrayNotHasKey('incoming', $chain->params);
    }

    public function testChainThroughWildcardExistingRules(): void
    {
        $result = $this->check(
            self::r('c', '/a', '/mid/x'),
            [self::r('w', '/mid/*', '/new/$1', ['match_type' => 'wildcard'])],
        );

        self::assertSame('/new/x', AnalysisFixtures::find($result->issues, 'chain')?->params['shortcut']);
    }

    public function testIncomingChainFromExistingRule(): void
    {
        $result = $this->check(self::r('c', '/b', '/c'), [self::r('e', '/a', '/b'), self::r('other', '/z', '/y')]);

        $chain = AnalysisFixtures::find($result->issues, 'chain');
        self::assertNotNull($chain);
        self::assertTrue($chain->params['incoming']);
        self::assertSame(['e', 'c'], $chain->params['rule_ids']);
        self::assertFalse($result->hasErrors());
    }

    public function testIncomingChainThroughCapturingRuleForWildcardCandidate(): void
    {
        $result = $this->check(
            self::r('c', '/mid/*', '/new/$1', ['match_type' => 'wildcard']),
            [self::r('e', '/old/*', '/mid/$1', ['match_type' => 'wildcard'])],
        );

        $chain = AnalysisFixtures::find($result->issues, 'chain');
        self::assertNotNull($chain);
        self::assertTrue($chain->params['incoming']);
    }

    public function testMaxDepth(): void
    {
        $existing = [];
        for ($i = 1; $i < 6; ++$i) {
            $existing[] = self::r('e' . $i, '/p' . $i, '/p' . ($i + 1));
        }
        $validator = AnalysisFixtures::validator(['depth' => 3]);
        $result = $validator->validate(self::r('c', '/p0', '/p1'), $existing);

        $deep = AnalysisFixtures::find($result->issues, 'chain_too_deep');
        self::assertNotNull($deep);
        self::assertSame(3, $deep->params['max_depth']);
    }

    public function testConflictAndDuplicate(): void
    {
        $result = $this->check(self::r('c', '/a', '/x'), [self::r('e1', '/a', '/y'), self::r('e2', '/a', '/x'), self::r('e3', '/b', '/x')]);

        self::assertSame(['e1'], AnalysisFixtures::find($result->issues, 'conflict')?->params['rule_ids']);
        self::assertSame(['e2'], AnalysisFixtures::find($result->issues, 'duplicate')?->params['rule_ids']);
        self::assertFalse($result->hasErrors());
    }

    public function testConflictForWildcardAndRegexCandidates(): void
    {
        $wild = $this->check(self::r('c', '/a/*', '/x', ['match_type' => 'wildcard']), [self::r('e', '/A/*', '/y', ['match_type' => 'wildcard']), self::r('f', '/a/x', '/y')]);
        self::assertSame(['e'], AnalysisFixtures::find($wild->issues, 'conflict')?->params['rule_ids']);

        $regex = $this->check(self::r('c', '^/a/(\d+)$', '/x/$1', ['match_type' => 'regex']), [self::r('e', '^/a/(\d+)$', '/y/$1', ['match_type' => 'regex'])]);
        self::assertSame(['e'], AnalysisFixtures::find($regex->issues, 'conflict')?->params['rule_ids']);
    }

    public function testNoConflictForDisabledOtherOrOtherHost(): void
    {
        $existing = [
            self::r('e1', '/a', '/y', ['enabled' => false]),
            self::r('e2', '/a', '/y', ['conditions' => ['hosts' => ['other.example']]]),
        ];
        $candidate = self::r('c', '/a', '/x', ['conditions' => ['hosts' => ['mine.example']]]);

        self::assertNull(AnalysisFixtures::find($this->check($candidate, $existing)->issues, 'conflict'));
    }

    public function testShadowed(): void
    {
        $result = $this->check(self::r('c', '/a', '/x'), [self::r('e', '/a', '/y', ['priority' => 10])]);
        self::assertSame('e', AnalysisFixtures::find($result->issues, 'shadowed')?->params['rule_id']);

        $wide = $this->check(self::r('c', '/docs/page', '/x'), [self::r('w', '/docs/*', '/y', ['match_type' => 'wildcard', 'priority' => 3])]);
        self::assertSame('w', AnalysisFixtures::find($wide->issues, 'shadowed')?->params['rule_id']);

        self::assertNull(AnalysisFixtures::find($this->check(self::r('c', '/a', '/x', ['priority' => 10]), [self::r('e', '/a', '/y')])->issues, 'shadowed'));
    }

    public function testEditReplacesTheRuleWithTheSameId(): void
    {
        $existing = [self::r('c', '/a', '/old-target'), self::r('other', '/b', '/c')];
        $result = $this->check(self::r('c', '/a', '/new-target'), $existing);

        self::assertSame([], $result->issues);
    }

    public function testEditRemovesLoopsOfTheOldVersion(): void
    {
        $existing = [self::r('c', '/a', '/b'), self::r('e', '/b', '/a')];

        self::assertNotNull(AnalysisFixtures::find($this->check(self::r('c', '/a', '/b'), $existing)->issues, 'loop'));
        self::assertNull(AnalysisFixtures::find($this->check(self::r('c', '/a', '/elsewhere'), $existing)->issues, 'loop'));
    }

    public function testInactiveCandidateTakesPartInNothing(): void
    {
        $existing = [self::r('e', '/a', '/y', ['priority' => 10]), self::r('f', '/b', '/a')];

        self::assertSame([], $this->check(self::r('c', '/a', '/x', ['enabled' => false]), $existing)->issues);
        self::assertSame(['expired'], AnalysisFixtures::codes($this->check(self::r('c', '/a', '/x', ['expires_at' => '2026-01-01T00:00:00+00:00']), $existing)->issues));
    }

    public function testInvalidSourceSkipsRelationalChecks(): void
    {
        $result = $this->check(self::r('c', '^/(a', '/b', ['match_type' => 'regex']), [self::r('e', '/b', '/a')]);

        self::assertSame(['regex_invalid'], AnalysisFixtures::codes($result->issues));
    }

    public function testRegexCandidateThatCannotBeSampledIsInformative(): void
    {
        $result = $this->check(self::r('c', '^/a/(?!x)(.*)$', '/b/$1', ['match_type' => 'regex']));

        self::assertSame(['analysis_skipped'], AnalysisFixtures::codes($result->issues));
        self::assertFalse($result->hasErrors());
    }

    public function testExpiredAndDisabledExistingRulesAreIgnored(): void
    {
        $existing = [
            self::r('e1', '/b', '/a', ['enabled' => false]),
            self::r('e2', '/a', '/z', ['expires_at' => '2026-01-01T00:00:00+00:00']),
        ];

        self::assertSame([], $this->check(self::r('c', '/a', '/b'), $existing)->issues);
    }

    public function testPreviewExact(): void
    {
        $preview = AnalysisFixtures::validator()->preview(self::r('c', '/blog/2024/test', '/journal/2024/test'), [], '/blog/2024/test');

        self::assertNotNull($preview);
        self::assertSame(301, $preview['status']);
        self::assertSame('/journal/2024/test', $preview['location']);
        self::assertSame('c', $preview['rule_id']);
        self::assertTrue($preview['matched_candidate']);
        self::assertSame(['c'], $preview['rules']);
    }

    public function testPreviewWildcardAndRegex(): void
    {
        $validator = AnalysisFixtures::validator();

        $wild = $validator->preview(self::r('c', '/blog/*', '/journal/$1', ['match_type' => 'wildcard']), [], 'https://www.example.org/blog/2024/test?x=1');
        self::assertSame('/journal/2024/test', $wild['location'] ?? null);

        $regex = $validator->preview(self::r('c', '^/p/(?<id>\d+)$', '/product/{id}', ['match_type' => 'regex', 'status' => 302]), [], 'p/42');
        self::assertSame('/product/42', $regex['location'] ?? null);
        self::assertSame(302, $regex['status'] ?? null);
        self::assertSame('42', $regex['captures']['id'] ?? null);
    }

    public function testPreviewHigherRuleWins(): void
    {
        $preview = AnalysisFixtures::validator()->preview(
            self::r('c', '/a', '/x'),
            [self::r('e', '/a', '/y', ['priority' => 5]), self::r('f', '/y', '/z')],
            '/a',
        );

        self::assertNotNull($preview);
        self::assertFalse($preview['matched_candidate']);
        self::assertSame('e', $preview['rule_id']);
    }

    public function testPreviewUsesExistingRulesAndTheirWildcards(): void
    {
        $preview = AnalysisFixtures::validator()->preview(
            self::r('c', '/a', '/mid/x', ['continue' => true, 'priority' => 5]),
            [self::r('w', '/mid/*', '/new/$1', ['match_type' => 'wildcard'])],
            '/a',
        );

        self::assertSame('/new/x', $preview['location'] ?? null);
        self::assertSame(['c', 'w'], $preview['rules'] ?? null);
        self::assertTrue($preview['matched_candidate'] ?? false);
    }

    public function testPreviewTakesHostAndLanguageFromCandidate(): void
    {
        $candidate = self::r('c', '/a', '/de/x', ['conditions' => ['hosts' => ['*.example.org'], 'languages' => ['de'], 'schemes' => ['http']]]);
        $validator = AnalysisFixtures::validator();

        self::assertSame('/de/x', $validator->preview($candidate, [], '/a')['location'] ?? null);
        self::assertNull($validator->preview($candidate, [], '/a', 'en'));
        self::assertNull($validator->preview($candidate, [], 'https://other.example/a'));
        self::assertSame('/de/x', $validator->preview($candidate, [], 'http://blog.example.org/a')['location'] ?? null);
    }

    public function testPreviewRejectsUnusableInput(): void
    {
        $validator = AnalysisFixtures::validator();
        $candidate = self::r('c', '/a', '/x');

        self::assertNull($validator->preview($candidate, [], ''));
        self::assertNull($validator->preview($candidate, [], "/a%00"));
        self::assertNull($validator->preview($candidate, [], 'http:///'));
        self::assertNull($validator->preview($candidate, [], '/nothing-matches-this'));
    }

    public function testSampleFor(): void
    {
        $validator = AnalysisFixtures::validator();

        self::assertSame('/old', $validator->sampleFor(self::r('c', '/old', '/x')));
        self::assertSame('/blog/example', $validator->sampleFor(self::r('c', '/blog/*', '/x', ['match_type' => 'wildcard'])));
        self::assertSame('/blog/1/example', $validator->sampleFor(self::r('c', '^/blog/(\d+)/([^/]+)$', '/x', ['match_type' => 'regex'])));
        self::assertNull($validator->sampleFor(self::r('c', '', '/x')));
    }
}
