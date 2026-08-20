<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Http\FetchBudget;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
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
// windows are seconds not hours, and this job must not gate on is_active —
// that flag isn't its concern.
//
// Callers are NOT uniformly interactive. The three dashboard controllers have a
// human watching a modal; LinkRouter's auto-route dispatch has nobody, and no
// interactive retry path at all. The tries/backoff/uniqueFor tuning below is
// still sized for the interactive case (it remains correct for both), but the
// auto path is why a runtime kill switch and the FreshaFetch self-heal backstop
// exist — a failure there is never noticed by a user who could retry it.
//
// SYNC-DRIVER CORRECTNESS (highest-value constraint in the unit): tests pin
// queue.default=sync (phpunit.xml), so dispatch()->afterCommit() executes
// handle() INLINE in the request, after commit. There is no queue to catch a
// throw and no failed() callback in that mode — an uncaught exception here
// becomes a 500 under sync where Horizon would show a clean 'failed' row under
// a real queue. Every EXPECTED upstream failure (the three Fetch*Exception
// subclasses) is therefore caught inside handle() and converted to a terminal
// row state; only a genuinely unexpected throwable propagates.
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
    // default) with headroom: handle() below opens a FetchBudget at exactly that
    // config value around the vendor fetch (E-1 fix), so THIS is the job's own
    // wall-clock backstop on top of a real, enforced bound — not, as an earlier
    // draft of this comment claimed, on top of nothing.
    public int $timeout = 75;

    // Short on purpose: a user who mistypes, sees the failure, and retries
    // within two minutes must actually get a fresh job — NOT RefreshConnectionJob's
    // 7200s dedupe window, which would silently swallow that retry.
    public int $uniqueFor = 120;

    public int $maxExceptions = 2;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $platform,
        // TRUE when nothing human triggered this connect — the auto-route path.
        // Suppresses the "connected!" notice: a pre-account user has no email and
        // never asked for this connection. Defaults FALSE so the three dashboard
        // call sites are byte-identical.
        public readonly bool $systemInitiated = false,
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

    public function handle(PlatformRegistry $registry, FetchBudget $budget, IntegrationNotifier $notifier): void
    {
        // ::find() respects the soft-delete scope — a null result already
        // covers both "never existed" and "user disconnected while queued"
        // (same reasoning as InstagramConnectJob::handle()).
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $descriptor = $registry->get($this->platform);
        // CA-W6: connectFetchStrategy() defaults to fetchStrategy() for every
        // descriptor that hasn't called connectFetch() — byte-identical lookup
        // for the eight already-armed platforms. Fresha is the one override
        // (FreshaFetch, its refreshStrategy()'s fetch, is refresh-only and
        // would 304 a fresh pending row — see FreshaConnectFetch's docblock).
        $fetch = $descriptor?->connectFetchStrategy();
        if ($descriptor === null || $fetch === null) {
            $this->markTerminal($connection, 'error', 'unsupported_platform');

            return;
        }

        // FETCH OUTSIDE THE LOCK — multi-second, touches nothing shared. Same
        // discipline as ScheduledRefresh::run().
        //
        // E-1: wrapped in the SAME wall-clock budget ConnectResolver
        // open around every other vendor call reachable from a connect — this job
        // was the one gap (identify() at 202-time was bounded, the fetch that
        // completes it here was not). FetchBudget is a SCOPED container binding
        // (see its docblock); nothing else in this path opens one, so there is no
        // nesting to worry about.
        try {
            $next = $budget->open(
                (float) config('partna.http_fetch.connect_budget_seconds', 20),
                fn () => $fetch->fetch($connection),
            );
        } catch (FetchNotModifiedException) {
            // Upstream confirmed the stored payload is still current. The
            // pending row's payload (the identity key + whatever identify()
            // could derive, merged over any prior payload on a reconnect —
            // see ManagesIntegrationConnection::upsertConnection) IS the
            // payload, so there is nothing to overwrite. Mirrors
            // PlatformRefresher::recordNotModified's quiet bump exactly.
            $this->markOk($connection);
            // A 304 is a SUCCESSFUL connect — the vendor confirmed the stored
            // payload is current. Skipping it here would silently drop the notice
            // for exactly the reconnect case. markOk() saves quietly but mutates
            // the in-memory row first, so the notifier's 'ok' guard passes.
            if (! $this->systemInitiated) {
                $notifier->connected($connection);
            }

            return;
        } catch (FetchShapeException $e) {
            // A stored payload is missing the key the fetch needs to read —
            // should be UNREACHABLE (identify() writes exactly that key per
            // platform; DeferredConnectParityTest guards it), so report()
            // makes this a canary rather than a silently swallowed 'error' row.
            report($e);
            $this->markTerminal($connection, 'error', $descriptor->connectFetchErrorMessage());

            return;
        } catch (FetchUnavailableException $e) {
            // A strategy that knows its failure was NOT a vendor miss (a staff
            // disable, a booking-XOR conflict, a mid-flight disconnect, a lock
            // timeout) carries its own wording; anything else is a genuine
            // vendor miss and gets the platform's own copy. Same rule the two
            // branches below already apply to this job's own non-vendor failures.
            $this->markTerminal($connection, 'unavailable', $e->userMessage() ?? $descriptor->connectFetchErrorMessage());

            return;
        }

        // C: re-check the staff kill switch AT WRITE TIME. This job's write never
        // goes through ManagesIntegrationConnection::upsertConnection() — the only
        // place assertPlatformAvailable() normally runs — so nothing stops a staff
        // disable landing between the 202 and this job from writing fresh content
        // for a platform that's now off. Same underlying rule
        // (FeatureAvailability::for($user)->allows('integration.<platform>')) the
        // trait checks, just without its abort(503)-shaped wrapper, which has no
        // meaning in a queue worker. A user that vanished (soft-deleted) between
        // dispatch and now can't be checked either way, so it fails the same as a
        // disabled platform — a terminal row, never a content write.
        //
        // The message is deliberately NOT $descriptor->connectFetchErrorMessage():
        // a staff disable (or the user vanishing) is not a vendor miss, and that
        // wording ("Could not find releases on that Bandcamp page.") would tell the
        // user we couldn't find their account when we never even asked the vendor —
        // same reasoning as the LockTimeoutException catch below.
        $user = $connection->user;
        if ($user === null || ! FeatureAvailability::for($user)->allows("integration.{$this->platform}")) {
            $this->markTerminal($connection, 'unavailable', FetchUnavailableException::GENERIC_USER_MESSAGE);

            return;
        }

        // SINGLE LOCKED WRITE — same platform-wide key as ScheduledRefresh::
        // run(), so this job can never race a scheduled refresh.
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

            // Reached only when the locked write succeeded — a thrown
            // LockTimeoutException skips straight to the catch, where
            // markTerminal() lands 'unavailable' and no notice is owed.
            if (! $this->systemInitiated) {
                $notifier->connected($connection);
            }
        } catch (LockTimeoutException $e) {
            // MUST NOT swallow like ScheduledRefresh::run() does — correct for
            // an hourly cron (the next tick retries), catastrophically wrong
            // here: a swallowed timeout leaves the row 'pending' forever and
            // the client polls forever.
            //
            // Does NOT call $this->release(): Illuminate\Queue\Jobs\Job::release()
            // only flips an internal flag, and SyncQueue::executeJob() (tests'
            // driver — phpunit.xml pins QUEUE_CONNECTION=sync) reacts solely to
            // a thrown Throwable — it never checks isReleased(). So on sync,
            // release() is a silent no-op: handle() returns normally, failed()
            // never fires, and the row is stuck 'pending' forever — precisely
            // the outcome this catch
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
            // OUR lock contention as a vendor miss. Same string the poll side
            // shows for a stale pending row (FetchUnavailableException::
            // STALE_CONNECT_ERROR — a neutral home both this job and
            // DefersBespokeConnect reference, since PHP cannot reference a
            // trait constant externally) — one wire copy, not a second
            // hand-typed copy of it here.
            report($e);
            Log::warning('platform.connect_job.lock_timeout', [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'user_id' => $connection->user_id,
            ]);
            $this->markTerminal($connection, 'unavailable', FetchUnavailableException::STALE_CONNECT_ERROR);
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
                $descriptor?->connectFetchErrorMessage() ?? FetchUnavailableException::GENERIC_USER_MESSAGE,
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

        // F26 (2026-08-18): a row that has NEVER fetched OK is not a
        // connection. Left live after a failed first connect it showed as a
        // connected account in the Platforms table (accountName derived from
        // the input url), sat in /accounts, and had provisioned an ingest
        // source that would fail on every tick. Soft-delete it — the status
        // poll reads trashed failed rows (connectStatusRow) so the modal still
        // shows the error and offers a retry; a retry writes a fresh row
        // (the unique indexes are partial on deleted_at IS NULL). A RE-connect
        // of an account that has fetched OK before keeps its row: the old
        // payload is still good, only this attempt failed.
        if ($connection->last_refreshed_at === null) {
            Log::info('platform.connect_job.first_fetch_failed_row_removed', [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'status' => $status,
            ]);
            $connection->delete();
        }
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
