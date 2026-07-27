# P8 deletion readiness

Companion to `2026-07-27-content-platform-rebuild.md` §18. This is the
checklist that decides when the old pipeline can actually be deleted — not a
restatement of the plan's intent, but the measured state of its preconditions.

**The rule this exists to enforce:** nothing is deleted while anything still
calls it. A deletion that "should be fine" is how a platform loses a feature
nobody noticed was load-bearing.

## Verdict (2026-07-28): P8 CANNOT PROCEED

Not "not yet scheduled" — blocked on capabilities that were never built. The
new pipeline cannot answer questions the old one answers today, so migrating
its consumers would remove working behaviour rather than replace it. The
blockers are named below with the precise missing piece. Anyone picking this up
should read §"Hard blockers" first and resist the temptation to migrate the
easy consumers in isolation: two of the blockers (payload parity, tombstone
backfill) are *ordering* constraints that make an otherwise-safe migration
unsafe if done first.

## Measured footprint

Run `php artisan rebuild:footprint` for current numbers. At 2026-07-28:

| | Files | Lines |
|---|---|---|
| Added by the rebuild | 212 | 17,076 |
| Replaced, still live | 184 | 27,227 |
| **Net (backend only)** | | **−10,151** |

That −10,151 is the *prize*, not the current state. None of the 184 replaced
files has been deleted, because none of their consumers has been migrated.

## Hard blockers — missing capability, not missing effort

1. **Runtime probe execution.** `probe_capability` exists only as compiled
   catalog metadata (`app/Catalog/Detector.php:30`); its sole runtime reader is
   `CatalogSyncCommand` persisting it to a column. The planned `LinkProbeWorker`
   / `ProbeGate` / `ProbeBudget` do not exist. Nothing in the new pipeline can
   answer "is this URL a Shopify/Woo/Squarespace/BigCartel storefront" or
   produce the `detected` array. **Blocks ShopController, CommerceProbeJob,
   ShopBrandSeeder/Profiler.**
2. **Brand plane / store seeding.** No successor to `ShopBrandSeeder`'s
   ShopBrand-row creation; `app/Catalog/Brand.php` is a value object.
3. **Bio-harvest importer.** `routing.import_runs` already accepts
   `kind='bio_harvest'`, but both existing importers fetch a *page* and
   `InstagramAutoSync` supplies a *list* of URLs. **Blocks InstagramAutoSync.**
4. **Findings / synced-modal contract.** New-pipeline conflicts become Hold
   intents behind `GET /api/routing/suggestions`, which the dashboard has not
   mounted. Nothing folds them into `payload.syncFindings`, which the existing
   Instagram modal and its applySync swap flow read. **Blocks LinkInBioScanJob
   and InstagramAutoSync semantically** — they would migrate "successfully" and
   silently stop telling users about conflicts.
5. **Soft-delete tombstone backfill.** Legacy refusal is a soft-deleted
   connection; new refusal is `routing.item_tombstones`
   (`PlacementPolicy.php:123`). No migration backfills the new table from
   historical soft-deletes. **Migrating any scan path before this backfill can
   resurrect connections users deliberately deleted.** This is the blocker with
   real user-visible harm, and it is invisible in tests.

**Payload parity — RESOLVED 2026-07-28.** `SourceReconciler` wrote
`{url, source}` and never `username`, while the public allowlist emits
`{username, url}` for socials and Instagram renders from `username` alone. Both
writers now go through `App\Routing\ConnectionPayload`. This was found live: the
showcase accounts served `"payload":[]` for all 20 connections and every
platform page rendered blank. It was invisible to 5,969 passing tests because
nothing asserted what reaches the public wire — `tests/Unit/Routing/ConnectionPayloadTest.php`
now does.

## Blocking: live consumers of the legacy router

`LinkRouter` / `ProviderDetector` cannot be deleted until every one of these is
on `LinkRoutingService`. **Status: 9 consumers, 0 migrated.**

| Consumer | Successor | Difficulty |
|---|---|---|
| `CustomLinksController::addLink` | `POST /api/routing/links` (built, wire-compatible) | TRIVIAL |
| `ReservationsController` | `preview()` + `LegacyPlatformMap::legacyFor`; XOR stays caller-owned | MODERATE |
| `BookingController` | same | MODERATE |
| `LinkInBioScanJob` | `LinkInBioImporter` (built) + blocker 4 | MODERATE |
| `InstagramAutoSync` | needs blocker 3 + blocker 4 | HARD |
| `CommerceProbeJob` | needs blocker 1 | HARD |
| `ShopBrandSeeder` / `ShopBrandProfiler` | needs blockers 1 + 2 | HARD |
| `ShopController` | needs blocker 1 | HARD |
| `PublicIntegrationConnectionResource` | no call site — shape coupling only | MODERATE |

**The plan's list is incomplete.** These also reference classes slated for
deletion and are not among the nine: `CustomLinkSeeder`, `EventsCatalog`,
`GoogleBusinessAutoSync`, `GoogleBusinessEnrichJob`,
`ScanPreviousWebsiteContentJob`, `OnboardingSuggestions`. In particular
`WebsiteLinkHarvester` **cannot** be deleted at step 3 even after all nine
migrate — the new pipeline's own `WebsiteImporter` and `LinkInBioImporter`
inject it for `allOutboundLinks()`. Its harvest function must move into the new
namespace first.

## Frontend

`lib/catalog.ts` and `lib/routing.ts` are live: the routing link-add sheet is
**mounted** on `/account/custom-links` as of 2026-07-28, so `POST /routing/links`
now has a real production caller.

`lib/social/platforms.ts` (565 lines) still cannot be deleted — its remaining
consumers are type/icon readers and the early-access forms, not add-link UIs.
`git grep -l "lib/social/platforms"` is the live list.

## Ready to delete NOW

Nothing. Re-check with:

```bash
php artisan rebuild:footprint
git grep -l "LinkRouter\|ProviderDetector" -- app/
```

## Order of operations when the above clears

1. Backfill `routing.item_tombstones` from historical soft-deletes (blocker 5)
   — before any scan path migrates, not after.
2. Decide the findings contract (blocker 4): fold Hold intents into
   `payload.syncFindings`, or mount the suggestions surface and retire the
   modal. Do not migrate a scan path until this is answered.
3. Migrate `CustomLinksController` — smallest real write-path switch. Add a
   routed-branch test to the legacy endpoint first; it currently has none, so
   there is nothing to prove parity against.
4. Reservations, then Booking. XOR/single-slot stays controller-owned; the race
   suites are the parity oracle.
5. `LinkInBioScanJob`, then `InstagramAutoSync` (needs blocker 3).
6. Build the probe runtime (blocker 1) + brand plane (blocker 2). Write
   `CommerceProbeJob::handle()` and `ShopBrandSeeder` characterization tests
   first — neither has any today.
7. Shop consumers last.
8. Move `allOutboundLinks()` into the new namespace, then delete
   `WebsiteLinkHarvester`.
9. Re-run `rebuild:footprint`; record the final number in the execution log.

## Retired in this phase

- **Pinterest** (owner decision, 2026-07-28): brand, surface, connector, legacy
  scraper/strategies/resource, registry entry, config, frontend references,
  design-system assets and page taxonomy. Data soft-deleted by
  `20260728100000`. The historical backfill CASE in `20260727110000`
  deliberately still names it — see `LegacyPlatformMap::RETIRED` for why.
