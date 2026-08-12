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
| `site.menu_items` | 370 | `menu_item` |
| `site.menu_item_categories` | 464 | (collection membership) |
| `site.menu_item_platforms` | 370 | (offers) |
| `site.menu_categories` | 52 | (collections) |
| `site.services` | 82 | `service` |
| `site.service_category_assignments` | 61 | (collection membership) |
| `site.service_categories` | 18 | (collections) |
| `site.shop_products` | 51 | `product` |
| `site.shop_brands` | 9 | collections + a `content.storefronts` sidecar (decided in 5a; **not** `f_catalog`, which is a music facet) |
| `site.content_selection` | 91 | `media` — but see §2.4, it is not uniformly reference-resolved |

`content.f_review` holds 0 rows; Google reviews have never reached the content
schema, blocked by the same driver gap.

### 1.5 What the schema anticipated but never grew

| Table | Rows | Note |
|---|---|---|
| `content.offers` | 14 | `channel`, `variant_label`, `amount_minor`, `qualifier` (`exact\|from\|upto\|range\|free\|variable\|on_request`), `amount_max_minor`, `availability` |
| `content.item_variants` | 0 | label / sku / position |
| `content.item_tags` | 186 | tag + tag_type |
| `content.collections` / `collection_items` | 0 / 0 | grouping — menu and service categories |
| `content.sources` `kind='manual'` | **0** | The unique index exists. **Nothing has ever written a row.** See §1.7 |

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

### 4.1 Status — 2026-08-12

| # | Slice | Size | Status | Hard blocker |
|---|---|---|---|---|
| 0 | Billed-effect driver seam | M | **Merged** — checkpoint §13, plus #MONEY-1/2 fixes | — |
| 0b | Manual-source write lane | M | **Merged** — checkpoint §12 | — |
| 2 | The events pool (`event` only) | M | **Merged** — checkpoint §14 | — |
| 1a | Media asset spine + upload lane | L | **Merged** — live on dev | — |
| 1b | Google pass-through, IG mirroring, the 91 selections | L | **Merged** — checkpoint §15 | — |
| 3 | Services → `content.*` | L | Not started | — |
| 4 | Menus → `content.*` | XL | Not started | — |
| 5a | Shop data move → `content.*` | L | Spec written 2026-08-12 — `2026-08-12-slice-5a-shop-data-design.md` | — |
| 5b | Shop pool + public render | M | Kickoff written, spec not started | 5a **merged** + partna-monorepo |
| 6 | Reviews → `content.*` | M | Not started | — |
| 7 | Legacy teardown | M | Not started | 1b, 3, 4, 5, 6 **+ frontend + standalone events** |

With 0, 0b and 2 merged, the blocker graph has almost emptied: **3, 4, 5 and 6 are
unblocked now.** Only 1b (needs 1a merged) and 7 are gated.

**Slice 7 gained a dependency revision 2 did not anticipate.** Slice 1a's scope
boundary keeps the legacy `gallery` / `designMedia` wire keys, because the frontends
still read them. Retiring those keys is therefore blocked on Partna-App's Media page
and the monorepo gallery render — work outside this programme's backend-only mandate.

**Slice 7 gained a second one from slice 2: standalone events.** See §7 slice 7's
"Carried from slice 2". Slice 2 retired the ACCOUNT half of the legacy events lane
but deliberately left STANDALONE `resource_kind='event'` rows publishing through it,
so that lane cannot be dropped yet.

### 4.2 Execution order

**Merge 1a → { 1b · 3 · 5a } concurrent → 6 → 4 → 5b → 7.**

**Revised 2026-08-12:** slice 5 split into 5a (data) and 5b (pool + public
render). 5a keeps 5's original concurrency slot — it is `product`-kind and
touches no shared read path, so §4.3 rule 1 still holds. 5b is sequenced late
because it is the only remaining slice that needs partna-monorepo to move in
step, and slice 7's teardown gate does not depend on it.

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

### Slice 7 — Legacy teardown · M
Drop the ten tables in §1.4. Re-home the orphaned observers and policies (§9).

**Gate:** the §8.4 coverage gate green on dev for every migrated type. Irreversible —
Supabase is on the Free plan with no PITR and no managed backups.

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
`MenuItemObserver` maintains `site.item_slugs` for `menu_item`, retaining old slugs
as 301 redirects (`ItemSlugAllocator` handles `event` and `menu_item`).
`content.item_slugs` exists as the destination and holds 0 rows. Dropping
`site.menu_items` without migrating slugs **breaks every existing dish permalink and
its redirect**. Slice 4 owns this.

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
