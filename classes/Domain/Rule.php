<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Grav\Plugin\RedirectManager\Util\Ids;
use Grav\Plugin\RedirectManager\Util\Timestamps;

/**
 * One redirect rule. Immutable; use with() to derive a changed copy.
 *
 * Priority: higher numbers are evaluated first. Rules of equal priority are ordered
 * exact before wildcard before regex, then by creation date (older first), then by id.
 */
final readonly class Rule
{
    public const DATE_FORMAT = DateTimeInterface::ATOM;

    /**
     * @param array<string, string|null> $queryParams required parameters for QueryMode::Params (null value = any value)
     * @param list<string>               $queryIgnore parameter name patterns ignored for QueryMode::Exact (utm_* style)
     * @param list<string>               $tags
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $target = '',
        public MatchType $matchType = MatchType::Exact,
        public StatusCode $status = StatusCode::MovedPermanently,
        public bool $enabled = true,
        public int $priority = 0,
        public TargetType $targetType = TargetType::Route,
        public bool $caseSensitive = false,
        public bool $ignoreTrailingSlash = true,
        public QueryMode $queryMode = QueryMode::Ignore,
        public array $queryParams = [],
        public array $queryIgnore = [],
        public bool $continueMatching = false,
        public bool $onlyIfNotFound = false,
        public ?DateTimeImmutable $activeFrom = null,
        public ?DateTimeImmutable $expiresAt = null,
        public string $note = '',
        public string $group = '',
        public array $tags = [],
        public RuleSource $origin = RuleSource::Manual,
        public Conditions $conditions = new Conditions(),
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isScheduled(DateTimeImmutable $now): bool
    {
        return $this->activeFrom !== null && $this->activeFrom > $now;
    }

    /** Enabled, started and not expired. */
    public function isActive(DateTimeImmutable $now): bool
    {
        return $this->enabled && !$this->isExpired($now) && !$this->isScheduled($now);
    }

    public function isExternalTarget(): bool
    {
        return $this->targetType === TargetType::Url;
    }

    /**
     * Returns a copy with the given fields changed. Keys use the serialized (snake_case) names.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /**
     * Builds a rule from its serialized form. Unknown enum values fall back to defaults;
     * validation of semantics (regex syntax, safe targets) is the job of RuleValidator.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = self::int($data['status'] ?? 301);

        return new self(
            id: self::str($data['id'] ?? '') !== '' ? self::str($data['id']) : Ids::rule(),
            source: self::str($data['source'] ?? ''),
            target: self::str($data['target'] ?? ''),
            matchType: MatchType::tryFrom(self::str($data['match_type'] ?? 'exact')) ?? MatchType::Exact,
            status: StatusCode::tryFrom($status) ?? StatusCode::MovedPermanently,
            enabled: self::bool($data['enabled'] ?? true),
            priority: self::int($data['priority'] ?? 0),
            targetType: TargetType::tryFrom(self::str($data['target_type'] ?? 'route')) ?? TargetType::Route,
            caseSensitive: self::bool($data['case_sensitive'] ?? false),
            ignoreTrailingSlash: self::bool($data['ignore_trailing_slash'] ?? true),
            queryMode: QueryMode::tryFrom(self::str($data['query_mode'] ?? 'ignore')) ?? QueryMode::Ignore,
            queryParams: self::queryParams($data['query_params'] ?? []),
            queryIgnore: self::strList($data['query_ignore'] ?? []),
            continueMatching: self::bool($data['continue'] ?? false),
            onlyIfNotFound: self::bool($data['only_if_not_found'] ?? false),
            activeFrom: self::date($data['active_from'] ?? null),
            expiresAt: self::date($data['expires_at'] ?? null),
            note: self::str($data['note'] ?? ''),
            group: trim(self::str($data['group'] ?? '')),
            tags: self::strList($data['tags'] ?? []),
            origin: RuleSource::tryFrom(self::str($data['origin'] ?? 'manual')) ?? RuleSource::Manual,
            conditions: Conditions::fromArray(is_array($data['conditions'] ?? null) ? self::assoc($data['conditions']) : []),
            createdAt: self::date($data['created_at'] ?? null),
            updatedAt: self::date($data['updated_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'target' => $this->target,
            'match_type' => $this->matchType->value,
            'status' => $this->status->value,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
            'target_type' => $this->targetType->value,
            'case_sensitive' => $this->caseSensitive,
            'ignore_trailing_slash' => $this->ignoreTrailingSlash,
            'query_mode' => $this->queryMode->value,
            'query_params' => $this->queryParams,
            'query_ignore' => $this->queryIgnore,
            'continue' => $this->continueMatching,
            'only_if_not_found' => $this->onlyIfNotFound,
            'active_from' => $this->activeFrom?->format(self::DATE_FORMAT),
            'expires_at' => $this->expiresAt?->format(self::DATE_FORMAT),
            'note' => $this->note,
            'group' => $this->group,
            'tags' => $this->tags,
            'origin' => $this->origin->value,
            'conditions' => $this->conditions->toArray(),
            'created_at' => $this->createdAt?->format(self::DATE_FORMAT),
            'updated_at' => $this->updatedAt?->format(self::DATE_FORMAT),
        ];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * @return list<string>
     */
    private static function strList(mixed $value): array
    {
        if ($value === [] || $value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, string|null>
     */
    private static function queryParams(mixed $value): array
    {
        if ($value === [] || $value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            parse_str(ltrim($value, '?'), $parsed);
            $value = $parsed;
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $name => $v) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[$name] = ($v === null || $v === '' || !is_scalar($v)) ? null : (string) $v;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function assoc(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return Timestamps::parse(trim($value));
    }
}
