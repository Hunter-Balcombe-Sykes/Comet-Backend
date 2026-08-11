# Content pool convergence — design (2026-08-11)

Converge every content type onto `content.*` as the single store of record, retire
the parallel `site.*` item tables, and finish the pool architecture the 2026-08-05
programme started.

**Execution is backend-only.** Wire changes are permitted and expected; each is
recorded in a manifest for the Partna-App and partna-monorepo teams to follow.
Owner decision, 2026-08-11: this is a foundational change and the frontends will
be told, not designed around.

Supersedes the scope statement in `docs/2026-08-05-platforms-as-sources.md` §"Phase 1
design" line 84 ("unifying them onto content.* is future work"). That document
remains the decision record for pool semantics; **its P4 checkpoint claim that the
programme is complete is false and is corrected here.**

---

## 1. Verified current state

All figures read from dev (`glncumufgaqcmqhzwrxm`) on 2026-08-11.

### 1.1 What works

`content.items` holds 477 rows and the watch/listen pools serve them live:

| Kind | Rows | Pool | Sources |
|---|---|---|---|
| `release` | 219 | `listen` | Apple Music, Bandcamp, YouTube Music |
| `video` | 130 | `watch` | YouTube, Vimeo, Twitch |
| `episode` | 106 | `listen` | Apple Podcasts |

Their connectors run on schedule — YouTube, Spotify, Bandcamp, Twitch, Vimeo,
Fresha, Substack, Eventbrite and Humanitix all have provisioned streams,
`auto_sync = true`, and a `last_run_at` within the last three days.

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

> **Correction to a prior working note:** an earlier draft of this analysis stated
> that `CostClass::Metered` exempts Google Business from the blocker. It does not.
> `CostClass` governs budget weight and whether `SourceProvisioner` enables
> `auto_sync`; it has no bearing on driver dispatch. Google needs an `'api'` driver
> and Instagram an `'actor'` driver, and neither exists. Any plan sizing the Google
> lane as "find out why it never ran" is mis-scoped.

### 1.3 What is orphaned

Three kinds accumulate rows that nothing reads, because `PoolRegistry` has no entry
for them:

| Kind | Rows | Written by |
|---|---|---|
| `event` | 14 | Eventbrite, Humanitix |
| `channel` | 7 | Twitch, Skool, Strava |
| `article` | 1 | Substack |

`ProjectorRegistry` returns `null` for an unmapped stream by design — "this stream
projects to no item" is a legitimate state — so an unread kind never errors. The
write half is loudly tested; the read half is only tested where a pool exists.

### 1.4 What still runs on legacy tables

Deliberately out of scope in 2026-08-05, in scope here:

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
| `site.content_selection` | 91 | `media` (reference-resolved, nothing copied) |

`content.f_review` holds 0 rows; Google reviews have never reached the content
schema, blocked by the same driver gap.

### 1.5 The schema already anticipated all of it

These exist today and are unused or barely used:

| Table | Rows | Purpose |
|---|---|---|
| `content.offers` | 10 | `channel`, `variant_label`, `amount_minor`, `qualifier` (`exact\|from\|upto\|range\|free\|variable\|on_request`), `amount_max_minor`, `availability` |
| `content.item_variants` | 0 | label / sku / position |
| `content.item_tags` | 186 | tag + tag_type |
| `content.collections` / `collection_items` | 0 | grouping — menu and service categories |
| `content.sources` `kind='manual'` | — | one-per-user unique index, for owner-authored items |

`site.menu_items` carries `base_price`, `pickup_price`, `pickup_source`,
`delivery_price`, `delivery_source` as flat columns. That is precisely three
`content.offers` rows with `channel ∈ {base, pickup, delivery}` and a real
`source_id`. The legacy table is the denormalised special case of what `offers`
expresses generically.

---

## 2. End state

`content.*` is the single store of record for every synced or owner-authored item.

`site.*` retains only what is not an item: `sites`, `design_kits`, `blocks`,
`customers`, `enquiries`, `workplaces`, `site_subdomain_aliases`, `pages`,
`sections`, `section_items`, and — in a demoted role — `site_media` and
`media_variants`.

### 2.1 The media boundary

`site_media.pool` currently does two unrelated jobs under one column. For
`gallery` and `content` it means "which curated surface does this appear on" — a
genuine pool. For `design` and `documents` it means "what kind of thing is this" —
a type tag wearing a pool's name. The convergence splits it along that seam.

| Today | Disposition |
|---|---|
| `site_media` pool=`gallery` (16) | → `content.items` kind=`media`, manual source. **In the media pool.** |
| `site_media` pool=`content` — the `designMedia` wire key (9) | → same. **In the media pool.** |
| `site_media` pool=`design` — `logo_full` 4, `logo_square` 6 | **Stays.** A singleton keyed by purpose, one per site. No library, no selection, no ordering. |
| `site_media` pool=`documents` (2) | **Stays.** `content.items` has a `document` kind; that is a separate surface and a later decision. |
| `site.media_variants` (80) | **Stays**, demoted to the byte and processing layer. |

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
| `content.media_assets` | 1 | `storage_path` → ready variant, `variant_family='native'`, dims, palette |
| `content.items` kind=`media` | 1 | **the curatable thing** — pins, excludes, sort order, overrides, Latest tag |

An Instagram photo takes the identical four-row shape, differing only in
`content.sources.kind` and whether `media_assets` carries `source_url` or
`storage_path`. That sameness is the feature: one library, one selection, one read
path, regardless of origin.

Accepted cost: `media_assets` re-states `width`, `height`, `palette`, `mime_type`
from `site_media`. The alternative — pointing `item_media` at `site_media` via a new
nullable column — avoids the copy but forks the read path, requiring two joins and a
coalesce on every pool read. The upload projector populates these from `site_media`
at `ready` time, so there is exactly one writer.

### 2.2 Required change to PoolResolver

`app/Site/Pools/PoolResolver.php:253-263` reads covers as
`content.media_assets.source_url`, and `cover()` at :333 returns the first non-empty
`source_url`. For an upload, `source_url` is null and `storage_path` is set. Both
must become: resolve `source_url`, else serve `storage_path` through the media disk
resolver. This is on the hot path for every pool read and every public profile
render.

### 2.3 Identity and fingerprint namespaces

`content.media_assets` has `UNIQUE (user_id, fingerprint)`. Fingerprints are
**namespaced by prefix, with no cross-namespace dedupe**:

| Origin | Fingerprint |
|---|---|
| Upload | `sha256:{content_hash}` — from `site.media_variants.content_hash` |
| Instagram | `instagram:{shortcode}:{index}` |
| Google Places | `places:{photo_ref}` |

Dedupe stays *within* a namespace, which is what the constraint actually protects:
a re-sync must not double-insert the same photo. An upload and an Instagram copy of
the same picture produce two assets deliberately — the bytes differ (original versus
re-encoded CDN copy), the storage differs, and the lifecycle differs, so
disconnecting Instagram must never mutate an asset backing an upload. Where two
items genuinely need unifying, `content.item_merges` is the mechanism: item-level,
auditable, reversible.

---

## 3. Invariants

Each is a failure mode observed in the 2026-08-05 programme.

1. **No slice is done without a live database assertion.** Every definition of done
   includes the SQL that proves it, run against dev, with output pasted into the
   checkpoint. "Grab-all live since P1" survived four phases because the claim was
   never a query.

2. **A kind is not adopted until something reads it.** Projector, `PoolRegistry`
   entry, and read path land in the same slice or none of them do. Registering a
   producer without a consumer is what created the 22 orphan rows in §1.3.

3. **Legacy deletion is its own slice, always last, always gated on the replacement
   being live-verified.** The Posts pool was deleted because Media was *believed*
   live. Slice 7 touches nothing until slices 1–6 have their assertions on record.

4. **Backfill is production code, not a throwaway script.** Under
   `app/Services/Migration/`, tested, idempotent, re-runnable. Prod has no users
   today (`core.users` = 0), so this code's real audience is the first user who does.

5. **No slice may cite another slice's checkpoint as evidence for its own claims.**
   That transitivity is how a false claim propagated through four phases unchallenged.

---

## 4. Programme decomposition

Seven slices, each independently shippable and independently verifiable.

| # | Slice | Size | Hard blocker | Sequencing preference |
|---|---|---|---|---|
| 0 | Billed-effect driver seam | M | — | — |
| 1 | Media pool live (Instagram + Google + uploads) | L | 0 | after 2 |
| 2 | Orphan kinds get pools (`event`, `channel`, `article`) | S | — | first |
| 3 | Services → `content.*` | M | — | after 2 |
| 4 | Menus → `content.*` | L | — | after 5 |
| 5 | Shop → `content.*` | M | — | after 3 |
| 6 | Reviews → `content.*` | M | 0 | — |
| 7 | Legacy teardown | S | 1–6 | last |

A **hard blocker** cannot be worked around; a **sequencing preference** exists to
build competence in the cheapest place first and may be reordered if circumstances
change. Only slices 1, 6 and 7 are genuinely blocked.

**Execution order: 0 → 2 → 1 → 3 → 5 → 4 → 6 → 7.**

Two deliberate deviations from the obvious sequence:

- **Slice 2 before slice 1.** Its items already exist in `content.items`, so it needs
  no connector, no driver and no migration — the cheapest possible proof that
  adopting a kind into the pool lane works, and a template every later slice copies.
  Slice 1 then becomes the second time the pattern is executed, not the first.
- **Shop (5) before menus (4).** `shop_products` is a `data jsonb` blob with no
  relational structure to preserve, making it the low-fidelity-risk commerce
  migration. Menus carry multi-category membership, per-platform pricing and
  `is_manual` authorship, and should follow a commerce type that has already landed.

Slices 1–7 each get their own spec → plan → implement cycle. Writing seven detailed
designs now would guess at decisions slice 0's results should inform.

---

## 5. Slice 0 — billed-effect driver seam (detailed)

### 5.1 What already exists

`EffectLedger::once()` implements claim-first, charge-once-by-digest, freshness
bucketing and abandoned-claim reconciliation. `HttpIo::effect()` computes the digest
and calls it. Both target services exist:

- `App\Services\Platforms\GoogleBusinessService::fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array`, with `PlacesBudget` already injected.
- `App\Services\Platforms\Actors\InstagramActorAdapter`, with `ApifyProfileScraperAdapter` and `FigueProfileScraperAdapter` implementations.

The only missing piece is dispatch.

### 5.2 The change

```php
namespace App\Ingest\Runtime\Effects;

interface BilledEffectDriver
{
    public function supports(string $kind, string $name): bool;

    /** @param BilledEffectContext $ctx */
    public function run(BilledEffectContext $ctx): mixed;
}
```

`BilledEffectContext` is a readonly value object carrying `kind`, `name`, `input`,
`runId`, `sourceId`, `userId`.

A container-resolved `BilledEffectDriverRegistry` holds the registered drivers.
`HttpIo::runBilledEffect()` becomes a registry lookup. **An unmatched `(kind, name)`
keeps today's throw** — a connector declaring an effect nobody implements must still
fail loudly.

Two drivers register:

| Driver | `(kind, name)` | Delegates to |
|---|---|---|
| `PlacesDetailsDriver` | `('api', 'places.details')` | `GoogleBusinessService::fetchPlaceDetails()` |
| `InstagramActorDriver` | `('actor', 'instagram')` | the configured `InstagramActorAdapter` |

The driver is invoked from inside the `once()` closure, exactly where the throw is
today, so claim-first and charge-once are preserved by construction. No other part
of `EffectLedger` changes.

### 5.3 Decisions

**`HttpIo` gains a `userId` constructor parameter.** Both drivers need it —
`PlacesBudget` meters per user, Instagram enforces a per-user cooldown — and
`HttpIo`'s constructor currently has only `manifest`, `fetcher`, `ledger`, `runId`,
`sourceId`. `RunExecutor` has already loaded the source row and passes it. The
alternative, having drivers re-query `ingest.sources` by `sourceId`, puts a database
read inside the billed closure for data the caller already held.

**A null result settles as success.** `fetchPlaceDetails()` returns `?array`; null
means the place was not found. Google charged us regardless, so this settles `ok`
with a null result and the connector's existing `is_array($place)` check yields
`Unavailable`. Treating it as driver failure would leave the ledger believing the
charge did not happen.

**Budget refusal deletes the claim.** `once()` claims a row before invoking the
driver and refuses on *any* existing row, because on a dead claim "the vendor may
have charged us". Budget refusal is the one case where we know with certainty no
money moved, because the refusal precedes the call. Left as-is, a refusal would
masquerade as a spent effect and block that digest until `reconcileAbandoned` swept
it.

Therefore: a driver refusing on budget throws `EffectRefused`; `once()` catches that
specific type and **deletes** the claim row, leaving the digest immediately
retryable. Any other throwable retains today's behaviour — the row stays, because we
cannot prove money did not move. Budget knowledge stays inside the driver; the
ledger learns exactly one new exception type.

Rejected alternative: checking budget in `HttpIo::effect()` before claiming. It
would leak per-vendor budget knowledge into the transport and duplicate it across
every future driver.

**Kill switch.** `config('partna.ingest.billed_effects_enabled')`, default **false**,
per environment. Landing the seam must not begin spending on the next
`ingest:dispatch` tick. Places is the only uncapped paid API in the stack. When
false, `runBilledEffect()` throws as it does today.

### 5.4 Verification

- Unit: registry dispatch by `(kind, name)`; unmatched kind still throws; kill switch off ⇒ throws.
- Ledger: a budget refusal leaves no blocking row and the digest is immediately retryable; a genuine failure leaves the row claimed.
- Integration: `PlacesDetailsDriver` returning null settles `ok` with null data, and the connector yields `Unavailable`.
- Dev assertion: one manual Google Business run produces rows in `ingest.effects`, a table that has held zero rows since creation.

```sql
SELECT kind, cost_tag, status, count(*) FROM ingest.effects GROUP BY 1,2,3;
```

- Post-deploy: `cloud env:logs partna development --minutes 10`, clean.

### 5.5 Out of scope for slice 0

Provisioning streams for Instagram and Google Business, projecting `kind='media'`,
and any pool or wire change. Slice 0 ends when a billed effect can execute and is
ledgered. Slice 1 consumes it.

---

## 6. Slices 1–7 — scope and definition of done

Each gets its own spec before implementation. Stated here so the programme's shape
is fixed and no slice quietly absorbs another's work.

### Slice 1 — Media pool live
Provision `media` streams for Instagram and Google Business; project `kind='media'`;
add the upload projector so `site_media` pools `gallery` and `content` become items
on the manual source; apply the §2.2 `PoolResolver` change; retire the
`site.content_selection` reference lane and the `gallery` / `designMedia` wire keys.

**Done when:** one dev account's media pool contains uploads, Instagram photos and
Google photos in a single library with a single selection, rendered on the public
profile; `content.items WHERE kind='media'` is non-zero; `site.content_selection`
has no remaining readers.

### Slice 2 — Orphan kinds get pools
`PoolRegistry` entries, page keys and read paths for `event`, `channel`, `article`.
No connector or migration work.

**Done when:** the 22 existing rows are reachable through a pool and rendered, and
the legacy events lane has no remaining readers.

### Slice 3 — Services → `content.*`
`FreshaServiceProjector` already exists. Backfill `site.services` (82),
`service_categories` (18) → `collections`, `service_category_assignments` (61) →
`collection_items`. Pricing → `offers` with `qualifier='from'` where applicable.
Soft-delete → `items.removed_at`.

### Slice 4 — Menus → `content.*`
`MenuItemProjector` covers DoorDash, Square and Uber Eats. Backfill 370 items, 52
categories, 464 multi-category links, 370 platform rows. `base/pickup/delivery`
prices → `offers` rows keyed by `channel`. `is_manual` → manual source. `badges` →
`item_tags`; `rating` / `rating_count` → `f_rated`.

### Slice 5 — Shop → `content.*`
`GumroadProductProjector` exists. Decompose `shop_products.data` jsonb into `items`,
`offers`, `item_variants`, `item_media`. `shop_brands` → `f_catalog` or collections,
decided in that slice's spec.

### Slice 6 — Reviews → `content.*`
Depends on slice 0. `GoogleBusinessReviewProjector` exists. Must preserve the
`when_unclaimed` redaction scope for reviewer-identifying fields (`author`,
`author_uri`, `author_photo`) declared in `GoogleBusinessConnector`'s
`Manifest::$redactionScopes`, and honour the outstanding LEGAL-2 obligation.

### Slice 7 — Legacy teardown
Drop `site.menu_items`, `site.menu_categories`, `site.menu_item_categories`,
`site.menu_item_platforms`, `site.services`, `site.service_categories`,
`site.service_category_assignments`, `site.shop_products`, `site.shop_brands`,
`site.content_selection`.

**Gate:** the §7 fidelity test green on dev for every migrated type. Irreversible —
Supabase is on the Free plan with no PITR and no managed backups.

---

## 7. Backfill architecture

One backfiller per type under `app/Services/Migration/`, behind a common interface,
each driven by an artisan command supporting `--dry-run` and reporting counts.

**Idempotency** comes from `content.source_items.coord` — the
`platform:account_ref:external_key` triple that already exists so a reconnect does
not read as mass deletion followed by mass creation. A backfill assigning the same
coord the connector would assign is re-runnable for free and converges with the
connector rather than fighting it.

**Owner-authored rows** take the manual source and a `manual:{legacy_uuid}` coord,
preserving the legacy identifier's traceability after the table is dropped.

**Fidelity gate.** Before slice 7 drops anything, a test asserts row counts *and*
field-level equality between each legacy table and its `content.*` projection.
Slice 7 is a no-op until that test is green on dev.

**Structural note.** `content.items` is scoped by `user_id`; menus are scoped
`menu_item → menu → site`. Partna is individual-only, so user↔site is effectively
1:1 and this resolves — but each backfiller must derive `user_id` through the site
explicitly rather than assume, and fail loudly on a site with no owner.

---

## 8. Verification and communication

**Per-slice checkpoint** appended to this document, carrying:

1. The SQL run against dev, with output pasted in.
2. Pest test names proving connector → projector → item → pool → wire.
3. `cloud env:logs partna development --minutes 10` scan result.

**Wire-change manifest.** `docs/wire-changes/YYYY-MM-DD-<slice>.md` — endpoint,
before shape, after shape, consuming repo. Appended by any slice changing public or
dashboard JSON. This is the artifact handed to the frontend teams.

**Testing note.** Tests run SQLite while production is Postgres. Every
constraint-bound write in this programme — the `items_kind_check` 14-kind CHECK, the
`offers.qualifier` CHECK, the `item_media.role` CHECK, the
`media_assets_fingerprint_unique` constraint — must be verified against the DDL in
`supabase/migrations/`, not merely against a green suite.

---

## 9. Documentation debt discharged by this spec

`docs/2026-08-05-platforms-as-sources.md` closes with "The program is complete".
That sentence is false and has already mis-scoped downstream work. It must be
amended in place to state that Media never shipped, with a pointer here — as long as
it reads complete, every future session starts from a false map.
