# Content & Platform Rebuild — Execution Log

Companion to `2026-07-27-content-platform-rebuild.md`. One entry per phase; updated as each phase lands. Owner approved full autonomous execution 2026-07-27 evening ("everything is sweet and good to go — execute it all"); all §23-E approvals granted by that sign-off.

| Phase | Status | Branch / commits | Gate result |
|---|---|---|---|
| P0 early hotfixes | ✅ landed | `rebuild/p0-hotfixes` → development | full suite + new regression tests green |
| P1 catalog | ✅ landed | `rebuild/p1-catalog` → development | compile+contract gates, 26 catalog tests (1,161 asserts), sync idempotence on real PG, full suite green |
| P2 routing | queued | — | — |
| P3 ingest + pilots | queued | — | — |
| P4 content/identity | queued | — | — |
| P5 sections/serving + creator cohort | queued | — | — |
| P6 frontend | queued | — | — |
| P7 connectors/upgrades + food cohort | queued | — | — |
| P8 deletions + re-measure | queued | — | — |

## P0 — Early security hotfixes (2026-07-27)

**Scope (§17 EARLY):**

1. **Host-spoofing patterns closed.** Every open-ended `brand\.[a-z.]+$` suffix let `brand.<attacker-domain>` classify as the brand (reserve buttons / order links / connect flows pointing at attacker hosts). Replaced with enumerated real-TLD sets in:
   - `WebsiteLinkHarvester` — all nine (pinterest, opentable, thefork, quandoo, deliveroo, just-eat ×both stems, treatwell, eventbrite, ticketmaster)
   - `OpenTableService` — shared `TLDS` const across `parseRid`/`isOpenTableUrl`/`nameFromUrl`; the bare `?rid=` fallback now also requires a real OpenTable host (a rid from `random.com/?rid=5` no longer parses)
   - `EventbriteScraper` — all four pattern sites (org/event normalizers, org-page event extraction, link canonicaliser)
   - `GoogleBusinessService` — structured Google TLD shapes (`com`, `com.XX`, `co.XX`, `XX`) for the Maps-host gate + interstitial body extraction
   - `PlatformRegistryServiceProvider` — the four `HostMatch` detectors (eventbrite, quandoo, ticketek, ticketmaster). Interim-only: P2 replaces this whole layer, but it is live until then.
2. **`SafeUrlFetcher`:** own-infrastructure denylist (partna.au, supabase.co, laravel.cloud, r2.cloudflarestorage.com, r2.dev, workers.dev — exact or subdomain, checked before DNS resolution; config `partna.http_fetch.denied_host_suffixes`, env `PARTNA_HTTP_FETCH_DENIED_HOST_SUFFIXES`) + `connectTimeout` clamped to the hop/chunk budget window in both the serial loop and `pooledGet`.
3. **`SiteMedia::svgVariantUrl()`:** the 2026-07-17 original-file fallback is removed — only the container-sanitised vector variant is ever served. The fallback fired exactly when the sanitiser had been skipped (GD fallback / pipeline off), serving auto-grabbed third-party SVG that had passed only `LogoAutoGrabber`'s regex pre-filter. Missing vector now degrades to the webp raster.

**Tests:** `tests/Unit/Security/HostSpoofingHotfixTest.php` pins both directions (spoof rejected, real host accepted) for the harvester, OpenTableService, the registry detectors, the SafeUrlFetcher denylist, and the svg fallback removal. Full suite green.

**Behaviour notes:** unknown ccTLD variants of enumerated brands no longer auto-classify (fail-safe direction; extend the lists if a real market host surfaces). Sites whose logo processing had fallen back to GD render raster instead of unsanitised vector.

## P1 — Platform catalog (2026-07-27)

**Shipped (plan §1):**

- **Four-plane catalog, compiled.** `app/Catalog/` value objects (Surface/Detector/Brand + 7 enums + SurfaceBuilder/DetectorBuilder + assert-only contracts), 103 definition files under `Definitions/` (every registered brand, every WLH label promoted to a real brand+surface, config-only brands, and Partna's reserved surfaces), `_manifest.php` (explicit list, no scanning), `_capabilities.php` (44 versioned capability names → the EXISTING strategy classes; factory closures where construction composes — UrlConnect+normalizer, OEmbedFetch+endpoint). `catalog:compile` runs contracts/uniqueness/capability-coverage and emits the content-addressed `bootstrap/catalog/compiled.php` (committed; CI guard = `--check`). **Compiled: 103 brands, 110 surfaces, 268 detectors.**
- **`catalog` schema** (pushed to dev Supabase 20260727100000 after repairing the collapse-era migration history — history-table-only repair): brands/surfaces/detectors/host_aliases/suffix_overrides + runtime tables (detector_suspensions with deliberately no FK, unmatched_domains) + sync_state. `catalog:sync` = upsert-tombstone only, digest short-circuit, hourly schedule as the convergence net; deploy runbook gained the explicit step. Idempotence + tombstone/resurrect proven against real Postgres (`tests/Postgres/CatalogSyncIdempotenceTest`).
- **Connections cutover (20260727110000):** `surface_key` (+`routing_class`, `is_primary`, detector/digest provenance) is the identity; unique indexes swapped to surface_key; one-primary-per-(user,routing_class) partial index added; **`platform` is now a GENERATED alias column** (14 special back-maps + brand-prefix rule) — raw writes to it error, killing dual truth structurally. `LegacyPlatformMap` is the single bridge; six lockstep tests pin PHP map ↔ backfill SQL ↔ alias SQL ↔ SQLite test schema ↔ compiled artefact. Model writes translate transparently (`platform` mutator → surface_key), so **zero call sites changed and zero behaviour changed**: pseudo-bucket rows map to hidden `partna.*_link` surfaces that alias back verbatim; real-brand upgrades happen at P2's reproject.
- `GET /api/catalog/surfaces` (authed) — successor feed for the FE platform mirror; ETag = artefact digest.
- CatalogIntegrityCheck boot handshake (unservable → hidden not 500; orphan capabilities reported).

**Decisions logged during build:** capability naming standardised `role.brand.method.v1`; legacy `shop` rows map to `partna.storefront` (real storefront connections), with `partna.manual_product` reserved separately for §16 manual adds — a deliberate refinement of the plan's pseudo-platform wording; Instagram's surface carries no fetch capability until the P3 connector exists (bespoke Apify flow noted); `isConnectable` means "the catalog can drive this" (bespoke-only GBP/Skool are notConnectable with notes); brand plane's design-system `brand.json`/colors.css generation deferred to its consumers (P5/P6); youtube_music icon asset still missing — P6 item. Three pre-existing test failures from the dev's 27-provider stopgap were repaired en route (registry coverage now asserts registry==LegacyPlatformMap lockstep), plus two latent test bugs the stricter SQLite schema mirror exposed.
