<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\ShopBrand;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopContentWriter;
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
     * ShopBrand::toBrandArray()), dispatched by its provider.
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
    public function syncLatest(ShopBrand $brand): ?int
    {
        // #428: toBrandArray() materialises $brand->products unconditionally,
        // so this method REQUIRES that relation loaded — 5a's claim that
        // "syncLatest() no longer reads $brand->products" was never true, and
        // ShopFetch stopped eager-loading it on the strength of that claim.
        // Under Eloquent strict mode that threw LazyLoadingViolationException,
        // which neither the catch below nor ShopFetch's catches, so every
        // scheduled refresh of a MULTI-brand connection failed the job
        // outright. (Multi-brand because Builder::hydrate() only arms the
        // instance flag when a query returns more than one row — which is also
        // why no test caught it.)
        //
        // Guaranteed here rather than at the call site: ShopController already
        // passes fresh('products') and loadMissing() is a no-op for it, but a
        // caller that forgets must not be able to kill a queue job.
        $brand->loadMissing('products');

        try {
            $catalog = $this->providerProducts($brand->toBrandArray());
        } catch (HttpException $e) {
            // OBS-2: previously swallowed here as a plain `return null`, which
            // is indistinguishable from a genuinely-empty catalog. ShopFetch's
            // synced-vs-failed split (and ShopController's manual-sync path)
            // both need this to propagate so a persistently-blocked store trips
            // the circuit breaker instead of reporting healthy forever.
            Log::warning('shop.sync_latest.unreachable', [
                'brand_id' => $brand->id,
                'url' => $brand->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        if ($catalog === []) {
            return null;
        }

        $collectionId = $this->storeCollectionId($brand);

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
            (string) $brand->connection->user_id,
            $collectionId,
            $latest->all(),
            $brand->currency,
        );

        // Final review F4: lane 2. writeManualItem() bumps the build state for
        // free, and ShopFetch/updateBrand purge the edge — but nothing here
        // moved site.sites.updated_at, and IndividualProfilePayloadBuilder
        // composes its 60s cache key from exactly that column. A scheduled
        // resync therefore served the pre-sync payload for the full TTL.
        $this->touchSite((string) $brand->connection->user_id);

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
     * The brand's content.collections row (kind='storefront'), created with
     * its storefront sidecar on first sync. One implementation
     * (ShopContentWriter::upsertStore()), two callers — ShopBackfiller's
     * one-off migration and this scheduled resync.
     */
    private function storeCollectionId(ShopBrand $brand): string
    {
        return $this->content()->upsertStore($brand, (string) $brand->connection->user_id);
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
