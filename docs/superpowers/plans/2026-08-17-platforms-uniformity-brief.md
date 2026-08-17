# Platforms uniformity — backend brief

**Date:** 2026-08-17 · **From:** dashboard lane (owner + Claude) · **To:** backend
**Status:** BRIEF — decisions to confirm, then plan. Nothing here is executed.

## Goal

The dashboard's Platforms table is moving to **one row per connection**, and every
row must behave the same: it has a name, it can be disconnected *as that row*, and
if the platform is a content source it feeds a pool. Today the wire supports that
for ~26 slugs and not for ~43. This brief names the gap, the owner rulings that
bound it, and the four pieces of backend work — one trivial, one a real phase.

**Read-only audit behind this:** `routes/api/platforms.php` (every group + the
registry loop), `PlatformRegistry::all()` dumped live (79 slugs, shapes, flags),
`RoutingConnectionResource`, `GenericPlatformController`, `SourceProvisioner`,
`app/Ingest/Connectors/*`, `app/Catalog/Definitions/*` (102 brand definitions
with `Detector::url`), and dev DB `site.platform_connections` for `ollies`.

## The one model, in three layers

A slug is "a platform" to the dashboard when all three hold:

| Layer | Test | Where it lives |
|---|---|---|
| **A · Storage** | own `surface_key`, own brand, own row(s) | catalog definition · Phase 6 ✅ for all 102 |
| **B · Routes** | `POST /platforms/{slug}/connect` · `DELETE /platforms/{slug}` · `GET /{slug}/selection` (+ `/accounts`, `/accounts/{id}` if multi) | `routes/api/platforms.php` — **43 slugs have none** |
| **C · Source** | a `Connector` + `SourceProvisioner::identifierFor` mapping → feeds a pool | `app/Ingest/Connectors/` — 16 connectors; 86 of 102 brands have none |

Layer C is a **property**, not a gap (see ruling 1). Layer B is the gap.

## Owner rulings (2026-08-17) — settled, do not re-litigate

1. **Source iff a connector already exists.** A brand with a `Connector`
   (DoorDash, Uber Eats, Square menus, Fresha, the music/video/event ones,
   Google Business, Instagram) is a source and feeds its pool. Every other brand
   is a **link-only platform**: connectable, disconnectable, listed, routed —
   and it sources nothing. **Do not write new connectors under this brief.**
   Menulog, Bopple, Easi, HungryPanda, Square Ordering, and every booking /
   reservation / ticketing / creator brand are link-only. (Menulog is the
   named example; the rule is general.)
2. **Every brand surface is a platform.** No family-only brands. Ordering,
   booking, reservations, ticketing, creator link-outs — same route contract
   as Spotify or TikTok. The families (`online-ordering`, `booking`,
   `reservations`, `events`) survive as the *paste-any-URL classifier* that
   routes to a brand; they stop being the only way in or out.
3. **Dashboard renders one row per connection.** `/routing/connections`
   already returns that; the dashboard stops collapsing by platform. Multi-store
   platforms (Shopify ×4 on ollies) become four rows, four sheets.

## The work

### W1 — `RoutingConnectionResource`: emit `name` (trivial · land now · no decision)

`payload.name` never leaves the backend. The resource sends `displayName` (the
*platform's* label) and `url`, so the dashboard falls back to a hostname —
`maps.google.com` for a Google Business row named "ST. ALi Coffee Roasters",
and it would print `10233455` for a Shopify store once rows are per-connection.

```php
'name' => is_string($this->payload['name'] ?? null) ? $this->payload['name'] : null,
```

Every connect strategy checked writes `payload.name` (Places, Shopify, Fresha,
reservation providers, LinkRouter cards). No schema, no migration. Independent of
everything else here and of slice 7 — safe on `development` today.

Bonus: the dashboard can then drop its per-platform `/accounts` refetch on
Platforms-page mount (6–8 requests it makes only to learn names).

### W2 — Phase 6b: brand surfaces get the platform route contract (the real piece)

**43 registry slugs have no routes of their own** — `shape=Bespoke`, but no
bespoke controller group exists; the registry loop `continue`s past Bespoke
assuming one does. Every op goes through a family controller. Confirmed by
walking `Route::getRoutes()` against `PlatformRegistry::all()`:

| Category | Slugs (routes-less) |
|---|---|
| Ordering | bopple, easi, hungrypanda, square-ordering — plus doordash / uber_eats / menulog, which are catalog surfaces **not even in the registry as slugs** |
| Booking | booksy, vagaro, timely, kitomba, phorest, shortcuts, bella-booking, boulevard, glossgenius, mangomint, zenoti, mindbody, ovatu (+ 3 more in catalog) |
| Reservations | resy, quandoo, sevenrooms, tock, tablecheck |
| Ticketing | ticketek, oztix, trybooking, resident-advisor, ticketmaster, events-custom |
| Creator / link-out | whatsapp, substack, patreon, ko-fi, buymeacoffee, github, gitlab, codepen, dribbble, behance, gumroad, mixcloud, tidal |
| *(false positive)* | apple-music, apple-podcast — routed under `/apple/music`, `/apple/podcast`; fine |

And the wider truth: **102 catalog brands carry `Detector::url`**; the registry
only knows 79 slugs; the loop only routes MultiAccount / LinkOnly /
SingleSelection. So the fix is not "add 43 groups" — it's **make the loop
route every detectable brand**.

**Proposed shape:**

- New `PlatformRouteShape::Brand` (or widen `LinkOnly`): emitted for every
  catalog surface with a URL detector that has no bespoke group.
- `POST /platforms/{slug}/connect` — accepts `{url}`; validates the URL
  matches **this** brand's detector (reject a DoorDash URL posted to
  `/menulog/connect`); delegates the write to `LinkRouter`, which already
  writes per-brand and already enforces one-store-per-brand.
- `GET /platforms/{slug}/selection` and `DELETE /platforms/{slug}` — via
  `GenericPlatformController` with `->defaults('platform', $slug)`, exactly as
  LinkOnly does today.
- `/accounts` + `DELETE /accounts/{id}` only where the brand's manifest allows
  more than one store (`GenericPlatformController::removeAccount` already keys
  on `resource_id` — reuse as-is).
- `platform.available` gating per slug off `AccountCapabilities` by routing
  class: ordering ⇒ `can_use_online_ordering`, booking ⇒ `can_use_booking`,
  reservations ⇒ `can_use_reservations`; social/creator ⇒ none. Run the
  `account-capability-audit` skill over the result.
- The registry must **know the catalog brands as slugs** — today
  `doordash`/`uber_eats`/`menulog` are surfaces without descriptors. Either
  descriptors are derived from the compiled catalog for every URL-detected
  brand, or the loop iterates the catalog directly. Prefer the former so
  `PlatformRegistry::all()` stays the one truth the dashboard's connect roster
  is "typed off".
- Family controllers unchanged in behaviour: `POST /online-ordering/entries`
  still classifies-then-writes; it now *lands on* the same row
  `POST /doordash/connect` would. `DELETE /online-ordering` (family-wide) can
  stay for the connect-sheet's "clear all" or be retired — owner's call.

**Exit:** `DELETE /platforms/doordash` disconnects DoorDash;
`DELETE /platforms/booksy` disconnects Booksy; the dashboard's per-key
disconnect is correct for **every** row `/routing/connections` returns with
zero special-casing (Apple's `/apple/…` prefix stays the one mapped exception).
`RegistryConnectCoverageTest` / `PlatformControllerConvergenceTest` extended to
assert every URL-detected brand has the four routes.

**Sizing hint:** M–L. The pieces exist (`LinkRouter`, `GenericPlatformController`,
`platform.available`); the work is the loop, the descriptor derivation, the
detector-match guard on connect, and the tests. Not XL — no new tables, no
teardown.

### W3 — Booking / reservations per-row disconnect (subsumed by W2; note if W2 slips)

Today only `DELETE /platforms/booking` and `DELETE /platforms/reservations`
exist — family-wide. Ordering got `DELETE /online-ordering/entries/{id}` in
Phase 6; booking/reservations didn't. W2 makes this moot (per-brand `DELETE`).
**If W2 is deferred**, add `DELETE /booking/{resourceId}` and
`DELETE /reservations/{resourceId}` mirroring `OnlineOrderingController::
removeEntry` so a row-level Disconnect button never fires a family-wide nuke.
The families are XOR-locked today (one provider at a time) so all==one in
practice — but the button's label and the endpoint's contract must agree.

### W4 — Registry inconsistency: `nowbookit` / `opentable` / `resdiary` (small)

`shape=MultiAccount` but `multiAccount()=false`. The loop gates `/accounts` and
`/accounts/{id}` on `multiAccount()`, so they get MultiAccount's `selection` +
`forget` and no per-account routes. Harmless today (one row each), but W2's
per-row logic branches on shape.

**Recommend:** reclassify to `SingleSelection` — a venue has one OpenTable rid /
one ResDiary microsite / one NowBookit venue; there is no legitimate second
account. Check `RegistryConnectCoverageTest` shape assertions first.

## Explicitly NOT in scope

- **New connectors / new sources.** Ruling 1. Menulog et al stay link-only.
- **Anything slice 7 touches.** None of W1–W4 read or write the nine drop-list
  tables. W1 is safe on `development` today; W2 should be sequenced after the
  drops if the same person is doing both — it competes for the same head, not
  the same tables.
- **The known Phase 6 defect** — `*.square.site` classifying as booking (it's
  Square Online, not Appointments). Named in the Phase 6 checkpoint; separate.
- **The dashboard half** — per-row table, per-row sheet, disconnect-by-shape,
  dropping the `/accounts` refetch. That's the dashboard lane's; it needs W1
  to be complete and W2 to be *correct*, and can ship the table shape before
  W2 with a shape-mapped disconnect as a bridge.

## Suggested order

1. **W1** — today, on `development`. One line + a resource test.
2. **W4** — with W1 or right after; it's a descriptor edit + test.
3. **W2** — its own branch, its own plan in the house format, after the slice-7
   drops land. Owner rulings 1–3 above are its charter.
4. **W3** — only if W2 slips past the dashboard's per-row table shipping.

## Open questions for the owner (answer before W2 is planned)

- After W2, does `DELETE /platforms/online-ordering` (family-wide) **stay** as
  a "remove all ordering" affordance, or retire?
- Should the connect sheet's roster offer the 86 link-only brands as cards, or
  keep the paste-URL entry as the only path for them? (Affects nothing on the
  backend — it's a roster question — but it changes how visible W2 is.)
