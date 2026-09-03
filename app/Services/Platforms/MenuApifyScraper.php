<?php

namespace App\Services\Platforms;

use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
//        rating, ratingCount, badges (DD only),
//        itemUrl (per-item deep link — real or null, never a store
//        fallback), soldOut (?bool — UE isSoldOut / Square !in_stock;
//        DD exposes none) ] … ] ] ] ]
class MenuApifyScraper
{
    // These actors scrape WAF-protected pages and intermittently return an empty
    // result even for a valid, open store (the scrape gets bot-blocked on a large
    // fraction of runs) — so retry an empty / transient miss a few times before
    // giving up. A hard 4xx (unknown store / unrented actor) is NOT retried.
    private const MAX_ATTEMPTS = 4;

    // Extra sequential attempts for a target whose pooled attempt missed. Small and
    // explicit: the pooled attempt already spent one billed run, and menu:retry-unavailable
    // re-dispatches every 15 min, so the ladder does not need to be exhaustive here.
    // Also keeps worst-case wall time (60s pool + T × FALLBACK_ATTEMPTS × 60s) under
    // MenuFetchJob::$timeout for the 4-target case.
    private const FALLBACK_ATTEMPTS = 2;

    // Per-attempt HTTP timeout (seconds). MAX_ATTEMPTS × this stays under the
    // MenuFetchJob timeout.
    private const ATTEMPT_TIMEOUT = 60;

    /** Lazily-built per-platform driver map (config slug => MenuPlatformDriver instance) — populated once on first use. */
    private ?array $drivers = null;

    /**
     * platform => 'blocked'|'not_found'|'empty_menu' for platforms that
     * returned no menu on the most recent fetchStores() call. Reset at the
     * top of fetchStores(); mapResponse() and attemptScrape() record into it
     * from whichever null-return case applies (menu-status split —
     * MenuFetchJob::writePlatformSyncStatus() writes this into
     * site.menu_platform_links.status instead of one flattened
     * 'unavailable'). A platform absent from this array failed for a reason
     * outside the Apify lane (budget exhaustion, a thrown exception, the
     * transport=http driver) — the caller falls back to 'unavailable' for it.
     *
     * @var array<string, string>
     */
    private array $lastFailureReasons = [];

    /** @return array<string, string> platform => 'blocked'|'not_found'|'empty_menu' for platforms that returned no menu on the last fetchStores() call. */
    public function lastFailureReasons(): array
    {
        return $this->lastFailureReasons;
    }

    /** Record why $platform returned no menu on this fetchStores() call. */
    private function recordFailureReason(string $platform, string $reason): void
    {
        $this->lastFailureReasons[$platform] = $reason;
    }

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

        // R4-RES-1: the budget is claimed inside attemptScrape() now, per real
        // actor run — this method is just the retry-loop driver. fetch()'s own
        // contract stays a bare ?array (its existing public callers, incl.
        // tests, expect that); the budget-exhausted flag is only meaningful to
        // fetchStores()'s cache-write decision below, so it's unwrapped here.
        return $this->scrapeLoop($actor, $token, $storeUrl, $platform, $address, $userId, 1, self::MAX_ATTEMPTS)['menu'];
    }

    /**
     * Run up to $attempts sequential scrape attempts for one URL, numbering them from
     * $firstAttempt (so a fallback continues the pooled attempt's counter rather than
     * restarting it in the logs). Each attempt claims its own budget slot inside
     * attemptScrape(). Stops on a mapped menu or a non-retryable result.
     *
     * `budgetExhausted` is true only when the loop's LAST attempt stopped because
     * ApifyBudget::tryClaim() denied the claim — never because of a scrape
     * failure — so a caller can tell "we ran out of shared spend" apart from
     * "this store didn't scrape" and avoid treating the former as evidence
     * about the store (R4-RES-1 follow-up).
     *
     * @return array{menu: array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null, budgetExhausted: bool}
     */
    private function scrapeLoop(string $actor, string $token, string $url, string $platform, ?string $address, ?string $userId, int $firstAttempt, int $attempts): array
    {
        $budgetExhausted = false;
        for ($i = 0; $i < $attempts; $i++) {
            $result = $this->attemptScrape($actor, $token, $url, $platform, $address, $userId, $firstAttempt + $i);
            if ($result['menu'] !== null) {
                return ['menu' => $result['menu'], 'budgetExhausted' => false];
            }
            $budgetExhausted = $result['budgetExhausted'];
            if (! $result['retryable']) {
                break;
            }
        }

        return ['menu' => null, 'budgetExhausted' => $budgetExhausted];
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
     * to a bounded number of sequential attempts (FALLBACK_ATTEMPTS, R4-RES-1) for
     * just that one — the reliable actors make this rare. An untyped store (one
     * bare URL) scrapes once and applies that price to both modes it offers.
     *
     * @param  array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>  $links  platform => MenuSource::storeLinks entry
     * @return array<string, array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null> platform => fused menu (null when nothing scraped)
     */
    public function fetchStores(array $links, ?string $userId = null, ?string $address = null): array
    {
        $this->lastFailureReasons = [];

        // transport=http platforms first (Square Online): one first-party
        // fetch per store — no token, no budget claim, no mode split (the
        // single result prices both modes). Failures negative-cache exactly
        // like a failed actor target so the retry cadence matches.
        $httpMenus = $this->fetchHttpStores($links);

        $token = config('services.apify.token');
        if (! $token) {
            return $httpMenus;
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
            return $httpMenus;
        }

        // R4-RES-1: drop targets we scraped and failed on within the last TTL. Checked
        // BEFORE the budget claim so a suppressed target neither claims nor bills.
        $targets = array_filter(
            $targets,
            fn (array $t) => ! Cache::has(CacheKeyGenerator::menuScrapeBlocked($t['platform'], $t['url'])),
        );
        if ($targets === []) {
            return $httpMenus;
        }

        // SCALE-2: claim one budget slot per target before firing the pool; drop
        // targets with no budget so the metered pool never exceeds the daily cap.
        $budget = app(ApifyBudget::class);
        $targets = array_filter($targets, function () use ($budget) {
            return $budget->tryClaim('menu');
        });
        if ($targets === []) {
            return $httpMenus;
        }

        // Fire every target concurrently (one attempt each).
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $key) => $pool->as($key)
                ->withToken($token)
                ->timeout(self::ATTEMPT_TIMEOUT)
                ->post($this->actorUrl($targets[$key]['platform']), $this->input($targets[$key]['url'], $targets[$key]['platform'], $address)),
            array_keys($targets),
        ));

        // Map each response; a concurrent miss falls back to a small bounded number
        // of sequential attempts for that single target (R4-RES-1 — never re-enters
        // fetch(), which would re-claim a budget slot and restart a full MAX_ATTEMPTS
        // ladder for a target already paid for by the pooled attempt above).
        $menus = [];
        $ttl = (int) config('partna.menu.blocked_ttl_seconds');
        foreach ($targets as $key => $target) {
            $resp = $responses[$key] ?? null;
            $mapped = $this->mapResponse($resp, $target['platform'], $userId);
            $budgetExhausted = false;
            if ($mapped === null && $this->responseRetryable($resp)) {
                $actor = $this->actorFor($target['platform']);
                // actorFor() is guaranteed non-null here — targets are only built
                // for platforms that already passed that guard above.
                $fallback = $actor === null ? null : $this->scrapeLoop(
                    $actor,
                    $token,
                    $target['url'],
                    $target['platform'],
                    $address,
                    $userId,
                    2,
                    self::FALLBACK_ATTEMPTS,
                );
                $mapped = $fallback['menu'] ?? null;
                $budgetExhausted = $fallback['budgetExhausted'] ?? false;
            }

            $blockedKey = CacheKeyGenerator::menuScrapeBlocked($target['platform'], $target['url']);
            if ($mapped !== null) {
                $menus[$key] = $mapped;
                Cache::forget($blockedKey);     // recovered — don't hold a stale block
            } elseif ($budgetExhausted) {
                // A shared-budget stop is a statement about our spend, not about
                // this store — never negative-cache it as blocked/hard_error.
                Log::info('menu.apify.blocked_cache_skipped_budget', ['platform' => $target['platform'], 'user_id' => $userId]);
            } else {
                // Never a bare null: a bare null is indistinguishable from a cache miss.
                Cache::put($blockedKey, ['at' => now()->toIso8601String(), 'reason' => $this->responseRetryable($resp) ? 'blocked' : 'hard_error'], $ttl);
            }
        }

        // Fuse each platform's mode menus into the per-mode-priced shape.
        $out = $httpMenus;
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

    /**
     * The transport=http platforms' menus, keyed by platform slug — Square
     * Online today. Shares the actor lane's negative-cache (same key, same
     * TTL) so a broken store isn't re-fetched every dispatch; a recovered
     * fetch clears the block, and a null result becomes the ghost-platform
     * path exactly like a failed actor scrape. Priced for BOTH modes: these
     * stores are location/mode-independent (one catalog, one price).
     *
     * @param  array<string, array{pickupUrl:?string, deliveryUrl:?string, storeUrl:?string, modes:list<string>}>  $links
     * @return array<string, array{store:array<string,mixed>, categories:list<array<string,mixed>>}|null>
     */
    private function fetchHttpStores(array $links): array
    {
        $out = [];
        $ttl = (int) config('partna.menu.blocked_ttl_seconds');

        foreach ($links as $platform => $link) {
            if (config('partna.menu.platforms.'.$platform.'.transport') !== 'http') {
                continue;
            }
            $driver = $this->driverFor($platform);
            $url = $link['storeUrl'] ?? $link['pickupUrl'] ?? $link['deliveryUrl'] ?? null;
            if (! $driver instanceof MenuHttpDriver || $url === null) {
                continue;
            }

            $blockedKey = CacheKeyGenerator::menuScrapeBlocked($platform, $url);
            if (Cache::has($blockedKey)) {
                $out[$platform] = null;

                continue;
            }

            $menu = $driver->fetchMenu($url);
            if ($menu !== null) {
                $out[$platform] = $this->priced($menu, true, true);
                Cache::forget($blockedKey);
            } else {
                $out[$platform] = null;
                Cache::put($blockedKey, ['at' => now()->toIso8601String(), 'reason' => 'http_fetch_failed'], $ttl);
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
            $this->recordFailureReason($platform, 'blocked');

            return null;
        }
        $items = $resp->json();
        if (! is_array($items) || $items === []) {
            Log::info('menu.apify.pool_empty', ['platform' => $platform, 'user_id' => $userId]);
            $this->recordFailureReason($platform, 'not_found');

            return null;
        }
        $menu = $this->driverFor($platform)->mapItems($items);

        if ($menu['categories'] === []) {
            $this->recordFailureReason($platform, 'empty_menu');

            return null;
        }

        return $menu;
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

        // Identity is UNIONED across both mode scrapes (2026-08-26): memo23's
        // includeItemCustomizations output is flaky per run — live evidence
        // the day it shipped: one mode scrape returned itemUuid/href for 0/82
        // items and the other for 34/82 (the verification run had 82/82) — so
        // a dish whose base-mode row lacks its id/link gap-fills from the
        // other mode's row (matched by name — the id side is the one that's
        // missing). Coverage is logged so actor flakiness stays visible.
        $identity = $this->identityMap($pickupMenu) + $this->identityMap($deliveryMenu);

        $base['categories'] = array_map(function (array $cat) use ($pickupMap, $deliveryMap, $identity) {
            $cat['items'] = array_map(function (array $item) use ($pickupMap, $deliveryMap, $identity) {
                // id-key first, name-key fallback: when the actor returns
                // ids for only ONE mode's scrape (its flaky-identity failure,
                // 2026-08-26), an id-only lookup would silently lose the
                // other mode's price for every id-carrying item.
                $key = $this->itemKey($item);
                $nameKey = 'name:'.$this->nameKey($item);
                $item['pickupPrice'] = $pickupMap[$key] ?? $pickupMap[$nameKey] ?? null;
                $item['deliveryPrice'] = $deliveryMap[$key] ?? $deliveryMap[$nameKey] ?? null;
                unset($item['price']);

                $donor = $identity[$this->nameKey($item)] ?? null;
                if ($donor !== null) {
                    $item['itemUrl'] = $item['itemUrl'] ?? $donor['itemUrl'];
                    $item['externalId'] = $item['externalId'] ?? $donor['externalId'];
                    $item['soldOut'] = $item['soldOut'] ?? $donor['soldOut'];
                }

                return $item;
            }, $cat['items']);

            return $cat;
        }, $base['categories']);

        $this->logIdentityCoverage($base);

        return $base;
    }

    /**
     * name-key => the per-item identity fields a mode scrape carried. Keyed by
     * NAME (not itemKey) deliberately: the row that needs the donor is the one
     * MISSING its external id, so an id-first key could never pair them.
     *
     * @return array<string, array{itemUrl:?string, externalId:?string, soldOut:?bool}>
     */
    private function identityMap(array $menu): array
    {
        $map = [];
        foreach ($menu['categories'] as $cat) {
            foreach ($cat['items'] as $item) {
                if (($item['itemUrl'] ?? null) === null && ($item['externalId'] ?? null) === null && ($item['soldOut'] ?? null) === null) {
                    continue;
                }
                $map[$this->nameKey($item)] ??= [
                    'itemUrl' => $item['itemUrl'] ?? null,
                    'externalId' => $item['externalId'] ?? null,
                    'soldOut' => $item['soldOut'] ?? null,
                ];
            }
        }

        return $map;
    }

    private function nameKey(array $item): string
    {
        return mb_strtolower(trim((string) ($item['name'] ?? '')));
    }

    /** Visibility for actor identity flakiness — never affects the result. */
    private function logIdentityCoverage(array $menu): void
    {
        $total = 0;
        $withId = 0;
        foreach ($menu['categories'] as $cat) {
            foreach ($cat['items'] as $item) {
                $total++;
                if (($item['externalId'] ?? null) !== null || ($item['itemUrl'] ?? null) !== null) {
                    $withId++;
                }
            }
        }
        if ($total > 0 && $withId < $total) {
            Log::info('menu.item_identity_partial', ['total' => $total, 'with_identity' => $withId]);
        }
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
                // Registered under BOTH keys so a lookup succeeds whichever
                // side of the id-asymmetry this scrape sat on.
                $map[$this->itemKey($item)] ??= (float) $price;
                $map['name:'.$this->nameKey($item)] ??= (float) $price;
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
     * (unknown store / unrented actor) is not. `budgetExhausted` is true ONLY
     * when the claim itself was denied — every other non-retryable/retryable
     * outcome is a real scrape result and sets it false, so a caller can tell
     * "we ran out of shared spend" apart from "this store didn't scrape".
     *
     * @return array{menu: array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null, retryable: bool, budgetExhausted: bool}
     */
    private function attemptScrape(string $actor, string $token, string $storeUrl, string $platform, ?string $address, ?string $userId, int $attempt): array
    {
        // R4-RES-1: the budget is claimed HERE, at the billed call site, not by the
        // caller — one claim per real actor run. A denied claim is non-retryable so
        // the enclosing loop stops rather than spinning against an exhausted cap.
        if (! app(ApifyBudget::class)->tryClaim('menu')) {
            Log::info('menu.apify.budget_exhausted', ['platform' => $platform, 'user_id' => $userId, 'attempt' => $attempt]);

            return ['menu' => null, 'retryable' => false, 'budgetExhausted' => true];
        }

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

            return ['menu' => null, 'retryable' => true, 'budgetExhausted' => false];
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
            $this->recordFailureReason($platform, 'blocked');

            return ['menu' => null, 'retryable' => $response->status() >= 500, 'budgetExhausted' => false];
        }

        $items = $response->json();
        if (! is_array($items) || $items === []) {
            Log::info('menu.apify.empty', ['platform' => $platform, 'user_id' => $userId, 'attempt' => $attempt]);
            $this->recordFailureReason($platform, 'not_found');

            return ['menu' => null, 'retryable' => true, 'budgetExhausted' => false];
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
        if ($menu['categories'] === []) {
            $this->recordFailureReason($platform, 'empty_menu');

            return ['menu' => null, 'retryable' => true, 'budgetExhausted' => false];
        }

        return ['menu' => $menu, 'retryable' => false, 'budgetExhausted' => false];
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
