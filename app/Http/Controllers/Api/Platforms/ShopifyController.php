<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    use ManagesPlatformSelection;

    private const BRANDS_KEY = 'platforms.shopify.brands';

    private const MAX_BRANDS = 5;

    public function __construct(private readonly ShopifyScraper $scraper) {}

    protected function selectionKey(): string
    {
        return self::BRANDS_KEY;
    }

    // GET /api/platforms/shopify/brands — all connected brands.
    public function brands(): JsonResponse
    {
        return $this->success(['brands' => $this->allBrands()]);
    }

    // POST /api/platforms/shopify/brands — add (or refresh) a brand. Resolves the
    // brand profile, dedups by canonical id, caps at MAX_BRANDS, stores with the
    // brand's existing products preserved (empty on first add).
    public function addBrand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'discountCode' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $origin = $this->scraper->originOf($validated['url']);
        if (! $origin) {
            return $this->error('Could not parse that store URL.', 422);
        }

        $brand = $this->scraper->fetchBrand($origin);
        $id = $brand['id'];

        $map = $this->brandMap();
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
        $this->writeSelection($map);

        return $this->success($map[$id]);
    }

    // PATCH /api/platforms/shopify/brands/{id} — update the discount code.
    public function updateBrand(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'discountCode' => ['present', 'nullable', 'string', 'max:100'],
        ]);

        $map = $this->brandMap();
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $map[$id]['discountCode'] = trim((string) $validated['discountCode']);
        $this->writeSelection($map);

        return $this->success($map[$id]);
    }

    // DELETE /api/platforms/shopify/brands/{id} — remove a brand.
    public function removeBrand(string $id): JsonResponse
    {
        $map = $this->brandMap();
        unset($map[$id]);
        $this->writeSelection($map);

        return $this->success(['brands' => array_values($map)]);
    }

    // GET /api/platforms/shopify/brands/{id}/products — live products for the picker.
    public function brandProducts(string $id): JsonResponse
    {
        $map = $this->brandMap();
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        return $this->success(['products' => $this->scraper->fetchProducts($map[$id]['url'], $map[$id]['currency'] ?? null)]);
    }

    // PUT /api/platforms/shopify/brands/{id}/selection — snapshot the chosen
    // products (re-fetched live, order preserved). Callable any time.
    public function setProducts(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'productIds' => ['present', 'array', 'max:250'],
            'productIds.*' => ['string', 'max:50'],
        ]);

        $map = $this->brandMap();
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $all = collect($this->scraper->fetchProducts($map[$id]['url'], $map[$id]['currency'] ?? null))->keyBy('productId');
        $map[$id]['products'] = collect($validated['productIds'])
            ->map(fn (string $pid) => $all->get($pid))
            ->filter()
            ->values()
            ->all();
        $this->writeSelection($map);

        return $this->success($map[$id]);
    }

    // GET /api/platforms/shopify/selection — COMPAT flat view of the primary brand
    // (first brand that has products) so partna-pages' existing Shop card keeps
    // rendering. Returns null when no brand has products. Reshaped to multi-brand
    // when the skeleton is reworked.
    public function selection(): JsonResponse
    {
        $primary = collect($this->brandMap())->first(fn ($b) => ! empty($b['products']));

        $selection = $primary ? [
            'url' => $primary['url'],
            'discountCode' => $primary['discountCode'] ?? '',
            'products' => $primary['products'],
        ] : null;

        return $this->success(['selection' => $selection]);
    }

    // DELETE /api/platforms/shopify — clear all brands.
    public function forget(): JsonResponse
    {
        $this->clearSelection();

        return $this->success(['brands' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    /** The stored brand map (id => brand), or empty. */
    private function brandMap(): array
    {
        $map = $this->readSelection();

        return is_array($map) ? $map : [];
    }

    /** Brands as a plain ordered list. */
    private function allBrands(): array
    {
        return array_values($this->brandMap());
    }
}
