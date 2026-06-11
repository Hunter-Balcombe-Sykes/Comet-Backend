<?php

namespace App\Services\Platforms;

// Decides which shop provider serves a user-pasted store URL — the user never
// picks. Probe order is cheapest-and-most-specific first:
//
//   1. Big Cartel — pure host match (*.bigcartel.com) + store.json round-trip.
//   2. Shopify    — GET <origin>/meta.json (Shopify-only endpoint).
//   3. WooCommerce — GET <origin>/wp-json/wc/store/v1/products?per_page=1.
//   4. Squarespace — GET <page>?format=json on the pasted URL then the
//                    conventional shop paths, looking for a products collection.
//   5. Generic    — fetch the pasted page itself and look for schema.org
//                   Product JSON-LD (Wix / custom storefronts).
//
// Each probe rides SafeUrlFetcher (SSRF-guarded, 8s timeout). Probe results
// that downstream steps need again travel back on the result (`page` for
// generic, `store` for Big Cartel, `sourceUrl` doubles as the Squarespace
// products-collection URL) so the controller never re-fetches.
class ShopProviderDetector
{
    public const PROVIDER_SHOPIFY = 'shopify';

    public const PROVIDER_WOOCOMMERCE = 'woocommerce';

    public const PROVIDER_SQUARESPACE = 'squarespace';

    public const PROVIDER_BIGCARTEL = 'bigcartel';

    public const PROVIDER_GENERIC = 'generic';

    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
        private readonly BigCartelScraper $bigcartel,
        private readonly GenericShopScraper $generic,
    ) {}

    /**
     * @return array{provider:string, origin:string, sourceUrl:string, page:array|null, store:array|null}|null
     *                                                                                                         `page` is the GenericShopScraper::fetchPage result (generic only);
     *                                                                                                         `store` is the BigCartelScraper::fetchStore result (bigcartel only).
     */
    public function detect(string $url): ?array
    {
        $origin = $this->shopify->originOf($url);
        if (! $origin) {
            return null;
        }

        // Host is decisive for Big Cartel — no point probing anything else.
        if ($account = $this->bigcartel->accountFromUrl($url)) {
            $store = $this->bigcartel->fetchStore($account);

            return $store === null ? null : [
                'provider' => self::PROVIDER_BIGCARTEL,
                'origin' => $store['origin'],
                'sourceUrl' => $store['origin'],
                'page' => null,
                'store' => $store,
            ];
        }

        if ($this->shopify->probe($origin)) {
            return ['provider' => self::PROVIDER_SHOPIFY, 'origin' => $origin, 'sourceUrl' => $origin, 'page' => null, 'store' => null];
        }

        if ($this->woocommerce->probe($origin)) {
            return ['provider' => self::PROVIDER_WOOCOMMERCE, 'origin' => $origin, 'sourceUrl' => $origin, 'page' => null, 'store' => null];
        }

        // sourceUrl = the discovered products-collection URL — product fetches
        // and refreshes hit it directly instead of re-discovering.
        if ($productsUrl = $this->squarespace->discoverProductsUrl($url)) {
            return ['provider' => self::PROVIDER_SQUARESPACE, 'origin' => $origin, 'sourceUrl' => $productsUrl, 'page' => null, 'store' => null];
        }

        $page = $this->generic->fetchPage($url);
        if ($page !== null) {
            return ['provider' => self::PROVIDER_GENERIC, 'origin' => $origin, 'sourceUrl' => $url, 'page' => $page, 'store' => null];
        }

        return null;
    }
}
