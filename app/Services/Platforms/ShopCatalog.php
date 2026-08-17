<?php

namespace App\Services\Platforms;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Provider-dispatched product catalog reads + the latest-mode selection sync.
// Extracted from ShopController so the scheduled refresh strategy (ShopFetch)
// and the HTTP endpoints share one implementation.
class ShopCatalog
{
    // How many products latest-mode selects when the brand has none yet.
    public const DEFAULT_LATEST_COUNT = 8;

    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
        private readonly BigCartelScraper $bigcartel,
        private readonly GenericShopScraper $generic,
        // Nullable + container-fallback (content()) rather than a required
        // 6th param: ShopSyncFailureObservabilityTest constructs ShopCatalog
        // by hand with 5 scraper mocks and no ShopContentWriter, for the two
        // code paths above that return/throw before ever touching storage.
        // A required param would fail construction for those tests even
        // though they never reach the code that needs it.
        private readonly ?ShopContentWriter $content = null,
    ) {}

    /**
     * Live product catalog for a stored brand (array shape from
     * ShopContentReader::brandMap(), or dispatchShapeFor() below), dispatched
     * by its provider.
     *
     * Client-mode brands: the store blocks our egress, so a live scrape
     * usually 502s. Try it anyway (blocks get lifted), then fall back to the
     * warmed catalog, then to the already-chosen products.
     *
     * @param  array<string,mixed>  $brand
     * @return list<array<string,mixed>>
     */
    public function providerProducts(array $brand): array
    {
        if (($brand['fetchMode'] ?? null) === 'client') {
            try {
                $live = $this->woocommerce->fetchProducts($brand['url']);
                if ($live !== []) {
                    return $live;
                }
            } catch (HttpException) {
                // Fall through to the cached/stored catalog.
            }

            return Cache::get($this->catalogKey((string) $brand['id'])) ?? ($brand['products'] ?? []);
        }

        return match ($brand['provider'] ?? 'shopify') {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => $this->woocommerce->fetchProducts($brand['url']),
            ShopProviderDetector::PROVIDER_SQUARESPACE => $this->squarespace->fetchProducts($brand['sourceUrl'] ?? $brand['url']),
            ShopProviderDetector::PROVIDER_BIGCARTEL => ($account = $this->bigcartel->accountFromUrl($brand['url']))
                ? $this->bigcartel->fetchProducts($account, $brand['currency'] ?? null)
                : [],
            ShopProviderDetector::PROVIDER_GENERIC => $this->generic->fetchPage($brand['sourceUrl'] ?? $brand['url'])['products'] ?? [],
            default => $this->shopify->fetchProducts($brand['url'], $brand['currency'] ?? null),
        };
    }

    /**
     * Latest-mode sync: re-pull the brand's catalog and replace its selection
     * with the store's newest products. Count preserves the user's current
     * selection size (they chose "how many" implicitly) with a sensible
     * default for a fresh flip. Ordering: createdAt DESC when the provider
     * exposes it (Shopify), else the endpoint's own order (Woo Store API and
     * the others list newest/featured first).
     *
     * Returns the number of products selected, or null when the store was
     * REACHABLE but genuinely has no products (brand left untouched — never
     * wipe a selection over an empty catalog). A store that couldn't be
     * reached at all (blocked egress, 5xx, timeout) is a distinct signal —
     * see the HttpException re-throw below — so callers can tell "empty" from
     * "broken" instead of both collapsing into the same silent null.
     */
    public function syncLatest(StoreRecord $store, string $ownerId): ?int
    {
        // Re-home Task 7: takes the StoreRecord and its owner directly. Both
        // used to be reached through the Eloquent model — the catalogue via
        // toBrandArray() (which materialised $brand->products, the reason #428
        // needed that relation eager-loaded) and the owner via
        // $brand->connection, a lazy relation that threw
        // LazyLoadingViolationException on the strict-mode rows ShopFetch hands
        // over. Neither hazard can recur: there is no model left to lazy-load
        // from, and the caller supplies what it already knows.
        try {
            $catalog = $this->providerProducts($this->dispatchShapeFor($store, $ownerId));
        } catch (HttpException $e) {
            // OBS-2: previously swallowed here as a plain `return null`, which
            // is indistinguishable from a genuinely-empty catalog. ShopFetch's
            // synced-vs-failed split (and ShopController's manual-sync path)
            // both need this to propagate so a persistently-blocked store trips
            // the circuit breaker instead of reporting healthy forever.
            Log::warning('shop.sync_latest.unreachable', [
                'collection_id' => $store->collectionId,
                'external_ref' => $store->externalRef,
                'url' => $store->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($catalog === []) {
            return null;
        }

        // The store's content.collections row (kind='storefront'), created with
        // its storefront sidecar on first sync.
        $collectionId = $this->content()->upsertStore($store, $ownerId);

        // 5a §3.5: count-preserving selection, now sized from content.*'s
        // live link count instead of $brand->products()->count() — that
        // relation reads site.shop_products, which this method no longer
        // writes, so it would freeze at whatever it last held.
        $count = max(1, DB::table('content.collection_items')
            ->where('collection_id', $collectionId)
            ->count() ?: self::DEFAULT_LATEST_COUNT);

        $latest = collect($catalog)
            ->sortByDesc(fn (array $p) => $p['createdAt'] ?? '')
            ->when(
                collect($catalog)->every(fn (array $p) => ($p['createdAt'] ?? null) === null),
                // No dates at all → trust the endpoint's own ordering.
                fn ($c) => collect($catalog),
            )
            ->take($count)
            ->values();

        // 5a §3.5: reconcile into content.* instead of rebuilding
        // site.shop_products. syncStore() upserts by coord and retires what
        // the fetched catalogue no longer carries — never a delete+reinsert
        // — so item ids (and analytics.item_views references) survive a
        // resync. Same atomicity contract as the old transactional rebuild:
        // syncStore() runs its own writes per-item, not one big transaction,
        // but a mid-loop failure now leaves partially-reconciled content.*
        // state rather than a torn legacy table — an accepted trade already
        // made by Task 5's syncStore() implementation, not new here.
        $written = $this->content()->syncStore(
            $ownerId,
            $collectionId,
            $latest->all(),
            $store->currency,
        );

        // Final review F4: lane 2. writeManualItem() bumps the build state for
        // free, and ShopFetch/updateBrand purge the edge — but nothing here
        // moved site.sites.updated_at, and IndividualProfilePayloadBuilder
        // composes its 60s cache key from exactly that column. A scheduled
        // resync therefore served the pre-sync payload for the full TTL.
        $this->touchSite($ownerId);

        return $written;
    }

    /**
     * Lane 2 of the three-lane invalidation discipline (spec §4). Raw
     * DB::table() because syncStore()'s own writes bypass Eloquent entirely,
     * so no observer fires for them. Site-nullable — a user mid-signup has no
     * site row; skip rather than guess an id (mirrors ShopController and
     * ShopBrandConnectJob's identical helpers).
     */
    private function touchSite(string $userId): void
    {
        DB::connection('pgsql')->table('site.sites')
            ->where('user_id', $userId)
            ->update(['updated_at' => now()]);
    }

    /**
     * The {id, provider, url, sourceUrl, currency, fetchMode, products} shape
     * providerProducts() dispatches on — formerly ShopBrand::toBrandArray()
     * (re-home Task 2).
     *
     * `products` is populated ONLY for fetchMode 'client', the single branch
     * of providerProducts() that reads it (its last-resort fallback when both
     * the live fetch and the warmed cache come up empty). Every other provider
     * therefore costs no extra query at all.
     *
     * Client mode, conversely, now pays that cost on EVERY call — a
     * collectionIdFor() lookup plus a cataloguesFor() reconstruct — including
     * when the live fetch then succeeds and the value is discarded. The old
     * path read an already-hydrated $brand->products relation for free. Judged
     * an acceptable trade for deleting the legacy read: client mode is one
     * fetchMode among six, its stores are the ones that block our egress (so
     * the fallback is the common branch for them, not the rare one), and the
     * reconstruct is bounded by one store's catalogue.
     *
     * That fallback also gets more truthful in the move: it used to read
     * site.shop_products, which nothing has written since slice 5a, so it
     * served whatever was frozen there. It now reads the live catalogue.
     *
     * collectionIdFor() is deliberately the read-only lookup, NOT
     * upsertStore(): this runs BEFORE the upsert in syncLatest(), and a store
     * whose fetch then fails must not have been minted into content.* as a
     * side effect of dispatching that fetch.
     *
     * Public because ShopController::setProducts() needs the same shape for
     * its own pre-lock scrape, and building it twice is how the two would
     * drift.
     *
     * $ownerId was briefly a closure, to keep syncLatest() from resolving
     * $brand->connection before its empty-catalog early return — a lazy read on
     * a strict-mode row, #428's exact shape. Re-home Task 7 removed the model
     * from this path entirely, so the owner is a plain string the caller
     * already has and the hazard cannot recur.
     *
     * @return array<string,mixed>
     */
    public function dispatchShapeFor(StoreRecord $store, string $ownerId): array
    {
        $products = [];
        if ($store->fetchMode === 'client') {
            $collectionId = $this->content()->collectionIdFor($store, $ownerId);
            $products = $collectionId === null ? [] : $this->content()->currentCatalogue($collectionId);
        }

        return [
            'id' => $store->externalRef,
            'provider' => $store->provider,
            'url' => $store->url,
            'sourceUrl' => $store->sourceUrl,
            'currency' => $store->currency,
            'fetchMode' => $store->fetchMode,
            'products' => $products,
        ];
    }

    /** Real ShopContentWriter, or the container's when constructed without one (see the constructor note). */
    private function content(): ShopContentWriter
    {
        return $this->content ?? app(ShopContentWriter::class);
    }

    /** Per-brand picker-catalog cache key (shared with ShopController). */
    public function catalogKey(string $id): string
    {
        return CacheKeyGenerator::shopifyBrandCatalog($id);
    }
}
