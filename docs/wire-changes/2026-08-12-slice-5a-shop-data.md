# Wire change — slice 5a, shop data move (2026-08-12)

Backend-only execution. Spec: `docs/superpowers/specs/2026-08-12-slice-5a-shop-data-design.md`.
9 stores and 51 products move from `site.shop_brands` / `site.shop_products`
into `content.*`; every shop reader and writer repoints. The legacy tables
survive but nothing writes them. Slice 7 drops them.

> **STATUS: DEPLOYED to development, 2026-08-13** (`9e5bf3a6a`). The four
> migrations are applied to dev and the backfill has run — 9 stores, 51
> products, verified idempotent by a second run with identical counts. Checkpoint
> with the full live assertions: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`
> §16. **Prod has none of the migrations and has never run the backfill** — every
> endpoint and divergence below describes dev only.
>
> The first live backfill attempt failed (`SQLSTATE[42702]`, a Postgres-only
> ambiguous-column defect SQLite's test lane could not catch) and left 9
> orphaned `content.collections` rows before the fix landed; both are described
> in checkpoint §16.3. Nothing below this banner was affected — the endpoint
> shapes, divergences and deploy ordering all held.

## Read this first: the backfill is a BLOCKING, ORDERED prerequisite

`php artisan content:backfill-shop` must complete **before** the code deploys,
against whichever environment is being deployed.

There is no legacy fallback left anywhere. The transitional `hybridBrandMap()`
was deleted in Task 8, so an un-backfilled brand is simultaneously:

- absent from the dashboard shop list (`GET /platforms/shop/brands`),
- absent from the shop card (`GET /platforms/shop/selection`),
- and absent from the public Shop **page entirely** — not empty, *gone*:
  `PlatformRegistryServiceProvider`'s presence gate reads `content.*` now, so a
  store with no storefront row does not get a page at all.

The ordered sequence, the operational cautions and the rollback position are in
`docs/deploy/routine-deploy.md` → "Slice 5a — shop data move". Do not deploy
from this document alone.

## Endpoints — consuming repos and what changed

**Every endpoint's request and response SHAPE is unchanged.** That was the
acceptance criterion for the slice and it is pinned by
`tests/Feature/Shop/ShopEndpointParityTest.php`, whose expectations were
captured from the pre-change controllers and are asserted with
`assertExactJson()`. Saying "unchanged" explicitly is the point of this table:
it is what lets slice 5b and slice 7 tell what has already moved.

**Consuming repo for all 14: Partna-App** (authenticated dashboard). None of
these is on the public/CDN path.

| Endpoint | Before | After |
|---|---|---|
| `GET /api/platforms/shop/brands` | brands + products from `site.shop_brands` / `site.shop_products` | same shape, from `content.*` — **see divergences below** |
| `POST /api/platforms/shop/brands` | unchanged | unchanged |
| `GET /api/platforms/shop/brands/{id}/connect/status` | unchanged | unchanged |
| `PATCH /api/platforms/shop/brands/{id}` | unchanged | unchanged |
| `DELETE /api/platforms/shop/brands/{id}` | deleted legacy rows | sets `content.items.removed_at`, deletes the collection; item rows survive so analytics keep resolving |
| `GET /api/platforms/shop/brands/{id}/products` | unchanged | same shape, from `content.*` — **see divergences below** |
| `POST /api/platforms/shop/brands/{id}/catalog` | unchanged | unchanged — reads the LIVE store catalogue, which was never in these tables |
| `PUT /api/platforms/shop/brands/{id}/selection` | unchanged | unchanged |
| `POST /api/platforms/shop/products` | unchanged | unchanged |
| `DELETE /api/platforms/shop/products/{productId}` | unchanged | unchanged |
| `GET /api/platforms/shop/selection` | unchanged | same shape, from `content.*` — **see divergences below** |
| `GET /api/platforms/shop/settings` | unchanged | unchanged |
| `PATCH /api/platforms/shop/settings` | unchanged | unchanged |
| `DELETE /api/platforms/shop` | deleted legacy rows | sets `content.items.removed_at`, deletes collections; item rows survive |

### Public surfaces — unchanged, deliberately

| Surface | Consuming repo | Status |
|---|---|---|
| `GET /api/public/profiles/{handle}` | partna-monorepo (`@partnaau/design-system`) | **unchanged.** No `pools.shop`; that is 5b. |
| `GET /api/public/profiles/{handle}/integrations` | partna-monorepo | **unchanged as of 5a.** ~~The shop branch of `PublicIntegrationConnectionResource` still renders, and its `SHOP_BRAND_ALLOWLIST` / `SHOP_PRODUCT_ALLOWLIST` are untouched.~~ **Corrected 2026-08-13, by 5b:** both allowlists are deleted and this endpoint's shop `payload` is now `[]` for every brand. See `docs/wire-changes/2026-08-12-slice-5b-shop-render.md` — the correction there is the frontend's only other written warning about this. |
| `content.f_link.url` | — | Stores the **bare** product URL. No referral query, no checkout deep link. Composition stays in the monorepo's `productHref()` for the whole of 5a; **5b moves it to the backend and retires that function** — done, see the 5b wire manifest. |

## Known divergences — values, not shapes

Every one of these was found by the parity test, argued, and accepted rather
than engineered away. They are listed here so Partna-App inherits them
explicitly instead of discovering them.

### 1. `createdAt` is reformatted, and synthesised when the blob had none

Stored in a Postgres `timestamptz`, which normalises every offset on write, so
it reads back in canonical `+00:00` form (`2026-02-10T00:00:00+00:00`) rather
than whatever string the scraper emitted (typically `…Z`).

A product whose blob carried no `createdAt` at all now gets one, synthesised
from `content.items.first_seen_at`. Transitional, and marked as such in
`ShopContentWriter::cataloguesFor()`.

Pinned on real Postgres by `tests/Postgres/ShopCatalogueCreatedAtTimezoneTest.php`
— the SQLite stand-in stores the column as TEXT and hands back the exact string
written, so a green SQLite suite proves nothing about this.

### 2. `handle` and `variantId` are present-as-null where the blob omitted them

A product blob that never set these keys previously rendered them absent from
the JSON. They now appear as explicit `null`. Key-absent → key-present-with-null.
(`vendor` and `description` are *omitted* when null rather than nulled, so those
two round-trip byte-identically.)

### 3. `images` on a single-cover product

A blob with a cover `image` and an empty/absent `images` array reads back as
`images: [<cover>]`.

`content.*` cannot distinguish "cover set, gallery empty" from "cover set,
gallery repeats the cover" — the reconstruction emits `[cover, ...gallery]`
once a cover exists. Real, structural, and not fixable without a schema
concept that does not exist. Squarespace and the generic Open Graph scraper
both legitimately produce this input.

### 4. A `Default Title`-only product loses its `variants` array

Shopify emits a single variant titled `"Default Title"` for a product with no
options — 17 of the 51 dev products. Those placeholder rows are not written to
`content.item_variants` (a table whose purpose is to name a choice), so such a
product reads back with no `variants`.

**Its variant id survives as the top-level `variantId`.** That is the piece that
had to live: the Shopify checkout deep link is built from it, and 5b's checkout
path depends on it.

### 5. Product-object key ordering

The keys within each product object are emitted in the reconstruction's order,
not the legacy blob's. The key *set* is unchanged (modulo 2 and 4 above). If any
consumer depends on JSON key order, that is a bug in the consumer —
`PublicIntegrationAllowlistTest` was itself relaxed from an order assertion to a
sorted key-set assertion for this reason.

### 6. `linkMode` now reports the global setting, not the per-brand column

**The one behaviour change a user can see.** `linkMode` on each brand now reads
`site.sites.shop_link_mode` — one value for the whole map — instead of the
per-brand `site.shop_brands.link_mode`.

Five of the nine dev brands store `'product'` under a `'checkout'` global, so
those five will *change value* on the dashboard at deploy. Not a shape change;
a value change on a real setting.

Sanctioned in Task 7 and correct: the per-brand column has been dormant since
2026-07-08 and the public path has stamped every brand's `linkMode` from the
global value ever since. The dashboard was the last surface still showing the
dead per-brand value. Recorded here because the slice's promise was "nothing
changes", and this is the exception.

### 7. `selectionMode` is a derived constant

Always `'manual'`. `site.shop_brands.selection_mode` was `'manual'` on all 9 dev
rows because that is the column default and nothing ever set it (#SEM-1) — the
live curation signal is `products_curated_at`, which moved to
`content.storefronts`. No storage, no behaviour change, and no revival of a dead
column.

### 8. A nameless brand reads back `name: null`

`content.collections.label` is NOT NULL, so `upsertStore()` writes the brand id
into it as a fallback. The reader recognises exactly that fallback
(`label === external_ref`) and nulls it back out rather than surface an id the
dashboard never showed. This is a *fix*, not a divergence — the legacy value was
null too.

## Not in this slice

- `profile.pools.shop` and the `shop` page — **5b**
- Backend read-time composition of the outbound product URL, and retiring
  `productHref()` in partna-monorepo — **5b**
- Retiring the legacy shop keys from `/integrations` — **5b**, and it is the
  breaking one
- Dropping `site.shop_brands` / `site.shop_products` — **slice 7**

## Dropped, and named as a loss

`site.shop_brands.style_analysis` (non-null on 4 dev rows) has no reader and no
writer — both sides of the code that produced it are gone. It is not carried
into `content.storefronts` and it cannot be regenerated. Carrying an
unreferenced jsonb blob into a new table to preserve the output of deleted code
is sediment, not preservation.
