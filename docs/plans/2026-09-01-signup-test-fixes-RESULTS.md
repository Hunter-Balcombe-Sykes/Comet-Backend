# Signup-test fixes — verification campaign results (Wave 5)

> STATUS: CAMPAIGN IN PREPARATION. This skeleton was staged 2026-09-01 while
> Wave 4 wiring completed; sections fill in as each verification executes.
> The plan file (2026-09-01-signup-test-fixes.md) is deleted only when every
> section below is green (repo convention).

## Shipped state at campaign start

| Wave | Commits (Comet-Backend `development`) | Live-verified |
|---|---|---|
| 0 — instant wins | b19fe0b36, bac1a50b8 | tinker: ig_cap=1800, mirror_procs=2, long_procs=3 |
| 1 — vendor foundation | 57c5d264e, 4c22f651a | sc_key set, sc_cap=5400; canary rebuilds green |
| 2 — identity & workplace | 236d6317c, ff9aaa8a9, c3962a0a1, e6b4a402d, d72f14665 | smoke 2026-09-01 (below) |
| 3 — media architecture | b314cbeb9 (+ monorepo a0d50399, pages worker bc253d64) | migration applied; backfill minted 151 |
| 4 — expansion | 67c9da00d (adapters) + 056582f52 (wiring, 92 files) | tinker 2026-09-02: TwitchConnect live, spotify_podcasts deferred, bluesky classifies own URL, all 7 connectors registered, tiktok_shop.store surface, find_social_profiles cap=0 (owner off-switch), ig_depth=false; FB-events backfill ran live: 119 satellites created+eager (5 no_parent_source) |

Dashboard (monorepo `main`): ec998f3c, 220ce112, a0d50399, e126d0f2 (tiles + vendor-store lane + registry 175 platforms).

## Mid-flight smoke test (2026-09-01, Waves 1+2 in production) — PASSED

- Nonexistent IG handle (`zx9qv7…`): build failed `source_not_found` at ~32s,
  `user_id` NULL, **zero residue** — no handle squatted (the bydannydixon
  class is dead).
- `builds:prune-expired`: 3/3 swept — both the new user-less branch and the
  legacy user-ful teardown, live.
- ryanfitzsimonshair rebuild: prewarm → **ready in 10s** (baseline 15–51s);
  handle `ryanfitzsimons` off scraped display name "Ryan Fitzsimons"
  (1b/1c ladder, on the freed handle); reel mp4 merged async post-ready
  (9d); tier markers stamped once with telemetry (9h): content_filled +
  enriched observed at **54s** (workplace baseline was 10–12 min).
- Media pool: 22/28 items INCLUDING ALL 5 VIDEOS projected **within 60s**;
  page serves videos from mirrored storage, videos leading the deck.

## Campaign run — 2026-09-02 (rebuilds via PUBLIC flow)

Teardown: 3/3 standing builds expired + pruned, zero residue (users gone,
handles freed). Rebuild inputs: danny=IG by.dannydixon (partna),
wolf=GB ChIJ-f-5fXtd1moRUyH7gmrvImY (business), ryan=IG ryanfitzsimonshair
(partna). Danny+wolf posted SIMULTANEOUSLY; ryan solo with prewarm.

### Headline latency table (Item 9) — MEASURED

| Milestone | Baseline | Target | Measured 2026-09-02 |
|---|---|---|---|
| KV live | ~9s | ~9s | ready+2s (ryan 12.5s total; probe-after-ready) |
| ready (partna / business) | 15–51s / ~5s | 8–25s / ~5s | **10.6s solo, 13.5s concurrent / 5.6s** |
| media pool fully visible | 6–7 min | 60–90s | **40.6s (ryan, all 5 videos, mirrored)** |
| workplace (partna) | 10–12 min | 45–90s | **15.0s (akro corroborated + written)** |
| EVERYTHING (partna / business) | 12–13 min / 4–9 min | 1.5–3 min | **40.6s / 41.2s** |

- Two simultaneous signups: danny+wolf ran concurrently — wolf finished
  (5.6s) while danny was mid-build; no serialization. ✓
- Tier telemetry captured for every build (pre_account.tier lines; danny
  content_filled seconds_since_created=61 under concurrency). ✓
- Handles: separator-less cleaned display names on all three mints —
  `dannydixon`, `thefamishedwolf`, `ryanfitzsimons` (re-minted on the
  freed handle from the scraped display name). ✓
- Harness note: probing the subdomain BEFORE ready seeds a cacheable
  edge-404 that the doc-write purge then clears — real visitors click
  after ready and hit a live site (~2s later). Observed-benign.

## Campaign checklist (execute per plan item's Verification section)

### Item 1 (identity pipeline) — PASSED 2026-09-02
- [x] Delete + rebuild via PUBLIC flow: all three cases (see run above).
- [x] Handles: separator-less cleaned display names (3/3); zero residue on
      teardown (3/3 pruned, users+handles freed). Failed-source zero-residue
      + ManyChat claim URLs: pre-verified in the 2026-09-01 smoke.
- [x] fleet:rebuild parity (1d): pre-verified in smoke.

### Item 2 + 7 (media surface) — PASSED 2026-09-02
- [x] ryanfitzsimons: rendered page's media order = video-first (deck
      videos at positions 0,2-6), all 5 IG reels present as <video> with
      .mp4 sources served from MIRRORED storage (fls-*.laravel.cloud).
- [x] dannydixon: 0 videos → 11 images, page renders, no errors.
- [x] Progressive serve: no `pool.video.progressive_serve` line observed —
      mirrors landed BEFORE the first page render (pool filled at 40.6s
      with mirrors already swapped), so the progressive path had nothing
      to do; the mechanism itself is pinned by its unit tests. Expired-
      source never-serves: unit-pinned (isExpired gate).

### Item 3 (connect sheet)
- [ ] Every backend platform present + wired on the sheet; order_online
      stays off; new Wave 4 tiles render and route to dedicated connects.

### Item 4 + 4-EXT (workplace) — PASSED 2026-09-02
- [x] Ryan: workplace = "AKRO STUDIO | ELSTERNWICK BARBERSHOP", written by
      the 15s enriched marker (corroboration picked it).
- [x] Two-workplace fixture: unit-suite covered (campaign re-run not
      needed — no live two-workplace account exists).
- [x] Places-first: the wolf GB build spent ZERO vendor credits (ledger:
      only instagram=5 + linkinbio=2 all day) — no paid chained scrape ran.

### Item 5 + 6 (one media pool, Astro consistency) — PASSED 2026-09-02
- [x] Website-grab lane estate-wide: 463 'website:'-coord items (danny 10,
      ryan 10, fleet 6 each from the backfill), all unpinned-library.
- [x] Emma: 1 grab — source-limited (her previous_website
      starbarber.com.au yields one server-readable image), mechanism
      identical to the 463 others. Pin/unpin/order: dashboard tests cover.
- [x] Danny: 10 Squarespace grabs on the fresh build (beats the 6-grab
      backfill expectation; the fresh lane grabbed the full set with
      logged dimensions).
- [x] Accent: chain + quality gate verified live — danny's all-neutral
      palette (#2d2d2d/#cfd5d7/#c7c7c9/#000) correctly REFUSES (a grey
      accent is worse than the kit default); bennelong's #83734d
      qualifies; Instagram imagery excluded by construction (resolver
      reads website-grab/logo media only).
- [x] Wolf: GB photos live as content items (79 item_media rows), gallery
      site_media carries none of them (1 design-pool row only).
- [x] Fresh-build seed/swap + doc rebuild: pages rendered correctly at
      first probe post-ready; deck[0]=newest video on ryan (above).

### Item 8 (vendor lane) — PASSED 2026-09-02
- [x] Budget ledger during the 3 builds: global=7 = instagram 5 +
      linkinbio 2 — every claim under its source key, GB spent zero.
      (Latency lines scrolled past the 100-entry log window; the ledger +
      the 10.6s ready ARE the vendor-speed evidence.) Apify: no apify
      claims during the builds — vendor-primary held.

### Item 9 (latency, the headline table)
Measure a fresh partna + a fresh business build against:

| Milestone | Baseline | Target | Measured |
|---|---|---|---|
| KV live | ~9s | ~9s | |
| ready (partna / business) | 15–51s / ~5s | 8–25s / ~5s | (smoke: 10s partna w/ prewarm) |
| media pool fully visible | 6–7 min | 60–90s | (smoke: <60s) |
| workplace (partna) | 10–12 min | 45–90s | (smoke: ≤54s) |
| EVERYTHING (partna / business) | 12–13 min / 4–9 min | 1.5–3 min / 2.5–3 min | |

- [ ] Two simultaneous signups complete without serializing.
- [ ] Tier telemetry (`pre_account.tier`) captured for every campaign build.

### Item 10 + 11 (expansion)
- [ ] One live connect per new platform on a test account (twitch, threads,
      pinterest, tiktok shop, amazon, bluesky, spotify podcast); FB events
      from an existing FB connection; shorts blended into watch; unified
      live-status poller ticking for all four platforms without
      MaxAttemptsExceeded.
- [ ] Budget caps claim under each new source key.

### Item 12 (menu/shop separation) — PASSED 2026-09-02
- [x] Wolf rebuild: 54 distinct dishes across 5 menu categories (57
      memberships — 3 are cross-category, e.g. Specials; each dish exists
      once). Storefront catalogue (woocommerce store, 8 items) lives in
      the library collection. Ordering links: the source listing carries
      none, so zero is the faithful state.

### Housekeeping (post-campaign)
- [ ] NIGHTWATCH_REQUEST_SAMPLE_RATE back to 0.1.
- [ ] Nightwatch billing (owner-side 402) — re-check.
- [ ] Legacy POOL_GALLERY reader teardown scheduled (flagged Wave 3).
- [ ] Delete the plan file once all green.
