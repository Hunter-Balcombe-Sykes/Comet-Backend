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

## Campaign checklist (execute per plan item's Verification section)

### Item 1 (identity pipeline)
- [ ] Delete + rebuild via PUBLIC flow: an IG personal account (danny case),
      an IG business (ryan case), a GB business (wolf case).
- [ ] Handles: separator-less cleaned display names; failed-source build
      leaves zero residue; ManyChat lane still mints claim URLs.
- [ ] fleet:rebuild parity (1d): same ladder as public flow. (Pre-verified
      in smoke; re-confirm in campaign run.)

### Item 2 + 7 (media surface)
- [ ] ryanfitzsimonshair: positions 1–2 of the deck are videos, newest
      first; 5 videos then images. (Pre-verified in smoke; re-confirm.)
- [ ] bydannydixon1: 0 videos → images only, no gap, no errors.
- [ ] Fresh build renders videos BEFORE mirrors land (progressive serve
      log line `pool.video.progressive_serve` present); mirrored URL
      replaces CDN URL on later rebuild; expired source never serves.

### Item 3 (connect sheet)
- [ ] Every backend platform present + wired on the sheet; order_online
      stays off; new Wave 4 tiles render and route to dedicated connects.

### Item 4 + 4-EXT (workplace)
- [ ] Ryan: akro nominated + corroborated (other salons rejected).
- [ ] Two-workplace bio fixture: both nominated, corroboration picks.
- [ ] Places-first one-hop consulted before any paid chained scrape.

### Item 5 + 6 (one media pool, Astro consistency)
- [ ] Emma (emdinonhair): Wix grabs visible on /media as unpinned items,
      pin/unpin/order works; sitepage gallery may surface them via auto arm.
- [ ] Danny: 6 Squarespace grabs on /media (backfilled).
- [ ] Accent resolves for a site with only website-grab imagery; Instagram
      imagery still excluded.
- [ ] Wolf: listing photos on workplace drop ONLY; gallery grid has zero
      listing photos.
- [ ] Fresh build: home bg shows seed within seconds; swaps to deck[0]
      (newest video) when the pool fills; seed never renders beside pool.
- [ ] Existing sites: document rebuild picks up backfilled grabs (may need
      a rebuild nudge — record what it took).

### Item 8 (vendor lane)
- [ ] Timeline shows scrapecreators latency lines (2–4s) on build scrapes;
      Apify only on vendor miss; budget ledgers claim/release correctly.

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

### Item 12 (menu/shop separation)
- [ ] Rebuild the-famished-wolf-kensington: shop page empty/absent, menu
      carries dishes once, ordering links intact, catalogue in library.

### Housekeeping (post-campaign)
- [ ] NIGHTWATCH_REQUEST_SAMPLE_RATE back to 0.1.
- [ ] Nightwatch billing (owner-side 402) — re-check.
- [ ] Legacy POOL_GALLERY reader teardown scheduled (flagged Wave 3).
- [ ] Delete the plan file once all green.
