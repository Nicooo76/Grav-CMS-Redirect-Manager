<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Suggest;

use Grav\Plugin\RedirectManager\Suggest\PageIndex;
use Grav\Plugin\RedirectManager\Suggest\PageInfo;
use Grav\Plugin\RedirectManager\Suggest\Suggester;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Suggester::class)]
#[CoversClass(PageIndex::class)]
#[Group('performance')]
final class SuggesterPerformanceTest extends TestCase
{
    private const PAGES = 5000;
    private const PATHS = 200;
    private const BUDGET_MS = 20.0;

    public function testSuggestOnFiveThousandPagesStaysUnderTwentyMilliseconds(): void
    {
        mt_srand(20260929);
        $words = self::vocabulary(300);
        $sections = array_slice($words, 0, 40);

        $pages = [];
        $routes = [];
        for ($i = 0; $i < self::PAGES; ++$i) {
            $section = $sections[$i % 40];
            $first = $words[mt_rand(0, 299)];
            $second = $words[mt_rand(0, 299)];
            $route = sprintf('/%s/%s-%s-%d', $section, $first, $second, $i);
            $routes[] = $route;
            $pages[] = new PageInfo(
                route: $route,
                slug: sprintf('%s-%s-%d', $first, $second, $i),
                title: ucfirst($first) . ' ' . $second . ' ' . $i,
                language: $i % 3 === 0 ? 'de' : 'en',
                taxonomy: ['tag' => [$words[mt_rand(0, 299)], $words[mt_rand(0, 299)]]],
                modified: 1700000000 + $i,
            );
        }
        $index = new PageIndex($pages);
        self::assertSame(self::PAGES, $index->count());
        $suggester = new Suggester($index);

        $paths = [];
        $expected = [];
        for ($i = 0; $i < self::PATHS; ++$i) {
            $route = $routes[mt_rand(0, self::PAGES - 1)];
            $expected[$i] = $route;
            $paths[] = match ($i % 5) {
                0 => self::typo($route),
                1 => '/old/' . basename($route),
                2 => strtoupper($route) . '.html',
                3 => '/' . $words[mt_rand(0, 299)] . '/' . $words[mt_rand(0, 299)] . '-' . $words[mt_rand(0, 299)],
                default => $route . '-old',
            };
        }

        // Warm up (opcache, first allocations).
        $suggester->suggest($paths[0]);

        $timings = [];
        $found = ['typo' => 0, 'moved' => 0, 'case' => 0, 'suffix' => 0];
        $kinds = [0 => 'typo', 1 => 'moved', 2 => 'case', 4 => 'suffix'];
        foreach ($paths as $i => $path) {
            $start = hrtime(true);
            $suggestions = $suggester->suggest($path);
            $timings[] = (hrtime(true) - $start) / 1e6;
            if (isset($kinds[$i % 5]) && in_array($expected[$i], array_map(static fn ($s): string => $s->target, $suggestions), true)) {
                ++$found[$kinds[$i % 5]];
            }
        }
        // The candidate prefilter must not cost recall: the original page is among the top five.
        foreach ($found as $kind => $hits) {
            self::assertGreaterThanOrEqual(36, $hits, $kind . ' recall');
        }
        sort($timings);
        $median = $timings[intdiv(count($timings), 2)];

        self::assertLessThan(
            self::BUDGET_MS,
            $median,
            sprintf('median %.2f ms, p95 %.2f ms, max %.2f ms', $median, $timings[(int) (count($timings) * 0.95)], end($timings)),
        );
    }

    /** @return list<string> */
    private static function vocabulary(int $size): array
    {
        $syllables = ['ba', 'ko', 'ri', 'tu', 'me', 'sa', 'lo', 'ni', 'pe', 'du', 'fa', 'gi', 'ho', 'ju', 'ze'];
        $words = [];
        while (count($words) < $size) {
            $word = '';
            for ($i = mt_rand(2, 4); $i > 0; --$i) {
                $word .= $syllables[mt_rand(0, count($syllables) - 1)];
            }
            $words[$word] = $word;
        }

        return array_values($words);
    }

    private static function typo(string $route): string
    {
        $pos = mt_rand(2, strlen($route) - 3);

        return substr($route, 0, $pos) . $route[$pos + 1] . $route[$pos] . substr($route, $pos + 2);
    }
}
