<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\NotFound;

use DateTimeImmutable;

/**
 * Second grouping pass for one path: referers, daily buckets, user agent classes, languages, hosts.
 * Only built for the paths of the requested page.
 *
 * At most MAX_REFERERS distinct referers are tracked per path; later new ones are ignored
 * (bounded memory even for a path hit from thousands of referers).
 *
 * @internal
 */
final class GroupDetail
{
    private const MAX_REFERERS = 1000;
    private const TOP_REFERERS = 3;

    /** @var array<string, int> */
    private array $referers = [];

    /** @var array<string, int> */
    private array $daily = [];

    /** @var array<string, int> */
    private array $classes = [];

    /** @var array<string, int> */
    private array $languages = [];

    /** @var array<string, int> */
    private array $hosts = [];

    private string $sampleQuery = '';

    private int $sampleTime = PHP_INT_MIN;

    /** Entries must arrive oldest first for the sample query to be "the latest". */
    public function add(NotFoundEntry $entry): void
    {
        $t = $entry->time->getTimestamp();
        $day = DayRange::key($t);
        $this->daily[$day] = ($this->daily[$day] ?? 0) + 1;

        $class = $entry->uaClass->value;
        $this->classes[$class] = ($this->classes[$class] ?? 0) + 1;

        if ($entry->referer !== '' && (isset($this->referers[$entry->referer]) || count($this->referers) < self::MAX_REFERERS)) {
            $this->referers[$entry->referer] = ($this->referers[$entry->referer] ?? 0) + 1;
        }
        if ($entry->language !== null) {
            $this->languages[$entry->language] = ($this->languages[$entry->language] ?? 0) + 1;
        }
        $this->hosts[$entry->host] = ($this->hosts[$entry->host] ?? 0) + 1;

        if ($entry->query !== '' && $t >= $this->sampleTime) {
            $this->sampleQuery = $entry->query;
            $this->sampleTime = $t;
        }
    }

    /**
     * @param array{0: int, 1: int, 2: int} $aggregate [hits, firstSeen, lastSeen]
     * @param array<string, int>            $zeroDays  zero-filled day buckets of the query range
     */
    public function toRow(string $path, array $aggregate, array $zeroDays): GroupRow
    {
        $daily = $zeroDays;
        foreach ($this->daily as $day => $count) {
            $daily[(string) $day] = $count;
        }
        ksort($daily);

        return new GroupRow(
            $path,
            $aggregate[0],
            new DateTimeImmutable('@' . $aggregate[1]),
            new DateTimeImmutable('@' . $aggregate[2]),
            array_slice(self::sortByCount($this->referers), 0, self::TOP_REFERERS, true),
            $daily,
            self::sortByCount($this->classes),
            self::sortByCount($this->languages),
            self::sortByCount($this->hosts),
            $this->sampleQuery,
        );
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int> highest count first, ties by key
     */
    private static function sortByCount(array $counts): array
    {
        $keys = array_map('strval', array_keys($counts));
        usort($keys, static fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

        $sorted = [];
        foreach ($keys as $key) {
            $sorted[$key] = $counts[$key];
        }

        return $sorted;
    }
}
