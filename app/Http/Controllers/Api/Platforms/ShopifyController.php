<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddShopifyBrandRequest;
use App\Http\Requests\Platforms\SetShopifyProductsRequest;
use App\Http\Requests\Platforms\UpdateShopifyBrandRequest;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for the Shopify integration — MULTI-BRAND. A user connects
// up to 5 stores; each brand carries its own profile (name/favicon/logo),
// discount code, and chosen products. Stored as one cache key (a map keyed by the
// canonical brand id) via the single-key trait. Scraping lives in
// App\Services\Platforms\ShopifyScraper.
//
// Product selection is decoupled from connect: adding a brand stores it with zero
// products; the picker (GET .../products + PUT .../selection) can run any time.
//
// GET /selection returns a COMPAT flat view of the primary brand so partna-pages
// keeps rendering the Shop card until the skeleton is reworked for multi-brand.
class ShopifyController extends ApiController
{
    use JitteredTtl;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_BRANDS = 5;

    // How long the picker-warmed product catalog stays cached, so a PUT
    // /selection right after the picker opened reuses it instead of re-scraping.
    private const CATALOG_TTL_MINUTES = 10;

    public function __construct(private readonly ShopifyScraper $scraper) {}

    protected function platform(): string
    {
        return 'shopify';
    }

    // GET /api/platforms/shopify/brands — all connected brands.
    public function brands(Request $request): JsonResponse
    {
        return $this->success(['brands' => $this->allBrands($this->currentUser($request))]);
    }

    // POST /api/platforms/shopify/brands — add (or refresh) a brand. Resolves the
    // brand profile, dedups by canonical id, caps at MAX_BRANDS, stores with the
    // brand's existing products preserved (empty on first add).
    public function addBrand(AddShopifyBrandRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        $origin = $this->scraper->originOf($validated['url']);
        if (! $origin) {
            return $this->error('Could not parse that store URL.', 422);
        }

        $brand = $this->scraper->fetchBrand($origin);
        $id = $brand['id'];

        // Scrape and URL-parse run outside the lock (slow external HTTP).
        // Only the read→mutate→write cycle is serialised.
        return $this->withConnectionLock($user, function () use ($user, $validated, $brand, $id, $origin) {
            $map = $this->brandMap($user);
            if (! isset($map[$id]) && count($map) >= self::MAX_BRANDS) {
                return $this->error('You can connect up to '.self::MAX_BRANDS.' brands.', 422);
            }

            $discount = array_key_exists('discountCode', $validated)
                ? trim((string) $validated['discountCode'])
                : ($map[$id]['discountCode'] ?? '');

            $map[$id] = [
                'id' => $id,
                'url' => $origin,
                'name' => $brand['name'],
                'currency' => $brand['currency'] ?? null,
                'favicon' => $brand['favicon'],
                'logo' => $brand['logo'],
                'discountCode' => $discount,
                'products' => $map[$id]['products'] ?? [],
            ];
            $this->writeConnection($user, $map);

            return $this->success($map[$id]);
        });
    }

    // PATCH /api/platforms/shopify/brands/{id} — update the discount code.
    public function updateBrand(UpdateShopifyBrandRequest $request, string $id): JsonResponse
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

            return $this->success($map[$id]);
        });
    }

    // DELETE /api/platforms/shopify/brands/{id} — remove a brand.
    public function removeBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $id) {
            $map = $this->brandMap($user);
            unset($map[$id]);
            $this->writeConnection($user, $map);

            return $this->success(['brands' => array_values($map)]);
        });
    }

    // GET /api/platforms/shopify/brands/{id}/products — live products for the picker.
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = $this->scraper->fetchProducts($map[$id]['url'], $map[$id]['currency'] ?? null);
        // Warm a short-lived catalog cache so the immediately-following PUT
        // /selection reuses these products instead of re-scraping the whole store.
        // Jittered TTL (integer seconds — DateTimeInterface bypasses jitter) so
        // concurrent cold misses at the boundary don't all re-scrape the store at once.
        Cache::put($this->catalogKey($id), $products, self::applyJitter(self::CATALOG_TTL_MINUTES * 60));

        return $this->success(['products' => $products]);
    }

    // PUT /api/platforms/shopify/brands/{id}/selection — snapshot the chosen
    // products (re-fetched live, order preserved). Callable any time.
    public function setProducts(SetShopifyProductsRequest $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $id, $validated) {
            $map = $this->brandMap($user);
            if (! isset($map[$id])) {
                return $this->error('Brand not found.', 404);
            }

            // Prefer the catalog the picker just warmed; only re-scrape if it has
            // gone cold (a save long after the picker was opened). The catalog key
            // is per-brand and the picker normally warms it first, so in practice
            // this is a cache hit and the lock holds for microseconds.
            $catalog = Cache::get($this->catalogKey($id))
                ?? $this->scraper->fetchProducts($map[$id]['url'], $map[$id]['currency'] ?? null);
            $all = collect($catalog)->keyBy('productId');
            $map[$id]['products'] = collect($validated['productIds'])
                ->map(fn (string $pid) => $all->get($pid))
                ->filter()
                ->values()
                ->all();
            $this->writeConnection($user, $map);

            return $this->success($map[$id]);
        });
    }

    // GET /api/platforms/shopify/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products. Reshaped to multi-brand
    // when the skeleton is reworked.
    public function selection(Request $request): JsonResponse
    {
        $primary = collect($this->brandMap($this->currentUser($request)))->first(fn ($b) => ! empty($b['products']));

        $selection = $primary ? [
            'url' => $primary['url'],
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/shopify — clear all brands.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['brands' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    /** The stored brand map (id => brand), or empty. */
    private function brandMap(User $user): array
    {
        $map = $this->readConnection($user);

        return is_array($map) ? $map : [];
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
