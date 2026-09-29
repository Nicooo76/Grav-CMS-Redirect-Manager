<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Analysis;

use Grav\Plugin\RedirectManager\Analysis\ChainShortener;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainShortener::class)]
final class ChainShortenerTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private static function r(string $id, string $source, string $target = '', array $extra = []): Rule
    {
        return AnalysisFixtures::rule($id, $source, $target, $extra);
    }

    /**
     * @param list<Rule> $rules
     */
    private function shorten(array $rules, string $id): ?Rule
    {
        $report = AnalysisFixtures::analyzer()->analyze($rules);
        foreach ($rules as $rule) {
            if ($rule->id === $id) {
                return (new ChainShortener())->shorten($rule, $report);
            }
        }
        self::fail('unknown rule');
    }

    public function testShortensToTheEndOfTheChainAndKeepsStatus(): void
    {
        $short = $this->shorten([self::r('a', '/a', '/b', ['status' => 302, 'note' => 'keep']), self::r('b', '/b', '/c'), self::r('c', '/c', '/d')], 'a');

        self::assertNotNull($short);
        self::assertSame('/d', $short->target);
        self::assertSame(StatusCode::Found, $short->status);
        self::assertSame('a', $short->id);
        self::assertSame('/a', $short->source);
        self::assertSame('keep', $short->note);
    }

    public function testShortenedRuleNoLongerChains(): void
    {
        $rules = [self::r('a', '/a', '/b'), self::r('b', '/b', '/c')];
        $short = $this->shorten($rules, 'a');
        $after = AnalysisFixtures::analyzer()->analyze([$short ?? $rules[0], $rules[1]]);

        self::assertSame([], $after->issuesFor('a'));
    }

    public function testWildcardShortcutKeepsPlaceholders(): void
    {
        $short = $this->shorten([
            self::r('w1', '/old/*', '/mid/$1', ['match_type' => 'wildcard']),
            self::r('w2', '/mid/*', '/new/$1', ['match_type' => 'wildcard']),
        ], 'w1');

        self::assertSame('/new/$1', $short?->target);
    }

    public function testChainEndingExternalSwitchesTargetType(): void
    {
        $short = $this->shorten([
            self::r('a', '/a', '/b'),
            self::r('b', '/b', 'https://www.example.org/x', ['target_type' => 'url']),
        ], 'a');

        self::assertSame('https://www.example.org/x', $short?->target);
        self::assertSame(TargetType::Url, $short->targetType);
    }

    public function testChainEndingInGoneTurnsRuleGone(): void
    {
        $short = $this->shorten([self::r('a', '/a', '/b'), self::r('b', '/b', '', ['status' => 410])], 'a');

        self::assertSame(StatusCode::Gone, $short?->status);
        self::assertSame('', $short->target);
    }

    public function testNotAChain(): void
    {
        self::assertNull($this->shorten([self::r('a', '/a', '/b'), self::r('b', '/b', '/c')], 'b'));
        self::assertNull($this->shorten([self::r('a', '/a', '/b'), self::r('b', '/b', '/a')], 'a'));
    }

    public function testLanguageVariantsThatDisagreeAreNotShortened(): void
    {
        $rules = [
            self::r('x', '/x', '/y', ['conditions' => ['languages' => ['de', 'en']]]),
            self::r('yde', '/y', '/z-de', ['conditions' => ['languages' => ['de']]]),
            self::r('yen', '/y', '/z-en', ['conditions' => ['languages' => ['en']]]),
        ];

        self::assertNull($this->shorten($rules, 'x'));
    }

    public function testLanguageVariantsThatAgreeAreShortened(): void
    {
        $rules = [
            self::r('x', '/x', '/y', ['conditions' => ['languages' => ['de', 'en']]]),
            self::r('y', '/y', '/z'),
        ];

        self::assertSame('/z', $this->shorten($rules, 'x')?->target);
    }

    public function testChainWithoutTemplateIsNotShortened(): void
    {
        $rules = [
            self::r('rx', '^/(a|b)/(\d+)$', '/x/$2', ['match_type' => 'regex']),
            self::r('w', '/x/*', '/y/$1', ['match_type' => 'wildcard']),
        ];
        $report = AnalysisFixtures::analyzer()->analyze($rules);

        self::assertNotNull(AnalysisFixtures::find($report->issuesFor('rx'), 'chain'));
        self::assertNull((new ChainShortener())->shorten($rules[0], $report));
    }
}
