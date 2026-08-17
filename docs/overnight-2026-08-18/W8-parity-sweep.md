# W8 — Backend/dashboard parity sweep (platforms, content, services, menus, routing)

> **Correction (Fable, after verification):** recommendations 1–3 are WRONG. `shop-page.tsx`, `events-page.tsx` and `custom-links-page.tsx` all render through the shared `PoolPage` → `usePool()` → `GET /content/pools/{pool}` (verified 07:58); `lib/data/sell.ts` / `events.ts` / `links.ts` are dev-showcase fixtures nothing under `app/` or `components/blocks` imports. Treat 4–8 as the real candidates; 5 (booking/reservations smart-detect) and 6 (IG/GB synced diff+apply) are the ones with owner value — recorded as X-items in LOG.md.


Scope: user-facing, non-staff, non-public, non-webhook endpoints under
platforms/content/services/menus/routing/display-settings/integrations/media,
checked against `partna-monorepo/apps/dashboard` (`api(`/`api<` call sites in
`lib/queries/*.ts`, `lib/data/*.ts`, `components/**`). Method: `php artisan
route:list --json` in Comet-Backend, then literal-string grep in the
dashboard for each static path segment. `RefreshController` variants and
`api/platforms/{p}/refresh(/status)` excluded per instructions (covered).

## 1. Endpoints with no dashboard caller

| Method | Path | Controller@method | What it does | Owner control? |
|---|---|---|---|---|
| GET | `/routing/suggestions` | `Routing\SuggestionsController@index` | Lists below-threshold/blocked routing matches ("is this yours?" inbox) | **Y** — built, then explicitly un-mounted. `lib/queries/routing.ts:5-8`: *"The suggestions inbox … was modelled here in full and never mounted — no surface ever read it. Removed 2026-08-16."* Real feature, currently dead on the frontend. |
| POST | `/routing/suggestions/{intent}/accept` | `SuggestionsController@accept` | Confirms a suggested connection | Y — same as above, paired verb |
| POST | `/routing/suggestions/{intent}/dismiss` | `SuggestionsController@dismiss` | Dismisses a suggested connection | Y — same as above |
| POST | `/platforms/booking/detect` | `Platforms\BookingController@detect` | Smart-detects a pasted URL's booking provider (Fresha/Square/custom) | **Y** — the Booking pool's whole add-flow. No `booking/` string anywhere in dashboard grep. |
| GET | `/platforms/booking/detect/status` | `BookingController@detectStatus` | Poll target for the above | Y — pairs with `detect` |
| GET | `/platforms/booking/status` | `BookingController@status` | Current booking-slot state | Y — pairs with above |
| POST | `/platforms/reservations/detect` | `Platforms\ReservationsController@detect` | Smart-detects a pasted URL's reservations provider (OpenTable/custom) | **Y** — Reservations pool add-flow, same shape as booking. No caller found. |
| GET | `/platforms/reservations/detect/status` | `ReservationsController@detectStatus` | Poll target | Y |
| GET | `/platforms/reservations/status` | `ReservationsController@status` | Current reservations-slot state | Y |
| GET | `/platforms/reservations/suggestion` | `ReservationsController@suggestion` | Reservation-link suggestion (harvested from a connection) | Y |
| POST | `/platforms/events/add` | `Platforms\EventsController@add` | Adds an event by URL (Eventbrite/Humanitix scrape or custom card) | **Y** — `components/blocks/pools/events-page.tsx` has **zero** `api(`/`api<`/`fetch(` calls; `app/(app)/events/page.tsx` renders it. The whole Events pool is fixture-only (`lib/data/events.ts`, static array). |
| DELETE | `/platforms/events/custom/{id}` | `EventsController@removeCustom` | Removes a custom event card | Y — same unwired pool |
| GET | `/platforms/events/selection` | `EventsController@selection` | Which pool events are featured on the site | Y — same |
| PUT | `/platforms/events/order` | `EventsController@reorder` | Reorders the events pool | Y — `lib/data/events.ts:13`: *"written AS IF `PUT /events/order` exists — it doesn't yet"* — that comment is stale; the backend route exists (`routes/api/platforms.php:437`, verified in `route:list`), the dashboard just isn't calling it. |
| GET | `/platforms/custom/links` | `Platforms\CustomLinksController@links` | Lists the Links pool (arbitrary branded URL cards) | **Y** — `lib/data/links.ts` is a static fixture array, no caller anywhere in `lib/`/`components/`. Entire Links pool unwired, same pattern as Events. |
| POST | `/platforms/custom/links` | `CustomLinksController@addLink` | Adds a link card (202 + enrichment) | Y |
| GET | `/platforms/custom/links/{id}/status` | `CustomLinksController@linkStatus` | Poll target for the above | Y |
| PUT | `/platforms/custom/links/order` | `CustomLinksController@reorderLinks` | Reorders the Links pool | Y |
| DELETE | `/platforms/custom/links/{id}` | `CustomLinksController@removeLink` | Removes a link card | Y |
| DELETE | `/platforms/custom` | `CustomLinksController@forget` | Clears the whole Links pool | Y |
| GET | `/platforms/shop/brands/{id}/products` | `Platforms\ShopController@brandProducts` | Live product catalog for one connected store | **Y** — `lib/data/sell.ts` is a static fixture, no caller. Sell pool (stores + products) is documented in detail (`sell.ts` header) but entirely unwired — only the brand-level CRUD (`connectStore`/`updateBrandAutoLatest`/`removeBrand`, `useShopBrands`) is live, in `lib/queries/platforms.ts`. |
| POST | `/platforms/shop/brands/{id}/catalog` | `ShopController@catalog` | Kicks off a deferred catalog fetch for a store | Y |
| PUT | `/platforms/shop/brands/{id}/selection` | `ShopController@setProducts` | Replaces which of that store's products are selected (≤250) | Y |
| POST | `/platforms/shop/products` | `ShopController@addProduct` | Adds one individually-pasted product (≤20 cap) | Y |
| DELETE | `/platforms/shop/products/{productId}` | `ShopController@removeProduct` | Removes an individually-added product | Y |
| GET | `/platforms/online-ordering/entries` | `Platforms\OnlineOrderingController@entries` | Lists the online-ordering pool entries | **Y** (moderate) — no caller and no dashboard page/route references `OnlineOrderingController` or `online-ordering/entries` anywhere; the category exists in `lib/data/connect.ts` (per-brand connect cards like ChowNow/Deliveroo/Wolt) but its own pool CRUD is entirely absent. Unclear whether this pool has a page at all — no `app/(app)/**online-order**` route found. |
| POST | `/platforms/online-ordering/entries` | `OnlineOrderingController@addEntry` | Adds an ordering-page entry | Y |
| GET | `/platforms/online-ordering/entries/{id}/status` | `OnlineOrderingController@entryStatus` | Poll target | Y |
| DELETE | `/platforms/online-ordering/entries/{id}` | `OnlineOrderingController@removeEntry` | Removes an entry | Y |
| DELETE | `/platforms/online-ordering` | `OnlineOrderingController@forget` | Clears the pool | Y |
| GET | `/platforms/instagram/synced` | `Platforms\InstagramController@synced` | Diffs Instagram profile fields against the site | N/A — no caller found (`grep -rn "instagram/synced"` empty). Sibling to Google Business's synced/apply, which is also uncalled (below). Given Instagram has a live connect/refresh flow already, **Y** — plausibly a real, close-to-shippable control. |
| POST | `/platforms/instagram/synced/apply` | `InstagramController@applySync` | Applies the diffed fields | Y — pairs with above |
| GET | `/platforms/google-business/synced` | `Platforms\GoogleBusinessController@synced` | Diffs GB listing fields against the site | Y — same pattern, no caller |
| POST | `/platforms/google-business/synced/apply` | `GoogleBusinessController@applySync` | Applies the diffed fields | Y — pairs with above |
| GET | `/config/integrations` | `PublicSite\PublicConfigController@integrations` | Returns `{googleMapsApiKey}` for server-side proxying | **N** — the backend's own docblock claims *"Current consumers: Address autocomplete on the professional dashboard"*, but that's stale: `app/api/places/route.ts` proxies Google Places using its **own** `GOOGLE_PLACES_API_KEY` Next.js env var, never calling this backend endpoint. Genuinely dead on the frontend side; flag the docblock as stale rather than building a control. |
| POST | `/platforms/{brand}/connect` (Brand-shape, ~50 slugs: chownow, deliveroo, grubhub, boulevard, vagaro, treatwell, ticketmaster, github, …) | `Platforms\GenericPlatformController@connectBrand` | Field-validated connect for that specific brand | **N** — deliberate design, not a gap. `lib/queries/platforms.ts:254-285` documents that these routes exist (registry loop registers a `connect` route for every non-Bespoke platform — confirmed live in `routes/api/platforms.php:275-291`) but the dashboard's `DEDICATED_CONNECT` allowlist intentionally excludes ~50 Brand-shape slugs and routes them through the generic `POST /routing/links` paste-detector instead, per a documented past incident (blank-toast 404s). Not a missing owner control — a working, intentional substitute. |

## 2. Accepted fields never sent

| Endpoint | Backend accepts | Dashboard sends | Gap |
|---|---|---|---|
| `PUT /content/items/{item}/overrides` | Any `(facet, column)` pair `FacetRegistry::allows()` permits, validated dynamically (`UpsertManualOverrideRequest`) | Whatever `GET /content/kinds` describes for that item's kind | **None found.** `lib/content/kinds.ts` fetches the schema live from `GET /content/kinds` (added 2026-08-16 specifically to stop hand-copying); the field renderer (`components/blocks/item-fields.tsx`) is schema-driven, not a hardcoded list. This is a solved problem, not a gap — no further action recommended. |
| `PATCH /content/pools/{pool}` style order/rule updates | n/a | n/a | Did not find a distinct `PATCH /content/pools/{pool}` route in `route:list` output — pool order goes through `PUT /content/pools/{pool}/order` (`PoolController@reorder`), which **is** called (`lib/queries/content-pools.ts`). No separate rules-PATCH endpoint exists to compare against. |
| `/platforms/{key}/display-settings` toggles | Whichever toggles the platform's registry entry declares (dynamic) | `fetchDisplaySettings`/`patchDisplaySetting` (`lib/queries/platforms.ts:526-594`) render/send whatever the GET returns | **None** — also schema-driven by design (docblock: *"a toggle added in PlatformRegistryServiceProvider shows up here with its own words"*). |

No hardcoded-vs-dynamic field mismatches were found in the areas checked — the codebase has moved deliberately toward schema-driven forms for exactly this class of drift (see `content/kinds` and `display-settings` comments), so §2 is mostly a confirmation there's nothing to fix rather than a punch list.

## 3. Top 10 recommendations, ranked by owner value

1. **Wire the Sell pool** (`shop/brands/{id}/products`, `shop/brands/{id}/catalog`, `shop/brands/{id}/selection`, `shop/products` add/remove) — biggest single gap: an entire documented feature (stores + product selection, ≤5 stores/≤20 individual products) sitting on a static fixture while the brand-level CRUD around it is fully live.
2. **Wire the Events pool** (`events/add`, `events/custom/{id}`, `events/selection`, `events/order`) — same pattern as Sell: fixture-only page, real backend behind it, own code comment (stale) claims the reorder route "doesn't exist yet."
3. **Wire the Links pool** (`custom/links` CRUD + reorder + status) — third instance of the same pattern (fixture page, live backend).
4. **Decide the fate of the routing-suggestions inbox** — it was fully built and then explicitly un-mounted (2026-08-16). Either resurrect it (cheap — the code exists) or mark `SuggestionsController` for removal to stop it drifting further from the schema.
5. **Booking/Reservations smart-detect** (`booking/detect(+status)`, `reservations/detect(+status)`, `reservations/suggestion`) — these look like the intended add-flow for two pools that currently have no other way to add a first connection; worth confirming whether the Booking/Reservations pools even have pages yet, since this may be blocking them entirely rather than being an optional add-on.
6. **Instagram/Google Business "synced" diff+apply** — both platforms already have live connect/refresh; this is a comparatively small addition (one GET, one POST each) that would close a stated but unbuilt reconciliation feature.
7. **Online-ordering pool CRUD** — lower confidence than 1-3 (no page was found to confirm intent either way); worth a quick check with product on whether this pool is planned at all before spending backend/frontend time.
8. **Flag `PublicConfigController::integrations()`'s docblock as stale** — cheap fix, prevents a future engineer from trusting a "current consumers" claim that's wrong; either delete the dead endpoint or correct the comment.
9. **No action on Brand-shape `connectBrand` routes** — confirmed intentional; don't "fix" this into the dashboard's allowlist without reading the 2026-08-14 incident note in `lib/queries/platforms.ts:254-285` first.
10. **No action on `content/items` overrides / `display-settings` fields** — confirmed schema-driven, nothing to add.

## Method notes / what "unclear" would look like

Every "no caller" claim above is backed by a `grep -rn` across `lib/`, `components/`, `app/` (excluding `node_modules`) for the endpoint's static path segment, cross-checked against the relevant page component's import list, and in the Sell/Events/Links cases directly confirmed by reading `lib/data/{sell,events,links}.ts` (static fixture arrays) and the corresponding `components/blocks/pools/*-page.tsx` (no `api(`/`api<`/`fetch(` calls). The one soft call is Online-ordering (#7): grep found zero references anywhere, including no page route, so it's reported as a probable gap rather than a certain one — no page exists to confirm or deny intent either way.
