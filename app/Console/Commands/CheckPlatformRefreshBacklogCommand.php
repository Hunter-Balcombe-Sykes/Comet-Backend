<?php

namespace App\Console\Commands;

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
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

        if ($overdue > $threshold) {
            report(new PlatformRefreshBacklogException($overdue, $threshold));
            $this->warn("Refresh backlog {$overdue} exceeds threshold {$threshold} — reported to Nightwatch.");
        } else {
            $this->info("Refresh backlog {$overdue} within threshold {$threshold}.");
        }

        return self::SUCCESS;
    }
}
