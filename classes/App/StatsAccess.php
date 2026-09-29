<?php

declare(strict_types=1);

namespace Grav\Plugin\RedirectManager\App;

use Grav\Plugin\RedirectManager\Stats\StatsStore;
use Throwable;

/**
 * Hands out the StatsStore after folding pending hit logs into stats.json, at most once per instance
 * (one instance per request or CLI run). A failed aggregation is ignored: the last written state is still valid.
 */
final class StatsAccess
{
    private bool $aggregated = false;

    public function __construct(private readonly StatsStore $store)
    {
    }

    public function store(): StatsStore
    {
        if (!$this->aggregated) {
            $this->aggregated = true;
            try {
                $this->store->aggregate();
            } catch (Throwable) {
                // Read the last written statistics instead.
            }
        }

        return $this->store;
    }
}
