# Wire change — slice 5b, the shop pool and the public render (2026-08-12)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-slice-5b-shop-render-design.md`, owner decision, following
the slice 2 precedent).

> **STATUS (2026-08-17): LIVE on dev.** Merged and deployed on **2026-08-13**;
> this header previously read "not yet merged … not yet deployed to any
> environment", which was wrong for four days and described a retirement that had
> already shipped. Read back off `dev-api.partna.au`:
> `GET /api/public/profiles/ollies` → 200, with `profile.pools.shop` serving
> **30 items / 5 collections** (read 2026-08-17 11:11 UTC).
>
> The backing tables this slice rendered from are themselves now gone:
> `site.shop_brands` and `site.shop_products` were dropped on dev by the shop
> re-home project (parent spec §29). Shapes below stand; their store moved.

## `GET /api/public/profiles/{handle}`

**Consuming repo:** partna-monorepo (`@partnaau/design-system`), Partna-App.

### New: `profile.pools.shop`

Before — key absent. After:

    "pools": {
      "shop": {
        "items": [ { ...poolItem, "collectionIds": ["<uuid>"], "variants": [ … ] } ],
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

`latestItemId` is always `null` for shop — `shop` is deliberately not in
`LATEST_TAG_POOLS` (hand-ordering fights a Latest badge, pool recency is a sync
artefact rather than product newness, and unavailable stock could carry the
badge). `order_by: 'recency'` governs only the unpinned tail; the owner's order
is carried by pins (see "Required deploy step" below).

**`collections` is present only on `shop`.** Every other pool's envelope stays
`{items, latestItemId}` — no `collections` key at all, not an empty object.

**Keyed by collection UUID, not by the legacy brand id.** `externalRef` is
carried inside each entry precisely so partna-monorepo's existing brand-keyed
render can map onto the new map mechanically — but the uuid is the key because
`external_ref` recurs across users (the same Shopify store id, `75102060779`,
backs five separate users' stores on dev) while the uuid does not. Keying on
`externalRef` would collide.

**`collections[*].name` is nullable — it was always a string before.**
`ShopContentWriter::upsertStore()` stores `label = name ?? brand_id` because
`content.collections.label` is `NOT NULL` and has no other way to represent "no
name was ever fetched" — the id becomes a sentinel value in that column. The
pool mirrors the same normalisation `ShopContentReader:159` already applies for
the dashboard read (`PoolResolver::collectionsFor()`): when `label` equals the
store's own `external_ref`, `name` is emitted as `null` rather than as the raw
id. Without this the public store card for a store whose name was never
fetched — reachable *often* now that a pending store renders (see below) —
would show its opaque provider id (`"75102060779"`, `"fearnoevil-com-au"`) as
its public display name. No backend consumer in this repo is affected; the
exposure is entirely in the frontend render.

**`collectionIds` is plural because a product can genuinely belong to two of a
user's stores** — the same product URL listed twice resolves to one
`content.items` row (5a §3.3), which can be linked to two storefront
collections at once.

### Changed: every `poolItem` gains four keys, additive and nullable

Same contract `durationSeconds` and slice 2's eight event keys already set — no
existing key changed or removed, and every kind (video, event, media, product)
carries all four, null/empty off-kind.

| Key | Type | Source | Null / empty when |
|---|---|---|---|
| `description` | string \| null | `f_text.body` | no body |
| `vendor` | string \| null | `f_catalog.vendor` | absent |
| `variants` | list | `item_variants` + variant-labelled `offers` | no variants |
| `collectionIds` | list | `collection_items` | not in a collection |

A video and an event now also carry these four keys — `description: null,
vendor: null, variants: [], collectionIds: []` — because the payload is built
once for every kind, not per kind.

Each variant object: `label`, `sku`, `imageUrl`, `availability`, `price`
(`{amountMinor, amountMaxMinor, currency, qualifier}`, same shape the item
itself carries).

**`frames` now populates for `kind: 'product'`.** Same shape as `media`'s
frames, previously always `[]` for every non-media kind. Carrying it was
required, not optional: without it the retirement drops 271 gallery images
that existed only in the legacy shop payload's `images[]` array.

**`popularityRank` is now populated for products.** Previously hardcoded
`null` for every item regardless of kind. It now joins
`analytics.content_popularity_scores` (`content_type='shop_product'`) on
`f_catalog.handle`. 34 scored rows exist on dev; retiring the legacy keys
without carrying this forward would have silently dropped live computed data
to `null`.

### Changed: `url` carries the composed outbound href for products

Previously the bare `content.f_link.url`. Now the backend composes the full
checkout/product link at read time (mode from `site.sites.shop_link_mode`,
Shopify cart deep-link or WooCommerce `?add-to-cart=`, discount code and
referral query appended). `productHref()` in partna-monorepo retires as a
direct consequence: **the sitepage clicks `item.url` and needs no shop
composition logic at all.**

`links[]` is unchanged and continues to carry the bare per-platform links, so
the referral suffix appears in exactly one place on the wire (`url`), not two.

### Removed from `platforms.shop[*]`: `payload` is now `[]`

    "platforms": {
      "shop": [ { "resourceId": "brand-abc", "payload": [], "lastRefreshedAt": "…" } ]
    }

**It is `[]`, not `{}`.** `filterPayload()` returns
`array_intersect_key($payload, array_flip([]))`, which is an empty PHP array —
and PHP's empty array always JSON-encodes as `[]`, never `{}`, because PHP has
no distinct empty-map literal. A consumer doing `Array.isArray(payload)` or
keying off `Object.keys(payload).length` will see an array, not an object.

**The envelope survives** — `resourceId`, `payload`, `lastRefreshedAt` — so a
consumer *iterating* `platforms` sees no shape change, only an empty payload.
Identical to what slice 2 did to `eventbrite` / `humanitix` / `events-custom`.

**This is the breaking part.** A consumer that *indexes into a brand id inside
`payload`* — which is how the legacy shop render works — gets `undefined`
where it previously got brand and product data. `SHOP_BRAND_ALLOWLIST` and
`SHOP_PRODUCT_ALLOWLIST` are deleted; there is no allowlist left to widen to
bring the old shape back.

**Every Shop page renders empty from the moment this deploys until
partna-monorepo reads `profile.pools.shop` instead.** This is a required
consumer action, not a suggestion — restated because it is the one fact in
this document that determines whether the deploy is safe to ship.

### Removed from the wire entirely: `referralQuery`, `linkMode`

Not emptied — absent. A privacy improvement: composition is backend-side now,
so the affiliate suffix is no longer publicly readable at all. `sourceUrl`
(re-scrape input) and `connectStatus` (dashboard-only) stay private, as they
were before.

### Page presence

Shop-page presence is now pool-derived, via `PoolResolver::hasSelection()`,
exactly as slice 2 did for events. **A shop connection no longer grants the
Shop page on its own; a non-empty pool selection does.**

**A `pending` store's products now render, where they previously did not.**
The legacy presence gate filtered `connect_status = 'pending'` out, in
lockstep with a payload that also rejected pending brands — two wrong queries
agreeing. That payload is gone and the pool has no notion of connect status at
all, so presence and payload now both say "present" instead of both saying
"absent." Owner decision, 2026-08-13 — not implemented as a filter, because the
only reason the filter existed was to keep two now-nonexistent things in
lockstep.

## Required deploy step — before the code deploy, not after

    php artisan content:provision-shop-pins --dry-run
    php artisan content:provision-shop-pins

Seeds each owner's existing catalogue order (`collections.position`, then
`collection_items.position`, flattened to a dense 1-based `sort_key`) as pins
on their `pool:shop` section. **Without this, every Shop page renders in
`last_seen_at` order** — a sync artefact, not the order the owner chose — from
the moment the pool read goes live.

**The command has no per-site try/catch.** Its per-user loop calls
`PoolSectionProvisioner::ensure()` and writes `site.section_items` rows
directly; a DB exception on one user's site aborts the whole run for every
user after it in iteration order, not just that one user. Re-running is safe:
an existing pin for `(section_id, item_id)` is left alone, never rewritten, so
a partial prior run — or an owner's manual drag made in between — survives a
re-run untouched.

## Not changed

`site.shop_products` and `site.shop_brands` are **not dropped**. Slice 7 owns
that, and per the parent spec's corrected §16.9, `site.shop_brands` is not
even inert yet — `ShopController` still writes it.

No DDL in this slice. The pre-assigned migration prefix block
`20260813110000`–`20260813119999` went unused and returns to the pool.
