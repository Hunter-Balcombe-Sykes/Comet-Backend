# Signup test fixes — plan (2026-09-01)

Source: three live signup tests run 2026-09-01 03:26–03:58 UTC
(`ryanfitzsimonshair` IG, `bydannydixon`→`bydannydixon1` IG,
`the-famished-wolf-kensington` GB) with full cross-platform timelines
captured (DB trail + Laravel Cloud logs; Nightwatch was down).
Items are added here only after owner approval. Delete this file when shipped.

## Item 1 (APPROVED): name-identity pipeline — scrape → clean name → handle → site

One pipeline, one ordering rule: **identity is derived once, in order:
scrape → clean name → handle → site.** Handles come from the cleaned
display name (both account types), separator-less; display names get a
gated trim of non-name words.

### 1a. Resequence the build — nothing allocated until the source is verified

- `PreAccountBuildService`: run the source scrape FIRST. Only a successful
  scrape creates the user row; handle, site, and KV route derive from what
  the scrape proved.
- Kills structurally: failed builds squatting handles (the `bydannydixon`
  → `bydannydixon1` defect — the typo'd build held the handle so the
  retry got a suffix).
- Accepted cost: subdomain goes live ~40s later, inside the progress
  screen. Polling contract (build id) unchanged.
- This is the load-bearing step — every signup goes through this service.
  Own commit, full test pass.

### 1b. One shared name-cleaning stage — the only writer of display names

- Persons (Instagram/TikTok): unchanged — Unicode fold → AI extraction →
  `PersonNameParser` floor → `NameShapeGate`.
- Businesses (Google Business): NEW. Deterministic rules first, using
  evidence we hold:
  - strip a trailing token run matching the listing's own address
    locality ("The Famished Wolf Kensington" + Kensington address →
    "The Famished Wolf");
  - strip pipe/dash-delimited suffixes when the suffix is locality
    and/or generic sector words ("AKRO STUDIO | ELSTERNWICK BARBERSHOP"
    → "Akro Studio").
  - AI pass behind a business-flavoured gate for shapes the rules miss.
    Gate rule: output must be a subsequence of the input — trimming only,
    never rewriting.
- Both writers route through it: the creation-time write AND
  `GoogleBusinessEnrichJob`'s canonical-name write (currently gated only
  by `google_business_sets_display_name`, takes Google's name verbatim) —
  so enrich can never un-trim what signup trimmed.
- Conservative bias: when in doubt, don't trim. Log every trim decision
  (`name_trim: {from, to, rule}`) alongside the existing
  `bio_intelligence` logging.

### 1c. Handle = cleaned display name, separators removed, two-step fallback

- `HandleAllocator` seed = cleaned display name for BOTH account types
  (IG builds stop seeding from the IG username).
- Slug drops spaces/punctuation entirely: `thefamishedwolf`,
  `ryanfitzsimons`, `dannydixon` — no hyphens.
- Collision ladder:
  1. untrimmed name's slug (`thefamishedwolfkensington`) — a brand's
     second location gets its real distinguisher, not a number;
  2. bare integer suffix (existing behaviour) as the floor.
- Untouched: 63-char DNS cap, handle-AND-subdomain-AND-reserved
  availability predicate, existing hyphenated handles (no migration).

### 1d. Fleet tooling parity

- `fleet:rebuild` seeds through the identical clean→slug path in the same
  commit, preserving its "rebuild re-allocates the same handle" invariant.

### Expected outcomes on the three test cases

| Case | Today | After |
|---|---|---|
| IG `ryanfitzsimonshair` | `ryanfitzsimonshair` | `ryanfitzsimons` / "Ryan Fitzsimons" |
| IG typo then `by.dannydixon` | failed build squats handle; retry → `bydannydixon1` | typo allocates nothing; retry → `dannydixon` / "Danny Dixon" |
| GB Famished Wolf | `the-famished-wolf-kensington` / "…Kensington" | `thefamishedwolf` / "The Famished Wolf" |

### Verification

- Full test suite on `PreAccountBuildService` resequencing.
- Live rebuild of the three test accounts via the public flow; confirm
  the table above, confirm failed-source builds leave no user/handle/KV
  residue, confirm enrich job does not revert trimmed names.
- `fleet:rebuild --dry-run` on an existing unclaimed handle re-derives
  the same handle.

## Item 2 (APPROVED): media surface — videos lead, newest-first, 5+5 selection

Presentation-layer change only. Acquisition already exists (every post in
the ≤12 scrape window becomes a mirrored content item); the gap is what
the media surface selects and in what order. The synchronous 1-photo +
1-reel hero seed is deliberately untouched — its job is "not blank at
second 15."

### Rule

- The media pool selects **up to 5 videos (newest first), then up to 5
  images (newest first)** — every available video ranks ahead of every
  image.
- Owner's stated goal, restated as the invariant to test against: **the
  first content item is a video whenever the account has any video; with
  2+ videos, the first two items are videos.** The videos-before-images
  rule satisfies this by construction — no special-case "first two" logic.
- Accounts with zero videos (e.g. bydannydixon1's 8 image posts) degrade
  to images-only with no gap — "up to", never padded.

### Placement

- Implement in the content-pool selection/ordering that feeds the site
  document (BuildSiteDocumentJob / pool resolution lane) — NOT in
  InstagramConnectionSeeder and NOT via new fixed-filename seed files.
  Rebuilding a 10-file seed would duplicate the content lane's identity/
  dedupe/refresh/expiry machinery with a worse copy, and mirroring up to
  5 reels synchronously would add minutes to a 15–48s build.
- Zero build-time cost; applies to existing sites on their next document
  rebuild, not just new builds; stays current as new posts enter the
  scrape window.
- Videos already render poster + tap-to-play, so five leading videos do
  not change page weight materially.

### Known bound (accepted)

"Newest 5 videos" means newest 5 **within the ≤12-post profile window**
the actor returns. An account that mostly posts images may surface only
1–2 videos here.

### Deferred option (NOT approved — revisit only if data shows starvation)

Conditional deeper scrape: a posts-actor call with a limit, fired only
when the window yields <5 videos. Real per-build Apify cost — hold until
real accounts demonstrate the shortfall.

### Verification

- A test account with ≥2 videos in its window renders videos in
  positions 1 and 2 of the media surface, newest first.
- bydannydixon1 (0 videos) renders images-only, newest first, no errors.
- ryanfitzsimonshair (5 videos / 12 posts) renders 5 videos then images.
- Existing live site gains the ordering after its next rebuild without a
  re-scrape.

## Item 3 (APPROVED): connect sheet — missing tiles + stale DEDICATED_CONNECT

Audit 2026-09-01: live PlatformRegistry (113 connectable) vs dashboard
`lib/data/connect.ts` catalog and the `DEDICATED_CONNECT` allowlist in
`lib/queries/platforms.ts` (last regenerated 2026-08-18) vs today's
`route:list --path=platforms` (115 connect routes). No 404 direction —
every allowlisted key still has a route. Drift is all one-way: backend
grew, dashboard didn't follow.

### 3a. Add the missing online-ordering tiles

- Add **DoorDash** and **Uber Eats** tiles to the connect sheet
  (online-ordering category — their competitors Deliveroo/Menulog/etc.
  are already there; backend routes + registry entries exist, and real
  connections already exist in prod via GB enrichment).
- **`order_online`: decide, don't assume** — it looks like the generic
  catch-all for unbranded ordering hosts (auto-detected, e.g.
  order.tryhubster.com). Default: keep it OFF the sheet, record the
  decision in connect.ts next to the catalog. Owner call at implementation.

### 3b. Regenerate DEDICATED_CONNECT (20 routes stale since 2026-08-18)

- Regenerate the set from `route:list --path=platforms` per the file's own
  documented procedure. Missing today: bookwell, cal_com, classpass,
  cliniko, deezer, eat_app, fareharbor, google_appointments, halaxy,
  hotdoc, jane_app, just_eat, mr_yum, order_online, pinterest, rezdy,
  simplybook_me, square-ordering, styleseat, uber_eats.
- Consequence being fixed: ~a dozen of these HAVE tiles but currently
  connect via the generic link router instead of their dedicated
  endpoints. **Deezer is the concrete defect**: its tile promises top-track
  sync (multi-account) which the generic path cannot deliver.
- Also resolves doordash's half-state (allowlisted, no tile) together
  with 3a.

### 3c. Drift-proofing (small, do it while in there)

- The allowlist is hand-copied by design ("regenerate when the catalog
  grows") — that step was missed for 2 weeks. Add a guard so it can't rot
  silently: a dashboard test/build check that fetches or snapshots the
  backend connect-route list and fails on divergence (or derive the set
  from `/platforms/meta` at runtime if the wire already carries it).
  Smallest credible mechanism wins; no new infrastructure.

### Verification

- Diff script re-run: zero keys in either direction between route:list,
  DEDICATED_CONNECT, and connect.ts (minus the recorded order_online
  decision).
- Deezer connect from the sheet hits `POST /platforms/deezer/connect`
  (not the generic router) and creates a syncing multi-account connection.
- DoorDash + Uber Eats tiles connect end-to-end on a test account.

## Item 4 (APPROVED): bio workplace — multiple venue-shaped mentions must not collapse to "no workplace"

Case: ryanfitzsimonshair. Bio `@akro.studio // @akrorecclub // @orka.bali`
→ ALL mentions classified `other` → BioMentionChainsJob no-op → workplace
missed. Root cause: BioIntelligence's rule types a mention `workplace`
only on explicit works-at wording OR when it is the profile's SINGLE
venue-shaped mention; two venue-ish handles (akro.studio + akrorecclub)
defeated the tiebreak. Owner confirms: akro.studio IS his workplace; the
other two are not. Danny's build proved the same mention resolves cleanly
via the corroboration gate.

### The fix: classify candidates, let corroboration disambiguate

The insight that makes this safe: FreshaWorkplaceLinker NEVER connects on
name alone — every candidate must corroborate on distance ≤300m /
postcode / phone / name-locality. So the classifier does not need to pick
the one true workplace; it only needs to nominate candidates, and the
existing evidence gate decides. A wrong candidate costs one cached scrape
and dies at no_match — it cannot become a wrong workplace.

1. **Classification (BioIntelligence prompt):** drop the "single
   venue-shaped mention" restriction. Each mention is typed independently:
   `workplace` when the surrounding text says they own/work/cut there, OR
   its handle/name is venue-shaped (studio/salon/barbers/shop tokens —
   keep the token list as-is; deliberately NOT adding "club", which is
   exactly what keeps @akrorecclub out). Multiple workplace candidates
   are now legal.
2. **Chains job (BioMentionChainsJob):** iterate workplace candidates
   ranked by evidence strength — explicit works-at wording first, then
   venue-token-in-handle, then bio order — instead of bio order alone.
   Everything downstream is unchanged: chained scrape → two name
   candidates → Places search → corroboration required → first CONNECTED
   candidate wins, `hasWorkplace` precedence intact.
3. **Cost unchanged by construction:** MAX_CHAINED_SCRAPES (3) and the
   14-day per-handle scrape cache already bound the extra attempts.

### Why not smarter

No sector-matching, no AI re-ranking, no new services: the corroboration
gate is already the disambiguator this design trusts everywhere else.
Ranking is a free tie-break on tokens; evidence does the rest.

### 4-EXT (APPROVED 2026-09-01): one-hop Places-first for venue-shaped mentions

For a venue-shaped mention, try Google Places FIRST on the prettified
handle + the user's own locality context (their GB/geo evidence) —
skipping the paid 15–30s chained Instagram scrape on the happy path.
The chained scrape remains the fallback when Places can't produce a
corroborated match (it supplies the postcode/phone evidence the
one-hop path may lack). Corroboration gate unchanged — the fast path
only wins when the evidence rule is satisfied. Effect: workplace
~30s earlier and most chained-scrape spend deleted; compounds with
Item 9a's timer removal.

### Expected outcomes

- Ryan's bio shape: akro.studio is the only venue-shaped candidate
  (`studio` token; "club"/bali excluded) → chain fires → postcode
  corroboration → workplace connected. First content position of the fix.
- Danny: unchanged (akro.studio still first, still corroborates).
- A bio with several true venues (multi-location person): candidates
  tried in ranked order, first corroborated venue wins — same
  one-workplace-slot semantics as today.
- A bio with zero venue-shaped mentions and no works-at wording: still no
  workplace — the conservative floor is untouched.

### Verification

- Rebuild ryanfitzsimonshair via the public flow → workplace = Akro
  Studio (postcode-corroborated), akrorecclub and orka.bali untouched.
- bydannydixon1-shaped bio → identical outcome to today.
- Fixture test: bio with two venue-shaped mentions where only the second
  corroborates → second connects (proves candidates iterate, gate
  decides).
- Fixture test: venue-shaped mention whose Places search cannot
  corroborate → no workplace written (proves the gate still holds).

## Item 5 (APPROVED): one media pool — retire the POOL_GALLERY side lane

The target architecture is already the ruled one (owner ruling 2026-08-14,
slice 7 unit E, quoted in IndividualProfileResource): "Curated imagery is
the `media` pool" — profile.gallery/curatedGallery/designMedia/siteImages
were deleted from the public wire, and apps/pages reads ONLY pools.media
for the gallery page. Owner uploads joined the pool via the upload→pool
bridge (2026-08-27). ONE writer never migrated: GalleryAutoGrabber still
writes website-grabbed images to site_media POOL_GALLERY — a lane the
wire stopped serving 2026-08-14.

### What is wrong today (verified 2026-09-01)

- Website-grabbed images render NOWHERE on the sitepage (Emma's Wix grab,
  Danny's 6 Squarespace shots — rows exist, never served).
- They are invisible on app.partna.au/media too (no pool item → cannot be
  seen, pinned, ordered, or deleted by the owner).
- The grabber's paid fetch/store/variant work has one living consumer:
  accent extraction (DesignKitAutopilot / SiteAccentResolver read
  POOL_GALLERY as the curated, non-Instagram accent tier).

### The fix

1. **Route GalleryAutoGrabber through the upload→pool bridge**: bytes to
   site_media POOL_CONTENT + mint an UNPINNED media-pool item per grabbed
   image, provenance-tagged (source: website). Default: same semantics as
   uploads (real unpinned items; the auto-newest arm may surface them,
   owner curation overrides).
2. **Accent readers repointed**: curated-tier accent = uploads + website
   grabs by provenance tag; Instagram stays excluded (preserves the
   existing owner decision), POOL_GALLERY no longer the discriminator.
3. **Backfill migration**: existing POOL_GALLERY rows → pool items (same
   provenance tag), then POOL_GALLERY retired as a write target.
   site_media ends as exactly: content (byte store), documents, design.
4. **Zero Astro changes** — the renderer already reads only pools.media;
   that it needs no edits is the proof of the architecture.

### DONE 2026-09-01 (executor decisions, recorded)

- Provenance = the manual lane's coord namespace ('website:' beside
  'upload:'/'manual:') — site_media carries no origin column and the
  anchor coord already says where bytes came from.
- The plan premise "GalleryAutoGrabber is the only writer left" was FALSE:
  POST /uploads still accepted pool=gallery via partna.upload_pools. That
  door is CLOSED in the same commit (config now ['content']); the DB CHECK
  keeps 'gallery' until the legacy read surfaces (GALLERY_POOLS filters,
  /api/gallery endpoints — dead wire since 2026-08-14) are torn down.
- Migration ROLLBACK: NONE (flip records no pre-image); recovery = R2 dump.
  Applied to dev 2026-09-01; backfill ran live: 151 website items minted,
  317 already bridged, 1 skipped (no webp variant). Existing sites surface
  them on their next document rebuild (Wave 5 verifies).
- Legacy POOL_GALLERY READERS flagged for a follow-up teardown:
  UserGalleryController, SitepageDataResolverService gallery envelope,
  GalleryVisibility, the observer's gallery cache-key mapping.

### Selection model (owner-confirmed 2026-09-01, already live as R5)

Sitepage = pinned items in owner order; "add media" picks from the
unselected library (uploads + IG + GB + website grabs — one set); the
auto arm (each source's N newest, partna.pools.auto_latest_n=5, ordered
per Item 2 videos-first) keeps day-one/unclaimed pages alive until the
owner curates. Item 5 changes WHO IS IN THE LIBRARY, not these semantics.

### Verification

- Rebuild a test account whose previous website has images → grabs appear
  on /media as unpinned library items and (via the auto arm) on the
  sitepage gallery; pin/unpin/order works on them like any upload.
- Accent extraction for a site with only website-grab imagery still
  resolves (and still ignores Instagram imagery).
- Backfilled legacy rows visible on /media for an existing account.
- site_media has zero POOL_GALLERY writes after the change (grep + a
  DB check constraint update if the pool value is dropped).

## Item 6 (APPROVED): Astro media consistency — one deck, named exceptions, seed as explicit fallback

Audit 2026-09-01 of apps/pages `[...path].astro` + resolve-site-content.ts.
The invariant (owner): every user-media render reads `pools.media` via
`media.deck`, in pool order — pins first (owner order), then the auto arm
(Item 2 ordering). ALREADY TRUE for: home background (deck[0]), info hero
(deck[1]), workplace hero fallback (deck[2]), gallery page (whole deck),
platform-card image fill (deck cycling). Two reads sit outside the deck:

### 6a. Google listing photos inside the GALLERY grid — remove

`workplacePhotos` (the workplace's Google listing photos) render in two
places: the workplace drop, and INSIDE the gallery page's own grid. The
second violates "gallery = the one pool" directly — photos the owner
cannot see or curate on /media, interleaved with their curated pool.
Fix: listing photos leave the gallery grid. They REMAIN on the workplace
drop, redefined as what they honestly are: the VENUE's surface chrome
(like its hours and address), not the user's media. If the owner wants
venue photos in their gallery, Item 5's library (website grabs) or manual
upload is the honest route.

### 6b. Instagram seed images on the platform card — bless as chrome

`igImages` (`surfaces.instagram.images`, the seed lane) feeds the
Instagram platform card. Ruling: platform cards are platform-identity
chrome (avatar/handle/latest-shot), not user-media surfaces — KEEP, and
record the rule in resolve-site-content.ts: `surfaces.*` media may only
ever render inside that platform's own card/drop, never on a user-media
surface (gallery, home bg, heroes, decks).

### 6c. Seed lane becomes the EXPLICIT day-one fallback

The mid-build symptom (bare home bg, near-empty media page while mirrors
drain — observed live on emdinonhair 07:16, healed by 07:27): deck frames
require mirrored/owned bytes, so deck[0] is null for the first minutes
while the seed reel/photo (mirrored synchronously IN the build, sitting
unused in the payload) could stand in. Fix: home bg and gallery fall back
`deck[n] ?? seed frame` when — and only when — the deck is EMPTY; the
moment any pool item exists, seed never renders. This gives the build's
first minutes a face without creating a second media system: the seed's
only rendering role is "the pool's stand-in while the pool fills".

### Verification

- Grep-level: no `surfaces.*` media reference on a user-media surface;
  gallery grid renders deck frames only.
- Fresh build watched live: home bg shows the seed reel within seconds,
  swaps to deck[0] (newest video, per Item 2) when mirrors land; gallery
  page shows only pool items + (mid-build) the seed stand-in.
- A workplace-bearing account still shows listing photos on the workplace
  drop; its gallery grid contains zero listing photos.

## Item 7 (APPROVED): progressive media — never block rendering on mirrors

Today images render from source_url while mirrors backfill, but VIDEOS are
dropped from the pool until mirrored (PoolResolver's video gate). Change:
an unmirrored video whose CDN URL passes the oe-expiry pre-flight (fresh,
mirror pending, attempts under cap) serves from source_url, exactly like
images — the mirror swap-in happens on a later document rebuild.

- Risk window is bounded and instrumented: a video URL that rotates
  before its mirror lands can 403 for that window; the Astro dead-image
  client handler is the existing net, and the poster (already mirrored
  or oe-valid) is the fallback frame.
- Effect: "media fully visible" collapses from mirror-drain time to
  seconds after projection, for both account types. Compounds with the
  videos-first mirror ordering (Item 2) which shrinks the risk window to
  the front of the drain.
- Scope guard: policy change in the pool video gate + wire; mirrors
  themselves unchanged (they remain the durable end state — this never
  weakens the "no live site depends on an expiring URL" doctrine, it
  time-bounds the exception to the build's first minutes).

Verification: fresh build renders its videos before any mirror lands;
mirrored URL replaces CDN URL on next rebuild; an artificially expired
source_url video is NOT served (gate holds); dead-media handler covers
the rotation window without a broken player.

DONE 2026-09-01 (executor decisions, recorded): the gate is the oe
pre-flight alone (no mirror-attempts cap — attempts aren't in the frames
read, and expiry is the honest liveness signal); deck classification uses
RESOLVED frames so an expired unmirrored video sits with the stills
rather than leading with a card that can't play; the videos-lead deck
partition applies in 'newest' mode only (smart/manual are owner
choices).

## Item 8 (APPROVED, with owner decision gates): vendor-fast scrape lane — Apify becomes the fallback

Kills the largest remaining hard floor (15–60s per actor run) by putting
a fast data vendor (1–3s REST) in front of Apify, primary-with-fallback.
Target: partna `ready` ≈ 5s, everything ≈ 45–60s.

### Decision gates — OWNER ONLY, before any code

- G1 (OPEN): Vendor diligence sign-off. Candidate vendor selected and
  live-tested 2026-09-01: **ScrapeCreators** (account created, ~7k
  credits, agent skill + MCP installed). Compliance posture: login-less
  public scraping, solo-founder company (launched 2024), no formal GDPR
  page — the sign-off on that posture remains the owner's.
- G2 (OPEN): Budget policy under the new economics (~$1–1.9/1k live,
  cache hits FREE with cache_max_age=1d..30d).
- G3 (RESOLVED 2026-09-01, owner-approved): per-platform matrix below,
  from the live fleet test (all lanes tested against ground-truth
  accounts; ~40 credits spent):

| Platform | Assignment |
|---|---|
| Instagram | **SC primary**, Apify fallback (2.5–3.6s vs 15–45s; schema = raw GraphQL our readers already parse; all 5 fixtures matched) |
| TikTok | **SC primary** (profile + /v3 videos endpoint; profile itemList unreliable — never source content from it; region-retry fallback) |
| Facebook | **SC primary** for steady-state (identity 1 credit incl. email/phone/rating; posts 3/call — bulk backfill economics ≈ Apify) |
| Spotify | **SC primary** (artist call = 7 albums + 10 singles + top tracks; discography arrays are plain lists) |
| SoundCloud | **SC primary** (artist + tracks verified: titles/art/dates/permalinks) |
| Linktree | **SC replaces our HTML parser** (exact parity + stable link ids + type discriminator; fragility transferred to vendor) |
| YouTube | **TIERED**: free RSS stays primary; SC channel-videos is the 404-fallback + enrichment (30 items, durations, views — kills the AWS-egress 404 class). Route is /v1/youtube/channel-videos (hyphen) |
| GB listing, menus, bookings, Eventbrite/Humanitix, Bandcamp, Vimeo | **UNCHANGED** — SC has no surface here; Apify + official APIs stay |

### Adapter contract notes (from live testing — bake into implementation)

- NotFound bills a full credit with success:true — gate on payload
  shape (e.g. __typename), never HTTP status.
- Handles are exact-match, no fuzzy resolution — squatter accounts
  return "successfully"; existing source-validation stays load-bearing.
- All media URLs signed/expiring — already assumed by the mirror
  doctrine; nothing new required.
- Optional keys are OMITTED, not null; strip credits_* fields before
  persisting payloads; follower_count sometimes only in
  edge_followed_by.count (existing dual-shape fallback).
- X avatar needs the _normal-suffix strip; YouTube publishedTime is
  synthesized — use publishDate.

### Implementation shape (after gates clear)

1. Third adapter behind the existing seam (`InstagramActorAdapter` /
   `adapterFor()`): vendor request in, normalized payload out — the
   downstream contract (fullName/biography/latestPosts/business fields,
   camelCase+snake_case tolerance) is already pinned by the two-actor
   history.
2. Primary-with-fallback resolver: vendor with ~3s budget → on failure,
   timeout, or shape-validation miss, fall through to the untouched
   Apify path. Bad vendor day degrades to today's speed, never to
   failed builds.
3. Budget ledger generalized per vendor (ApifyBudget pattern).
4. Contract tests on golden fixtures from BOTH lanes; shape-mismatch
   telemetry; canary rollout (vendor-first on a % of builds, comparing
   name quality, post counts, seed-media success) before default-on.

Verification: canary metrics ≥ Apify-lane parity; fallback exercised in
a fault-injection test; a build with the vendor lane forced off is
byte-identical in outcome to today.

## Item 9 (APPROVED): golden-standard signup latency — the base pipeline work

Items 7/8 stack on this. Audit basis: 7-agent code audit 2026-09-01 +
measured timelines of four live builds. Governing rules: (1) no clock
ever stands in for an event — every wait is a data dependency or a
state-gated recheck; (2) tier order identity → addressability → first
paint → content → enrichment → polish; (3) I/O-bound work runs
concurrently unless data forbids it; (4) videos mirror first; (5) every
quality gate (corroboration, budgets, AI gates, Fresha precedence)
survives untouched.

### 9a. State-gated workplace precedence (kill the 600s timer)

- BioMentionChainsJob dispatches AT ready. Gate: an auto-mode Fresha
  connect pending (connectMode=auto row exists, connectPendingAt set)
  → short re-queue (~30s) until settled; else run now. Precedence by
  STATE, not clock. (The 600s timer only ever covered Fresha's first
  retry tier anyway — the race is already winnable today.)
- Partna workplace lands ~45–90s instead of 10–12 min. Item 4's
  corroboration gate bounds the race's residual failure mode.

### 9b. Queue concurrency (config/horizon.php)

- `scraping` supervisor: 1 → 3 workers (jobs are Apify-HTTP waits, not
  CPU; memory on the 2GB box is the constraint to check).
- media-mirror out of supervisor-1's 11-queue strict-priority list into
  its own lane (or supervisor-1 2 → 4). Fixes: mirror drain 5–6 min →
  ~1 min, rebuild/purge churn no longer starves media, and TWO
  SIMULTANEOUS SIGNUPS NO LONGER SERIALIZE (launch-scaling fix).

### 9c. Enrich reorder

- GoogleBusinessEnrichJob: seedWorkplaceEarly (needs only Place Details
  already on the row) moves BEFORE the paid Apify listing scrape; the
  website-scan subtree (about/gallery/logo/accent) starts ~30–60s
  earlier, parallel with the scrape. Apify-derived link seeding stays
  after the scrape (true dependency).

### 9d. Build-internal parallelization

- Seed fetches pooled (photo + reel-cover + profile concurrently); reel
  mp4 started but NOT awaited — photo fallback + Item 6c stand-in cover
  first paint; the doc rebuild swaps the reel in when it lands.
- **DONE 2026-09-01, consciously narrowed** (executor decision): the mp4
  (10-40s) left the critical path via SeedReelMirrorJob (media-mirror
  lane, payload-merge under the row lock, observer purge swaps it live);
  the poster rides WITH the mp4 (poster-first belongs to Item 6c's
  renderer work, Wave 3). Image-GET pooling was NOT done: the three
  small fetches share a hardened streamed-sink transport (SEC-14 sniff +
  pixel budget) and pooling would have saved <1s at the cost of that
  posture. ManyChat's webhook materializes identity synchronously (its
  202 must carry the claim URL); fleet stays async for 1d parity.
- AI bio pass runs concurrent with seed media fetches (names still
  written before seed()'s identity/Fresha-dependent steps — that
  ordering contract holds).
- HeadshotAutoSeeder + unmatched-link sweep move after ready (both
  already non-fatal).
- Target ready: partna 8–25s (today 15–51s); business unchanged (~5s).

### 9e. Timer → event chaining in the cascade

- Replace: +30s gallery scan, +30s menu HTML, +30/45/60s PDF scans,
  +120s accent re-pass, +5 min GoogleMenuPhotoScanJob, and the 15-min
  menu:retry-unavailable cron for the transient-failure case — each
  with dispatch-on-completion from the job that produces its input.
- **DONE 2026-09-01** (executor decisions, recorded): the three scan
  staggers were pure dead time (inputs already in hand) — removed
  outright. The +120s accent re-pass chains from SiteMediaObserver on a
  logo/gallery asset reaching READY with a dominant colour (the exact
  state the async tiers query). The photo scan's 5-min hold became a
  deferral released by MenuFetchJob's every terminal path
  (chainAfterMenuSettled). The transient-failure case gets ONE in-band
  re-fetch ~90s out via a RetryMenuFetchJob relay (self-dispatch would
  be dropped by the unique lock still held in settled()); the 15-min
  cron stays as the long-tail net.

### 9f. Videos-first mirror dispatch

- ProjectionWriter::dispatchMirrors orders video assets before images
  (compounds with Items 2 and 7).

### 9g. Scrape pre-warm at the availability step

- When the signup form's IG handle validates (availability endpoint,
  looser throttle bucket), fire a cache-warm profile scrape (the 900s
  profile cache makes the build's scrape a hit). Budget-gated + bot-token
  guarded so abandoned forms cost ≤1 actor run (≈1 vendor call under
  Item 8).
- **DONE 2026-09-01** (executor decisions, recorded): the availability
  endpoint never sees the IG handle (it checks email/handle_lc), so the
  warm got its own endpoint — POST /public/signup/prewarm, dedicated
  tight bucket (5/min + 15/hr per IP), the build endpoint's bot-token
  scope, unconditional 202 (never an existence oracle), a 900s-unique
  job matching the profile-cache window. FE fires it debounced 900ms
  after the IG input settles, once per distinct handle. rememberLocked
  collapses a warm-vs-build race: the build's prefetch waits on the
  warm's lock and reads its answer.

### 9h. Tier markers on the poll wire (optional garnish, included)

- Status endpoint exposes ready → content_filled → enriched so the
  signup UI shows honest progress and every real signup emits per-tier
  timing telemetry.
- **DONE 2026-09-01** (executor decisions, recorded): markers are two
  nullable columns stamped LAZILY by the public poll the first time it
  observes each condition (observeTierMarkers) — centralised instead of
  hooked into every producer job; each stamp logs `pre_account.tier`
  with seconds_since_created (the campaign's telemetry). The FE card's
  unmount-at-ready contract was left intact on purpose — tiers ride the
  wire (BuildStatus.tiers) for a future card iteration; changing the
  card's lifecycle mid-plan was out of scope.

### Target totals (validated against the 2026-09-01 measured builds)

| Milestone | Today | Target |
|---|---|---|
| KV live | ~9s | ~9s |
| ready (partna / business) | 15–51s / ~5s | 8–25s / ~5s |
| media pool fully visible | 6–7 min | 60–90s (seconds with Item 7) |
| workplace (partna) | 10–12 min | 45–90s |
| EVERYTHING (partna / business) | 12–13 min / 4–9 min | 1.5–3 min / 2.5–3 min |

### Verification

- Rebuild the standing test accounts (emdinonhair, bydannydixon1-class,
  a GB business) and measure every tier against the table — same
  timeline-capture method as the 2026-09-01 baseline runs.
- Two simultaneous signups complete without serializing.
- Fresha-present fixture: bio chain defers while the auto connect is
  pending and fills only if Fresha fails — precedence semantics proven
  by test, not by clock.

## Item 10 (APPROVED): platform expansion via ScrapeCreators — new sources for existing pools

Owner-selected 2026-09-01 from the gap analysis. Contingent on Item 8's
G1/G2 gates (same vendor). Every new connection surface consults
AccountCapabilities (account-capability-audit rule); new tiles follow
Item 3's connect-sheet + DEDICATED_CONNECT regeneration pattern. No new
pool types anywhere — everything feeds pools that already exist.

### 10a. Link-only platforms upgraded to data sources

- **Twitch** (tested, strong): identity + recent VODs w/ thumbnails/
  dates/views → **watch pool**; live status → live badge. Also exposes
  the account's sibling social links (detection input).
- **Pinterest**: boards + pins → **media pool** (image-rich; creatives/
  beauty/food).
- **Threads**: identity card + last ~20–30 posts → profile card and
  modest media feed. (Media URLs are IG-signed — mirror, never hot-link.)

Owner explicitly did NOT include (stay link-only): Snapchat, LinkedIn,
X, Telegram, Reddit, GitHub, Kick.

### 10b. New platforms added to the registry

- **TikTok Shop**: products + reviews → **shop + reviews pools** (live
  in AU; creators selling through it get real product cards).
- **Komi / Pillar / Linkbio / Linkme**: structured link-in-bio, same
  endpoint pattern as the proven Linktree one → LinkInBioScan
  generalizes from Linktree-only to five services (musicians/creators
  coverage).
- **Amazon Shop** (influencer storefronts): products → **shop pool**.
- **Bluesky**: profile + posts → socials/custom_links (+ media
  candidates). Low urgency, cheap add.

Registry/lane work per platform: PlatformRegistry entry (or binding),
connect route, connect-sheet tile, ingest connector where it's a
content source, capability gate.

## Item 11 (APPROVED): deeper endpoints for already-scraped platforms — pool enrichment

All sleeper-category items owner-approved 2026-09-01. Same vendor
contingency as Item 10.

- **11a. Facebook Events → events pool** (highest value in the set):
  profile_events/event_details turn every business account's existing
  FB connection into an events source — AU venues post events on FB far
  more than on ticketing platforms; today the events pool feeds ONLY
  from ticketing (Eventbrite/Humanitix/Luma/Partiful/Oztix).
- **11b. Instagram depth**: user_reels (video history BEYOND the
  12-post profile window — direct answer to Item 2's window bound;
  supersedes Item 2's deferred "conditional deeper scrape" with a
  cheaper mechanism), highlights (owner-curated sets → gallery
  candidates), tagged_posts (social-proof imagery).
- **11c. YouTube shorts + lives**: short-form → watch pool; lives →
  live status.
- **11d. Unified live-status**: TikTok live + YouTube lives + Twitch
  live via one vendor — consolidation path for
  CheckStreamingLiveStatusJob (every-minute poller with a
  MaxAttemptsExceeded issue open since July).
- **11e. Transcripts** (IG/TikTok/YouTube/FB video → text): new fuel
  for the AI enrichment layer — About generation, service/menu
  extraction from spoken content. Most speculative item; ship behind
  its own budget cap.
- **11f. Spotify podcasts + episodes → listen pool** (alongside
  apple-podcast).
- **11g. find_social_profiles**: cross-platform discovery from one
  identity — strengthens the build's platform-detection layer
  (bio-mention chains, GB-enrich socials).

### Sequencing note (Items 10–11)

11a (FB events) and 10a-Twitch are the value leaders; 11b rides Item 2's
implementation; 10b's link-in-bio quartet rides the Linktree adapter.
Everything else follows the first adapter wave proving the pattern.

## Item 12 (APPROVED): menu/shop separation — a restaurant's ordering store must not fill the shop pool

Case: the-famished-wolf-kensington. Their own website is WooCommerce used
for online ordering; the commerce probe correctly detected a store,
ShopInitialFillJob pulled the catalogue and auto-selected dishes onto the
SHOP page (Wolf Dogs, Classic Mac, desserts — 8 products), duplicating
the menu ("Classic Mac" exists as BOTH menu_item and product). The lane
has no sector, menu-overlap, or capability gating (verified: zero hits
in ShopInitialFillJob + StoreBrandSeeder). Separation IS possible — all
evidence lands before products are created (sector=restaurant at
03:54:19, 144 menu items at 03:56:14, first product 03:57:23).

### Fix

1. **Food-sector guard on the sell surface**: when the connected store
   sits on the user's OWN website domain AND the user's sector is
   food-service, the store's catalogue fills LIBRARY-ONLY — no
   ShopAutoSelector::selectInitial(), no auto-publish. The store
   connection itself stays (it is their ordering system; the ordering
   link keeps routing as today). Owner can still enable shop manually
   post-claim (shop is opt-in pins by design since 2026-08-17) — this
   preserves the mixed case of a restaurant that also sells real merch.
2. **Menu-name dedupe backstop** (all sectors): a store product whose
   normalized name matches an existing menu_item is skipped at fill
   time — catches the Classic Mac class even outside the sector guard.
3. No new pool semantics — this changes what auto-PUBLISHES, not what
   is ingested.

### Verification

- Rebuild the-famished-wolf-kensington: shop page empty (or absent),
  menu carries the dishes once, ordering links intact; store catalogue
  present as unselected library items.
- Fixture: non-restaurant with a WooCommerce store → shop auto-fill
  unchanged. Fixture: restaurant selling merch → merch connectable
  manually post-claim.
- Execution slot: Wave 3 (pool lane work).

## Decisions record (owner sign-off 2026-09-01 evening)

- **G1 SIGNED OFF**: ScrapeCreators accepted as vendor (fallback
  architecture is the risk mitigation).
- **G2 SIGNED OFF**: caps may be raised at executor's discretion —
  resolution: raise per-source scrape caps ~3x, give every NEW source
  (Items 10/11) its own cap from day one.
- **All remaining decisions delegated to executor**, resolved as:
  - Item 3a: `order_online` stays OFF the sheet (decision recorded in
    connect.ts).
  - Item 5: website grabs enter the pool as unpinned REAL items (upload
    semantics).
  - Item 9b: worker-box upgrade pre-approved if memory requires; deploy,
    watch for OOM, scale box or trim workers accordingly.
  - Pending triage: media_mirror zero-dispatch log flood → fixed in
    Wave 3 (rate-limit the aggregate line); menu-scan-before-sector →
    folded into 9e's event chaining; cold first-render latency →
    measured in Wave 5, addressed only if still slow post-waves;
    rebuild-churn → absorbed by 9b + existing coalescing (no separate
    work); Nightwatch billing → OWNER side (still 402 as of sign-off).
- **Environment ruling**: software not live, all users are test
  accounts — Supabase dev may be overridden freely, test sites may go
  down during execution, deploys to the development environment are
  unrestricted. Full execution + go-live permission granted.

## EXECUTION ARCHITECTURE (locked 2026-09-01)

Designed from scratch for this plan (owner: "not biased by existing
skills or instructions"). Principles: dependency-ordered waves; the
vendor adapter lands EARLY because every later item gets faster and
cheaper on top of it; each wave ends deployed + live-verified before the
next begins; every item's own Verification section is executed literally
and evidence recorded; one results doc at the end proves the whole plan.

- **Wave 0 — instant wins (config + wiring, no architecture):**
  9b queue concurrency, G2 cap raises, 9c enrich reorder, 9f
  videos-first mirror dispatch, Item 3 (tiles + DEDICATED_CONNECT
  regen + drift guard). Deploy backend + dashboard.
- **Wave 1 — vendor foundation (Item 8):** ScrapeCreatorsAdapter for
  IG; primary-with-fallback resolver; per-vendor budget ledger;
  contract tests on saved live fixtures; then TikTok/FB/Spotify/
  SoundCloud/Linktree primaries + YouTube tiered fallback. Canary =
  rebuild of the standing test accounts.
- **Wave 2 — identity & workplace:** Item 1 (scrape-first resequence —
  now cheap on the 2-4s vendor scrape), Item 4 + 4-EXT, 9a timer kill,
  9d build parallelization, 9e event chaining, 9g pre-warm, 9h tier
  markers.
- **Wave 3 — media architecture:** Items 2, 5, 6, 7 together (one
  library, videos-first ordering, one renderer, progressive video), plus
  the log-flood fix.
- **Wave 4 — expansion:** Item 11a (FB events) + 10a-Twitch first, then
  10b link-in-bio quartet (rides the Linktree adapter), Pinterest/
  Threads/TikTok Shop/Amazon/Bluesky, 11b-g.
- **Wave 5 — full verification campaign:** delete + rebuild the
  standing test handles through the public flow; measure every tier
  against the 2026-09-01 baseline timelines; execute every item's
  Verification section; write the results doc
  (docs/plans/2026-09-01-signup-test-fixes-RESULTS.md). Plan file is
  deleted only when results are green (repo convention).

Method per wave: implement (parallel subagents for disjoint files/repos,
diffs reviewed centrally before commit), `php artisan test` targeted +
typecheck/lint per repo, commit small on the deploying branches
(Comet-Backend `development`, monorepo `main`), deploy, then live-verify
with the same DB/log capture used for the baseline. Any wave may be
paused/resumed across sessions; this section is the handoff contract.
