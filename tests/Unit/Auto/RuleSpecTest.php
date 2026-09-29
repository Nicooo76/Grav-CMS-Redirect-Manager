<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Auto\PageSnapshot;
use Grav\Plugin\RedirectManager\Auto\RuleSpec;
use Grav\Plugin\RedirectManager\Domain\MatchType;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use Grav\Plugin\RedirectManager\Domain\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleSpec::class)]
#[CoversClass(DeletePolicy::class)]
#[Group('auto')]
final class RuleSpecTest extends TestCase
{
    /**
     * @param list<string> $languages
     * @param list<string> $allLanguages
     */
    private function spec(
        string $source = '/old',
        string $target = '/new',
        MatchType $match = MatchType::Exact,
        array $languages = [PageSnapshot::ANY],
        array $allLanguages = [PageSnapshot::ANY],
    ): RuleSpec {
        return new RuleSpec($source, $target, $match, StatusCode::MovedPermanently, TargetType::Route, $languages, $allLanguages, 'note');
    }

    public function testReplaceAutoDefaultsToTrue(): void
    {
        self::assertTrue($this->spec()->replaceAuto);
        self::assertFalse((new RuleSpec('/a', '/b', MatchType::Exact, StatusCode::Gone, TargetType::Route, ['*'], ['*'], 'n', false))->replaceAuto);
    }

    public function testIsWildcard(): void
    {
        self::assertTrue($this->spec(match: MatchType::Wildcard)->isWildcard());
        self::assertFalse($this->spec(match: MatchType::Exact)->isWildcard());
        self::assertFalse($this->spec(match: MatchType::Regex)->isWildcard());
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function conditionLanguagesProvider(): iterable
    {
        yield 'single-language site' => [['*'], ['*'], []];
        yield 'single-language spec on a multi-language page' => [['*'], ['de', 'en'], []];
        yield 'all languages' => [['de', 'en'], ['de', 'en'], []];
        yield 'all languages in another order' => [['en', 'de'], ['de', 'en'], []];
        yield 'subset keeps its order' => [['en'], ['de', 'en'], ['en']];
        yield 'subset of three' => [['fr', 'de'], ['de', 'en', 'fr'], ['fr', 'de']];
        yield 'page languages with the wildcard key' => [['de'], ['*', 'de'], []];
        yield 'nothing in common' => [['it'], ['de', 'en'], ['it']];
    }

    /**
     * @param list<string> $languages
     * @param list<string> $all
     * @param list<string> $expected
     */
    #[DataProvider('conditionLanguagesProvider')]
    public function testConditionLanguages(array $languages, array $all, array $expected): void
    {
        self::assertSame($expected, $this->spec(languages: $languages, allLanguages: $all)->conditionLanguages());
    }

    public function testReverseOfAnExactSpecSwapsSourceAndTarget(): void
    {
        $spec = $this->spec('/blog/old', '/blog/new');

        self::assertSame('/blog/new', $spec->reverseSource());
        self::assertSame('/blog/old', $spec->reverseTarget());
    }

    public function testReverseOfAWildcardSpecSwapsTheCaptureAndTheStar(): void
    {
        $spec = $this->spec('/old/*', '/new/$1', MatchType::Wildcard);

        self::assertSame('/new/*', $spec->reverseSource());
        self::assertSame('/old/$1', $spec->reverseTarget());
    }

    public function testReverseOfAWildcardWithoutCapture(): void
    {
        $spec = $this->spec('/old/*', '/gone', MatchType::Wildcard);

        self::assertSame('/gone', $spec->reverseSource());
        self::assertSame('/old/$1', $spec->reverseTarget());
    }

    /**
     * @return iterable<string, array{mixed, DeletePolicy}>
     */
    public static function policyProvider(): iterable
    {
        yield 'ask' => ['ask', DeletePolicy::Ask];
        yield 'gone' => ['gone', DeletePolicy::Gone];
        yield 'parent' => ['parent', DeletePolicy::Parent];
        yield 'never' => ['never', DeletePolicy::Never];
        yield 'case and padding' => ['  GONE ', DeletePolicy::Gone];
        yield 'unknown falls back to ask' => ['delete', DeletePolicy::Ask];
        yield 'empty' => ['', DeletePolicy::Ask];
        yield 'null' => [null, DeletePolicy::Ask];
        yield 'bool' => [false, DeletePolicy::Ask];
        yield 'int' => [1, DeletePolicy::Ask];
        yield 'array' => [['never'], DeletePolicy::Ask];
    }

    #[DataProvider('policyProvider')]
    public function testDeletePolicyFromConfig(mixed $value, DeletePolicy $expected): void
    {
        self::assertSame($expected, DeletePolicy::fromConfig($value));
    }
}
