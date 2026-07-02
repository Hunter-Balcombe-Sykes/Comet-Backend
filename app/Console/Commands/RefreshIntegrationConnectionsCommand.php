<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

// Dispatcher (not a worker): selects connections DUE for refresh per the platform's
// TTL and fans out one RefreshConnectionJob each onto the platform_refresh queue.
// Replaces the old serial foreach + 300/run cap (SCALE-1). Cheap and frequent — the
// heavy fetching happens on the queue, paced per-provider by the RateLimiter. Due-ness
// is per-connection (last_refreshed_at + per-provider TTL), so capacity scales with the
// fleet instead of a fixed daily cap.
class RefreshIntegrationConnectionsCommand extends Command
{
    protected $signature = 'integrations:refresh';

    protected $description = 'Dispatch a refresh job for every platform connection due per its TTL.';

    public function handle(PlatformRegistry $registry): int
    {
        $defaultTtl = (int) config('partna.refresh.default_ttl_seconds');
        $maxFailures = (int) config('partna.refresh.max_consecutive_failures');
        $dispatched = 0;

        foreach ($registry->refreshable() as $platform => $descriptor) {
            $ttl = $descriptor->refreshInterval() ?? $defaultTtl;
            $cutoff = now()->subSeconds($ttl);

            IntegrationConnection::query()
                ->where('platform', $platform)
                ->dueForRefresh($cutoff, $maxFailures)
                ->lazyById()
                ->each(function (IntegrationConnection $connection) use (&$dispatched) {
                    RefreshConnectionJob::dispatch($connection->id, $connection->platform);
                    $dispatched++;
                });
        }

        $this->info("Platform refresh: dispatched {$dispatched} due connection job(s).");

        return self::SUCCESS;
    }
}
