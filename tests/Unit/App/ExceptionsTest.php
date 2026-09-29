<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\App;

use Grav\Plugin\RedirectManager\Analysis\Severity;
use Grav\Plugin\RedirectManager\Analysis\ValidationIssue;
use Grav\Plugin\RedirectManager\App\Exception\InvalidInputException;
use Grav\Plugin\RedirectManager\App\Exception\PayloadTooLargeException;
use Grav\Plugin\RedirectManager\App\Exception\RateLimitedException;
use Grav\Plugin\RedirectManager\App\Exception\ResourceNotFoundException;
use Grav\Plugin\RedirectManager\App\Exception\RevisionConflictException;
use Grav\Plugin\RedirectManager\App\Exception\RuleValidationException;
use Grav\Plugin\RedirectManager\App\Exception\UnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InvalidInputException::class)]
#[CoversClass(PayloadTooLargeException::class)]
#[CoversClass(RateLimitedException::class)]
#[CoversClass(ResourceNotFoundException::class)]
#[CoversClass(RevisionConflictException::class)]
#[CoversClass(RuleValidationException::class)]
#[CoversClass(UnavailableException::class)]
#[Group('app')]
final class ExceptionsTest extends TestCase
{
    public function testInvalidInputBuildsAnErrorIssueFromItsArguments(): void
    {
        $e = new InvalidInputException('Unknown action.', null, 'action', 'unknown_action');

        self::assertSame('Unknown action.', $e->getMessage());
        self::assertSame('action', $e->field);
        self::assertSame('unknown_action', $e->errorCode);
        self::assertCount(1, $e->issues);
        self::assertSame(Severity::Error, $e->issues[0]->severity);
        self::assertSame('unknown_action', $e->issues[0]->code);
        self::assertSame('action', $e->issues[0]->field);
        self::assertSame('Unknown action.', $e->issues[0]->message);
    }

    public function testInvalidInputDefaults(): void
    {
        $e = new InvalidInputException('Bad.');

        self::assertSame('', $e->field);
        self::assertSame('invalid_value', $e->errorCode);
        self::assertSame('invalid_value', $e->issues[0]->code);
        self::assertSame('', $e->issues[0]->field);
    }

    public function testInvalidInputKeepsGivenIssues(): void
    {
        $issues = [ValidationIssue::warning('w', 'source', 'Careful.'), ValidationIssue::error('e', 'target', 'Broken.')];

        $e = new InvalidInputException('Two problems.', $issues, 'target');

        self::assertSame($issues, $e->issues);
        self::assertSame('invalid_value', $e->errorCode);
    }

    public function testInvalidInputWithAnEmptyIssueListStaysEmpty(): void
    {
        self::assertSame([], (new InvalidInputException('x', []))->issues);
    }

    public function testPayloadTooLarge(): void
    {
        $e = new PayloadTooLargeException(3_000_000, 2_097_152);

        self::assertSame(3_000_000, $e->size);
        self::assertSame(2_097_152, $e->max);
        self::assertSame('The import is 3000000 bytes; the limit is 2097152 bytes.', $e->getMessage());
    }

    public function testRateLimited(): void
    {
        $e = new RateLimitedException(42);

        self::assertSame(42, $e->retryAfter);
        self::assertSame('A check ran recently. Try again in 42 seconds.', $e->getMessage());
    }

    public function testResourceNotFoundCapitalizesTheKind(): void
    {
        $e = new ResourceNotFoundException('rule', 'r123');

        self::assertSame('rule', $e->kind);
        self::assertSame('r123', $e->id);
        self::assertSame('Rule "r123" was not found.', $e->getMessage());
        self::assertSame('Suggestion "s\'1" was not found.', (new ResourceNotFoundException('suggestion', "s'1"))->getMessage());
    }

    public function testRuleValidationSeparatesErrorsFromWarnings(): void
    {
        $warning = ValidationIssue::warning('chain', 'target', 'Long chain.');
        $error1 = ValidationIssue::error('source_empty', 'source', 'Empty.');
        $info = ValidationIssue::info('note', 'note', 'FYI.');
        $error2 = ValidationIssue::error('loop', 'target', 'Loop.');

        $e = new RuleValidationException([$warning, $error1, $info, $error2]);

        self::assertSame('The rule is invalid.', $e->getMessage());
        self::assertSame([$warning, $error1, $info, $error2], $e->issues);
        self::assertSame([$error1, $error2], $e->errors());
    }

    public function testRuleValidationErrorsAreReindexed(): void
    {
        $e = new RuleValidationException([ValidationIssue::warning('w', 'f', 'm'), ValidationIssue::error('e', 'f', 'm')], 'Row 3 is invalid.');

        self::assertSame('Row 3 is invalid.', $e->getMessage());
        self::assertSame([0], array_keys($e->errors()));
    }

    public function testRuleValidationWithoutErrors(): void
    {
        self::assertSame([], (new RuleValidationException([ValidationIssue::warning('w', 'f', 'm')]))->errors());
        self::assertSame([], (new RuleValidationException([]))->errors());
    }

    /**
     * @return iterable<string, array{class-string<RuntimeException>}>
     */
    public static function plainExceptionProvider(): iterable
    {
        yield 'conflict' => [RevisionConflictException::class];
        yield 'unavailable' => [UnavailableException::class];
    }

    /**
     * @param class-string<RuntimeException> $class
     */
    #[DataProvider('plainExceptionProvider')]
    public function testPlainExceptionsCarryTheirMessage(string $class): void
    {
        $e = new $class('Something.', 7);

        self::assertSame('Something.', $e->getMessage());
        self::assertSame(7, $e->getCode());
    }

    public function testAllAreRuntimeExceptions(): void
    {
        foreach (
            [
                new InvalidInputException('x'),
                new PayloadTooLargeException(1, 1),
                new RateLimitedException(1),
                new ResourceNotFoundException('rule', 'x'),
                new RevisionConflictException(),
                new RuleValidationException([]),
                new UnavailableException(),
            ] as $e
        ) {
            self::assertInstanceOf(RuntimeException::class, $e);
        }
    }
}
