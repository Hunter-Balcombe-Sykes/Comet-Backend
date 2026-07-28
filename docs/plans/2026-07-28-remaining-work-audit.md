# Remaining-work audit — verified 2026-07-28 (Opus audit agent, all file:line-checked)

Ground truth for the full-completion push. Plan: 2026-07-27-content-platform-rebuild.md.
Lanes cite this file; re-verify a verdict only if the code moved since.

## Verified state
- DONE + live: P0-P2 (catalog, routing engine, /catalog/surfaces + /routing/preview|links consumed by FE), P6 rounds 1-5 (Platforms table, pool template, one table grammar), Pinterest retirement, CI pipeline, monorepo tokens audit.
- DocumentBuilder executes only kind_is + published_within of the 7 RuleOperator ops (app/Site/Documents/DocumentBuilder.php:204-217) AND has zero callers.
- Identity Resolver, Override/ValueResolver: pure tested domain logic, zero callers; content.* (33 tables) has NO writer; site.pages/sections/section_items read-only via DocumentBuilder.
- Connectors: 9 classes; ConnectorRegistry mapped only 4 (fresha, google_business, spotify, vimeo, youtube were dead — round-5 repairs register them). ~12 connectors remain per plan §11 (Menulog/Deliveroo explicitly out of scope).
- Ingest chain broken twice: nothing creates ingest.sources (only tests); RunExecutor never invokes a projector; ingest:project command referenced but missing.
- Serving: site.site_documents written only by the caller-less builder; apps/pages has no fetch-document.ts (plan §9).
- §12 store logos, §13 kit autopilot (fromBrandPalette/fromWebsiteEvidence; site.design_kit_restyles exists unused), §15 PresetInstantiator, §14 field_bindings: all greenfield.
- P8 blockers all stand (2026-07-28-p8-deletion-readiness.md): B1 probe runtime (LinkProbeWorker/ProbeGate/ProbeBudget), B2 ShopBrandSeeder successor, B3 LinkInBioImporter takes one URL not a list, B4 suggestions UI (backend live at routes/api/user.php:75-79, zero FE callers), B5 routing.item_tombstones backfill missing (table at 20260727120000:92; reader PlacementPolicy.php:130).
- FE plan §16 all unbuilt: LibraryView, Section inspector, SuggestionsInbox, SetPrimarySheet, RestylePreviewDialog. GET /api/site/sections/{id}/trace + GET /api/content/kinds: no routes.
- Deferred FK — RESOLVED 2026-07-28 as **withdrawn, not owed**. `catalog:sync` runs after the code deploy (routine-deploy step 2b) and hourly, never as a migration step, so a from-zero apply ends with `catalog.surfaces` empty: an FK (NOT VALID included — it still enforces on INSERT/UPDATE) would 500 every connection write until someone synced. Second reason: the R2 dump is the only backup and carries `pinterest.profile` rows, while a sync into a fresh project writes only the current artefact — so a restore could never VALIDATE. `IntegrationConnection::booted()` already rejects unknown surfaces against the COMPILED ARTEFACT (`ConnectionSurfaceGuardTest`), which is stricter than the DB projection an FK would check. Full argument amended into `20260727110000`'s header; `routing.source_intents.surface_key` stays unconstrained for the same reasons.
- Legacy: LinkRouter/ProviderDetector still in 30 files; lib/social/platforms.ts has 9 FE consumers. These delete in P8, not before.

## Execution lanes (2026-07-28 night)
- LANE A (spine): ingest sources creation at connect-time → RunExecutor invokes projectors → ingest:project → content.items lands → DocumentBuilder full 7-op DSL + build trigger + site_documents → apps/pages fetch-document behind a flag.
- LANE B (APIs): curation (sections/section_items pins/excludes), identity queue (candidates/rulings), manual overrides CRUD, /content/kinds, section trace. Policies + tests.
- LANE C (P8 blockers): tombstones backfill migration + characterization tests; probe runtime §11; brand plane §12; LinkInBio multi-URL; ShopBrandSeeder successor.
- LANE D (FE): suggestions inbox UI + SetPrimarySheet now; LibraryView + Section inspector + RestylePreviewDialog once A/B endpoints exist.
- WAVE 2: §13 autopilot, §15 presets, §14 bindings; remaining connectors; then P8 deletions.

## P8 physics
The deletions (~52-58k LOC: LinkRouter, ContentSelection, per-platform services + FE sections, config social_platforms, payload column, v5 branch) execute only after lanes A-D land AND the new pipeline serves the pilot accounts through a dev soak, plus two OWNER inputs the plan itself gates on: TikTok legal sign-off and Booksy paid-test approval. Nothing else external is required (Apify budget keys exist).
