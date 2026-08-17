# Overnight run 2026-08-18 — connect → pool → resync: test, fix, retest

**Status: DRAFT skeleton.** Written from a 12-reader survey (results in the
session scratchpad `survey.json`); owner decisions pending (see §9). Once
answered, §9 becomes fixed rulings and the run starts at W0.

Owner brief (verbatim intent): autonomously test → repair → retest the
fundamental connect / pool / resync setup for **every connectable platform**,
using **real** accounts (real Google Business listings, real Fresha stores,
real influencer Instagrams, real artists/channels), for both `partna` and
`business` account types; fix everything found (including unrelated bugs met
on the way); wire every backend field/control the dashboard doesn't yet show
(over-include; owner trims later); finish pool categories UI (menu, services);
richer connect-success summary; per-platform URLs + source badges on item
sheets; best-quality images; cross-platform identity for listen/menu; auto-sync
-latest toggle for every sourcing platform; per-connection display names for
link-only platforms. All doctrines suspended for this run — this file is the
method. Dev only. No live users. Merge freely.

## 0. Method (applies to every workstream)

The loop for each item is **Probe → Record → Fix → Re-probe → Gate**:

1. **Probe** with a real account through the real path (dashboard in the
   Browser pane, or the API/tinker when the UI has no verb) and read the
   ground truth in Supabase dev (`glncumufgaqcmqhzwrxm`) — never trust a 200.
2. **Record** the finding in `docs/overnight-2026-08-18/LOG.md` as
   `F<n>` (what, evidence, root cause hypothesis) *before* touching code.
3. **Fix** on a branch `overnight/<workstream>` (Comet-Backend off
   `development`; monorepo off `main`). Tests first where a test can express
   the behaviour (Pest / phpunit.pg / dashboard typecheck+lint).
4. **Re-probe** the exact same real account/path. Same SQL, same screenshot.
5. **Gate** = ALL of: unit/feature test green · typecheck+lint green ·
   live re-probe shows the new behaviour with a timestamped SQL row / wire
   payload / screenshot pasted into LOG.md · no new errors in
   `cloud env:logs partna development` for the touched jobs · merged and
   pushed (dev deploy) · **re-probed once more against dev-api after deploy**.
   Nothing moves to the next item until its gate is closed. If a gate cannot
   close after 2 fix attempts → record as `BLOCKED-F<n>` with what was tried
   and move on; never leave half-applied.
6. **Guardrails** (from the legacy-boundary reader): dev ref only, never prod
   `edplucmvkcnokyygxqsb`; never re-add retired surfaces
   (`partna.custom_link|order_link|storefront|reserve_link|booking_link|manual_event`),
   dropped tables, kinds `article`/`channel`, legacy wire shapes, Stripe/Shopify/
   3rd account type; twitch/skool/strava/gumroad/substack are valid *link-only*
   surfaces — do not re-source them; every pool mutation bumps all 3 cache
   lanes; paid ingest sources are flip-on → run → flip-off (or scheduled under
   caps once §9 rules it) — never left armed by accident.
7. **Topology** (default, pending §9): local Laravel (`php artisan serve` +
   `horizon`/`queue:work` + `schedule:work`) against dev Supabase for fast
   iteration, dashboard on :3000 pointed at it; at each gate push → Laravel
   Cloud dev → re-probe on dev-api. Browser: built-in pane, owner-authenticated
   session (I never enter credentials).
8. **Opportunistic bugs**: anything unrelated met on the way → `LOG.md` under
   `X<n>`, fixed under the same gate rules, in the workstream branch where found.

## 1. Workstreams (order = dependency order; each has its own gate list)

### W0 — Setup, fixtures, guardrail checks (no product changes)
- Run legacy-residue detection SQL (reader query 1: live retired-surface rows
  must be 0; report soft-deleted counts). Clean the two run accounts only.
- Confirm env on Laravel Cloud dev + local `.env`: `APIFY_TOKEN`,
  `PARTNA_INGEST_BILLED_EFFECTS_ENABLED`, per-actor caps, effect freshness,
  `GOOGLE_MAPS_SERVER_API_KEY`, `PARTNA_CONNECT_DEFERRED`, `PARTNA_LOGO_REMOVAL_*`,
  `FRESHA_BOOKING_INIT_HASH`/`FRESHA_CLIENT_VERSION` (probe live), Redis, R2.
- Add `data-testid` to the handful of dashboard primitives automation needs.
- Add missing artisan helpers the run needs: `ingest:arm {source} --for=1`
  (flip auto_sync + next_attempt_at now, auto-restore), `ingest:run {source}`
  (claimOne + RunSourceJob::dispatchSync), `apify:budget --reset {actor}`.
- Baseline test runs: `composer test`, `composer test:pg`, dashboard
  `npm run typecheck && npm run lint`. Record baseline failures as X-items.
- Fixture list (real accounts, chosen at run start, recorded in LOG.md):
  per platform ≥2 real accounts (URL form + handle/channel-name form where
  the platform has one), one for the partna account, one for business.
  Google Business: (a) AU cafe/restaurant with website + UberEats/DoorDash/
  Menulog + booking, (b) AU salon/barber with Fresha + Instagram, (c) AU sole
  trader with a Linktree as website. Same artist on Spotify + Apple Music +
  SoundCloud + YouTube Music. Instagram: public creator with reels + carousel.

### W1 — Connect matrix: EVERY connectable platform × {url, handle} × {partna, business}
Source of truth for the list: compiled catalog `_manifest.php` + registry
(reader: oauth / url / handle / deferred / bespoke groups; brand connects;
LinkOnly). For each cell record: HTTP result · what landed in
`site.platform_connections` (payload, status never stuck `pending`) ·
`ingest.sources` row (auto_sync, next_attempt_at, eager run?) · what reached
`content.items` and when · what got auto-selected in the pool (must NOT be
"everything" unless the pool's rule says so after §9) · dashboard row + sheet
state · connect-sheet success summary.
Known fixes queued from survey: brand connects stuck `pending` forever
(GenericPlatformController.php:91-95); dead skool branch; gumroad/mixcloud/tidal
no route; `?platform=KEY` after connect never opens the sheet; deferred-connect
env set stale; Instagram double Apify run per connect; connect summary shows
generic icon (build: name/avatar/handle/counts per platform where the payload
has it); connect-input variants (handle/channel-name/artist-name where the
vendor supports it — judged per platform, recorded in LOG).

### W2 — Pool rules + auto-sync-latest per platform
- Every sourcing connector gets an `auto_sync_latest` display toggle in the
  platform sheet (spotify, soundcloud missing today; PlatformRegistryServiceProvider.php:534-583).
- Media/services/menus selection model per §9 (opt-in + N latest vs all).
- Make the toggle *do* something for media (today inert; SectionCandidates only
  reads it in latest_per_auto_source). GB `photos` toggle likewise.
- Reshape the 12 dev `pool:shop` sections to pins+latest (command).
- Dashboard renders `origin` (auto vs pinned) + `latestItemId`.
- DisplaySettings show/update semantics consistent for multi-account platforms.
- Vimeo/eventbrite/humanitix sources unprovisioned on dev → provision + prove.
Gate: for each pool, a real connect on the run account yields the ruled
selection (SQL on `site.section_items`/pool wire) and toggling the rule flips it.

### W3 — Google Business chain (connect → seed → harvest → auto-connect → previous-website scan → logo → link-in-bio)
- Eager ingest run on GB connect (reviews/photos land at all).
- Website harvest: JS-less anchor scan fallback → Apify already; add
  LinkInBio detection for GB website / previous_website → `LinkInBioScanJob`;
  exempt link-in-bio hosts from `matchesPreviousWebsite`.
- Route unclassified previous-website links to custom_links via `LinkRouter`.
- Ordering auto-connect → menu lane decision (legacy MenuFetchJob vs ingest
  connectors) — pick one, make it fire and fill the menus pool.
- Logo: raster grab proven; vector only if processor deployed + env set (§9).
- Partna accounts: gate at GoogleBusinessAutoSync.php:118 per §9.
- Enrich job 900s re-bill refusal → make retest-friendly (flag/env).
- Scan job `$tries=1`/60s → sane retry.
Gate: real business (a) end-to-end: SQL shows workplace, previous_website,
custom_links, auto-connected ordering/booking connections, menus pool items,
logo asset; business (c) shows Linktree unrolled.

### W4 — Media pool (Instagram posts + reels, Google photos, auto-fill N)
- Instagram: eager run proven live; reels projected with `kind`/poster on wire
  + video badge in dashboard (mp4 mirroring per §9); private/empty → owner
  visible note.
- Google photos: stable identity across refetch (key on photo resource name)
  so selection survives; selectable per §9.
- Auto-fill N on connect when pool has nothing selected (config default,
  per-connection later); Instagram `auto_sync_latest` ON at connect.
- Mirror non-Instagram images? (menu/album art hotlinked today) — see W5.
Gate: real creator IG connect → N media items selected, reels visible +
badged; real GB connect on empty media pool → N photos selected; refetch
keeps selection.

### W5 — Identity/dedup + per-platform links + image quality (listen, menus)
- Apple Music `songs` stream → kind `track` (iTunes lookup entity=song) with
  creator/duration/f_link, so Spotify/Apple/SoundCloud/YT-Music can union.
- TitleRelease normalisation for combined artist credits (Spotify).
- Per-platform links on the wire: `item_links` for listen already; add
  `menus`/`services` to `ItemLinkRules::ROSTER` + HOSTS; menu items get
  f_link/offer.url per source; apps/pages renders `links` (icon row).
- Cover selection: record dims (Apple URL rewrite to 1200x1200; Spotify
  images from actor if present; probe HEAD/dims for hotlinked), choose max
  area then source priority; mirror album/menu art to R2 when chosen.
- Identity candidates: minimal merge/split verb in the dashboard item sheet
  (or at least visible "possible duplicate" chip) — scope per time.
Gate: same artist on ≥3 listen platforms → ONE track item with N links and
best cover; UE+DD same dish → ONE menu item with 2 links.

### W6 — Services (Fresha) + categories UI (menu, services)
- Fresha hash/version live-check; team-member picker for partna in connect
  flow AND platform sheet; business per §9; selection change → eager re-run.
- Fresha per-service URL if derivable; manual+Fresha same-name union check.
- Categories: full CRUD + assignment + ordering UI for menu & services in
  the dashboard (backend has category endpoints from phase 7 — wire all of
  them); wire shape verified on apps/pages.
Gate: real Fresha venue with >1 team member connected on a partna account →
services pool shows chosen members' services grouped by category; edit
category in dashboard → wire updates.

### W7 — Platform naming (first column) + connect summary
- New `display_name` (column or payload key per §9) computed by ONE helper
  from (surface_key, payload); per-platform URL→handle extractors for the 11
  bare-host socials + subdomain handles (substack/gumroad); og:title fetch per
  §9; RoutingConnectionResource emits it; public allowlist + backfill command.
- Dashboard column 1 fallback chain: display_name → handle → labelFromUrl
  (no >24-char collapse).
Gate: run account with medium/kick/patreon/github/discord/substack + a store
shows names, not hostnames.

### W8 — Item sheets + backend→dashboard parity sweep
- Per-platform URL rows on item sheets (existing links + "add URL for
  platform" from roster served by an endpoint, not a hand-copied list).
- Source list with sync badges per source; per-field sync state (emit
  `overrides[]` on the wire).
- Parity sweep: enumerate every field/control the backend exposes for
  connections, platforms, pool items, sections (resources + wire ITEM_KEYS +
  display settings) vs what the dashboard renders/edits → wire the missing
  ones (read-only display where no verb exists).
Gate: for each pool, sheet on a multi-source item shows every source, every
link, and every backend field; typecheck+lint green; screenshot per pool.

### W9 — Resync (scheduler + Resync button + paid cadence)
- Dashboard Resync → also forces an ingest run for that connection's sources
  (bump next_attempt_at / claimOne+dispatch), invalidates table caches.
- Paid connectors: eagerOnConnect + scheduled cadence under caps per §9
  (music weekly, GB 2d, menus weekly). Legacy `pending` rows swept.
- Twitch/substack ingest.sources rows with no connector → cleaned/guarded.
- Double-fetch (legacy refresh + ingest for youtube etc.) → retire the legacy
  refresh where the wire no longer reads its payload.
Gate: bounded proof — bump `next_attempt_at` on a real youtube source, watch
`ingest.runs` row + new video in watch pool; Resync click yields a run within
the poll window; scheduled cadence visible in `ingest.sources.next_attempt_at`.

### W10 — Apify actors: probes + backup/failover
- Live-probe candidate actors for spotify (ISRC/full catalogue), soundcloud,
  instagram, google-business; record shapes in convergence-log F-entries.
- Failover: ordered actor list per platform, adapter per actor, driver
  iterates on NoAnswer/4xx — implement only where a probed backup returns
  usable data. Env-swappable actor ids for menus/GB. `.env.example` gaps.
Gate: driver test with primary NoAnswer → backup Answered; live run recorded.

### W11 — Opportunistic bugs (X-items) — fixed under W-gates as met.

## 2. Evidence standard
Every closed gate has, in LOG.md: timestamp · account handle · SQL (query +
rows) · wire payload excerpt or screenshot · commit sha · deploy confirmation.
"Done" without those four is not done.

## 3. Ordering / checkpoints
W0 → W1 (all platforms, free lanes first, paid after env confirmed) → W2 →
W3 → W4 → W5 → W6 → W7 → W8 → W9 → W10. Checkpoint file after every
workstream (`docs/overnight-2026-08-18/CHECKPOINT-W<n>.md`) so a session
restart resumes cleanly. Fresh session per 2–3 workstreams.

## 9. Owner rulings (answered 2026-08-18 ~02:40 AEST) — these are fixed

| # | Topic | Ruling |
|---|---|---|
| R1 | Test account | `tobiasinthe6@outlook.com` (dev, throwaway; already logged in in the Browser pane on :3000). ONE account; swap account type in Settings when a case needs `partna` vs `business`. Named handles (ollies, broken-oven, …) read-only. |
| R2 | Spend | Full dev headroom: set `PARTNA_INGEST_BILLED_EFFECTS_ENABLED=true`, raise per-actor + global caps, effect freshness ~600s on Laravel Cloud **dev only**; spend remaining STARTER balance (US$3.9/29 used at 02:35). On 402/limit → log, continue free lanes; owner tops up in the morning. |
| R3 | Cloud env | I set vars myself via `cloud env:vars development --action=set --key K --value V` (single-key set; never `replace`). Every change logged in LOG.md; temporary ones restored at end. |
| R4 | Topology | Hybrid: local Laravel (`artisan serve` :8000 + `queue:work`/horizon + `schedule:work`) against dev Supabase, local Redis; dashboard :3000 pointed at local API during loops (`NEXT_PUBLIC_API_BASE_URL`), back to `dev-api` for the post-deploy re-probe. Local `.env` synced from dev env (`cloud env:get development --json --show-sensitive`) after backing up the current one. |
| R5 | Pool model | Media → opt-in: pins + auto-select N latest per source (N=5 default, config; per-connection later); Instagram/GB toggles control it. Services & menus stay all-published + get full categories UI. Events/reviews/custom_links unchanged. |
| R6 | GB photos | Selectable: key on stable Google photo resource name so identity survives refetch (overrides D5). |
| R7 | Reels | **Full playback tonight**: mirror mp4 to R2, expose stream URL + kind/poster on the wire, sitepage plays inline; dashboard video badge. |
| R8 | Paid cadence | Eager run on connect + dashboard Resync forces an ingest run + scheduled under caps: music weekly, Google Business 2d, menus weekly, Instagram connect+Resync only. |
| R9 | Naming | New `display_name` on connections via ONE helper: URL→handle extractor per platform now, then bounded cached og:title/profile fetch to upgrade to real store/artist/person name where available. |
| R10 | Apple songs | Add Apple Music `songs` stream → kind `track` (+ artist-credit normalisation) so Spotify/Apple/SoundCloud/YT-Music union into one listen item with per-platform links + best cover. |
| R11 | Fresha | Partna: team picker in connect flow AND platform sheet; Business: storewide default with optional narrowing from the sheet. |
| R12 | Legacy | Purge residue on the test account before testing; report residue elsewhere in LOG.md, no deletes. |
| R13 | Logo vector | Check `~/Developer/partna-logo-processor` + dev env; deploy if ready and test raster→vector; else raster only + log. |
| R14 | GB on partna | Relax gate: partna accounts get previous_website + socials + booking seeding; ordering/reservations stay capability-gated. |
| R15 | Agents | Pure search/scan agents run on **sonnet/haiku**; anything requiring judgement stays on the main model. |
| R16 | Go | Start immediately after readiness check; run through the night with per-workstream checkpoints. |

Critic-derived defaults (not asked; sensible, reversible — flag in morning if wrong):
- **Disconnect = hide, don't delete**: connection-alive predicate on kind_is candidates; content.* history kept.
- **Link-in-bio pasted into the router / GB website / previous_website** → unrolled via `LinkInBioScanJob` (linktr.ee, beacons.ai, msha.ke, stan.store).
- Router `review` outcomes are logged per URL, no inbox UI tonight.
- Rows seeded by raw SQL get `ingest:backfill-sources` — but prefer the API path.
- Live menu lane image choice (MenuMerger "Uber Eats wins") also gets the best-quality rule (W5).
- All five GB display toggles (reviews/hours/photos/location/menu) must reach the pool wire, not only `reviews` (W2).
- Routing lane (`/routing/preview|links`, suggestions) is in W1's matrix as the paste path.

## 10. Readiness (checked 02:35 AEST)
- Browser pane: `localhost:3000` logged in as tobiasinthe6@outlook.com ✅ (dashboard → dev-api).
- Local: PHP 8.5, composer, redis running, docker `partna-pg-test` up (:55432) for `test:pg`, `npx supabase` 2.114 (not logged in → migrations applied via Supabase MCP `apply_migration` + file committed to `supabase/migrations`), `npx wrangler` available, `cloud` CLI authed.
- Local `.env` missing Places/service-role/R2/logo/Fresha/AI keys → sync from dev env (R4).
- Apify token valid, US$3.9/29 used this cycle.
- Monorepo `main` is 2 commits ahead of origin → push first.
- Laptop sleep: `caffeinate -dims` runs for the session.
