<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for the Shopify integration. Mirrors FreshaController:
// single-tenant cache, no auth, no migration. Saves a store URL (+ optional
// discount code), lists products from /products.json, persists a selection
// of products that partna-pages reads back.
//
// Approach proven and documented in:
//   ~/Developer/platform link capabilites/shopify.md
//
// Promotion plan: extract scrape logic to App\Services\Platforms\ShopifyScraper,
// persist per-user via a platform_connections table, wire to /account/platforms.
class ShopifyController extends ApiController
{
    private const URL_KEY = 'platforms.shopify.url';

    private const DISCOUNT_KEY = 'platforms.shopify.discount';

    // The saved selection blob partna-pages reads to render the shop section.
    private const SELECTION_KEY = 'platforms.shopify.selection';

    private const CACHE_TTL_DAYS = 30;

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // POST /api/platforms/shopify/connect — save store URL + optional discount.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
            'discountCode' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $origin = $this->originOf($validated['url']);
        if (! $origin) {
            return $this->error('Could not parse that store URL.', 422);
        }

        $discount = isset($validated['discountCode']) ? trim((string) $validated['discountCode']) : '';
        Cache::put(self::URL_KEY, $origin, now()->addDays(self::CACHE_TTL_DAYS));
        Cache::put(self::DISCOUNT_KEY, $discount, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success([
            'url' => $origin,
            'discountCode' => $discount,
            'products' => $this->fetchProducts($origin),
        ]);
    }

    // GET /api/platforms/shopify/products — products for the saved store.
    public function products(): JsonResponse
    {
        $origin = Cache::get(self::URL_KEY);
        if (! $origin) {
            return $this->error('No Shopify URL saved yet. POST one to /connect first.', 404);
        }

        return $this->success([
            'url' => $origin,
            'discountCode' => (string) Cache::get(self::DISCOUNT_KEY, ''),
            'products' => $this->fetchProducts($origin),
        ]);
    }

    // GET /api/platforms/shopify/url — peek at saved url + discount.
    public function show(): JsonResponse
    {
        return $this->success([
            'url' => Cache::get(self::URL_KEY),
            'discountCode' => (string) Cache::get(self::DISCOUNT_KEY, ''),
        ]);
    }

    // POST /api/platforms/shopify/selection — save the chosen products. Re-fetches
    // the saved store so the stored blob is server-authoritative; filters to the
    // posted productIds (order preserved as posted).
    public function saveSelection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'productIds' => ['required', 'array', 'min:1'],
            'productIds.*' => ['string', 'max:50'],
        ]);

        $origin = Cache::get(self::URL_KEY);
        if (! $origin) {
            return $this->error('No Shopify URL saved yet. Save one first.', 404);
        }

        $all = collect($this->fetchProducts($origin))->keyBy('productId');
        $chosen = collect($validated['productIds'])
            ->map(fn (string $id) => $all->get($id))
            ->filter()
            ->values()
            ->all();

        if (empty($chosen)) {
            return $this->error('None of those products were found in the saved store.', 404);
        }

        $selection = [
            'url' => $origin,
            'discountCode' => (string) Cache::get(self::DISCOUNT_KEY, ''),
            'products' => $chosen,
        ];
        Cache::put(self::SELECTION_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/shopify/selection — read the saved selection (partna-pages
    // reads this; the dashboard reads it to restore its "saved" state on load).
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_KEY)]);
    }

    // DELETE /api/platforms/shopify — clear url, discount, and selection.
    public function forget(): JsonResponse
    {
        Cache::forget(self::URL_KEY);
        Cache::forget(self::DISCOUNT_KEY);
        Cache::forget(self::SELECTION_KEY);

        return $this->success(['url' => null, 'discountCode' => '', 'selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Reduce any store URL to its scheme://host origin — cart deep links are
    // built relative to the origin (<origin>/cart/<variant>:1).
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}";
    }

    /**
     * Fetch <origin>/products.json and flatten to the fields we use. Only the
     * first variant of each product is kept (its id powers the cart deep link).
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool}>
     */
    private function fetchProducts(string $origin): array
    {
        $response = $this->fetcher->fetch($origin.'/products.json?limit=250', [
            'User-Agent' => self::SCRAPE_USER_AGENT,
        ]);

        if ($response['status'] !== 200) {
            abort(502, "Shopify returned HTTP {$response['status']} for /products.json — the store may have it disabled.");
        }

        $data = json_decode($response['body'], true);
        if (! is_array($data) || ! isset($data['products']) || ! is_array($data['products'])) {
            abort(502, 'No products.json found — this may not be a Shopify store, or it disabled the endpoint.');
        }

        $out = [];
        foreach ($data['products'] as $product) {
            $variant = $product['variants'][0] ?? null;
            if (! $variant || ! isset($variant['id'])) {
                continue;
            }

            $out[] = [
                'productId' => (string) ($product['id'] ?? ''),
                'title' => (string) ($product['title'] ?? ''),
                'handle' => (string) ($product['handle'] ?? ''),
                'vendor' => $product['vendor'] ?? null,
                'image' => data_get($product, 'images.0.src'),
                'price' => $variant['price'] ?? null,
                'currency' => data_get($variant, 'presentment_prices.0.price.currency_code'),
                'variantId' => (string) $variant['id'],
                'available' => (bool) ($variant['available'] ?? true),
            ];
        }

        return $out;
    }
}
