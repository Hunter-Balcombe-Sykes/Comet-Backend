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
| `site.shop_brands` | 9 | (`f_catalog` / collections) |
| `site.content_selection` | 91 | `media` — but see §2.4, it is not uniformly reference-resolved |

`content.f_review` holds 0 rows; Google reviews have never reached the content
schema, blocked by the same driver gap.

### 1.5 What the schema anticipated but never grew

| Table | Rows | Note |
|---|---|---|
| `content.offers` | 10 | `channel`, `variant_label`, `amount_minor`, `qualifier` (`exact\|from\|upto\|range\|free\|variable\|on_request`), `amount_max_minor`, `availability` |
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

Eight slices, each independently shippable and independently verifiable.

| # | Slice | Size | Hard blocker | Sequencing preference |
|---|---|---|---|---|
| 0 | Billed-effect driver seam | M | — | — |
| 0b | Manual-source write lane | M | — | before 1 |
| 1 | Media pool live (Instagram + Google + uploads) | XL | 0, 0b | after 2 |
| 2 | The events pool (`event` only — see §7) | M | — | first |
| 3 | Services → `content.*` | L | 0b | after 2 |
| 4 | Menus → `content.*` | XL | 0b | after 5 |
| 5 | Shop → `content.*` | L | 0b | after 3 |
| 6 | Reviews → `content.*` | M | 0 | — |
| 7 | Legacy teardown | M | 1–6 | last |

**Execution order: 2 → 0 → 0b → 1 → 3 → 5 → 4 → 6 → 7.**

Sizes revised upward from revision 1 (slices 3 and 5 from M, 4 from L, 7 from S)
because §1.7 removed the assumption that a manual write lane exists, and §7.3 added
per-backfiller work that revision 1 did not account for. Slice 2 revised S → M on
2026-08-12 for the reasons in §7 — the rule and payload changes, plus a data repair
revision 2's row counts concealed.

Deliberate ordering choices:

- **Slice 2 first.** Its items already exist in `content.items`, so it needs no
  connector, no driver and no migration — the cheapest proof that adopting a kind
  into the pool lane works, and a template every later slice copies. **Cheapest is
  not cheap** (revised 2026-08-12): the pool's auto-rule and item payload both turn
  out to be shaped for undated content, and adopting a dated kind changes each. That
  is a finding worth having first, since menus, services and shop all carry prices
  and several carry dates.
- **Shop (5) before menus (4).** `shop_products` is a `data jsonb` blob with no
  relational structure to preserve. Menus carry multi-category membership,
  per-platform pricing and `is_manual` authorship.

Slices 1–7 each get their own spec → plan → implement cycle.

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
  (`:238-248`) and carries its own per-user cooldown
  (`partna.instagram.apify_cooldown_seconds`) and daily cap — a second refusal path
  with different semantics from Places'.

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
| `PlacesDetailsDriver` | `('api', 'places.details')` | `GoogleBusinessService::fetchPlaceDetails()` |
| `InstagramActorDriver` | `('actor', 'instagram')` | `InstagramScraper` |

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

This preserves retryability *and* the money-adjacent audit trail. Revision 1's
success criterion — "a budget refusal leaves no blocking row" — was satisfiable by a
system that had destroyed the evidence, and `reconcileAbandoned()`'s docblock is
explicit that the ledger "NEVER sets settled_at, never deletes".

Because `fetchPlaceDetails()` can throw its budget exception mid-loop, the
`PlacesDetailsDriver` must **not** map `PlacesBudgetExhaustedException` to
`BudgetRefused` unconditionally. It maps only when no attempt has fired; otherwise
it is a genuine failure and the claim stays. Slice 0 either passes an
attempt-counter out of the service or performs its own pre-flight
`PlacesBudget::claim()` and treats any in-loop throw as failure. Recommended: the
latter — it needs no change to `GoogleBusinessService`.

**A null result is disambiguated, never blanket-settled as `ok`.**
`fetchPlaceDetails()` returns null for four different reasons, three of which
involve no charge:

| Cause | Site | Outcome |
|---|---|---|
| Missing API key | `:211-214`, before any HTTP | `NoAnswer` |
| Network exception on every attempt | `:250` | `NoAnswer` |
| Non-2xx (429 / 5xx outage) | `:262` | `NoAnswer` |
| Genuine not-found | mapped response | `Answered`, data null |

This matters because `partna.ingest.effect_freshness_seconds` defaults to **604800
— seven days**. Settling a Places outage as `ok`+null would cache "no data" for that
digest for a week, and `verdictFor()` (`:139-141`) would replay it as
`['status'=>'ok','result'=>null,'cached'=>true]`. A missing key in an environment
would settle *every* place as permanently-ok-null. Only `Answered` settles `ok`;
`NoAnswer` settles `failed` and is retryable.

**Kill switch.** `config('partna.ingest.billed_effects_enabled')`, default **false**,
per environment. Its purpose is *activation gating*, not budget safety — `PlacesBudget`
(global, per-SKU and per-user daily caps) and `ApifyBudget` (global and per-actor
caps) already bound spend, and `ingest:dispatch` only claims `auto_sync = true`
sources, which Instagram and Google are not. The switch exists because `production`
deploys on push, so slice 0 can reach prod weeks before slice 1 intends paid fetching
to be live there. It must also gate `SourceProvisioner`'s stream provisioning, or
streams get provisioned and dispatched against a switch that only throws at the last
moment, burning run rows and `ingest.anomalies` every tick.

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

### Slice 5 — Shop → `content.*` · L
`GumroadProductProjector` exists. Decompose `shop_products.data` jsonb into `items`,
`offers`, `item_variants`, `item_media`. `shop_brands` → `f_catalog` or collections,
decided in that slice's spec.

### Slice 6 — Reviews → `content.*` · M
Depends on slice 0. Must preserve the `when_unclaimed` redaction scope for
reviewer-identifying fields (`author`, `author_uri`, `author_photo`) declared in
`GoogleBusinessConnector`'s `Manifest::$redactionScopes`, and honour the outstanding
LEGAL-2 obligation.

### Slice 7 — Legacy teardown · M
Drop the ten tables in §1.4. Re-home the orphaned observers and policies (§9).

**Gate:** the §8.4 coverage gate green on dev for every migrated type. Irreversible —
Supabase is on the Free plan with no PITR and no managed backups.

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
- an explicit re-confirmation that `auto_sync` stays `false` for both billed
  connectors (it was `false` on all three google_business sources when read
  during the pre-flight)

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

- the section/page-count assertions (Task 8 Step 5) were not re-run after the
  deploy
- the wire manifest still quotes the pre-backfill slug mapping ("12 current
  event slugs, 9 mapped, 3 unmapped"); dev now holds 14 minted slugs across
  all event items, so those figures should be re-measured before the manifest
  is handed to the frontend teams

**The known regression is CLOSED (`1197052f8`).** `removeEvent()` previously
wrote only `hiddenEventIds`, which the pool does not read — so every hide made
after the one-shot migration would have silently failed. It now mirrors the
hide into a section exclude via `EventExcludeSync`, sharing the
`EventsPayload::id()` hash rule with the migration command so the two cannot
drift. Three regression tests cover it.

**Accepted, per owner decision 2026-08-12:** three legacy event permalinks
have no content item to map to (two never imported, one a standalone row that
lands none) and their URLs stop resolving. Dev only, no customers.

**Carried to a later slice:** standalone `resource_kind='event'` rows still
publish through the legacy integrations wire, because they land no content
item. The manual write lane (slice 0b) now exists to hold them, but pointing
the Tickets & Events card at it is its own change with its own wire impact.
Until then a frontend Events page reads BOTH sources.

Its plan file `docs/superpowers/plans/2026-08-11-content-pool-slice2-events.md`
is likewise still untracked.

### 14.6 Section numbering

§12 (slice 0b) was written before §13 and §14 because it was the first
checkpoint any slice recorded. The numbers are labels, not an ordering — the
programme order remains 0 → 0b → 2.
