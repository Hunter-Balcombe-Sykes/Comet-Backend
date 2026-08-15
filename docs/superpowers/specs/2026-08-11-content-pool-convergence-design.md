# Content pool convergence — design (2026-08-11)

Converge every content type onto `content.*` as the single store of record, retire
the parallel `site.*` item tables, and finish the pool architecture the 2026-08-05
programme started.

**Execution is backend-only.** Wire changes are permitted and expected; each is
recorded in a manifest for the Partna-App and partna-monorepo teams to follow.
Owner decision, 2026-08-11: this is a foundational change and the frontends will be
told, not designed around.

Supersedes the scope statement in `docs/2026-08-05-platforms-as-sources.md` §"Phase 1
design" line 84 ("unifying them onto content.* is future work"). That document
remains the decision record for pool semantics; **its P4 checkpoint claim that the
programme is complete is false and is corrected here.**

---

## 0. Revision note — independent review, 2026-08-11

Revision 1 of this spec was reviewed adversarially against the codebase and the dev
database. Its headline diagnosis held; five of its engineering decisions did not.
Corrected in revision 2:

| Was | Is |
|---|---|
| `EffectRefused` is "one new exception type" | It already exists and already covers off-manifest hosts. Reusing it would delete claims for admission failures — see §5.3 |
| "Budget refusal precedes the call, so no money moved" | `fetchPlaceDetails()` claims budget *inside* a retry loop; a throw on attempt 2 follows a request that already reached Google |
| A null result settles as `ok` | Null has four causes, three of which involve no charge. Freshness is 7 days, so this cached outages as "no data" for a week |
| The Instagram driver delegates to `InstagramActorAdapter` | That interface is `input()`/`classify()` only and cannot fetch. The runner is `InstagramScraper` |
| Fingerprints are namespaced `sha256:` / `instagram:` / `places:` | `mediaFingerprint()` computes one unnamespaced `url-<sha1>` and prefers URL over ref. Adopting the invented scheme would re-mint all 467 assets |
| The manual source is an existing affordance | `content.sources` holds 25 rows, **all `connection`**. `ProjectionWriter` structurally refuses sources without a `connection_id`. This is now slice 0b |

Two further findings reshaped the programme: `mergeInto()` hard-deletes uncurated
merged-away items (§7.3), and the §7 fidelity gate as originally written was
incompatible with identity merging (§7.4).

One reviewer finding was **not** adopted: it reported `logo_square = 9` against this
spec's 6. Both are correct — this spec counts live rows (`deleted_at IS NULL`); the
reviewer counted all rows. Clarified in §2.1 rather than changed.

## 0b. Revision note — slice 2 planning, 2026-08-12

Planning slice 2 and reviewing that plan against the codebase falsified one of this
document's own claims and sharpened two others. Revision 3:

| Was | Is |
|---|---|
| §9.1: `BuildState` names its raw-write seams "with a CI check keeping the list current" | **There is no list and no CI check.** That sentence exists in `BuildState`'s docblock and this spec repeated it. Rewritten — see §9.1 |
| §9.2: "name the keys it invalidates" | Necessary but not sufficient. `BuildState::bump()` does **not** invalidate the public-profile payload cache — different lane entirely. Spelled out in §9.2 |
| §1.3: unread kinds are a uniform problem to be solved by adding pools | `event` is; `channel` and `article` are not. Slice 2 is events-only, and the reasons are structural rather than scheduling — see §7 slice 2 |

## 0c. Revision note — slice 3 entry gate, 2026-08-12

Re-deriving §1's figures from dev before starting slice 3 corrected one cause and
moved two counts. Revision 4:

| Was | Is |
|---|---|
| §1.6: the Fresha zero is a projector that "has never run" | The ingest connector yields `Unavailable` on every run. One wrong request variable, and a misleading error message that blames a hash rotation that has not happened — see §1.6 |
| Slice 3's kickoff prompt: the cause is F7 (`selection: null` → 304) | F7 was fixed 2026-08-11 (`2ca21904e`) and governs the **legacy** `site.services` lane. Two different classes share the name `FreshaServiceProjector` |
| §1.5: `content.offers` 10 | **14** |
| §1.7: `content.sources` 27 connection / 0 manual | **29 connection @100 / 6 manual @200** — slice 0b's lane is live and exercised |

**The name collision is the transferable lesson.** §1.6 counted
`App\Ingest\Projection\FreshaServiceProjector` while the review that diagnosed it
was reading `App\Services\Platforms\FreshaServiceProjector`. Both are real, both
are current, both project Fresha services — and one works. A slice that reasons
about "the projector" without a fully-qualified name is reasoning about an
ambiguity.

The §9.1 error is the same failure mode this document exists to correct. §11 amends
`2026-08-05-platforms-as-sources.md` for claiming a programme was complete when the
database said otherwise; §9.1 asserted a CI gate that the codebase said otherwise.
A design document repeating a docblock it never checked is exactly how the 2026-08-05
claim propagated in the first place. **Verify assertions about guardrails before
writing them down — a guardrail nobody checked is worse than none, because plans
budget for its protection.**

## 0d. Revision note — slice 6 entry gate, 2026-08-13

Re-deriving state from dev before slice 6 falsified five premises its own kickoff prompt
carried, and one of this document's counts. Revision 5:

| Was | Is |
|---|---|
| §1.4: `content.f_review` holds 0 rows; Google reviews have never reached the content schema | **15 rows**, and 15 `content.items` of kind `review`. Slice 1b's media runs carried them: the connector's `profile` / `reviews` / `media` streams share ONE `places.details` effect |
| Slice 6's kickoff: `GoogleBusinessReviewProjector` has never executed | It has. Slice 6's unit 1 was a verification job, not a build |
| Slice 6's kickoff: the pool ships "with no payload-builder change" | `PoolResolver::itemPayloads()` joined nine facet tables and `f_review` was not among them. A registry-only change ships a review card with no rating and no text |
| §1.6-style reading of the `profile` stream as unfinished | **Vestigial, not unfinished.** 3 `record_versions`, 0 `source_items`. Its intended consumer was the field-bindings lane, created by `20260728150000` and deliberately dropped by `20260805110000`; the identity fold it existed for runs instead via `IdentitySync` off `IntegrationConnectionObserver::saved` |
| Slice 6's kickoff: `reviewSummary` is the only companion to `reviews` | **Four** keys sit behind one owner toggle (`DisplaySettingsFilter:33`): `reviews`, `reviewSummary`, `rating`, `reviewCount` |

The transferable one is the third. **A pool is a read path, and a read path is not free
because the write path already landed rows.** Sizing a slice from "the data is already
there" skipped the join that makes the data renderable.

Where slice 6's new facts landed in this document: the row counts in §1.4 and §1.5, the
`content.source_stats` seam in §1.5, and the slice-6/slice-7 consequences in §7. **Not §5**
— slice 6's own spec §7 pointed there, but §5 is the billed-effect driver seam and the
source-level write path is a `ProjectionWriter` seam, not a driver one. Slice 6 changed
nothing in the driver contract.

---

## 1. Verified current state

All figures read from dev (`glncumufgaqcmqhzwrxm`) on 2026-08-11. Counts of
`site.*` media are live rows only (`deleted_at IS NULL`); all others are raw.

### 1.1 What works

`content.items` holds 477 rows and the watch/listen pools serve them live.
**Attribution below is from landed `content.source_items`, not from
`ProjectorRegistry`** — a registered projector is not evidence that it has ever run:

| Kind | Rows | Pool | Sources that have actually landed records |
|---|---|---|---|
| `release` | 219 | `listen` | Apple Music 186, Bandcamp 33. **YouTube Music: 0** — no `youtube_music` ingest source exists |
| `video` | 130 | `watch` | YouTube 71, Vimeo 59. **Twitch: 0** — `twitch/vods` has landed no records |
| `episode` | 106 | `listen` | Apple Podcasts |

Nine connectors have provisioned streams, `auto_sync = true` and a `last_run_at`
within three days: YouTube, Spotify, Bandcamp, Twitch, Vimeo, Fresha, Substack,
Eventbrite, Humanitix. **Running is not the same as landing records** — see §1.6.

### 1.2 What is claimed but false

`docs/2026-08-05-platforms-as-sources.md` P4 checkpoint states:

> Post photos/videos are Media options via the instagram connector (grab-all live since P1)

The database refutes this:

```
content.items WHERE kind='media'   →  0 rows
ingest.effects                     →  0 rows   (no billed effect has ever run, any connector)
ingest.sources WHERE source_key='instagram'       → 1 row,  0 streams, last_run_at NULL
ingest.sources WHERE source_key='google_business' → 12 rows, 0 streams, last_run_at NULL
```

**Root cause.** `app/Ingest/Runtime/HttpIo.php:116` — `runBilledEffect()` throws
unconditionally. There is no `kind` dispatch and no driver registry:

```php
private function runBilledEffect(string $kind, string $name, array $input): array
{
    throw new \RuntimeException("No billed-effect driver is wired for kind '{$kind}' …");
}
```

`HttpIo` is the only production `Io`; every other implementer is a test fake.
Instagram calls `$io->effect('actor', 'instagram', …)` and Google Business calls
`$io->effect('api', 'places.details', …)`. Both hit the same throw. Every working
connector fetches over plain HTTP via `$io->get()` and never reaches the ledger —
the divide is free-versus-paid, not Instagram-versus-rest.

**Consequence — a live regression.** The Posts pool (route, grid, nav row, site-page
section, fixtures) was deleted from Partna-App at P4 on the strength of the false
claim. Users lost Posts and never gained Media.

> **Correction to a prior working note:** an earlier analysis stated that
> `CostClass::Metered` exempts Google Business from the blocker. It does not.
> `CostClass` governs budget weight and whether `SourceProvisioner` enables
> `auto_sync`; it has no bearing on driver dispatch. Google needs an `'api'` driver
> and Instagram an `'actor'` driver, and neither exists. Any plan sizing the Google
> lane as "find out why it never ran" is mis-scoped.

### 1.3 What is orphaned

Three kinds accumulate rows nothing reads, because `PoolRegistry` has no entry for
them. Attribution is from landed data:

| Kind | Rows | Sources that landed them |
|---|---|---|
| `event` | 14 | Eventbrite, Humanitix |
| `channel` | 7 | Twitch 3, Spotify 3, SoundCloud 1 |
| `article` | 1 | Substack |

Skool and Strava have `ChannelCardProjector` registered but **no `ingest.sources`
rows at all**, so they contribute nothing.

`ProjectorRegistry::for()` returns `null` for an unmapped stream by design — "this
stream projects to no item" is a legitimate state — so an unread kind never errors.
The write half is loudly tested; the read half is only tested where a pool exists.

**Do not read this table as a work list (added 2026-08-12).** The three kinds are
one *symptom* and three different problems. Slice 2 adopts `event` alone; `channel`
and `article` are deferred on product grounds, and a row count is not evidence a
kind is ready for a pool. Of the 14 `event` rows, 6 are empty shells and 3 are
stale — see §7 slice 2. The same caution applies to any later slice that sizes
itself from a count in this section.

### 1.4 What still runs on legacy tables

Deliberately out of scope in 2026-08-05, in scope here. All ten counts verified:

| Table | Rows | Kind it becomes |
|---|---|---|
| `site.menu_items` | **318** | `menu_item` |
| `site.menu_item_categories` | **402** | (collection membership) |
| `site.menu_item_platforms` | **318** | (offers) |
| `site.menu_categories` | **44** | (collections) |
| `site.services` | 82 | `service` |
| `site.service_category_assignments` | 61 | (collection membership) |
| `site.service_categories` | 18 | (collections) |
| `site.shop_products` | 51 | `product` |
| `site.shop_brands` | 9 | collections + a `content.storefronts` sidecar (decided in 5a; **not** `f_catalog`, which is a music facet) |
| `site.content_selection` | 91 | `media` — but see §2.4, it is not uniformly reference-resolved |

**Corrected 2026-08-16 (slice 4 entry gate).** The four menu rows above read
370 / 464 / 370 / 52 when this table was written on 2026-08-11. Every one was
stale by the next day. The deltas are exactly one menu tree — `showcase-eats`,
deleted between 08-11 and 08-12 through application code (zero orphan slug rows
survive, and nothing at the DB level would have cleaned them). It was not a
scrape: no menu had been fetched since 08-06. The trigger is not recoverable —
no audit table covers menus — and is recorded as undetermined rather than
guessed at. Full working in §23.1.

**Corrected 2026-08-13 (slice 6 entry gate).** This section used to read *"`content.f_review`
holds 0 rows; Google reviews have never reached the content schema, blocked by the same
driver gap."* Both halves are false. `content.f_review` holds **15 rows** and
`content.items` holds 15 of kind `review`, landed by slice 1b's Google runs — the
connector's `profile`, `reviews` and `media` streams share a single `places.details`
effect, so enabling media enabled reviews with it. Reviews were therefore never blocked
on a driver gap after slice 0.

### 1.5 What the schema anticipated but never grew

| Table | Rows | Note |
|---|---|---|
| `content.offers` | 14 | `channel`, `variant_label`, `amount_minor`, `qualifier` (`exact\|from\|upto\|range\|free\|variable\|on_request`), `amount_max_minor`, `availability` |
| `content.item_variants` | 0 | label / sku / position |
| `content.item_tags` | 186 | tag + tag_type |
| `content.collections` / `collection_items` | 0 / 0 | grouping — menu and service categories |
| `content.sources` `kind='manual'` | **0** | The unique index exists. **Nothing has ever written a row.** See §1.7 |

**Added 2026-08-13 by slice 6 — `content.source_stats`, the first source-level fact in
`content.*`.** PK `source_id` (FK → `content.sources`, CASCADE), columns `rating_avg`,
`rating_count`, `summary_text`, `updated_at`, all nullable. Every other table in this
schema describes an ITEM; these describe the connected account itself — the Google place's
star average, total rating count and Google-authored review summary. **`ProjectionWriter`
therefore now has a source-scoped write path** alongside its item-scoped ones: a projection
may return a `source_stats` key, which upserts one row per source rather than per item.
Any slice adding a source-level fact uses that seam rather than inventing a second one, and
any teardown reasoning about "what a projection writes" must account for a write that has
no `item_id`. Written by the `reviews` stream (see §7 slice 6 for why not `profile`), and
read by the public wire as `pools.reviews.stats`.

`site.menu_items` carries `base_price`, `pickup_price`, `pickup_source`,
`delivery_price`, `delivery_source` as flat columns. That is precisely three
`content.offers` rows with `channel ∈ {base, pickup, delivery}` and a real
`source_id`. The legacy table is the denormalised special case of what `offers`
expresses generically.

### 1.6 Projectors that exist but have never executed

Registration is not execution. Verified zero landed records:

| Projector | Streams | Records landed |
|---|---|---|
| `FreshaServiceProjector` | `fresha/services` × 4 sources | **0** |
| (Fresha identity) | `fresha/profile` × 4 sources | **0** |
| `TwitchVodProjector` | `twitch/vods` × 3 sources | **0** |

So `site.services`' 82 rows come entirely from the legacy non-ingest path, and
**slice 3 is not "backfill onto a working projector"** — the projector has never
run against real data. Any slice depending on one of these must first prove it
executes.

**Cause established 2026-08-12 (slice 3 entry gate), for the Fresha rows.** The
zero is a connector defect, not a projector one, and it is not the F7 /
`selection: null` dead end the 08-11 Instagram review handed to slice 3 — that
was fixed at `2ca21904e` on 2026-08-11 and governs a different lane.

**Two classes share the name `FreshaServiceProjector`.**
`App\Services\Platforms\FreshaServiceProjector` is fed by `FreshaFetch` and
writes `site.services` — it works, and produced the 59 live `source='fresha'`
rows. `App\Ingest\Projection\FreshaServiceProjector` is fed by
`FreshaConnector::pull()` and writes `content.*` — it is the one counted here.
`ProjectorRegistry.php:28` maps `fresha/services` to the ingest class, which
never reads `payload.selection`.

`ingest.runs` records `services` as `unavailable` on all four sources on every
run since 2026-07-28 — not a 304. `FreshaConnector.php:239` sends
`shouldShowAllEmployees: true`, which returns Fresha's employee-picker screen
with an empty `screenServices`; the connector reads the absent
`screenServices.categories` as the pinned-hash rotation symptom and yields
`Unavailable`. The hash is valid — three live calls on 2026-08-12 returned HTTP
200 with well-formed `bookingFlowInitialize` and no `errors`. With
`shouldShowAllEmployees: false` the same request returns
`BookingFlowScreenServices` and 25 / 40 / 22 services parsing cleanly through
the connector's existing mapper.

Two consequences later slices inherit: an error message naming a specific cause
is a claim that can be wrong and cost more than a generic one, and the ingest
lane returns the **storewide** menu where the legacy lane stores one employee's
filtered menu — so the two are not row-for-row equivalent (87 vs 59 on dev).

### 1.7 The manual lane exists and is broken

`ProjectionWriter::projectStream()` (`app/Ingest/Projection/ProjectionWriter.php:89-92`):

```php
$connectionId = $source['connection_id'] ?? null;
if ($connectionId === null) {
    return ['status' => 'skipped', 'reason' => 'no_connection'];
}
```

and `ensureContentSource()` (`:213-233`) only ever inserts `kind => 'connection'`.
Dev confirms `content.sources` is 25 rows, all `connection`, zero `manual`, zero
`import`.

Every part of this programme that writes owner-authored items — uploads in slice 1,
hand-written services in slice 3, manual dishes in slice 4 — depends on a lane with
no writer. **This is slice 0b**, and it is a prerequisite for slices 1, 3, 4 and 5.

**Correction, established during slice 0b planning (2026-08-11).** Revision 2
said "nothing has ever written a row" with `kind='manual'`. That is wrong.
`PoolItemCreateController` (`routes/api/user.php:172`,
`tests/Feature/Content/PoolLaneTest.php:365`) is a live, routed manual-source
writer. Dev holds 0 manual rows because the endpoint has not been exercised,
not because no writer exists.

It is worse than absent. It hand-rolls the writes and skips
`content.identity_keys` and `content.item_anchors`, so `resolveItems()` — which
unions every live source item for `(user_id, kind)` across all sources — sees a
keyless singleton, `createItem()` mints a blank `content.items` row for it, and
the re-read loop repoints the hand-added source item onto that blank. Any user
who hand-adds to a pool and holds a connector for the same kind gets a blank
duplicate in their library and an item severed from its own source row.

The `priority` claim in the `content.sources` DDL comment ("one manual source
per user, at max priority: what makes 'the user outranks the machine' a data
fact") was also untrue — the controller wrote 100, the same as every
connection. Slice 0b sets it to 200 and corrects any row already written.

**A constraint slices 3, 4 and 5 must design around.** `Resolver::poisonedKeys()`
marks a key value poisoned when a SINGLE source contributes it twice, and there
is exactly one manual source per user. So two manual coords carrying the same
canonical URL do not merge — they poison that URL for the whole resolution run,
and any connector item carrying it stops unioning too.
**A backfiller must therefore mint at most one manual coord per canonical URL
per user.** The hand-add endpoint satisfies this by deriving its coord from the
URL rather than minting a fresh UUID per request.

**The cost depends on ordering, and the cheap case is misleading.** The pure
resolver returns three separate groups either way, but a group is not an item:
`content.item_anchors` is sticky, so a coord that already has an anchor rebinds
to it. Measured, both pinned in `tests/Feature/Ingest/ManualSourceLaneTest.php`:

| Ordering | Outcome |
|---|---|
| Connector runs, then two hand-adds | **2 items.** The connector coord and the first manual coord keep the anchor they converged on; only the second manual coord strands. Bounded damage |
| Two backfilled rows, then the connector runs | **3 items.** No anchor exists to protect anything, the URL is already poisoned when the connector's coord appears, and it never folds. Permanent non-convergence |

The second is the ordering a backfiller produces — legacy rows first, connector
afterwards. Slices 3, 4 and 5 must therefore dedupe by canonical URL **before
writing**, not rely on a later run to reconcile.

**Dev baseline re-run 2026-08-12, immediately before implementing slice 0b.**
`content.sources` is now **27** rows (not 25), still all `connection`, still
`priority` 100–100, still zero `manual` and zero `import`. The corrective UPDATE
in `ensureManualSource()` is therefore **defensive on dev, not load-bearing** —
no row exists to raise. Live orphan items (`removed_at IS NULL` with no source
item) baseline at **0**, which is the figure §12's gate compares against.

---

## 2. End state

`content.*` is the single store of record for every synced or owner-authored item.

`site.*` retains only what is not an item: `sites`, `design_kits`, `blocks`,
`customers`, `enquiries`, `workplaces`, `site_subdomain_aliases`, `pages`,
`sections`, `section_items`, and — in a demoted role — `site_media` and
`media_variants`.

### 2.1 The media boundary

`site_media.pool` currently does two unrelated jobs under one column. For `gallery`
and `content` it means "which curated surface does this appear on" — a genuine pool.
For `design` and `documents` it means "what kind of thing is this" — a type tag
wearing a pool's name. The convergence splits it along that seam.

Live-row counts (`deleted_at IS NULL`); totals including soft-deleted rows are
higher, notably `logo_square` at 9:

| Today | Live rows | Disposition |
|---|---|---|
| pool=`gallery` | 16 | → `content.items` kind=`media`, manual source. **In the media pool.** |
| pool=`content` — the `designMedia` wire key | 9 | → same. **In the media pool.** |
| pool=`design` — `logo_full` 4, `logo_square` 6 | 10 | **Stays.** A singleton keyed by purpose, one per site. No library, no selection, no ordering. |
| pool=`documents` | 2 | **Stays.** `content.items` has a `document` kind; that is a separate surface and a later decision. |
| `site.media_variants` | 80 | **Stays**, demoted to the byte and processing layer. |

**`site_media` survives as storage and processing; it stops being a curation
surface.** `content.media_assets` models a *resolved* asset — one `storage_path`,
dimensions, palette, blurhash. `site_media` models an *asset factory* —
`processing_state` (`pending→processing→scanning→ready→failed→quarantined`),
`scanned_at` for the CSAM scan, `original_mime`, `original_size_bytes`,
`processing_error`, `poster_path` — and `media_variants` holds the derived
renditions (`variant_key`, `artifact_type`, webp tiers, 720p/1080p mp4,
`bitrate_kbps`). Rebuilding that inside `content.*` would re-implement a working,
scanned, variant-generating pipeline for no curation gain.

**One uploaded photo, after convergence:**

| Table | Rows | Holds |
|---|---|---|
| `site.site_media` | 1 | upload record, `processing_state='ready'`, `scanned_at` |
| `site.media_variants` | 1–n | the renditions |
| `content.media_assets` | 1 | `storage_path` → chosen variant, `variant_family='native'`, dims, palette |
| `content.items` kind=`media` | 1 | **the curatable thing** — pins, excludes, sort order, overrides, Latest tag |

An Instagram photo takes the identical four-row shape, differing only in
`content.sources.kind` and whether `media_assets` carries `source_url` or
`storage_path`.

**The two-writer problem, stated honestly.** Revision 1 claimed "exactly one
writer". That is false. `ProjectionWriter::resolveMediaAssets()` (`:902-917`)
already writes `content.media_assets`, and it writes **only** `fingerprint`,
`source_url`, `width`, `height`, `dims_confidence`. It has no code path writing
`storage_path`, `mime_type`, `palette`, `variant_family` or `blurhash` — the exact
columns the table above depends on. Slice 1 must therefore either:

- **(a)** extend `ProjectionWriter` with an upload-aware branch — a real change to
  hot, well-tested code, which slice 1 must budget for; or
- **(b)** accept a second writer confined to the upload path, with a test pinning
  that the two never touch the same fingerprint namespace.

**Decision: (a).** A second writer to a table whose uniqueness constraint spans both
writers' rows is how silent duplication starts. Slice 1 owns the `ProjectionWriter`
change.

### 2.2 Required change to PoolResolver

`app/Site/Pools/PoolResolver.php:253-263` selects `content.media_assets.source_url`.
`cover()` (`:333-343`) iterates roles in priority order `['cover','poster','gallery']`
and returns the first non-empty `source_url` **within that order** — role priority,
not positional order, and the rewrite must preserve it.

For an upload, `source_url` is null and `storage_path` is set. Two things the
rewrite must resolve that revision 1 hand-waved:

1. **`storage_path` is not a URL.** `MediaDiskResolver::resolve()` returns a *disk
   name*, not a URL. Serving requires `Storage::disk(...)->url($path)` plus the
   correct R2/CDN base.
2. **Which variant?** `media_variants` holds 1–n renditions per upload. Writing one
   `storage_path` at backfill time hard-codes a rendition and defeats the variant
   system on the hot path. Slice 1 must decide: store the variant *key* and resolve
   at read time, or store a path and accept the pinning. Recommended: store
   `variant_key` alongside, resolve at read.

This is on every pool read and every public profile render.

### 2.3 Fingerprints — what the code actually does

`ProjectionWriter::mediaFingerprint()` (`:850-861`) is the sole fingerprint author:

```php
$url = $entry['url'] ? SecretParams::minimiseUrl($entry['url']) : null;
$ref = $entry['ref'] ?: null;
$fingerprint = $url ?? $ref;
return [$fingerprint === null ? null : 'url-'.sha1($fingerprint), $url];
```

One unnamespaced `url-<sha1>`, and **URL wins over ref**. Dev confirms all 467
`content.media_assets` rows match `url-<sha1hex>`, 467 carry `source_url`, and
**0 carry `storage_path`**.

Revision 1 proposed `sha256:` / `instagram:` / `places:` namespaces. That scheme
does not exist, and adopting it would change every asset's fingerprint —
`resolveMediaAssets()`'s `insertOrIgnore` would mint 467 duplicates on the next
projection run while `item_media.asset_id` repointed, orphaning the originals.
**Rejected.**

**Instead:** uploads join the existing namespace as `url-sha1(<stable-upload-ref>)`,
where the stable ref is `upload:{site_media_id}` — not the content hash, because
`site_media_id` is stable across reprocessing while the hash is not. No existing row
changes.

**A latent bug slice 1 must fix first.** `InstagramMediaProjector` emits both `url`
(Instagram's signed CDN link) and `ref` (`instagram:{shortcode}:{i}`), and
`mediaFingerprint()` prefers the URL. Its own docblock claims the opposite — that
the fingerprint is "keyed on the shortcode-stable ref". `SecretParams::minimiseUrl()`
redacts `_nc_sid` but not `oh` / `oe` / `_nc_ohc`, so a re-signed Instagram URL
yields a **new fingerprint every sync** and a duplicate asset per photo per run.
Slice 1 lands zero Instagram media today, so this has never fired — it will fire on
day one. Fix: prefer `ref` over `url` when a ref is present, with a migration for
any already-minted URL-keyed assets.

### 2.4 `site.content_selection` is not uniformly reference-resolved

| `entry_type` | Rows | `media_id` | `external_ref` |
|---|---|---|---|
| `google-photo` | 82 | 0 | 82 |
| upload | 3 | 3 | 0 |
| `ig-post` | 4 | 0 | 0 |
| `ig-reel` | 2 | 0 | 0 |

Six Instagram rows reference **nothing** — they resolve positionally against the
live connection payload and are dropped when it is absent. And the 82 `google-photo`
rows carry Places photo refs whose servable URLs exist only inside the legacy
`site.platform_connections` payload, obtained via billed media calls already made.

Slice 1 must therefore decide, explicitly: read those URLs from the legacy payload
during migration, or re-bill Places. Recommended: read from the payload — the bytes
were already paid for.

---

## 3. Invariants

Each is a failure mode observed in the 2026-08-05 programme. Revision 1 of this spec
violated #1 and #6 itself.

1. **No slice is done without a live database assertion.** Every definition of done
   includes the SQL that proves it, run against dev, with output pasted into the
   checkpoint.

2. **A kind is not adopted until something reads it.** Projector, `PoolRegistry`
   entry, and read path land in the same slice or none of them do.

3. **Legacy deletion is its own slice, always last, always gated on the replacement
   being live-verified.** Slice 7 touches nothing until slices 1–6 have their
   assertions on record.

4. **Backfill is production code, not a throwaway script.** Under
   `app/Services/Migration/`, tested, idempotent, re-runnable.

5. **No slice may cite another slice's checkpoint as evidence for its own claims.**

6. **Registration is not execution.** A registered projector, a provisioned stream
   and a scheduled run are three different facts. Claiming a lane works requires
   landed records, not a registry entry — see §1.6.

---

## 4. Programme decomposition

Nine slices, each independently shippable and independently verifiable. Slice 1 was
split into 1a/1b on 2026-08-12 (`2026-08-12-media-pool-slice-1a-design.md`).

### 4.1 Status — 2026-08-14

| # | Slice | Size | Status | Hard blocker |
|---|---|---|---|---|
| 0 | Billed-effect driver seam | M | **Merged** — checkpoint §13, plus #MONEY-1/2 fixes | — |
| 0b | Manual-source write lane | M | **Merged** — checkpoint §12 | — |
| 2 | The events pool (`event` only) | M | **Merged** — checkpoint §14 | — |
| 1a | Media asset spine + upload lane | L | **Merged** — live on dev (checkpoint in the 1a design doc) | — |
| 1b | Google pass-through, IG mirroring, the 91 selections | L | **Merged** — checkpoint §15 | — |
| 3a | Services — owner-authored, pool, write cutover | L | **Merged** — checkpoint §17 | — |
| 3b | Services — Fresha connector, excludes, collections | L | **Merged** — checkpoint §19, plus the 3b-residuals follow-up | — |
| 4 | Menus → `content.*` | XL | **Merged** — checkpoint §23; Phase 5's live connector proof RAISED, see §23.6 | — |
| 5a | Shop data move → `content.*` | L | **Merged** — checkpoint §16 | — |
| 5b | Shop pool + public render | M | **Merged** — checkpoint §18 | — |
| 6 | Reviews → `content.*` | M | **Merged, CLOSED, live-verified** — checkpoint inline in §7 (commit `e82890b1e`) | — |
| 7 | Legacy teardown | M | Not started | 4 **+ standalone events**; frontend gate overridden by owner ruling 2026-08-14 (rebuild, not repair — see convergence-log F17) |

Everything except 7 is merged with a checkpoint on record (§12–§19 and §23; slice
6's is inline in §7). **Slice 4 landed 2026-08-16 — 7 is unblocked**, with the
carry-overs in §23.10 and the one open question in §23.6.

**Slice 3 split into 3a/3b on 2026-08-12**, on a seam the database dictated rather
than a preference: all 61 `service_category_assignments` and 16 of the 18
`service_categories` belong to Fresha, and the two owner-authored categories are
both already soft-deleted. The owner half therefore carries no collections work and
the Fresha half carries all of it. The split also keeps the write cutover (17
endpoints) away from the connector fix, so neither waits on the other. Same
reasoning as 1a/1b.

**Slice 7 gained a dependency revision 2 did not anticipate.** Slice 1a's scope
boundary keeps the legacy `gallery` / `designMedia` wire keys, because the frontends
still read them. Retiring those keys is therefore blocked on Partna-App's Media page
and the monorepo gallery render — work outside this programme's backend-only mandate.

**Slice 7 gained a second one from slice 2: standalone events.** See §7 slice 7's
"Carried from slice 2". Slice 2 retired the ACCOUNT half of the legacy events lane
but deliberately left STANDALONE `resource_kind='event'` rows publishing through it,
so that lane cannot be dropped yet.

### 4.2 Execution order

**Merge 1a → { 1b · 3a · 5a } concurrent → 3b → 6 → 4 → 5b → 7.**

**Revised 2026-08-12:** slice 5 split into 5a (data) and 5b (pool + public
render). 5a keeps 5's original concurrency slot — it is `product`-kind and
touches no shared read path, so §4.3 rule 1 still holds. 5b is sequenced late
because it is the only remaining slice that needs partna-monorepo to move in
step, and slice 7's teardown gate does not depend on it.

**Revised 2026-08-13:** slice 3 split into 3a (owner-authored services, the
pool, the write cutover) and 3b (the Fresha connector, excludes, collections)
— see §4.1. 3b follows 3a rather than running beside it because it builds on
3a's pool and its `content.*` read path.

Sizes were revised upward from revision 1 (3 and 5 from M, 4 from L, 7 from S)
because §1.7 removed the assumption that a manual write lane exists, and §8.3 added
per-backfiller work revision 1 did not account for. Slice 2 revised S → M on
2026-08-12 for the reasons in §7.

Deliberate ordering choices:

- **Merge 1a before anything else starts.** It creates `app/Services/Migration/` and
  `MediaUrlResolver`, which every later slice builds on. Branching the wave before
  1a lands means three worktrees each invent that scaffolding independently.
- **Slice 2 was first, and cheapest was not cheap.** Its items already existed, so it
  needed no connector, driver or migration — but the pool's auto-rule and item
  payload both turned out to be shaped for undated content, and adopting a dated kind
  changed each. Worth having first, since menus, services and shop all carry prices
  and several carry dates.
- **Shop (5) before menus (4).** `shop_products` is a `data jsonb` blob with no
  relational structure to preserve. Menus carry multi-category membership,
  per-platform pricing and `is_manual` authorship.
- **Reviews (6) after 1b, not concurrent with it** — see §4.3.

Every slice gets its own spec → plan → implement → review → checkpoint cycle.

### 4.3 Concurrency rules

**A worktree isolates files. It does not isolate the dev database.** Every branch
shares `glncumufgaqcmqhzwrxm`. That is the constraint concurrency planning must
respect; the file-level collision surface is trivial by comparison (`PoolRegistry`'s
const arrays, the PHPStan baseline, migration filenames — all mechanical).

**Rule 1 — different kinds may run concurrently.** The §8.3 hard-delete hazard is
kind-scoped:

```php
private function resolveItems(string $userId, string $kind): array
{
    $rows = DB::table('content.source_items as si')
        ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
        ->where('cs.user_id', $userId)
        ->where('si.kind', $kind)          // ← scoped
```

So a `media` run cannot destroy `service` or `product` rows. Slices working on
distinct kinds are safe to develop, backfill and verify in parallel. **1b (`media`),
3 (`service`) and 5 (`product`) are mutually safe.**

**Rule 2 — 1b and 6 must NOT be concurrent.** `GoogleBusinessConnector` declares
three streams — `profile`, `reviews`, `media` — and all three are served by a
**single** billed call:

```php
$effect = $io->effect('api', 'places.details', ['place_id' => $placeId]);
```

1b enables the `media` stream and 6 enables `reviews`, so they would edit the same
connector file, share the same ledger digest — claim-first means one gets `refused`
while the other holds the claim — and compete for the same Places budget across the
same 12 `google_business` sources. Not data corruption, but unreadable test results
and a wasted billed call.

**Rule 3 — the same kind serialises.** Two slices touching one kind must not run
their backfill and verification windows simultaneously. That window is roughly the
backfill command plus its §10 SQL assertions, not the whole slice.

**Rule 4 — one merge at a time, rebase after each.** Merge order among concurrent
slices is free; simultaneity is not. When one lands, the others rebase onto the new
`development` before merging. Prefer rebase to merging `development` in — conflicts
surface once and history stays linear.

**Rule 5 — pre-assign migration filename prefixes** before a wave starts, one block
per slice, so ordering never depends on who committed first.

Block `20260813110000`–`20260813119999` was pre-assigned to slice 5b. 5b shipped
no DDL — every write lands in an existing table — so the block went unused and
returns to the pool for whichever slice needs it next.

Same again for slice 4's `20260813120000`–`20260813129999`: `menu_item` was
already in both kind CHECKs, `content.offers` already carried `channel`/`url`,
and `content.collections.kind` has no CHECK at all, so the whole slice shipped
without a migration. That block also returns to the pool.

**Rule 6 — one worktree per session; never the main checkout.** This repo has
already lost uncommitted work to a concurrent merge. Run `git worktree list` before
starting: several are typically live.

**Non-technical caution.** 1b is the most review-heavy slice remaining — it spends
money, mirrors third-party bytes, and takes a data-loss decision on the 91
`site.content_selection` rows. A three-wide wave means it competes for reviewer
attention with two other slices. Running it two-wide with slice 3 is the safer
trade, and still surfaces slice 3's unknown (§1.6 — `FreshaServiceProjector` has
landed zero records) early.

---

## 5. Slice 0 — billed-effect driver seam (detailed)

### 5.1 What already exists

`EffectLedger::once()` implements claim-first, charge-once-by-digest, freshness
bucketing and abandoned-claim reconciliation. `HttpIo::effect()` computes the digest
and calls it. Verified: existing-row check at `:61-67`, insert-then-act at `:70-98`,
`catch (\Throwable)` marks `failed` + `settled_at` and rethrows at `:121-131`.

Target services:

- **Google:** `GoogleBusinessService::fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array`, with `PlacesBudget` injected.
- **Instagram:** `App\Services\Platforms\InstagramScraper` — **not**
  `InstagramActorAdapter`, which is a two-method interface (`input()`, `classify()`)
  with no fetch capability. `InstagramScraper` resolves an adapter internally
  (`:238-248`).

> **CORRECTED 2026-08-12 (deviation D2).** This bullet used to claim
> `InstagramScraper` "carries its own per-user cooldown
> (`partna.instagram.apify_cooldown_seconds`) and daily cap". **It does not.**
> It claims `ApifyBudget` in exactly one place — the thin-profile retry
> (`InstagramScraper.php:62`) — and its own comment says so: *"This class does
> not otherwise claim Apify budget — the controllers do."* The cap lives in
> `InstagramController::guardApifyBudget()`, which also documents that there is
> **intentionally no per-user cooldown**; `partna.instagram.apify_cooldown_seconds`
> is config with no live reader.
>
> The ingest lane has no controller in front of it, so **`InstagramActorDriver`
> claims `ApifyBudget` itself** — as shipped. Anything slice 1 adds to the
> Instagram lane must keep that property, or scheduled runs spend outside the
> daily cap.

### 5.2 The change

```php
namespace App\Ingest\Runtime\Effects;

interface BilledEffectDriver
{
    public function supports(string $kind, string $name): bool;
    public function run(BilledEffectContext $ctx): BilledEffectResult;
}
```

`BilledEffectContext` is a readonly value object carrying `kind`, `name`, `input`,
`runId`, `sourceId`, `userId`.

`BilledEffectResult` is **not** a bare `mixed` — see §5.3's null problem. It carries
`data` plus an `outcome` of `Answered` (vendor responded, including "no such place")
or `NoAnswer` (we never got a response).

A container-resolved `BilledEffectDriverRegistry` holds the drivers.
`HttpIo::runBilledEffect()` becomes a registry lookup. **An unmatched `(kind, name)`
keeps today's throw.**

| Driver | `(kind, name)` | Delegates to |
|---|---|---|
| `PlacesDetailsDriver` | `('api', 'places.details')` | `GoogleBusinessService::fetchPlaceDetailsRaw()` |
| `InstagramActorDriver` | `('actor', 'instagram')` | `InstagramScraper` |

> **CORRECTED 2026-08-12 (deviation D1).** The table used to name
> `fetchPlaceDetails()`. That method returns `mapDetails()` output, and
> `GoogleBusinessConnector` reads the **raw** Places (New) shape — so wiring it
> would have landed nothing and, worse, made the manifest's `when_unclaimed`
> reviewer-PII redaction vacuous: it would strip keys that were already null,
> so the lane would *look* compliant while dropping attribution for claimed
> accounts too. Slice 0 extracted `fetchPlaceDetailsRaw()`;
> `fetchPlaceDetails()` keeps its exact public contract by calling it and
> mapping. Using the raw variant also avoids firing `resolvePhotoUrls()`'s
> up-to-15 extra billed media calls per run — those belong to slice 1, which
> should budget for them explicitly.

**A second, opt-in interface exists as shipped:** `PrecheckableBilledEffect`
(`precheck(BilledEffectContext): void`). A driver that can tell it will not
attempt an effect — an exhausted budget, a missing credential — throws
`EffectNotAttempted` from it and no ledger row is claimed at all. Consulted
after the existing-row read and before the claim, so a settled `ok` row still
replays when the budget is gone. It is an OPTIMISATION, never the authority:
it runs outside the claim, so `run()` must still enforce whatever it screens
for, and it must guard its own I/O (see §13.6).

The driver is invoked from inside the `once()` closure, exactly where the throw is
today, so claim-first and charge-once are preserved by construction.

### 5.3 Decisions

**`HttpIo` gains a `userId` constructor parameter.** Both drivers need it.
`RunExecutor` already has the source row in scope — it passes `$source['id']` at
`:83` and reads `$source['user_id']` at `:306`.

**Budget refusal settles as `refused`; it does not delete the claim.**

Revision 1 proposed deleting the claim on `EffectRefused`. Two things make that
unsafe:

1. `EffectRefused` **already exists** (`app/Ingest/Runtime/EffectRefused.php`) and
   its docblock covers "off-manifest host, exhausted budget, or a ledger refusal".
   It is already thrown by `HttpIo::admit()` and caught by `RunExecutor:87`.
   Deleting claims on it would delete them for *admission* failures too — so an
   Apify run that bills on start and then polls an off-manifest host would have its
   claim deleted and be re-run, billing twice.
2. "Refusal precedes the call" is **false** for the named driver.
   `fetchPlaceDetails()` claims budget *inside* a retry loop of up to 3 attempts and
   throws `PlacesBudgetExhaustedException` on any of them — so a throw on attempt 2
   follows a request that already reached `places.googleapis.com`. A client-side
   timeout does not mean Google did not serve and bill.

**Therefore:**

- A new, distinct `BudgetRefused` exception, thrown **only** by a driver, **only**
  before its first vendor call. No transport path can raise it. `EffectRefused`
  keeps its current meaning and its current handling.
- `once()` catches `BudgetRefused` and settles the row `status='refused'` with
  `settled_at`. It does **not** delete.
- `verdictFor()` learns that a `refused` row does not block a fresh claim.

> **CORRECTED 2026-08-12 (deviation D3) — the three bullets above describe a
> design that was NOT built. What shipped:**
>
> - The type is **`EffectNotAttempted`, a subclass of `EffectRefused`** — named
>   for the fact it asserts ("no request left the process") rather than the
>   reason ("budget"). A missing Places key and a missing Apify token are
>   exactly as pre-vendor-call as a budget denial, and one type covers all
>   three. Because it subclasses `EffectRefused`, `RunExecutor`'s existing
>   handler already folds it to `budget_skipped` — **zero `RunExecutor`
>   changes**.
> - `once()` **DELETES** the row it just inserted, rather than settling
>   `refused`. Settling could not work: `verdictFor()` returns early on
>   `settled_at !== null` and `digest` is the PRIMARY KEY, so "does not block a
>   fresh claim" would have needed either a delete anyway or a reclaim-UPDATE
>   overwriting the audit trail the design existed to protect. The DELETE is
>   guarded `->where('status','claimed')->whereNull('settled_at')`, so
>   "provably unsettled" is an enforced precondition, not a comment.
> - **No `verdictFor()` change and no `refused` status in play.** There is no
>   reclaim logic to reason about.
>
> This is the ONE path in the class that removes a row, and `EffectLedger`'s
> class docblock says so, keeping `reconcileAbandoned()`'s "never deletes"
> contract honest.

This preserves retryability *and* the money-adjacent audit trail. Revision 1's
success criterion — "a budget refusal leaves no blocking row" — was satisfiable by a
system that had destroyed the evidence, and `reconcileAbandoned()`'s docblock is
explicit that the ledger "NEVER sets settled_at, never deletes".

Because `fetchPlaceDetails()` can throw its budget exception mid-loop, the
`PlacesDetailsDriver` must **not** map `PlacesBudgetExhaustedException` to
`BudgetRefused` unconditionally. It maps only when no attempt has fired; otherwise
it is a genuine failure and the claim stays.

> **CORRECTED 2026-08-12 (deviation D5).** Neither prescription above shipped —
> no attempt-counter was extracted and no pre-flight `claim()` was added inside
> the driver's `run()`. What ships is simpler and needs no change to
> `GoogleBusinessService`: `fetchPlaceDetailsRaw()` **reports** a first-attempt
> denial as `PlaceDetailsFailure::BudgetDenied` (→ `EffectNotAttempted`, claim
> released) rather than throwing, so an escaping
> `PlacesBudgetExhaustedException` can only be a MID-LOOP denial — meaning a
> request already reached Google and may have been billed. The driver catches
> it and returns **`NoAnswer`**, which keeps the settled row and its claim.
> That is the only safe reading of "we might have been charged".
>
> Catching rather than letting it propagate is the point: an escaping
> `RuntimeException` reaches `RunExecutor`'s catch-all, marks the stream
> `error` and pages on-call. **A spend ceiling is not our bug.** The same
> reasoning is why a `NoAnswer` is RETURNED as a `failed` verdict rather than
> rethrown — `error` means "our bug", while a Google 429 is `unavailable`.
> (§13.6 records a regression where a later change broke exactly this property
> and had to be fixed; the shape recurs.)

**A null result is disambiguated, never blanket-settled as `ok`.**
`fetchPlaceDetails()` returns null for four different reasons, three of which
involve no charge:

| Cause | Site | Outcome |
|---|---|---|
| Missing API key | `:211-214`, before any HTTP | **`EffectNotAttempted`** — corrected, see below |
| Network exception on every attempt | `:250` | `NoAnswer` |
| Non-2xx (429 / 5xx outage) | `:262` | `NoAnswer` |
| Genuine not-found | mapped response | `Answered`, data null |

This matters because `partna.ingest.effect_freshness_seconds` defaults to **604800
— seven days**. Settling a Places outage as `ok`+null would cache "no data" for that
digest for a week, and `verdictFor()` (`:139-141`) would replay it as
`['status'=>'ok','result'=>null,'cached'=>true]`. A missing key in an environment
would settle *every* place as permanently-ok-null. Only `Answered` settles `ok`.

> **CORRECTED 2026-08-12 (deviation D6), against the shipped code — read this
> before relying on the table above.** This paragraph used to end "`NoAnswer` settles `failed` and
> is retryable." **It is not retryable**, and the two claims below are the ones
> slice 1 must design against, because its Instagram actor returns `NoAnswer` on
> every vendor outage.
>
> - **`NoAnswer` settles `failed` and is NOT retryable inside the freshness
>   window.** The row stays, settled, and `verdictFor()` replays it as
>   `['status' => 'failed', 'result' => null, 'cached' => true]` for the full
>   seven days. That is deliberate — `EffectLedger`'s own catch states it: "we
>   cannot know whether the vendor billed us… this digest is inert until the
>   freshness bucket rolls." An outage is cached as a non-answer for a week, and
>   the connector folds it to Unavailable rather than erroring.
> - **A missing API key is `EffectNotAttempted`, not `NoAnswer`**
>   (`PlacesDetailsDriver:143`). That is the opposite behaviour: the claim row is
>   DELETED, so the digest is retryable the instant the key is set. Reading the
>   old table would lead a slice-1 author to believe a misconfigured environment
>   self-heals only after seven days, when it recovers immediately.
>
> Net: `EffectNotAttempted` = nothing was sent, row removed, retry freely.
> `NoAnswer` = something was sent and we do not know if we were billed, row
> kept, inert for the window. The whole distinction is about whether money may
> already have moved.

**Kill switch.** `config('partna.ingest.billed_effects_enabled')`, default **false**,
per environment. Its purpose is *activation gating*, not budget safety — `PlacesBudget`
(global, per-SKU and per-user daily caps) and `ApifyBudget` (global and per-actor
caps) already bound spend, and `ingest:dispatch` only claims `auto_sync = true`
sources, which Instagram and Google are not. The switch exists because `production`
deploys on push, so slice 0 can reach prod weeks before slice 1 intends paid fetching
to be live there.

> **CORRECTED 2026-08-12 (deviation D4).** This paragraph used to end: "It must
> also gate `SourceProvisioner`'s stream provisioning, or streams get
> provisioned and dispatched…". **False on two counts, and no provisioner
> change shipped.** `SourceProvisioner` never touches `ingest.streams` at all —
> `RunExecutor::ensureStream()` does, at run time — and
> `SourceProvisioner::schedulable()` already returns
> `$manifest->cost === CostClass::Free`, so both billed sources are created
> `auto_sync = false` and `SourceScheduler::claimDue()` never claims them.
> Confirmed on dev after the slice 0 deploy: `auto_sync` is `false` on every
> `google_business` and every `instagram` source.
>
> The switch is therefore activation gating only, exactly as the sentences
> above it say. Slice 1 turning on paid fetching means setting
> `PARTNA_INGEST_BILLED_EFFECTS_ENABLED` **and** deliberately flipping
> `auto_sync`; neither implies the other.

### 5.4 Verification

- Unit: registry dispatch by `(kind, name)`; unmatched kind still throws; switch off ⇒ throws.
- Ledger: `BudgetRefused` settles `refused` and the digest is immediately re-claimable; `EffectRefused` retains today's behaviour; any other throwable leaves the row `failed`.
- Driver: each of the four null causes maps to the correct `BilledEffectResult` outcome; only `Answered` settles `ok`.
- Dev assertion — `ingest.effects` has held zero rows since creation:

```sql
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;
```

- Post-deploy: `cloud env:logs partna development --minutes 10`, clean.

### 5.5 Out of scope for slice 0

Stream provisioning for Instagram and Google Business, projecting `kind='media'`,
and any pool or wire change. Slice 0 ends when a billed effect can execute and is
ledgered.

---

## 6. Slice 0b — manual-source write lane

**Why it exists:** §1.7. `content.sources` has never held a non-`connection` row,
and `ProjectionWriter` refuses sources without a `connection_id`. Slices 1, 3, 4 and
5 all write owner-authored items and are blocked without it.

**Scope.** Extend `ProjectionWriter` (or extract a shared writer beneath it) so a
source with `kind='manual'` and a null `connection_id` can land items through the
same path as a connection source — `source_items`, `identity_keys`, `item_anchors`,
`items`, `f_*`, `item_media`, `offers`, `item_tags`. `ensureContentSource()` gains a
manual branch honouring the one-per-user unique index.

**Why not let each backfiller write raw SQL:** it would duplicate ~400 lines of
`ProjectionWriter` semantics ten times, and any divergence produces items the
identity resolver treats inconsistently.

**Definition of done:** a manual source can land an item that `PoolResolver` returns
and a subsequent connector run does not corrupt — see §7.3.

---

## 7. Slices 1–7 — scope and definition of done

Each gets its own spec before implementation.

### Slice 1 — Media pool live · XL
Provision `media` streams for Instagram and Google Business; fix the §2.3 Instagram
fingerprint bug **before** first sync; project `kind='media'`; extend
`ProjectionWriter` per §2.1(a) for uploads; apply the §2.2 `PoolResolver` change
including the variant decision; migrate `site.content_selection` per §2.4; retire
the `gallery` / `designMedia` wire keys.

`PoolRegistry` already has `media` with `PAGE_KEYS['media']='gallery'`, so the pool
registration itself is done.

**Done when:** one dev account's media pool contains uploads, Instagram photos and
Google photos in a single library with a single selection, rendered publicly;
`content.items WHERE kind='media'` is non-zero; no duplicate assets after two
consecutive Instagram syncs; `site.content_selection` has no readers.

### Slice 2 — The events pool · M
**Scoped down and re-sized 2026-08-12**, after planning it against the database.
Revision 2 read the three unread kinds as one problem — "adopt the kind, add the
pool" — and sized it S on the strength of the items already existing. They are not
one problem, and two of the three cannot be adopted without a decision this document
has no authority to make.

`PoolRegistry` entry, `PAGE_KEYS`, `PAGE_LABELS`, per-pool section shape, section
provisioning for existing users, and the read path for **`event` only**.

- **`event` is buildable and carries the value.** It already has a page
  (`SitepageId::Events`), a working projector, and 14 rows. But it is not a registry
  one-liner: the pool contract's auto half (`latest_per_auto_source`) emits exactly
  ONE item per connection source, which for a ticketing platform shows a visitor one
  event and hides the rest. Events need their own rule (`upcoming_occurrence`) and
  ordering (`occurrence`), and `PoolResolver`'s payload has no `startsAt`, venue or
  price — so `f_occurrence`, `f_place` and `offers` cannot reach the wire at all.
  That is the real content of this slice, and the reason it is M, not S.
- **`channel` is deferred on product grounds.** Not architecturally blocked — two
  pools may share a page key — but its 7 rows are Twitch 3 / Spotify 3 / SoundCloud
  1, platforms that already own **two different** pages (Watch and Listen). One pool
  cannot route them to both, so any single-pool answer mixes them onto one page. And
  a channel card is a *profile*, not a piece of content. Owner's call, unmade.
- **`article` is deferred on product grounds.** Technically unblocked; needs a
  Writing page, which is a new `SitepageId` case in LOCKSTEP with
  `partna-monorepo/.../page-taxonomy.ts`. Owner declined 2026-08-11. Its one row is
  `"Please update feed subscription"` — Substack feed housekeeping, not content.

**Two data problems this slice owns**, both found by asserting against dev rather
than trusting the row counts in §1.3:

- 6 of the 14 event rows are empty shells — `headline_cache` NULL, no facets —
  wreckage from the 2026-07-28 `f_occurrence_zone_confidence_check` violation
  logged in `ingest.anomalies`. Both underlying bugs are already fixed
  (`20260731230000` widened the CHECK; `RunExecutor:196` raised the anomaly to
  `critical`), but the data never re-derived because `RunExecutor:168` gates
  projection on records having *changed* and theirs never did. **The repair is
  `ingest:project`, not a code change** — but it is not optional, and §1.3's
  "14 rows" overstates what is renderable.
- 3 more carry `source_items.removed_at` with a live `content.items` — the §9.8
  asymmetry, arriving early. Settled here: an item whose every source item is
  retired is itself retired. **One-way** — `content.items.removed_at` is never
  cleared by reappearance (`ProjectionWriter:272-275`), so a re-listed event does
  not return.

**Done when:** the live `event` rows are reachable through a pool and rendered
publicly with their dates, venues and prices; every live event item has a headline
and an occurrence; and the legacy events lane has no readers.

**Note the last clause is separable.** Retiring the legacy lane means migrating 13
`site.item_slugs` event permalinks and their 301s into `content.item_slugs` (which
holds 0), and the two tables key differently — `item_key` is a payload hex id, not
a coord — so at least 3 of the 13 have no content item to map to. If that half is
deferred, the checkpoint must say the criterion is unmet rather than tick it.

Plan: `docs/superpowers/plans/2026-08-11-content-pool-slice2-events.md`.

### Slice 3 — Services → `content.*` · L
**First: prove `FreshaServiceProjector` executes** (§1.6 — it has landed zero
records). Then backfill `site.services` (82), `service_categories` (18) →
`collections`, `service_category_assignments` (61) → `collection_items`. Pricing →
`offers` with `qualifier='from'` where applicable. Legacy `deleted_at` →
`items.removed_at`, and **never** `source_items.removed_at` — the latter is cleared
on reappearance, which would resurrect a user-deleted row.

### Slice 4 — Menus → `content.*` · XL
`MenuItemProjector` covers DoorDash, Square and Uber Eats. Backfill 370 items, 52
categories, 464 multi-category links, 370 platform rows. `base/pickup/delivery` →
`offers` keyed by `channel`. `is_manual` → manual source. `badges` → `item_tags`;
`rating`/`rating_count` → `f_rated`. **Must migrate `site.item_slugs`** — see §9.3.

### Slice 5 — Shop → `content.*` · L → split into 5a + 5b on 2026-08-12
Decompose `shop_products.data` jsonb into `items`, `offers`, `item_variants`,
`item_media`. Sub-specs: `2026-08-12-slice-5a-shop-data-design.md` (the data move,
14 endpoint repoints, legacy goes inert) and
`2026-08-12-slice-5b-shop-render-KICKOFF-PROMPT.md` (the pool, the `shop` page,
the public wire change).

**Two claims in this paragraph were false and are corrected here in place.**
`GumroadProductProjector` "exists" but shares no field name with the blob
(`title`/`price` string/`variants` vs `name`/`price_cents`/`pay_what_you_want`)
and has **no `ingest.sources` row at all** — nothing in slice 5 runs a projector.
And `shop_brands → f_catalog` is not an option: `f_catalog` is a *music* facet
(`release_type`, `track_number`, `isrc`, `gtin`, `sku`) keyed `(item_id,
source_id)`, so it cannot describe a store. **Decided in 5a:** brands become
`content.collections` rows with a `content.storefronts` 1:1 sidecar carrying the
behaviour; `f_catalog` holds product identifiers only.

### Slice 6 — Reviews → `content.*` · M
Depends on slice 0. Must preserve the `when_unclaimed` redaction scope for
reviewer-identifying fields (`author`, `author_uri`, `author_photo`) declared in
`GoogleBusinessConnector`'s `Manifest::$redactionScopes`, and honour the outstanding
LEGAL-2 obligation.

**Shipped and LIVE-VERIFIED on dev 2026-08-14 (`9efd9516c`); prod out of scope.**
Sub-spec: `2026-08-12-slice-6-reviews-design.md`. Wire manifest:
`docs/wire-changes/2026-08-12-slice-6-reviews.md`. What it settled:

**Checkpoint — Task 10, measured on `glncumufgaqcmqhzwrxm`, counts not names.**

| Assertion | Before | After |
|---|---|---|
| review items with `headline_cache` | 15 | **0** |
| `f_text` rows for review-kind items | 15 | **0** |
| of those, carrying a real display name | 5 | **0** |
| total review items (must survive) | 15 | **15** |

Redaction, both directions, with the new column inheriting it unchanged:
claimed **5 rows / 5 named / 5 `author_uri`**; unclaimed **10 rows / 0 named /
0 `author_uri` / 0 photo**, text and rating present on both. That `author_uri`
landed 5/5 and 0/10 without a manifest edit is the proof the `when_unclaimed`
scope already covered it, which §2.4 asserted but could not show until now.

**The claim transition behaves as §2.1 says, not as intuition says.** One
unclaimed user was flipped to `active` by direct SQL, re-projected scoped to
that user, and attribution stayed **0 named / 0 `author_uri`** — redaction is
applied at LANDING, so the stored doc is permanently redacted and only a fresh
billed fetch past the freshness window could restore it. Status restored.

**`content:prune-orphaned-review-pii` exercised against real rows for the first
time.** One claimed review was orphaned and aged past the grace window: dry-run
reported exactly 1, the real run deleted 1 and bumped 1 site, and afterwards the
`f_review` row, any `f_text` row and `headline_cache` were all gone. Before this
slice that same prune would have left the name in the latter two. Restored by
re-projection; counts back to baseline.

**`content.source_stats` proved on a NEVER-RUN source**, not a replay — effect
replay returns the cached Details payload without calling the driver, so
re-running a recent source proves nothing. It landed `rating_avg 4.4`,
`rating_count 725`, `summary_text` null (Google returned no `reviewSummary` for
that place — a data fact, not a mapping gap).

**Read back off the live public wire**: `pools.reviews.stats` =
`{ratingAvg: 4.4, ratingCount: 725, summaryText: null}`, five review items,
`headline` null on every one, the `review` block carrying all six keys including
`authorUri`; and `/integrations` no longer serving any of the four retired keys
while the rest of that lane still publishes.

**One premise of the plan was wrong, in our favour.** It called
`content:purge-review-headline-pii` mandatory because `upsertSingletonFacet`
never deletes. True — but `ingest:project --rebuild` "first drops the
projection-derived rows … so a projector whose OUTPUT SHAPE changed leaves no
orphans behind", which is exactly this change, and the plan's own Task 10 runs
the rebuild first. Both purge runs therefore reported "nothing to do". The
command is still correct and unit-tested; it is the targeted tool when a full
rebuild is not run. **The dev run demonstrated it executes cleanly and leaves
zero remnants — it did not demonstrate its delete path.**

Nightwatch: no new exceptions since the deploy (most recent open issue last seen
`2026-08-13T16:00`, deploy `2026-08-14T00:54`). Dev logs clean.

- **The rows already existed** — see §0d. The slice was a read path plus a PII cleanup,
  not an ingest build.
- **Reviews get a pool, with curation restricted to exclusion.** `PoolRegistry` gained
  `EXCLUDE_ONLY_POOLS` and `MANUAL_ADD_FORBIDDEN_POOLS`, both currently `['reviews']`:
  pins, reorders and hand-authored `review` items are refused 422 on all four write paths.
  The first pool whose content is not the owner's own.
- **The reviewer's name is now in `content.f_review` and nowhere else.** The projector
  used to set `headline` to the display name, which `ProjectionWriter` folded into
  `f_text` and `items.headline_cache` — two copies outside `redactionScopes`,
  `content:prune-orphaned-review-pii` and the DSAR omission. `headline` is null by
  contract for `review`, and `content:purge-review-headline-pii` cleared the existing
  copies.
- **The aggregates moved with the reviews.** `PublicIntegrationConnectionResource` no
  longer publishes `reviews`, `reviewSummary`, `rating` or `reviewCount` for
  `google-business`; they serve from `content.source_stats` (§1.5) as
  `pools.reviews.stats`. The FETCH is unchanged — this retires a read, not a call.
- **The aggregates ride the `reviews` stream, not `profile`**, precisely because
  `profile` is vestigial (§0d) and slice 7 could legitimately delete it. A `Sample`
  stream emitting a source-level fact is the cheaper mistake than a public-wire
  dependency on machinery scheduled for plausible removal.
- **LEGAL-2 is not discharged.** It is inherited and extended: owner suppression of
  individual reviews is a new adviser question (`docs/legal/reviewer-data-disclosure.md`
  §4 point 5).

### Slice 7 — Legacy teardown · M
Drop the ten tables in §1.4. Re-home the orphaned observers and policies (§9).

**Gate:** the §8.4 coverage gate green on dev for every migrated type. Irreversible
in practice — Supabase is Pro since 2026-08-14 (daily backups), but the `pg_dump`
per-table exact-count gate stays mandatory as the surgical control.

#### Carried from slice 2 — standalone events still ride the legacy wire

**Deferred deliberately on 2026-08-12, not overlooked.** Slice 2 retired the ACCOUNT
half of the legacy events lane: organiser rows now publish `payload: {}` and the
Events page is pool-derived. It did **not** retire STANDALONE rows — a
`resource_kind='event'` connection, one event added by URL from the Tickets & Events
card — which still publish their full event fields through
`GET /api/public/profiles/{handle}/integrations`.

**Why they were left.** A standalone row has no ingest connector
(`ConnectorRegistry::MAP` holds `eventbrite` and `humanitix` only, and
`SourceProvisioner` provisions from an ORGANISER url), so it lands no
`content.items` and the pool cannot represent it. Emptying its payload would have
made add-an-event-by-URL publicly **inert** rather than migrating it. Dev carried 2
active standalone rows at the time.

**What changed since, and why this is now doable.** Slice 0b shipped the manual write
lane, so there is now a writer that can put an owner-authored item into
`content.items` without a connector — which is exactly what a standalone event is.
`ProjectionWriter::writeManualItem()` is the seam, with a coord derived from the
event URL (§1.7's one-coord-per-URL rule applies).

**Scope when someone picks this up** — it is a slice-sized piece of work, not a
tidy-up:

1. Write existing standalone rows into `content.items` through the manual lane, as a
   backfill (§8 applies: idempotent, production code, coord = `manual:{sha1(url)}`).
2. Repoint the Tickets & Events card's add-an-event verb at that lane, so new
   standalone events land as content items rather than connection payloads.
3. Only then empty the standalone payload on the integrations wire — a **breaking
   wire change** needing its own manifest, because a frontend Events page currently
   reads BOTH `profile.pools.events` and standalone rows from `/integrations`.
4. Decide what happens to `site.item_slugs` permalinks for standalone events, which
   the pool's `content.item_slugs` lane does not yet hold.

**Until all four land, slice 7 must not drop the legacy events wire.** Step 3 is the
one that unblocks the teardown; steps 1 and 2 are prerequisites for it not being a
data-loss event. Recorded in slice 2's wire manifest under "STANDALONE event rows are
UNCHANGED — read this before migrating".

**Second gate, added 2026-08-12 — frontend.** Slice 1a deliberately keeps the legacy
`gallery` and `designMedia` wire keys because Partna-App and partna-monorepo still
read them. `site_media` pools `gallery` and `content` therefore cannot retire until
both frontends consume `pools.media` instead. That work sits outside this
programme's backend-only mandate and is not estimated here; slice 7 must confirm it
has landed rather than assume it. The remaining nine tables in §1.4 are unaffected
and may be dropped on the coverage gate alone — **slice 7 may be split** if the
frontend lags, dropping the commerce tables first and the media lane later.

#### Carried from slice 6 — three things the teardown must not walk into

Recorded 2026-08-13. The detail, including two findings slice 6 deliberately did not act
on, is in `docs/superpowers/plans/2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`.

1. **`content.source_stats` exists and the public wire depends on it** (§1.5). It is not a
   scratch table: `pools.reviews.stats` is the only remaining source of the Google star
   average, review count and review summary, because slice 6 retired all four keys from
   `PublicIntegrationConnectionResource`.
2. **The `google_business` `profile` stream is redundant with the `IdentitySync` fold, not
   merely unused.** Its intended consumer (field bindings) was dropped by
   `20260805110000`, and the identity fold runs off `IntegrationConnectionObserver::saved`
   instead. **Retire it or justify keeping it — do not drop it silently**, and do not move
   `source_stats` onto it.
3. **`PruneOrphanedReviewPiiCommand` still depends on `content.f_review`** and is now the
   sole retention mechanism for reviewer identity, since slice 6 removed the two
   ungoverned copies. Anything that changes how review items are written or retired must
   keep that command's guarantee true.

---

## 8. Backfill architecture

One backfiller per type under `app/Services/Migration/`, behind a common interface,
each driven by an artisan command supporting `--dry-run` and reporting counts. All
write through the slice-0b manual lane, never raw.

### 8.1 Idempotency

`content.source_items.coord` is `platform:account_ref:external_key`
(`ProjectionWriter.php:134`), unique on `(source_id, coord)`, and `stream_id` is
**nullable** — so a manual backfill can insert coords without a stream. Owner-authored
rows take a `manual:{legacy_uuid}` coord, preserving the legacy identifier after the
table is dropped.

### 8.2 Scoping

`content.items` is scoped by `user_id`; menus are scoped `menu_item → menu → site`;
`site.content_selection` is scoped by `site_id`; and `site.sites` has **no
`deleted_at`** — sites die by cascade, not soft delete. Partna is individual-only so
user↔site is effectively 1:1, but each backfiller must derive `user_id` through the
site explicitly and fail loudly on a site with no owner.

### 8.3 The connector must not destroy backfilled rows

`ProjectionWriter::resolveItems()` (`:391-404`) unions **every** live `source_item`
for `(user_id, kind)` across all sources, not just the stream being projected. And
`mergeInto()` (`:560-586`) ends:

```php
$hasCuration = DB::table('site.section_items')->where('item_id', $discardedItemId)->exists()
    || DB::table('content.manual_overrides')->where('item_id', $discardedItemId)->exists();

if (! $hasCuration) {
    DB::table('content.items')->where('id', $discardedItemId)->delete();
}
```

A hard DELETE. So a backfilled item that unions with a synced one, and carries
neither a pin nor an override, **is silently destroyed by the next connector run**.
Revision 1's claim that backfill "converges with the connector" was wrong.

**Mitigation, to be settled in slice 0b:** either a backfilled item is anchored so it
always wins the merge, or `mergeInto()` learns that a manual-source item is never
the discarded side. The second is preferable — an owner's row should never lose to a
scrape. Every backfill slice must include a test that runs the relevant connector
*after* the backfill and asserts the rows survive.

### 8.4 The coverage gate (replaces revision 1's equality gate)

Revision 1 required "row counts and field-level equality between each legacy table
and its `content.*` projection". That is **incompatible with the architecture**:
`MenuItemProjector` serves three platforms and the identity resolver unions across
sources, so 370 legacy dishes will by design collapse to fewer `content.items`. A
count-equality gate would either fail permanently or be quietly weakened exactly when
it is load-bearing.

**The gate is coord coverage, not item counts:** every legacy row must have a live
`content.source_items.coord` traceable to it, and every field asserted per-coord.
Item-level collapse is then expected and visible rather than a test failure.

---

## 9. Integration surface — what else breaks

Revision 1 omitted all of this. None of it is optional.

### 9.1 `BuildState` bumps are manual, and nothing enforces them

**Corrected 2026-08-12.** Revision 2 stated that `bump()` is called at every
raw-write seam "with a CI check keeping the list current". That sentence is in
`BuildState`'s own docblock (`app/Site/Documents/BuildState.php:15-19`) and this
spec repeated it without checking. **It is false.** The class contains `bump()`,
`read()` and `commit()` — no list, no constant, no test. Nothing greps as a
registry, and `./vendor/bin/pest --filter=BuildState` matches no test description,
so it reports no-tests-found, which reads like a pass.

The *practice* is real — 17 call sites bump by hand — but it is discipline, not a
gate. Every backfiller in this programme is a raw-write seam, and **if one forgets
to bump, no test and no CI job will say so.** Plans must not budget for protection
that does not exist.

What `bump()` actually buys: it increments `site.site_build_state.content_revision`,
which `DocumentBuilder` reads before a build and compare-and-sets on commit
(`:36`, `:51`, `:70`), and which `SectionTracer` reads to explain a section. That
is the **document build lane only**. See §9.2 for what it does not do.

### 9.2 Cache invalidation — three separate lanes, not one

CLAUDE.md: any write path bypassing Eloquent "MUST invalidate the affected cache
keys explicitly; it will not be caught by an observer." Ten backfillers, all raw.
§2.2 also changes the *content* of every cached public profile payload.

**Sharpened 2026-08-12.** Revision 2 asked each slice to "name the keys it
invalidates". Necessary, but it left the impression that a `BuildState::bump()`
covers the public surface. It does not — a raw write that changes rendered content
must touch **all three** of these, and they are independent:

| Lane | Invalidated by | Bumping alone is enough? |
|---|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` | yes — this is what it is for |
| The 60s public-profile payload cache | `site.sites.updated_at` moving | **no.** `IndividualProfilePayloadBuilder::cacheKey()` (`:723-730`) composes the key from `$site?->updated_at?->timestamp`, and `bump()` writes `site.site_build_state`, a different table. Without an explicit touch the stale payload is served for the full TTL |
| The Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | **no.** The CDN outlives the origin write — found verifying P2 of the 2026-08-05 programme, and why `PoolController::poolChanged()` purges as well as bumps |

`PoolController::poolChanged()` is the reference implementation for the second and
third; it is the shape every backfiller should copy. Each slice must still name its
keys, and must additionally state whether it touched `site.sites.updated_at` and
whether an edge purge was dispatched.

### 9.3 `site.item_slugs` and the 301 lane
**DONE — slice 4, 2026-08-16 (§23.5).** All 318 `menu_item` slugs live in
`content.item_slugs`, and ongoing allocation re-homed off `MenuItemObserver`
onto `ContentItemSlugAllocator::SLUGGED_KINDS`, which
`ProjectionWriter::refreshItemCaches()` already honours. Removal frees a slug
via `ManualServiceWriter::markRemoved()`.

Two things this section got wrong, corrected here because slice 7 reads it:
the destination allocator was NOT absent — `ContentItemSlugAllocator` shipped
in slice 2 — and the retired set was empty, because `MenuFetchJob` forgets a
vendor-renamed dish's slug rather than retiring it. Migrating to `content.*`
is what makes vendor renames redirect at all.

The 11 legacy `item_type='event'` rows in `site.item_slugs` are untouched and
remain slice 7's to delete.

### 9.4 Observers orphaned by slice 7
`MenuItemObserver`, `Core\MenuObserver`, `Core\ServiceObserver`,
`Core\ServiceCategoryObserver`, `Core\SiteMediaObserver` all key off dropped tables.
Their side-effects — slug bookkeeping, cache invalidation, build bumps — must be
re-homed onto the `content.*` write path or they are lost silently.

### 9.5 GDPR / DSAR export
`DataExportPayloadBuilder` streams `site.services` as a named export section and
pins `services` and `service_categories` in its declared return shape. Dropping
those tables breaks the export. Data must be re-sourced from `content.*` and the
section keys decided — noting the 2026-08-05 precedent that DSAR allowlists
deliberately retain legacy keys so previously-stored payloads stay disclosable.

### 9.6 Policies
`ContentItemPolicy` is kind-agnostic (authorises on `user_id`), so new kinds need no
new policy. But `ServicePolicy` and `ContentSelectionPolicy` become orphaned, and
`PolicyCoverageTest` asserts every model has a policy or a justified
`POLICY_EXEMPT` — slice 7 will trip it.

### 9.7 Analytics continuity
`mergeInto()` hard-deletes merged-away items. Historical `analytics.item_views` and
`content_popularity_scores` rows reference item ids that migration will merge or
delete. Each slice must state whether historical analytics are repointed, orphaned,
or accepted as lost.

### 9.8 Item retirement asymmetry
`content.items` holds 106 episodes while only 90 live `source_items` remain — items
are not retired when their source items are. The backfill and the coverage gate will
meet this asymmetry and must decide it deliberately rather than discover it.

**Narrowed 2026-08-13 (slice 5b §3.3).** An **owner-authored** write may clear
`content.items.removed_at`; a **connector** re-observing an item never may. The
one-way rule was written against scrape flapping, which must not undo a
deliberate removal — an owner re-adding a product through `addProduct` /
`setProducts` is not that. Slices 3, 4 and 6 inherit the narrowed form.

**The rule is enforced caller-side, not inside the writer — a slice adopting
this pattern must check its own call sites rather than assume the write
function guards itself.** In 5b, `ShopContentWriter::syncStore()`'s un-retire
step is unconditional for every item it links — it has no owner-vs-connector
branch and its own docblock says so explicitly. It is reached from both owner
paths (`ShopController`, `ShopProductSeeder`) and the scheduled connector path
(`ShopFetch::fetch()` → `ShopCatalog::syncLatest()` → `syncStore()`, a
6-hourly refresh, `auto_sync_latest` absent meaning ON). The rule holds only
because `ShopFetch` itself skips hand-curated brands and the individual
bucket before ever reaching `syncStore()` — so the scheduled path can only
un-retire items its own top-N windowing retired, never one an owner curated by
hand. Gating `syncStore()` itself with a flag was considered and rejected by
the owner 2026-08-13.

---

## 10. Verification and communication

**Per-slice checkpoint** appended to this document:

1. The SQL run against dev, with output pasted in.
2. Pest test names proving connector → projector → item → pool → wire.
3. `cloud env:logs partna development --minutes 10` scan result.

**Wire-change manifest.** `docs/wire-changes/YYYY-MM-DD-<slice>.md` — endpoint,
before shape, after shape, consuming repo.

**Testing note.** Tests run SQLite while production is Postgres. Every
constraint-bound write here — `items_kind_check` (14 kinds), `offers_qualifier_check`
(7 values), `item_media_role_check` (`cover|gallery|poster|avatar|logo`),
`media_assets_fingerprint_unique` `(user_id, fingerprint)` — must be verified against
the DDL in `supabase/migrations/`, not merely against a green suite.

---

## 11. Documentation debt discharged by this spec

`docs/2026-08-05-platforms-as-sources.md` closes with "The program is complete".
That sentence is false and has already mis-scoped downstream work. It must be
amended in place to state that Media never shipped, with a pointer here — as long as
it reads complete, every future session starts from a false map.

---

## 12. Slice 0b checkpoint — 2026-08-12

**Status: DONE. §3 invariant 1 discharged — live assertion below, with output.**

Merged to `development` and deployed 2026-08-12 (`196d29d18`). The
"Outstanding" section that stood here while the branch was unmerged is now
§12.5, kept as the record of what was run rather than deleted.

### 12.1 Pre-implementation baseline (run against dev, `glncumufgaqcmqhzwrxm`, 2026-08-12)

```sql
SELECT kind, count(*) AS sources, min(priority) AS min_priority, max(priority) AS max_priority
FROM content.sources GROUP BY 1 ORDER BY 1;
```

```
kind        | sources | min_priority | max_priority
------------+---------+--------------+-------------
connection  |      27 |          100 |          100
```

One row group. **Zero `manual`, zero `import`** — so the corrective UPDATE in
`ensureManualSource()` is defensive on dev, not load-bearing. §1.7 said 25
connections; it is 27.

```sql
SELECT count(*) AS orphan_items
FROM content.items i
WHERE i.removed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM content.source_items si WHERE si.item_id = i.id);
```

```
orphan_items
------------
           0
```

A true zero, so the post-deploy gate is "still 0" rather than "no increase".

### 12.2 What shipped

| Change | Where |
|---|---|
| `MANUAL_SOURCE_PRIORITY = 200` + `ensureManualSource()` | `ProjectionWriter` |
| `writeManualItem()` — the lane | `ProjectionWriter` |
| `upsertSourceItem()` accepts a stream-less record (`?string`) | `ProjectionWriter` |
| Owner-anchored item wins a merge (`preferOwnerAnchored()`) | `ProjectionWriter::bindGroup()` |
| `content.item_links` joins the curation predicate | `ProjectionWriter::mergeInto()` |
| Hand-adds routed onto the lane; URL-derived coord; conditional pin | `PoolItemCreateController` |
| `idx_content_sources_manual` and `section_items_unique` mirrored | `tests/Pest.php` |

### 12.3 Pest cases

`tests/Feature/Ingest/ManualSourceLaneTest.php` — 9, all passing:

1. it creates exactly one manual source per user, above connection priority
2. it raises a manual source left at connection priority by the old writer
3. it lands an owner-authored item with identity keys, an anchor, and every facet family
4. it is idempotent on the coord, so a backfill can be re-run
5. it bumps the site build state so the public document rebuilds
6. it keeps the owner-authored item when a connector run merges it with a synced one
7. it still merges two connector items on the oldest binding when no owner row is involved
8. it poisons a url on the second manual coord, which then cannot fold in
9. it poisons a url before the connector arrives, so it never converges at all

`tests/Feature/Content/PoolLaneTest.php` — 2 added (16 total, all passing):

10. it hand-adds an item that a later connector run enriches instead of stranding
11. it re-adding the same url upserts one coord rather than poisoning it

`composer test`: 7523 passed, 2 failed. Both failures are in
`RunExecutorProjectionTest` and are pre-existing parallel flakes — they pass
serially, pass in a parallel run scoped to `tests/Feature/Ingest/`, and a
second full parallel run fails a different Redis-centric set without touching
them. Serial run of the affected suites (`Ingest`, `Content`, `Site`,
`Unit/Ingest`, `Unit/Site`): **810 passed**.

### 12.4 Two plan corrections established by running the code

Both were specified wrongly in
`docs/superpowers/plans/2026-08-11-content-pool-slice-0b-manual-write-lane.md`
and are recorded here because later slices inherit the same reasoning.

**1. `bindGroup()`'s loser set is not `slice(1)`.** The plan changed only the
winner selection and left losers as "everything after the first". Those agree
only while the winner IS first. `preferOwnerAnchored()` can pick a later
element, and `slice(1)` then hands `mergeInto()` the winner as its own
`discardedItemId` — whose hard DELETE destroys the owner row the change exists
to protect — while leaving the real loser unmerged. Verified by reverting the
one line: the winner is chosen correctly and `content.items` no longer holds
it. Losers are now derived from the winner.

**2. Poisoning costs different amounts depending on ordering.** The plan
expected 3 `content.items` and instructed a stop-and-report if not; the actual
figure for the case it described is 2. The pure Resolver does return three
GROUPS, but a group is not an item — `content.item_anchors` is sticky, so
coords that converged before the poisoning rebind to the anchor they already
have. Both orderings are now pinned (§1.7 table). The expensive one — two
backfilled rows, then the connector — is the one slices 3–5 produce.

### 12.5 Live dev assertion — RUN 2026-08-12, output pasted

Executed against dev after the deploy:

```
php artisan tinker --execute="echo app(ProjectionWriter::class)->writeManualItem(
    '019f936e-115f-7203-bd51-7459da0d1959',
    'manual:'.sha1('https://vimeo.com/76979871'),
    ['kind' => 'video', 'headline' => 'Slice 0b smoke',
     'facets' => ['f_link' => ['url' => 'https://vimeo.com/76979871']]]);"
→ 8f8c4a71-b7a6-4eef-9bdd-54f1af2c913f
```

```
source_kind | priority | coord                        | stream_id | item_kind | headline_cache | keys | anchors
------------+----------+------------------------------+-----------+-----------+----------------+------+--------
manual      |      200 | manual:0c80a34bbb79d8f88529… | NULL      | video     | Slice 0b smoke |    2 |       1
```

**Every expected value met**: priority 200, `stream_id` NULL, `item_id`
non-null, `keys` = 2, `anchors` = 1.

Gates re-run after the write:

```
orphan items (gate: 0)             → 0     (unchanged from the §12.1 baseline)
manual sources (gate: 1)           → 1
duplicate current slugs (gate: 0)  → 0
```

`cloud env:logs partna development --minutes 12`: 84 entries, **0
error/critical**, and zero occurrences of `42703`, `23505`, `Manual coord …
did not resolve`, `Could not resolve a manual content source`,
`replay_unavailable` or `details_fetch_failed`.

The original command block, retained so the assertion is reproducible:

```bash
cloud command:run partna development "tinker --execute=\"
  \\\$u = App\\\\Models\\\\Core\\\\User\\\\User::whereHas('site', fn(\\\$q) => \\\$q->where('subdomain','<handle>'))->firstOrFail();
  \\\$id = app(App\\\\Ingest\\\\Projection\\\\ProjectionWriter::class)->writeManualItem(
      \\\$u->id,
      'manual:'.sha1('https://vimeo.com/76979871'),
      ['kind' => 'video', 'headline' => 'Slice 0b smoke', 'facets' => ['f_link' => ['url' => 'https://vimeo.com/76979871']]]
  );
  echo \\\$id;
\""
```

```sql
SELECT cs.kind AS source_kind, cs.priority, si.coord, si.stream_id, si.item_id,
       i.kind AS item_kind, i.headline_cache,
       (SELECT count(*) FROM content.identity_keys k WHERE k.source_item_id = si.id) AS keys,
       (SELECT count(*) FROM content.item_anchors a WHERE a.coord = si.coord)        AS anchors
FROM content.source_items si
JOIN content.sources cs ON cs.id = si.source_id
LEFT JOIN content.items i ON i.id = si.item_id
WHERE cs.kind = 'manual';
```

Expected per row: `priority` 200, `stream_id` NULL, `item_id` non-null,
`keys` = 2, `anchors` = 1. Then re-run the §12.1 orphan query — the gate is
**still 0** — and `cloud env:logs partna development --minutes 10`, expecting
no `RuntimeException: Manual coord … did not resolve to an item`, no
`Could not resolve a manual content source`, and no 23505 on
`section_items_unique`.

### 12.6 Cache invalidation (§9.2, all three lanes)

| Lane | This slice |
|---|---|
| `site.site_build_state` build state | **Bumped** — `writeManualItem()` → `bumpSite()`, and the controller bumps again for the curation write |
| 60s public-profile payload cache | **Not busted.** A content write does not move `site.sites.updated_at`, so the key does not roll. TTL-bounded staleness only (default 60s) |
| Cloudflare edge | **Purged on the endpoint only** — the controller's existing `CloudflareCachePurgeJob`. The `bindGroup()` change lands on the connector path, which has no edge purge, so a connector-triggered survivor change is visible at the edge only after TTL. Accepted: already true of every projection run |

---

## 13. Slice 0 checkpoint — billed-effect driver seam

**Status: DONE as of 2026-08-12. Both blockers cleared; live billed effect
executed and recorded. The blocker text below is kept as the record of what
was wrong and how it was resolved, not as an open item.**

Written 2026-08-12 by the slice 0b session, auditing slice 0 rather than
executing it — §3 invariant 5 forbids a slice citing another's checkpoint as
evidence, and this section is the reverse case: an independent audit recording
what is and is not established. Everything below marked "verified" was run for
this section. Nothing from slice 0's own post-deploy steps has been run, and
none of it is reported as if it had been.

### 13.1 Build state — verified complete

All 11 created files and the config wiring exist:

| Deliverable | State |
|---|---|
| `Effects/{BilledEffectDriver,Context,Outcome,Result,DriverRegistry}.php` | present |
| `Effects/{PlacesDetailsDriver,InstagramActorDriver}.php` | present |
| `EffectNotAttempted.php`, `EffectNoAnswer.php` | present |
| `PlaceDetailsResult.php`, `PlaceDetailsFailure.php` | present |
| `config/partna.php:1009` `billed_effects_enabled` | present, defaults **false** |
| `.env.example:577` `PARTNA_INGEST_BILLED_EFFECTS_ENABLED=false` | present |
| `AppServiceProvider:120` registry singleton | bound |

Its seven Pest files run green: **56 passed, 147 assertions.**

### 13.2 Pre-deploy dev baseline — verified (`glncumufgaqcmqhzwrxm`, 2026-08-12)

```sql
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;
```

```
(0 rows)
```

Confirms §5.4: `ingest.effects` has held zero rows since creation. **No billed
effect has ever executed.**

### 13.3 BLOCKER 1 — CLEARED 2026-08-12. Was: set on neither environment

`config('partna.ingest.billed_effects_enabled')` reads
`PARTNA_INGEST_BILLED_EFFECTS_ENABLED` with a default of `false`. Read from
Laravel Cloud 2026-08-12:

```
development: PARTNA_INGEST_BILLED_EFFECTS_ENABLED present = 0
production:  PARTNA_INGEST_BILLED_EFFECTS_ENABLED present = 0
```

Unset on both, so it resolves **false everywhere**. The drivers cannot dispatch
even once the code is deployed. Slice 0's own checkpoint requirement — an
explicit statement that the flag is *true on development, false on production*
— is therefore **unmet**, and the "false on production" half is currently true
only by default rather than by intent.

Setting it needs care: the Cloud CLI's file mode **replaces the whole set**,
so a naive write drops every other var. The safe form is
`cloud environment:variables development --action=append --key=… --value=…`.

**RESOLVED.** Appended on development 2026-08-12 and verified non-destructive:
94 → 95 variables, **no key lost, no value changed**, exactly one added.
Production deliberately left UNSET, so it resolves `false` by default — the
intended state, now by intent rather than by accident. Confirmed live on the
deployed app: `config('partna.ingest.billed_effects_enabled')` → `true` on
development.

### 13.4 BLOCKER 2 — CLEARED. The definition of done is now MET

§5.3: "Slice 0 ends when a billed effect can execute and is recorded."

**One real billed Places Details call was executed against dev on 2026-08-12**
— a deliberate spend, pre-flighted per the plan (key present; `PlacesBudget`
remaining = 197; source `84bef00d-634f-485d-9b4f-b7569a6c6241`, whose
identifier is Google's own documentation place id, on a dev test account).
Driven by dispatching `RunSourceJob` directly, since `claimDue()` filters
`auto_sync = true` and billed sources are not.

`ingest.effects` — **the first row it has ever held**:

```
digest                           | kind | cost_tag       | cost_units | status | claimed_at          | settled_at          | duration
---------------------------------+------+----------------+------------+--------+---------------------+---------------------+----------
e3fbf61bf2d2955c4a8136a62fb3b717 | api  | places.details |         10 | ok     | 2026-08-12 03:54:46 | 2026-08-12 03:54:47 | 00:00:01
```

`cloud env:logs partna development`: clean — no
`ingest.effect.replay_unavailable`, no `google_business.details_fetch_failed`,
no `PlaceDetailsUnavailableException`, no `AbandonedEffectException`.

**Expected side effect, per the plan:** `RunSourceJob::handle()`'s `finally`
called `SourceScheduler::release()` on a source that was never claimed, so
`next_attempt_at`, `consecutive_failures`, `health` and the `change_rate` EWMA
moved on that row. Harmless while `auto_sync = false` — nothing reads them —
recorded so a later reader does not mistake it for the scheduler having picked
the source up.

### 13.5 Still outstanding (documentation only — the slice itself is done)

Performed: Steps 4 (merge + deploy), 6 (the billed run), 7 (ledger assertion),
8 (log scan). Not performed:

- a Nightwatch scan for `PlaceDetailsUnavailableException` /
  `AbandonedEffectException` (the log scan was clean; Nightwatch not checked)
- the six D1–D6 spec corrections against §5 — §5.3's closing claim that a
  `NoAnswer` is retryable is the one the plan flags as actively wrong, and §5
  still describes a design that differs from what was built
- ~~an explicit re-confirmation that `auto_sync` stays `false` for both billed
  connectors~~ **DONE 2026-08-12**: read from dev after deploy —
  `{"google_business":[false],"instagram":[false]}`, i.e. false on every source
  of both kinds, so neither billed connector can be picked up by
  `SourceScheduler::claimDue()`.
- ~~the six D1–D6 spec corrections against §5~~ **ALL SIX DONE 2026-08-12.**
  Each is marked inline in §5 as `CORRECTED 2026-08-12 (deviation Dn)`, keeping
  the original wording above it so the correction reads as a record rather than
  a quiet rewrite:
  | | Was | Is |
  |---|---|---|
  | D1 | §5.2 wired the driver to `fetchPlaceDetails()` | `fetchPlaceDetailsRaw()` — the mapped shape would have landed nothing and made the reviewer-PII redaction vacuous |
  | D2 | §5.1 said `InstagramScraper` carries a per-user cooldown and daily cap | It carries neither; the driver claims `ApifyBudget` itself |
  | D3 | §5.3 specified `BudgetRefused` settling `status='refused'` | `EffectNotAttempted` (an `EffectRefused` subclass) and a guarded DELETE; no `verdictFor()` change |
  | D4 | §5.3 said the kill switch must also gate `SourceProvisioner` | False twice over; no provisioner change shipped |
  | D5 | §5.3 prescribed an attempt-counter or pre-flight `claim()` | Neither; the raw fetch reports a first-attempt denial, so a mid-loop throw folds to `NoAnswer` |
  | D6 | §5.3 said a `NoAnswer` is retryable | It is not — inert for the freshness window |
  §5 no longer describes a design that was not built.

### 13.6 #MONEY-1 and #MONEY-2 — found by audit AFTER slice 0, fixed 2026-08-12

Making `runBilledEffect()` reachable made a latent defect reachable with it.
Both were fixed on `audit-fix/effect-ledger-money-2026-08-12` (merged
`99579b046`); plan:
`docs/superpowers/plans/2026-08-12-effect-ledger-money-findings.md`.

**#MONEY-1 (P1).** `EffectLedger::once()` wrapped the vendor call and the
settle write in one `try`, so its catch-all could not tell "the paid call
failed" from "the paid call succeeded and the bookkeeping failed". A DB hiccup
on the settle write stamped the row `failed`, discarded the paid result, and —
since `verdictFor()` serves a settled row for the whole freshness window —
locked that digest out for seven days behind an error naming the DB exception.
The `try` now covers `$effect()` alone; `settleOk()` cannot throw, and on
failure leaves the row CLAIMED so `markAbandoned()` owns it while the caller
still receives what it paid for.

**#MONEY-2 (P3).** The claim row was written before the driver's budget check,
so a capped day cost an INSERT plus the DELETE that undoes it, per attempt.
`PrecheckableBilledEffect` is an opt-in interface consulted after the
existing-row read and before the claim — a settled `ok` row still replays when
the budget is gone, which is pinned by its own test.

**A regression the review caught in the #MONEY-2 fix**, worth recording because
the shape recurs: `PlacesBudget::remaining()` is the one budget method with no
`try/catch`, and `once()` invokes the precheck outside every `try` it has — so
a Valkey blip escaped to `RunExecutor`'s catch-all and turned a
`budget_skipped` (rank 3) into a paging `error` (rank 7). The precheck now
reports and allows on any `Throwable`; allowing is safe only because `run()`'s
`claim()` remains the authority and still fails closed.

**Live verification, 2026-08-12 after deploy.** One further real billed Places
Details call (a DIFFERENT place id — the freshness bucket would otherwise have
replayed the 03:54 row and proved nothing):

```
digest                           | cost_tag       | cost_units | status | claimed_at          | settled_at
---------------------------------+----------------+------------+--------+---------------------+--------------------
6a684e00ee60b969b24674dbd80484c3 | places.details |         10 | ok     | 2026-08-12 07:01:34 | 2026-08-12 07:01:35
e3fbf61bf2d2955c4a8136a62fb3b717 | places.details |         10 | ok     | 2026-08-12 03:54:46 | 2026-08-12 03:54:47
```

`meta` on the new row is 28,305 bytes and carries the `result` key — the paid
payload is persisted for replay, which is the property `settleOk()` exists to
guarantee. Log scan clean: no `settle_unrecorded`, no `effect.abandoned`, no
`details_fetch_failed`, no `replay_unavailable`.

This proves the refactor did not break the SUCCESS path. It cannot prove the
failure path — that is what the SQLite-trigger tests in
`tests/Feature/Ingest/BilledEffectSettleFailureTest.php` are for.

Its plan file `docs/superpowers/plans/2026-08-11-billed-effect-driver-seam-PLAN.md`
is also still untracked, against this repo's convention of committing plans.

---

## 14. Slice 2 checkpoint — the events pool

**Status: DONE as of 2026-08-12 for Tasks 1–9, with one carried regression
named below. All three migration commands have now run on dev.**

Same authorship note as §13: written by the slice 0b session as an audit.

### 14.1 Build state — verified complete

All 12 plan deliverables exist, including the three console commands
(`content:repair-event-items`, `content:backfill-item-slugs`,
`content:migrate-hidden-events`) and the wire manifest
`docs/wire-changes/2026-08-11-slice2-events-pool.md`.

`EventsPoolTest`, `EventItemRepairTest`, `PoolRegistryTest`, `RuleOperatorTest`:
**33 passed, 135 assertions.**

### 14.2 The data repair DID land on dev — verified

This is the one live assertion either slice can currently show, and it passes.
The repair was `ingest:project`, an already-deployed command, which is why it
could run before the slice merged.

```sql
SELECT count(*) AS live_events,
       count(*) FILTER (WHERE i.headline_cache IS NOT NULL) AS with_headline,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM content.f_occurrence o WHERE o.item_id = i.id)) AS with_occurrence
FROM content.items i
WHERE i.kind = 'event' AND i.removed_at IS NULL;
```

```
live_events | with_headline | with_occurrence
------------+---------------+-----------------
         14 |            14 |              14
```

**Gate met** (`with_headline` = `with_occurrence` = `live_events`). The six
empty shells from the 2026-07-28 `f_occurrence_zone_confidence_check` wreckage
are repaired, and no merge collapsed the count.

### 14.3 The migration commands — ALL RUN 2026-08-12, output pasted

Run in the manifest's mandated order, `--dry-run` first each time:

```
content:backfill-item-slugs --dry-run  → would mint 14, skipped 0
content:backfill-item-slugs            → minted 14, skipped 0
content:migrate-hidden-events --dry-run → Hidden events: none to migrate.
content:migrate-hidden-events           → Hidden events: none to migrate.
content:repair-event-items --dry-run    → incomplete: 0, orphaned: 3
content:repair-event-items --retire     → 3 retired (see the defect below)
```

`migrate-hidden-events` is a genuine no-op on dev — zero `hiddenEventIds`
exist — so that lane remains **unverifiable against live data**, exactly as
the plan said to state rather than claim.

**A defect the retire run exposed, now fixed (`196d29d18`).**
`content:repair-event-items --retire` filtered `site.sites` on `deleted_at`,
a column that table has never had. Postgres raised `42703` **after** the
retirement had committed, so none of the three invalidations ran — no
`BuildState` bump, no `sites.updated_at` touch, no edge purge. Dev retired 3
items and kept serving them from cache; repaired by hand for site
`019f936e-119e-7096-a9fd-d154fbec66c6`.

SQLite had hidden it entirely: an unknown **double-quoted** identifier is
reinterpreted as a string literal, so the filter compiled to
`'deleted_at' is null` — always false, zero rows, no error, loop skipped. The
existing test asserted `removed_at` only and passed. The new test asserts the
invalidation itself.

Final dev state:

```
live events                       → 11
retired events                    →  3
content.item_slugs                → 14   (all current; 0 duplicates)
events with no headline (gate: 0) →  0
orphan items (gate: 0)            →  0
```

`cloud env:logs partna development --minutes 12`: 84 entries, 0 error/critical.

### 14.4 The state before those commands ran — verified by measurement

```sql
content.item_slugs                          →   0 rows
site.item_slugs (legacy)                    → 330 rows, all current
event source_items with removed_at          →   3
live event items whose every source item is retired → 3
```

Three consequences, each tied to a command that ships in this slice and so
cannot run until it deploys:

1. **`content:backfill-item-slugs` has not run.** `content.item_slugs` is
   empty, so every pool item currently serves `slug: null`. The manifest
   already names this a required deploy step — "nothing serves a slug until
   this runs" — so it is expected, not a defect. But the manifest's measured
   "12 current event slugs, 9 mapped, 3 unmapped" describes a post-backfill
   state that **does not yet exist on dev**.
2. **`content:repair-event-items` retirement has not run.** The three items
   whose every source item is retired are still live — exactly the §9.8
   asymmetry the slice owns. Retirement is one-way, so this is correctly
   gated behind a deliberate run.
3. **`content:migrate-hidden-events` has not run.** Per the plan this is a
   no-op on dev (zero `hiddenEventIds`) and therefore unverifiable there; the
   plan already says to state that rather than claim a live assertion.

### 14.5 Outstanding

- ~~the section/page-count assertions (Task 8 Step 5)~~ **DONE 2026-08-12**,
  measured on dev after the deploy: `site.sections` with `key='pool:events'` =
  **2**, `site.pages` with `key='events'` = **2**. Both non-zero, which is the
  assertion — sections and pages provision on demand at first read, so these
  are counts after the pool was read rather than a backfill.
- ~~the wire manifest still quotes the pre-backfill slug mapping~~
  **RE-MEASURED and corrected 2026-08-12** (`eedf32063`). Final dev state:

  | Measure | Value |
  |---|---|
  | `content.item_slugs` | **14**, all `is_current` |
  | …on a LIVE item | 11 |
  | …on a RETIRED item | 3 (retired by `--retire`; rows survive as 301 history) |
  | Live `content.items` kind `event` | 11 |
  | Legacy `site.item_slugs` `item_type='event'` | 12, all current, untouched |
  | Legacy slugs carried into `content.item_slugs` | **9**, all on live items |

  The pre-migration 9-mapped/3-unmapped prediction held exactly. The manifest
  now also states the distinction that trips people up: the 3 unmapped legacy
  slugs are NOT the 3 minted slugs sitting on retired items.

**The known regression is CLOSED (`1197052f8`).** `removeEvent()` previously
wrote only `hiddenEventIds`, which the pool does not read — so every hide made
after the one-shot migration would have silently failed. It now mirrors the
hide into a section exclude via `EventExcludeSync`, sharing the
`EventsPayload::id()` hash rule with the migration command so the two cannot
drift. Three regression tests cover it.

**Accepted, per owner decision 2026-08-12:** three legacy event permalinks
have no content item to map to (two never imported, one a standalone row that
lands none) and their URLs stop resolving. Dev only, no customers.

**Carried to a later slice: standalone events.** Standalone
`resource_kind='event'` rows still publish through the legacy integrations
wire, so a frontend Events page reads BOTH sources. The full scope, why it was
deferred, and the four steps it needs are recorded once — in **§7 slice 7,
"Carried from slice 2"** — because slice 7 is the slice it blocks. Do not
restate it here; a deferral described in two places drifts.

Its plan file `docs/superpowers/plans/2026-08-11-content-pool-slice2-events.md`
is likewise still untracked.

### 14.6 Section numbering

§12 (slice 0b) was written before §13 and §14 because it was the first
checkpoint any slice recorded. The numbers are labels, not an ordering — the
programme order remains 0 → 0b → 2.

---

## 15. Slice 1b checkpoint — Google on display, Instagram in the pool

Merged to `development` and deployed 2026-08-13. Spec:
`2026-08-12-media-pool-slice-1b-design.md`. Wire manifest:
`docs/wire-changes/2026-08-12-media-pool-slice-1b.md`.

### 15.1 Entry gate — re-derived, not cited (invariant #5)

1a's state was re-established from dev rather than read from its checkpoint:

```
ProjectionWriter.php:1166   $fingerprint = $ref ?? $url;      ✅ ref preferred
content.media_assets.site_media_id exists                     ✅ 1
content.items kind='media' (live)                             ✅ 45
content.media_assets WHERE site_media_id IS NOT NULL          ✅ 25
site.sections pool:media carrying latest_per_auto_source      ✅ 0
content.media_assets total                                    ✅ 526 = 501 + 25
```

**The prod-side check could not be run, and is recorded as a deferral rather
than a pass.** Prod Supabase MCP SQL fails `28P01` for both `postgres` and
`supabase_read_only_user`, and `cloud tinker production` reports the environment
stopped. Closed on the architecture instead: prod last deployed `265f9aa`
(2026-07-26, pre-1a), has never had the content-pool migrations applied, and
carries no users — so there is no `content.media_assets` row to be mis-keyed.
**Fix the prod MCP credential before the next prod verification need.**

### 15.2 Live dev assertions — run 2026-08-13, output pasted

**Google photos resolve, with attribution (D2, D6).** A fresh Details fetch for
a place that had never run (`ChIJ-x9YKE1D1moR8t2Hdfarbbc`):

```sql
SELECT count(*) AS new_google_assets,
       count(*) FILTER (WHERE source_url IS NOT NULL) AS with_url,
       count(*) FILTER (WHERE attribution IS NOT NULL) AS with_attr,
       count(*) FILTER (WHERE source_url LIKE '%lh3.googleusercontent.com%') AS unkeyed_lh3,
       count(*) FILTER (WHERE source_url ILIKE '%key=%') AS leaked_api_key
FROM content.media_assets
WHERE created_at > now() - interval '5 minutes'
  AND site_media_id IS NULL AND storage_path IS NULL;

 new_google_assets | with_url | with_attr | unkeyed_lh3 | leaked_api_key
       10          |    10    |     10    |     10      |       0
```

Sample attribution — all three D6 keys present, which **closes the spec's one
open unknown**: `photos[].googleMapsUri` and `flagContentUri` DO come back under
the existing bare-`photos` field mask.

```json
{"authors":[{"uri":"https://maps.google.com/maps/contrib/101239599710321892718","name":"Carl Mutzelburg"}],
 "flag_uri":"https://www.google.com/local/content/rap/report?postId=…",
 "maps_uri":"https://www.google.com/maps/place//data=…"}
```

**Instagram landed on owned bytes (D8, D9).**

```
mirrored | distinct_paths | ig assets left unmirrored | total
   33    |       33       |             0             |  585
```

**The parent's no-duplicate proof, for Instagram.** Two consecutive syncs of
`basette_barberia_`:

```
                     assets | live media items
before second sync     575  |       57
after  second sync     575  |       57
```

Zero duplicates across a re-sign — the property D1's owned/borrowed split rests
on, and the one Google cannot satisfy.

**Selection migration (D10).** `content:migrate-selection`, then re-run:

```
Uploads: migrated 3
Dropped (google-photo):   85
Dropped (ig-post/ig-reel): 6
Skipped (no backfilled item): 0
Dropped rows belonged to 11 site(s)  [ids printed by the command]
```

Second run: identical counts, no second pin. Post-state: **3 `pool:media` pins**,
**94 `site.content_selection` rows still present** — the migration is additive,
as designed.

> **The counts moved again.** The spec recorded 80/4/2/3 = 89; live it was
> 85/6/3 = 94, across 11 sites rather than 8. Five more `google-photo` rows and
> two more `ig-*` rows appeared between spec and execution. The *decision* is
> unchanged; the figures in D10 are stale and these supersede them.

**Prune (D4).** `content:prune-borrowed-assets --dry-run`:

```
Borrowed assets: would prune 0
Spared (owned bytes or upload-backed): 25
Spared (still referenced by a live item): 0
Spared (inside the 30-day url window):   501
```

Correctly inert: nothing is past the window yet. It is scheduled daily 03:50.

### 15.3 The §8.3 regression, extended to two connector sources

`tests/Feature/Ingest/MediaMergeRegressionTest.php` — uploads + Instagram +
Google on the `media` kind together, which had never been exercised:

- uploads and Instagram items survive a Google projection run
- Google media churns across two runs with rotated refs, and owned items are
  untouched

Both pass. **`preferOwnerAnchored()` holds for a two-connector media merge.**

### 15.4 Post-deploy

`cloud env:logs partna development --minutes 15`: clean. Every
`MirrorMediaAssetJob` completed in 2–5s; no failures, no exceptions.
**Nightwatch scanned** (the check slice 0 recorded as skipped): no new issues
since the deploy — the most recent open issues all predate it.

### 15.5 What this slice did NOT do, on the record

- **The six Google-only sites still have an empty background picker.** Google
  photos flow to the page automatically; they do not flow into the picker,
  because borrowed media is not pinnable (D5). Filling it is uploads,
  Instagram, or a product decision. Not papered over.
- **Google `auto_sync` is left OFF**, as it was before the slice. D3's 7-day
  cadence is set as `ingest.sources.min_interval_secs = 604800` on all 12
  google_business rows — `StreamSpec` has no per-stream interval, so this is a
  source-row setting, not a manifest guarantee.
- **Only the public Instagram handle is enabled.** `tobiasbalcombe` is a
  private account — the actor returned `"private": true` and zero posts, so
  scheduling it weekly would bill an Apify slot that can never yield media.
  `basette_barberia_` is enabled at 7 days.
- **`gallery` / `designMedia` stay** — blocked on the frontends, per 1a.

### 15.6 A correction to a shipped privacy control

Spec D6 asserted the redaction manifest was not widened, having checked
`GoogleBusinessConnector::manifest()`. It missed a second, structural registry:
`ThirdPartyPii::NESTED_KEYS = ['photos' => ['authors']]`, applied at
`PublicIntegrationConnectionResource:436` and `DsarPayloadFilter:192`, whose
docblock names this connector explicitly and justifies stripping the credits
with *"no attribution obligation attaches — photo refs are not yet resolved to
images"*.

**Task 5 is exactly what makes that false.** Resolved by surface, following the
two obligations rather than forcing the lanes to agree — public render carries
the credit (Places terms require it on display), DSAR export and the legacy
integration payload keep stripping it (Article 15 is the subject's own data).
No behaviour changed in the legacy lane; the docblock premise was corrected in
place so the asymmetry is not later "tidied up".

Verified that attribution reaches DSAR by no route today:
`streamContentSourceItems()` selects an explicit column list with no doc column,
and `content.media_assets` is absent from `COVERED_PII_TABLES`. **Whether the
owner's own media catalogue should be exported at all is a pre-existing Article
15 gap — flagged, not fixed.**

---

## 16. Slice 5a checkpoint — the shop data move

**Status: DONE.** Merged to `development` and deployed 2026-08-13 (`9e5bf3a6a`).
Spec: `2026-08-12-slice-5a-shop-data-design.md`. Wire manifest:
`docs/wire-changes/2026-08-12-slice-5a-shop-data.md`, flipped to deployed
alongside this checkpoint.

### 16.1 Pre-implementation baseline

Established while writing the spec and re-confirmed immediately before the
backfill ran (`glncumufgaqcmqhzwrxm`):

```
content.storefronts                      0
content.items        kind='product'      0
content.item_variants                    0
content.collections / collection_items   0 / 0
site.shop_brands                         9   (still authoritative)
site.shop_products                      51   (still authoritative)
```

The four migrations (`20260813100000`–`20260813100003`: `content.storefronts`,
`storefronts.external_ref`, `f_catalog.handle/vendor/variant_ref`,
`item_variants.image_url`) were applied to dev ahead of the code deploy, per
§9.1's rule that a raw-write seam cannot be deferred — `ProjectionWriter`
writes all four unconditionally, for every connector, not just shop.

### 16.2 What shipped

| Change | Where |
|---|---|
| `content.storefronts` — 1:1 sidecar on `content.collections`, `provider`/`url`/`currency`/`discount_code`/`referral_query`/`is_individual`/`fetch_mode`/`connect_status`/`products_curated_at`/four logo columns | new migration + `Storefront` model |
| `ProjectionWriter` writes `content.item_variants` | shared hot-path change, §3.2a — every remaining slice inherits it |
| `content.f_catalog.handle` / `.vendor` / `.variant_ref` | new columns; `variant_ref` carries the Shopify checkout deep link id that the skipped `"Default Title"` placeholder variant would otherwise have dropped entirely |
| `content.offers.availability` writer | previously unpopulated by any projector (§1.5) — this slice is its first writer |
| `ShopBackfiller` + `content:backfill-shop` | `app/Services/Migration/`, `--dry-run`, idempotent by coord |
| `ShopContentWriter` — reconcile-by-coord | replaces `syncLatest()`'s delete-then-insert; retires absent products, never hard-deletes, never touches `source_items.removed_at` |
| `ShopContentReader` | reconstructs the legacy blob shape from `content.*` for the 14 dashboard endpoints |
| `hybridBrandMap()` deleted | no legacy fallback survives — the backfill is a blocking deploy prerequisite from this point on |

### 16.3 The incident — the first live backfill run failed, with second-order damage

`content:backfill-shop --dry-run` previewed correctly (§16.4), but the first
real run against dev **failed on all 9 stores, writing no complete store**:

```
SQLSTATE[42702]: Ambiguous column: column reference "products_curated_at" is ambiguous
```

**Cause.** Inside `upsertStore()`'s `ON CONFLICT DO UPDATE`, the bare column
reference in `coalesce(products_curated_at, excluded.products_curated_at)` is
ambiguous between the target row and `excluded` — Postgres rejects it outright.
SQLite accepts the identical SQL, so the entire default test suite, including
this slice's own coverage, passed green against code that could not run on
production's database engine. **This is the second time in this slice that
SQLite tolerated what Postgres rejects** — the first was Task 7's `createdAt`
timestamp format (§16.6; `content.f_published.published_from` is `timestamptz`
on Postgres, `TEXT` in the stand-in). Fixed in `fb8491bfc`, with a Postgres-lane
regression test proven to fail against the pre-fix code.

**Second-order damage.** `upsertStore()` wrote `content.collections` then
`content.storefronts` **without a transaction**. The failed run's `collections`
insert committed for all 9 stores before each store's `storefronts` insert hit
the ambiguous-column error — so the command reported "9 FAILED, exit 1" while
silently leaving 9 orphaned `collections` rows behind it. Worse: the identity
check `upsertStore()` uses to find an existing store joins `collections` to
`storefronts`, and an orphan defeats that join. The retry after the SQL fix
therefore did not recognise the 9 orphans as existing stores — it minted 9 NEW
collections, correctly paired with storefronts this time, leaving **18
collections for 9 stores**.

**Resolution.** The 9 orphaned collections were deleted by hand. `upsertStore()`
and `retireStore()` are now wrapped in a single DB transaction (`6f3c52aa5`),
so a mid-write failure can no longer leave a collection without its storefront
sidecar (or vice versa), with a Postgres-lane rollback test pinning it. Both
fixes and a pint pass (`9e5bf3a6a`) merged before the backfill was re-run.

### 16.4 The commands, run against dev, output pasted

```
php artisan content:backfill-shop --dry-run
  → Shop: would backfill 9 stores, 51 products.

php artisan content:backfill-shop            (first real run, PRE-FIX)
  → 9 FAILED, exit 1  — SQLSTATE[42702], see §16.3

[fb8491bfc merged and deployed; 9 orphaned collections deleted by hand;
 6f3c52aa5 merged and deployed]

php artisan content:backfill-shop            (post-fix)
  → Shop: backfilled 9 stores, 51 products.        exit 0

php artisan content:backfill-shop            (re-run — idempotency proof)
  → Shop: backfilled 9 stores, 51 products.        exit 0
```

Both post-fix runs produced identical counts, satisfying the deploy runbook's
"re-run once" idempotency check without a second incident.

### 16.5 Live dev assertions — post-backfill state, identical after both runs

```
content.items kind='product' (removed_at IS NULL)  51
content.item_variants                             268
content.offers (total, incl. 14 pre-existing)     324
content.collections                                 9   (kind='storefront')
content.storefronts                                 9
content.collection_items                           51
content.item_media                                906
content.f_catalog                                  84   (33 pre-existing Bandcamp + 51 products)
```

Assertions:

```
legacy rows with no live coord (coverage gate)      0   ← the gate, green
products with no offer (investigate signal)         0
storefronts carrying referral_query                 0   (all 9 legacy brands had it empty)
storefronts carrying discount_code                  4   (matches the 4 legacy brands)
storefronts with products_curated_at                1   (matches the 1 legacy brand)
f_catalog rows carrying variant_ref                51
offers availability: product-level 35 in_stock / 16 out_of_stock  = the blob's exact 35/16 split
offers availability: variant-level 224 in_stock / 35 out_of_stock
offers availability: 14 NULL — the pre-existing Bandcamp rows, untouched
content.item_variants carrying image_url            0
site.shop_products  max(updated_at)  2026-08-12 17:24:33+00   (deploy was 22:05 — inert)
site.shop_brands    max(updated_at)  2026-08-12 10:54:51+00   (inert)
```

Every total reconciles: `324 = 14 pre-existing + 51 product-level offers (35+16)
+ 259 variant-level offers (224+35)`. The gap between `268` `item_variants` and
`259` variant-level offers is 9 variants whose price did not parse — per §3.2,
`minorUnits()` returns null rather than inventing `amount_minor = 0`, so those
9 mint a variant row with no offer, by design, not by omission.

**`image_url = 0` is stated honestly, not hidden.** Zero variants in real dev
data carry an image (`variant_blobs_with_image = 0` in the source blobs), so
the column and its round-trip are correct but **unexercised by real data**.
The 5b kickoff prompt is updated to say so rather than assume the column works
because it exists.

### 16.6 Gates at merge

SQLite `./vendor/bin/pest`: **7830 passed**. Postgres lane (`composer test:pg`):
**198 passed / 0 failed**. Schema lane (`composer test:schema`): **195 passed**.
PHPStan: **no errors** (needs a raised memory limit to run). Pint: clean.

The Postgres lane briefly went red mid-branch — the hand-written stand-in DDL
in `tests/Postgres/` drifted from the branch's own writer changes across two
separate tasks (`content.item_variants` never `CREATE`d; `offers.availability`
and `f_catalog.handle/vendor/variant_ref` missing) — fixed in `765334cb3`.
Neither per-task review had caught it because neither ran the Postgres lane,
only the SQLite `Ingest` suite. Recorded because it is the same failure mode as
§16.3 in miniature: a green SQLite run proves nothing about a Postgres-only
gap, and the fix belongs in the lane that actually exercises it.

### 16.7 Cache invalidation (§9.2, all three lanes)

| Lane | This slice |
|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` on every write path — backfiller, `syncLatest()`, all four mutating endpoints |
| 60s public-profile payload cache | `site.sites.updated_at` touched on every write path, per `PoolController::poolChanged()`'s shape |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` on every write path |

No CI check enforces any of the three — §9.1/§9.2 already established none
exists — so this slice asserts all three directly in Pest rather than trusting
a docblock. Nothing in 5a changes the *public* rendered surface (§2 of the
spec: "nothing public changes"), so a missed invalidation here would be latent
until 5b's pool read goes live, not visible today.

### 16.8 Conventions this slice established — for slices 3, 4 and 5b

Carried forward from the slice spec's §7 so the parent programme record does
not depend on a sub-spec surviving:

| Convention | Value | Who needs it |
|---|---|---|
| Coord for a legacy table whose rows are *rewritten*, not updated | `manual:{sha1(canonical_url)}`, not `manual:{legacy_uuid}` — §8.1's uuid convention mints a fresh coord every sync when the legacy table has no stable id | slices 3, 4, 7 |
| `offers.availability` vocabulary | `in_stock` / `out_of_stock` | slices 3, 4 |
| Collection-scoped behaviour | a 1:1 sidecar table (`content.storefronts`), not columns on `content.collections` | slices 3, 4 |
| `SECTION_SHAPE` for priced, undated items | `rule: [kind_is]`, `order_by: 'recency'` | slice 4 explicitly, and 5b |
| Owner-chosen ordering | pins in `site.section_items`, which carry position; the auto half offers only `alphabetical` / `occurrence` / `recency` and cannot express "the order the owner chose" | slice 4, 5b |
| `LATEST_TAG_POOLS` membership for commerce | **excluded** — hand-ordering fights a Latest badge, pool recency is a sync artefact not product newness, and unavailable stock can carry the badge | slice 4, 5b |
| `ProjectionWriter` now writes `content.item_variants` (§3.2a) | a projection may carry a `variants` key; absent-key is inert | **every remaining slice** — shared hot-path code |

### 16.9 What remains outstanding

- **The re-added-product decision (§8 "Out of scope — carried to 5b") is still
  open.** `content.items.removed_at` is never cleared by reappearance, and no
  shop read in 5a filters on it — so the gap is latent, not live, until 5b's
  pool read starts filtering. Carried into the 5b kickoff prompt as Unit 0,
  unchanged by this checkpoint.
- **`upsertStore()`'s SELECT-then-INSERT remains TOCTOU-racy** against a
  concurrent scheduled `syncLatest()` run — pre-existing shape, not introduced
  by this slice, parked for slice 7 (denormalise `user_id` onto `storefronts`
  behind a real unique index).
- **`style_analysis`** (non-null on 4 legacy brands) is dropped with no
  migration path — no reader, no writer survives the code that produced it.
  Named as a real loss in the wire manifest, not carried into `storefronts`.
- `site.shop_products` is **inert** — nothing writes it; the remaining
  `ShopProduct::delete()` calls only clear pre-deploy rows.
  **`site.shop_brands` is NOT inert** (corrected 2026-08-13 by slice 5b's entry
  gate). `ShopController` still writes it — `updateOrCreate` at `:317`,
  `firstOrCreate` at `:929`, `delete` at `:869` — and
  `ShopContentWriter::upsertStore()` takes the `ShopBrand` model as its identity
  anchor. Its `max(updated_at)` is frozen only because no brand has been added
  on dev since 2026-08-12 10:54. Slice 7 must re-home the writer, not merely
  drop the table.
- **Correction to an earlier draft of this section.** It read "the public Shop
  page is unchanged — still legacy-sourced". That is **false**. Task 8's review
  found the public regression had three heads, and all three were repointed at
  `content.*` in this slice: `PublicIntegrationConnectionResource` (the
  CDN-cached payload), `PlatformRegistryServiceProvider` (the page-PRESENCE
  gate — why a store connected post-deploy would otherwise get no Shop page at
  all, not merely an empty one), and `CloudflarePurgeService` (PDP purge
  handles). They had to move: once nothing wrote `site.shop_products`, leaving
  them reading it would have frozen every public shop card at its pre-deploy
  contents. What 5b still owns is the POOL render and the `/integrations`
  retirement, not the data source.

### 16.10 Post-deploy verification — RUN 2026-08-13, output pasted

```
cloud env:logs partna development --minutes 20
  → 98 entries, levels {info: 98}. Zero error/warning/critical.
```

**Nightwatch scanned** (§13's checkpoint recorded this as skipped; §15 and this
one do not repeat that gap). Exactly one new issue since the deploy:

```
#427  QueryException SQLSTATE[42702]: column reference "products_curated_at" is ambiguous
      first seen 2026-08-12T22:07:27Z · last seen 2026-08-12T22:07:27Z
```

First and last seen are the same instant — the single failed backfill run of
§16.7. It has **not recurred** across the two successful backfill runs that
followed the fix (22:19 and 22:21). Every other open issue predates the deploy;
the newest is 7 hours older than it.

---

## 17. Slice 3a checkpoint — owner-authored services

**Status: DONE 2026-08-13.** Merged to `development` at `2d54c0438` (23 commits,
fast-forward from `5bd607377`), deployed, backfill run on dev, live assertions
below. §3 invariant 1 discharged.

Slice 3 split into 3a/3b on a seam the database dictated: every one of the 61
`service_category_assignments` and 16 of the 18 `service_categories` belong to
Fresha, and the two owner-authored categories were already soft-deleted. So the
owner half carries no collections work at all. 3b's kickoff:
`docs/superpowers/plans/2026-08-13-slice-3b-services-fresha-KICKOFF-PROMPT.md`.

### 17.1 Live dev assertion — RUN 2026-08-13, output pasted

```
php artisan content:backfill-owner-services --dry-run
  → [dry-run] would backfill 18, retired 3, skipped (no user) 0, failed 0
php artisan content:backfill-owner-services
  → backfilled 18, retired 3, skipped (no user) 0, failed 0
```

```
live_service_items            18     (spec §5.1 predicted 18)
retired_service_items          3
manual coords (manual:…)      21
offers                        21     all qualifier='exact'
f_duration rows               17     = 21 minus the 4 null durations
pool:services pins            18     live only
source_items.removed_at   →    0     GATE — the load-bearing invariant
orphan items              →    0     GATE — unchanged from the 0b baseline
```

**Idempotency proven on live data**, not only in tests: a second identical run
reported the same counts and left `content.items` 21, `source_items` 21, `offers`
21 and `section_items` 18 unchanged, with both gates still 0.

`cloud env:logs partna development --minutes 12` across the backfill window:
**0 error/critical**, no `42703`, no `23505`, no `SQLSTATE`.

### 17.2 Cache invalidation (§9.2, all three lanes)

| Lane | This slice |
|---|---|
| `site.site_build_state` | **Bumped** — `ManualServiceWriter::invalidate()` on every write path, plus `writeManualItem()`'s own per-item bump |
| 60s public-profile payload cache | **Busted** — `invalidate()` touches `site.sites.updated_at`, which the cache key composes from |
| Cloudflare edge | **Purged** — `CloudflareCachePurgeJob` dispatched per touched site |

The three-lane test initially passed with the `BuildState` lane **deleted**,
because `writeManualItem()` bumps internally; it now asserts an exact revision
delta. That trap is worth carrying forward: `content_revision > 0` proves nothing
about the caller.

### 17.3 What the final whole-branch review caught that per-task reviews could not

Three blockers survived six per-task reviews and four fix rounds, all of them the
same shape — a state the user sets that the reads never honour, which is the
defect class this slice exists to remove:

- **`GET /api/me` still served the legacy table.** `UserCacheService::getActiveServices()`
  carries **no `source` filter at all**, so it never appeared in this spec's §1.1
  inventory — that inventory was built by grepping `whereNull('source')`. A
  service created through the API was absent from the dashboard bootstrap, an
  edited one served pre-cutover values, a deleted one still showed live.
- **Hiding a service did not close its page.** Three hand-written copies of the
  manual-source predicate in the visibility gates dropped `->where('is_active', true)`
  with no replacement. The write side of that invariant was caught and fixed in an
  earlier task; the read side was not.
- **Clearing `description` or `duration_minutes` was a silent no-op** — 200 with
  the old value, rendered forever, because `upsertSingletonFacet()` only writes
  columns present in the projection.

**Method lesson: an inventory built from one predicate does not find every
reader.** Two owner-services readers carry no `source` filter and were invisible
to the grep — `getActiveServices()` and `ContentFreshness:89-98`. The latter is
still un-migrated and is recorded in 3b's prompt.

### 17.4 Assurance improvements that outlive this slice

- **`setupServicesTable()` now carries `services_user_sort_order_uq`.** It did
  not, so the production constraint `UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL`
  did not exist under SQLite — and a Critical that returned HTTP 500 on two live
  endpoints could not fail a test. Under a faithful restore of that bug, all 24
  tests in the relevant file stayed green. This raises the floor for every
  `sort_order` writer in the repo.
- **`createServiceFor()` / `ownerService()` no longer mint an impossible state.**
  Both hardcoded `sort_order = 0`, so any test seeding two services for one user
  created a row pair Postgres would reject.
- **`composer test` now needs `COMPOSER_PROCESS_TIMEOUT=0`** — the suite exceeds
  composer's 300s default, and the timeout presents as a hang.

### 17.5 Carried to 3b, recorded in its prompt

`StaffServiceManagementController` (nine methods, no `source` filtering —
post-cutover staff cannot see owner-authored services **at all**, not merely
stale); `ContentFreshness:89-98`; the 3 retired owner services carrying no pin;
`ProjectionWriter`'s bare unscoped `DB::table()` calls; and
`CloudflarePurgeService`'s three raw un-deduped `report($e)` calls, which with
the purge job's three self-dispatched follow-ups can reach **12 reports per site
save** — handed to slice 5b, and recorded in the slice-7 and slice-4 prompts
where it fires.

**Migration prefix block `20260813090000`–`20260813099999` was NOT consumed** —
3a needed no schema change. It is free for 3b.

---

## 18. Slice 5b checkpoint — the shop pool and the public render

**Status: MERGED to `development` 2026-08-13 (PR #280, rebase, 23 commits on
`4ab37744a` → `737133069`), deployed to dev (deploy `2222`, 08:33:42 UTC),
provisioning run, live assertions taken. NOT deployed to production — that
deploy stays gated on partna-monorepo landing its side of the wire
retirement.**
Spec: `2026-08-12-slice-5b-shop-render-design.md`. Wire manifest:
`docs/wire-changes/2026-08-12-slice-5b-shop-render.md`.

§18.1–§18.2, §18.6 and §18.8–§18.10 were written by Task 9 before any of this
was true, with the live-verification subsections deliberately left as
`PENDING — Task 10` rather than populated with unmeasured numbers. Task 10
then filled §18.3–§18.5 and §18.7 with pasted output, and confirmed lane 3 of
§18.6 from the live log scan. Per invariant #1, nothing here is claimed that
was not run.

### 18.1 Pre-implementation baseline

This is the state 5b's own spec re-derived from dev on 2026-08-13, before any
of 5b's 8 tasks had shipped a line of code — i.e. dev as 5a left it. Full
detail and the code citations behind each row are in the 5b design spec §1;
reproduced here so this checkpoint does not depend on that spec surviving.

```
content.items kind='product', removed_at IS NULL     51    (5a: 51)
content.collections (all / kind='storefront')       9 / 9  (5a: 9)
content.storefronts                                  9     (5a: 9)
content.collection_items                            51     (5a: 51)
content.item_variants                              268     (5a: 268)
content.offers                                     324     (5a: 324)
max(updated_at) site.shop_products    2026-08-12 17:24:33+00  (5a: identical)
max(updated_at) site.shop_brands      2026-08-12 10:54:51+00  (5a: identical)

site.pages       gallery 12, watch 10, listen 10, events 4, library 1
                 — NO `shop` page exists
site.sections    pool:media 12, pool:listen 10, pool:watch 10, pool:events 4
                 — NO `pool:shop` section exists

content.items kind='product' with removed_at IS NOT NULL      0
```

Two findings from that baseline pass are corrections to prior record, not new
5b state, and are applied above at §9.8 and §16.9: `content.items.removed_at`
clearing was narrowed from "never" to "owner-authored writes may, connectors
never may" (§9.8), and `site.shop_brands` was found still written by
`ShopController`, contradicting §16.9's prior claim of full inertness.

### 18.2 What shipped — 8 tasks, `feat/slice-5b-shop-render`

| Change | Where |
|---|---|
| `shop` pool registered — `POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, `SECTION_SHAPE` (`kind_is` / `recency`); deliberately excluded from `LATEST_TAG_POOLS` | `app/Site/Pools/PoolRegistry.php` |
| `content:provision-shop-pins` (`--dry-run`) — flattens catalogue position into dense pins | `app/Console/Commands/ProvisionShopPinsCommand.php` |
| `retireAbsent()`'s stale-coord row-wise match fixed to a `whereNotExists` | `ShopContentWriter::retireAbsent()` |
| Re-add clears `content.items.removed_at` for every item just linked (unconditional in the writer); the owner-vs-connector boundary is enforced by `ShopFetch`'s callers, not inside `syncStore()` itself | `ShopContentWriter::syncStore()`, fourth step |
| Outbound URL composition moved to the backend — mode from `site.sites.shop_link_mode`, Shopify cart deep-link / WooCommerce `?add-to-cart=`, discount + referral appended | `PoolResolver::itemPayloads()` |
| Pool item payload gains `description`, `vendor`, `variants`, `collectionIds` (every kind, nullable off-kind); `frames` extended to `kind='product'`; `popularityRank` populated for products from `content_popularity_scores` | `PoolResolver` |
| `collections` map added to the `shop` pool envelope, keyed by collection uuid, `name` nulled when it is the `external_ref` sentinel | `PoolResolver::collectionsFor()` |
| `ITEM_KEYS` (30) / `STORE_KEYS` (9) / `VARIANT_KEYS` (5) pinned by `PoolWireShapeTest`, explicit key-by-key construction, no row spread | `PoolResolver`, `tests/Feature/Content/PoolWireShapeTest.php` |
| Legacy `/integrations` shop keys retired — `SHOP_BRAND_ALLOWLIST` / `SHOP_PRODUCT_ALLOWLIST` deleted, `filterPayload()`'s shop branch returns `[]`, envelope preserved | `PublicIntegrationConnectionResource` |
| Shop-page presence made pool-derived via `PoolResolver::hasSelection()` | `PlatformRegistryServiceProvider` |

No DDL. No migration filename prefix consumed (§4.3 rule 5).

### 18.3 Live dev assertions — RUN 2026-08-13, output pasted

Merged to `development` at `737133069` (PR #280, rebase, 23 commits linear on
`4ab37744a`). Deploy `2222` succeeded 08:33:42 UTC. All SQL below run against
`glncumufgaqcmqhzwrxm` after the provisioning command.

```
shop_pages                 5
shop_sections              5
pinned_items              51        -- spec §5.1 expected 51
retired_products           0
shop_products_max_updated  2026-08-12 17:24:33+00   -- unchanged; still inert
```

Every `pool:shop` section carries the corrected rule — one distinct shape
across all five:

```
rule      {"all": [{"op": "kind_is", "values": ["product"]}]}
order_by  recency
sections  5
```

§8.4 coverage gate — coord coverage, not row-count equality:

```
uncovered_legacy_rows  0
```

### 18.4 The provisioning command, run against dev

```
$ php artisan content:provision-shop-pins --dry-run
pool:shop pins: would pin 51, left alone 0, across 0 site(s).

$ php artisan content:provision-shop-pins
pool:shop pins: pinned 51, left alone 0, across 5 site(s).
```

The dry run reports `across 0 site(s)` by construction: the site counter only
increments on a real write, and the dry run performs none — it does not even
provision the page/section, which was a fix round finding (`ensure()` INSERTs,
so it must not be reached under `--dry-run`).

51 pins across 5 sites, matching the 51 products and 5 product-holding users
the entry gate measured.

### 18.5 A real `PoolResolver::resolve()` call — SQL cannot prove this

The composition happens in PHP. Invoked on dev against `ollies` (33 products
across 5 stores — the multi-store case, so the lowest-`position` tie-break is
actually exercised):

```
items in selection   33
collections          5
first item url       https://natalieanne.com/cart/47811307995314:1?discount=ALEX10

first collections entry:
{"3b3c23ea-b222-4656-8658-ac504c48f797": {
  "externalRef": "11461296187",
  "provider": "shopify",
  "url": "https://natalieanne.com",
  "name": "Natalie Anne Haircare",
  "currency": "AUD",
  "favicon": "https://natalieanne.com/cdn/shop/files/NA-Favicon.png?...",
  "logo": "https://natalieanne.com/cdn/shop/files/Natalie_Anne_LOGO.png?...",
  "discountCode": "ALEX10",
  "position": 1
}}
```

That `url` is the whole point of the slice: a Shopify cart deep link
(`/cart/{variant_ref}:1`) with the store's discount code appended, composed
backend-side from `f_link.url` + `f_catalog.variant_ref` + the storefront row.
`productHref()` in partna-monorepo has nothing left to do. The store card
carries exactly the nine `STORE_KEYS`, keyed by collection uuid, with a real
`name` rather than the raw `external_ref`.

### 18.6 Cache invalidation (§9.2, all three lanes)

This table states what the code does — verified by reading the write paths,
not by a live request — matching the form §16.7 used before its own live
assertions were available.

| Lane | This slice |
|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` on `content:provision-shop-pins` (only on sites it actually wrote pins for) and on the §3.3 un-retire path inside `ShopContentWriter::syncStore()` |
| 60s public-profile payload cache | `site.sites.updated_at` touched on the same two paths — `IndividualProfilePayloadBuilder::cacheKey()` composes its key from `updated_at`, so `bump()` alone (a different table) does not invalidate it |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` on the same two paths |

**Lane 3 confirmed firing on the live dev write.** The `cloud env:logs` scan
taken right after the provisioning run shows `CloudflareCachePurgeJob` running
five times between 08:37:15 and 08:37:21 — the command wrote at 08:37:08-10,
one purge per site written — plus the delayed follow-ups at 08:38. Lanes 1 and
2 are asserted by test (`ShopPinProvisioningTest`) rather than observed here;
each assertion was verified to fail if its lane is removed.

### 18.7 Gates at merge — RUN 2026-08-13, all after the rebase

| Gate | Result |
|---|---|
| `composer test` (SQLite) | **7951 passed**, 1 skipped, 1 warning, 0 failures (492s) |
| `composer test:pg` | **198 passed** (934 assertions), 0 failures |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | **No errors** |
| `./vendor/bin/pint --test` | passed |
| Post-resolution registry run | **42 passed** — `PoolRegistryTest` + `ShopPoolTest` + **`ServicesPoolTest`** + `ShopPinProvisioningTest` + `PoolLaneTest` |
| CI on PR #280 | all **9** required checks green (`test`, `postgres-tests`, `schema-tests`, `schema-drift`, `outbound-http-guard`, `supply-chain`, `checkpoint-suppressions`, `worker-tests`, `worker-static`) |

7951 is up from 7886 on the branch alone; the delta is 3a's tests, all green
under the merge. Running 3a's own `ServicesPoolTest` **after** resolving is
what proves the union kept both halves — 5b's tests pass fine against a
`POOLS` array that dropped `services` entirely, so they cannot detect that
failure mode.

**Two rebase conflicts a mechanical resolution would have gotten wrong**, both
resolved by reading each side:

1. Wave A's own fix commit restored the docblock clause "Services is poolless"
   — true when written, **false** once 3a landed. Taking `--theirs` would have
   re-asserted it. Related: `poolForKind()`'s example listed `service` as a
   poolless kind; it now reads `(channel, article, …)`.
2. **Both slices claimed §17.** 3a's checkpoint merged first, so 5b's became
   §18 — heading, all ten subsections, every internal cross-reference and the
   wire manifest's pointer. A union merge leaves two §17s and every `§17.x`
   reference ambiguous, which no test anywhere would catch.

**Running the Postgres lane locally needs setup that is not in place.**
`phpunit.pg.xml` deliberately inherits `DB_*` from the shell, and the local
`.env` points `DB_HOST` at a Supabase ref that no longer resolves, so a bare
`composer test:pg` fails 9 / skips 189 on connection. It was run against a
throwaway `postgres:16` container mirroring CI's service block — *not* the
local `supabase_db_Partna-Development`, which the lane would pollute since it
provisions its own tables.

### 18.8 Conventions this slice established — for slices 3, 4 and 6

Carried forward from the slice spec's §7 so the parent programme record does
not depend on the sub-spec surviving:

| Convention | Value | Who needs it |
|---|---|---|
| Clearing `removed_at` | the rule is owner-may/connector-never, but 5b enforces it **caller-side**: the writer's un-retire step is unconditional, and the boundary lives in what calls it | slices 3, 4, 6 — check your own call sites, do not assume the writer guards itself |
| A pool payload carrying groups | additive `collections` map on the pool envelope, keyed by collection **uuid**, each carrying `externalRef`; items carry plural `collectionIds` | **slice 4 explicitly** — menu categories are the same problem |
| Pool payload enforcement point | explicit key-by-key construction + a wire-shape test pinning key sets, failing on additions too | every remaining slice |
| Owner-chosen ordering | pins seeded once by a provisioning command; nothing writes pins afterwards | slice 4 |
| Kind-specific item fields | additive and nullable on **every** item, never a kind-shaped sub-object | slices 4, 6 |

### 18.9 What remains outstanding

- **#428 — the scheduled shop refresh is FAILING on dev, and it is not 5b's.**
  Found by this slice's post-deploy Nightwatch scan, which is the entire reason
  §5.5 requires one. `LazyLoadingViolationException: Attempted to lazy load
  [products] on model [ShopBrand]` — `ShopBrand::toBrandArray()` (`:129`) reads
  `$this->products`, the legacy `site.shop_products` relation, while `ShopFetch`
  eager-loads only `connection`. Lazy loading is disabled, so it throws;
  `ShopFetch`'s `catch (HttpException)` does not catch it, so it propagates and
  **fails `RefreshConnectionJob`** — incrementing `consecutive_failures`,
  writing `last_refresh_status`, and landing the job in `failed_jobs`.
  **36 occurrences in 24 hours.** First seen `2026-08-13T00:23:04Z`, last seen
  `08:25:51Z` — both BEFORE 5b's deploy at `08:33:42Z`, so this is a **5a
  regression**, not 5b's: 5a stopped writing `site.shop_products` and changed
  what `ShopFetch` eager-loads, but `toBrandArray()` still reads the relation.
  Consequence worth recording for the record 5b itself set: the connector path
  `ShopFetch → syncLatest() → syncStore()` currently throws at `syncLatest()`
  **before ever reaching `syncStore()`**, so the owner-vs-connector un-retire
  boundary documented in §9.8 is presently unexercised in practice. The
  reasoning stands; the path is dead until this is fixed. Not fixed in 5b — 5b
  does not open `ShopCatalog` or `ShopBrand`, and a live scheduled-refresh
  failure deserves its own branch and review rather than being folded into a
  slice that also retires a public wire.

  **FIXED 2026-08-13 on its own branch — PR #282, `6fc1a8d31`.** Three findings
  worth keeping, because the first is the one that made this invisible:

  1. **Eloquent strict mode only arms per INSTANCE, and `Builder::hydrate()`
     sets that flag `if (count($items) > 1)`.** A query returning ONE row
     lazy-loads freely no matter what `Model::preventLazyLoading()` says. So
     this could only ever fire on a connection holding **two or more** brands
     — dev's failing account holds five, every other shop connection holds
     one, and the production data matched exactly: only the 5-brand connection
     carried the lazy-load error. It also explains the green suite: every
     existing fixture had one brand per connection, so no test could reproduce
     it regardless of intent. Any test asserting strict-mode behaviour in this
     repo must hydrate more than one row.
  2. **Every existing `ShopFetch` test mocks `ShopCatalog`**, so `syncLatest()`
     — and therefore `toBrandArray()` — never ran under test at all. The
     regression tests use a real `ShopCatalog` with a mocked scraper.
  3. **The code fix alone would not have restored the account.**
     `consecutive_failures` had reached exactly `10`, the
     `partna.refresh.max_consecutive_failures` cap, so `dueForRefresh()`
     excluded the connection permanently — the circuit breaker had latched.
     The counter was cleared for that one connection (matched on the
     lazy-load error text, so the three genuinely-unreachable stores stuck at
     10 since 2026-07-27 were left alone), then `integrations:refresh` was run:
     `RefreshConnectionJob … 16s DONE`, `last_refresh_status` back to `ok`,
     `consecutive_failures` 0, error null. **A latched breaker is part of the
     blast radius of any job-killing exception — fixing the throw does not
     un-latch it.**

  The fix itself: `syncLatest()` now calls `loadMissing('products')` rather
  than trusting its caller, and `ShopFetch` eager-loads the relation again so
  that guarantee costs one query per batch instead of one per brand. With the
  un-retire path reachable again, the §9.8 boundary is live rather than moot.
  Still deliberately unfixed: `providerProducts()`'s client-mode fallback reads
  the frozen legacy relation, so post-5a it degrades to stale rows or `[]`.
  Zero client-mode brands exist on dev; re-homing it belongs with slice 7.
- **`site.shop_brands` re-homing off the `ShopBrand` model is still slice 7's,
  not this slice's** — 5b only corrected the record that it was needed (§16.9);
  it did not do the re-homing.
- **`CloudflarePurgeService::purgeHandle()`'s un-deduped, 4x-amplified error
  reporting is unfixed**, deliberately — named in the 5b design spec §8 as
  out of scope, owned by whichever slice next touches that file (4 and 7).
- **`upsertStore()`'s SELECT-then-INSERT TOCTOU race is unfixed**, carried to
  slice 7 per 5a's checkpoint (§16.9) and 5b's own scope boundary (§2).
- **Partna-monorepo has not landed its side, as far as this session can
  tell.** That repository is not checked out on this machine and is not
  visible to `gh` from here, so its state cannot be verified from this
  session. If it has not read `profile.pools.shop` and stopped indexing
  `platforms.shop[*].payload` by the time this branch deploys, **every Shop
  page on dev renders empty** — named plainly, not softened, per the wire
  manifest's "required consumer action."

### 18.10 Definition of done — against the 5b spec's §6

Item-by-item against the design spec's criteria, honestly, not optimistically:

| Criterion | Status |
|---|---|
**Reconciled 2026-08-13, after Task 10 ran.** Task 9 wrote this table before the
merge, marking the live-verification rows `PENDING Task 10`; Task 10 then filled
§18.3–§18.5 and §18.7 with pasted output but did not come back and update these
rows, so for a while the summary claimed less than the evidence three
subsections above it. Corrected below — each row now states what was actually
run, and the two that are genuinely not met stay not met.

| Criterion | Status |
|---|---|
| Shop page renders from `profile.pools.shop` in owner order, grouped by the `collections` map | **Backend half MET, verified live** (§18.5): 33 items and a 5-entry `collections` map for a 5-store account, over 51 pins written in catalogue order (§18.3–§18.4). The **render** half cannot be verified from this repo at all — it is partna-monorepo's, and it has not landed. |
| Outbound URL composed by the backend | **MET, and proven live rather than only by test** (§18.5): `https://natalieanne.com/cart/47811307995314:1?discount=ALEX10`, a real Shopify cart deep link with the store's discount appended. The `referral_query` axis remains test-only, exactly as spec §5.3 anticipated — dev carries no store with one. |
| Pool payload has an `SHOP_PRODUCT_ALLOWLIST`-equivalent enforcement point | **MET** — `ITEM_KEYS` / `STORE_KEYS` / `VARIANT_KEYS` + `PoolWireShapeTest`, which fails on key ADDITIONS as well as removals. |
| Every `pool:shop` section carries the corrected rule | **MET, verified live** (§18.3): all five sections, one distinct shape — `{"all":[{"op":"kind_is","values":["product"]}]}` / `recency`. |
| A re-added product returns | **MET, verified live 2026-08-13** (§18.11). Retire-then-re-add exercised against dev through the real `ShopContentWriter::syncStore()`: `removed_at` set on removal, back to `null` on re-add, and `sameItemRow: true` — it returned on the SAME `content.items` row rather than a new one, which is the half that matters (a new row would orphan `analytics.item_views` and every pin). |
| Coverage gate returns 0 | **MET, verified live** (§18.3): 0 uncovered legacy rows. |
| Checkpoint and wire manifest committed | **MET** — this document and `docs/wire-changes/2026-08-12-slice-5b-shop-render.md`. |
| **Legacy `/integrations` shop keys retired** | **MET, verified on the live dev wire 2026-08-13** (§18.12). `platforms.shop[*].payload` is `[]` with the envelope intact, and `profile.pools.shop` serves 33 items and a 5-entry `collections` map in its place. Spec §6's test is whether the retirement proved **unshippable at merge** — it shipped, it is live, and both halves of the wire were read back from `dev-api.partna.au`. Previously left unticked here on the stricter test of "has partna-monorepo migrated", which is a **deploy gate, not a criterion of this slice** — see below. |

**So: eight of eight met.** The only backend-side caveat is that the first row's
*render* half belongs to partna-monorepo and cannot be observed from this repo;
its payload half is proven.

**What remains is a deploy gate, not an unmet criterion.** partna-monorepo still
reads `platforms.shop[*].payload`, which is now `[]`, so its Shop sections render
empty until it switches to `profile.pools.shop`. That is a consumer migration
with a tracked required action in the wire manifest — production stays undeployed
until it lands. Conflating it with slice 5b's own definition of done is what left
this row wrongly unticked at first pass.

### 18.12 Both halves of the public wire, read back live — 2026-08-13

The retirement and its replacement, from `https://dev-api.partna.au` rather than
from a resolver call, because the wire is the thing the criterion is about.

`GET /api/public/profiles/ollies/integrations` — the retired half:

```json
{ "resourceId": "shop", "payload": [], "lastRefreshedAt": "2026-08-13T10:02:54+00:00" }
```

`payload` is `[]` (an array, not `{}`) and the envelope is intact — a consumer
*iterating* `platforms` sees no shape change.

`GET /api/public/profiles/ollies` → `profile.pools` — the replacement half:

```
pool keys           watch, listen, media, events, services, shop
shop items          33
shop collections    5
first item url      https://natalieanne.com/cart/47811307995314:1?discount=ALEX10
description/vendor/variants/collectionIds present   true
frames on first product                             7
popularityRank populated                            15 of 33 items
referralQuery present anywhere in the shop pool     false
linkMode present anywhere in the shop pool          false
```

Four things this settles that nothing else had:

- **The composed checkout deep link is on the PUBLIC wire**, not merely returned
  by an internal resolver call. `productHref()` has nothing left to do.
- **`frames` populates for products** — 7 on the first item. This was the 271
  gallery images the retirement would otherwise have dropped.
- **`popularityRank` works against real data** — 15 of 33 items carry a rank
  (18, 17, 8, 16, 4, 29…). §18.9 previously listed this as covered only by the
  SQLite stand-in and unproven live. It is now proven live.
- **`referralQuery` and `linkMode` appear nowhere in the shop pool**, verified by
  substring search over the emitted JSON rather than by test fixture. The privacy
  improvement is real on the wire.

### 18.11 The re-add, verified live — RUN 2026-08-13, output pasted

The last criterion this repo could settle on its own. Exercised through the real
`ShopContentWriter::syncStore()` against dev, on a **throwaway collection** so no
existing catalogue was touched:

```json
{
  "collection":  "4415bd4d-dc52-41d7-9d97-17c2b588d9c3",
  "item":        "2deda708-e3ed-439a-ab2f-86ba6171e5c9",
  "retiredAt":   "2026-08-13 11:18:39+00",   // syncStore() with an empty catalogue
  "afterReadd":  null,                        // syncStore() with the product back
  "sameItemRow": true                         // the point of the whole rule
}
```

`sameItemRow` is what the §3.3 narrowing exists for: a re-add must clear
`removed_at` on the EXISTING row, not mint a new one, because a new row orphans
`analytics.item_views` and every `site.section_items` pin pointing at the old id.

**Probe rows removed afterwards, verified zero residue.** `content.source_items`
was deleted first — it is the one child of `content.items` whose FK is `SET NULL`
rather than `CASCADE`, so deleting the item first would have left an orphan row
with a null `item_id`. Post-cleanup dev is back to exactly **51 live products, 0
retired** — the pre-probe state.
## 19. Slice 3b checkpoint — Fresha services

**Status: verified on dev 2026-08-13, merge gate pending.** Branch
`feat/slice-3b-fresha` off `bf7a4f9a4`. Prefix `20260813090000` consumed by
`slice3b_collections_keys_and_selection_ref`. §3 invariant 1 discharged for the
Fresha half; §3 invariant 2 (something reads the kind) discharged by the booking
surface reading `content.*`.

Unlike 3a, this half is **landed, not migrated**. There is no backfill command:
the connector fetches the vendor and the projector writes `content.*`, so the
verification below is a real ingest run, not a data move.

### 19.1 The headline — the price that justified the slice

```
user 019ebedc -> 36 services   PARTIAL BALAYAGE+WELLAPLEX   $275
```

§1.4 of the slice spec measured that exact service live at **employee $275
versus storewide "from $180"**. The connector fetched the chosen team member's
menu and the resolver renders the honest price. `ULTIMATE HAIR SMOOTHENING`
likewise landed `from $360` where the storewide menu published `from $180`.

That divergence — 22 of 23 prices understated on one salon — is the whole
reason this slice exists, and it is now closed on live data rather than in a
fixture.

### 19.2 Live dev run — 2026-08-13 ~20:35 AEST, output pasted

The branch is deployed nowhere (Laravel Cloud only deploys `development`, and
merging first would have inverted the gate), so it ran **locally against dev**
via a scratchpad script pulling dev credentials from the Cloud CLI into process
env, never echoed, `.env` untouched. The script refuses if `DB_USERNAME` names
the prod ref or names neither ref. `QUEUE_CONNECTION=null` and
`CACHE_STORE=array` kept the run off Redis and stopped any incidental job
reaching a third party.

**A prerequisite the plan did not anticipate.** `ingest.sources.selection_ref`
was **NULL on all four dev sources** — Task 1's migration added the column but
nothing had re-run `SourceProvisioner::sync()` since, so a run would have landed
nothing everywhere. Backfilled by syncing **only the five Fresha connections**.
`ingest:backfill-sources` was rejected: its dry-run showed it would process
**80 connections across every connector**, bumping `next_attempt_at` on
unrelated sources and risking interference with slice 5b.

The backfill confirmed the `selection_ref` design on real data:

| connection | selection mode | selection_ref | next_attempt_at |
|---|---|---|---|
| brotherwolf | `employee` | **4891132** | pulled forward to now |
| vision | `employee` | **4508456** | pulled forward to now |
| edward | null selection | NULL | unchanged (2026-08-15) |
| some-salon-abc123 | null selection | NULL | unchanged |
| anseo-studio | null selection | NULL | **n/a — no ingest source at all** |

The pull-forward is the refetch-on-change line firing live. `anseo-studio`
having no source confirms slice-spec §1.2/§7 on real data:
`SourceProvisioner::freshaSlug()` matches only `fresha.com/…/a/<slug>` and that
row is a `book-now/…?pId=` URL.

Only 2 sources were due, both Fresha — checked before dispatching, so there
were no collateral runs.

#### Run 1 — `ingest:dispatch --sync --limit=5` → claimed 2, 0 failed

```
brotherwolf  services  health=ok           run_seq=1  selection_ref=4891132
vision       services  health=ok           run_seq=1  selection_ref=4508456
edward       services  health=unavailable  run_seq=0  selection_ref=NULL
some-salon   services  health=unavailable  run_seq=0  selection_ref=NULL
```

`run_seq` moved off zero **for the first time since 2026-07-28**. 59 service
`record_versions` landed = 23 + 36, exactly the employee-menu counts slice-spec
§1.3 measured live. The two connections with no selection stayed at zero — the
`no_selection` decision working, not a failure.

Projection ran automatically, chained from `RunSourceJob`.

```
content.source_items kind=service:  connection 59   manual 21
content.items kind=service:         77 live / 3 retired
offers (connection):                exact 52 | from 4 | free 3
gate zero-amount 'exact':           0
gate source_items.removed_at:       0
gate orphan items:                  0
collections kind=service_category:  16, all keyed, all is_user_created=false, none removed
collection_items:                   59
```

- `manual` staying at **21** is the gate: 3a's owner half is intact and
  untouched by the Fresha landing. 77 live items = 18 owner + 59 Fresha.
- **16 categories = brotherwolf 5 + vision 11**, matching §1.3's live probe
  exactly. Every one is keyed on the vendor category id, which is what
  `collections_user_kind_external_ref_uq` needs to work at all.
- 59 `collection_items` = every landed service in exactly one category.
- The `from $1` row was checked against the raw vendor document rather than
  assumed a parse bug: Fresha literally publishes `"from $1"` for *Extensions*.
  Correct parse.

Legacy tables unmoved: **18 live / 3 deleted `source IS NULL`, 59 live / 2
deleted `source = 'fresha'`** — exactly the slice spec's opening figures. §6's
"the legacy tables are not disturbed" requirement, verified rather than assumed.

#### Run 2 — identical dispatch, after nudging `next_attempt_at`

```
content.items fingerprint, run 1:  md5 = dd8494a58f6353237670d70800d7dacf  n=80
content.items fingerprint, run 2:  md5 = dd8494a58f6353237670d70800d7dacf  n=80
```

**Not one item id changed.** Every count and every gate unmoved (21 / 59 / 77 /
3 / 16 / 59; gates 0 / 0 / 0). §8.3's `mergeInto()` hard-delete hazard exercised
for real, on real vendor data, and clean.

#### The resolver — what no SQL stands in for

```
user 019e5c37 -> 23 services   First Time Client            $70   45min   Hair
user 019ebedc -> 36 services   FULL HEAD BALAYAGE+WELLAPLEX $390  2h 30min BALAYAGE
keys: name,price,category,currency,duration,serviceId,priceValue,description,hasVariants
```

Nine keys, a real category label rather than a hardcoded one, and prices and
durations round-tripped from `content.offers` / `content.f_duration` back into
the vendor's own display grammar.

### 19.3 Pest — the chain, end to end

The chain runs **connector → projector → item → pool → wire**, and it is
drivable in a test: no seam required a live connector run to prove.

| Stage | Test |
|---|---|
| connector: nothing chosen lands nothing and makes no HTTP call | `tests/Feature/Ingest/FreshaConnectorTest.php` — *"lands nothing and makes no HTTP call when nothing has been chosen"* |
| connector: the chosen employee's menu is what is fetched | *"asks for one employee menu when an employee is chosen"*, *"asks for the store menu when storewide is chosen"* |
| connector: the vendor category id survives the mapper | *"carries the vendor category id alongside its name"*; *"lands a package row whose catalog id is only on the secondary action"*; *"counts rows it could not map instead of dropping them silently"* |
| **connector → projector → collections → wire, in one case** | `tests/Feature/Ingest/FreshaServiceProjectorTest.php` — *"drives a Fresha category from a landed record to a rendered booking category"* |
| projector: a vendor rename follows rather than duplicating | *"follows a vendor-side category rename instead of minting a duplicate"* |
| wire: the nine-key shape and its ordering | `tests/Feature/Platforms/FreshaBookingSurfaceTest.php` — *"reproduces the stored blob's service shape exactly"*, *"orders services deterministically when several rows share one first_seen_at"*, *"round-trips every price qualifier back to its display string"*, *"keeps a hidden service in services[] and leaves the hiding to hiddenServiceIds"* |
| the two-surface rule, both directions | `tests/Feature/Content/ServiceTwoSurfaceTest.php` — *"never renders a Fresha service in the public services section"*, *"never renders an owner-authored service on the booking surface"* |
| no Fresha row reaches the owner-authored price mapper | `tests/Feature/Architecture/FreshaMapperGuardTest.php` — *"routes no Fresha service through the owner-authored price mapper"* |

The e2e case is load-bearing and was built to be: it starts from records landed
in `ingest.record_versions` in the doc shape `mapServiceItem()` actually
produces, lets the real observer and `SourceProvisioner` mint the ingest source
(**asserted, not hand-inserted**), calls `ProjectionWriter::projectStream()`,
reads real `content.collections` / `content.collection_items`, and finishes at
`FreshaServiceItems::selectionServices()`. **No hand-inserted collection row
anywhere.** The chain mutation — dropping only the `collections` key from the
projection — reddened **both** e2e cases at the database (count 0 versus 2 and
1), not merely the unit case.

Lane coverage: `composer test:pg` 202 passed / 2 skipped (both skips
pre-existing); `tests/Feature/Ingest` 418; `tests/Feature/Content` 199;
`tests/Feature/Architecture` 91; `--filter=Fresha` 277 passed, 1020 assertions.

### 19.4 Logs and Nightwatch — including the signal that was dismissed

`ingest.anomalies` in the two hours around the run: **none**.

Nightwatch showed **two new exceptions six minutes after the run** — #429 and
#430, `RedisException` connection timeout. They were **investigated rather than
dismissed on timing**:

- the stack is `Horizon\Console\WorkCommand → RedisQueue->pop() → blpop()`,
  i.e. dev's own Horizon worker polling its queue on Laravel Cloud;
- this run used `QUEUE_CONNECTION=null`, enqueued nothing, ran no worker, and
  exported no Nightwatch token, so it **cannot** be the source;
- 2 occurrences in 24h at the queue-poll layer this repo already built
  `GuardedPhpRedisConnector` for.

**Attributed to dev infrastructure, not to slice 3b.** Recorded here rather than
omitted: a checkpoint that lists only clean results teaches the next reader that
clean is the expected shape of a verification, and the next person to see a
Nightwatch entry six minutes after their run has no precedent for what to do
with it.

**Not ours, but live on dev and surfaced deliberately:** Nightwatch #427 is
`SQLSTATE[42702] Ambiguous column: products_curated_at`, 12 hours old — the
exact bare-column-in-`ON CONFLICT` defect class this programme's constraints
warn about, landing in slice 5b's product territory.

### 19.5 Cache invalidation (§9.2, all three lanes)

| Lane | This slice |
|---|---|
| `site.site_build_state` | **Bumped** — every owner and staff category write verb calls `ManualServiceWriter::invalidate()` |
| 60s public-profile payload cache | **Busted** — `invalidate()` touches `site.sites.updated_at` |
| Cloudflare edge | **Purged** — `CloudflareCachePurgeJob` per touched site |

Every lane is asserted with an **exact revision delta**, never `> 0`. 3a's
three-lane test passed with a lane deleted because `writeManualItem()` bumps
internally; on these routes nothing else bumps, so `> 0` would in fact have
caught a deleted lane — the exact `+1` stays as the rule anyway, because it also
catches a double bump.

**The open cost, stated plainly:** there is no observer on
`content.collections` and no CI check, so invalidation is a hand-maintained
caller obligation programme-wide. A forgotten caller serves stale pages until
TTL. Any new `content.collections` write path must invalidate explicitly.

### 19.6 What the waves cost, and what they bought

Twelve rulings, thirteen fix rounds. Four things are worth carrying beyond this
slice.

- **The boolean lane asymmetry is not a curiosity, it is a defect generator.**
  `is_user_created` is a PHP bool in **neither** lane: SQLite returns INTEGER
  `0`, PDO_PGSQL returns the string `"f"` — and `"f"` is truthy. Two independent
  tasks hit it, one as a silently-inverted `list()` filter that would have called
  every Fresha category owner-made **in production only, with the suite green**.
  `item_count` and `position` come back as numeric strings from the same driver.
  Normalise on the way out of every row-returning method; never compare a driver
  value to a PHP literal.
- **`content.collections.position` is a seed, not a synced column.** It was in
  the upsert's update list, so every connector run rewrote it — silently undoing
  the owner's reorder, on a schedule, with no signal. It is now insert-only.
  `label` deliberately stays in the update list: a vendor rename should be
  followed, not duplicated. `removed_at` is in neither list, so a scrape
  re-listing a category cannot resurrect an owner deletion.
  **`content.collection_items.position` is still recomputed from array order on
  every run and is not owner-owned** — nothing conflicts today because service
  ordering lives in `site.services.sort_order`, but the moment membership order
  becomes owner-editable the same rule must be applied there.
- **A pinned-set guard is only as good as the moment its baseline was taken.**
  The `projectionFor(` caller list had **four** entries, not the three the plan
  recorded — `StaffServiceManagementController` joined it mid-slice. Had the
  baseline been copied from the document, the guard would have failed on a
  correct caller on day one, and the natural "fix" would have been to relax it.
  Derive a pinned set from the tree, never from the document that predates the
  work.
- **A filtered test run proves what it matched and nothing else.** A task ran
  `--filter=ServiceCategory`, saw 46 passed, and missed three failures in
  `ServiceCategoriesIsolationTest` — `ServiceCategor**ies**` diverges at the
  `y`/`i`. Running `tests/Feature/Security` as a **directory** later found a
  sixteenth failure a path-scoped run had missed. Same blind spot, reached two
  different ways.

### 19.7 Assurance debt this slice found and did not create

- **Three tenancy tests resolved the row by the very owner they then asserted**,
  so they could never have caught a create landing under someone else's id. Two
  were repaired here; the pattern now has three recorded instances in this
  repo's tenancy suite.
- **`ServiceCategoriesIsolationTest` and its passing sibling call the controller
  directly** (`app(UserServiceCategoryController::class)->show(...)`), bypassing
  routing and middleware, so they can certify an authorization posture the real
  HTTP path does not have. Pre-existing, out of scope, recorded rather than
  left unremarked.
- **An assertion on a value's *type* rather than its *identity* passes for any
  member of that type.** A pre-existing test asserted only `instanceof Note`;
  adding the new `no_selection` note silently converted it into a tautology and
  nothing failed. Tightened to pin the code.
- **`PruneRetiredItemSlugsTest`'s cutoff-boundary case is a flake**, not a
  regression: untouched by this branch, passes 13/13 in isolation, and sets
  `retired_at` exactly at the cutoff so elapsed time under full-suite load tips
  it past. This slice adds ~130 tests and therefore lengthens the run before it
  executes, so a marginal case may now tip more often.
- **`phpstan analyse` in a worktree dies with `Child process error (exit code
  255): while running parallel worker` and OOMs at 128M.** Neither failure looks
  like what it is. Use
  `php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug`
  — `--debug` disables parallelism, and the real errors then print normally.

### 19.8 Open, and carried on

- **Whether a multi-`category_ids` payload should 422 rather than collapse to
  one** is an open product decision. The collapse is pinned by test on both
  surfaces so it is visible and goes red the day memberships become
  multi-valued.
- **`ProjectionWriter::upsertCollections()`'s id lookup filters `user_id` +
  `external_ref` but not `kind`.** Harmless today because the map key
  disambiguates; seen twice, by two reviewers.
- **`reorder` on the staff category surface runs two independent lock scopes**
  (content, then legacy via `ReorderService`) that are not atomic with each
  other. Worst case is a stale order within one block, never a collision.
  Unifying them needs `ReorderService`, which was out of scope.
- **`ContentFreshness:89-98`** is still un-migrated, carried from 3a.
- **13 of the 14 `ingest.sources` stand-in declarations lack `selection_ref`**,
  all in `tests/Postgres/`. `DuplicateStandInDdlGuardTest` structurally excludes
  that directory, so nothing catches the divergence. Inert today because no
  Postgres-lane test exercises `SourceProvisioner` or `RunExecutor`; the first
  one that does must add the column.
- **Slice 7 inherits an undercount:** there are **18** service routes on the
  owner surface, not 17 — `UserServiceCategoryController` has seven, not six.

---

## 20. Phase 1 checkpoint — lean out + the `commerce_probe` fix

Convergence programme Phase 1 (`docs/2026-08-14-convergence-phases.md` §1), run
2026-08-14 on branch `feat/phase-1-lean-out`. Dev only; production untouched.
§3 invariant 1 discharged below — every claim is a live assertion against
`glncumufgaqcmqhzwrxm`, output pasted.

### What shipped

| Unit | Change |
|---|---|
| 1.1 | `routing.source_intents.origin` accepts `commerce_probe` (migration `20260814100000`) |
| 1.2 | twitch, skool, strava, gumroad, substack de-sourced — 5 connectors + 4 projectors deleted |
| 1.3 | `article` retired from `KindRegistry`; its 1 orphan row deleted |
| 1.4 | the `profile_fields` seam deleted from all three connectors; 10 dead streams retired |

### Exit criteria, measured on dev

```sql
SELECT
  (SELECT pg_get_constraintdef(oid) FROM pg_constraint
     WHERE conname='source_intents_origin_check'
       AND conrelid='routing.source_intents'::regclass)          AS origin_check,
  (SELECT convalidated FROM pg_constraint
     WHERE conname='source_intents_origin_check'
       AND conrelid='routing.source_intents'::regclass)          AS validated,
  (SELECT count(*) FROM content.items WHERE kind='article')      AS article_items,
  (SELECT count(*) FROM content.source_items WHERE kind='article') AS article_source_items,
  (SELECT count(*) FROM content.f_channel)                       AS f_channel,
  (SELECT count(*) FROM content.items)                           AS items_total,
  (SELECT count(*) FROM ingest.streams)                          AS streams_total,
  (SELECT count(*) FROM ingest.streams WHERE stream_name='profile') AS profile_streams,
  (SELECT count(*) FROM ingest.sources
     WHERE source_key IN ('twitch','substack') AND auto_sync)    AS demoted_still_scheduled,
  (SELECT count(*) FROM content.source_items WHERE item_id IS NULL) AS orphan_source_items;
```

```
origin_check            | CHECK ((origin = ANY (ARRAY['paste'::text, 'website_import'::text,
                        |   'link_in_bio'::text, 'bio_harvest'::text, 'google_business'::text,
                        |   'staff'::text, 'reproject'::text, 'commerce_probe'::text])))
validated               | true
article_items           | 0
article_source_items    | 0
f_channel               | 9
items_total             | 722
streams_total           | 49
profile_streams         | 0
demoted_still_scheduled | 0
orphan_source_items     | 0
```

The live `commerce_probe` write gate, run before cleanup (the insert succeeded
under the new constraint, then the probe row was deleted):

```sql
INSERT INTO routing.source_intents
  (user_id, surface_key, routing_class, identifier, canonical_url, state, origin)
SELECT id, 'shopify.store', 'commerce', 'commerce-probe-live-gate',
       'https://example.invalid/commerce-probe-live-gate', 'proposed', 'commerce_probe'
FROM core.users ORDER BY created_at LIMIT 1
RETURNING origin, state;
-- => commerce_probe | proposed        (routing.source_intents back to 0 rows after cleanup)
```

Deltas against the RUNBOOK baseline: `content.items` 723 → 722 (the one
`article`), `ingest.streams` 59 → 49 (the ten `profile` streams).
`content.f_channel` is unchanged at 9, as the phase requires.

### Four things a later phase must not re-derive wrongly

1. **`content.f_channel` = 9 is spotify 4 + TWITCH 4 + soundcloud 1.** The
   phases doc's parenthetical — "spotify/soundcloud still produce channel" —
   accounts for only 5 of the 9. Twitch's 4 are landed history behind a
   now-de-sourced connector. Phase 4 retires the `channel` kind and must
   therefore dispose of twitch's 4 rows explicitly; they will not disappear
   when spotify/soundcloud convert to `track`.

2. **The de-sourced platforms keep their `ingest.sources` rows,
   `auto_sync = false`.** `SourceScheduler::scoreDue()` filters on that flag,
   so nothing claims them, and `IngestProjectCommand` skips streams with no
   projector — so a Phase 2 `--rebuild` passes over them rather than dropping
   their content. Deleting those rows would have taken twitch's 4 `f_channel`
   items with them and broken this phase's own exit criterion.

3. **`KindRegistry` (13 kinds) is deliberately narrower than the DB CHECK (14).**
   `article` is the one value they disagree on. Narrowing the DB would force a
   rewrite of `ContentKindDomainParityTest`, which reads its expected domain
   out of two migration files BY NAME and would not see a superseding
   migration — it would keep passing against stale text (log F9). Both
   docblocks record this so the gap is not "fixed".

4. **`SourceIntentDomainTest` now resolves the EFFECTIVE domain**, taking the
   newest `ADD CONSTRAINT` for a column across all migrations and falling back
   to the inline `CREATE TABLE` declaration. Before this change it read one
   migration by name — the same latent staleness as (3). `state` and
   `block_reason` still take the fallback path.

### Raised, not done — needs an owner ruling

**Phase 1.2 says the five demoted platforms end as `PD::linkOnly()`
registrations. That half was not executed, and should not be without a
decision.** gumroad and substack were *already* `PD::linkOnly()`, so they
needed nothing. twitch, skool and strava are not: they carry connect
strategies, scrapers, their own resources, route shapes, display toggles and —
for twitch — the live-status lane (`TwitchApiClient` → `LiveStatusPoller`,
which is unrelated to ingest and survives). Dev holds live connections behind
them: **twitch 5, skool 1, strava 1, substack 1**.

Converting those three descriptors to `linkOnly` would move real connections
onto `LinkConnectionResource`, delete user-facing connect flows, and change the
public wire — none of which Phase 1 names, and all of which is product, not
cleanup. The ingest demotion (this phase's actual goal) is complete without it:
none of the five can produce a `content.item` any more.

**Recommendation:** fold the descriptor question into Phase 6, which already
owns platform-surface retirement and has to decide each connection's
destination anyway.

---

## 21. Phase 2 checkpoint — identity key emission

Convergence programme Phase 2 (`docs/2026-08-14-convergence-phases.md` §2), run
2026-08-14 on branch `feat/phase-2-identity-keys`. Dev only; production
untouched, and no tool call in the session named it. §3 invariant 1 discharged
below — every figure is a live assertion against `glncumufgaqcmqhzwrxm`, output
pasted.

### What shipped

`ProjectionWriter::writeIdentityKeys()` emitted **2 of 17** `KeyClass` values:
`platform_object`, which embeds the platform and so can never match
cross-source by construction, and `canonical_url`, which never matches across
platforms. The Joining tier could union nothing; Corroborating and Evidential
were empty. That is the whole reason `content.item_merges` and
`content.identity_candidates` had sat at 0 since the engine shipped (log F1) —
the resolver is complete and covered by 21 unit tests; it was **starved, not
missing**.

| Unit | Change |
|---|---|
| 2.1 | `IdentityKeyDeriver` — pure derivation of 15 of 17 classes; `writeIdentityKeys()` now only persists what it returns |
| 2.1a | `MediaFingerprint` extracted, so the asset dedupe key and the `content_digest` identity key cannot drift apart |
| 2.2 | `tests/Unit/Ingest/IdentityKeyEmissionTest.php` — 26 emission tests, including every no-data path |
| — | `KeyClass::normalizeText()` made platform-independent (log F26) |

### Entry state, re-measured before touching anything

F10 required re-verification at entry, and F24's numbers had already moved
under it. Measured immediately before the rebuild:

```sql
SELECT (SELECT count(*) FROM content.identity_keys)                    AS identity_keys,
       (SELECT count(DISTINCT key_class) FROM content.identity_keys)   AS key_classes,
       (SELECT count(*) FROM content.item_merges)                      AS item_merges,
       (SELECT count(*) FROM content.identity_candidates)              AS candidates,
       (SELECT count(*) FROM content.items)                            AS items,
       (SELECT md5(string_agg(id::text, ',' ORDER BY id))
          FROM content.items)                                          AS item_id_digest;
```

```
identity_keys | key_classes | item_merges | candidates | items | item_id_digest
         1235 |           2 |           0 |          0 |   723 | 32bd74bdb9acc42005100999eb224cad
```

F10 holds on the data and needed one correction on the design:

- `isrc` 0, `gtin` 0, `track` items 0 — confirmed, so `Isrc`, `Gtin14` and
  `TitleDuration` are unemittable **by data**, exactly as predicted. They are
  derived anyway, from the `f_catalog`/`f_duration` columns that already exist:
  reading an empty column invents nothing and starts working the day a
  connector fills it.
- **`TitleRelease` is title|ARTIST, not title|date.** F10 read "release" as the
  release date and cited `f_published` coverage as the evidence. The key
  registry (`docs/plans/2026-07-27-content-platform-rebuild.md` §5) rules
  explicitly that "year is not in the music key" — two platforms disagree about
  a remaster's date constantly and agree about who made it. Derived from
  `f_authored.creator`, live on 192 of 223 releases.
- **`FeedGuid` is the one class deliberately not derived.** No projection field
  means a feed's `<guid>`; adding one that no connector fills would rebuild
  exactly the `profile_fields` seam Phase 1 deleted (F15/F20). `EnclosureUrl`
  IS derived, from `f_playable.stream_url`, because that field already exists.

### Exit criteria, measured on dev after `ingest:project --rebuild`

Sequence: `--dry-run` first (read-only; 40 streams / 597 records), then
`--rebuild`, both via `cloud command:run development`, both exit 0, 0 failed.

```sql
SELECT key_class, tier, count(*) AS keys, count(DISTINCT key_value) AS distinct_values
  FROM content.identity_keys GROUP BY 1,2 ORDER BY 3 DESC;
```

```
 key_class                 | tier          | keys | distinct_values
---------------------------+---------------+------+-----------------
 platform_object           | joining       |  700 |             648
 canonical_url             | joining       |  535 |             437
 title_only                | corroborating |  420 |             313
 title_loose               | evidential    |  420 |             280
 title_release             | corroborating |  192 |             159
 offering_name_in_category | corroborating |   59 |              59
 name_price_band           | evidential    |   59 |              59
 offering_name_spec        | corroborating |   59 |              59
 offering_name             | corroborating |   56 |              55
 content_digest            | joining       |   52 |              52
 author_date_body          | evidential    |   20 |              20
 event_occurrence          | corroborating |   13 |              13
```

| Criterion | Result |
|---|---|
| Full suite green | **8163 passed**, 1 skipped, 1 warning (28274 assertions) |
| `tests/Postgres` green (MANDATORY — `ProjectionWriter` changed) | **207 passed** (959 assertions) |
| PHPStan | **no errors** (1358 files) |
| **More than 2 distinct `key_class` on dev** | **12** — criterion met |
| `content.items` reconciled exactly | **723 → 723, `item_id_digest` byte-identical** |
| `content.item_merges` defensible row by row | **0 rows** — see below |

### Why zero merges is the correct outcome, not a failure

Predicted before a line of code was written, by running the resolver's own
union rules in SQL. Only seven `(user, kind)` pairs on dev have more than one
source at all — release (apple_music + 3× bandcamp), video (vimeo + youtube),
service (fresha + manual), event (eventbrite + humanitix), channel. Across all
of them there is **not one** cross-source collision on a normalised name, an
occurrence instant or a media fingerprint.

Every duplicate title that does exist is duplicated **within a single source**
(27 of them on one Apple Music catalogue: `lemonade` ×4, `texas hold em single`
×4). `Resolver::poisonedKeys()` discards those outright — a value that does not
identify inside a source cannot identify across them — so they are excluded
before the union pass, not merged and then unpicked.

The `content.items` digest being unchanged is the strongest available proof:
not one item id was minted, merged or hard-deleted by the rebuild.

`content.identity_candidates` is 0 for the same reason — the 140 duplicate
`title_loose` values are all same-source, hence poisoned.

### The live gate rejected the first attempt (log F26)

Recorded because a clean checkpoint would teach the wrong lesson.
`KeyClass::normalizeText()` transliterated via `iconv('ASCII//TRANSLIT')`,
which is a C-library behaviour. Round 1 "fixed" it from the macOS output alone,
merged, deployed, and the rebuild put this in the database:

```
 key_value
----------------------------------
 morning dew donk acoustic 4 44 single|beyonc
 telephone the dj remixes|lady gaga beyonc
```

glibc under the container's POSIX locale does not transliterate — it
substitutes `?`. So "Beyoncé" became `beyonc` and "Björk" the two-token
`bj rk`, which could never match `bjork` from a second source. Round 2 replaced
iconv with an explicit `TRANSLITERATIONS` table (Latin-1 Supplement + Latin
Extended-A), and the accent cases are now pinned by unit test:

```
identity_keys | key_classes | item_merges | items | item_id_digest                    | 'beyonce' keys | mangled
         2585 |          12 |           0 |   723 | 32bd74bdb9acc42005100999eb224cad |            145 |       0
```

The round-1 → round-2 delta is **+2 keys, all `title_loose`**, and both are
named: `BEYONCÉ (More Only) - EP` and `BEYONCÉ (Platinum Edition) - EP`
normalised to 9 characters under the mangling — below `TitleLoose`'s minLength
of 10 — and to `beyonce ep` (10) once the accent folded. Every other class is
unchanged to the row.

**No test on a laptop could have caught this.** Invariant 1 is usually observed
as bookkeeping; here the live assertion was the only thing standing between a
silently wrong identity layer and Phase 4 building music dedup on top of it.

### Cache lanes and logs

No site-facing content changed — 0 items minted, merged or removed — so no
cache-lane assertion applies; `refreshItemCaches()`/`bumpSite()` ran inside the
rebuild as they do on any projection.
`cloud env:logs partna development --minutes 12`, scanned after both rebuilds:
routine `CloudflareCachePurgeJob`, `CheckStreamingLiveStatusJob`,
`analytics:compute-popularity` and the deploy cycle. **No exceptions, no
failures.**

### What this hands the next phases

- **Slice 4 (menus)** will produce the programme's **first real merges**. Its
  kickoff has been amended in place: the working key is
  `offering_name_in_category` (`norm(category)|norm(dish)`, minLength 5), and it
  is derived from the projection's `collections` entries — so `MenuItemProjector`
  must emit a category label or short dish names silently stop merging.
- **Phase 4 (listen)** gets the identity layer it depends on, with the ISRC
  caveat unchanged: no connector supplies one, so cross-platform track dedup
  falls back to `title_release`/`title_only` (corroborating, cross-source only)
  unless an Apify actor returning ISRC is selected.
- **The custom links pool** inherits log **F25**: `Resolver::mayUnion()`
  sanctions a `link` folding into any kind, but both callers of
  `resolveItems()` scope resolution to ONE kind, so that path is unreachable
  through `ProjectionWriter`. A pasted link will not fold into a synced item
  however exactly the URLs match. It is also what makes emitting `title_only`
  safe — a podcast episode cannot fuse with a same-titled video.

---

## 22. Phase 3 checkpoint — the custom links pool (W5)

Phase 3's LINKS half. The menu half (W4) is slice 4's and is not touched here.
Merged as `b7657b50e`, deployed to dev, and every figure below read back off
`glncumufgaqcmqhzwrxm` or `dev-api.partna.au` after the run.

### What shipped

- **`PoolRegistry` gains `custom_links`** — kind `link`, page key `links`, label
  `Links`, section shape a BARE `kind_is` on `recency`. Registry config plus one
  test, on the reviews-pool template (`8dd1ff989`): no migration, confirming
  F12/F14. `link` was already in both `content.items` and
  `content.source_items` kind CHECKs, so nothing DDL moved.
- **`CustomLinkBackfiller` + `content:backfill-custom-links`** (`--dry-run`,
  `--user=`) under `app/Services/Migration/` — production code, idempotent,
  re-runnable (invariant #4).
- **Curation flags are the mirror of reviews** and needed no new consts:
  `allowsPin()` and `allowsManualAdd()` both answer true off the existing
  exclusion lists. `item_links` stay refused by ABSENCE — `ItemLinkRules` has no
  roster entry, so `allowsPlatform()` rejects every platform (owner ruling
  2026-08-14).

### Entry state, measured before touching anything

```
live partna.custom_link connections   23   (26 rows, 3 soft-deleted)
distinct (user, lower(url))           23   distinct users 9   all 9 have sites
content.items kind='link'              0
content.items total                  726
site.pages key='links'                 0
```

The other five pseudo-platform surfaces, unchanged and deliberately out of
scope: `partna.order_link` 10, `partna.storefront` 6, `partna.reserve_link` 2,
`partna.booking_link` 0, `partna.manual_event` 0 — 18 non-custom-link
connections, matching the scope doc's figure exactly.

### The run

```
$ php artisan content:backfill-custom-links --dry-run
[dry-run] would backfill 23, duplicate url 0, skipped (no url) 0, skipped (no site) 0, failed 0

$ php artisan content:backfill-custom-links
backfilled 23, duplicate url 0, skipped (no url) 0, skipped (no site) 0, failed 0
```

### Exit criteria, measured on dev

**Coverage gate — coord coverage, not row equality.** Derived independently
twice: once in PHP with the app's own `sha1()`, once in SQL with `pgcrypto`.
Both produced the same 23 coords.

```sql
with live as (
  select user_id, btrim(payload->>'url') as url
  from site.platform_connections
  where surface_key='partna.custom_link' and deleted_at is null
    and coalesce(btrim(payload->>'url'),'') <> ''
), want as (
  select distinct user_id,
         'manual:'||encode(extensions.digest(lower(url),'sha1'),'hex') as coord
  from live
)
select (select count(*) from live) live_connections, count(*) expected_coords,
       count(si.id) landed_coords, count(*) filter (where si.id is null) missing
from want w
left join content.sources cs on cs.user_id=w.user_id and cs.kind='manual'
left join content.source_items si on si.source_id=cs.id and si.coord=w.coord;

 live_connections | expected_coords | landed_coords | missing
------------------+-----------------+---------------+---------
               23 |              23 |            23 |       0
```

**Items and curation.**

```
content.items kind='link'          23      f_link rows for them          23
content.items total               749      = 726 + 23, no merges
f_text rows carrying a body        13      = 23 − the 10 with no description
site.pages key='links'              9      site.sections key='pool:custom_links'   9
section_items pinned               23      excluded                        0
content.item_merges                 0      identity key classes           12
```

`item_merges` 0 is the CORRECT outcome, not a miss: no `link` items existed
before this run, and F25 records that identity resolution is kind-scoped, so a
link cannot fold into a synced item of another kind. `749 = 726 + 23` exactly —
nothing was absorbed and nothing was lost.

**The wire, read back off `dev-api.partna.au`.**

`GET /api/public/profiles/ollies` → `pools` keys are now `custom_links, events,
listen, media, services, shop, watch`. `pools.custom_links` carries 12 items,
`latestItemId` **null**, and **no** `stats` or `collections` key. Item keys are
exactly `PoolResolver::ITEM_KEYS` minus `selected`, which the public builder
strips. On every item: `platform` null (the manual source has no connection),
`origin` `"manual"` (they are pins), `thumbnail` null, `links` a single
`{platform: null, url, source: "synced"}` entry.

Headline fallback, live on `gsnwilliams` and `showcase-creator` — where the
payload carried no `name`, the host stands in exactly as the hand-add lane does
it: `tiktok.com`, `broadsheet.com.au`, `youtube.com`, `www.nasa.gov`.

**F27 confirmed live.** Exactly one of the 23 differs between the lanes:

```
https://youtube.com/@gsnwilliams?si=[redacted]
```

`f_link.url` is minimised by `SecretParams::minimiseUrl()` (#PRIV-5) while the
legacy payload keeps the raw query. `si` is YouTube's share param and the
destination ignores it. The coord derives from the RAW url, so coverage is
unaffected — which the gate above proves rather than assumes.

**Idempotency, proven on live data rather than asserted.** The command was run
a SECOND and THIRD time against dev:

```
backfilled 23, duplicate url 0, skipped (no url) 0, skipped (no site) 0, already curated 23, failed 0
```

and every count above was unchanged: 23 items, 23 source items, 23 curation
rows, 749 total, `max(sort_key)` still 12 on the 12-link section. No
duplication, no re-ordering, no resurrection.

`already curated 23` IS the re-run signal — every link is written again (the
coord is stable) while its curation is left alone. The second run did not
print it: the counter was computed and never displayed, so a first run and an
idempotent re-run rendered identically. Fixed and redeployed before the third
run above, which is the one quoted.

### Three things a later phase must not re-derive wrongly

1. **The pool is a SNAPSHOT until Phase 6.** `CustomLinksController` still owns
   every custom-link write and does not mint content items, so a link added
   after this run appears only on the legacy lane until the command is re-run.
   That is by construction, not an oversight: Phase 6 is where
   `partna.custom_link` stops being connectable at all, and moving the write
   path before then would mean building it twice.
   `CustomLinkBackfiller::linkProjection()` is public as the seam.

2. **Re-runs are FIRST-WRITE-WINS on curation** (fixed pre-flight, in
   self-review). The command re-pinned unconditionally at first, so a second run
   would have flipped an `excluded` row — the owner having taken a link off
   their page — back to `pinned` and republished it. Curation is now written
   only when the item has no curation row at all, and the pin counter seeds from
   the section's highest existing `sort_key` so a new link APPENDS. Consequence:
   the legacy `is_active` flag governs at FIRST SIGHT only; after that the
   pool's own curation is the record.

3. **The coord and the connection `resource_id` share a hash basis** (log F28),
   so Phase 6 can join a retired connection to the item standing in for it by
   string prefix — `resource_id = 'link-' || substr(coord, 8, 16)` — with no
   lookup table. Pinned by a test, because it is a coincidence of two
   independent derivations that neither side declares as a contract.

### Cache lanes, suite, and what was not done

All three lanes fire once per touched site through `SiteCacheLanes::bust()`
(`BuildState::bump`, `site.sites.updated_at`, the Cloudflare purge), asserted
directly in `CustomLinkBackfillerTest` against the +2 revision bump — ">0" would
pass with the `invalidate` call deleted, which is why it is not asserted that
way.

Suite green at merge: **8193 passed, 1 skipped, 0 failed**. 20 backfiller cases
plus 8 pool cases, all in `tests/Feature/Content/` (a new `tests/Feature` child
directory would fail `AuditPipelineIntegrityTest`).

**Not done, deliberately:** `favicon`/`logo` are not carried onto the pool.
Minting `content.media_assets` for third-party image URLs pulls slice 1a's
borrowed-asset lane in for decoration, so a link publishes `thumbnail: null`.
If link cards want brand marks that is its own decision with its own storage
question. **Production is untouched** — dev only, per this programme's scope.

---

## 23. Slice 4 checkpoint — menus on `content.*`

Merged as `3a2792eb7`, deployed to dev, and every figure below read back off
`glncumufgaqcmqhzwrxm` or `dev-api.partna.au` after the run. Spec:
`2026-08-12-slice-4-menus-design.md`. Wire manifest:
`docs/wire-changes/2026-08-12-slice-4-menus.md`.

**Phase 5's live connector proof is BLOCKED — the menu connectors declare a
billed `actor` effect that has no registered driver, so the lane cannot run at
all. Raised, not quietly deferred; see §23.6.** Everything else in the
definition of done is on record below.

### 23.1 Entry state, measured before touching anything

```
site.menu_items            318   (is_manual 7)
site.menu_categories        44
site.menu_item_categories  402
site.menu_item_platforms   318
site.menus                   5
site.menu_platform_links     5
site.item_slugs   menu_item 318 (retired 0) | event 11 (retired 0)
content.item_slugs          16   (all current)
content.source_items kind=menu_item   0
content.items              749
pricing: base 317 | pickup 34 | delivery 315 | currency NULL 93
```

**The parent spec's §1.4 figures (370/52/464/370) are stale and corrected
there.** The drop is exactly one menu tree — −52 items, −8 categories, −62
memberships, −52 platform rows, −1 menu, −1 link — belonging to
`showcase-eats`, deleted between 2026-08-11 and 08-12. It cannot have been a
scrape: `max(last_fetched_at)` across all five surviving menus is 2026-08-06.
Zero orphan slug rows survive, and `site.item_slugs` has no FK to
`site.menu_items`, so nothing at the database level would have cleaned them —
the delete went through application code that reconciles slugs. **The exact
trigger is not recoverable**: no audit table covers menus and Cloud logs do
not reach back. Recorded as undetermined rather than guessed.

### 23.2 The run

```
$ php artisan content:backfill-menus --dry-run
[dry-run] would backfill 318, duplicate name 0, skipped (no site) 0, skipped (no name) 0, failed 0
  slugs: migrated 0, collided 0, unmapped 318 | storefronts 0

$ php artisan content:backfill-menus
backfilled 318, duplicate name 0, skipped (no site) 0, skipped (no name) 0, failed 0
  slugs: migrated 318, collided 0, unmapped 0 | storefronts 5

$ php artisan content:provision-menu-pins
pool:menus pins: pinned 318, left alone 0, unmapped 0, across 5 site(s).
```

The dry run's `unmapped 318` is correct and not a defect: no items exist yet,
so no `content.item_anchors` row maps a dish. It resolves to 0 on the real run.

**Idempotency, proven by re-running both:**

```
$ php artisan content:backfill-menus          # second run
backfilled 318, duplicate name 0, skipped (no site) 0, skipped (no name) 0, failed 0
  slugs: migrated 318, collided 0, unmapped 0 | storefronts 5     <- identical

$ php artisan content:provision-menu-pins     # second run
pool:menus pins: pinned 0, left alone 318, unmapped 0, across 0 site(s).
```

`content.items` kind `menu_item` stayed at 318 across both.

### 23.3 The coverage gate — derived twice, independently

Parent §8.4: coord coverage, not row counts. Derived once in PHP by the app's
own `sha1(normalizeName(...))` and once in SQL with `pgcrypto`, mirroring the
normalisation in a regex. The two agree exactly:

```sql
with want as (
  select 'manual:menu:'||mi.menu_id||':'||encode(digest(
     btrim(regexp_replace(regexp_replace(lower(mi.name),'[^a-z0-9]+',' ','g'),'\s+',' ','g')),
     'sha1'),'hex') as coord
  from site.menu_items mi
)
legacy dishes              318
distinct expected coords   318
landed coords              318
expected NOT landed          0
landed NOT expected          0
```

### 23.4 Exit state on dev

```
content.items kind=menu_item      318
content.source_items menu_item    318
content.item_slugs                334   (16 event + 318 menu_item)
content.offers on menu items     1018
content.collections menu_category  44   order_platform 5
content.storefronts                14   (9 shop + 5 ordering platforms)
collection_items (menu lanes)     720   = 402 category + 318 platform memberships
pins on pool:menus                318
content.item_merges                 0
identity keys on menu items      1654   across 4 key classes
```

`content.items` total 749 → 1068. That is +319, and the extra one is **not
slice 4's**: it is an `episode` created 2026-08-15 16:15 UTC by a scheduled
podcast run, hours before the backfill at 22:48. Only `menu_item` rows were
created in the backfill window.

**`item_merges` is 0, and that is CORRECT rather than a missing outcome.** The
slice was expected to produce the programme's first merges. It did not,
because dev has no dish sold on two platforms: each of the five menus carries
exactly one `menu_item_platforms` row per dish, so there is no cross-source
pair to union. `identity_candidates` is likewise 0. §8.3's hard-delete of
uncurated losers therefore remains **unexercised in production data** and is
carried to whichever slice first lands a genuinely multi-platform menu.

### 23.5 The 301 lane, proven live

The retired set was EMPTY at entry (all 329 `site.item_slugs` rows current),
because `MenuFetchJob` *forgets* a vendor-renamed dish's slug rather than
retiring it. So the 301 had to be created, not migrated. On dev, against a
real dish on `ollies`:

```
BEFORE   slug pizza-margherita          aliases [<raw id>]
RENAME   -> "Pizza Margherita Deluxe"
AFTER    slug pizza-margherita-deluxe   aliases [pizza-margherita, <raw id>]
```

Read back off `dev-api.partna.au` on the public wire, not just the database.
Renaming back exercised the allocator's rename-back arm: the slug returned to
`pizza-margherita` (reactivated, **not** `pizza-margherita-2`) with
`pizza-margherita-deluxe` retired behind it.

**This is a behaviour improvement, named as one:** vendor renames now redirect,
where the legacy lane destroyed the old URL.

### 23.6 Phase 5's live proof — BLOCKED on an unwired driver, raised

**The menu connector lane cannot execute at all, and no data fix would have
changed that.** Established by running it, with the owner's go-ahead, after
repointing two of `showcase-eats`' placeholder connections at real stores:

```
uber_eats.order  -> https://www.ubereats.com/au/store/universal-restaurant/XzekA5noR8WoNpQV2DoP_A
doordash.order   -> https://www.doordash.com/store/doc-pizza-%26-mozzarella-bar-carlton-25116160
```

Both runs failed identically, before any third-party call:

```
RuntimeException: No billed-effect driver is wired for kind 'actor'
(effect 'menu'). A connector must not declare a billed effect it cannot perform.
  HttpIo.php:158 <- EffectLedger.php:127 <- UberEatsMenuConnector.php:58
                                         <- DoordashMenuConnector.php:64
```

`BilledEffectDriverRegistry` is an explicit, ordered list in
`AppServiceProvider` holding exactly two drivers — `PlacesDetailsDriver` and
`InstagramActorDriver`. Its own comment already says so: *"`actor` alone is
ambiguous — the three menu connectors declare it too and have no driver, which
is why they keep hitting HttpIo's throw."*

**So Phase 5's gate as written — provision, dispatch, project — was never
achievable.** It needs a menu actor driver written first, against the three
actors `config('partna.menu.platforms')` names
(`memo23~uber-eats-scraper`, `dz_omar~doordash-scraper`,
`menus-r-us~restaurant-menu-scraper`). That is a slice-sized piece of work
nobody has scoped, and it is not in slice 4's units — slice 4 moves the DATA;
the connector lane was assumed to exist.

**Nothing was spent, and that is slice 0's seam working exactly as designed.**
It refuses an effect it has no driver for rather than letting an undeclared
call read as free. `ingest.effects` carries two `menu` rows at
`status='failed'` — those are CLAIMS, not charges; the Apify call never
happened. Apify sits at **US$2.81 of a US$29 ceiling**, untouched by this
slice, against its US$18 cap.

State left behind, deliberately:

- The two connections keep their real URLs. They are more truthful than
  `some-store`, the owner approved pointing them there, and they are ready for
  the day a driver lands. `square.order` still holds its placeholder — there is
  no real Square store on dev to point it at.
- The three sources carry `auto_sync = false` and `SourceScheduler` filters
  `where('auto_sync', true)`, so nothing will run them on a schedule. Their
  `menu` streams read `health='unavailable', consecutive_failures=1` — the
  honest record of the attempt.
- Also established while checking: **the menu connectors do not read
  `selection_ref`** (that is Fresha's sub-account mechanism), so the NULL on
  these sources is not a second blocker.

**Consequence for the programme:** `content.source_items` of kind `menu_item`
is 318, all of them from the manual backfill lane. Zero have come from a
connector. Parent invariant #6 — registration is not execution — holds against
the menu lane exactly as it did against `FreshaServiceProjector` before slice
3b. Whoever picks up the driver work inherits `MenuItemProjector` at version 2,
already emitting the `collections` its identity keys need.

### 23.7 Cache, and one warning this slice caused

All three lanes (§9.2) fire from `MenuBackfiller::run()` via
`SiteCacheLanes::bust()`, once per touched site rather than per row.

`CloudflarePurgeService::purgeHandle()` now purges dish pages under all three
addressable forms — legacy uuid, content item id, current slug — because both
menu wires are live and it cannot know which one a consumer built its href
from. That triples the menu contribution, from 300 to 900 URLs per host, and
dev's largest menu now trips the existing volume canary:

```
cloudflare.purge.url_volume_high  handle=fable-sevenrun url_count=941 threshold=900
```

**The threshold is deliberately left alone.** It exists to flag volume nobody
predicted; this volume is predicted and written into the service's own
derivation. Raising it to silence a true signal would retire the canary for
the next real jump. Slice 7 removes the legacy third and takes it back under
the line on its own — **listed as a slice 7 expectation, not a standing
alarm.**

### 23.8 Logs and performance

`cloud env:logs partna development --minutes 45`: no exceptions, no failures,
every `CloudflareCachePurgeJob` DONE. Two warnings, both accounted for — the
purge-volume canary above, and one `slow_public_profile` (ollies, 1644ms)
which is a cold-cache rebuild after this run's own purge. Warm reads measured
straight after:

```
ollies (65 dishes)          301-535ms
fable-sevenrun (156 dishes) 215-336ms
```

No new Nightwatch issues; the cold-rebuild pattern maps to existing #386, the
same finding Phase 3 recorded.

### 23.9 Tests

Full suite `8260 passed, 1 skipped, 28529 assertions` on the merge commit.
Postgres lane `207 passed / 959 assertions` (run against a throwaway
`postgres:16` — the local `.env` DB_HOST is a dead ref). PHPStan clean across
`app/`. Pint clean.

New: `MenuProjectionMapperTest` (12), `MenuBackfillerTest` (19),
`MenuSlugLaneTest` (10), `MenusPoolTest` (13), `MenuItemProjectorTest` (6),
plus three `--connector` cases on `SourceProvisionerTest`.

### 23.10 What slice 7 inherits

- **The menu tables are NOT dropped.** Both wires run side by side.
- `MenuItemObserver`'s slug duty is re-homed onto
  `ContentItemSlugAllocator::SLUGGED_KINDS`, and removal now frees a slug via
  `ManualServiceWriter::markRemoved()`. Retiring the observer is safe;
  retiring the const entry is not.
- `CloudflarePurgeService`'s dish lookup reads both lanes — drop the legacy
  third with the table.
- The 11 legacy `item_type='event'` rows in `site.item_slugs` are untouched
  and remain slice 7's to delete.
- `content.item_merges` is still 0, so §8.3's hard-delete path is unexercised.

### 23.11 A kickoff premise that was false

The slice-4 kickoff lists as a non-negotiable: *"The k6 harness hard-codes
menu invariants (`scripts/launch-check/k6/`). If this slice changes menu
shape, re-check `seed.sql` and `jobs.js`."*

**It does not.** A case-insensitive search for `menu`, `dish` or `food` across
every `.js`, `.sql` and `.md` file under `scripts/launch-check/k6/` returns
nothing. CLAUDE.md's own list of the harness's three hard-coded invariants is
gallery-max-6, the `media_variants` webp row, and the analytics `Origin`
header — none of them menu-related. The kickoff generalised from that list.
Corrected in the kickoff in place.
