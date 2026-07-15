<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Last-resort shop provider: reads schema.org Product JSON-LD off the exact
// page the user pasted (a shop/collection/landing page). Covers Squarespace,
// Wix, BigCommerce and custom storefronts that emit standard product markup —
// no platform API, no auth. The page URL is stored on the brand as
// `sourceUrl` so re-fetches read the same page.
class GenericShopScraper extends PlatformScraper
{
    // readProductPage() outcomes — the controller keys 422 codes off these.
    public const OUTCOME_PRODUCT = 'product';

    public const OUTCOME_STORE_PAGE = 'store_page';

    public const OUTCOME_NO_PRODUCT = 'no_product';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    // Gallery cap — mirrors ShopifyScraper::MAX_IMAGES so no provider's product
    // row can bloat past a sane multi-image-strip size.
    private const MAX_IMAGES = 25;

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
        return $this->fetchPageDetailed($url)['page'];
    }

    /**
     * fetchPage() plus the discriminators ShopProviderDetector needs to tell
     * "unsupported store type" apart from "blocked/unreachable" (WS-B1.2):
     * whether the page itself served 200 HTML, and whether that HTML carries
     * supported-storefront tech markers (platform present but its API
     * blocked/disabled ≠ not a store platform at all).
     *
     * @return array{page: array{brand: array{id:string, name:?string, currency:?string, favicon:?string, logo:?string}, products: list<array<string,mixed>>}|null, reachable: bool, storefrontMarkers: bool}
     */
    public function fetchPageDetailed(string $url): array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return ['page' => null, 'reachable' => false, 'storefrontMarkers' => false];
        }
        $html = $res['body'];
        $origin = $this->originOf($res['finalUrl']) ?? $this->originOf($url) ?? $url;

        $products = $this->productsFromJsonLd($html, $res['finalUrl'], $origin);
        if ($products === []) {
            return ['page' => null, 'reachable' => true, 'storefrontMarkers' => $this->looksLikeStorefront($html)];
        }

        $name = $this->metaContent($html, 'og:site_name')
            ?? (preg_match('~<title[^>]*>([^<]+)</title>~i', $html, $m)
                ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5)
                : null);

        return [
            'page' => [
                'brand' => [
                    'id' => preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string) parse_url($origin, PHP_URL_HOST))),
                    'name' => $name,
                    'currency' => $products[0]['currency'] ?? null,
                    'favicon' => $this->favicon($html, $origin),
                    'logo' => $this->logo($html, $origin),
                ],
                'products' => $products,
            ],
            'reachable' => true,
            'storefrontMarkers' => true,
        ];
    }

    /**
     * Read ONE product off a single product-page URL — the universal fallback
     * for "add an individual product" from any storefront (or a store we can't
     * connect wholesale). Prefers schema.org Product JSON-LD, then OpenGraph
     * product tags. Returns null when the page yields neither.
     *
     * @return array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, url:string}|null
     */
    public function fetchSingleProduct(string $url): ?array
    {
        return $this->readProductPage($url)['product'];
    }

    /**
     * fetchSingleProduct() with a tagged outcome (WS-B1.1), so the add-product
     * endpoint can tell "you pasted a store homepage — connect it as a brand"
     * apart from a plain unreadable page:
     *
     *   - `product`     — a product was extracted; `product` is set.
     *   - `store_page`  — no single product, but the URL is a site-root page
     *                     that is clearly a storefront (platform tech markers,
     *                     or a multi-product JSON-LD list). `storeUrl` carries
     *                     the origin for a "connect as brand" prefill.
     *   - `no_product`  — page served, but no product markup we recognise.
     *                     Deliberately NOT classified further: a deep URL that
     *                     fails extraction may still be a real product page,
     *                     so it must never be false-blocked as a store.
     *   - `unreachable` — the page itself couldn't be fetched (non-200).
     *
     * @return array{outcome: string, product: ?array, storeUrl: ?string}
     */
    public function readProductPage(string $url): array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'product' => null, 'storeUrl' => null];
        }
        $html = $res['body'];
        $origin = $this->originOf($res['finalUrl']) ?? $this->originOf($url) ?? $url;

        $products = $this->productsFromJsonLd($html, $res['finalUrl'], $origin);

        // A site-root page carrying a whole product LIST is a storefront, not
        // "a product" — don't silently import an arbitrary first item.
        if (count($products) >= 2 && $this->isRootUrl($res['finalUrl'])) {
            return ['outcome' => self::OUTCOME_STORE_PAGE, 'product' => null, 'storeUrl' => $origin];
        }

        $product = $products[0] ?? $this->productFromOpenGraph($html, $res['finalUrl'], $origin);
        if ($product !== null) {
            return ['outcome' => self::OUTCOME_PRODUCT, 'product' => $product, 'storeUrl' => null];
        }

        if ($this->isRootUrl($res['finalUrl']) && $this->looksLikeStorefront($html)) {
            return ['outcome' => self::OUTCOME_STORE_PAGE, 'product' => null, 'storeUrl' => $origin];
        }

        return ['outcome' => self::OUTCOME_NO_PRODUCT, 'product' => null, 'storeUrl' => null];
    }

    /** True when the URL has no path beyond "/" — a homepage, not a product page. */
    private function isRootUrl(string $url): bool
    {
        return rtrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/') === '';
    }

    /**
     * Deterministic storefront-tech markers for the platforms we can connect
     * as a brand. Asset/runtime signatures only (not prose mentioning a
     * platform), so a positive means a brand connect is likely to work.
     */
    private function looksLikeStorefront(string $html): bool
    {
        return (bool) preg_match(
            '~cdn\.shopify\.com|/cdn/shop/|window\.Shopify|Shopify\.theme'
            .'|plugins/woocommerce|class=["\'][^"\']*\bwoocommerce'
            .'|Static\.SQUARESPACE_CONTEXT|assets\.squarespace\.com'
            .'|bigcartel\.com~i',
            $html,
        );
    }

    /**
     * OpenGraph product fallback — many storefronts (Shopify product pages,
     * WooCommerce, custom) emit og:title/og:image + product:price:amount even
     * when the page carries no Product JSON-LD. Requires a deterministic
     * product signal (og:type contains "product", or an explicit price meta):
     * og:title alone is ANY webpage — a brand homepage must not become a
     * "product" (WS-B1.1, abovetheground.co regression).
     *
     * @return array{productId:string, title:string, handle:string, vendor:?string, image:?string, price:?string, currency:?string, variantId:string, available:bool, url:string}|null
     */
    private function productFromOpenGraph(string $html, string $pageUrl, string $origin): ?array
    {
        $price = $this->metaContent($html, 'product:price:amount')
            ?? $this->metaContent($html, 'og:price:amount');
        $ogType = strtolower((string) $this->metaContent($html, 'og:type'));

        if (! str_contains($ogType, 'product') && ($price === null || trim($price) === '')) {
            return null;
        }

        $title = $this->metaContent($html, 'og:title')
            ?? (preg_match('~<title[^>]*>([^<]+)</title>~i', $html, $m)
                ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5)
                : null);
        if (! is_string($title) || trim($title) === '') {
            return null;
        }
        $title = trim($title);

        $image = $this->metaContent($html, 'og:image');
        $currency = $this->metaContent($html, 'product:price:currency')
            ?? $this->metaContent($html, 'og:price:currency');
        $url = $this->absoluteUrl($this->metaContent($html, 'og:url') ?? $pageUrl, $origin);
        $id = substr(md5($url.'|'.$title), 0, 16);

        return [
            'productId' => $id,
            'title' => $title,
            'handle' => $id,
            'vendor' => null,
            'image' => is_string($image) && $image !== '' ? $this->absoluteUrl($image, $origin) : null,
            'price' => is_string($price) && trim($price) !== '' ? trim($price) : null,
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
            'variantId' => $id,
            'available' => true,
            'url' => $url,
        ];
    }

    /**
     * Collect schema.org Product nodes — direct, inside @graph, or inside
     * ItemList.itemListElement (both ListItem.item and bare Product forms) —
     * and flatten to the canonical product shape.
     *
     * @return list<array{productId:string, title:string, handle:string, vendor:?string, description:?string, image:?string, images:list<string>, price:?string, currency:?string, variantId:string, available:bool, url:string}>
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
            // SEO plugins (Yoast/Rank Math) HTML-encode entities inside JSON-LD
            // strings ("FEAR NO EVIL &bull; Bulwark Jacket") — decode like the
            // platform scrapers do for their titles.
            $title = is_string($node['name'] ?? null)
                ? html_entity_decode(trim($node['name']), ENT_QUOTES | ENT_HTML5)
                : '';
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
                'description' => $this->sanitizeDescription($node['description'] ?? null),
                'image' => $this->imageUrl($node['image'] ?? null, $origin),
                'images' => $this->imageUrls($node['image'] ?? null, $origin),
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

    /**
     * image: string | [string, ...] | ImageObject{url} | [ImageObject, ...] → every
     * resolved absolute URL (capped), for a multi-image strip. `imageUrl()` above
     * stays the single-hero reader; this normalizes the same shapes to the full list.
     *
     * @return list<string>
     */
    private function imageUrls(mixed $image, string $origin): array
    {
        $list = is_array($image) && array_is_list($image) ? $image : ($image !== null ? [$image] : []);

        $out = [];
        foreach ($list as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : $item;
            if (! is_string($url) || $url === '') {
                continue;
            }
            if (count($out) >= self::MAX_IMAGES) {
                break;
            }
            $out[] = $this->absoluteUrl($url, $origin);
        }

        return $out;
    }

    // schema.org description → plain-text: sanitizeDescription() lives on the
    // shared PlatformScraper base — identical logic, once, for every scraper.

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
