# Media Pool Slice 1a Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-08-12-media-pool-slice-1a-design.md` (sub-slice of `2026-08-11-content-pool-convergence-design.md` §7).

**Goal:** One dev account's media pool contains its uploaded photos, resolving to servable URLs with dimensions on the public profile payload; `content.items WHERE kind='media'` rises from 10 to 35; every `pool:media` section carries the corrected `kind_is` rule; the backfiller is idempotent; a Google Business connector run after the backfill leaves all 25 upload items alive.

**Architecture:** A new `content.media_assets.site_media_id` column turns an asset row into a pointer at the working `site.media_variants` pipeline. A new `MediaUrlResolver` service is the single URL seam (storage_path → variant → source_url precedence). `ProjectionWriter` gains an upload entry shape whose fingerprint lives inside the existing `url-` namespace, and flips fingerprint preference from URL to ref. `PoolResolver` grows `frames[]` (additive wire change). A backfiller writes the 25 live gallery/content uploads through the slice-0b manual lane.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4 (SQLite in-memory + Postgres lane), raw SQL migrations under `supabase/migrations/`.

## Global Constraints

- **Never create Laravel migration files** — schema changes are raw SQL in `supabase/migrations/` (composer guard rejects Laravel migrations).
- Tests run SQLite, prod is Postgres — constraint-bound writes must be verified against `supabase/migrations/` DDL, not just a green suite. The SQLite stand-in for `content.media_assets` lives in `tests/Pest.php:2455` and MUST gain any new column in the same task as the migration.
- `frames[]` is **additive**; `thumbnail` **stays a bare string** — watch/listen/events consumers must see byte-identical output for existing shapes.
- `frames` may only emit roles in `cover|gallery|poster|avatar|logo` (`item_media_role_check`, `supabase/migrations/20260727140000_content_schema.sql:375`).
- Upload fingerprints are `'url-'.sha1('upload:'.$siteMediaId)` — inside the existing `url-` namespace, minted only by `ProjectionWriter`.
- Backfiller writes through `ProjectionWriter::writeManualItem()` — never raw SQL into `content.*`.
- Raw-write seams must invalidate **all three cache lanes**: `BuildState::bump($siteId)` + touch `site.sites.updated_at` + `CloudflareCachePurgeJob::dispatch($subdomain)` (template: `app/Console/Commands/MigrateHiddenEventsToPoolExcludes.php:143-148`). There is NO CI check for this — the tests assert it directly.
- Branch off `development`: `git checkout -b media-pool-slice-1a development`.
- Before merge: apply the migration to dev Supabase (`glncumufgaqcmqhzwrxm`) and re-verify the fingerprint-flip safety claim against **prod** (`edplucmvkcnokyygxqsb`).
- `composer test` before done. `vendor/bin/pint --dirty` before each commit (never `composer test --filter` — broken).

## File Structure

| File | Responsibility |
|---|---|
| `supabase/migrations/20260812090000_content_media_assets_site_media_id.sql` | Create: the pointer column + index |
| `tests/Pest.php` (line ~2455) | Modify: SQLite stand-in gains `site_media_id` |
| `tests/Postgres/MediaAssetSiteMediaFkTest.php` | Create: pins `ON DELETE SET NULL` against real Postgres |
| `app/Ingest/Projection/ProjectionWriter.php` | Modify: `mediaFingerprint()` ref-preference + upload branch in `resolveMediaAssets()` |
| `tests/Feature/Ingest/MediaFingerprintPreferenceTest.php` | Create: fingerprint preference tests |
| `tests/Feature/Ingest/UploadMediaEntryTest.php` | Create: upload entry shape tests |
| `app/Services/Media/MediaUrlResolver.php` | Create: the URL seam (3 strategies + omit) |
| `tests/Feature/Media/MediaUrlResolverTest.php` | Create: resolver strategy tests |
| `app/Site/Pools/PoolResolver.php` | Modify: `frames[]` + thumbnail through the resolver |
| `tests/Feature/Content/MediaPoolFramesTest.php` | Create: wire-shape tests |
| `app/Site/Pools/PoolRegistry.php` | Modify: `SECTION_SHAPE['media']` |
| `app/Console/Commands/ReshapeMediaSectionsCommand.php` | Create: re-provision the ten dev sections |
| `tests/Feature/Content/MediaSectionReshapeTest.php` | Create: shape + command tests |
| `app/Services/Migration/MediaUploadBackfiller.php` | Create: the 25-row backfill through the manual lane |
| `app/Console/Commands/BackfillUploadMediaCommand.php` | Create: artisan wrapper with `--dry-run` |
| `tests/Feature/Content/MediaUploadBackfillerTest.php` | Create: idempotency, skips, §8.3 merge regression, cache lanes |
| `docs/wire-changes/2026-08-12-media-pool-slice-1a.md` | Create: wire manifest for both frontends |

Task order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9. Tasks 2 and 6 are independent of the column; everything else flows through it. Within-slice order is free per spec §3, but this sequence means each task's tests can run against completed neighbours.

---

### Task 1: The `site_media_id` column

**Files:**
- Create: `supabase/migrations/20260812090000_content_media_assets_site_media_id.sql`
- Modify: `tests/Pest.php` (the `content.media_assets` stand-in at ~line 2455)
- Create: `tests/Postgres/MediaAssetSiteMediaFkTest.php`

**Interfaces:**
- Consumes: existing `content.media_assets` and `site.site_media` DDL.
- Produces: `content.media_assets.site_media_id uuid NULL REFERENCES site.site_media(id) ON DELETE SET NULL` — read by Tasks 3, 4, 5, 7.

**Design decision closed here (spec §5.3 left it open):** the FK is `ON DELETE SET NULL`. `RESTRICT` would break `SiteMedia::forceDelete()` (user deletion / GDPR teardown paths); `CASCADE` would silently delete an asset row that `content.item_media` still points at. `SET NULL` leaves a detectable dead shape (all four resolution columns null) that `MediaUrlResolver` omits — the frame disappears gracefully instead of 404ing, and the §5.1 SQL can find such rows.

- [ ] **Step 1: Write the migration**

```sql
-- Slice 1a (media pool): content.media_assets becomes able to point at an
-- owned upload. The asset row is a POINTER into the site.media_variants
-- pipeline, not a snapshot of it — pinning a rendition path here would
-- hard-code one variant and dangle after a reprocess (spec §3.2).
--
-- ON DELETE SET NULL, not RESTRICT: SiteMedia::forceDelete() runs in user
-- deletion flows and must not be blocked by a content row. The resulting
-- all-null shape is deliberately detectable and MediaUrlResolver omits it.
ALTER TABLE "content"."media_assets"
    ADD COLUMN "site_media_id" uuid REFERENCES "site"."site_media" ("id") ON DELETE SET NULL;

-- Partial: 485 existing rows are connector-minted and stay NULL.
CREATE INDEX "idx_media_assets_site_media"
    ON "content"."media_assets" ("site_media_id")
    WHERE "site_media_id" IS NOT NULL;
```

No `CONCURRENTLY` (table has 485 rows on dev; the one-`CONCURRENTLY`-per-file rule is moot). Check `supabase/migrations/CONVENTIONS.md` for the header comment format used by recent files and match it.

- [ ] **Step 2: Mirror the column into the SQLite stand-in**

In `tests/Pest.php`, inside the `content.media_assets` CREATE at ~line 2455, add after `storage_path TEXT NULL,`:

```php
        site_media_id TEXT NULL,
```

(Stand-in drift is a known failure mode — memory `reference_pest_standin_phantom_columns`. The stand-in must gain exactly the columns the migration adds, no more.)

- [ ] **Step 3: Write the Postgres-lane FK pin**

`tests/Postgres/MediaAssetSiteMediaFkTest.php` (model on the existing `tests/Postgres/IngestCascadeDeletionTest.php` for lane setup conventions — copy its `beforeEach`/connection idioms exactly):

```php
<?php

// Slice 1a: a hard-deleted site.site_media row must not dangle a pointer.
// SET NULL (not CASCADE — item_media may still reference the asset; not
// RESTRICT — SiteMedia::forceDelete() runs in user-deletion flows).
it('sets media_assets.site_media_id to NULL when the upload row is hard-deleted', function () {
    $rule = DB::connection('pgsql')->selectOne("
        SELECT rc.delete_rule
        FROM information_schema.referential_constraints rc
        JOIN information_schema.table_constraints tc
          ON tc.constraint_name = rc.constraint_name
         AND tc.constraint_schema = rc.constraint_schema
        WHERE tc.table_schema = 'content'
          AND tc.table_name = 'media_assets'
          AND rc.constraint_schema = 'content'
          AND rc.unique_constraint_schema = 'site'
    ");

    expect($rule)->not->toBeNull()
        ->and($rule->delete_rule)->toBe('SET NULL');
});
```

(If the join proves ambiguous against the applied schema, query `pg_constraint.confdeltype = 'n'` for the FK on `content.media_assets` referencing `site.site_media` instead — the assertion target is the delete rule, not the query shape.)

- [ ] **Step 4: Run the suite to confirm nothing regressed**

Run: `composer test`
Expected: PASS (the stand-in column is additive; nothing reads it yet). Note the Postgres-lane test does NOT run here — it runs in its own lane; verify it during Task 9's dev apply.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations/20260812090000_content_media_assets_site_media_id.sql tests/Pest.php tests/Postgres/MediaAssetSiteMediaFkTest.php
git commit -m "feat(media): content.media_assets.site_media_id — the asset row becomes a pointer into the variant pipeline"
```

---

### Task 2: `mediaFingerprint()` prefers `ref` over `url`

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php:1155` (one line) + the `#PRIV-5` docblock above it
- Create: `tests/Feature/Ingest/MediaFingerprintPreferenceTest.php`

**Interfaces:**
- Consumes: `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string` (public, existing) as the test entry point; media entries shaped `['role' => ..., 'url' => ?, 'ref' => ?, 'alt' => ?, 'width' => ?, 'height' => ?]`.
- Produces: fingerprint = `'url-'.sha1($ref ?? $minimisedUrl)`. Every existing dev row is stable under this (Google assets are ref-only; Instagram has zero items) — re-verified against prod in Task 9.

**Why first among the code changes:** the moment 1b lands the Google URL pass-through, `mapPhoto()` emits `url` beside `ref`; under url-preference that re-keys the ten existing Google assets and `insertOrIgnore` mints ten duplicates (spec §3.1). Landing this in 1a is what prevents that.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Ingest/MediaFingerprintPreferenceTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1a §3.1: the media fingerprint keys on the vendor-stable ref, not
// the (re-signing) URL. InstagramMediaProjector's docblock always claimed
// this; ProjectionWriter now actually does it. Ordered before 1b's Google
// URL pass-through so adding `url` beside `ref` cannot re-key an asset.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function fingerprintItem(string $userId, array $mediaEntry): ?object
{
    app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.sha1(json_encode($mediaEntry)),
        ['kind' => 'media', 'headline' => 'A photo', 'media' => [$mediaEntry]],
    );

    return DB::table('content.media_assets')->where('user_id', $userId)->first();
}

it('keys an entry carrying both url and ref off the ref', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://scontent.cdninstagram.com/photo.jpg?oh=abc&oe=123',
        'ref' => 'instagram:SHORTCODE:0',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('instagram:SHORTCODE:0'));
});

it('keys a ref-only entry off the ref, unchanged from today', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'gallery',
        'ref' => 'places/ChIJx/photos/AXCi2',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('places/ChIJx/photos/AXCi2'))
        ->and($asset->source_url)->toBeNull();
});

it('keys a url-only entry off the minimised url, unchanged from today', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://cdn.example.com/img.jpg',
    ]);

    expect($asset->fingerprint)->toBe('url-'.sha1('https://cdn.example.com/img.jpg'))
        ->and($asset->source_url)->toBe('https://cdn.example.com/img.jpg');
});

it('still stores the minimised url when the ref wins the key', function () {
    $userId = createTenant('fp-'.Str::lower(Str::random(6)))->id;

    $asset = fingerprintItem($userId, [
        'role' => 'cover',
        'url' => 'https://cdn.example.com/img.jpg',
        'ref' => 'stable-ref-1',
    ]);

    // Identity changed to the ref; source_url is untouched by the flip.
    expect($asset->fingerprint)->toBe('url-'.sha1('stable-ref-1'))
        ->and($asset->source_url)->toBe('https://cdn.example.com/img.jpg');
});
```

Caveat for the url-only assertions: `SecretParams::minimiseUrl()` runs first — the example URLs above carry no strippable params so minimised == raw. If an assertion fails on a changed URL, that is `minimiseUrl` at work; use a param-free URL, do not weaken the assertion.

- [ ] **Step 2: Run to verify the both-keys test fails**

Run: `vendor/bin/pest tests/Feature/Ingest/MediaFingerprintPreferenceTest.php`
Expected: the two "both keys" tests FAIL (fingerprint currently keys off the url); the ref-only and url-only tests PASS (they pin unchanged behaviour).

- [ ] **Step 3: Flip the preference**

`app/Ingest/Projection/ProjectionWriter.php:1155`:

```php
        $fingerprint = $ref ?? $url;
```

And in the docblock above (`:1133-1146`), replace the sentence "The vendor's stable ref is the fallback for images with no fetchable URL (GBP photo resource names)." with:

```
     * The vendor's stable ref is PREFERRED over the URL (slice 1a §3.1):
     * Instagram URLs re-sign on every sync (`oh`/`oe` params survive
     * minimisation), so a URL-keyed asset re-mints per sync the moment a
     * projector emits url beside ref. The URL is the fallback for entries
     * with no ref.
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS. If any existing test pinned url-preference, read it before touching it — the spec says no data migration is needed *on dev*; a test asserting the old preference is a test of the bug, update it with a comment pointing at spec §3.1.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/MediaFingerprintPreferenceTest.php
git commit -m "feat(ingest): media fingerprint prefers the vendor-stable ref over the re-signing url"
```

---

### Task 3: The upload entry shape in `ProjectionWriter`

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` — `mediaFingerprint()` (:1147) and `resolveMediaAssets()` (:1173)
- Create: `tests/Feature/Ingest/UploadMediaEntryTest.php`

**Interfaces:**
- Consumes: Task 1's `site_media_id` column.
- Produces: the **upload media entry contract**, consumed by Task 7's backfiller:

```php
[
    'role' => 'cover',                    // one frame per upload item
    'site_media_id' => '<uuid>',          // presence of this key IS the upload shape
    'alt' => ?string,
    'width' => ?int,                      // from the chosen media_variants row
    'height' => ?int,                     //   (the CALLER reads the variant —
    'mime_type' => ?string,               //    the hot path stays query-free)
]
```

Asset row minted for it: `fingerprint = 'url-'.sha1('upload:'.$siteMediaId)`, `site_media_id` set, `source_url` NULL, `dims_confidence = 'measured'`, `mime_type`/`width`/`height` from the entry.

**This is the slice's main regression risk** (hot path, every connector) — the diff is deliberately two small additive branches, and the existing `ProjectionWriterTest` / `ProjectionWriterBatchingTest` / `ProjectionWriterUrlMinimisationTest` suites are the regression net.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Ingest/UploadMediaEntryTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 1a §3.4: a media entry may carry site_media_id instead of url/ref.
// The fingerprint lives INSIDE the existing url- namespace ('upload:{id}'
// is minted only here), so the uniqueness constraint keeps one meaning.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function uploadEntry(string $siteMediaId, array $overrides = []): array
{
    return array_merge([
        'role' => 'cover',
        'site_media_id' => $siteMediaId,
        'alt' => 'A shopfront',
        'width' => 1200,
        'height' => 800,
        'mime_type' => 'image/webp',
    ], $overrides);
}

it('mints one asset with site_media_id, measured dims, and null source_url', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Shopfront',
        'media' => [uploadEntry($siteMediaId)],
    ]);

    $asset = DB::table('content.media_assets')->where('user_id', $userId)->first();

    expect($asset->fingerprint)->toBe('url-'.sha1('upload:'.$siteMediaId))
        ->and($asset->site_media_id)->toBe($siteMediaId)
        ->and($asset->source_url)->toBeNull()
        ->and($asset->dims_confidence)->toBe('measured')
        ->and((int) $asset->width)->toBe(1200)
        ->and((int) $asset->height)->toBe(800)
        ->and($asset->mime_type)->toBe('image/webp');
});

it('mints ONE asset when two items reference the same site_media', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();
    $writer = app(ProjectionWriter::class);

    foreach (['manual:a-'.$siteMediaId, 'manual:b-'.$siteMediaId] as $coord) {
        $writer->writeManualItem($userId, $coord, [
            'kind' => 'media',
            'headline' => 'Shared upload',
            'media' => [uploadEntry($siteMediaId)],
        ]);
    }

    expect(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.item_media')->count())->toBe(2);
});

it('leaves url/ref entries in the same batch completely untouched', function () {
    $userId = createTenant('up-'.Str::lower(Str::random(6)))->id;
    $siteMediaId = (string) Str::uuid();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:mixed-'.$siteMediaId, [
        'kind' => 'media',
        'headline' => 'Mixed frames',
        'media' => [
            uploadEntry($siteMediaId),
            ['role' => 'gallery', 'url' => 'https://cdn.example.com/other.jpg'],
        ],
    ]);

    $assets = DB::table('content.media_assets')->where('user_id', $userId)->orderBy('fingerprint')->get();

    expect($assets)->toHaveCount(2);
    $urlAsset = $assets->firstWhere('source_url', 'https://cdn.example.com/other.jpg');
    expect($urlAsset->site_media_id)->toBeNull()
        ->and($urlAsset->dims_confidence)->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Ingest/UploadMediaEntryTest.php`
Expected: FAIL — `site_media_id` is null on the minted asset and the fingerprint derives from nothing (entry has no url/ref, so `mediaFingerprint` returns null and no asset is minted at all).

- [ ] **Step 3: Implement the two branches**

In `mediaFingerprint()` (`ProjectionWriter.php:1147`), add BEFORE the `$url` extraction:

```php
        // Upload shape (slice 1a §3.4): the stable ref IS the site_media id.
        // Inside the url- namespace by construction — only this method mints
        // 'upload:' fingerprints, so no existing row can collide.
        $siteMediaId = isset($entry['site_media_id']) && is_string($entry['site_media_id']) && $entry['site_media_id'] !== ''
            ? $entry['site_media_id']
            : null;
        if ($siteMediaId !== null) {
            return ['url-'.sha1('upload:'.$siteMediaId), null];
        }
```

In `resolveMediaAssets()` (`:1199-1211`), the row build becomes:

```php
        $rows = [];
        foreach ($missing as $fingerprint => [$entry, $url]) {
            $isUpload = isset($entry['site_media_id']) && is_string($entry['site_media_id']) && $entry['site_media_id'] !== '';
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'source_url' => $url,
                'site_media_id' => $isUpload ? $entry['site_media_id'] : null,
                'mime_type' => $isUpload ? ($entry['mime_type'] ?? null) : null,
                'width' => $entry['width'] ?? null,
                'height' => $entry['height'] ?? null,
                // 'measured' for uploads: the variant pipeline decoded the
                // image. 'declared' when a connector claimed dims. Unchanged
                // for the connector shapes.
                'dims_confidence' => $isUpload ? 'measured' : (isset($entry['width']) ? 'declared' : null),
                'created_at' => now(),
            ];
        }
```

- [ ] **Step 4: Run the new tests, then the writer's existing suites**

Run: `vendor/bin/pest tests/Feature/Ingest/UploadMediaEntryTest.php tests/Feature/Ingest/ProjectionWriterTest.php tests/Feature/Ingest/ProjectionWriterBatchingTest.php tests/Feature/Ingest/ProjectionWriterUrlMinimisationTest.php tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: all PASS. Then `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/UploadMediaEntryTest.php
git commit -m "feat(ingest): upload media entries — site_media_id-backed assets through the projection spine"
```

---

### Task 4: `MediaUrlResolver`

**Files:**
- Create: `app/Services/Media/MediaUrlResolver.php`
- Create: `tests/Feature/Media/MediaUrlResolverTest.php` (create the directory if absent)

**Interfaces:**
- Consumes: asset rows (stdClass) carrying `id`, `source_url`, `storage_path`, `site_media_id`, `width`, `height`; `App\Models\Core\MediaVariant` (fields `media_id`, `variant_key`, `artifact_type`, `disk`, `path`, `width`, `height`, and the `url` accessor with its disk-URL fallback chain); `config('partna.media_disk')`.
- Produces (consumed by Task 5):

```php
namespace App\Services\Media;

class MediaUrlResolver
{
    /**
     * @param  iterable<object>  $assets  rows with id, source_url, storage_path, site_media_id, width, height
     * @return array<string, array{url: string, width: int|null, height: int|null}>  keyed by asset id; unresolvable assets ABSENT
     */
    public function resolve(iterable $assets): array
}
```

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Media/MediaUrlResolverTest.php`:

```php
<?php

use App\Services\Media\MediaUrlResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.3: the ONE url seam for media assets. Precedence
// storage_path → variant (via site_media_id) → source_url → omitted.
// Batched: sits on the public profile hot path behind the 60s cache.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    Storage::fake('media');
});

function assetRow(array $overrides = []): object
{
    return (object) array_merge([
        'id' => (string) Str::uuid(),
        'source_url' => null,
        'storage_path' => null,
        'site_media_id' => null,
        'width' => null,
        'height' => null,
    ], $overrides);
}

function variantFor(string $siteMediaId, string $key, int $width = 1200, int $height = 800): void
{
    DB::table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $siteMediaId,
        'variant_key' => $key, 'artifact_type' => 'webp', 'disk' => 'media',
        'path' => "variants/{$siteMediaId}/{$key}.webp", 'mime' => 'image/webp',
        'width' => $width, 'height' => $height, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('serves storage_path off the media disk, first in precedence', function () {
    $asset = assetRow(['storage_path' => 'mirrored/abc.webp', 'width' => 640, 'height' => 480,
        'site_media_id' => (string) Str::uuid()]); // even with a pointer present

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out)->toHaveKey($asset->id)
        ->and($out[$asset->id]['url'])->toContain('mirrored/abc.webp')
        ->and($out[$asset->id]['width'])->toBe(640)
        ->and($out[$asset->id]['height'])->toBe(480);
});

it('serves the optimized webp variant for a site_media pointer, with the VARIANT row dims', function () {
    $siteMediaId = (string) Str::uuid();
    variantFor($siteMediaId, 'maximized', 4000, 2600);
    variantFor($siteMediaId, 'optimized', 2400, 1560);
    $asset = assetRow(['site_media_id' => $siteMediaId, 'width' => 9999, 'height' => 9999]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id]['url'])->toContain('optimized')
        ->and($out[$asset->id]['width'])->toBe(2400)
        ->and($out[$asset->id]['height'])->toBe(1560);
});

it('falls back to any webp tier when optimized is missing', function () {
    $siteMediaId = (string) Str::uuid();
    variantFor($siteMediaId, 'maximized');
    $asset = assetRow(['site_media_id' => $siteMediaId]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id]['url'])->toContain('maximized');
});

it('passes source_url through unchanged, last in precedence', function () {
    $asset = assetRow(['source_url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360]);

    $out = app(MediaUrlResolver::class)->resolve([$asset]);

    expect($out[$asset->id])->toBe(['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360]);
});

it('omits an asset that resolves to nothing — absent, never null', function () {
    $pointerless = assetRow();
    $deadPointer = assetRow(['site_media_id' => (string) Str::uuid()]); // no variants exist

    $out = app(MediaUrlResolver::class)->resolve([$pointerless, $deadPointer]);

    expect($out)->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Media/MediaUrlResolverTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement**

`app/Services/Media/MediaUrlResolver.php`:

```php
<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use Illuminate\Support\Facades\Storage;

/**
 * The ONE media-asset → servable-URL seam (slice 1a §3.3).
 *
 * Precedence per asset: storage_path (owned bytes, 1b's Instagram mirror
 * feeds this) → site_media_id (best webp rendition out of the working
 * variant pipeline) → source_url (vendor link, passed through) → omitted.
 * An unresolvable asset is ABSENT from the result, never null — the ten
 * ref-only Google assets degrade to an empty gallery, not broken images.
 *
 * Batched by construction: one variant query for the whole page of items.
 * This sits on the public profile hot path behind the 60s payload cache.
 */
class MediaUrlResolver
{
    /** Delivery preference. 'optimized' is the in-page/gallery tier (config/partna.php image_variants). */
    private const TIER_ORDER = ['optimized', 'maximized'];

    /**
     * @param  iterable<object>  $assets  rows carrying id, source_url, storage_path, site_media_id, width, height
     * @return array<string, array{url: string, width: int|null, height: int|null}> keyed by media_assets.id
     */
    public function resolve(iterable $assets): array
    {
        $assets = collect($assets)->unique('id');

        $variantByMedia = $this->bestVariants(
            $assets->filter(fn (object $a) => empty($a->storage_path) && ! empty($a->site_media_id))
                ->pluck('site_media_id')->unique()->values()->all()
        );

        $out = [];
        foreach ($assets as $asset) {
            $resolved = $this->resolveOne($asset, $variantByMedia);
            if ($resolved !== null) {
                $out[(string) $asset->id] = $resolved;
            }
        }

        return $out;
    }

    /** @return array{url: string, width: int|null, height: int|null}|null */
    private function resolveOne(object $asset, array $variantByMedia): ?array
    {
        if (! empty($asset->storage_path)) {
            return [
                'url' => Storage::disk((string) config('partna.media_disk'))->url((string) $asset->storage_path),
                'width' => $asset->width === null ? null : (int) $asset->width,
                'height' => $asset->height === null ? null : (int) $asset->height,
            ];
        }

        $variant = ! empty($asset->site_media_id) ? ($variantByMedia[(string) $asset->site_media_id] ?? null) : null;
        if ($variant !== null && $variant->url !== '') {
            // The variant row's OWN dims: renditions are capped, and the
            // asset row's dims describe the original.
            return [
                'url' => $variant->url,
                'width' => $variant->width === null ? null : (int) $variant->width,
                'height' => $variant->height === null ? null : (int) $variant->height,
            ];
        }

        if (! empty($asset->source_url)) {
            return [
                'url' => (string) $asset->source_url,
                'width' => $asset->width === null ? null : (int) $asset->width,
                'height' => $asset->height === null ? null : (int) $asset->height,
            ];
        }

        return null;
    }

    /**
     * One query for the whole batch; best webp rendition per site_media.
     *
     * @param  list<string>  $siteMediaIds
     * @return array<string, MediaVariant>
     */
    private function bestVariants(array $siteMediaIds): array
    {
        if ($siteMediaIds === []) {
            return [];
        }

        $rank = array_flip(self::TIER_ORDER);

        return MediaVariant::query()
            ->whereIn('media_id', $siteMediaIds)
            ->where('artifact_type', 'webp')
            ->get()
            ->groupBy('media_id')
            ->map(fn ($variants) => $variants->sortBy(
                fn (MediaVariant $v) => $rank[$v->variant_key] ?? PHP_INT_MAX
            )->first())
            ->all();
    }
}
```

Check `MediaVariant`'s model class location/namespace matches the import (`App\Models\Core\MediaVariant`) and that `media_id` is the FK name — both verified against `app/Models/Core/Site/SiteMedia.php:238-241` while writing this plan.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Media/MediaUrlResolverTest.php`
Expected: PASS. Then `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Media/MediaUrlResolver.php tests/Feature/Media/MediaUrlResolverTest.php
git commit -m "feat(media): MediaUrlResolver — the one asset→servable-URL seam (storage_path → variant → source_url → omit)"
```

---

### Task 5: `frames[]` on the pool payload

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php` — constructor, `itemPayloads()` (`$covers` query :301-311, payload build :338), `cover()` (:404-414)
- Create: `tests/Feature/Content/MediaPoolFramesTest.php`

**Interfaces:**
- Consumes: Task 4's `MediaUrlResolver::resolve()`.
- Produces (the wire change, recorded in Task 8):
  - every pool item gains `'frames' => list<array{url: string, width: int|null, height: int|null, role: string, alt: string|null}>` — populated only for `kind === 'media'`, `[]` for every other kind;
  - `thumbnail` stays a **bare string**, keeps `cover → poster → gallery` role priority, but now resolves through `MediaUrlResolver` (pure passthrough for source_url-only assets — byte-identical for watch/listen/events).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Content/MediaPoolFramesTest.php` (reuses `poolTenant()`/`poolSource()`/`poolItem()` helpers defined in `tests/Feature/Content/PoolLaneTest.php` — Pest test-file functions are global; if collisions arise, name new local helpers with a `mediaFrames` prefix):

```php
<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.5: media items ship every frame (positional, dimensioned);
// every other kind ships frames: [] — the wire shape does not change with
// kind. thumbnail STAYS a bare string with cover→poster→gallery priority.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

function frameAsset(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('content.media_assets')->insert(array_merge([
        'id' => $id, 'user_id' => $userId, 'fingerprint' => 'url-'.sha1($id),
        'source_url' => null, 'storage_path' => null, 'site_media_id' => null,
        'width' => null, 'height' => null, 'created_at' => now(),
    ], $overrides));

    return $id;
}

function frameRow(string $itemId, string $sourceId, string $assetId, string $role, int $position, ?string $alt = null): void
{
    DB::table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'asset_id' => $assetId, 'role' => $role, 'position' => $position,
        'alt_text' => $alt, 'created_at' => now(),
    ]);
}

it('ships ordered frames with dims for a multi-frame media item, omitting the unresolvable', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Gallery shot', '2026-08-01T00:00:00Z');

    $a = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/a.jpg', 'width' => 800, 'height' => 600]);
    $b = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/b.jpg', 'width' => 640, 'height' => 480]);
    $dead = frameAsset($pro->id); // ref-only Google shape: no url anywhere

    frameRow($itemId, $sourceId, $b, 'gallery', 1, 'second');
    frameRow($itemId, $sourceId, $a, 'cover', 0, 'first');
    frameRow($itemId, $sourceId, $dead, 'gallery', 2);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toBe([
        ['url' => 'https://cdn.example.com/a.jpg', 'width' => 800, 'height' => 600, 'role' => 'cover', 'alt' => 'first'],
        ['url' => 'https://cdn.example.com/b.jpg', 'width' => 640, 'height' => 480, 'role' => 'gallery', 'alt' => 'second'],
    ])->and($item['thumbnail'])->toBe('https://cdn.example.com/a.jpg');
});

it('ships frames: [] for a non-media kind that carries item_media rows', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');
    $asset = frameAsset($pro->id, ['source_url' => 'https://i.ytimg.com/x.jpg']);
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toBe([])
        ->and($item['thumbnail'])->toBe('https://i.ytimg.com/x.jpg'); // unchanged for existing kinds
});

it('keeps cover→poster→gallery role priority for thumbnail, independent of position', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Priority', '2026-08-01T00:00:00Z');

    $gallery = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/gallery.jpg']);
    $poster = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/poster.jpg']);
    frameRow($itemId, $sourceId, $gallery, 'gallery', 0); // positionally FIRST
    frameRow($itemId, $sourceId, $poster, 'poster', 1);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    // Role priority wins for thumbnail; frames stay positional.
    expect($item['thumbnail'])->toBe('https://cdn.example.com/poster.jpg')
        ->and($item['frames'][0]['url'])->toBe('https://cdn.example.com/gallery.jpg');
});

it('resolves an upload-backed frame through the variant pipeline', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'media', 'Upload', '2026-08-01T00:00:00Z');

    $siteMediaId = (string) Str::uuid();
    DB::table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $siteMediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
        'path' => "variants/{$siteMediaId}/optimized.webp", 'mime' => 'image/webp',
        'width' => 2400, 'height' => 1600, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $asset = frameAsset($pro->id, ['site_media_id' => $siteMediaId]);
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'][0]['url'])->toContain('optimized')
        ->and($item['frames'][0]['width'])->toBe(2400)
        ->and($item['thumbnail'])->toBe($item['frames'][0]['url']); // frames[0] IS what thumbnail resolves to
});
```

Note: `site.media_variants` insert columns must match the stand-in created by `setupMediaTables()` (`tests/Pest.php:1179`) — check it before writing; drop `updated_at`/`created_at` keys if the stand-in lacks them.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Content/MediaPoolFramesTest.php`
Expected: FAIL — no `frames` key on payloads.

- [ ] **Step 3: Implement**

In `PoolResolver`:

1. Constructor gains the resolver:

```php
    public function __construct(
        private readonly PoolSectionProvisioner $provisioner,
        private readonly SectionCandidates $candidates,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly MediaUrlResolver $mediaUrls,
    ) {}
```

(import `App\Services\Media\MediaUrlResolver`.)

2. Widen the `$covers` query (`:301-311`):

```php
        $covers = DB::connection('pgsql')->table('content.item_media')
            ->join('content.media_assets', 'content.media_assets.id', '=', 'content.item_media.asset_id')
            ->whereIn('content.item_media.item_id', $ids)
            ->whereIn('content.item_media.role', ['cover', 'poster', 'gallery'])
            ->orderBy('content.item_media.position')
            ->get([
                'content.item_media.item_id',
                'content.item_media.role',
                'content.item_media.alt_text',
                'content.media_assets.id as asset_id',
                'content.media_assets.source_url',
                'content.media_assets.storage_path',
                'content.media_assets.site_media_id',
                'content.media_assets.width',
                'content.media_assets.height',
            ])
            ->groupBy('item_id');

        // ONE resolver call for the page — MediaUrlResolver batches its
        // variant lookup, and this sits on the public hot path.
        $resolvedUrls = $this->mediaUrls->resolve(
            $covers->flatten(1)->map(fn (object $row) => (object) [
                'id' => $row->asset_id,
                'source_url' => $row->source_url,
                'storage_path' => $row->storage_path,
                'site_media_id' => $row->site_media_id,
                'width' => $row->width,
                'height' => $row->height,
            ])
        );
```

3. In the payload loop, replace `'thumbnail' => $this->cover($covers->get($itemId, collect())),` with:

```php
                'thumbnail' => $this->cover($covers->get($itemId, collect()), $resolvedUrls),
                // Slice 1a §3.5: media items ship every frame (positional);
                // every other kind ships [] — the wire shape does not change
                // with kind, same contract startsAt/venue/price follow.
                'frames' => $item->kind === 'media'
                    ? $this->frames($covers->get($itemId, collect()), $resolvedUrls)
                    : [],
```

4. Rewrite `cover()` and add `frames()`:

```php
    /**
     * Prefer the cover, then poster, then any gallery frame — ROLE priority,
     * not positional order (frames() is the positional view). Same firstWhere
     * semantics as before slice 1a; only the URL source moved from raw
     * source_url to the resolver seam.
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     */
    private function cover(Collection $rows, array $resolved): ?string
    {
        foreach (['cover', 'poster', 'gallery'] as $role) {
            $row = $rows->firstWhere('role', $role);
            $url = $row !== null ? ($resolved[(string) $row->asset_id]['url'] ?? null) : null;
            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Every servable frame, in item_media.position order. An asset that
     * resolves to no URL is OMITTED, never emitted as null — the unrenderable
     * ref-only Google assets degrade to an empty gallery (spec §3.5).
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     * @return list<array{url: string, width: int|null, height: int|null, role: string, alt: string|null}>
     */
    private function frames(Collection $rows, array $resolved): array
    {
        $frames = [];
        foreach ($rows as $row) {
            $hit = $resolved[(string) $row->asset_id] ?? null;
            if ($hit === null) {
                continue;
            }
            $frames[] = [
                'url' => $hit['url'],
                'width' => $hit['width'],
                'height' => $hit['height'],
                'role' => (string) $row->role,
                'alt' => $row->alt_text === null ? null : (string) $row->alt_text,
            ];
        }

        return $frames;
    }
```

- [ ] **Step 4: Run the new tests plus every pool consumer**

Run: `vendor/bin/pest tests/Feature/Content/ tests/Feature/Media/`
Expected: PASS — including `PoolLaneTest` and `EventsPoolTest` untouched output (`thumbnail` byte-identical via passthrough). Then `composer test` — PASS. If any public-payload snapshot test fails on the new `frames` key, that is the additive change landing — update the snapshot and note it in the Task 8 manifest.

- [ ] **Step 5: Commit**

```bash
git add app/Site/Pools/PoolResolver.php tests/Feature/Content/MediaPoolFramesTest.php
git commit -m "feat(pools): frames[] with dimensions on pool items; thumbnail resolves through MediaUrlResolver"
```

---

### Task 6: `SECTION_SHAPE['media']` and the re-shape command

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php:71` (`SECTION_SHAPE`)
- Create: `app/Console/Commands/ReshapeMediaSectionsCommand.php`
- Modify: `tests/Unit/Site/Pools/PoolRegistryTest.php` (add shape assertion)
- Create: `tests/Feature/Content/MediaSectionReshapeTest.php`

**Interfaces:**
- Consumes: `PoolRegistry::sectionShape('media')` (existing static), `PoolSectionProvisioner`, the three cache lanes.
- Produces: new sections provision with `{"all": [{"op": "kind_is", "values": ["media"]}]}`, `order_by = 'recency'`; command `content:reshape-media-sections {--dry-run}` fixes existing rows.

**Why no new operator:** `kind_is` alone is already a valid, executed rule (one dev section carries a bare multi-kind `kind_is`), so the four-registry DSL hazard does not apply. `latest_per_auto_source` would show ONE Google photo and hide nine — the identical pathology slice 2 fixed for events.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Site/Pools/PoolRegistryTest.php` (match its existing test style — read the file first):

```php
it('provisions media with a bare kind_is rule — a gallery wants every photo, not one per source', function () {
    $shape = PoolRegistry::sectionShape('media');

    expect($shape['rule'])->toBe([['op' => 'kind_is', 'values' => ['media']]])
        ->and($shape['order_by'])->toBe('recency');
});
```

`tests/Feature/Content/MediaSectionReshapeTest.php`:

```php
<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 1a §3.6: ten dev pool:media sections carry the watch/listen default
// (latest_per_auto_source ⇒ ONE photo per connection). The constant change
// fixes new provisions; this command fixes the existing rows — matched on
// rule CONTENT, so a hand-edited section is not clobbered.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function legacyMediaSection(string $siteId): string
{
    // Exactly what PoolSectionProvisioner wrote before this slice.
    $site = Site::query()->findOrFail($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, 'media');
    DB::table('site.sections')->where('id', $section->id)->update([
        'rule' => json_encode(['all' => [
            ['op' => 'kind_is', 'values' => ['media']],
            ['op' => 'latest_per_auto_source', 'values' => ['media']],
        ]]),
    ]);

    return (string) $section->id;
}

it('reshapes a section still carrying latest_per_auto_source and fires all three cache lanes', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    $this->artisan('content:reshape-media-sections')
        ->expectsOutputToContain('reshaped 1')
        ->assertExitCode(0);

    $rule = json_decode((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'), true);
    expect($rule)->toBe(['all' => [['op' => 'kind_is', 'values' => ['media']]]])
        ->and(DB::table('site.site_documents')->where('site_id', $siteId)->exists())->toBeTrue()
        ->and(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('leaves a hand-edited rule alone, and reports it', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $custom = json_encode(['all' => [['op' => 'kind_is', 'values' => ['media']], ['op' => 'headline_has', 'values' => ['dog']]]]);
    DB::table('site.sections')->where('id', $sectionId)->update(['rule' => $custom]);

    $this->artisan('content:reshape-media-sections')->assertExitCode(0);

    expect((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'))->toBe($custom);
});

it('writes nothing under --dry-run', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $before = (string) DB::table('site.sections')->where('id', $sectionId)->value('rule');

    $this->artisan('content:reshape-media-sections', ['--dry-run' => true])->assertExitCode(0);

    expect((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'))->toBe($before);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('resolves EVERY media item under the corrected rule, not one per source', function () {
    // The spec's warning: assert against the RESOLVER, not just the constant —
    // a rule the executor does not recognise fails at runtime, not in the registry.
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id, 'google_business.location');
    $sourceId = poolSource($pro->id, $connectionId);
    $one = poolItem($pro->id, $sourceId, 'media', 'Photo 1', '2026-08-01T00:00:00Z');
    $two = poolItem($pro->id, $sourceId, 'media', 'Photo 2', '2026-08-02T00:00:00Z');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $selectedIds = collect($out['selection'])->pluck('id')->all();

    // Under latest_per_auto_source this would be ONE item. kind_is admits both.
    expect($selectedIds)->toContain($one)->toContain($two);
});
```

Check the `site.site_documents` assertion against how `BuildState::bump()` actually persists (read `app/Site/Documents/BuildState.php:21` first); if bump writes a counter column rather than inserting, assert on that column changing instead.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Site/Pools/PoolRegistryTest.php tests/Feature/Content/MediaSectionReshapeTest.php`
Expected: registry test FAILS (media falls through to the watch/listen default); reshape tests FAIL (command missing); resolver test FAILS (provisioner writes `latest_per_auto_source`, which emits one item).

- [ ] **Step 3: Implement**

`PoolRegistry::SECTION_SHAPE` (`:71`) gains:

```php
    public const SECTION_SHAPE = [
        'events' => [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'upcoming_occurrence'],
            ],
            'order_by' => 'occurrence',
        ],
        // A gallery wants EVERY photo. latest_per_auto_source is the
        // watch/listen rolling-latest semantics: one item per connection
        // source, which for media means one Google photo visible and nine
        // hidden (slice 1a §1.3 — the same pathology events hit in slice 2).
        'media' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
    ];
```

`app/Console/Commands/ReshapeMediaSectionsCommand.php` (modelled on `MigrateHiddenEventsToPoolExcludes`):

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-shape existing pool:media sections still carrying the watch/listen
 * default rule (slice 1a §3.6). PoolSectionProvisioner::ensure() early-returns
 * on an existing section, so changing SECTION_SHAPE does not reshape the ten
 * rows that exist on dev — this does.
 *
 * Matched on rule CONTENT (any predicate with op=latest_per_auto_source),
 * not blindly on the key, so a hand-edited section is never clobbered.
 * Idempotent: the corrected rule no longer matches. Read-only under --dry-run.
 */
class ReshapeMediaSectionsCommand extends Command
{
    protected $signature = 'content:reshape-media-sections
        {--dry-run : Report what would be reshaped without writing}';

    protected $description = 'Correct pool:media section rules from latest_per_auto_source to bare kind_is';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $shape = PoolRegistry::sectionShape('media');
        $reshaped = 0;
        $skipped = 0;

        $sections = DB::connection('pgsql')->table('site.sections')
            ->where('key', PoolRegistry::sectionKey('media'))
            ->get(['id', 'site_id', 'rule']);

        foreach ($sections as $section) {
            $rule = is_string($section->rule) ? json_decode($section->rule, true) : (array) $section->rule;
            $predicates = is_array($rule['all'] ?? null) ? $rule['all'] : [];

            $carriesLegacyDefault = collect($predicates)->contains(
                fn ($p) => is_array($p) && ($p['op'] ?? null) === 'latest_per_auto_source'
            );

            if (! $carriesLegacyDefault) {
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line("  + section {$section->id}: would reshape");
                $reshaped++;

                continue;
            }

            DB::connection('pgsql')->table('site.sections')
                ->where('id', $section->id)
                ->update([
                    'rule' => json_encode(['all' => $shape['rule']]),
                    'order_by' => $shape['order_by'],
                    'updated_at' => now(),
                ]);
            $reshaped++;

            // Raw-write seam: all three lanes by hand (spec §4). bump() alone
            // is not enough — the payload cache key composes from
            // sites.updated_at, and the CDN outlives the origin write.
            $site = DB::connection('pgsql')->table('site.sites')
                ->where('id', $section->site_id)->first(['id', 'subdomain']);
            if ($site !== null) {
                BuildState::bump((string) $site->id);
                DB::connection('pgsql')->table('site.sites')
                    ->where('id', $site->id)->update(['updated_at' => now()]);
                if ((string) ($site->subdomain ?? '') !== '') {
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            }
        }

        $verb = $dry ? 'would reshape' : 'reshaped';
        $this->info("pool:media sections: {$verb} {$reshaped}, left alone {$skipped}.");

        return self::SUCCESS;
    }
}
```

Check how commands register in this app (`bootstrap/app.php` auto-discovery vs explicit list) — follow whatever `MigrateHiddenEventsToPoolExcludes` needed (it appears to rely on auto-discovery; if so, nothing extra).

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Unit/Site/Pools/PoolRegistryTest.php tests/Feature/Content/MediaSectionReshapeTest.php`
Expected: PASS. Then `composer test` — PASS (PoolRegistryTest's one-pool-per-kind pin is undisturbed).

- [ ] **Step 5: Commit**

```bash
git add app/Site/Pools/PoolRegistry.php app/Console/Commands/ReshapeMediaSectionsCommand.php tests/Unit/Site/Pools/PoolRegistryTest.php tests/Feature/Content/MediaSectionReshapeTest.php
git commit -m "feat(pools): media sections provision with bare kind_is; command reshapes the legacy-default rows"
```

---

### Task 7: `MediaUploadBackfiller`

**Files:**
- Create: `app/Services/Migration/MediaUploadBackfiller.php`
- Create: `app/Console/Commands/BackfillUploadMediaCommand.php`
- Create: `tests/Feature/Content/MediaUploadBackfillerTest.php`

**Interfaces:**
- Consumes: Task 3's upload entry contract; `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string`; `SiteMedia` model (`GALLERY_POOLS`, `PROCESSING_STATE_READY`, `mediaVariants` relation); the three cache lanes.
- Produces:

```php
namespace App\Services\Migration;

class MediaUploadBackfiller
{
    /** @return array{backfilled: int, skipped_not_ready: int, skipped_no_variant: int, failed: int} */
    public function run(bool $dryRun = false, ?string $siteId = null): array
}
```

Command: `content:backfill-upload-media {--dry-run} {--site=}`.

**Rules pinned by the spec:**
- Scope: live (`deleted_at IS NULL`) `site.site_media` with `pool IN ('gallery','content')` — 25 rows on dev. `design`/`documents` stay put.
- Coord: `manual:{site_media_uuid}` — the legacy identifier survives the table's eventual demotion, and a re-run updates rather than duplicates.
- `user_id` derived through the site, **failing loudly** (counted in `failed`, warned by name) when a site has no owner.
- Only `processing_state='ready'` is eligible; everything else skipped AND counted.
- A row with no webp variant (e.g. a video upload) is skipped AND counted separately — an item whose one frame can never resolve would be an empty card, and the honest number belongs in the dry-run report.
- Headline: `caption`, else `alt_text`, else none (null `headline_cache` is legitimate for a photo — the "does a photo need a headline" question is explicitly 1b's).
- Backfill must NOT resurrect a user-removed item — `writeManualItem` already guarantees this (only `PoolItemCreateController` clears `items.removed_at`, deliberately).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Content/MediaUploadBackfillerTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Services\Migration\MediaUploadBackfiller;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 1a §3.7: the 25 live gallery/content uploads become media items
// through the slice-0b manual lane — production code, tested, idempotent,
// re-runnable (convergence invariant #4). Never raw writes into content.*.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

function uploadRow(string $siteId, array $overrides = [], bool $withVariant = true): string
{
    $id = (string) Str::uuid();
    DB::table('site.site_media')->insert(array_merge([
        'id' => $id, 'site_id' => $siteId, 'bucket' => 'media',
        'path' => "uploads/{$id}.jpg", 'pool' => 'gallery', 'media_type' => 'image',
        'processing_state' => 'ready', 'is_active' => 1, 'sort_order' => 0,
        'alt_text' => 'An upload', 'caption' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));

    if ($withVariant) {
        DB::table('site.media_variants')->insert([
            'id' => (string) Str::uuid(), 'media_id' => $id,
            'variant_key' => 'optimized', 'artifact_type' => 'webp', 'disk' => 'media',
            'path' => "variants/{$id}/optimized.webp", 'mime' => 'image/webp',
            'width' => 2400, 'height' => 1600, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

it('backfills a ready gallery upload as a media item with a variant-backed asset', function () {
    [$pro, $siteId] = poolTenant();
    $mediaId = uploadRow($siteId, ['caption' => 'Our shopfront']);

    $result = app(MediaUploadBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1);

    $asset = DB::table('content.media_assets')->where('user_id', $pro->id)->first();
    expect($asset->fingerprint)->toBe('url-'.sha1('upload:'.$mediaId))
        ->and($asset->site_media_id)->toBe($mediaId)
        ->and($asset->dims_confidence)->toBe('measured')
        ->and((int) $asset->width)->toBe(2400);

    $item = DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'media')->first();
    expect($item->headline_cache)->toBe('Our shopfront');

    $coord = DB::table('content.source_items')->value('coord');
    expect($coord)->toBe('manual:'.$mediaId);
});

it('is idempotent across two runs — one item, one asset', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $backfiller = app(MediaUploadBackfiller::class);
    $backfiller->run();
    $backfiller->run();

    expect(DB::table('content.items')->where('user_id', $pro->id)->where('kind', 'media')->count())->toBe(1)
        ->and(DB::table('content.media_assets')->where('user_id', $pro->id)->count())->toBe(1);
});

it('skips and counts non-ready, variantless, and out-of-scope rows', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);                                                     // eligible
    uploadRow($siteId, ['processing_state' => 'failed']);                   // skipped: not ready
    uploadRow($siteId, ['media_type' => 'video'], withVariant: false);      // skipped: no webp variant
    uploadRow($siteId, ['pool' => 'design']);                               // out of scope entirely
    uploadRow($siteId, ['pool' => 'documents']);                            // out of scope entirely
    uploadRow($siteId, ['deleted_at' => now()]);                            // soft-deleted: out of scope

    $result = app(MediaUploadBackfiller::class)->run();

    expect($result['backfilled'])->toBe(2) // gallery + design? NO — see assertion below
        ->and($result['skipped_not_ready'])->toBe(1)
        ->and($result['skipped_no_variant'])->toBe(1);
})->todo('fix the expected backfilled count when writing this test: pool=design and pool=documents rows must NOT appear in ANY counter — scope them out in the query, then assert backfilled === 1');

it('does not resurrect a user-removed upload item on re-run', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $backfiller = app(MediaUploadBackfiller::class);
    $backfiller->run();
    DB::table('content.items')->where('user_id', $pro->id)->update(['removed_at' => now()]);

    $backfiller->run();

    expect(DB::table('content.items')->where('user_id', $pro->id)->whereNotNull('removed_at')->count())->toBe(1);
});

it('fires all three cache lanes for the touched site, and only for touched sites', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    app(MediaUploadBackfiller::class)->run();

    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
    // BuildState::bump asserted per the mechanism BuildState actually uses —
    // read app/Site/Documents/BuildState.php and assert its persisted effect.
});

it('writes nothing under --dry-run and reports the would-be counts', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);

    $this->artisan('content:backfill-upload-media', ['--dry-run' => true])
        ->expectsOutputToContain('would backfill 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->count())->toBe(0);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

// §8.3 regression (spec §5.2): mergeInto() hard-deletes a discarded item
// carrying neither pin nor override; preferOwnerAnchored() should make the
// owner row win, but it has never been exercised against a media-kind merge.
it('keeps every backfilled upload item alive through a Google Business media projection run', function () {
    [$pro, $siteId] = poolTenant();
    uploadRow($siteId);
    uploadRow($siteId, ['sort_order' => 1]);
    app(MediaUploadBackfiller::class)->run();

    $uploadItemIds = DB::table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'media')->pluck('id')->all();
    expect($uploadItemIds)->toHaveCount(2);

    // A connector-lane media projection for the same user. Follow the
    // manualLaneBandcamp() pattern in tests/Feature/Ingest/ManualSourceLaneTest.php
    // to build a google_business connection source + ingest stream whose
    // record projects through GoogleBusinessMediaProjector's shape
    // (ref-only media entry, kind 'media'), then:
    //   $writer->projectStream($source, $streamId, 'media');
    [$source, $streamId] = mediaLaneGoogleStream($pro->id, 'places/ChIJx/photos/AXCi2');
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');

    $survivors = DB::table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'media')
        ->whereNull('removed_at')->pluck('id')->all();

    foreach ($uploadItemIds as $id) {
        expect($survivors)->toContain($id);
    }
    // And their frames still resolve on the pool read:
    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $byId = collect($out['library'])->keyBy('id');
    foreach ($uploadItemIds as $id) {
        expect($byId[$id]['frames'][0]['url'] ?? null)->not->toBeNull();
    }
});
```

Write the `mediaLaneGoogleStream()` helper by copying `manualLaneBandcamp()` from `ManualSourceLaneTest.php` and adapting kind/stream/payload to the Google Business media stream (look at `tests/Feature/Ingest/GoogleBusinessConnectorTest.php` for the record shape its media stream lands). Resolve the `->todo` test's counts while writing it — the todo marks the one place this plan could not pre-compute the number without the query written.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Content/MediaUploadBackfillerTest.php`
Expected: FAIL — `MediaUploadBackfiller` does not exist.

- [ ] **Step 3: Implement the backfiller**

`app/Services/Migration/MediaUploadBackfiller.php`:

```php
<?php

namespace App\Services\Migration;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\SiteMedia;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 1a §3.7: land the live gallery/content uploads as media-kind content
 * items, through the slice-0b manual lane — NEVER raw writes into content.*.
 *
 * Coord manual:{site_media_uuid}: stable, so a re-run UPDATES (idempotent),
 * and the legacy identifier survives site.site_media's eventual demotion
 * (slice 7). Deliberately does not resurrect user-removed items —
 * writeManualItem() clears only source-level absence; only a person
 * re-adding means "bring it back" (PoolItemCreateController's job).
 *
 * design/documents pools stay put (parent spec §2.1): design assets are
 * brand chrome, documents are downloads — neither is gallery content.
 */
class MediaUploadBackfiller
{
    /** Delivery-tier preference when reading dims/mime for the asset row. */
    private const TIER_ORDER = ['optimized', 'maximized'];

    public function __construct(private readonly ProjectionWriter $writer) {}

    /** @return array{backfilled: int, skipped_not_ready: int, skipped_no_variant: int, failed: int} */
    public function run(bool $dryRun = false, ?string $siteId = null): array
    {
        $result = ['backfilled' => 0, 'skipped_not_ready' => 0, 'skipped_no_variant' => 0, 'failed' => 0];
        $touchedSites = [];

        $rows = SiteMedia::query()
            ->whereIn('pool', SiteMedia::GALLERY_POOLS)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->with(['site', 'mediaVariants'])
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $media) {
            try {
                if ($media->processing_state !== SiteMedia::PROCESSING_STATE_READY) {
                    $result['skipped_not_ready']++;

                    continue;
                }

                $variant = $this->bestVariant($media);
                if ($variant === null) {
                    // A frame that can never resolve would be an empty card.
                    // Counted, not silently dropped — the operator decides.
                    $result['skipped_no_variant']++;

                    continue;
                }

                // Fail LOUDLY on an ownerless site (parent spec §8.2) — a
                // silent skip here is an upload that vanishes from the count.
                $userId = $media->site?->user_id;
                if ($userId === null) {
                    throw new \RuntimeException("site_media {$media->id}: site or owner missing.");
                }

                if ($dryRun) {
                    $result['backfilled']++;

                    continue;
                }

                $headline = trim((string) ($media->caption ?? '')) ?: trim((string) ($media->alt_text ?? ''));

                $projection = [
                    'kind' => 'media',
                    'media' => [[
                        'role' => 'cover',
                        'site_media_id' => (string) $media->id,
                        'alt' => $media->alt_text,
                        'width' => $variant->width === null ? null : (int) $variant->width,
                        'height' => $variant->height === null ? null : (int) $variant->height,
                        'mime_type' => $variant->mime,
                    ]],
                ];
                if ($headline !== '') {
                    $projection['headline'] = $headline;
                }

                $this->writer->writeManualItem((string) $userId, 'manual:'.$media->id, $projection);

                $touchedSites[(string) $media->site_id] = true;
                $result['backfilled']++;
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Media upload backfill failed for one row.', [
                    'site_media_id' => $media->id, 'error' => $e->getMessage(),
                ]);
                $result['failed']++;
            }
        }

        if (! $dryRun) {
            $this->invalidate(array_keys($touchedSites));
        }

        return $result;
    }

    private function bestVariant(SiteMedia $media): ?MediaVariant
    {
        $webp = $media->mediaVariants->where('artifact_type', 'webp');
        foreach (self::TIER_ORDER as $tier) {
            $hit = $webp->firstWhere('variant_key', $tier);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $webp->first();
    }

    /**
     * Raw-write seam — all three lanes per touched site (spec §4).
     * writeManualItem() bumped build state per item already; updated_at and
     * the edge purge are the two lanes it deliberately does not own.
     *
     * @param  list<string>  $siteIds
     */
    private function invalidate(array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            BuildState::bump($siteId);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);
            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');
            if ($subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
}
```

Note `SiteMedia` uses `SoftDeletes`, so the query excludes `deleted_at IS NOT NULL` rows automatically — that IS the "live" scope.

`app/Console/Commands/BackfillUploadMediaCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\Migration\MediaUploadBackfiller;
use Illuminate\Console\Command;

/**
 * Artisan wrapper for the slice-1a upload backfill (spec §3.7). Idempotent,
 * re-runnable; the coord is the site_media uuid, so a second run updates.
 */
class BackfillUploadMediaCommand extends Command
{
    protected $signature = 'content:backfill-upload-media
        {--dry-run : Report counts without writing}
        {--site= : Only this site id}';

    protected $description = 'Backfill live gallery/content uploads as media-kind content items';

    public function handle(MediaUploadBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $backfiller->run($dry, $this->option('site') ?: null);

        $verb = $dry ? 'would backfill' : 'backfilled';
        $this->info("Uploads: {$verb} {$result['backfilled']}, "
            ."skipped {$result['skipped_not_ready']} not-ready, "
            ."{$result['skipped_no_variant']} without a webp variant"
            .($result['failed'] > 0 ? ", {$result['failed']} FAILED" : '.'));

        if ($result['failed'] > 0) {
            $this->warn('Failures are reported to Nightwatch and logged with site_media ids. Fix and re-run — the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/Content/MediaUploadBackfillerTest.php`
Expected: PASS, including the §8.3 merge regression. If the merge regression FAILS (an upload item merged away), STOP — that is `preferOwnerAnchored()` not covering the media-kind merge, which spec §5.2 flags as never-exercised. Diagnose with superpowers:systematic-debugging before touching `mergeInto()`; a fix there is hot-path and needs its own review.

Then `composer test` — PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Migration/MediaUploadBackfiller.php app/Console/Commands/BackfillUploadMediaCommand.php tests/Feature/Content/MediaUploadBackfillerTest.php
git commit -m "feat(content): MediaUploadBackfiller — gallery/content uploads through the manual lane, idempotent"
```

---

### Task 8: Wire-change manifest

**Files:**
- Create: `docs/wire-changes/2026-08-12-media-pool-slice-1a.md`

**Interfaces:** none in code — this is the slice's public deliverable to the two frontend repos (spec scope boundary: backend only; the dashboard Media page and public gallery render are follow-on work in those repos).

- [ ] **Step 1: Write the manifest** (model on `docs/wire-changes/2026-08-11-slice2-events-pool.md` — same structure: endpoint, consuming repos, before/after, render guidance):

```markdown
# Wire change — slice 1a, media pool (2026-08-12)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-media-pool-slice-1a-design.md`, owner decision 2026-08-12).

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:** partna-monorepo (`@partnaau/design-system`), Partna-App.

### Changed: every `poolItem` gains `frames`

Additive on every pool — same contract `startsAt`/`venue`/`price` follow.
No existing key changed or removed.

    "frames": [
      { "url": "https://…", "width": 2400, "height": 1600,
        "role": "cover", "alt": "A shopfront" }
    ]

- Populated ONLY for `kind: "media"` items; `[]` for every other kind.
- Ordered by the item's frame position (positional, NOT role priority).
- `width`/`height` are the SERVED rendition's dimensions — reserve layout
  space with them; they are why the key exists. Either may be null.
- `role` is one of `cover|gallery|poster|avatar|logo`.
- A frame that cannot resolve to a URL is OMITTED, never null — an item can
  legitimately carry `frames: []` (today: the ten ref-only Google photos,
  which 1b makes servable).

### Unchanged, deliberately: `thumbnail`

Still a bare string (cover → poster → gallery role priority), on every pool.
For a media item, `frames[0]` is the same asset `thumbnail` resolves to —
read dimensions from there. Making `thumbnail` an object was rejected: it is
live on watch/listen/events today and would break three surfaces to serve one.

### Now populated: `pools.media`

The media pool now resolves upload-backed items (25 on dev after backfill).
The legacy `gallery` / `designMedia` payload keys STAY until both frontends
stop reading them — nothing to change today, but new gallery work should
read `pools.media` + `frames`, not the legacy keys.
```

- [ ] **Step 2: Cross-check the manifest against the shipped code** — every claim in it must match `PoolResolver::itemPayloads()` as merged (key name `frames`, field names `url/width/height/role/alt`, the media-kind gate, the omission rule).

- [ ] **Step 3: Commit**

```bash
git add docs/wire-changes/2026-08-12-media-pool-slice-1a.md
git commit -m "docs(wire): slice 1a manifest — frames[] lands, thumbnail stays a string"
```

---

### Task 9: Dev execution, live verification, prod safety check

**Files:** none created — this task is the runbook. Its output is pasted into the execution checkpoint (spec §5.1 requires the live outputs recorded).

- [ ] **Step 1: Full local gate**

```bash
composer test
vendor/bin/pint --dirty
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all green. (Schema/PG lanes: `composer test:schema` needs the applied schema — it runs meaningfully only after Step 3.)

- [ ] **Step 2: Prod safety re-verification (spec §3.1 — do NOT assume dev is representative)**

Against prod (`edplucmvkcnokyygxqsb`), read-only:

```sql
SELECT count(*) AS media_assets FROM content.media_assets;
SELECT count(*) AS media_items FROM content.items WHERE kind = 'media';
```

Expected: both 0 (prod carries no customer data). Paste into the checkpoint. If nonzero: STOP and check each row's fingerprint would be stable under ref-preference (`fingerprint = 'url-'.sha1(ref)` already, or no ref emitted) before merging.

- [ ] **Step 3: Apply the migration to dev Supabase** (memory: dev migration BEFORE merge)

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run   # confirm exactly the one new file
supabase db push
```

Then run the Postgres-lane / schema-lane tests that need the applied column, per `reference_schema_lane_local_invocation`:

```bash
composer test:schema
```

Expected: `MediaAssetSiteMediaFkTest` passes (delete_rule = SET NULL).

- [ ] **Step 4: PR → merge to development → deploy** (normal flow; CI gate runs on `development`).

- [ ] **Step 5: Run the two commands on dev, dry-run first, output pasted**

```bash
cloud command:run development "content:reshape-media-sections --dry-run"
cloud command:run development "content:reshape-media-sections"
cloud command:run development "content:backfill-upload-media --dry-run"
cloud command:run development "content:backfill-upload-media"
```

Expected: reshape reports 10 reshaped; backfill dry-run reports 25 (16 gallery + 9 content). If the dry-run count is NOT 25, reconcile against `SELECT pool, processing_state, media_type, count(*) FROM site.site_media WHERE deleted_at IS NULL AND pool IN ('gallery','content') GROUP BY 1,2,3;` before running live — a not-ready or variantless tail is expected to show up in the skip counters, not silently shrink the total.

- [ ] **Step 6: The §5.1 SQL assertions against dev, output pasted**

```sql
-- items rise from 10 to 35
SELECT kind, count(*) FROM content.items WHERE kind='media' AND removed_at IS NULL GROUP BY 1;

-- 25 upload-backed assets
SELECT count(*) FILTER (WHERE site_media_id IS NOT NULL) AS uploads,
       count(*) AS total FROM content.media_assets;

-- no pool:media section still carries latest_per_auto_source
SELECT count(*) FROM site.sections
WHERE kind='collection' AND rule::text LIKE '%latest_per_auto_source%'
  AND rule::text LIKE '%"media"%';

-- every backfilled upload is reachable as an item the media rule matches
SELECT count(*) FROM content.items i
JOIN content.source_items si ON si.item_id = i.id
JOIN content.sources s ON s.id = si.source_id
WHERE i.kind='media' AND i.removed_at IS NULL
  AND s.kind='manual' AND si.coord LIKE 'manual:%';
```

Expected: 35 / (25, 510) / 0 / 25. Adjust expectations only against Step 5's reconciled counts, never silently.

- [ ] **Step 7: The resolver proof — no SQL stands in for it (spec §5.1)**

Via `cloud tinker development`, for the dev account that owns the uploads:

```php
$site = \App\Models\Core\Site\Site::query()->whereHas('user')->first(); // pick the known dev account explicitly
$out = app(\App\Site\Pools\PoolResolver::class)->resolve($site, 'media');
collect($out['library'])->map(fn ($i) => [$i['id'], $i['frames'][0]['url'] ?? null])->all();
```

Expected: the 25 upload items each carry a non-null `frames[0].url`. **`section_items` count is NOT the proof** — pins are the curation half; the corrected `kind_is` rule matches backfilled uploads in the auto half, so a fully working pool reads `section_items = 0`.

- [ ] **Step 8: Post-deploy scans — BOTH, not just logs** (spec §5.4 names the slice-0 gap)

```bash
cloud env:logs partna development --minutes 10
```

Expected: clean. Then check Nightwatch (`list_issues` for the partna app, development environment) — no new exceptions. Record both scans in the checkpoint.

- [ ] **Step 9: Bookkeeping**

Update the parent convergence spec's slice table / memory per house convention, and record the checkpoint with all pasted outputs.

---

## Self-Review (performed while writing)

- **Spec coverage:** §3.1 → Task 2; §3.2 → Task 1; §3.3 → Task 4; §3.4 → Task 3; §3.5 → Task 5; §3.6 → Task 6; §3.7 → Task 7; §4 (three lanes) → Tasks 6+7 tests and implementations; §5.1 → Task 9 Steps 5-7; §5.2 → Tasks 2-7 test lists; §5.3 → Task 1 (role check honoured in Task 5's output; fingerprint constraint exercised in Tasks 2-3; FK behaviour pinned in the PG lane); §5.4 → Task 9 Step 8; §7/§8 out-of-scope — nothing here touches `mapPhoto()`, Instagram streams, `content_selection`, or the legacy wire keys.
- **Decisions this plan closed that the spec left open:** FK `ON DELETE SET NULL` (Task 1 rationale); webp tier order `optimized → maximized → any` (Task 4); upload frame role `cover` (single-frame item — makes `thumbnail === frames[0].url` hold by construction); headline `caption → alt_text → none`; variantless rows skipped+counted (extends the spec's skip rule from `processing_state` to "can never render", same honesty contract).
- **Known open point for the executor:** the `->todo` test in Task 7 Step 1 — the skip-counter expectations must be finalised when the scope query exists.
- **Type consistency:** `MediaUrlResolver::resolve()` return shape (`array<string, array{url, width, height}>`) is consumed identically in Tasks 5 and 7; the upload entry contract in Task 3's Interfaces block is exactly what Task 7's projection emits.
