# Sitepage Cache Freshness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a design save visible on the origin on the very next request and on the edge within one purge cycle, by removing the stale-timestamp race that pins old renders into a 24-hour edge cache.

**Architecture:** Three independent changes. (1) A monotonic **timestamp floor** cache key, written by the invalidation path and `max()`-ed into the read path, so a stale `handle.resolve` entry can no longer hold the payload cache key back. (2) A `?preview=1` **edge bypass** in the Cloudflare Worker so the dashboard's live-preview iframe goes straight to origin. (3) **Chained follow-up purges** — the primary purge dispatches three delayed follow-ups up-front instead of one, as defence in depth.

**Tech Stack:** PHP 8.2 / Laravel 12, Redis cache (`Cache` facade, default store), Pest 4 + PHPUnit (SQLite in-memory), plain-JS Cloudflare Worker in `cloudflare-worker/`.

**Source spec:** `docs/superpowers/specs/2026-07-25-sitepage-cache-freshness-design.md`

## Global Constraints

- **No Laravel migration files.** Nothing in this plan touches the database schema. If you think you need a migration, you have misread the plan.
- **Do not modify `CacheLockService`.** It is shared infrastructure; the spec puts the fix at the one call site. Explicitly out of scope.
- **Do not change `PRIMARY_CACHE_TTL_S`** (86,400) in `cloudflare-worker/src/index.js`. Edge TTL stays at 24h.
- **Do not change `resolve_cache_ttl`** (30s). The floor makes its staleness harmless.
- **Floor writes only ever happen post-commit.** A floor written inside an open DB transaction hands out the post-write cache key before the data commits, which is worse than the bug being fixed. Every existing caller already satisfies this; the helper's docblock must state the constraint.
- **The floor write must be monotonic (only-raise):** `max((int) Cache::get($key, 0), $ts)`, never a blind `Cache::put`.
- `followUpDepth` must be a **plain property with a class-level default**, assigned in the constructor body — never a promoted readonly parameter. See Task 3.
- Config default for the follow-up schedule: `[120, 300, 900]`, **absolute offsets from the primary purge**, not per-hop delays.
- Floor TTL config default: `600` seconds.
- Comment style per `CLAUDE.md`: comment for WHY, not what. No banners, no restatements.
- Run `php artisan pint` on changed files before each commit. Do **not** run pint across the repo — it churns the baseline.
- Tests run SQLite; production is Postgres. Nothing here is constraint-bound, so no DDL verification is required.

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `app/Services/Cache/CacheKeyGenerator.php` | modify (~line 264) | Add `handleResolveFloor()` next to `handleResolve()` |
| `config/partna.php` | modify (~1088, ~1931) | Add `public_profile.resolve_floor_ttl`; replace `cache.purge_followup_seconds` with `cache.purge_followup_schedule` |
| `app/Services/Cache/SiteCacheService.php` | modify (`invalidateSitePayload`, line 572) | Write the monotonic floor; new private `writeResolveFloor()` helper |
| `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php` | modify (`show()`, ~line 99) | Read the floor, `max()` it against the resolved timestamp |
| `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` | modify | `followUpDepth` property; up-front multi-follow-up dispatch; depth in `uniqueId()` |
| `cloudflare-worker/src/index.js` | modify (line 405) | Add `preview` to the `serveIndividual()` bypass |
| `tests/Feature/Cache/DesignKitCacheInvalidationTest.php` | extend | Race test, floor helper test, monotonicity test |
| `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php` | modify | Schedule fan-out, no-chaining, per-depth `uniqueId()` |
| `tests/Unit/Jobs/HorizonQueueCoverageTest.php` | modify (line 592) | Drop the removed config key |
| `tests/Feature/Cache/WorkerPreviewBypassTest.php` | create | PHP-parses `index.js` to assert `preview` is in the bypass |

---

### Task 1: Timestamp floor — key, config, and monotonic write

**Files:**
- Modify: `app/Services/Cache/CacheKeyGenerator.php:264-276`
- Modify: `config/partna.php:1088-1097`
- Modify: `app/Services/Cache/SiteCacheService.php:572-632`
- Test: `tests/Feature/Cache/DesignKitCacheInvalidationTest.php` (append)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `CacheKeyGenerator::handleResolveFloor(string $handle): string` → `"handle.resolve.floor:<lowercased-handle>"`
  - `SiteCacheService::invalidateSitePayload(Site $site): void` — unchanged signature, now also writes the floor.
  - Config key `partna.public_profile.resolve_floor_ttl` (int, default 600).
  - Task 2 reads the floor key with `Cache::get($key, 0)` on the **default** cache store — the same store `SiteCacheService` and `CacheLockService` use. Do not pin a store.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Cache/DesignKitCacheInvalidationTest.php`. The file's `beforeEach` already calls `Cache::flush()` and the `setup*Table()` helpers, and the existing tests use `DB::connection('pgsql')->table(...)->insert(...)` — follow that pattern exactly.

```php
use App\Services\Cache\CacheKeyGenerator;

// ── Timestamp floor: key format ──────────────────────────────────────────────

it('builds the handle resolve floor key from the lowercased handle', function () {
    expect(CacheKeyGenerator::handleResolveFloor('FloorCase'))
        ->toBe('handle.resolve.floor:floorcase');
});

// ── Timestamp floor: invalidateSitePayload writes it ─────────────────────────
// The floor is what makes a stale handle.resolve entry harmless: the controller
// takes max(resolved ts, floor), so a re-installed pre-write timestamp can no
// longer hold the public.profile:* key back.

it('writes the site updated_at timestamp to the resolve floor on invalidation', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floorwrite',
        'handle_lc' => 'floorwrite',
        'primary_email' => 'floorwrite@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floorwrite',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $site = Site::query()->findOrFail($siteId);

    app(SiteCacheService::class)->invalidateSitePayload($site);

    expect((int) Cache::get('handle.resolve.floor:floorwrite'))
        ->toBe($site->updated_at->timestamp);
});

// ── Timestamp floor: monotonicity ────────────────────────────────────────────
// invalidateSitePayload() has callers that can hold a Site instance whose
// updated_at predates a concurrent save (ServiceCategoryObserver, UserCacheService's
// catch-all via the memoized $professional->site relation, ClaimSiteService,
// deletion paths). A blind Cache::put from one of those would regress a higher
// floor written moments earlier and re-open the exact race this fixes.

it('never regresses an existing higher resolve floor', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $past = now()->subMinutes(5)->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floormono',
        'handle_lc' => 'floormono',
        'primary_email' => 'floormono@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floormono',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    // A newer save already raised the floor.
    $higher = now()->addMinutes(10)->timestamp;
    Cache::put('handle.resolve.floor:floormono', $higher, now()->addSeconds(600));

    // A caller holding the 5-minutes-stale Site instance invalidates.
    $staleSite = Site::query()->findOrFail($siteId);
    app(SiteCacheService::class)->invalidateSitePayload($staleSite);

    expect((int) Cache::get('handle.resolve.floor:floormono'))->toBe($higher);
});

// ── Timestamp floor: TTL comes from config, not a literal ────────────────────

it('takes the resolve floor TTL from config', function () {
    config()->set('partna.public_profile.resolve_floor_ttl', 1234);

    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'floorttl',
        'handle_lc' => 'floorttl',
        'primary_email' => 'floorttl@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'floorttl',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Hydrate the model BEFORE spying — a query can touch the cache, and a spy
    // installed around it would record puts this assertion doesn't care about.
    $site = Site::query()->findOrFail($siteId);

    Cache::spy();

    app(SiteCacheService::class)->invalidateSitePayload($site);

    // The TTL must be the config value, not a literal 600 in the service.
    Cache::shouldHaveReceived('put')
        ->withArgs(fn ($key, $value, $ttl) => $key === 'handle.resolve.floor:floorttl' && $ttl === 1234)
        ->once();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
./vendor/bin/pest tests/Feature/Cache/DesignKitCacheInvalidationTest.php
```

Expected: FAIL. The key-format test fails with `Call to undefined method App\Services\Cache\CacheKeyGenerator::handleResolveFloor()`; the write/monotonicity/TTL tests fail because no floor key is ever written (`Cache::get` returns null).

- [ ] **Step 3: Add the key generator method**

In `app/Services/Cache/CacheKeyGenerator.php`, immediately after `handleResolve()` (which ends at line 267):

```php
    /**
     * Monotonic floor for the handle-resolve timestamp.
     *
     * The public.profile:{handle}:{ts} key is deliberately timestamp-keyed so
     * mutations roll it forward — but {ts} is read out of handle.resolve, a 30s
     * cache. A request that read the DB before a write committed can re-put the
     * pre-write timestamp AFTER invalidation deleted it, re-leasing stale data
     * for another 30s. This key is the escape hatch: the invalidation path owns
     * it, the read path max()es against it, so a stale resolve entry can no
     * longer hold the payload key back.
     *
     * Writer: SiteCacheService::invalidateSitePayload.
     * Reader: IndividualProfileController::show.
     */
    public static function handleResolveFloor(string $handle): string
    {
        return 'handle.resolve.floor:'.strtolower($handle);
    }
```

- [ ] **Step 4: Add the config entry**

In `config/partna.php`, inside the `'public_profile' => [` block (starts line 1088), after `'resolve_cache_ttl'`:

```php
        // Monotonic floor for the resolve timestamp (see
        // CacheKeyGenerator::handleResolveFloor). Must outlive any stale resolve
        // entry that could carry an older stamp: resolve primary is 30s with ±20%
        // jitter (≤36s) and its :stale twin is 10× that (≤360s). 600 clears both
        // with margin. Lower it and the race it closes reopens for the gap.
        'resolve_floor_ttl' => (int) env('PARTNA_PUBLIC_PROFILE_RESOLVE_FLOOR_TTL', 600),
```

- [ ] **Step 5: Write the monotonic floor in `SiteCacheService`**

In `app/Services/Cache/SiteCacheService.php`, the `handle` block inside `invalidateSitePayload()` currently reads (lines 621-629):

```php
        $handle = strtolower((string) ($site->subdomain ?? ''));
        if ($handle !== '') {
            $resolveKey = CacheKeyGenerator::handleResolve($handle);
            $keys[] = $resolveKey;
            $keys[] = $resolveKey.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
```

Replace with:

```php
        $handle = strtolower((string) ($site->subdomain ?? ''));
        if ($handle !== '') {
            $resolveKey = CacheKeyGenerator::handleResolve($handle);
            $keys[] = $resolveKey;
            $keys[] = $resolveKey.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));

        // Deleting handle.resolve is not sufficient — an in-flight reader that
        // queried the DB pre-commit can re-put the old timestamp after the
        // delete. The floor is the authoritative lower bound the reader can't
        // regress below.
        if ($handle !== '') {
            $this->raiseResolveFloor($handle, $site->updated_at?->timestamp);
        }
```

Then add the private helper. Put it directly below `invalidateSitePayload()`:

```php
    /**
     * Raise the handle-resolve timestamp floor, only-ever-upward.
     *
     * INVARIANT — this may only be called POST-COMMIT. A floor written inside an
     * open transaction publishes the post-write cache key before the data is
     * visible, so a racing reader caches PRE-commit data under the authoritative
     * new key — and public.profile:* keys are never explicitly busted (rotation
     * by key is the design), so that entry survives the full payload TTL plus
     * its stale window. Every current caller of invalidateSitePayload() already
     * satisfies this (SiteObserver and ServiceCategoryObserver are
     * $afterCommit = true; UserSiteController::update busts after the save;
     * ClaimSiteService invalidates outside its transaction closure). Nothing
     * enforces it — any new caller inside a transaction MUST defer.
     *
     * Only-raise, not a blind put: several callers can hold a Site instance
     * whose updated_at predates a concurrent save, and lowering the floor
     * reopens the very race this closes. The read-modify-write is not atomic,
     * but it narrows exposure from "any invalidation within the floor TTL" to
     * microseconds, and its worst case degrades to the pre-floor behaviour.
     *
     * A null/0 timestamp (malformed row) skips the write entirely — 0 is a no-op
     * under max() but writing it would clobber a valid higher floor.
     */
    private function raiseResolveFloor(string $handle, ?int $timestamp): void
    {
        if ($timestamp === null || $timestamp <= 0) {
            return;
        }

        $key = CacheKeyGenerator::handleResolveFloor($handle);
        $floor = max((int) Cache::get($key, 0), $timestamp);

        Cache::put($key, $floor, (int) config('partna.public_profile.resolve_floor_ttl', 600));
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Cache/DesignKitCacheInvalidationTest.php
```

Expected: PASS, all tests in the file including the three pre-existing CCH-4 tests.

- [ ] **Step 7: Run the wider cache suite for regressions**

```bash
./vendor/bin/pest tests/Feature/Cache tests/Unit/Services/Cache
```

Expected: PASS. If `WarmPublicSiteCacheJobTest` fails, stop — the spec says `WarmPublicSiteCacheJob` is deliberately unchanged (it reads the site fresh from the DB, so its key is already authoritative); you have modified something you should not have.

- [ ] **Step 8: Format and commit**

```bash
php artisan pint app/Services/Cache/CacheKeyGenerator.php app/Services/Cache/SiteCacheService.php config/partna.php tests/Feature/Cache/DesignKitCacheInvalidationTest.php
git add app/Services/Cache/CacheKeyGenerator.php app/Services/Cache/SiteCacheService.php config/partna.php tests/Feature/Cache/DesignKitCacheInvalidationTest.php
git commit -m "fix(cache): add a monotonic handle-resolve timestamp floor

The public.profile:{handle}:{ts} key rotates on updated_at, but {ts} is
read out of the 30s handle.resolve cache — so a delete-then-stale-set race
re-leases the pre-write timestamp and the payload key never rolls. The
floor is owned by the invalidation path and read as a lower bound."
```

---

### Task 2: Controller reads the floor (closes the race)

**Files:**
- Modify: `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:99`
- Test: `tests/Feature/Cache/DesignKitCacheInvalidationTest.php` (append)

**Interfaces:**
- Consumes: `CacheKeyGenerator::handleResolveFloor(string $handle): string` and the floor written by `SiteCacheService::invalidateSitePayload()` (Task 1).
- Produces: no new public API. The behavioural contract is: `show()` builds its payload key from `max((int) $resolved['updated_at_ts'], (int) Cache::get(handleResolveFloor($handle), 0))`.

- [ ] **Step 1: Write the failing race test**

Append to `tests/Feature/Cache/DesignKitCacheInvalidationTest.php`. This is the test the spec calls the race test — it must fail without the floor read.

```php
// ── The race, end to end ─────────────────────────────────────────────────────
// Reproduces the actual production sequence: a reader queries the DB pre-commit,
// the write commits and invalidates, THEN the in-flight reader's Cache::put lands
// and re-installs the pre-write timestamp with a fresh 30s lease. Without the
// floor the controller builds the OLD payload key and serves the pre-change
// render for up to 30s — long enough for the edge purge to land and re-pin it
// for the full 24h edge TTL.

it('resolves the post-write payload key even when a stale resolve entry is re-installed', function () {
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $past = now()->subMinutes(5)->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'racecase',
        'handle_lc' => 'racecase',
        'display_name' => 'Race Case',
        'primary_email' => 'racecase@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'racecase',
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'created_at' => $past,
        'updated_at' => $past,
    ]);

    $staleTs = Site::query()->findOrFail($siteId)->updated_at->timestamp;

    // The write commits and rotates updated_at.
    $site = Site::query()->findOrFail($siteId);
    $site->touch();
    $freshTs = $site->fresh()->updated_at->timestamp;
    expect($freshTs)->toBeGreaterThan($staleTs);

    // Invalidation runs (SiteObserver::saved, afterCommit).
    app(SiteCacheService::class)->invalidateSitePayload($site->fresh());

    // The in-flight reader's Cache::put lands AFTER the delete — the race.
    Cache::put(
        CacheKeyGenerator::handleResolve('racecase'),
        ['pro_id' => $userId, 'site_id' => $siteId, 'updated_at_ts' => $staleTs],
        now()->addSeconds(30),
    );

    $response = $this->getJson('/api/public/profiles/racecase');
    $response->assertOk();

    // The post-write key must be the one that got populated; the pre-write key
    // must never have been built.
    expect(Cache::has(CacheKeyGenerator::publicProfile('racecase', $freshTs)))->toBeTrue()
        ->and(Cache::has(CacheKeyGenerator::publicProfile('racecase', $staleTs)))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Cache/DesignKitCacheInvalidationTest.php --filter="resolves the post-write payload key"
```

Expected: FAIL — the assertion that the fresh key exists is false, because the controller built the key from the re-installed `$staleTs`.

- [ ] **Step 3: Read the floor in the controller**

In `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`, line 99 currently reads:

```php
        $key = CacheKeyGenerator::publicProfile($handleLc, (int) $resolved['updated_at_ts']);
```

Replace with:

```php
        // handle.resolve can hand back a pre-write timestamp: its delete-then-set
        // window lets an in-flight reader re-install the old stamp with a fresh
        // lease. The floor is written post-commit by invalidateSitePayload and
        // only ever rises, so max() pins the key to the post-write value.
        // Costs a third Redis round-trip on the hot path — a per-request cost
        // paid to close a per-save race.
        $ts = max(
            (int) $resolved['updated_at_ts'],
            (int) Cache::get(CacheKeyGenerator::handleResolveFloor($handleLc), 0),
        );

        $key = CacheKeyGenerator::publicProfile($handleLc, $ts);
```

- [ ] **Step 4: Update the class docblock**

The class docblock's caching section (lines ~22-40) documents the two-lookup hot path and asserts staleness "is bounded by the TTL — short enough that no mutation-driven invalidation is required". That is now the thing this change disproves. Amend the item-1 paragraph so it ends:

```
 *                                  Staleness is bounded by the TTL, but the TTL
 *                                  alone is NOT sufficient: the payload key's
 *                                  rotation signal (updated_at_ts) lives inside
 *                                  this cached value, so a stale entry gates the
 *                                  rotation. handle.resolve.floor:{handle} (below)
 *                                  is the fix — do not rely on the TTL alone.
 *   1b. handle.resolve.floor:{handle}
 *                                  Monotonic post-commit timestamp floor written
 *                                  by SiteCacheService::invalidateSitePayload.
 *                                  max()'d against updated_at_ts so a stale
 *                                  resolve entry can't hold the payload key back.
 *                                  NOT covered: the ['not_found' => true] variant
 *                                  — a stale-set can re-install that too and this
 *                                  method 404s before reaching the max(). Matters
 *                                  for first publish/claim, not design edits; the
 *                                  follow-up purge chain is the rescue there.
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
./vendor/bin/pest tests/Feature/Cache/DesignKitCacheInvalidationTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the public-profile suite for regressions**

```bash
./vendor/bin/pest tests/Feature/Api/PublicSite tests/Feature/Cache
```

Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
php artisan pint app/Http/Controllers/Api/PublicSite/IndividualProfileController.php tests/Feature/Cache/DesignKitCacheInvalidationTest.php
git add app/Http/Controllers/Api/PublicSite/IndividualProfileController.php tests/Feature/Cache/DesignKitCacheInvalidationTest.php
git commit -m "fix(cache): pin the public profile key to the resolve floor

max(resolved updated_at_ts, floor) so a re-installed stale resolve entry
can no longer build the pre-write payload key. Includes the race
regression test, which fails without the floor read."
```

---

### Task 3: Chained follow-up purges

**Files:**
- Modify: `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- Modify: `config/partna.php:1930-1934`
- Test: `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`
- Test: `tests/Unit/Jobs/HorizonQueueCoverageTest.php:592`

**Interfaces:**
- Consumes: nothing from Tasks 1-2.
- Produces:
  - `CloudflareCachePurgeJob::__construct(string $handle, ?string $customDomain = null, bool $followUp = false, ?string $moderationCaseId = null, bool $bulk = false, int $followUpDepth = 0)` — `$followUpDepth` is the **sixth** positional parameter and is **not** promoted.
  - `public int $followUpDepth = 0;` class property.
  - `uniqueId()` returns `...($this->followUp ? '|fu'.$this->followUpDepth : '')...` — so a depth-1 follow-up for `jane` with custom domain `tuesdae.co` is `jane|tuesdae.co|fu1`.
  - Config key `partna.cache.purge_followup_schedule` (list<int>, default `[120, 300, 900]`).
  - Config key `partna.cache.purge_followup_seconds` is **removed** — nothing may read it after this task.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`, **replace** the existing test `it('dispatches one delayed follow-up purge after the primary purge', ...)` (line ~152) with:

```php
it('dispatches one follow-up per schedule entry, up-front, at the configured offsets', function () {
    // Up-front dispatch, not a chain: a chain loses its tail if any link
    // exhausts its retries, and the +900s purge is precisely the one a degraded
    // Cloudflare window most needs. The depth discriminator in uniqueId() keeps
    // the three from coalescing, and the 30s uniqueFor lock expires long before
    // any delay elapses.
    config()->set('partna.cache.purge_followup_schedule', [120, 300, 900]);

    $job = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, 3);

    foreach ([1, 2, 3] as $depth) {
        Queue::assertPushed(CloudflareCachePurgeJob::class,
            fn (CloudflareCachePurgeJob $f) => $f->followUp === true
                && $f->followUpDepth === $depth
                && $f->handle === 'Jane'
                && $f->customDomain === 'Tuesdae.co'
                && $f->delay !== null
        );
    }
});

it('honours a shortened follow-up schedule', function () {
    config()->set('partna.cache.purge_followup_schedule', [60]);

    $job = new CloudflareCachePurgeJob('jane');

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once();
    $job->handle($purge);

    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
    Queue::assertPushed(CloudflareCachePurgeJob::class,
        fn (CloudflareCachePurgeJob $f) => $f->followUp === true && $f->followUpDepth === 1
    );
});
```

**Replace** `it('gives follow-ups their own lock namespace and a shorter lock than their delay', ...)` (line ~192) with:

```php
it('gives each follow-up depth its own lock namespace and a lock shorter than its delay', function () {
    config()->set('partna.cache.purge_followup_schedule', [120, 300, 900]);

    $first = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', followUp: true, followUpDepth: 1);
    $third = new CloudflareCachePurgeJob('Jane', 'Tuesdae.co', followUp: true, followUpDepth: 3);

    // Depth in the id: without it the three up-front follow-ups coalesce into one.
    expect($first->uniqueId())->toBe('jane|tuesdae.co|fu1')
        ->and($third->uniqueId())->toBe('jane|tuesdae.co|fu3')
        ->and($first->uniqueId())->not->toBe($third->uniqueId())
        ->and($first->uniqueFor)->toBe(30)
        ->and($first->uniqueFor)->toBeLessThan(min(config('partna.cache.purge_followup_schedule')));
});

it('defaults followUpDepth to a class-level 0 so pre-deploy payloads survive unserialization', function () {
    // A promoted readonly param has no class default: an in-flight payload
    // serialized before this change would unserialize with the property
    // uninitialized and fatal in uniqueId() on retry. Same scar as $bulk.
    $reflection = new ReflectionProperty(CloudflareCachePurgeJob::class, 'followUpDepth');

    expect($reflection->isPromoted())->toBeFalse()
        ->and($reflection->hasDefaultValue())->toBeTrue()
        ->and($reflection->getDefaultValue())->toBe(0);
});
```

**Replace** `it('follow-up purges but never chains another follow-up', ...)` (line ~172) with:

```php
it('follow-up purges but never dispatches anything itself', function () {
    // followUpDepth exists only to feed uniqueId() and logging. No job ever
    // re-dispatches — the primary owns the whole schedule.
    $job = new CloudflareCachePurgeJob('jane', null, followUp: true, followUpDepth: 2);

    $purge = Mockery::mock(CloudflarePurgeService::class);
    $purge->shouldReceive('purgeHandle')->once()->with('jane', null);
    $job->handle($purge);

    Queue::assertNothingPushed();
});
```

In `tests/Unit/Jobs/HorizonQueueCoverageTest.php`, line 592 reads:

```php
        ->and($followUp->uniqueFor)->toBeLessThan((int) config('partna.cache.purge_followup_seconds', 120));
```

Replace with:

```php
        ->and($followUp->uniqueFor)->toBeLessThan((int) min(config('partna.cache.purge_followup_schedule', [120])));
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
./vendor/bin/pest tests/Unit/Jobs/CloudflareCachePurgeJobTest.php tests/Unit/Jobs/HorizonQueueCoverageTest.php
```

Expected: FAIL — `Unknown named parameter $followUpDepth`, and the fan-out test sees 1 pushed job instead of 3.

- [ ] **Step 3: Replace the config key**

In `config/partna.php`, replace lines 1930-1934 (the `purge_followup_seconds` entry and its docblock) with:

```php
        // Absolute offsets, in seconds FROM THE PRIMARY PURGE, at which follow-up
        // purges land. Not per-hop delays: the primary dispatches all of them
        // up-front, each with its own delay and depth. Each must clear the sum of
        // the payload staleness windows (Laravel Cloud edge s-maxage + the Worker
        // subrequest cacheTtl) for a visitor who raced the primary purge; the
        // later entries exist for a degraded-Cloudflare window where the earlier
        // ones fail. Every entry MUST exceed CloudflareCachePurgeJob's follow-up
        // $uniqueFor (30) or a follow-up would coalesce into its own predecessor.
        'purge_followup_schedule' => [120, 300, 900],
```

There is no env read — the schedule is a list, and the removed `PARTNA_CACHE_PURGE_FOLLOWUP_SECONDS` env var is not set in `.env.example` (verified). Do not add one.

- [ ] **Step 4: Add the `followUpDepth` property**

In `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`, directly below the `public bool $bulk = false;` declaration and its docblock:

```php
    /**
     * Which follow-up in the schedule this is (1-based); 0 for a primary purge.
     * Feeds uniqueId() so the up-front-dispatched follow-ups don't coalesce into
     * each other, and logging. No job ever re-dispatches on the strength of it.
     *
     * Plain property with a class-level default, assigned in the constructor body
     * — NOT a promoted readonly param. A promoted property has no class default,
     * so a payload serialized before this deploy and unserialized after it would
     * leave this uninitialized and fatal in uniqueId() on retry. Same reasoning
     * as $bulk above; $followUp stays a promoted bool so in-flight payloads
     * carrying it keep working.
     */
    public int $followUpDepth = 0;
```

- [ ] **Step 5: Add the depth to `uniqueId()`**

In `uniqueId()`, change:

```php
            .($this->followUp ? '|fu' : '')
```

to:

```php
            .($this->followUp ? '|fu'.$this->followUpDepth : '')
```

- [ ] **Step 6: Accept the depth in the constructor**

Change the constructor signature's tail and body. Currently:

```php
        bool $bulk = false,
    ) {
        $this->bulk = $bulk;
```

Becomes:

```php
        bool $bulk = false,
        int $followUpDepth = 0,
    ) {
        $this->bulk = $bulk;
        $this->followUpDepth = $followUpDepth;
```

- [ ] **Step 7: Replace the single follow-up dispatch with the schedule fan-out**

In `handle()`, replace the whole `if (! $this->followUp) { ... }` block (currently one `self::dispatch(...)->delay(...)`) with:

```php
        // A visitor can hit the just-purged URL while the payload layers are
        // still inside their staleness windows (Laravel Cloud's OWN Cloudflare
        // edge honours s-maxage on api/public/profiles and sits outside our
        // zone's purge reach, + the Worker's subrequest cache) — the router
        // would then re-pin that stale render under its 24h HTML TTL. Delayed
        // follow-up purges evict any such re-pin.
        //
        // All of them are dispatched HERE, up-front, one per schedule entry —
        // not chained. A chain loses its tail if any link exhausts its retries,
        // and the last offset is precisely the one a degraded-Cloudflare window
        // most needs. uniqueId()'s depth discriminator keeps them from
        // coalescing, and the 30s follow-up lock expires long before any delay.
        if (! $this->followUp) {
            /** @var list<int> $schedule */
            $schedule = array_values((array) config('partna.cache.purge_followup_schedule', [120, 300, 900]));

            foreach ($schedule as $index => $offsetSeconds) {
                // Forward $bulk so a takedown's follow-ups also stay on the
                // cloudflare_bulk lane — dropping that would let them compete
                // with real-time purges, defeating the lane isolation.
                self::dispatch(
                    $this->handle,
                    $this->customDomain,
                    followUp: true,
                    moderationCaseId: $this->moderationCaseId,
                    bulk: $this->bulk,
                    followUpDepth: $index + 1,
                )->delay(now()->addSeconds((int) $offsetSeconds));
            }
        }
```

- [ ] **Step 8: Update the `$uniqueFor` docblock**

The `$uniqueFor` docblock says "their dispatch delay (120s)". Change that sentence's parenthetical to "(the shortest schedule offset, 120s by default)" so it no longer names a config key that has been removed.

- [ ] **Step 9: Run the tests to verify they pass**

```bash
./vendor/bin/pest tests/Unit/Jobs/CloudflareCachePurgeJobTest.php tests/Unit/Jobs/HorizonQueueCoverageTest.php
```

Expected: PASS.

- [ ] **Step 10: Verify the removed config key has no readers left**

```bash
grep -rn "purge_followup_seconds\|PARTNA_CACHE_PURGE_FOLLOWUP_SECONDS" --include="*.php" --include="*.js" --include="*.example" app config tests routes cloudflare-worker
```

Expected: **no output**. Hits under `audits/` and `docs/superpowers/specs/` are historical records and are correctly left alone — that is why they are excluded from the paths above.

- [ ] **Step 11: Format and commit**

```bash
php artisan pint app/Jobs/Cloudflare/CloudflareCachePurgeJob.php config/partna.php tests/Unit/Jobs/CloudflareCachePurgeJobTest.php tests/Unit/Jobs/HorizonQueueCoverageTest.php
git add app/Jobs/Cloudflare/CloudflareCachePurgeJob.php config/partna.php tests/Unit/Jobs/CloudflareCachePurgeJobTest.php tests/Unit/Jobs/HorizonQueueCoverageTest.php
git commit -m "feat(cache): dispatch follow-up purges on a bounded schedule

Replaces the single +120s follow-up with [120, 300, 900] absolute offsets,
all dispatched up-front by the primary so no link's retry exhaustion can
lose the tail. Depth discriminates uniqueId(). Removes the superseded
purge_followup_seconds config and its stale invariant docblock."
```

---

### Task 4: Worker preview bypass

**Files:**
- Modify: `cloudflare-worker/src/index.js:400-407`
- Test: `tests/Feature/Cache/WorkerPreviewBypassTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks at the code level. **Functionally depends on Task 2** — bypassing the edge sends the preview to Astro, which asks the API; without the floor, that answer can still be up to 30s stale. Do not ship this task without Tasks 1-2.
- Produces: the query param contract `?preview=1` on any sitepage URL (subdomain or custom domain) → origin, `no-store`, no cache read, no cache write. The frontend handoff in Task 5 depends on this exact param name.

- [ ] **Step 1: Write the failing guard test**

`cloudflare-worker/` has no JS test harness (zero test files), so the guard is a PHP test that parses the source — the same technique as `tests/Feature/Subdomain/ReservedSubdomainWorkerSyncTest.php`.

Create `tests/Feature/Cache/WorkerPreviewBypassTest.php`:

```php
<?php

/*
|--------------------------------------------------------------------------
| Worker preview-bypass guard
|--------------------------------------------------------------------------
| The dashboard live preview appends ?preview=1 and depends on serveIndividual()
| routing it straight to origin — no cache read, no cache write. cloudflare-worker/
| has no JS test harness, so this PHP test parses the source, exactly as
| ReservedSubdomainWorkerSyncTest does for the RESERVED set. Losing the param
| from the bypass would silently return the preview to a 24h-TTL edge entry and
| reintroduce the symptom this whole change set exists to remove.
*/

it('keeps preview in the serveIndividual bypass condition', function () {
    $path = base_path('cloudflare-worker/src/index.js');
    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse("Could not read {$path}");

    // Match the bypass condition itself, not just the word "preview" anywhere in
    // the file — a comment mentioning preview must not satisfy this guard.
    $matched = preg_match(
        '/if\s*\(\s*previewParams\.has\((.*?)\)\s*\)\s*\{/s',
        $contents,
        $match
    );

    expect($matched)->toBe(1,
        'Could not locate the `if (previewParams.has(...))` bypass in '
        .'cloudflare-worker/src/index.js — has serveIndividual() been restructured? '
        .'Update this guard to match.'
    );

    preg_match_all('/previewParams\.has\("([^"]+)"\)/', $contents, $params);

    expect($params[1])->toContain('preview')
        ->and($params[1])->toContain('architecture')
        ->and($params[1])->toContain('skeleton');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Cache/WorkerPreviewBypassTest.php
```

Expected: FAIL — `Failed asserting that array contains 'preview'`.

- [ ] **Step 3: Add `preview` to the bypass**

In `cloudflare-worker/src/index.js`, lines 400-406 currently read:

```js
  // Preview requests (?architecture= — current — or legacy ?skeleton=) render a
  // transient alternate architecture; never cache them, or a stale variant would
  // pin in the edge cache. Always fetch fresh. EDGE-7: still finalise so the
  // preview carries security headers.
  const previewParams = new URL(request.url).searchParams;
  if (previewParams.has("skeleton") || previewParams.has("architecture")) {
    return finalize(await env.PARTNA_PAGES.fetch(originRequest), {sitepage: true, noStore: true});
  }
```

Replace with:

```js
  // Bypass the edge entirely for preview-shaped requests: ?preview= (the
  // dashboard live preview), ?architecture= (transient alternate architecture),
  // or legacy ?skeleton=. No cache read, no cache write — cacheKeyFor() strips
  // the query string, so a cached preview would pin under the plain URL's key
  // for the full 24h TTL. Always fetch fresh. EDGE-7: still finalise so the
  // preview carries security headers.
  //
  // Known trade-off (accepted, see the 2026-07-25 cache-freshness design): any
  // of these params is a cache-busting lever for anonymous traffic. Not new —
  // ?architecture= and ?skeleton= already were — but "preview" is more
  // guessable. Cloudflare bot protection sits in front; origin rate-limiting
  // for bypass params is separate, out-of-scope work.
  const previewParams = new URL(request.url).searchParams;
  if (previewParams.has("preview") || previewParams.has("skeleton") || previewParams.has("architecture")) {
    return finalize(await env.PARTNA_PAGES.fetch(originRequest), {sitepage: true, noStore: true});
  }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Cache/WorkerPreviewBypassTest.php
```

Expected: PASS.

- [ ] **Step 5: Confirm the custom-domain path reaches the same bypass**

The preview iframe URL comes from `sitepageUrl()`, which returns the **custom domain** when one is primary and active — so `?preview=1` can land on that host. Verify the custom-domain branch routes into `serveIndividual()` rather than a separate handler:

```bash
grep -n "serveIndividual" cloudflare-worker/src/index.js
```

Expected: the custom-domain branch calls `serveIndividual(env, ctx, request, <resolved handle>)`. If it does not, stop and report — the bypass would not cover custom domains and the plan needs revisiting.

- [ ] **Step 6: Commit**

```bash
git add cloudflare-worker/src/index.js tests/Feature/Cache/WorkerPreviewBypassTest.php
git commit -m "feat(worker): bypass the edge for ?preview= sitepage requests

The dashboard live preview must never read or write the 24h edge entry —
cacheKeyFor() strips the query string, so a cached preview pins under the
plain URL's key. Guarded by a PHP source-parse test, following the
ReservedSubdomainWorkerSyncTest precedent."
```

---

### Task 5: Full-suite verification, frontend handoff, and rollout notes

**Files:**
- Create: `docs/superpowers/plans/2026-07-25-sitepage-cache-freshness-rollout.md`
- No code changes in this repo. The frontend one-liner lands in `PartnaAu/partna-frontend`, a separate repo — **do not clone it from here.**

**Interfaces:**
- Consumes: the `?preview=1` contract from Task 4.
- Produces: a rollout document Josh can execute. This task produces no runtime behaviour.

- [ ] **Step 1: Run the full test suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS. Do not pipe the output through anything — piping masks the exit code. If `AuditPipelineIntegrityTest` fails, the new test file `tests/Feature/Cache/WorkerPreviewBypassTest.php` may need wiring into a lens scope group; read the failure message, which names the lens.

- [ ] **Step 2: Run PHPStan**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: no new errors. `composer test` does **not** run PHPStan — this is a separate gate. If the baseline reports an *unmatched* ignored error, that is a correct fix invalidating a baseline entry: regenerate only the affected entry rather than the whole baseline.

- [ ] **Step 3: Write the rollout document**

Create `docs/superpowers/plans/2026-07-25-sitepage-cache-freshness-rollout.md`:

```markdown
# Sitepage cache freshness — rollout

Three deploy surfaces, three separate actions. The Laravel changes are safe on
their own; the Worker change is only *useful* once the Laravel changes are live.

## 1. Laravel (backend)

Ships with `development`. No migration, no config to set — `resolve_floor_ttl`
(600) and `purge_followup_schedule` ([120, 300, 900]) have code defaults.

Removed: `PARTNA_CACHE_PURGE_FOLLOWUP_SECONDS`. It is not set in any environment
(verified against `.env.example`), but if it has been set by hand on
`development` or `production`, it is now dead — remove it via
`cloud environment:get <env> --json --fields=environmentVariables` to confirm,
then unset.

## 2. Cloudflare Worker

Does **not** ship with the Laravel deploy. Needs its own:

```bash
cd cloudflare-worker && wrangler deploy
```

## 3. Frontend (PartnaAu/partna-frontend)

One line, applied separately in that repo — `app/(app)/account/(dashboard)/design/page.tsx:96`:

```diff
-<iframe key={bump} src={url} title="Live preview of your site" className="size-full" />
+<iframe key={bump} src={`${url}?preview=1`} title="Live preview of your site" className="size-full" />
```

The concat is safe: `sitepageUrl()` never emits a query string. When a custom
domain is primary and active it returns the custom domain — covered, because
custom domains route through the same `serveIndividual()` where the bypass lives.

The 900ms debounce and 400ms `MIN_GAP_MS` stay as they are. Once both the edge
and the API are guaranteed fresh, ~1.5s is a correct reload point.

## Verification

Change a `design_kits` column for a test handle, then poll both layers:

```bash
# origin (bypasses the edge) vs the edge, same page
curl -sS "https://<handle>.partna.au/?architecture=staple" | grep -oE "\-\-dk-border-radius:[^;]*"
curl -sS -D- -o /dev/null "https://<handle>.partna.au/" | grep -i "x-partna-cache"
```

**Success criteria:** after a save, the origin reflects the change on the *next*
request — no 30s window — and the edge reflects it within one purge cycle with no
stale re-pin. The failure signature to watch for is the one from the diagnosis:
edge EVICTED at ~+4s, then HIT again at ~+8s still serving the old value.

Also confirm `?preview=1` returns `cache-control: no-store`:

```bash
curl -sS -D- -o /dev/null "https://<handle>.partna.au/?preview=1" | grep -iE "cache-control|x-partna-cache"
```

## Known gap, accepted not fixed

The floor covers the **timestamp** variant of the resolve race. It does not cover
the `['not_found' => true]` variant — a stale-set can re-install that, and the
controller 404s before reaching the `max()`. That matters for first publish/claim
(a just-published site can 404 briefly and the edge could pin that render), not
for design edits on an existing site. The follow-up purge schedule is the rescue.
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/plans/2026-07-25-sitepage-cache-freshness-rollout.md
git commit -m "docs: rollout notes for the sitepage cache freshness change"
```

- [ ] **Step 5: Report the frontend handoff to Josh**

The frontend one-liner is in a different repo and is not yours to apply from
here. Surface it explicitly rather than leaving it in the doc: state the file,
the line, and that the Worker needs its own `wrangler deploy`.

---

## Self-review against the spec

| Spec section | Covered by |
|---|---|
| §1 floor — `handleResolveFloor()` | Task 1 Step 3 |
| §1 floor — `invalidateSitePayload()` writes it, null/0 guard | Task 1 Step 5 |
| §1 floor — monotonic only-raise | Task 1 Steps 1, 5 (test + `max()`) |
| §1 floor — post-commit invariant in the docblock | Task 1 Step 5 |
| §1 floor — controller `max()` | Task 2 Step 3 |
| §1 floor — TTL 600 from config, rationale | Task 1 Step 4 |
| §1 `not_found` variant not covered | Task 2 Step 4 docblock; rollout doc "Known gap" |
| §1 third Redis round-trip noted | Task 2 Step 3 comment |
| §1 deliberately unchanged (`CacheLockService`, `WarmPublicSiteCacheJob`, `resolve_cache_ttl`) | Global Constraints; Task 1 Step 7 guard |
| §2 Worker bypass | Task 4 Step 3 |
| §2 frontend one-liner | Task 5 Step 3 |
| §2 custom-domain coverage | Task 4 Step 5 |
| §2 accepted bypass risk | Task 4 Step 3 comment |
| §3 schedule config, absolute offsets | Task 3 Step 3 |
| §3 up-front dispatch, no chaining | Task 3 Step 7 (+ test in Step 1) |
| §3 `followUpDepth` not promoted | Task 3 Step 4 (+ reflection test) |
| §3 depth in `uniqueId()` | Task 3 Step 5 |
| §3 `uniqueFor` unchanged (30/240) | untouched; asserted in Task 3 Step 1 |
| §3 `purge_followup_seconds` removed | Task 3 Steps 3, 10 |
| Testing — race test | Task 2 Step 1 |
| Testing — floor helper, config TTL | Task 1 Step 1 |
| Testing — monotonicity | Task 1 Step 1 |
| Testing — purge job schedule + uniqueId | Task 3 Step 1 |
| Testing — Worker guard | Task 4 Step 1 |
| Rollout §1-3 + verification | Task 5 Step 3 |
