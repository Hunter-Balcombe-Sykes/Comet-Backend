<?php

// app/Jobs/Analytics/RecordAnalyticsEventJob.php

namespace App\Jobs\Analytics;

use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Writes one analytics event to its raw table, then bumps the per-user analytics
// summary cache version (debounced). Dispatched onto the 'analytics' queue (default
// redis connection — already consumed by Horizon's supervisor-analytics).
//
// At-least-once: the writer's insertOrIgnore on the minted PK neutralises a retry that
// lands after a partial success, so a worker restart never double-counts.
class RecordAnalyticsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    /** @param  array<string, mixed>  $payload  AnalyticsEvent::toArray() */
    public function __construct(public readonly array $payload)
    {
        $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
    }

    public function handle(AnalyticsEventWriter $writer, AnalyticsCacheService $cache): void
    {
        $event = AnalyticsEvent::fromArray($this->payload);

        $writer->write($event);

        // Debounced version bump (was the controller's debounceInvalidateAnalytics),
        // moved here so it fires AFTER the row lands. AnalyticsCacheService::bumpVersion
        // is itself wrapped/fail-open, so a cache fault never fails a job whose write
        // already committed. SyncIngestor performs the same bump inline, keeping the two
        // ingest paths' side effects identical.
        $cache->bumpVersion($event->userId);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('Analytics event permanently dropped', [
            'job'        => static::class,
            'event_type' => $this->payload['event_type'] ?? 'unknown',
            'error'      => $e->getMessage(),
        ]);
    }
}
