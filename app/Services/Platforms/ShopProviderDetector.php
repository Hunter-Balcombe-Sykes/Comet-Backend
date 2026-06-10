<?php

namespace App\Services\Platforms;

// Decides which shop provider serves a user-pasted store URL — the user never
// picks. Probe order is cheapest-and-most-specific first:
//
//   1. Shopify   — GET <origin>/meta.json (Shopify-only endpoint).
//   2. WooCommerce — GET <origin>/wp-json/wc/store/v1/products?per_page=1.
//   3. Generic   — fetch the pasted page itself and look for schema.org
//                  Product JSON-LD (Squarespace / Wix / custom storefronts).
//
// Each probe rides SafeUrlFetcher (SSRF-guarded, 8s timeout), so the worst
// case is three bounded outbound requests. The generic probe's fetched page
// is returned so the controller doesn't re-fetch it.
class ShopProviderDetector
{
    public const PROVIDER_SHOPIFY = 'shopify';

    public const PROVIDER_WOOCOMMERCE = 'woocommerce';

    public const PROVIDER_GENERIC = 'generic';

    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly GenericShopScraper $generic,
    ) {}

    /**
     * @return array{provider:string, origin:string, sourceUrl:string, page:array|null}|null
     *                                                                                       `page` is the GenericShopScraper::fetchPage result (generic only).
     */
    public function detect(string $url): ?array
    {
        $origin = $this->shopify->originOf($url);
        if (! $origin) {
            return null;
        }

        if ($this->shopify->probe($origin)) {
            return ['provider' => self::PROVIDER_SHOPIFY, 'origin' => $origin, 'sourceUrl' => $origin, 'page' => null];
        }

        if ($this->woocommerce->probe($origin)) {
            return ['provider' => self::PROVIDER_WOOCOMMERCE, 'origin' => $origin, 'sourceUrl' => $origin, 'page' => null];
        }

        $page = $this->generic->fetchPage($url);
        if ($page !== null) {
            return ['provider' => self::PROVIDER_GENERIC, 'origin' => $origin, 'sourceUrl' => $url, 'page' => $page];
        }

        return null;
    }
}
