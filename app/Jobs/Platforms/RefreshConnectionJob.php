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
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * RV-8: set when this dispatch came from RefreshController (a user clicking
     * "refresh"), not RefreshIntegrationConnectionsCommand's hourly cron. Folded
     * into uniqueId() below so a manual click gets its own dedup lane — without
     * it, a cron-dispatched job already holding this connection's 2h
     * ShouldBeUnique lock (including sitting in rate-limit purgatory, where a
     * release does not free the lock) would silently swallow the manual
     * dispatch for up to 2 hours, with no signal back to the user. Declared as
     * a plain property with a class-level default (NOT a promoted constructor
     * param) — same reasoning as CloudflareCachePurgeJob's $bulk: a promoted
     * property's default is applied by the CONSTRUCTOR, not the declaration, so
     * a job already serialized (queued) before this change and unserialized
     * after it would leave the property truly uninitialized and fatal in
     * uniqueId() on its next retry.
     */
    public bool $manual = false;

    public function __construct(
        public string $connectionId,
        public string $platform,
        bool $manual = false,
    ) {
        $this->manual = $manual;
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
        return $this->connectionId.($this->manual ? ':manual' : '');
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
        // E-5: a 'pending' row belongs to an in-flight (or stranded) ConnectFetchJob.
        // RefreshIntegrationConnectionsCommand's dueForRefresh() scope already
        // excludes these, but RefreshController::refresh() (the manual "refresh"
        // button) dispatches this job over every active() row regardless of
        // status — this guard is what actually keeps THAT path from racing a
        // pending connect and recording a vendor 304 as a bogus 'ok'.
        if ($connection === null || ! $connection->is_active || $connection->last_refresh_status === 'pending') {
            return;
        }

        $refresher->refresh($connection);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('integrations.refresh.job_failed', [
            'connection_id' => $this->connectionId,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);
    }
}
