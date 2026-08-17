# Wire changes — shop re-home (`site.shop_brands` + `site.shop_products`)

## 2026-08-17 · The PUBLIC wire is UNCHANGED (dev)

This file exists to say that explicitly rather than by omission. A project that
moves every read and write of two tables and then drops them is exactly the
shape that silently changes a payload, so the absence of a change is the claim
worth recording — and worth checking rather than asserting.

**Why it cannot have changed.** The public shop surface stopped reading the
legacy tables at **slice 5b Task 8**, which deleted the shop branch of
`PublicIntegrationConnectionResource::filterPayload()` along with
`SHOP_BRAND_ALLOWLIST` / `SHOP_PRODUCT_ALLOWLIST`. #API-1's enforcement point
moved to `PoolResolver::ITEM_KEYS` / `STORE_KEYS` / `VARIANT_KEYS`, pinned by
`tests/Feature/Content/PoolWireShapeTest.php` — a strictly stronger filter,
because it reaches inside variant objects where the deleted top-level
`array_intersect_key` never did. This project therefore inherited a public wire
that was already composed from `content.*`.

**Checked, not assumed:** `grep` across `app/Http/Controllers/Api/PublicSite/`,
`app/Http/Resources/PublicSite/` and `app/Site/` returns **zero** references to
`site.shop_brands`, `site.shop_products` or the `ShopBrand` model. There is no
public read path that could have been affected by the DROP.

## What DID change, and where it is visible

All of it is the OWNER's dashboard (`/api/platforms/shop/*`), never the
sitepage:

| Endpoint | Change |
|---|---|
| `POST /brands/{id}/catalog` | now 404s for a store present in `site.shop_brands` but absent from `content.*`, where it previously worked. Deliberate — `catalog()` re-scrapes off `{url, provider}` and `content.*` carries both. Live dev population for this case: **zero** (all 10 legacy rows verified present in `content.*`). |
| `GET /brands/{id}/connect/status` | resolves the store from `content.*`, so the same absent-from-`content.*` case 404s instead of returning a store. Same zero population. |
| every write endpoint | writes `content.collections` + `content.storefronts` only. `site.shop_brands` is no longer written by anything. |

**Response BODIES are byte-identical.** `tests/Feature/Shop/ShopEndpointParityTest`
holds `assertExactJson` captures taken from a dump of the real pre-repoint
responses for all five read endpoints; they were not edited during this project,
and they pass.

## Schema — band `20260819*`, dev only

| Migration | What |
|---|---|
| `20260819000100` | `content.storefronts.user_id`, NOT NULL, FK to `core.users` ON DELETE CASCADE |
| `20260819000110` | `storefronts_user_provider_ref_uq` — UNIQUE, partial on `external_ref IS NOT NULL` |
| `20260819000120` | `storefronts_connect_status_check` — carries `shop_brands`' live vocabulary across before the DROP |
| `20260819000200` | DROP `site.shop_products` (child first — it FKs the parent) |
| `20260819000210` | DROP `site.shop_brands` |

`site.shop_brands` carried three CHECK constraints. Two were dead —
`selection_mode` and `link_mode` do not exist on `content.storefronts`,
deliberately. The third, `connect_status`, is live and is read by
`PublicIntegrationConnectionResource`, which rejects only `'pending'`, so an
unlisted third value would render publicly as though the store had connected.
It was carried before the DROP rather than after, because afterwards there is no
original left to compare against.

**Production is untouched by this band** and still carries both tables together
with the code that reads them. Prod reconciliation is separate, deferred work.

## Cross-lane note

`content.storefronts` is **not** the shop lane's alone — `MenuFetchJob` writes
order-platform store cards into it (5 of 15 live dev rows). The `user_id`
migration made that column NOT NULL, so `MenuFetchJob::syncOrderPlatforms()` had
to start writing it in the same change. Without that, the migration would have
passed and the next menu scrape creating a NEW order-platform storefront would
have failed on a NOT NULL violation — deferred and silent.
