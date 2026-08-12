# Instagram multi-photo mirror + gallery pre-fill — Backend Spec

**Date:** 2026-08-11
**Status:** Revised after independent review — pending owner sign-off
**Author:** Josh + Claude
**Scope:** backend only. Frontend rendering is out of scope.

**In one line:** mirror the 6 newest Instagram photos instead of 1, name them by post
rather than by rank, and pre-fill an empty photo gallery with them once.

## 1. Problem & context

The Instagram scrape returns up to 12 posts. We mirror exactly **one** photo (plus one
reel, its poster, and the profile pic) into R2 and store the URLs in
`site.platform_connections.payload`. Three separate consumers were all built expecting
several photos, and all three are starved by that single-item producer:

1. **The dashboard picker.** `ContentController::instagramPhotoOptions()` maps the whole
   `payload.images` array into selectable options, alongside `googlePhotos` and
   `uploads`. It works today and offers exactly one option.
2. **The `ig-photo` selection type.** `ContentSelection::TYPE_IG_PHOTO` exists
   specifically for a user-picked Instagram photo, deliberately excluded from
   `IG_TYPES` so a pick is never pinned to the reserved slots, with the comment
   *"a picked photo shouldn't vanish just because newer posts pushed it out of the
   latest-N window."* A latest-N window implies N > 1.
3. **The pre-account spec.** `2026-07-18-pre-account-sites-design.md:111` asked for
   *"profile+recent media rehosted into `site_media` pools … take the N best/most
   recent."* That clause was never implemented; the build reused
   `InstagramConnectionSeeder` verbatim, which satisfies "renders identically to a
   connected user's site" — the criterion that silently replaced it.

**Contrast with Google Business,** which offers a 10-photo picker with none of this
work: Google photos are *hotlinked* (`lh3.googleusercontent.com`) via a stable Places
`ref`, so offering more costs nothing. Instagram CDN URLs are signed and short-lived,
so each photo must be streamed into our own R2. The gap is a mirroring-budget
constraint, not a picker-design one.

### 1.1 The latent bug this walks into

Mirrored photos use a **fixed filename** in a per-connection folder:
`platforms/instagram/<created_at ts>/photo.jpg`. `RefreshController.php:187` re-runs the
same seeder on every refresh, which **overwrites `photo.jpg` in place**.

So a picked `ig-photo` — stored by URL — keeps a URL whose *bytes silently change*. The
promise that a pick "shouldn't vanish" is only half true: it doesn't vanish, it becomes
a different photo. With one image this is nearly invisible. With six rank-named files
(`photo-1.jpg` … `photo-6.jpg`) it would be actively wrong: a customer's pick at slot 4
becomes an unrelated image on the next refresh.

**Root cause:** the filename encodes *rank* ("the newest one"). Rank changes on every
refresh. Any surface where users pick things needs stable *identity*, which is exactly
why Google photos are keyed by a Places `ref` rather than by position.

## 2. Goals

1. Mirror the **6 most recent photos** instead of 1, so the existing picker offers 6.
2. Make a picked photo permanently resolve to **the photo that was picked**.
3. Keep the auto backdrop rule (newest reel + newest post) working **unchanged**.
4. **Pre-fill the photo grid** with those 6 photos — on pre-account builds *and* on
   dashboard connect — so a site is never born empty.
5. Pre-fill **once, into an empty gallery only**. Whatever the owner does afterwards —
   reorder, delete, replace — is final and is never overwritten.

### 2.1 The contract that makes goal 5 safe

`GalleryAutoGrabber` (the website-scan path) already solved this exact problem in
production, and its docblock states the rule this spec adopts verbatim:

> *"Fills, never tops up: any site with even one active gallery photo already —
> user-uploaded or previously auto-grabbed — is left alone entirely."*

Seed-once-into-empty is what makes auto-fill safe. The danger was never auto-fill; it was
*repeated* auto-fill on every refresh. Since `seed()` also runs on refresh
(`RefreshController.php:187`), the empty-gallery guard is what turns every subsequent run
into a no-op.

## 3. Non-goals

- **Multi-reel.** One reel is mirrored; there is no reel picker and none is added.
- **Auto-creating backdrop `ig-photo` rows.** An earlier draft auto-picked 4 photos into
  the *backdrop* selection. Dropped: with the photos now in the grid, the same images
  would render twice on one page. The backdrop keeps only its existing auto slots (reel +
  newest post) and the grid takes the photos. This also removes the `maybeSeedFromGoogle()`
  conflict that review found — see §10.
- **Changing the reel/profile-pic filenames.** No picker can pin them, so overwrite-in-place
  stays correct there.
- **Backfilling existing connections.** This applies to connects and refreshes from the
  ship date forward. The 9 IG-built dev sites are not retro-seeded.

## 4. Design

### 4.1 Core idea

Name each mirrored photo after **the post it came from**, not its rank:

```
platforms/instagram/<ts>/post-<shortCode>.jpg   ← 0–6, content-addressed  (NEW)
platforms/instagram/<ts>/reel.mp4               ← unchanged
platforms/instagram/<ts>/reel-cover.jpg         ← unchanged
platforms/instagram/<ts>/profile.jpg            ← unchanged
```

Everything else follows from this.

### 4.2 Scraper — `InstagramScraper::latestMedia()`

Additionally return `photos`: the newest **6 photos**, newest-first, each
`{thumbnailUrl, shortCode}`. Videos remain excluded (unchanged semantics — the existing
method already picks the newest photo and newest video independently). The existing
single `photo` key becomes `photos[0]`, so reel/post picking is untouched.

The list is already sorted newest-first by post timestamp before picking, so this is a
slice, not a re-implementation.

**Actor field-name drift — load-bearing.** `latestMedia()` currently reads
`data_get($post, 'shortCode')` (camelCase only, at `InstagramScraper.php:400,406,409`).
The default actor (`apify~instagram-profile-scraper`) returns camelCase, but
`figue~instagram-profile-scraper` returns raw GraphQL **snake_case** (`shortcode`), and
the actor is a config-level rollback lever (`config/partna.php:396`). No adapter
normalises post shape — `app/Services/Platforms/Actors/` only builds run input. A
rollback would silently null every shortCode and degrade the naming scheme — the same
failure mode that already hit `business_category_name`.

**Use the existing ingest precedent**, which already solved this for the same field:

```php
// app/Ingest/Connectors/InstagramConnector.php:203
Fields::firstString($post, ['shortCode', 'shortcode', 'short_code', 'code'])
```

Reuse `Fields::firstString` with that exact four-key list rather than inventing a
narrower two-key read.

**Missing shortCode:** skip the photo and log it. Do not fall back to a rank-based name —
that reintroduces the exact bug this design removes. A URL-hash fallback is also invalid,
because IG CDN URLs are signed and differ between scrapes, so it would defeat dedupe.

### 4.3 Seeder — `InstagramConnectionSeeder::seed()`

Mirror each of the newest ≤6 photos to `post-<shortCode>.jpg`, **skipping any shortcode
already present in R2**. Content-addressing gives dedupe for free: a refresh where
nothing new was posted downloads **nothing**, which is cheaper than today's
unconditional re-fetch of `photo.jpg`.

`payload.images` = the resulting mirrored URLs, **newest-first**.

**Populate `imagesDropped`.** The field is allowlisted on the public wire
(`PublicIntegrationConnectionResource.php:80`), in the DSAR export
(`DsarPayloadFilter.php:62`), and defaulted in `InstagramPayload.php:64` — but **nothing
in `app/` ever writes it**, so it is permanently 0, and the test that appears to cover it
(`PlatformFixesTest.php:268`) actually asserts on `images` count. It exists precisely to
report partial-mirror failure, and going 1 → 6 makes partial failure roughly six times
more likely. Set it to the count of photos that were selected for mirroring but failed
(fetch failure, oversize, or missing shortCode), and add the assertion the existing test
lacks.

> **Invariant — newest-first ordering is load-bearing.** `ContentSelectionService`
> resolves the reserved `ig-post` slot as `images[0]`, and
> `IntegrationConnectionObserver::reconcileContentInstagramSlots()` gates slot
> reservation on `images[0]` being non-empty. Newest-first keeps both correct with zero
> edits to either file. Any reordering of `images` breaks the backdrop.

`images` is **one list serving both surfaces**, not two. `images[0]` is simultaneously
the auto backdrop post and the picker's first option — that is today's behaviour and it
is preserved. The picker does not exclude it, and no de-duplication between the two is
wanted.

Existing per-image caps (15 MB), streaming-via-temp-file, pacing, and the
`report()`-on-failure convention all carry over unchanged. A failed individual mirror
yields no entry for that photo and does not fail the run — matching today's best-effort
behaviour.

### 4.4 Cleanup rule

Retained in-job and **after** the writes, preserving the current race-free property (a
separately-queued delete could otherwise run after a fresh re-mirror and wipe it).

Delete a mirrored photo when it is **both**:
- no longer among the newest 6, **and**
- not referenced as `external_ref` by any `ig-photo` row in `site.content_selection`
  for this site.

This replaces the current fixed-name `array_diff` complement with: list the folder,
compute the keep-set, delete the rest. The keep-set is the union of:

- the fixed-name files **actually written this run** (`profile.jpg`, `reel.mp4`,
  `reel-cover.jpg`) — a fixed-name file *not* rewritten this run is stale and is
  reclaimed, exactly as today;
- the newest-6 `post-<shortCode>.jpg` files;
- any file referenced as `external_ref` by an `ig-photo` pick.

**New coupling:** the seeder gains one query against `site.content_selection`, resolving
site from `user_id` (sites are 1:1 with users). This is a deliberate, minimal
Platforms → Site read; it is the only way to honour "keep what the customer picked"
without leaving reclamation to a scheduled sweep.

Cleanup stays best-effort inside try/catch — it must never fail a connection that
otherwise succeeded.

**R2 round-trips — bounded and fail-safe.** The seeder makes zero LIST calls today. Use
**exactly one** LIST of the connection folder, and derive both the skip-if-present check
and the delete set from that single result — never one existence check per shortcode.

> **A failed LIST must degrade to "mirror everything, delete nothing."** Treating an
> empty or errored listing as "the folder is empty" would make the delete set the entire
> keep-set complement and wipe live files, including picked ones. Deletion must be gated
> on the LIST having succeeded. Note the seeder resolves its disk via
> `MediaDiskResolver::resolve()` while `GcOrphanedPlatformMediaCommand` deliberately uses
> the literal `media` disk with `throw => true` for exactly this class of reason.

**Known benign race:** a customer viewing a stale page could pick a photo that this run
just deleted, leaving a dangling `ig-photo` ref. The resolver already handles a
non-resolving ref by dropping the entry, so the failure mode is a missing backdrop
image, not an error. A fresh page load cannot offer it, because the options list is
built from `images`.

### 4.5 Gallery pre-fill — in the seeder, guarded on empty

After the mirror completes, seed up to 6 `site_media` rows in the `gallery` pool from the
same photos.

**This lives in `InstagramConnectionSeeder`, not the generator**, because it now applies
to both entry points — pre-account builds and dashboard connect — which both funnel
through `seed()`. The empty-gallery guard, not the call site, is what enforces goal 5.

**The guard, checked before any upload:**

```
any active, non-failed site_media in pool='gallery' for this site  →  do nothing at all
```

Mirrors `GalleryAutoGrabber::grabIfEmpty()` exactly (`:58-67`). Refresh runs then become
no-ops, because the gallery we seeded is no longer empty.

**Use `MediaUploadService::upload()` — never write `site_media` directly.** It is the
single source of truth for the pool cap and for variant dispatch:

- it counts active rows and throws `PoolLimitExceededException` **before** insert, so the
  Postgres `enforce_site_gallery_max6` trigger is never reached (catch it and stop the
  loop, as `GalleryAutoGrabber:upload()` does);
- it dispatches `ProcessImageVariantsJob`, which writes the `media_variants` WebP row.
  **Without that row the gallery URL resolves empty** — a direct insert would produce six
  invisible photos.

Cap: `config('partna.image_pools.gallery.max')`, already 6.

**Reuse the bytes already fetched.** Each photo is streamed to a temp file during the R2
mirror; wrap that same temp file in an `UploadedFile` for the gallery upload rather than
re-fetching. One fetch per photo, two stored objects.

> **Known and accepted duplication:** each photo ends up stored twice — once as the
> platform mirror (which `payload.images` points at, feeding the backdrop's `images[0]`
> and the picker) and once as gallery media with its own variants. These are two
> different systems with different lifecycles; collapsing them would mean the picker
> reading from `site_media`, which would break §4.6's zero-edit property for a few
> kilobytes per account. Not worth it.

**Ordering is asynchronous, and that is the feature.** `ProcessImageVariantsJob` runs on
its own `images` queue (`ProcessImageVariantsJob.php:52`), so the build job does 6
downloads and 6 row inserts, **not** 6 transcodes. The site goes live immediately with
rows in `pending`, and the grid fills in as each conversion lands. This delivers the
phased "site first, photos after" behaviour with no phase machinery to build.

**Teardown is already handled.** `PruneExpiredPreAccountBuilds.php:112` calls
`purgeMediaArtifacts($user)`, which clears `site_media` R2 artifacts before the row
cascade. No change needed.

### 4.6 What deliberately does not change

`ContentSelectionService`, `IntegrationConnectionObserver`, `ContentController`,
`InstagramPayload`, and the public render path take **zero edits**. The picker goes from
1 option to 6 because it already maps the whole array; the backdrop keeps working because
`images[0]` is still the newest photo; the grid is written through the existing
`MediaUploadService` pipeline and read by the existing `getGallery` resolver.

Dropping the backdrop auto-picks (§3) is what restores this property in full — an earlier
draft required a `maybeSeedFromGoogle()` change, which is no longer needed because no
`ig-photo` rows are created on the user's behalf.

## 5. Failure modes

| Case | Behaviour |
|---|---|
| Account has fewer than 6 photos | Mirror what exists. Verified live: of 5 real accounts, one had 1 post and one had 0 |
| Account has 0 posts | `images` is `[]`; no slots reserved; site builds "ready" with no media (existing behaviour, unchanged by this spec) |
| A single photo fails to mirror | Logged, omitted from `images`, run continues |
| `shortCode` absent | Photo skipped and logged (§4.2) |
| Actor rolled back to `figue` | Both field shapes read, so naming survives (§4.2) |
| Picked photo rotates out of newest 6 | File retained; pick still resolves; photo no longer appears in picker *options* |
| Connection disconnected | Unchanged — `DeleteMirroredMediaJob` deletes by folder prefix, agnostic to filenames |
| Gallery already has photos | Pre-fill skipped entirely (§4.5). The common case on every refresh |
| Owner deletes all 6, then reconnects IG | Gallery is empty again, so it re-seeds. Accepted — `GalleryAutoGrabber` behaves identically rather than tracking a "seeded once" flag. Not worth the extra state |
| A variant job fails | That row stays non-`ready` and the resolver omits it; the other photos are unaffected |
| Pool cap reached mid-loop | `PoolLimitExceededException` stops the loop cleanly; photos already written stay |
| Gallery seeded but variants not yet processed | Grid renders empty for seconds, then fills. Expected (§4.5), not a failure |

## 6. Testing

**Unit — `InstagramScraper::latestMedia()`**
- returns the newest 6 photos, newest-first, videos excluded
- fewer than 6 available; zero available
- `shortCode` read from both camelCase and snake_case fixtures
- a post with no shortCode is skipped, not rank-named

**Feature — `InstagramConnectionSeeder`**
- mirrors up to 6 files named `post-<shortCode>.jpg`
- skips a shortcode already present in R2 (no re-fetch)
- `payload.images` is newest-first
- cleanup deletes a rotated-out unpicked photo
- cleanup **retains** a rotated-out photo referenced by an `ig-photo` selection

- a failed LIST mirrors everything and deletes **nothing** (§4.4)
- `imagesDropped` reports the count of photos that failed to mirror

**Feature — gallery pre-fill (§4.5)**
- an empty gallery is seeded with up to 6 rows, on **both** pre-account build and
  dashboard connect
- a gallery with even one active photo is **left completely alone**
- a refresh after a successful seed is a no-op
- each row dispatches `ProcessImageVariantsJob` and becomes servable once it runs
- `PoolLimitExceededException` stops the loop without failing the connect
- an account with 2 photos seeds 2 rows, not 6

**Regression**
- `images[0]` still drives the reserved `ig-post` slot
- `instagramPhotoOptions()` returns 6 options given a 6-image payload
- **no** `ig-photo` selection rows are created by any automatic path

**Existing tests this change breaks — both stub the old `latestMedia()` return shape and
must be updated:** `tests/Feature/Platforms/InstagramR2CleanupTest.php:148` and
`tests/Feature/Platforms/PlatformFixesTest.php:256`.

### 6.1 Postgres lane — mandatory, not optional

The suite runs SQLite, which **cannot see** `core.enforce_site_gallery_max6`. A test that
inserts a 7th gallery row will pass green locally and in CI and fail in production. §4.5
claims `MediaUploadService` counts first and therefore never reaches the trigger — that
claim must be proven where the trigger actually exists.

Add to the Postgres lane (`tests/Postgres/`, alongside the existing
`GalleryMax6TriggerTest.php`): seeding into a site that already holds 5 gallery photos
stops at 6 via `PoolLimitExceededException` and never raises
`Gallery limit reached: max 6 images per site`.

No migration is required — `site_media`/`media_variants` and the trigger all exist on the
2026-07-26 baseline.

## 7. Risks

**Legal exposure — and it is not storage-only.** This takes rehosted Instagram images
from 1 to 6 per account, on **every** connected account rather than pre-account ones
alone, and now renders them as page content rather than only as a backdrop. The
pre-account spec records that the 2026-05-31 legal review's ruling on IG
scrape-and-rehost was *knowingly set aside* by Josh on 2026-07-18. This is a materially
larger version of that same set-aside decision, and is the single item in this spec most
worth a deliberate second look before shipping.

Critically, `images` is allowlisted on the **public** wire
(`PublicIntegrationConnectionResource.php:80`) and in the DSAR export
(`DsarPayloadFilter.php:62`), so all six URLs ship publicly — on a **pre-claim** endpoint,
for a person who has not agreed to anything. This is amplification of an existing
exposure, not a new PII class: the URLs are our own R2 objects, and the PRIV-2 strip in
`InstagramSourceGenerator` covers `bioLinks`/`syncFindings`/`unmatched` only and is
unaffected. Flagged, not blocking — see `project_platform_integrations_legal`.

**Storage and bandwidth.** Six photos per connection instead of one, each stored twice
(platform mirror + gallery media) plus a WebP variant — so roughly 18 small objects where
there was 1. Partly offset by the skip-if-present dedupe, which removes the current
unconditional re-download on every refresh.

**Build latency.** Up to 5 extra small image streams plus 6 row inserts inside
`GeneratePreAccountSiteJob` (300 s timeout, of which Apify consumes 6–41 s in steady
state). The transcodes do **not** land here — `ProcessImageVariantsJob` is queued
separately (§4.5). A two-phase split was considered and **rejected as unnecessary**: the
async variant queue already produces the "site live first, photos fill in after"
behaviour for free.

**Image-queue load.** 6 variant jobs per Instagram connect, where there was previously 0.
Low volume at pilot scale, but it is a new steady-state load on the `images` queue that
did not exist before.

## 8. Concurrency

No new hazard, but stated so an implementer does not have to re-derive it. The new
`content_selection` read (§4.4) sits **before** the `Cache::lock` in
`InstagramConnectionSeeder`. Note that `$connection->update()` *inside* that lock fires
`saved` synchronously, so `reconcileContentInstagramSlots()` → `setInstagramAuto()` →
`persist()`'s Postgres transaction **already runs inside the cache lock** today. Adding a
read before the lock introduces no new ordering and no nesting. Lock discipline stays
`Cache::lock` → DB transaction on both paths.

## 9. Open items

- The legal set-aside in §7 now covers a materially larger surface than when it was made.
  Owner decision, not an implementation blocker.
- `GcOrphanedPlatformMediaCommand`'s docblock enumerates the four fixed filenames and
  will read stale after this change. Behaviourally unaffected (it groups by folder token
  and checks segment count, both of which still hold) — worth a comment refresh if that
  file is open for other work.

## 10. Revision & review record

**Independently reviewed 2026-08-11** against the code. All eight load-bearing claims in
§§1–4.4 were confirmed and are unchanged. Three design gaps were found in the then-§4.5
(backdrop auto-picks): hardcoded slot positions, backdrop image duplication, and a
`maybeSeedFromGoogle()` delete-all-then-insert that would have silently destroyed the
auto-picks when an owner connected Google Business.

**Revised after review** on the owner's direction: pre-fill the **photo grid** instead of
the backdrop, on both pre-account and dashboard connect, seeded once into an empty gallery
(§2.1). This supersedes the backdrop auto-picks entirely — and in doing so removes all
three reviewed gaps rather than patching them, since no `ig-photo` rows are created on a
user's behalf any more. §4.6's zero-edit property is restored in full.

The earlier deferral of the `site_media` grid rested on a refresh-versus-user-uploads
conflict that the seed-once-into-empty contract dissolves: an unclaimed site has no
uploads to lose, and a claimed one is skipped by the guard.
