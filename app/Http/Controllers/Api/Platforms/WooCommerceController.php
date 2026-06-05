<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\User\User;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// WooCommerce integration — MULTI-BRAND (same model as Shopify). A user connects
// up to 5 stores; each brand carries its own profile (name/favicon/logo),
// discount code, and chosen products. Stored as one brand map keyed by canonical
// brand id via the single-key trait. Scraping lives in WooCommerceScraper which
// uses the public WooCommerce Store API (no API keys required).
class WooCommerceController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const CATALOG_KEY = 'platforms.woocommerce.brands';

    private const MAX_BRANDS = 5;

    private const CATALOG_TTL_MINUTES = 10;

    public function __construct(private readonly WooCommerceScraper $scraper) {}

    protected function platform(): string
    {
        return 'woocommerce';
    }

    // GET /api/platforms/woocommerce/brands
    public function brands(Request $request): JsonResponse
    {
        return $this->success(['brands' => $this->allBrands($this->currentUser($request))]);
    }

    // POST /api/platforms/woocommerce/brands
    //
    // Two modes:
    //  - Client mode (default for the dashboard): the browser already fetched the
    //    store's public WP REST API (bypassing any WAF that blocks our server's
    //    IP) and passes `name`/`favicon`/`logo`. We trust those and skip scraping.
    //  - Server mode (fallback): no client metadata — scrape server-side. Works
    //    only for stores that don't block datacenter IPs.
    public function addBrand(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'discountCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'favicon' => ['sometimes', 'nullable', 'string', 'max:1000', 'url'],
            'logo' => ['sometimes', 'nullable', 'string', 'max:1000', 'url'],
        ]);

        $origin = $this->scraper->originOf($validated['url']);
        if (! $origin) {
            return $this->error('Could not parse that store URL.', 422);
        }

        // Client mode is signalled by any browser-supplied brand field.
        $clientMode = array_key_exists('name', $validated)
            || array_key_exists('favicon', $validated)
            || array_key_exists('logo', $validated);

        if ($clientMode) {
            $id = $this->scraper->brandId($origin);
            $brand = [
                'id' => $id,
                'name' => $validated['name'] ?? null,
                'currency' => null,
                'favicon' => $validated['favicon'] ?? null,
                'logo' => $validated['logo'] ?? null,
            ];
        } else {
            $brand = $this->scraper->fetchBrand($origin);
            $id = $brand['id'];
        }

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
    }

    // PATCH /api/platforms/woocommerce/brands/{id}
    public function updateBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate([
            'discountCode' => ['present', 'nullable', 'string', 'max:100'],
        ]);

        $map = $this->brandMap($user);
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $map[$id]['discountCode'] = trim((string) $validated['discountCode']);
        $this->writeConnection($user, $map);

        return $this->success($map[$id]);
    }

    // DELETE /api/platforms/woocommerce/brands/{id}
    public function removeBrand(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        $map = $this->brandMap($user);
        unset($map[$id]);
        $this->writeConnection($user, $map);

        return $this->success(['brands' => array_values($map)]);
    }

    // GET /api/platforms/woocommerce/brands/{id}/products
    public function brandProducts(Request $request, string $id): JsonResponse
    {
        $map = $this->brandMap($this->currentUser($request));
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        $products = $this->scraper->fetchProducts($map[$id]['url'], $map[$id]['currency'] ?? null);
        Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES));

        return $this->success(['products' => $products]);
    }

    // PUT /api/platforms/woocommerce/brands/{id}/selection
    //
    // Two modes:
    //  - Client mode (default): the browser passes the chosen full product
    //    objects (it fetched the catalog directly, bypassing any store WAF). We
    //    normalise + store them verbatim — no server re-fetch.
    //  - Server mode (fallback): `productIds` + a server re-fetch of the catalog.
    public function setProducts(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        $map = $this->brandMap($user);
        if (! isset($map[$id])) {
            return $this->error('Brand not found.', 404);
        }

        if ($request->has('products')) {
            $validated = $request->validate([
                'products' => ['present', 'array', 'max:250'],
                'products.*.productId' => ['required', 'string', 'max:50'],
                'products.*.title' => ['required', 'string', 'max:500'],
                'products.*.handle' => ['nullable', 'string', 'max:500'],
                'products.*.image' => ['nullable', 'string', 'max:2000'],
                'products.*.price' => ['nullable', 'string', 'max:50'],
                'products.*.currency' => ['nullable', 'string', 'max:10'],
                'products.*.permalink' => ['nullable', 'string', 'max:2000'],
                'products.*.available' => ['sometimes', 'boolean'],
            ]);

            $map[$id]['products'] = collect($validated['products'])->map(fn (array $p) => [
                'productId' => (string) $p['productId'],
                'title' => (string) $p['title'],
                'handle' => (string) ($p['handle'] ?? ''),
                'vendor' => null,
                'image' => $p['image'] ?? null,
                'price' => $p['price'] ?? null,
                'currency' => $p['currency'] ?? null,
                'variantId' => (string) $p['productId'],
                'available' => (bool) ($p['available'] ?? true),
                'permalink' => $p['permalink'] ?? null,
            ])->values()->all();
            $this->writeConnection($user, $map);

            return $this->success($map[$id]);
        }

        $validated = $request->validate([
            'productIds' => ['present', 'array', 'max:250'],
            'productIds.*' => ['string', 'max:50'],
        ]);

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
    }

    // DELETE /api/platforms/woocommerce
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['brands' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    private function brandMap(User $user): array
    {
        $map = $this->readConnection($user);

        return is_array($map) ? $map : [];
    }

    private function allBrands(User $user): array
    {
        return array_values($this->brandMap($user));
    }

    private function catalogKey(string $id): string
    {
        return self::CATALOG_KEY.'.catalog.'.$id;
    }
}
