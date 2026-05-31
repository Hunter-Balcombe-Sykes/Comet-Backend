<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a Shopify store with no auth: products from /products.json, and a small
// brand profile (id + name from /meta.json, favicon + logo from the homepage).
// /meta.json + /products.json are undocumented but stable storefront endpoints.
// Extracted from ShopifyController. Spec: ~/Developer/platform link capabilites/shopify.md
class ShopifyScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Reduce any store URL to its scheme://host origin — deep links are built
    // relative to it.
    public function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}";
    }

    /**
     * Brand profile for the store. `id` is the canonical dedup key — the
     * /meta.json shop id when available, else a slug of the host.
     *
     * @return array{id:string, name:?string, favicon:?string, logo:?string}
     */
    public function fetchBrand(string $origin): array
    {
        $meta = $this->json($origin.'/meta.json');
        $rawId = data_get($meta, 'id');
        $id = $rawId !== null
            ? (string) $rawId
            : preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST)));

        $home = $this->fetcher->fetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = $home['status'] === 200 ? $home['body'] : '';

        $name = data_get($meta, 'name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $this->metaContent($html, 'og:site_name');

        return [
            'id' => $id,
            'name' => $name,
            'favicon' => $this->favicon($html, $origin),
            'logo' => $this->logo($html),
        ];
    }

    /**
     * Fetch <origin>/products.json and flatten to the fields we use. Only the
     * first variant of each product is kept (its id powers the cart deep link).
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool}>
     */
    public function fetchProducts(string $origin): array
    {
        $response = $this->fetcher->fetch($origin.'/products.json?limit=250', ['User-Agent' => self::USER_AGENT]);

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

    // <meta property="og:X" content="..."> — property/content in either order.
    private function metaContent(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        if (preg_match('~<meta[^>]+property=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    // <link rel="...icon..."> href → absolute https; fallback /favicon.ico.
    private function favicon(string $html, string $origin): ?string
    {
        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*icon[^"\']*["\']~i', $link)
                && preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                return $this->absoluteUrl(html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5), $origin);
            }
        }

        return $origin.'/favicon.ico';
    }

    // JSON-LD Organization "logo" → og:logo → apple-touch-icon → null.
    private function logo(string $html): ?string
    {
        if (preg_match('~"logo"\s*:\s*"(https?:[^"]+)"~i', $html, $m)) {
            return stripslashes($m[1]);
        }
        if ($og = $this->metaContent($html, 'og:logo')) {
            return $og;
        }
        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\']apple-touch-icon["\']~i', $link)
                && preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                return html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5);
            }
        }

        return null;
    }

    /** @return list<string> all <link ...> tags in the html */
    private function linkTags(string $html): array
    {
        return preg_match_all('~<link[^>]+>~i', $html, $m) ? $m[0] : [];
    }

    private function absoluteUrl(string $url, string $origin): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return $origin.'/'.ltrim($url, '/');
    }
}
