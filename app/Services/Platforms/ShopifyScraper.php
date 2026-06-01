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

        $home = $this->fetcher->fetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
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
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool}>
     */
    public function fetchProducts(string $origin, ?string $defaultCurrency = null): array
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
                'currency' => data_get($variant, 'presentment_prices.0.price.currency_code') ?? $defaultCurrency,
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

    // Pick the best favicon from the page's <link rel="...icon..."> tags.
    //
    // Strategy: collect every non-apple-touch-icon link, score by (clamped
    // size + format bonus), return the winner. Apple-touch icons are
    // excluded — they're 180×180+ iOS chrome images, oversized and often
    // visually different from the real brand favicon. PNG/SVG beat ICO
    // since the result renders at ~32px in the dashboard.
    private function favicon(string $html, string $origin): ?string
    {
        $candidates = [];
        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*apple-touch-icon[^"\']*["\']~i', $link)) {
                continue;
            }
            if (! preg_match('~rel=["\'][^"\']*icon[^"\']*["\']~i', $link)) {
                continue;
            }
            if (! preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                continue;
            }

            $size = 0;
            if (preg_match('~sizes=["\'](\d+)x\d+["\']~i', $link, $s)) {
                $size = (int) $s[1];
            }
            $type = '';
            if (preg_match('~type=["\']image/([a-z0-9+.\-]+)["\']~i', $link, $t)) {
                $type = strtolower($t[1]);
            }

            $candidates[] = [
                'href' => html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5),
                'size' => $size,
                'type' => $type,
            ];
        }

        if (! empty($candidates)) {
            usort($candidates, function (array $a, array $b): int {
                // Bigger is better up to 192px; past that the icon is
                // wasted bandwidth for the dashboard's ~32px slot.
                $sizeA = min($a['size'] !== 0 ? $a['size'] : 16, 192);
                $sizeB = min($b['size'] !== 0 ? $b['size'] : 16, 192);
                $typeA = in_array($a['type'], ['png', 'svg+xml'], true) ? 16 : 0;
                $typeB = in_array($b['type'], ['png', 'svg+xml'], true) ? 16 : 0;

                return ($sizeB + $typeB) <=> ($sizeA + $typeA);
            });

            return $this->absoluteUrl($candidates[0]['href'], $origin);
        }

        return $origin.'/favicon.ico';
    }

    // Pick the best brand logo from the page.
    //
    // Source priority (high → low):
    //   1. JSON-LD Organization.logo as a plain string.
    //   2. JSON-LD Organization.logo as an ImageObject ({ "url": "…" }).
    //   3. og:logo meta tag.
    //   4. <img> whose class contains a known logo class (header__logo,
    //      site-logo, brand-logo, plain "logo"). Covers Dawn + most
    //      Shopify themes.
    //   5. <img src> on the Shopify CDN whose filename contains "logo".
    //   6. apple-touch-icon (180×180+ — last-resort brand identity).
    //   7. null.
    private function logo(string $html, string $origin): ?string
    {
        if (preg_match('~"logo"\s*:\s*"(https?:[^"]+)"~i', $html, $m)) {
            return $this->absoluteUrl($this->cleanJsonString($m[1]), $origin);
        }

        if (preg_match('~"logo"\s*:\s*\{[^}]*?"url"\s*:\s*"(https?:[^"]+)"~i', $html, $m)) {
            return $this->absoluteUrl($this->cleanJsonString($m[1]), $origin);
        }

        if ($og = $this->metaContent($html, 'og:logo')) {
            return $this->absoluteUrl($og, $origin);
        }

        // Class-name signal — most Shopify themes (Dawn, Impulse, Streamline,
        // Prestige, etc.) wrap the header logo in an <img> whose class
        // contains "header__logo", "site-logo", "brand-logo", or just "logo".
        // Match the attribute in either order (class before src or vice versa).
        $logoClassRegex = '(?:header__logo|header-logo|site-logo|brand-logo|\blogo\b)';
        if (preg_match('~<img[^>]+class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\'][^>]*src=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<img[^>]+src=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\']~i', $html, $m)) {
            return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
        }

        // Filename signal — Shopify CDN paths often embed "logo" in the
        // uploaded asset name (`logo.png`, `brand_logo.svg`, etc.).
        if (preg_match('~<img[^>]+src=["\']([^"\']+/cdn/shop/files/[^"\']*logo[^"\']*\.(?:png|svg|jpg|jpeg|webp))["\']~i', $html, $m)) {
            return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
        }

        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*apple-touch-icon[^"\']*["\']~i', $link)
                && preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                return $this->absoluteUrl(html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5), $origin);
            }
        }

        return null;
    }

    /** Strip JSON escapes and HTML entities from a scraped URL string. */
    private function cleanJsonString(string $value): string
    {
        return stripslashes(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
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
