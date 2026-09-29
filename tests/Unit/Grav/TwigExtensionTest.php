<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Plugin\RedirectManager\Grav\TwigExtension;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(TwigExtension::class)]
#[Group('grav')]
final class TwigExtensionTest extends GravTestCase
{
    /** @var list<string> */
    private array $lookups = [];

    private function extension(): TwigExtension
    {
        $this->lookups = [];

        return new TwigExtension(function (string $url): ?array {
            $this->lookups[] = $url;

            return match ($url) {
                '/old' => ['status' => 301, 'location' => '/new', 'rule_id' => 'r1'],
                '/gone' => ['status' => 410, 'location' => '', 'rule_id' => 'r2'],
                default => null,
            };
        });
    }

    public function testRegistersTheFunctionAndTheFilter(): void
    {
        $extension = $this->extension();

        $functions = $extension->getFunctions();
        self::assertCount(1, $functions);
        self::assertSame('redirect_for', $functions[0]->getName());
        self::assertSame(['status' => 301, 'location' => '/new', 'rule_id' => 'r1'], ($functions[0]->getCallable())('/old'));

        $filters = $extension->getFilters();
        self::assertCount(1, $filters);
        self::assertSame('redirect_target', $filters[0]->getName());
        self::assertSame('/new', ($filters[0]->getCallable())('/old'));
    }

    /**
     * @return iterable<string, array{mixed, ?array{status: int, location: string, rule_id: string}}>
     */
    public static function redirectForCases(): iterable
    {
        yield 'match' => ['/old', ['status' => 301, 'location' => '/new', 'rule_id' => 'r1']];
        yield 'gone rule' => ['/gone', ['status' => 410, 'location' => '', 'rule_id' => 'r2']];
        yield 'no rule' => ['/other', null];
        yield 'empty string' => ['', null];
        yield 'null' => [null, null];
        yield 'not a string' => [42, null];
        yield 'array' => [['/old'], null];
    }

    /**
     * @param array{status: int, location: string, rule_id: string}|null $expected
     */
    #[DataProvider('redirectForCases')]
    public function testRedirectFor(mixed $url, ?array $expected): void
    {
        self::assertSame($expected, $this->extension()->redirectFor($url));
    }

    public function testRedirectForDoesNotAskTheLookupForValuesThatCannotBeUrls(): void
    {
        $extension = $this->extension();
        $extension->redirectFor('');
        $extension->redirectFor(null);
        $extension->redirectFor(7);

        self::assertSame([], $this->lookups);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function redirectTargetCases(): iterable
    {
        yield 'rewritten' => ['/old', '/new'];
        yield 'rule without location keeps the url' => ['/gone', '/gone'];
        yield 'no rule keeps the url' => ['/other', '/other'];
        yield 'empty string is returned as is' => ['', ''];
        yield 'null is returned as is' => [null, null];
        yield 'non-string is returned as is' => [42, 42];
    }

    #[DataProvider('redirectTargetCases')]
    public function testRedirectTarget(mixed $url, mixed $expected): void
    {
        self::assertSame($expected, $this->extension()->redirectTarget($url));
    }
}
