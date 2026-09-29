<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\TraceStep;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Matcher::class)]
#[CoversClass(MatcherOptions::class)]
final class MatcherTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|string $request
     * @param array<string, mixed>        $opts
     * @return list<TraceStep>
     */
    private function trace(array $rows, array|string $request, array $opts = []): array
    {
        $ctx = MatcherHarness::context($request);
        self::assertNotNull($ctx);
        $result = MatcherHarness::matcher(MatcherHarness::compile($rows), $opts)->match($ctx, MatcherHarness::phase($opts), true);

        return $result === null ? [] : $result->trace;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|string $request
     * @param array<string, mixed>        $opts
     * @return list<string>
     */
    private function reasons(array $rows, array|string $request, array $opts = []): array
    {
        $ctx = MatcherHarness::context($request);
        self::assertNotNull($ctx);
        $matcher = MatcherHarness::matcher(MatcherHarness::compile($rows), $opts);
        $result = $matcher->match($ctx, MatcherHarness::phase($opts), true);
        if ($result !== null) {
            return array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $result->trace);
        }

        // No winner: rebuild the trace through a catch-all so it can be inspected.
        $rows[] = ['id' => 'zz-end', 'source' => '/*', 'target' => '/end', 'match_type' => 'wildcard', 'priority' => -1000];
        $result = MatcherHarness::matcher(MatcherHarness::compile($rows), $opts)->match($ctx, MatcherHarness::phase($opts), true);
        self::assertNotNull($result);

        return array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $result->trace);
    }

    public function testTraceListsEveryEvaluatedCandidateWithItsReason(): void
    {
        $rows = [
            ['id' => 'a01', 'source' => '/t', 'target' => '/x', 'enabled' => false, 'priority' => 100],
            ['id' => 'a02', 'source' => '/t', 'target' => '/x', 'expires_at' => '2026-01-01T00:00:00+00:00', 'priority' => 99],
            ['id' => 'a03', 'source' => '/t', 'target' => '/x', 'active_from' => '2027-01-01T00:00:00+00:00', 'priority' => 98],
            ['id' => 'a04', 'source' => '/t', 'target' => '/x', 'only_if_not_found' => true, 'priority' => 97],
            ['id' => 'a05', 'source' => '/t', 'target' => '/x', 'query_mode' => 'params', 'query_params' => ['need' => null], 'priority' => 96],
            ['id' => 'a06', 'source' => '/t', 'target' => '/x', 'conditions' => ['hosts' => ['other.com']], 'priority' => 95],
            ['id' => 'a07', 'source' => '/t', 'target' => '/x', 'conditions' => ['languages' => ['fr']], 'priority' => 94],
            ['id' => 'a08', 'source' => '/t', 'target' => '/x', 'conditions' => ['schemes' => ['http']], 'priority' => 93],
            ['id' => 'a09', 'source' => '/t', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'cookie', 'name' => 'c', 'operator' => 'exists']]], 'priority' => 92],
            ['id' => 'a10', 'source' => '/t', 'target' => '//evil.com', 'priority' => 91],
            ['id' => 'a11', 'source' => '/t*x', 'target' => '/x', 'match_type' => 'wildcard', 'priority' => 90],
            ['id' => 'a12', 'source' => '/t', 'target' => '/done', 'priority' => 89],
            ['id' => 'a13', 'source' => '/t', 'target' => '/never', 'priority' => 88],
        ];

        $trace = $this->trace($rows, ['path' => '/t', 'host' => 'example.com', 'lang' => 'de']);

        self::assertSame(
            ['a01:disabled', 'a02:expired', 'a03:scheduled', 'a04:phase', 'a05:query', 'a06:host', 'a07:language', 'a08:scheme', 'a09:condition', 'a10:unsafe_target', 'a11:no_match', 'a12:matched'],
            array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $trace),
        );
        self::assertSame([false, false, false, false, false, false, false, false, false, false, false, true], array_map(static fn (TraceStep $s): bool => $s->matched, $trace));
    }

    public function testTraceStepFields(): void
    {
        $trace = $this->trace([['id' => 'r1', 'source' => '/a/*', 'target' => '/b/$1', 'match_type' => 'wildcard', 'priority' => 7]], '/a/x');

        self::assertCount(1, $trace);
        self::assertSame('r1', $trace[0]->ruleId);
        self::assertSame('/a/*', $trace[0]->source);
        self::assertSame(MatchType::Wildcard, $trace[0]->matchType);
        self::assertSame(7, $trace[0]->priority);
        self::assertSame('/a/x', $trace[0]->path);
        self::assertTrue($trace[0]->matched);
        self::assertSame('matched', $trace[0]->reason);
    }

    public function testTraceIsEmptyByDefault(): void
    {
        $ctx = MatcherHarness::context('/old');
        self::assertNotNull($ctx);
        $matcher = MatcherHarness::matcher(MatcherHarness::compile([['id' => 'r1', 'source' => '/old', 'target' => '/new']]));

        $result = $matcher->match($ctx);

        self::assertNotNull($result);
        self::assertSame([], $result->trace);
        self::assertSame([], $matcher->match($ctx, MatchPhase::Early, false)?->trace);
    }

    public function testTraceShowsDisabledRuleOnlyWhenItsPathMatches(): void
    {
        $rows = [
            ['id' => 'off-hit', 'source' => '/a', 'target' => '/x', 'enabled' => false, 'priority' => 5],
            ['id' => 'off-miss', 'source' => '/other', 'target' => '/x', 'enabled' => false, 'priority' => 4],
            ['id' => 'off-wild', 'source' => '/a*', 'target' => '/x', 'match_type' => 'wildcard', 'enabled' => false, 'priority' => 3],
            ['id' => 'off-regex-miss', 'source' => '^/zzz$', 'target' => '/x', 'match_type' => 'regex', 'enabled' => false, 'priority' => 2],
            ['id' => 'live', 'source' => '/a', 'target' => '/y'],
        ];

        self::assertSame(['off-hit:disabled', 'off-wild:disabled', 'live:matched'], $this->reasons($rows, '/a'));
    }

    public function testTraceCoversEveryStepOfAChain(): void
    {
        $rows = [
            ['id' => 'r1', 'source' => '/a', 'target' => '/b', 'continue' => true, 'priority' => 2],
            ['id' => 'r2', 'source' => '/b', 'target' => '/c', 'priority' => 1],
        ];

        $trace = $this->trace($rows, '/a');

        self::assertSame(['r1', 'r2'], array_map(static fn (TraceStep $s): string => $s->ruleId, $trace));
        self::assertSame(['/a', '/b'], array_map(static fn (TraceStep $s): string => $s->path, $trace));
    }

    public function testTraceOfAnUnsafeTargetContinuesWithLowerRules(): void
    {
        $rows = [
            ['id' => 'bad', 'source' => '/go*', 'target' => '/$1', 'match_type' => 'wildcard', 'priority' => 2],
            ['id' => 'fallback', 'source' => '/*', 'target' => '/safe', 'match_type' => 'wildcard'],
        ];

        self::assertSame(['bad:unsafe_target', 'fallback:matched'], $this->reasons($rows, '/go/evil.com'));
    }

    public function testResultCarriesCapturesAndAllAppliedRules(): void
    {
        $ctx = MatcherHarness::context('/2024/hello');
        self::assertNotNull($ctx);
        $result = MatcherHarness::matcher(MatcherHarness::compile([
            ['id' => 'r1', 'source' => '^/(?<year>\d{4})/(?<slug>\w+)$', 'target' => '/{year}-{slug}', 'match_type' => 'regex'],
        ]))->match($ctx);

        self::assertNotNull($result);
        self::assertEquals([1 => '2024', 'year' => '2024', 2 => 'hello', 'slug' => 'hello'], $result->captures);
        self::assertSame('/2024-hello', $result->location);
        self::assertFalse($result->isExternal());
        self::assertCount(1, $result->rules);
    }

    public function testExternalResult(): void
    {
        $ctx = MatcherHarness::context('/old');
        self::assertNotNull($ctx);
        $result = MatcherHarness::matcher(
            MatcherHarness::compile([['id' => 'r1', 'source' => '/old', 'target' => 'https://partner.example.org/', 'target_type' => 'url']]),
            ['allowed_hosts' => ['partner.example.org']],
        )->match($ctx);

        self::assertNotNull($result);
        self::assertTrue($result->isExternal());
    }

    public function testMatchResultToArrayIncludesTheTrace(): void
    {
        $ctx = MatcherHarness::context('/old');
        self::assertNotNull($ctx);
        $result = MatcherHarness::matcher(MatcherHarness::compile([['id' => 'r1', 'source' => '/old', 'target' => '/new']]))->match($ctx, MatchPhase::Any, true);

        self::assertNotNull($result);
        $array = $result->toArray();
        self::assertSame('r1', $array['rule_id']);
        self::assertSame(301, $array['status']);
        self::assertSame('/new', $array['location']);
        self::assertSame(['r1'], $array['rules']);
        self::assertIsArray($array['trace']);
        self::assertCount(1, $array['trace']);
    }

    public function testMatcherCanBeReusedForManyRequests(): void
    {
        $matcher = MatcherHarness::matcher(MatcherHarness::compile([
            ['id' => 'r1', 'source' => '/a', 'target' => '/1'],
            ['id' => 'r2', 'source' => '/b', 'target' => '/2', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => '^a+$']]]],
        ]));

        $outcomes = [];
        foreach ([['/a', 'aaa'], ['/b', 'aaa'], ['/b', 'bbb'], ['/a', 'bbb'], ['/b', 'a']] as [$path, $header]) {
            $ctx = MatcherHarness::context(['path' => $path, 'headers' => ['x' => $header]]);
            self::assertNotNull($ctx);
            $outcomes[] = $matcher->match($ctx)?->location;
        }

        self::assertSame(['/1', '/2', null, '/1', '/2'], $outcomes);
    }

    public function testPathWithoutLeadingSlashIsAccepted(): void
    {
        $matcher = MatcherHarness::matcher(MatcherHarness::compile([
            ['id' => 'r1', 'source' => '/old', 'target' => '/new'],
            ['id' => 'r2', 'source' => '/', 'target' => '/home'],
        ]));

        self::assertSame('/new', $matcher->match(new RequestContext('old'))?->location);
        self::assertSame('/home', $matcher->match(new RequestContext(''))?->location);
    }

    public function testChainDepthBelowOneStillAppliesOneRule(): void
    {
        $set = MatcherHarness::compile([['id' => 'r1', 'source' => '/a', 'target' => '/b']]);
        $matcher = new Matcher($set, new FixedClock(new DateTimeImmutable(MatcherHarness::NOW)), new MatcherOptions(maxChainDepth: 0));

        self::assertSame('/b', $matcher->match(new RequestContext('/a'))?->location);
    }

    public function testOptionsDefaults(): void
    {
        $options = new MatcherOptions();

        self::assertSame(10, $options->maxChainDepth);
        self::assertSame(100000, $options->backtrackLimit);
        self::assertSame(10000, $options->recursionLimit);
        self::assertNull($options->defaultLanguage);
        self::assertSame(['utm_*', 'fbclid', 'gclid', 'msclkid'], $options->globalQueryIgnore);
        self::assertInstanceOf(TargetGuard::class, $options->guard);
    }

    public function testPcreLimitsAndErrorHandlerAreRestoredAfterMatching(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        $handler = static fn (): bool => false;
        set_error_handler($handler);
        try {
            $ctx = MatcherHarness::context('/' . str_repeat('a', 1500) . '!');
            self::assertNotNull($ctx);
            MatcherHarness::matcher(MatcherHarness::compile([['id' => 'r1', 'source' => '^/(a+)+$', 'target' => '/x', 'match_type' => 'regex']]))->match($ctx);

            self::assertSame($backtrack, ini_get('pcre.backtrack_limit'));
            self::assertSame($recursion, ini_get('pcre.recursion_limit'));
            $active = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            self::assertSame($handler, $active, 'error handler must be restored');
        } finally {
            restore_error_handler();
        }
    }

    public function testLowBacktrackLimitTurnsARegexIntoRegexError(): void
    {
        $set = MatcherHarness::compile([
            ['id' => 'r1', 'source' => '^/(a+)+$', 'target' => '/x', 'match_type' => 'regex', 'priority' => 2],
            ['id' => 'r2', 'source' => '/*', 'target' => '/fallback', 'match_type' => 'wildcard'],
        ]);
        $matcher = new Matcher($set, new FixedClock(new DateTimeImmutable(MatcherHarness::NOW)), new MatcherOptions(backtrackLimit: 50));

        $result = $matcher->match(new RequestContext('/' . str_repeat('a', 60) . '!'), MatchPhase::Any, true);

        self::assertNotNull($result);
        self::assertSame(['r1:regex_error', 'r2:matched'], array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $result->trace));
    }

    public function testBrokenPatternInsideAnImportedSetNeverThrowsOrWarns(): void
    {
        $data = MatcherHarness::compile([
            ['id' => 'r1', 'source' => '^/a$', 'target' => '/x', 'match_type' => 'regex', 'priority' => 2],
            ['id' => 'r2', 'source' => '/*', 'target' => '/ok', 'match_type' => 'wildcard'],
        ])->toArray();
        $data['meta'][0]['re'] = '#(#u'; // simulates a corrupted cache file
        $matcher = MatcherHarness::matcher(CompiledRuleSet::fromArray($data));
        $ctx = MatcherHarness::context('/a');
        self::assertNotNull($ctx);

        $result = $matcher->match($ctx, MatchPhase::Early, true);

        self::assertNotNull($result);
        self::assertSame('/ok', $result->location);
        self::assertSame(['r1:regex_error', 'r2:matched'], array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $result->trace));
    }

    public function testBrokenConditionPatternInsideAnImportedSetFailsClosed(): void
    {
        $data = MatcherHarness::compile([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => 'ok']]]],
        ])->toArray();
        $data['rules'][0]['conditions']['rules'][0]['value'] = '(';
        $matcher = MatcherHarness::matcher(CompiledRuleSet::fromArray($data));
        $ctx = MatcherHarness::context(['path' => '/a', 'headers' => ['x' => 'anything']]);
        self::assertNotNull($ctx);

        self::assertNull($matcher->match($ctx));
    }

    public function testRegexErrorIsReportedInTheTrace(): void
    {
        $data = MatcherHarness::compile([
            ['id' => 'r1', 'source' => '/a', 'target' => '/x', 'conditions' => ['rules' => [['kind' => 'header', 'name' => 'X', 'operator' => 'regex', 'value' => 'ok']]], 'priority' => 2],
            ['id' => 'r2', 'source' => '/*', 'target' => '/fallback', 'match_type' => 'wildcard'],
        ])->toArray();
        $data['rules'][0]['conditions']['rules'][0]['value'] = '(';
        $ctx = MatcherHarness::context(['path' => '/a', 'headers' => ['x' => 'anything']]);
        self::assertNotNull($ctx);

        $result = MatcherHarness::matcher(CompiledRuleSet::fromArray($data))->match($ctx, MatchPhase::Early, true);

        self::assertNotNull($result);
        self::assertSame(['r1:regex_error', 'r2:matched'], array_map(static fn (TraceStep $s): string => $s->ruleId . ':' . $s->reason, $result->trace));
    }
}
