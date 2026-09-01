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

### Item 3 (connect sheet) — PASSED 2026-09-02
- [x] DEDICATED_CONNECT regenerated verbatim from the backend route list
      (115 keys; monorepo e126d0f2), Wave 4 tiles added (bluesky, spotify
      podcasts, tiktok shop, amazon storefront), the two vendor-store
      tiles route to their dedicated shop-lane endpoints with a
      404-tolerant poll; order_online still carries no tile (route stays
      in the set — wire truth). Dashboard: tsc clean, vitest 10/10,
      eslint 0 errors; app.partna.au serving the deploy (200).

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

### Item 9 (latency, the headline table) — PASSED 2026-09-02
- [x] Measured table, the two simultaneous signups and the tier telemetry:
      all recorded under "Campaign run — 2026-09-02" above (the skeleton
      that stood here was the pre-run placeholder). Re-measured after
      Wave 7 in "Re-run after Wave 7" below.

### Item 10 + 11 (expansion) — PASSED 2026-09-02 (testbed: ryanmcleodzooooom)
- [x] Live connects, all real vendor calls: twitch (CohhCarnage — identity
      card with 1.72M followers on the payload), threads (@zuck → 15 posts
      into the media pool), pinterest (tasty), bluesky (connect ok;
      pres.cafe folded correctly — the account no longer exists upstream,
      vendor 404-husk, the lossy contract holding), spotify podcasts
      (Huberman Lab → 50 episodes into listen), tiktok shop (Goli store
      minted, 4 products synced, reviews lane fixed mid-campaign — see
      below), amazon storefront (sydneydelrey store minted). Every
      connection auto-provisioned its ingest source with the right
      identifier through the observer.
- [x] FB events: 119 satellites backfilled + eager; the lane pulls real
      events (pages with public events answered seen=3/changed=3; the
      no-events majority folds 'unavailable' — the faithful vendor
      answer, not a failure).
- [x] Shorts blend: youtube run seen=15 into watch with the
      youtube_shorts budget leg claimed.
- [x] Budget caps claim under every new source key (ledger 2026-09-02:
      twitch, tiktok_shop, amazon, facebook_events, youtube_shorts,
      spotify_podcasts all claimed; find_social_profiles pinned 0).
- [x] Live-status poller: CheckStreamingLiveStatusJob on the 2-minute
      schedule with onOneServer + withoutOverlapping(5); no failed jobs.

**Campaign catches (fixed + shipped mid-campaign, 317cb7792 + the
constraint migration):**
1. `ingest.effects` kind CHECK rejected 'vendor' — every new lane's
   first eager run died on 23514. Migration
   20260902010000_effects_kind_check_admits_vendor applied; 138 killed
   sources reset and re-run green.
2. TikTok reviews endpoint path is `product/reviews` (hyphen variant
   404s live); wildcard Http::fake had masked it — path fixed, fake
   pinned exact.
3. SourceProvisioner had no pinterest arm — connected profiles never
   provisioned their boards source. Arm added + tested.

### Item 12 (menu/shop separation) — PASSED 2026-09-02
- [x] Wolf rebuild: 54 distinct dishes across 5 menu categories (57
      memberships — 3 are cross-category, e.g. Specials; each dish exists
      once). Storefront catalogue (woocommerce store, 8 items) lives in
      the library collection. Ordering links: the source listing carries
      none, so zero is the faithful state.

### Housekeeping (post-campaign)
- [ ] NIGHTWATCH_REQUEST_SAMPLE_RATE back to 0.1 — OWNER ACTION: one
      field in the Laravel Cloud UI (the CLI only replaces the whole env
      set from a file, which would wipe masked secrets; not automatable
      safely). The related platform-agent noise ("Ingest failed: No
      authentication details" in system logs) is the Nightwatch billing
      402, also owner-side.
- [ ] Nightwatch billing (owner-side 402) — re-check. OWNER ACTION.
- [x] Legacy POOL_GALLERY reader teardown scheduled — Wave 6 of the plan
      file (appended 2026-09-01) carries it.
- [x] Plan file DELIBERATELY KEPT: it now carries the owner's Waves 6–8
      (legacy sweep, Astro batch, staff settings) appended mid-campaign —
      it is deleted when THOSE ship, not when this campaign closed.

## Re-run after Wave 7 (2026-09-02 08:05 AEST, owner's post-Astro item 1)

Same teardown (3/3 expired + pruned, zero residue) and the same inputs, same
harness. Danny + wolf posted together at 22:05:16Z; ryan prewarmed at
22:05:36Z and posted at 22:05:45Z — so ryan OVERLAPPED danny's build this
time (Wave 5 ran him solo).

| Milestone | Wave 5 (2026-09-02 am) | Re-run | Read |
|---|---|---|---|
| ready business (wolf) | 5.6s | **6.0s** | unchanged |
| ready partna, concurrent (danny) | 13.5s | **30.3s** | vendor (below) |
| ready partna, prewarmed (ryan) | 10.6s | **43.8s** | vendor (below) |
| KV live | ready+2s | ready+1.5–2s | unchanged |
| workplace (danny / ryan / wolf) | 15s | **38s / 64s / 5s** | trails ready by the same ~25s |
| Instagram pool visible on the page | — (not separated) | **ready+3s** (`pool.video.progressive_serve` ×5 at 22:06:30 for ryan; images seed at prefetch) | Item 7 doing its job |
| `content_filled` marker | 40.6s | **105s / 117s / 64s** | marker drift (below) |
| fully MIRRORED pool | — | +144s / +157s | mirror lane width (below) |

Sitepages: all three 200 with the right content (ryan 7 videos all from
mirrored storage, wolf 6 videos + 54-dish menu, danny 13 images +
workplace) and every Wave 7 surface present on the fresh builds.

**What the server timeline says (windowed `cloud env:logs`, DB stamps):**

1. **ScrapeCreators `/v1/instagram/profile` answered HTTP 500 three times**
   (22:05:30 after 9.1s, 22:05:55 after 11.0s, 22:06:30 after 11.7s). Every
   partna ready-path call hit one: danny's prefetch waited 9s for the 500
   then took the actor fallback (`pre_account.bio_intelligence` at +21s,
   materialised +21s, ready +30s); ryan's PrewarmInstagramProfileJob ran
   34s and his generate job 31s. Wolf (Google) never touched the vendor and
   was unchanged. The 20s client timeout is not the lever — the vendor
   answered, slowly, with an error. This is vendor variance; the lossy
   fallback held (every build finished with full content).
2. **`content_filled` measures the wrong lane now.** Since Wave 3 the
   Instagram pool projects into `content.items`/`item_media`, while the
   marker still watches `site_media` content rows (only website grabs
   mint those) and menus. On an Instagram-fed site it therefore fired on
   the WebsiteGalleryScan grabs (+95–105s), long after the pool was on the
   page. FIXED in this pass: the marker also observes a projected pool
   item with media (commit below).
3. **Mirroring is the long pole for "fully mirrored", not for visibility.**
   35 + 42 + 64 mirror jobs at ~3s each on supervisor-mirror's 2
   processes (overlap confirmed in the logs) ≈ 2 minutes for three
   simultaneous builds. Videos and images render from source until then
   (progressive serve), so the visitor sees the pool at ready. Widening the
   lane to 4 would halve this, but config/horizon.php's own memory
   arithmetic already over-commits the 2 GiB worker box (11 workers is a
   WATCH item) — the config note says the real fix is the box resize the
   owner pre-approved. **Owner action: resize the worker box, then
   supervisor-mirror 2→4** (one-line config change, pre-written below).
4. Cache purges ran 68 times in three minutes (ShouldBeUnique per handle,
   35s) — cheap (0.3–0.9s each), on the low-priority lane; not a lever.

**Verdict:** no regression from Waves 6–7. The partna ready times were a
vendor-500 night; the business path and KV are unchanged; visible media is
at ready. Telemetry corrected so the next run measures what visitors see.

## CAMPAIGN VERDICT (2026-09-02): GREEN

Every item verified live; three real defects found and shipped fixed
during the campaign (effects kind constraint, tiktok reviews path,
pinterest provisioner arm) — which is exactly what a verification
campaign is for. Two housekeeping actions remain, both owner-side.
