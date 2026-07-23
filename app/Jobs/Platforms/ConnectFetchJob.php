<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\HighlightsPicker;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Phase 2 of docs/superpowers/plans/2026-07-20-platform-connect-async.md
// (LIFE-13..20): completes a pending row written by the (not-yet-wired, W6)
// deferred-connect controller path. One generic, registry-driven job — every
// per-platform variance already lives inside the registry-resolved
// FetchStrategy (the SAME strategy ScheduledRefresh runs on the 12h cron), so
// this job is a lookup plus a single locked write, not eight per-platform copies.
//
// Deliberately NOT a thin wrapper around RefreshConnectionJob/PlatformRefresher
// — see the plan's §2d rejection reasons: a failed CONNECT is not a failing
// CONNECTION (PlatformHealthNotifier must never fire here), the dedupe/retry
// windows are seconds not hours (a human is watching the modal, not a cron),
// and this job must not gate on is_active — that flag isn't its concern.
//
// SYNC-DRIVER CORRECTNESS (highest-value constraint in the unit): the deployed
// dev env runs queue.default=sync, so dispatch()->afterCommit() executes
// handle() INLINE in the request, after commit. There is no queue to catch a
// throw and no failed() callback in that mode — an uncaught exception here
// becomes a 500 in dev where Horizon would show a clean 'failed' row in prod.
// Every EXPECTED upstream failure (the three Fetch*Exception subclasses) is
// therefore caught inside handle() and converted to a terminal row state; only
// a genuinely unexpected throwable propagates.
class ConnectFetchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> Seconds — NOT RefreshConnectionJob's 30/120/300; a human is watching the connect modal, not a cron. */
    public array $backoff = [5, 20];

    // Must exceed config('partna.http_fetch.connect_budget_seconds') (20s
    // default) with headroom: that budget bounds the vendor fetch itself via
    // Phase 1's SafeUrlFetcher deadline; this is the job's own wall-clock
    // backstop on top of it.
    public int $timeout = 45;

    // Short on purpose: a user who mistypes, sees the failure, and retries
    // within two minutes must actually get a fresh job — NOT RefreshConnectionJob's
    // 7200s dedupe window, which would silently swallow that retry.
    public int $uniqueFor = 120;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $platform,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function uniqueId(): string
    {
        return "{$this->platform}:{$this->connectionId}";
    }

    // Deliberately NO RateLimited middleware. 'partna.connect.rate_limits' is
    // keyed by Apify ACTOR and caps PAID scrape burst; all eight deferred-
    // connect platforms are keyless public scrapes. Attaching that limiter
    // would couple free connects to the paid budget and mass-release this
    // queue during exactly the signup spike it exists to absorb.
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [];
    }

    public function handle(PlatformRegistry $registry, HighlightsPicker $picker): void
    {
        // ::find() respects the soft-delete scope — a null result already
        // covers both "never existed" and "user disconnected while queued"
        // (same reasoning as InstagramConnectJob::handle()).
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $descriptor = $registry->get($this->platform);
        $fetch = $descriptor?->fetchStrategy();
        if ($descriptor === null || $fetch === null) {
            $this->markTerminal($connection, 'error', 'unsupported_platform');

            return;
        }

        // FETCH OUTSIDE THE LOCK — multi-second, touches nothing shared. Same
        // discipline as ScheduledRefresh::run() and HighlightsPicker::items().
        try {
            $next = $fetch->fetch($connection);
        } catch (FetchNotModifiedException) {
            // Upstream confirmed the stored payload is still current. The
            // pending row's payload (the identity key + whatever identify()
            // could derive, merged over any prior payload on a reconnect —
            // see ManagesIntegrationConnection::upsertConnection) IS the
            // payload, so there is nothing to overwrite. Mirrors
            // PlatformRefresher::recordNotModified's quiet bump exactly.
            $this->markOk($connection);

            return;
        } catch (FetchShapeException $e) {
            // A stored payload is missing the key the fetch needs to read —
            // should be UNREACHABLE (identify() writes exactly that key per
            // platform; DeferredConnectParityTest guards it), so report()
            // makes this a canary rather than a silently swallowed 'error' row.
            report($e);
            $this->markTerminal($connection, 'error', $descriptor->connectFetchErrorMessage());

            return;
        } catch (FetchUnavailableException) {
            $this->markTerminal($connection, 'unavailable', $descriptor->connectFetchErrorMessage());

            return;
        }

        // Warm the highlights picker snapshot, ALSO outside the lock — though
        // in practice there is nothing to wait on here: warmInto() is a pure
        // array merge (backfills `recent` from the stored row only when the
        // fresh fetch payload doesn't already carry one), never a vendor call
        // or a HighlightsStrategy method. Must still happen before the write
        // below: warmInto() shapes the payload without touching the DB
        // specifically so content + `recent` land in ONE locked write (see
        // HighlightsPicker::warmInto's docblock).
        if ($descriptor->hasHighlights()) {
            $next = $picker->warmInto($next, $connection);
        }

        // SINGLE LOCKED WRITE — same platform-wide key as ScheduledRefresh::
        // run(), so this job can never race a dashboard highlights save or a
        // scheduled refresh. Taking the lock twice (once for content, once for
        // `recent`) would open a window for a highlights save to land between.
        //
        // NOT per-account (2026-07-21 fix): CacheKeyGenerator::platformConnectionLock()
        // no longer takes a suffix, so this can no longer drift from
        // withConnectionLock()'s key the way it used to for a multi-account
        // platform (bandcamp/youtube/vimeo/youtube-music/spotify/soundcloud).
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, $connection->user_id);

        try {
            Cache::lock($key, 10)->block(5, function () use ($connection, $next) {
                $connection->update([
                    'payload' => $next,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                    // Conditional-request validators: a wired fetch strategy
                    // (OEmbedFetch/YoutubeMusicFetch) set these via
                    // ConditionalContext::applyTo($connection) before
                    // returning above; a non-wired strategy leaves them at
                    // their stored value, so this is a harmless no-op write there.
                    'refresh_etag' => $connection->refresh_etag,
                    'refresh_last_modified' => $connection->refresh_last_modified,
                ]);
            });
        } catch (LockTimeoutException $e) {
            // MUST NOT swallow like ScheduledRefresh::run() does — correct for
            // an hourly cron (the next tick retries), catastrophically wrong
            // here: a swallowed timeout leaves the row 'pending' forever and
            // the client polls forever.
            //
            // Does NOT call $this->release(): Illuminate\Queue\Jobs\Job::release()
            // only flips an internal flag, and SyncQueue::executeJob() (the
            // deployed dev env's driver) reacts solely to a thrown Throwable —
            // it never checks isReleased(). So on sync, release() is a silent
            // no-op: handle() returns normally, failed() never fires, and the
            // row is stuck 'pending' forever — precisely the outcome this catch
            // exists to prevent, and worse than ScheduledRefresh's swallow
            // (that at least logs). A retry that only works with a real queue
            // worker behind it reads as handled but isn't, which breaks the
            // invariant this whole job is built around: queued and inline
            // execution must produce the SAME user-visible outcome — the same
            // reason every Fetch*Exception above is caught rather than left to
            // the queue. block(5) already waited five seconds on a per-user,
            // per-platform lock; contention that outlasts that is anomalous,
            // not routine, so a clean terminal failure — parity with every
            // Fetch*Exception branch above, and matching the poll contract
            // (failed -> show error -> offer retry) — is the right trade over
            // an automatic retry that silently doesn't exist on this driver.
            //
            // The message is deliberately NOT $descriptor->connectFetchErrorMessage():
            // that wording ("couldn't find that channel") would misrepresent
            // OUR lock contention as a vendor miss.
            report($e);
            Log::warning('platform.connect_job.lock_timeout', [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'user_id' => $connection->user_id,
            ]);
            $this->markTerminal($connection, 'unavailable', 'We couldn\'t save your connection just then — please try again.');
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('platform.connect_job.failed', [
            'connection_id' => $this->connectionId,
            'platform' => $this->platform,
            'error' => $e->getMessage(),
        ]);

        $connection = IntegrationConnection::find($this->connectionId);
        if ($connection) {
            $descriptor = app(PlatformRegistry::class)->get($this->platform);
            $this->markTerminal(
                $connection,
                'unavailable',
                $descriptor?->connectFetchErrorMessage() ?? 'We could not load that account. Please try again.',
            );
        }
    }

    /**
     * Terminal write for an expected failure — payload untouched (unlike the
     * success write above, this never rewrites content), so saveQuietly()
     * matches InstagramConnectJob::markFailed: no observer, no edge-cache
     * purge for a row whose displayed content didn't change.
     */
    private function markTerminal(IntegrationConnection $connection, string $status, ?string $error): void
    {
        $connection->forceFill([
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,
        ])->saveQuietly();
    }

    /**
     * 304 short-circuit — bump the freshness clock, clear the failure
     * counter, touch nothing else. Quiet for the same reason as markTerminal:
     * no content changed, so no purge is owed.
     */
    private function markOk(IntegrationConnection $connection): void
    {
        $connection->forceFill([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ])->saveQuietly();
    }
}
