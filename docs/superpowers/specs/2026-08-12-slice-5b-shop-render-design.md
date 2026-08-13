# Slice 5b — the shop pool and the public render

Second half of `2026-08-11-content-pool-convergence-design.md` §7 "Slice 5".
Slice 5 was cut in two on 2026-08-12; 5a (`2026-08-12-slice-5a-shop-data-design.md`)
moved the data and is merged.

- **5a — merged** (`9e5bf3a6a`, deployed 2026-08-13). 51 products and 9 stores in
  `content.*`, a `content.storefronts` sidecar, 14 endpoints repointed. Nothing
  public changed shape.
- **5b (this spec)** — the `shop` pool, the `shop` page, the outbound-URL
  composition moving to the backend, and retiring the legacy shop keys from
  `/integrations`.

This is the half that cannot be un-shipped quietly: it changes a CDN-cached
public wire that partna-monorepo reads today.

---

## 1. Verified state — dev (`glncumufgaqcmqhzwrxm`), 2026-08-13

Every figure re-derived from the database while writing this spec, per parent
invariant #1 and rule zero (#5 — no slice cites another's checkpoint). The
right-hand column is what 5a measured; where this spec and dev disagree, **dev
wins and the divergence is stated**.

### 1.1 The entry gate — all values re-measured, all match

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

content.storefronts by provider          shopify 8, woocommerce 1
storefronts with referral_query <> ''    0 of 9
storefronts with discount_code           4 of 9
site.sites.shop_link_mode                'checkout' on all 32 — zero 'product'
```

No gate value is zero. **5a landed.**

### 1.2 Inertness — stronger evidence than the kickoff's two-samples test

The kickoff asks for two reads an hour apart. A better proof was available and
was taken instead: at measurement time `now()` was `2026-08-12 23:42 UTC`, and
the scheduled shop refresh had run at `22:23:20 UTC` — 79 minutes earlier. That
run moved `content.items.last_seen_at` for products to exactly `22:23:20` while
`site.shop_products.updated_at` stayed at `17:24:33`. The live sync lane
demonstrably executed after the 5a deploy and wrote `content.*` only.

### 1.3 Divergence: `site.shop_brands` is NOT inert

The kickoff's non-negotiables and parent §16.9 both state that 5a made
`site.shop_products` **and** `site.shop_brands` inert. Only the first is true.

`ShopController` still writes `site.shop_brands`:

| Site | Write |
|---|---|
| `:317` | `ShopBrand::updateOrCreate(...)` — `addBrand` |
| `:929` | `ShopBrand::firstOrCreate(...)` — the individual bucket |
| `:869` | `ShopBrand::where(...)->delete()` — `forget` |

and `ShopContentWriter::upsertStore(ShopBrand $brand, string $ownerId)` takes the
legacy model as its identity anchor. The controller's own docblock (`:58-61`)
records this correctly — *"site.shop_brands survives as the per-brand
identity/lifecycle anchor this controller still writes"*. The parent spec is what
is wrong. `max(updated_at)` is frozen only because no brand has been added on dev
since 2026-08-12 10:54, not because nothing writes it.

`site.shop_products` **is** genuinely inert: the remaining `ShopProduct::delete()`
calls only clear pre-deploy rows, and nothing creates one.

**Action:** parent §16.9 corrected in place. This does not block 5b — 5b reads
`content.*` — but slice 7's teardown ordering depends on it being true.

### 1.4 Divergence: `shop_link_mode` has no live coverage either

The kickoff flags `referral_query` as untested by dev data (`''` on all 9 stores).
The same is true of link mode: **all 32 sites are `'checkout'`, none `'product'`,
none null.** So neither axis of Unit 4's matrix is exercised by dev data. The
tests are the only proof for the whole composition, not just the referral half.

### 1.5 Divergence: the page-presence gate is deliberately `removed_at`-blind

`PlatformRegistryServiceProvider`'s shop `complete()` closure joins
`collection_items → collections → storefronts` and **deliberately does not filter
`content.items.removed_at`**, with a comment stating that lockstep with the
payload is the requirement, "not correctness-by-a-different-rule".

5b's pool read *does* filter `removed_at`. Left alone, a user whose only products
were retired would get a Shop page that is present but empty. §3.8 resolves this
by making presence pool-derived, which restores lockstep by construction.

### 1.6 The store and product shape, measured

All 9 storefronts carry products; **none is empty**:

| Store | Provider | `external_ref` | Products | Discount |
|---|---|---|---|---|
| Above the Ground | shopify | `75102060779` | 8 | — |
| Above the Ground | shopify | `75102060779` | 8 | `ALEX10` |
| Allbirds | shopify | `11044168` | 8 | `TESTCODE10` |
| Culture Kings | shopify | `10233455` | 8 | — |
| Natalie Anne Haircare | shopify | `11461296187` | 7 | `ALEX10` |
| Above the Ground | shopify | `75102060779` | 4 | — |
| Above the Ground | shopify | `75102060779` | 3 | — |
| Above the Ground | shopify | `75102060779` | 3 | `Dhshs` |
| FEAR NO EVIL | woocommerce | `fearnoevil-com-au` | 2 | — |

Five distinct users hold the nine stores; one holds five of them. `external_ref`
`75102060779` recurs across **five** users — store identity is the provider's id,
item identity is per-user, and the two never collide because `content.items` is
user-scoped.

`connect_status` is NULL on all 9 (no pending store on dev) and `is_individual`
is false on all 9 (**no individual bucket exists yet**, so its 20-item cap is
untested against live data).

Product-side inputs Unit 4 and the payload depend on:

```
products with an f_link row                51 / 51
f_link URLs containing '?'                  0 / 51   ← the '&' branch is untested by dev data
f_catalog.variant_ref populated            51 / 51
f_catalog.handle populated                 51 / 51
f_catalog.vendor populated                 49 / 51
f_text rows / with body (description)      51 / 50
item_variants with sku                    268 / 268
item_variants with image_url                0 / 268  ← column never exercised by real data
offers carrying a variant_label           259
item_media for products                   321  (cover 50, gallery 271), on 50 of 51 items
```

Woo vs Shopify id shapes, checked because §3.5 depends on it: the WooCommerce
store's `variant_ref` and `sku` are the **same** value (`2595`); on Shopify they
differ (`48604305129707` variant vs `9577680797931` product).

### 1.7 Unit 0's premise, verified in code

| Claim | Verified at |
|---|---|
| `upsertSourceItem()` clears only `source_items.removed_at` | `ProjectionWriter:422-423`, `'removed_at' => null`, commented *"delete lives on items.removed_at and is never touched here"* |
| `resolveItems()` never consults `items.removed_at` | `ProjectionWriter:564-571` — filters `si.removed_at` and nothing else |
| `retireAbsent()` can match a stale coord row-wise | `ShopContentWriter:705-711` — `whereNotIn` executes in SQL, `->unique()` in PHP afterwards |
| `buildPools()` loops every `POOLS` key | `IndividualProfilePayloadBuilder:315` |

Dev carries **0** retired products and **0** retired product source-items, so the
defect is latent today and becomes live the moment the pool read lands.

---

## 2. Scope boundary

**In 5b:** the `shop` pool registry entry, `site.pages key='shop'` provisioning
with pins, the re-add decision (§3.3), the `retireAbsent()` fix (§3.4), backend
read-time URL composition (§3.5), the `pools.shop` wire including its collections
map (§3.6), the payload enforcement point (§3.7), and retirement of the legacy
shop keys from `/integrations` with presence moving to the pool (§3.8).

**Not in 5b:** dropping `site.shop_brands` / `site.shop_products` (slice 7);
re-homing `ShopContentWriter` off the `ShopBrand` model (slice 7, per §1.3);
the `f_catalog`/`item_variants` schema, which 5a already shipped.

**No DDL.** Every write lands in existing tables. The pre-assigned migration
prefix block `20260813110000`–`20260813119999` is **not consumed**; it returns to
the pool for whichever slice needs it.

---

## 3. The change

### 3.1 Register the pool

```php
POOLS['shop']          = ['product'];
PAGE_KEYS['shop']      = 'shop';
PAGE_LABELS['shop']    = 'Shop';
SECTION_SHAPE['shop']  = ['rule' => [['op' => 'kind_is']], 'order_by' => 'recency'];
```

Shop is **not** in `LATEST_TAG_POOLS` — 5a §7 argued it both ways and excluded it
(hand-ordering fights a Latest badge; pool recency is `last_seen_at`, a sync
artefact rather than product newness; 16 of 51 dev products are unavailable, so
the badge can land on sold-out stock). `latestItemId` is therefore always `null`.

`order_by` governs only the **unpinned** tail; the owner's ordering is carried by
pins (§3.4).

**Collision with slice 3a.** `feat/slice-3-services` has already added
`services => ['service']` to all four consts with a byte-identical
`SECTION_SHAPE`, and rewrote the docblock sentence to *"Sell / Menu are NOT
here"*. This slice deletes "Menu" from that same sentence. It is a union merge,
not a design conflict. **Whichever merges second re-runs `PoolRegistryTest` and
the provisioning tests after resolving** — a union merge that drops half a const
array still passes every test written by the other branch.

### 3.2 Provision pages, sections and pins for existing users

`PoolSectionProvisioner::ensure()` creates the page and section on demand at
first read, so no backfill is needed for those. **Pins are different** — nothing
creates them, and without them the Shop page renders in recency order rather than
the owner's.

A console command `content:provision-shop-pins` (`--dry-run`, counts reported)
walks every user holding a `kind='storefront'` collection and, for each, ensures
the section then writes one `site.section_items` row per product with
`state='pinned'`.

`site.section_items.sort_key` is a nullable `double precision` — a fractional
drag key, not a composite — so the two-level catalogue order is **flattened into
a dense 1-based sequence**: walk the user's products ordered by
`(collections.position, collection_items.position)` and write
`sort_key = (float) ($index + 1)`. That is byte-for-byte how
`PoolController::reorder()` writes a drag (`:173`), so a pin this command creates
and a pin the owner drags are indistinguishable, and stores order against each
other while products order within their store.

Idempotent: an existing pin for `(section_id, item_id)` is left alone, never
rewritten, which is also what makes §3.4's ordering rule hold.

`ensure()` early-returns on an existing section, so a later `SECTION_SHAPE`
change will not reshape rows this command created — the same trap slices 1a and 2
hit. `ReshapeMediaSectionsCommand` is the precedent if that is ever needed.

### 3.3 A re-added product comes back — the rule, and its narrowing

**Decision (owner, 2026-08-13): `content.items.removed_at` is cleared when a
coord re-enters a live catalogue through an owner-authored write.**

The parent programme rule — *an item whose every source item is retired is itself
retired, and `removed_at` is never cleared by reappearance* — was written against
**connectors**, where a reappearance is a scrape artefact and clearing the flag
would undo a deliberate removal. A shop re-add is not that: it is an explicit
owner act through `addProduct` / `setProducts` / a re-run seeder.

The rule is therefore narrowed rather than broken:

> An **owner-authored** write may clear `content.items.removed_at`. A **connector**
> re-observing an item never may.

Implemented in `ShopContentWriter::syncStore()` as a fourth step, after
upsert-by-coord and collection linking: for every item now linked to this
storefront collection, `UPDATE content.items SET removed_at = NULL, updated_at =
now() WHERE removed_at IS NOT NULL`. Scoped to the items just linked, so it can
never un-retire something outside this catalogue.

**Why it cannot be deferred.** No shop read in 5a filters `removed_at`, so today
the gap is invisible. The pool read filters it. The individual bucket's
`MAX_INDIVIDUAL_PRODUCTS = 20` cap retires the oldest product on **every** add
and `ShopProductSeeder` re-adds by URL, so a permanently-absent product is
reachable within an afternoon of dashboard use — showing normally in the
dashboard while missing from the Shop page, with the cause three layers away.

This is recorded in the parent spec §9.8 as a programme-level narrowing, not only
here, because slices 3, 4 and 6 inherit the same one-way rule.

### 3.4 `retireAbsent()`'s stale-coord match — fixed, not accepted

The `$absent` set is computed by joining `collection_items` to `source_items` and
excluding live coords **row-wise**:

```php
->when($liveCoords !== [], fn ($q) => $q->whereNotIn('si.coord', $liveCoords))
->pluck('ci.item_id')->unique()
```

`whereNotIn` runs in SQL; `->unique()` runs in PHP afterwards. An item carrying
both a `pid:`-derived and a URL-derived coord — a product that gains a URL
upstream — has a stale `source_items` row that matches, so the item is treated as
absent even though a live-coord row exists. Its `collection_items` link is
dropped and, if no other store carries it, it is retired while still in the
catalogue.

The query is inverted. The correct question is *"does this item have **no**
live-coord source item?"*:

```php
->whereNotExists(fn ($e) => $e->from('content.source_items as si2')
    ->whereColumn('si2.item_id', 'ci.item_id')
    ->whereIn('si2.coord', $liveCoords))
```

§3.3's un-retire makes the consequence partly self-healing (the next sync relinks
and un-retires), which is a reason not to treat it as urgent — not a reason to
leave a one-way disappearance in code the pool now renders.

**`retireAbsent()`'s delete-links-FIRST-then-requery ordering is load-bearing and
is preserved.** Reversed, the synced store's own stale link satisfies the "still
linked to a storefront of this user" test and cross-store retirement silently
becomes a no-op. A regression test pins the ordering by asserting the cross-store
case, not merely the single-store one.

### 3.5 The outbound URL moves to the backend

Owner decision 2026-08-12: `content.f_link.url` holds the **bare** product URL and
the composition becomes a backend read-time concern, so referral revenue is
testable in this repo and a link-mode change takes effect on the next payload
build with nothing to re-backfill. `productHref()` in partna-monorepo retires.

**The composition is not reconstructed from convention — it is recovered from
this repository's own history.** `productHref()` lives in a repo not available to
this session, but the contract was specified here when the feature was built:

> `ac462b2d6` (2026-07-07), *"shop: latest-mode auto selection, checkout link-out,
> referral suffix"*: **"link_mode ('product'|'checkout'): rides the public payload
> so the sitepage can deep-link carts (Shopify `/cart/{variant}:1?discount=`, Woo
> `?add-to-cart=`) instead of product pages."**

> `edf71f545` (2026-07-08): **"The `/cart/{variantId}:1?discount=&ref=` checkout
> URL is still built pages-side (`fetch-shopify-selection.ts`) from `variantId` +
> brand fields."**

The rule:

```
mode = site.sites.shop_link_mode ?? 'checkout'

product  mode                        → f_link.url                          (bare)
checkout + shopify     + variant_ref → {storefront.url}/cart/{variant_ref}:1
checkout + woocommerce + variant_ref → {f_link.url}?add-to-cart={variant_ref}
checkout + anything else             → f_link.url                          (fallback)

then append, in this order, each with `?` if the URL carries no query yet and
`&` if it does — so a missing discount never leaves the referral with a stray `&`:

    discount={discount_code}     when non-empty
    {referral_query}             when non-empty — in BOTH modes
```

Worked examples, all four separator cases:

| mode / provider | discount | referral | Result |
|---|---|---|---|
| checkout / shopify | — | — | `https://store/cart/123:1` |
| checkout / shopify | `ALEX10` | — | `https://store/cart/123:1?discount=ALEX10` |
| checkout / shopify | — | `ref=abc` | `https://store/cart/123:1?ref=abc` |
| checkout / shopify | `ALEX10` | `ref=abc` | `https://store/cart/123:1?discount=ALEX10&ref=abc` |
| checkout / woo | — | `ref=abc` | `https://store/product/x/?add-to-cart=2595&ref=abc` |
| product / any | — | `ref=abc` | `https://store/products/x?ref=abc` |

`referral_query` is stored as a whole `key=value` pair (e.g. `ref=abc`), capped at
500 chars — `ShopController::referralQueryFrom()` and `UrlParamExtractor::extract()`
both pin that shape — so it is appended as a query fragment, not as a fixed `ref`
param. The commits' `ref=` is the July form, before 2026-07-25 generalised the
key.

Woo's `?add-to-cart` takes `variant_ref`, which for the one Woo store on dev is
the same value as `sku` (§1.6). Shopify's differ, and the variant id is the
correct one there.

**Four judgement calls, named here because history does not settle them:**

1. Empty `discount` / referral are **omitted**, not emitted as bare `?discount=`.
   Functionally identical, and an empty param on an affiliate URL invites a
   truncation bug downstream.
2. `referral_query` applies in **`product` mode too**. It is affiliate
   attribution, not a checkout artefact; omitting it in product mode would drop
   revenue on every non-checkout site. Revenue-safe reading.
3. Squarespace / BigCartel / generic fall back to the product URL in checkout
   mode — no deep-link form is documented for them, and dev has none.
4. Param order is discount then referral, matching `edf71f545`.

**Which storefront when an item is in two.** A URL-derived coord is not
store-scoped (5a §3.3), so one product URL listed by two of a user's stores is one
`content.items` row. The composing storefront is chosen by lowest
`collections.position`, tie-broken on `external_ref` — the same ordering
`ShopContentReader::brandMap()` already uses, so the dashboard and the wire agree.

**Batched, never per row.** This sits behind the 60s payload cache on the public
hot path. Four set-wide reads are added to `PoolResolver::itemPayloads()` —
collections+storefronts by item, `f_catalog` by item, `item_variants` by item, and
the single-flight-cached popularity ranks (§3.6) —
and the existing `offers` query changes from `keyBy('item_id')` to
`groupBy('item_id')` so **one** fetch serves both the cheapest-per-item price and
the per-`variant_label` variant prices. All of it is gated on the resolved item
set actually containing a `product`, so watch / listen / media / events pay
nothing.

### 3.6 The wire

The envelope stays uniform — `items` + `latestItemId` on every pool — and gains
one additive sibling key:

```jsonc
"pools": {
  "shop": {
    "items": [ { /* poolItem */ "collectionIds": ["<uuid>"], "variants": [ … ] } ],
    "latestItemId": null,
    "collections": {
      "<collection-uuid>": {
        "externalRef": "75102060779",
        "provider": "shopify",
        "url": "https://abovetheground.co",
        "name": "Above the Ground",
        "currency": "AUD",
        "favicon": "…", "logo": "…",
        "discountCode": "ALEX10",
        "position": 0
      }
    }
  }
}
```

**Keyed by collection UUID, not by the legacy brand id.** A menu category (slice
4) has no external ref, so the uuid is what makes this map reusable. Each object
carries `externalRef` so partna-monorepo can map its existing brand-keyed render
onto the new map mechanically — and because `external_ref` recurs across users
(§1.6) while the uuid does not, the uuid is the only safe key.

**`collectionIds` is plural because it must be**, not for future-proofing: one
item genuinely belongs to two collections when a user lists one product URL in two
stores.

**Four additive keys on every pool item**, following the precedent slice 2 set
when it added eight — nullable off-kind so the shape never varies by kind:

| Key | Type | Source | Null / empty when |
|---|---|---|---|
| `description` | string \| null | `f_text.body` | no body (1 of 51 on dev) |
| `vendor` | string \| null | `f_catalog.vendor` | absent (2 of 51 on dev) |
| `variants` | list | `item_variants` + variant-labelled `offers` | no variants |
| `collectionIds` | list | `collection_items` | not in a collection |

Plus **`frames` is extended to `kind='product'`** — same shape, empty→populated,
no new key. Without it the retirement drops 271 gallery images (§1.6), because
`PoolResolver` currently ships `frames: []` for every kind but `media`.

Each variant object: `label`, `sku`, `price` (same `{amountMinor, amountMaxMinor,
currency, qualifier}` shape the item carries), `availability`, `imageUrl`.
**`imageUrl` is unverified against real data** — `item_variants.image_url` is
populated on 0 of 268 dev rows, so it round-trips in tests only.

**`popularityRank` is populated for products — found while planning, 2026-08-13.**
`PoolResolver` hardcodes `'popularityRank' => null` for every item, but the legacy
shop wire populated it from `analytics.content_popularity_scores`
(`content_type='shop_product'`, keyed by product **handle**, per
`ShopBrand::toBrandArray():135`). Dev holds **34 scored `shop_product` rows**, so
retiring the legacy keys without carrying this drops live computed data to null.

It is carried rather than named as a loss: `f_catalog.handle` is populated on 51
of 51 products, so the join is exact, and a wire change that silently discards
computed data is expensive to notice and to restore. `PoolResolver` reads the
ranks through the same single-flight cache `PublicIntegrationController` uses
(`CacheKeyGenerator::sitePopularityRanks`, CCG-102 — that read used to hit
Postgres on every request), gated on the item set containing a product so no other
pool pays for it. Every other kind keeps `null`, unchanged.

**`url` carries the composed outbound href for products.** That is what makes
`productHref()` retire literally: the sitepage clicks `item.url` and needs no shop
logic at all. `links[]` continues to carry the bare per-platform links, so the
canonical product URL is still available and the referral suffix appears in
exactly one place.

**Deliberately off the wire, and this is a privacy improvement:** `referralQuery`
and `linkMode` leave the public payload entirely — the affiliate suffix stops
being publicly readable now that composition is backend-side. `sourceUrl`
(re-scrape input) and `connectStatus` stay private — but `connectStatus`
**no longer filters anything.**

**Corrected 2026-08-13 (owner ruled against implementing the parenthetical
above as written).** The legacy `/integrations` presence gate filtered
`connect_status = 'pending'` out, in lockstep with a payload that also
rejected pending brands. That payload is retired in this slice and the pool
read has no notion of connect status at all — so the filter's only reason to
exist (keeping two now-nonexistent things in agreement) is gone with it.
Pending stores are no longer filtered from presence or render; see §3.8.

The `collections` map carries only collections referenced by a **selected** item.
No dev store is empty (§1.6), so this has no live effect today; it is stated so
the rule is not discovered later.

### 3.7 The enforcement point

`SHOP_PRODUCT_ALLOWLIST` (#API-1) exists because `ShopProduct.data` is raw scraper
output, so whatever a fetcher chose to store reached unauthenticated visitors. The
pool payload needs an equivalent, but the mechanism cannot be copied:
`array_intersect_key` filters a **blob**, and pool items are built from typed
columns.

Two parts:

1. **Explicit construction only.** Every payload — item, store object, variant
   object — is built by naming each key. No spread of a DB row, no
   `(array) $row`, anywhere in the pool read. This is where a blob would
   otherwise creep back in, since `variants` and `collections` are the first
   nested collections the pool payload has carried.
2. **A wire-shape test pinning the exact key sets** of pool item, store object and
   variant object against named consts, failing on **additions as well as
   removals**. That is strictly stronger than the legacy list, which could only
   ever catch keys it happened not to list.

Recorded for slices 4 and 6, which inherit the payload shape.

### 3.8 Retiring the legacy shop keys, and presence

**Owner decision 2026-08-13: retire in this slice, following the slice 2
precedent** — *"Backend-only execution; the frontends are told, not designed
around"* (slice 2 wire manifest, owner decision). Slice 2 emptied
`eventbrite` / `humanitix` / `events-custom` the same way.

- `SHOP_BRAND_ALLOWLIST` and `SHOP_PRODUCT_ALLOWLIST` are removed, and
  `filterPayload()`'s `shop` branch returns `[]`.
- **The envelope survives** — `resourceId`, `payload`, `lastRefreshedAt` — so a
  consumer iterating `platforms` sees no shape change, only an empty payload.
  Identical to what slice 2 did.
- `withShopBrands()` / `withShopLinkMode()` and the controller's `brandMap()` call
  on the public path go with it.
- **Shop-page presence becomes pool-derived**, via `PoolResolver::hasSelection()`,
  exactly as events did. This also resolves §1.5: presence and pool then run the
  same arithmetic, so lockstep holds by construction rather than by a comment
  asking two queries to stay wrong in the same way.

**The risk, stated plainly.** Every Shop page renders empty from the moment this
deploys until partna-monorepo reads `profile.pools.shop`. That repo is not
available to this session and its readiness cannot be verified here. This is the
owner's accepted trade, taken with the slice 2 precedent in view; the wire
manifest states it as a required consumer action, not a suggestion.

---

## 4. Cache invalidation — all three lanes, asserted directly

Per parent §9.2. There is **no CI check** that a raw-write seam bumps, despite
`BuildState`'s docblock claiming one (parent §9.1) — so the tests assert each lane
directly rather than trusting the practice.

| Lane | Action | Why bumping alone is not enough |
|---|---|---|
| `site.site_documents` build state | `BuildState::bump($siteId)` | this is what it is for |
| 60s public-profile payload cache | touch `site.sites.updated_at` | `IndividualProfilePayloadBuilder::cacheKey()` composes the key from `updated_at`; `bump()` writes a different table |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` | the CDN outlives the origin write |

Applies to `content:provision-shop-pins` and to §3.3's un-retire path.

---

## 5. Verification

### 5.1 Live dev assertions, output pasted into the checkpoint

```sql
-- provisioning
SELECT count(*) FROM site.pages    WHERE key='shop';
SELECT count(*) FROM site.sections WHERE key='pool:shop';
SELECT count(*) FROM site.section_items si
  JOIN site.sections s ON s.id=si.section_id
 WHERE s.key='pool:shop' AND si.state='pinned';        -- expect 51

-- every pool:shop section carries the corrected rule
SELECT rule, order_by FROM site.sections WHERE key='pool:shop';

-- §8.4 coverage gate: coord coverage, NOT row-count equality (expect 0)
SELECT count(*) FROM site.shop_products p
WHERE NOT EXISTS (
  SELECT 1 FROM content.source_items si
  WHERE si.coord = 'manual:' || encode(digest((p.data->>'url')::bytea,'sha1'),'hex')
    AND si.removed_at IS NULL);

-- retirement stays clean
SELECT count(*) FROM content.items WHERE kind='product' AND removed_at IS NOT NULL;

-- legacy still inert (shop_products only — see §1.3 for shop_brands)
SELECT max(updated_at) FROM site.shop_products;
```

### 5.2 A real `PoolResolver` call — SQL cannot stand in

The composition happens in PHP, so SQL cannot prove it. `PoolResolver::resolve()`
is invoked against a dev account holding products, and the resolved payload is
pasted into the checkpoint: item count, the `collections` map, and at least one
`url` showing a composed checkout deep link.

### 5.3 Pest — the matrix, because dev data proves nothing

Dev is `checkout` on all 32 sites and `referral_query = ''` on all 9 stores, and
no product URL contains `?`. **The tests are the only exercise this logic gets:**

- `product` mode → bare URL, in every provider
- `checkout` + shopify + `variant_ref` → `/cart/{ref}:1`
- `checkout` + shopify, `variant_ref` missing → bare URL
- `checkout` + woocommerce → `?add-to-cart={ref}`
- `checkout` + squarespace/generic → bare URL
- `referral_query` present → appended with `?` when the base has no query
- `referral_query` present → appended with `&` when the base already has one
  (constructed, since no dev URL does)
- `referral_query` present in **`product`** mode → still appended
- `discount_code` present → `?discount=`; absent → no empty param
- item in two stores → the lowest-position storefront composes, deterministically
- `referral_query` never appears in `links[]`
- **the payload key sets** — item, store, variant — pinned against the §3.7 consts
- re-add: retire a product, re-add it, assert it returns to the pool (§3.3)
- `retireAbsent()`: an item with both a stale and a live coord survives (§3.4)
- `retireAbsent()`: cross-store retirement still works, pinning the ordering
- all three cache lanes fire on the provisioning command and the un-retire path

### 5.4 Postgres lane

This slice does not touch `app/Ingest/`, so CLAUDE.md does not mandate
`composer test:pg`. It is run anyway: 5a was bitten twice by SQLite/Postgres
divergence in exactly this code (a bare column in `ON CONFLICT DO UPDATE`; a
`timestamptz` round-trip), and §3.4 rewrites a subquery.

### 5.5 Post-deploy

`cloud env:logs partna development --minutes 10`, plus a **Nightwatch scan** —
slice 0's checkpoint recorded a log scan performed and Nightwatch skipped; §15,
§16 and this slice do not repeat that gap.

---

## 6. Definition of done

A dev account's Shop page renders from `profile.pools.shop` with its products in
the owner's chosen order, grouped into store cards rebuilt from the `collections`
map; the outbound URL is composed by the backend, with a `referral_query` +
`checkout` combination proven correct **by test** since dev data carries neither;
the pool payload has an enforcement point equivalent to `SHOP_PRODUCT_ALLOWLIST`;
every `pool:shop` section carries the corrected rule; a re-added product returns;
the coverage gate returns 0; checkpoint and wire manifest committed.

The legacy `/integrations` shop keys **are** retired in this slice (§3.8). If, at
merge, that turns out to be unshippable, the criterion is marked **unmet** rather
than ticked.

---

## 7. Conventions this slice establishes

| Convention | Value | Who needs it |
|---|---|---|
| Clearing `removed_at` | an **owner-authored** write may; a **connector** re-observing never may | slices 3, 4, 6 |
| A pool payload carrying groups | additive `collections` map on the pool envelope, keyed by collection **uuid**, each carrying `externalRef`; items carry plural `collectionIds` | **slice 4 explicitly** — menu categories are the same problem |
| Pool payload enforcement point | explicit key-by-key construction + a wire-shape test pinning key sets, failing on additions too | every remaining slice |
| Owner-chosen ordering | pins seeded once by a provisioning command; nothing writes pins afterwards | slice 4 |
| Kind-specific item fields | additive and nullable on **every** item, never a kind-shaped sub-object | slices 4, 6 |

---

## 8. Out of scope — carried to slice 7

- Dropping `site.shop_brands` / `site.shop_products`.
- **Re-homing `ShopContentWriter` off the `ShopBrand` model** (§1.3). Until then
  `site.shop_brands` remains a live write target, contrary to what parent §16.9
  claimed.
- `upsertStore()`'s TOCTOU race against a concurrent scheduled sync — pre-existing,
  fixed properly by denormalising `user_id` onto `storefronts` behind a unique
  index.
- **`CloudflarePurgeService::purgeHandle()`'s un-deduped, 4x-amplified error
  reporting.** Raised by the slice-3a session 2026-08-13 and verified here.
  `purgeHandle()` runs three independent lookups — shop product handles
  (`content.collection_items`/`collections`/`f_catalog`, which 5a's C2 fix
  repointed and whose join surface went from one table to four), `site.menu_items`,
  and event ids — and **each `catch` calls a raw `report($e)`** with no dedup
  (OBS-101, `cdf6f9eaf`, predating this programme).
  `CloudflareCachePurgeJob::handle()` self-dispatches three delayed follow-ups
  (`partna.cache.purge_followup_schedule` = `[120, 300, 900]`), so one site save
  runs `purgeHandle()` four times: **up to 12 un-deduped Nightwatch reports per
  site save, per site** on a connection-level fault, or four if only the shop
  lookup fails.

  **Not fixed in 5b, deliberately.** 5b does not open this file, and the change is
  cross-cutting observability affecting the products, menus and events lookups
  alike — folding a monitoring-behaviour change into a slice that also retires a
  public wire would make both harder to review and harder to revert. The two
  slices that *trigger* it own it, and both prompts now say so: slice 7 (drops
  `site.menu_items` and the shop tables out from under two of the three lookups)
  and slice 4 (moves menus to `content.*`). The remedy in both is the same — repoint
  the lookup in the same window as the schema change, and wrap the catch in
  `App\Services\Analytics\Concerns\EscalatesRepeatedFaults`, already used by ten
  services.

---

## 9. Downstream prompt edits this slice owes

Per the kickoff's rule that a checkpoint is not a communication channel — edited
in place, stating the fact rather than the story:

| Change | Document |
|---|---|
| `site.shop_brands` is not inert (§1.3) | parent §16.9 |
| Owner-authored writes may clear `removed_at` (§3.3) | parent §9.8 |
| Pool item payload shape + `collections` map (§3.6) | `slice-4-menus`, `slice-6-reviews` |
| Payload enforcement point (§3.7) | `slice-4-menus`, `slice-6-reviews`, `slice-7-teardown` |
| `PoolResolver` / `PoolRegistry` / provisioning changed | `slice-4-menus`, `slice-6-reviews`, `slice-7-teardown` |
| Migration block `20260813110000`–`20260813119999` unused (§2) | parent §4.3 rule 5 |

`media-pool-slice-1b` is **not** edited: it is merged (parent §15), so its prompt
is a historical record rather than an instruction anything will act on.
