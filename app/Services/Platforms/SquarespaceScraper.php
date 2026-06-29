<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Squarespace storefronts answer `?format=json` on any page with the full
// page model — no key, no auth. A commerce collection page returns
// `collection.typeName == "products"` plus an `items` array whose
// `structuredContent` carries variants, prices, and stock. Detection probes
// the pasted URL first, then the conventional shop paths.
class SquarespaceScraper extends PlatformScraper
{
    private const SHOP_PATHS = ['/shop', '/store', '/products'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Find the products-collection URL for a pasted Squarespace page, or null
     * when the site is not Squarespace / has no reachable products collection.
     * The returned URL (without the ?format=json suffix) is stored as the
     * brand's sourceUrl so product refreshes hit the right collection.
     */
    public function discoverProductsUrl(string $url): ?string
    {
        $origin = $this->originOf($url);
        if (! $origin) {
            return null;
        }

        $candidates = [rtrim(strtok($url, '?'), '/')];
        foreach (self::SHOP_PATHS as $path) {
            $candidates[] = $origin.$path;
        }

        foreach (array_unique($candidates) as $candidate) {
            $data = $this->pageJson($candidate);
            if (($data['collection']['typeName'] ?? null) === 'products' && is_array($data['items'] ?? null)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}
     */
    public function fetchBrand(string $productsUrl): array
    {
        $origin = $this->originOf($productsUrl) ?? $productsUrl;
        $data = $this->pageJson($productsUrl) ?? [];
        $website = is_array($data['website'] ?? null) ? $data['website'] : [];

        $first = $data['items'][0]['structuredContent']['variants'][0]['priceMoney']['currency'] ?? null;

        // The page model exposes the site identity directly — no second fetch.
        $logo = $website['logoImageUrl'] ?? null;
        if (! is_string($logo) || $logo === '') {
            $logo = null;
        }

        return [
            'id' => $this->idFromOrigin($origin),
            'name' => is_string($website['siteTitle'] ?? null) ? trim($website['siteTitle']) : null,
            'currency' => is_string($first) ? strtoupper($first) : null,
            'favicon' => null,
            'logo' => $logo,
        ];
    }

    /**
     * Products from a discovered collection URL, in the canonical shop shape.
     *
     * @return list<array{productId:string, title:?string, handle:?string, image:?string, price:?string, currency:?string, variantId:?string, available:bool, url:?string}>
     */
    public function fetchProducts(string $productsUrl): array
    {
        $origin = $this->originOf($productsUrl) ?? '';
        $data = $this->pageJson($productsUrl);
        if (! is_array($data['items'] ?? null)) {
            return [];
        }

        $products = [];
        foreach ($data['items'] as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }
            $sc = is_array($item['structuredContent'] ?? null) ? $item['structuredContent'] : [];
            $variant = is_array($sc['variants'][0] ?? null) ? $sc['variants'][0] : [];

            // Sale price wins while the variant is flagged on sale.
            $money = ! empty($variant['onSale']) && is_array($variant['salePriceMoney'] ?? null)
                ? $variant['salePriceMoney']
                : ($variant['priceMoney'] ?? null);

            $fullUrl = is_string($item['fullUrl'] ?? null) ? $item['fullUrl'] : null;

            $products[] = [
                'productId' => (string) $item['id'],
                'title' => is_string($item['title'] ?? null) ? html_entity_decode(trim($item['title']), ENT_QUOTES | ENT_HTML5) : null,
                'handle' => $fullUrl ? basename($fullUrl) : null,
                'image' => is_string($item['assetUrl'] ?? null) ? $item['assetUrl'] : null,
                'price' => is_array($money) && isset($money['value']) ? (string) $money['value'] : null,
                'currency' => is_array($money) && is_string($money['currency'] ?? null) ? strtoupper($money['currency']) : null,
                'variantId' => isset($variant['id']) ? (string) $variant['id'] : (string) $item['id'],
                'available' => ! empty($variant['unlimited']) || (int) ($variant['qtyInStock'] ?? 0) > 0,
                'url' => $fullUrl ? $this->absoluteUrl($fullUrl, $origin) : null,
            ];
        }

        return $products;
    }

    /** GET <url>?format=json — null unless the response parses as a JSON object. */
    private function pageJson(string $url): ?array
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $res = $this->fetcher->tryFetch($url.$sep.'format=json', ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : null;
    }

    /** Stable brand id from the host: shop.example.com → shop-example-com. */
    private function idFromOrigin(string $origin): string
    {
        $host = parse_url($origin, PHP_URL_HOST) ?? $origin;

        return str_replace('.', '-', preg_replace('~^www\.~i', '', strtolower($host)));
    }
}
