<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddShopBrandRequest;
use App\Http\Requests\Platforms\AddShopProductRequest;
use App\Http\Requests\Platforms\SetShopProductsRequest;
use App\Http\Requests\Platforms\SubmitShopCatalogRequest;
use App\Http\Requests\Platforms\UpdateShopBrandRequest;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\Payloads\ShopPayload;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

// PROVIDER-AGNOSTIC shop endpoints (formerly ShopifyController) — MULTI-BRAND.
// A user connects up to 5 stores by URL alone; ShopProviderDetector works out
// whether each one is Shopify, WooCommerce, Squarespace, Big Cartel, or a
// generic storefront with Product JSON-LD — the user never chooses. Each brand carries its provider,
// profile (name/favicon/logo), discount code, and chosen products, stored as
// one brand-keyed map on the single 'shop' connection row.
//
// Product selection is decoupled from connect: adding a brand stores it with
// zero products; the picker (GET .../products + PUT .../selection) runs any time.
//
// GET /selection returns a COMPAT flat view of the primary brand so partna-pages
// keeps rendering the Shop card until the skeleton is reworked for multi-brand.
class ShopController extends ApiController
{
    use JitteredTtl;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_BRANDS = 5;

    // Reserved brand bucket holding individually-added products (not tied to a
    // connected store). Doesn't count against MAX_BRANDS.
    private const INDIVIDUAL_BRAND_ID = 'individual';

    private const MAX_INDIVIDUAL_PRODUCTS = 20;

    // How long the picker-warmed product catalog stays cached, so a PUT
    // /selection right after the picker opened reuses it instead of re-scraping.
    private const CATALOG_TTL_MINUTES = 10;

    public function __construct(
        private readonly ShopProviderDetector $detector,
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
        private readonly BigCartelScraper $bigcartel,
        private readonly GenericShopScraper $generic,
    ) {}

    protected function platform(): string
    {
        return 'shop';
    }

    // GET /api/platforms/shop/brands — all connected brands.
    public function brands(Request $request): JsonResponse
    {
        return $this->success([
            'brands' => ShopBrandResource::collection($this->allBrands($this->currentUser($request)))->resolve(),
        ]);
    }

    // POST /api/platforms/shop/brands — add (or refresh) a brand. Detects the
    // provider, resolves the brand profile, dedups by canonical id, caps at
    // MAX_BRANDS, stores with the brand's existing products preserved.
    public function addBrand(AddShopBrandRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        // Detection + scrape run outside the lock (slow external HTTP).
        // Only the read→mutate→write cycle is serialised.
        $detected = $this->detector->detect($validated['url']);

        // Server-side probe failed AND the dashboard supplied a browser-fetched
        // Store API payload — stores whose WAF blocks datacenter IPs (403s every
        // server request) still connect via the user's own browser.
        if ($detected === null && is_array($validated['storeApi'] ?? null)) {
            $detected = $this->detectFromClientPayload($validated['url'], $validated['storeApi']);
        }

        if ($detected === null) {
            return $this->error(
                "Couldn't connect that as a store — we look for Shopify, WooCommerce, or standard product markup on the page. Some sites block automated requests. You can still add individual products from any store: paste a product's own page URL instead.",
                422,
            );
        }

        [$brand, $detectedProducts] = $this->brandProfileFor($detected);
        $id = $brand['id'];

        return $this->withConnectionLock($user, function () use ($user, $validated, $detected, $brand, $detectedProducts, $id) {
            $map = $this->brandMap($user);
            // The reserved individual-products bucket doesn't occupy a store slot.
            $storeCount = count(array_diff_key($map, [self::INDIVIDUAL_BRAND_ID => true]));
            if (! isset($map[$id]) && $storeCount >= self::MAX_BRANDS) {
                return $this->error('You can connect up to '.self::MAX_BRANDS.' stores.', 422);
            }

            $discount = array_key_exists('discountCode', $validated)
                ? trim((string) $validated['discountCode'])
                : ($map[$id]['discountCode'] ?? '');

            $map[$id] = [
                'id' => $id,
                'provider' => $detected['provider'],
                'url' => $detected['origin'],
                'sourceUrl' => $detected['sourceUrl'],
                'name' => $brand['name'],
                'currency' => $brand['currency'] ?? null,
                'favicon' => $brand['favicon'],
                'logo' => $brand['logo'],
                'discountCode' => $discount,
                'products' => $map[$id]['products'] ?? [],
            ];
            // Client-connected brands can't be re-scraped server-side; the
            // flag routes product reads through the catalog cache + the
            // client re-warm endpoint instead of abort(502)ing.
            if (! empty($detected['fetchMode'])) {
                $map[$id]['fetchMode'] = $detected['fetchMode'];
            }
            $this->writeConnection($user, $map);

            // The generic detector already read the page's products — warm the
            // picker catalog so the immediately-following GET is instant.
            if ($detectedProducts !== null) {
                Cache::put($this->catalogKey($id), $detectedProducts, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));
            }

            return $this->success((new ShopBrandResource($map[$id]))->resolve());
        });
    }

    // PATCH /api/platforms/shop/brands/{id} — update the discount code.
    public function updateBrand(UpdateShopBrandRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $map = $this->brandMap($user);
            if (! isset($map[$id])) {
                return $this->error('Brand not found.', 404);
            }

            $map[$id]['discountCode'] = trim((string) $validated['discountCode']);
            $this->writeConnection($user, $map);

            return $this->success((new ShopBrandResource($map[$id]))->resolve());
        });
    }

    // DELETE /api/platforms/shop/brands/{id} — remove a brand.
    public function removeBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id) {
            $map = $this->brandMap($user);
            unset($map[$id]);
            $this->writeConnection($user, $map);

            return $this->success(['brands' => ShopBrandResource::collection(array_values($map))->resolve()]);
        });
    }

    // POST /api/platforms/shop/brands/{id}/catalog — client-assisted catalog
    // re-warm for a client-mode brand: the browser fetched the store's public
    // Store API and posts the raw products; we normalise + host-pin them and
    // warm the same picker cache the live scrape would have.
    public function catalog(SubmitShopCatalogRequest $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = $this->woocommerce->productsFromClient($map[$id]['url'], $request->validated()['products']);
        if ($products === []) {
            return $this->error('No products from this store were found in that payload.', 422);
        }

        Cache::put($this->catalogKey($id), $products, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));

        return $this->success(['products' => $products]);
    }

    // GET /api/platforms/shop/brands/{id}/products — live products for the picker.
    // Cache::remember short-circuits the live scrape when the cache was already
    // warmed by addBrand or a recent GET, so re-opening the picker within the
    // 10-min window is instant and doesn't re-scrape the upstream store.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = Cache::remember(
            $this->catalogKey($id),
            self::applyJitter(self::CATALOG_TTL_MINUTES * 60),
            fn () => $this->providerProducts($map[$id]),
        );

        return $this->success(['products' => $products]);
    }

    // PUT /api/platforms/shop/brands/{id}/selection — snapshot the chosen
    // products (re-fetched live, order preserved). Callable any time.
    public function setProducts(SetShopProductsRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $map = $this->brandMap($user);
            if (! isset($map[$id])) {
                return $this->error('Brand not found.', 404);
            }

            // Prefer the catalog the picker just warmed; only re-scrape if it
            // has gone cold (a save long after the picker was opened).
            $catalog = Cache::get($this->catalogKey($id))
                ?? $this->providerProducts($map[$id]);
            $all = collect($catalog)->keyBy('productId');
            $map[$id]['products'] = collect($validated['productIds'])
                ->map(fn (string $pid) => $all->get($pid))
                ->filter()
                ->values()
                ->all();
            $this->writeConnection($user, $map);
            // Invalidate the picker catalog so a subsequent GET re-scrapes the
            // store instead of serving the pre-selection snapshot for up to 10 min.
            Cache::forget($this->catalogKey($id));

            return $this->success((new ShopBrandResource($map[$id]))->resolve());
        });
    }

    // GET /api/platforms/shop/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products.
    public function selection(Request $request): JsonResponse
    {
        $primary = ShopPayload::fromArray($this->readConnection($this->currentUser($request)))->primaryWithProducts();

        $selection = $primary ? [
            'url' => $primary['url'],
            'provider' => $primary['provider'] ?? 'shopify',
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/shop — clear all brands.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['brands' => []]);
    }

    // POST /api/platforms/shop/products — add a single product by URL, not tied
    // to a connected store (mirrors standalone events). Scraped from its own
    // page (JSON-LD → OpenGraph) and kept in the reserved 'individual' bucket.
    public function addProduct(AddShopProductRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $product = $this->generic->fetchSingleProduct($request->validated()['url']);
        if ($product === null) {
            return $this->error(
                "Couldn't read a product from that link. Open the product's own page and paste its URL — it needs a title and price in standard product markup.",
                422,
            );
        }

        return $this->withConnectionLock($user, function () use ($user, $product) {
            $map = $this->brandMap($user);
            $brand = $map[self::INDIVIDUAL_BRAND_ID] ?? [
                'id' => self::INDIVIDUAL_BRAND_ID,
                'provider' => ShopProviderDetector::PROVIDER_GENERIC,
                'url' => '',
                'sourceUrl' => '',
                'name' => null,
                'currency' => $product['currency'] ?? null,
                'favicon' => null,
                'logo' => null,
                'discountCode' => '',
                'individual' => true,
                'products' => [],
            ];

            // Newest first, de-duped by productId, capped.
            $brand['products'] = collect($brand['products'] ?? [])
                ->reject(fn ($p) => ($p['productId'] ?? null) === $product['productId'])
                ->prepend($product)
                ->take(self::MAX_INDIVIDUAL_PRODUCTS)
                ->values()
                ->all();
            $map[self::INDIVIDUAL_BRAND_ID] = $brand;
            $this->writeConnection($user, $map);

            return $this->success((new ShopBrandResource($map[self::INDIVIDUAL_BRAND_ID]))->resolve());
        });
    }

    // DELETE /api/platforms/shop/products/{productId} — remove one individual
    // product; drops the reserved bucket entirely once it's empty.
    public function removeProduct(Request $request, string $productId): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $productId) {
            $map = $this->brandMap($user);
            $brand = $map[self::INDIVIDUAL_BRAND_ID] ?? null;
            if ($brand === null) {
                return $this->error('Product not found.', 404);
            }

            $brand['products'] = collect($brand['products'] ?? [])
                ->reject(fn ($p) => ($p['productId'] ?? null) === $productId)
                ->values()
                ->all();

            if ($brand['products'] === []) {
                unset($map[self::INDIVIDUAL_BRAND_ID]);
            } else {
                $map[self::INDIVIDUAL_BRAND_ID] = $brand;
            }
            $this->writeConnection($user, $map);

            return $this->success(['brands' => ShopBrandResource::collection(array_values($map))->resolve()]);
        });
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Resolve the brand profile (and, for generic pages, the products that
     * came with it) for a freshly-detected store.
     *
     * @param  array{provider:string, origin:string, sourceUrl:string, page:array|null}  $detected
     * @return array{0: array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}, 1: ?array}
     */
    private function brandProfileFor(array $detected): array
    {
        // Client-assisted detection already carries the brand + products the
        // browser fetched — no server round-trips (they'd be blocked anyway).
        if (isset($detected['clientBrand'])) {
            return [$detected['clientBrand'], $detected['clientProducts']];
        }

        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'], $detected['page']['products']],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
    }

    /**
     * Server-probe-failed fallback: build a detection result from the
     * browser-fetched Store API payload. Null when the payload contains no
     * products actually belonging to the pasted store (host-pinned).
     *
     * @return array{provider:string, origin:string, sourceUrl:string, page:null, store:null, clientBrand:array, clientProducts:array, fetchMode:string}|null
     */
    private function detectFromClientPayload(string $url, array $storeApi): ?array
    {
        $origin = $this->shopify->originOf($url);
        if (! $origin) {
            return null;
        }

        $rawProducts = $storeApi['products'] ?? null;
        if (! is_array($rawProducts)) {
            return null;
        }

        $products = $this->woocommerce->productsFromClient($origin, $rawProducts);
        if ($products === []) {
            return null;
        }

        $root = is_array($storeApi['root'] ?? null) ? $storeApi['root'] : null;

        return [
            'provider' => ShopProviderDetector::PROVIDER_WOOCOMMERCE,
            'origin' => $origin,
            'sourceUrl' => $origin,
            'page' => null,
            'store' => null,
            'clientBrand' => $this->woocommerce->brandFromClient($origin, $root),
            'clientProducts' => $products,
            'fetchMode' => 'client',
        ];
    }

    /** Live product catalog for a stored brand, dispatched by its provider. */
    private function providerProducts(array $brand): array
    {
        // Client-mode brands: the store blocks our egress, so a live scrape
        // usually 502s. Try it anyway (blocks get lifted), then fall back to
        // the warmed catalog, then to the already-chosen products.
        if (($brand['fetchMode'] ?? null) === 'client') {
            try {
                $live = $this->woocommerce->fetchProducts($brand['url']);
                if ($live !== []) {
                    return $live;
                }
            } catch (HttpException) {
                // Fall through to the cached/stored catalog.
            }

            return Cache::get($this->catalogKey($brand['id'])) ?? ($brand['products'] ?? []);
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
     * The stored brand map (id => brand), or empty. Brands stored before the
     * provider field existed are Shopify (the only provider back then) —
     * ShopPayload applies that default and preserves every other brand key verbatim.
     */
    private function brandMap(User $user): array
    {
        return ShopPayload::fromArray($this->readConnection($user))->toArray();
    }

    /** Brands as a plain ordered list. */
    private function allBrands(User $user): array
    {
        return array_values($this->brandMap($user));
    }

    /** Per-brand picker-catalog cache key. */
    private function catalogKey(string $id): string
    {
        return CacheKeyGenerator::shopifyBrandCatalog($id);
    }
}
