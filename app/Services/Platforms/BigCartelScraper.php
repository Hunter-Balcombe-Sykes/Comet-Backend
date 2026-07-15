<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Big Cartel's legacy public API is keyless: api.bigcartel.com/{account}/
// store.json (identity + currency) and products.json (full catalog with
// images and prices). The account is the *.bigcartel.com subdomain, so
// detection is a host match + a store.json round-trip; custom-domain Big
// Cartel stores fall through to the generic JSON-LD provider.
class BigCartelScraper extends PlatformScraper
{
    // Gallery cap — mirrors ShopifyScraper::MAX_IMAGES so no provider's product
    // row can bloat past a sane multi-image-strip size.
    private const MAX_IMAGES = 25;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** The account subdomain from a *.bigcartel.com URL, or null. */
    public function accountFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        if (preg_match('~^([a-z0-9][a-z0-9-]*)\.bigcartel\.com$~', $host, $m) && $m[1] !== 'www') {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{id:string, name:?string, currency:?string, favicon:?string, logo:?string, origin:string}|null
     */
    public function fetchStore(string $account): ?array
    {
        $store = $this->json("https://api.bigcartel.com/{$account}/store.json");
        if (! is_array($store)) {
            return null;
        }

        return [
            'id' => "bigcartel-{$account}",
            'name' => is_string($store['name'] ?? null) ? trim($store['name']) : $account,
            'currency' => is_string($store['currency']['code'] ?? null) ? strtoupper($store['currency']['code']) : null,
            'favicon' => null,
            'logo' => null,
            'origin' => "https://{$account}.bigcartel.com",
        ];
    }

    /**
     * @return list<array{productId:string, title:?string, handle:?string, image:?string, images:list<string>, price:?string, currency:?string, variantId:?string, available:bool, url:?string}>
     */
    public function fetchProducts(string $account, ?string $currency = null): array
    {
        $items = $this->json("https://api.bigcartel.com/{$account}/products.json");
        if (! is_array($items)) {
            return [];
        }
        $origin = "https://{$account}.bigcartel.com";

        $products = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $image = $item['images'][0]['secure_url'] ?? $item['images'][0]['url'] ?? null;
            $price = $item['price'] ?? $item['default_price'] ?? null;

            // Full gallery, same per-image field preference (secure_url over url)
            // as the hero `image` above — capped so a huge gallery can't bloat
            // the stored product row.
            $images = [];
            foreach (array_slice(is_array($item['images'] ?? null) ? $item['images'] : [], 0, self::MAX_IMAGES) as $img) {
                $src = is_array($img) ? ($img['secure_url'] ?? $img['url'] ?? null) : null;
                if (is_string($src) && $src !== '') {
                    $images[] = $src;
                }
            }

            $products[] = [
                'productId' => (string) $item['id'],
                'title' => is_string($item['name'] ?? null) ? trim($item['name']) : null,
                'handle' => is_string($item['permalink'] ?? null) ? $item['permalink'] : null,
                'image' => is_string($image) ? $image : null,
                'images' => $images,
                'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                'currency' => $currency,
                'variantId' => (string) $item['id'],
                'available' => ($item['status'] ?? null) === 'active',
                'url' => is_string($item['url'] ?? null) ? $this->absoluteUrl($item['url'], $origin) : null,
            ];
        }

        return $products;
    }

    private function json(string $url): mixed
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        return json_decode($res['body'], true);
    }
}
