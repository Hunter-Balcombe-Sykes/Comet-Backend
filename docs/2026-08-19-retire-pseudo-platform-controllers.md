# Retire the pseudo-platform link lane (plan, 2026-08-19)

**Status: APPROVED by owner 2026-08-19, not started.** Branch:
`feat/suggestion-swap-and-link-caps` (already carries the Swap + cap
commits this plan builds on). Ledger this continues:
`docs/plans/2026-07-28-p8-deletion-readiness.md`.

Owner ask (verbatim intent): "we can't keep having legacy — remove
OnlineOrderingController, BookingController, ReservationsController and
everything that treats ordering / booking / reservations / custom links /
custom events as their own pseudo-platform, so they go through the proper
platform setup like everything else. A second Uber Eats must NOT become a
links-pool item: manual → just don't connect it; auto → offer it as a Swap
suggestion. Retire the synced-findings modal too — the inbox is the one
place. Standalone events stay a feature."

## Goal

ONE write path for a routed link — `LinkRouter`/`LinkRoutingService` →
`SourceReconciler` → brand surface — with the router's cap / conflict /
Swap machinery as the ONLY behaviour when a slot is taken, and ONE
suggestion surface (`GET /routing/suggestions`, the "Found on your
platforms" inbox). Delete the category controllers, their routes, the
`partna.*` link pseudo-surfaces, the "owner ruling 2A" overflow into the
links pool, and the per-platform synced-findings modal.

## Owner rulings recorded (2026-08-19)

| # | Ruling |
|---|---|
| R1 | OpenTable-from-Google-listing suggestion folds into `/routing/suggestions`. One inbox, one call. |
| R2 | Manual connect of a second store on a single-account brand → **422 `slot_taken`** + the connect sheet offers an inline **Swap** (same accept call the inbox uses). Nothing written on refusal. |
| R3 | `CustomLinksController` + `partna.custom_link` + Phase-3 backfiller: delete in this pass. |
| R4 | `EventsController` facade + `events-custom` + `partna.manual_event`: delete in this pass. |
| R5 | Ruh's four overflow rows: delete the ones whose URL equals a live connection on the same user; enrich the rest. |
| R6 | **Retire the synced-findings modal** (Instagram / Google Business `/synced` + `/apply`); its findings surface in the inbox instead. |
| R7 | **Standalone events stay a feature** (a pasted Eventbrite/Humanitix EVENT link, not an organiser). Implementation assumption (owner to veto): they become **events-pool items via `ManualEventWriter`**, not `event-*` connection rows; existing rows convert one-off. |
| R8 | Enum / legacy-map cuts touch `PlatformConnectRequest` and the DSAR + public allowlists — edited in the same commit, flagged as privacy-facing. |

## State of the world (measured, dev DB, 2026-08-19)

- `site.platform_connections` live rows on `partna.custom_link` /
  `partna.order_link` / `partna.booking_link` / `partna.reserve_link`: **0**.
  Every ordering / booking / reservations connection is on its brand
  surface (Phase 6). `partna.manual_product`: 1 (shop lane, out of scope).
- Dashboard callers into the doomed controllers: **one** —
  `GET /platforms/reservations/suggestion` (`useReservationSuggestion` → the
  OpenTable row in the inbox). `/detect`, `/status`, `/forget`,
  `/online-ordering/entries`, `/platforms/custom/*`, `/platforms/events/*`:
  **none**.
- The synced-findings modal IS live: `platform-sheet.tsx` →
  `synced-findings.tsx` → `GET/POST /platforms/{instagram|google-business}/synced`.
- The 2A overflow (`$this->pool->add()` after a taken slot) exists ONLY in
  the three controllers. The router lane already answers a taken slot with
  `cap_reached` / `conflict` intents → **Swap** (this branch).
- Ruh's four no-media Bopple / Uber Eats pool links: `manual`-source, first
  seen 2026-08-17 (backfill window), never enriched.
- `php artisan rebuild:footprint`: **33,182 lines** "replaced, still live".
  This pass retires the link-routing slice; the rest is Tier B below.

## Phases

### Phase 1 — Behaviour (on the open branch)
1. `GenericPlatformController` / `ManagesIntegrationConnection`: manual
   connect of a second account on a surface with `max_accounts = 1` in the
   ordering / booking / reservations classes → **422**
   `{code:'slot_taken', incumbentConnectionId, displayName}`. No pool write.
2. `SourceReconciler`: unchanged (cap → `cap_reached` + sole incumbent →
   Swap; class XOR → `conflict` → Replace). Confirm `GoogleBusinessAutoSync`'s
   ordering / booking / reservations seed records a `cap_reached` intent via
   the reconciler on a taken slot instead of skip-and-log — that is what makes
   "auto → Swap" TRUE for the Google path. If that requires the GB harvest to
   move onto `LinkRoutingService`, do the ordering/booking/reservations arm
   only here and leave the rest of GB for Tier B.
3. Tests: `SuggestionsInboxTest` (already has swap), a `slot_taken` test on
   the generic connect, a GB taken-slot → intent test.

### Phase 2 — Enrichment (favicon / share image / description)
1. `LinkPoolWriter::add()`: on CREATE with no media and no body, dispatch
   `EnrichPoolLinkJob` after commit (guard: the job's own `add()` call passes
   images / body, so it never re-dispatches). Every manual-lane writer
   (dashboard Add, bio import, GB, ordering fallbacks) gets it for free;
   remove the per-caller dispatches that become redundant.
2. `php artisan content:enrich-pool-links {--user=} {--missing}`: one-off
   for existing links with no cover/logo media or empty body.
3. R5: `content:prune-overflow-links --dry-run` → delete pool links whose
   canonical URL equals a live connection's URL for the same user; run on
   dev for Ruh, report counts.

### Phase 3 — Inbox absorbs the other suggestion sources (R1, R6)
1. `SuggestionsController::index` gains two read-time folds, shaped as
   ordinary suggestion rows (`surfaceKey`, `brandKey`, `identifier`, `url`,
   `question`, `actions`):
   - OpenTable-from-Google-listing (`OpenTableService::suggestionFromGoogleBusiness`)
     → `actions: ['accept','dismiss']`; accept = the OpenTable connect.
   - Legacy `payload.syncFindings` for Instagram + Google Business
     (`BuildsAutoSyncFindings` shape) → the modal's rows; `SyncFindingsBridge`
     already folds router intents INTO the modal — invert it: the inbox is
     the sink. Accept goes through `SuggestionApplier` (intent-backed) or the
     synced/apply logic (payload-backed) — one `accept` endpoint, branch on
     the row's `source`.
   - Dismiss writes the same tombstone the inbox writes today.
2. Delete `GET/POST /platforms/{p}/synced*` routes + controller methods,
   `SyncFindingsBridge` (its fold moves), dashboard `synced-findings.tsx`,
   `useSyncedFindings` / `applySyncedFinding`, and the modal mount in
   `platform-sheet.tsx`.
3. Tests: `SyncFindingsFoldTest` becomes `SuggestionsInboxFoldTest` (same
   scenarios, new endpoint). No scenario deleted.

### Phase 4 — Delete (R3, R4)
1. Controllers: `OnlineOrderingController`, `BookingController`,
   `ReservationsController`, `CustomLinksController`, `EventsController`
   (facade only — `EventbriteController` / `HumanitixController` /
   `EventsPlatformController` STAY: brand connect + fetch).
2. Routes: `booking`, `reservations`, `online-ordering`, `custom`, `events`
   prefixes in `routes/api/platforms.php`.
3. `Partna.php`: drop `custom_link`, `booking_link`, `reserve_link`,
   `order_link` (if declared), `manual_event`. Keep `manual_product`,
   `storefront`. `php artisan catalog:compile`.
4. `Platform` enum: drop `OnlineOrdering`, `Reservations`, `Custom`,
   `Booking`; `GoogleBusinessAutoSync::BOOKING_PLATFORMS` /
   `RESERVATION_PLATFORMS` and `BuildsAutoSyncFindings::*_SLOT_PLATFORMS` key
   on real platforms only (or go, if Phase 3 empties them).
5. `LegacyPlatformMap`: drop `custom`, `events-custom`, `booking`,
   `reservations`, `online-ordering` (→ `partna.*`) and their
   `SPECIAL_TO_LEGACY` mirrors; keep `shop`.
6. `CustomLinkSeeder`: keep ONLY the pool-write path used by
   `LinkInBioImporter`; the connection-writing `seed()` / `seedCustom()`
   halves and every caller (`InstagramAutoSync`, `CommerceProbeJob`,
   `LinkRouter` custom arm) become `LinkPoolWriter::add()`. `RouteContext`
   goes with them (the importer has `ImportRun`).
7. R7: `EventsSeeder` / `EventsCatalog` standalone-event arm → write an
   events-pool item via `ManualEventWriter`; `content:convert-standalone-events`
   one-off for existing `event-*` rows; the `resource_kind='event'` read
   arms go. `StandaloneEventBackfill*` (Phase 3 tool) deleted.
8. `CustomLinkBackfiller` + `BackfillCustomLinks` deleted.
9. `PlatformRegistry` / `PlatformDescriptor` / `DerivedDescriptorFactory`:
   remove the pseudo-platform descriptors. R8: `PlatformConnectRequest`,
   DSAR export allowlist, public allowlist — edited in the same commit.
10. Tests: delete `BookingXorControllerLockTest`,
    `ReservationsXorControllerLockTest`; re-home the Fresha half of
    `FreshaForgetXorLockTest`; edit (not delete) the CustomLinks / Events /
    pseudo-slug halves of `PublicIntegrationAllowlistTest`,
    `SessionA3LockTest`, `SessionA4LockTest`, `NoUntypedPayloadAccessTest`,
    `SectorCapabilityGatingTest`, `EnrichLinkCardJobLockTest`,
    `DastIdorCoverageDriftTest`, `PlatformControllerConvergenceTest`,
    `CatalogArtefactTest`.
11. Comment-only references (Fresha / Square / LinkRouter / GBAutoSync) —
    reword where they name a deleted method as the owner of a behaviour.
12. Gate: `php artisan test` green;
    `php artisan route:list | grep -E "booking|reservations|online-ordering|custom/links|platforms/events|/synced"`
    empty; `rebuild:footprint` re-run and the number recorded in the P8 ledger.

### Phase 5 — Dashboard
1. `lib/queries/platforms.ts`: delete `useReservationSuggestion`,
   `useSyncedFindings`, `applySyncedFinding`; `RoutingSuggestion` gains
   `source: 'router' | 'google_listing' | 'sync_finding'` if the wire needs it.
2. `suggestions-inbox.tsx`: drop the bespoke reservation row (it is an
   ordinary row now).
3. `connection-sheet.tsx`: 422 `slot_taken` → step-3 state "You already
   have an Uber Eats" with **Swap** (accept on the incumbent's intent, or a
   direct replace call — decide with the backend shape) and **Keep mine**.
4. Delete `synced-findings.tsx` and its mount in `platform-sheet.tsx`.
5. Delete orphan `lib/data/{events,links,listen,media,menu,sell,services,watch}.ts`;
   drop the pseudo-platform IDENTITIES (`online-ordering`, `custom`,
   `booking`, `reservations`, `events-custom`) from `lib/data/platforms.ts`
   — keep them as CATEGORY keys in `lib/data/connect.ts`.
6. Gate: `npx tsc --noEmit` 0, `npx eslint .` 0 errors, `/platforms`,
   `/links`, `/events` 200, 375px no sideways scroll.

### Phase 6 — Deploy order
Backend branch → `development` (Laravel Cloud) FIRST, then the dashboard
push. In between, the dashboard tolerates both shapes: a missing
`/reservations/suggestion` = no row; the modal simply shows nothing new.
Migration one-offs (`enrich-pool-links --missing`, `prune-overflow-links`,
`convert-standalone-events`) run on dev after deploy, counts recorded here.

## Files in scope (NOTHING else)

Backend delete: the five controllers; `CustomLinkBackfiller`,
`BackfillCustomLinks`, `StandaloneEventBackfill*`; `RouteContext`;
`SyncFindingsBridge` (after its fold moves).
Backend edit: `routes/api/platforms.php`, `Catalog/Definitions/Partna.php`,
`Registry/Platform.php`, `Catalog/LegacyPlatformMap.php`,
`Registry/{PlatformRegistry,PlatformDescriptor,DerivedDescriptorFactory}.php`,
`Requests/Platforms/PlatformConnectRequest.php`, DSAR + public allowlists,
`GenericPlatformController.php`, `Concerns/ManagesIntegrationConnection.php`,
`Routing/SuggestionsController.php`, `Services/Content/LinkPoolWriter.php`,
`Services/Platforms/{CustomLinkSeeder,LinkRouter,GoogleBusinessAutoSync,InstagramAutoSync,EventsSeeder,EventsCatalog}.php`,
`Services/Platforms/Concerns/BuildsAutoSyncFindings.php`,
`Jobs/Platforms/CommerceProbeJob.php`, `Jobs/Content/EnrichPoolLinkJob.php`,
new commands under `Console/Commands/`, tests listed in Phase 4.10.
Dashboard: files listed in Phase 5.

## Verification (owner-visible)
- `route:list` shows none of the retired prefixes.
- Connect a second Uber Eats manually → 422, sheet offers Swap / Keep;
  nothing in the pool, no connection.
- Bio import / GB sync finding a second Bopple → inbox row with **Swap**.
- Instagram / Google Business findings appear in the inbox; the platform
  sheet has no "synced" modal.
- Ruh's Bopple links carry favicon + share image + description; the
  duplicates of live connections are gone.
- Public sitepage "Order online" / "Book" / events actions unchanged
  (`SiteActionsService` reads by `routing_class`; events read the pool).

## Tier B — legacy still LIVE, blocked on a successor (NOT this pass)
Kept on the board; each is a P8 ledger row.
| Item | What blocks it |
|---|---|
| `LinkRouter` + `ProviderDetector` + `WebsiteLinkHarvester::classify()` | still routes for `InstagramAutoSync`, `GoogleBusinessAutoSync` (beyond the arm Phase 1 moves), `CommerceProbeJob`, `ShopController`. `WebsiteLinkHarvester::allOutboundLinks()` is used by the NEW importers — must move into `app/Routing` first |
| `InstagramAutoSync` / `GoogleBusinessAutoSync` full migration onto `LinkInBioImporter::import(kind='bio_harvest')` | next P8 unit; Phase 1.2 does only the taken-slot arm |
| Shop lane: `ShopController`, `ShopBrandProfiler`, `ShopProviderDetector`, `CommerceProbeJob` | four of five storefront probes not ported to `app/Routing/Probes`; `ShopBrandProfiler` has no successor |
| `PublicIntegrationConnectionResource` | legacy public wire shape; separate cut |
| Per-platform services (161 files, 24.7k lines: bespoke connect controllers + scrapers + strategies) | these ARE the live connect + fetch paths the connect sheet uses; not link-routing |
| `payload` JSONB on `site.platform_connections` (§18 dissolution) | separate programme |
| Instagram-at-1 (`multiAccount(5)` → 1 for socials?) | separate owner call; changes the manual connect's route shape too |

## Out of scope
`partna.manual_product` / `partna.storefront`; everything in Tier B; the
frozen `Partna-Frontend`.

## If you get stuck
Write `docs/blocked-2026-08-19-<phase>.md` with the exact test / route /
row that refused, and stop. Do not widen scope to get past it.

---

# EXECUTION STATE — paused 2026-08-19, resume here

Branch: `feat/suggestion-swap-and-link-caps` (Comet-Backend). Dashboard work
is on `partna-monorepo` `main`, already pushed.

## Done and committed (backend branch)

| Commit | What |
|---|---|
| `e253f9ef6` | Swap for cap-blocked links on single-account surfaces; 13 content/events surfaces lose the 1-account cap; `catalog:compile` re-run |
| `28727547a` | A cap-blocked suggestion re-settles against the cap as it is now (catalog widened → back to a plain Add) |
| `cde935ae9` | **Phase 1** — manual second store on a filled slot → 422 `slot_taken` (+ incumbent id/url, `replace=true` to swap); auto → `cap_reached` intent naming the incumbent → Swap in the inbox; GoogleBusinessAutoSync stops pooling on BOTH arms; golden master 139 → 150 routes |
| `7c4e29bb4` | **Phase 2** — enrichment moved into `LinkPoolWriter::add()` (any write with no media and no body reads the page; job's write-back passes `enrich: false`); `content:enrich-pool-links` (dev dry-run: 24) and `content:prune-overflow-links` (dev dry-run: 2 of 65) |
| _this commit_ | **Phase 3** — inbox absorbs both other suggestion surfaces; `/synced` endpoints deleted; 8 test files migrated |

Dashboard (monorepo `main`, `adc8047`): links-table marks fit their cell,
connect-sheet `/links?url=` hand-off lands on step 2, inbox rows read
`Not now` (muted) → `Add`/`Swap` (solid, right).

## Phase 3 — DONE in this commit
- `SyncFindingsBridge` gained `payloadSuggestions()` / `locatePayloadFinding()`
  / `settlePayloadFinding()`: legacy `payload.syncFindings` CONFLICTS fold into
  `GET /routing/suggestions` as rows addressed `sync:{holder}:{platform}`.
  Seeded findings deliberately do NOT fold (settled work; Platforms shows it).
- `SuggestionsController` also folds the standing Google-listing OpenTable
  offer as `listing:opentable` (dismissal → tombstone `opentable.reserve:google-listing`).
  Deduped by surfaceKey — an intent wins over a payload finding for the same surface.
- `accept`/`dismiss` branch on the id shape; the payload settle keeps the
  platform-connection lock (423 on contention) that `applySync` had.
- `SuggestionApplier::applyDirect()` added for the listing (no intent behind it).
- Routes `/platforms/{instagram,google-business}/synced[/apply]` DELETED, plus
  their controller methods (`synced`, `applySync`, `shapeFinding`,
  `applyIntentConflict`). `whereUuid` relaxed on the suggestion routes.
- Tests migrated, no scenario dropped: `InstagramSyncedTest` →
  `InstagramFindingsInboxTest` (12), `SyncFindingsFoldTest` →
  `SuggestionsInboxFoldTest` (7), plus `AutoSyncApplyCapabilityDenialTest`,
  `BookingXorConnectRaceTest`, `SessionAControllerLockTest`,
  `InstagramControllerLockTest`, `EventSyncFindingsTest`,
  `GoogleBusinessApifyTest` (which also gained `setupRoutingTables()`).

**Last verified state:** Platforms + Routing + Content suites were green
(2183 passed) BEFORE the final pint pass and the last two fold-test edits;
each changed file was run individually green after. **First action on resume:
`php artisan test` and fix anything that fell out** — the full suite has not
been run end-to-end since Phase 3 landed.

## NOT STARTED

### Phase 4 — the delete (the main event)
Everything in "Phases → Phase 4" above, unchanged. Notes gathered since:
- `Platform` enum pseudo-cases are used by `GoogleBusinessAutoSync::{BOOKING,RESERVATION}_PLATFORMS`
  and `BuildsAutoSyncFindings::{BOOKING,RESERVATIONS}_SLOT_PLATFORMS` — those
  lists must drop `Platform::Booking` / `::Reservations` when the cases go, and
  `BookingXorConnectRaceTest` pins them by reflection (it will fail loudly, which
  is the point).
- **`ReservationsController::clearReservations()` is where the reservations
  FAMILY XOR lives for the legacy lane.** Deleting the controller removes it.
  The new lane enforces the same rule (`SourceReconciler::isExclusiveAuto`), but
  check `LinkRouter::seedReservation` — it currently writes single-slot per BRAND
  with no family check. Do not delete without moving that guard.
- `GET /platforms/reservations/suggestion` is now redundant (the inbox carries
  it) — delete with the controller; the dashboard hook goes in Phase 5.

### Phase 5 — dashboard
Unchanged from the plan. `useSyncedFindings` / `applySyncedFinding` /
`synced-findings.tsx` / its mount in `platform-sheet.tsx` are now DEAD against
this backend — Phase 5 is required before the backend deploys, or the platform
sheet will 404 on open.

### Phase 6 — deploy + one-off commands
Backend branch → `development` FIRST (NOT done — needs owner sign-off), then the
dashboard. Then run on dev: `content:enrich-pool-links --missing`,
`content:prune-overflow-links`, and (after Phase 4) `content:convert-standalone-events`.
