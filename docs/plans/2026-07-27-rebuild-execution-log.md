# Content & Platform Rebuild — Execution Log

Companion to `2026-07-27-content-platform-rebuild.md`. One entry per phase; updated as each phase lands. Owner approved full autonomous execution 2026-07-27 evening ("everything is sweet and good to go — execute it all"); all §23-E approvals granted by that sign-off.

| Phase | Status | Branch / commits | Gate result |
|---|---|---|---|
| P0 early hotfixes | ✅ landed | `rebuild/p0-hotfixes` → development | full suite + new regression tests green |
| P1 catalog | ✅ landed | `rebuild/p1-catalog` → development | compile+contract gates, 26 catalog tests (1,161 asserts), sync idempotence on real PG, full suite green |
| P2 routing | ✅ landed | `rebuild/p2-routing` → development | 449-case corpus, 100 routing tests, full suite + architecture guards green |
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

## P2 — Link routing (2026-07-27)

**Shipped (plan §2):** `PublicSuffixList` (vendored PSL, exception/wildcard algorithm) → `IriCanonicalizer` (the ONE canonicaliser) → `Rulepack` (registrable-key index + build-time specificity order, baked into the catalog artefact) → `LinkProjector` (pure, 0–100 confidence + margin) → `PlacementPolicy` (both gates PRE-write; sealed Place/Choose/Note/Hold/Reject) → `SourceReconciler` (intents → connections; booking XOR; caps; never replaces user data) → `LinkObserver` (append-only, best-effort). `LinkRoutingService` orchestrates; `POST /api/routing/preview` + `/api/routing/links` succeed `CustomLinksController::addLink` with a compatible envelope. `routing` schema (20260727120000): month-partitioned observations, source_intents (5 states / 9 block reasons), item_tombstones, import_runs. `routing:reproject` replays recorded traffic against the current or another checkout's artefact and **exits non-zero when a classification would be LOST**, so it can gate a rules PR.

**The spoofable-host class is dead structurally**, not by patch: identity is the PSL registrable domain, so `brand.attacker.com` resolves to `attacker.com` and no brand rule can reach it — regardless of what any pattern says.

**Corpus (the P2 gate): 449 cases.** The 285 positives are GENERATED by walking every compiled detector, synthesising a URL its own pattern accepts and asserting the real pipeline round-trips it — so the corpus cannot fall behind the catalog, and a detector-coverage test proves none is missed. 164 hand-written attacker cases (spoofed hosts across 32 brands, lookalikes, IDN confusables, userinfo tricks, path-embedded brands, reserved paths, bare domains, own-infra, non-http schemes, shorteners, ordinary businesses, malformed input) assert nothing ever connects.

**Defects the corpus caught (all fixed):** `apple_music`/`apple_podcasts` detectors were keyed on FULL HOSTS (`music.apple.com`), which the registrable-domain lookup never produces — **both surfaces were undetectable**; three patterns anchored on a trailing slash could never match the canonical form; Shopify/Big Cartel storefront detection had been lost entirely in the P1 catalog pass (restored); OpenTable's `?rid=` rule covered only `.com`, missing every regional booking URL including AU.

**Messy-input robustness** (owner requirement, now plan §2): locale/UI params, `/amp` + `/index.html`, copy-paste damage, prose-embedded links, and line-wrapped URLs are all normalised — identity params never touched.

**Also fixed en route:** a pre-existing test-suite fragility (`RefreshFetchBudgetTest` borrowed four helpers from three other test files; it only passed when Pest co-located them, and new test files broke it) and SQLite's 10-database ATTACH cap silently swallowing the new schemas (four long-deleted schemas dropped from the attach list). New directories wired into all 12 audit lenses + their `.md` scope groups — no exemptions, no weakened assertions.

**Decisions logged during build:** capability naming standardised `role.brand.method.v1`; legacy `shop` rows map to `partna.storefront` (real storefront connections), with `partna.manual_product` reserved separately for §16 manual adds — a deliberate refinement of the plan's pseudo-platform wording; Instagram's surface carries no fetch capability until the P3 connector exists (bespoke Apify flow noted); `isConnectable` means "the catalog can drive this" (bespoke-only GBP/Skool are notConnectable with notes); brand plane's design-system `brand.json`/colors.css generation deferred to its consumers (P5/P6); youtube_music icon asset still missing — P6 item. Three pre-existing test failures from the dev's 27-provider stopgap were repaired en route (registry coverage now asserts registry==LegacyPlatformMap lockstep), plus two latent test bugs the stricter SQLite schema mirror exposed.
