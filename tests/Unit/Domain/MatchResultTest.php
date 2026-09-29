<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Domain;

use Grav\Plugin\RedirectManager\Domain\MatchResult;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TraceStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchResult::class)]
#[CoversClass(TraceStep::class)]
#[Group('domain')]
final class MatchResultTest extends TestCase
{
    public function testDefaults(): void
    {
        $rule = new Rule('a', '/x', '/y');
        $result = new MatchResult($rule, StatusCode::Found, '/y', [$rule]);

        self::assertSame($rule, $result->rule);
        self::assertSame(StatusCode::Found, $result->status);
        self::assertSame('/y', $result->location);
        self::assertSame([$rule], $result->rules);
        self::assertSame([], $result->captures);
        self::assertSame([], $result->trace);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function locationProvider(): iterable
    {
        yield 'https' => ['https://example.com/a', true];
        yield 'http' => ['http://example.com', true];
        yield 'uppercase scheme' => ['HTTPS://EXAMPLE.COM', true];
        yield 'other scheme with slashes' => ['ftp://host/file', true];
        yield 'scheme with plus and dot' => ['git+ssh://host', true];
        yield 'internal path' => ['/blog/post', false];
        yield 'internal path with query' => ['/blog?a=1', false];
        yield 'protocol relative' => ['//example.com', false];
        yield 'empty for 410' => ['', false];
        yield 'mailto has no slashes' => ['mailto:a@b.c', false];
        yield 'scheme not at start' => ['/redirect?to=https://example.com', false];
        yield 'scheme starting with a digit' => ['1http://x', false];
    }

    #[DataProvider('locationProvider')]
    public function testIsExternal(string $location, bool $expected): void
    {
        $rule = new Rule('a', '/x');

        self::assertSame($expected, (new MatchResult($rule, StatusCode::MovedPermanently, $location, [$rule]))->isExternal());
    }

    public function testToArrayListsRuleIdsAndSerializesTheTrace(): void
    {
        $first = new Rule('first', '/a', '/b', continueMatching: true);
        $second = new Rule('second', '/b', '/c');
        $result = new MatchResult(
            $second,
            StatusCode::PermanentRedirect,
            '/c',
            [$first, $second],
            ['1' => 'x', 'name' => 'y'],
            [
                new TraceStep('first', '/a', MatchType::Exact, 5, true, 'matched', '/a'),
                new TraceStep('second', '/b*', MatchType::Wildcard, 0, false, 'no_match', '/b'),
            ],
        );

        self::assertSame(
            [
                'rule_id' => 'second',
                'status' => 308,
                'location' => '/c',
                'rules' => ['first', 'second'],
                'captures' => ['1' => 'x', 'name' => 'y'],
                'trace' => [
                    ['rule_id' => 'first', 'source' => '/a', 'match_type' => 'exact', 'priority' => 5, 'matched' => true, 'reason' => 'matched', 'path' => '/a'],
                    ['rule_id' => 'second', 'source' => '/b*', 'match_type' => 'wildcard', 'priority' => 0, 'matched' => false, 'reason' => 'no_match', 'path' => '/b'],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testToArrayOfGoneResult(): void
    {
        $rule = new Rule('g', '/gone', status: StatusCode::Gone);

        self::assertSame(
            ['rule_id' => 'g', 'status' => 410, 'location' => '', 'rules' => ['g'], 'captures' => [], 'trace' => []],
            (new MatchResult($rule, StatusCode::Gone, '', [$rule]))->toArray(),
        );
    }

    public function testTraceStepToArray(): void
    {
        $step = new TraceStep('r1', '^/(\d+)$', MatchType::Regex, -3, false, 'regex_error', '/12');

        self::assertSame(
            ['rule_id' => 'r1', 'source' => '^/(\d+)$', 'match_type' => 'regex', 'priority' => -3, 'matched' => false, 'reason' => 'regex_error', 'path' => '/12'],
            $step->toArray(),
        );
    }
}
