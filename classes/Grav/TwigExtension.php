<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\Grav;

use Closure;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * redirect_for(url)        {status, location, rule_id} or null when no rule applies
 * url|redirect_target      the redirect location, or the URL itself when no rule applies
 *
 * Both are read-only: nothing is recorded or sent.
 */
final class TwigExtension extends AbstractExtension
{
    /**
     * @param Closure(string): (array{status: int, location: string, rule_id: string}|null) $lookup
     */
    public function __construct(private readonly Closure $lookup)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('redirect_for', $this->redirectFor(...))];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('redirect_target', $this->redirectTarget(...))];
    }

    /**
     * @return array{status: int, location: string, rule_id: string}|null
     */
    public function redirectFor(mixed $url): ?array
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        return ($this->lookup)($url);
    }

    public function redirectTarget(mixed $url): mixed
    {
        $result = $this->redirectFor($url);

        return $result !== null && $result['location'] !== '' ? $result['location'] : $url;
    }
}
