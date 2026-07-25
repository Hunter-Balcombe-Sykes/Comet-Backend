<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Slow-fetch enrichment for an async link-card connect (JOB-1). The connect action
// writes a usable MINIMAL card synchronously (status 'pending') and returns 202;
// this job runs snapshot() (the outbound HTTP that used to block the request thread)
// on the queue and upgrades ONLY the display fields — name/description/favicon/logo —
// leaving the stored url untouched so resource_id / storeKey dedup stays stable. A
// failed snapshot is fine: the minimal card is an acceptable final state, so the row
// still flips to 'ok'. Shared by custom links, online-ordering, booking, reservations.
class EnrichLinkCardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public string $userId,
        public string $platform,
        public string $resourceId,
        public string $url,
    ) {
        $this->onQueue(config('partna.queues.scraping'));
    }

    public function uniqueId(): string
    {
        return $this->platform.':'.$this->resourceId;
    }

    public function handle(LinkCardScraper $scraper): void
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', $this->platform)
            ->where('resource_id', $this->resourceId)
            ->first();

        if ($row === null) {
            return; // removed between dispatch and run
        }

        $snapshot = $scraper->snapshot($this->url); // slow HTTP; null on failure — OUTSIDE the lock

        // PWL-8: only the re-read + write span is locked, behind the same
        // per-user/platform key the connect-time controller writes hold
        // (ManagesIntegrationConnection::withConnectionLock), so this job can't
        // race a concurrent connect/forget on the same row. Re-read INSIDE the
        // lock for authoritative state — the scrape above took time, so the
        // row may have changed or been deleted while it ran. On contention:
        // log-and-skip, not terminal-write — the minimal card written at
        // connect is already an acceptable final state, so leave the row's
        // pending/last-good state untouched rather than fail the job or burn
        // a retry.
        try {
            Cache::lock(CacheKeyGenerator::platformConnectionLock($this->platform, $this->userId), 10)
                ->block(5, function () use ($snapshot) {
                    $row = IntegrationConnection::query()
                        ->where('user_id', $this->userId)
                        ->where('platform', $this->platform)
                        ->where('resource_id', $this->resourceId)
                        ->first();

                    if ($row === null) {
                        return; // removed while the scrape was in flight
                    }

                    $payload = $row->payload;
                    if ($snapshot !== null) {
                        // Upgrade DISPLAY fields only — never the stored url (keeps dedup keys stable).
                        foreach (['name', 'description', 'favicon', 'logo'] as $field) {
                            if (($snapshot[$field] ?? null) !== null) {
                                $payload[$field] = $snapshot[$field];
                            }
                        }
                    }

                    $row->update([
                        'payload' => $payload,
                        'last_refreshed_at' => now(),
                        'last_refresh_status' => 'ok',
                    ]);

                    // Bell notice for booking / reservations / online ordering, whose
                    // rows carry resource_kind NULL. Custom links (controller-added and
                    // CustomLinkSeeder-added alike) stamp 'link' and are dropped by the
                    // notifier's own guard — which is why no kind check belongs here.
                    // Container-resolved, matching the trait's emit point: this handle()
                    // has tests that invoke it directly with explicit arguments.
                    app(IntegrationNotifier::class)->connected($row);
                });
        } catch (LockTimeoutException $e) {
            report($e);
            Log::warning('platforms.enrich_link_card.lock_timeout', [
                'user_id' => $this->userId,
                'platform' => $this->platform,
                'resource_id' => $this->resourceId,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('platforms.enrich_link_card.failed', [
            'user_id' => $this->userId,
            'platform' => $this->platform,
            'resource_id' => $this->resourceId,
            'error' => $e->getMessage(),
        ]);
    }
}
