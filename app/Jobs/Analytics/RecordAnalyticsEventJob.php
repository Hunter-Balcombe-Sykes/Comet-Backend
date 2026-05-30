<?php
// app/Jobs/Analytics/RecordAnalyticsEventJob.php
namespace App\Jobs\Analytics;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Contracts\AnalyticsEventWriter;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    public function handle(AnalyticsEventWriter $writer): void
    {
        $event = AnalyticsEvent::fromArray($this->payload);

        $writer->write($event);

        $this->bumpAnalyticsVersion($event->userId);
    }

    // Moved off the request path (was the controller's debounceInvalidateAnalytics).
    // Wrapped so a cache fault never fails a job whose write already committed —
    // re-running handle() would be harmless anyway (PK-idempotent), but acking avoids
    // a needless retry.
    private function bumpAnalyticsVersion(string $userId): void
    {
        try {
            if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
                Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
            }
        } catch (Throwable $e) {
            Log::warning('analytics.cache_bump_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
