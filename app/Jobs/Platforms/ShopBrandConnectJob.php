<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\ShopBrand;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Shop\ShopContentWriter;
use App\Site\Documents\BuildState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// W9 §4 Unit 3 — completes a `pending` site.shop_brands row that addBrand()
// wrote synchronously (brand_id/provider/url/source_url all already truthful)
// by fetching the display profile (name/currency/favicon/logo) ShopBrandProfiler
// ::forRow() defers for shopify/woocommerce/squarespace. Lands INERT — nothing
// dispatches this job yet (that's Unit 4).
//
// Deliberately its OWN job rather than a generic-registry lookup like
// ConnectFetchJob: Shop is the only platform where one platform_connections
// row fans out to up to MAX_BRANDS=5 content rows, so uniqueness/failure state
// has to key on the BRAND, not the connection — see uniqueId() below.
class ShopBrandConnectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> Seconds — mirrors ConnectFetchJob's human-paced backoff, not RefreshConnectionJob's cron one. */
    public array $backoff = [5, 20];

    // Must exceed config('partna.http_fetch.connect_budget_seconds') (20s
    // default) with headroom, same reasoning as ConnectFetchJob::$timeout.
    public int $timeout = 45;

    // Short on purpose — a user who retries within two minutes must get a
    // fresh job, not be silently swallowed by a cron-length dedupe window.
    public int $uniqueFor = 120;

    public int $maxExceptions = 2;

    // Mirrors ManagesIntegrationConnection::assertPlatformAvailable()'s
    // abort(503) message verbatim — that method is private and abort()-shaped,
    // meaningless in a worker, so the underlying FeatureAvailability rule is
    // called directly (below) and this is the same sentence it would have used.
    private const UNAVAILABLE_ERROR = 'This integration is currently unavailable.';

    public function __construct(
        public readonly string $brandRowId,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    /**
     * Keyed on the BRAND row, not "{platform}:{connectionId}" like
     * ConnectFetchJob. Shop is the only platform where one connection fans out
     * to up to 5 brands — a connection-keyed uniqueId() would collapse two
     * brands added within uniqueFor() seconds into one job and strand the
     * second in 'pending' forever.
     */
    public function uniqueId(): string
    {
        return "shop-brand:{$this->brandRowId}";
    }

    // Deliberately NO RateLimited middleware — same reasoning as
    // ConnectFetchJob::middleware(): Shop's fetches are keyless public
    // scrapes, not paid-actor calls, so there is no shared budget to protect.
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [];
    }

    public function handle(ShopBrandProfiler $profiler, IntegrationConnectionCacheRefresher $refresher, FetchBudget $budget, ShopContentWriter $content): void
    {
        // ::find() has no soft-delete scope of its own (site.shop_brands is
        // hard-delete-only) — null already covers "never existed" and "user
        // removed the brand while the job was queued".
        $brand = ShopBrand::with('connection')->find($this->brandRowId);
        if (! $brand) {
            return;
        }

        // BelongsTo applies IntegrationConnection's own SoftDeletes scope, so
        // this null check ALSO covers a parent connection soft-deleted while
        // the job was queued — no separate query needed.
        $connection = $brand->connection;
        if ($connection === null) {
            return;
        }

        // FETCH OUTSIDE THE LOCK — same discipline as ConnectFetchJob. forRow()
        // re-derives the profile from the brand's own stored url/source_url;
        // it never re-runs detection, so brand_id/provider are never touched.
        $profile = $budget->open(
            (float) config('partna.http_fetch.connect_budget_seconds', 20),
            fn () => $profiler->forRow($brand),
        );

        // Write-time availability re-check: staff can disable integration.shop
        // between the 202 and this job running. A disabled platform must never
        // land a fresh profile — terminal 'failed' instead, profile discarded.
        $user = $connection->user;
        if ($user === null || ! FeatureAvailability::for($user)->allows('integration.shop')) {
            $this->markTerminal($brand, self::UNAVAILABLE_ERROR, $content);

            return;
        }

        // SINGLE LOCKED WRITE — the SAME key ManagesIntegrationConnection::
        // withConnectionLock() uses, so this job can never race a dashboard
        // brand edit (addBrand/updateBrand/setProducts all take this lock).
        $key = CacheKeyGenerator::platformConnectionLock('shop', $connection->user_id);

        // Compare-and-set, not a blind update: with tries=3/backoff=[5,20]/
        // timeout=45, worst-case wall-clock across all attempts (~160s) can
        // exceed uniqueFor's 120s dedupe TTL. If that lock lapses mid-retry, a
        // second dispatch (a fresh addBrand() re-add, or a user-triggered
        // retry) can settle the row correctly BEFORE this stale attempt's own
        // locked write finally runs — a bare update() would then clobber that
        // newer state, worst case flipping a successful connect back to
        // 'failed'. Guarding on `connect_status = 'pending'` means a write
        // that no longer matches the state it was dispatched for is a no-op
        // instead of a regression. Builder::update() still maintains
        // updated_at via addUpdatedAtColumn() (the 5-minute stale-pending
        // backstop reads it) — do not hand-set it, and do not drop to
        // DB::table(), which would skip that.
        $settled = false;

        try {
            Cache::lock($key, 10)->block(5, function () use ($brand, $profile, &$settled) {
                // brand_id is deliberately NOT touched here — forRow() re-reads
                // the STORED url/source_url and can resolve a different id than
                // the row was keyed on (e.g. Shopify's meta.json drifting);
                // recomputing it would silently key-shift an existing row.
                $settled = ShopBrand::whereKey($brand->id)
                    ->where('connect_status', 'pending')
                    ->update([
                        'name' => $profile['name'],
                        // Coalesce, not overwrite: the deferred write may have
                        // already stored a truthful currency (Shopify carries
                        // it from the carried meta.json at 202 time — see
                        // ShopController::addBrand's currency comment).
                        // ShopBrandProfiler::forRow() degrades to null (rather
                        // than throwing) on a transient re-fetch miss, so a
                        // blind overwrite here would destroy a value that was
                        // already correct. A genuine currency CHANGE (a real
                        // new value from this fetch) still wins — only a
                        // degraded null defers to what's already on the row.
                        'currency' => $profile['currency'] ?? $brand->currency,
                        'favicon' => $profile['favicon'],
                        'logo' => $profile['logo'],
                        'connect_status' => null,
                        'connect_error' => null,
                    ]) > 0;
            });
        } catch (LockTimeoutException $e) {
            // Never $this->release(): on the sync driver (tests' driver —
            // phpunit.xml pins QUEUE_CONNECTION=sync) that only flips an
            // internal flag SyncQueue::executeJob() never checks — a silent
            // no-op that would strand this row 'pending' forever. Verbatim
            // the reasoning at ConnectFetchJob:179-214.
            report($e);
            Log::warning('shop.brand_connect_job.lock_timeout', [
                'brand_row_id' => $brand->id,
                'connection_id' => $connection->id,
                'user_id' => $connection->user_id,
            ]);
            $this->markTerminal($brand, FetchUnavailableException::STALE_CONNECT_ERROR, $content);

            return;
        }

        // Nothing changed (another writer already settled or failed this row
        // — see the compare-and-set note above) — no purge is owed.
        if (! $settled) {
            return;
        }

        // Explicit purge: the connection's payload is a frozen MARKER
        // (FOUND-25), so IntegrationConnectionObserver's wasChanged('payload')
        // gate never fires for a Shop write, and it watches IntegrationConnection
        // — never ShopBrand — so nothing else will ever purge this settle.
        $refresher->refresh($connection);

        // Task 8: mirror the settled profile onto content.* — addBrand()
        // already wrote a 'pending' content.storefronts row at 202 time (this
        // job's own success write is what the ShopController docblocks call
        // "the deferred-connect job" writing content.*). Without this, a
        // deferred connect would settle on the legacy row but stay stuck at
        // connect_status='pending'/nameless on the content.*-only read
        // endpoints until the brand's next scheduled sync.
        $content->upsertStore($brand->fresh()->toStoreRecord(), (string) $connection->user_id);
        self::bumpSiteCache((string) $connection->user_id);

        // The settle just stored the fetched favicon/logo — kick off the
        // best-effort processed mark (background removal + SVG).
        ProcessShopBrandLogoJob::dispatch((string) $brand->id);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.brand_connect_job.failed', [
            'brand_row_id' => $this->brandRowId,
            'error' => $e->getMessage(),
        ]);

        $brand = ShopBrand::find($this->brandRowId);
        if ($brand) {
            // No method-injected params reach failed() (the queue worker
            // calls it with just the exception) — container-fallback
            // resolve, same convention ShopCatalog's own nullable
            // ShopContentWriter constructor param uses.
            $this->markTerminal($brand, FetchUnavailableException::GENERIC_USER_MESSAGE, app(ShopContentWriter::class));
        }

        // Deliberately does NOT release the ShouldBeUnique dedupe lock here.
        // CallQueuedHandler::failed() already calls
        // ensureUniqueJobLockIsReleased() BEFORE it invokes this method
        // (CallQueuedHandler.php:348-350 vs :359-360), and its guard is
        // `! commandShouldBeUniqueUntilProcessing()` — true for a plain
        // ShouldBeUnique job like this one — so the lock is always released
        // on every production failure path, sync driver included. An explicit
        // release here would be dead code that implies a protection the
        // framework already provides.
    }

    /**
     * Terminal write for an expected failure — the display profile is left
     * untouched (unlike the success write above). Compare-and-set on
     * connect_status = 'pending', same reasoning as the success write: a
     * late/stale failure (e.g. this job's OWN final retry attempt, arriving
     * after a fresher dispatch already settled or failed the row) must never
     * flip an already-settled brand back to 'failed'. Builder::update() (not
     * $brand->forceFill()->saveQuietly()) — no ShopBrandObserver is
     * registered, so there is no observer behaviour to lose, and no content
     * changed on a genuine settle-already-happened no-op, so no purge is owed.
     * A 'failed' brand stays fully usable — brand_id/provider/url/source_url
     * are already truthful, so it is NOT reset back to a broken state (plan §3g).
     */
    private function markTerminal(ShopBrand $brand, string $error, ShopContentWriter $content): void
    {
        $updated = ShopBrand::whereKey($brand->id)
            ->where('connect_status', 'pending')
            ->update([
                'connect_status' => 'failed',
                'connect_error' => $error,
            ]) > 0;

        // Task 8: mirror onto content.* only on a REAL transition (the
        // compare-and-set above is a no-op otherwise) — a brand mid deferred-
        // connect has a 'pending' content.storefronts row from addBrand()
        // already; without this it would stay stuck there on the content.*-
        // only read endpoints even though the legacy row correctly moved to
        // 'failed'. $brand->connection is null for a soft-deleted parent
        // (BelongsTo respects SoftDeletes) — skip rather than resolve a
        // user id that doesn't exist.
        if ($updated && $brand->connection !== null) {
            $content->upsertStore($brand->fresh()->toStoreRecord(), (string) $brand->connection->user_id);
            self::bumpSiteCache((string) $brand->connection->user_id);
            // Final review F4: lane 3. A pending→failed transition FLIPS the
            // brand onto the public wire — PublicIntegrationConnectionResource
            // ::filterPayload() rejects only 'pending' — so the CDN is holding
            // a payload that no longer matches the origin. Container-resolved
            // rather than a fourth parameter: failed() reaches this method with
            // no injectable params at all (same reasoning as $content there).
            app(IntegrationConnectionCacheRefresher::class)->refresh($brand->connection);
        }
    }

    /**
     * Fix round 1, I5: the two cache lanes IntegrationConnectionCacheRefresher
     * doesn't own. upsertStore() is a raw DB::table() write — no model, no
     * observer — so nothing else bumps the build state or the site's
     * updated_at for it. Same discipline ShopController::bumpSiteCache() and
     * ShopBackfiller::invalidate() already apply at every other raw-write
     * seam; this job was the one that skipped it.
     *
     * Site-nullable (a fixture or a user mid-signup may have no site row):
     * skip both lanes rather than guess an id, mirroring the controller.
     */
    private static function bumpSiteCache(string $userId): void
    {
        $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->value('id');
        if ($siteId === null) {
            return;
        }

        BuildState::bump((string) $siteId);
        DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['updated_at' => now()]);
    }
}
