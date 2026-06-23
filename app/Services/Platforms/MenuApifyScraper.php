<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Scrapes ONE store's full menu from Uber Eats or DoorDash via Apify and maps it
// to a normalized shape that MenuMerger then fuses across the two platforms. Both
// platforms WAF-block our own servers and expose no public menu API, so Apify
// (residential-proxied) is the only content path — the same plumbing
// GoogleBusinessApifyScraper uses (run-sync-get-dataset-items, 201 on success,
// config('services.apify.token')).
//
// Actors (captured live 2026-06-19):
//   Uber Eats → natanielsantos~uber-eats-scraper — one restaurant object with
//       sections → subsections → items; item price already in dollars; per-item
//       imageUrl, isSoldOut, uuid, and optionsList (modifier groups). Store-level
//       rating / reviewCount / currencyCode / logoUrl.
//   DoorDash  → dz_omar~doordash-scraper — one store object with menu_categories[]
//       → items[] of { item_id, name, description, price_cents, image_url,
//       rating_pct, rating_count, badges[] }. Needs an `address` (locale) input;
//       store-level rating / num_ratings / currency / cover image. Free actor.
//
// Normalized output:
//   [ 'store' => [ name, rating, reviewCount, currency, logo ],
//     'categories' => [ [ 'name' => …, 'items' => [ [
//        externalId, name, description, price, image, isSoldOut,
//        modifiers (UE only), rating, ratingCount, badges (DD only) ] … ] ] ] ]
class MenuApifyScraper
{
    // owner~name form for the Apify API path.
    private const ACTORS = [
        'uber-eats' => 'natanielsantos~uber-eats-scraper',
        'doordash' => 'dz_omar~doordash-scraper',
    ];

    // These actors scrape WAF-protected pages and intermittently return an empty
    // result even for a valid, open store (the scrape gets bot-blocked on a large
    // fraction of runs) — so retry an empty / transient miss a few times before
    // giving up. A hard 4xx (unknown store / unrented actor) is NOT retried.
    private const MAX_ATTEMPTS = 4;

    // Per-attempt HTTP timeout (seconds). MAX_ATTEMPTS × this stays under the
    // MenuFetchJob timeout.
    private const ATTEMPT_TIMEOUT = 60;

    // DoorDash results are location-specific; with no consumer address the actor
    // defaults to Chicago, IL (wrong country). A valid AU locale keeps results
    // Australian even when the store sits in another AU city — the specific store
    // URL still resolves and its menu returns regardless of delivery range.
    private const DOORDASH_FALLBACK_ADDRESS = 'Melbourne VIC, Australia';

    /**
     * Scrape one store URL on the given platform and map it to the normalized
     * menu shape. Null on missing token / failure / empty result — the caller
     * records the per-platform status 'unavailable' and keeps any prior menu.
     *
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null
     */
    public function fetch(string $storeUrl, string $platform, ?string $userId = null, ?string $address = null): ?array
    {
        $actor = self::ACTORS[$platform] ?? null;
        $token = config('services.apify.token');
        if ($actor === null || ! $token) {
            return null;
        }

        // Retry empty / transient misses (the actor is flaky and returns []
        // on a fraction of runs even for a valid store). Stop early on a mapped
        // menu (success) or a non-retryable hard error (4xx).
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attemptScrape($actor, $token, $storeUrl, $platform, $address, $userId, $attempt);
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
     * Scrape ONE store across both fulfilment modes and fuse into a single menu
     * whose items each carry a `pickupPrice` AND a `deliveryPrice` (either null).
     *
     * Uber Eats / DoorDash price the same dish differently for pickup vs delivery
     * (delivery menus are commonly marked up), and the price only exists on the
     * mode-specific store page — so we scrape the pickup URL and the delivery URL
     * separately and key the two price sets together by the item's stable external
     * id (UE uuid / DD item_id survive the mode switch). When the store link is
     * untyped (one bare store URL, no pickup/delivery variant) we scrape once and
     * apply that single price to both modes the store offers.
     *
     * @param  array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}  $link  a MenuSource::storeLinks entry
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null null when nothing scraped (caller records 'unavailable')
     */
    public function fetchStore(array $link, string $platform, ?string $userId = null, ?string $address = null): ?array
    {
        $pickupUrl = $link['pickupUrl'] ?? null;
        $deliveryUrl = $link['deliveryUrl'] ?? null;
        $storeUrl = $link['storeUrl'] ?? null;

        // Untyped store (no mode-specific URL): one scrape, price applies to both
        // modes the store offers.
        if ($pickupUrl === null && $deliveryUrl === null) {
            if ($storeUrl === null) {
                return null;
            }
            $menu = $this->fetch($storeUrl, $platform, $userId, $address);

            return $menu === null ? null : $this->priced($menu, true, true);
        }

        $pickupMenu = $pickupUrl !== null ? $this->fetch($pickupUrl, $platform, $userId, $address) : null;
        $deliveryMenu = $deliveryUrl !== null ? $this->fetch($deliveryUrl, $platform, $userId, $address) : null;

        // Both modes scraped → fuse their prices per item. Only one came back (the
        // other mode wasn't offered, or its scrape was blocked) → that mode's price
        // only; the missing mode stays null but the order URL still routes (the
        // merger fills the URL from the store link regardless of price).
        if ($pickupMenu !== null && $deliveryMenu !== null) {
            return $this->dual($pickupMenu, $deliveryMenu);
        }
        if ($pickupMenu !== null) {
            return $this->priced($pickupMenu, true, false);
        }
        if ($deliveryMenu !== null) {
            return $this->priced($deliveryMenu, false, true);
        }

        return null;
    }

    /**
     * Re-key a single-mode menu's items onto the pickup/delivery price slots: the
     * scraped `price` becomes pickupPrice and/or deliveryPrice per the flags, and
     * the raw `price` is dropped. Used for single-mode and untyped stores.
     *
     * @param  array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}  $menu
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function priced(array $menu, bool $fillPickup, bool $fillDelivery): array
    {
        $menu['categories'] = array_map(function (array $cat) use ($fillPickup, $fillDelivery) {
            $cat['items'] = array_map(function (array $item) use ($fillPickup, $fillDelivery) {
                $price = $item['price'] ?? null;
                $item['pickupPrice'] = $fillPickup ? $price : null;
                $item['deliveryPrice'] = $fillDelivery ? $price : null;
                unset($item['price']);

                return $item;
            }, $cat['items']);

            return $cat;
        }, $menu['categories']);

        return $menu;
    }

    /**
     * Fuse the pickup + delivery scrapes of one store into a single menu. The
     * structurally richer scrape (more items) is the spine; each item gets its
     * pickupPrice from the pickup scrape and deliveryPrice from the delivery
     * scrape, matched by external id (else normalized name).
     *
     * @param  array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}  $pickupMenu
     * @param  array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}  $deliveryMenu
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function dual(array $pickupMenu, array $deliveryMenu): array
    {
        $pickupMap = $this->priceMap($pickupMenu);
        $deliveryMap = $this->priceMap($deliveryMenu);

        // Spine = the scrape with more items (delivery wins a tie — it's usually
        // the fuller menu), so a dish missing from one mode still appears.
        $base = $this->itemCount($deliveryMenu) >= $this->itemCount($pickupMenu) ? $deliveryMenu : $pickupMenu;

        $base['categories'] = array_map(function (array $cat) use ($pickupMap, $deliveryMap) {
            $cat['items'] = array_map(function (array $item) use ($pickupMap, $deliveryMap) {
                $key = $this->itemKey($item);
                $item['pickupPrice'] = $pickupMap[$key] ?? null;
                $item['deliveryPrice'] = $deliveryMap[$key] ?? null;
                unset($item['price']);

                return $item;
            }, $cat['items']);

            return $cat;
        }, $base['categories']);

        return $base;
    }

    /**
     * externalId-or-name → price for every priced item in a scraped menu (first
     * occurrence wins).
     *
     * @param  array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}  $menu
     * @return array<string, float>
     */
    private function priceMap(array $menu): array
    {
        $map = [];
        foreach ($menu['categories'] as $cat) {
            foreach ($cat['items'] as $item) {
                $price = $item['price'] ?? null;
                if (! is_numeric($price)) {
                    continue;
                }
                $key = $this->itemKey($item);
                $map[$key] ??= (float) $price;
            }
        }

        return $map;
    }

    /** A stable per-item key for cross-mode matching: the external id, else the normalized name. */
    private function itemKey(array $item): string
    {
        $ext = $item['externalId'] ?? null;
        if (is_string($ext) && $ext !== '') {
            return 'id:'.$ext;
        }

        return 'name:'.mb_strtolower(trim((string) ($item['name'] ?? '')));
    }

    /** Total item count across a scraped menu's categories. */
    private function itemCount(array $menu): int
    {
        $n = 0;
        foreach ($menu['categories'] as $cat) {
            $n += count($cat['items'] ?? []);
        }

        return $n;
    }

    /**
     * One scrape attempt. Returns the mapped menu on success, else null with a
     * `retryable` flag — empty results / timeouts / 5xx are retryable; a 4xx
     * (unknown store / unrented actor) is not.
     *
     * @return array{menu: array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null, retryable: bool}
     */
    private function attemptScrape(string $actor, string $token, string $storeUrl, string $platform, ?string $address, ?string $userId, int $attempt): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::ATTEMPT_TIMEOUT)
                ->post(
                    'https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items',
                    $this->input($storeUrl, $platform, $address),
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
            // actor not rented / unsubscribed paid actor) is a hard error.
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
     * organizeMenuBySections. DoorDash (dz_omar): startUrls[{url}] + a consumer
     * `address` (locale) — fetchReviews off (we don't surface reviews).
     *
     * @return array<string,mixed>
     */
    private function input(string $storeUrl, string $platform, ?string $address): array
    {
        return match ($platform) {
            'uber-eats' => [
                'restaurantUrls' => [$storeUrl],
                'scrapeMenu' => true,
                'organizeMenuBySections' => true,
            ],
            'doordash' => [
                'startUrls' => [['url' => $storeUrl]],
                'address' => $this->cleanString($address) ?? self::DOORDASH_FALLBACK_ADDRESS,
                'fetchReviews' => false,
            ],
            default => [],
        };
    }

    /**
     * Uber Eats (natanielsantos): one restaurant object with sections →
     * subsections → items. Each subsection becomes a category; item prices are
     * already in dollars; optionsList becomes our modifier groups.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
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
                    $itemName = $this->titleCase($this->cleanString(data_get($item, 'title')));
                    if ($itemName === null) {
                        continue;
                    }
                    $price = data_get($item, 'price');
                    $catItems[] = [
                        'externalId' => $this->cleanString(data_get($item, 'uuid')),
                        'name' => $itemName,
                        'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                        'price' => is_numeric($price) ? round((float) $price, 2) : null,
                        'image' => $this->safeUrl(data_get($item, 'imageUrl')),
                        'isSoldOut' => (bool) data_get($item, 'isSoldOut', false),
                        'modifiers' => $this->modifiers(data_get($item, 'optionsList')),
                        'rating' => null,
                        'ratingCount' => null,
                        'badges' => null,
                    ];
                }
                if ($catItems !== []) {
                    $categories[] = ['name' => $name, 'items' => $catItems];
                }
            }
        }

        $rating = data_get($store, 'rating');
        $reviews = data_get($store, 'reviewCount');

        return [
            'store' => [
                'name' => $this->cleanString(data_get($store, 'name')),
                'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
                'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
                'currency' => $this->cleanString(data_get($store, 'currencyCode')) ?? 'AUD',
                'logo' => $this->safeUrl(data_get($store, 'logoUrl')),
            ],
            'categories' => $categories,
        ];
    }

    /**
     * DoorDash (dz_omar): one store object with menu_categories[] → items[].
     * Item price is in cents; image_url / rating_pct / rating_count / badges are
     * per item. `featured_items` is skipped — it re-lists items already in the
     * categories.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    private function mapDoorDash(array $items): array
    {
        $store = is_array($items[0] ?? null) ? $items[0] : [];

        $categories = [];
        foreach ((array) data_get($store, 'menu_categories', []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $name = $this->cleanString(data_get($category, 'category_name'))
                ?? $this->cleanString(data_get($category, 'name'))
                ?? 'Menu';
            $catItems = [];
            foreach ((array) data_get($category, 'items', []) as $item) {
                $itemName = $this->titleCase($this->cleanString(data_get($item, 'name')));
                if ($itemName === null) {
                    continue;
                }
                $cents = data_get($item, 'price_cents');
                $price = is_numeric($cents)
                    ? round(((int) $cents) / 100, 2)
                    : $this->parsePrice(data_get($item, 'price_display'));
                $ratingPct = data_get($item, 'rating_pct');
                $ratingCount = data_get($item, 'rating_count');
                $catItems[] = [
                    'externalId' => $this->cleanString((string) data_get($item, 'item_id')),
                    'name' => $itemName,
                    'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                    'price' => $price,
                    'image' => $this->safeUrl(data_get($item, 'image_url')),
                    'isSoldOut' => false,
                    'modifiers' => null,
                    'rating' => is_numeric($ratingPct) ? round((float) $ratingPct, 2) : null,
                    'ratingCount' => is_numeric($ratingCount) ? (int) $ratingCount : null,
                    'badges' => $this->badges(data_get($item, 'badges')),
                ];
            }
            if ($catItems !== []) {
                $categories[] = ['name' => $name, 'items' => $catItems];
            }
        }

        $rating = data_get($store, 'rating');
        $reviews = data_get($store, 'num_ratings');

        return [
            'store' => [
                'name' => $this->cleanString(data_get($store, 'name')),
                'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
                'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
                'currency' => $this->cleanString(data_get($store, 'currency')) ?? 'AUD',
                'logo' => $this->safeUrl(data_get($store, 'cover_square_image'))
                    ?? $this->safeUrl(data_get($store, 'cover_image'))
                    ?? $this->safeUrl(data_get($store, 'header_image')),
            ],
            'categories' => $categories,
        ];
    }

    /**
     * Uber Eats optionsList → normalized modifier groups: [{ name, options:
     * [{ name, price }] }]. Option `price` is the per-option surcharge in dollars
     * (0 for no-charge choices). Null when the item has no modifiers.
     *
     * @return list<array{name?:string, options:list<array{name:string, price?:float}>}>|null
     */
    private function modifiers(mixed $optionsList): ?array
    {
        if (! is_array($optionsList) || $optionsList === []) {
            return null;
        }
        $groups = [];
        foreach ($optionsList as $group) {
            if (! is_array($group)) {
                continue;
            }
            $options = [];
            foreach ((array) data_get($group, 'options', []) as $option) {
                $optionName = $this->titleCase($this->cleanString(data_get($option, 'title')));
                if ($optionName === null) {
                    continue;
                }
                $price = data_get($option, 'price');
                $options[] = array_filter([
                    'name' => $optionName,
                    'price' => is_numeric($price) ? round((float) $price, 2) : null,
                ], fn ($v) => $v !== null);
            }
            if ($options !== []) {
                $groups[] = array_filter([
                    'name' => $this->titleCase($this->cleanString(data_get($group, 'title'))),
                    'options' => $options,
                ], fn ($v) => $v !== null && $v !== []);
            }
        }

        return $groups === [] ? null : $groups;
    }

    /**
     * DoorDash badges → normalized [{ text, type? }]. The element shape isn't
     * fixed (a quiet store returns none), so extract defensively: a plain string,
     * or a { text|name|label, type } object.
     *
     * @return list<array{text:string, type?:string}>|null
     */
    private function badges(mixed $badges): ?array
    {
        if (! is_array($badges) || $badges === []) {
            return null;
        }
        $out = [];
        foreach ($badges as $badge) {
            if (is_string($badge)) {
                $text = $this->cleanString($badge);
            } else {
                $text = $this->cleanString(data_get($badge, 'text'))
                    ?? $this->cleanString(data_get($badge, 'name'))
                    ?? $this->cleanString(data_get($badge, 'label'));
            }
            if ($text === null) {
                continue;
            }
            $type = is_array($badge) ? $this->cleanString(data_get($badge, 'type')) : null;
            $out[] = array_filter(['text' => $text, 'type' => $type], fn ($v) => $v !== null);
        }

        return $out === [] ? null : $out;
    }

    /**
     * Best-effort price → dollars float. DoorDash sends "A$15.30" display strings
     * as a fallback when price_cents is absent; also tolerates plain numerics.
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

    /** Title Case — first letter of every word uppercase, rest lowercase. */
    private function titleCase(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        return ucwords(strtolower($s));
    }

    /** Sentence case — first letter of each sentence uppercase, rest lowercase. */
    private function sentenceCase(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        $lower = strtolower($s);

        return preg_replace_callback(
            '/(^|\.\s+|!\s+|\?\s+)([a-z])/u',
            fn (array $m) => $m[1].strtoupper($m[2]),
            $lower,
        ) ?? $lower;
    }
}
