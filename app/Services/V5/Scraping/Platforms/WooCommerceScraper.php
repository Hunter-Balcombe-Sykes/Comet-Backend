<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Log;

// V5 WooCommerce scraper — fetches products from a WooCommerce store via the
// public Store API (/wp-json/wc/store/v1/products). The Store API is
// unauthenticated by design (used by Woo's own block-based checkout).
//
// Falls back to empty items with a logged warning when the store's API is
// disabled or unreachable.
class WooCommerceScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    private const PRODUCTS_PATH = '/wp-json/wc/store/v1/products';

    /**
     * Fetch products + brand info from a WooCommerce store.
     *
     * @param string $identifier The store origin URL (e.g. https://mystore.com)
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        $origin = rtrim($identifier, '/');

        // Fetch the WP REST root for site name
        $root = $this->safeJsonFetch($origin.'/wp-json');
        $siteName = is_array($root) ? ($root['name'] ?? null) : null;

        // Try pretty /wp-json path first, fall back to ?rest_route=
        $products = $this->fetchStoreApi($origin);

        if (empty($products)) {
            Log::info('v5.woocommerce.empty', [
                'origin' => $origin,
                'site_name_found' => $siteName !== null,
            ]);
        }

        $items = [];
        foreach ($products as $product) {
            if (! is_array($product) || ! isset($product['id'])) {
                continue;
            }

            $prices = is_array($product['prices'] ?? null) ? $product['prices'] : [];
            $name = (string) ($product['name'] ?? '');
            $values = [
                ['field_name' => 'title', 'value' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5), 'format' => 'text'],
                ['field_name' => 'url', 'value' => (string) ($product['permalink'] ?? $origin), 'format' => 'url'],
            ];

            $price = $this->minorToDecimal($prices['price'] ?? null, (int) ($prices['currency_minor_unit'] ?? 2));
            if ($price !== null) {
                $values[] = ['field_name' => 'price', 'value' => $price, 'format' => 'text'];
            }

            $description = $product['short_description'] ?? $product['description'] ?? null;
            if (! empty($description)) {
                $values[] = ['field_name' => 'description', 'value' => $this->sanitizeDescription($description), 'format' => 'text'];
            }

            $items[] = [
                'identifier' => (string) $product['id'],
                'name' => $name !== '' ? $name : 'Untitled',
                'item_type' => 'product',
                'values' => $values,
            ];
        }

        $profile = [];
        if (is_string($siteName) && $siteName !== '') {
            $profile['display_name'] = $siteName;
        }

        return ['items' => $items, 'profile' => $profile];
    }

    /**
     * Try both URL forms of the Store API.
     *
     * @return list<array>
     */
    private function fetchStoreApi(string $origin): array
    {
        $urls = [
            $origin.self::PRODUCTS_PATH.'?per_page=100',
            $origin.'/?rest_route='.rawurlencode('/wc/store/v1/products').'&per_page=100',
        ];

        foreach ($urls as $url) {
            $data = $this->safeJsonFetch($url);
            if (is_array($data) && array_is_list($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Store-API minor units to decimal string.
     */
    private function minorToDecimal(mixed $minor, int $minorUnit): ?string
    {
        if ($minor === null || ! preg_match('/^\d+$/', (string) $minor)) {
            return null;
        }
        $digits = (string) $minor;
        if ($minorUnit <= 0) {
            return $digits;
        }
        $padded = str_pad($digits, $minorUnit + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$minorUnit).'.'.substr($padded, -$minorUnit);
    }

    /**
     * Safely fetch a JSON endpoint.
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
