<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\StoreRecord;
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

// W9 §4 Unit 3 — completes a `pending` store that addBrand() wrote
// synchronously (external_ref/provider/url/source_url all already truthful) by
// fetching the display profile (name/currency/favicon/logo) ShopBrandProfiler
// ::forRow() defers for shopify/woocommerce/squarespace.
//
// Re-home Task 9: the row it completes is content.storefronts, not
// site.shop_brands, and it is dispatched with that store's collection id.
// (The original comment said it "Lands INERT — nothing dispatches this job
// yet"; ShopController::addBrand has dispatched it since W9 Unit 4.)
//
// Deliberately its OWN job rather than a generic-registry lookup like
// ConnectFetchJob: Shop is the only platform where one user fans out to up to
// MAX_BRANDS=5 stores, so uniqueness/failure state has to key on the STORE, not
// the connection — see uniqueId() below.
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
    public int $timeout = 75;

    // Short on purpose — a user who retries within two minutes must get a
    // fresh job, not be silently swallowed by a cron-length dedupe window.
    public int $uniqueFor = 120;

    public int $maxExceptions = 2;

    // Mirrors ManagesIntegrationConnection::assertPlatformAvailable()'s
    // abort(503) message verbatim — that method is private and abort()-shaped,
    // meaningless in a worker, so the underlying FeatureAvailability rule is
    // called directly (below) and this is the same sentence it would have used.
    private const UNAVAILABLE_ERROR = 'This integration is currently unavailable.';

    /**
     * Re-home Task 9: the content.collections id, NOT the site.shop_brands uuid
     * PK this job used to carry. That uuid has no content.* twin — the
     * storefront is keyed by collection_id and identity is
     * (user_id, provider, external_ref) — so the collection id is the natural
     * substitute: one per store, stable across a rename, and already the
     * storefront's own primary key (spec §5).
     */
    public function __construct(
        public readonly string $collectionId,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    /**
     * Keyed on the STORE, not "{platform}:{connectionId}" like ConnectFetchJob.
     * Shop is the only platform where one user fans out to up to 5 stores — a
     * connection-keyed uniqueId() would collapse two stores added within
     * uniqueFor() seconds into one job and strand the second in 'pending'
     * forever.
     */
    public function uniqueId(): string
    {
        return "shop-store:{$this->collectionId}";
    }

    // Deliberately NO RateLimited middleware — same reasoning as
    // ConnectFetchJob::middleware(): Shop's fetches are keyless public
    // scrapes, not paid-actor calls, so there is no shared budget to protect.
    /** @return array<int, object> */
    public function middleware(): array
    {
        return [];
    }

    public function handle(ShopBrandProfiler $profiler, IntegrationConnectionCacheRefresher $refresher, FetchBudget $budget, ShopConnections $shop): void
    {
        // Re-home Task 9: resolved off content.* by collection id. null already
        // covers "never existed" and "the user removed the store while this sat
        // in the queue" — retireStore() deletes the storefront row.
        $store = $shop->storeByCollection($this->collectionId);
        if ($store === null || $store->userId === null) {
            return;
        }

        $user = User::find($store->userId);
        if ($user === null) {
            return;
        }

        // The anchor is still needed for the availability re-check and the
        // cache purge — content.storefronts carries no connection linkage, so
        // it is looked up by resource_id, which IS the store id. null covers a
        // connection soft-deleted while this job was queued, the case the old
        // BelongsTo null-check covered for free.
        $connection = $shop->anchorFor($user, $store->externalRef);
        if ($connection === null) {
            return;
        }

        // FETCH OUTSIDE THE LOCK — same discipline as ConnectFetchJob. forRow()
        // re-derives the profile from the store's own url/source_url; it never
        // re-runs detection, so external_ref/provider are never touched.
        $profile = $budget->open(
            (float) config('partna.http_fetch.connect_budget_seconds', 20),
            fn () => $profiler->forRow($store),
        );

        // Write-time availability re-check: staff can disable integration.shop
        // between the 202 and this job running. A disabled platform must never
        // land a fresh profile — terminal 'failed' instead, profile discarded.
        if (! FeatureAvailability::for($user)->allows('integration.shop')) {
            $this->markTerminal($store, self::UNAVAILABLE_ERROR, $connection);

            return;
        }

        // SINGLE LOCKED WRITE — the SAME key ManagesIntegrationConnection::
        // withConnectionLock() uses, so this job can never race a dashboard
        // store edit (addBrand/updateBrand/setProducts all take this lock).
        $key = CacheKeyGenerator::platformConnectionLock('shop', $store->userId);

        // Compare-and-set, not a blind write: with tries=3/backoff=[5,20]/
        // timeout=45, worst-case wall-clock across all attempts (~160s) can
        // exceed uniqueFor's 120s dedupe TTL. If that lock lapses mid-retry, a
        // second dispatch (a fresh addBrand() re-add, or a user-triggered
        // retry) can settle the store correctly BEFORE this stale attempt's own
        // locked write finally runs — a bare update would then clobber that
        // newer state, worst case flipping a successful connect back to
        // 'failed'. Guarding on `connect_status = 'pending'` means a write that
        // no longer matches the state it was dispatched for is a no-op instead
        // of a regression.
        //
        // This is why the settle is a GUARDED UPDATE and not upsertStore():
        // that method is an unconditional upsert returning a collection id, and
        // has no way to express the guard or to report whether a row actually
        // moved. Same direct-write seam updateBrand()/setProducts() already use
        // for products_curated_at.
        $settled = false;

        try {
            Cache::lock($key, 10)->block(5, function () use ($store, $profile, &$settled) {
                $settled = $this->settle($store, $profile);
            });
        } catch (LockTimeoutException $e) {
            // Never $this->release(): on the sync driver (tests' driver —
            // phpunit.xml pins QUEUE_CONNECTION=sync) that only flips an
            // internal flag SyncQueue::executeJob() never checks — a silent
            // no-op that would strand this store 'pending' forever. Verbatim
            // the reasoning at ConnectFetchJob:179-214.
            report($e);
            Log::warning('shop.brand_connect_job.lock_timeout', [
                'collection_id' => $this->collectionId,
                'connection_id' => $connection->id,
                'user_id' => $store->userId,
            ]);
            $this->markTerminal($store, FetchUnavailableException::STALE_CONNECT_ERROR, $connection);

            return;
        }

        // Nothing changed (another writer already settled or failed this store
        // — see the compare-and-set note above) — no purge is owed.
        if (! $settled) {
            return;
        }

        // Explicit purge: the connection's payload is a frozen MARKER
        // (FOUND-25), so IntegrationConnectionObserver's wasChanged('payload')
        // gate never fires for a Shop write, and it watches IntegrationConnection
        // — never content.* — so nothing else will ever purge this settle.
        $refresher->refresh($connection);
        self::bumpSiteCache($store->userId);

        // THE INITIAL CATALOGUE FILL (2026-08-17, Sell opt-in regression
        // caught live: a fresh connect ended with ZERO products). The
        // catalogue used to arrive via the scheduled ShopFetch, which is
        // gated on auto_sync_latest — and anchors mint with that OFF now, so
        // without this one-shot the library stays empty and the product
        // picker has nothing to pick. This is a FILL, not auto-latest: it
        // runs once regardless of the toggle, and nothing publishes — shop
        // runs pins + the (toggle-gated) latest rule at read time.
        // Best-effort: a blocked store is already a usable settle (the brand
        // is named), and the fetch gate/circuit machinery owns retries.
        try {
            app(ShopCatalog::class)->syncLatest(
                $shop->storeByCollection($this->collectionId) ?? $store,
                (string) $store->userId,
            );
        } catch (Throwable $e) {
            Log::warning('shop.brand_connect_job.initial_fill_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }

        // First-connect Sell seeding (owner spec, 2026-08-20): the fill above
        // put products in the library; if the owner has never chosen any for
        // this store, pin up to 5 of the newest — once, ever. Best-effort for
        // the same reason as the fill: the connect itself is already settled.
        try {
            app(ShopAutoSelector::class)->selectInitial($this->collectionId);
        } catch (Throwable $e) {
            Log::warning('shop.brand_connect_job.auto_select_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }

        // The settle just stored the fetched favicon/logo — kick off the
        // best-effort processed mark (background removal + SVG).
        ProcessShopBrandLogoJob::dispatch($this->collectionId);
    }

    /**
     * The success write, on content.* — compare-and-set on
     * connect_status = 'pending' (see the caller for why), returning whether a
     * row actually moved.
     *
     * external_ref/provider are deliberately NOT touched: forRow() re-reads the
     * STORED url/source_url and can resolve a different id than the store was
     * keyed on (e.g. Shopify's meta.json drifting); recomputing identity here
     * would silently key-shift a live store.
     *
     * updated_at is set by hand because this is a query-builder write on a raw
     * table, where Eloquent's Builder::update() would have maintained it. It is
     * load-bearing: connectStatus()'s 5-minute stale-pending backstop reads
     * exactly this column now (spec §12.2).
     *
     * @param  array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}  $profile
     */
    private function settle(StoreRecord $store, array $profile): bool
    {
        $moved = DB::table('content.storefronts')
            ->where('collection_id', $this->collectionId)
            ->where('connect_status', 'pending')
            ->update([
                // Coalesce, not overwrite: the deferred write may have already
                // stored a truthful currency (Shopify carries it from the
                // carried meta.json at 202 time — see ShopController::addBrand's
                // currency comment). ShopBrandProfiler::forRow() degrades to
                // null (rather than throwing) on a transient re-fetch miss, so a
                // blind overwrite here would destroy a value that was already
                // correct. A genuine currency CHANGE still wins — only a
                // degraded null defers to what is already stored.
                'currency' => $profile['currency'] ?? $store->currency,
                'favicon_url' => $profile['favicon'],
                'logo_url' => $profile['logo'],
                'connect_status' => null,
                'connect_error' => null,
                'updated_at' => now(),
            ]) > 0;

        if (! $moved) {
            return false;
        }

        // The display name lives on the parent collection, not the storefront.
        // `label` is NOT NULL and upsertStore() writes `name ?? externalRef`
        // into it, which ShopContentReader nulls back out on read — so falling
        // back to externalRef here mirrors the legacy write of a null name
        // exactly, rather than inventing a display name.
        DB::table('content.collections')
            ->where('id', $this->collectionId)
            ->update(['label' => $profile['name'] ?? $store->externalRef, 'updated_at' => now()]);

        return true;
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.brand_connect_job.failed', [
            'collection_id' => $this->collectionId,
            'error' => $e->getMessage(),
        ]);

        // No method-injected params reach failed() (the queue worker calls it
        // with just the exception) — container-fallback resolve, the same
        // convention ShopCatalog's own nullable constructor param uses.
        $shop = app(ShopConnections::class);
        $store = $shop->storeByCollection($this->collectionId);
        if ($store === null || $store->userId === null) {
            return;
        }

        $user = User::find($store->userId);
        $connection = $user === null ? null : $shop->anchorFor($user, $store->externalRef);
        if ($connection === null) {
            return;
        }

        $this->markTerminal($store, FetchUnavailableException::GENERIC_USER_MESSAGE, $connection);

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
     * after a fresher dispatch already settled or failed the store) must never
     * flip an already-settled store back to 'failed'. No purge is owed on a
     * genuine settle-already-happened no-op, which is why the refresh below
     * sits behind the row count. A 'failed' store stays fully usable —
     * external_ref/provider/url/source_url are already truthful, so it is NOT
     * reset back to a broken state (plan §3g).
     */
    private function markTerminal(StoreRecord $store, string $error, IntegrationConnection $connection): void
    {
        $moved = DB::table('content.storefronts')
            ->where('collection_id', $this->collectionId)
            ->where('connect_status', 'pending')
            ->update([
                'connect_status' => 'failed',
                'connect_error' => $error,
                'updated_at' => now(),
            ]) > 0;

        if (! $moved) {
            return;
        }

        self::bumpSiteCache((string) $store->userId);

        // Final review F4: lane 3. A pending→failed transition FLIPS the store
        // onto the public wire — PublicIntegrationConnectionResource
        // ::filterPayload() rejects only 'pending' — so the CDN is holding a
        // payload that no longer matches the origin. Container-resolved rather
        // than a fourth parameter: failed() reaches this method with no
        // injectable params at all.
        app(IntegrationConnectionCacheRefresher::class)->refresh($connection);
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
