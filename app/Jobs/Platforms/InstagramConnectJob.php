<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\Dispatchers\IntegrationNotifier;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Performs the full Instagram automatic-connect pipeline in the background so
// connect() can return 202 immediately instead of blocking the PHP-FPM worker
// for up to 150s (110s Apify + media mirroring).
//
// Pipeline:
//   1. Apify scrape via InstagramScraper::fetchProfile (up to 110s), done here.
//   2-4. Mirror the SINGLE latest post to R2, BE2 bio-link auto-sync, and the
//      connection row upsert (last_refresh_status='ok'/'unavailable') all live
//      in InstagramConnectionSeeder::seed — the same pipeline reused by
//      PreAccount\Generators\InstagramSourceGenerator for site-first builds.
//
// The connection row is written with last_refresh_status='pending' by the
// controller BEFORE this job is dispatched, so the status endpoint can respond
// before the job runs.
class InstagramConnectJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify can take up to 110s; allow headroom for media mirroring on top.
    public int $timeout = 150;

    // Unlimited attempts, bounded by retryUntil() below — the 'platform-connect'
    // RateLimited middleware RELEASES this job when the Apify actor is over-limit, and
    // every release counts as an attempt. A finite $tries would mass-fail connects
    // during the exact signup spike the gate exists to absorb. Genuine errors stay
    // capped by $maxExceptions, so a broken scrape still fails fast.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120];

    // One content failure (e.g. Apify returned bad data) should surface quickly
    // rather than silently retrying twice.
    public int $maxExceptions = 2;

    // One auto-connect per connection at a time. The window matches retryUntil() so a
    // job parked in rate-limit purgatory can't have a duplicate slip in behind it, bill
    // a second Apify scrape, and tear the connection row (LIFE-1). The lock also releases
    // on completion/failure, so this is the worst-case backstop, not the common path.
    public int $uniqueFor = 900;

    // Declared, not promoted (and so not readonly): a promoted default is not a
    // DECLARED default, so a job enqueued before this property existed would restore
    // with it uninitialized — SerializesModels skips properties absent from the
    // payload — and fatal on read in handle(). Defaults false: see handle().
    public bool $notifyOnConnect = false;

    // Declared, not promoted, for the identical reason spelled out above — this
    // job is enqueued across deploys, so a promoted default would restore
    // uninitialized and fatal on the read in handle().
    public bool $autoConnectBooking = false;

    // $autoConnectBooking: TRUE only when a staff/ManyChat build reached this
    // scrape (today, via a Google listing that carries an Instagram link). The
    // dashboard connect and the refresh sweep both leave it at FALSE so the
    // account holder is shown a picker instead. NOT part of uniqueId() below:
    // origin does not make two scrapes of one connection different jobs.
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $connectionId,
        bool $notifyOnConnect = false,
        bool $autoConnectBooking = false,
    ) {
        $this->notifyOnConnect = $notifyOnConnect;
        $this->autoConnectBooking = $autoConnectBooking;
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    // Key on connection + username: dedups a true duplicate (retry / double-submit
    // of the same connect, which would re-bill Apify and tear the row), but NOT a
    // legitimate account switch — the connection row is reused via updateOrCreate,
    // so connecting a different username must still run (no per-user cooldown).
    public function uniqueId(): string
    {
        return $this->connectionId.':'.$this->username;
    }

    /** Apify actor for the 'platform-connect' rate budget. */
    public function providerRateKey(): string
    {
        return Platform::Instagram->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-connect')];
    }

    // Wall-clock deadline for rate-limit releases. A connect held behind its actor's
    // per-minute limit keeps retrying until it slips through or 15 min elapses, then
    // lapses to failed() (terminal, user can reconnect) — never an infinite park.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(InstagramScraper $scraper, InstagramConnectionSeeder $seeder, InstagramAutoSync $autoSync): void
    {
        // ::find() respects the soft-delete scope, so a null result already covers
        // both "never existed" and "user disconnected (soft-deleted) while queued".
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        // fetchProfile(), NOT fetchProfileResult(): 36 mock sites across 14 test
        // files stub this exact method, so the entry point the job calls is part
        // of its contract. It also suffices — fetchProfile() returns null for a
        // thin profile too, and the null branch below is what protects the data.
        $profile = $scraper->fetchProfile($this->username, $this->userId);

        if (! $profile) {
            // Hard-fail loudly so Horizon records a failure and Nightwatch alerts —
            // a silent markFailed()+return made Horizon mark the job "succeeded",
            // hiding a broken auto-connect (JOB-4). No retry: re-running re-bills the
            // Apify scrape. failed() marks the connection 'unavailable' for the user.
            //
            // Returning HERE, before seed(), is also what preserves an already-
            // connected user's payload and mirrored R2 files when a refresh comes
            // back thin: seed()'s stale-reclaim deletes every mirrored file it did
            // not write this run, and a thin run writes none.
            $this->fail(new \RuntimeException(
                "Instagram scrape returned no profile for @{$this->username} (user {$this->userId})"
            ));

            return;
        }

        // Mirror + selection-build + auto-sync + row-update all live in the
        // seeder — the same pipeline PreAccount\InstagramSourceGenerator reuses
        // for pre-account (site-first) builds.
        $seeder->seed($connection, $this->username, $this->userId, $profile, $this->autoConnectBooking);

        // Bell only for a dispatcher that means "the user just added this" — never
        // GoogleBusinessAutoSync (runs for an UNCLAIMED pre-account build, whose
        // google_business_full_sync gate has no claimed-status term) nor
        // RefreshController (a re-pull of an already-connected account). seed() wrote
        // through this instance, so the notifier's status guard does the rest.
        if ($this->notifyOnConnect) {
            app(IntegrationNotifier::class)->connected($connection);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('instagram.connect_job.failed', [
            'user_id' => $this->userId,
            'username' => $this->username,
            'connection_id' => $this->connectionId,
            'error' => $e->getMessage(),
        ]);

        $connection = IntegrationConnection::find($this->connectionId);
        if ($connection) {
            $this->markFailed($connection, 'job_failed');
        }
    }

    // PWL-7 (job/seeder half): this is the failure path, so it must NEVER fail to
    // record the terminal state — lock it against a concurrent writer of the same
    // row (same platformConnectionLock key seed()/ConnectFetchJob use) but fall
    // back to a bare write on contention rather than leaving the row stuck.
    private function markFailed(IntegrationConnection $connection, string $error): void
    {
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, (string) $connection->user_id);
        $write = function () use ($connection, $error) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'last_refresh_error' => $error,
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        };
        try {
            Cache::lock($key, 10)->block(5, $write);
        } catch (LockTimeoutException) {
            $write();   // best-effort terminal write; a failure must always be recorded
        }
    }
}
