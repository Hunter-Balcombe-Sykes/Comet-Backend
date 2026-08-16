# Slice 7 — legacy teardown · design

Parent: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7,
§9. Kickoff: `plans/2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`. Entry gate:
`plans/2026-08-16-slice-7-entry-gate-report.md`.

**Dev only. Production is out of scope** (owner decision 2026-08-12) and is the
subject of this slice's closing follow-up, not its work.

**Size: XL, not the parent's M.** The estimate assumed the DROPs were the work.
They are the last step of six cutovers that four earlier slices each deferred
here in one line of their own wire manifests.

---

## 1. The law this slice runs on

> **Every `content.*` write path lands BEFORE the DROP that removes its legacy
> twin. No exceptions, no "same migration window".**

Not style — the failure it prevents is silent and unrecoverable in place. The
only ongoing writer of 288 of the 318 migrated `menu_item` coords is
`content:backfill-menus`, a one-shot command that **reads `site.menu_items`**.
DROP first and those dishes have no writer at all: `pools.menus` freezes at its
2026-08-15 snapshot, the owner's dashboard 500s, and the fix is not a rollback —
the table is gone, so the backfiller can never run again. The same shape holds
for `site.services` and `payload.selection`.

Corollary: **this slice's DROPs are one unit, executed last, after every cutover
below is live-verified on dev.** A per-unit "drop as you go" ordering is
explicitly rejected.

## 2. Entry state

Re-derived live 2026-08-16; figures and method in the entry-gate report, not
repeated here (invariant #5 — a future reader re-derives rather than cites
either document). The two facts that shape the design:

- **The §8.4 coverage gate is green** for all ten tables. Every legacy live row
  has a live `content.source_items.coord`. Nothing below is gated on more
  backfilling.
- **Six write lanes still target drop-list tables**, all of them this slice's,
  all deferred here in writing.

## 3. The scrape lane — the decision that shapes unit A

The obvious move is to retire `MenuFetchJob` and let the ingest connectors feed
menus, since `DoordashMenuConnector`, `UberEatsMenuConnector` and
`SquareMenuConnector` all exist and all map to `MenuItemProjector`.

**Rejected, on measurement.** Live on dev:

| | Legacy lane | Ingest lane |
|---|---|---|
| Users | 5 (`fable-sevenrun`, `ollies`, `fred-sarson`, `broken-oven`, `doc-pizza…`) | 1 (`showcase-eats`) |
| Dishes | 318 | 30 |
| Overlap | **zero** | **zero** |
| Refresh | automatic, on link change or Refresh button | `auto_sync = false` on all three sources |
| Cost | Apify scrape, existing controls | `cost_units = 50` — the paid actor lane |

The populations are **disjoint**. Every user with a real menu has no ingest
source; the only user with ingest sources had their menu tree deleted before
slice 4 ran. And Phase 4's standing rule keeps paid sources at
`auto_sync = false`, so the ingest lane does not refresh anything on a schedule
by design — `SourceProvisioner` will turn the flag back off if someone sets it.

Retiring `MenuFetchJob` would therefore stop automatic menu refresh for all five
real menus, cover none of them, and swap `MenuMerger`'s proven Uber Eats ×
DoorDash union for the identity resolver — which §25.6 records as **unexercised**
(`content.item_merges` is still 0, re-confirmed 0 at this gate).

**Decision D1.** `MenuFetchJob` stays, with its scrape, its merger, its rate
limits and its cost controls untouched. Only `persist()` changes: where it wrote
the four legacy tables, it writes `content.*` through the shared writer in D2.
The ingest lane stays exactly as Phase 5 left it — a second, paid, opt-in path
onto the same `content.items`, converging through `MenuItemProjector` and the
coord scheme both already share.

## 4. Units

Ordered. Units A–F are cutovers; G–J are the kickoff's §9 units; K is the DROPs.

### Unit A — the menu write lane → `content.*` · L

**Current.** `MenuContentController` (10 owner routes), `MenuFetchJob::persist()`,
`MenuScanApplier`, `WebsiteMenuHtmlScanJob` / `WebsiteMenuPdfScanJob` all write
`site.menu_items` + `menu_categories` + `menu_item_categories` +
`menu_item_platforms`. Grepping all five for `content.`, `ProjectionWriter`,
`ManualServiceWriter` or `ContentItemSlugAllocator` returns **zero** hits.

**Target.** Copy slice 3a's proven pair, which exists precisely so a backfiller
and a controller cannot diverge:

| 3a, for services | This slice, for menus |
|---|---|
| `ManualServiceWriter` — projection + curation + `invalidate()` | **`ManualMenuWriter`** |
| `ManualServiceItems` — `activeQuery`, `rows`, `find`, `publicList`, `exportRows`, `toServiceModel` | **`ManualMenuItems`** |

Two assets make this cheaper than it looks and **must be reused, not
re-derived**:

- **`MenuProjectionMapper`** already maps a legacy-shaped dish → the exact
  projection `ProjectionWriter` accepts, including offers, badges, media,
  category collections and `order_platform` collections. It is pure and
  documented. `ManualMenuWriter::projectionFor()` is a thin wrapper over it, and
  the controller assembles a legacy-shaped object from its request payload
  exactly as `ManualServiceWriter::projectionFor()` accepts one.
- **The coord scheme is fixed and must not be re-derived:**
  `MenuProjectionMapper::coordFor($menuId, $name)` =
  `manual:menu:{menu_id}:{sha1(normalizeName(name))}`. Its docblock explains why
  it is not `manual:{uuid}`; that reasoning still holds and a second derivation
  would mint duplicate items for every existing dish.

**Slug duty.** Already re-homed — `ContentItemSlugAllocator::SLUGGED_KINDS`
carries `'menu_item'` and `ProjectionWriter::refreshItemCaches()` honours it.
**Removing that const entry is forbidden** (§23.10): it *is* the re-homing.
Deletion frees a slug via `markRemoved()`.

**Wire shape.** The dashboard keeps today's payload. `MenuPayloadComposer` is
repointed at `ManualMenuItems`, which returns legacy-shaped rows the same way
`ManualServiceItems::toServiceModel()` does. Rationale: the dashboard is a
working surface, and breaking all 10 editing verbs during a frontend rebuild
makes menus unusable for the duration. 3a proved the shim.

**Behaviour that must survive the cutover**, each currently enforced in the
legacy writer and each needing an explicit home:

- `is_manual` — an owner edit to a scraped dish detaches it from platform sync,
  and `MenuFetchJob`'s rebuild must not clobber it.
- `menus.suppressed_items` — deleting a scraped dish must stop the next scrape
  resurrecting it.
- `menu_categories.source_platform = 'scan'` — scan-owned categories survive a
  scrape rebuild (`MenuFetchJob::rebuildableCategoryIds()`).
- `EDITABLE_SOURCES = ['manual','scan','website-scan']` — scraper-owned
  categories are off-limits to hand edits.

`site.menus` and `site.menu_platform_links` are **not** on the drop list and
stay as the per-menu bookkeeping row (`last_fetched_at`, `dining_modes`,
`suppressed_items`, `scan_items`). `is_manual` and the suppression set therefore
have a home that survives; the spec's requirement is that the `content.*` write
path reads and honours them.

### Unit B — the public `/menu` endpoint · M

**Decision D2: delete `GET /api/public/profiles/{handle}/menu` outright.** Do
not repoint it.

`pools.menus` on `GET /api/public/profiles/{handle}` is already a complete
replacement — items, `collections` (categories *and* ordering-platform store
cards), `diningModes`, and permalinks with 301 aliases the legacy lane never
had. The 2026-08-14 owner override says the sitepage frontend is rebuilt, not
repaired, so there is no compatibility to preserve and repointing would build a
second read path nobody will consume.

`PublicMenuController`, `MenuItemDeepLinks` and the legacy-lane half of
`ItemSlugAllocator` go with it. Its own wire manifest records the deletion.

### Unit C — Fresha `payload.selection` off `site.services` · M

`App\Services\Platforms\FreshaServiceProjector` writes `site.services` and
composes `payload.selection`, which `PublicIntegrationConnectionResource:111`
ships publicly. Call sites: `FreshaController::connect()` (storewide, `:147`)
and the selection-save path (`:484`), `UserServiceController:362` / `:524`,
`PlatformRegistryServiceProvider:338`.

**Decision D3: compose from `content.*`, keep the blob.** The alternative —
retiring `fresha.selection` from the public wire — is a breaking change to a
booking surface, and slice 6 showed (with `pools.reviews.stats`) that moving a
public fact to a pool is a slice of its own. Compose the same shape from
`content.items` kind `service` + `content.offers` + `content.f_duration`, which
§19.2 already proved round-trips into "the vendor's own display grammar".

`ManagesIntegrationConnection`'s advisory-lock timeouts are sized around this
projector's runtime; re-check them once it no longer writes Postgres rows per
service, and **narrow rather than delete** — the docblock chain at `:396-450`
explains what each guards.

### Unit D — standalone events, the four-step · M

Parent §7's four steps, none started. Dev carries **2 live** `resource_kind='event'`
connections (`eventbrite`). Step 3 is a breaking wire change with its own
manifest; steps 1–2 are what stop it being data loss.

1. Backfill existing standalone rows into `content.items` via
   `ProjectionWriter::writeManualItem()`, coord `manual:{sha1(url)}` (§1.7's
   one-coord-per-URL rule).
2. Repoint the Tickets & Events card's add-an-event verb at that lane.
3. Empty the standalone payload on `/integrations`.
4. Decide `site.item_slugs` permalinks for standalone events — the 11 legacy
   `item_type='event'` rows are this slice's to delete either way.

### Unit E — `site.content_selection` · S

Four owner routes (`GET`/`PUT /api/content/selection`,
`PUT /api/content/instagram-auto`, `PUT /api/content/google-photos`) and the
public gallery read via `SitepageDataResolverService` →
`IndividualProfilePayloadBuilder`.

Under the owner override the `gallery` / `designMedia` / `siteImages` keys are
**deleted outright, not dual-served**. The owner-curation verbs retire in favour
of `pool:media` pins. `ContentSelectionService` goes with the table.

**Record, do not re-litigate:** the 92 non-upload rows (85 `google-photo`, 7
`ig-*`) are not carried — decided in checkpoint §15 at 91 rows. It is 92 now
because the table is a live write target and one site re-curated after the
migration. State the final count in the checkpoint.

### Unit F — legacy assignment sync on the reorder verbs · S

`UserServiceController:1121-1134` and `StaffServiceManagementController:612-625`
each maintain `site.service_category_assignments` by hand for the Fresha subset,
beside the `content.*` `collections->reposition()` call. Delete the legacy half.
Same shape as the `StaffServiceCategoryManagementController::index()` two-id-space
merge that 3b explicitly handed over — **which is also this unit's**, and which
queries a dropped table if left.

### Unit G — observers (§9.4) · S

All five are **registered** — `ServiceObserver`, `ServiceCategoryObserver`,
`SiteMediaObserver`, `MenuObserver` in `EventServiceProvider:39-45`,
`MenuItemObserver` via `#[ObservedBy]`. Verified at the gate; no "moved but
never re-registered" case exists.

Retire each only after enumerating its side-effects and confirming each has a
home on the `content.*` path. `MenuItemObserver`'s three duties are already
re-homed (§23.5). `ServiceObserver::reevaluateBooking()` is mirrored at
`UserServiceController:1444` and `StaffServiceManagementController:927`.
**Event discovery is disabled** — any replacement listener must be explicitly
registered or it is silently dead.

`MirrorMediaAssetJob` fires off `ProjectionWriter`, not an observer, so it is
not in the §9.4 list — but it must keep dispatching.

### Unit H — policies (§9.6) · S

`ServicePolicy` and `ContentSelectionPolicy` orphan; `PolicyCoverageTest` will
trip. `ContentItemPolicy` is kind-agnostic (authorises on `user_id`) — confirm
that still holds for every kind now in play rather than assuming it.

### Unit I — DSAR / GDPR (§9.5) · M

`DataExportPayloadBuilder` streams `site.services` as a named section and pins
`services` / `service_categories` in its declared return shape; it also queries
`site.service_category_assignments:822` and `site.service_categories:841`.
Re-source from `content.*` — `ManualServiceItems::exportRows()` exists for
exactly this.

**Retain the legacy section keys.** The 2026-08-05 precedent is that DSAR
allowlists keep them so previously-stored payloads stay disclosable.

Slice 6 added `content.source_stats` to the export with `summary_text` omitted;
`DsarPayloadFilter::WITHHELD_DISCLOSURE` names both halves. If either table
moves, the disclosure string and the omission move with it.

### Unit J — analytics (§9.7) · documentation only

Owner decision 2026-08-12: historical `analytics.item_views` and
`content_popularity_scores` rows referencing merged-away or deleted items are
**accepted as lost**. No FK, so orphans are inert.

**The one thing to verify rather than assume:** that no query inner-joins
analytics to `content.items` in a way that would silently drop rows or error. If
one exists it needs a null-safe path, and that *is* code.

### Unit K — the DROPs · M

Last, and only after A–J are live-verified on dev.

Children before parents, raw SQL under `supabase/migrations/`, one concern per
file, `CONCURRENTLY` at most once per file:

```
site.menu_item_categories, site.menu_item_platforms, site.menu_items,
site.menu_categories, site.service_category_assignments, site.services,
site.service_categories, site.shop_products, site.shop_brands,
site.content_selection
```

Check `pg_depend` for FK dependents, views, triggers and RLS policies on each —
the table list will not tell you what the catalog will.

In the same window:

- **`ShopContentWriter` re-homed off the `ShopBrand` model.** `site.shop_brands`
  is a live write target (`ShopController` `updateOrCreate:317`,
  `firstOrCreate:929`, `delete:869`) and `upsertStore()` takes the model as its
  identity anchor. Named by the kickoff as part of this unit, not a follow-up.
- **`CloudflarePurgeService::purgeHandle()`** — delete the legacy third of the
  dish lookup (the menu third already reads both lanes and is wrapped in
  `EscalatesRepeatedFaults`, so the DROP does not trip it), repoint the product
  and event lookups, and wrap their two remaining **raw `report($e)`** calls in
  `EscalatesRepeatedFaults` — otherwise one site save is up to 8 un-deduped
  Nightwatch reports, and a save-storm reads as an outage. Take the class
  docblock's URL-volume derivation back from 900 to 600 per host (§23.7).
- **`BackfillClaimedGoogleBusinessReviewsCommand` deleted** (owner ruling
  2026-08-14) — vestigial, its lane retired.
- **`site.item_slugs`** — the 11 legacy `item_type='event'` rows deleted.

## 5. Backup — the gate before the first DROP

Non-negotiable and **surgical, not a substitute for the Pro-plan daily backup**:
`pg_dump` the ten tables to the `partna-db-backup` R2 bucket, verify the dump is
readable, and assert **dumped row counts exactly match live counts per table**.
If one table disagrees, **nothing** is dropped. Location recorded in the
checkpoint.

Taken immediately before unit K, against the schema as it stands then — not at
the start of the slice, when it would be stale by several units.

## 6. Cache invalidation (§9.2)

Every unit here is a raw-write seam. All three lanes, independently, per touched
site — `PoolController::poolChanged()` is the reference implementation:

| Lane | Trigger |
|---|---|
| `site.site_build_state` | `BuildState::bump($siteId)` |
| 60s public-profile payload | touch `site.sites.updated_at` — `bump()` writes a *different table* and does not bust this |
| Cloudflare edge | `CloudflareCachePurgeJob::dispatch($subdomain)` |

Nothing enforces this — §9.1: `BuildState` has no registry and no test, despite
its own docblock claiming one. The three-lane test must assert an **exact
revision delta**, not `content_revision > 0`: 3a's first version passed with the
`BuildState` lane deleted, because `writeManualItem()` bumps internally.

## 7. Verification

- Every assertion re-run and **pasted** into the parent-spec checkpoint
  (invariant #1). Coverage re-derived at DROP time, not cited from the gate.
- Wire manifest: `docs/wire-changes/2026-08-12-slice-7-teardown.md`, naming every
  key that died — including which frontend reads break, per the override.
- Full suite, PHPStan, Pint. **`composer test` is not enough**: unit A touches
  `ProjectionWriter`'s callers, so `composer test:pg` runs too, and the schema
  lane (`composer test:schema`) covers the DROPs.
  In a worktree PHPStan needs
  `php -d memory_limit=1G ./vendor/bin/phpstan analyse <path> --no-progress --debug`
  — the default invocation OOMs and reports it as "severe errors".
- Post-deploy: `cloud env:logs partna development --minutes 10` **and** a
  Nightwatch scan.

## 8. Out of scope

- **Production.** Deferred (owner, 2026-08-12) and the reasons stand: prod is
  hundreds of commits behind, the schemas have diverged from the 2026-07-26
  baseline, and prod DB access was unconfirmed. An irreversible teardown must not
  be the operation that discovers a migration gap on a database nobody could
  read. The slice's closing deliverable is the prod-reconciliation write-up.
- **Rebuilding the frontend.** The override says it breaks and gets rebuilt.
- **Cross-platform menu identity / `item_merges`.** Still 0, still unexercised
  (§25.6). D1 keeps `MenuMerger` precisely so this slice does not become the one
  that exercises it.
- **The `google_business` `profile` stream.** Slice 6 says retire it or justify
  keeping it — **do not drop it silently**, and do not move `source_stats` onto
  it. Carried, not resolved here.

## 9. Open — needs an owner call, does not block starting

1. **Does the rebuild-not-repair override cover the dashboard (Partna-App), or
   only the sitepage (`apps/pages`)?** Unit A assumes **sitepage only** and keeps
   the dashboard's menu payload shape. If the dashboard is in the rebuild too,
   unit A sheds its compatibility shim and gets materially smaller.
2. **`anseo-studio`'s Fresha connection has no ingest source** —
   `SourceProvisioner::freshaSlug()` matches only `fresha.com/…/a/<slug>` and
   that row is a `book-now/…?pId=` URL. Widen the matcher or write the row off,
   and say which. Not noise (3b was explicit about this).
3. **The fourth pin path on exclusion-only pools** (slice 6, deliberately not
   fixed): a custom `collection` section with a `kind_is: review` rule can have
   reviews pinned into it. Inert only because no public controller reads
   `site.documents`. **If any unit here makes a custom-section lane publicly
   readable, the guard must move from the section key to the item's kind** — a
   code change with a legal edge (slice 6 spec §4.3).
