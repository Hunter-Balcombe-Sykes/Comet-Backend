<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

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

    // Gallery cap — mirrors ShopifyScraper::MAX_IMAGES so no provider's product
    // row can bloat past a sane multi-image-strip size.
    private const MAX_IMAGES = 25;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Cheap "is this a WooCommerce store?" probe — one product, smallest page.
     * A JSON array response (even empty) means the Store API is live. Tries
     * the pretty /wp-json path first, then the ?rest_route= form (WP sites
     * with plain permalinks 404 the pretty path but serve the query form).
     */
    public function probe(string $origin): bool
    {
        foreach ($this->storeApiUrls($origin, 'per_page=1') as $url) {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
            if ($res === null || $res['status'] !== 200) {
                continue;
            }
            $data = json_decode($res['body'], true);
            if (is_array($data) && array_is_list($data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Both URL forms of the Store API products endpoint, pretty path first.
     *
     * @return list<string>
     */
    private function storeApiUrls(string $origin, string $query): array
    {
        return [
            $origin.self::PRODUCTS_PATH.'?'.$query,
            $origin.'/?rest_route='.rawurlencode('/wc/store/v1/products').'&'.$query,
        ];
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

        // tryFetch() returns null on a transport-level failure (unresolvable host,
        // SSRF rejection, timeout) — guard it, else `$home['status']` warns on null
        // and Laravel's error handler turns that into a 500 (WS-B1).
        $home = $this->fetcher->tryFetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = ($home !== null && $home['status'] === 200) ? $home['body'] : '';

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
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, description:?string, image:?string, images:list<string>, price:?string, currency:?string, variantId:string, available:bool, url:string}>
     */
    public function fetchProducts(string $origin): array
    {
        $data = null;
        $lastStatus = null;
        foreach ($this->storeApiUrls($origin, 'per_page=100') as $url) {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
            if ($res === null || $res['status'] !== 200) {
                $lastStatus = $res['status'] ?? 'no response';

                continue;
            }
            $decoded = json_decode($res['body'], true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $data = $decoded;
                break;
            }
        }

        if ($data === null) {
            abort(502, "WooCommerce returned HTTP {$lastStatus} for the Store API — it may be disabled or blocking server requests.");
        }

        return $this->mapStoreApiProducts($data, $origin);
    }

    /**
     * Raw Store-API products → the canonical product shape (shared by the
     * server fetch above and the client-assisted path below).
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, description:?string, image:?string, images:list<string>, price:?string, currency:?string, variantId:string, available:bool, url:string}>
     */
    public function mapStoreApiProducts(array $data, string $origin): array
    {
        $out = [];
        foreach ($data as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }
            $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];

            // Full image gallery (compat `image` below stays the first entry) —
            // capped so a huge gallery can't bloat the stored product row.
            $images = [];
            foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $img) {
                $src = is_array($img) ? ($img['src'] ?? null) : null;
                if (! is_string($src) || $src === '') {
                    continue;
                }
                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }
                $images[] = $src;
            }

            // Store API exposes purchasable variations (id + attribute values)
            // on variable products — capped like the Shopify scraper, titled
            // from the attribute values ("Small / Red") for the selector UI.
            $variants = [];
            foreach (array_slice(is_array($product['variations'] ?? null) ? $product['variations'] : [], 0, 25) as $variation) {
                if (! is_array($variation) || ! isset($variation['id'])) {
                    continue;
                }
                $attrs = collect(is_array($variation['attributes'] ?? null) ? $variation['attributes'] : [])
                    ->map(fn ($a) => is_array($a) ? ($a['value'] ?? null) : null)
                    ->filter(fn ($v) => is_string($v) && $v !== '')
                    ->implode(' / ');
                $variants[] = [
                    'id' => (string) $variation['id'],
                    'title' => $attrs !== '' ? html_entity_decode($attrs, ENT_QUOTES | ENT_HTML5) : null,
                    'price' => null,
                    'available' => true,
                ];
            }

            $out[] = [
                'productId' => (string) $product['id'],
                'title' => html_entity_decode((string) ($product['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'handle' => (string) ($product['slug'] ?? ''),
                'vendor' => null,
                // Store API's excerpt wins when present; the full description is
                // the fallback (same content-priority the storefront theme uses).
                'description' => $this->sanitizeDescription($product['short_description'] ?? null)
                    ?? $this->sanitizeDescription($product['description'] ?? null),
                'image' => data_get($product, 'images.0.src'),
                'images' => $images,
                'price' => $this->minorToDecimal($prices['price'] ?? null, (int) ($prices['currency_minor_unit'] ?? 2)),
                'currency' => isset($prices['currency_code']) ? strtoupper((string) $prices['currency_code']) : null,
                'variantId' => (string) $product['id'],
                'available' => (bool) ($product['is_in_stock'] ?? true),
                'url' => (string) ($product['permalink'] ?? $origin),
                'createdAt' => null,
                'variants' => $variants,
            ];
        }

        return $out;
    }

    /**
     * Client-assisted path: the dashboard fetched the store's public Store API
     * from the BROWSER (the user's IP — works when the store's WAF blocks our
     * datacenter egress) and posted the raw JSON. Same mapping as the server
     * fetch, then hardened:
     *   - permalinks pinned to the store's host (a posted catalog can't turn
     *     the shop card into links to some other site)
     *   - images must be http(s) URLs
     *   - strings capped to sane lengths
     *
     * @return list<array<string,mixed>>
     */
    public function productsFromClient(string $origin, array $rawProducts): array
    {
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $bareHost = preg_replace('~^www\.~', '', $host);

        $out = [];
        foreach ($this->mapStoreApiProducts($rawProducts, $origin) as $product) {
            $permalinkHost = strtolower((string) parse_url($product['url'], PHP_URL_HOST));
            if ($permalinkHost === '' || preg_replace('~^www\.~', '', $permalinkHost) !== $bareHost) {
                continue;
            }
            if ($product['image'] !== null
                && ! preg_match('~^https?://~i', (string) $product['image'])) {
                $product['image'] = null;
            }
            $product['images'] = array_values(array_filter(
                $product['images'],
                fn ($src) => is_string($src) && preg_match('~^https?://~i', $src) === 1,
            ));

            $product['productId'] = mb_substr($product['productId'], 0, 64);
            $product['variantId'] = mb_substr($product['variantId'], 0, 64);
            $product['title'] = mb_substr($product['title'], 0, 300);
            $product['handle'] = mb_substr($product['handle'], 0, 200);
            if ($product['currency'] !== null && ! preg_match('~^[A-Z]{3}$~', (string) $product['currency'])) {
                $product['currency'] = null;
            }

            $out[] = $product;
        }

        return $out;
    }

    /**
     * Brand profile from a client-posted WP root payload. No server fetches —
     * the store blocks us; the favicon default resolves in the visitor's
     * browser instead.
     *
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function brandFromClient(string $origin, ?array $root): array
    {
        $name = is_array($root) ? data_get($root, 'name') : null;
        $name = is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 200) : null;

        return [
            'id' => preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST))),
            'name' => $name,
            'currency' => null,
            'favicon' => $origin.'/favicon.ico',
            'logo' => null,
        ];
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
    // (short_description/description HTML → plain-text: sanitizeDescription()
    // lives on the shared PlatformScraper base — identical logic, once, for
    // every scraper.)

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
