<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a WooCommerce store with no auth via the public Store API
// (/wp-json/wc/store/v1/products — unauthenticated by design, used by Woo's
// own block-based checkout) plus the WP REST root (/wp-json) for the site
// name. Favicon/logo come from the homepage via the PlatformScraper helpers.
//
// Store API price fields are MINOR UNITS as strings ("1900" with
// currency_minor_unit 2 = 19.00) — minorToDecimal() converts to the decimal
// string shape the Shopify scraper produces, so the stored product is
// provider-agnostic.
class WooCommerceScraper extends PlatformScraper
{
    private const PRODUCTS_PATH = '/wp-json/wc/store/v1/products';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Cheap "is this a WooCommerce store?" probe — one product, smallest page.
     * A JSON array response (even empty) means the Store API is live.
     */
    public function probe(string $origin): bool
    {
        $res = $this->fetcher->fetch($origin.self::PRODUCTS_PATH.'?per_page=1', ['User-Agent' => self::USER_AGENT]);
        if ($res['status'] !== 200) {
            return false;
        }
        $data = json_decode($res['body'], true);

        return is_array($data) && array_is_list($data);
    }

    /**
     * Brand profile for the store. `id` is a slug of the host (WooCommerce has
     * no global shop id). `currency` falls back to the first product's
     * currency code at product-fetch time.
     *
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function fetchBrand(string $origin): array
    {
        // WP REST root carries the site name; tolerate it being disabled.
        $root = $this->json($origin.'/wp-json');
        $name = data_get($root, 'name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        $home = $this->fetcher->fetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = $home['status'] === 200 ? $home['body'] : '';

        return [
            'id' => preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST))),
            'name' => $name ?? $this->metaContent($html, 'og:site_name'),
            'currency' => null,
            'favicon' => $this->favicon($html, $origin),
            'logo' => $this->logo($html, $origin),
        ];
    }

    /**
     * Fetch the store's visible products, flattened to the canonical shape.
     * `variantId` mirrors the product id (Woo variations aren't needed for a
     * view-product deep link; `url` is the permalink).
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, url:string}>
     */
    public function fetchProducts(string $origin): array
    {
        $res = $this->fetcher->fetch($origin.self::PRODUCTS_PATH.'?per_page=100', ['User-Agent' => self::USER_AGENT]);

        if ($res['status'] !== 200) {
            abort(502, "WooCommerce returned HTTP {$res['status']} for the Store API — it may be disabled.");
        }

        $data = json_decode($res['body'], true);
        if (! is_array($data) || ! array_is_list($data)) {
            abort(502, 'No Store API products found — this may not be a WooCommerce store.');
        }

        $out = [];
        foreach ($data as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }
            $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];

            $out[] = [
                'productId' => (string) $product['id'],
                'title' => html_entity_decode((string) ($product['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'handle' => (string) ($product['slug'] ?? ''),
                'vendor' => null,
                'image' => data_get($product, 'images.0.src'),
                'price' => $this->minorToDecimal($prices['price'] ?? null, (int) ($prices['currency_minor_unit'] ?? 2)),
                'currency' => isset($prices['currency_code']) ? strtoupper((string) $prices['currency_code']) : null,
                'variantId' => (string) $product['id'],
                'available' => (bool) ($product['is_in_stock'] ?? true),
                'url' => (string) ($product['permalink'] ?? $origin),
            ];
        }

        return $out;
    }

    /**
     * Store-API minor units → decimal string ("1900", 2 → "19.00"; "5", 2 →
     * "0.05"; "1900", 0 → "1900"). Null/non-numeric in → null out.
     */
    public function minorToDecimal(mixed $minor, int $minorUnit): ?string
    {
        if ($minor === null || ! preg_match('/^\d+$/', (string) $minor)) {
            return null;
        }
        $digits = (string) $minor;
        if ($minorUnit <= 0) {
            return $digits;
        }
        $padded = str_pad($digits, $minorUnit + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$minorUnit).'.'.substr($padded, -$minorUnit);
    }

    // ── internals ────────────────────────────────────────────────

    private function json(string $url): ?array
    {
        $res = $this->fetcher->fetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res['status'] !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : null;
    }
}
