# P8 deletion readiness

Companion to `2026-07-27-content-platform-rebuild.md` §18. This is the
checklist that decides when the old pipeline can actually be deleted — not a
restatement of the plan's intent, but the measured state of its preconditions.

**The rule this exists to enforce:** nothing is deleted while anything still
calls it. A deletion that "should be fine" is how a platform loses a feature
nobody noticed was load-bearing.

## Verdict (2026-07-28, revised again): no hard CAPABILITY blocker left on the scan paths

Originally five blockers, all "capabilities that were never built". Four are
now built (B2 brand plane, B3 bio-harvest importer, B4 findings/synced-modal
fold, B5 tombstone backfill); B1 remains half-built (probe runtime exists, one
of five probes does) and still blocks the SHOP consumers only. With B4
resolved (wave-2B), LinkInBioScanJob and InstagramAutoSync may migrate without
silently dropping conflict findings — the remaining gates are the ordering
constraints below (apply the B5 migration first) and per-consumer parity work.

Anyone picking this up should read §"Hard blockers" first and resist the
temptation to migrate the easy consumers in isolation. The two *ordering*
constraints (payload parity, tombstone backfill) that make an otherwise-safe
migration unsafe if done first are both resolved in code — but the tombstone
backfill is a MIGRATION, and it is not yet applied to any ref. See "Before
deletion day".

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

1. **Runtime probe execution — PARTIALLY RESOLVED 2026-07-28.** The runtime now
   exists: `app/Routing/Probes/` (`LinkProbeWorker`, `ProbeGate`, `ProbeBudget`,
   `Probe`, `ProbeOutcome`) with the Shopify own-domain `/meta.json` probe
   delegating to `ShopifyScraper::probeMeta()`. A probe answer re-enters as an
   ordinary `Projection`, so tombstones/capabilities/thresholds apply to a
   probed storefront without reimplementation. **Still open: four of five
   probes** (Woo `/wp-json/wc/store/v1/products`, Squarespace `?format=json`,
   BigCartel `store.json`, generic JSON-LD — all present in the legacy
   `ShopProviderDetector` and ready to port). `ShopController` and
   `CommerceProbeJob` cannot migrate until a merchant on any of those four
   stops being identifiable.
2. **Brand plane / store seeding — RESOLVED 2026-07-28.**
   `App\Services\Brand\StoreBrandSeeder` is the successor: it probes, hands the
   answer to `PlacementPolicy`, lets `SourceReconciler` own the write, and
   writes only the `site.shop_brands` row itself. It contains no tombstone,
   lock or connection-upsert logic — that is the point, and
   `StoreBrandSeederTest` proves the refusal is still honoured. `ShopBrandProfiler`
   still has no successor (product-catalog derivation is a separate concern).
3. **Bio-harvest importer — RESOLVED 2026-07-28.** `LinkInBioImporter::import()`
   accepts `string|list<string>` plus a run kind, recording ONE `import_runs`
   row per batch with a shared dedupe table and a shared link budget.
   `kind='bio_harvest'` now has a writer.
4. **Findings / synced-modal contract — RESOLVED 2026-07-28 (wave-2B).**
   `App\Routing\SyncFindingsBridge` folds Hold intents (state=blocked,
   block_reason=conflict, origin ∈ {bio_harvest, link_in_bio}) into the
   `GET /platforms/instagram/synced` RESPONSE at read time, shaped exactly as
   legacy conflict findings (never written into `payload.syncFindings` — one
   source of truth, nothing to un-ship later). `POST /synced/apply` falls back
   to the intent ledger when no payload finding matches, resolving it through
   `App\Routing\SuggestionApplier` — the same demote/create/settle transaction
   the suggestions inbox uses (extracted from `SuggestionsController::accept`,
   keeping intent application single-writer). Origin-scoped on purpose: the
   Instagram modal owns the bio-scan origins; GoogleBusinessAutoSync's modal
   folds its own origin when that path migrates. Pinned in
   `tests/Feature/Routing/SyncFindingsFoldTest.php`. **LinkInBioScanJob and
   InstagramAutoSync are no longer semantically blocked.**
5. **Soft-delete tombstone backfill — RESOLVED 2026-07-28.**
   `supabase/migrations/20260728120000_backfill_item_tombstones.sql` seeds
   `routing.item_tombstones` from historical soft-deleted connections, with
   three argued exclusions (live sibling / Pinterest retirement / unclaimed
   users). `tests/Postgres/ItemTombstoneBackfillTest.php` runs the migration's
   real SQL off disk; `tests/Feature/Routing/TombstoneResurrectionTest.php`
   pins both shapes of the hazard (re-paste re-creates the connection, re-scan
   re-proposes the intent) next to the fix.
   **Not yet applied to dev or prod** — see "Before deletion day" below.

**Payload parity — RESOLVED 2026-07-28 (amended same day).** `SourceReconciler`
wrote `{url, source}` and never `username`, while the public allowlist emits
`{username, url}` for socials and Instagram renders from `username` alone. Both
writers were routed through `App\Routing\ConnectionPayload`. This was found
live: the showcase accounts served `"payload":[]` for all 20 connections and
every platform page rendered blank. It was invisible to 5,969 passing tests
because nothing asserted what reaches the public wire —
`tests/Unit/Routing/ConnectionPayloadTest.php` now does.
"Both writers" undercounted: `SuggestionsController::accept` was a THIRD
writer, still building `['url','source']` by hand, so an accepted Instagram
suggestion re-created the blank-page payload. Fixed in the P6 round-5 audit
repairs — it now calls `ConnectionPayload::forWrite`, and
`SuggestionsInboxTest` pins the username on an accepted handle-surface
suggestion. Lesson stands: parity claims about "all writers" need a grep, not
a memory.

## Blocking: live consumers of the legacy router

`LinkRouter` / `ProviderDetector` cannot be deleted until every one of these is
on `LinkRoutingService`. **Status: 9 consumers, 0 migrated.**

| Consumer | Successor | Difficulty |
|---|---|---|
| `CustomLinksController::addLink` | `POST /api/routing/links` (built, wire-compatible) | TRIVIAL |
| `ReservationsController` | `preview()` + `LegacyPlatformMap::legacyFor`; XOR stays caller-owned | MODERATE |
| `BookingController` | same | MODERATE |
| `LinkInBioScanJob` | `LinkInBioImporter` (built) + blocker 4 | MODERATE |
| `InstagramAutoSync` | ~~blocker 3~~ + blocker 4 | MODERATE (was HARD) |
| `CommerceProbeJob` | needs blocker 1's remaining four probes | HARD |
| `ShopBrandSeeder` | `StoreBrandSeeder` (built) + blocker 1's remaining probes | MODERATE (was HARD) |
| `ShopBrandProfiler` | no successor — product-catalog derivation, unstarted | HARD |
| `ShopController` | needs blocker 1's remaining four probes | HARD |
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
**mounted** — since the P6 IA rounds it lives on the Platforms index
(`app/(dashboard)/account/platforms/page.tsx`), and `/account/custom-links` is
now only a redirect stub into it — so `POST /routing/links` has a real
production caller.

`lib/social/platforms.ts` (565 lines) still cannot be deleted — its remaining
consumers are type/icon readers and the early-access forms, not add-link UIs.
`git grep -l "lib/social/platforms"` is the live list.

## Ready to delete NOW

Nothing. Re-check with:

```bash
php artisan rebuild:footprint
git grep -l "LinkRouter\|ProviderDetector" -- app/
```

## Before deletion day — operational, not code

The B5 backfill is **written and proven, not applied**. It is a migration, so
it lands with the next `supabase db push`; until it has run against a ref, that
ref still resurrects deletions.

- Apply to dev (`glncumufgaqcmqhzwrxm`), then prod (`edplucmvkcnokyygxqsb`).
  Prod carries no customer data yet, so its run is a no-op — dev's is not.
- **The 30-day window is the deadline that matters.** `PurgeSoftDeleted`
  hard-deletes a soft-deleted connection 30 days after `deleted_at`, so every
  day this sits unapplied is a day of refusals that become unrecoverable. This
  is the one item on the list with an expiry date.
- After applying, spot-check on dev:
  `SELECT count(*) FROM routing.item_tombstones WHERE reason LIKE 'legacy%';`

~~Known consequence to decide on~~ — **DECIDED AND IMPLEMENTED 2026-07-28
(wave-2B):** a direct request wins over a tombstone. `PlacementPolicy` only
consults tombstones when `! $context->isDirectRequest()`, and
`SourceReconciler::applyIntent` deletes the superseded `surface:identifier`
tombstone when a direct re-add applies (a bare-surface refusal stays — it is
wider than the one account being restored). Scan/suggestion origins stay
suppressed. Pinned in `TombstoneResurrectionTest` (both directions) and
`RoutingEndpointTest`; the backfill stays exactly as wide as it was.

## Order of operations when the above clears

1. ~~Backfill `routing.item_tombstones` from historical soft-deletes~~ — code
   landed 2026-07-28; **apply the migration before any scan path migrates.**
2. ~~Decide the findings contract (blocker 4)~~ — decided and built 2026-07-28
   (wave-2B): Hold intents fold into the /synced RESPONSE via
   `SyncFindingsBridge`; the modal stays. See blocker 4 above.
3. Migrate `CustomLinksController` — smallest real write-path switch. Add a
   routed-branch test to the legacy endpoint first; it currently has none, so
   there is nothing to prove parity against.
4. Reservations, then Booking. XOR/single-slot stays controller-owned; the race
   suites are the parity oracle.
5. `LinkInBioScanJob`, then `InstagramAutoSync` — pass the harvested bio URLs
   to `LinkInBioImporter::import($user, $urls, 'bio_harvest')`.
6. Port the remaining four probes into `app/Routing/Probes/` from
   `ShopProviderDetector`'s cascade (Woo, Squarespace, BigCartel, generic
   JSON-LD), each delegating to its existing scraper the way
   `ShopifyStorefrontProbe` does. Write `CommerceProbeJob::handle()` and
   `ShopBrandSeeder` characterization tests first — neither has any today, and
   `StoreBrandSeederTest` is the shape they should take.
7. Shop consumers last. `ShopBrandProfiler` has no successor at all: its
   product-catalog derivation is a separate piece of work from B2's brand-row
   seeding, and nothing in the new pipeline does it.
8. Move `allOutboundLinks()` into the new namespace, then delete
   `WebsiteLinkHarvester`.
9. Re-run `rebuild:footprint`; record the final number in the execution log.

## Retired in this phase

- **Pinterest** (owner decision, 2026-07-28): brand, surface, connector, legacy
  scraper/strategies/resource, registry entry, config, frontend references,
  design-system assets and page taxonomy. Data soft-deleted by
  `20260728100000`. The historical backfill CASE in `20260727110000`
  deliberately still names it — see `LegacyPlatformMap::RETIRED` for why.
