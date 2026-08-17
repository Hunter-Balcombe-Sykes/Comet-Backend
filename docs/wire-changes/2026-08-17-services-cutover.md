# Wire changes — services cutover

## 2026-08-17 · Two service surfaces, and which one the rebuild targets — OWNER RULING

There are two service-shaped keys on the public wire, they carry **different**
things, and that is intended. Confirmed live on `ollies` off
`dev-api.partna.au`, read 2026-08-17 11:11 UTC:

| Wire key | Contents on `ollies` | What it is |
|---|---|---|
| `profile.services` | **2** | Owner-authored services only |
| `profile.pools.services` | **25** — 23 `origin=auto` + 2 `origin=manual` | The services POOL: every service-kind item regardless of who sourced it, Fresha-scraped included |

**Owner ruling (2026-08-17): `pools.services` carrying Fresha-sourced services
STANDS.** The pool is the union of all service-kind items, and origin is carried
per item (`origin`: `auto` | `manual`) precisely so a consumer can tell them
apart. Slice 3b recorded this as a suspicion; the programme review confirmed it
live; the owner has now ruled it intended behaviour rather than a leak.

**What the frontend rebuild must do with that:** the rebuild targets `pools.*`,
so a salon rendering both `profile.services` and `pools.services` unfiltered will
show its owner-authored services twice — once alone, once inside the pool. Pick
one surface per section, or filter the pool by `origin`. This is a render-side
decision, not a backend defect; nothing on the backend will de-duplicate them for
you.

---

## 2026-08-17 · KV render payload: `services[].id` domain change (dev)

`site.public_site_payload`'s `services` key now composes from `content.*`
(migration `20260817000000`). Each entry's `id` is the `content.items` id,
where the pre-image emitted the `site.services` id. The public API
(`GET /api/public/profiles/{handle}`) has emitted content ids since slice 3a,
so KV and API now agree.

Verified at apply time on dev (`glncumufgaqcmqhzwrxm`), re-run rather than cited:

- **Content is unchanged.** The `services` key was snapshotted across all 22
  published sites before and after the apply; counts and titles are identical
  element-for-element. Only the id domain moved. Three sites carry services
  (`loadtest` 15, `ollies` 2, `broken-oven` 1); the other 19 emit `[]`.
- **Ids are content-domain.** Every emitted `services[].id` resolves in
  `content.items` and none resolves in `site.services`.
- **Both surfaces agree.** For `ollies`, the cached render payload
  (`SiteCacheService::getPublicSitePayload`) and the public profiles API return
  the same two ids — `ec5cdf51-…` (Hair cut) and `d77155c7-…` (fsdf) — with
  matching price, duration and description.
- **The view is off the legacy tables.** The `pg_depend` rewrite query returns
  zero rows for `site.services`, `site.service_categories` and
  `site.service_category_assignments`, which is Unit 1's acceptance criterion
  and unblocks the DROP unit.

Cache: the payload cache is keyed per subdomain and holds the pre-image ids
until busted, so all 22 published sites were invalidated
(`SiteCacheService::invalidateSitePayload`, which clears both the primary and
`:stale` SWR keys) and re-warmed at apply time. Note that
`SyncSubdomainToKvJob` writes only the `{type:"individual"}` routing pointer —
it does not carry the payload — so a KV re-sync is not what refreshes this key.

### Not changed by this migration

The view has no *section*-level gate, and never had one: the pre-image filtered
`sv.is_active` (the service row's flag), not the site's `services` section
block. The profiles API does gate on the section via `sectionEnvelope`. So a
site whose `services` section is `is_active = false` emits services from the
view while the API returns `[]` — `broken-oven` is in exactly this state today.
This divergence pre-dates the migration and is unaffected by it, but it is the
one place where "KV and API agree" does not hold, and it is worth settling
before the DROP unit.

---

## 2026-08-17 · Management-surface id domain: legacy uuids resolve nowhere (dev)

Spec ruling 1. Every service and service-category verb — owner and staff —
addresses `content.*` ids only. No mapping was minted and nothing carries the
legacy ids forward; `site.services` and `site.service_categories` are dropped
(`20260818000100`–`000300`).

**Breaks, deliberately.** A stored `site.services` uuid now 404s on:

| Verb | Route |
|---|---|
| show / update / delete / restore / resync | `GET·PATCH·DELETE /api/services/{service}`, `POST /api/services/{service}/restore`, `/resync` |
| category assignment | `PATCH /api/services/{service}/category` |
| staff twins | `/api/staff/professionals/{pro}/services/{service}` (+`/hard`, `/restore`) |
| staff categories | `/api/staff/professionals/{pro}/service-categories/{category}` (+`/hard`, `/restore`) |

`POST /api/services/reorder` and `/reorder-layout` 422 (`One or more items are
invalid.` / `One or more service IDs are invalid.`) for a legacy id, as does
`POST /api/staff/professionals/{pro}/service-categories/reorder`.

The public wire was already single-id: `payload.selection` has composed from
`content.*` since slice 7 Task 11, and the KV `services[]` id domain moved with
the migration recorded above. So the break lands only on authenticated
management URLs, and the frontend is under the rebuild-not-repair override.

## 2026-08-17 · Fresha service edits: price is vendor-owned (422)

`PATCH /api/services/{service}` and its staff twin answer **422** — `Fresha
prices come from Fresha and cannot be edited here.` — when the request changes
`price_cents` on a connection-sourced service. An **echo** of the current price
passes, so a dashboard round-trip that resends the whole object is unaffected.

D3a's reasoning, unchanged: `content.offers` is a set-union collection and
`FacetRegistry` excludes collections from `content.manual_overrides`, so an
edited price has no content.* home. Title, description and duration DO override
(`content.manual_overrides`), and those overrides now fold onto the booking blob
as well as the dashboard — before this change an edited Fresha service still
published the vendor's own text.

## 2026-08-17 · One category space

`content.collections` is the only service-category id space. Consequences on
the wire:

- A **Fresha service may be filed under any owner collection**. Previously the
  two spaces were kept apart and the mismatch was refused.
- **Both cross-space 422s are gone**: `A Fresha-synced service cannot be filed
  under an owner-authored category` and `An owner-authored service cannot be
  filed under a Fresha-synced category` can no longer be returned.
- `A service cannot be both categorised and uncategorised` is also gone. It
  policed a membership write this endpoint stopped making in slice 7 Task 12;
  with order-only blocks, a service appearing in two blocks is the supported
  multi-category case and its first occurrence sets its position.
- The grouped reads (`?grouped=1`, owner and staff) emit one category list
  where they used to concatenate two.

`PATCH /api/services/{service}/category` still 422s an unknown or foreign
collection id (`Category is invalid.`), and `POST /api/services` still
validates a supplied `category_id`/`category_ids` for ownership without
persisting it — that check moved from `site.service_categories` to
`content.collections` with everything else.

## 2026-08-17 · Reorder payload id domain, and a one-time ordering effect

`POST /api/services/reorder` and `/reorder-layout` (owner and staff) take
`content.items` ids for both halves, and every block's `id` is a
`content.collections` id. Ordering for BOTH halves is written to
`site.section_items.sort_key` on the site's services section — one scale, one
traversal — replacing the global `site.services.sort_order` renumber and the
`services_user_sort_order_uq` partial unique that made it fragile.

**One-time, self-healing:** a professional holding both halves sees their Fresha
services tail the dashboard list until their first reorder, because a
connection-sourced item has no `sort_key` row until something pins it, and an
unpositioned item sorts last by the `PHP_INT_MAX` sentinel
(`ServiceResource` maps that sentinel to `null` on the wire, so no client sees
it). Any reorder writes real positions for both halves and the effect is gone.

## Not changed

The section-gate divergence recorded above still stands: `site.public_site_payload`
has no `services` section gate while the profiles API applies one, so a site whose
`services` section is inactive emits services from the view and `[]` from the API.
It pre-dates this project, was NOT introduced or fixed by it, and `broken-oven`
remains in that state.
