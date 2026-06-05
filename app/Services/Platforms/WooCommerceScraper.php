<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a WooCommerce store with no auth: products from the public
// /wp-json/wc/store/v1/products endpoint (available on WooCommerce 5.0+ with
// the Blocks plugin), and a brand profile (name from /wp-json/, favicon + logo
// from the homepage). No API keys required.
class WooCommerceScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    public function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}";
    }

    /**
     * Brand profile for the store. `id` is a slug of the host (WooCommerce
     * has no storefront meta.json equivalent). `currency` is left null because
     * each WooCommerce product carries its own currency in the Store API
     * response — no store-level default is needed.
     *
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function fetchBrand(string $origin): array
    {
        $id = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST)));

        $wpInfo = $this->json($origin.'/wp-json/');

        $home = $this->fetcher->fetch($origin.'/', ['User-Agent' => self::USER_AGENT]);
        $html = $home['status'] === 200 ? $home['body'] : '';

        $name = data_get($wpInfo, 'name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : $this->metaContent($html, 'og:site_name');

        return [
            'id' => $id,
            'name' => $name,
            'currency' => null,
            'favicon' => $this->favicon($html, $origin),
            'logo' => $this->logo($html, $origin),
        ];
    }

    /**
     * Fetch up to 100 products from the WooCommerce Store API and flatten to
     * the shared ShopProduct shape. Prices are converted from minor units
     * (e.g., "2999" with minor_unit=2 → "29.99"). The `permalink` field is
     * included so the sitepage can link directly to each product page instead
     * of constructing a Shopify-style cart URL.
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, permalink:?string}>
     */
    public function fetchProducts(string $origin, ?string $defaultCurrency = null): array
    {
        $response = $this->fetcher->fetch(
            $origin.'/wp-json/wc/store/v1/products?per_page=100',
            ['User-Agent' => self::USER_AGENT],
        );

        if ($response['status'] !== 200) {
            abort(502, "WooCommerce Store API returned HTTP {$response['status']} — the store may not have WooCommerce installed, or the Store API is disabled.");
        }

        $data = json_decode($response['body'], true);
        if (! is_array($data)) {
            abort(502, 'No products found — this may not be a WooCommerce store, or the Store API is disabled.');
        }

        $out = [];
        foreach ($data as $product) {
            $id = $product['id'] ?? null;
            if (! $id) {
                continue;
            }

            $prices = $product['prices'] ?? [];
            $minorPrice = (string) ($prices['price'] ?? '');
            $minorUnit = (int) ($prices['currency_minor_unit'] ?? 2);
            $currency = $prices['currency_code'] ?? $defaultCurrency;

            $permalink = $product['permalink'] ?? null;
            $handle = $permalink ? basename(rtrim($permalink, '/')) : (string) $id;

            $out[] = [
                'productId' => (string) $id,
                'title' => (string) ($product['name'] ?? ''),
                'handle' => $handle,
                'vendor' => null,
                'image' => data_get($product, 'images.0.src'),
                'price' => $minorPrice !== '' ? $this->formatPrice($minorPrice, $minorUnit) : null,
                'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
                'variantId' => (string) $id,
                'available' => (bool) ($product['is_in_stock'] ?? true),
                'permalink' => $permalink,
            ];
        }

        return $out;
    }

    // ── internals ────────────────────────────────────────────────

    /** Convert a minor-unit price string to a decimal string (e.g. "2999", 2 → "29.99"). */
    private function formatPrice(string $minorPrice, int $minorUnit): string
    {
        if ($minorUnit === 0) {
            return $minorPrice;
        }

        return number_format((float) $minorPrice / (10 ** $minorUnit), $minorUnit, '.', '');
    }

    private function json(string $url): ?array
    {
        $res = $this->fetcher->fetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res['status'] !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : null;
    }

    private function metaContent(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        if (preg_match('~<meta[^>]+property=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

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
                $sizeA = min($a['size'] !== 0 ? $a['size'] : 16, 192);
                $sizeB = min($b['size'] !== 0 ? $b['size'] : 16, 192);
                $typeA = in_array($a['type'], ['png', 'svg+xml'], true) ? 16 : 0;
                $typeB = in_array($b['type'], ['png', 'svg+xml'], true) ? 16 : 0;

                return ($sizeB + $typeB) <=> ($sizeA + $typeA);
            });

            return $this->absoluteUrl($candidates[0]['href'], $origin);
        }

        return null;
    }

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

        $logoClassRegex = '(?:header__logo|header-logo|site-logo|brand-logo|\blogo\b)';
        if (preg_match('~<img[^>]+class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\'][^>]*src=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<img[^>]+src=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\']~i', $html, $m)) {
            return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
        }

        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*apple-touch-icon[^"\']*["\']~i', $link)
                && preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                return $this->absoluteUrl(html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5), $origin);
            }
        }

        if ($og = $this->metaContent($html, 'og:image')) {
            return $this->absoluteUrl($og, $origin);
        }

        return null;
    }

    private function cleanJsonString(string $value): string
    {
        return stripslashes(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }

    /** @return list<string> */
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
