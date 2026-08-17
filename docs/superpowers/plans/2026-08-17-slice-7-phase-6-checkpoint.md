# Slice 7 Phase 6 — checkpoint (2026-08-17)

> **FOLDED INTO THE PARENT SPEC — cite `§27` there, not this file.** Phase 8
> filed this checkpoint into
> `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §27 on
> 2026-08-17; until then the spec carried no record of the programme's largest
> teardown. This file is kept as the original working record. Where the two
> differ, the spec is current — in particular §10 below is **superseded** (see
> the note there).

What shipped, what was dropped, what was deliberately left behind, **and that
production still carries the legacy schema.**

Evidence for the decisions is in `2026-08-17-slice-7-drops-gate-report.md`
(read that for WHY); the wire changes are in
`docs/wire-changes/2026-08-12-slice-7-teardown.md` §"Phase 6". This document is
the WHAT.

**Dev only** (`glncumufgaqcmqhzwrxm`). Production was not touched and is not
named in any executed statement.

---

## 1. Verdict

**Shipped, at reduced scope: five tables of nine.** Merged to `development` at
`cef89ec5f`, deployed (`deployment.succeeded`), migrations applied, live-verified.

Two owner rulings on the day shaped it:

1. **The 23-row loss is accepted** (§3). This cleared the coverage stop the gate
   report raised.
2. **Scope cut to five tables**, on a finding the gate report had not yet made:
   `site.shop_products` is a live READ target, not inert.

## 2. Dropped

| Table | Rows at drop |
|---|---|
| `site.menu_item_categories` | 358 |
| `site.menu_item_platforms` | 310 |
| `site.menu_items` | 293 |
| `site.menu_categories` | 40 |
| `site.content_selection` | 95 |

Plus `DELETE FROM site.item_slugs WHERE item_type='event'` — 6 rows. Verified 0
after.

Migrations `20260817000100` … `20260817001100`. **Ledger repaired**: MCP
`apply_migration` stamped its own versions (`20260817005915`…) rather than the
repo filenames — the fourth consecutive occurrence of that drift. Repo versions
inserted, MCP strays deleted, ledger re-read and confirmed aligned.

## 3. Accepted data loss

23 rows on `ollies`, minted by a scrape at `2026-08-16 23:03:42+00`, coords never
written to `content.source_items`: 10 dishes, 2 categories, 11 memberships. Owner
ruled the loss acceptable rather than reorder the phase around a re-backfill.

**The lesson outlives the rows: on a live environment a coverage gate is valid
only until the next scrape.** 2026-08-16 read 318/318; 2026-08-17 read 283/293.
Net counts FELL (318→293) while uncovered rows appeared — totals concealed the
hole, and only a per-row coord derivation found it. Never gate on counts.

## 4. Backup gate — GREEN

`pg_dump` (PG17, via the Supavisor pooler — the direct host is IPv6-only and
unreachable from Docker), restored into a throwaway `postgres:17` container and
counted there. **Not an `ls`.**

| Table | Live | Dumped → restored |
|---|---|---|
| `site.menu_items` | 293 | 293 ✓ |
| `site.menu_item_categories` | 358 | 358 ✓ |
| `site.menu_item_platforms` | 310 | 310 ✓ |
| `site.menu_categories` | 40 | 40 ✓ |
| `site.content_selection` | 95 | 95 ✓ |
| `site.item_slugs` (all / event) | 299 / 6 | 299 / 6 ✓ |

Artifacts, with checksums:

```
~/partna-db-backups/slice7-drop-preimage-full-20260817.sql   691010 bytes
  sha256 f6817fd320979eaacb0af3fba4f670ca931d429b5d7044e7c3063e98d2573505
~/partna-db-backups/slice7-drop-preimage-20260817.sql        677834 bytes
  sha256 7c622ccecbdc20e5c917a1e5d7dffc13f8f4ccae1562a13cb8637a6a359812d2
```

🔴 **The R2 copy was NOT taken.** `rclone`, `aws` and `wrangler` are all absent
locally and the `.env` AWS keys address the media bucket, not `partna-db-backup`.
The gate's *assertion* (a verified, restorable pre-image with exact per-table
counts) is satisfied; its *offsite durability* is not. The dump lives on one
laptop. Supabase Pro daily managed backups are the second line. **Anyone
repeating this should get the R2 upload working first** — it is the one step of
the backup gate that was not completed as specified.

## 5. Code retired

- **Menu read arms:** `MenuPayloadComposer` (legacy eager-load, `hasOwnerContent`
  legacy half, `legacyCategories()`), `MenuDashboardPayload::itemCount`,
  `MenuFetchJob::hasOwnerContent`, `FoodContentProbe`'s SLICE 4 SWAP POINT.
- **`legacyOwnerSource()` → `ownerSource()`**, deriving scan/website-scan from the
  content category ref. Degradation named in the docblock.
- **Observers:** `MenuItemObserver` (+ its `#[ObservedBy]`), `Core\MenuObserver`
  (+ registration). `MenuObserver`'s single duty was refusing to orphan
  `site.menu_categories` rows; with the table gone the orphan cannot exist.
- **The slug lane:** `EventSlugSync`, `ItemSlugAllocator`,
  `IntegrationConnectionObserver`'s four event-slug methods, `BackfillItemSlugs`.
- **Migration services/commands:** `MenuBackfiller`/`BackfillMenus`,
  `ContentSelectionMigrator`/`MigrateContentSelectionCommand`,
  `ProvisionMenuPinsCommand` (one-shot; its pins are seeded),
  `BackfillClaimedGoogleBusinessReviewsCommand` (owner ruling 2026-08-14).

**Models KEPT — a correction to the plan.** Task 27 step 5 said "delete the nine
dropped tables' models". `MenuItem`, `MenuCategory` and `MenuItemPlatform` must
**survive**: `ManualMenuItems` hydrates all three unpersisted (`exists = false`)
as DTO carriers for the dashboard shape, exactly as `ManualServiceItems` does
with `Service`. Deleting them breaks the surviving content lane rather than
tidying it. Same shape as `ShopBrand` surviving its own deferral.

## 6. Three observers were NOT retired, and should not have been

The kickoff said "retire the five observers". Two of the five key off tables that
are not dropped:

- `ServiceObserver`, `ServiceCategoryObserver` — `site.services` /
  `site.service_categories` are **deferred**. Retiring them would have broken a
  live lane.
- `SiteMediaObserver` — observes `site.site_media`, which **survives**, and
  touches nothing on the drop list. It is unrelated to this teardown; the §9.4
  list was wrong to include it.

Only `MenuItemObserver` and `MenuObserver` retired.

Likewise **policies**: `ContentSelectionPolicy` was already gone. `ServicePolicy`
**stays** — its model and table are deferred. `PolicyCoverageTest` is green.

## 7. Deferred — four tables, both with live read lanes

| Table(s) | Blocker |
|---|---|
| `site.services`, `site.service_categories`, `site.service_category_assignments` | The Fresha half was never cut over. ~30 live query sites over five files; `UserCacheService::professionalServices()` and both dashboards merge a `content.*` half with a legacy one — *"TWO id spaces are live during the transition."* |
| `site.shop_products` | Live READ via `$brand->products`: `ShopController::brandMap()` eager-loads `with('products')`; `ShopCatalog::syncLatest()` requires the relation. One unit with `site.shop_brands`. |

Both planned in `2026-08-17-shop-brands-rehome.md` (shop) and its spec §11
(services).

**Method finding, worth more than the deferral:** the kickoff's residual sweep
(`grep -rn "table('site\.<t>'" app/`) matches only raw query-builder calls and is
**blind to Eloquent**. It returned a clean five sites while `ShopController` and
the entire Fresha read half sat invisible to it. A table is inert only when BOTH
greps come back empty.

## 8. Verification

- `pest --parallel` — **8271 passed, 2 skipped, 0 failed**.
- `phpstan analyse app` — **[OK] No errors**. `pint` — passed.
- Schema + Postgres lanes updated for the dropped tables
  (`PlatformAndMenuRlsTest`, `CheckConstraintsTest`,
  `MenuForceDeleteCascadeTest` reduced to the surviving `menu_platform_links`
  cascade).
- Live on dev after the drop: `GET /api/public/profiles/ollies` → **200**, and
  `pools.menus` serves **65 items / 16 collections**.
- `cloud env:logs --minutes 15`: one error, the known Cache SLO violation (#371),
  expected on a cold post-deploy cache. **No `42P01`, no "does not exist".**
- Nightwatch: 16 open exceptions, **all pre-existing**; none from the teardown.
- `content:backfill-standalone-events` **run for real** (had only ever been
  dry-run): backfilled 1. `content.items` kind `event` 17 → 18.

**Not run: `content:backfill-menus`.** It reads three dropped tables and was
deleted in the same change. Step 8 of the kickoff asked for it; that instruction
is unexecutable by construction and is the ordering contradiction the gate report
recorded at §4.

## 9. Post-drop state

```
content.items live   menu_item=323 release=225 video=135 episode=115
                     service=77 media=77 track=75 product=52 link=34
                     review=20 event=18
content.collections  menu_category=53 service_category=16 storefront=10
                     order_platform=5
content.item_slugs   380
site.item_slugs      293 total, 0 event        <- write-free orphan
site.menus / menu_platform_links   5 / 5       <- survive by design
DEFERRED  site.services 82 | shop_products 51 | shop_brands 10
```

## 10. Production still carries the legacy schema

Nothing in the content-pool convergence programme has been applied to
`edplucmvkcnokyygxqsb`. Prod is hundreds of commits behind, its schema diverged
from the 2026-07-26 baseline, and prod DB access remains **unconfirmed**.

The follow-up is `2026-08-17-prod-schema-reconciliation.md`.

> **SUPERSEDED 2026-08-17 (phase 8).** This section used to warn that
> `20260817000000_public_site_payload_services_from_content.sql` was "committed
> and deliberately unapplied", and that the next `supabase db push` against dev
> would apply it unannounced. **It has been applied** — dev's ledger carries
> version `20260817000000` (verified 2026-08-17 11:06 UTC, alongside
> `…000100`–`…001100`). The footgun no longer exists; do not carry the warning
> forward.

The prod position is also **stronger than "still carries the legacy schema"**:
production is missing the `content`, `ingest`, `routing` **and** `catalog`
schemas outright — `content.items` does not exist there — and its ledger holds 4
rows (latest `20260803100001`) against dev's 106. Verified 2026-08-17 11:06 UTC.
Prod DB access via MCP **works**; the previously recorded `28P01` breakage is
stale.

## 11. Carried open questions

- `content.item_merges` is **still 0** — cross-platform identity remains
  unexercised (§25.6), as slice 7 intended.
- `anseo-studio`'s Fresha connection still has no ingest source
  (`SourceProvisioner::freshaSlug()` doesn't match a `book-now/…?pId=` URL).
  Widen the matcher or write it off — still unanswered.
- The fourth pin path on exclusion-only pools (slice 6) is untouched and still
  inert; nothing in this phase made a custom-section lane publicly readable.
