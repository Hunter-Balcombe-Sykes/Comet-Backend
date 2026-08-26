<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Square Online menu driver — FIRST-PARTY HTTP, no Apify (rebuilt 2026-08-26,
// menu deep-links plan B2/A0.1; the old menus-r-us AI-extraction actor emitted
// no item ids or URLs and is deleted with the plan's Part C).
//
// Square Online stores (square.site AND custom domains) are Weebly-hosted and
// expose an unauthenticated structured JSON API. One fetch of the store page
// yields the two ids the API needs (`"user":{"id":N` and `"site_id":N` in the
// embedded bootstrap state), then
//   https://cdn5.editmysite.com/app/store/api/v28/editor/users/{u}/sites/{s}/products
// returns the complete catalog: exact names/prices/descriptions/images,
// category, `inventory` stock flags, the Square catalog id, and
// `absolute_site_link` — a ready-made per-item product-page URL. All verified
// live against order.fat-tuna.com (plan A0.1) — better data than any actor,
// at zero scrape cost.
//
// Registry: `'transport' => 'http'`, no `actor` — MenuApifyScraper routes this
// platform through fetchMenu() (MenuHttpDriver) instead of the actor pool.
// Square menus are location-independent (no consumer address, no
// pickup/delivery mode split — the single fetch prices both modes).
class SquareMenuDriver implements MenuHttpDriver, MenuPlatformDriver
{
    use NormalizesMenuData;

    private const API_BASE = 'https://cdn5.editmysite.com/app/store/api/v28/editor';

    /** Rule 3 (OutboundHttpGuard, pattern D): the only variable URL segments
     *  are the two numeric ids interpolated into API_BASE paths. */
    private const API_ID_PATTERN = '/^\d{4,20}$/';

    private const USER_AGENT = 'Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)';

    private const TIMEOUT_SECONDS = 20;

    private const PER_PAGE = 100;

    /** Hard page cap — 100×20 = 2000 products, far beyond any real menu. */
    private const MAX_PAGES = 20;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    public function fetchMenu(string $storeUrl): ?array
    {
        $html = $this->fetchHtml($storeUrl);
        if ($html === null) {
            return null;
        }

        $ids = $this->extractIds($html);
        if ($ids === null) {
            Log::info('menu.square.ids_not_found', ['url' => $storeUrl]);

            return null;
        }

        $products = $this->fetchProducts($ids['user'], $ids['site']);
        if ($products === null) {
            return null;
        }

        return [
            'store' => [
                'name' => $this->storeName($html),
                // Square Online exposes no public store rating.
                'rating' => null,
                'reviewCount' => null,
                'currency' => $this->storeCurrency($html) ?? 'AUD',
                'logo' => null,
                'diningModes' => null,
            ],
            'categories' => $this->categorize($products, $storeUrl),
        ];
    }

    /** @return list<array{name:string, items:list<array<string,mixed>>}> */
    private function categorize(array $products, string $storeUrl): array
    {
        $byCategory = [];
        $order = [];

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            $name = $this->titleCase($this->cleanString($product['name'] ?? null));
            if ($name === null) {
                continue;
            }

            $category = $this->cleanString(data_get($product, 'category.data.name'))
                ?? $this->cleanString(data_get($product, 'category.name'))
                ?? 'Menu';
            if (! isset($byCategory[$category])) {
                $byCategory[$category] = [];
                $order[] = $category;
            }

            $price = data_get($product, 'price.high');

            $byCategory[$category][] = [
                'externalId' => $this->cleanString($product['id'] ?? null),
                'name' => $name,
                'description' => $this->sentenceCase($this->cleanString(strip_tags((string) ($product['short_description'] ?? '')))),
                'price' => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => null,
                'image' => $this->safeUrl(data_get($product, 'images.data.0.absolute_url')),
                'rating' => null,
                'ratingCount' => null,
                'badges' => null,
                'itemUrl' => $this->itemUrl($product, $storeUrl),
                'soldOut' => $this->soldOut($product['inventory'] ?? null),
            ];
        }

        $categories = [];
        foreach ($order as $category) {
            if ($byCategory[$category] !== []) {
                $categories[] = ['name' => $category, 'items' => $byCategory[$category]];
            }
        }

        return $categories;
    }

    /**
     * `absolute_site_link` when the API supplies it; else composed from the
     * connected store origin + relative `site_link`. Either way it is the
     * item's own product page — never a store-root fallback.
     */
    private function itemUrl(array $product, string $storeUrl): ?string
    {
        $absolute = $this->safeUrl($product['absolute_site_link'] ?? null);
        if ($absolute !== null) {
            return $absolute;
        }

        $relative = $this->cleanString($product['site_link'] ?? null);
        $origin = $this->origin($storeUrl);
        if ($relative === null || $origin === null) {
            return null;
        }

        return $this->safeUrl($origin.'/'.ltrim($relative, '/'));
    }

    /**
     * Square's inventory flags → the normalized soldOut bool. Tracking off
     * (`enabled: false`) means the store makes no stock claim — null, not
     * "in stock".
     */
    private function soldOut(mixed $inventory): ?bool
    {
        if (! is_array($inventory)) {
            return null;
        }
        if (($inventory['all_variations_sold_out'] ?? false) === true
            || ($inventory['marked_sold_out_at_all_existing_locations'] ?? false) === true) {
            return true;
        }

        return ($inventory['enabled'] ?? false) === true ? false : null;
    }

    /**
     * The store page is a USER-SUPPLIED URL (the connected store link) —
     * fetched through SafeUrlFetcher (OutboundHttpGuard pattern B: SSRF
     * checks, redirect discipline, byte caps come with the door).
     */
    private function fetchHtml(string $storeUrl): ?string
    {
        $result = $this->fetcher->tryFetch($storeUrl, ['User-Agent' => self::USER_AGENT]);
        if ($result === null || ($result['status'] ?? 0) >= 400 || ! is_string($result['body'] ?? null)) {
            Log::info('menu.square.page_fetch_failed', ['url' => $storeUrl, 'status' => $result['status'] ?? null]);

            return null;
        }

        return $result['body'];
    }

    /**
     * The two API ids from the store page's embedded bootstrap state.
     * Anchors verified server-side (A0.1): `"user":{"id":139318758` and
     * `"site_id":833850756789237813`. No store-location id is needed —
     * the site-level products endpoint returns the full catalog.
     *
     * @return array{user:string, site:string}|null
     */
    private function extractIds(string $html): ?array
    {
        if (preg_match('/"user"\s*:\s*\{\s*"id"\s*:\s*(\d{4,})/', $html, $user) !== 1) {
            return null;
        }
        if (preg_match('/"site_id"\s*:\s*"?(\d{10,})"?/', $html, $site) !== 1) {
            return null;
        }

        return ['user' => $user[1], 'site' => $site[1]];
    }

    /** @return list<array<string,mixed>>|null */
    private function fetchProducts(string $userId, string $siteId): ?array
    {
        // Rule 3: both interpolated segments validated before any URL is
        // built (belt over the extraction regexes' own digit anchors).
        if (preg_match(self::API_ID_PATTERN, $userId) !== 1 || preg_match(self::API_ID_PATTERN, $siteId) !== 1) {
            return null;
        }

        $products = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            try {
                $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get(self::API_BASE."/users/{$userId}/sites/{$siteId}/products", [
                        'page' => $page,
                        'per_page' => self::PER_PAGE,
                        'visibilities[]' => 'visible',
                        'include' => 'images,category',
                    ]);
            } catch (\Throwable $e) {
                Log::info('menu.square.products_fetch_failed', ['error' => $e->getMessage(), 'page' => $page]);

                return $products === [] ? null : $products;
            }

            if (! $resp->successful()) {
                Log::info('menu.square.products_http_error', ['status' => $resp->status(), 'page' => $page]);

                return $products === [] ? null : $products;
            }

            $batch = (array) $resp->json('data');
            $products = array_merge($products, $batch);

            $total = (int) $resp->json('meta.pagination.total', count($products));
            if (count($batch) < self::PER_PAGE || count($products) >= $total) {
                break;
            }
        }

        return $products === [] ? null : $products;
    }

    private function storeName(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property="og:site_name"[^>]+content="([^"]+)"/i', $html, $m) === 1
            || preg_match('/<title>([^<]+)<\/title>/i', $html, $m) === 1) {
            return $this->cleanString(html_entity_decode($m[1]));
        }

        return null;
    }

    private function storeCurrency(string $html): ?string
    {
        if (preg_match('/"currency"\s*:\s*"([A-Z]{3})"/', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'];
    }

    // ── MenuPlatformDriver (actor transport) — not used for Square. ─────────
    // The registry keeps the shared driver contract so driverFor() stays one
    // type; an actor-transport call reaching an http driver is a wiring bug
    // and fails loudly rather than silently scraping nothing.

    public function buildInput(string $storeUrl, ?string $address): array
    {
        throw new \LogicException('SquareMenuDriver is transport=http — buildInput() has no actor to feed.');
    }

    public function mapItems(array $items): array
    {
        throw new \LogicException('SquareMenuDriver is transport=http — mapItems() has no actor payload to map.');
    }
}
