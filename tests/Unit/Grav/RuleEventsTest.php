<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Tests\Unit\Grav;

use Grav\Common\Grav;
use Grav\Plugin\RedirectManager\Domain\Rule;
use Grav\Plugin\RedirectManager\Grav\RuleEvents;
use Grav\Plugin\RedirectManager\Tests\Unit\Support\GravTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RocketTheme\Toolbox\Event\Event;

#[CoversClass(RuleEvents::class)]
#[Group('grav')]
final class RuleEventsTest extends GravTestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    private function events(): RuleEvents
    {
        $this->calls = [];

        return new RuleEvents(function (string $name, array $payload): array {
            $this->calls[] = [$name, $payload];

            return $payload;
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        foreach ([RuleEvents::ACTION_CREATE, RuleEvents::ACTION_UPDATE, RuleEvents::ACTION_DELETE, RuleEvents::ACTION_IMPORT, RuleEvents::ACTION_AUTO] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actions')]
    public function testSavedDispatchesTheRuleThePreviousRuleAndTheAction(string $action): void
    {
        $rule = new Rule('a', '/old', '/new');
        $previous = new Rule('a', '/old', '/older');

        $this->events()->saved($rule, $previous, $action);

        self::assertCount(1, $this->calls);
        [$name, $payload] = $this->calls[0];
        self::assertSame('onRedirectRuleSaved', $name);
        self::assertSame($rule, $payload['rule']);
        self::assertSame($previous, $payload['previous']);
        self::assertSame($action, $payload['action']);
    }

    public function testSavedWithoutPreviousRule(): void
    {
        $this->events()->saved(new Rule('a', '/x', '/y'), null, RuleEvents::ACTION_CREATE);

        self::assertNull($this->calls[0][1]['previous']);
    }

    public function testMatchedAddsCancelFalseAndReturnsThePayloadAfterTheListeners(): void
    {
        $events = new RuleEvents(function (string $name, array $payload): array {
            $this->calls[] = [$name, $payload];
            $payload['result'] = 'replaced';

            return $payload;
        });
        $this->calls = [];

        $out = $events->matched(['result' => 'original', 'context' => 'ctx']);

        self::assertSame('onRedirectMatched', $this->calls[0][0]);
        self::assertSame(['result' => 'original', 'context' => 'ctx', 'cancel' => false], $this->calls[0][1]);
        self::assertSame('replaced', $out['result']);
    }

    public function testMatchedKeepsACancelFlagThatTheCallerAlreadySet(): void
    {
        $this->events()->matched(['cancel' => true]);

        self::assertTrue($this->calls[0][1]['cancel']);
    }

    public function testNotFoundLoggedAndSuggestionCreated(): void
    {
        $events = $this->events();

        $events->notFoundLogged(['entry' => 'e']);
        $events->suggestionCreated(['id' => 's1', 'path' => '/p']);

        self::assertSame(['onNotFoundLogged', ['entry' => 'e']], $this->calls[0]);
        self::assertSame(['onSuggestionCreated', ['suggestion' => ['id' => 's1', 'path' => '/p']]], $this->calls[1]);
    }

    public function testFromGravFiresTheEventOnGravAndReturnsWhatListenersLeftInIt(): void
    {
        $grav = new Grav();
        $grav->listeners['onRedirectMatched'] = static function (?Event $event): ?Event {
            $event['result'] = 'from-listener';
            $event['cancel'] = true;

            return $event;
        };
        $events = RuleEvents::fromGrav($grav);

        $out = $events->matched(['result' => 'original']);

        self::assertSame('from-listener', $out['result']);
        self::assertTrue($out['cancel']);
        self::assertCount(1, $grav->fired);
        self::assertSame('onRedirectMatched', $grav->fired[0][0]);
    }

    public function testFromGravPassesRuleObjectsThroughUntouched(): void
    {
        $grav = new Grav();
        $rule = new Rule('a', '/x', '/y');

        RuleEvents::fromGrav($grav)->saved($rule, null, RuleEvents::ACTION_AUTO);

        self::assertSame('onRedirectRuleSaved', $grav->fired[0][0]);
        $event = $grav->fired[0][1];
        self::assertNotNull($event);
        self::assertSame($rule, $event['rule']);
        self::assertSame('auto', $event['action']);
    }
}
