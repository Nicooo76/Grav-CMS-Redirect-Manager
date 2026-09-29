<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Analysis\ChainAnalyzer;
use Grav\Plugin\RedirectManager\Analysis\ConflictDetector;
use Grav\Plugin\RedirectManager\Analysis\RuleValidator;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Util\FixedClock;

/**
 * Builders shared by the analysis tests.
 */
final class AnalysisFixtures
{
    public const NOW = '2026-09-29T12:00:00+00:00';

    public static function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable(self::NOW));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function options(array $options = []): MatcherOptions
    {
        return new MatcherOptions(
            maxChainDepth: is_int($options['depth'] ?? null) ? $options['depth'] : 10,
            guard: new TargetGuard(['example.org', '*.example.org']),
            defaultLanguage: is_string($options['lang'] ?? null) ? $options['lang'] : null,
        );
    }

    /**
     * @param array<string, mixed> $extra serialized rule fields (snake_case)
     */
    public static function rule(string $id, string $source, string $target = '', array $extra = []): Rule
    {
        return Rule::fromArray(array_merge(['id' => $id, 'source' => $source, 'target' => $target], $extra));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function analyzer(array $options = []): ChainAnalyzer
    {
        return new ChainAnalyzer(self::options($options), self::clock());
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function validator(array $options = []): RuleValidator
    {
        $opts = self::options($options);

        return new RuleValidator($opts, $opts->guard, self::clock());
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function conflicts(array $options = []): ConflictDetector
    {
        return new ConflictDetector(self::options($options), self::clock());
    }

    /**
     * @param list<ValidationIssue> $issues
     * @return list<string>
     */
    public static function codes(array $issues): array
    {
        $codes = array_map(static fn (ValidationIssue $i): string => $i->code, $issues);
        sort($codes);

        return $codes;
    }

    /**
     * @param list<ValidationIssue> $issues
     */
    public static function find(array $issues, string $code): ?ValidationIssue
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }

        return null;
    }
}
