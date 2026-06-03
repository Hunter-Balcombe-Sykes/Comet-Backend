# Design Media Promotion — PR Review

**Hand this to a fresh Claude Code session on the `development` branch after #158 is merged.**

This PR replaces `profile.content_images` (legacy image-only snake_case field) with a polymorphic top-level `designMedia` field (camelCase, image + video). It also fixes a cache-invalidation bug in video processing. This review checks the implementation is correct, complete, and safe before the frontend consumes the new wire shape.

---

## Pre-flight

```bash
git fetch origin && git checkout development && git pull origin development
composer test
```

All tests must be green (4 pre-existing failures in `CloudflarePurgeServiceTest` / `HandleAliasLifecycleTest` are acceptable — they predate this PR). If anything else is red, stop.

---

## Files changed

| File | What changed |
|------|-------------|
| `app/Services/PublicSite/SitepageDataResolverService.php` | `buildMediaItem` extracted from `buildGalleryItem`; new `getContentMedia`; old `getContentImages` deleted |
| `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` | New `buildDesignMedia()`; `content_images` removed from `build()`; class docblock updated |
| `app/Http/Resources/PublicSite/IndividualProfileResource.php` | `designMedia` added as top-level; `content_images` removed from `profile` block |
| `app/Services/Media/VideoVariantService.php` | `processVariants` now calls `markReady()` (model load + save) instead of mass-update |
| `tests/Feature/Media/VideoReadyCacheInvalidationTest.php` | New — asserts site.updated_at advances after markReady |
| `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php` | Three `content_images` tests replaced with `designMedia` equivalents; six resolver/edge-case tests added |

---

## Review dimensions

### 1. Wire shape contract

Read `app/Http/Resources/PublicSite/IndividualProfileResource.php::toArray`. Verify:

- `designMedia` is a **top-level key** (sibling of `designKit`, `skeletonId`, `publicConfig`) — NOT nested inside `profile`.
- Each item in `designMedia[]` has exactly these camelCase keys: `id`, `sortOrder`, `kind`, `url`, `urlHd`, `alt`, `caption`, `poster`, `durationMs`.
- `profile` no longer contains `content_images`.
- Empty `designMedia` serialises as `[]` (array), not `{}` — correct because it's a list, not an object.

### 2. Resolver correctness

Read `SitepageDataResolverService::getContentMedia` and `buildMediaItem`. Verify:

- Only rows with `pool='content'`, `is_active=true`, `processing_state='ready'`, no `deleted_at` are returned.
- Videos use `artifact_type='mp4'` variants for `url`/`url_hd` and `artifact_type='poster'` for `poster`. Images use `artifact_type='webp'` variants.
- `url=''` items are filtered out (items with no resolvable URL don't surface).
- `buildGalleryItem` still delegates to `buildMediaItem` — gallery wire shape unchanged.

### 3. Cache-invalidation fix

Read `VideoVariantService::markReady`. Verify:

- It does a `SiteMedia::query()->find($mediaId)` then `$media->save()` — NOT a mass update.
- `save()` fires `SiteMediaObserver::saved` → `$site->touch()` → `SiteObserver` → `CloudflareCachePurgeJob`. Trace this chain in `app/Observers/Core/SiteMediaObserver.php`.
- `processVariants` calls `$this->markReady(...)` where the mass-update used to be.
- The `VideoReadyCacheInvalidationTest` passes.

### 4. No PII leaks

Confirm `designMedia[i]` items never expose: storage `path`, `bucket/disk`, `original_filename`, `user_id`, `site_id`, `processing_error`. All of these live on the SiteMedia row — check `buildMediaItem` and `buildDesignMedia` to confirm only the projected keys land in the output.

### 5. Backward compat / breaking change scope

- `profile.content_images` is gone. This is a **breaking change** — lockstep with the frontend is required. Confirm no other Laravel code (commands, jobs, other controllers) reads `content_images` from the payload array: `grep -rn "content_images" app/`.
- `getContentImages` method is deleted. Confirm no callers: `grep -rn "getContentImages" app/`.

### 6. Test completeness

Run `vendor/bin/pest tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php --verbose` and confirm:
- Tests cover: image item, video item, mixed sort_order, four exclusion rules (not-ready, not-active, soft-deleted, wrong pool), missing poster, optimized-only variant, null site.
- `content_images` does NOT appear in any assertion (old shape fully replaced).

---

## Go / No-go

Write a one-paragraph verdict: is this safe to ship in lockstep with the frontend skeleton change? Note any issues with severity (P0 = block, P1 = fix before ship, P2 = follow-up OK).
