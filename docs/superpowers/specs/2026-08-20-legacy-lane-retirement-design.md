# Legacy lane retirement — design

**Date:** 2026-08-20
**Status:** §4, §5, §6, §7.1–7.3 (dashboard half) **SHIPPED** 2026-08-20 — `30f0e86e8`, `f91800d6f`, `c0a026145`, `573f31b82` on `development`.

**Still open:** §7.1's public half (`IndividualProfileResource` / `IndividualProfilePayloadBuilder` — `architectureId` + the `skeletonId` alias), §7.4 (the `all_site_data` view + `20260817000000` payload function), §7.5 (column drop), and §8 (the two compat maps — `SiteOrderingValidationRules` and `SiteActionsService` were owned by `feature/item-feed-2026-08-19`).

**What changed vs. this plan:** the §9 phasing was built around a `bootstrap/catalog/compiled.php` conflict with `fix/dev-red-queryplan-and-pint-2026-08-19`. That branch merged (PR #300) mid-execution, so the catalog fold (§5) was no longer blocked and shipped in the same pass. `LegacyPlatformMap` now carries `legacy_platform` on **all 72** legacy surfaces, not the 9 in §5.1 — see the commit message on `f91800d6f` for why (an inverse built from only the 9 would have widened `surfaceFor()` from 72 to 111 and been ambiguous for `bandcamp`).
**Branch:** `docs/legacy-lane-retirement-spec-2026-08-20`
**Base:** `development` @ `3deef23be`

## 1. What this is

Three surfaces were reported as vestigial and removable:

1. `LegacyPlatformMap` running as a second source of platform identity beside `CompiledCatalog`.
2. `GET /api/public/profiles/{handle}/platforms` — a legacy route alias.
3. `site.sites.architecture_id` — CHECK-constrained to one value, still on the wire, plus two small compat maps riding alongside it.

Investigation changed the shape of all three. **One is smaller than reported, two are larger.** This spec records what is actually removable, what is load-bearing, and the order it has to happen in.

The headline correction: the legacy `platform` slug vocabulary is **not** removable dead weight. It is a live wire contract with four synchronised representations, one of which is a `GENERATED ALWAYS … STORED` Postgres column. What *is* achievable is making the catalog the single **authored** source and generating the rest from it.

## 2. Evidence

All figures measured 2026-08-20 against `development` @ `3deef23be` and dev Supabase (`glncumufgaqcmqhzwrxm`).

> **A live coverage reading is valid only until the next write.** Every figure here is timestamped. Re-measure before acting on any of them — see §9.

### 2.1 `ROUTING_CLASS` is fully redundant

Diffing `LegacyPlatformMap::ROUTING_CLASS` against `bootstrap/catalog/compiled.php`:

```
ROUTING_CLASS entries:                    72
missing from artefact:                    none
disagreements:                            none
TO_SURFACE targets missing from artefact: none
artefact surfaces lacking routing_class:  none  (all 111)
```

The catalog answers for every surface the map covers, with identical values.

### 2.2 The compiled artefact is always present

`bootstrap/catalog/compiled.php` is **git-tracked** (338 KB, confirmed via `git ls-files`), not built at deploy. `CatalogNotCompiled` therefore cannot fire in any deployed environment.

This matters because `isKnownSurface()` and `routingClassFor()` both justify their hardcoded table as "a fallback for an environment where the artefact is missing". That environment does not exist. Worse, the fallback is **incoherent**: it covers only the 72 legacy surfaces, so the other 39 already fail closed on `CatalogNotCompiled`. Today's behaviour is not conservative, it is inconsistent.

### 2.3 The legacy slug has four synchronised lanes

| # | Lane | Location |
|---|------|----------|
| 1 | PHP const | `LegacyPlatformMap::SPECIAL_TO_LEGACY` + brand-prefix default |
| 2 | Backfill CASE (historical) | `supabase/migrations/20260727110001_connections_surface_key_backfill.sql` |
| 3 | **Live generated column** | `supabase/migrations/20260727110004_connections_platform_generated_alias.sql` — `platform text GENERATED ALWAYS AS (CASE surface_key …) STORED NOT NULL` |
| 4 | SQLite test mirror | `tests/Pest.php:1121` |

`tests/Unit/Catalog/CatalogLegacyMapTest.php` already pins all four pair-for-pair (its cases at lines 51, 71, 91) and asserts map ⊆ artefact with agreeing routing classes (line 107).

**Consequence:** Postgres cannot read `compiled.php`. Lanes 2–4 can never *follow* the catalog at runtime; they can only be **generated from it and asserted equal**. Folding slugs into the catalog collapses lane 1 only. The existing lockstep test is already doing the real safety work — this spec keeps it and retargets it, rather than claiming the fold removes the need for it.

### 2.4 The generated `platform` column is heavily read

~25 query sites read the column in SQL (`ProjectionWriter:950`, `IntegrationConnection:395`, `RefreshController`, `PublicIntegrationController`, `StaffUserController`, `AutoSyncSetting`, `ItemLinkRules`, `SyncFindingsBridge:160`, and more).

**Dropping the generated column is therefore out of scope.** It would mean ~25 query rewrites plus a full heap rewrite under `ACCESS EXCLUSIVE`. Recorded here so a future session does not re-derive it as an easy win.

### 2.5 `architecture_id` is live on the frontend

`PartnaAu/partna-frontend` @ `main` (local clean checkout `partna-frontend-main`):

Verified against **live `main` @ `a93ecaf` (2026-07-28) via `gh api`**, not the local `partna-frontend-main` checkout (clean `main` but HEAD 2026-07-25 — 3 commits behind; treat it as a staleness trap).

| File | Use | Behaviour if the field disappears |
|---|---|---|
| `staff/users/[id]/page.tsx:232` | renders it in the staff UI | `{design.architecture_id ?? 'staple'}` — **explicit fallback; renders the identical string**, since the CHECK permits only `'staple'` |
| `lib/schemas/account.ts:205-206` | Zod parses `architectureId` + `skeletonId` | both `.optional().nullable()` inside `.passthrough()` — **does not throw on absence** |
| `lib/account/map-snapshot-to-account.ts:119,237` | reads into account state | `readString(site?.architecture_id) ?? …` fallback chain |
| `lib/staff/types.ts:80,120` | typed field on two staff payloads | hand-written TS type; `lib/staff/api.ts` does **no** runtime validation (no `zod`, no `.parse`) — compile-time only |

**The field is referenced but NOT load-bearing.** Every consumer is defensively coded. Removing it from the dashboard wire produces **no user-visible change** — the fallback value equals the only permitted value. The residual is a compile-time type tidy-up inside the frontend repo.

An earlier draft of this spec rated this a "High — render break". That was an unjustified leap from *referenced* to *load-bearing*; the consumption shape (`?? fallback`, `.optional()`, types without runtime validation) says the producer can stop sending it. Corrected 2026-08-20.

All four are **dashboard** surfaces reading `SiteResource` / `Staff*Resource`. None consume the public profile payload. That is what makes the Phase 1/2 split in §9 coherent rather than arbitrary.

The public sitepage is the Astro app (`@partnaau/design-system`), **not checked out locally** — its consumption of `architectureId`/`skeletonId` and of the `/platforms` alias is **unverified**. Removing either is accepted risk, owner ruling 2026-08-20, mitigated by a coordinated frontend PR.

### 2.6 `architecture_id` reaches into Postgres

Beyond PHP, `s.architecture_id` is emitted as `skeleton_id` from:

- `all_site_data` view — `20260726000000_baseline_pilot.sql:3368` (and `:1470`, `:2041`, `:2064`)
- the public-site payload function — `20260817000000_public_site_payload_services_from_content.sql:84` **and** `:220`

Column DDL: `architecture_id text DEFAULT 'staple' NOT NULL` + `sites_architecture_id_check CHECK (architecture_id = 'staple')` (baseline `:2007`, `:2022`).

### 2.7 Both compat maps have zero rows behind them

Dev `site.sites`, 32 rows total:

```
manual_page_order containing "book":  0
"apple-podcast" token in settings:    0
legacy "button" shape in settings:    0
non-'staple' architecture_id:         0
```

Production carries no customer data (`core.users` = 0).

**Caveat that must not be lost:** `SitepageId::normalizePageId()` is called on a **read** path (`SiteActionsService.php:529`), not only on inbound validation. Deleting `LEGACY_PAGE_IDS` is safe *because the stored data is clean*, not because the map is inbound-only. If the re-measure in §9 returns non-zero, this deletion is off.

### 2.8 Documentation already drifted

`CLAUDE.md` states architecture write paths "collapse all historical ids via `LEGACY_ARCHITECTURE_IDS`". That constant **was removed 2026-08-05** (`NormalizesSiteUpdateInput.php:12`). Fix as part of this work.

## 3. Non-goals

- Dropping the generated `platform` column (§2.4).
- Removing `surfaceFor()` / `legacyFor()` as *functions* — the legacy slug stays on the wire (`platform` in resources, `resourceId` in `GoogleBusinessAutoSync`, `DerivedDescriptorFactory` keying, `SyncFindingsBridge`).
- Adding a second architecture. Explicitly a platform decision, not a task (`CLAUDE.md`).
- Reconciling production schema. Prod lacks `catalog`, `content`, `ingest`, `routing` outright.

## 4. Delete `ROUTING_CLASS`

Delete the 72-entry const. `isKnownSurface()` and `routingClassFor()` become pure `CompiledCatalog` reads.

Remove the `CatalogNotCompiled` catch from both. Rationale in §2.2: it defends an impossible state and already fails to defend 39 surfaces. After this change a genuinely absent artefact fails **loudly and uniformly** at the write gate, which is correct — a silent half-open gate on connection writes is the worse failure.

`IntegrationConnection::booted()` (`:203`, `:220`) is the primary caller and needs no change beyond inheriting the new behaviour.

**Deliberately unchanged:** `DetectorSuspensions` / `UnmatchedDomains` keep their fail-open-and-log behaviour. That is a separate, documented ruling (`CLAUDE.md`: a kill-switch that 500s the paste preview is worse than the detector it disables) and is not affected here.

## 5. Legacy slug: catalog becomes the authored source

### 5.1 Catalog carries the slug

Add `legacyPlatform` (nullable string) to `App\Catalog\Surface` and a `legacyPlatform(string)` method to `SurfaceBuilder`. Emit as `legacy_platform` in `Surface::toArray()`.

Set it **only** on the 9 live surfaces whose slug is not their brand prefix:

```
apple_music.artist        => apple-music
apple_podcasts.show       => apple-podcast
bella_booking.book        => bella-booking
google_business.listing   => google-business
ko_fi.page                => ko-fi
resident_advisor.tickets  => resident-advisor
square.order              => square-ordering
youtube_music.channel     => youtube-music
partna.storefront         => shop
```

Every other surface derives its slug from the brand prefix, matching `split_part(surface_key, '.', 1)`.

**Expect 9 here but 14 in the SQL.** The applied CASE in `20260727110004` has 14 `WHEN` arms and its comment says "Only 14 surfaces alias to a non-prefix slug". That is these 9 **plus the 5 retired `partna.*` arms** (`custom_link`, `manual_event`, `booking_link`, `reserve_link`, `order_link`). The count difference is correct and expected — it is not drift. §5.4's test must reconcile catalog-derived output against the applied CASE *through* `historicalSpecialToLegacyMap()`, never by raw arm count.

### 5.2 `LegacyPlatformMap` holds no data

Keeps its public surface, loses its tables:

- `legacyFor($surfaceKey)` → catalog `legacy_platform`, else prefix rule
- `surfaceFor($legacySlug)` → inverted catalog index, built once and memoised

Delete `TO_SURFACE`, `SPECIAL_TO_LEGACY`, `ROUTING_CLASS`, and the accessors that exposed them (`toSurfaceMap()`, `specialToLegacyMap()`, `routingClassMap()`) once callers move.

`PlatformRegistry:59` currently returns `array_keys(LegacyPlatformMap::toSurfaceMap())` — repoint to the catalog-derived slug list.

### 5.3 `RETIRED` stays

`RETIRED` and its two `historical*Map()` accessors are **kept**. They record decisions the historical migration CASEs still contain (Pinterest 2026-07-28; the five pseudo-platform link-lane surfaces 2026-08-19). Without them the lockstep test cannot tell "retired on purpose" from "drifted by accident" — which is the single most valuable thing that test does.

Retired entries are **not** added to the catalog. They name surfaces that no longer exist in it.

### 5.4 Generate the SQL, don't hand-maintain it

New command `catalog:emit-legacy-alias-sql` renders the alias CASE from the catalog, in the exact form lane 3 uses.

`CatalogLegacyMapTest` is retargeted: instead of comparing a PHP const against three SQL texts, it compares **catalog-derived output** against the applied DDL in `20260727110004`, the historical backfill in `20260727110001`, and the SQLite mirror in `tests/Pest.php`. Retired `partna.*` entries continue to be tolerated in the applied CASEs via `historicalSpecialToLegacyMap()`.

Result: still four representations, exactly one authored. Changing a slug remains a migration with a heap rewrite — that cost is inherent to `STORED` and is not removed by this work.

### 5.5 Docblocks

`CompiledCatalog`'s "This is the ONLY runtime source of platform identity" becomes true as written once §4 and §5 land, but must state plainly that the legacy slug vocabulary is mirrored into SQL and that changing it requires a migration.

`LegacyPlatformMap`'s docblock defers to "P2's reproject", which never landed. Replace with what remains and why.

## 6. Remove the `/platforms` alias

- Delete the route (`routes/api.php:195-197`).
- Delete the purge URL (`CloudflarePurgeService.php:175`) and update the two comments at `:131`, `:171`.
- Retarget to `/integrations` in: `PublicPlatformEndpointTest`, `PlatformLoopTest`, `ShopAsyncConnectTest`, `ShopRelationalStorageTest`, `EventsAsyncConnectTest`, `LegacyEventsLaneRetiredTest`, `CloudflarePurgeServiceTest`.
- Add a retirement guard asserting the path 404s, following `tests/Feature/PublicSite/PublicMenuRouteRetiredTest.php`.

`PublicIntegrationController`'s class docblock (`:12`) names the old path — update.

## 7. Remove `architecture_id`

Four layers. The last two are separately revertible and land last.

**7.1 API resources** — `SiteResource:122`, `StaffSiteResource:27`, `StaffUserListResource:49`, `StaffUserController:140,144`, and `IndividualProfileResource:148-149` (both `architectureId` **and** the `skeletonId` transition alias).

**7.2 Validation** — `UpdateSiteRequest:26,101,143` (incl. `ALLOWED_ARCHITECTURES`), `StaffUpdateSiteRequest:30,86`, `NormalizesSiteUpdateInput`.

**7.3 Model / payload / export** — `Site::$fillable:106`, `Site::DEFAULT_ARCHITECTURE_ID:62`, the property docblock `:30` and class comment `:51`; `AllSiteData`; `DataExportPayloadBuilder:346`; `IndividualProfilePayloadBuilder:47,81,127`; `SiteProvisioningService:237`.

**7.4 Postgres** — one migration replacing `all_site_data` and the `20260817000000` payload function to stop emitting `skeleton_id` (three sites, §2.6).

**7.5 Column drop** — a **separate, later** migration dropping `sites_architecture_id_check` then the column. Sequenced after the frontend PR is deployed so the wire change and the destructive DDL revert independently.

`tests/Schema/ArchitectureSystemConstraintsTest` pins the CHECK constraint and runs in the **applied-schema lane** (`composer test:schema`, CI `ci.yml`) — **not** `composer test`. Update it in the same change as 7.5, and run that lane explicitly.

## 8. Compat maps

- `SitepageId::LEGACY_PAGE_IDS:153` + `normalizePageId():155` — delete; update callers at `SiteOrderingValidationRules:106,148` and `SiteActionsService:529`.
- `SiteOrderingValidationRules::LEGACY_BUTTON_REF_TO_ACTION_ID:37` + its use at `:155` — delete.

Conditional on the §9 re-measure returning zero. See the read-path caveat in §2.7.

## 9. Sequencing and contested files

Ten files in this blast radius are owned by branches that were live on 2026-08-20:

| File | Owner |
|---|---|
| `bootstrap/catalog/compiled.php` | `fix/dev-red-queryplan-and-pint-2026-08-19` |
| `PlatformConnectRequest.php` | `fix/dev-red-…` |
| `SuggestionsController.php` | `fix/dev-red-…` |
| `SyncFindingsBridge.php` | `fix/dev-red-…` |
| `LinkRouter.php` | `fix/dev-red-…` |
| `WebsiteLinkHarvester.php` | `fix/dev-red-…` |
| `DerivedDescriptorFactory.php` | `fix/dev-red-…` |
| `IndividualProfileResource.php` | **both** |
| `IndividualProfilePayloadBuilder.php` | `feature/item-feed-2026-08-19` |
| `SiteOrderingValidationRules.php` | `feature/item-feed-2026-08-19` |

`bootstrap/catalog/compiled.php` is the blocker. It is a **git-tracked generated artefact**, and `fix/dev-red-…` already modifies it (33 insertions, 30 deletions, from edits to 5 `Catalog/Definitions/*` and `Hosts.php`). §5 rewrites essentially every line of the same 338 KB file plus the digest.

A conflict there must **never** be hand-resolved: a wrong resolution does not fail loudly, it silently mis-maps platform identity app-wide. The only correct resolution is *take one side, re-run `catalog:compile`* — which requires sequencing, not merging.

### Phase 1 — no contested files

§4, §6, §8, and §7.1–7.3 restricted to the **dashboard** resources (`SiteResource`, `StaffSiteResource`, `StaffUserListResource`, `StaffUserController`, both Requests, `NormalizesSiteUpdateInput`, `Site`, `AllSiteData`, `DataExportPayloadBuilder`, `SiteProvisioningService`).

This delivers exactly the backend change the frontend PR needs (§2.5: every confirmed consumer is a dashboard surface).

### Phase 2 — after `fix/dev-red-…` and `feature/item-feed-…` merge

§5 in full (catalog fold + `compiled.php` regeneration), `IndividualProfileResource` / `IndividualProfilePayloadBuilder`, `LEGACY_BUTTON_REF_TO_ACTION_ID`, the 5 contested `LegacyPlatformMap` call sites, and §7.4.

### Phase 3 — after the frontend PR deploys

§7.5, the column drop.

**Before starting either phase:** re-run `git worktree list` plus each sibling's `git diff --name-only development...HEAD` and re-run the §2.7 data query. Both readings are stale the moment another session commits.

## 10. Testing

| Lane | Command | Covers |
|---|---|---|
| Default | `composer test` | resources, requests, routes, compat-map removal, retargeted `CatalogLegacyMapTest` |
| Applied schema | `composer test:schema` | `ArchitectureSystemConstraintsTest` — **required** for §7.4/7.5, does not run in `composer test` |
| Postgres | `composer test:pg` | only if `ProjectionWriter:950` (`pluck('platform')`) is touched |

New tests:

1. **Routing-class single-lane guard** (architecture test) — asserts no class outside `App\Catalog` holds a surface→routing-class table, so the lane cannot regrow.
2. **`/platforms` retirement guard** — asserts 404.
3. **Catalog-derived alias SQL** — asserts generated CASE equals all three applied representations (§5.4).

Note `tests/` runs SQLite while prod is Postgres. §7.4/7.5 are constraint-bound DDL changes; verify against `supabase/migrations/` DDL and the schema lane, not a green default suite.

## 11. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Astro sitepage still calls `/platforms` | High — integration cards vanish | Unverifiable locally; owner-accepted 2026-08-20. Confirm before Phase 1 merge, or hold §6 to Phase 3. |
| Astro sitepage reads `architectureId`/`skeletonId` | Medium — unverified consumer | Sitepage is not checked out; unlike the dashboard (§2.5) its consumption shape is unknown, so it cannot be discounted the same way. §7.1's public-wire half is Phase 2; the `skeletonId` alias was already marked "drop once confirmed" and never was. |
| Dashboard reads `architecture_id` | **Low** — no user-visible change | Verified on live `main`: every consumer has a fallback or is `.optional()` (§2.5). Frontend PR is a type tidy-up, not a blocker. |
| `compiled.php` merge conflict | High — silent identity corruption | Phase 2 gating (§9). Never hand-resolve; re-run `catalog:compile`. |
| Stored settings carry `book` after re-measure | Medium — read-path break | §2.7 caveat; re-measure gates the deletion. |
| Column drop irreversible | Medium | Phase 3, separate migration, after FE deploy. |
| Removing `CatalogNotCompiled` fallback hard-fails a broken env | Low | Artefact is git-tracked (§2.2); loud uniform failure is the intended outcome. |

## 12. Open questions

1. Has the Astro sitepage flipped off `/platforms` and off `skeletonId`? Blocks §6 and §7.1's public half. **Requires an answer outside this repo.**
2. Should §7.5 wait for a prod schema-reconciliation window? Prod lacks the four newer schemas and holds no customer data, so its `architecture_id` drop is independent — but it should not be forgotten.
