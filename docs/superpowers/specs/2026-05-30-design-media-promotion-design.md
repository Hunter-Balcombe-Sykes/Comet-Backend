# Design Media Promotion — Backend Spec

**Status:** approved direction, awaiting user review of this written spec.
**Author:** Josh (founder) + Claude (planning session, 2026-05-30).
**Scope:** backend only. Frontend skeleton themes change in a coordinated PR; that work is out of scope here.

## 1. Problem

The content-media pool (`site_media.pool='content'`) accepts both image and video uploads today. The full processing pipeline — ffprobe codec/resolution/duration validation, MP4 transcode to two tiers (`url` 720p + `url_hd` 1080p), poster JPEG extraction, content-hashed paths, MediaVariant rows — is production-grade and shared with the gallery pool (see `MediaUploadService`, `VideoVariantService`, both already live on origin/development).

The DB view `public_site_payload` already emits `content_videos` alongside `content_images` (`supabase/migrations/20260527070000_skeleton_system_cleanup.sql:226`). `SiteCacheService::resolveImageVariantUrlsInSite` already resolves variant/poster URLs for that array.

But the data path that builds the **public profile payload** for the skeleton — `SitepageDataResolverService::getContentImages` → `IndividualProfilePayloadBuilder::build` → `IndividualProfileResource` — is image-only. Content-pool videos are uploaded, processed, stored, and pre-aggregated, but never reach the rendered page. The last 10 ft of plumbing only carries the image lane.

## 2. Goals

1. **Surface content-pool videos** to the public profile payload alongside images, in a single sort-ordered list.
2. **Reshape the public field** to match the polymorphic envelope `buildGalleryItem` already returns (`{kind, url, url_hd, alt_text, caption, poster, duration_ms}`) so the renderer is one component regardless of media type.
3. **Promote the field** from `profile.content_images` (where it sits today as legacy compat) to a top-level `designMedia` field, sibling of `designKit`. Content media is design-layer infrastructure that the skeleton paints with — not user content the way bio/gallery/services are.
4. **Drop `content_images` cleanly** from the resource. No compat layer. The skeleton consumes `designMedia` only.
5. **Be foundational.** The shape we ship should not need to change to add a third media type (e.g. animated WebP, Lottie), add per-item display knobs, or split images from videos.

## 3. Non-goals

- **Body background re-introduction.** The `--dk-bg-image` toggle was removed 2026-05-28 (`supabase/migrations/20260528030000_drop_design_kit_bg_image.sql`). Reintroducing it is a separate decision.
- **Per-section media references.** Sections continue to be authored as Block rows with their own settings; this spec does not introduce `Block.settings.background_media_id` or similar.
- **Direct-to-R2 upload path.** The 200 MB / 30 s caps make the current server-proxy path comfortable. `SiteMediaService::createSignedUploadUrl` remains dormant.
- **Per-user storage quota.** The pool count cap (`content` = 6 mixed items) is unchanged.
- **CSAM re-enablement.** The CSAM scan pipeline is deferred (commit 95ebfb6b); the read-gate was removed from `getGallery` and is not added to the new `getContentMedia`. Re-enablement is project-wide and tracked separately.
- **Frontend / dashboard work.** Skeleton theme rendering and dashboard upload UI are owned by the frontend repo.

## 4. Design

### 4.1 Wire shape — the polymorphic envelope

The new `designMedia` field is an ordered array. Each item carries the same shape regardless of media type. **All wire-field names are camelCase** to match every other top-level field (`designKit`, `skeletonId`, `publicConfig`) and every engine output (`gallery[i].urlHd`, `services[i].priceCents`, `document.downloadUrl`, etc.). The resolver returns snake_case internally; the builder layer remaps to camelCase before the resource emits.

```jsonc
{
  "designMedia": [
    {
      "id": "uuid",
      "sortOrder": 0,
      "kind": "image",
      "alt": "studio shot" | null,
      "caption": "..."     | null,
      "url": "https://cdn.../optimized.webp",
      "urlHd": "https://cdn.../maximized.webp" | null,
      "poster": null,
      "durationMs": null
    },
    {
      "id": "uuid",
      "sortOrder": 1,
      "kind": "video",
      "alt": "intro reel"  | null,
      "caption": null,
      "url": "https://cdn.../optimized.mp4",
      "urlHd": "https://cdn.../maximized.mp4" | null,
      "poster": "https://cdn.../poster.jpg",
      "durationMs": 12500
    }
  ]
}
```

**Field semantics:**
- `id` — UUID of the `SiteMedia` row. Not strictly required by the renderer but useful for React keys and debugging.
- `sortOrder` — preserves user-defined order across mixed media types. Server-side ordering is authoritative; clients use this only for stable keys / analytics.
- `kind` — `"image" | "video"`. Discriminator for the renderer; chosen for parity with `buildGalleryItem`.
- `alt`, `caption` — null when unset; matches existing nullable semantics. `alt` matches the `gallery[i].alt` convention (not `alt_text`).
- `url` — primary delivery URL. For images: optimized WebP (≤2400 px, ~500 KB target). For videos: optimized 720p MP4, the autoplay/in-viewport default.
- `urlHd` — high-quality fallback. For images: maximized WebP (≤4000 px) when available, else `null`. For videos: maximized 1080p MP4 when available, else `null`. Uniform shape across kinds so the renderer can always check `urlHd`.
- `poster` — still-frame JPEG for videos; `null` for images (the image itself is the poster).
- `durationMs` — integer for videos; `null` for images.

**Empty state:** `designMedia: []` (always an array, never `null`).

**Foundational properties:**
- A future third media type (animated WebP, Lottie, etc.) adds one `kind` literal and a renderer branch. No layout-code changes; no new top-level field.
- A future per-item knob (e.g. autoplay-mute, loop, focal point) becomes a new optional field on every envelope entry. Backward-compatible additions only.

### 4.2 Resource shape change

```diff
// IndividualProfileResource::toArray()
 return [
     'profile' => [
         'handle' => $this->handle,
         'displayName' => $this->display_name,
         'site_id' => $this->sections['site_id'] ?? null,
         'bio' => $this->sections['bio'] ?? null,
         'gallery' => $this->sections['gallery'] ?? [],
         'links' => $this->sections['links'] ?? [],
         'services' => $this->sections['services'] ?? [],
         'document' => $this->sections['document'] ?? null,
         'newsletter' => $this->sections['newsletter'] ?? null,
         'workplace' => $this->sections['workplace'] ?? null,
-        'content_images' => $this->sections['content_images'] ?? [],
     ],
     'designKit' => $designKitOut,
+    'designMedia' => $this->sections['design_media'] ?? [],
     'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',
     'publicConfig' => $publicConfigOut,
 ];
```

`designMedia` sits as a top-level sibling of `designKit` — both describe the design layer the skeleton paints with.

### 4.3 Resolver change

`SitepageDataResolverService::getContentImages` is replaced (not deprecated alongside) by `SitepageDataResolverService::getContentMedia`. The new method:

- Reads the same `site_media` rows: `pool='content'`, `is_active=true`, `processing_state='ready'`, ordered by `sort_order`.
- Eager-loads `mediaVariants` to keep it a single query.
- Returns a polymorphic list matching the envelope above.
- Projects each row via a **shared helper** (see §4.4) so gallery and content-media never drift.

Pseudocode:

```php
public function getContentMedia(?Site $site): array
{
    if (! $site) return [];

    return SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_CONTENT)
        ->where('is_active', true)
        ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
        ->with('mediaVariants')
        ->orderBy('sort_order')
        ->get()
        ->map(fn (SiteMedia $m) => $this->buildMediaItem($m))
        ->filter(fn (?array $item) => $item !== null && $item['url'] !== '')
        ->values()
        ->all();
}
```

### 4.4 Shared projection helper

`buildGalleryItem` currently lives on `SitepageDataResolverService` and is called only by `getGallery`. Extract its body into a private `buildMediaItem(SiteMedia $media): ?array` and have `buildGalleryItem` delegate. `getContentMedia` calls the same helper.

This guarantees the gallery wire shape and the content-media wire shape never drift. A future change to the envelope (e.g. adding `aspect_ratio`) touches one helper and one set of tests.

The helper is intentionally **kind-aware but pool-agnostic** — it doesn't know whether it's building a gallery item or a design-media item. That makes it safe to reuse from any future caller.

### 4.5 Builder change

The builder introduces a new private `buildDesignMedia` method that mirrors the `buildGallery` pattern: call the resolver (snake_case output), remap to camelCase for the wire. **This is non-optional** — passing the resolver output directly to the resource would emit snake_case keys on the wire, which violates the convention every other top-level field follows.

```php
/**
 * Design-layer media — DesignMediaItem[] (empty array when nothing in the pool).
 *
 * Remaps the resolver's snake_case keys (sort_order, alt_text, url_hd,
 * duration_ms) to the camelCase wire shape per the §5 wire convention.
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

And the `build()` method:

```diff
 return (new IndividualProfileResource($pro, [
     'site_id' => $site?->id,
     'design_kit' => $this->loadDesignKit($site),
+    'design_media' => $this->buildDesignMedia($site),
     'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
     'public_config' => $this->buildPublicConfig(),
-    'content_images' => $this->resolver->getContentImages($site),
     'bio' => $this->buildBio($pro, $sections),
     ...
 ]))->resolve();
```

The PHPDoc payload shape comment at the top of the class is updated to reflect the new top-level field.

### 4.6 Naming rationale

- **`designMedia`** (top-level, camelCase) — sibling of `designKit`. Reads truthfully: this is the design layer. The skeleton author thinks "designKit + designMedia + skeletonId = design inputs".
- **`getContentMedia` / `'design_media'` (snake)** — internal naming keeps the `content_*` lineage (the pool is still called `content`; renaming the pool is a no-value migration) while the wire field uses `designMedia` to communicate intent to consumers. The resource is the translation point between internal naming (snake_case `design_media` key on the sections array) and wire naming (`designMedia` field on the payload), matching how `design_kit` → `designKit` is handled today.
- **Pool name stays `content`.** Renaming requires altering the `site_media.pool` CHECK constraint, the upload pools config, the cache invalidator's key list, several tests, and the DB view — all for zero user-facing benefit. The pool name is internal vocabulary.

## 5. Security

The change exposes new URLs that already-public uploads produced. No new attack surface is introduced. Existing defenses are preserved by being inherited from the upload path:

| Concern | Defense | Where |
|---|---|---|
| Path/URL injection in payload | URLs built via `MediaVariant::getUrlAttribute`, which concatenates the configured disk's `url` (R2 CDN base) with the stored object path; no user-controlled URL composition. | `app/Models/Core/MediaVariant.php:107` |
| Codec-bomb / decode-resource exhaustion | Upload-time ffprobe enforces codec allowlist (`h264`, `hevc`, `vp9`) and resolution ceiling (≤3840 × ≤2160) **before** the DB row is created or the file is streamed to R2. | `app/Services/Media/VideoVariantService.php` (`probeAndValidate`) |
| Spoofed MIME (renamed .pdf as .mp4) | ffprobe rejects non-video containers at the same boundary. Images use a separate byte-magic sniff in `SniffsFileMimeType`. | same |
| Privilege escalation (upload to other user's site) | `SitePolicy::create` enforces ownership via the `site` relation. The controller calls `authorizeForUser` on a `SiteMedia` skeleton with the resolved site. | `app/Policies/SitePolicy.php`, `app/Http/Controllers/Api/User/Uploads/UserUploadController.php:59` |
| In-progress / failed / soft-deleted items leaking publicly | `getContentMedia` filters `processing_state='ready'`, `is_active=true`, and respects `deleted_at IS NULL` via the `SoftDeletes` trait. | `SiteMedia` model + new resolver method |
| Per-tenant gating | Video uploads gated by `FeatureFlagService::enabled('video_uploads', $pro)` in the controller. Existing items remain consumable even if the flag is later flipped off — the flag gates write, not read (intentional: don't break a user's existing public profile by toggling a flag). | `UserUploadController:66` |
| PII leakage in payload | `designMedia` exposes only `id`, `sort_order`, `kind`, `alt_text`, `caption`, `url`, `url_hd`, `poster`, `duration_ms`. **Excludes:** `original_filename`, `original_size_bytes`, `original_mime`, `bucket`, `path`, `processing_error`. No user-identifying metadata reaches the public surface. | This spec (resolver projection) |
| CDN cache poisoning across users | Variant paths are content-hashed (`*_{sha256_short}.mp4`, `*_{sha256_short}.webp`). Re-processing produces a new URL; a different source produces a new URL. Cross-user collision requires SHA-256 collision. | `VideoVariantService::processVariants`, `ImageVariantService` |
| Hot-link / abuse of public URLs | Out of scope. R2 objects are public; CDN-level hot-link protection (referer allowlist) is a delivery-layer concern handled at Cloudflare. Documented as a known limitation. | — |
| CSAM scan gate | Removed from `getGallery` in commit 95ebfb6b; **not added** to `getContentMedia` for consistency. Re-enablement is project-wide and gated by `PARTNA_CSAM_SCAN_ENABLED`. | `SitepageDataResolverService::applyCsamScanGate` (still defined, currently unreferenced for gallery; new method follows suit) |

**Net change to attack surface:** zero. This spec adds a new field to the public payload that surfaces objects the upload path has already vetted.

## 6. Performance & caching

- **Query cost:** `getContentMedia` is a single query with eager-loaded `mediaVariants` — identical pattern and cost to `getContentImages`. The pool cap (6 items × ≤4 variants each) bounds the row count at ~24 joined rows.
- **Cache key:** `IndividualProfilePayloadBuilder::cacheKey` already includes `$site->updated_at->timestamp`. Any media insert/reorder/delete touches the parent `Site` via the observer chain (verified by recent refactor: commits a93b1326, 410a197f, ddd8c2d0, etc.). The cache key rolls forward automatically.
- **Cloudflare edge:** `CloudflareCachePurgeJob` is dispatched via the same observer chain. The recent `unique per handle` change (commit 1324da07) means cascaded media updates coalesce into a single purge per handle, preventing thundering-herd purges when a user reorders.
- **SWR shadow:** `CloudflarePurgeService` includes the SWR stale shadow URL (commit 72ba28ed). The new field rides on the same key.

**No new cache code is required.**

## 7. Tests

### 7.1 Unit — `SitepageDataResolverServiceTest`

| Test | Assertion |
|---|---|
| `getContentMedia returns empty when site is null` | `[]` |
| `getContentMedia returns empty when pool is empty` | `[]` |
| `getContentMedia returns images in sort_order` | shape, `kind='image'` |
| `getContentMedia returns videos in sort_order` | shape, `kind='video'`, `poster` and `duration_ms` populated |
| `getContentMedia interleaves images and videos by sort_order` | order preserved across kinds |
| `getContentMedia excludes is_active=false` | excluded item not in result |
| `getContentMedia excludes processing_state in [pending, processing, failed]` | excluded |
| `getContentMedia excludes soft-deleted rows` | excluded |
| `getContentMedia only reads pool='content'` | gallery-pool items not present |
| `getContentMedia projects through the same helper as getGallery` | shape parity test (same item appears identically in both lists when present in both pools) |

### 7.2 Feature — `IndividualProfilePayloadBuilderTest` (or PublicProfileControllerTest)

| Test | Assertion |
|---|---|
| Payload exposes top-level `designMedia` | key present, array |
| Payload no longer has `profile.content_images` | key absent |
| Payload no longer has top-level `content_images` / `content_media` | key absent |
| `designMedia` ordering matches sort_order with mixed media | ordered correctly |
| Video media in content pool surfaces with `poster` and `duration_ms` | populated |
| Toggling `is_active=false` removes item from next read | removed |
| Reordering moves the item across cache invalidation | rolling cache key |

### 7.3 Existing tests touched

- `UploadImageRequestTest` — no change.
- `MediaUploadServiceTest` — no change (upload path unchanged).
- `UserUploadControllerTest::reorder` — verify mixed-type reorder still works for content pool (already covered for gallery; extend to content).
- Any test asserting on `profile.content_images` shape — update or delete.

## 8. Migration & rollout

**No DB migration.** This is a wire-shape change.

**Deployment order:**

1. Backend PR ships the new resource shape. `content_images` is removed from the payload. `designMedia` is added.
2. Frontend PR ships the skeleton change to read `data.designMedia` instead of `data.profile.content_images`. The skeleton renders the `kind` discriminator (image → `<img>`, video → `<video>` with poster).
3. Either ship in **lockstep** (back-to-back deploys within the same window) or behind a feature-flagged frontend rollout that reads `data.designMedia ?? data.profile.content_images`.

**Recommendation:** lockstep deploy. Pre-beta, no customers, and the frontend change is a single file. Compat fallback adds carry cost we'll remove anyway.

**Rollback:** pure code revert; no data risk. Variant rows, R2 objects, and the DB view are unchanged by this spec.

## 9. Open questions

None remaining at design time. Implementation will surface secondary questions (PHPDoc wording, exact test names) that don't need spec-level resolution.

## 10. Out-of-spec follow-ups

These were considered and deliberately deferred. Each is a separate plan when triggered:

- **Body background re-introduction with image-or-video support.** If the design team wants `--dk-bg-* CSS-var` style body backgrounds back, the `designMedia[0]` slot is the natural source — but the trigger is the design-system bringing the concept back, not this spec.
- **Per-section media references.** If a section needs its own dedicated background distinct from `designMedia`, the path is `Block.settings.{slot}_media_id` referencing a `site_media.id`. Explicitly excluded by user direction in this round.
- **Direct-to-R2 upload path.** If video caps lift back to 500 MB+, wire `SiteMediaService::createSignedUploadUrl` to a route and switch the dashboard to multipart presigned upload. Today's 200 MB cap doesn't justify the complexity.
- **Per-user storage quota.** Pool count cap is the only quota today. If individual videos start eating budget, a `users.storage_quota_bytes` column + per-upload rolling sum gates it without changing this shape.
- **Hot-link / CDN-level access control.** Cloudflare referer allowlist or signed-URL delivery for design media if hot-linking abuse appears.

## 11. References

- `app/Services/PublicSite/SitepageDataResolverService.php` — resolver (current `getContentImages` at line 207 on origin/development)
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` — builder
- `app/Http/Resources/PublicSite/IndividualProfileResource.php` — wire shape
- `app/Services/Media/VideoVariantService.php` — codec/resolution validation
- `app/Services/Media/MediaUploadService.php` — orchestration
- `app/Http/Controllers/Api/User/Uploads/UserUploadController.php` — HTTP entrypoint
- `config/partna.php` — upload pools, video caps (200 MB / 30 s), MP4 tiers
- `supabase/migrations/20260527070000_skeleton_system_cleanup.sql` — DB view `public_site_payload`
- `supabase/migrations/20260528030000_drop_design_kit_bg_image.sql` — body bg removal (context for non-goal)
- Recent video commits: `1c377664`, `2cffacaa`, `816912b0`, `9ee8b787`, `1e2b6574`, `18c9c051`
