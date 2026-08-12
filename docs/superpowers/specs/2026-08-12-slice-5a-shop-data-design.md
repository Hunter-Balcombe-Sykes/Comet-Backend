# Slice 5a — the shop data move

Sub-slice of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 5 — Shop →
`content.*` · L". Slice 5 is cut in two; this is the first half.

- **5a (this spec)** — the storefront/product data model, the backfill, and
  repointing every shop write and read at `content.*`. The legacy tables stop
  being touched. **Nothing public changes.**

- **5b (later spec)** — the `shop` pool, the `shop` page, and the public wire
  change that retires the legacy shop keys from `/integrations`. Needs
  partna-monorepo to move in step.

**Why the split, decided 2026-08-12 with the owner.** Scoped as one slice this
was a new table, a backfiller, 14 endpoint repoints, the sync rewrite, pool
registration, page provisioning, a public wire change and a frontend contract
change — larger than slice 4, which the parent sizes XL. Slice 1 was cut the same
way on the same day for the same reason. 5a is verifiable end to end with SQL
against dev; 5b is the half that cannot be un-shipped quietly.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-12

Every figure below was read from the database while writing this spec, per
convergence invariant #1. Where it contradicts the parent spec or the slice 5
kickoff prompt, the correction is stated and **dev wins**.

```
site.shop_products                      51
site.shop_brands                         9
DISTINCT brand_id in shop_products       9
content.source_items kind='product'      0
content.items        kind='product'      0
content.item_variants                    0     ← this slice is its first user
content.collections / collection_items   0 / 0 ← likewise
content.offers                          14     (13 free + 1 from; availability NULL on all)
content.sources                         29 connection + 6 manual
ingest.sources                          14 source_keys — NO gumroad row exists
site.pages                              gallery 12, watch 10, listen 10, events 4, library 1
                                        — no `shop` page exists
```

### 1.1 The blob is uniform, and it is Shopify-shaped

All 51 `site.shop_products.data` rows carry the same 14 keys, with no variation:

`available` · `createdAt` · `currency` · `description` · `handle` · `image` ·
`images` · `price` · `productId` · `title` · `url` · `variantId` · `variants` ·
`vendor`

```
variants total          285   (32 of 51 products are multi-variant; max 13)
  of which placeholder   17   single variant titled "Default Title"
images total            321
products unavailable     16
price format            51/51 match ^[0-9]+(\.[0-9]{1,2})?$ — no odd formats
distinct productId       33   over 51 rows
distinct url             34   over 51 rows
duplicate (user, url)     0   ← §1.7's poisoned-key hazard does not bite here
```

The kickoff prompt frames the blob as the low-risk half because "nothing
relational can be lost". That is true of its *shape* and false of its *volume*:
285 variants and 321 images are relational structure living inside the blob, and
they are the bulk of the rows this slice writes.

### 1.2 Three brand columns are dead or dormant, and the kickoff's table is stale

`site.shop_brands` is **25 columns, not 20**. The kickoff's table omits
`products_curated_at` — the one that is actually load-bearing — plus `name`,
`currency`, `source_url`, `position`, `favicon`, `logo`, `logo_mark_url`,
`logo_mark_svg_url`.

| Column | Dev reality | Verdict |
|---|---|---|
| `selection_mode` | `'manual'` on all 9 — the column default | **Dead.** `ShopFetch`'s docblock records this as #SEM-1: the default *is* `'manual'` and `addBrand()` never sets it, so it can never distinguish "curated" from "never touched". The live signal is `products_curated_at` |
| `link_mode` | product 5 / checkout 4 | **Dormant** since 2026-07-08. It became one global `site.sites.shop_link_mode`, and `PublicIntegrationConnectionResource:342,376` stamps every brand's public `linkMode` from that single value. The per-brand column is not read on the public path |
| `style_analysis` | non-null on 4 | **Dead.** `grep -rn style_analysis app/` returns nothing — neither writer nor reader survives. Orphaned data from a removed code path |
| `products_curated_at` | set on 1 of 9 | **Live.** `ShopFetch:48` skips these brands; `ShopController:722` stamps it |
| `referral_query` | `''` on all 9 | **Live mechanism, no data.** Revenue-affecting by design; nothing at risk today |
| `discount_code` | set on 4 | Live |
| `is_individual` | `false` on all 9 | Live — `ShopFetch:47`, `StoreBrandSeeder:117` store cap, `ProcessShopBrandLogoJob:53` |
| `fetch_mode` | NULL on all 9 | Live — `ShopCatalog::providerProducts()` dispatches on `'client'` |
| `connect_status` | NULL on all 9 | Live — gates the public wire (`PublicIntegrationConnectionResource:360` rejects `'pending'`) |

**The correction that propagates.** The kickoff tells this slice, and tells
slices 3 and 4, that `selection_mode` is "the per-brand equivalent of
`auto_sync_latest`". It is not, and has never been. The per-brand fact is
`products_curated_at`; the global gate is `display_settings.auto_sync_latest` on
the connection.

`site.sites.shop_auto_latest` **is dropped** — the 2026-08-05 migration into
`display_settings` did land. But `display_settings` is NULL on all 6 shop
connections and `ShopFetch:38` treats absent as ON, so auto-latest is on for
every dev user.

### 1.3 The scheduled sync is actively rewriting this data

With auto-latest ON for all 6 connections and 8 of 9 brands uncurated,
`ShopFetch` → `ShopCatalog::syncLatest()` re-syncs on schedule, and its
implementation (`ShopCatalog:119-129`) is:

```php
ShopProduct::where('brand_id', $brand->id)->delete();
foreach ($latest as $index => $productData) { ShopProduct::create([...]); }
```

**`site.shop_products.id` is therefore not an identifier.** It is a fresh uuid
every sync cycle. Parent §8.1's `manual:{legacy_uuid}` coord convention cannot be
used here — see §3.3.

### 1.4 `GumroadProductProjector` is not this lane, and has no source

Parent §7 opens slice 5 with "`GumroadProductProjector` exists". It does, and it
is irrelevant. It reads `name`, `price_cents`, `pay_what_you_want`, `rating`,
`thumbnail`; the blob carries `title`, `price` (a **string**), `currency`,
`images`, `variants`. Not one field name matches.

There is also no `gumroad` row in `ingest.sources` at all — 14 source keys, none
of them Gumroad. Invariant #6 in its strongest form: no source, no stream, no
records. **Nothing in this slice runs through a projector.** Every write is
owner-authored and goes through the slice-0b manual lane.

### 1.5 The consequence for §8.3's regression test

Parent §8.3 requires every backfill slice to "run the relevant connector *after*
the backfill and assert the rows survive", because `mergeInto()` hard-deletes a
discarded item carrying neither a pin nor an override.

**For `product` there is no connector to run.** The honest equivalent — and the
thing that actually rewrites this data — is a `syncLatest()` run. This slice
tests that instead, and says so rather than constructing a synthetic
product-kind connector that would prove nothing about the real write path.

---

## 2. Scope boundary

**In 5a:** `content.storefronts`, the collections mapping, `ShopBackfiller`, the
`syncLatest()` reconcile rewrite, the seeders, and all 14 `/platforms/shop/*`
endpoints repointed at `content.*` with byte-identical request and response
shapes.

**Not in 5a:** `PoolRegistry` entry, `site.pages key='shop'` provisioning, any
change to `GET /api/public/profiles/{handle}` or `/integrations`. The public
Shop page keeps rendering from the legacy read path in
`PublicIntegrationConnectionResource` for the whole of 5a.

**Not dropped by 5a:** `site.shop_brands` and `site.shop_products` survive as
tables. After this slice nothing writes them and nothing but the public shop read
path reads them. Slice 7 drops them.

Migration filename prefix block, pre-assigned by parent §4.3 rule 5:
`20260813100000`–`20260813109999`.

---

## 3. The change

### 3.1 `content.storefronts`

A 1:1 sidecar keyed by `collection_id`.

```
collection_id        uuid PK, FK → content.collections(id) ON DELETE CASCADE
provider             text NOT NULL
url                  text
source_url           text
currency             text
discount_code        text
referral_query       text NOT NULL DEFAULT ''
is_individual        boolean NOT NULL DEFAULT false
fetch_mode           text
connect_status       text
connect_error        text
products_curated_at  timestamptz
logo_url             text
favicon_url          text
logo_mark_url        text
logo_mark_svg_url    text
created_at / updated_at  timestamptz NOT NULL DEFAULT now()
```

**Why a sidecar and not columns on `collections`.** `content.collections` is the
generic grouping table, and slices 3 and 4 will use it for service categories and
menu categories. A menu category needs none of these fifteen fields. Putting them
on `collections` makes every category row carry fifteen permanently-null shop
columns. The schema already uses 1:1 sidecars for exactly this (`f_link`,
`f_catalog`, `f_rated` hang fields off an item without widening `content.items`);
`storefronts` is the same pattern applied to a collection for the first time.

**Why not `f_catalog`.** Parent §7 leaves the choice open as "`f_catalog` or
collections". `f_catalog` is a *music* facet — `release_type`, `track_number`,
`disc_number`, `isrc`, `gtin`, `sku`, 33 rows, all Bandcamp — and it is keyed
`(item_id, source_id)`, so it cannot describe a store at all. Only `sku` and
`gtin` are product-relevant, and this slice uses them for that (§3.2). **Decided:
collections + a storefronts sidecar; `f_catalog` for product identifiers only.**

Store logos stay text URLs rather than becoming `content.media_assets` rows.
`ProcessShopBrandLogoJob` already writes owned R2 URLs into these four columns,
so there is nothing to mirror and no dimension to measure; routing them through
the asset spine would be work with no consumer.

### 3.2 The product mapping

Each `site.shop_products` row becomes one `content.items` row, `kind='product'`.

| Blob key | Destination |
|---|---|
| `title` | `items.headline_cache` |
| `url` | `f_link.url` — **bare and uncomposed**, see §3.7 |
| `price` + `currency` | one `offers` row: `amount_minor`, `currency`, `qualifier` |
| `available` | that offer's `availability` |
| `variants[]` | one `item_variants` row each (label = `title`, position = array index) **plus** one `offers` row each carrying that variant's own price and availability, keyed by `variant_label` |
| `image` | `item_media` `role='cover'`, position 0 |
| `images[]` | `item_media` `role='gallery'`, position 1..n |
| `productId` | `f_catalog.sku` |
| each variant's `id` | that variant's `item_variants.sku` |
| `handle`, `vendor`, `description` | `items.facets_cache` |
| `createdAt` | `items.first_seen_at` |

**Prices parse as integer minor units, never through a float.** Every one of the
51 rows matches `^[0-9]+(\.[0-9]{1,2})?$`, so `"200.00"` → `20000` by string
split. Float arithmetic on money is how a cent goes missing.

`offers.qualifier` is `'exact'`, or `'free'` when the amount is zero — both
inside `offers_qualifier_check` (`exact|from|upto|range|free|variable|on_request`),
verified against the live constraint. `'from'` is deliberately not used: a
Shopify list price is the price, not a floor.

**Zero means free here, and that was checked rather than assumed.** Slice 3
raised (2026-08-12) that the same rule is false for services: all 61 Fresha rows
carry `price_cents = 0` and none is free, because
`FreshaServiceProjector:374` coerces an unparsed value to `0`
(`is_numeric($v) ? … : 0`). Shop has no such fallback —
`ShopifyScraper:201` reads `$variant['price'] ?? null`, so an unknown price
stays **null**. Dev agrees: 0 products priced at 0, min 25.00, max 589.00, and
no variant priced at 0. The discriminator is the source, not the integer.

**A null price yields no offer row, deliberately.** `minorUnits()` returns null
for anything that does not match `^[0-9]+(\.[0-9]{1,2})?$`, and the mapper emits
no offer for it — inventing `amount_minor = 0` would publish "Free" on a product
whose price merely failed to parse, which is exactly slice 3's failure mode. The
consequence for §5.1's "every product has an offer" query is stated there.

`offers.availability` has **no precedent** — it is NULL on all 14 existing rows
and carries no CHECK. This slice establishes `'in_stock'` / `'out_of_stock'`,
schema.org's ItemAvailability shorthand. Recorded here because slices 3 and 4
will meet the same empty field.

**The 17 placeholder variants are not written.** Shopify emits a single variant
titled `"Default Title"` for a product with no options; 17 of the 51 carry
exactly that. Writing them would put 17 rows labelled "Default Title" in a table
whose whole purpose is to name a choice. They are skipped, counted, and reported.
The other 2 single-variant products carry real titles and are kept. Expected
`item_variants` ≈ 268; the exact figure is whatever the run reports, and §8.4's
gate is coord coverage, not a predicted count.

`item_media.role` uses only `cover` and `gallery`, both inside
`item_media_role_check` (`cover|gallery|poster|avatar|logo`), verified live.

### 3.2a `ProjectionWriter` learns `variants` — a shared hot-path change

**Found while planning, 2026-08-12.** `content.item_variants` holds 0 rows not
because nothing has used it but because **nothing can write it**.
`ProjectionWriter::writeFacets()` (`:1010-1112`) assembles exactly three
collection tables from a projection — `item_media`, `offers`, `item_tags` — and
`item_variants` appears nowhere in the file. The manual lane inherits that
limitation, so §3.2's variant mapping is unimplementable as the code stands.

This slice adds it, following the existing shape exactly: a `variantsByItem`
accumulator beside the other three, a `$variantRows` builder, and an
`'item_variants'` entry in the `$tables` map so it gets the same
delete-by-`(item_id, source_id)`-then-insert treatment inside the same
per-chunk transaction.

**Two traps this must not spring:**

- `eligible_cache` is composed from a presence scan over
  `['item_media', 'offers', 'item_tags', 'f_action']` (`:1321`), and the
  comment at `:1310-1313` states that **declaration order is part of the cached
  value (I9)**. `item_variants` is therefore appended at the END of that list,
  never inserted among the existing entries, or every item's cached value
  changes shape.
- This is the projection hot path for **every** connector, not just shop. The
  change is additive — a projection with no `variants` key produces an empty
  array and writes nothing — but it earns its own task, its own tests, and a
  note in every remaining slice's prompt under §7.

### 3.3 The coord rule — URL-derived, not uuid-derived

```
manual:{sha1(canonical_product_url)}
```

**This diverges from parent §8.1 deliberately.** §8.1 prescribes
`manual:{legacy_uuid}` "preserving the legacy identifier after the table is
dropped". For shop that identifier does not exist: §1.3 shows `syncLatest()`
deletes and re-inserts every row each cycle, so a uuid-keyed coord would mint a
fresh item on every sync and orphan the previous one.

The URL is the stable identity, and keying on it satisfies §1.7's
one-coord-per-canonical-URL-per-user rule by construction rather than by
discipline. Dev is clean for it: 0 duplicate `(user_id, url)` pairs across all 51
rows. The 17-18 duplicates are cross-user, and `content.items` is user-scoped, so
they do not interact.

**Slices 3, 4 and 7 must know this**, because §8.1 as written would send them the
other way for any legacy table whose rows are rewritten rather than updated.

### 3.4 `ShopBackfiller`

`app/Services/Migration/ShopBackfiller.php` plus an artisan command, per
convergence invariant #4 — production code, tested, idempotent, re-runnable,
`--dry-run`, counts reported. Writes through
`ProjectionWriter::writeManualItem()`, never raw. `MediaUploadBackfiller` (slice
1a, merged) is the shape to follow.

- Scope: every `site.shop_brands` row and its `site.shop_products` children.
- `user_id` derived through `platform_connections.user_id`, failing loudly on a
  connection with no owner (parent §8.2).
- Brand → `collections` row (`label` = `name`, `is_user_created = false`,
  `position` = brand position) + `storefronts` row. Collection identity is keyed
  on `(user_id, provider, url)` so a re-run updates rather than duplicates.
- Product → the §3.2 mapping, plus a `collection_items` row at the product's
  `position`.
- Idempotency is by coord for items and by `(user_id, provider, url)` for
  collections. Two runs produce one set.

### 3.5 Repointing the write lane

`ShopCatalog::syncLatest()` stops writing `site.shop_products` and writes
`content.*` through the manual lane. **It must not port its delete-then-insert
literally.** That would destroy and re-mint `content.items` ids every sync,
breaking `analytics.item_views` references and any pin.

It reconciles instead:

- upsert by coord for every product in the fetched set;
- `content.items.removed_at` set for coords no longer present;
- **never** `content.source_items.removed_at` — that field is cleared on
  reappearance (`ProjectionWriter:272-275`), which would resurrect a product the
  owner removed;
- **never** a hard delete.

`ShopProductSeeder` and `ShopController::addProduct()` follow the same lane, and
the `'individual'` bucket becomes a collection with `is_individual = true`
carrying its existing `MAX_INDIVIDUAL_PRODUCTS = 20` cap.

`ShopFetch`'s `whereNull('products_curated_at')` gate follows the column to
`storefronts`. Its auto-latest gate on `display_settings.auto_sync_latest` is
unchanged — that lives on the connection and this slice does not touch it.

### 3.6 Repointing the 14 dashboard endpoints

Every route under `/platforms/shop/*` — `brands`, `addBrand`, `connectStatus`,
`updateBrand`, `removeBrand`, `brandProducts`, `catalog`, `setProducts`,
`addProduct`, `removeProduct`, `selection`, `settings`, `updateSettings`,
`forget` — keeps its **exact** request and response shape and swaps its backing
store to `content.*`. Partna-App needs no change, and that is the acceptance
criterion: a response diff against the pre-change branch must be empty.

Two are not simple swaps:

- **`catalog`** reads the live store catalogue through `ShopCatalog::
  providerProducts()` and the per-brand picker cache. That catalogue was never
  stored in these tables and is untouched by this slice.
- **`removeBrand` / `forget`** delete rows today. Against `content.*` they set
  `items.removed_at` and delete the collection; the item rows survive, per the
  same never-hard-delete rule as §3.5, so analytics keep resolving.

### 3.7 What is dropped, and it is named as a regression

- `selection_mode` — nothing reads it (§1.2). No behaviour change.
- `link_mode` per-brand — dormant since 2026-07-08 (§1.2). The global
  `site.sites.shop_link_mode` survives untouched and keeps driving the public
  `linkMode`. No behaviour change.
- `style_analysis` — no reader, no writer, 4 rows of orphaned data. **This is
  the one real loss**: the data cannot be regenerated. It is dropped because
  carrying an unreferenced jsonb blob into a new table to preserve the output of
  deleted code is not preservation, it is sediment.

**`f_link.url` stays bare, and the URL composition moves to the backend at read
time — in 5b.** Today the backend ships the ingredients (`linkMode`,
`referralQuery`, `provider`, product `url`) and the monorepo sitepage's
`productHref()` composes the outbound link, including the Shopify checkout deep
link from `variantId`. Owner decision 2026-08-12: that composition becomes a
backend read-time concern so referral revenue is testable here, and
`productHref()` retires. 5a therefore stores the bare URL and changes no
composition; **5b owns the move and the frontend coordination it needs.**

---

## 4. Cache invalidation — all three lanes

The backfiller and every repointed write path are raw-write seams. Per parent
§9.2, copying `PoolController::poolChanged()`:

| Lane | Action | Why bumping alone is not enough |
|---|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` | this is what it is for |
| 60s public-profile payload cache | touch `site.sites.updated_at` | `IndividualProfilePayloadBuilder::cacheKey()` composes the key from `updated_at`; `bump()` writes a different table, so the stale payload is served for the full TTL |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | the CDN outlives the origin write |

There is no CI check that a raw-write seam bumps — parent §9.1 established that
`BuildState`'s own docblock claims one and it does not exist. The tests below
assert all three directly.

---

## 5. Verification

### 5.1 Live dev assertions, output pasted into the checkpoint

```sql
-- the move
SELECT count(*) FROM content.items WHERE kind='product' AND removed_at IS NULL;   -- 51
SELECT count(*) FROM content.item_variants;                                       -- ~268
SELECT count(*) FROM content.offers;                                              -- 14 + 51 + variants
SELECT count(*) FROM content.collections;                                         -- 9
SELECT count(*) FROM content.storefronts;                                         -- 9
SELECT count(*) FROM content.collection_items;                                    -- 51

-- §8.4 coverage gate: every legacy row traceable to a live coord (expect 0)
SELECT count(*) FROM site.shop_products p
WHERE NOT EXISTS (
  SELECT 1 FROM content.source_items si
  WHERE si.coord = 'manual:' || encode(digest((p.data->>'url')::bytea, 'sha1'), 'hex')
    AND si.removed_at IS NULL);
-- digest() needs pgcrypto; verified available on dev 2026-08-12.

-- money survived: every product has at least one offer.
-- Expect 0 — but this is an INVESTIGATE signal, not a hard gate. A product
-- whose price does not parse legitimately produces no offer (§3.2), and all
-- 51 dev rows currently parse. A non-zero result means a price shape changed
-- upstream, which is worth knowing; it does not mean the backfill is wrong.
SELECT count(*) FROM content.items i WHERE i.kind='product'
  AND NOT EXISTS (SELECT 1 FROM content.offers o WHERE o.item_id = i.id);  -- 0

-- legacy is dead: nothing wrote it after the cutover timestamp
SELECT max(updated_at) FROM site.shop_products;
SELECT max(updated_at) FROM site.shop_brands;
```

The coverage gate is coord coverage, **not** row-count equality (parent §8.4).
51 legacy rows are expected to remain 51 items only because no connector unions
against them; the gate is written so a future collapse is visible rather than a
failure.

### 5.2 Pest

- price `"200.00"` → `20000` minor; `"0"` → qualifier `free`; no float anywhere
  in the path
- a 13-variant product mints 13 `item_variants` and 13 variant offers; a
  `"Default Title"` singleton mints **zero** variants and is counted as skipped
- `cover` from `image`, `gallery` from `images[]`, positions ordered
- brand → collection + storefront; `referral_query`, `discount_code`,
  `products_curated_at`, `connect_status`, `is_individual`, `fetch_mode` all
  round-trip
- the backfiller is idempotent across two runs — item ids, collection ids and
  offer counts identical
- **the §8.3 regression, adapted:** `syncLatest()` runs *after* the backfill and
  (a) no `content.items.id` changes for a product still in the catalogue,
  (b) a product dropped from the catalogue gets `items.removed_at` and **not**
  `source_items.removed_at`, (c) nothing is hard-deleted
- each of the 14 endpoints returns a response identical to the pre-change branch
- every write path bumps build state, moves `sites.updated_at`, and dispatches a
  purge

### 5.3 Postgres, not SQLite

Tests run SQLite; production is Postgres. Verified against the live constraints,
not against a green suite:

- `items_kind_check` — 14 kinds, `product` is among them
- `offers_qualifier_check` — `exact|from|upto|range|free|variable|on_request`;
  this slice emits `exact` and `free`
- `item_media_role_check` — `cover|gallery|poster|avatar|logo`; this slice emits
  `cover` and `gallery`
- `content.item_variants.label` is **NOT NULL** — the reason a placeholder
  variant is skipped rather than written with a null label
- `content.collections.kind` carries **no CHECK** — free text; this slice writes
  `'storefront'`
- the new `storefronts.collection_id` FK cascade: deleting a collection must not
  orphan a storefront row

### 5.4 Post-deploy

`cloud env:logs partna development --minutes 10`, clean. Nightwatch checked —
slice 0's checkpoint records a log scan performed and a Nightwatch scan skipped;
do not repeat that gap.

---

## 6. Definition of done

51 products and 9 stores represented in `content.*` with every brand behaviour
either migrated into `storefronts` or named in §3.7 as dropped; ~268 variants and
their offers populated; the coverage gate returning 0; `syncLatest()` running
after the backfill without changing an item id or hard-deleting a row; all 14
dashboard endpoints byte-identical; `site.shop_products` / `site.shop_brands`
written by nothing; checkpoint and wire manifest committed.

`site.shop_products` and `site.shop_brands` are **not dropped** — that is slice 7.
The public Shop page is **unchanged** — that is 5b.

---

## 7. Conventions this slice establishes

Recorded here because other slices are told to reuse them.

| Convention | Value | Who needs it |
|---|---|---|
| Coord for a legacy table whose rows are *rewritten* | `manual:{sha1(canonical_url)}`, not `manual:{uuid}` | slices 3, 4, 7 |
| `offers.availability` vocabulary | `in_stock` / `out_of_stock` | slices 3, 4 |
| Collection-scoped behaviour | a 1:1 sidecar table, not columns on `collections` | slices 3, 4 |
| `SECTION_SHAPE` for priced, undated items | `rule: [kind_is]`, `order_by: 'recency'` | **slice 4 explicitly**, and 5b |
| Owner-chosen ordering | pins in `site.section_items`, which carry position; the auto half has only `alphabetical` / `occurrence` / `recency` and cannot express it | slice 4, 5b |
| `LATEST_TAG_POOLS` membership for commerce | **excluded** | slice 4, 5b |
| `ProjectionWriter` writes `item_variants` (§3.2a) | new — a projection may now carry a `variants` key | **every remaining slice**, it is shared hot-path code |

**Why shop is excluded from `LATEST_TAG_POOLS`**, since the kickoff asks for the
argument either way: the selection is hand-ordered, so a Latest badge fights the
owner's own ordering; pool recency is `last_seen_at`, a sync artefact rather than
product newness; and 16 of 51 dev products are unavailable, so the badge can land
on a sold-out item. The case *for* — "newest product" is genuinely meaningful
where "latest service" is not — is real but is served by the store's own site,
not by a badge on a curated affiliate list.

**Why pins carry the ordering.** `SectionCandidates:105-116` offers exactly three
orderings and none is "the order the owner chose", while `shop_products.position`
is precisely that. Pinned `site.section_items` rows carry their own position, and
`SectionCandidates:119` excludes already-pinned ids from the auto half, so there
is no duplication. It also satisfies §8.3 for free: `mergeInto()`'s
`hasCuration` check is `exists in site.section_items OR content.manual_overrides`,
so a pinned product cannot be hard-deleted by a merge. **5b provisions those
pins; 5a writes no sections.**

---

## 8. Out of scope — carried to 5b

- `PoolRegistry` entry (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`, `SECTION_SHAPE`),
  `site.pages key='shop'` provisioning, and pins at each product's position
- Backend read-time composition of the outbound product URL from `link_mode` +
  `referral_query`, and retiring the monorepo's `productHref()`
- The public wire change: `profile.pools.shop` arriving and the legacy shop keys
  leaving `/integrations`, with the collections map the sitepage needs to rebuild
  its store cards
- The wire-change manifest and the partna-monorepo coordination it requires

## 9. Out of scope — carried to slice 7

Dropping `site.shop_brands` and `site.shop_products`, and re-homing the observers
and policies that orphans.
