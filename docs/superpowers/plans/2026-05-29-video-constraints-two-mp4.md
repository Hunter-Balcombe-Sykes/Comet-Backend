# Video Constraints + Two-MP4 Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Slim the video pipeline to two plain MP4 tiers (720p + 1080p, audio kept) the frontend picks between, capped at 30s / 200 MB, dropping the unused HLS + adaptive-playlist machinery.

**Architecture:** Today one upload fans out to ~17–107 files (original + 2 MP4 + 2 HLS segment sets + 2 per-variant playlists + adaptive master + poster), of which only the *optimized HLS* + poster are ever served. We remove HLS packaging and the adaptive master playlist entirely, keep both MP4 tiers as the deliverables, and reshape the public payload so each gallery video carries **both** tier URLs (`url` = optimized 720p for autoplay, `urlHd` = maximized 1080p for tap/fullscreen). The original source is kept (R2 storage is ~$0.015/GB-mo with no egress — cheap insurance against re-encode generational loss). Constraints move to 30s max duration and 200 MB max upload; the ≤4K resolution gate stays (it bounds *decode cost*, not file size).

**Tech Stack:** Laravel 12, PHP 8.2, ffmpeg/ffprobe (shelled out), Pest 4, R2 (`media` disk).

---

## Design Decisions (locked)

| Decision | Value | Why |
|----------|-------|-----|
| Max duration | **30s** | Short autoplay clips; enforced at HTTP boundary via `probeAndValidate` |
| Max upload | **200 MB** | Generous for a 30s 4K phone clip; rejects pro-format (ProRes) monsters |
| Tiers | **optimized 720p + maximized 1080p MP4** | Frontend picks: 720p autoplays in-grid, 1080p loads on tap/fullscreen |
| Audio | **kept** (AAC 128k / 192k) | Some clips may be tap-to-unmute (testimonials) |
| HLS + adaptive playlist | **removed** | Never served (resolver always preferred `optimized`); pointless for ≤30s clips |
| Original | **kept** | R2 is cheap + no egress; insurance against re-encode generational loss |
| ≤4K resolution gate | **kept** | Bounds decode cost/memory (independent of the size cap); phones shoot 4K |
| 4K source ≤200 MB | **accepted** | A ~10s 4K clip is ~70 MB — under the cap — and welcome. It's downscaled to ≤1080p on output (slightly cleaner 1080p via supersampling). Rejecting it would 422 normal phone footage for zero delivery benefit; only the kept original costs more (pennies on R2). |
| AVI container | **dropped from accepted mimes** | Almost never carries h264/hevc/vp9 → passes size/mime gate, then fails codec on the worker = wasted upload |

**Output sizes (worst case @ 30s):** optimized ~8 MB, maximized ~19.5 MB, poster ~0.1 MB. Delivered per autoplay = ~8 MB.

## Wire-shape change (frontend coordination required)

The public profile payload's gallery item changes from a single `url` (HLS-preferred) to two MP4 URLs. **Additive** — `urlHd` is new; existing `url` now points at the optimized MP4 instead of the HLS playlist.

Before:
```json
{ "url": "<optimized HLS .m3u8>", "kind": "video", "poster": "...jpg", "durationMs": 12000, "alt": null, "caption": null }
```
After:
```json
{ "url": "<optimized 720p .mp4>", "urlHd": "<maximized 1080p .mp4>", "kind": "video", "poster": "...jpg", "durationMs": 12000, "alt": null, "caption": null }
```

> **Frontend (separate repo `partna-frontend` / `partna-pages`, NOT edited here):** the gallery component must (1) switch from an HLS player to a plain `<video>` element pointing at `url`, and (2) optionally swap to `urlHd` on expand/fullscreen. For images, `urlHd` is `null`. Flag this to the frontend dev — do not attempt to edit the frontend from a backend session.

## File Structure

| File | Change |
|------|--------|
| `config/partna.php` | Duration default 300→30, upload default 512000→204800; comment touch-ups. Variant defs unchanged. |
| `app/Services/Media/VideoVariantService.php` | Remove HLS packaging step, adaptive-playlist build+upload, `packageHls()`, `buildAdaptivePlaylist()`. Document the 4K gate's purpose. |
| `app/Services/PublicSite/SitepageDataResolverService.php` | `buildGalleryItem` video branch: emit `url` (optimized MP4) + `urlHd` (maximized MP4); drop HLS/adaptive preference. |
| `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` | `buildGallery`: pass through `urlHd`; update return-shape annotation. |
| `app/Http/Requests/Api/User/Uploads/UploadImageRequest.php` | Drop `avi` from `mimes`; fix size/mime error messages. |
| `.env.example` | Update `PARTNA_VIDEO_MAX_UPLOAD_KB` / `PARTNA_VIDEO_MAX_DURATION_SECONDS` documented defaults. |
| `tests/Feature/Media/VideoConstraintsTest.php` | NEW — config values + request validation. |
| `tests/Feature/PublicSite/GalleryVideoPayloadTest.php` | NEW — two-tier payload shape, no HLS. |

---

### Task 1: Tighten the config constraints

**Files:**
- Modify: `config/partna.php:907-910`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Media/VideoConstraintsTest.php`:

```php
<?php

use function Pest\Laravel\artisan;

it('caps video uploads at 200 MB and 30 seconds', function () {
    expect(config('partna.video_max_upload_size'))->toBe(204800)   // 200 MB in KB
        ->and(config('partna.video_max_duration_seconds'))->toBe(30);
});

it('keeps both 720p and 1080p MP4 variant tiers', function () {
    $variants = config('partna.video_variants');

    expect($variants)->toHaveKeys(['optimized', 'maximized'])
        ->and($variants['optimized']['resolution'])->toBe('1280x720')
        ->and($variants['maximized']['resolution'])->toBe('1920x1080');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoConstraintsTest`
Expected: FAIL — `video_max_upload_size` is 512000, `video_max_duration_seconds` is 300.

- [ ] **Step 3: Change the config defaults**

In `config/partna.php`, change lines 909–910 from:

```php
    'video_max_upload_size' => (int) env('PARTNA_VIDEO_MAX_UPLOAD_KB', env('SIDEST_VIDEO_MAX_UPLOAD_KB', 512000)), // 500 MB
    'video_max_duration_seconds' => (int) env('PARTNA_VIDEO_MAX_DURATION_SECONDS', env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 300)), // 5 min
```

to:

```php
    'video_max_upload_size' => (int) env('PARTNA_VIDEO_MAX_UPLOAD_KB', env('SIDEST_VIDEO_MAX_UPLOAD_KB', 204800)), // 200 MB
    'video_max_duration_seconds' => (int) env('PARTNA_VIDEO_MAX_DURATION_SECONDS', env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 30)), // 30s — short autoplay clips
```

Then update the comment block at lines 904–905 from:

```php
    | video_variants define the two MP4 output tiers.  HLS streams are
    | packaged from these MP4 files (no extra re-encode).
```

to:

```php
    | video_variants define the two MP4 output tiers delivered directly to
    | the player: optimized (720p, autoplay) + maximized (1080p, on-demand).
    | The frontend chooses which to load by context.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=VideoConstraintsTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add config/partna.php tests/Feature/Media/VideoConstraintsTest.php
git commit -m "feat(video): cap uploads at 200MB/30s, document MP4-only tiers"
```

---

### Task 2: Drop AVI + fix upload validation messages

**Files:**
- Modify: `app/Http/Requests/Api/User/Uploads/UploadImageRequest.php:36-42, 88-96`
- Test: `tests/Feature/Media/VideoConstraintsTest.php`

- [ ] **Step 1: Add the failing validation test**

Append to `tests/Feature/Media/VideoConstraintsTest.php`:

```php
use App\Http\Requests\Api\User\Uploads\UploadImageRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\UploadedFile;

it('rejects avi and oversized videos, accepts mp4 within cap', function () {
    $rules = (new UploadImageRequest())->rules();

    // mimes rule no longer lists avi
    $videoRules = collect($rules['video'])->first(fn ($r) => is_string($r) && str_starts_with($r, 'mimes:'));
    expect($videoRules)->toBe('mimes:mp4,mov,webm');

    // size rule reflects the 200 MB cap (204800 KB)
    $maxRule = collect($rules['video'])->first(fn ($r) => is_string($r) && str_starts_with($r, 'max:'));
    expect($maxRule)->toBe('max:204800');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VideoConstraintsTest`
Expected: FAIL — current `mimes` is `mimes:mp4,mov,webm,avi`.

- [ ] **Step 3: Update the rule + messages**

In `UploadImageRequest.php`, change the `video` rule (line 40) from:

```php
                'mimes:mp4,mov,webm,avi',
```

to:

```php
                'mimes:mp4,mov,webm',
```

Then change the `video.mimes` message (line 96) from:

```php
            'video.mimes' => 'Video must be MP4, MOV, WebM, or AVI.',
```

to:

```php
            'video.mimes' => 'Video must be MP4, MOV, or WebM.',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=VideoConstraintsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/User/Uploads/UploadImageRequest.php tests/Feature/Media/VideoConstraintsTest.php
git commit -m "feat(video): drop AVI (codec mismatch footgun), fix upload messages"
```

---

### Task 3: Reshape the public payload to two MP4 tiers

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:149-182`
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:121-137`
- Test: `tests/Feature/PublicSite/GalleryVideoPayloadTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PublicSite/GalleryVideoPayloadTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\MediaVariant;
use App\Services\PublicSite\SitepageDataResolverService;

it('emits both optimized and maximized MP4 urls for a video, no HLS', function () {
    $media = new SiteMedia([
        'media_type' => SiteMedia::MEDIA_TYPE_VIDEO,
        'alt_text' => 'demo',
        'caption' => null,
        'duration_ms' => 12000,
    ]);
    $media->id = 'vid-1';

    // Build the relation in memory — no DB / ffmpeg needed.
    $media->setRelation('mediaVariants', collect([
        new MediaVariant(['variant_key' => 'optimized', 'artifact_type' => 'mp4', 'path' => 'videos/x/optimized_a.mp4', 'disk' => 'media']),
        new MediaVariant(['variant_key' => 'maximized', 'artifact_type' => 'mp4', 'path' => 'videos/x/maximized_b.mp4', 'disk' => 'media']),
        new MediaVariant(['variant_key' => 'poster', 'artifact_type' => 'poster', 'path' => 'videos/x/poster_c.jpg', 'disk' => 'media']),
    ]));

    $resolver = app(SitepageDataResolverService::class);
    $item = $resolver->buildGalleryItem($media);

    expect($item['kind'])->toBe('video')
        ->and($item['url'])->toContain('optimized_a.mp4')
        ->and($item['url_hd'])->toContain('maximized_b.mp4')
        ->and($item['poster'])->toContain('poster_c.jpg')
        ->and($item['duration_ms'])->toBe(12000);
});
```

> NOTE: `MediaVariant->url` (accessor `getUrlAttribute`, `app/Models/Core/MediaVariant.php:107`) has a fast path that reads `config("filesystems.disks.{$disk}.url")` and concatenates `baseUrl/path` — no S3 client init. So set `config(['filesystems.disks.media.url' => 'https://cdn.test']);` at the top of the test and use `disk => 'media'` on the variants; `url` then resolves deterministically to `https://cdn.test/videos/x/optimized_a.mp4` with no network/AWS SDK.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GalleryVideoPayloadTest`
Expected: FAIL — `url` resolves to the HLS playlist (or undefined `url_hd` key).

- [ ] **Step 3: Rewrite the video branch of `buildGalleryItem`**

In `SitepageDataResolverService.php`, replace the video branch (lines 155–182) with:

```php
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
                'url' => $url,
                'url_hd' => $urlHd,
                'alt_text' => $media->alt_text,
                'caption' => $media->caption,
                'kind' => 'video',
                'poster' => $poster,
                'duration_ms' => $media->duration_ms,
            ];
        }
```

Then update the image branch return (lines 188–195) to include `url_hd => null` so the shape is uniform:

```php
        return [
            'url' => $url,
            'url_hd' => null,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'kind' => 'image',
            'poster' => null,
            'duration_ms' => null,
        ];
```

And update the method's return-shape docblock (line 149) to add `url_hd: string|null`.

- [ ] **Step 4: Map `url_hd` → `urlHd` on the wire**

In `IndividualProfilePayloadBuilder.php`, update `buildGallery` (lines 129–136) from:

```php
        return array_values(array_map(static fn (array $item): array => [
            'url' => (string) ($item['url'] ?? ''),
            'alt' => $item['alt_text'] ?? null,
            'caption' => $item['caption'] ?? null,
            'kind' => (string) ($item['kind'] ?? 'image'),
            'poster' => $item['poster'] ?? null,
            'durationMs' => $item['duration_ms'] ?? null,
        ], $items));
```

to:

```php
        return array_values(array_map(static fn (array $item): array => [
            'url' => (string) ($item['url'] ?? ''),
            'urlHd' => $item['url_hd'] ?? null,
            'alt' => $item['alt_text'] ?? null,
            'caption' => $item['caption'] ?? null,
            'kind' => (string) ($item['kind'] ?? 'image'),
            'poster' => $item['poster'] ?? null,
            'durationMs' => $item['duration_ms'] ?? null,
        ], $items));
```

And update the `@return` annotation on line 122 to add `urlHd: string|null`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GalleryVideoPayloadTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PublicSite/SitepageDataResolverService.php app/Services/PublicSite/IndividualProfilePayloadBuilder.php tests/Feature/PublicSite/GalleryVideoPayloadTest.php
git commit -m "feat(video): serve two MP4 tiers (url + urlHd) instead of HLS"
```

---

### Task 4: Remove HLS packaging + adaptive playlist from the pipeline

**Files:**
- Modify: `app/Services/Media/VideoVariantService.php` (remove lines 180–293 HLS/adaptive blocks; remove `packageHls()` 454–473; remove `buildAdaptivePlaylist()` 520–559)
- Modify: `app/Models/Core/MediaVariant.php:19-25` (docblock documents HLS artifact types — must be updated)

> Transcoding shells out to ffmpeg and is verified manually (Task 5), not in CI. This task is a mechanical removal verified by the full suite still passing + manual upload.
>
> **Do NOT touch `deleteVariants()` or its tests.** `MediaJobReliabilityTest` (lines 163–277) inserts `hls_playlist` rows + `hls/optimized/*.ts` fixtures and asserts `deleteVariants` removes them. That cleanup path stays format-agnostic on purpose: videos uploaded *before* this change still have HLS files in R2 that must be deletable. Those tests should keep passing unchanged.

- [ ] **Step 1: Remove the HLS packaging step**

In `processVariants`, delete the entire `// --- 4. Package HLS from each MP4 ---` block (lines 180–190) and the `// --- 5. Adaptive master playlist ... ---` comment (lines 192–193). Also delete `$hlsDirs = [];` initialisation.

- [ ] **Step 2: Remove the HLS upload loop**

Delete the `// Upload HLS segments + playlists` loop (lines 238–272) and the adaptive-playlist build+upload block (lines 274–293) in full. Keep the MP4 upload loop (209–236) and the poster upload (295–313) intact. The `$variantHashes` array is still used by the MP4 loop, so keep it.

- [ ] **Step 3: Remove the now-dead private methods**

Delete `packageHls()` (lines 454–473) and `buildAdaptivePlaylist()` (lines 520–559) entirely. Update the class-top comment (line 41) from:

```php
// V2: Transcodes videos to MP4 + HLS via FFmpeg. Feature-flagged (PARTNA_VIDEO_UPLOADS_ENABLED). Uses dedicated redis_video connection.
```

to:

```php
// V2: Transcodes videos to two MP4 tiers (720p + 1080p) + a poster via FFmpeg.
// Feature-flagged (PARTNA_VIDEO_UPLOADS_ENABLED). Uses dedicated redis_video connection.
```

- [ ] **Step 4: Document the 4K resolution gate's purpose**

Update the constant comment (lines 47–50) from:

```php
    /** 4K ceiling: long edge ≤ 3840px, short edge ≤ 2160px. */
    private const MAX_RESOLUTION_LONG = 3840;
```

to:

```php
    /**
     * 4K ceiling: long edge ≤ 3840px, short edge ≤ 2160px. This guards FFmpeg
     * decode cost/memory (full-res frame buffers), NOT file size — a small but
     * highly-compressed 8K source would otherwise blow up the worker. Output is
     * always downscaled to ≤1080p regardless.
     */
    private const MAX_RESOLUTION_LONG = 3840;
```

- [ ] **Step 5: Update the `MediaVariant` artifact-type docblock**

In `app/Models/Core/MediaVariant.php`, replace the `Videos:` block in the class docblock (lines 19–25) with one that reflects MP4-only output while noting HLS is legacy-but-still-cleaned:

```php
 * Videos (new uploads, 2026-05-29+):
 *   - variant_key='optimized'  + artifact_type='mp4'    → 720p MP4 (autoplay default)
 *   - variant_key='maximized'  + artifact_type='mp4'    → 1080p MP4 (on-demand / fullscreen)
 *   - variant_key='poster'     + artifact_type='poster' → poster JPEG
 *
 * Legacy (pre-2026-05-29) videos may still carry artifact_type='hls_playlist'
 * rows + hls/ segment files; these are no longer produced, but deleteVariants()
 * stays format-agnostic so they remain cleanable.
```

Also update the one-line summary comment (line 41) from `... MP4 video, HLS playlist, poster ...` to `... MP4 video, poster ...`.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: PASS. No test asserts that `processVariants` *creates* HLS — verified: in `MediaJobReliabilityTest` the video `processVariants` is always **mocked** (lines 91, 134), and the real `processVariants` calls in `tests/` are all for `ImageVariantService`, a different class. The `hls_playlist`/`m3u8` references that do exist are `deleteVariants` fixtures (Task 4 note) and must keep passing unchanged.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Media/VideoVariantService.php app/Models/Core/MediaVariant.php
git commit -m "refactor(video): remove unused HLS packaging + adaptive playlist"
```

---

### Task 5: Update env docs + manual end-to-end verification

**Files:**
- Modify: `.env.example` (video section)

- [ ] **Step 1: Update `.env.example` documented defaults**

Find the `PARTNA_VIDEO_MAX_UPLOAD_KB` / `PARTNA_VIDEO_MAX_DURATION_SECONDS` lines and set their example values + comments to `204800` (200 MB) and `30` (30s) respectively, matching the new config defaults. Leave the variant/queue vars unchanged.

- [ ] **Step 2: Manual transcode verification on dev**

With `PARTNA_VIDEO_UPLOADS_ENABLED=true` and a video worker on the `videos` queue:
1. Upload a ~20s 1080p clip via the gallery upload endpoint.
2. Confirm in R2 under `videos/<user>/<media>/`: exactly `original_*.<ext>`, `optimized_*.mp4`, `maximized_*.mp4`, `poster_*.jpg` — and **no `hls/` directory**.
3. Hit `GET /api/public/profiles/{handle}` and confirm each gallery video item has `url` (optimized .mp4), `urlHd` (maximized .mp4), `poster`, `durationMs`.
4. Upload a 45s clip → expect HTTP 422 "Video is too long".
5. Upload a >200 MB file → expect HTTP 422 size error.

Run: `cloud env:logs partna development --minutes 10` after the uploads to confirm no transcode errors.

- [ ] **Step 3: Commit**

```bash
git add .env.example
git commit -m "docs(env): video caps now 200MB / 30s"
```

---

## Self-Review

- **Spec coverage:** 30s cap (Task 1) ✓, 200 MB cap (Task 1) ✓, two MP4 tiers kept with audio (config unchanged, verified Task 1) ✓, frontend picks via `url`/`urlHd` (Task 3) ✓, HLS/adaptive removed (Task 4) ✓, original kept (no change — explicitly left intact) ✓, 4K gate kept + documented (Task 4) ✓, AVI dropped (Task 2) ✓.
- **Placeholder scan:** none — every code step shows the full before/after.
- **Type consistency:** resolver emits `url_hd` (snake) → payload builder maps to `urlHd` (camel wire); image branch also emits `url_hd => null` so the array shape is uniform across both branches.
- **Open verification dependency:** Task 3 Step 1 assumes `MediaVariant->url` resolves from `disk`+`path` without a live remote disk — the test note instructs binding a local `media` disk if needed; confirm the accessor before running.

## Out of Scope / Follow-ups

- **Frontend (`partna-pages`):** swap HLS player → `<video>` on `url`, use `urlHd` on expand. Separate repo, separate dev.
- **Skip-maximized-for-small-sources:** a ≤720p source still gets a redundant "maximized" encode (no upscale, so it's just another ~720p file). Cheap to live with; optimize later only if storage warrants.
- **Original lifecycle:** if stored originals ever become costly at scale, add an R2 lifecycle rule to prune originals older than N days. Not needed pre-beta.
- **Legacy `SiteCacheService` video block (dead code):** `resolveImageVariantUrlsInSite` (`app/Services/Cache/SiteCacheService.php:441-457`) resolves `gallery_videos`/`content_videos` with `variants` (mp4), `streams` (hls_playlist), `poster`. Verified dead for video on the individual-only platform: (a) `WarmPublicSiteCacheJob:19-21` states visitors of `<handle>.partna.au` **never read** this cache key — they hit `IndividualProfileController`; (b) `gallery_videos`/`content_videos` have no producer (line 473 just defaults them to `[]`). After HLS removal its `streams` line would always be empty. **Left untouched here** (minimal blast radius — it's not on the live path). Optional separate cleanup: drop the `streams` line and the dead video loop. Confirm with a runtime check (`getPublicSitePayload` for a real handle) before deleting, in case any non-individual/legacy site still routes through `PublicSiteController`.
- **Dashboard video preview:** `GalleryImageResource` exposes `variants` via `SiteMedia::variantUrls()`, which filters `artifact_type='webp'` only — so the user's own dashboard already returns no variant URLs for video rows. Pre-existing, unaffected by this change. Worth a separate look if dashboard video thumbnails are wanted.
