<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Matching;

use DateTimeImmutable;
use Grav\Plugin\RedirectManager\Domain\MatchPhase;
use Grav\Plugin\RedirectManager\Domain\RequestContext;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Matching\CompiledRuleSet;
use Grav\Plugin\RedirectManager\Matching\Matcher;
use Grav\Plugin\RedirectManager\Matching\MatcherOptions;
use Grav\Plugin\RedirectManager\Matching\RuleCompiler;
use Grav\Plugin\RedirectManager\Security\TargetGuard;
use Grav\Plugin\RedirectManager\Util\FixedClock;
use Grav\Plugin\RedirectManager\Util\InvalidPathException;
use Grav\Plugin\RedirectManager\Util\PathNormalizer;

/**
 * Builds contexts, options and matchers from the compact arrays used in fixtures/matcher-cases.php.
 */
final class MatcherHarness
{
    public const NOW = '2026-09-29T12:00:00+00:00';

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function compile(array $rows): CompiledRuleSet
    {
        return (new RuleCompiler())->compile(array_map(Rule::fromArray(...), $rows));
    }

    /**
     * Runs the set through var_export, like the OPcache file would.
     */
    public static function exported(CompiledRuleSet $set): CompiledRuleSet
    {
        /** @var array<string, mixed> $data */
        $data = eval('return ' . var_export($set->toArray(), true) . ';');

        return CompiledRuleSet::fromArray($data);
    }

    /**
     * Mirrors the request factory: the raw path is normalized once. Null when the normalizer rejects it.
     *
     * @param array<string, mixed>|string $request
     */
    public static function context(array|string $request): ?RequestContext
    {
        $request = is_string($request) ? ['path' => $request] : $request;
        try {
            $path = PathNormalizer::normalize(self::str($request['path'] ?? '/'));
        } catch (InvalidPathException) {
            return null;
        }
        /** @var array<string, mixed> $query */
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        /** @var array<string, string> $headers */
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        /** @var array<string, string> $cookies */
        $cookies = is_array($request['cookies'] ?? null) ? $request['cookies'] : [];

        return new RequestContext(
            $path,
            $query,
            self::str($request['host'] ?? ''),
            self::str($request['scheme'] ?? 'https'),
            isset($request['lang']) ? self::str($request['lang']) : null,
            $headers,
            $cookies,
        );
    }

    /**
     * @param array<string, mixed> $opts
     */
    public static function matcher(CompiledRuleSet $set, array $opts = []): Matcher
    {
        /** @var list<string> $hosts */
        $hosts = is_array($opts['allowed_hosts'] ?? null) ? $opts['allowed_hosts'] : [];
        /** @var list<string> $ignore */
        $ignore = is_array($opts['global_ignore'] ?? null) ? $opts['global_ignore'] : ['utm_*', 'fbclid', 'gclid', 'msclkid'];
        $options = new MatcherOptions(
            maxChainDepth: is_int($opts['max_chain'] ?? null) ? $opts['max_chain'] : 10,
            guard: new TargetGuard($hosts, (bool) ($opts['allow_any'] ?? false)),
            defaultLanguage: isset($opts['default_lang']) ? self::str($opts['default_lang']) : null,
            globalQueryIgnore: $ignore,
        );

        return new Matcher($set, new FixedClock(new DateTimeImmutable(self::str($opts['now'] ?? self::NOW))), $options);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public static function phase(array $opts): MatchPhase
    {
        return MatchPhase::from(self::str($opts['phase'] ?? 'early'));
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
