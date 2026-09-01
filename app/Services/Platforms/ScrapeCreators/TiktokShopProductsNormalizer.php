<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b (2026-09-01): /v1/tiktok/shop/products → the exact catalogue blob
// contract ShopifyScraper::fetchProducts documents (ShopifyScraper.php:127)
// and ShopContentWriter::syncStore / ShopProductProjection::fromBlob consume
// — so a TikTok Shop storefront fills the shop pool through the SAME writer
// path as every other provider, never a bespoke lane. Rows are SYNTHESIZED,
// never spread from the vendor item, so credits_* and the format_*/display
// duplicates can never leak into a persisted payload.
//
// Trial-verified quirks absorbed here (recorded payload 2026-09-01, Goli
// Nutrition US storefront):
//  - sale_price_decimal is a PLAIN decimal string but drops trailing zeros
//    ("30.8") — it still satisfies ShopProductProjection::minorUnits' string
//    arithmetic, so it passes through untouched; the format_* twins carry
//    locale symbols and are never read.
//  - the endpoint lists on-sale products only and exposes no per-product
//    availability, description, createdAt or variant list — those keys land
//    as their documented empty values so syncLatest()'s all-null createdAt
//    branch keeps the endpoint's own (best-selling / newest) order.
//  - the vendor's region enum has NO AU value (US is the only reliable
//    region) — region handling is the caller's concern, not shape.
class TiktokShopProductsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{shop: array{seller_id: string, name: string, url?: string, logo?: string, rating?: float, review_count?: int}, products: non-empty-list<array<string, mixed>>}|null
     *                                                                                                                                                                                   null unless the payload positively carries a named shop AND at
     *                                                                                                                                                                                   least one id-bearing product — a billed NotFound husk must read
     *                                                                                                                                                                                   as "vendor miss", never as an empty storefront.
     */
    public function normalize(array $body): ?array
    {
        $info = is_array($body['shopInfo'] ?? null) ? $body['shopInfo'] : [];
        $sellerId = trim((string) ($info['seller_id'] ?? ''));
        $shopName = is_string($info['shop_name'] ?? null) ? trim($info['shop_name']) : '';
        $list = $body['products'] ?? null;
        if ($sellerId === '' || $shopName === '' || ! is_array($list)) {
            return null;
        }

        $products = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['product_id'] ?? ''));
            $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
            if (preg_match('/^\d+$/', $id) !== 1 || $title === '') {
                continue;
            }

            $price = is_array($item['product_price_info'] ?? null) ? $item['product_price_info'] : [];
            $image = $this->firstUrl($item['image'] ?? null);
            $currency = $price['currency_name'] ?? null;
            $slug = $item['seo_url']['slug'] ?? null;
            $canonical = $item['seo_url']['canonical_url'] ?? null;
            $skuId = trim((string) ($price['sku_id'] ?? ''));

            $products[] = [
                'productId' => $id,
                'title' => $title,
                'handle' => is_string($slug) && $slug !== '' ? $slug : null,
                'vendor' => $shopName,
                'description' => null,
                'image' => $image,
                'images' => $image === null ? [] : [$image],
                'price' => $this->decimal($price['sale_price_decimal'] ?? null),
                'currency' => is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null,
                'variantId' => $skuId !== '' ? $skuId : null,
                'available' => true,
                'url' => is_string($canonical) && str_starts_with($canonical, 'https://')
                    ? $canonical
                    : 'https://www.tiktok.com/shop/pdp/'.$id,
                'createdAt' => null,
                'variants' => [],
            ];
        }

        if ($products === []) {
            return null;
        }

        $shop = ['seller_id' => $sellerId, 'name' => $shopName];

        $link = $info['shop_link'] ?? null;
        if (is_string($link) && str_starts_with($link, 'https://')) {
            $shop['url'] = $link;
        }
        if (($logo = $this->firstUrl($info['shop_logo'] ?? null)) !== null) {
            $shop['logo'] = $logo;
        }
        // shop_rating arrives as a numeric STRING ("4.6") — cast, don't trust.
        if (is_numeric($info['shop_rating'] ?? null)) {
            $shop['rating'] = (float) $info['shop_rating'];
        }
        if (is_numeric($info['review_count'] ?? null)) {
            $shop['review_count'] = (int) $info['review_count'];
        }

        return ['shop' => $shop, 'products' => $products];
    }

    /** First https URL of a {url_list: [...]} image blob, or null. */
    private function firstUrl(mixed $image): ?string
    {
        $urls = is_array($image) ? ($image['url_list'] ?? null) : null;
        $first = is_array($urls) ? ($urls[0] ?? null) : null;

        return is_string($first) && str_starts_with($first, 'https://') ? $first : null;
    }

    /** Pass-through only when minorUnits' string arithmetic can read it. */
    private function decimal(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value !== null && preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value) === 1 ? $value : null;
    }
}
