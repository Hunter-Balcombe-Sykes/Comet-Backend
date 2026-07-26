<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Shopify scraper — fetches products from a Shopify store via the public
// storefront endpoints (/meta.json, /products.json). These endpoints are
// undocumented but stable, and require no authentication.
//
// NOTE: This is currently a STUB. The full implementation will:
// - Call /meta.json for brand info (shop id, name, currency)
// - Call /products.json?limit=250 for the product catalog (paginated)
// - Parse homepage HTML for favicon/logo via BaseScraper helpers
// - Return products as V5 items with type 'product'
//
// The old implementation is at App\Services\Platforms\ShopifyScraper.
class ShopifyScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Fetch products from a Shopify store.
     *
     * @param string $identifier The store origin URL (e.g. https://mystore.myshopify.com)
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // TODO: Full implementation
        // $meta = $this->apiGet('/meta.json', [], $this->buildEndpoint($identifier));
        // $products = $this->paginate('/products.json', ['limit' => 250], 'offset', 1);
        // Map to V5 items with item_type 'product'
        // Parse homepage HTML for favicon/logo

        return [
            [
                'identifier' => 'shopify_stub',
                'name' => 'Shopify Products (Stub)',
                'item_type' => 'product',
                'values' => [
                    ['field_name' => 'note', 'value' => 'Shopify scraper needs API keys/OAuth configuration for full operation. See App\Services\Platforms\ShopifyScraper for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
