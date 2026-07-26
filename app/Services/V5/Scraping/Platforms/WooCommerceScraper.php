<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 WooCommerce scraper — fetches products from a WooCommerce store via the
// public Store API (/wp-json/wc/store/v1/products). The Store API is
// unauthenticated by design (used by Woo's own block-based checkout).
//
// NOTE: This is currently a STUB. The full implementation will:
// - Resolve both the pretty /wp-json path and the ?rest_route= fallback
// - Fetch the WP REST root for the site name
// - Call /wp-json/wc/store/v1/products for the product catalog (paginated)
// - Parse homepage HTML for favicon/logo via BaseScraper helpers
// - Convert Store API minor units (£19.00 = "1900") to decimal strings
// - Return products as V5 items with type 'product'
//
// The old implementation is at App\Services\Platforms\WooCommerceScraper.
class WooCommerceScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Fetch products from a WooCommerce store.
     *
     * @param string $identifier The store origin URL (e.g. https://mystore.com)
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // TODO: Full implementation
        // - Try $origin . '/wp-json/wc/store/v1/products?per_page=100'
        // - Fall back to $origin . '/?rest_route=' . urlencode('/wc/store/v1/products')
        // - Parse WP REST root for site name
        // - Map products to V5 items with minor unit conversion

        return [
            [
                'identifier' => 'woocommerce_stub',
                'name' => 'WooCommerce Products (Stub)',
                'item_type' => 'product',
                'values' => [
                    ['field_name' => 'note', 'value' => 'WooCommerce scraper needs API keys/OAuth configuration for full operation. See App\Services\Platforms\WooCommerceScraper for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
