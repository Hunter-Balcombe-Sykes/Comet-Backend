<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Gumroad creator storefront (the remaining-work audit's #21): keyless.
 * {subdomain}.gumroad.com is an Inertia app — the whole page state ships in
 * `<div id="app" data-page="...">` (component "Users/Show"), whose props
 * carry `sections[]`: `SellerProfileProductsSection` rows hold
 * `search_results.products[]` and `SellerProfileFeaturedProductSection`
 * holds one product under `props.product`. Shape verified against live
 * profiles 2026-07-28 (products: id, permalink, name, price_cents,
 * currency_code, thumbnail_url, ratings{count,average},
 * is_pay_what_you_want, url). Gumroad's OAuth API is own-account-only —
 * scrape stays (plan §11's no-user-OAuth rule).
 *
 * Coverage is deliberately UNKNOWN: a products section may paginate
 * server-side past what the first page embeds, and nothing in the payload
 * says so — so absence must never delete (the null orderField already
 * forbids it; unknown keeps the claim honest too).
 */
class GumroadConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('gumroad'),
            identifierKind: 'subdomain',
            hosts: ['*.gumroad.com', 'gumroad.com'],
            streams: [
                'products' => new StreamSpec(
                    name: 'products',
                    target: 'product',
                    profile: SourceProfile::Catalogue,
                    requires: ['name', 'url'],
                    volatile: [],
                    // No time order exists to reason about a prefix with, so
                    // this stream can never delete.
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 86400,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $subdomain = strtolower(trim($pull->identifier, " \t\n\r\0\x0B/."));
        $response = $io->get("https://{$subdomain}.gumroad.com/");

        if ($response['status'] !== 200 || $response['body'] === '') {
            // Unreachable is never "the store emptied" (C5).
            yield new Unavailable("store page returned {$response['status']}", $response['status']);

            return;
        }

        $page = $this->inertiaPage($response['body']);
        if ($page === null) {
            // A 200 without the Inertia payload is a layout change or an
            // interstitial — a shape break, not an empty store.
            yield new Unavailable('store page carried no parseable Inertia data-page payload');

            return;
        }

        $products = $this->products($page);

        if ($products === []) {
            // A real storefront with zero listed products is legitimate; so
            // is a profile whose sections lazy-load. Either way: no coverage,
            // nothing can be tombstoned.
            yield new Note('no_products', 'No products found in the profile sections');

            return;
        }

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $products = array_slice($products, 0, $limit);
        }

        foreach ($products as $product) {
            yield new Record('products', $product['key'], $product['doc']);
        }

        yield new Covered('products', Coverage::unknown());
    }

    /** @return array<string, mixed>|null */
    private function inertiaPage(string $html): ?array
    {
        if (! preg_match('~<div[^>]+id="app"[^>]+data-page="([^"]+)"~s', $html, $m)
            && ! preg_match('~data-page="([^"]+)"~s', $html, $m)) {
            return null;
        }

        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Every product across the profile's sections, deduped by permalink —
     * a featured product usually re-appears inside the products grid.
     *
     * @param  array<string, mixed>  $page
     * @return list<array{key: string, doc: array<string, mixed>}>
     */
    private function products(array $page): array
    {
        $sections = $page['props']['sections'] ?? [];
        if (! is_array($sections)) {
            return [];
        }

        $out = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $candidates = match ($section['type'] ?? null) {
                'SellerProfileProductsSection' => (array) data_get($section, 'search_results.products', []),
                'SellerProfileFeaturedProductSection' => array_filter([data_get($section, 'props.product')]),
                default => [],
            };

            foreach ($candidates as $candidate) {
                $product = $this->mapProduct($candidate);
                if ($product !== null && ! isset($out[$product['key']])) {
                    $out[$product['key']] = $product;
                }
            }
        }

        return array_values($out);
    }

    /** @return array{key: string, doc: array<string, mixed>}|null */
    private function mapProduct(mixed $product): ?array
    {
        if (! is_array($product)) {
            return null;
        }

        $name = is_string($product['name'] ?? null) ? trim($product['name']) : '';
        // The featured-product props carry the canonical link as `long_url`;
        // grid products carry `url` (with a ?layout=profile suffix to strip).
        $url = is_string($product['long_url'] ?? null) ? $product['long_url']
            : (is_string($product['url'] ?? null) ? strtok($product['url'], '?') : null);
        $permalink = is_string($product['permalink'] ?? null) ? $product['permalink'] : null;

        if ($name === '' || $url === null || $url === '' || $permalink === null) {
            return null;
        }

        $priceCents = is_numeric($product['price_cents'] ?? null) ? (int) $product['price_cents'] : null;
        $ratings = is_array($product['ratings'] ?? null) ? $product['ratings'] : [];

        return ['key' => $permalink, 'doc' => array_filter([
            'permalink' => $permalink,
            'name' => $name,
            'url' => $url,
            'price_cents' => $priceCents,
            'currency' => is_string($product['currency_code'] ?? null) ? strtoupper($product['currency_code']) : null,
            'pay_what_you_want' => ($product['is_pay_what_you_want'] ?? null) === true ? true : null,
            'thumbnail' => is_string($product['thumbnail_url'] ?? null) ? $product['thumbnail_url'] : null,
            'rating' => is_numeric($ratings['average'] ?? null) ? (float) $ratings['average'] : null,
            'ratings_count' => is_numeric($ratings['count'] ?? null) ? (int) $ratings['count'] : null,
            'native_type' => is_string($product['native_type'] ?? null) ? $product['native_type'] : null,
        ], static fn ($v) => $v !== null)];
    }
}
