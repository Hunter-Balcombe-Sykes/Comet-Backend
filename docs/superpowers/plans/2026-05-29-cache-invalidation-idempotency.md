# Cache-Invalidation Idempotency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make site cache-invalidation idempotent and coalesced so a single write fires the expensive Cloudflare purge + cache-warm exactly once (not 2–3×), and route every invalidation through one path (`SiteObserver`).

**Architecture:** Two complementary changes. (1) Add `ShouldBeUnique` to `CloudflareCachePurgeJob` and `WarmPublicSiteCacheJob` keyed by handle/subdomain — when workers are enabled these coalesce a burst of dispatches into one run (the latency fix). (2) Remove the ~8 redundant explicit `invalidateSite()` calls that duplicate work already done by `SiteMediaObserver` → `SiteObserver`, and convert the 2 paths that bypass Eloquent events (`UserGalleryController::reorder`, `ServiceCategoryObserver`) to `$site->touch()` so they flow through the same single pipeline — which also fixes a real bug where those two paths never purged the Cloudflare edge. Two direct calls are deliberately kept (a conservative professional-cache catch-all and account-deletion teardown).

**Explicitly NOT doing:** a request-scoped dedup guard. It would require unit-of-work flush boundaries (HTTP terminate / queue-job / CLI), which is the event-bus machinery we scoped out, and it conflicts with the observable-outcome test design (`BlockAndMediaTouchSiteTest` performs several site-touching operations per test with no request boundary to reset against). Consequence: the cheap inline Redis sweep (`invalidateSite`, the benign `CACHE DELETE FAILURE` lines in Nightwatch) may still run 1–2× per request — idempotent and ~tens of ms. Only the expensive jobs are made exactly-once.

**Net effect when workers are enabled (production):** an image delete fires **1** Cloudflare purge + **1** warm (was 2 each), plus 1–2 cheap Redis sweeps (was 3). On the current dev `sync` queue the jobs still run inline; the full latency win lands when `QUEUE_CONNECTION` flips to `redis`.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Redis (cache + queue), Laravel Horizon (workers).

---

## File Map

| File | Change |
|------|--------|
| `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` | implement `ShouldBeUnique`, add `uniqueId()` + `uniqueFor` |
| `app/Jobs/Cache/WarmPublicSiteCacheJob.php` | implement `ShouldBeUnique`, add `uniqueId()` + `uniqueFor` |
| `app/Http/Controllers/Api/User/Uploads/UserUploadController.php` | remove redundant `invalidateSite()` in `destroy` |
| `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php` | remove redundant calls in `destroy`/`update`; convert `reorder` to `$site->touch()` |
| `app/Http/Controllers/Api/User/Account/UserDocumentController.php` | remove redundant calls in `store`/`update`/`destroy` |
| `app/Services/Media/MediaUploadService.php` | remove redundant calls (lines 137, 264) |
| `app/Observers/Core/ServiceCategoryObserver.php` | convert `invalidateSite()` to `$site->touch()`; drop `SiteCacheService` dep |
| `app/Services/Cache/UserCacheService.php` | keep line 302; add WHY comment |
| `app/Services/User/AccountDeletionService.php` | keep line 494; add WHY comment |
| `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php` | add uniqueness test |
| `tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php` | **create** — uniqueness test |
| `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php` | strengthen: assert payload key cleared on SiteMedia delete |
| `tests/Feature/Gallery/GalleryReorderPurgeTest.php` | **create** — reorder now dispatches purge |
| `tests/Feature/Observers/ServiceCategoryObserverTest.php` | update mocks; add purge-on-touch test |

**Existing safety net:** `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php` already proves `SiteMedia::create/save/delete` dispatch `CloudflareCachePurgeJob` via the `touch()` → `SiteObserver::saved` chain. Since `SiteObserver::saved` runs `invalidateSite()` and the purge dispatch in the same method, that test transitively guarantees invalidation still happens after we remove the redundant explicit calls. Task 3 makes the invalidation assertion explicit.

---

### Task 1: Make CloudflareCachePurgeJob unique per handle

**Files:**
- Modify: `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- Test: `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php` (add the `use` line at the top with the other imports):

```php
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('is unique per lowered handle so a burst of site touches coalesces to one purge', function () {
    $job = new CloudflareCachePurgeJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case')
        ->and($job->uniqueFor)->toBe(120);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Jobs/CloudflareCachePurgeJobTest.php --filter="is unique per lowered handle"`
Expected: FAIL — `CloudflareCachePurgeJob` is not an instance of `ShouldBeUnique` / `uniqueId()` undefined.

- [ ] **Step 3: Implement**

In `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`:

Add the import below the existing `ShouldQueue` import:
```php
use Illuminate\Contracts\Queue\ShouldBeUnique;
```

Change the class declaration:
```php
class CloudflareCachePurgeJob implements ShouldQueue, ShouldBeUnique
```

Add these members inside the class, immediately after `public int $timeout = 15;`:
```php
    /**
     * Coalesce window: while a purge for this handle is queued/running, duplicate
     * dispatches from the same request's observer cascade (or a rapid burst of
     * edits) are dropped. Exceeds $timeout so a slow purge can't release the lock
     * early and let a duplicate through.
     */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return strtolower(trim($this->handle));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Cloudflare/CloudflareCachePurgeJob.php tests/Unit/Jobs/CloudflareCachePurgeJobTest.php
git commit -m "perf(cache): make CloudflareCachePurgeJob unique per handle to coalesce cascaded purges"
```

---

### Task 2: Make WarmPublicSiteCacheJob unique per subdomain

**Files:**
- Modify: `app/Jobs/Cache/WarmPublicSiteCacheJob.php`
- Test: `tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php`:

```php
<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('is unique per lowered subdomain so cascaded warms coalesce to one rebuild', function () {
    $job = new WarmPublicSiteCacheJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case')
        ->and($job->uniqueFor)->toBe(120);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php`
Expected: FAIL — not an instance of `ShouldBeUnique` / `uniqueId()` undefined.

- [ ] **Step 3: Implement**

In `app/Jobs/Cache/WarmPublicSiteCacheJob.php`:

Add the import below the existing `ShouldQueue` import:
```php
use Illuminate\Contracts\Queue\ShouldBeUnique;
```

Change the class declaration:
```php
class WarmPublicSiteCacheJob implements ShouldQueue, ShouldBeUnique
```

Add these members inside the class, immediately after `public int $timeout = 10;`:
```php
    /**
     * Coalesce window: a single edit can touch the site more than once (media +
     * section-visibility), each firing SiteObserver. Without this, every touch
     * re-dispatches a full payload rebuild. Exceeds $timeout to avoid early release.
     */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return strtolower($this->subdomain);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Cache/WarmPublicSiteCacheJob.php tests/Unit/Jobs/WarmPublicSiteCacheJobUniquenessTest.php
git commit -m "perf(cache): make WarmPublicSiteCacheJob unique per subdomain to coalesce cascaded warms"
```

---

### Task 3: Make the invalidation guarantee explicit, then remove redundant call in UserUploadController::destroy

**Files:**
- Modify: `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php`
- Modify: `app/Http/Controllers/Api/User/Uploads/UserUploadController.php:334`

This task first hardens the safety net (proving the observer chain invalidates the payload cache, not just dispatches the purge), then removes the first redundant call.

- [ ] **Step 1a: Add the alias table to this file's `beforeEach`**

`invalidateSite()` queries `site.site_subdomain_aliases` (SiteCacheService.php:543). `setupSitesTable()` does NOT create it — there is a separate `setupSubdomainAliasesTable()` helper (tests/Pest.php:1166). Without it, `invalidateSite()` throws (caught by `SiteObserver`, so the existing purge-push tests still pass, but the new key-clear assertion below would fail because the throw happens before `Cache::deleteMultiple`). Update `beforeEach` in `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php`:

```php
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupBlocksTable();
    setupMediaTables();
});
```

- [ ] **Step 1b: Strengthen the safety-net test**

In `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php`, add this test after the existing `SiteMedia::delete dispatches CloudflareCachePurgeJob…` test. `Queue::fake()` stops the purge/warm *jobs* from running, but `invalidateSite()` is a direct call inside `SiteObserver::saved` (not a queued job), so it still runs and clears the key — exactly what we want to assert:

```php
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

it('SiteMedia::delete clears the public payload cache via SiteObserver (invalidation, not just purge)', function () {
    Queue::fake();
    $fixture = seedTouchFixture();
    $media = SiteMedia::create([
        'site_id' => $fixture['site_id'],
        'user_id' => $fixture['pro_id'],
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    // Seed the key AFTER create() so the create's own invalidation can't pre-clear it.
    $key = CacheKeyGenerator::publicSitePayload('touchtest');
    Cache::put($key, ['stale' => true], 600);

    $media->delete();

    expect(Cache::get($key))->toBeNull();
});
```

- [ ] **Step 2: Run it to confirm the chain already invalidates (PASS before touching the controller)**

Run: `php artisan test tests/Feature/Observers/BlockAndMediaTouchSiteTest.php`
Expected: PASS (all tests, including the new one). This proves `SiteMediaObserver` → `SiteObserver::saved` → `invalidateSite()` clears the payload key with no help from the controller.

- [ ] **Step 3: Remove the redundant call**

In `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`, delete line 334 and its blank line:

```php
        app(SiteCacheService::class)->invalidateSite($site);
```

The `destroy` method's tail becomes:
```php
        $image->delete();

        if ($this->shouldRememberConfirmationPreference($request)) {
            app(ConfirmationPreferenceService::class)->enableForProfessional(
                (string) $pro->id,
                ConfirmationPreferenceService::ACTION_DELETE_MEDIA
            );
        }

        return $this->success(['deleted' => true]);
```

If `SiteCacheService` is now unused in this file, remove its `use App\Services\Cache\SiteCacheService;` import. (It is still used elsewhere? Check: `grep -n "SiteCacheService" app/Http/Controllers/Api/User/Uploads/UserUploadController.php` — if zero hits after the edit, remove the import.)

- [ ] **Step 4: Verify nothing regressed**

Run: `php artisan test tests/Feature/Observers/BlockAndMediaTouchSiteTest.php tests/Feature/FeatureFlags/VideoUploadsFlagTest.php tests/Feature/MediaUploadFailureHandlingTest.php`
Expected: PASS. (`$image->delete()` still fires the observer chain → invalidation + purge.)

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Observers/BlockAndMediaTouchSiteTest.php app/Http/Controllers/Api/User/Uploads/UserUploadController.php
git commit -m "refactor(cache): drop redundant invalidateSite in image destroy (observer chain covers it)"
```

---

### Task 4: Remove redundant calls in UserGalleryController (destroy + update)

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php:133,179`

Both paths mutate a `SiteMedia` via Eloquent (`save`/`delete`), so `SiteMediaObserver` already touches the site → full pipeline. The explicit calls are redundant. (NOTE: `reorder` at line 94 is handled in Task 7 — do NOT touch it here.)

- [ ] **Step 1: Remove the call in `destroy`**

Delete line 179 + its surrounding blank line:
```php
        app(SiteCacheService::class)->invalidateSite($site);
```
`destroy` tail becomes:
```php
        $image->delete();

        if ($this->shouldRememberConfirmationPreference($request)) {
            app(ConfirmationPreferenceService::class)->enableForProfessional(
                (string) $pro->id,
                ConfirmationPreferenceService::ACTION_DELETE_MEDIA
            );
        }

        return $this->success(['deleted' => true]);
```

- [ ] **Step 2: Remove the call in `update` and the now-dead `$changed` flag**

The current `update` tail is:
```php
        $changed = false;
        if (! empty($update)) {
            $image->fill($update);
            if ($image->isDirty(['caption', 'alt_text'])) {
                $image->save();
                $changed = true;
            }
        }

        if ($changed) {
            app(SiteCacheService::class)->invalidateSite($site);
        }

        return $this->success([
```

Replace with (the `save()` itself now drives invalidation via the observer):
```php
        if (! empty($update)) {
            $image->fill($update);
            if ($image->isDirty(['caption', 'alt_text'])) {
                $image->save();
            }
        }

        return $this->success([
```

- [ ] **Step 3: Drop the now-unused import if applicable**

Run: `grep -n "SiteCacheService" app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php`
After Task 7 the `reorder` path will use `$site->touch()` and not `SiteCacheService`, so the import will be fully unused — but it is still used by `reorder` until Task 7 lands. **Leave the `use` import in place for now; Task 7 removes it.**

- [ ] **Step 4: Verify**

Run: `php artisan test tests/Feature/Gallery/GalleryCaptionTest.php tests/Feature/Security/TenantIsolation/GalleryIsolationTest.php tests/Feature/Observers/BlockAndMediaTouchSiteTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php
git commit -m "refactor(cache): drop redundant invalidateSite in gallery update/destroy (observer chain covers it)"
```

---

### Task 5: Remove redundant calls in UserDocumentController (store + update + destroy)

**Files:**
- Modify: `app/Http/Controllers/Api/User/Account/UserDocumentController.php:200,249,282`

`store` creates a `SiteMedia` via `SiteMedia::create()` inside a transaction (line 127); `update` calls `$document->save()` (line 243); `destroy` calls `$document->delete()` (line 280). All fire `SiteMediaObserver` → site touch → full pipeline. The explicit calls are redundant.

- [ ] **Step 1: Remove the call in `store`**

Delete line 200:
```php
        app(SiteCacheService::class)->invalidateSite($site);
```
so the tail is:
```php
        if ($previousPath !== null && $previousPath !== '') {
            // ... existing R2 cleanup ...
        }

        return $this->success(['document' => $this->buildDocumentPayload($media)], 201);
```

- [ ] **Step 2: Remove the call in `update` and the dead `$changed` flag**

Replace:
```php
        $changed = false;
        if (! empty($update)) {
            $document->fill($update);
            if ($document->isDirty(['alt_text', 'caption', 'is_active'])) {
                $document->save();
                $changed = true;
            }
        }

        if ($changed) {
            app(SiteCacheService::class)->invalidateSite($site);
        }

        return $this->success(['document' => $this->buildDocumentPayload($document->fresh())]);
```
with:
```php
        if (! empty($update)) {
            $document->fill($update);
            if ($document->isDirty(['alt_text', 'caption', 'is_active'])) {
                $document->save();
            }
        }

        return $this->success(['document' => $this->buildDocumentPayload($document->fresh())]);
```

- [ ] **Step 3: Remove the call in `destroy`**

Delete line 282:
```php
        app(SiteCacheService::class)->invalidateSite($site);
```
so the tail is:
```php
        $document->delete();

        return $this->success(['deleted' => true]);
```

- [ ] **Step 4: Drop the unused import if applicable**

Run: `grep -n "SiteCacheService" app/Http/Controllers/Api/User/Account/UserDocumentController.php`
If zero hits, remove `use App\Services\Cache\SiteCacheService;`.

- [ ] **Step 5: Verify**

Run: `php artisan test tests/Feature/Documents/DocumentControllerIntegrationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/User/Account/UserDocumentController.php
git commit -m "refactor(cache): drop redundant invalidateSite in document store/update/destroy (observer chain covers it)"
```

---

### Task 6: Remove redundant calls in MediaUploadService

**Files:**
- Modify: `app/Services/Media/MediaUploadService.php:137,264`

Line 137 follows `$media->update(['path' => …])` (line 119, Eloquent save → observer touch). Line 264 follows `$media->delete()` (line 263, Eloquent → observer touch). Both redundant.

- [ ] **Step 1: Remove line 137**

Delete:
```php
        $this->siteCache->invalidateSite($site);
```
so the upload tail is:
```php
        } else {
            $this->dispatchImageJob($media->id, $originalPath, $basePath);
        }

        // Refresh — sync mode may have already advanced processing_state to 'ready'.
        $media->refresh();
        $media->load('mediaVariants');

        return $media;
```

- [ ] **Step 2: Remove line 264**

Delete:
```php
        $this->siteCache->invalidateSite($site);
```
so `rollbackFailedVideoDispatch` tail is:
```php
        $media->delete();
    }
```

- [ ] **Step 3: Decide on the `$siteCache` dependency**

Run: `grep -n "siteCache" app/Services/Media/MediaUploadService.php`
If there are no remaining uses, remove the `SiteCacheService` constructor property and its import. If other methods still use it, leave it.

- [ ] **Step 4: Verify**

Run: `php artisan test tests/Feature/MediaUploadFailureHandlingTest.php tests/Feature/FeatureFlags/VideoUploadsFlagTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Media/MediaUploadService.php
git commit -m "refactor(cache): drop redundant invalidateSite in MediaUploadService (observer chain covers it)"
```

---

### Task 7: Route UserGalleryController::reorder through the pipeline (behavior fix — now purges the edge)

**Files:**
- Create: `tests/Feature/Gallery/GalleryReorderPurgeTest.php`
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php:94`

`reorder` mass-updates `sort_order` via the query builder (bypasses Eloquent events), so today it only busts Redis (`invalidateSite`) and never dispatches `CloudflareCachePurgeJob` — gallery reorders stay stale at the Cloudflare edge for the full `s-maxage` window. `UserUploadController::reorder` already uses `$site->touch()` (the correct pattern); this aligns gallery reorder with it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gallery/GalleryReorderPurgeTest.php` (harness mirrors `GalleryMixedReorderTest`, adapted for `UserGalleryController` + `ReorderGalleryImageRequest`; does NOT mock `SiteCacheService` so the real observer runs, and fakes the queue to capture the purge):

```php
<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\SiteManagement\UserGalleryController;
use App\Http\Requests\Api\User\ImageGallery\ReorderGalleryImageRequest;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable(); // invalidateSite() queries site.site_subdomain_aliases
    setupMediaTables();
});

function seedGalleryReorderFixture(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'gallery-purge',
        'handle_lc' => 'gallery-purge',
        'display_name' => 'Gallery Purge',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'gallery-purge',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $pro = User::query()->findOrFail($userId);
    $pro->load('site');

    $ids = [];
    foreach ([0, 1] as $sort) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site_media')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'pool' => 'gallery',
            'path' => "images/{$siteId}/{$id}/original.webp",
            'sort_order' => $sort,
            'is_active' => true,
            'media_type' => 'image',
            'processing_state' => SiteMedia::PROCESSING_STATE_READY,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $ids[] = $id;
    }

    return [$pro, $ids];
}

it('gallery reorder dispatches CloudflareCachePurgeJob via $site->touch()', function () {
    [$pro, $ids] = seedGalleryReorderFixture();
    Queue::fake();

    $request = Request::create('/api/gallery/reorder', 'POST', ['ids' => array_reverse($ids)]);
    $request->attributes->set('professional', $pro);
    app()->instance('request', $request);

    $formRequest = ReorderGalleryImageRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    app()->instance(ImageVariantService::class, Mockery::mock(ImageVariantService::class));
    $response = app(UserGalleryController::class)->reorder($formRequest);

    expect($response->getStatusCode())->toBe(200);
    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'gallery-purge';
    });
});
```

- [ ] **Step 2: Run it to verify it FAILS**

Run: `php artisan test tests/Feature/Gallery/GalleryReorderPurgeTest.php`
Expected: FAIL — `Queue::assertPushed` finds no `CloudflareCachePurgeJob` (current code only calls `invalidateSite`, which dispatches nothing).

- [ ] **Step 3: Implement the conversion**

In `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php`, replace line 94:
```php
        app(SiteCacheService::class)->invalidateSite($site);
```
with:
```php
        // Mass `update()` above bypasses Eloquent events, so SiteMediaObserver
        // never touches the site. Touch explicitly to fire SiteObserver — Redis
        // invalidation + Cloudflare edge purge + cache warm — matching the
        // image-reorder path (UserUploadController::reorder).
        $site->touch();
```

Then remove the now-unused import `use App\Services\Cache\SiteCacheService;` (verify with `grep -n "SiteCacheService" app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php` → expect zero hits).

- [ ] **Step 4: Run it to verify it PASSES**

Run: `php artisan test tests/Feature/Gallery/GalleryReorderPurgeTest.php tests/Feature/Gallery/GalleryCaptionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php tests/Feature/Gallery/GalleryReorderPurgeTest.php
git commit -m "fix(cache): gallery reorder now purges the Cloudflare edge via \$site->touch()"
```

---

### Task 8: Route ServiceCategoryObserver through the pipeline (behavior fix — now purges the edge)

**Files:**
- Modify: `app/Observers/Core/ServiceCategoryObserver.php`
- Modify: `tests/Feature/Observers/ServiceCategoryObserverTest.php`

A `ServiceCategory` rename embeds in the public payload's services array, but the observer only calls `invalidateSite()` (no edge purge). Convert the site-cache step to `$site->touch()` so it flows through `SiteObserver` (invalidate + purge + warm). The four service-key busts (`Cache::deleteMultiple`) stay unchanged.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Observers/ServiceCategoryObserverTest.php`, add `setupSitesTable()` + `setupSubdomainAliasesTable()` to `beforeEach` (the touch fires `SiteObserver` → `invalidateSite`, which queries the aliases table):
```php
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupServiceCategoriesTable();
});
```

Add this test (a pro WITH a published site; fake the queue to capture the purge):
```php
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\Queue;

it('touches the site so a ServiceCategory change purges the Cloudflare edge', function () {
    $pro = seedCategoryTestPro();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'subdomain' => 'cat-pro',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    Queue::fake();

    ServiceCategory::query()->create([
        'user_id' => $pro->id,
        'title' => 'Haircuts',
        'sort_order' => 0,
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'cat-pro';
    });
});
```

- [ ] **Step 2: Run it to verify it FAILS**

Run: `php artisan test tests/Feature/Observers/ServiceCategoryObserverTest.php --filter="purges the Cloudflare edge"`
Expected: FAIL — no `CloudflareCachePurgeJob` pushed (observer calls `invalidateSite`, which dispatches nothing).

- [ ] **Step 3: Implement the conversion**

In `app/Observers/Core/ServiceCategoryObserver.php`:

Remove the `SiteCacheService` import and the constructor (the observer no longer needs the injected service — service-key busting uses the `Cache` facade):
```php
// DELETE: use App\Services\Cache\SiteCacheService;
```
Replace the constructor block:
```php
    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}
```
with: (delete it entirely — no constructor needed).

Replace the site-cache `try` block (the `$pro?->site` → `invalidateSite` block, lines ~71–82) with:
```php
        // Category titles are embedded in the public site payload's services array
        // (SitepageDataResolverService::buildServicesData), so a rename also stales
        // the cached page. Touch the site to fire SiteObserver (Redis invalidation +
        // Cloudflare edge purge + cache warm) — invalidateSite alone never purged
        // the edge, so renames lagged ~s-maxage at <handle>.partna.au.
        try {
            $pro = User::query()->with('site')->find($userId);
            $pro?->site?->touch();
        } catch (\Throwable $e) {
            Log::warning('Site touch failed on ServiceCategory change', [
                'category_id' => $category->id,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
```

- [ ] **Step 4: Update the three existing site-less tests**

The existing `created`/`updated`/`deleted` tests inject a `SiteCacheService` mock and assert `shouldNotReceive('invalidateSite')`. The observer no longer depends on `SiteCacheService`, so those three blocks are dead. In each of the three tests, delete:
```php
    $siteCache = Mockery::mock(SiteCacheService::class);
    $siteCache->shouldNotReceive('invalidateSite'); // no site on this pro
    app()->instance(SiteCacheService::class, $siteCache);
```
(and the `shouldNotReceive('invalidateSite');` variant). Also remove the now-unused `use App\Services\Cache\SiteCacheService;` import if nothing else references it. The four-key bust assertions stay — they still pass (the `Cache::deleteMultiple` for service keys is unchanged), and with no site seeded, `$pro?->site` is null so no touch/purge fires.

- [ ] **Step 5: Run the full file to verify all PASS**

Run: `php artisan test tests/Feature/Observers/ServiceCategoryObserverTest.php`
Expected: PASS (3 updated service-key tests + the new purge test).

- [ ] **Step 6: Commit**

```bash
git add app/Observers/Core/ServiceCategoryObserver.php tests/Feature/Observers/ServiceCategoryObserverTest.php
git commit -m "fix(cache): ServiceCategory changes now purge the Cloudflare edge via \$site->touch()"
```

---

### Task 9: Document the two deliberately-kept direct calls

**Files:**
- Modify: `app/Services/Cache/UserCacheService.php:300-303`
- Modify: `app/Services/User/AccountDeletionService.php:488-494`

These two are NOT redundant and must stay; add a one-line WHY so a future cleanup doesn't remove them.

- [ ] **Step 1: Annotate UserCacheService**

The current block:
```php
        // Also invalidate site cache (public payload includes professional fields)
        if ($professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }
```
Replace the comment with:
```php
        // Conservative catch-all: bust the site payload for ANY professional change.
        // Kept deliberately (not redundant with UserObserver's touch, which only
        // fires for PUBLIC_PROFILE_USER_FIELDS). Removing this risks under-invalidation
        // if a non-listed column ever leaks into the public payload — invalidate-only
        // here is cheaper than that staleness class of bug.
        if ($professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }
```

- [ ] **Step 2: Annotate AccountDeletionService**

The existing comment at line 488 already explains the intent. Append one line so the "why not removed" is explicit. Change:
```php
        // Step 3: bust the public site cache (15-min TTL) so a just-purged site
        // stops serving stale payloads to public requests the instant we delete.
        // invalidateSite() handles the main subdomain + all aliases in one call.
```
to:
```php
        // Step 3: bust the public site cache (15-min TTL) so a just-purged site
        // stops serving stale payloads to public requests the instant we delete.
        // invalidateSite() handles the main subdomain + all aliases in one call.
        // Deliberate direct call (NOT redundant): teardown runs a force-delete cascade
        // that we don't want to depend on observer ordering for — invalidate explicitly.
```

- [ ] **Step 3: Verify no behavior change**

Run: `php artisan test tests/Feature/Account/AccountDeletionPurgeMediaTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Cache/UserCacheService.php app/Services/User/AccountDeletionService.php
git commit -m "docs(cache): explain why the two remaining direct invalidateSite calls are intentional"
```

---

### Task 10: Full-suite verification + style

- [ ] **Step 1: Pint**

Run: `php artisan pint`
Expected: clean (fixes whitespace/import ordering from the edits).

- [ ] **Step 2: Full test suite**

Run: `composer test`
Expected: PASS (no regressions). Pay attention to: `tests/Feature/Observers/*`, `tests/Feature/Gallery/*`, `tests/Feature/Documents/*`, `tests/Unit/Jobs/*`, `tests/Feature/Account/*`.

- [ ] **Step 3: Manual sanity — confirm the coalescing logic by reasoning**

Re-read `app/Observers/Core/SiteObserver.php::saved`: with workers enabled, a single image delete now dispatches `CloudflareCachePurgeJob` and `WarmPublicSiteCacheJob` for the same handle/subdomain from each `$site->touch()` in the cascade; `ShouldBeUnique` drops the duplicates while the first is queued. Confirm both jobs are dispatched `->afterCommit()` (they are) so the unique lock is taken after commit. No code change — this step is a comprehension checkpoint.

- [ ] **Step 4: Commit any Pint changes**

```bash
git add -A
git commit -m "style: pint after cache-invalidation idempotency changes"
```

---

## Out of scope (noted follow-ups, do NOT implement here)

- **`/service-categories/reorder` and `/services/reorder`** likely mass-update `sort_order` via the query builder (bypassing observers) and so may never purge the edge — same class of bug as gallery reorder. Investigate separately; if confirmed, apply the same `$site->touch()` fix.
- **Dropping eager warm in favour of SWR lazy-fill.** `invalidateSite` clears the `:stale` key too, so the first post-edit reader pays a cold rebuild — that's why warm-on-write exists. Whether to keep it is a separate behavioural decision.
- **Request-scoped dedup guard / event bus.** Deferred until a second independent consumer of a site-change signal exists (see the discussion that produced this plan). The cheap inline `invalidateSite` sweep running 1–2× per request is accepted.
- **The `PARTNA_MEDIA_DISK not set` warning.** This is a missing env var (`MediaDiskResolver` falls back to `filesystems.default` and logs), unrelated to cache invalidation. Fix by setting `PARTNA_MEDIA_DISK` in the dev/prod environment — no code change, so it is out of this plan.

## Self-review notes

- **Spec coverage:** ShouldBeUnique (Tasks 1–2); redundant-call removal across all 8 sites (Tasks 3–6); the 2 bypass-Eloquent conversions (Tasks 7–8); the 2 deliberate keeps documented (Task 9); verification (Task 10). All callers found in the audit are accounted for.
- **Type/name consistency:** `uniqueId()`/`uniqueFor` identical across Tasks 1–2; `$site->touch()` used identically in Tasks 7–8; `ReorderGalleryImageRequest` payload is `['ids' => [...]]` (confirmed from its rules — no `pool` field).
- **TDD honesty:** Tasks 1–2, 7, 8 are genuine red→green. Tasks 3–6 are refactors guarded by the strengthened `BlockAndMediaTouchSiteTest` (Task 3 Steps 1a–2) plus existing endpoint feature tests — characterization, not red→green, and labelled as such.

**Verified against the real code/tests before finalizing:**
- `phpunit.xml`: `CACHE_STORE=array` (ArrayStore supports atomic locks → `ShouldBeUnique` works in tests), `QUEUE_CONNECTION=sync`, `DB_CONNECTION=sqlite :memory:`.
- No test asserts an exact `invalidateSite` call count. Mock expectations are: `zeroOrMoreTimes` (DocumentControllerIntegrationTest:33), `shouldNotReceive` on the no-op PATCH (DocumentControllerIntegrationTest:286) and the three site-less ServiceCategory tests, bare `andReturnNull` stubs (MediaUploadFailureHandling, VideoUploadsFlag, GalleryMixedReorder), and `atLeast()->once()` in `AccountDeletionPurgeMediaTest:120` — the latter targets the call Task 9 deliberately KEEPS, so it stays green.
- All 13 `invalidateSite(` references in `app/` are accounted for: 8 removed (Tasks 3–6), 2 converted to `touch()` (Tasks 7–8), 2 kept + documented (Task 9), 1 is the canonical `SiteObserver` definition path (unchanged). Only `SiteObserver::saved` dispatches the purge/warm jobs, so job-level uniqueness (Tasks 1–2) covers every dispatch site.
- `ReorderGalleryImageRequest` validates only `['ids' => array<uuid>]` (no `pool` field) and inherits `BaseFormRequest::authorize()` (returns true — proven by `GalleryMixedReorderTest` using the sibling request).
- `setupSubdomainAliasesTable()` (tests/Pest.php:1166) is required wherever a test triggers a real `invalidateSite()`; added to the three affected `beforeEach` blocks.
