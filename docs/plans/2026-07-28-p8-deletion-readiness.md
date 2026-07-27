# P8 deletion readiness

Companion to `2026-07-27-content-platform-rebuild.md` §18. This is the
checklist that decides when the old pipeline can actually be deleted — not a
restatement of the plan's intent, but the measured state of its preconditions.

**The rule this exists to enforce:** nothing is deleted while anything still
calls it. A deletion that "should be fine" is how a platform loses a feature
nobody noticed was load-bearing.

## Measured footprint

Run `php artisan rebuild:footprint` for current numbers. At 2026-07-28:

| | Files | Lines |
|---|---|---|
| Added by the rebuild | 212 | 17,076 |
| Replaced, still live | 184 | 27,227 |
| **Net (backend only)** | | **−10,151** |

The frontend mirror (`lib/social/platforms.ts`, 565 lines) and the
design-system engines are additional, and are not counted by that command
because it reads only this repo.

## Blocking: live consumers of the legacy router

`LinkRouter` / `ProviderDetector` cannot be deleted until every one of these
is on `LinkRoutingService`. Each needs its own change, because each has
different semantics that the new pipeline must preserve rather than
approximate:

| Consumer | What it uses the old router for | Successor |
|---|---|---|
| `CustomLinksController::addLink` | classify a pasted link, else custom-link fallback | `POST /api/routing/links` (built, wire-compatible) |
| `BookingController` | booking-class routing + XOR | `PlacementPolicy` + `SourceReconciler` |
| `ReservationsController` | reservations routing | same |
| `ShopController` | shop routing + commerce probe dispatch | same + probe capability |
| `LinkInBioScanJob` | classify every unrolled link | `WebsiteImporter` (built) — needs a link-in-bio variant |
| `CommerceProbeJob` | probe-then-classify | probe capability (P7, not yet built) |
| `InstagramAutoSync` | classify bio links | `WebsiteImporter`'s harvest path |
| `ShopBrandSeeder` / `ShopBrandProfiler` | brand identity from a store URL | catalog surface + brand plane |
| `PublicIntegrationConnectionResource` | reads route shape for the wire | catalog surface |

**Status: 9 consumers, 0 migrated.** The new endpoints exist and are tested,
but nothing has been switched over — deliberately, since each switch is a
behaviour change that wants its own verification against the showcase pair.

## Blocking: the frontend still reads the old shapes

`lib/catalog.ts` and `lib/routing.ts` exist and are tested, and the sheet
flow is built, but **nothing is mounted**. Until the dashboard actually calls
the new endpoints, deleting the old ones removes a working feature.

`lib/social/platforms.ts` (565 lines) can only be deleted once every consumer
reads the catalog instead — `git grep -l "lib/social/platforms"` in the
frontend repo is the live list.

## Ready to delete NOW (nothing references them)

Nothing yet. Re-check with:

```bash
php artisan rebuild:footprint
git grep -l "LinkRouter\|ProviderDetector" -- app/
```

## Order of operations when the above clears

1. Migrate the 9 consumers, one change each, showcase pair green after every one.
2. Mount the frontend surfaces; delete `lib/social/platforms.ts` once its
   consumer list is empty.
3. Delete `LinkRouter`, `RouteResult`, `RouteContext`, `ProviderDetector`,
   `WebsiteLinkHarvester`, `LinkInBioDetector`.
4. Delete the per-platform service/job files whose connectors have replaced
   them — connector-by-connector, never in bulk.
5. Drop `platform_connections.payload` and the legacy `platform` alias column.
6. Re-run `rebuild:footprint`; record the final number in the execution log.

## Retired in this phase

- **Pinterest** (owner decision, 2026-07-28): brand, surface, connector,
  legacy scraper/strategies/resource, registry entry, config, frontend
  references. Data soft-deleted by `20260728100000`. The historical backfill
  CASE in `20260727110000` deliberately still names it — see
  `LegacyPlatformMap::RETIRED` for why.
