<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

// One connection = one refresh. The dispatcher (integrations:refresh) fans these
// out onto the platform_refresh queue; manual/webhook triggers can reuse the same
// job later (the "any trigger → one job" spine). Wraps PlatformRefresher, which
// owns the fetch + failure bookkeeping. Per-provider outbound pressure is capped by
// the 'platform-refresh' RateLimiter — NOT by worker count — so the supervisor can
// run many processes safely. ShouldBeUnique dedups a cron re-run / manual refresh
// colliding with an in-flight or retrying job.
class RefreshConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Unlimited attempts, bounded by retryUntil() below. Deliberate: the RateLimited
     * middleware RELEASES a job when the provider is over-limit, and every release
     * counts as an attempt — a finite $tries would mass-fail queued jobs during a
     * cold-start burst before they ever executed. Real errors are capped separately
     * by $maxExceptions, so a genuinely broken fetch still fails fast.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** Backoff (seconds) between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    /**
     * Dedup window — matches the retryUntil() horizon so the hourly dispatcher can't
     * enqueue a duplicate while the original is still alive in rate-limit purgatory.
     */
    public int $uniqueFor = 7200;

    public function __construct(
        public string $connectionId,
        public string $platform,
    ) {
        $this->onQueue(config('partna.queues.platform_refresh'));
    }

    /**
     * Wall-clock retry deadline. If a job can't get through its provider's rate limit
     * within 2h, let it lapse — the connection is still due, so the next dispatcher
     * run simply re-creates it. Freshness converges; nothing is lost.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function uniqueId(): string
    {
        return $this->connectionId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-refresh')];
    }

    public function handle(PlatformRefresher $refresher): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);

        // Deleted or deactivated between dispatch and execution — nothing to do.
        if ($connection === null || ! $connection->is_active) {
            return;
        }

        $refresher->refresh($connection);
    }
}
