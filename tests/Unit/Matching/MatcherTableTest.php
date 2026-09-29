<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every case of fixtures/matcher-cases.php against a freshly compiled set AND against the same set
 * after a var_export round trip (what the OPcache file does), and requires identical results.
 */
#[CoversClass(Matcher::class)]
#[CoversClass(RuleCompiler::class)]
#[CoversClass(CompiledRuleSet::class)]
final class MatcherTableTest extends TestCase
{
    /**
     * @return array<string, array{rules: list<array<string, mixed>>, request: array<string, mixed>|string, expect: array<int, mixed>|null, opts: array<string, mixed>}>
     */
    private static function table(): array
    {
        /** @var array<string, array{rules: list<array<string, mixed>>, request: array<string, mixed>|string, expect: array<int, mixed>|null, opts: array<string, mixed>}> $cases */
        $cases = require __DIR__ . '/fixtures/matcher-cases.php';

        return $cases;
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>, 1: array<string, mixed>|string, 2: array<int, mixed>|null, 3: array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        foreach (self::table() as $name => $case) {
            yield $name => [$case['rules'], $case['request'], $case['expect'], $case['opts']];
        }
    }

    public function testTableHasAtLeast150Cases(): void
    {
        self::assertGreaterThanOrEqual(150, count(self::table()));
    }

    /**
     * @param list<array<string, mixed>>   $rules
     * @param array<string, mixed>|string  $request
     * @param array<int, mixed>|null       $expect
     * @param array<string, mixed>         $opts
     */
    #[DataProvider('cases')]
    public function testCase(array $rules, array|string $request, ?array $expect, array $opts): void
    {
        $set = MatcherHarness::compile($rules);

        $fresh = $this->execute($set, $request, $opts);
        $this->assertOutcome($expect, $fresh);

        $restored = $this->execute(MatcherHarness::exported($set), $request, $opts);
        self::assertEquals($fresh?->toArray(), $restored?->toArray(), 'Result changed after the export round trip.');
    }

    /**
     * @param array<string, mixed>|string $request
     * @param array<string, mixed>        $opts
     */
    private function execute(CompiledRuleSet $set, array|string $request, array $opts): ?MatchResult
    {
        $ctx = MatcherHarness::context($request);
        if ($ctx === null) {
            return null;
        }
        $started = microtime(true);
        $result = MatcherHarness::matcher($set, $opts)->match($ctx, MatcherHarness::phase($opts));
        self::assertLessThan(1.0, microtime(true) - $started, 'Matching took too long.');

        return $result;
    }

    /**
     * @param array<int, mixed>|null $expect
     */
    private function assertOutcome(?array $expect, ?MatchResult $result): void
    {
        if ($expect === null) {
            self::assertNull($result, $result === null ? '' : sprintf('Unexpected match: rule %s -> %s', $result->rule->id, $result->location));

            return;
        }
        self::assertNotNull($result, 'Expected a match.');
        self::assertSame($expect[0], $result->status->value, 'status');
        self::assertSame($expect[1], $result->location, 'location');
        self::assertSame($expect[2], $result->rule->id, 'deciding rule');
        if (isset($expect[3])) {
            self::assertSame($expect[3], array_map(static fn ($r): string => $r->id, $result->rules), 'applied rules');
        }
    }
}
