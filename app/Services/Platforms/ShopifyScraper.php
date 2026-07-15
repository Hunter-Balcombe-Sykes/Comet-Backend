<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Str;

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

        // tryFetch() returns null on a transport-level failure (unresolvable host,
        // SSRF rejection, timeout) — guard it, else `$home['status']` warns on null
        // and Laravel's error handler turns that into a 500 (WS-B1).
        $home = $this->fetcher->tryFetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = ($home !== null && $home['status'] === 200) ? $home['body'] : '';

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

    // Full variant lists are capped so a 100-variant catalog monster can't
    // bloat the stored product row; the first N cover real selector UIs.
    private const MAX_VARIANTS = 25;

    // Product-level gallery cap — mirrors MAX_VARIANTS' "don't let one product
    // bloat the row" intent. A real product page rarely shows more than this,
    // and the sitepage gallery only needs enough for a swipeable strip.
    private const MAX_IMAGES = 25;

    /**
     * Fetch <origin>/products.json and flatten to the fields we use. The first
     * variant stays the flat compat fields (variantId/price/available — powers
     * the default deep link); the full list rides in `variants` so checkout
     * link-mode can offer a variant selector. Each variant carries its own
     * `image` (its assigned photo, resolved from featured_image or image_id →
     * the product's images[]) so a colour/style pick can swap the displayed
     * photo; `images` is the whole product gallery (absolute src URLs) for a
     * multi-image strip. `createdAt` (product created_at) lets latest-mode
     * selection sort newest-first regardless of endpoint ordering.
     * `$defaultCurrency` is the shop-level currency used as a fallback when a
     * variant lacks presentment_prices.
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, description:?string, image:?string, images:list<string>, price:?string, currency:?string, variantId:string, available:bool, url:string, createdAt:?string, variants:list<array{id:string, title:?string, price:?string, available:bool, image:?string}>}>
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

            // Product gallery + an id→src lookup so a variant that only carries
            // an `image_id` (not an inline featured_image) still resolves to a
            // real photo. `variant_ids` on an image isn't needed here — the
            // variant side already points back via featured_image / image_id.
            $images = [];
            $imagesById = [];
            foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $img) {
                $src = is_array($img) ? ($img['src'] ?? null) : null;
                if (! is_string($src) || $src === '') {
                    continue;
                }
                if (count($images) < self::MAX_IMAGES) {
                    $images[] = $src;
                }
                if (isset($img['id'])) {
                    $imagesById[(string) $img['id']] = $src;
                }
            }

            $variants = [];
            foreach (array_slice(is_array($product['variants'] ?? null) ? $product['variants'] : [], 0, self::MAX_VARIANTS) as $v) {
                if (! is_array($v) || ! isset($v['id'])) {
                    continue;
                }
                $variants[] = [
                    'id' => (string) $v['id'],
                    'title' => isset($v['title']) ? (string) $v['title'] : null,
                    'price' => $v['price'] ?? null,
                    'available' => (bool) ($v['available'] ?? true),
                    // The variant's own photo: an inline featured_image.src wins;
                    // else map image_id → the product gallery. Null when the
                    // merchant didn't assign one (sitepage falls back to `image`).
                    'image' => $this->variantImage($v, $imagesById),
                ];
            }

            $handle = (string) ($product['handle'] ?? '');
            $out[] = [
                'productId' => (string) ($product['id'] ?? ''),
                'title' => (string) ($product['title'] ?? ''),
                'handle' => $handle,
                'vendor' => $product['vendor'] ?? null,
                'description' => $this->sanitizeDescription($product['body_html'] ?? null),
                // Compat single hero image (first product image); `images` below
                // carries the full gallery for a multi-image strip.
                'image' => $images[0] ?? data_get($product, 'images.0.src'),
                'images' => $images,
                'price' => $variant['price'] ?? null,
                'currency' => data_get($variant, 'presentment_prices.0.price.currency_code') ?? $defaultCurrency,
                'variantId' => (string) $variant['id'],
                'available' => (bool) ($variant['available'] ?? true),
                // Absolute product URL so sitepage skeletons never need
                // provider-specific URL construction.
                'url' => $origin.'/products/'.$handle,
                'createdAt' => isset($product['created_at']) && is_string($product['created_at']) ? $product['created_at'] : null,
                'variants' => $variants,
            ];
        }

        return $out;
    }

    /**
     * Resolve one variant's own image src. Shopify exposes it two ways in
     * /products.json: an inline `featured_image` object (`.src`), or a bare
     * `image_id` that indexes the product's images[] (mapped in $imagesById).
     * Returns null when neither is present — the variant shares the product's
     * hero image on the sitepage.
     *
     * @param  array<string,mixed>  $variant
     * @param  array<string,string>  $imagesById  product image id → absolute src
     */
    private function variantImage(array $variant, array $imagesById): ?string
    {
        $featured = $variant['featured_image'] ?? null;
        if (is_array($featured) && isset($featured['src']) && is_string($featured['src']) && $featured['src'] !== '') {
            return $featured['src'];
        }

        if (isset($variant['image_id']) && $variant['image_id'] !== null) {
            return $imagesById[(string) $variant['image_id']] ?? null;
        }

        return null;
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * body_html → plain-text description: strip tags, decode entities, collapse
     * whitespace, cap length. Blank/non-string input becomes null.
     */
    private function sanitizeDescription(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }
        $text = Str::limit(Str::squish(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)), 2000, '');

        return $text !== '' ? $text : null;
    }

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
