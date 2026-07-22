<?php

namespace App\Services\Platforms;

use App\Services\Cache\ApifyBudget;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
// Per-platform actor id, host pattern, and scrape/map logic live in
// config('partna.menu.platforms') + one MenuPlatformDriver class per platform
// (FOUND-23 registry) — this class is generic plumbing only: retries, concurrent
// pooled fetches across pickup/delivery modes, and per-mode price fusion. Adding
// a platform is a config entry + a driver class; nothing here changes.
//
// Normalized output (per MenuPlatformDriver::mapItems):
//   [ 'store' => [ name, rating, reviewCount, currency, logo ],
//     'categories' => [ [ 'name' => …, 'items' => [ [
//        externalId, name, description, price, image,
//        rating, ratingCount, badges (DD only) ] … ] ] ] ]
class MenuApifyScraper
{
    // These actors scrape WAF-protected pages and intermittently return an empty
    // result even for a valid, open store (the scrape gets bot-blocked on a large
    // fraction of runs) — so retry an empty / transient miss a few times before
    // giving up. A hard 4xx (unknown store / unrented actor) is NOT retried.
    private const MAX_ATTEMPTS = 4;

    // Per-attempt HTTP timeout (seconds). MAX_ATTEMPTS × this stays under the
    // MenuFetchJob timeout.
    private const ATTEMPT_TIMEOUT = 60;

    /** Lazily-built per-platform driver map (config slug => MenuPlatformDriver instance) — populated once on first use. */
    private ?array $drivers = null;

    /**
     * Scrape one store URL on the given platform and map it to the normalized
     * menu shape. Null on missing token / failure / empty result — the caller
     * records the per-platform status 'unavailable' and keeps any prior menu.
     *
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null
     */
    public function fetch(string $storeUrl, string $platform, ?string $userId = null, ?string $address = null): ?array
    {
        $actor = $this->actorFor($platform);
        $token = config('services.apify.token');
        if ($actor === null || ! $token) {
            return null;
        }

        // SCALE-2: one budget slot per store-scrape (retries below are reliability
        // for THIS store, not extra spend). Null = existing skip contract.
        if (! app(ApifyBudget::class)->tryClaim('menu')) {
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
     * Scrape EVERY connected platform's store across both fulfilment modes
     * CONCURRENTLY, then fuse each platform's modes into one menu whose items carry
     * a `pickupPrice` AND a `deliveryPrice`. Uber Eats / DoorDash price the same
     * dish differently per mode (delivery is often marked up) and the price only
     * exists on the mode-specific page — so every (platform, mode) URL is its own
     * scrape, keyed back together per item by external id (UE uuid / DD item_id).
     *
     * One Http::pool round fires all targets at once, so wall time ≈ the slowest
     * single scrape, not the sum (a 4-scrape UE+DD menu drops from ~4× to ~1×). A
     * target that comes back empty/transient on the concurrent attempt falls back
     * to the robust sequential fetch() (full retry loop + 4xx handling) for just
     * that one — the reliable actors make this rare. An untyped store (one bare
     * URL) scrapes once and applies that price to both modes it offers.
     *
     * @param  array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>  $links  platform => MenuSource::storeLinks entry
     * @return array<string, array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null> platform => fused menu (null when nothing scraped)
     */
    public function fetchStores(array $links, ?string $userId = null, ?string $address = null): array
    {
        $token = config('services.apify.token');
        if (! $token) {
            return [];
        }

        // One scrape target per (platform, mode) URL — key "platform|mode".
        $targets = [];
        foreach ($links as $platform => $link) {
            if ($this->actorFor($platform) === null) {
                continue;
            }
            $pickupUrl = $link['pickupUrl'] ?? null;
            $deliveryUrl = $link['deliveryUrl'] ?? null;
            $storeUrl = $link['storeUrl'] ?? null;
            if ($pickupUrl === null && $deliveryUrl === null) {
                if ($storeUrl !== null) {
                    $targets[$platform.'|single'] = ['platform' => $platform, 'url' => $storeUrl];
                }

                continue;
            }
            if ($pickupUrl !== null) {
                $targets[$platform.'|pickup'] = ['platform' => $platform, 'url' => $pickupUrl];
            }
            if ($deliveryUrl !== null) {
                $targets[$platform.'|delivery'] = ['platform' => $platform, 'url' => $deliveryUrl];
            }
        }
        if ($targets === []) {
            return [];
        }

        // SCALE-2: claim one budget slot per target before firing the pool; drop
        // targets with no budget so the metered pool never exceeds the daily cap.
        $budget = app(ApifyBudget::class);
        $targets = array_filter($targets, function () use ($budget) {
            return $budget->tryClaim('menu');
        });
        if ($targets === []) {
            return [];
        }

        // Fire every target concurrently (one attempt each).
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $key) => $pool->as($key)
                ->withToken($token)
                ->timeout(self::ATTEMPT_TIMEOUT)
                ->post($this->actorUrl($targets[$key]['platform']), $this->input($targets[$key]['url'], $targets[$key]['platform'], $address)),
            array_keys($targets),
        ));

        // Map each response; a concurrent miss falls back to the sequential fetch()
        // (its own retry loop) for that single target.
        $menus = [];
        foreach ($targets as $key => $target) {
            $resp = $responses[$key] ?? null;
            $mapped = $this->mapResponse($resp, $target['platform'], $userId);
            if ($mapped === null && $this->responseRetryable($resp)) {
                $mapped = $this->fetch($target['url'], $target['platform'], $userId, $address);
            }
            if ($mapped !== null) {
                $menus[$key] = $mapped;
            }
        }

        // Fuse each platform's mode menus into the per-mode-priced shape.
        $out = [];
        foreach ($links as $platform => $link) {
            if ($this->actorFor($platform) === null) {
                continue;
            }
            $single = $menus[$platform.'|single'] ?? null;
            $pickup = $menus[$platform.'|pickup'] ?? null;
            $delivery = $menus[$platform.'|delivery'] ?? null;

            if ($single !== null) {
                $out[$platform] = $this->priced($single, true, true);
            } elseif ($pickup !== null && $delivery !== null) {
                $out[$platform] = $this->dual($pickup, $delivery);
            } elseif ($pickup !== null) {
                $out[$platform] = $this->priced($pickup, true, false);
            } elseif ($delivery !== null) {
                $out[$platform] = $this->priced($delivery, false, true);
            } else {
                $out[$platform] = null;
            }
        }

        return $out;
    }

    /** Apify actor id (owner~name) for a menu platform, or null when unknown (FOUND-23 registry). */
    private function actorFor(string $platform): ?string
    {
        return config('partna.menu.platforms.'.$platform.'.actor');
    }

    /** This platform's scrape driver, or null when unknown. Memoized on first use. */
    private function driverFor(string $platform): ?MenuPlatformDriver
    {
        $this->drivers ??= array_map(fn (array $m) => app($m['driver']), config('partna.menu.platforms'));

        return $this->drivers[$platform] ?? null;
    }

    /** Apify run-sync endpoint for a platform's actor. */
    private function actorUrl(string $platform): string
    {
        return 'https://api.apify.com/v2/acts/'.$this->actorFor($platform).'/run-sync-get-dataset-items';
    }

    /**
     * Map a pooled response to a normalized single-mode menu (items carry `price`),
     * or null when it isn't usable (non-Response / error / empty / unexpected shape).
     *
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null
     */
    private function mapResponse(mixed $resp, string $platform, ?string $userId): ?array
    {
        if (! $resp instanceof Response || ! $resp->successful()) {
            return null;
        }
        $items = $resp->json();
        if (! is_array($items) || $items === []) {
            Log::info('menu.apify.pool_empty', ['platform' => $platform, 'user_id' => $userId]);

            return null;
        }
        $menu = $this->driverFor($platform)->mapItems($items);

        return $menu['categories'] === [] ? null : $menu;
    }

    /** Whether a missed pooled response is worth a sequential retry (empty / transient yes; hard 4xx no). */
    private function responseRetryable(mixed $resp): bool
    {
        if (! $resp instanceof Response) {
            return true;
        }
        if ($resp->successful()) {
            return true;
        }

        return $resp->status() >= 500;
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

        $menu = $this->driverFor($platform)->mapItems($items);

        // Mapped to nothing (unexpected shape / all-empty rows) — treat as a
        // retryable miss rather than a real menu.
        return $menu['categories'] === []
            ? ['menu' => null, 'retryable' => true]
            : ['menu' => $menu, 'retryable' => false];
    }

    /**
     * Actor input for one store scrape, from the platform's driver — Uber Eats
     * (memo23): startUrls[{url}]. DoorDash (dz_omar): startUrls[{url}] + a
     * consumer `address` (locale) — fetchReviews off (we don't surface reviews).
     *
     * @return array<string,mixed>
     */
    private function input(string $storeUrl, string $platform, ?string $address): array
    {
        return $this->driverFor($platform)?->buildInput($storeUrl, $address) ?? [];
    }
}
