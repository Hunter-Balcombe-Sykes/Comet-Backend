<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Log;

// V5 Shopify scraper — fetches products from a Shopify store via the public
// storefront endpoints (/meta.json, /products.json). These endpoints are
// undocumented but stable, and require no authentication.
//
// Falls back to empty items with a logged warning when the store's endpoints
// are disabled or unreachable.
class ShopifyScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Fetch products + brand info from a Shopify store.
     *
     * @param string $identifier The store origin URL (e.g. https://mystore.myshopify.com)
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        $origin = rtrim($identifier, '/');

        // Fetch /meta.json for store name
        $meta = $this->safeJsonFetch($origin.'/meta.json');
        $storeName = is_array($meta) ? ($meta['name'] ?? null) : null;

        // Fetch /products.json for catalog (paginated by offset, limit 250)
        $allProducts = [];
        $offset = 0;
        $maxPages = 4; // up to ~1000 products

        for ($page = 0; $page < $maxPages; $page++) {
            $params = http_build_query(['limit' => 250, 'offset' => $offset]);
            $data = $this->safeJsonFetch($origin.'/products.json?'.$params);
            if (! is_array($data) || empty($data['products'])) {
                break;
            }
            $allProducts = array_merge($allProducts, $data['products']);
            if (count($data['products']) < 250) {
                break; // last page
            }
            $offset += 250;
        }

        $items = [];
        foreach ($allProducts as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }
            $variant = $product['variants'][0] ?? null;
            if (! is_array($variant)) {
                continue;
            }

            $handle = (string) ($product['handle'] ?? '');
            $values = [
                ['field_name' => 'title', 'value' => (string) ($product['title'] ?? ''), 'format' => 'text'],
                ['field_name' => 'price', 'value' => (string) ($variant['price'] ?? ''), 'format' => 'text'],
                ['field_name' => 'url', 'value' => $origin.'/products/'.$handle, 'format' => 'url'],
            ];

            $description = $product['body_html'] ?? null;
            if (! empty($description)) {
                $values[] = ['field_name' => 'description', 'value' => $this->sanitizeDescription($description), 'format' => 'text'];
            }

            $items[] = [
                'identifier' => (string) $product['id'],
                'name' => (string) ($product['title'] ?? 'Untitled'),
                'item_type' => 'product',
                'values' => $values,
            ];
        }

        if (empty($items)) {
            Log::info('v5.shopify.empty', [
                'origin' => $origin,
                'meta_found' => $meta !== null,
            ]);
        }

        $profile = [];
        if (is_string($storeName) && $storeName !== '') {
            $profile['display_name'] = $storeName;
        }

        return ['items' => $items, 'profile' => $profile];
    }

    /**
     * Safely fetch a JSON endpoint.
     *
     * @return array|null Decoded JSON or null on failure
     */
    private function safeJsonFetch(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || ($res['status'] ?? 0) !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);

        return is_array($data) ? $data : null;
    }
}
