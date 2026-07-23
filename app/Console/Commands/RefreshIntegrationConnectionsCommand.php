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
//
// RV-5: the fan-out is now bounded per platform per run (partna.refresh.dispatch) and
// spread across a stagger window via ->delay(). This does NOT reintroduce SCALE-1's
// fixed daily ceiling — eligibility is still pure TTL due-ness, the cap only bounds a
// single dispatch burst, and oldest-first ordering (NULLS FIRST) guarantees any
// over-cap remainder is picked up by a later run rather than starved. See
// config/partna.php 'refresh.dispatch' for the full rationale.
class RefreshIntegrationConnectionsCommand extends Command
{
    protected $signature = 'integrations:refresh
        {--limit= : Max connections per platform this run (default: partna.refresh.dispatch.max_per_platform)}
        {--stagger-window= : Seconds to spread dispatches across (0 = dispatch immediately; default: partna.refresh.dispatch.stagger_window_seconds)}';

    protected $description = 'Dispatch a refresh job for every platform connection due per its TTL.';

    public function handle(PlatformRegistry $registry): int
    {
        $defaultTtl = (int) config('partna.refresh.default_ttl_seconds');
        $maxFailures = (int) config('partna.refresh.max_consecutive_failures');

        $cap = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('partna.refresh.dispatch.max_per_platform');
        $cap = max(1, $cap);

        $window = $this->option('stagger-window') !== null
            ? (int) $this->option('stagger-window')
            : (int) config('partna.refresh.dispatch.stagger_window_seconds');
        $maxStagger = (int) config('partna.refresh.dispatch.max_stagger_seconds');

        // Phase 1: collect the capped, oldest-first candidate set per platform. Each
        // platform's cap is independent (a global cap would starve whichever platform
        // is registered last — see plan §4.2), and ordering by last_refreshed_at ASC
        // NULLS FIRST is what guarantees an over-cap remainder converges on a later
        // run instead of being starved forever (Postgres defaults ASC to NULLS LAST,
        // so omitting NULLS FIRST would starve never-refreshed connections first).
        $candidates = collect();

        foreach ($registry->refreshable() as $platform => $descriptor) {
            $ttl = $descriptor->refreshInterval() ?? $defaultTtl;
            $cutoff = now()->subSeconds($ttl);

            $candidates = $candidates->merge(
                IntegrationConnection::query()
                    ->where('platform', $platform)
                    ->dueForRefresh($cutoff, $maxFailures)
                    ->orderByRaw('last_refreshed_at ASC NULLS FIRST')
                    ->limit($cap)
                    ->get(['id', 'platform'])
            );
        }

        // Phase 2: dispatch with a single run-global stagger index so the spread is
        // even across platforms (a per-platform index would re-synchronise every
        // platform's first job at delay 0). ->delay() only sets available_at — it does
        // not sleep() — so this loop still completes in milliseconds regardless of
        // window size.
        $total = $candidates->count();
        $spacing = $window <= 0 ? 0 : min($maxStagger, $window / max($total, 1));

        foreach ($candidates as $i => $connection) {
            $pending = RefreshConnectionJob::dispatch($connection->id, $connection->platform);

            $delaySeconds = (int) round($i * $spacing);
            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        }

        // NOTE: a job whose unique lock is already held (e.g. still in rate-limit
        // purgatory from a prior run) is silently swallowed by ShouldBeUnique at
        // dispatch time, so this counts SELECTIONS, not confirmed dispatches.
        $this->info("Platform refresh: selected {$total} due connection(s) for dispatch.");

        return self::SUCCESS;
    }
}
