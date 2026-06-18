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
        'uber-eats' => 'datacach~ubereats-menu-scraper',
        'doordash' => 'alizarin_refrigerator-owner~doordash-scraper',
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
     * Actor input. Uber Eats (datacach): store_urls[] + ISO country_code.
     * DoorDash (alizarin): a single storeUrl with the menu scrape type.
     *
     * @return array<string,mixed>
     */
    private function input(string $storeUrl, string $platform): array
    {
        return match ($platform) {
            'uber-eats' => [
                'store_urls' => [$storeUrl],
                'country_code' => 'au',
            ],
            'doordash' => [
                'storeUrl' => $storeUrl,
                'scrapeType' => 'menu',
                'includeMenu' => true,
            ],
            default => [],
        };
    }

    /**
     * Uber Eats (datacach) returns a FLAT list — one row per menu item — with
     * section_name for grouping and menu_item_price in the smallest currency
     * unit (cents). Group by section, preserving first-seen order. This actor
     * exposes no store-level rating/review fields.
     *
     * @param  list<mixed>  $items
     * @return array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function mapUberEats(array $items): array
    {
        $categories = [];   // section_name => ['name' => …, 'items' => [...]]
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->cleanString(data_get($row, 'menu_item_name'));
            if ($name === null) {
                continue;
            }
            $section = $this->cleanString(data_get($row, 'section_name')) ?? 'Menu';
            $cents = data_get($row, 'menu_item_price');

            $categories[$section] ??= ['name' => $section, 'items' => []];
            $categories[$section]['items'][] = array_filter([
                'name' => $name,
                'description' => $this->cleanString(data_get($row, 'menu_item_description')),
                'price' => is_numeric($cents) ? round(((float) $cents) / 100, 2) : null,
                'image' => $this->safeUrl(data_get($row, 'menu_item_image')),
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
     * DoorDash output shape isn't publicly documented, so map DEFENSIVELY:
     * accept either a nested store object (menu → categories/sections → items)
     * or a flat item list grouped by a category field. Tuned against the logged
     * keys from the first real run.
     *
     * @param  list<mixed>  $items
     * @return array{rating:?float, reviewCount:?int, currency:?string, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function mapDoorDash(array $items): array
    {
        $first = is_array($items[0] ?? null) ? $items[0] : [];

        $groups = data_get($first, 'menu.categories')
            ?? data_get($first, 'menuCategories')
            ?? data_get($first, 'categories')
            ?? data_get($first, 'menuSections')
            ?? data_get($first, 'sections');

        $categories = is_array($groups) && $groups !== []
            ? $this->doorDashGroups($groups)
            : $this->doorDashFlat($items);

        $rating = data_get($first, 'rating') ?? data_get($first, 'ratingValue')
            ?? data_get($first, 'averageRating') ?? data_get($first, 'storeRating');
        $reviews = data_get($first, 'reviewCount') ?? data_get($first, 'numRatings')
            ?? data_get($first, 'ratingCount') ?? data_get($first, 'numReviews');

        return [
            'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
            'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
            'currency' => 'AUD',
            'categories' => $categories,
        ];
    }

    /**
     * Nested DoorDash menu groups → our category shape. Each group's items come
     * from whichever of items / menuItems / products it carries.
     *
     * @param  list<mixed>  $groups
     * @return list<array{name:string, items:list<array<string,mixed>>}>
     */
    private function doorDashGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $name = $this->cleanString(
                data_get($group, 'name') ?? data_get($group, 'title') ?? data_get($group, 'categoryName')
            ) ?? 'Menu';
            $rawItems = data_get($group, 'items') ?? data_get($group, 'menuItems') ?? data_get($group, 'products');
            $items = $this->doorDashItems(is_array($rawItems) ? $rawItems : []);
            if ($items !== []) {
                $out[] = ['name' => $name, 'items' => $items];
            }
        }

        return $out;
    }

    /**
     * Flat DoorDash item list grouped by whichever category field each row
     * carries (fallback when no nested group structure is present).
     *
     * @param  list<mixed>  $rows
     * @return list<array{name:string, items:list<array<string,mixed>>}>
     */
    private function doorDashFlat(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->itemName($row);
            if ($name === null) {
                continue;
            }
            $section = $this->cleanString(
                data_get($row, 'category') ?? data_get($row, 'categoryName')
                ?? data_get($row, 'section') ?? data_get($row, 'section_name')
            ) ?? 'Menu';
            $categories[$section] ??= ['name' => $section, 'items' => []];
            $categories[$section]['items'][] = $this->doorDashItem($row, $name);
        }

        return array_values($categories);
    }

    /**
     * @param  list<mixed>  $rawItems
     * @return list<array<string,mixed>>
     */
    private function doorDashItems(array $rawItems): array
    {
        $out = [];
        foreach ($rawItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->itemName($row);
            if ($name !== null) {
                $out[] = $this->doorDashItem($row, $name);
            }
        }

        return $out;
    }

    /** @param  array<string,mixed>  $row */
    private function doorDashItem(array $row, string $name): array
    {
        return array_filter([
            'name' => $name,
            'description' => $this->cleanString(
                data_get($row, 'description') ?? data_get($row, 'menu_item_description')
            ),
            'price' => $this->parsePrice(
                data_get($row, 'price') ?? data_get($row, 'displayPrice')
                ?? data_get($row, 'menu_item_price') ?? data_get($row, 'priceText')
            ),
            'image' => $this->safeUrl(
                data_get($row, 'image') ?? data_get($row, 'imageUrl')
                ?? data_get($row, 'menu_item_image') ?? data_get($row, 'photoUrl')
            ),
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string,mixed>  $row */
    private function itemName(array $row): ?string
    {
        return $this->cleanString(
            data_get($row, 'name') ?? data_get($row, 'itemName')
            ?? data_get($row, 'menu_item_name') ?? data_get($row, 'title')
        );
    }

    /**
     * Best-effort price → dollars float. Handles "$12.99" strings, 12.99 floats,
     * and integer cents (1299 → 12.99 — both actors price in the smallest unit).
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
