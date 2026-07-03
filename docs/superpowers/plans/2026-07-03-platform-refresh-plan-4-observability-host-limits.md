# Platform Refresh Plan 4 — Per-Host Burst Control & Silent-Failure Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Draft — awaiting Josh's sign-off. **P2 cluster, NO DB migration** → lighter gate than Plans 1–3, but still sign-off-before-implement.

**Goal:** Close the two P2 residuals the foundation left open in the connection-scale-health audit — (**#SCALE-3**) cap the *inner* concurrent third-party bursts that live inside individual fetch strategies and cache the keyless iTunes API; and (**#OBS-1**) make four silent failure points reach Nightwatch by adding `report()` / `$this->fail()` alongside their existing `Log::warning` breadcrumbs.

**Architecture:** SCALE-3's *outer* pacing is already solved by Plan 1's per-provider `platform-refresh` RateLimiter (which paces how many refresh **jobs** run per minute per platform). What that limiter does **not** govern is the concurrent HTTP a single fetch fans out **within one job** — an unmetered `Http::pool` to i.ytimg.com, the billed Google Places media pool, the shared `SafeUrlFetcher::fetchMany` pool (Eventbrite/Humanitix), and the uncached iTunes lookups. This plan adds per-host concurrency caps (config-driven, chunked pools) + an iTunes response cache. OBS-1 wires the four surviving legacy `Log::warning`-only sites to Nightwatch (which alerts on thrown/reported exceptions, **not** log lines — `reference_nightwatch_alerts`).

**Tech Stack:** PHP 8.2, Laravel 12, Redis cache (array cache in tests), Pest 4 (SQLite in-memory), existing `PlatformRegistry` / `PlatformRefresher` / `SafeUrlFetcher` / `CacheKeyGenerator` spine. No new dependencies.

**Source:** Strategy doc `docs/superpowers/plans/2026-07-01-platform-refresh-scaling-strategy.md` §8 — this is the "**#SCALE-3 residual + Bundle B (#OBS-1)**" follow-on. Audit `audits/sweeps/2026-07-01-connection-scale-health/CONSOLIDATED.md` (#SCALE-3, #OBS-1). Depends on Plans 1–3, all landed on `development`.

---

## ⚠️ Premise corrections (verified against LANDED code 2026-07-03)

Three finding-text premises no longer hold against the code Plans 1–3 actually shipped. Read these before implementing — they change the shape of two tasks and delete one.

1. **SCALE-3's "effective delay = `max(global_throttle, host_delay)`" is obsolete.** Plan 1 **deleted** the command's global `--throttle-ms` throttle (verified: `grep -c throttle-ms RefreshIntegrationConnectionsCommand.php` → 0). There is no global throttle to `max()` against anymore, and the `platform-refresh` RateLimiter (Plan 1, `PlatformRegistryServiceProvider.php:312`) already paces **job dispatch** per provider. So this plan does **NOT** add a `refresh_host_delays` delay-config map. The genuine residual is the **inner per-fetch concurrent bursts** the job-level limiter can't see — capped here as pool-concurrency limits + an iTunes cache.

2. **Google Places is re-resolved *weekly*, not "daily even when unchanged."** `GoogleBusinessFetch::fetch()` (`app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php:31-38`) already short-circuits when `detailsFetchedAt` is < 6 days old — no API call at all. The billed re-resolve waste is therefore per-*weekly*-repull, not daily. Still worth fixing at 10k scale (≤10 billed media calls × ~10k GB connections ÷ 7 days), but the value is lower than the finding implies — reflected in Task 6 being an optional, best-effort optimization.

3. **OBS-1's Fresha entry is DROPPED from this plan.** The finding lists `FreshaController` (`employeeServices` GraphQL) as a silent-failure site. That method (`fetchEmployeeServices()`, still present at `FreshaController.php:302` with its 3 `Log::warning('fresha.employee_services.failed')` sites) is scheduled for **wholesale deletion** by the pending legal-remediation plan `docs/superpowers/plans/2026-07-02-fresha-graphql-remediation.md` (Task 1c deletes the entire method). Adding `report()` to code another approved plan deletes is throwaway work + a guaranteed merge conflict. **The Fresha remediation plan must ensure its replacement `extractServices()` fallback path carries appropriate observability** — that is its responsibility, not this plan's. This leaves **4** OBS-1 sites in scope.

---

## Global Constraints

- **NO Laravel migration files** — a composer guard rejects them. This plan makes **no schema change** (config + code only). No blocker-gate migration item.
- **Tests run on SQLite in-memory + `array` cache + `sync` queue** (`phpunit.xml`: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`). `Cache::get/put`, `Cache::add`, `Http::fake`, `Http::pool`, and `Exceptions::fake()` all work on that stack. All queries must stay DB-agnostic (no `NULLS FIRST`, `interval`, `power()`); this plan adds no new queries.
- **Cache KEYS are centralized in `CacheKeyGenerator` (`app/Services/Cache/`); the `Cache::*` read/write may live in the scraper** — matching the **existing** `YoutubeThumbnailResolver` precedent (it calls `Cache::get/put` directly with a `CacheKeyGenerator::youtubeThumbnailVerdict()` key). GS-1 ("raw `Cache::*` in the cache layer") is a convention with **no test enforcing it** (verified: no architecture test restricts Cache placement); the enforced discipline is key-centralization. Do NOT invent a new cache service for a single cached call — follow the resolver precedent.
- **Every `ShouldQueue` job must define `$tries`, `$backoff` (or `backoff()`), and `$timeout`** (`tests/Feature/Queue/JobHygienePolicyTest.php`). This plan modifies `DeleteMirroredMediaJob` (already compliant) and adds no new jobs.
- **Nightwatch alerts on thrown/reported exceptions, not `Log::warning`** (`reference_nightwatch_alerts`). OBS-1's entire point is converting invisible breadcrumbs into `report()`/`fail()` alerts. Transient/expected misses (`status='unavailable'`) stay quiet — only *real* problems (data corruption, billed-API outage, fail-closed leak-prevention firing) get reported.
- **Do NOT modify `.env`** — add new keys to `.env.example` only; read via `env()` with a safe default inside `config/partna.php`.
- **Run `php artisan pint` on changed files before each commit**; keep commits surgical (don't let Pint churn unrelated lines — `feedback_pint_baseline_not_clean`).
- **Do NOT run `composer test` concurrently with a test-running review subagent** (`feedback_audit_fix_runbook_gotchas`).
- **Config is the single source** of every concurrency cap and cache TTL; defaults are conservative starting values, tunable via env.

---

## File Structure

**New files:**
- `app/Exceptions/Platforms/PlaceDetailsUnavailableException.php` — reported to Nightwatch when the billed Google Place-Details call fails/returns non-OK (OBS-1).
- `app/Exceptions/Platforms/MissingPublicAllowlistException.php` — reported when the public resource's fail-closed allowlist branch fires (OBS-1).
- `tests/Feature/Platforms/RefreshHostLimitsTest.php` — SCALE-3 pool-cap + iTunes-cache assertions.
- `tests/Feature/Platforms/RefreshObservabilityTest.php` — OBS-1 `report()`/`fail()` assertions across the 4 sites.

**Modified files:**
- `config/partna.php` — add `refresh.host_limits` sub-block.
- `.env.example` — document the new env keys.
- `app/Services/Cache/CacheKeyGenerator.php` — add `itunesResponse()` key.
- `app/Services/Platforms/AppleSearch.php` — cache successful iTunes responses in `itunes()`.
- `app/Services/Platforms/YoutubeThumbnailResolver.php` — cap the maxres-probe pool concurrency (chunk).
- `app/Services/Http/SafeUrlFetcher.php` — cap the `fetchMany()` pool concurrency (chunk).
- `app/Services/Platforms/GoogleBusinessService.php` — cap the Places media pool + skip already-resolved photos (Task 5); `report()` on Place-Details failure (Task 7); optional carry-forward hook in `fetchPlaceDetails` (Task 6).
- `app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php` — pass prior payload photos into `fetchPlaceDetails` (Task 6).
- `app/Services/Platforms/PlatformRefresher.php` — `report()` the caught `FetchShapeException` (OBS-1).
- `app/Jobs/Platforms/DeleteMirroredMediaJob.php` — `$this->fail()` the non-platforms guard instead of a bare `return` (OBS-1).
- `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` — `report()` the fail-closed allowlist branch (OBS-1).

---

# Part A — SCALE-3: per-host burst control + caching

## Task 1: Config — `refresh.host_limits` block

**Files:**
- Modify: `config/partna.php`
- Modify: `.env.example`
- Test: (values asserted indirectly by Tasks 2–5)

**Interfaces:**
- Produces: `config('partna.refresh.host_limits.itunes.cache_ttl_seconds')`, `...youtube_thumbnails.pool_concurrency`, `...google_places.pool_concurrency`, `...fetch_many.pool_concurrency`. Consumed by Tasks 2–5.

- [ ] **Step 1: Add the `host_limits` sub-block**

In `config/partna.php`, inside the existing `'refresh' => [ … ]` array, after the `'backlog' => [ … ]` sub-array (currently ends at line ~1175, before the closing `],`):

```php
        // SCALE-3: inner per-host burst control for the refresh fetch strategies.
        // The per-provider 'platform-refresh' RateLimiter (rate_limits, above) paces
        // how many refresh JOBS run per minute per platform. It does NOT see the
        // concurrent HTTP a SINGLE fetch fans out within one job — these cap those
        // bursts so a fetch can't hammer a keyless / billed third-party host. (The old
        // global --throttle-ms was removed in Plan 1; there is no global delay to max
        // against — these are the inner-burst residual.)
        'host_limits' => [
            // iTunes Search/Lookup — keyless, ~20 req/min/IP (429s after ~20 Apple
            // refreshes in one run). Cache successful responses so repeated lookups
            // across a run (and re-runs within the window) don't each re-hit Apple.
            'itunes' => [
                'cache_ttl_seconds' => (int) env('PARTNA_REFRESH_ITUNES_CACHE_TTL', 21600), // 6h
            ],
            // i.ytimg.com maxresdefault HEAD probes (YoutubeThumbnailResolver). Cheap
            // HEADs, so a generous cap; bounds the batch when many videos miss cache.
            'youtube_thumbnails' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_YTIMG_POOL', 10),
            ],
            // Google Places media — BILLED per call. Keep the concurrent burst tight.
            'google_places' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_PLACES_POOL', 5),
            ],
            // Shared SafeUrlFetcher::fetchMany pool (Eventbrite/Humanitix HTML scrapes;
            // WAF-ban risk in aggregate). Caps every fetchMany caller globally.
            'fetch_many' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_FETCH_MANY_POOL', 6),
            ],
        ],
```

- [ ] **Step 2: Document the env keys**

In `.env.example`, add near the other `PARTNA_REFRESH_*` keys:

```dotenv
PARTNA_REFRESH_ITUNES_CACHE_TTL=21600
PARTNA_REFRESH_YTIMG_POOL=10
PARTNA_REFRESH_PLACES_POOL=5
PARTNA_REFRESH_FETCH_MANY_POOL=6
```

- [ ] **Step 3: Verify config loads**

Run: `php artisan config:clear && php artisan tinker --execute="echo config('partna.refresh.host_limits.google_places.pool_concurrency'), '|', config('partna.refresh.host_limits.itunes.cache_ttl_seconds');"`
Expected: prints `5|21600`.

- [ ] **Step 4: Commit**

```bash
php artisan pint config/partna.php
git add config/partna.php .env.example
git commit -m "feat(refresh): host_limits config (pool caps + iTunes cache TTL) (SCALE-3)"
```

---

## Task 2: Cache successful iTunes responses

**Files:**
- Modify: `app/Services/Cache/CacheKeyGenerator.php`
- Modify: `app/Services/Platforms/AppleSearch.php`
- Test: `tests/Feature/Platforms/RefreshHostLimitsTest.php`

**Interfaces:**
- Consumes: `config('partna.refresh.host_limits.itunes.cache_ttl_seconds')` (Task 1).
- Produces: `CacheKeyGenerator::itunesResponse(string $path): string`; `AppleSearch::itunes()` now reads-through a cache and only stores successful (array) responses. No public-signature change.

**Why cache in `itunes()`:** it is the single choke point — every `fetchAlbums`/`fetchEpisodes`/`resolveArtistId`/`resolvePodcastId` call routes through it (verified). Caching here covers all iTunes traffic with one change. Only *successful* responses are cached (a failed/`null` lookup must be retried, not remembered), matching the `YoutubeThumbnailResolver` verdict-cache pattern.

- [ ] **Step 1: Write the failing test**

This file's `use` block covers the whole `RefreshHostLimitsTest.php` — Tasks 3–6 append only `it()` blocks, no further imports. iTunes traffic is tested by **mocking `SafeUrlFetcher`** (AppleSearch's only outbound seam) so the test is hermetic — no real DNS/HTTP (`assertSafe()` does a real host resolve; Tasks 3/5/6 fake plain `Http` instead, which needs no DNS).

```php
<?php
// tests/Feature/Platforms/RefreshHostLimitsTest.php
//
// SCALE-3 per-host burst-control + caching tests. All imports for the whole file
// live in this block; Tasks 3–6 append only it() blocks.

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Http;

it('caches a successful iTunes lookup so a repeat resolution issues no second fetch', function () {
    config()->set('partna.refresh.host_limits.itunes.cache_ttl_seconds', 3600);

    // tryFetch MUST be called exactly once for the same path; the 2nd resolution is
    // served from cache. once() is the assertion — a broken cache calls it twice.
    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->once()->andReturn([
            'status' => 200,
            'body' => json_encode(['results' => [['wrapperType' => 'artist', 'artistId' => 42]]]),
        ]);
    });

    $apple = app(AppleSearch::class);
    $resolve = new ReflectionMethod(AppleSearch::class, 'resolveArtistId');
    $resolve->setAccessible(true);

    expect($resolve->invoke($apple, 'Same Artist'))->toBe(42); // network hit → cached
    expect($resolve->invoke($apple, 'Same Artist'))->toBe(42); // served from cache (once() holds)
});

it('does not cache a failed iTunes lookup (retried on the next call)', function () {
    config()->set('partna.refresh.host_limits.itunes.cache_ttl_seconds', 3600);

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->twice()->andReturn(
            ['status' => 429, 'body' => ''],                                                                        // 1st: fail (must NOT cache)
            ['status' => 200, 'body' => json_encode(['results' => [['wrapperType' => 'artist', 'artistId' => 7]]])], // 2nd: succeeds
        );
    });

    $apple = app(AppleSearch::class);
    $resolve = new ReflectionMethod(AppleSearch::class, 'resolveArtistId');
    $resolve->setAccessible(true);

    expect($resolve->invoke($apple, 'Retry Artist'))->toBeNull(); // 429 → null, not cached
    expect($resolve->invoke($apple, 'Retry Artist'))->toBe(7);    // re-fetched, succeeds
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: FAIL — with no cache, the 2nd resolution calls `tryFetch` again, violating the `once()` expectation (Mockery fails the test).

- [ ] **Step 3: Add the cache key**

In `app/Services/Cache/CacheKeyGenerator.php`, after the `apifyActorDailyLimit()` method (end of file, before the closing `}`):

```php
    /** Cached keyless iTunes Search/Lookup response, keyed by request path (SCALE-3). */
    public static function itunesResponse(string $path): string
    {
        return 'platforms:itunes:'.sha1($path);
    }
```

- [ ] **Step 4: Cache in `AppleSearch::itunes()`**

In `app/Services/Platforms/AppleSearch.php`, add the imports at the top:

```php
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
```

Replace the `itunes()` method body with a read-through that caches only successes:

```php
    private function itunes(string $path): ?array
    {
        // SCALE-3: cache successful lookups (iTunes is keyless, ~20 req/min/IP). Only
        // a valid decoded response is stored — a null/non-200 must be retried, never
        // remembered. Matches the YoutubeThumbnailResolver "cache the verdict, not the
        // miss" pattern; key centralised in CacheKeyGenerator.
        $key = CacheKeyGenerator::itunesResponse($path);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->fetcher->tryFetch('https://itunes.apple.com'.$path, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        Cache::put($key, $json, (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'));

        return $json;
    }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Cache/CacheKeyGenerator.php app/Services/Platforms/AppleSearch.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git add app/Services/Cache/CacheKeyGenerator.php app/Services/Platforms/AppleSearch.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git commit -m "feat(refresh): cache successful iTunes responses (SCALE-3)"
```

---

## Task 3: Cap the YouTube thumbnail-probe pool concurrency

**Files:**
- Modify: `app/Services/Platforms/YoutubeThumbnailResolver.php`
- Test: append to `tests/Feature/Platforms/RefreshHostLimitsTest.php`

**Interfaces:**
- Consumes: `config('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency')` (Task 1).
- Produces: `bestForMany()` unchanged signature/return; the maxres HEAD probes now run in bounded chunks instead of one unbounded `Http::pool`.

**Scope guard (do NOT touch CCH-2):** this task ONLY caps the pool concurrency. The single-flight lock and the `'hq'`-verdict stale-TTL recheck are finding **#CCH-2** (Bundle C, kept separate). Leave the cache read (line ~66), the `Cache::put` verdict write (line ~95), and `CACHE_DAYS` exactly as they are.

- [ ] **Step 1: Write the failing test** (append the `it()` block to `RefreshHostLimitsTest.php`; imports already at the top)

```php
it('chunks the ytimg maxres probes to the configured pool concurrency', function () {
    config()->set('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 2);
    Http::fake(['i.ytimg.com/vi/*/maxresdefault.jpg' => Http::response('', 200)]);

    // The private chunked-pool helper must exist — proves the cap is wired, not that
    // the functional result happens to match on the current unbounded code.
    expect(method_exists(YoutubeThumbnailResolver::class, 'pooledHead'))->toBeTrue();

    $ids = ['aaa', 'bbb', 'ccc', 'ddd', 'eee']; // 5 misses, cap 2 → 3 chunks
    $result = app(YoutubeThumbnailResolver::class)->bestForMany($ids);

    expect($result)->toHaveCount(5);
    foreach ($ids as $id) {
        expect($result[$id])->toBe("https://i.ytimg.com/vi/{$id}/maxresdefault.jpg");
    }
    Http::assertSentCount(5); // every id probed across chunks
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: FAIL — `method_exists(..., 'pooledHead')` is false (the helper doesn't exist yet).

- [ ] **Step 3: Implement the chunked pool**

In `app/Services/Platforms/YoutubeThumbnailResolver.php`, replace the single `Http::pool(...)` block (currently lines ~78–84) with a call to a new helper:

```php
        // 2. Probe every miss concurrently, but in bounded chunks so one refresh can't
        //    fan out an unlimited burst of HEADs to i.ytimg.com (SCALE-3). Global
        //    across the batch; per-provider job pacing is handled upstream by the
        //    platform-refresh RateLimiter.
        $responses = $this->pooledHead($misses);
```

Add the helper method (place it above `cacheKey()`):

```php
    /**
     * HEAD-probe every id concurrently, capped at the configured pool concurrency.
     * Chunks the ids and runs one Http::pool per chunk; results keyed by id (via
     * $pool->as($id)) are merged across chunks.
     *
     * @param  array<int,string>  $ids
     * @return array<string, \Illuminate\Http\Client\Response|\Throwable>
     */
    private function pooledHead(array $ids): array
    {
        $max = max(1, (int) config('partna.refresh.host_limits.youtube_thumbnails.pool_concurrency', 10));
        $responses = [];

        foreach (array_chunk($ids, $max) as $chunk) {
            $batch = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->head($this->maxresUrl($id)),
                $chunk,
            ));
            $responses += $batch; // key-preserving union (keys are the ids)
        }

        return $responses;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: PASS.

- [ ] **Step 5: Verify the YouTube resolver's own suite still green**

Run: `php artisan test tests/Feature/Platforms/ --filter=Youtube`
Expected: PASS (no regression to verdict caching / CCH-2 territory).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/YoutubeThumbnailResolver.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git add app/Services/Platforms/YoutubeThumbnailResolver.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git commit -m "feat(refresh): cap ytimg thumbnail-probe pool concurrency (SCALE-3)"
```

---

## Task 4: Cap the shared `fetchMany` pool concurrency

**Files:**
- Modify: `app/Services/Http/SafeUrlFetcher.php`
- Test: append to `tests/Feature/Platforms/RefreshHostLimitsTest.php`

**Interfaces:**
- Consumes: `config('partna.refresh.host_limits.fetch_many.pool_concurrency')` (Task 1).
- Produces: `fetchMany()` unchanged signature/return; each redirect round now pools in bounded chunks. Caps every `fetchMany` caller (Eventbrite `:81`, Humanitix `:116`) — a global outbound politeness cap.

**Why here, not per-scraper:** both Eventbrite and Humanitix route their concurrent event-page GETs through this one `Http::pool` (verified). Capping the utility caps both callers with one change and preserves the SSRF two-pass redirect handling.

- [ ] **Step 1: Write the failing test** (append the `it()` block to `RefreshHostLimitsTest.php`; imports already at the top)

```php
it('caps fetchMany concurrency via a chunked pool helper', function () {
    config()->set('partna.refresh.host_limits.fetch_many.pool_concurrency', 2);
    // Literal public IP bypasses assertSafe()'s DNS resolution → hermetic. 8.8.8.8 is
    // a public address (passes NO_PRIV_RANGE|NO_RES_RANGE); Http::fake stubs the GET.
    Http::fake(['8.8.8.8/*' => Http::response('<html>ok</html>', 200)]);

    expect(method_exists(SafeUrlFetcher::class, 'pooledGet'))->toBeTrue();

    $urls = [
        'https://8.8.8.8/1', 'https://8.8.8.8/2', 'https://8.8.8.8/3',
        'https://8.8.8.8/4', 'https://8.8.8.8/5',
    ];
    $out = app(SafeUrlFetcher::class)->fetchMany($urls);

    // All 5 resolved despite a cap of 2 (chunked, not dropped).
    expect(array_keys($out))->toEqualCanonicalizing($urls);
    foreach ($urls as $u) {
        expect($out[$u]['status'])->toBe(200);
    }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: FAIL — `pooledGet` does not exist.

- [ ] **Step 3: Extract the chunked pool helper**

In `app/Services/Http/SafeUrlFetcher.php`, inside `fetchMany()`, replace the inline `Http::pool(...)` call in the redirect-follow loop (currently lines ~143–151) with:

```php
            // Fire the current round's URLs, capped to the configured concurrency so a
            // large organiser (many event pages) can't burst all at once against the
            // target host (SCALE-3, WAF-ban risk). Indices key the pool → map back.
            $responses = $this->pooledGet($currentUrls, $mergedHeaders);
```

Add the private helper (place it after `fetchMany()`):

```php
    /**
     * GET a list of URLs concurrently, capped at the configured pool concurrency.
     * Preserves the numeric index as the pool key (via $pool->as((string) $i)) so the
     * caller maps responses back to its $originals; chunks are merged key-preserving.
     *
     * @param  array<int,string>  $currentUrls  0-indexed list of URLs for this round.
     * @param  array<string,string>  $mergedHeaders
     * @return array<string, \Illuminate\Http\Client\Response|\Throwable>  Keyed by "$i".
     */
    private function pooledGet(array $currentUrls, array $mergedHeaders): array
    {
        $max = max(1, (int) config('partna.refresh.host_limits.fetch_many.pool_concurrency', 6));
        $responses = [];

        foreach (array_chunk($currentUrls, $max, true) as $chunk) {
            $batch = Http::pool(fn (Pool $pool) => array_map(
                fn (int $i, string $url) => $pool->as((string) $i)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders($mergedHeaders)
                    ->withoutRedirecting()
                    ->get($url),
                array_keys($chunk),
                array_values($chunk),
            ));
            $responses += $batch;
        }

        return $responses;
    }
```

**Note:** `array_chunk($currentUrls, $max, true)` preserves the original 0-based indices, so `$pool->as((string) $i)` still keys by the same `$i` the surrounding `foreach ($originals as $i => $original)` loop reads via `$responses[(string) $i]`. No change to the redirect/re-validation logic below the pool.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: PASS.

- [ ] **Step 5: Regression-check the Events scrapers**

Run: `php artisan test tests/Feature/Platforms/ --filter="Eventbrite|Humanitix"`
Expected: PASS — the concurrent event-page fetch still returns identical results (just chunked).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Http/SafeUrlFetcher.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git add app/Services/Http/SafeUrlFetcher.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git commit -m "feat(refresh): cap SafeUrlFetcher::fetchMany pool concurrency (SCALE-3)"
```

---

## Task 5: Cap the Google Places media pool + skip already-resolved photos

**Files:**
- Modify: `app/Services/Platforms/GoogleBusinessService.php`
- Test: append to `tests/Feature/Platforms/RefreshHostLimitsTest.php`

**Interfaces:**
- Consumes: `config('partna.refresh.host_limits.google_places.pool_concurrency')` (Task 1).
- Produces: `resolvePhotoUrls()` (private, unchanged signature) now (a) skips any photo already carrying a non-empty `url`, and (b) pools the remaining billed media calls in bounded chunks. `fetchPlaceDetails()` behaviour is otherwise unchanged; Task 6 supplies the prior-photo urls that make (a) save money.

**Why (a) matters even before Task 6:** it makes the method idempotent w.r.t. resolved photos — a photo that already has a servable `url` is never re-billed. Task 6 threads prior urls in; without Task 6 this is a harmless no-op (fresh photos never carry a `url`), so the two tasks are independently reviewable.

- [ ] **Step 1: Write the failing test** (append the `it()` block to `RefreshHostLimitsTest.php`; imports already at the top)

```php
it('skips billed re-resolution of photos that already carry a url, and pools the rest', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.refresh.host_limits.google_places.pool_concurrency', 2);

    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/resolved.jpg'], 200),
    ]);

    $svc = app(GoogleBusinessService::class);
    $ref = new ReflectionMethod(GoogleBusinessService::class, 'resolvePhotoUrls');
    $ref->setAccessible(true);

    $photos = [
        ['ref' => 'places/x/photos/a', 'url' => 'https://cached.example/a.jpg'], // already resolved
        ['ref' => 'places/x/photos/b'],                                          // needs resolving
        ['ref' => 'places/x/photos/c'],                                          // needs resolving
    ];

    $out = $ref->invoke($svc, 'test-key', 'ChIJx', $photos);

    // Already-resolved photo untouched; the other two resolved.
    expect($out[0]['url'])->toBe('https://cached.example/a.jpg')
        ->and($out[1]['url'])->toBe('https://lh3.example/resolved.jpg')
        ->and($out[2]['url'])->toBe('https://lh3.example/resolved.jpg');

    // Only the 2 unresolved photos were billed (the cached one sent nothing).
    Http::assertSentCount(2);
    expect(method_exists(GoogleBusinessService::class, 'resolvePhotoUrls'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: FAIL — the current `resolvePhotoUrls` re-resolves ALL photos → `Http::assertSentCount(2)` fails (3 sent), and photo 0's `url` gets overwritten.

- [ ] **Step 3: Rewrite `resolvePhotoUrls`**

In `app/Services/Platforms/GoogleBusinessService.php`, replace the `resolvePhotoUrls()` method with:

```php
    private function resolvePhotoUrls(string $key, string $placeId, array $photos): array
    {
        $photos = array_values($photos);

        // SCALE-3: only resolve photos MISSING a servable url. A photo whose url was
        // carried over from the prior payload (GoogleBusinessFetch) is not re-billed.
        $toResolve = [];
        foreach ($photos as $index => $photo) {
            if (empty($photo['url']) && ! empty($photo['ref'])) {
                $toResolve[$index] = $photo;
            }
        }
        if ($toResolve === []) {
            return $photos;
        }

        // Cap the concurrent burst of BILLED media calls (SCALE-3): chunk the pool.
        $max = max(1, (int) config('partna.refresh.host_limits.google_places.pool_concurrency', 5));

        try {
            $responses = [];
            foreach (array_chunk($toResolve, $max, true) as $chunk) {
                $batch = Http::pool(fn (Pool $pool) => array_map(
                    fn (int $i, array $photo) => $pool->as((string) $i)
                        ->timeout(5)
                        ->withHeaders(['X-Goog-Api-Key' => $key])
                        ->get('https://places.googleapis.com/v1/'.($photo['ref'] ?? '').'/media', [
                            'maxWidthPx' => 1200,
                            'skipHttpRedirect' => 'true',
                        ]),
                    array_keys($chunk),
                    array_values($chunk),
                ));
                $responses += $batch;
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('google_business.photo_resolve_failed', [
                'place_id' => $placeId,
                'message' => $e->getMessage(),
            ]);

            return $photos;
        }

        foreach ($toResolve as $index => $photo) {
            $res = $responses[$index] ?? null;
            $uri = $res instanceof Response && $res->ok() ? $res->json('photoUri') : null;
            if (is_string($uri) && $uri !== '') {
                $photos[$index]['url'] = $uri;
            }
        }

        return $photos;
    }
```

**Note:** `array_chunk($toResolve, $max, true)` preserves the original photo indices (which may be sparse — e.g. `[1 => …, 2 => …]` when index 0 was already resolved), and `$pool->as((string) $i)` keys by that index, so the write-back loop's `$responses[$index]` aligns. The existing `report($e)` + `Log::warning` catch (already present) is retained.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: PASS.

- [ ] **Step 5: Regression-check Google Business**

Run: `php artisan test tests/Feature/Platforms/ --filter="GoogleBusiness"`
Expected: PASS — a first-connect resolve (no prior urls) still resolves every photo.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/GoogleBusinessService.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git add app/Services/Platforms/GoogleBusinessService.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git commit -m "feat(refresh): cap Places media pool + skip already-resolved photos (SCALE-3)"
```

---

## Task 6: Carry forward unchanged Places photo URLs across refreshes (best-effort)

**Files:**
- Modify: `app/Services/Platforms/GoogleBusinessService.php`
- Modify: `app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php`
- Test: append to `tests/Feature/Platforms/RefreshHostLimitsTest.php`

**Interfaces:**
- Consumes: nothing new (uses the Task 5 skip guard).
- Produces: `fetchPlaceDetails(string $placeId, array $priorPhotos = [])` — new **optional** 2nd param (connect callers unaffected); `carryForwardPhotoUrls(array $photos, array $priorPhotos): array` (private). `GoogleBusinessFetch::fetch()` passes `$payload['photos']` in.

**⚠️ Best-effort — flagged soft spot.** This reuses a prior servable url only when the fresh photo's `ref` (Google's photo resource `name`) is **unchanged**. Google's photo `name` can rotate between Place-Details calls; when it does, the photo is simply resolved fresh (Task 5) — **never worse than today, sometimes cheaper.** Savings scale with ref stability, which we do not control. Include this task only if the billed-cost reduction is wanted; the Task 5 concurrency cap stands alone without it. (Premise correction #2: the re-resolve is weekly, gated by `detailsFetchedAt < 6 days`, so the ceiling on savings is modest.)

- [ ] **Step 1: Write the failing test** (append the `it()` block to `RefreshHostLimitsTest.php`; imports already at the top)

```php
it('reuses a prior photo url when the ref is unchanged (no re-bill), resolves changed refs', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');

    // The Place-Details response returns two photo refs: one seen before, one new.
    // NOTE ordering: the `/media` pattern is listed FIRST — Http::fake matches the
    // first pattern, and the details glob (`v1/places/*`) would otherwise also match
    // the media URL (`v1/places/ChIJx/photos/NEW/media`). Media-first disambiguates.
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/new.jpg'], 200),
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJx',
            'photos' => [
                ['name' => 'places/ChIJx/photos/STABLE', 'widthPx' => 100, 'heightPx' => 100],
                ['name' => 'places/ChIJx/photos/NEW', 'widthPx' => 100, 'heightPx' => 100],
            ],
        ], 200),
    ]);

    $svc = app(GoogleBusinessService::class);
    $prior = [
        ['ref' => 'places/ChIJx/photos/STABLE', 'url' => 'https://lh3.example/stable.jpg'],
    ];

    $details = $svc->fetchPlaceDetails('ChIJx', $prior);

    $byRef = collect($details['photos'])->keyBy('ref');
    expect($byRef['places/ChIJx/photos/STABLE']['url'])->toBe('https://lh3.example/stable.jpg') // reused, not re-billed
        ->and($byRef['places/ChIJx/photos/NEW']['url'])->toBe('https://lh3.example/new.jpg');    // freshly resolved

    // Only the NEW ref hit the billed media endpoint.
    Http::assertSentCount(2); // 1 details + 1 media (STABLE skipped)
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: FAIL — `fetchPlaceDetails` ignores `$prior`, re-resolves STABLE → 3 requests sent, STABLE url overwritten.

- [ ] **Step 3: Add the `$priorPhotos` param + carry-forward**

In `app/Services/Platforms/GoogleBusinessService.php`, change the `fetchPlaceDetails` signature and its photo block:

```php
    public function fetchPlaceDetails(string $placeId, array $priorPhotos = []): ?array
```

Then in its body, replace the photo-resolution line (currently `$mapped['photos'] = $this->resolvePhotoUrls($key, $placeId, $mapped['photos']);`) with:

```php
        if (isset($mapped['photos']) && is_array($mapped['photos'])) {
            // SCALE-3: pre-populate servable urls from the prior payload for unchanged
            // refs so resolvePhotoUrls skips them (no billed re-call). Best-effort —
            // a rotated ref just resolves fresh below. Connect callers pass no prior
            // photos, so this is a no-op there.
            $mapped['photos'] = $this->carryForwardPhotoUrls($mapped['photos'], $priorPhotos);
            $mapped['photos'] = $this->resolvePhotoUrls($key, $placeId, $mapped['photos']);
        }
```

Add the private helper (next to `resolvePhotoUrls`):

```php
    /**
     * Copy a resolved servable url from the prior payload onto any fresh photo whose
     * ref is unchanged, so the billed media re-resolve is skipped (SCALE-3). Fail-safe:
     * an unmatched/rotated ref is left without a url and resolved fresh downstream.
     *
     * @param  array<int, array<string,mixed>>  $photos       fresh photos (ref only, no url)
     * @param  array<int, array<string,mixed>>  $priorPhotos  previously stored photos (ref + url)
     * @return array<int, array<string,mixed>>
     */
    private function carryForwardPhotoUrls(array $photos, array $priorPhotos): array
    {
        $priorByRef = [];
        foreach ($priorPhotos as $p) {
            if (! empty($p['ref']) && ! empty($p['url'])) {
                $priorByRef[$p['ref']] = $p['url'];
            }
        }
        if ($priorByRef === []) {
            return $photos;
        }

        foreach ($photos as $i => $photo) {
            $ref = $photo['ref'] ?? null;
            if ($ref !== null && empty($photo['url']) && isset($priorByRef[$ref])) {
                $photos[$i]['url'] = $priorByRef[$ref];
            }
        }

        return $photos;
    }
```

- [ ] **Step 4: Thread prior photos in from the refresh strategy**

In `app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php`, change the `fetchPlaceDetails` call (line ~40):

```php
        $details = $this->googleBusiness->fetchPlaceDetails((string) $placeId, (array) ($payload['photos'] ?? []));
```

(The connect path `GoogleBusinessController.php:85` calls `fetchPlaceDetails($data['placeId'])` with one arg — the new default `[]` keeps it identical.)

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshHostLimitsTest.php`
Expected: PASS.

- [ ] **Step 6: Regression-check Google Business end-to-end**

Run: `php artisan test tests/Feature/Platforms/ --filter="GoogleBusiness"`
Expected: PASS — `GoogleBusinessDetailsTest` still asserts the same stored payload (a first refresh has no prior photos → resolves all, unchanged behaviour).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Services/Platforms/GoogleBusinessService.php app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git add app/Services/Platforms/GoogleBusinessService.php app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php tests/Feature/Platforms/RefreshHostLimitsTest.php
git commit -m "feat(refresh): reuse unchanged Places photo urls across refreshes (SCALE-3)"
```

---

# Part B — OBS-1: silent-failure observability

## Task 7: Report the billed Place-Details failure

**Files:**
- Create: `app/Exceptions/Platforms/PlaceDetailsUnavailableException.php`
- Modify: `app/Services/Platforms/GoogleBusinessService.php`
- Test: `tests/Feature/Platforms/RefreshObservabilityTest.php`

**Interfaces:**
- Produces: `new PlaceDetailsUnavailableException(string $placeId, ?int $status)`; `fetchPlaceDetails()` now `report()`s on failure (both the thrown-exception and non-OK-status paths) while keeping its existing `Log::warning` + `return null` contract.

**Why both branches:** the sibling methods `resolvePhotoUrls`/`streetViewPano` already `report($e)` in their catch blocks — `fetchPlaceDetails` is the inconsistent one (verified). A Google **outage** manifests as a non-OK **status** (429/5xx), not a thrown exception, so the non-OK branch is the important one to alert on (the finding's core concern: "a Google outage would silently stale every business card").

- [ ] **Step 1: Write the failing test**

This file's `use` block covers the whole `RefreshObservabilityTest.php` — Tasks 8 and 10 append only `beforeEach`/helper/`it()` blocks, no further imports. (Importing `MissingPublicAllowlistException` / `PublicIntegrationConnectionResource` here before Task 10 creates them is safe — a `use` is an alias, not a load, and Task 7's tests never reference them.)

```php
<?php
// tests/Feature/Platforms/RefreshObservabilityTest.php
//
// OBS-1 report()/fail() assertions across the 4 surviving silent-failure sites.
// All imports for the whole file live in this block; Tasks 8 & 10 append only bodies.

use App\Exceptions\Platforms\MissingPublicAllowlistException;
use App\Exceptions\Platforms\PlaceDetailsUnavailableException;
use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('reports a PlaceDetailsUnavailableException when the billed Place-Details call is non-OK', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    Http::fake(['places.googleapis.com/v1/places/*' => Http::response(['error' => 'quota'], 429)]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJfail');

    expect($result)->toBeNull(); // contract preserved
    Exceptions::assertReported(fn (PlaceDetailsUnavailableException $e) => $e->placeId === 'ChIJfail' && $e->status === 429);
});

it('does not report on a healthy Place-Details fetch', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    // Media pattern first (see Task 6 note); the details response carries no photos,
    // so resolvePhotoUrls is skipped and no media call is made regardless.
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'x'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJok'], 200),
    ]);

    app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJok');

    Exceptions::assertNothingReported();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: FAIL — nothing is reported today (only `Log::warning`); the class doesn't exist.

- [ ] **Step 3: Create the exception**

```php
<?php
// app/Exceptions/Platforms/PlaceDetailsUnavailableException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when the BILLED Google Place-Details call fails or returns a
// non-OK status. Previously only Log::warning'd → invisible to Nightwatch, so a Google
// outage would silently stale every business card with nobody paged (OBS-1). Distinct
// from the transient photo/street-view misses, which stay quiet.
class PlaceDetailsUnavailableException extends RuntimeException
{
    public function __construct(public string $placeId, public ?int $status = null)
    {
        parent::__construct("Google Place-Details unavailable for {$placeId}".($status !== null ? " (status {$status})" : ''));
    }
}
```

- [ ] **Step 4: Report in both failure branches**

In `app/Services/Platforms/GoogleBusinessService.php`, add the import at the top:

```php
use App\Exceptions\Platforms\PlaceDetailsUnavailableException;
```

In `fetchPlaceDetails()`, in the `catch (\Throwable $e)` block, add `report($e)` above the existing `Log::warning`:

```php
        } catch (\Throwable $e) {
            report($e); // OBS-1: billed-call network failure must reach Nightwatch, not just the log.
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
```

And in the non-OK branch (`if (! $res->ok())`), add the report above the existing `Log::warning`:

```php
        if (! $res->ok()) {
            report(new PlaceDetailsUnavailableException($placeId, $res->status())); // OBS-1: an outage (429/5xx) pages on-call.
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'status' => $res->status(),
            ]);

            return null;
        }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Exceptions/Platforms/PlaceDetailsUnavailableException.php app/Services/Platforms/GoogleBusinessService.php tests/Feature/Platforms/RefreshObservabilityTest.php
git add app/Exceptions/Platforms/PlaceDetailsUnavailableException.php app/Services/Platforms/GoogleBusinessService.php tests/Feature/Platforms/RefreshObservabilityTest.php
git commit -m "feat(obs): report billed Place-Details failures to Nightwatch (OBS-1)"
```

---

## Task 8: Report data-shape refresh failures

**Files:**
- Modify: `app/Services/Platforms/PlatformRefresher.php`
- Test: append to `tests/Feature/Platforms/RefreshObservabilityTest.php`

**Interfaces:**
- Consumes: existing `FetchShapeException` (a data-integrity error — a stored payload lost a key the fetch needs).
- Produces: `PlatformRefresher::refresh()` now `report()`s the caught `FetchShapeException` (status `'error'`); the `FetchUnavailableException` path (transient miss, `'unavailable'`) stays quiet.

**Why report the real exception (not a synthesized one):** `FetchShapeException` already carries the message and is a groupable type in Nightwatch. Reporting `$e` in the catch block preserves the real stack and keeps the report site adjacent to the existing `recordFailure(...'error')` bookkeeping. The `unsupported_platform` guard (`recordFailure(..., 'error')` at line ~40) is unreachable from cron/controller (both gate on the refreshable set — verified comment) and is left as-is.

**SEC-1 test-timing note:** creating a `youtube` connection resolves `PlatformRegistry` in the model's `saving` guard, eagerly wiring scrapers (`reference_integrationconnection_guard_test_timing`). This test drives the real `YoutubeFetch` shape-check (no scraper mock needed — the payload simply lacks `handle`), so there is no mock-ordering hazard here.

- [ ] **Step 1: Write the failing test** (append the `beforeEach` + helper + `it()` blocks to `RefreshObservabilityTest.php`; imports already at the top. The `beforeEach` applies file-wide — harmless for Task 7's tests, which don't touch the DB.)

```php
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function obsUser(): User
{
    return User::create([
        'handle' => 'obs', 'handle_lc' => 'obs', 'display_name' => 'Obs',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'obs@example.com',
    ]);
}

it('reports a FetchShapeException (data corruption) but records status=error', function () {
    Exceptions::fake();
    $user = obsUser();

    // YouTube payload MISSING the required `handle` → YoutubeFetch throws FetchShapeException.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['name' => 'no handle here'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('error')
        ->and($conn->consecutive_failures)->toBe(1);
    Exceptions::assertReported(FetchShapeException::class);
});

it('does NOT report a transient unavailable miss', function () {
    Exceptions::fake();
    $user = obsUser();

    // Missing placeId → GoogleBusinessFetch throws FetchUnavailableException (transient).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['url' => 'https://maps.google.com/x'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('unavailable');
    Exceptions::assertNothingReported();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: FAIL — the shape-error case reports nothing today (only `Log::warning`).

- [ ] **Step 3: Report the caught shape exception**

In `app/Services/Platforms/PlatformRefresher.php`, in `refresh()`, add `report($e)` to the `FetchShapeException` catch (leave the `FetchUnavailableException` catch untouched):

```php
        } catch (FetchShapeException $e) {
            // OBS-1: a shape error means a stored payload lost a required key (data
            // corruption). Report it so Nightwatch pages — the Log::warning in
            // recordFailure() alone is an invisible breadcrumb. Transient upstream
            // misses (FetchUnavailableException, below) stay quiet by design.
            report($e);

            return $this->recordFailure($connection, $e->getMessage(), 'error');
        } catch (FetchUnavailableException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'unavailable');
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/RefreshObservabilityTest.php
git add app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/RefreshObservabilityTest.php
git commit -m "feat(obs): report data-shape refresh failures to Nightwatch (OBS-1)"
```

---

## Task 9: Fail the `DeleteMirroredMediaJob` non-platforms guard

**Files:**
- Modify: `app/Jobs/Platforms/DeleteMirroredMediaJob.php`
- Test: `tests/Feature/Platforms/DeleteMirroredMediaJobTest.php`

**Interfaces:**
- Produces: the defence-in-depth guard now `$this->fail(...)`s (→ `failed()` → `report()`) instead of a bare `return`, so a corrupted `_folder` that would silently skip cleanup pages on-call.

**Vendor-verified chain:** `$this->fail($e)` → `InteractsWithQueue::fail()` → `$this->job->fail()` → base `Job::fail()` → `$this->failed($e)` (the job's `failed()` → `report($e)`). Under the sync queue (`dispatchSync`, which `phpunit.xml` uses), `$this->job` is set, so the chain fires and is assertable. `fail()` does **not** halt `handle()`, so the guard must `return` immediately after.

**No regression to the existing `DeleteMirroredMediaJobTest`:** its bad-prefix cases call `->handle()` **directly** (no queue job), so `InteractsWithQueue::fail()` sees a null `$this->job` and no-ops — the job still doesn't delete, and the existing "no broad deletion" assertion still passes. Leave that test as-is; this task's new test exercises the `fail()` path via `dispatchSync`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Platforms/DeleteMirroredMediaJobTest.php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

it('fails (and reports) instead of silently returning on a non-platforms prefix', function () {
    Exceptions::fake();
    Storage::fake('media');

    DeleteMirroredMediaJob::dispatchSync('not-platforms/rogue');

    Exceptions::assertReported(fn (\Throwable $e) => str_contains($e->getMessage(), 'non-platforms prefix'));
});

it('deletes a valid platforms/ prefix without reporting', function () {
    Exceptions::fake();
    Storage::fake('media');
    Storage::disk('media')->put('platforms/instagram/123/a.jpg', 'x');

    DeleteMirroredMediaJob::dispatchSync('platforms/instagram/123');

    expect(Storage::disk('media')->exists('platforms/instagram/123/a.jpg'))->toBeFalse();
    Exceptions::assertNothingReported();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/DeleteMirroredMediaJobTest.php`
Expected: FAIL — the first test reports nothing (the guard does a bare `return`).

- [ ] **Step 3: Replace the bare `return` with `$this->fail()`**

In `app/Jobs/Platforms/DeleteMirroredMediaJob.php`, change the guard in `handle()`:

```php
        // Defence-in-depth: only ever delete inside the platforms namespace. A
        // corrupted/empty _folder must never widen into a broad-prefix wipe — and it
        // must NOT silently no-op either (OBS-1). fail() → failed() → report() pages
        // on-call; fail() doesn't stop handle(), so return right after.
        if (! str_starts_with($this->folder, 'platforms/')) {
            Log::warning('DeleteMirroredMediaJob: refused non-platforms prefix', [
                'folder' => $this->folder,
            ]);
            $this->fail(new \RuntimeException("DeleteMirroredMediaJob refused non-platforms prefix: {$this->folder}"));

            return;
        }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/DeleteMirroredMediaJobTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Job-hygiene gate still green**

Run: `php artisan test tests/Feature/Queue/JobHygienePolicyTest.php`
Expected: PASS (the job's `$tries`/`$backoff`/`$timeout` are unchanged).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Jobs/Platforms/DeleteMirroredMediaJob.php tests/Feature/Platforms/DeleteMirroredMediaJobTest.php
git add app/Jobs/Platforms/DeleteMirroredMediaJob.php tests/Feature/Platforms/DeleteMirroredMediaJobTest.php
git commit -m "feat(obs): DeleteMirroredMediaJob guard fails (reports) instead of silent return (OBS-1)"
```

---

## Task 10: Report the fail-closed public-allowlist branch + full-suite gate

**Files:**
- Create: `app/Exceptions/Platforms/MissingPublicAllowlistException.php`
- Modify: `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`
- Test: append to `tests/Feature/Platforms/RefreshObservabilityTest.php`

**Interfaces:**
- Produces: `new MissingPublicAllowlistException(string $platform)`; the resource's fail-closed branch (a platform with no allowlist entry) now `report()`s alongside its `Log::warning` + empty-payload return.

**Why this deserves a page:** the branch is unreachable-by-design (the SEC-1 model saving guard rejects unregistered platforms at write time, and every registered platform already has an entry — verified comment). If it *does* fire, a platform shipped without an allowlist and is silently rendering an empty payload on the public, CDN-cached wire — a config bug you want paged immediately.

**Accepted trade-off (flagged):** the report is un-throttled. Because the branch is unreachable-by-design, a "report storm" implies a genuine P1 you'd fix at once; adding a cache-based throttle here would pull raw `Cache::*` onto the public request path for a branch that should never fire — not worth it. The resource is CDN-cached (renders only on cache miss), further bounding volume.

- [ ] **Step 1: Write the failing test** (append the `it()` blocks to `RefreshObservabilityTest.php`; imports already at the top)

```php
it('reports (and fails closed to empty) when a platform has no public allowlist', function () {
    Exceptions::fake();

    // Build the model WITHOUT saving so the SEC-1 saving guard doesn't reject the
    // unknown platform — we only exercise the resource's read-time allowlist branch.
    $conn = new IntegrationConnection([
        'platform' => 'totally-unregistered',
        'resource_id' => 'x',
        'payload' => ['secret_internal_key' => 'leak-me'],
    ]);

    $out = (new PublicIntegrationConnectionResource($conn))->toArray(request());

    expect($out['payload'])->toBe([]); // fail-closed: nothing leaks
    Exceptions::assertReported(fn (MissingPublicAllowlistException $e) => str_contains($e->getMessage(), 'totally-unregistered'));
});

it('does not report for a normally-allowlisted platform', function () {
    Exceptions::fake();

    $conn = new IntegrationConnection([
        'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'c', 'name' => 'vid', '_internal' => 'hidden'],
    ]);

    $out = (new PublicIntegrationConnectionResource($conn))->toArray(request());

    expect($out['payload'])->toHaveKey('handle')->not->toHaveKey('_internal');
    Exceptions::assertNothingReported();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: FAIL — the fail-closed branch reports nothing today; the class doesn't exist.

- [ ] **Step 3: Create the exception**

```php
<?php
// app/Exceptions/Platforms/MissingPublicAllowlistException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when PublicIntegrationConnectionResource hits its fail-closed
// branch — a platform with NO public allowlist entry. Unreachable by design (SEC-1
// rejects unregistered platforms at write time), so if it fires it's a config bug that
// is silently rendering an empty payload publicly: page immediately (OBS-1).
class MissingPublicAllowlistException extends RuntimeException
{
    public function __construct(public string $platform)
    {
        parent::__construct("PublicIntegrationConnectionResource: no public allowlist for platform '{$platform}'");
    }
}
```

- [ ] **Step 4: Report in the fail-closed branch**

In `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`, add the import:

```php
use App\Exceptions\Platforms\MissingPublicAllowlistException;
```

In `filterPayload()`, in the `if ($allowed === null)` branch, add `report(...)` above the existing `Log::warning`:

```php
        if ($allowed === null) {
            // OBS-1: this fail-closed branch is unreachable by design — if it fires, a
            // platform shipped without an allowlist and is rendering empty publicly.
            // Report so Nightwatch pages (Log::warning alone is invisible to it).
            report(new MissingPublicAllowlistException($platform));
            Log::warning('PublicIntegrationConnectionResource: no allowlist for platform', [
                'platform' => $platform,
            ]);

            return [];
        }
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/Platforms/RefreshObservabilityTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite (namespace/relocation safety net)**

Run: `composer test`
Expected: PASS — full suite green in the main checkout (not a filtered subset; `feedback_namespace_relocation_short_refs`). Do NOT run this concurrently with a review subagent (`feedback_audit_fix_runbook_gotchas`).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Exceptions/Platforms/MissingPublicAllowlistException.php app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php tests/Feature/Platforms/RefreshObservabilityTest.php
git add app/Exceptions/Platforms/MissingPublicAllowlistException.php app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php tests/Feature/Platforms/RefreshObservabilityTest.php
git commit -m "feat(obs): report fail-closed public-allowlist branch to Nightwatch (OBS-1)"
```

---

## Self-Review

**1. Spec coverage.**

*SCALE-3 (per prompt + audit "What to do", reframed by Premise correction #1):*
- per-host politeness (obsolete `max(global,host)` delay) → **reframed**: replaced by inner-burst concurrency caps + iTunes cache (Tasks 1–5) ✓ (global throttle gone — Premise #1)
- cache successful iTunes responses (hours) → Task 2 ✓
- cap `Http::pool` concurrency for ytimg → Task 3 ✓
- cap `Http::pool` concurrency for Places → Task 5 ✓
- skip Places photos already carrying a resolved `url` → Task 5 (guard) + Task 6 (threads the prior urls that make it save) ✓
- Eventbrite/Humanitix `fetchMany` bursts → Task 4 (shared pool cap) ✓

*OBS-1 (4 surviving sites; Fresha dropped per Premise #3):*
- `GoogleBusinessService::fetchPlaceDetails` (billed) → Task 7 ✓
- `PlatformRefresher::recordFailure` shape errors → Task 8 ✓
- `DeleteMirroredMediaJob` bare-`return` guard → Task 9 ✓
- `PublicIntegrationConnectionResource` fail-closed → Task 10 ✓
- `FreshaController` employeeServices → **intentionally dropped** (deleted by the pending Fresha remediation plan — Premise #3) ✓

**2. Placeholder scan.** Every code step carries complete code and exact run/expected lines. No "TBD"/"similar to Task N"/"add error handling". Two flagged soft spots are explicit, not placeholders: Task 6's photo-ref stability (best-effort, fail-safe) and Task 10's un-throttled report (accepted, branch unreachable-by-design). ✓

**3. Type consistency.**
- `config('partna.refresh.host_limits.*')` keys defined in Task 1 (`itunes.cache_ttl_seconds`, `youtube_thumbnails.pool_concurrency`, `google_places.pool_concurrency`, `fetch_many.pool_concurrency`) are read verbatim in Tasks 2/3/5/4 respectively. ✓
- `CacheKeyGenerator::itunesResponse(string)` (Task 2) used only inside `AppleSearch::itunes()`. ✓
- Private pool helpers `pooledHead` (Task 3), `pooledGet` (Task 4), and the rewritten `resolvePhotoUrls` / new `carryForwardPhotoUrls` (Tasks 5/6) are each self-contained to their file. ✓
- `fetchPlaceDetails(string $placeId, array $priorPhotos = [])` (Task 6) — the optional param keeps the 1-arg connect caller (`GoogleBusinessController:85`) valid; the refresh caller (`GoogleBusinessFetch`, Task 6 Step 4) passes the 2nd arg. ✓
- Exceptions `PlaceDetailsUnavailableException($placeId, $status)` (Task 7) and `MissingPublicAllowlistException($platform)` (Task 10) — public props asserted identically in their tests. ✓

**4. Adversarial verification (against landed code + Laravel vendor source).**
- Plan 1/2/3 landing confirmed (`RefreshConnectionJob` exists, `--throttle-ms` gone, `platform-refresh` limiter registered, `ApifyBudget` present).
- All 4 SCALE-3 pool sites confirmed uncapped; `itunes()` confirmed uncached and the single choke point.
- All 4 OBS-1 sites confirmed `Log::warning`-only; sibling `report()` inconsistency in `GoogleBusinessService` confirmed.
- `GoogleBusinessFetch` 6-day gate confirmed (Premise #2); Fresha GraphQL confirmed still present + slated for deletion (Premise #3).
- `$this->fail()` → `failed()` → `report()` chain verified in `vendor/laravel/framework/.../Queue/InteractsWithQueue.php` + `Jobs/Job.php`.

**Remaining soft spots (honest):**
- **Task 6 photo-ref stability** — savings depend on Google's photo `name` staying stable across Place-Details calls; fail-safe (unmatched ref resolves fresh). If a reviewer distrusts it, ship Tasks 1–5 + 7–10 and drop Task 6; nothing else depends on it.
- **Task 10 un-throttled report** — accepted; unreachable-by-design + CDN-cached path.
- **Pool-cap defaults** (10/5/6) are conservative first guesses; tune once Nightwatch shows real burst shapes.
- **Existing failure-path tests** — a bare `report()` does NOT fail a Pest/Laravel test by default (no existing GB/refresher test uses `assertNothingReported()`/`withoutExceptionHandling()` on these paths — verified), so the new reports shouldn't cascade. The Task 10 full-suite gate is the safety net; if any test drives one of these paths and now trips, add `Exceptions::fake()` to it (don't remove the report).

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-03-platform-refresh-plan-4-observability-host-limits.md`. **P2 cluster, no DB migration → lighter gate**, but per the audit's per-unit sign-off discipline, implementation waits for your sign-off. Two execution options once approved:

**1. Subagent-Driven (recommended)** — a fresh subagent per task, two-stage review between tasks (implement Sonnet → independent Sonnet review), fast iteration. Matches the audit fix-flow. Tasks 1→10 are ordered so config (Task 1) lands first and each subsequent task is independently testable; Task 6 is the one optional/droppable unit.

**2. Inline Execution** — execute tasks in this session with checkpoints for review.

**Which approach?**
