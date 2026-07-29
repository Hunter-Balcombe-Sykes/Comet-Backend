<?php

namespace App\Exceptions\Routing;

use RuntimeException;

// Reported to Nightwatch when the count of routing.source_intents stuck
// proposed/blocked past the age gate exceeds the configured threshold
// (LIFE-19). Modelled on PlatformRefreshBacklogException — this is a BACKLOG
// alarm, not a per-row one: every reachable stuck state is a question waiting
// on a user in their own suggestions inbox (SuggestionsController::index), so
// one ignored suggestion is not an incident. A mass of them is a detector
// regression, a misconfiguration, or the inbox being unreachable — an
// engineering fault, which is what this pages on.
class StuckSourceIntentBacklogException extends RuntimeException
{
    public function __construct(public int $stuckCount, public int $threshold, public int $ageDays)
    {
        parent::__construct("Stuck source intents backlog {$stuckCount} (older than {$ageDays}d) exceeds threshold {$threshold}.");
    }
}
