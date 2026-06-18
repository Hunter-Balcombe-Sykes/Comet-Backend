<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Fetches a store's full menu (categories → items → name/price/description/
// image) from Uber Eats or DoorDash via Apify, for the single shared
// site.menus table. Both platforms WAF-block our own servers and expose no
// public menu API, so Apify (residential-proxied) is the only content path —
// the same plumbing GoogleBusinessApifyScraper already uses
// (run-sync-get-dataset-items, 201 on success, config('services.apify.token')).
//
// One actor per platform. Output mapping is DEFENSIVE (multiple candidate keys,
// scheme-checked URLs) and the first row's keys are logged on each run so the
// shape can be tuned against real data. Uber Eats' actor (datacach) returns a
// FLAT list of menu items grouped by section_name with prices in cents;
// DoorDash's dataset shape isn't publicly documented, so it is mapped from
// whichever nested-group or flat-list structure it returns.
class MenuApifyScraper
{
    // owner~name form for the Apify API path.
    private const ACTORS = [
        'uber-eats' => 'natanielsantos~uber-eats-scraper',
        'doordash' => 'crawlerbros~doordash-restaurant-scraper',
    ];

    // These actors scrape WAF-protected pages and intermittently return an empty
    // result even for a valid, open store (the scrape gets bot-blocked on a large
    // fraction of runs) — so retry an empty / transient miss a few times before
    // giving up. A hard 4xx (unknown store / unrented actor) is NOT retried.
    private const MAX_ATTEMPTS = 4;

    // Per-attempt HTTP timeout (seconds). MAX_ATTEMPTS × this stays under the
    // MenuFetchJob timeout.
    private const ATTEMPT_TIMEOUT = 60;

    /**
     * Scrape one store URL on the given platform and map it to our normalized
     * menu shape. Null on missing token / failure / empty result — the caller
     * records fetch_status 'unavailable' and keeps any prior menu untouched.
     *
     * @return array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null
     */
    public function fetch(string $storeUrl, string $platform, ?string $userId = null): ?array
    {
        $actor = self::ACTORS[$platform] ?? null;
        $token = config('services.apify.token');
        if ($actor === null || ! $token) {
            return null;
        }

        // Retry empty / transient misses (the actor is flaky and returns []
        // on a large fraction of runs even for a valid store). Stop early on a
        // mapped menu (success) or a non-retryable hard error (4xx).
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attemptScrape($actor, $token, $storeUrl, $platform, $userId, $attempt);
            if ($result['menu'] !== null) {
                return $result['menu'];
            }
            if (! $result['retryable']) {
                break;
            }
        }

        return null;
    }

    /**
     * One scrape attempt. Returns the mapped menu on success, else null with a
     * `retryable` flag — empty results / timeouts / 5xx are retryable; a 4xx
     * (unknown store / unrented actor) is not.
     *
     * @return array{menu: array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null, retryable: bool}
     */
    private function attemptScrape(string $actor, string $token, string $storeUrl, string $platform, ?string $userId, int $attempt): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::ATTEMPT_TIMEOUT)
                ->post(
                    'https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items',
                    $this->input($storeUrl, $platform),
                );
        } catch (Throwable $e) {
            report($e);
            // info level: the Laravel Cloud log stream (cloud env:logs) only
            // surfaces info, so a failed scrape must log here to be diagnosable.
            Log::info('menu.apify.threw', ['platform' => $platform, 'user_id' => $userId, 'attempt' => $attempt, 'error' => $e->getMessage()]);

            return ['menu' => null, 'retryable' => true];
        }

        // run-sync-get-dataset-items returns 201 on success — ->ok() only accepts 200.
        if (! $response->successful()) {
            // 5xx is genuine Apify infra worth alerting on; 4xx (unknown store /
            // actor not rented / unsubscribed paid actor) is a hard error. Log the
            // status + body snippet at info so the exact Apify message shows in
            // cloud env:logs (which only surfaces info-level lines).
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify menu scrape failed with status '.$response->status()));
            }
            Log::info('menu.apify.not_ok', [
                'platform' => $platform,
                'user_id' => $userId,
                'attempt' => $attempt,
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 600),
            ]);

            return ['menu' => null, 'retryable' => $response->status() >= 500];
        }

        $items = $response->json();
        if (! is_array($items) || $items === []) {
            Log::info('menu.apify.empty', ['platform' => $platform, 'user_id' => $userId, 'attempt' => $attempt]);

            return ['menu' => null, 'retryable' => true];
        }

        // First-run visibility: the first row's keys, so the mapping can be tuned
        // against real data without dumping the whole (large) dataset.
        Log::info('menu.apify.keys', [
            'platform' => $platform,
            'user_id' => $userId,
            'attempt' => $attempt,
            'rows' => count($items),
            'first_keys' => is_array($items[0] ?? null) ? array_keys($items[0]) : gettype($items[0] ?? null),
        ]);

        $menu = $platform === 'uber-eats' ? $this->mapUberEats($items) : $this->mapDoorDash($items);

        // Mapped to nothing (unexpected shape / all-empty rows) — treat as a
        // retryable miss rather than a real menu.
        return $menu['categories'] === []
            ? ['menu' => null, 'retryable' => true]
            : ['menu' => $menu, 'retryable' => false];
    }

    /**
     * Actor input. Uber Eats (natanielsantos): restaurantUrls[] + scrapeMenu +
     * organizeMenuBySections (nested sections → subsections → items output).
     * DoorDash (crawlerbros): storeUrls[] (full menu sections + items).
     *
     * @return array<string,mixed>
     */
    private function input(string $storeUrl, string $platform): array
    {
        return match ($platform) {
            'uber-eats' => [
                'restaurantUrls' => [$storeUrl],
                'scrapeMenu' => true,
                'organizeMenuBySections' => true,
            ],
            'doordash' => [
                'storeUrls' => [$storeUrl],
            ],
            default => [],
        };
    }

    /**
     * Uber Eats (natanielsantos) returns one restaurant object per URL with
     * sections → subsections → items, store-level rating / reviewCount /
     * currencyCode, and item prices already in dollars. Each subsection becomes
     * one of our categories.
     *
     * @param  list<mixed>  $items
     * @return array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function mapUberEats(array $items): array
    {
        $store = is_array($items[0] ?? null) ? $items[0] : [];

        $categories = [];
        foreach ((array) data_get($store, 'sections', []) as $section) {
            foreach ((array) data_get($section, 'subsections', []) as $subsection) {
                $name = $this->cleanString(data_get($subsection, 'title'))
                    ?? $this->cleanString(data_get($section, 'title'))
                    ?? 'Menu';
                $catItems = [];
                foreach ((array) data_get($subsection, 'items', []) as $item) {
                    $itemName = $this->cleanString(data_get($item, 'title'));
                    if ($itemName === null) {
                        continue;
                    }
                    $price = data_get($item, 'price');
                    $catItems[] = array_filter([
                        'name' => $itemName,
                        'description' => $this->cleanString(data_get($item, 'description')),
                        'price' => is_numeric($price) ? round((float) $price, 2) : null,
                        'image' => $this->safeUrl(data_get($item, 'imageUrl')),
                    ], fn ($v) => $v !== null);
                }
                if ($catItems !== []) {
                    $categories[] = ['name' => $name, 'items' => $catItems];
                }
            }
        }

        $rating = data_get($store, 'rating');
        $reviews = data_get($store, 'reviewCount');

        return [
            'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
            'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
            'currency' => $this->cleanString(data_get($store, 'currencyCode')) ?? 'AUD',
            'categories' => $categories,
        ];
    }

    /**
     * DoorDash (crawlerbros) returns a flat menuItems[] of { section, name,
     * description, price ("$15.30") } plus menuSections (section-name strings).
     * Group the items by their section. No store-level rating / item images.
     *
     * @param  list<mixed>  $items
     * @return array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function mapDoorDash(array $items): array
    {
        $store = is_array($items[0] ?? null) ? $items[0] : [];

        $categories = [];   // section => ['name' => …, 'items' => [...]]
        foreach ((array) data_get($store, 'menuItems', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = $this->cleanString(data_get($item, 'name'));
            if ($name === null) {
                continue;
            }
            $section = $this->cleanString(data_get($item, 'section')) ?? 'Menu';
            $categories[$section] ??= ['name' => $section, 'items' => []];
            $categories[$section]['items'][] = array_filter([
                'name' => $name,
                'description' => $this->cleanString(data_get($item, 'description')),
                'price' => $this->parsePrice(data_get($item, 'price')),
                'image' => $this->safeUrl(data_get($item, 'imageUrl') ?? data_get($item, 'image')),
            ], fn ($v) => $v !== null);
        }

        return [
            'rating' => null,
            'reviewCount' => null,
            'currency' => 'AUD',
            'categories' => array_values($categories),
        ];
    }

    /**
     * Best-effort price → dollars float. DoorDash (crawlerbros) sends "$15.30"
     * strings; also tolerates plain numeric values.
     */
    private function parsePrice(mixed $value): ?float
    {
        if (is_string($value)) {
            return preg_match('~([0-9]+(?:\.[0-9]+)?)~', str_replace(',', '', $value), $m) === 1
                ? round((float) $m[1], 2)
                : null;
        }
        if (is_int($value)) {
            return round($value / 100, 2);
        }
        if (is_float($value)) {
            return round($value, 2);
        }

        return null;
    }

    /** A trimmed http/https URL, or null — drops javascript:/data:/relative etc. */
    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    /** A non-empty trimmed string, or null. */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }
}
