<?php

namespace App\Services\Platforms;

// W9: the sole synchronous brand_id derivation, so a deferred connect can
// write a truthfully-keyed site.shop_brands row BEFORE the brand-profile
// fetch runs. Every branch below calls the exact scraper method fetchBrand()
// itself calls for that provider — never a reimplementation. Woo and
// Squarespace derive an id from the host with DIFFERENT expressions
// (`www-example-com` vs `example-com` for the same https://www.example.com);
// reimplementing either here instead of delegating would key a pending row
// on a different id than fetchBrand() later produces, silently creating a
// duplicate brand row. ShopBrandIdentityTest pins this against
// ShopBrandProfiler::forDetected() for all six provider paths.
class ShopBrandIdentity
{
    public function __construct(
        private readonly ShopifyScraper $shopify,
        private readonly WooCommerceScraper $woocommerce,
        private readonly SquarespaceScraper $squarespace,
    ) {}

    /**
     * @param  array{provider:string, origin:string, sourceUrl:string, page:array|null,
     *               store:array|null, meta?:array|null, clientBrand?:array}  $detected
     */
    public function for(array $detected): string
    {
        // Client-assisted detection already carries the id the browser fetched.
        if (isset($detected['clientBrand'])) {
            return (string) $detected['clientBrand']['id'];
        }

        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_BIGCARTEL => (string) $detected['store']['id'],
            ShopProviderDetector::PROVIDER_GENERIC => (string) $detected['page']['brand']['id'],
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => $this->woocommerce->brandIdFor($detected['origin']),
            // Mirrors SquarespaceScraper::fetchBrand()'s own origin resolution
            // verbatim (`$origin = $this->originOf($productsUrl) ?? $productsUrl`)
            // so the id is derived from the identical origin fetchBrand() will use.
            ShopProviderDetector::PROVIDER_SQUARESPACE => $this->squarespace->idFromOrigin(
                $this->squarespace->originOf($detected['sourceUrl']) ?? $detected['sourceUrl']
            ),
            // Shopify: use the meta.json ShopProviderDetector already fetched
            // during the probe (carried on 'meta') — no second HTTP call.
            default => $this->shopify->brandIdFrom($detected['meta'] ?? null, $detected['origin']),
        };
    }
}
