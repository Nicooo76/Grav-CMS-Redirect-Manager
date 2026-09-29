<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Domain\Rule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Speed guard: analysis of 10,000 rules and validation of one candidate against 10,000 rules.
 *
 * The budgets (2 s and 150 ms) are the product requirement; the code needs a fraction of that on a normal machine.
 * Numbers go to STDERR.
 */
#[CoversNothing]
#[Group('benchmark')]
final class AnalysisPerformanceTest extends TestCase
{
    private const EXACT = 9500;
    private const WILDCARD = 300;
    private const REGEX = 200;

    /**
     * @return list<Rule>
     */
    private static function rules(): array
    {
        $rules = [];
        for ($i = 0; $i < self::EXACT; ++$i) {
            $extra = [];
            if ($i % 40 === 0) {
                $extra['conditions'] = ['languages' => ['de', 'en']];
            }
            if ($i % 50 === 1) {
                $extra['conditions'] = ['rules' => [['kind' => 'header', 'name' => 'User-Agent', 'operator' => 'contains', 'value' => 'bot', 'negate' => true]]];
            }
            if ($i % 25 === 2) {
                $extra['case_sensitive'] = true;
            }
            if ($i % 60 === 3) {
                $extra['enabled'] = false;
            }
            $rules[] = AnalysisFixtures::rule(sprintf('e%05d', $i), sprintf('/section-%d/old-page-%d', $i % 60, $i), sprintf('/section-%d/new-page-%d', $i % 60, $i), $extra + [
                'priority' => $i % 7,
                'created_at' => sprintf('2026-01-%02dT10:00:00+00:00', 1 + $i % 28),
            ]);
        }
        // chains: every 20th "new page" moves on again
        for ($i = 0; $i < self::EXACT; $i += 20) {
            $rules[] = AnalysisFixtures::rule(sprintf('c%05d', $i), sprintf('/section-%d/new-page-%d', $i % 60, $i), sprintf('/final/%d', $i));
        }
        for ($i = 0; $i < self::WILDCARD; ++$i) {
            $rules[] = AnalysisFixtures::rule(sprintf('w%05d', $i), sprintf('/wild-%d/*', $i), sprintf('/wild-target-%d/$1', $i), ['match_type' => 'wildcard']);
        }
        for ($i = 0; $i < self::WILDCARD; $i += 10) {
            $rules[] = AnalysisFixtures::rule(sprintf('x%05d', $i), sprintf('/wild-target-%d/*', $i), sprintf('/wild-final-%d/$1', $i), ['match_type' => 'wildcard']);
        }
        for ($i = 0; $i < self::REGEX; ++$i) {
            $rules[] = AnalysisFixtures::rule(sprintf('r%05d', $i), sprintf('^/re-%d/(?<id>\d+)/([^/]+)$', $i), sprintf('/regex-target-%d/{id}/$2', $i), ['match_type' => 'regex']);
        }

        return $rules;
    }

    public function testAnalyzeTenThousandRulesUnderTwoSeconds(): void
    {
        $rules = self::rules();
        self::assertGreaterThanOrEqual(10000, count($rules));

        $start = hrtime(true);
        $report = AnalysisFixtures::analyzer()->analyze($rules);
        $ms = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf("\nanalyze %d rules: %.0f ms, %d chains\n", count($rules), $ms, count($report->chains())));
        self::assertGreaterThan(400, count($report->chains()));
        self::assertLessThan(2000, $ms);
    }

    public function testValidateOneCandidateAgainstTenThousandRulesUnder150Ms(): void
    {
        $rules = self::rules();
        $validator = AnalysisFixtures::validator();
        $scenarios = [
            'exact, new chain' => AnalysisFixtures::rule('cand1', '/brand-new', '/section-20/new-page-20'),
            'exact, incoming chain' => AnalysisFixtures::rule('cand2', '/section-7/new-page-7', '/somewhere'),
            'exact, conflict' => AnalysisFixtures::rule('cand5', '/section-0/old-page-0', '/somewhere'),
            'edit of existing rule' => AnalysisFixtures::rule('e00100', '/section-40/old-page-100', '/section-40/other'),
            'wildcard' => AnalysisFixtures::rule('cand3', '/fresh/*', '/wild-target-10/$1', ['match_type' => 'wildcard']),
            'regex' => AnalysisFixtures::rule('cand4', '^/fresh/(\d+)$', '/wild-3/$1', ['match_type' => 'regex']),
        ];

        foreach ($scenarios as $name => $candidate) {
            $start = hrtime(true);
            $result = $validator->validate($candidate, $rules);
            $ms = (hrtime(true) - $start) / 1e6;

            fwrite(STDERR, sprintf("\nvalidate (%s) vs %d rules: %.1f ms, %d issues\n", $name, count($rules), $ms, count($result->issues)));
            self::assertLessThan(150, $ms, $name);
        }

        $chain = $validator->validate($scenarios['exact, new chain'], $rules);
        self::assertNotNull(AnalysisFixtures::find($chain->issues, 'chain'));
        $incoming = $validator->validate($scenarios['exact, incoming chain'], $rules);
        self::assertTrue(AnalysisFixtures::find($incoming->issues, 'chain')?->params['incoming'] ?? false);
        $conflict = $validator->validate($scenarios['exact, conflict'], $rules);
        self::assertNotNull(AnalysisFixtures::find($conflict->issues, 'conflict'));
    }

    public function testPreviewAgainstTenThousandRulesUnder150Ms(): void
    {
        $rules = self::rules();
        $start = hrtime(true);
        $preview = AnalysisFixtures::validator()->preview(AnalysisFixtures::rule('cand', '/fresh/*', '/x/$1', ['match_type' => 'wildcard']), $rules, '/fresh/a/b');
        $ms = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf("\npreview vs %d rules: %.1f ms\n", count($rules), $ms));
        self::assertSame('/x/a/b', $preview['location'] ?? null);
        self::assertLessThan(150, $ms);
    }
}
