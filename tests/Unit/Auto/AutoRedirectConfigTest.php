<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Auto;

use Grav\Plugin\RedirectManager\Auto\AutoRedirectConfig;
use Grav\Plugin\RedirectManager\Auto\ChildrenMode;
use Grav\Plugin\RedirectManager\Auto\DeletePolicy;
use Grav\Plugin\RedirectManager\Domain\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutoRedirectConfig::class)]
#[Group('auto')]
final class AutoRedirectConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = AutoRedirectConfig::fromArray([]);

        self::assertSame(StatusCode::MovedPermanently, $config->status);
        self::assertSame(ChildrenMode::Wildcard, $config->children);
        self::assertSame(DeletePolicy::Ask, $config->onDelete);
    }

    public function testReadsTheSection(): void
    {
        $config = AutoRedirectConfig::fromArray(['status' => '308', 'children' => 'EACH', 'on_delete' => 'parent']);

        self::assertSame(StatusCode::PermanentRedirect, $config->status);
        self::assertSame(ChildrenMode::Each, $config->children);
        self::assertSame(DeletePolicy::Parent, $config->onDelete);
    }

    public function testNonRedirectStatusAndUnknownValuesFallBack(): void
    {
        $config = AutoRedirectConfig::fromArray(['status' => 410, 'children' => 'nope', 'on_delete' => 'explode']);

        self::assertSame(StatusCode::MovedPermanently, $config->status);
        self::assertSame(ChildrenMode::Wildcard, $config->children);
        self::assertSame(DeletePolicy::Ask, $config->onDelete);
    }
}
