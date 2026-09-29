<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Grav\Common\Grav;
use Grav\Plugin\RedirectManager\Domain\Rule;
use RocketTheme\Toolbox\Event\Event;

/**
 * Fires the plugin's events for other plugins. The API controller, the CLI and the auto-redirect listener
 * call saved() after rules.yaml was written; see docs/API.md, "Events for other plugins".
 *
 *   onRedirectRuleSaved  rule (Rule), previous (?Rule), action (create|update|delete|import|auto)
 *
 * The dispatcher is a callable (name, payload) => payload so tests need no Grav.
 */
final class RuleEvents
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_IMPORT = 'import';
    public const ACTION_AUTO = 'auto';

    /** @var callable(string, array<string, mixed>): array<string, mixed> */
    private $dispatch;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $dispatch
     */
    public function __construct(callable $dispatch)
    {
        $this->dispatch = $dispatch;
    }

    public static function fromGrav(Grav $grav): self
    {
        return new self(static function (string $name, array $payload) use ($grav): array {
            $event = $grav->fireEvent($name, new Event($payload));

            return $event->toArray();
        });
    }

    /**
     * @param string $action one of the ACTION_* constants
     */
    public function saved(Rule $rule, ?Rule $previous, string $action): void
    {
        ($this->dispatch)('onRedirectRuleSaved', ['rule' => $rule, 'previous' => $previous, 'action' => $action]);
    }

    /**
     * onRedirectMatched: listeners may replace "result" or set "cancel" to true.
     *
     * @param array<string, mixed> $payload result, context, request
     *
     * @return array<string, mixed> payload after the listeners ran
     */
    public function matched(array $payload): array
    {
        return ($this->dispatch)('onRedirectMatched', $payload + ['cancel' => false]);
    }

    /**
     * @param array<string, mixed> $payload entry
     */
    public function notFoundLogged(array $payload): void
    {
        ($this->dispatch)('onNotFoundLogged', $payload);
    }

    /**
     * onSuggestionCreated: a stored suggestion was created or improved.
     *
     * @param array<string, mixed> $suggestion the stored record (id, path, target, score, reason, ...)
     */
    public function suggestionCreated(array $suggestion): void
    {
        ($this->dispatch)('onSuggestionCreated', ['suggestion' => $suggestion]);
    }
}
