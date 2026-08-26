# Plan 3: platform quality fixes (routing + Instagram media)

Date: 2026-08-26. Status: READY — executes automatically after
`docs/2026-08-26-menu-item-deep-links-and-cleanup-plan.md` (plan 1)
completes, and BEFORE `docs/2026-08-26-pd-registry-retirement-plan.md`
(plan 2). Same EXECUTION METHOD as plan 1's method section (inline, full
permission to go live, push as you go, fix-everything-found via todos,
auto-add discovered improvements, hard gates with fresh-eyes Sonnet
critics, checkpoints into this doc). Execution starts only on the owner's
explicit go signal for the run.

**Pace note (owner, 2026-08-26):** these tasks are smallish but NOT
"urgent" in the rushed sense. The goal is a golden-standard setup done
properly — each item gets the full treatment (tests, live verification,
critic pass), never a quick patch to move on faster.

Sources: items 4–5 of `docs/2026-08-26-backend-fixes-for-dev.md` (verified
2026-08-26 evening as NOT started by the backend dev — only the doc commit
exists; the menu-domain items 1–3 were absorbed into plan 1 — tell the dev
items 4–5 are taken too), and
`docs/2026-08-26-instagram-video-mirror-handoff.md` (diagnosis; superseded
by R3 below, which carries today's stronger verification).

## R1 — Deep links to recognised platforms fall into custom links

(backend-fixes item 4, full diagnosis there — routing scored a recognised
OpenTable deep link confidence 59 / margin 0 → `note` verdict → links pool.)

**The fix already EXISTS as code**: commit `312bda304` ("Route a
query-identified deep link to its surface instead of the links pool") on
the local unpushed branch `fix/suggestions-inbox-and-opentable-routing`
(2 commits ahead, ~133 behind development at diagnosis time). It makes
margin scan for the first DIFFERENT-surface candidate (two agreeing rules
for one surface are agreement, not ambiguity) and gives query-captured
identifiers +20 for parity with path-captured. Comes with
`LinkProjectorSurfaceMarginTest` and was verified against
`RoutingCorpusTest` (no positive changed surface, 150+ negatives hold, the
bonus can't rescue below-FLOOR detectors).

Corrections from the fresh-eyes verification pass (2026-08-26 evening):
- Branch is 2 ahead / **233 behind** development (not ~133). The fix
  commit's file (`LinkProjector.php`) has had ZERO substantive development
  changes since the merge-base (only a no-op merge), so the cherry-pick of
  `312bda304` itself is low-risk; the branch's OTHER commit (`44b589f37`)
  duplicates development's `6feebdf73`+`2678fd655` — DROP it.
  `SuggestionsController.php` diverged substantially — do NOT rebase the
  whole branch; cherry-pick `312bda304` alone onto a fresh branch.
- Test path is `tests/Unit/Routing/LinkProjectorSurfaceMarginTest.php`
  (not Feature).
- Both defects confirmed still live on development: margin vs plain
  runner-up at `LinkProjector.php:94`; path +35 vs query +15 at
  `:153`/`:173`.
- **`routing:reproject` is a READ-ONLY decision aid** ("writes nothing,
  ever" — `RoutingReprojectCommand.php:23`, test asserts output only). It
  CANNOT rescue stranded links. The handoff's rescue idea is void.

Steps:
1. Fresh branch off development; cherry-pick `312bda304` only.
2. Run `LinkProjectorSurfaceMarginTest` + `RoutingCorpusTest` +
   `SuggestionsInboxTest` + full suite.
3. Live-verify: paste the §4 OpenTable experiences URL on dev → becomes an
   OpenTable reservation connection, not a custom link.
4. **Stranded-link rescue (replaces the reproject idea):** use
   `routing:reproject --since=30d` as the read-only AUDIT to list links
   whose surface would now resolve, then re-route those through the real
   write path (a small one-off artisan command that re-runs the router on
   each listed observation's URL for its user, or delete+re-seed the
   affected cards). ollies' stray OpenTable card is the known fixture.
- GATE R1: tests green; live paste-to-connection proof; rescue verified on
  the ollies card; fresh-eyes critic on the diff.

## R2 — Previous-website links carded into custom links (race)

(backend-fixes item 5 — link-in-bio unroll carded the user's OWN old-site
pages because `workplace.previous_website` was written ~55s AFTER the
carding lane ran. Two async lanes of one signup raced.)

Correction from the verification pass: `seedWorkplace()`
(`GoogleBusinessAutoSync.php:430-521`) already writes `previous_website`
BEFORE its own sibling steps (`seed():124`, ahead of `seedSocials()`/
`dispatchInstagram()` at `:140`). The race is not intra-method ordering —
it is that the whole `GoogleBusinessEnrichJob` chain runs ASYNC against
separately-dispatched harvest/unroll jobs (Instagram → Linktree) that can
dequeue first. So fix-1 must target the DISPATCH GRAPH of the connect
flow (where the enrich job sits relative to whatever dispatches the
harvest), and fix-2 (reconciliation) is the load-bearing half.

Both halves:
1. **Narrow the window**: map the connect flow's dispatch graph; make the
   `previous_website` persist happen at the earliest point the website URL
   is known — synchronously in the connect request or as the FIRST job in
   the chain, ahead of any harvest/unroll dispatch. Verify with the run
   ledger timestamps on a fresh dev connect.
2. **Race-proof reconciliation** (the half that actually closes it): on
   `previous_website` set/changed (`WorkplaceObserver::saved()` already
   watches the field), sweep the custom-links pool and remove
   SCRAPE-SEEDED cards whose host matches. Manual adds survive (mirror
   `CustomLinkSeeder::addManual()`'s exemption, `:118-130`). **Card origin
   does not exist today anywhere on the write path** (`CustomLinkSeeder` →
   `LinkPoolWriter::add` → `ProjectionWriter::writeManualItem`; both
   scrape-seeded and user-typed land through the identical
   `manual:{sha1(url)}` coord) — record origin at write time as part of
   this task. CAUTION: do NOT conflate this with today's unrelated
   `20260826120000_facet_source_item_origin.sql` migration
   (`source_item_id` on facet tables solves a different disambiguation) —
   the origin tag here is new, its own column/field with its own
   migration.

Tests: lane-order-independent — cards seeded before previous_website lands
are swept when it does; manual card with matching host survives; sweep
does not fire for unrelated hosts.

Cleanup (after fix lands): ollies' 7 stali.com.au cards (fix-2 sweep
should take them) + the stray OpenTable card if R1's reproject didn't.

- GATE R2: tests green; live dev re-run of a GB claim (or simulated lane
  ordering) shows zero own-site cards; ollies pool clean; fresh-eyes
  critic.

## R3 — Instagram media expiry-proofing (video + image)

Diagnosis: `docs/2026-08-26-instagram-video-mirror-handoff.md` + this
session's verification (2026-08-26 evening). The Apify profile actor
serves cached crawls (staleness observed up to 3–4 days); Instagram signs
`/o1/v/` video URLs with ~24h validity (dead on arrival — 16 stuck assets
on dev spanning 08-24→08-26, all `fetch_failed`) and image URLs with
~2–8 days (surviving on margin, not design).

**Everything below is EMPIRICALLY VERIFIED (2026-08-26):**
- Stored expired URL 403s; `oe=` hex decodes cleanly (`0x6A88938E` →
  2026-08-21T18:06Z on an asset fetched 08-24).
- Embed refresh: `instagram.com/p/{shortCode}/embed/` returns 200 with NO
  login **from the Laravel Cloud dev environment itself** (`cloud tinker`
  test: 200, 263KB, `video_url` present, no login wall) AND from
  residential; extracted URL is freshly signed (~36h) and serves
  `206 video/mp4` to our exact PartnaBot UA. Verified on two shortcodes.
- Actor fallback: `apify~instagram-scraper` with
  `directUrls:[post URL], resultsType:'posts', resultsLimit:1` returns the
  same freshly-signed URL; `206 video/mp4` verified. Runs on Apify
  proxies (no IP exposure), reuses existing token/run-sync/ApifyBudget
  plumbing. Cost ≈ a cent per refresh.

Build (hybrid, shared for video AND image):
1. **Pre-flight `oe=` check** on EVERY fbcdn/cdninstagram fetch (mirror
   attempts and projection-time mints): parse hex epoch; expired or
   near-expiry → skip the doomed fetch, invoke the refresh path. Applies
   to images and videos alike.
2. **Shared refresh path**, keyed by the stored shortcode
   (`content.source_items.coord` = `instagram:acct-{hash}:{shortCode}`):
   embed page first (free, prod-proven), single-post actor scrape as
   fallback (guaranteed, paid). Defensive extraction (unauthenticated
   surface: markup can change, rate limits possible); bounded attempts;
   update `media_assets.source_url` with the fresh URL, then mirror as
   normal. For images, handle **carousel child-matching**: a multi-photo
   post's refresh payload carries all children (actor `childPosts`) — map
   each stale asset to its child (position/fingerprint), don't assume the
   first image. Terminal state stays poster-only/absent — the frontend
   already renders it gracefully.
3. **Revival for free**: the 16 stuck assets keep `mirror_eligible: true`
   and revive on the next mirror pass — no data surgery.
4. **Observability**: log the decoded `oe=` at seed/mint time (closes the
   handoff's "unresolved wrinkle") and a `media_mirror.refreshed` event
   with which leg (embed/actor) produced the URL.

**Legacy deletion / unification (nothing replaced survives) — corrected
scope from the verification pass:**
- The seeder has THREE bespoke raw-`Http::` fetch sites, not two:
  `mirrorVideo`/`attemptMirrorVideo` (hero reel) AND **`mirrorOne()`
  (~L377-449 — profile pic, post photos, reel posters)**. All three bypass
  `SafeUrlFetcher` and `MediaMirror` entirely.
- **The unification is NOT a mechanical swap**: `MediaMirror` UPDATEs a
  pre-minted `content.media_assets` row (`storage_path` addressing), while
  the seeder writes plain URL strings into `IntegrationConnection.payload`
  JSON (`videoUrl`/`images`/`profilePicUrl`) — different addressing
  models. Design the bridge deliberately: extract ONE shared
  fetch-and-store helper (pre-flight `oe=` check + SafeUrlFetcher
  transport + refresh path + storage write) that BOTH consumers call —
  `MediaMirror` keeps updating media_assets rows; the seeder keeps
  writing payload URLs but through the shared helper instead of raw
  `Http::`. Do NOT force hero media into media_assets rows as part of
  this plan (that is a projection-model change; note it as a possible
  follow-up, decide then). Verify a fresh connect mirrors hero reel +
  profile pic + post photos through the helper.
- No `oe=` handling exists ANYWHERE in the codebase today (grep-confirmed)
  — the pre-flight is greenfield; put the parse in one place (the shared
  helper / SafeUrlFetcher seam), not per-caller.
- The UA-swap 403 retry in `SafeUrlFetcher::tryFetchToFile` fires only
  when the original UA was Mozilla-prefixed (WAF-dodge, not a generic 403
  handler) — keep it; the pre-flight sits IN FRONT of it. Remove only
  retry behaviour that specifically re-fetches a URL the pre-flight
  already knows is expired.
- `docs/2026-08-26-instagram-video-mirror-handoff.md` — superseded by
  this plan; delete once R3 ships (its diagnosis is preserved here).
- Sweep comments/docs that describe video mirroring as "fails on expired
  URLs" as if permanent.
- GATE R3: tests (pre-flight skips expired; refresh produces a mirrorable
  URL; carousel child-matching; owner-visible states unchanged); live dev
  proof — the 5 ollies video cards PLAY on the sitepage from R2 URLs;
  fresh connect mirrors hero reel through the unified path; fresh-eyes
  critic.

## R4 — Handoff bookkeeping

- Mark `docs/2026-08-26-backend-fixes-for-dev.md` items 1–5 as absorbed
  (1–3 → plan 1, 4–5 → here) with commit refs once shipped; keep until
  every item ships, then delete. Same for the Instagram handoff doc (R3).
- GATE R4 (final): all fixes live on development + deployed; checkpoint
  written; auto-continue into plan 2.

---
# RUN CHECKPOINTS

**GATE R1 PASSED — 2026-08-27 ~05:00 AEST.** Cherry-pick of 312bda304
applied clean onto current development (the branch's other commit dropped
as already-shipped); LinkProjectorSurfaceMarginTest + RoutingCorpusTest +
SuggestionsInboxTest + full suite green; deployed. LIVE: the incident URL
projects opentable.reserve at confidence 79 / margin 79 (was 59/0 →
note). Reproject audit on dev: 0 reclassified / 1 newly matched
(tidal.player — a side benefit) / 20 "lost" all probe-evidence
storefront domains the URL-only replay can never see (pre-existing
methodology noise, not a regression — the fix only ADDS score). Rescue:
the stranded ollies OpenTable link re-routed through the REAL
LinkRoutingService paste path → verdict 'place', reservation connection
01a03bf3 with identifier 291533; the stale custom-link card retired.
