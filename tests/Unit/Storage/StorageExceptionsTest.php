<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Storage;

use Grav\Plugin\RedirectManager\Storage\ConcurrentModificationException;
use Grav\Plugin\RedirectManager\Storage\CorruptRulesFileException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConcurrentModificationException::class)]
#[CoversClass(CorruptRulesFileException::class)]
#[Group('storage')]
final class StorageExceptionsTest extends TestCase
{
    public function testConcurrentModificationCarriesBothRevisions(): void
    {
        $e = new ConcurrentModificationException('abc123', 'def456');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('abc123', $e->expected);
        self::assertSame('def456', $e->actual);
        self::assertSame('The rules were changed by someone else. Reload and try again.', $e->getMessage());
        self::assertStringNotContainsString('abc123', $e->getMessage(), 'revisions are data, not part of the user-facing message');
    }

    public function testCorruptRulesFileNamesTheFileAndTheReason(): void
    {
        $e = new CorruptRulesFileException('/data/rules.yaml', 'duplicate rule id "a".');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertSame('/data/rules.yaml', $e->rulesFile);
        self::assertSame('Rules file "/data/rules.yaml" is corrupt: duplicate rule id "a".', $e->getMessage());
        self::assertSame(0, $e->getCode());
        self::assertNull($e->getPrevious());
    }

    public function testCorruptRulesFileKeepsThePreviousException(): void
    {
        $cause = new \InvalidArgumentException('bad yaml');

        $e = new CorruptRulesFileException('rules.yaml', 'cannot parse', $cause);

        self::assertSame($cause, $e->getPrevious());
    }
}
