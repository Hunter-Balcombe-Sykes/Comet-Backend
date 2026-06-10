<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a Shopify store with no auth: products from /products.json, and a small
// brand profile (id + name from /meta.json, favicon + logo from the homepage).
// /meta.json + /products.json are undocumented but stable storefront endpoints.
// Generic HTML helpers (favicon/logo/og) live in PlatformScraper.
// Spec: ~/Developer/platform link capabilites/shopify.md
class ShopifyScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Cheap "is this a Shopify store?" probe — /meta.json is Shopify-only and
     * returns a JSON object with the shop id/name. Used by ShopProviderDetector.
     */
    public function probe(string $origin): bool
    {
        $meta = $this->json($origin.'/meta.json');

        return is_array($meta) && (isset($meta['id']) || isset($meta['name']) || isset($meta['myshopify_domain']));
    }

    /**
     * Brand profile for the store. `id` is the canonical dedup key — the
     * /meta.json shop id when available, else a slug of the host. `currency`
     * is the shop's default currency (ISO 4217 code), used as a per-product
     * fallback when /products.json doesn't expose presentment_prices.
     *
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function fetchBrand(string $origin): array
    {
        $meta = $this->json($origin.'/meta.json');
        $rawId = data_get($meta, 'id');
        $id = $rawId !== null
            ? (string) $rawId
            : preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST)));

        $home = $this->fetcher->tryFetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = $home['status'] === 200 ? $home['body'] : '';

        $name = data_get($meta, 'name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $this->metaContent($html, 'og:site_name');

        $currency = data_get($meta, 'currency');
        $currency = is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;

        return [
            'id' => $id,
            'name' => $name,
            'currency' => $currency,
            'favicon' => $this->favicon($html, $origin),
            'logo' => $this->logo($html, $origin),
        ];
    }

    /**
     * Fetch <origin>/products.json and flatten to the fields we use. Only the
     * first variant of each product is kept (its id powers the cart deep link).
     * `$defaultCurrency` is the shop-level currency used as a fallback when a
     * variant lacks presentment_prices (most single-currency stores).
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, url:string}>
     */
    public function fetchProducts(string $origin, ?string $defaultCurrency = null): array
    {
        $response = $this->fetcher->tryFetch($origin.'/products.json?limit=250', ['User-Agent' => self::USER_AGENT]);

        if ($response === null || $response['status'] !== 200) {
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

            $handle = (string) ($product['handle'] ?? '');
            $out[] = [
                'productId' => (string) ($product['id'] ?? ''),
                'title' => (string) ($product['title'] ?? ''),
                'handle' => $handle,
                'vendor' => $product['vendor'] ?? null,
                'image' => data_get($product, 'images.0.src'),
                'price' => $variant['price'] ?? null,
                'currency' => data_get($variant, 'presentment_prices.0.price.currency_code') ?? $defaultCurrency,
                'variantId' => (string) $variant['id'],
                'available' => (bool) ($variant['available'] ?? true),
                // Absolute product URL so sitepage skeletons never need
                // provider-specific URL construction.
                'url' => $origin.'/products/'.$handle,
            ];
        }

        return $out;
    }

    // ── internals ────────────────────────────────────────────────

    private function json(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : null;
    }
}
