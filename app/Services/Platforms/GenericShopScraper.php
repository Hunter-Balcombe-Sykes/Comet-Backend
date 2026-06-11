<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Last-resort shop provider: reads schema.org Product JSON-LD off the exact
// page the user pasted (a shop/collection/landing page). Covers Squarespace,
// Wix, BigCommerce and custom storefronts that emit standard product markup —
// no platform API, no auth. The page URL is stored on the brand as
// `sourceUrl` so re-fetches read the same page.
class GenericShopScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Fetch the page once and extract brand profile + products together.
     * Returns null when the page yields no Product JSON-LD at all (the
     * detector treats that as "not a recognisable shop page").
     *
     * @return array{brand: array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}, products: list<array<string,mixed>>}|null
     */
    public function fetchPage(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];
        $origin = $this->originOf($res['finalUrl']) ?? $this->originOf($url) ?? $url;

        $products = $this->productsFromJsonLd($html, $res['finalUrl'], $origin);
        if ($products === []) {
            return null;
        }

        $name = $this->metaContent($html, 'og:site_name')
            ?? (preg_match('~<title[^>]*>([^<]+)</title>~i', $html, $m)
                ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5)
                : null);

        return [
            'brand' => [
                'id' => preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST))),
                'name' => $name,
                'currency' => $products[0]['currency'] ?? null,
                'favicon' => $this->favicon($html, $origin),
                'logo' => $this->logo($html, $origin),
            ],
            'products' => $products,
        ];
    }

    /**
     * Collect schema.org Product nodes — direct, inside @graph, or inside
     * ItemList.itemListElement (both ListItem.item and bare Product forms) —
     * and flatten to the canonical product shape.
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, url:string}>
     */
    private function productsFromJsonLd(string $html, string $pageUrl, string $origin): array
    {
        $productNodes = [];
        foreach ($this->jsonLdNodes($html) as $node) {
            if (! is_array($node)) {
                continue;
            }
            if ($this->isProductNode($node)) {
                $productNodes[] = $node;

                continue;
            }
            // ItemList of products (typical shop/collection page markup).
            foreach ((array) ($node['itemListElement'] ?? []) as $element) {
                if (! is_array($element)) {
                    continue;
                }
                $item = is_array($element['item'] ?? null) ? $element['item'] : $element;
                if ($this->isProductNode($item)) {
                    $productNodes[] = $item;
                }
            }
        }

        $out = [];
        $seen = [];
        foreach ($productNodes as $node) {
            $title = is_string($node['name'] ?? null) ? trim($node['name']) : '';
            if ($title === '') {
                continue;
            }

            // offers: object, array of objects, or AggregateOffer.
            $offers = $node['offers'] ?? [];
            if (is_array($offers) && array_is_list($offers)) {
                $offers = $offers[0] ?? [];
            }
            if (! is_array($offers)) {
                $offers = [];
            }

            $url = $this->firstString($offers['url'] ?? null, $node['url'] ?? null) ?? $pageUrl;
            $url = $this->absoluteUrl($url, $origin);

            $id = $this->firstString($node['sku'] ?? null, $node['productID'] ?? null) ?? substr(md5($url.'|'.$title), 0, 16);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $price = $offers['price'] ?? $offers['lowPrice'] ?? null;
            $availability = strtolower((string) ($offers['availability'] ?? ''));

            $out[] = [
                'productId' => $id,
                'title' => $title,
                'handle' => $id,
                'vendor' => null,
                'image' => $this->imageUrl($node['image'] ?? null, $origin),
                'price' => is_scalar($price) ? (string) $price : null,
                'currency' => is_string($offers['priceCurrency'] ?? null) ? strtoupper($offers['priceCurrency']) : null,
                'variantId' => $id,
                'available' => ! str_contains($availability, 'outofstock') && ! str_contains($availability, 'soldout'),
                'url' => $url,
            ];
        }

        return $out;
    }

    /** schema.org @type may be a string or a list (e.g. ["Product","Thing"]). */
    private function isProductNode(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        return in_array('Product', $types, true);
    }

    /** image: string | [string, ...] | ImageObject{url} | [ImageObject, ...]. */
    private function imageUrl(mixed $image, string $origin): ?string
    {
        if (is_array($image) && array_is_list($image)) {
            $image = $image[0] ?? null;
        }
        if (is_array($image)) {
            $image = $image['url'] ?? null;
        }

        return is_string($image) && $image !== '' ? $this->absoluteUrl($image, $origin) : null;
    }

    private function firstString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
