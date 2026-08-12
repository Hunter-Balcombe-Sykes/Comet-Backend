# Slice 1a — the media asset spine and the upload lane

Sub-slice of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 1 — Media
pool live · XL". Slice 1 is cut in two; this is the first half.

- **1a (this spec)** — the asset/URL seam, the pool read path, and owner uploads.
  No external sync, no new billing.
- **1b (later spec)** — Google URL pass-through, Instagram streams and R2
  mirroring, and the 91 `site.content_selection` rows.

**Scope boundary set 2026-08-12:** backend only. The dashboard Media page
(Partna-App) and the public gallery render (monorepo) are follow-on work; this
slice's public deliverable is the JSON payload plus a wire-change manifest
telling both repos what landed. The legacy `gallery` / `designMedia` wire keys
therefore **stay**, because they cannot retire until the frontends stop reading
them.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-12

Every figure below was read from the database while writing this spec, per
convergence invariant #1. Where it contradicts the parent spec, the correction
is stated.

```
content.items kind='media'      : 10   (all google_business, all headline_cache NULL)
content.media_assets            : 485  (0 with storage_path)
site.content_selection          : google-photo 82, ig-post 4, ig-reel 2, upload 3
site.site_media (deleted_at NULL): gallery 16, content 9, design 11, documents 2
site.media_variants             : 80
content.sources                 : connection 28 @p100, manual 1 @p200
ingest.sources                  : google_business 12 (auto=false; 1 has run),
                                  instagram 1 (auto=false, never run)
ingest.streams                  : google_business/media 1 (has run)
ingest.effects                  : 1 row, api/places.details, ok
site.sections pool:media        : 10 sections, 0 with section_items
```

### 1.1 Ten media items already exist, and none of them can be shown

The parent spec was written when `content.items WHERE kind='media'` was **0**.
It is now **10**. They landed as a side effect of slice 0's single billed Places
Details verification run on 2026-08-12 — the `google_business/media` stream ran
alongside `profile` and `reviews`.

They are not usable:

- `headline_cache` is NULL on all ten.
- Their `content.media_assets` rows have `source_url` NULL. `PoolResolver`'s
  `$covers` query (`app/Site/Pools/PoolResolver.php:301-312`) selects
  `media_assets.source_url`, and `cover()` (`:404-414`) returns the first
  non-empty one. For these rows it returns null.

The cause is deliberate, and correct: `GoogleBusinessMediaProjector` emits the
Places photo *resource name* as `ref` and no URL, because a keyed Places media
URL would write the API key into content rows. Its docblock says the
byte-mirroring pipeline "resolves it later". **No such pipeline exists for
`content.media_assets`** — the resolver seam this slice builds is that later.

### 1.2 A working URL → owned-bytes → `media_assets` pipeline already exists

Parent spec §2.1 frames this as a *two*-writer problem: `ProjectionWriter`
versus a proposed upload writer. There is a third.

`App\Services\Brand\BrandAssetPipeline` fetches through `SafeUrlFetcher`,
re-encodes to webp, puts to the configured media disk, and inserts
`content.media_assets` with `storage_path`, `mime_type`, measured `width` /
`height`, `dims_confidence='measured'` and `variant_family='native'`
(`app/Services/Brand/BrandAssetPipeline.php:187-222`). It writes into the same
`UNIQUE (user_id, fingerprint)` index as `ProjectionWriter`, keyed by a **content
hash** with no `url-` prefix — deliberately, per its own `#PRIV-5` comment, so
minimising a URL cannot re-mint or collide the row.

It has produced zero rows on dev (0 of 485 assets carry `storage_path`), so it
is built and unexercised. It is the natural donor for 1b's Instagram mirroring.
1a does not touch it; 1a only ensures the read path it would feed exists.

**Consequence for §2.1's decision.** The parent chose option (a) — extend
`ProjectionWriter` rather than admit a second writer — on the grounds that two
writers into one uniqueness constraint is how silent duplication starts. That
reasoning stands and 1a follows it, but the premise ("exactly two") was already
false when written. The mitigating fact is that the three writers occupy
disjoint fingerprint shapes: `url-<sha1>` (ProjectionWriter), bare content hash
(BrandAssetPipeline), and — after this slice — `url-sha1('upload:{id}')`, which
is a ProjectionWriter fingerprint by construction because ProjectionWriter mints
it.

### 1.3 The media pool is provisioned with the wrong rule

Ten `pool:media` sections exist on dev, each with:

```json
{"all": [{"op": "kind_is", "values": ["media"]},
         {"op": "latest_per_auto_source", "values": ["media"]}]}
```

`order_by = 'recency'`, `section_items` = 0.

This is the watch/listen default, inherited because `PoolRegistry::SECTION_SHAPE`
(`app/Site/Pools/PoolRegistry.php:71`) has an entry only for `events`.
`latest_per_auto_source` emits exactly ONE item per connection source. For a
gallery that means one Google photo visible and nine hidden — the identical
pathology slice 2 found for events, and the reason events got its own shape.

Unlike events, **this needs no new operator.** A rule of `kind_is` alone is
already valid and already in use (one section on dev carries a bare multi-kind
`kind_is`), so the four-registry DSL hazard — enum + `phrase()` +
`EXECUTED_OPERATORS` + `ORDER_BY`, miss one and it is a 500 rather than a red
test — does not apply. The change is a `SECTION_SHAPE` entry.

`PoolSectionProvisioner::ensure()` early-returns on an existing section
(`app/Site/Pools/PoolSectionProvisioner.php:26-33`), so changing the constant
does **not** reshape the ten rows that exist. They need an explicit
re-provision, exactly as slice 2's §14.3 migration commands did for events.

### 1.4 The parent spec's §2.4 Google recommendation is sound

§2.4 recommends migrating the 82 `google-photo` selections by reading servable
URLs out of the legacy `site.platform_connections` payload rather than
re-billing Places. Verified: 150 of 150 photo entries across all connections
carry a `url` key, and the values are unkeyed
`https://lh3.googleusercontent.com/place-photos/...` links.

`GoogleBusinessService::resolvePhotoUrls()` (`:516`) makes the billed per-photo
media call, and `carryForwardPhotoUrls()` (`:474`) re-uses stored URLs on
refresh so a re-sync does not re-bill. That is why the payload has them.

Meanwhile `GoogleBusinessConnector::mapPhoto()` (`:256-275`) emits only `ref`,
`width_px`, `height_px`, reading the raw Places shape (`$photo['name']`) rather
than the mapped one. **The URL exists and the ingest lane discards it.** Fixing
that is 1b's first unit, not this slice's — but it is why item 1 of §3 below is
ordered where it is.

---

## 2. Why uploads first

Uploads are the only media source whose bytes we already own and whose
correctness does not depend on a vendor. If the read path is wrong, uploads
expose it without a paid call or an expiring link confusing the diagnosis.

They also satisfy convergence invariant #2 — a kind is not adopted until
something reads it — on the day the slice lands, because the pool read path and
the items arrive together.

And they de-risk the slice's one genuinely dangerous change: the
`ProjectionWriter` hot-path branch is exercised against 25 rows we control
before any connector writes media through it.

---

## 3. The change

Seven units. Order matters between 1 and everything in 1b; the rest is free.

### 3.1 `mediaFingerprint()` prefers `ref` over `url`

`app/Ingest/Projection/ProjectionWriter.php:1147-1159` currently computes
`$fingerprint = $url ?? $ref` — URL wins. Its consumers' docblocks claim the
opposite: `InstagramMediaProjector` says the fingerprint is "keyed on the
shortcode-stable ref, not the signed URL".

Invert it: `$fingerprint = $ref ?? $url`. `source_url` is still stored, still
minimised; only the key changes.

**No data migration is required, and the reason is checkable.** Only two
projectors emit a media `ref`: `GoogleBusinessMediaProjector` (ref only, no
url) and `InstagramMediaProjector` (both). Instagram has landed zero items, and
Google's ten assets already fingerprint off the ref via the existing
`$url ?? $ref` null-URL fallback. Every fingerprint on dev is stable under the
change. The parent spec's "with a migration for any already-minted URL-keyed
assets" is unnecessary — but the plan must re-verify this against prod before
merging, not assume dev is representative.

**This is why the unit is ordered first.** The moment 1b lands the Google URL
pass-through, `mapPhoto()` starts emitting `url` beside `ref`. Under
url-preference that re-keys the ten existing Google assets, and
`resolveMediaAssets()`'s `insertOrIgnore` mints ten duplicates while
`item_media.asset_id` still points at the originals. Landing 1a before 1b is
not a preference; it is the thing that prevents the duplication.

`SecretParams::minimiseUrl()` redacts `_nc_sid` but not `oh` / `oe` /
`_nc_ohc`, so an Instagram URL re-signs to a new value every sync. After this
change that no longer affects identity. It still affects `source_url`, which is
1b's problem and is why 1b mirrors Instagram bytes rather than storing links.

### 3.2 `content.media_assets.site_media_id`

New column: `uuid NULL`, FK → `site.site_media(id)`, no DB default, indexed.
Raw SQL under `supabase/migrations/`, one statement, per the project's migration
rules.

Why a column rather than a `storage_path` snapshot: `site.media_variants` holds
1–n renditions per upload, each with its own `disk` and `path`, and reprocessing
regenerates them. Pinning one path at backfill time hard-codes a rendition,
takes the variant system off the public hot path, and leaves a dangling path
after a reprocess with nothing to detect it. `site_media_id` is also already the
stable ref §2.3 of the parent chose for the upload fingerprint
(`upload:{site_media_id}`), so the column and the key agree by construction.

The asset row becomes a pointer into the working variant pipeline rather than a
snapshot of it.

### 3.3 `MediaUrlResolver`

New service, `app/Services/Media/MediaUrlResolver.php`. Input: `media_assets`
rows. Output: per asset, a servable URL plus width/height.

Three strategies, in precedence order:

| Asset shape | Resolution |
|---|---|
| `storage_path` set | `Storage::disk(config('partna.media_disk'))->url($path)` |
| `site_media_id` set | best `media_variants` rendition (webp tier), using that row's own `disk` + `path` |
| `source_url` set | passed through unchanged |
| none of the above | omitted from the payload |

Batched — one query per shape across the whole page of items, never per row;
this sits on the public profile hot path behind the 60s payload cache.

The seam is the point. 1b's Instagram mirroring changes nothing here: it
populates `storage_path` and the first branch already serves it.

### 3.4 The upload branch in `resolveMediaAssets()`

`ProjectionWriter.php:1173-1215`. Today it mints assets carrying only
`fingerprint`, `source_url`, `width`, `height`, `dims_confidence`.

A media entry may now carry `site_media_id` instead of `url` / `ref`. For those:

- fingerprint = `'url-'.sha1('upload:'.$siteMediaId)` — inside the existing
  namespace, so no existing row changes and the uniqueness constraint keeps one
  meaning
- `site_media_id` written
- `width` / `height` / `mime_type` read from the chosen `media_variants` row
- `dims_confidence = 'measured'` — the variant pipeline decoded the image
- `source_url` stays null

The PHP-side dedupe by fingerprint at the top of the method already covers the
upload case, since two items referencing one `site_media` produce one key.

This is the slice's main regression risk: hot, well-tested code on every
projection run for every connector. It gets its own review unit and its own
tests, and nothing else in the slice depends on it landing first.

### 3.5 `frames[]` and thumbnail dimensions on the pool payload

`PoolResolver` hydration, `:301-312` and `:338`.

Each item gains:

```
frames: [{ url, width, height, role, alt }]   // ordered by item_media.position
thumbnail: "https://…"                        // UNCHANGED — still a bare string
```

Media items ship every frame; every other kind ships `frames: []`. This is the
same "the wire shape does not change with kind" contract slice 2 established
when it added `startsAt` / `venue` / `price` to all items.

**`thumbnail` stays a string, deliberately.** Making it an object would have
been the tidier shape, but `thumbnail` is emitted for *every* pool — watch,
listen and events are live and consumed today, so changing its type is a
breaking change to three surfaces in order to serve one. Dimensions reach the
wire through `frames` instead: for a media item, `frames[0]` is the same asset
`thumbnail` resolves to, carrying its width and height. The addition is purely
additive and no existing consumer changes.

Two things the rewrite must preserve:

- `cover()`'s role priority is `cover → poster → gallery`, which is **role**
  priority, not positional order. `thumbnail` keeps that. `frames` is
  positional.
- An asset that resolves to no URL is omitted from `frames` rather than emitted
  as null, so the ten unrenderable Google items degrade to an empty gallery
  instead of ten broken images. 1b fixes them properly.

Dimensions are not decoration: without them a gallery cannot reserve space and
every image load shifts the layout.

`frames` is additive, so nothing breaks — but it is still a wire change and is
recorded in `docs/wire-changes/2026-08-12-media-pool-slice-1a.md` with before
and after shapes and the consuming repos named, per parent §10.

### 3.6 `SECTION_SHAPE['media']` and re-provisioning the ten sections

```php
'media' => [
    'rule' => [['op' => 'kind_is']],
    'order_by' => 'recency',
],
```

Plus an artisan command that re-shapes existing `pool:media` sections whose
rule still carries `latest_per_auto_source`, matched on the rule content rather
than blindly, so a hand-edited section is not clobbered. `--dry-run`, counts
reported, run against dev with output pasted into the checkpoint.

`PoolRegistryTest` pins that a kind belongs to at most one pool; adding a shape
entry does not disturb that. The rule change must be asserted against the
resolver, not just the constant — a section whose rule the executor does not
recognise fails at runtime, not in the registry.

### 3.7 `MediaUploadBackfiller`

`app/Services/Migration/MediaUploadBackfiller.php` plus an artisan command with
`--dry-run`, per convergence invariant #4 — production code, tested, idempotent,
re-runnable. Writes through the slice-0b manual lane
(`ProjectionWriter::writeManualItem()`), never raw.

- Scope: live `site.site_media` where `pool IN ('gallery','content')` — 16 + 9 =
  25 rows on dev. `pool='design'` (11) and `pool='documents'` (2) stay put, per
  parent §2.1.
- Coord: `manual:{site_media_uuid}`, preserving the legacy identifier after the
  table is eventually demoted.
- `user_id` derived through the site explicitly, failing loudly on a site with
  no owner (parent §8.2).
- Only `processing_state='ready'` rows are eligible; anything else is skipped
  and counted, not silently dropped.

---

## 4. Cache invalidation — all three lanes

The backfiller and the re-provision command are raw-write seams. Per parent
§9.2, and copying `PoolController::poolChanged()`:

| Lane | Action | Why bumping alone is not enough |
|---|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` | — this is what it is for |
| 60s public-profile payload cache | touch `site.sites.updated_at` | `IndividualProfilePayloadBuilder::cacheKey()` composes the key from `updated_at`; `bump()` writes a different table, so the stale payload is served for the full TTL |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | the CDN outlives the origin write |

There is no CI check that a raw-write seam bumps (parent §9.1 — `BuildState`'s
own docblock claims one and it does not exist). The plan must not budget for
protection that is not there; the tests below assert it directly.

---

## 5. Verification

### 5.1 Live dev assertions, output pasted into the checkpoint

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

Plus the resolver itself, which no SQL can stand in for: call `PoolResolver`
for the dev account's `media` pool and assert it returns the 25 uploads with
non-null `frames[0].url`. **`section_items` is not the proof.** Pins are the
curation half; a backfilled upload needs no pin because the corrected
`kind_is(media)` rule matches it in the auto half. A `section_items` count would
read zero on a fully working pool.

Note that `PoolSectionProvisioner` writes `min_items: 1` and `on_empty: 'hide'`,
so a media section with nothing resolvable hides rather than renders empty —
which is also why the ten unrenderable Google items do not produce a broken
gallery in the interim.

### 5.2 Pest

- fingerprint prefers `ref`; a `ref`-only entry is unchanged; a `url`-only entry
  is unchanged; an entry with both keys off the ref
- an upload mints one asset with `site_media_id`, measured dims, null
  `source_url`; two items on one `site_media` mint one asset
- `MediaUrlResolver` resolves each of the three shapes, and omits the fourth
- pool payload carries ordered `frames[]` for a multi-frame item; `frames: []`
  for a video item; `thumbnail` keeps `cover → poster → gallery` priority
- the backfiller is idempotent across two runs; a non-`ready` upload is skipped
  and counted
- **§8.3 regression:** run the Google Business connector *after* the backfill
  and assert the 25 upload items survive. `mergeInto()` hard-deletes a discarded
  item carrying neither a pin nor an override; slice 0b's `preferOwnerAnchored()`
  should make the owner row win, but it has never been exercised against a
  media-kind merge
- the backfiller bumps build state, moves `sites.updated_at`, and dispatches a
  purge

### 5.3 Postgres, not SQLite

Tests run SQLite; production is Postgres. Verify directly against the DDL in
`supabase/migrations/`:

- `item_media_role_check` — `cover|gallery|poster|avatar|logo`. `frames` must
  emit only these.
- `media_assets_fingerprint_unique (user_id, fingerprint)`.
- The new `site_media_id` FK's delete behaviour: a hard-deleted `site_media`
  must not orphan a live asset silently.

### 5.4 Post-deploy

`cloud env:logs partna development --minutes 10`, clean. Nightwatch checked —
slice 0's checkpoint records a log scan performed and a Nightwatch scan skipped;
do not repeat that gap.

---

## 6. Definition of done

One dev account's media pool contains its uploaded photos, selectable and
ordered, resolving to servable URLs with dimensions on the public profile
payload; `content.items WHERE kind='media'` is 35; every `pool:media` section
carries the corrected rule; the backfiller re-runs without producing a second
row; and a Google Business connector run after the backfill leaves all 25 upload
items alive.

---

## 7. Out of scope — carried to 1b

- `GoogleBusinessConnector::mapPhoto()` URL pass-through, and the NULL
  `headline_cache` on the ten existing Google items — including whether a photo
  needs a headline at all, or whether the pool contract should tolerate a null
  one by design
- Instagram `media` stream provisioning, the R2 mirror (generalising
  `BrandAssetPipeline`), and the no-duplicate-after-two-consecutive-syncs proof
- The 91 `site.content_selection` rows: 82 `google-photo` migrate by
  `external_ref` against the legacy payload's resolved URLs, 3 `upload` migrate
  by `media_id`, and the 6 `ig-post` / `ig-reel` rows carry **neither**
  `media_id` nor `external_ref` — they resolve positionally against a live
  connection payload, so there is nothing to migrate them by. That is a
  data-loss decision to take explicitly in 1b, not a mapping problem
- Retiring the `gallery` / `designMedia` wire keys — blocked on the frontends
- Any dashboard or public render work

## 8. Out of scope — carried to slice 7

`site.site_media` demotion, `site.content_selection` drop, and the observers
those orphan.
