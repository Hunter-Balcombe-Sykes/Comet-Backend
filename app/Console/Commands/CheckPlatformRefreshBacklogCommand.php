<?php

namespace App\Console\Commands;

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\StrandedPendingWindow;
use Illuminate\Console\Command;

// Staleness alarm: counts connections overdue by more than (TTL × grace) and reports
// to Nightwatch when the total exceeds the alert threshold. This is how we learn the
// fleet has outgrown refresh capacity BEFORE cards silently go stale (SCALE-1).
class CheckPlatformRefreshBacklogCommand extends Command
{
    protected $signature = 'integrations:refresh-backlog';

    protected $description = 'Alert when too many platform connections are overdue for refresh.';

    public function handle(PlatformRegistry $registry): int
    {
        $defaultTtl = (int) config('partna.refresh.default_ttl_seconds');
        $maxFailures = (int) config('partna.refresh.max_consecutive_failures');
        $grace = (float) config('partna.refresh.backlog.grace_multiplier');
        $threshold = (int) config('partna.refresh.backlog.alert_threshold');

        $overdue = 0;

        foreach ($registry->refreshable() as $platform => $descriptor) {
            $ttl = (int) (($descriptor->refreshInterval() ?? $defaultTtl) * $grace);
            $cutoff = now()->subSeconds($ttl);

            $overdue += IntegrationConnection::query()
                ->where('platform', $platform)
                ->dueForRefresh($cutoff, $maxFailures)
                ->count();
        }

        // CA-SM review fix (E-5 follow-up): scopeDueForRefresh() above correctly
        // excludes EVERY 'pending' row from the cron's own selection now — a fresh
        // pending row is a healthy in-flight refresh/connect, not a fault — but
        // that exclusion also made a row stranded 'pending' by a dead worker
        // invisible to THIS alarm, where before it would have fallen into the
        // "never refreshed" bucket and been counted. Folded back into the SAME
        // $overdue total via the SAME threshold/exception this command already
        // has, rather than a second alarm: visibility restored, nothing new
        // invented. Counted across every platform, not just the registry's
        // refreshable set — connect writes 'pending' on non-refreshable
        // platforms too, and a dead worker there is just as real a fault. Never
        // fed back into dueForRefresh()/the cron itself (see scopeStrandedPending).
        $strandedCutoff = now()->subMinutes(StrandedPendingWindow::MINUTES);
        $overdue += IntegrationConnection::query()->strandedPending($strandedCutoff)->count();

        if ($overdue > $threshold) {
            report(new PlatformRefreshBacklogException($overdue, $threshold));
            $this->warn("Refresh backlog {$overdue} exceeds threshold {$threshold} — reported to Nightwatch.");
        } else {
            $this->info("Refresh backlog {$overdue} within threshold {$threshold}.");
        }

        return self::SUCCESS;
    }
}
