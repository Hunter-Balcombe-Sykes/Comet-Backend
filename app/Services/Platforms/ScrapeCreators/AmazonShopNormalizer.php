<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b (2026-09-01 plan): Amazon influencer storefronts → the SHOP pool.
// /v1/amazon-shop answers the storefront page in one call: identity (name,
// avatar, description, socials), idea lists, curations, and `trendingPicks` —
// the only PRODUCT-shaped rows in the payload ({url, image, price, discount};
// no title, the photo IS the card). This normalizer distils those picks into
// the exact product-contract blobs ShopContentWriter::syncStore() /
// ShopProductProjection::fromBlob() already read from every other shop source
// (url, image, productId, price-as-string) — the pool side needs nothing new.
//
// Recorded-payload quirks absorbed here (2026-09-01 capture, sydneydelrey):
// prices arrive as JSON NUMBERS (27.98, and a bare int 239) — rendered to the
// "^[0-9]+(\.[0-9]{1,2})?$" string minorUnits() parses, via number_format so
// no float round-trip can shave a cent's representation; the ASIN rides only
// inside the /getProductDetails/<ASIN> URL path — extracted as productId (the
// dedupe key); product URLs carry the creator's affiliate tag (tag_override)
// and are kept VERBATIM — rewriting to a bare /dp/ link would strip their
// commission; optional keys are OMITTED, never null, mirroring the vendor;
// nothing from the raw body is spread through, so credits_* can never reach
// persistence. No currency anywhere in the payload — the store row's
// store-level currency fallback (fromBlob's $storeCurrency) is the owner of
// that decision, not this normalizer.
//
// Returns null unless the payload positively carries at least one usable
// pick (http url + http image). A NotFound husk, shape drift, or an empty
// storefront must all read as "vendor miss" so the caller falls through —
// this lane may never be the reason a shop reads as empty.
class AmazonShopNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{products: non-empty-list<array{url: string, image: string, productId?: string, price?: string}>, name?: string, avatar?: string}|null
     */
    public function normalize(array $body): ?array
    {
        $picks = $body['trendingPicks'] ?? null;
        if (! is_array($picks)) {
            return null;
        }

        $products = [];
        $seen = [];
        foreach ($picks as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $url = trim((string) ($pick['url'] ?? ''));
            $image = trim((string) ($pick['image'] ?? ''));
            // The vendor sends no product titles, so a pick without its photo
            // has no rendering surface at all — skipped, not carried.
            if (preg_match('~^https?://~i', $url) !== 1 || preg_match('~^https?://~i', $image) !== 1) {
                continue;
            }

            $asin = $this->asin($url);
            $key = $asin ?? $url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $product = ['url' => $url, 'image' => $image];
            if ($asin !== null) {
                $product['productId'] = $asin;
            }
            if (($price = $this->price($pick['price'] ?? null)) !== null) {
                $product['price'] = $price;
            }

            $products[] = $product;
        }

        if ($products === []) {
            return null;
        }

        $page = ['products' => $products];

        $name = $body['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            $page['name'] = trim($name);
        }

        $avatar = $body['avatar'] ?? null;
        if (is_string($avatar) && preg_match('~^https?://~i', $avatar) === 1) {
            $page['avatar'] = $avatar;
        }

        return $page;
    }

    /**
     * The syncStore-facing view: product-contract blobs in storefront order.
     *
     * @param  array{products: non-empty-list<array{url: string, image: string, productId?: string, price?: string}>}  $page  a normalize() result
     * @return non-empty-list<array{url: string, image: string, productId?: string, price?: string}>
     */
    public function products(array $page): array
    {
        return $page['products'];
    }

    /** The ASIN inside a /getProductDetails/<ASIN> storefront URL, else null. */
    private function asin(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~/getProductDetails/([A-Z0-9]{10})$~', $path, $m) === 1 ? $m[1] : null;
    }

    /**
     * A positive JSON number → the string shape minorUnits() parses
     * ("27.98", 239 → "239.00"). Anything else — absent, zero, negative,
     * non-numeric — omits the key: a free product on an affiliate storefront
     * is not a real offer, it is missing data.
     */
    private function price(mixed $value): ?string
    {
        if (is_bool($value) || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
