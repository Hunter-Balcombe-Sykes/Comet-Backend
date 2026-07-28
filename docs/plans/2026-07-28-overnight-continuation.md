# Overnight completion push — continuation file (2026-07-28, ~14:5x AEST)

Resume-from-here for ANY Claude session. Read with: docs/plans/2026-07-28-remaining-work-audit.md
(verified ground truth), 2026-07-27-content-platform-rebuild.md (the plan),
2026-07-28-p8-deletion-readiness.md, 2026-07-27-rebuild-execution-log.md (history through round 5).

## DONE tonight (all live on dev unless noted)
- FE rounds 4-5 (Partna-Frontend main, head ~a93ecaf5): one table grammar everywhere (bulk select,
  toolbar Add/Refresh, row detail) across Platforms/Links/Events/Watch/Listen/Products; provider/store
  rows; every row opens its sheet (click-catcher fix 3dc6862f); commerce catalog in add sheet
  (Bandcamp/Gumroad/Shopify/WooCommerce + per-category URL field + full logo sweep); honest add
  outcomes (outcome:review keeps sheet open); SUGGESTIONS INBOX on /account/platforms (first consumer
  of GET/accept/dismiss /routing/suggestions); SetPrimarySheet built+tested, GATED OFF
  (SET_PRIMARY_PERSISTENCE_READY=false in set-primary-sheet.tsx) pending task #26 backend API.
- Backend (development, head = lane-C push after af7b7dd4): commerce shelf catalog (9185e01e);
  CI unbroken (b22612dc; was red since 07-27); connect fixes live-verified on Maha (dotted IG handles,
  Medium {2,40}, shop 429→422 store_catalog_blocked); round-5 routing repairs (af7b7dd4): Note never
  blocks + WRITES the link card (verified live: broadsheet.com.au lands in Links), Reject-only
  blockReason, array_replace verdict fix, suggestions payload via ConnectionPayload::forWrite,
  ConnectorRegistry 9/9 + drift test; LANE C shipped: tombstone backfill migration
  (20260728120000, JUST PUSHED to dev ref) + resurrection tests, probe runtime skeleton
  (1/5 probes: shopify /meta.json), StoreBrandSeeder, LinkInBio batch import, brand-asset pipeline
  (20260728130000 pushed) wired to store logos, catalog FK formally WITHDRAWN with written argument.
- E2E: Maha Restaurant (user 019e5c37-9a69-725c-b3a9-6a345af0376d, handle ollies,
  tobiasindarwin@gmail.com, business/restaurant) seeded with 25+ REAL connections via the live API.
  Expected blocks: strava+skool staff kill-switched; fresha capability-blocked (food business).
  Browser session in this chat is logged in as @brokenovenpizzabar.

## RUNNING right now (background agents; results must be stitched when they report)
- LANE A (Fable, worktree): ingest sources seam → RunExecutor invokes projectors → ingest:project →
  content.items lands → full 7-op section DSL → document build trigger → site_documents; live row
  counts on Maha. Pushes to development (or branch lane-a-spine on conflict).
- LANE B (Opus, worktree): curation (sections/section_items pins/excludes), identity queue
  (candidates/rulings/merges), manual_overrides CRUD, GET /content/kinds, section trace; policies +
  tenant-isolation tests. Pushes to development (or branch lane-b-apis).
- After each lane lands: rebase check, run its touched suites, verify dev deploy succeeded
  (cloud deployment:list development), update execution log.

## NEXT (dependency order — task list ids in this session's tracker)
1. #26 primary-CTA API: read per-class is_primary + POST /routing/connections/{id}/primary; then flip
   SET_PRIMARY_PERSISTENCE_READY + implement persistPrimaryChoice (FE seam + 10 tests ready).
2. #20 YouTube Music releases: releases-tab ytInitialData or Data API; quick guard first
   (200-with-zero-entries feed = "could not load", never a dead empty card).
3. #21 Gumroad store connector (Inertia data-page payload or OAuth API) — store wizard 422s
   unsupported_store today.
4. Lane-C leftovers: remaining 4 probes (Woo/Squarespace/BigCartel/generic — port from
   ShopProviderDetector), ShopBrandProfiler successor (product-catalog derivation), decision on
   tombstone vs deliberate manual re-add (PlacementPolicy::isTombstoned ignores isDirectRequest —
   if re-add should win, fix reader not backfill).
5. Wave 2 (after A/B land): LibraryView + Section inspector + RestylePreviewDialog (FE, need A/B
   endpoints); §13 kit autopilot (fromBrandPalette/fromWebsiteEvidence; design_kit_restyles table
   exists unused); §15 PresetInstantiator; §14 field_bindings; apps/pages fetch-document.ts behind
   flag (plan §9); remaining ~12 connectors (plan §11; Menulog/Deliveroo out of scope).
6. P8 deletions (~52-58k LOC): ONLY after lanes land + dev soak serving pilot accounts. Owner inputs
   required: TikTok legal sign-off, Booksy paid-test approval. Then execute the readiness checklist.

## Known landmines for the next session
- composer test full-run OOMs locally (PHP 8.5 vs CI 8.4) — run suites chunked. Known FE flake:
  setup-steps HandleStep under load (passes solo).
- Two agents may still hold worktrees under Comet-Backend/.claude/worktrees/ — leave them.
- Backend deploys: push development = deploy dev. Prod deploys via push development:production
  (NOT done tonight; prod untouched).
- app.partna.au (prod FE) talks to dev-api.partna.au (NEXT_PUBLIC_API_BASE_URL) — intended.
- Maha data quirks: stale "Doc Pizza" online-ordering rows from an earlier GB seed; IG followers/post
  counts 0 (partial Apify) — cosmetic.

## Update ~15:1x — lanes B + C stitched
- LANE C DONE + both migrations APPLIED to dev ref (tombstones backfill 20260728120000, brand_asset_refs 20260728130000).
- LANE B DONE (routes pushed 61636698..bec14245): curation/pages/sections/items/groups, identity queue, overrides, /content/kinds, section trace; 71 tests. Its one red (IngestBrandAssetJob missing failed()) fixed in 90af365d — development green.
- Open from B's report: DisjointSet::separate() directional bug (task #27, characterization-pinned); merges repoint source items only (facets re-derive when lane A's projector lands); trace explains 2/7 ops until lane A's full DSL.
- Still running: LANE A only.
