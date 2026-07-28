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
- LANE A: LANDED + STITCHED (2026-07-28 ~17:30). All three commits deployed to dev; suites green;
  Maha live: 10 sources / 142 items. Stitch found + fixed a live bug (e64bd999): bumpSite and
  site:build-documents filtered site.sites on a deleted_at column only the SQLite stand-in had —
  every projection bump 42703'd silently, so no document ever built. Verified end-to-end after fix:
  doc v1 built inline, sweeper picked up a manual bump within 3 min (unchanged-hash path). See
  execution log "LANE A landed + stitch".
- WAVE-2B: DONE, merged e76c8456, deployed to dev. #26 primary-CTA API (GET /routing/connections +
  POST .../{id}/primary; no migration — is_primary existed), #27 DisjointSet::separate() cuts in
  both argument orders, tombstones origin-aware (direct paste wins, reconciler deletes superseded
  refusal; scan suppression kept), B4 SyncFindingsBridge folds Hold intents into the IG synced
  modal at read time + SuggestionApplier extraction. 1941 tests green on merged result.
- WAVE-2C: DONE, merged 8cf04af5, deployed to dev. 5-platform probe cascade (+squarespace.store
  surface), catalog-fed StoreBrandProfiler, §13 autopilot + restyle endpoints, §15 PresetInstantiator
  (no cold-build caller yet — §14 pipeline swap wires it), §14 field_bindings (migration
  20260728150000 APPLIED to dev ref). IdentitySync stays live writer until the swap.
- CONNECTOR FLEET: DONE, merged 11c399ab, deployed to dev. All ten §11 items (21 connector classes
  registered, drift guards green, no schema changes). tiktok/booksy stay owner-gated — NOT built.
- EffectLedger chip (fleet agent's flag): DONE 694906b7 — replays carry data, freshness-bucketed
  digests, fail-closed on missing/oversized results. NEXT-queue item 1 is now fully landed.

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
