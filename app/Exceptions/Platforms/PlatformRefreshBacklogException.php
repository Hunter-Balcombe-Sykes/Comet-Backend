<?php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when the count of connections overdue for refresh exceeds
// the configured threshold — the "alert BEFORE N outgrows capacity" signal SCALE-1
// called for. A plain Log line would be an invisible breadcrumb (Nightwatch alerts on
// thrown/reported exceptions, not logs — see reference_nightwatch_alerts).
class PlatformRefreshBacklogException extends RuntimeException
{
    public function __construct(public int $overdueCount, public int $threshold)
    {
        parent::__construct("Platform refresh backlog {$overdueCount} exceeds threshold {$threshold}.");
    }
}
