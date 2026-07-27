# Content & Platform Rebuild — Execution Log

Companion to `2026-07-27-content-platform-rebuild.md`. One entry per phase; updated as each phase lands. Owner approved full autonomous execution 2026-07-27 evening ("everything is sweet and good to go — execute it all"); all §23-E approvals granted by that sign-off.

| Phase | Status | Branch / commits | Gate result |
|---|---|---|---|
| P0 early hotfixes | ✅ landed | `rebuild/p0-hotfixes` → development | full suite + new regression tests green |
| P1 catalog | ✅ landed | `rebuild/p1-catalog` → development | compile+contract gates, 26 catalog tests (1,161 asserts), sync idempotence on real PG, full suite green |
| P2 routing | ✅ landed | `rebuild/p2-routing` → development | 449-case corpus, 100 routing tests, full suite + architecture guards green |
| P3 ingest + pilots | ✅ core landed | development | 105 ingest tests; schema live on dev |
| P4 content/identity | 🔄 core landed | development | 33-table content schema live; 28 resolver/value tests |
| P5 sections/serving + creator cohort | 🔄 core landed | development | sections+documents schema live; 21 builder/rule tests |
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

## P3 — Ingestion (2026-07-27)

**Shipped:** `ingest` schema (15 tables: sources with the DB claim as THE run lock and an EWMA cadence band, streams with coverage/health/guard/run_seq fence, runs, hash-partitioned content-addressed `record_versions`, `record_state`, the effects ledger, anomalies). Manifest/StreamSpec/SourceProfile — one declaration deciding cadence, whether absence may EVER mean deletion, and the dashboard's pinnable/editable flags. Message sum type (Record | Covered | Bookmark | Note | Deferred | Unavailable). Coverage + domination. DocHasher (volatility on the hash INPUT only) and Redactor (applied BEFORE landing). EffectLedger (charge-once; a dead claim is REFUSED, not retried). Lander (unchanged content writes zero rows; ≥40% single-run vanish trips the guard, files a critical anomaly and deletes nothing). Connector contract + HttpIo/ReplayIo + RunExecutor. SourceScheduler. Projection layer with instrumented reads. Connectors: Bandcamp, Vimeo, Spotify oEmbed, YouTube RSS, Fresha, Google Business. `ingest:dispatch` (15 min) + `ingest:stranded` (hourly) + a dedicated Horizon lane.

**Corrections made to delivered work:** the Horizon lane was sized 2×256 MiB, pushing the box's permitted ceiling ~514 MiB past 2048 — capped to ONE worker at 192 MiB everywhere, with the residual ~194 MiB over-commit stated plainly in the config (the box had only ~88 MiB of headroom before a fifth lane existed, so *any* fifth lane crosses it; `memory` is a restart threshold, not a reservation; the real fix is the resize §19 already carries). `RunExecutor` read `is_claimed` off a column that does not exist and so always defaulted TRUE — which would have landed third-party personal data for unclaimed accounts, exactly the regression claim-state-scoped redaction prevents. `PlacesBudgetGuardTest` caught the GoogleBusiness connector naming the Places host in its manifest; it makes no direct HTTP calls at all (the billed call goes through the ledgered effect whose driver claims PlacesBudget), so the host list is now empty with the reason stated.

**Also:** phpstan caught absence-folding calling `Covered::dominates()` instead of `Covered->coverage->dominates()` — it would have fataled the first time any stream tried to fold absence.

## P4 — Content & identity (2026-07-27)

**Shipped:** `content` schema (33 tables) — items (thin spine: no pool, no is_selected, no sort_order, no identifier), source_items with stable coords, identity_keys as EVIDENCE (deliberately no unique index — two sources sharing an ISRC is the signal, not a write failure), identity_decisions, anchors, merges, candidates, `manual_overrides` (typed per-column, NULL = explicit clear), 14 typed facet singletons keyed (item, source), set-union collections (offers/media/tags/variants/actions/refs), media_assets as entities, collections and slugs.

**The Resolver is pure (C7):** source items + human decisions in, groups out, no DB and no clock, so identity is RECOMPUTED rather than accumulated. Pass order is load-bearing — join → user's "same" → user's "different" as a CUT → corroborating unions — which is what makes a human ruling survive a joining key that disagrees (C8). Poisoned keys (one source attaching a value to two of its own records) are dropped entirely. Evidential keys never merge; they queue, and never re-ask an answered question.

**ValueResolver:** per-column rules (recency/priority/longest/union) with manual override absolute. Recency ranks by when CONTENT changed, past a 24h dwell — otherwise an hourly re-publisher owns every volatile column forever.

## P5 — Sections & serving (2026-07-27)

**Shipped:** pages/sections/section_items/section_groups + source_routes + versioned site_documents + site_build_state + design_kit_restyles. Nav is PAGES (three sections on a Menu page = one nav row). Capability sits on the page and is checked at WRITE time. The rule DSL is bounded at 7 operators / 5 predicates — that bound is the feature, since it is what lets one rule be validated, rendered as an English sentence, and explained per candidate.

**DocumentBuilder:** read revision → build → hash → commit under CAS. Byte-identical output inserts nothing and purges nothing; a builder whose revision moved cannot mark the site clean. Two bugs found in testing: a raw `now()` that tied BuildState to Postgres for no benefit, and `commit()` against a site with no state row affecting zero rows — the FIRST build of every new site would have reported "superseded" forever.

## Frontend (P6 groundwork)

`lib/catalog.ts` reads `GET /catalog/surfaces` instead of mirroring the backend registry in 565 hand-maintained lines. Module-level cache is safe because the ETag is the artefact digest — the payload can only change on deploy. `legacyPlatform` is carried so the old mirror can be deleted screen by screen rather than in one flag day.

## Going live (2026-07-28)

**The showcase accounts were not reachable, then were blank.** Two separate faults, both only findable from outside the code:

1. `showcase-creator` and `showcase-eats` were published in the database but had no Cloudflare KV entry, so `<handle>.partna.au` 404'd. Dev and prod share one KV namespace (`ollies` was present, the showcase pair was not), so `partna:backfill-subdomain-kv` was all that was needed. Both now serve 200.
2. Once reachable, every platform page rendered the bare home shell with zero outbound links. The connections were real, active and correctly routed — but the public wire served `"payload":[]` for all 20 of them. `PublicIntegrationConnectionResource` filters payload through a per-platform allowlist (socials expect `{username,url}`), and the showcase seeder wrote only `{source:'showcase'}`, so filtering left nothing. The sitepage reads `payload.url`, and Instagram reads `payload.username` exclusively, so an empty payload renders an empty page.

The seeder was the immediate cause, but `SourceReconciler` had the same bug latent: it wrote `url` and never `username`, so a genuinely routed Instagram connection would also have rendered blank. Both writers now go through `App\Routing\ConnectionPayload`, which also refuses to publish composite (`artist/4gzp…`) or opaque (`1419227`) identifiers as a username. **This survived 5,969 passing tests because nothing asserted what reaches the public wire.** After backfilling 28 rows, showcase-creator renders 10 platforms and showcase-eats 3.

Apple Music and Apple Podcasts still render nothing, correctly: their allowlists expect `link`, which is *content* supplied by a refresh job, not identity written at link time. `last_refresh_status = 'pending'` is the honest state.

**Deploy trap, re-derived the hard way.** The pages worker deploy was correct and active, yet the live page kept serving pre-removal Pinterest markup. The router caches at `PRIMARY_CACHE_TTL_S = 86400` and builds its cache key without the query string, so a `?cb=` buster does nothing. `apps/pages/CLAUDE.md:125` already documents both facts; reading it first would have saved the detour. Purge is not optional after a pages deploy.

**Pinterest removal completed** across the monorepo (design-system assets, page taxonomy, platform sections, actions vocabulary). The whole Pinterest data path in apps/pages turned out to be already dead — no component read `surfaces.pinterest` — so removing it changed no behaviour. The "Other" platform-section category went with it, having existed solely to hold Pinterest.

**Monorepo CI had been red since 2026-07-25** on the tokens-only audit, failing on five values `apps/pages/CLAUDE.md` already sanctioned. The regex lived in both `ci.yml` and CLAUDE.md and the copies drifted. Both now call `apps/pages/scripts/tokens-only-audit.sh`. Verified the gate still fails on a real violation rather than being green by construction; that check exposed a blind spot (only line-leading declarations are inspected, so single-line rules and `@media` breakpoints go unseen) which is documented in the script rather than papered over, since widening it would flag the two structural breakpoints.

**Link-add sheet mounted** on `/account/custom-links`, replacing the single-field modal with the URL-plus-category-shelves drawer. Wiring it up exposed two more defects: `lib/catalog.ts` cast its response without parsing it (a non-catalog body threw inside a render and took the surface down), and `useCatalog` fetched on mount, so a closed sheet bought a request on every page load. Both fixed, and `lib/catalog` went from zero tests to ten.

**P8 remains blocked** — see `2026-07-28-p8-deletion-readiness.md`. Five capabilities the new pipeline does not have, not five tasks nobody got to. The one with real user-visible harm is the missing `routing.item_tombstones` backfill: migrating a scan path before it would resurrect connections users deliberately deleted, and no test would catch it.
