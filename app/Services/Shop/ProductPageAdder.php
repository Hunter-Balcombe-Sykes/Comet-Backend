<?php

namespace App\Services\Shop;

use App\Jobs\Platforms\ConnectStoreFromProductJob;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopProviderDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Add a product by its page URL" — the ONE way in, shared by the legacy
 * POST /platforms/shop/products and the pool lane's POST
 * /content/pools/shop/items (owner, 2026-08-17: store-first).
 *
 * Reads the page (GenericShopScraper::readProductPage — JSON-LD Product/
 * Offer, OpenGraph fallback), writes the product into the owner's reserved
 * "individual products" store exactly as ShopController::addProduct always
 * did (same projection: variants, offers, gallery), and returns the content
 * item it became. Then, if the page's ORIGIN is a store we can read (Shopify
 * / WooCommerce) and the owner has not connected it, dispatches a job that
 * connects the store off-thread — its catalogue lands in the library
 * unpublished, auto-latest OFF, and because product coords are URL-keyed the
 * synced record folds onto the same item, upgrading what the page gave us
 * with the store's own variants and gallery.
 *
 * Nothing here pins: whether the product goes ON the site is the caller's
 * verb (the pool lane pins; the legacy lane never did).
 */
class ProductPageAdder
{
    public const OUTCOME_PRODUCT = 'product';

    public const OUTCOME_STORE_PAGE = 'store_page';

    public const OUTCOME_NO_PRODUCT = 'no_product';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    /** The individual-bucket cap. Source of truth; ShopProductSeeder reads it. */
    public const MAX_INDIVIDUAL_PRODUCTS = 20;

    /** Origins we connect as a store from a product paste. */
    private const CONNECTABLE_PROVIDERS = [
        ShopProviderDetector::PROVIDER_SHOPIFY,
        ShopProviderDetector::PROVIDER_WOOCOMMERCE,
    ];

    public function __construct(
        private readonly GenericShopScraper $generic,
        private readonly FetchBudget $budget,
        private readonly ShopConnections $shop,
        private readonly ShopContentWriter $content,
        private readonly IntegrationConnectionCacheRefresher $refresher,
        private readonly ShopProviderDetector $detector,
    ) {}

    /**
     * @return array{outcome: string, itemId: ?string, storeUrl: ?string, product: ?array<string, mixed>}
     */
    /**
     * @param  bool  $connectStore  probe the page's origin and connect it as a
     *                              store when it is one we can read. The pool
     *                              lane wants this; the legacy endpoint keeps
     *                              its old behaviour (a product, nothing more).
     */
    public function add(User $user, string $url, bool $connectStore = true): array
    {
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $read = $this->budget->open($seconds, fn () => $this->generic->readProductPage($url));

        if ($read['outcome'] === GenericShopScraper::OUTCOME_STORE_PAGE) {
            return ['outcome' => self::OUTCOME_STORE_PAGE, 'itemId' => null, 'storeUrl' => $read['storeUrl'], 'product' => null];
        }
        if ($read['outcome'] === GenericShopScraper::OUTCOME_UNREACHABLE) {
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'itemId' => null, 'storeUrl' => null, 'product' => null];
        }
        $product = $read['product'];
        if ($product === null) {
            return ['outcome' => self::OUTCOME_NO_PRODUCT, 'itemId' => null, 'storeUrl' => null, 'product' => null];
        }

        $itemId = Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', $user->id), 10)
            ->block(5, fn () => $this->writeIndividual($user, $product));

        if ($connectStore) {
            // Best effort, never fatal: the product is already added; a probe
            // that throws (a scraper without a fake in a test, a flaky host)
            // must not fail the add.
            try {
                $this->connectOriginIfNew($user, $url);
            } catch (\Throwable $e) {
                Log::warning('shop.connect_from_product.probe_failed', ['user_id' => $user->id, 'url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return ['outcome' => self::OUTCOME_PRODUCT, 'itemId' => $itemId, 'storeUrl' => null, 'product' => $product];
    }

    /**
     * The reserved-bucket write, verbatim from ShopController::addProduct:
     * newest first, de-duped by productId, capped, reconstructed from content.*.
     *
     * @param  array<string, mixed>  $product
     */
    private function writeIndividual(User $user, array $product): ?string
    {
        $connection = $this->shop->individualAnchor($user);
        $stores = $this->shop->stores($user);
        $maxPosition = $stores->max('position');
        $existing = $stores->get(StoreRecord::INDIVIDUAL_REF);
        $individual = $existing ?? new StoreRecord(
            externalRef: StoreRecord::INDIVIDUAL_REF,
            provider: ShopProviderDetector::PROVIDER_GENERIC,
            position: ($maxPosition === null ? -1 : $maxPosition) + 1,
            url: '',
            sourceUrl: '',
            currency: $product['currency'] ?? null,
            discountCode: '',
            isIndividual: true,
        );

        $productId = $product['productId'] ?? null;
        $collectionId = $this->content->upsertStore($individual, (string) $user->id);
        $ordered = collect($this->content->currentCatalogue($collectionId))
            ->reject(fn (array $p) => ($p['productId'] ?? null) === $productId)
            ->prepend($product)
            ->take(self::MAX_INDIVIDUAL_PRODUCTS)
            ->values();
        $this->content->syncStore((string) $user->id, $collectionId, $ordered->all(), $individual->currency);
        $this->refresher->refresh($connection);

        // The item the page became — by the same URL coord syncStore minted.
        $url = trim((string) ($product['url'] ?? ''));
        $coord = $url !== ''
            ? ShopProductProjection::coordFor($url)
            : ShopProductProjection::coordForProductId($collectionId, (string) $productId);

        $itemId = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $user->id)
            ->where('cs.kind', 'manual')
            ->where('si.coord', $coord)
            ->value('si.item_id');

        return $itemId === null ? null : (string) $itemId;
    }

    /**
     * Connect the page's store off-thread when it is a Shopify/Woo origin the
     * owner has not connected. Detection is a fetch, so it runs OUTSIDE the
     * lock; the job takes the lock again for its own write.
     */
    private function connectOriginIfNew(User $user, string $url): void
    {
        $origin = $this->originOf($url);
        if ($origin === null) {
            return;
        }
        $known = $this->shop->stores($user)->first(
            fn (StoreRecord $store): bool => $store->url !== null && $this->originOf($store->url) === $origin
        );
        if ($known !== null) {
            return;
        }
        $detected = $this->detector->detectDetailed($origin)['detected'] ?? null;
        if ($detected === null || ! in_array($detected['provider'], self::CONNECTABLE_PROVIDERS, true)) {
            return;
        }
        ConnectStoreFromProductJob::dispatch((string) $user->id, $detected)->afterCommit();
    }

    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return strtolower(($parts['scheme'] ?? 'https').'://'.$parts['host']);
    }
}
