# Legacy Surface Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the superseded public-site payload lane and the smaller vestigial surfaces around it, so exactly one machine answers "what is on this person's page".

**Architecture:** The backend has two public-site lanes. The canonical one is `GET /api/public/profiles/{handle}` → `IndividualProfileController` → `IndividualProfilePayloadBuilder` → `IndividualProfileResource`. The superseded one is `GET /api/public/site` + `/api/public/site-by-slug` → `PublicSiteController` → `SiteCacheService::buildPayloadFromDb()` → the `site.public_site_payload` DB view. A four-repo consumer search (2026-09-04) proved the superseded lane has no caller. This plan removes it top-to-bottom, then clears four smaller items found in the same scan.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, Supabase/PostgreSQL (raw SQL migrations in `supabase/migrations/`), Next.js 15 (dashboard, separate repo).

**Spec:** `audits/cleanup/2026-09-04-legacy-payload-lane/CONSOLIDATED.md` (adjudicated findings #LEGACY-1, #LEGACY-2) plus the full inventory published 2026-09-04. Finding IDs below use the inventory's `LEG-n` numbering; the audit file's `#LEGACY-1`/`#LEGACY-2` map to `LEG-1` and `LEG-3` respectively.

---

## Global Constraints

- **Never create Laravel migration files.** All schema changes are raw SQL in `supabase/migrations/`, named `YYYYMMDDHHMMSS_snake_case.sql`. A Composer guard (`guard:no-laravel-migrations`) fails the build otherwise.
- **One `CONCURRENTLY` statement per migration file, maximum.** Not relevant to this plan (no index changes), but the rule stands.
- **Tests run SQLite; production runs PostgreSQL.** A green `composer test` says nothing about CHECK/NOT NULL constraints or view definitions. Verify DDL against `supabase/migrations/` and the schema lane.
- **Do not use `Cache::forever()`.** Every cache key must carry a TTL (Valkey runs `volatile-lru`; a TTL-less key is evictable job state). Guarded by `tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`.
- **Authorization goes through Policies**, never inline `abort_unless(...403)`. CI fails on inline 403 aborts.
- **API responses go through Resource classes**, never raw Eloquent.
- **Comment for WHY, not what.** 4-space indent, LF. Run `php artisan pint` before committing; the gate is `pint --test`, not `pint`.
- **Branch off `development`**, never commit to it directly. Branch name: `cleanup/legacy-payload-lane-2026-09-04`.
- **Every commit message ends with:**
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg
  ```

## Sequencing constraint — read before starting

**A different session is actively working in this worktree right now.** As of 2026-09-04 12:47 the branch is `refactor/site-media-usage-rename` with 130 uncommitted files, renaming `site.site_media.pool` → `usage` (migration `20260904235904_site_media_pool_to_usage.sql`).

Three files that refactor owns are also touched by this plan:
- `app/Services/Cache/CacheKeyGenerator.php`
- `tests/Pest.php`
- `tests/Unit/Services/Cache/CacheKeyGeneratorTest.php`

**Do not start this plan until that refactor is committed and merged.** Then branch off the updated `development`. Verify before starting:

```bash
git worktree list
git status --short | wc -l          # expect 0 (or only files unrelated to this plan)
git log --oneline -1                # expect the usage-rename merge
```

That refactor also **implements finding LEG-5** (the `pool` word naming two unrelated things). LEG-5 is therefore **not a task in this plan** — it is being fixed elsewhere. Task 7 documents the outcome rather than changing it.

## Finding coverage

| Finding | Task | Note |
|---|---|---|
| LEG-1 legacy payload lane | 1, 2, 3, 4 | Split across four tasks by reviewer gate |
| LEG-2 dead `gallery` view keys | 4 | Subsumed — the whole view is dropped |
| LEG-3 duplicate route registrations | 5 | Independent; can go first |
| LEG-4 `NotificationEmailPolicy` model | 6 | Plus the cache-lane check that matters more |
| LEG-5 `pool`/`usage` collision | — | Being fixed by the in-flight refactor |
| LEG-6 `skeleton_id` alias | 4 | Dies with the view |
| LEG-7 two things called "sections" | 7 | Documentation only |
| LEG-8 retired-surface rows | 8 | Data repoint |
| LEG-9 `site.menu_items` stand-in | 9 | **Corrected** — it is load-bearing, see task |
| LEG-10 dead half of the cache warm | 2 | Folded into the `SiteCacheService` task |

---

## File Structure

**Deleted outright (backend):**
- `app/Http/Controllers/Api/PublicSite/PublicSiteController.php` — the superseded controller
- `app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php` — its only request class
- `app/Models/Views/PublicSitePayload.php` — Eloquent model over the dropped view
- `app/Services/Streaming/LiveStatusInjector.php` — orphaned; only `PublicSiteController` consumed it
- `tests/Feature/PublicSite/PublicSiteControllerShowTest.php`
- `tests/Feature/PublicSite/PublicSiteControllerShowByHeaderTest.php`
- `tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php`
- `tests/Unit/Streaming/LiveStatusInjectorTest.php`

**Modified (backend):**
- `app/Services/Cache/SiteCacheService.php` — remove `getPublicSitePayload`, `buildPayloadFromDb`, `warmSiteCache`, `writePayloadWithStale`, `safeHydrateSitePayload`, `resolveImageVariantUrlsInSite`, `ensureBlockCollections`, `buildCombinedBlocksPayload`
- `app/Services/Cache/CacheKeyGenerator.php` — remove `publicSite()` (line 30) and `publicSitePayload()` (line 35)
- `app/Jobs/Cache/WarmPublicSiteCacheJob.php` — drop the legacy half of the dual-warm (LEG-10)
- `app/Listeners/RecordCacheMetrics.php` — drop the legacy key from metric routing
- `app/Services/Cache/CacheLockService.php` — drop the legacy docblock reference
- `app/Services/Cloudflare/CloudflarePurgeService.php` — drop the `PublicSiteController` reference
- `app/Models/Core/User/User.php:197` — stale comment citing the view
- `routes/api.php` — remove the `site-by-slug` route (and the LEG-3 duplicates)
- `routes/api/publicSite.php` — remove the whole domain group
- `docs/api.md` — remove the retired endpoints, add the vocabulary table

**Created:**
- `supabase/migrations/20260905000000_drop_public_site_payload_view.sql`
- `supabase/migrations/20260905000100_repoint_retired_surfaces.sql`

**Frontend: nothing required.** `PartnaAu/partna-monorepo` — the live frontend, all three apps — has **no `/api/public/site` route at all** (`apps/dashboard/app/api/` contains no `public/` directory). The only copy of that proxy lives in `PartnaAu/partna-frontend`, the **superseded** dashboard: last commit 2026-07-28, five weeks stale, and its own final commit is a feature "gated off pending a backend endpoint". Even there the proxy has zero callers. Deleting it is optional hygiene in a dead repo, not a coordinated change — see Task 10.

---

### Task 1: Remove the routes and the controller

**Files:**
- Modify: `routes/api.php:113`
- Modify: `routes/api/publicSite.php:25-27`
- Delete: `app/Http/Controllers/Api/PublicSite/PublicSiteController.php`
- Delete: `app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php`
- Delete: `tests/Feature/PublicSite/PublicSiteControllerShowTest.php`
- Delete: `tests/Feature/PublicSite/PublicSiteControllerShowByHeaderTest.php`
- Modify: `tests/Feature/Validation/RequestValidationTest.php`
- Create: `tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (this is the first).
- Produces: the routes `/api/public/site` and `/api/public/site-by-slug` no longer exist. Task 2 relies on `PublicSiteController` being gone so `SiteCacheService`'s public methods have no caller.

- [ ] **Step 1: Write the failing retirement guard**

This mirrors the existing precedent at `tests/Feature/PublicSite/PublicMenuRouteRetiredTest.php` — the repo's convention is to pin a retirement with a 404 assertion so the route cannot silently return.

Create `tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php`:

```php
<?php

// The superseded public-site payload lane was removed 2026-09-04 after a
// four-repo consumer search found no caller: the live sitepage app reads
// /public/profiles/{handle}, the mobile app reads no public surface, and the
// only reference to /public/site-by-slug anywhere was a dashboard proxy route
// with zero callers of its own. These assertions stop it growing back.

use Illuminate\Support\Facades\Route;

it('no longer registers the legacy public-site payload routes', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($uris)->not->toContain('api/public/site');
    expect($uris)->not->toContain('api/public/site-by-slug');
});

it('404s the retired by-slug endpoint', function () {
    $this->getJson('/api/public/site-by-slug', ['X-Site-Subdomain' => 'anything'])
        ->assertNotFound();
});

it('has no PublicSiteController class left to route to', function () {
    expect(class_exists(\App\Http\Controllers\Api\PublicSite\PublicSiteController::class))
        ->toBeFalse();
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `./vendor/bin/pest tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php`

Expected: FAIL — all three assertions fail because the routes and the class still exist.

- [ ] **Step 3: Remove the flat by-slug route**

In `routes/api.php`, delete the `site-by-slug` registration at line 113 and its `use` import of `PublicSiteController` at the top of the file. Leave a comment in its place, matching the repo's convention for retired routes:

```php
// The legacy public-site payload lane (`/public/site`, `/public/site-by-slug`)
// was REMOVED 2026-09-04. `GET /api/public/profiles/{handle}` is the only
// public-site payload endpoint. Guard:
// tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php
```

- [ ] **Step 4: Remove the domain-scoped group**

In `routes/api/publicSite.php`, delete the `Route::get('/site', ...)` registration and its `PublicSiteController` import. Leave the file's remaining routes alone — the three duplicate POSTs are Task 5, deliberately separated so a reviewer can reject one without the other.

- [ ] **Step 5: Delete the controller and its request class**

```bash
git rm app/Http/Controllers/Api/PublicSite/PublicSiteController.php
git rm app/Http/Requests/Api/PublicSite/PublicSiteShowRequest.php
git rm tests/Feature/PublicSite/PublicSiteControllerShowTest.php
git rm tests/Feature/PublicSite/PublicSiteControllerShowByHeaderTest.php
```

- [ ] **Step 6: Drop the request class from the validation sweep**

`tests/Feature/Validation/RequestValidationTest.php` enumerates Form Request classes. Remove the `PublicSiteShowRequest` entry. Find it with:

```bash
grep -n "PublicSiteShowRequest" tests/Feature/Validation/RequestValidationTest.php
```

- [ ] **Step 7: Run the guard and the validation sweep**

Run: `./vendor/bin/pest tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php tests/Feature/Validation/RequestValidationTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
php artisan pint
git add -A
git commit -m "Retire the public-site payload routes and their controller

A four-repo consumer search on 2026-09-04 found no caller for
/api/public/site or /api/public/site-by-slug. The live sitepage app
(partna-monorepo/apps/pages) reads /public/profiles/{handle}; the mobile
app reads no public-site surface; the only reference to site-by-slug
anywhere was a dashboard proxy route with zero internal callers.

/api/public/site was additionally unreachable in production regardless:
it was domain-scoped to {subdomain}.partna.au, a zone the Worker claims
wholesale and forwards to the partna-pages service without ever calling
Laravel.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 2: Strip the legacy payload builder out of SiteCacheService

**Files:**
- Modify: `app/Services/Cache/SiteCacheService.php`
- Modify: `app/Jobs/Cache/WarmPublicSiteCacheJob.php`
- Modify: `app/Services/Cache/CacheKeyGenerator.php`
- Modify: `app/Listeners/RecordCacheMetrics.php`
- Modify: `app/Services/Cache/CacheLockService.php`
- Modify: `tests/Feature/Cache/WarmPublicSiteCacheJobTest.php`
- Modify: `tests/Unit/Services/Cache/SwrStaleFallbackTest.php`
- Modify: `tests/Feature/Cache/SiteCacheServiceLockTimeoutTest.php`
- Modify: `tests/Feature/Cache/SwrDeferredRecomputeTest.php`
- Modify: `tests/Feature/Cache/RecordCacheMetricsTest.php`
- Modify: `tests/Unit/Services/Cache/CacheKeyGeneratorTest.php`

**Interfaces:**
- Consumes: `PublicSiteController` is deleted (Task 1), so `SiteCacheService::getPublicSitePayload()` has no caller.
- Produces: `SiteCacheService` retains only its invalidation surface — `invalidateSite(string $subdomain): void` and the individual-profile cache helpers. `CacheKeyGenerator::publicSite()` and `::publicSitePayload()` no longer exist. `WarmPublicSiteCacheJob::handle(SiteCacheService, CacheLockService, IndividualProfilePayloadBuilder)` keeps its signature but performs only the §28.8 warm, keyed by `CacheKeyGenerator::publicProfile($handleLc, $ts)`.

- [ ] **Step 1: Establish exactly what is dead before deleting**

The methods form a closed cluster. Confirm no survivor calls into them:

```bash
grep -rn "getPublicSitePayload\|buildPayloadFromDb\|warmSiteCache\|writePayloadWithStale\|safeHydrateSitePayload\|resolveImageVariantUrlsInSite\|ensureBlockCollections\|buildCombinedBlocksPayload" app/
```

Expected after Task 1: matches only inside `SiteCacheService.php` itself, plus `WarmPublicSiteCacheJob.php` (`warmSiteCache`), `RecordCacheMetrics.php` and `CacheLockService.php` (docblock mentions).

Anything else is a real caller — stop and reassess rather than deleting.

- [ ] **Step 2: Write the failing test for the warm job**

`WarmPublicSiteCacheJob::handle()` takes three injected services and calls `$siteCache->warmSiteCache($subdomain)` on line 63 before doing the §28.8 warm. Its own note (lines 20-25) says the legacy key it populates is never read. Assert only the canonical warm survives.

The existing test at line 9 is `it('calls warmSiteCache with a lowercased subdomain', ...)` and mocks the three services. **Replace that test** (the behaviour it asserts is what this task removes) with:

```php
it('no longer calls the retired warmSiteCache', function () {
    expect(method_exists(SiteCacheService::class, 'warmSiteCache'))->toBeFalse();
    expect(method_exists(CacheKeyGenerator::class, 'publicSite'))->toBeFalse();
    expect(method_exists(CacheKeyGenerator::class, 'publicSitePayload'))->toBeFalse();
});

it('still warms the §28.8 individual-profile key', function () {
    $cacheLock = Mockery::mock(CacheLockService::class);
    $builder = Mockery::mock(IndividualProfilePayloadBuilder::class);

    // handle() resolves the user by handle_lc before warming; with no matching
    // row it returns without touching the builder, which is the assertion:
    // the job survives a missing site instead of throwing.
    $builder->shouldNotReceive('build');

    $job = new WarmPublicSiteCacheJob('My-Site');
    $job->handle(app(SiteCacheService::class), $cacheLock, $builder);
})->throwsNoExceptions();
```

Keep the file's other five tests (queue name, tries, backoff, timeout, `Queue::fake` dispatch) exactly as they are — none of them touch the legacy warm.

**Note the real names**, which differ from what a reader might assume: the key generators are `publicSite()` and `publicSitePayload()` (not `sitePayload()`), and the canonical key is `CacheKeyGenerator::publicProfile($handleLc, $ts)` — there is no `individualProfile()` method.

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Cache/WarmPublicSiteCacheJobTest.php`

Expected: FAIL — `publicSite()` still exists.

- [ ] **Step 4: Delete the payload cluster from SiteCacheService**

Remove these methods and any private helper they alone use: `getPublicSitePayload`, `buildPayloadFromDb`, `warmSiteCache`, `writePayloadWithStale`, `safeHydrateSitePayload`, `resolveImageVariantUrlsInSite`, `ensureBlockCollections`, `buildCombinedBlocksPayload`, and the `MISS_SENTINEL` / `MISS_PRIMARY_TTL_SECONDS` / `PAYLOAD_STALE_TTL_MULTIPLIER` constants if nothing else references them.

Remove the `use App\Models\Views\PublicSitePayload;` import.

**Keep `invalidateSite()`** — it busts the individual-profile keys too. Remove only the legacy key from the list of keys it busts.

- [ ] **Step 5: Drop the two key generators**

In `app/Services/Cache/CacheKeyGenerator.php`, delete `publicSite()` (line 30, returns `site:public:<subdomain>`) and `publicSitePayload()` (line 35, returns `site:payload:<subdomain>`). Remove their cases from `tests/Unit/Services/Cache/CacheKeyGeneratorTest.php`.

Leave every other generator alone — `publicProfile()`, `handleResolve()` and `professionalPayloadBy*()` all serve the canonical lane.

- [ ] **Step 6: Drop the legacy half of the warm**

In `app/Jobs/Cache/WarmPublicSiteCacheJob.php`, remove the `$siteCache->warmSiteCache($subdomain)` call and rewrite the class comment. The "Audit #12" note explaining why both keys are warmed becomes the explanation for why only one is:

```php
// V2: Pre-warms the public sitepage cache after publish events, so the first
// visitor does not pay a cold build.
//
// Warms the IndividualProfileController key only. The legacy
// SiteCacheService::warmSiteCache key was removed 2026-09-04 with the rest of
// the payload lane — Audit #12 had already recorded that visitors of
// `<handle>.partna.au` never read it.
```

- [ ] **Step 7: Clean the two docblock references**

`app/Listeners/RecordCacheMetrics.php:29` and `app/Services/Cache/CacheLockService.php:23` cite `SiteCacheService::getPublicSitePayload()` in comments describing cache-key ordering. Update both to cite the individual-profile path instead. In `RecordCacheMetrics`, also remove any `match`/`str_starts_with` arm routing `site:public:` or `site:payload:` prefixes to a metric bucket.

- [ ] **Step 8: Repair the four mixed-concern cache tests**

Each of these exercises both lanes. Remove only the legacy-lane cases, keeping the individual-profile ones:

```bash
./vendor/bin/pest tests/Feature/Cache/ tests/Unit/Services/Cache/
```

Work through the failures one file at a time: `SwrStaleFallbackTest.php`, `SiteCacheServiceLockTimeoutTest.php`, `SwrDeferredRecomputeTest.php`, `RecordCacheMetricsTest.php`. A test whose entire subject was the legacy payload gets deleted; a test covering SWR or lock behaviour generically gets repointed at the individual-profile key.

- [ ] **Step 9: Run the cache suites**

Run: `./vendor/bin/pest tests/Feature/Cache/ tests/Unit/Services/Cache/ tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
php artisan pint
git add -A
git commit -m "Strip the legacy payload builder out of SiteCacheService

Removes getPublicSitePayload, buildPayloadFromDb, warmSiteCache and the
five private helpers they alone used, plus the site:public:* and
site:payload:* key generators. Task 1 deleted their only caller.

Also drops the dead half of WarmPublicSiteCacheJob's dual-warm. The job's
own Audit #12 note already recorded that the legacy key it populated is
never read by visitors; it warmed both defensively rather than removing
one. Now it warms the key that is actually read.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 3: Delete the view model and the orphaned live-status injector

**Files:**
- Delete: `app/Models/Views/PublicSitePayload.php`
- Delete: `app/Services/Streaming/LiveStatusInjector.php`
- Delete: `tests/Unit/Streaming/LiveStatusInjectorTest.php`
- Delete: `tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php`
- Modify: `tests/TestCase.php`
- Modify: `tests/Support/Architecture/RedisConnectionPinningScanner.php`
- Modify: `tests/Pest.php`
- Modify: `app/Models/Core/User/User.php:197`
- Modify: `app/Services/Cloudflare/CloudflarePurgeService.php`

**Interfaces:**
- Consumes: `SiteCacheService` no longer imports `PublicSitePayload` (Task 2); `PublicSiteController` is gone (Task 1).
- Produces: `App\Models\Views` contains only `AllSiteData`. No class references `site.public_site_payload`, which is what makes Task 4's migration safe.

**Context the implementer needs:** `LiveStatusInjector` injected Twitch/Kick live badges into the legacy payload. It has exactly one production consumer — `PublicSiteController` — and the canonical lane never called it. Deleting it removes a feature that only ever reached the dead lane. The `live_check_enabled` column and `CheckStreamingLiveStatusJob` are **separate and stay**: they serve `site.blocks` and the dashboard.

- [ ] **Step 1: Confirm the injector is genuinely orphaned**

```bash
grep -rn "LiveStatusInjector\|injectIntoPayload" app/
```

Expected: matches only inside `app/Services/Streaming/LiveStatusInjector.php`. If `IndividualProfilePayloadBuilder` appears, stop — the canonical lane took a dependency and this deletion is wrong.

- [ ] **Step 2: Write the failing guard**

Append to `tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php`:

```php
it('leaves no class behind that reads the dropped payload view', function () {
    expect(class_exists(\App\Models\Views\PublicSitePayload::class))->toBeFalse();
    expect(class_exists(\App\Services\Streaming\LiveStatusInjector::class))->toBeFalse();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PublicSite/LegacyPayloadRouteRetiredTest.php`

Expected: FAIL — both classes still exist.

- [ ] **Step 4: Delete the four files**

```bash
git rm app/Models/Views/PublicSitePayload.php
git rm app/Services/Streaming/LiveStatusInjector.php
git rm tests/Unit/Streaming/LiveStatusInjectorTest.php
git rm tests/Feature/Api/PublicSiteStreamingLiveStatusTest.php
```

- [ ] **Step 5: Remove the test-harness wiring**

`tests/TestCase.php` binds or fakes `LiveStatusInjector`; `tests/Support/Architecture/RedisConnectionPinningScanner.php` lists it as a scanned class; `tests/Pest.php` has a `setupPublicSitePayloadTable()` helper (around line 1474). Remove all three. Find them with:

```bash
grep -n "LiveStatusInjector" tests/TestCase.php tests/Support/Architecture/RedisConnectionPinningScanner.php
grep -n "setupPublicSitePayloadTable" tests/
```

Delete the helper **and** every call site the second grep reports.

- [ ] **Step 6: Fix the two stale comments**

`app/Models/Core/User/User.php:197` and `app/Services/Cloudflare/CloudflarePurgeService.php` both cite the removed lane in prose. Rewrite each to describe the current state — the individual-profile endpoint — rather than deleting the comment, since both explain a non-obvious behaviour.

- [ ] **Step 7: Run the full default suite**

Run: `composer test`

Expected: PASS. This is the first point where the whole lane is gone, so this run is the real gate. Fix any straggler that references a deleted symbol.

- [ ] **Step 8: Commit**

```bash
php artisan pint
git add -A
git commit -m "Delete the payload view model and the orphaned live-status injector

PublicSitePayload was the Eloquent model over site.public_site_payload;
nothing reads that view after Task 2.

LiveStatusInjector had exactly one consumer, PublicSiteController, and the
canonical IndividualProfileController lane never called it — so the live
badge it injected only ever reached the lane no client reads. The
live_check_enabled column and CheckStreamingLiveStatusJob are unaffected:
they serve site.blocks and the dashboard.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 4: Drop the `site.public_site_payload` view

**Files:**
- Create: `supabase/migrations/20260905000000_drop_public_site_payload_view.sql`

**Interfaces:**
- Consumes: no PHP class references the view (Task 3).
- Produces: `site.public_site_payload` no longer exists on dev. `site.all_site_data` is untouched and still live.

**Context the implementer needs:** This view is what LEG-2 and LEG-6 were about. It emitted `gallery` and `gallery_videos` keys filtered on a `site_media` usage retired 2026-09-02 (so permanently empty), and it aliased `architecture_id` as `skeleton_id`. Dropping the view resolves both without a separate change. **`site.all_site_data` is a different view and stays** — `StaffSiteController` reads it.

⚠️ **Ordering:** the in-flight `site_media.pool` → `usage` rename must land first. That migration's own comment notes Postgres rewrites this view's definition in place; if this plan drops the view first, that is fine too, but the two must not be applied out of the order they were authored in. Check `supabase_migrations.schema_migrations` before pushing.

- [ ] **Step 1: Confirm nothing in the repo still references the view**

```bash
grep -rn "public_site_payload" app/ tests/ routes/
```

Expected: zero matches. Matches inside `supabase/migrations/` are historical and expected — leave them.

- [ ] **Step 2: Write the migration**

Create `supabase/migrations/20260905000000_drop_public_site_payload_view.sql`:

```sql
-- Drop site.public_site_payload.
--
-- WHY: the view backed GET /api/public/site and /api/public/site-by-slug via
-- SiteCacheService::buildPayloadFromDb(). A four-repo consumer search on
-- 2026-09-04 found no caller for either route, and both were removed from the
-- backend in the same branch. The view now has no reader.
--
-- It also carried two pieces of drift worth recording, because both die here:
--   * `gallery` and `gallery_videos` keys filtered on site_media usage
--     'gallery', retired 2026-09-02. Both had returned '[]' since, while still
--     costing two correlated subqueries per cache miss.
--   * `skeleton_id` aliased site.sites.architecture_id — a third name for a
--     column the canonical wire calls architectureId.
--
-- NOT DROPPED: site.all_site_data is a different view, still read by
-- StaffSiteController through App\Models\Views\AllSiteData. Leave it alone.
--
-- SAFE: dropping a view is catalog-only. No table, column or row is touched.
-- Nothing else in the database depends on it — verified with pg_depend below
-- at authoring time.
--
-- ROLLBACK: recreate from the definition in
-- supabase/migrations/20260817000000_public_site_payload_services_from_content.sql,
-- which is the last version that shipped.

BEGIN;

DROP VIEW IF EXISTS "site"."public_site_payload";

COMMIT;
```

- [ ] **Step 3: Verify no database object depends on the view**

Run against dev before pushing:

```sql
SELECT DISTINCT dependent.relname AS depends_on_the_view
FROM pg_depend d
JOIN pg_rewrite r    ON r.oid = d.objid
JOIN pg_class dependent ON dependent.oid = r.ev_class
JOIN pg_class source ON source.oid = d.refobjid
JOIN pg_namespace n  ON n.oid = source.relnamespace
WHERE n.nspname = 'site'
  AND source.relname = 'public_site_payload'
  AND dependent.relname <> 'public_site_payload';
```

Expected: zero rows. Any row means another view builds on it — stop and reassess.

- [ ] **Step 4: Dry-run then apply to dev**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

- [ ] **Step 5: Verify the view is gone and its neighbour survived**

```sql
SELECT to_regclass('site.public_site_payload') AS should_be_null,
       to_regclass('site.all_site_data')       AS should_be_present;
```

Expected: `should_be_null` is NULL, `should_be_present` is not.

- [ ] **Step 6: Run the schema lane**

Run: `composer test:schema`

Expected: PASS. This lane does not run under `composer test`, so it must be run explicitly.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260905000000_drop_public_site_payload_view.sql
git commit -m "Drop the site.public_site_payload view

Its only reader was the payload lane removed earlier in this branch. The
view also emitted two keys filtered on the 'gallery' media usage retired
2026-09-02 — permanently empty, two correlated subqueries per cache miss —
and aliased architecture_id as skeleton_id, a third name for a column the
canonical wire calls architectureId. All of it dies with the view.

site.all_site_data is untouched: StaffSiteController still reads it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 5: Remove the duplicate domain-scoped routes (LEG-3)

**Files:**
- Modify: `routes/api/publicSite.php`
- Create: `tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php`

**Interfaces:**
- Consumes: Task 1 already removed `/site` from this group.
- Produces: `routes/api/publicSite.php` contains only the flat `/v1/public/report` group. The flat siblings in `routes/api.php` are the sole registrations for customers, enquiry and subscribe.

**Context the implementer needs:** `POST /public/customers`, `/public/enquiry` and `/public/subscribe` were each registered twice — once flat in `routes/api.php` (lines 232, 235, 165) and once domain-scoped here. The middleware stacks are **byte-for-byte identical**, so there is no behaviour change and no security fix; this is purely removing a second registration that a future middleware tightening could miss. The file's own comment already records the group as unreachable in production (the Worker forwards every `*.partna.au` request to the pages app — measured 2026-08-05, SIGNUP-7).

- [ ] **Step 1: Record the middleware equivalence before deleting**

```bash
grep -n -A1 "public/customers\|public/enquiry\|public/subscribe" routes/api.php
grep -n -A1 "'/customers'\|'/enquiry'\|'/subscribe'" routes/api/publicSite.php
```

Confirm the middleware arrays match. If they differ, the flat sibling must be brought up to the stricter of the two **before** the domain copy is deleted — otherwise this task silently loosens a public endpoint.

- [ ] **Step 2: Write the failing guard**

Create `tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php`:

```php
<?php

// The {subdomain}.partna.au domain group was removed 2026-09-04. It duplicated
// three flat routes with byte-identical middleware, and was unreachable in
// production regardless: the Worker claims */* on the partna.au zone and
// forwards to the pages app without calling Laravel (measured 2026-08-05,
// SIGNUP-7). A second registration a future middleware change could miss is a
// hazard with no upside.

use Illuminate\Support\Facades\Route;

it('registers each public lead route exactly once', function () {
    $counts = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array($r->uri(), [
            'api/public/customers',
            'api/public/enquiry',
            'api/public/subscribe',
        ], true))
        ->countBy(fn ($r) => $r->uri());

    expect($counts['api/public/customers'])->toBe(1);
    expect($counts['api/public/enquiry'])->toBe(1);
    expect($counts['api/public/subscribe'])->toBe(1);
});

it('registers no domain-scoped routes at all', function () {
    $domained = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->getDomain() !== null)
        ->map(fn ($r) => $r->uri())
        ->all();

    expect($domained)->toBeEmpty();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php`

Expected: FAIL — each count is 2, and the domain list is non-empty.

- [ ] **Step 4: Delete the domain group**

In `routes/api/publicSite.php`, remove the entire `Route::group([...'domain' => ...])` block and the now-unused `$publicDomain` variable and imports. Keep the `/v1/public/report` group below it. Replace the deleted block with:

```php
// The {subdomain}.{public_domain} group was REMOVED 2026-09-04. Its four
// routes each had a flat sibling in routes/api.php with identical middleware,
// and the group was unreachable in production: the Worker claims */* on the
// partna.au zone and forwards to the pages app (measured 2026-08-05, SIGNUP-7).
// Guard: tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php
```

- [ ] **Step 5: Run the guard plus the three endpoints' own suites**

```bash
./vendor/bin/pest tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php
./vendor/bin/pest --filter="Enquiry|CustomerLead|Subscription"
```

Expected: PASS. The flat routes keep their existing coverage.

- [ ] **Step 6: Commit**

```bash
php artisan pint
git add -A
git commit -m "Remove the duplicate domain-scoped public route group

POST /public/customers, /public/enquiry and /public/subscribe were each
registered twice — flat in routes/api.php and again in a
{subdomain}.partna.au domain group — with byte-identical middleware.

No behaviour change: the domain group was unreachable in production, since
the Worker claims */* on the partna.au zone and forwards to the pages app.
The hazard was the second registration, which a future middleware
tightening applied to one file would silently miss.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 6: Retire the `NotificationEmailPolicy` model and check its cache lane (LEG-4)

**Files:**
- Delete: `app/Models/Core/Notifications/NotificationEmailPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php:35,274`
- Modify: `app/Policies/NotificationPolicy.php:18`
- Modify: `tests/Unit/Policies/NotificationPolicyTest.php`
- Modify: `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: no `NotificationEmailPolicy` Eloquent class. The `notifications.notification_email_policies` **table stays** and both query-builder call sites keep working.

**Context the implementer needs:** The model's only reference outside its own file is the `Gate::policy()` registration that exists to satisfy `PolicyCoverageTest`. Both real code paths use the query builder: `StaffNotificationEmailPolicyController` writes with raw `INSERT`, `NotificationEmailPreferenceController` reads with `DB::table()`. **The second half of this task matters more than the first**: those raw writes bypass Eloquent, so no observer fires, and per the repo's cache contract a write path that skips the model layer must invalidate affected cache keys explicitly.

- [ ] **Step 1: Check whether the raw writes bust their cache keys**

```bash
grep -n "Cache::\|SiteCacheLanes\|BuildState\|forget\|invalidate" \
  app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php
```

If there is no invalidation, determine what caches a user's notification-email preferences:

```bash
grep -rn "notification_email\|notificationEmail" app/Services/Cache/ app/Services/Notifications/
```

**If a cache key exists and is not busted, that is a live staleness bug** — fix it in this task and say so in the commit. If nothing caches these rows, record that in the commit message so the next reader does not re-derive it.

- [ ] **Step 2: Write the failing guard**

Create `tests/Feature/Notifications/NotificationEmailPolicyModelRetiredTest.php`:

```php
<?php

// The NotificationEmailPolicy Eloquent model was removed 2026-09-04: its only
// reference outside its own file was the Gate::policy() registration that
// satisfied PolicyCoverageTest. Both real call sites use the query builder.
// The TABLE is live and must stay.

use Illuminate\Support\Facades\DB;

it('has no NotificationEmailPolicy model', function () {
    expect(class_exists(\App\Models\Core\Notifications\NotificationEmailPolicy::class))
        ->toBeFalse();
});

it('still has a working notification_email_policies table', function () {
    expect(fn () => DB::connection('pgsql')
        ->table('notifications.notification_email_policies')
        ->count())->not->toThrow(Throwable::class);
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Notifications/NotificationEmailPolicyModelRetiredTest.php`

Expected: FAIL on the first assertion.

- [ ] **Step 4: Delete the model and its Gate registration**

```bash
git rm app/Models/Core/Notifications/NotificationEmailPolicy.php
```

In `app/Providers/AppServiceProvider.php`, remove the `use` at line 35 and the `Gate::policy(...)` at line 274. In `app/Policies/NotificationPolicy.php:18`, remove `NotificationEmailPolicy` from the docblock's list of covered models.

- [ ] **Step 5: Repair the policy tests**

`tests/Unit/Policies/NotificationPolicyTest.php` instantiates the model. Remove those cases — the other three models the policy covers keep theirs. Leave `StaffNotificationEmailPolicyControllerAuthTest.php` and `StaffNotificationOnBehalfTest.php` alone: they exercise the controller, which is unchanged.

- [ ] **Step 6: Run the notification suites and the policy-coverage guard**

```bash
./vendor/bin/pest tests/Feature/Notifications/ tests/Unit/Policies/ \
  tests/Feature/Architecture/PolicyCoverageTest.php \
  tests/Feature/Staff/StaffNotificationOnBehalfTest.php
```

Expected: PASS. If `PolicyCoverageTest` fails claiming a model lacks a policy, the model was not fully removed.

- [ ] **Step 7: Commit**

```bash
php artisan pint
git add -A
git commit -m "Retire the NotificationEmailPolicy model, keep its table

The model's only reference outside its own file was the Gate::policy()
registration satisfying PolicyCoverageTest. Both real call sites use the
query builder: StaffNotificationEmailPolicyController writes raw INSERTs,
NotificationEmailPreferenceController reads via DB::table(). The table is
live and untouched.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 7: Document the surviving vocabulary (LEG-7, and LEG-5's outcome)

**Files:**
- Modify: `docs/api.md`

**Interfaces:**
- Consumes: Task 4 removed `skeleton_id` from the wire; the in-flight refactor renamed `site_media.pool` → `usage`.
- Produces: one table in `docs/api.md` that a frontend engineer can read instead of inferring from three payloads.

**Context the implementer needs:** This is the task that actually addresses the reported frontend confusion, and it is documentation only — no code changes. Two collisions survive by design and must be explained rather than fixed:

1. **`media` pool vs `gallery` page.** `PoolRegistry::PAGE_KEYS` maps `'media' => 'gallery'` deliberately. The pool is `media`, the page it renders on is `gallery`, the upload usage feeding it is `content`, and the setting hiding it is `display_gallery_page`. All four are correct in their own layer.
2. **Two things called "sections".** `site.blocks` with `block_group = 'sections'` (~3,053 rows) is the per-site **visibility toggle** store, keyed by block type, read by `SitepageDataResolverService::loadSections()`. `site.sections` (~2,727 rows) paired with `site.pages` is the rule-driven **rendering** system. Different jobs, same word, both written daily.

- [ ] **Step 1: Verify the current pool list before writing it down**

```bash
grep -n "POOLS\|PAGE_KEYS" app/Site/Pools/PoolRegistry.php | head -20
```

Copy the real values. Do not transcribe from this plan — `PoolRegistry::POOLS` is the source of truth and may have moved.

- [ ] **Step 2: Add the vocabulary section to `docs/api.md`**

Add under the public-profile endpoint's documentation:

```markdown
### Vocabulary: pool vs page vs usage

Three layers use overlapping words. This table is the mapping; nothing here
is a bug.

| Content pool (`pools.<key>`) | Page it renders on (`site.pages.key`) | Notes |
|---|---|---|
| `media` | `gallery` | The one that catches people out. Hidden by `settings.display_gallery_page`. |
| `custom_links` | `links` | Joins the page its own connections built. |
| `menus` | `menu` | Joins the legacy menu lane's page. |
| `services` · `shop` · `events` · `reviews` · `watch` · `listen` | same name | 1:1. |

**A pool is a section of the public page.** There are nine, listed by
`PoolRegistry::POOLS`, and they are the whole public wire at
`data.profile.pools.*`.

**A usage is what an uploaded file is for** — `site.site_media.usage`, one of
`content` (owner photos), `design` (logo/brand, never published as a card) or
`documents` (downloadable PDFs). This column was called `pool` until
2026-09-04; the rename exists because the two words named unrelated things and
collided worst on the value `content`.

`site.site_media` is not a rival to the media pool — it is the upload
substrate beneath it. `content.media_assets` is the curation layer over both
uploads and ingested items, bridged by `content.media_assets.site_media_id`.

### Two things called "sections"

| Store | What it is | Read by |
|---|---|---|
| `site.blocks` where `block_group = 'sections'` | Per-site visibility toggles, keyed by block type | `SitepageDataResolverService::loadSections()` |
| `site.sections` + `site.pages` | Rule-driven page/section rendering | `PoolResolver`, the page composer |

Both are live and written daily. A toggle is not a section row.

### Retired public endpoints

`GET /api/public/site` and `GET /api/public/site-by-slug` were **removed
2026-09-04**. `GET /api/public/profiles/{handle}` is the only public-site
payload endpoint. The removed lane emitted `skeleton_id` (an alias of
`architecture_id`, on the wire as `architectureId`) and `gallery` /
`gallery_videos` keys that had been permanently empty since the `gallery`
media usage was retired 2026-09-02.
```

- [ ] **Step 3: Verify the doc's claims against the code**

```bash
grep -c "'media' => 'gallery'" app/Site/Pools/PoolRegistry.php     # expect 1
grep -c "display_gallery_page" app/Services/PublicSite/IndividualProfilePayloadBuilder.php   # expect >=1
```

- [ ] **Step 4: Commit**

```bash
git add docs/api.md
git commit -m "Document the pool / page / usage vocabulary

Three layers use overlapping words and a frontend engineer reads all three
in one payload: the pool is 'media', the page is 'gallery', the upload
usage is 'content', and the setting is display_gallery_page. Each is right
in its own layer. Same for the two stores both called 'sections'.

Documents the removal of the legacy public-site endpoints in the same pass.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 8: Repoint the retired-surface connection rows (LEG-8)

**Files:**
- Create: `supabase/migrations/20260905000100_repoint_retired_surfaces.sql`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: zero live `site.platform_connections` rows on a surface listed in `IntegrationConnection::RETIRED_SURFACES`.

**Context the implementer needs:** `IntegrationConnection::RETIRED_SURFACES` lists six surfaces that can no longer be connected, but the guard fires **on create only** — deliberately, so a retirement migration could repoint existing rows. As of 2026-09-04 dev still holds 8 such rows: `partna.custom_link` (4), `partna.storefront` (3), `partna.order_link` (1). The repoint appears never to have run. `partna.manual_product` (12 rows) is correctly **not** retired and must not be touched.

⚠️ **This task writes user-visible data.** Per the repo's blocker gate, get owner sign-off on the disposition before running it. The two options are repoint-to-real-brand-surface or soft-delete; which is right depends on whether those 8 rows represent links the owners still want.

- [ ] **Step 1: Re-measure — the 2026-09-04 figures are stale the moment anything writes**

```sql
SELECT surface_key, platform, COUNT(*) AS n, MAX(updated_at) AS newest
FROM site.platform_connections
WHERE surface_key IN (
  'partna.custom_link','partna.order_link','partna.storefront',
  'partna.reserve_link','partna.booking_link','partna.manual_event'
)
  AND deleted_at IS NULL
GROUP BY 1,2 ORDER BY 3 DESC;
```

- [ ] **Step 2: Inspect what the rows actually hold before deciding**

```sql
SELECT id, user_id, surface_key, platform, profile_url, created_at
FROM site.platform_connections
WHERE surface_key IN ('partna.custom_link','partna.order_link','partna.storefront')
  AND deleted_at IS NULL
ORDER BY surface_key, created_at;
```

- [ ] **Step 3: Get the disposition decision**

Present the rows and ask: repoint each to its real brand surface via `LinkRouter`, or soft-delete as abandoned? **Stop here until answered.** Do not guess — these are owner-facing links.

- [ ] **Step 4: Write the migration for the chosen disposition**

For soft-delete (the simpler branch — use only if that is what was decided):

```sql
-- Clear the last rows on retired platform surfaces.
--
-- WHY: IntegrationConnection::RETIRED_SURFACES lists six surfaces that can no
-- longer be connected, but the guard fires on CREATE only — deliberately, so
-- the retirement migration could repoint existing rows. That repoint appears
-- never to have run: 8 rows survived on three retired surfaces, last touched
-- 2026-08-16.
--
-- partna.manual_product is NOT in RETIRED_SURFACES and is deliberately
-- excluded here — it is hidden, not retired.
--
-- ROLLBACK: UPDATE site.platform_connections SET deleted_at = NULL
--           WHERE deleted_at = '<the timestamp this migration wrote>';

BEGIN;

UPDATE "site"."platform_connections"
SET "deleted_at" = now()
WHERE "surface_key" IN (
    'partna.custom_link',
    'partna.order_link',
    'partna.storefront',
    'partna.reserve_link',
    'partna.booking_link',
    'partna.manual_event'
)
  AND "deleted_at" IS NULL;

COMMIT;
```

- [ ] **Step 5: Apply and verify**

```bash
supabase db push --dry-run && supabase db push
```

Then confirm zero live rows remain, and that `partna.manual_product` is untouched:

```sql
SELECT surface_key, COUNT(*) FROM site.platform_connections
WHERE deleted_at IS NULL AND surface_key LIKE 'partna.%'
GROUP BY 1;
```

Expected: `partna.manual_product` only.

- [ ] **Step 6: Bust the affected sites' caches**

These rows were changed by raw SQL, so **no observer fired**. Per the repo's three-lane cache contract, any owner-visible mutation needs `BuildState::bump()`, a `site.sites.updated_at` touch, and a conditional edge purge. Run for each affected site:

```bash
php artisan tinker --execute="
  \App\Site\Documents\SiteCacheLanes::bust('<site_id>');
"
```

Collect the site ids from the Step 2 query's `user_id` values first.

- [ ] **Step 7: Commit**

```bash
git add supabase/migrations/20260905000100_repoint_retired_surfaces.sql
git commit -m "Clear the last rows on retired platform surfaces

RETIRED_SURFACES' guard fires on create only, so the retirement migration
could repoint existing rows. It never ran: 8 rows survived on three retired
surfaces. partna.manual_product is excluded — hidden, not retired.

Raw SQL bypasses the observers, so the affected sites' three cache lanes
were busted explicitly via SiteCacheLanes::bust().

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 9: Re-scope the `site.menu_items` stand-in (LEG-9 — corrected)

**Files:**
- Modify: `tests/Pest.php:1348-1380`
- Modify: `tests/Feature/Platforms/MenuScanApplierContentTest.php:409-416`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: the stand-in either stays with a comment explaining why, or is replaced by a stronger absence assertion. Either way the regression guard survives.

**Context the implementer needs — this finding was initially wrong and the correction matters.** The inventory listed the `site.menu_items` SQLite stand-in as orphaned scaffolding for a dropped table. It is not. `tests/Feature/Platforms/MenuScanApplierContentTest.php:409` asserts:

```php
it('writes content.* and leaves site.menu_items empty', function () {
    // ...
    expect(DB::connection('pgsql')->table('site.menu_items')->count())->toBe(0)
```

That is a **regression guard proving the new content lane does not write the old table**, and it needs the table to exist in SQLite in order to count zero rows. Deleting the stand-in breaks the guard. The real choice is which shape of guard is stronger.

- [ ] **Step 1: Confirm the guard is the only consumer**

```bash
grep -rn "site\.menu_items" tests/ | grep -v "^tests/Pest.php"
```

Expected: `MenuScanApplierContentTest.php` (the assertion), plus comment-only mentions in `MenuTest.php`, `ScanPreviousWebsiteContentJobTest.php` and `PestSetupHelpers.php`. Only the first is executable.

- [ ] **Step 2: Choose the stronger guard**

Two options — pick one and record why in the commit:

**(a) Keep the stand-in, document it.** Lowest risk. The guard keeps testing "the applier wrote nothing here."

**(b) Assert the table does not exist.** Stronger, because it also catches code that would recreate it. Replace the count assertion with an absence assertion and delete the stand-in.

Option (b) is preferred — it matches the production reality, where the table is dropped and a write would raise `42P01`.

- [ ] **Step 3: If (b) — write the replacement assertion**

In `tests/Feature/Platforms/MenuScanApplierContentTest.php`, replace the count assertion:

```php
it('writes content.* and never touches the dropped site.menu_items table', function () {
    // ... existing arrange/act ...

    // site.menu_items was dropped on dev 2026-08-17. Asserting ABSENCE is
    // stronger than asserting an empty table: it also fails if a future change
    // recreates the table to write to it, which is the actual regression.
    expect(Schema::connection('pgsql')->hasTable('site.menu_items'))->toBeFalse();
});
```

Add `use Illuminate\Support\Facades\Schema;` to the file's imports.

- [ ] **Step 4: Delete the stand-in**

In `tests/Pest.php`, remove the `CREATE TABLE IF NOT EXISTS site.menu_items (...)` block (around line 1348) and the two defensive `ALTER TABLE site.menu_items ADD COLUMN` statements that follow it (around lines 1372 and 1377).

- [ ] **Step 5: Run the menu suites**

```bash
./vendor/bin/pest tests/Feature/Platforms/MenuScanApplierContentTest.php \
  tests/Feature/Platforms/MenuTest.php \
  tests/Feature/Platforms/ScanPreviousWebsiteContentJobTest.php
```

Expected: PASS. A failure naming `site.menu_items` means something still reads it — go back to option (a).

- [ ] **Step 6: Commit**

```bash
php artisan pint
git add -A
git commit -m "Assert site.menu_items is absent rather than empty

Corrects a finding: the SQLite stand-in for this dropped table looked like
orphaned scaffolding, but MenuScanApplierContentTest needed it to assert the
content lane writes zero rows there. Asserting the table does not exist is
the stronger guard — it also catches a change that recreates the table in
order to write to it, which is the regression that actually matters.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_019rmjaSLYSJkypTp5nzBYJg"
```

---

### Task 10: Full verification and the frontend PR

**Files:**
- Modify: `audits/cleanup/2026-09-04-legacy-payload-lane/CONSOLIDATED.md` — tick the boxes
- Optional, superseded repo only: `PartnaAu/partna-frontend` `app/api/public/site/route.ts` — delete

**Interfaces:**
- Consumes: every previous task.
- Produces: a merged branch and a verified dev environment. **No frontend change is required** — the live frontend never had the route.

- [ ] **Step 1: Run every lane**

The default suite is not sufficient — four lanes exist and three do not run under `composer test`:

```bash
composer test          # ~20 min
composer test:schema   # required — Task 4 changed the schema
composer test:pg
composer test:authz
```

Expected: all PASS. Record any pre-existing failure separately rather than attributing it to this branch — check with `git stash && composer test` if unsure.

- [ ] **Step 2: Static analysis and formatting**

```bash
php artisan pint --test
./vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: clean. `pint --test` is the gate, not `pint`. PHPStan may surface pre-existing findings in untouched files — only fix ones this branch caused.

- [ ] **Step 3: Verify the canonical lane still serves a real site**

The suite runs SQLite; this is the check against the real dev database:

```bash
curl -s "https://dev-api.partna.au/api/public/profiles/<a-known-live-handle>" | \
  python3 -c "import json,sys; d=json.load(sys.stdin)['data']['profile']; \
  print('pools:', sorted(d['pools'].keys())); \
  print('architectureId:', d.get('architectureId')); \
  print('skeleton_id present:', 'skeleton_id' in json.dumps(d))"
```

Expected: nine pools, an `architectureId`, and `skeleton_id present: False`.

- [ ] **Step 4: Verify the retired endpoints are gone**

```bash
curl -s -o /dev/null -w "site-by-slug: %{http_code}\n" \
  -H "X-Site-Subdomain: anything" "https://dev-api.partna.au/api/public/site-by-slug"
```

Expected: `404`.

- [ ] **Step 5: Confirm no frontend change is needed**

The live frontend is `PartnaAu/partna-monorepo` (owner-confirmed 2026-09-04). It has **no `/api/public/site` route** and never called the legacy lane. Re-confirm against the current HEAD rather than trusting this plan:

```bash
git clone --depth 1 git@github.com:PartnaAu/partna-monorepo.git /tmp/pm && cd /tmp/pm
grep -rn "site-by-slug\|X-Site-Subdomain" . | grep -v node_modules
ls apps/dashboard/app/api/
```

Expected: zero grep matches, and no `public/` directory under `apps/dashboard/app/api/`.

**If that holds, there is no frontend PR for this work.** The orphan proxy exists only in `PartnaAu/partna-frontend`, the superseded dashboard (last commit 2026-07-28, zero callers of the proxy even there). Deleting it is optional hygiene in a repo nobody deploys — do it only if that repo is being tidied for other reasons, and never as a blocker on this branch.

**If the grep finds a match**, stop: the monorepo took a dependency on the legacy lane after 2026-09-03, and Tasks 1–4 must be reverted or re-gated before merging.

- [ ] **Step 6: Tick the audit checkboxes**

In `audits/cleanup/2026-09-04-legacy-payload-lane/CONSOLIDATED.md`, change `- [ ]` to `- [x]` for `#LEGACY-1` and `#LEGACY-2`, and update the Progress block's counts to match. All boxes ticked triggers auto-archive on the next `scripts/audit/archive-done.sh` run.

- [ ] **Step 7: Open the backend PR**

```bash
git push -u origin cleanup/legacy-payload-lane-2026-09-04
gh pr create --base development --title "Retire the legacy public-site payload lane and four smaller vestigial surfaces" --body "$(cat <<'EOF'
## What

Removes the superseded public-site payload lane end to end, plus four smaller
items found in the same 2026-09-04 scan.

## Why the lane is safe to delete

A four-repo consumer search:

| Repo | HEAD | Calls the lane? |
|---|---|---|
| partna-monorepo (live sitepage) | 2026-09-03 | No — reads `/public/profiles/{handle}` |
| Partna-App (mobile) | 2026-08-12 | No public-site surface at all |
| partna-pages (superseded) | 2026-07-20 | No |
| partna-frontend (superseded dashboard) | 2026-07-28 | One proxy route with **zero callers** |

`/api/public/site` was independently unreachable in production: domain-scoped
to `{subdomain}.partna.au`, a zone the Worker claims wholesale and forwards to
the pages app without calling Laravel.

## Also removed

- The `site.public_site_payload` view — with it, the permanently-empty
  `gallery`/`gallery_videos` keys and the `skeleton_id` alias
- The dead half of `WarmPublicSiteCacheJob`'s dual-warm
- `LiveStatusInjector` — orphaned; only the deleted lane consumed it
- The duplicate domain-scoped route group
- The `NotificationEmailPolicy` model (table kept)

## Not in this PR

- `site_media.pool` → `usage` — shipped separately
- Prod schema reconciliation — separate scheduled work

## Verification

`composer test`, `test:schema`, `test:pg`, `test:authz` all green; canonical
endpoint verified against dev-api; retired endpoints return 404.

**No frontend change required.** The live frontend (`partna-monorepo`) never
called this lane — verified by exhaustive grep across all three apps. The only
copy of the `/api/public/site` proxy is in the superseded `partna-frontend`
repo, where it has zero callers.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review

**1. Spec coverage.** All ten findings are accounted for: LEG-1 across tasks 1–4, LEG-2 and LEG-6 subsumed by task 4, LEG-3 in task 5, LEG-4 in task 6, LEG-5 explicitly out of scope (being fixed by the in-flight refactor, noted in the sequencing section and documented in task 7), LEG-7 in task 7, LEG-8 in task 8, LEG-9 in task 9, LEG-10 folded into task 2. The audit file's `#LEGACY-1`/`#LEGACY-2` map to LEG-1 and LEG-3.

**2. Placeholder scan.** No TBDs. Every code step carries the actual code. Task 8's migration body is written for the soft-delete branch and explicitly gated on a decision, which is a genuine stop-and-ask rather than a placeholder — the alternative branch would be a different migration and cannot be pre-written without the answer.

**3. Type consistency.** `SiteCacheLanes::bust()` (task 8) matches the seam named in CLAUDE.md. `Schema::connection('pgsql')` (task 9) matches the connection the stand-ins use throughout `tests/Pest.php`. Task 2's names were verified against the source after drafting — see below.

**Verification pass caught three wrong names in task 2**, now corrected in place: the generator is `publicSitePayload()`, not `sitePayload()`; there is no `individualProfile()` key (the canonical one is `publicProfile($handleLc, $ts)`); and `WarmPublicSiteCacheJob::handle()` takes three injected services, not two.

**Two deliberate stops remain**, both genuine decisions rather than gaps: task 2 step 6 asks whether `handle()` still needs its `SiteCacheService` parameter once the legacy warm is gone (depends on the rest of the method body, which the implementer will have open), and task 8 stops for an owner ruling on whether the 8 retired-surface rows are repointed or soft-deleted.
