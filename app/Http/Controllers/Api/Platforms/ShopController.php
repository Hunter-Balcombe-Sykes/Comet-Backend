<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddShopBrandRequest;
use App\Http\Requests\Platforms\SetShopProductsRequest;
use App\Http\Requests\Platforms\UpdateShopBrandRequest;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProviderDetector;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        if ($detected === null) {
            return $this->error(
                "Couldn't find a supported store at that URL. Shopify and WooCommerce stores connect automatically; other sites need standard product markup on the page you paste.",
                422,
            );
        }

        [$brand, $detectedProducts] = $this->brandProfileFor($detected);
        $id = $brand['id'];

        return $this->withConnectionLock($user, function () use ($user, $validated, $detected, $brand, $detectedProducts, $id) {
            $map = $this->brandMap($user);
            if (! isset($map[$id]) && count($map) >= self::MAX_BRANDS) {
                return $this->error('You can connect up to '.self::MAX_BRANDS.' brands.', 422);
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

    // GET /api/platforms/shop/brands/{id}/products — live products for the picker.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = $this->providerProducts($map[$id]);
        // Warm a short-lived catalog cache so the immediately-following PUT
        // /selection reuses these products instead of re-scraping the whole store.
        // Jittered TTL so concurrent cold misses at the boundary don't all
        // re-scrape the store at once.
        Cache::put($this->catalogKey($id), $products, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));

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

            return $this->success((new ShopBrandResource($map[$id]))->resolve());
        });
    }

    // GET /api/platforms/shop/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products.
    public function selection(Request $request): JsonResponse
    {
        $primary = collect($this->brandMap($this->currentUser($request)))->first(fn ($b) => ! empty($b['products']));

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
        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'], $detected['page']['products']],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
    }

    /** Live product catalog for a stored brand, dispatched by its provider. */
    private function providerProducts(array $brand): array
    {
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
     * provider field existed are Shopify (the only provider back then).
     */
    private function brandMap(User $user): array
    {
        $map = $this->readConnection($user);
        if (! is_array($map)) {
            return [];
        }

        foreach ($map as &$brand) {
            if (is_array($brand)) {
                $brand['provider'] ??= 'shopify';
            }
        }

        return $map;
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
