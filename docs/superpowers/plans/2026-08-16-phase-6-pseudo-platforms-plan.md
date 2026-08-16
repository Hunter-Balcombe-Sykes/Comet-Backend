# Phase 6 — pseudo-platform retirement (plan, 2026-08-16)

Executes convergence §6 / scope §1.6 + §W6. Dev only; production out of scope.
Branch `feat/phase-6-pseudo-platforms` off `origin/development`.

## Entry state, measured on dev (`glncumufgaqcmqhzwrxm`) before touching anything

```
partna.custom_link   23 live (25 rows)    platform 'custom'
partna.order_link    10 live (10 rows)    platform 'online-ordering'
partna.storefront     6 live ( 6 rows)    platform 'shop'
partna.reserve_link   2 live ( 3 rows)    platform 'reservations'
partna.manual_event   0 live ( 1 row)     platform 'events-custom'
partna.booking_link   0 live ( 0 rows)    platform 'booking'
                     41 live  — matches scope §1.6 exactly
```

Ordering brand surfaces ALREADY carry live connections (showcase-eats seed rows,
`source: showcase`, created 2026-07-27): `uber_eats.order` 1, `doordash.order` 1,
`menulog.order` 1, `square.order` 1. `doordash.order` is proven end to end —
Phase 5's 30 `menu_item` rows came through it.

## Owner rulings taken 2026-08-16 (recorded before execution)

1. **Ordering surfaces stay single-store.** Ollies' second Uber Eats store
   (`doc-pizza-…-carlton`) becomes a links-pool item. No `multiAccount()`, no
   change to the `order:{platform}` collection natural key.
2. **A row with no working brand home becomes a links-pool item, provider label
   preserved.** Applies to `hungryjacks.app.link` (no brand exists anywhere),
   errols' `opentable.com.au/restref/…` (surface exists, but the row carries no
   `rid` so the widget would render broken), and ollies' SevenRooms row.
3. **OpenTable wins ollies' reservation slot**; SevenRooms drops to a link. This
   restores the single-slot reservations invariant, currently violated in dev data.
4. **The shop marker splits into one connection per store** on its real brand
   surface (`shopify.store` / `woocommerce.store`). `partna.storefront` is fully
   retired. Every live storefront on dev is shopify (46) or woocommerce (2), so
   every one has a real destination.

### Decisions this session owns (not owner calls)

- **Slug spelling.** `uber_eats` / `doordash` / `menulog` (underscore) are
  canonical as brand + legacy-platform keys — the generated `platform` alias
  column already produces exactly these for the live showcase rows. The hyphen
  spellings stay untouched where they are a STORED natural key
  (`content.collections.external_ref = 'order:uber-eats'`,
  `site.menu_platform_links.platform`, `config('partna.menu.platforms')` keys),
  because renaming a live ref rewrites every collection's key — the reason slice
  4 declined to unify them. One mapping seam + a lockstep test, not a data rewrite.
- **Display names.** No new vocabulary is minted. The catalog already carries
  `displayName` per surface (`Uber Eats`, `DoorDash`, `Menulog`, `Bopple`,
  `OrderMate`, `Square`); the pool wire publishes that where it publishes
  `name: null` today.

## Verified before starting

- **`RoutingCapabilityGate` keys on `routing_class` only** — `denialFor()` is a
  match on the class string (`booking`/`reservations`/`ordering`), never on
  platform or surface. Every replacement surface carries the same class, so
  gating cannot weaken. Pinned by a test in Unit 6.
- **Decision 10 is already superseded**, contrary to a stale memory.
  `RegistryCoverageTest` asserts `registry keys == LegacyPlatformMap` (78 slugs)
  and its comment says "the old hand-list here encoded Decision 10, which plan §0
  supersedes." Green today (10/10). That test also FORCES map↔registry lockstep,
  so dropping the six pseudo PDs requires dropping their map entries in the same
  change.
- **Every host the harvester classifies already has a catalog surface** — 14/14
  ordering, 12/12 reservations, 18/18 booking. So the shared keys are replaced
  per-brand rather than demoted; no brand loses its routing class.

## Disposition of all 41

| Surface | n | Destination | Status |
|---|---|---|---|
| `partna.custom_link` | 23 | `custom_links` pool | already migrated (Phase 3), 23 ↔ 23 items 1:1 across 9 users; Unit 4 moves the WRITE path |
| `partna.storefront` | 6 | `shopify.store` / `woocommerce.store`, one per store | 5 have storefront collections + products; `spinosaurus…` is an empty marker (0/0) → deleted, not migrated |
| `partna.order_link` | 10 | see below | 8 to brand surfaces, 2 to links pool |
| `partna.reserve_link` | 2 | see below | both to links pool (rulings 2 + 3) |
| `partna.booking_link` | 0 | — | nothing to migrate |
| `partna.manual_event` | 0 | — | nothing to migrate |

Order links in detail:

| user | host | destination |
|---|---|---|
| broken-oven | `…square.site` | `square.order` |
| broken-oven | `ubereats.com` | `uber_eats.order` |
| errols | `ordermate.online` | `ordermate.order` |
| fable-sevenrun | `hungryjacks.app.link` | **links pool** (no brand exists) |
| fable-sevenrun | `ubereats.com` | `uber_eats.order` |
| fred-sarson | `ubereats.com` | `uber_eats.order` |
| ollies | `ubereats.com` (universal-restaurant) | `uber_eats.order` |
| ollies | `doordash.com` | `doordash.order` |
| ollies | `bopple.app` | `bopple.order` (detector widened to `.app`) |
| ollies | `ubereats.com` (doc-pizza) | **links pool** (ruling 1 — second store) |

## Units

- [ ] **1 — Close the public-allowlist gap and promote the three brands.**

  **`LegacyPlatformMap` and `PlatformRegistry` are FROZEN at 78 slugs and must
  not gain entries.** `CatalogLegacyMapTest::matches the backfill migration CASE
  pair-for-pair` asserts `TO_SURFACE ∪ RETIRED` equals the historical
  `20260727110001` CASE exactly, and `RegistryCoverageTest` chains the registry
  to the same 78. Brands added since P1 live in the **catalog** — which is
  already the write authority (`isKnownSurface()` falls through to
  `CompiledCatalog`). Writers therefore pass the SURFACE KEY
  (`uber_eats.order`), which `setPlatformAttribute()` accepts verbatim.

  **Live defect this exposes, fixed here.** `PublicIntegrationConnectionResource
  ::ALLOWLIST` is keyed on the legacy `platform` string and is fail-closed, but
  `PublicAllowlistCoverageTest` only iterates `PlatformRegistry::keys()` — the
  same frozen 78 — so it structurally cannot see a catalog-only brand. On dev
  today `showcase-eats` publishes `doordash`, `menulog`, `uber_eats` and
  `shopify` as EMPTY payloads and reports `MissingPublicAllowlistException` on
  every public request (Nightwatch #436, reproduced 2026-08-16 04:47Z against
  `dev-api.partna.au`). `square-ordering`, which has an entry, renders fine.

  Work: allowlist entries for every connectable catalog brand, keyed by brand
  prefix, on the existing CardPayload key set; widen the coverage test to
  iterate catalog brands so the gap cannot reopen; drop `notConnectable()` from
  `uber_eats.order` and `menulog.order`.
- [ ] **2 — Per-brand classification.** `WebsiteLinkHarvester` gains
  `ORDERING_PLATFORM`; `BOOKING_PLATFORM` / `RESERVATION_PLATFORM` shared values
  flip to per-brand slugs. `bopple.app` added to `ORDERING_HOSTS`.
- [ ] **3 — LinkRouter drops the shared-key seeders.** `seedOnlineOrdering`,
  `seedReservation` and `seedBooking`'s shared-key arm write the brand surface.
  `Platform` enum loses `Custom` / `Booking` / `Reservations` / `OnlineOrdering`.
- [ ] **4 — Close the six write paths.** Custom links and custom events mint pool
  items instead of connections; booking/reservations custom fallbacks go to the
  links pool; ordering writes a brand connection; shop writes one connection per
  store.
- [ ] **5 — Migration command** (`content:retire-pseudo-platforms`), idempotent,
  `--dry-run`, coverage gate derived twice.
- [ ] **6 — Tests, capability audit, checkpoint.** Includes a test pinning
  `RoutingCapabilityGate`'s routing_class keying, and the
  `account-capability-audit` skill against everything touched.

## STATUS as of 2026-08-16 (handoff)

`feat/phase-6-pseudo-platforms`, NOT merged. `development` is untouched and green.

**Green and committed**
- `536989b5b` — Unit 1. Allowlist gap closed (36 catalog brands), coverage guard
  widened to catalog surfaces and proven to fail, `uber_eats.order` +
  `menulog.order` promoted, artefact recompiled.
- `9b4e383cc` — Units 2–3. Per-brand classification, per-brand router seeders,
  booking XOR + reservations single-slot moved onto `routing_class`,
  `write()` re-keyed onto `surface_key`. 2265 passed / 9040 assertions.

**Unit 4, IN PROGRESS — working tree is RED. Do not merge.**

The custom-link half is built: `LinkPoolWriter`, `LinkPoolReader`,
`EnrichPoolLinkJob` (ports the title enrichment the pool hand-add never had),
`CustomLinksController` rewritten onto the pool with its JSON shape unchanged,
`CustomLinkSeeder::writeCard()` redirected onto the same lane keeping its cap
lock. PHPStan clean on all of it. 36 tests converted and passing across
`CustomLinksControllerTest`, `CustomLinksPayloadTest`,
`StravaAndCustomLinksContractTest`, `CustomLinkSeederLockTest`,
`CustomLinkSeederPreviousWebsiteTest`, `LinkInBioScanJobTest`.

Still red, both for the same two reasons — the users have no `site` (a pool item
needs a section, which hangs off the site), and the assertions read connection
rows:
- `tests/Feature/Platforms/CustomLinkSeederTest.php` — 4 cases.
- `tests/Feature/Routing/RoutingEndpointTest.php` — ~5 cases. These also assert
  the Note-link response shape, which came from the connection row; the endpoint
  itself may need its 'busy'/423 branch revisited since `writeCard` still returns
  that status but no row.

**Not started**
- online-ordering, booking/reservations and events-custom write paths.
- The shop split into one connection per store (owner ruling 4 / option B) — the
  largest remaining piece, and it overlaps slice 7's scheduled rework of
  `site.shop_brands`.
- Unit 5 migration command; Unit 6 guard activation, capability audit, checkpoint.

**Two behaviour changes already made that belong in the checkpoint**
- A pool link publishes `favicon: null` and `logo: null`. Phase 3's ruling,
  restated — dashboard link cards lose their brand marks. Pinned by a test so it
  reads as a decision, not a regression.
- A SITELESS user can no longer hold a custom link. The connection lane allowed
  it; a pool item cannot exist without a section. `writeCard()` returns
  `cap_full` for that case rather than failing silently.

## Exit criteria

- No write path can create a `partna.*` connection; guarded by a test.
- All 41 migrated or dispositioned in writing.
- Suite green; PHPStan clean; Pint clean; the other eight CI jobs enumerated.
- Checkpoint with live SQL into the parent spec
  (`2026-08-11-content-pool-convergence-design.md`).
