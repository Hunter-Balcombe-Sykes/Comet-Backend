# Design Media Promotion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** [`docs/superpowers/specs/2026-05-30-design-media-promotion-design.md`](../specs/2026-05-30-design-media-promotion-design.md)

**Goal:** Surface content-pool videos in the public profile payload by introducing a polymorphic top-level `designMedia` field (camelCase wire shape, sibling of `designKit`), replacing the legacy image-only `profile.content_images`. Also close a pre-existing cache-invalidation gap that affects every video upload.

**Architecture:** Extract `SitepageDataResolverService::buildGalleryItem`'s body into a shared private `buildMediaItem` helper so the gallery engine and the new `getContentMedia` method project through identical code. The resolver returns snake_case internally; the builder layer remaps to camelCase wire shape via a new `buildDesignMedia` method that mirrors `buildGallery`'s pattern. Drop `content_images` cleanly — no compat layer. Also: fix `VideoVariantService::processVariants` so the parent Site is touched after the variant mass-update completes (otherwise the public profile cache stays stale for up to 60 s after every video upload finishes processing).

**Tech Stack:** Laravel 12, Pest 4 for tests, Postgres-shaped SQLite shim in CI, R2 (S3-compatible) for storage.

---

## Pre-flight

This plan targets `origin/development`. Local `development` is 42 commits behind upstream at the time of writing, and there is in-progress CSAM-cleanup work in the working tree.

- [ ] Stash or commit any uncommitted CSAM-cleanup work on local `development`
- [ ] Fast-forward: `git fetch origin && git checkout development && git merge --ff-only origin/development`
- [ ] Confirm: `git log --oneline -1` shows commit `9639bb7f` or newer ("test(email): exempt visitor-confirmation jobs from capability sweep")
- [ ] Create the feature branch: `git checkout -b feat/design-media-promotion`

---

## Task 1: Extract shared `buildMediaItem` helper

**Goal:** Pure refactor — move the body of `buildGalleryItem` into a new private `buildMediaItem(SiteMedia $media): ?array`, have `buildGalleryItem` delegate. Behavior unchanged. The helper returns **resolver-internal snake_case** keys; the wire-layer camelCase remap happens in `IndividualProfilePayloadBuilder` (already does this for gallery via `buildGallery`'s explicit `array_map`, and Task 5 introduces the same pattern for design media).

**Why first:** All subsequent work depends on a single projection helper. Doing this as a no-op refactor first means we can verify the gallery wire shape hasn't drifted before we add the new caller.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php` (around line 122 for `buildGalleryItem`)

- [ ] **Step 1: Baseline — run existing gallery tests, capture green**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'gallery'
```

Expected: all green. If any are red on origin/development before your change, stop and ask Josh — that's a pre-existing failure not introduced by this plan.

- [ ] **Step 2: Extract the helper**

In `app/Services/PublicSite/SitepageDataResolverService.php`, find the existing `buildGalleryItem` method. Replace the method body so it delegates to a new private `buildMediaItem`:

```php
/**
 * Project a SiteMedia row to the resolver-internal polymorphic envelope
 * used by both the gallery engine and the new design media field. Videos
 * emit two plain MP4 tiers — `url` (optimized 720p, autoplay default) and
 * `url_hd` (maximized 1080p, on-demand/fullscreen); images use the
 * optimised WebP and carry `url_hd => null` for a uniform shape.
 *
 * NOTE: keys are snake_case — this is the resolver's internal shape.
 * IndividualProfilePayloadBuilder remaps to camelCase for the wire
 * (e.g. url_hd → urlHd, duration_ms → durationMs, alt_text → alt).
 *
 * Returns null when the media has no resolvable primary URL (e.g. variants
 * row missing post-failure). Callers must filter those out.
 *
 * @return array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}|null
 */
private function buildMediaItem(SiteMedia $media): ?array
{
    $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;

    if ($isVideo) {
        // Two MP4 tiers, delivered directly (no HLS): optimized 720p is the
        // default/autoplay source, maximized 1080p is loaded on demand
        // (tap / fullscreen). The frontend picks; we expose both URLs.
        $variants = [];
        $poster = null;
        foreach ($media->mediaVariants as $mv) {
            if ($mv->artifact_type === 'mp4') {
                $variants[$mv->variant_key] = $mv->url;
            } elseif ($mv->artifact_type === 'poster') {
                $poster = $mv->url;
            }
        }
        // optimized is the contract default; fall back to maximized then
        // original so a partially-processed item still renders something.
        $url = $variants['optimized'] ?? $variants['maximized'] ?? $variants['original'] ?? '';
        $urlHd = $variants['maximized'] ?? null;

        return [
            'id' => (string) $media->id,
            'sort_order' => (int) $media->sort_order,
            'url' => $url,
            'url_hd' => $urlHd,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'kind' => 'video',
            'poster' => $poster,
            'duration_ms' => $media->duration_ms,
        ];
    }

    $variantUrls = $media->variantUrls();
    $url = $variantUrls['optimized'] ?? $variantUrls['original'] ?? '';
    $urlHd = $variantUrls['maximized'] ?? null;

    return [
        'id' => (string) $media->id,
        'sort_order' => (int) $media->sort_order,
        'url' => $url,
        'url_hd' => $urlHd,
        'alt_text' => $media->alt_text,
        'caption' => $media->caption,
        'kind' => 'image',
        'poster' => null,
        'duration_ms' => null,
    ];
}

/**
 * Backwards-compatible gallery item projection. Delegates to the shared
 * polymorphic helper. Kept as a thin alias so the gallery engine's call
 * site reads naturally; future callers should use buildMediaItem directly.
 *
 * @return array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}|null
 */
public function buildGalleryItem(SiteMedia $media): ?array
{
    return $this->buildMediaItem($media);
}
```

> **Visibility:** `buildGalleryItem` is `public` on `origin/development` — preserve that. `buildMediaItem` is `private`.

> **Non-breaking nature of `id` / `sort_order`:** verified on `origin/development` — `IndividualProfilePayloadBuilder::buildGallery` maps the resolver output to its own explicit key list (`url`, `urlHd`, `alt`, `caption`, `kind`, `poster`, `durationMs`). Extra keys are silently dropped. So adding `id` and `sort_order` to the helper does not change the gallery wire shape.

- [ ] **Step 3: Re-run gallery tests, confirm still green**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'gallery'
```

Expected: same green result as Step 1. If any test went red, the helper diverges from the original — revert and inspect.

- [ ] **Step 4: Run the full feature test file to catch unrelated regressions**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
```

Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PublicSite/SitepageDataResolverService.php
git commit -m "refactor(public-profile): extract buildMediaItem from buildGalleryItem

Shared polymorphic projection helper for SiteMedia rows. buildGalleryItem
delegates so the gallery wire shape is unchanged. Helper returns
resolver-internal snake_case keys; wire-layer camelCase remap stays in
IndividualProfilePayloadBuilder.

Prep for designMedia top-level field (see
docs/superpowers/specs/2026-05-30-design-media-promotion-design.md)."
```

---

## Task 2: Touch parent Site on video-variant ready transition

**Goal:** Close a pre-existing cache-invalidation gap. `ProcessVideoVariantsJob` finishes by mass-updating the `SiteMedia` row to `processing_state='ready'` via `SiteMedia::query()->update(...)`. Laravel mass-updates **bypass observers**, so `SiteMediaObserver::saved` never fires, so `$site->touch()` never fires, so the public-profile cache key (which is `public.profile:{handle_lc}:{site.updated_at}`) doesn't roll forward, and Cloudflare doesn't get a push-purge. Newly-processed videos therefore appear only after the cache TTL expires (60 s default).

**Why in this PR:** the `designMedia` plan promotes video to a first-class consumption path. Shipping it on top of a 60-second-stale read path is exactly the foundational compromise the user asked us not to make. The fix is one line; the test is small.

**Files:**
- Modify: `app/Services/Media/VideoVariantService.php` (around line 240 where the mass update happens — `processVariants`)
- Modify: `tests/Feature/MediaUploadFailureHandlingTest.php` OR a sibling test that already covers `VideoVariantService` (verify location with `grep -rn 'VideoVariantService' tests/`)

- [ ] **Step 1: Locate the mass-update site**

```bash
grep -n "SiteMedia::query" app/Services/Media/VideoVariantService.php
```

Expected: hits around line 240 inside `processVariants`. The block looks like:

```php
SiteMedia::query()
    ->where('id', $mediaId)
    ->whereNull('deleted_at')
    ->update([
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'processing_error' => null,
        'duration_ms' => $durationMs,
        'poster_path' => $posterRemotePath,
    ]);
```

- [ ] **Step 2: Write the failing test**

Find the existing test file that exercises `VideoVariantService::processVariants` end-to-end. If none does, create a new minimal test at `tests/Feature/Media/VideoReadyCacheInvalidationTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\VideoVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
});

it('touches the parent Site updated_at when video variant processing completes', function () {
    // Seed user + site + a video media row that's still pending.
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId, 'handle' => 'touch-test', 'handle_lc' => 'touch-test',
        'display_name' => 'Touch Test', 'account_type' => 'individual',
        'status' => 'active',
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $proId, 'subdomain' => 'touch-test',
        'settings' => json_encode([]), 'is_published' => 1,
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $proId,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'processing', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/touch-test/orig.mp4',
        'created_at' => now()->subHour()->toDateTimeString(),
        'updated_at' => now()->subHour()->toDateTimeString(),
    ]);

    $siteUpdatedAtBefore = Site::query()->findOrFail($siteId)->updated_at;

    // Directly simulate the post-variant-processing transition the job
    // would do at the end of processVariants(). This is the line the fix
    // covers — we're asserting the site_id is touched as a side effect.
    $service = app(VideoVariantService::class);
    // Use the dedicated public API once added by the implementation (see Step 3)
    $service->markReady(mediaId: $mediaId, durationMs: 5000, posterPath: 'videos/touch-test/poster.jpg');

    $siteUpdatedAtAfter = Site::query()->findOrFail($siteId)->updated_at;

    expect($siteUpdatedAtAfter)->not->toEqual($siteUpdatedAtBefore);
});
```

- [ ] **Step 3: Run the test, confirm it fails**

```bash
vendor/bin/pest tests/Feature/Media/VideoReadyCacheInvalidationTest.php
```

Expected: FAIL — either `markReady` method does not exist (most likely) or the Site updated_at is unchanged.

- [ ] **Step 4: Implement the fix**

In `app/Services/Media/VideoVariantService.php`, extract the mass-update at the end of `processVariants` into a small public method that does the update **AND then touches the parent site**. The pattern mirrors the controller's reorder fix (`UserUploadController::reorder` line 287–292) which has a verbose comment explaining the same gap.

Replace the existing block:

```php
SiteMedia::query()
    ->where('id', $mediaId)
    ->whereNull('deleted_at')
    ->update([
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'processing_error' => null,
        'duration_ms' => $durationMs,
        'poster_path' => $posterRemotePath,
    ]);
```

With a call to a new method:

```php
$this->markReady($mediaId, $durationMs, $posterRemotePath);
```

And add the method below `processVariants`:

```php
/**
 * Advance a video SiteMedia row to ready and touch the parent Site so
 * SiteObserver::saved fires (Cloudflare purge + cache-key roll + local
 * Redis invalidation). Mass-updates via the query builder bypass
 * SiteMediaObserver — the same gap the reorder controller works around
 * with $site->touch(). Without this, content-pool and gallery videos
 * surface only after the public_profile cache TTL expires (60 s default).
 *
 * Use a model load + save() rather than a mass update so the observer
 * chain fires naturally. The DB cost is one extra SELECT, paid once
 * per video at completion — well worth a push-fresh public profile.
 */
public function markReady(string $mediaId, int $durationMs, ?string $posterPath): void
{
    $media = SiteMedia::query()
        ->whereNull('deleted_at')
        ->find($mediaId);

    if (! $media) {
        return; // Soft-deleted mid-processing; nothing to advance.
    }

    $media->processing_state = SiteMedia::PROCESSING_STATE_READY;
    $media->processing_error = null;
    $media->duration_ms = $durationMs;
    $media->poster_path = $posterPath;
    $media->save(); // SiteMediaObserver::saved → $site->touch() → cache roll.
}
```

- [ ] **Step 5: Run the test, confirm it passes**

```bash
vendor/bin/pest tests/Feature/Media/VideoReadyCacheInvalidationTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the existing video pipeline tests to catch regressions**

```bash
vendor/bin/pest --filter 'Video\|video'
```

Expected: all green. Watch specifically for `tests/Unit/MediaJobReliabilityTest.php` and any `ProcessVideoVariantsJob` integration tests.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Media/VideoVariantService.php tests/Feature/Media/VideoReadyCacheInvalidationTest.php
git commit -m "fix(media): touch parent Site on video variant ready transition

VideoVariantService::processVariants used SiteMedia::query()->update() —
a mass update that bypasses SiteMediaObserver, leaving the parent Site
un-touched and the public_profile cache key stale until TTL (60 s).

Switch to a model load + save() via the new markReady() method so the
observer chain fires: SiteMediaObserver → \$site->touch() →
SiteObserver → CloudflareCachePurgeJob + cache key roll.

Same pattern as the existing reorder fix in UserUploadController."
```

---

## Task 3: Add `getContentMedia` resolver method (image case)

**Goal:** New method that returns the polymorphic envelope (snake_case, resolver-internal) for content-pool media. TDD with the image case first — videos and edge cases are covered in Task 4 to keep the failing/passing transitions clean.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php` (around line 179 for `getContentImages`)
- Modify: `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

Add this test in `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`, immediately after the existing `'surfaces content-pool site_media as profile.content_images[]'` test. Don't replace the existing test yet — we keep both passing until Task 5.

```php
it('exposes content-pool images via the resolver getContentMedia method', function () {
    $pro = seedIndividualProfile('cmedia1');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/original.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Studio shot',
        'caption' => 'Behind the scenes',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $resolver = app(\App\Services\PublicSite\SitepageDataResolverService::class);

    $items = $resolver->getContentMedia($site);

    expect($items)->toBeArray()->toHaveCount(1);
    // Resolver-internal shape is snake_case; the builder later camelCases.
    expect($items[0])->toMatchArray([
        'id' => $mediaId,
        'sort_order' => 0,
        'kind' => 'image',
        'alt_text' => 'Studio shot',
        'caption' => 'Behind the scenes',
        'poster' => null,
        'duration_ms' => null,
        'url_hd' => null,
    ]);
    // url depends on test-env variant resolution; just assert it's a string.
    expect($items[0])->toHaveKey('url');
});
```

- [ ] **Step 2: Run the test, confirm it fails**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'getContentMedia method'
```

Expected: FAIL with `Call to undefined method ... ::getContentMedia()` or equivalent.

- [ ] **Step 3: Implement `getContentMedia`**

In `app/Services/PublicSite/SitepageDataResolverService.php`, immediately above the existing `getContentImages` method (around line 179 on origin/development), add the new method. Keep `getContentImages` in place for now — Task 6 removes it.

```php
// ── Content media (polymorphic — design layer) ──────────────────────

/**
 * Content-pool media — design-layer assets the skeleton paints with
 * (backgrounds, section covers, decorative imagery). Polymorphic: images
 * and videos in a single sort-ordered list, projected through the shared
 * buildMediaItem helper so the shape matches gallery items exactly.
 *
 * Unlike the phase-8 engines, this is not gated by a Block row — it's
 * design infrastructure, not user content. The skeleton consumes whatever
 * is in the pool in order.
 *
 * Returns snake_case keys (id, sort_order, url, url_hd, alt_text,
 * caption, kind, poster, duration_ms) — the resolver-internal shape.
 * IndividualProfilePayloadBuilder::buildDesignMedia remaps to camelCase
 * for the wire.
 *
 * @return list<array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}>
 */
public function getContentMedia(?Site $site): array
{
    if (! $site) {
        return [];
    }

    return SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_CONTENT)
        ->where('is_active', true)
        ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
        ->with('mediaVariants')
        ->orderBy('sort_order')
        ->get()
        ->map(fn (SiteMedia $media) => $this->buildMediaItem($media))
        ->filter(fn (?array $item) => $item !== null && $item['url'] !== '')
        ->values()
        ->all();
}
```

Also update the existing `getContentImages` PHPDoc to mark it deprecated:

```php
/**
 * @deprecated Use getContentMedia() instead. Removed in Task 6 of the same PR.
 *
 * Content-pool images (the legacy image-only field). Kept temporarily so
 * the resource's content_images field continues to resolve during the
 * in-PR transition; deleted once the wire migration in Task 5 is green.
 *
 * @return list<array{url: string, alt_text: string|null}>
 */
public function getContentImages(?Site $site): array
{
    // ... unchanged body ...
}
```

- [ ] **Step 4: Run the new test, confirm it passes**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'getContentMedia method'
```

Expected: PASS.

- [ ] **Step 5: Run the full feature test file to confirm nothing else broke**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
```

Expected: all green (including the existing `content_images` tests, which still call `getContentImages` via the resource).

- [ ] **Step 6: Commit**

```bash
git add app/Services/PublicSite/SitepageDataResolverService.php tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
git commit -m "feat(public-profile): add getContentMedia polymorphic resolver

New method returns content-pool media (images + videos) via the shared
buildMediaItem helper. Resolver returns snake_case (internal shape);
the wire-layer camelCase remap is in the builder (next commit).
getContentImages kept temporarily — removed in Task 6.

Spec: docs/superpowers/specs/2026-05-30-design-media-promotion-design.md"
```

---

## Task 4: Test video, mixed, filtering, and edge-case coverage for `getContentMedia`

**Goal:** Lock in the video projection shape, mixed-media sort order, the four exclusion rules (`is_active=false`, `processing_state` not ready, soft-deleted, wrong pool), AND two edge cases the helper handles via `?? null` fallbacks (video missing poster, video with only `optimized` variant — no `maximized`). Before the wire migration in Task 5.

**Why now:** Locking these tests in before the resource migration means Task 5 only fails on the resource-shape change, not on resolver behaviour. Easier diagnosis if something breaks.

**Files:**
- Modify: `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`

- [ ] **Step 1: Add the video-projection test**

```php
it('projects content-pool videos with kind=video, poster and duration_ms', function () {
    $pro = seedIndividualProfile('cmedia-video');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'videos/content/original.mp4',
        'media_type' => 'video',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Intro reel',
        'duration_ms' => 12500,
        'poster_path' => 'videos/content/poster.jpg',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    foreach (
        [
            ['variant_key' => 'optimized',  'artifact_type' => 'mp4',    'mime' => 'video/mp4',  'path' => 'videos/content/opt.mp4'],
            ['variant_key' => 'maximized',  'artifact_type' => 'mp4',    'mime' => 'video/mp4',  'path' => 'videos/content/max.mp4'],
            ['variant_key' => 'poster',     'artifact_type' => 'poster', 'mime' => 'image/jpeg', 'path' => 'videos/content/poster.jpg'],
        ] as $row
    ) {
        DB::connection('pgsql')->table('site.media_variants')->insert([
            'id' => (string) Str::uuid(),
            'media_id' => $mediaId,
            'variant_key' => $row['variant_key'],
            'artifact_type' => $row['artifact_type'],
            'disk' => 'media',
            'path' => $row['path'],
            'mime' => $row['mime'],
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['kind'])->toBe('video');
    expect($items[0]['duration_ms'])->toBe(12500);
    expect($items[0]['poster'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url_hd'])->toBeString()->not->toBeEmpty();
});
```

- [ ] **Step 2: Add the mixed sort_order test**

```php
it('interleaves content-pool images and videos by sort_order', function () {
    $pro = seedIndividualProfile('cmedia-mixed');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    $imageId = (string) Str::uuid();
    $videoId = (string) Str::uuid();

    // Image at sort_order=1, video at sort_order=0 — video must come first.
    DB::connection('pgsql')->table('site.site_media')->insert([
        [
            'id' => $imageId, 'site_id' => $siteId, 'user_id' => $pro->id,
            'pool' => 'content', 'path' => 'images/content/a.jpg',
            'media_type' => 'image', 'processing_state' => 'ready',
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'id' => $videoId, 'site_id' => $siteId, 'user_id' => $pro->id,
            'pool' => 'content', 'path' => 'videos/content/a.mp4',
            'media_type' => 'video', 'processing_state' => 'ready',
            'sort_order' => 0, 'is_active' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $videoId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/content/opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(2);
    expect($items[0]['id'])->toBe($videoId);    // sort_order=0
    expect($items[0]['kind'])->toBe('video');
    expect($items[1]['id'])->toBe($imageId);    // sort_order=1
    expect($items[1]['kind'])->toBe('image');
});
```

- [ ] **Step 3: Add filtering exclusion tests**

```php
it('excludes content-pool media that is not ready / not active / soft-deleted / wrong pool', function () {
    $pro = seedIndividualProfile('cmedia-filter');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    $base = [
        'site_id' => $siteId, 'user_id' => $pro->id, 'media_type' => 'image',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ];

    DB::connection('pgsql')->table('site.site_media')->insert([
        // Excluded: processing_state != ready
        array_merge($base, [
            'id' => (string) Str::uuid(),
            'pool' => 'content', 'path' => 'a.jpg',
            'processing_state' => 'processing', 'is_active' => 1,
        ]),
        // Excluded: is_active = false
        array_merge($base, [
            'id' => (string) Str::uuid(),
            'pool' => 'content', 'path' => 'b.jpg',
            'processing_state' => 'ready', 'is_active' => 0,
        ]),
        // Excluded: soft-deleted
        array_merge($base, [
            'id' => (string) Str::uuid(),
            'pool' => 'content', 'path' => 'c.jpg',
            'processing_state' => 'ready', 'is_active' => 1,
            'deleted_at' => now()->toDateTimeString(),
        ]),
        // Excluded: gallery pool (wrong pool)
        array_merge($base, [
            'id' => (string) Str::uuid(),
            'pool' => 'gallery', 'path' => 'd.jpg',
            'processing_state' => 'ready', 'is_active' => 1,
        ]),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toBeArray()->toBeEmpty();
});
```

- [ ] **Step 4: Add the null-site guard test**

```php
it('returns empty when the site is null', function () {
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia(null);
    expect($items)->toBeArray()->toBeEmpty();
});
```

- [ ] **Step 5: Add edge-case test — video with missing poster**

```php
it('handles content-pool video with no poster artifact (poster=null)', function () {
    $pro = seedIndividualProfile('cmedia-no-poster');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $pro->id,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/np.mp4', 'duration_ms' => 4200,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/np-opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['kind'])->toBe('video');
    expect($items[0]['poster'])->toBeNull();   // missing artifact → null, not crash
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
});
```

- [ ] **Step 6: Add edge-case test — video with only optimized variant (no maximized)**

```php
it('returns url_hd=null for content-pool video with only optimized variant', function () {
    $pro = seedIndividualProfile('cmedia-opt-only');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId, 'site_id' => $siteId, 'user_id' => $pro->id,
        'pool' => 'content', 'media_type' => 'video',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => 1,
        'path' => 'videos/oo.mp4', 'duration_ms' => 3000,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'mp4',
        'disk' => 'media', 'path' => 'videos/oo-opt.mp4', 'mime' => 'video/mp4',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $site = \App\Models\Core\Site\Site::query()->findOrFail($siteId);
    $items = app(\App\Services\PublicSite\SitepageDataResolverService::class)->getContentMedia($site);

    expect($items)->toHaveCount(1);
    expect($items[0]['url'])->toBeString()->not->toBeEmpty();
    expect($items[0]['url_hd'])->toBeNull();
});
```

- [ ] **Step 7: Run the new tests, confirm all pass**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'getContentMedia\|projects content-pool videos\|interleaves content-pool\|excludes content-pool\|returns empty when the site is null\|no poster artifact\|only optimized variant'
```

Expected: 7 tests pass (including the one from Task 3). If any fail, the resolver behaviour diverges from the spec — fix in the resolver, not the test.

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
git commit -m "test(public-profile): cover getContentMedia video, mixed, filtering, edge cases

Locks in the polymorphic projection (video kind + poster + duration_ms),
sort_order interleaving across kinds, the four exclusion rules
(is_active=false, processing_state != ready, soft-deleted, wrong pool),
plus two edge cases (missing poster → null, optimized-only → url_hd=null)
before the IndividualProfileResource shape migration."
```

---

## Task 5: Promote to top-level camelCase `designMedia` — drop `content_images`

**Goal:** Replace `profile.content_images` with top-level `designMedia` in the resource. The wire shape is **camelCase** to match every other top-level field and engine output. A new `buildDesignMedia` method in the builder remaps the resolver's snake_case to wire camelCase. Update the three existing tests that asserted on `profile.content_images`.

**Files:**
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (add `buildDesignMedia`, update `build`, update class-level PHPDoc)
- Modify: `app/Http/Resources/PublicSite/IndividualProfileResource.php` (`toArray` + PHPDoc + `$sections` array typedef)
- Modify: `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php` (3 existing tests + 1 `toHaveKeys` assertion)

- [ ] **Step 1: Update the existing tests to assert the new camelCase wire shape (failing first)**

In `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`:

**1a.** Find the `toHaveKeys` block in the first test (`'returns 200 with the skeleton-system envelope shape for an individual'`, around line 126) and remove `'content_images'` from the array. Then add a top-level `designMedia` assertion:

```php
// Before:
expect($profile)->toHaveKeys([
    'handle', 'displayName',
    'bio', 'gallery', 'links', 'services', 'document', 'newsletter',
    'content_images',
]);

// After:
expect($profile)->toHaveKeys([
    'handle', 'displayName',
    'bio', 'gallery', 'links', 'services', 'document', 'newsletter',
]);
// designMedia is a top-level sibling of designKit, not a profile field.
expect($data)->toHaveKey('designMedia');
expect($data['designMedia'])->toBeArray();
```

**1b.** Replace the test `'surfaces content-pool site_media as profile.content_images[]'` (around line 289) with:

```php
it('surfaces content-pool site_media as top-level designMedia[] in camelCase', function () {
    $pro = seedIndividualProfile('content1');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/original.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'alt_text' => 'Studio shot',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content1')->assertOk()->json('data');

    expect($data)->toHaveKey('designMedia');
    expect($data['designMedia'])->toBeArray()->toHaveCount(1);
    // Wire shape is camelCase, matching gallery[i] and every engine output.
    expect($data['designMedia'][0])->toHaveKeys([
        'id', 'sortOrder', 'kind', 'url', 'urlHd', 'alt', 'caption', 'poster', 'durationMs',
    ]);
    expect($data['designMedia'][0]['kind'])->toBe('image');
    expect($data['designMedia'][0]['alt'])->toBe('Studio shot');
    expect($data['designMedia'][0]['sortOrder'])->toBe(0);
    expect($data['profile'])->not->toHaveKey('content_images');
});
```

**1c.** Replace `'omits soft-deleted content_images'` (around line 316) with:

```php
it('omits soft-deleted content-pool media from designMedia', function () {
    $pro = seedIndividualProfile('content2');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/deleted.jpg',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => 1,
        'deleted_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content2')->assertOk()->json('data');
    expect($data['designMedia'])->toBeArray()->toBeEmpty();
});
```

**1d.** Replace `'omits processing-state != ready content_images'` (around line 339) with:

```php
it('omits processing-state != ready content-pool media from designMedia', function () {
    $pro = seedIndividualProfile('content3');
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $pro->id)->value('id');

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'user_id' => $pro->id,
        'pool' => 'content',
        'path' => 'images/content/processing.jpg',
        'media_type' => 'image',
        'processing_state' => 'processing',
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $data = $this->getJson('/api/public/profiles/content3')->assertOk()->json('data');
    expect($data['designMedia'])->toBeArray()->toBeEmpty();
});
```

- [ ] **Step 2: Run the updated tests, confirm they fail**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'designMedia\|skeleton-system envelope'
```

Expected: FAIL — `data.designMedia` key is missing (the resource doesn't expose it yet).

- [ ] **Step 3: Add the `buildDesignMedia` builder method**

In `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`, add a new private method (place it near `buildGallery` for symmetry — they're the same pattern):

```php
/**
 * Design media engine — DesignMediaItem[] (empty array when nothing in pool).
 *
 * Remaps the resolver's snake_case keys (sort_order, alt_text, url_hd,
 * duration_ms) to the camelCase wire shape per the §5 wire convention.
 * Mirrors buildGallery's pattern — same projection style across the two
 * polymorphic-media surfaces.
 *
 * @return list<array{id: string, sortOrder: int, kind: string, url: string, urlHd: string|null, alt: string|null, caption: string|null, poster: string|null, durationMs: int|null}>
 */
private function buildDesignMedia(?Site $site): array
{
    $items = $this->resolver->getContentMedia($site);

    return array_values(array_map(static fn (array $item): array => [
        'id' => (string) ($item['id'] ?? ''),
        'sortOrder' => (int) ($item['sort_order'] ?? 0),
        'kind' => (string) ($item['kind'] ?? 'image'),
        'url' => (string) ($item['url'] ?? ''),
        'urlHd' => $item['url_hd'] ?? null,
        'alt' => $item['alt_text'] ?? null,
        'caption' => $item['caption'] ?? null,
        'poster' => $item['poster'] ?? null,
        'durationMs' => $item['duration_ms'] ?? null,
    ], $items));
}
```

- [ ] **Step 4: Wire `design_media` through `build()`**

In the same file, update the `build` method (around line 65–85 on origin/development):

```php
public function build(User $pro, ?Site $site): array
{
    $sections = $this->resolver->loadSections($site);
    $booking = $this->resolver->getBooking($site, $sections);

    return (new IndividualProfileResource($pro, [
        'site_id' => $site?->id,
        'design_kit' => $this->loadDesignKit($site),
        'design_media' => $this->buildDesignMedia($site),
        'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
        'public_config' => $this->buildPublicConfig(),
        // Engine outputs — flat, camelCase, no envelope wrapper.
        'bio' => $this->buildBio($pro, $sections),
        'gallery' => $this->buildGallery($site, $sections),
        'links' => $this->buildLinks($site, $booking),
        'services' => $this->buildServices($site, $pro->id, $sections),
        'document' => $this->buildDocument($site),
        'newsletter' => $this->buildNewsletter($sections),
        'workplace' => $this->buildWorkplace($site, $sections),
    ]))->resolve();
}
```

The change: `'content_images' => $this->resolver->getContentImages($site)` is **removed**, and `'design_media' => $this->buildDesignMedia($site)` is **added** (positioned with the other design-layer keys: `design_kit`, `design_media`, `skeleton_id`).

Update the class-level PHPDoc at the top of the file (around line 21–48). Find this section in the payload-shape comment:

```
 *       newsletter: NewsletterData | null,
 *       content_images: [...]  // retained for compat
 *     },
 *     designKit: { colors: {...}, typography: {...}, ... },
```

Replace it with:

```
 *       newsletter: NewsletterData | null,
 *       workplace: WorkplaceData | null,
 *     },
 *     designKit: { colors: {...}, typography: {...}, ... },
 *     designMedia: DesignMediaItem[],
```

- [ ] **Step 5: Wire `designMedia` through the resource**

In `app/Http/Resources/PublicSite/IndividualProfileResource.php`:

**5a.** Update the `@param` array shape (around line 42–54) — remove `content_images` and add `design_media`:

```php
/**
 * @param  array{
 *     site_id?: string|null,
 *     design_kit?: array<string, mixed>,
 *     design_media?: list<array<string, mixed>>,
 *     skeleton_id?: string|null,
 *     public_config?: array<string, mixed>,
 *     bio?: array<string, mixed>|null,
 *     gallery?: list<array<string, mixed>>,
 *     links?: list<array<string, mixed>>,
 *     services?: list<array<string, mixed>>,
 *     document?: array<string, mixed>|null,
 *     newsletter?: array<string, mixed>|null,
 *     workplace?: array<string, mixed>|null,
 * }  $sections
 */
```

**5b.** Update `toArray` (around line 80–120). Replace the `return` block with:

```php
return [
    // Content data — the profile itself + engine outputs. camelCase
    // keys for engine fields per spec §5 wire convention.
    'profile' => [
        'handle' => $this->handle,
        'displayName' => $this->display_name,
        'site_id' => $this->sections['site_id'] ?? null,

        // Engine outputs (phase 8).
        'bio' => $this->sections['bio'] ?? null,
        'gallery' => $this->sections['gallery'] ?? [],
        'links' => $this->sections['links'] ?? [],
        'services' => $this->sections['services'] ?? [],
        'document' => $this->sections['document'] ?? null,
        'newsletter' => $this->sections['newsletter'] ?? null,
        'workplace' => $this->sections['workplace'] ?? null,
    ],

    // Per-user design kit. Partial — only contains stored (non-null)
    // columns from site.design_kits, mapped from flat snake_case DB
    // columns to nested camelCase groups. partna-pages merges this with
    // DESIGN_KIT_DEFAULTS (code-side) before passing to the skeleton.
    'designKit' => $designKitOut,

    // Design-layer media — polymorphic image/video items ordered by
    // sortOrder. The skeleton paints with these (backgrounds, section
    // covers, decorative imagery). Always an array. Wire shape is
    // camelCase per §5 convention; the builder's buildDesignMedia()
    // remaps the resolver's snake_case before it lands here. See spec
    // docs/superpowers/specs/2026-05-30-design-media-promotion-design.md.
    'designMedia' => $this->sections['design_media'] ?? [],

    // Which of the four code-side skeletons to render. One of
    // 'skeleton-1', 'skeleton-2', 'skeleton-3', 'skeleton-4'.
    'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',

    // Platform-wide knobs the skeleton needs at render time (analytics
    // endpoint, etc.). Always an object.
    'publicConfig' => $publicConfigOut,
];
```

**5c.** Update the class-level PHPDoc (around line 9–34). Replace the payload-shape bullet for `profile` and add `designMedia`:

```
 *   - `profile` — content (engine fields + base profile)
 *   - `designKit` — per-user design vars (nested camelCase), partial
 *   - `designMedia` — content-pool media (polymorphic image/video, camelCase, ordered)
 *   - `skeletonId` — picks which code-side skeleton renders
 *   - `publicConfig` — analytics endpoint + platform-wide keys
```

And under `INTENTIONAL EXCLUSIONS`, add a line:

```
 *   - Legacy `profile.content_images` — promoted to top-level `designMedia`
 *     and made polymorphic (images + videos, camelCase). See spec 2026-05-30.
```

- [ ] **Step 6: Run the updated tests, confirm they pass**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --filter 'designMedia\|skeleton-system envelope'
```

Expected: PASS — `designMedia` is now exposed at the top level in camelCase, `content_images` is no longer on `profile`.

- [ ] **Step 7: Run the full feature test file**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
```

Expected: all green. Watch for stragglers — any test that still asserts on `content_images` is a miss that needs updating.

- [ ] **Step 8: Run the two other test files known to mention `content_images` — confirm they're no-ops**

```bash
vendor/bin/pest tests/Feature/Cache/PublicCacheMiddlewareTest.php tests/Feature/Documents/DocumentPayloadProjectionTest.php
```

Expected: **all green without changes.** Verified on `origin/development`: both files reference `content_images` as a key on the **DB-view shape**, not the resource shape. `PublicCacheMiddlewareTest.php:94` uses it inside a `Cache::put` fixture mirroring the `public_site_payload` view. `DocumentPayloadProjectionTest.php:18/43/60` passes it to `SiteCacheService::resolveImageVariantUrlsInSite` whose input is the view's array. The DB view is not changing in this PR, so these tests should stay green untouched. If they break, something else is wrong — investigate, don't blindly rename.

- [ ] **Step 9: Commit**

```bash
git add app/Services/PublicSite/IndividualProfilePayloadBuilder.php app/Http/Resources/PublicSite/IndividualProfileResource.php tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
git commit -m "feat(public-profile): promote content media to top-level designMedia

profile.content_images (image-only legacy field) is removed from the
public profile payload. Replaced by top-level designMedia — polymorphic
image+video envelope sibling of designKit, camelCase per the §5 wire
convention. New buildDesignMedia() in IndividualProfilePayloadBuilder
remaps the resolver's snake_case to wire camelCase (mirrors buildGallery).

Wire shape: { id, sortOrder, kind, url, urlHd, alt, caption, poster,
durationMs } per spec
docs/superpowers/specs/2026-05-30-design-media-promotion-design.md."
```

---

## Task 6: Delete legacy `getContentImages` + final cleanup

**Goal:** Remove the temporarily-kept `getContentImages` resolver method and the redundant Task 3 unit test. The `designMedia` wire shape now has full coverage from Tasks 4 + 5.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php`
- Modify: `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`

- [ ] **Step 1: Confirm nothing else calls `getContentImages`**

```bash
grep -rn "getContentImages" app/ tests/
```

Expected: only the resolver method definition. If anything else references it, follow the chain and update the caller in this task before deletion.

- [ ] **Step 2: Delete `getContentImages`**

Remove the entire `getContentImages` method (the `@deprecated` block added in Task 3) from `app/Services/PublicSite/SitepageDataResolverService.php`.

- [ ] **Step 3: Delete the redundant Task-3 unit test**

The test `'exposes content-pool images via the resolver getContentMedia method'` (added in Task 3) overlaps with the broader feature tests added in Tasks 4 and 5. Keep one or the other; recommendation is to keep the Task-4/Task-5 tests since they exercise the full wire shape end-to-end, and delete the Task-3 test.

Find and delete the Task-3 test `it('exposes content-pool images via the resolver getContentMedia method', ...)` in `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`.

- [ ] **Step 4: Run the full feature test file one final time**

```bash
vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
```

Expected: all green. Test count should be down by exactly 1 (the deleted Task-3 test).

- [ ] **Step 5: Run the full test suite to catch repository-wide regressions**

```bash
vendor/bin/pest
```

Expected: all green. If anything fails outside the files this PR touches, investigate — it's likely a stale reference to `content_images` somewhere I missed.

- [ ] **Step 6: Lint-check the touched files (Pint, scoped to changed lines only — repo baseline isn't clean)**

```bash
vendor/bin/pint --test app/Services/PublicSite/SitepageDataResolverService.php app/Services/PublicSite/IndividualProfilePayloadBuilder.php app/Http/Resources/PublicSite/IndividualProfileResource.php app/Services/Media/VideoVariantService.php
```

Expected: zero "would change" output for the lines you touched. If Pint flags pre-existing style issues, leave them — repo baseline isn't Pint-clean and a wholesale fix is out of scope.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PublicSite/SitepageDataResolverService.php tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php
git commit -m "chore(public-profile): drop legacy getContentImages resolver

getContentMedia is the only caller path now. The temporary @deprecated
shim from the migration commit is gone; the redundant resolver-level
unit test is collapsed into the feature-level designMedia coverage.

Closes the design-media promotion spec."
```

---

## Task 7: Push and open the PR

- [ ] **Step 1: Push the branch**

```bash
git push -u origin feat/design-media-promotion
```

- [ ] **Step 2: Open the PR via `gh`**

```bash
gh pr create --title "feat(public-profile): promote content media to top-level designMedia (image + video, camelCase)" --body "$(cat <<'EOF'
## Summary

- Replaces image-only `profile.content_images` (snake_case wart) with polymorphic top-level `designMedia` (camelCase, image + video) on the public profile payload.
- Extracts a shared `SitepageDataResolverService::buildMediaItem` helper so the gallery engine and the new `getContentMedia` method project through identical code.
- Adds a `IndividualProfilePayloadBuilder::buildDesignMedia` that mirrors `buildGallery`'s pattern: call resolver (snake_case), remap to camelCase for the wire.
- Fixes a pre-existing cache-invalidation gap in `VideoVariantService::processVariants` — mass-update bypassed `SiteMediaObserver` so the parent Site wasn't touched and Cloudflare wasn't push-purged after video processing finished. Replaced with a model load + save() so the observer chain fires.
- No DB migration. No compat fallback — coordinated lockstep deploy with the frontend skeleton change.

Wire shape: `{ id, sortOrder, kind, url, urlHd, alt, caption, poster, durationMs }` — camelCase, matches every other top-level field and engine output.

## Spec

- [`docs/superpowers/specs/2026-05-30-design-media-promotion-design.md`](docs/superpowers/specs/2026-05-30-design-media-promotion-design.md)

## Plan

- [`docs/superpowers/plans/2026-05-30-design-media-promotion.md`](docs/superpowers/plans/2026-05-30-design-media-promotion.md)

## Test plan

- [ ] All Pest tests pass: `vendor/bin/pest`
- [ ] `IndividualProfileControllerTest` — new `designMedia` tests cover image, video, mixed sort_order, four exclusion rules, missing poster, optimized-only
- [ ] `VideoReadyCacheInvalidationTest` — asserts `$site->updated_at` rolls when video variant processing finishes
- [ ] Manual smoke: hit `/api/public/profiles/{handle}` for a profile with one image and one video in the content pool — verify `data.designMedia` ordered by sortOrder with correct camelCase keys (`kind`, `urlHd`, `durationMs`)
- [ ] Confirm frontend PR is ready to land in lockstep — skeleton consumes `data.designMedia` instead of `data.profile.content_images`
- [ ] Confirm no other backend repo (workers, cron) reads `profile.content_images` from the payload

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Note the PR URL for Josh**

The PR URL is the deliverable. Send it to Josh as the close-out message.

---

## Self-Review (informational)

**Spec coverage:**

| Spec section | Task |
|---|---|
| §4.1 wire shape — **camelCase** `{id, sortOrder, kind, url, urlHd, alt, caption, poster, durationMs}` | Task 1 (helper snake_case internal), Task 5 (camelCase wire transform + tests), Task 4 (resolver-internal tests) |
| §4.2 resource shape (`designMedia` top-level, drop `content_images`) | Task 5 |
| §4.3 resolver (`getContentMedia` method) | Task 3 |
| §4.4 shared `buildMediaItem` helper | Task 1 |
| §4.5 builder change (`design_media` snake key inbound, `buildDesignMedia` camelCase outbound) | Task 5 |
| §4.6 naming rationale | Reflected in PHPDoc updates in Task 5 + Task 6 |
| §5 security (filtering, no PII fields exposed) | Task 4 (filtering tests), Task 5 (resource doesn't expose path/bucket/filename) |
| §6 performance / caching | Task 2 closes the pre-existing video-ready cache-invalidation gap. Observer chain (no new code) covers create/delete/reorder. |
| §7 test matrix | Task 3 + Task 4 + Task 5 |
| §8 rollout (lockstep, no compat) | Task 5 (no compat layer added), Task 7 PR body (test plan item for frontend lockstep) |

**No placeholders:** every step has exact code, exact commands, and exact expected output.

**Type consistency:** `buildMediaItem` returns snake_case throughout Tasks 1, 3, 4. `buildDesignMedia` returns camelCase in Task 5. The resolver method `getContentMedia` is the only public addition; `buildGalleryItem` keeps its signature. `markReady` is the only new public method on `VideoVariantService`.
