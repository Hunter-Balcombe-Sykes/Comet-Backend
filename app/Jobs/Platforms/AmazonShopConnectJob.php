<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\AmazonShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ScrapeCreators\AmazonShopNormalizer;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Item 10b (2026-09-01): connect an Amazon influencer storefront
 * (amazon.com/shop/<handle>) as a shop-pool store. One vendor call answers
 * identity + trendingPicks; the picks land through the pool's ordinary
 * door — ShopContentWriter::syncStore() under a StoreRecord — so nothing
 * downstream learns a new shape. Re-dispatch for an already-connected
 * handle is a REFRESH: the record folds onto what content.* already holds
 * (never blanking user edits) and the catalogue reconciles.
 *
 * A vendor miss of any kind (budget denied, transport, billed husk, empty
 * storefront) is a NO-OP — no store row minted, no syncStore([]) — because
 * this lane must never be the reason a shop reads as empty, and syncStore's
 * retire-absent would read an empty input as "every product left".
 *
 * PROVIDER DECISION (unit brief: "new provider const vs PROVIDER_GENERIC,
 * by reading StoreRecord's consumers"): a NEW provider, 'amazon-shop'
 * (AmazonShopScraper::PROVIDER). Consumer by consumer:
 *   - content.storefronts identity is (user_id, provider, external_ref);
 *     this lane's external_ref is amazon's own storefront handle, a
 *     namespace PROVIDER_GENERIC's origin-derived ids don't share — a
 *     distinct provider keeps the unique index honest.
 *   - ShopCatalog::providerProducts() dispatches on provider; GENERIC would
 *     route every scheduled resync through GenericShopScraper against
 *     amazon.com, which bot-blocks the read (the documented reason Amazon
 *     sat in WebsiteLinkHarvester::LINK_ONLY_HOSTS) — a guaranteed
 *     HttpException per cycle. Until the spine adds an 'amazon-shop' match
 *     arm, an unknown provider falls to the shopify default there, so this
 *     job keeps its stores out of that path: the anchor is minted with
 *     auto-latest OFF (ShopConnections::anchor's own mint rule) and refresh
 *     is re-dispatching THIS job. The arm is reported as a spine entry.
 *   - ShopConnections::surfaceFor() answers 'generic.store' for an unknown
 *     provider by design ("a store we can read but cannot name") — the
 *     wire surface is presentational, so riding that fallback is safe; a
 *     dedicated PROVIDER_SURFACE line is reported for the spine regardless.
 *
 * CAPABILITIES: AccountCapabilities consulted (2026-09-01) — no capability
 * differs for the shop pool (both account types connect stores), so the
 * gate here is FeatureAvailability's 'integration.shop' kill switch, the
 * exact gate every shop-lane job runs (ConnectStoreFromProductJob,
 * ShopBrandConnectJob). Never a branch on account_type.
 *
 * Timeouts mirror ShopInitialFillJob, not ShopBrandConnectJob: this job
 * does the whole catalogue fill (16 recorded picks) plus the auto-select,
 * the workload measured at 70-80s for 7 products against the remote dev DB
 * (T5/T7, 2026-08-20). uniqueFor > timeout, the HorizonQueueCoverageTest
 * rule, so a unique job cannot outrun its own dedupe lock mid-run.
 */
class AmazonShopConnectJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> Seconds. */
    public array $backoff = [10];

    public int $timeout = 240;

    public int $uniqueFor = 300;

    /** The store cap, from `partna.shop_brands_max` — the ONE definition (#CFG-3). */
    private static function maxBrands(): int
    {
        return (int) config('partna.shop_brands_max');
    }

    public function __construct(
        public readonly string $userId,
        public readonly string $storefrontUrl,
    ) {
        $this->onQueue(config('partna.queues.platform_connect'));
    }

    public function uniqueId(): string
    {
        return 'amazon-shop-connect:'.$this->userId.':'.sha1(trim($this->storefrontUrl));
    }

    public function handle(
        AmazonShopScraper $scraper,
        AmazonShopNormalizer $normalizer,
        ShopConnections $shop,
        ShopContentWriter $content,
        ShopAutoSelector $selector,
        IntegrationConnectionCacheRefresher $refresher,
    ): void {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }
        if (! FeatureAvailability::for($user)->allows('integration.shop')) {
            return;
        }

        $handle = AmazonShopScraper::handleFromUrl($this->storefrontUrl);
        if ($handle === null) {
            Log::info('shop.amazon_shop_connect.unrecognized_url', [
                'user_id' => $user->id, 'url' => $this->storefrontUrl,
            ]);

            return;
        }

        // PRE-FLIGHT, before the billed call: a NEW store that has nowhere
        // to land must not spend a vendor credit discovering that. Re-checked
        // under the lock below — this read is about not billing, that one is
        // about not racing.
        if ($this->existingStore($shop, $user, $handle) === null && $this->capReached($shop, $user)) {
            Log::info('shop.amazon_shop_connect.cap_reached', ['user_id' => $user->id, 'handle' => $handle]);

            return;
        }

        // FETCH OUTSIDE THE LOCK — same discipline as ShopBrandConnectJob.
        // Budget claim/release/husk mechanics live in the scraper.
        $page = $scraper->fetchStorefront($handle, (string) $user->id);
        if ($page === null) {
            // The no-op contract: an already-connected store keeps its
            // catalogue, an unconnected handle mints nothing.
            Log::info('shop.amazon_shop_connect.no_answer', ['user_id' => $user->id, 'handle' => $handle]);

            return;
        }

        // SINGLE LOCKED WRITE — the same key every dashboard store edit
        // takes (addBrand/updateBrand/setProducts), so the mint can never
        // race one. Only the record write sits inside; the per-product sync
        // below runs outside (it is the slow half — ShopInitialFillJob's
        // own reason for a 240s ceiling).
        $key = CacheKeyGenerator::platformConnectionLock('shop', $user->id);

        try {
            /** @var array{0: string, 1: StoreRecord}|null $minted */
            $minted = Cache::lock($key, 10)->block(5, function () use ($shop, $content, $user, $handle, $page): ?array {
                $existing = $this->existingStore($shop, $user, $handle);
                if ($existing === null && $this->capReached($shop, $user)) {
                    Log::info('shop.amazon_shop_connect.cap_reached', ['user_id' => $user->id, 'handle' => $handle]);

                    return null;
                }

                $shop->anchor($user, AmazonShopScraper::PROVIDER, $handle);
                $record = $this->record($shop, $user, $existing, $handle, $page);

                return [$content->upsertStore($record, (string) $user->id), $record];
            });
        } catch (LockTimeoutException $e) {
            // Never $this->release(): a silent no-op on the sync driver
            // (ShopBrandConnectJob's documented trap). The credit is spent
            // either way; the owner's retry re-dispatches cleanly.
            report($e);
            Log::warning('shop.amazon_shop_connect.lock_timeout', ['user_id' => $user->id, 'handle' => $handle]);

            return;
        }

        if ($minted === null) {
            return;
        }
        [$collectionId, $record] = $minted;

        $written = $content->syncStore(
            (string) $user->id,
            $collectionId,
            $normalizer->products($page),
            $record->currency,
        );

        // First-connect auto-select, best-effort like ShopInitialFillJob:
        // selectInitial() is CAS-stamped (at most once per store, ever) and
        // carries its own Item 12 publish gates.
        try {
            $selector->selectInitial($collectionId);
        } catch (Throwable $e) {
            report($e);
            Log::warning('shop.amazon_shop_connect.auto_select_failed', [
                'collection_id' => $collectionId, 'error' => $e->getMessage(),
            ]);
        }

        // The two cache lanes syncStore()'s raw writes don't own: the edge
        // payload behind the connection marker, and the site's updated_at
        // (IndividualProfilePayloadBuilder's 60s cache key) — the same pair
        // ShopCatalog::syncLatest() + ShopFetch settle between them.
        $connection = $shop->anchorFor($user, $handle);
        if ($connection !== null) {
            $refresher->refresh($connection);
        }
        $this->touchSite((string) $user->id);

        Log::info('shop.amazon_shop_connect.synced', [
            'user_id' => $user->id, 'handle' => $handle,
            'collection_id' => $collectionId, 'written' => $written,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('shop.amazon_shop_connect.failed', [
            'user_id' => $this->userId,
            'url' => $this->storefrontUrl,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * This lane's store for $handle, or null. Provider-checked: stores()
     * keys on external_ref across the whole family, and folding onto some
     * other provider's store that happens to share the ref would key-shift
     * it (the exact hazard ShopBrandConnectJob::settle refuses).
     */
    private function existingStore(ShopConnections $shop, User $user, string $handle): ?StoreRecord
    {
        $store = $shop->stores($user)->get($handle);

        return $store !== null && $store->provider === AmazonShopScraper::PROVIDER ? $store : null;
    }

    private function capReached(ShopConnections $shop, User $user): bool
    {
        $count = $shop->stores($user)->filter(fn (StoreRecord $s): bool => $s->isIndividual === false)->count();

        return $count >= self::maxBrands();
    }

    /**
     * The record upsertStore() writes — minted settled (connect_status
     * null), because unlike the deferred addBrand() lane the catalogue is
     * already in hand; there is nothing left for a pending state to wait on.
     *
     * The refresh path folds onto the EXISTING record (upsertStore writes
     * every column unconditionally — StoreRecord::with()'s own warning):
     * a user's rename and a corrected currency both survive; only fields
     * this lane genuinely owns (url, a still-unset name/logo) move.
     *
     * @param  array{products: non-empty-list<array<string, mixed>>, name?: string, avatar?: string}  $page
     */
    private function record(ShopConnections $shop, User $user, ?StoreRecord $existing, string $handle, array $page): StoreRecord
    {
        $url = AmazonShopScraper::storefrontUrlFor($handle);

        if ($existing !== null) {
            return $existing->with([
                'name' => $existing->name ?? ($page['name'] ?? null),
                'url' => $url,
                'sourceUrl' => $url,
                'currency' => $existing->currency ?? AmazonShopScraper::CURRENCY,
                'logoUrl' => $existing->logoUrl ?? ($page['avatar'] ?? null),
                'connectStatus' => null,
                'connectError' => null,
            ]);
        }

        $maxPosition = $shop->stores($user)->max('position');

        return new StoreRecord(
            externalRef: $handle,
            provider: AmazonShopScraper::PROVIDER,
            name: $page['name'] ?? null,
            position: ($maxPosition === null ? -1 : $maxPosition) + 1,
            url: $url,
            sourceUrl: $url,
            // amazon.com implies USD — the payload carries no currency, so
            // the store row owns the decision (fromBlob's $storeCurrency).
            currency: AmazonShopScraper::CURRENCY,
            discountCode: '',
            referralQuery: '',
            isIndividual: false,
            fetchMode: null,
            connectStatus: null,
            connectError: null,
            // The storefront avatar is the store's display mark — this lane
            // has no favicon/logo scrape to defer to ShopBrandProfiler.
            logoUrl: $page['avatar'] ?? null,
        );
    }

    /**
     * Lane 2 of the invalidation discipline — raw write, no observer fires
     * for it. Site-nullable: a user mid-signup has no site row; skip rather
     * than guess (mirrors ShopCatalog::touchSite verbatim).
     */
    private function touchSite(string $userId): void
    {
        DB::connection('pgsql')->table('site.sites')
            ->where('user_id', $userId)
            ->update(['updated_at' => now()]);
    }
}
