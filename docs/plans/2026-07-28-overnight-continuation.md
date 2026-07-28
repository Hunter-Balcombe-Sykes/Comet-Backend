# Backend completion push — continuation file (2026-07-28 evening; BACKEND ONLY)

OWNER DIRECTIVE 16:1x: this push covers Comet-Backend ONLY. All UI/frontend work is handed to the
frontend dev — do not build FE here. FE-relevant seams are listed at the bottom as handoff notes.
Companion docs: 2026-07-28-remaining-work-audit.md (verified ground truth),
2026-07-27-content-platform-rebuild.md (the plan), 2026-07-28-p8-deletion-readiness.md,
2026-07-27-rebuild-execution-log.md (history through round 5).

## Backend DONE tonight (all deployed to dev)
- Commerce catalog shelf (9185e01e); CI unbroken (b22612dc); connect fixes live-verified on Maha
  (dotted IG handles, Medium {2,40}, shop 429→422 store_catalog_blocked).
- Round-5 routing repairs (af7b7dd4): Note never blocks + writes the link card (live-verified),
  Reject-only blockReason, array_replace verdict fix, suggestions payload via ConnectionPayload,
  ConnectorRegistry 9/9 + drift test, outcome field (connected|link|review) on /routing/links.
- LANE C: tombstone backfill 20260728120000 + brand_asset_refs 20260728130000 — BOTH APPLIED to dev
  ref; probe runtime skeleton (shopify /meta.json probe); StoreBrandSeeder; LinkInBio batch import;
  brand-asset pipeline wired to store logos; catalog FK formally withdrawn (argument in
  20260727110000's header). IngestBrandAssetJob failed() hygiene fix (90af365d).
- LANE B: full API surface — pages/sections/section_items/section_groups curation (pins/excludes),
  section trace, /content/kinds, identity queue (candidates/rule/dismiss), manual overrides CRUD;
  policies, resources, tenant isolation; 71 tests (61636698..bec14245).
- E2E: Maha account (user 019e5c37-9a69-725c-b3a9-6a345af0376d, handle ollies) seeded with 25+ real
  connections via the live API. strava/skool kill-switched; fresha capability-blocked (correct).

## RUNNING
- LANE A (worktree agent): ingest sources seam (committed), projection real → content.items
  (committed, incl. FreshaServiceProjector), NOW: full 7-op DSL + site:build-documents command +
  document job; then live Maha row counts, rebase, push. Stitch on landing: run its suites, verify
  dev deploy, update execution log.
- WAVE-2B agent (worktree): #26 primary-CTA API (read per-class is_primary + POST
  /routing/connections/{id}/primary), #27 DisjointSet::separate() direction fix (characterization
  test pinned in IdentityQueueTest), tombstone-vs-deliberate-re-add decision (PlacementPolicy
  isTombstoned should let isDirectRequest re-adds win — implement origin-aware check), B4
  findings/synced-modal scan contract (p8-readiness doc names it; sole hard blocker on scan paths).
- WAVE-2C agent (worktree): remaining 4 shop probes (Woo/Squarespace/BigCartel/generic — port from
  ShopProviderDetector into app/Routing/Probes), ShopBrandProfiler successor (product-catalog
  derivation), §13 kit autopilot (fromBrandPalette + fromWebsiteEvidence → design_kit_restyles),
  §15 PresetInstantiator (10 presets → pages/sections/kit seed), §14 field_bindings.

## NEXT backend queue (after the above land)
1. Connector fleet (plan §11, needs LANE A landed first — same dirs): eventbrite, humanitix,
   soundcloud, twitch VODs, skool, strava; #20 youtube-music releases strategy (+ empty-feed guard);
   #21 gumroad connector (Inertia payload/OAuth); instagram (Apify, manual-only+caps);
   square/ubereats/doordash menus (Apify, budget keys exist). GATED: tiktok (owner legal sign-off),
   booksy (owner paid-test approval). OUT OF SCOPE: menulog, deliveroo (plan §11 says so).
2. §14 provisional-site hardening: pre-account build pipeline swap to §4 ingestion.
3. Serving cutover backend half: document build triggers on content change (lane A lands the
   command/job; wire observers + schedule); then dev soak on pilot accounts.
4. P8 deletions (~52-58k LOC backend: LinkRouter, ContentSelection, per-platform services, config
   social_platforms, payload column, v5 branch) — execute the readiness checklist ONLY after soak.
   Owner inputs still required: TikTok legal sign-off, Booksy paid-test approval.

## Handoff notes for the FRONTEND dev (not our work)
- SetPrimarySheet exists gated OFF: set-primary-sheet.tsx SET_PRIMARY_PERSISTENCE_READY=false —
  flip + implement persistPrimaryChoice once wave-2B ships the primary API.
- LibraryView / Section inspector / RestylePreviewDialog (plan §16): backend endpoints from LANE B
  (+ /content/kinds, trace) are live; document/library data arrives with LANE A.
- apps/pages fetch-document.ts (plan §9 serving consumption in the monorepo).
- lib/social/platforms.ts deletion waits on FE consumers (9 remain) — part of P8's FE half.

## Landmines
- composer test full-run OOMs locally (PHP 8.5 vs CI 8.4) — run suites chunked. FE flake irrelevant now.
- Agent worktrees may exist under .claude/worktrees/ — leave them.
- Push development = deploy dev. Prod = push development:production (NOT tonight; prod untouched).
- Maha data quirks: stale "Doc Pizza" ordering rows from an earlier GB seed; IG counts 0 (partial
  Apify) — cosmetic.
