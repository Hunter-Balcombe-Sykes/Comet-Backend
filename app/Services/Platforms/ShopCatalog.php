<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Returns the number of products selected, or null when the catalog
     * couldn't be fetched (brand left untouched — never wipe a selection
     * because a store was temporarily unreachable).
     */
    public function syncLatest(ShopBrand $brand): ?int
    {
        try {
            $catalog = $this->providerProducts($brand->toBrandArray());
        } catch (HttpException) {
            return null;
        }

        if ($catalog === []) {
            return null;
        }

        $count = max(1, $brand->products()->count() ?: self::DEFAULT_LATEST_COUNT);

        $latest = collect($catalog)
            ->sortByDesc(fn (array $p) => $p['createdAt'] ?? '')
            ->when(
                collect($catalog)->every(fn (array $p) => ($p['createdAt'] ?? null) === null),
                // No dates at all → trust the endpoint's own ordering.
                fn ($c) => collect($catalog),
            )
            ->take($count)
            ->values();

        // Transactional rebuild — same atomicity contract as
        // ShopController::setProducts() (a mid-loop failure must not leave a
        // partial product set).
        DB::connection('pgsql')->transaction(function () use ($brand, $latest) {
            ShopProduct::where('brand_id', $brand->id)->delete();
            foreach ($latest as $index => $productData) {
                ShopProduct::create([
                    'brand_id' => $brand->id,
                    'product_id' => (string) ($productData['productId'] ?? ''),
                    'position' => $index,
                    'data' => $productData,
                ]);
            }
        });

        return $latest->count();
    }

    /** Per-brand picker-catalog cache key (shared with ShopController). */
    public function catalogKey(string $id): string
    {
        return CacheKeyGenerator::shopifyBrandCatalog($id);
    }
}
