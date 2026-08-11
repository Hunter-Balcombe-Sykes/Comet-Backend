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

### 1.7 The manual lane does not exist

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
| 2 | Orphan kinds get pools (`event`, `channel`, `article`) | S | — | first |
| 3 | Services → `content.*` | L | 0b | after 2 |
| 4 | Menus → `content.*` | XL | 0b | after 5 |
| 5 | Shop → `content.*` | L | 0b | after 3 |
| 6 | Reviews → `content.*` | M | 0 | — |
| 7 | Legacy teardown | M | 1–6 | last |

**Execution order: 2 → 0 → 0b → 1 → 3 → 5 → 4 → 6 → 7.**

Sizes revised upward from revision 1 (slices 3 and 5 from M, 4 from L, 7 from S)
because §1.7 removed the assumption that a manual write lane exists, and §7.3 added
per-backfiller work that revision 1 did not account for.

Deliberate ordering choices:

- **Slice 2 first.** Its items already exist in `content.items`, so it needs no
  connector, no driver and no migration — the cheapest proof that adopting a kind
  into the pool lane works, and a template every later slice copies.
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

### Slice 2 — Orphan kinds get pools · S
`PoolRegistry` entries, `PAGE_KEYS`, `PAGE_LABELS`, section provisioning for
existing users, and read paths for `event`, `channel`, `article`.

**Done when:** the 22 existing rows are reachable through a pool and rendered, and
the legacy events lane has no readers.

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

### 9.1 `BuildState` raw-write registry
`app/Site/Documents/BuildState.php:15-19`: `bump()` is called from Eloquent
observers for modelled tables **and explicitly at every raw-write seam, with a CI
check keeping the list current**. Every backfiller is a raw-write seam. Without a
bump, migrated content never rebuilds the public document; without registration, CI
fails.

### 9.2 Cache invalidation
CLAUDE.md: any write path bypassing Eloquent "MUST invalidate the affected cache
keys explicitly; it will not be caught by an observer." Ten backfillers, all raw.
§2.2 also changes the *content* of every cached public profile payload. Each slice
must name the keys it invalidates and whether a Cloudflare/KV purge is required.

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
