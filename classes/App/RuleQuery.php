<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\Domain\MatchType;

/**
 * Filter, sort and paging of the rule list (GET /redirects/rules, `list` in the CLI).
 */
final readonly class RuleQuery
{
    public const SORTS = ['priority', 'source', 'target', 'status', 'hits', 'last_hit', 'created_at', 'updated_at'];
    public const STATES = ['active', 'disabled', 'expired', 'scheduled'];
    public const BADGES = ['active', 'disabled', 'expired', 'scheduled', 'chain', 'loop', 'conflict', 'dead_target', 'unused'];
    public const MAX_PER_PAGE = 500;

    /** Sorts that start with the largest value. */
    private const DESC_DEFAULT = ['priority', 'hits', 'last_hit', 'created_at', 'updated_at'];

    public function __construct(
        public string $search = '',
        public ?string $matchType = null,
        public ?int $status = null,
        public ?string $state = null,
        public ?string $badge = null,
        public ?string $group = null,
        public ?string $origin = null,
        public ?string $tag = null,
        public ?int $unusedDays = null,
        public string $sort = 'priority',
        public string $direction = 'desc',
        public int $page = 1,
        public int $perPage = 50,
    ) {
    }

    /**
     * @param array<string, mixed> $q query parameters as sent by the client
     *
     * @throws InvalidInputException
     */
    public static function fromArray(array $q, int $defaultPerPage = 50): self
    {
        $str = static fn (string $k): ?string => isset($q[$k]) && is_scalar($q[$k]) && trim((string) $q[$k]) !== '' ? trim((string) $q[$k]) : null;

        $matchType = $str('match_type');
        if ($matchType !== null && MatchType::tryFrom($matchType) === null) {
            throw new InvalidInputException('"match_type" must be exact, wildcard or regex.', field: 'match_type');
        }
        $state = $str('state');
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw new InvalidInputException('"state" must be one of ' . implode(', ', self::STATES) . '.', field: 'state');
        }
        $badge = $str('badge');
        if ($badge !== null && !in_array($badge, self::BADGES, true)) {
            throw new InvalidInputException('"badge" must be one of ' . implode(', ', self::BADGES) . '.', field: 'badge');
        }
        $sort = $str('sort') ?? 'priority';
        if (!in_array($sort, self::SORTS, true)) {
            throw new InvalidInputException('"sort" must be one of ' . implode(', ', self::SORTS) . '.', field: 'sort');
        }
        $dir = strtolower($str('dir') ?? (in_array($sort, self::DESC_DEFAULT, true) ? 'desc' : 'asc'));
        if ($dir !== 'asc' && $dir !== 'desc') {
            throw new InvalidInputException('"dir" must be asc or desc.', field: 'dir');
        }

        return new self(
            search: $str('q') ?? '',
            matchType: $matchType,
            status: self::int($str('status'), 'status'),
            state: $state,
            badge: $badge,
            group: isset($q['group']) && is_scalar($q['group']) && (string) $q['group'] !== '' ? trim((string) $q['group']) : null,
            origin: $str('origin'),
            tag: $str('tag'),
            unusedDays: self::int($str('unused_days'), 'unused_days'),
            sort: $sort,
            direction: $dir,
            page: max(1, self::int($str('page'), 'page') ?? 1),
            perPage: min(self::MAX_PER_PAGE, max(1, self::int($str('per_page'), 'per_page') ?? $defaultPerPage)),
        );
    }

    /** Same filters, all rows on one page (CLI, export). */
    public function withoutPaging(): self
    {
        return new self(
            $this->search,
            $this->matchType,
            $this->status,
            $this->state,
            $this->badge,
            $this->group,
            $this->origin,
            $this->tag,
            $this->unusedDays,
            $this->sort,
            $this->direction,
            1,
            PHP_INT_MAX,
        );
    }

    private static function int(?string $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1) {
            throw new InvalidInputException(sprintf('"%s" must be an integer.', $field), field: $field);
        }

        return (int) $value;
    }
}
