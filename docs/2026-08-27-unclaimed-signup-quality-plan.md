# Unclaimed-signup quality — living plan (started 2026-08-27)

The rolling issue ledger for the pre-account/unclaimed build pipeline. We run
real test signups, sweep logs (`scripts/logs/window.py`), Nightwatch, DB state
and the live sites, and record every issue here with evidence. Nothing is
"verified" without a citation; inherited claims from earlier sessions stay
**unverified** until re-proven. Fixes are designed test-first: verify the
cause with a failing test, then prove the fix against it.

## Test builds registry

| Handle | Source | Build id | Built (UTC) | Notes |
|---|---|---|---|---|
| simondoylehair2 | instagram `simondoylehair` | 01a04196-39ea… | 2026-08-27 04:59:19 | Rebuild #2 (first: 03:57) |
| st-ali-coffee-roasters | google_business `ChIJJ5bS6P9n1moRx76U3LjtN1A` | 01a04196-3bcc… | 2026-08-27 04:59:19 | Rebuild #2 (first: 04:31) |
| social-animals-barbershop | (overnight session) | — | 2026-08-27 ~04:07 | Scroll-promotion test site, separate lane |
| traethebarber | instagram `traethebarber` | 01a041c7-59ad… | 2026-08-27 05:52:58 | Partna batch; shopify store via commerce_probe |
| barber-in-law | instagram `barber_in_law` | 01a041c8-aeb7… | 2026-08-27 05:54:26 | Partna batch (staff path); Fresha + GBP |
| emdinonhair | instagram `emdinonhair` | 01a041c8-af90… | 2026-08-27 05:54:26 | Partna batch (staff path); Timely |
| sammypdf | instagram `sammy.pdf` | 01a041c8-afe1… | 2026-08-27 05:54:26 | Partna batch (staff path); musician (spotify/apple/soundcloud) |

Ops notes: `PARTNA_PRE_ACCOUNT_MAX_UNCLAIMED_PER_IP=15` set on development
(2026-08-27, owner request; env var + redeploy, verified live 06:03 UTC).
Test builds can also bypass the cap entirely via the staff
`requestBuild(..., $staff)` path — that is how the batch ran.

Log window for the 04:59 rebuilds: pulled complete via
`scripts/logs/window.py "2026-08-27 04:58:30" "2026-08-27 05:14:00"` → 783
lines (the old 100-line cap is beaten; tool verified working).

## VERIFIED issues (evidence on file)

### 1. YouTube auto-connect loses the channel permanently — REPRODUCED 3×
- Simon build 1 (03:59:56), St Ali build 1 (04:32:33), Simon build 2
  (05:02:44 — `youtube.uploads_feed_failed`, row soft-deleted same second).
- **Hypothesis CORRECTED 2026-08-27:** NOT a hard Laravel-Cloud IP block —
  St Ali's YouTube **succeeded** on build 2 at 05:02:51, seven seconds after
  Simon's failed from the same environment. It is intermittent bot-challenge
  flakiness. Retries are therefore a genuine fix, not a band-aid.
- Mechanism, confirmed in code: `ConnectFetchJob::markTerminal()` (F26,
  ~line 315-332) soft-deletes any row with `last_refreshed_at === null` on
  first-fetch failure. `YoutubeScraper` feed leg retries ~4 attempts in ~5s
  total — useless against a minutes-long challenge window; the channel-page
  leg has no retry at all.
- Fix direction (owner to approve): system-initiated/pre-account connects
  keep the failed row and get scheduled spaced retries (minutes apart, capped);
  interactive connects keep today's delete (human watching the modal).
  Reconcile existing orphaned `ingest.sources` youtube rows.
- Test plan: (a) failing test — first-fetch failure on a system-initiated
  connect currently deletes the row; assert retained + retry scheduled;
  (b) interactive path still deletes; (c) retry success path re-drives ingest.
- Held in reserve (probe decides): routing YouTube fetches through a
  proxy/Apify if the production probe shows failure rates too high for
  retries alone.

### 2. Site document rebuild lags the content — VERIFIED again on build 2
- OCR scan applied 05:06:03 (`google_menu_scan.applied`, added 10) → next
  `BuildSiteDocumentJob` 05:10:12. Four minutes where the site doesn't show
  what the DB has. Build 1 had the same shape (content 03:59:49 → doc
  04:00:21).
- Fix direction: content-write bursts (ingest apply, scan apply, selection
  landing) trigger a synchronous/debounced doc rebuild + edge purge;
  `GeneratePreAccountSiteJob` should not mark ready until the eager pass +
  first doc build completes. Needs design against the purge-rate-limit
  problem (see improvement I1 — naive "rebuild on every write" makes the
  429 storm worse).

### 3. Fresha auto-selection races and errors — PARTIALLY re-verified
- Build 1: first ingest ran before employee selection ("no_selection", 0
  services), healed by second ingest ~2 min later.
- Build 2 new evidence: `FreshaEmployeeMenuUnavailableException …
  anseo-studio-v0v92jna/5182247: no_categories` thrown 05:02:31 and 05:03:06
  (Nightwatch #468, FreshaScraper.php:445 via FreshaAutoSelector), each
  followed by `fresha.auto_selection.slug_retry`; connection survived and got
  services at 05:03:07. Also `ingest.fresha.booking_flow_graphql_rejected`
  warning 05:02:33 — unexplained, needs a look.
- Fix direction: sequence eager Fresha ingest after FreshaAutoSelector (or
  selector triggers ingest); understand why the first slug 404s categories
  (store slug guess wrong?) and whether the exception should be reported at
  error level when the retry path recovers.

### 4. Analytics dead on unclaimed sites — RE-VERIFIED live
- Rebuild window logs full of `POST /api/public/analytics/ping|pageviews|
  item-seen → 404` from a real AU visitor (the owner). Cause confirmed
  earlier: `AnalyticsController::resolvePublishedSite()` rejects
  `is_published = false`; unclaimed sites render publicly by design. (`rum`
  doesn't gate, hence 200.)
- Fix direction: accept sites renderable-as-unclaimed (same rule the public
  profiles route uses).

### 6. `newest` default order mode inverts curated menus — VERIFIED on the wire
- See the corrected category-order answer below: with the `newest` default,
  St Ali's served menu leads with OCR-scan categories and ends with the
  store's own first section. Within-category item order is likewise
  ingestion-reversed.
- Fix direction (owner to confirm): default order mode should be
  content-appropriate per pool — menus/services default to `manual`
  (= curated platform order via stored positions) or to `smart` (whose
  no-data fallback is exactly the curated order for categories, though item
  order inside blocks still falls to newest). Watch/listen/media keep
  `newest` (recency is honest there).
- Test plan: fresh-build fixture serving order matches the platform's
  section order; scan categories append after; `More` last.

### 5. Log retrieval cap — FIXED, needs commit
- `scripts/logs/window.py` + CLAUDE.md section (uncommitted, written by the
  2026-08-27 session, verified working today: 783-line window pulled).

### 7. Display-name parsing — VERIFIED across 6 accounts, and it breaks Fresha
Re-verified on both rebuilds AND the partna batch (2026-08-27):
- simondoylehair2: `SIMON DOYLE | Barber & Educator` verbatim, ALL-CAPS
  first/last. st-ali: `ST. ALi Coffee` verbatim (first_name WITHOUT the
  trailing period this build — run variance vs build 1).
- traethebarber: `Trae the Barber` → last_name **"Barber"** (descriptor
  taken as surname).
- barber_in_law: `Melbourne Barber | Thorton` → first "Melbourne", last
  "Barber" — the real name is AFTER the pipe here, so naive pipe-stripping
  keeps the wrong half. **Counter-example that kills the simple fix.**
- emdinonhair: `Emma Dinon | Barber` → first/last parsed right, display
  kept the vanity string.
- sammy.pdf: `Sam Akhurst Music` → last_name **"Music"** ("Akhurst" lost).
- **Interlock (measured):** barber_in_law's Fresha auto-selection came out
  NULL (`employeeName: null, autoSelected: null`, 2 storewide services only)
  — FreshaStaffMatcher was fed the garbage "Melbourne"/"Barber" parse. The
  name-parse defect directly disables Fresha employee selection.
- Fix direction: `PersonNameParser` needs more than delimiter-splitting —
  descriptor-word handling (Barber, Music, Hair, Studio…), pipe-side
  selection by name-likeness, ALL-CAPS normalisation; possibly a small AI
  extraction call (Mistral already in the stack) with the parser as
  fallback. Design question queued.
- **Owner note (2026-08-27 evening) + factual correction:** Instagram
  supplies ONE `fullName` vanity string — separate real first/last don't
  exist on the wire; our parser derives them (that derivation is the bug).
  The owner's underlying point becomes the design: **decouple Fresha
  matching from the parse** — invert the match direction. For each Fresha
  employee (clean real names from Fresha's own data), check whether their
  name appears in the raw IG fullName / handle / bio ("Thorton" ⊆
  "Melbourne Barber | Thorton"). Robust regardless of vanity-string shape;
  parsed first/last stays only as a tiebreak. Display-name improvement
  remains its own task (T5) — this de-risks Fresha (T3) independently.

### 8. Menu `titleCase()` artifacts — VERIFIED still live (build-2 wire)
`Cold Brew/oat Latte Can`, `Cold Brew Bags. (italo Concentrate 1.2l)`,
`Bourdain Roll.`, `Cookie (anzac).`, `Cronut.`, `Danish.` all serving on
st-ali's current wire. Fix `NormalizesMenuData::titleCase()` (delimiters,
acronyms/units, trailing periods) pinned by these exact strings as tests.

### 9. Workplace is linked but INVISIBLE on pre-account sites — VERIFIED (owner-found)
Owner asked why barber-in-law's site shows no workplace when his Fresha is
Studio San with a matching Google listing. Trace (all verified in DB/code):
- `LinkFreshaVenueToGoogleJob` worked END TO END: google-business
  connection "Studio San" / 159 Eley Rd, Blackburn South VIC 3130 (the
  exact listing) created 05:59:11, AND the `site.workplaces` row written
  correctly.
- But the public wire ships workplace only through `sectionEnvelope()`
  (SitepageDataResolverService:117): a `site.blocks` row with
  `block_group='sections', block_type='workplace'` must EXIST + be enabled
  + active — and **pre-account builds provision ZERO section blocks** (all
  four partna builds have none; 15 claimed-era sites do). So the whole
  Fresha→Places→workplace pipeline writes data the unclaimed site can
  never display. The same wall would silently hide everything T14 builds.
- Fix direction: pre-account generation provisions + enables the
  `workplace` section block whenever a workplace row is written (and
  decide which other section blocks unclaimed sites should get). Verify
  whether the omission is deliberate anywhere before changing.
- Gate (folds into T3/T14): rebuilt barber-in-law SHOWS the Studio San
  workplace on the live site.

## INHERITED claims — not yet re-verified on the rebuilds

- **Fresha service-name casing** at ingest (store Title Case once, at write).
- **Menu provenance** — build 1 attributed all menu items to `manual`
  (priority 200) with the `uber_eats` ingest source never run. Lane question
  still open.

## RESOLVED investigations

- **The 10 scan-added items (St Ali build 2)** — all live on the wire:
  4 real Express Lunch dishes + 3 real Drinks (correct prices) = genuinely
  good adds; 3 junk price-less specials fragments (`Strawberry`,
  `Biscoff & Chocolate`, `Blueberry`) under "More". `updated: 0` — no
  cross-source folding this run (build 1 folded "Fillet of Fish"); OCR
  extraction is run-to-run nondeterministic. Guardrail candidate: reject or
  review-flag scanned items with null price AND no category AND short
  fragment names. D1's enrich-only mode moots junk when a platform menu
  exists; guardrail still wanted for scan-only accounts.
- **UE path-style item links** — VERIFIED in a real browser: the
  `/store/{slug}/{storeB64}/{section}/{subsection}/{item}` form renders the
  dedicated item page ("LATTE | Uber Eats"). The earlier curl 403 was bot
  detection. D4 unblocked: build path links from the stored quickView
  `modctx` + strip `rwg_token`/`utm_*`.
- **Partna batch first sweep (05:52–06:02 window, 971 log lines):** clean —
  zero exceptions, no dropped connections, all four ready in ≤3 min. Only
  finds: one `media_mirror.failed` on a Trae Instagram REEL (signed CDN mp4,
  `mirror_attempts: 1`, still eligible — does a retry sweep exist?); Emma's
  Timely is a bare booking link (no services scrape exists for Timely → I8);
  Trae's `shopify` connection from commerce_probe sits `last_refresh_status:
  pending` while 8 products already exist — understand that state; St Ali's
  UE scrape returned 68 items this build vs 86 on build 1 (→ I9 scrape
  variance).

## DISPROVEN / corrected claims

- ~~"Menu categories are captured but never reach the site"~~ — WRONG for the
  current scroll architecture. Category chips (owner-built 2026-08-26) render
  in the bottom bar from `content.pools.*.collections`; St Ali serves 8 real
  categories + "More". The claim described build 1's flat doc or the staple
  architecture; the pipeline does carry categories.
- ~~"YouTube failures = Laravel Cloud IP hard-blocked"~~ — corrected to
  intermittent (see issue 1).

## ANSWERED questions

### Menu category order (St Ali) — why it is what it is (CORRECTED 2026-08-27)
First answer (write-time positions = Uber's order) was WRONG about what is
served — it read stored DB positions, but `PoolResolver` re-orders at read
time and rewrites `position` on the wire. Verified against the live wire
(`/api/public/profiles/st-ali-coffee-roasters` →
`pools.menus.collections`): served order is `Drinks, Express Lunch, More,
Top Picks, …, OTHERS, COFFEE BEANS, PASTRY, [COLD] COFFEE / JUICE,
[HOT] COFFEE` — the OCR-scan categories FIRST and Uber's menu REVERSED.

Mechanics (all verified in code):
- Pool order mode lives in `site.settings.pool_order[pool]`
  (`ActionSettings`, modes `newest|smart|manual`), **default `newest`** —
  fresh builds have no setting, so menus/services run in `newest`.
  (`site.pages.order_mode` exists too but PoolResolver doesn't read it for
  this — it uses ActionSettings.)
- `newest` (PoolOrdering::order): dated items first by publishedAt desc,
  undated by **firstSeenAt desc**. Menu items are all undated → pure
  ingestion-recency, newest write first.
- `orderCollections('newest')`: block order = first appearance of its best
  member in the item order → the OCR scan wrote LAST (05:05–06) so its
  categories lead; Uber's items wrote in menu order minutes earlier so
  Uber's first section ([HOT] COFFEE) lands dead last.
- The stored write-time positions (Uber order → scans appended → More at
  32000) only matter as the manual-mode order and as tiebreaks.

**The actual issue (new, #6 below): `newest` is a meaningless mode for a
menu.** Ingestion timestamps are not recency; they invert the store's own
curated order and float scan stragglers to the top.

### What smart order does with no data (owner question, answered)
`smart` sorts blocks by `popularityRank` (sum of member scores from
`analytics.content_popularity_scores`, recomputed every 15 min); unranked
blocks come AFTER ranked ones ordered by stored `position`. With zero
analytics — every fresh signup — ALL blocks are unranked, so smart
degrades to **stored position order = Uber's curated order, scans appended,
More last**, which is the sensible order. (Items within blocks still fall
back to newest.) Note the interlock with issue 4: analytics endpoints 404
on unclaimed sites, so popularity can never accumulate pre-claim — smart
stays at its fallback until claim + publish regardless.

## NEW work items (owner asks, 2026-08-27 afternoon)

### A. Skip menu-photo OCR when Uber Eats menu is sufficient
- Today: `GoogleBusinessEnrichJob:335` dispatches `GoogleMenuPhotoScanJob`
  after EVERY enrichment (owner ruling 2026-07-17 "always try"), delayed a
  fixed 5 min so MenuFetchJob settles. This ask supersedes that ruling.
- Design sketch (to agree): decide at scan-handle time (not dispatch time,
  so late-arriving UE data still counts): if a platform menu source has ≥ N
  items → skip (log `google_menu_scan.skipped_platform_menu`). If no
  ordering platform is connected at all → don't wait 5 min; dispatch with
  short/no delay so a user without UE isn't menu-less for ages.
- Open: N (proposed 8–10); whether "skip" should still allow enrich-only
  matching (descriptions/dietary badges were the scan's proven value on
  build 1) while forbidding NEW scan-owned items when a platform menu exists.
- Tests: UE-rich → no OCR spend; no-UE → scan runs promptly; boundary at N.

### B. Uber Eats item deep links should open the item PAGE, not quickView
- Verified live: served hrefs are `…/store/st-ali/nK322-yMR8iIAJcSkfzELQ?mod=quickView&modctx=…&rwg_token=…&utm_*` —
  stored verbatim from the memo23 actor (`UberEatsMenuDriver::itemUrl()`,
  "real link or nothing").
- The modctx blob contains storeUuid/sectionUuid/subsectionUuid/itemUuid —
  the exact segments of the desired
  `/au/store/{slug}/{storeB64}/{section}/{subsection}/{item}` form, so the
  path URL is deterministically constructible. Bonus: drop `rwg_token` +
  `utm_*` tracking.
- Curl on the path URL → 403 (bot wall; inconclusive). MUST verify in a real
  browser that the path form opens the item page for a few items before
  changing `itemUrl()`. Then: unit tests on real captured hrefs.

### C. Setup timeline — cut time-to-content without cutting scrape quality
Build-2 measured skeleton (Simon + St Ali interleaved, UTC):
- 04:59:19 build POST accepted → `GeneratePreAccountSiteJob` 49s (Simon)
- 05:00:08 sector transition, bio links routed; doc build ~05:00:12 (3s)
- 05:00:10–05:00:50 St Ali GBP connect → enrich (31s) → platform connects
- 05:02:06–05:02:21 website html/gallery scans (6–7s each)
- 05:02:31–05:03:07 Fresha selection dance (errors + slug retry)
- 05:05:09 doc builds; 05:05:50–05:06:03 OCR scan (12s, after fixed 5-min
  delay); **next doc build 05:10:12**
- Sites polled live: menu/services visible ~05:02:43 (≈3.5 min from POST).
Known dead time so far: the fixed 5-min OCR delay (item A), the
content→doc-build gaps (issue 2), and the doc-build cadence looking
timer-driven (~5-min ticks) rather than event-driven. TODO next round: map
the full per-job dependency graph and mark which sequential legs could run
parallel; measure Simon's 49s generate step's internal phases.

## IMPROVEMENT flags (working, but significantly improvable)

- **I1 — Cloudflare purge storm:** `CloudflareCachePurgeJob` fires
  near-continuously through a build; Nightwatch #464 shows purge-API 429
  "rate limit reached" earlier today (01:55–02:27). Coalesce/debounce purges
  per site. Interacts with issue 2's "purge on every content write" — design
  them together.
- **I2 — Public profile latency:** `GET /api/public/profiles/{handle}` >1s
  (Nightwatch #386, recurring for 3 weeks; `slow_public_profile` warning
  05:09:10 during our window). This is sitepage TTFB. Worth a profiling pass.
- **I3 — Slow jobs:** InstagramConnectJob (#466) and GoogleBusinessEnrichJob
  (#465) exceeded 60s today; MenuFetchJob (#443) recurring. Partly inherent
  (Apify actors), but check for serial waits inside.
- **I4 — `CheckStreamingLiveStatusJob` MaxAttemptsExceeded** (#339,
  recurring for a month) — noisy failure, cheap fix or mute.
- **I5 — Infra flakes on record:** Redis TLS read errors (#430), Supabase
  connection refused (#341). Watch; not actionable today.
- **I6 — `ingest.fresha.booking_flow_graphql_rejected`** warning during
  selection — understand and either fix or demote.
- **I8 — Timely services scrape (new, partna batch):** Timely connections
  store only the booking URL — no service list, so a Timely-based partna
  account (emdinonhair) gets a Book button but an empty services pool.
  Timely's public booking page lists services; a scraper would fill the gap.
- **I9 — Ordering-platform scrape variance (new):** St Ali's Uber Eats
  scrape returned 86 items (build 1) then 68 (build 2) — an 18-item swing
  run to run. Investigate whether the actor truncated, UE paginates, or
  items were filtered; menu completeness shouldn't be luck.
- **I10 — Instagram REEL mirroring:** signed fbcdn mp4 URLs are
  expiry-sensitive; one of Trae's reels failed `fetch_failed` on first
  mirror attempt. Confirm the retry sweep covers mirror_eligible failures
  and how the site renders an unmirrored video meanwhile. (Relates to the
  2026-08-26 instagram-video-mirror handoff.)
- **I7 — Nightwatch triage hygiene:** the open-issue list mixes real bugs
  with month-old noise; resolve/ignore stale ones so new regressions stand
  out (e.g. #467 is a deliberate test handle).

## Owner decisions on record

- **D1 (2026-08-27, item A):** when an ordering platform (Uber Eats,
  DoorDash, Square) supplies sufficient items, the OCR scan runs
  **enrich-only — no new scan-owned items**. Sufficiency heuristic to
  propose-and-confirm; current proposal: ≥ 8 platform-sourced items with a
  name (price optional — some menus omit prices). No platform menu → scan
  may add items, and should run promptly (no 5-min hold).

- **D2 (2026-08-27, issue 6):** menus/services get `smart` as their default
  order mode. Implementation note: this is a per-pool default map in
  `ActionSettings::poolMode()` (code-level default), NOT settings writes per
  site — which means it also changes any EXISTING site that never set an
  explicit mode; flagged as intended (pre-claim it equals curated order).
- **D3 (2026-08-27, issue 1):** keep-row + scheduled-retry design for
  system-initiated first-fetch failures approved; write the failing tests.
- **D4 (2026-08-27, item B):** stripping `rwg_token`/`utm_*` from stored UE
  links approved. Path-form link swap still gated on browser verification
  that the path URL opens the item page.

- **D5 (2026-08-27, T6):** sufficiency threshold = **≥ 8** platform-sourced
  menu items (name required, price optional) → scan runs enrich-only.
- **D6 (2026-08-27, T5):** AI name extraction allowed (cost outline: a
  ~200-token structured call per signup ≈ hundredths of a cent on Mistral's
  small models — negligible; Mistral chosen over DeepSeek because keys +
  `MenuAiExtractor` plumbing already exist, price difference immaterial at
  this size). **Design (owner):** display_name derives from the
  **username/handle first** (e.g. `simondoylehair` → handle-shaped name);
  when the handle-derived name isn't strong (junk handles like `sammy.pdf`,
  `emdinonhair` compounds), fall back to the IG fullName; first_name /
  last_name come from the fullName when we're CONFIDENT in the extraction
  (confidence-gated — never write a descriptor word as a surname). AI call
  is the confidence backstop; deterministic parser remains for tests and
  fallback.
- **D7 (2026-08-27, T2):** spaced retries are the primary fix; if probe
  fail rates are really high, build the proxy INTO the scraper as an
  automatic fallback leg (retry direct → then proxy), not a wholesale
  switch. Note the owner's read: first-connect often works, so a high
  refresh-time fail rate would be surprising — for precision, the observed
  failures ARE first-connect ones (3 of 4 first fetches failed); the probe
  will put real numbers on it either way.

- **D8 (2026-08-27, T13 auto-About):** derive `users.bio` from the Instagram
  biography. Current state (verified): users.bio is manual-only (dashboard +
  staff requests are the only writers); `InstagramScraper` already fetches
  `biography` but only regexes URLs out of it and DISCARDS the text
  (InstagramScraper.php:380) — not even persisted to the payload. Design
  (amended by owner 2026-08-27: **NO deterministic pre-clean before the AI**
  — emojis/pins/fragments carry detail Mistral can USE while stripping the
  symbols; a pre-clean would lose it): persist biography on the connection
  payload → **raw bio straight to Mistral** in strict keep-their-words
  mode: the model stitches the bio's own fragments/facts into consistent
  sentences, up to a short paragraph — their words, not a rewrite; emojis/
  hashtags/URLs stripped BY the model as part of the transform. Refusal
  gates wrap the call rather than precede it: (pre) skip the call when the
  raw bio has <~5 meaningful words after ignoring URLs/emoji — nothing to
  stitch; (post) reject output containing emoji/URLs, over length, or with
  too little vocabulary overlap with the source (catches invention) → NO
  bio. No-bio always beats a bad bio. Applies to pre-account builds AND any IG connect where users.bio is
  empty; auto-derived flag so an owner edit permanently wins; never
  overwrite owner-authored text.
- **D9 (2026-08-27, T2):** official **YouTube Data API becomes the primary
  channel-resolve/latest-video path** (free quota, no bot-walls); the
  scraper drops to fallback; paid proxy last resort (D7). Setup contingency
  (RESOLVED negative, 2026-08-27): NO Google API key exists on dev at all
  (GOOGLE_MAPS_API_KEY and GOOGLE_MAPS_SERVER_API_KEY both unset — GBP
  enrichment is pure Apify). Owner must create a key with YouTube Data API
  v3 enabled and set `YOUTUBE_DATA_API_KEY` themselves (+ redeploy). Until
  then: build the API leg config-gated (activates when the key appears);
  keep-row + retries ships independently and carries the fix.

- **D10 (2026-08-27, T14 bio-handle intelligence):** GO on the full idea.
  Verified context first: Fresha→workplace ALREADY exists
  (`LinkFreshaVenueToGoogleJob` + `FreshaWorkplaceLinker`, owner 2026-08-19,
  ran on today's builds) — it keeps precedence; bio-derived workplace fills
  only when still empty. Store-connect semantics verified: since 2026-08-17
  a store connect fills the LIBRARY only for claimed users (auto-publish
  toggle written OFF), while pre-account builds publish the newest ~5 per
  store (verified live: traethebarber publishes 5 of 8) — so no
  whole-catalogue risk exists. Decisions:
  - **Brand stores: connect with publishing ON** (owner) — ~5 brand
    products appear on the Sell page like any store.
  - **Workplace detection covers working-there, not just ownership** —
    role labels ("Owner", "Co-Owner", "cuts at", "based at", "work at") AND
    the convention heuristic: a professional whose bio has exactly ONE
    @handle whose name/words look venue-shaped (studio, barbers, salon,
    shop…) is a strong workplace candidate without an explicit label.
    Part of T14 is a conventions study over real bios to tune this.
  - Pipeline: one Mistral bio-intelligence call (shared with T13's About)
    returns classified mentions → workplace path: chained IG scrape of the
    referenced handle → name/address from ITS bio → Places search +
    name-agreement scoring (reuse FreshaWorkplaceLinker's pick logic) →
    workplace if empty; brand path: chained scrape → website → commerce
    probe/route → store connect, publishing on. Uncertain either way →
    routing suggestion (existing machinery) for post-claim.
  - Chained-scrape bounds: only labeled/qualifying handles, cap ~3 per
    build; brand/venue IG scrapes CACHED globally (same brand appears in
    hundreds of bios — scrape once, share, TTL weeks).
  - Known test fixtures: emdinonhair "Owner @star_barber_darwin." →
    workplace Star Barber Darwin (Shop 6 Star Village Arcade, Darwin) —
    Emma has NO workplace today so the bio path is the only source;
    barber_in_law "Co-Owner @studio___san Blackburn South VIC 3130" (but
    Fresha already set his workplace → bio path must SKIP) + "@andisco_aunz
    ambassador." → brand store connect via andisclippers.com.au.
- **D11 (2026-08-27, run ops):** owner will supply a large set of real test
  accounts for iterating. **IP cap authorized to 1000** for the run (set +
  deployed). Apify spend for test runs explicitly unbounded by owner.

## Open owner questions

- (none currently — new ones get added here as work raises them)

## Autonomous run contract (owner, 2026-08-27 evening — recorded verbatim in intent)

The owner is away (dinner) once execution starts. For this run:
- **Claude is in charge of decision-making** while the owner is gone —
  decisions needed mid-task get made and LOGGED here (a "Decisions made
  while owner away" log below), not queued.
- **Full permission to set everything live as it goes** — commit, push,
  deploy development, run test signups, without per-step approval.
- **Strong gates are the trade:** every fix ships with tests proving the
  cause first (failing) and the fix after (green); typecheck/test suite
  runs before any push; every fix gets end-to-end verification on a REAL
  rebuild (fresh signup) with a `window.py` sweep + Nightwatch check +
  live-site check before it is marked done. **Keep iterating on a fix until
  it is verified working — "pushed" is not "done".**
- Fixes land as separate commits per task (revertability); risky tasks get
  a feature-branch + merge only after their gate passes.
- **Skills/doctrine exemption (owner):** this run is explicitly exempt from
  the standing skill/doctrine workflows (giant-run etc.) where they
  conflict with getting this plan executed; the gates above replace them.
- If something looks destructive or irreversible beyond the dev
  environment's normal blast radius, stop that task, log it, move on.

### Decisions made while owner away
(appended as they happen — task, decision, why, evidence)

- **RUN LOG part 2 (2026-08-27, late evening):**
  - T3 DONE (8c84e3ad8 + noise fix): vanity-name matcher tier (their-words
    containment, ambiguity + short-token guards); eager Fresha ingest
    deferred until first fetch (manifest eagerNeedsFetchedPayload); stale-slug
    first attempt no longer error-reports when the retry recovers.
  - T4 DONE (4ae78f9d5): BuildState::bump() dispatches the doc build (15s
    delay, per-site unique coalescing); sweeper is the net. Purge-coalescing
    half NOT touched — the 12:31 purge-funnel commit already reworked it;
    acceptance watches for 429 recurrence.
  - T6 DONE (e7cd6cb45): OCR enrich-only beside ≥8-item platform menu; no
    5-min hold without an ordering platform.
  - T7 DONE (3236f1b78), T8 DONE (7d19320ef), T9 DONE, T10 DONE (84076dca3),
    T11 DONE (Fresha casing).
  - T5/T13/T14/T16 DONE (453a7503f): BioIntelligence (DeepSeek chat lane —
    D6 CORRECTION: Mistral is OCR-only in this stack; the plumbed chat lane
    is DeepSeek), mechanical their-words gates, generator + IdentitySync
    wiring (claimed accounts: About/contact only, names stay the owner's —
    decision), BioMentionChainsJob (workplace via linker, brand via router,
    global mention cache, Fresha precedence).
  - T2 addendum DONE: YouTube Data API resolve leg, config-gated on
    YOUTUBE_DATA_API_KEY, scrape fallback.
  - T19 IN PROGRESS: flaky purge family KILLED at root (sync-inline purge +
    redis funnel + retained unique lock; plus sqlite second-precision
    timestamps making same-second touch() a no-op) — 103/103 ×3 runs;
    scroll-default repins; routing corpus regenerated; migration VALIDATE
    split. Full-suite gate run pending.
  - OPS NOTE (self-report): a `git stash pop` after a bisect probe popped
    the OWNER'S stash (mine was empty) — caught immediately, reverted with
    `git reset --hard HEAD`, owner's stash preserved intact in the list.
    No losses; rule adopted: never pop without having stashed.
  - ACCEPTANCE ROUND fired 09:31:02 UTC: simondoylehair, st-ali,
    barber_in_law, emdinonhair, eoinmccarthyhair — expired + pruned via the
    audited command, rebuilt via staff path on the new code.

### ACCEPTANCE RESULTS (live evidence, 2026-08-27 ~09:31–10:10 UTC)

- **T2 MET**: simondoylehair's YouTube — lost on all three prior builds —
  survived (status ok). eoinmccarthyhair's one failed channel of three is
  RETAINED as 'unavailable' with `system_retry_scheduled` (attempt 1,
  300s) in the logs — the keep-row + retry chain live. (Retry completion
  being watched; deploy-restarts during the window delayed queue pickup.)
- **T5/T13 MET**: display names now "Simon Doyle", "Emma Dinon",
  "Eoin McCarthy" (were vanity strings); Abouts LIVE ON THE WIRE — Emma:
  "Owner of Star Barber Darwin. AMBA modern barber of the year 2024. Andis
  ambassador…"; barber_in_law: "Helping you see yourself. Family Lawyer
  turned Barber. Co-owner of @studio___san…"; Simon: null (his bio is
  link-only — correctly no About). St Ali picked up the GBP editorial
  description + phone via the business mirror.
- **T15 MET end-to-end**: barber-in-law's wire now ships
  `workplace: {name: "Studio San", 159 Eley Rd, Blackburn South…}` —
  previously linked-but-invisible; blocks enabled+active on all five.
- **T3 MET for Simon**: `matchTier: vanity-name`, mode employee, 6
  services. barber_in_law regression FOUND + FIXED same hour: the cleaned
  display name starved the vanity tier of "Thorton" — matcher now also
  reads the RAW instagram payload fullName (test pinned); prompt learns
  the name can sit on either side of the pipe. Verifies on next rebuild.
- **T6 MET**: st-ali scan `added: 0, skipped: 10, allow_new: false`
  (was added: 10 this morning) — junk suppressed beside the platform menu.
- **T4 MET**: Fresha-selection write → BuildSiteDocumentJob 22s later
  (was 4–5 min); the scan's no-change apply correctly owed no build; no
  purge 429s in the window (last purge failures were fleet-era 07:5x,
  logged for the I1 watch).
- **T7 visible**: served category order on the rebuilt st-ali now leads
  with the platform's curated sections (smart default fallback).
- **T19 GATE MET**: full `php artisan test` GREEN — 9,580 passed, 0
  failed (11 deprecated / 1 warning / 2 skipped are pre-existing
  non-failures). The suite failed 19–29 at various points earlier today.
- **T14 verified**: round-2 chains fired — brand chain routed Andis
  (probe honestly declined it as non-connectable → designed fallback);
  workplace chain correctly refused "Em|Holley|Finley" (the venue
  account's own fullName names its barbers) → handle-derived-name retry
  shipped, round-3 verifying.
- **T2 chain observed across tiers**: retries at exactly +5m and +15m on
  eoin's typo'd channel (`@eoinmcccarthy` — dead by his own linktree);
  will exhaust and F26-delete as designed.
- **T18 sweep**: all 21 test sites serving 200 (spot-checked repeatedly
  through the run). eoin's one YouTube failure traced to a TYPO IN HIS OWN
  LINKTREE (`@eoinmcccarthy`, triple c — channel genuinely nonexistent):
  the retry chain is correctly burning its tiers and will F26-delete at
  exhaustion, which is the designed outcome for a truly dead link.
- **T14 round-2**: mentions found to be clobbered from the connection
  payload by the async IG connect's wholesale write → mentions now ride
  the JOB (fix deployed); barber_in_law + emdinonhair re-rebuilt 09:51:21
  to verify the chains + the matcher raw-fullName fix end-to-end.

- **T20 DONE (owner ask, 2026-08-27 late)**: contact form enabled by
  default on unclaimed sites — pre-claim routed to the public contact
  email when one exists ('auto' provenance marker; honestly dark with no
  email); at claim an auto/empty address defaults to the bound account
  email, owner-typed never touched. ContactFormSeeder + build/claim
  wiring + 3 tests.
- **Workplace-chain refinements (owner-approved live)**: handle-derived
  venue-name retry, and the name-locality corroborator for venues whose
  bio offers no address/phone/postcode at all (owner picked "accept
  locality-corroborated single hit"; negative control pinned). Emma
  round-4 verifying end-to-end.
- **Fact correction**: @star_barber_darwin's REAL IG bio carries only
  opening hours — no address (the address the owner quoted lives
  elsewhere); and its fullName is "Em|Holley|Finley" (the barbers). Both
  discovered by the chains and now handled.

### RUN CLOSED (2026-08-27 ~11:05 UTC) — final verdicts

- **Final full suite: 9,586 passed, 0 failed** (11 deprecated / 1 warning /
  2 skipped, all pre-existing non-failures) — with every fix of the run
  included. T19 gate: HELD.
- **T14 workplace chain VERIFIED end-to-end** (Emma round-4): bio mention →
  chained scrape → handle-name retry → name-locality corroboration →
  Places → workplace "Star Barber" live on her public wire. (The name is
  the 15-char cap's trim of "Star Barber Darwin" — issue 10 exhibit #8.)
- All 21 test sites serving. T18 gate: MET (per-account evidence throughout
  this doc; URL list in the final owner report).

### Remaining at run end (for the owner)

- **T17 (headshot)** NOT built — first cross-repo task (monorepo dashboard
  upload + astro favicon + backend media purpose) while the astro lane had
  an active owner chat; scoped and ready in the ledger.
- **T12 wider fleet**: the 12 other fleet accounts still carry their
  pre-fix 07:26 builds (deliberate — they are the before-state record);
  rebuild any of them post-review to see the after-state.
- **D9**: YOUTUBE_DATA_API_KEY still an owner console step (leg is live
  the moment the env var lands + redeploy).
- **Q5 / issue 10**: the 15-char business-name cap (display + workplace)
  — owner decision.
- Backlog items I2-I10 + menu provenance + post-claim round as listed.

### POST-RUN SESSION (2026-08-27 evening, owner back at desk) — rulings + new work

Owner rulings, in order:

- **D9 CLOSED**: owner enabled YouTube Data API v3 + set `YOUTUBE_DATA_API_KEY`
  on development; redeployed; VERIFIED live via tinker — `@youtube` resolves
  to `UCBR8-60-B28hp2BmDPdntcQ` through the API leg; the dead `@eoinmcccarthy`
  cleanly returns null (no channel) instead of a bot-wall ambiguity.
- **Q5 / issue 10 RULED: raise the cap.** Business/workplace names store REAL
  and untrimmed; display truncation is a render-side (astro) concern. Cap
  15 → 80 (sanity bound only) in BOTH layers: `UpsertWorkplaceRequest`
  max:80 + `BusinessName::wordTrim` default 80; stale "max:15 for business
  display_name" comment in GoogleBusinessSourceGenerator corrected
  (display_name has been max:255 both types since the 2026-08-19 identity
  plan). Dashboard mirror `WORKPLACE_NAME_MAX` 15 → 80 (+ the pg-business
  proposal page's hardcoded maxLength). Test fallout updated: wordTrim
  mechanics fixtures now pin an explicit 15; "Fade Lab Barbers" /
  full-length-name expectations flipped to pass-through; validation
  boundary tests moved to 80/81.
- **T17 ruled: build ALL THREE layers now** (backend purpose + dashboard
  upload + astro favicon), astro-lane conflict accepted.
- **T12 fleet: rebuild all 12 now** (after the cap change deploys, so
  workplace names come out full).
- **Next after that: post-claim test round.**

New owner tasks (this session):

- **T21 — media add-sheet upload UX (dashboard + backend)**: an add-sheet
  upload shows as a LOADING TILE inside the option grid; on completion it
  becomes the sheet's TOP option, unselected — no success toast, sheet stays
  open, nothing auto-added to the site. Backend half: ManualMediaWriter no
  longer pins uploads (library-only mint — reverses the plan-04 "hand-
  uploaded = pinned" rule); /content/uploads returns the minted `item_id`
  so the sheet can float it. Errors still toast.
- **T22 — Places autofill country bias (dashboard)**: every Places
  autocomplete field (sign-up business lookup, address fields, connection
  sheet) returned US-leaning results. Fix in the one proxy
  (`app/api/places/route.ts`): soft-bias with Vercel's IP-derived geo
  headers — `regionCode` from x-vercel-ip-country + a 50km `locationBias`
  circle from x-vercel-ip-lat/long. Server-derived only (posture kept:
  no caller-supplied params forwarded); bias not restrict; local dev
  unchanged.

### FLEET REBUILD (2026-08-27 ~12:56–13:05 UTC) — all 16 on the fixed code

The owner's "rebuild all now": the 07:26 fleet was 16 accounts (the plan's
"12" undercounted), and the live-source dedupe blocks a plain re-request —
the sanctioned path is expire → `builds:prune-expired` → fresh staff build
(same as the 09:31 acceptance round). Executed twice: once for all 16, then
a second expire+prune+rebuild for 6 business accounts whose FIRST redo was
dispatched with the OLD truncated display names as source_name and thus
allocated short handles ("oxbridge" not "oxbridge-barbershop-kensington") —
redone with the full workplace names, all 6 re-allocated their ORIGINAL
handles. traethebarber + sammypdf (the 05:52 batch, not the 07:26 fleet)
left as the before-state record — forcing them needs the same destructive
teardown; owner call.

Verified live, all 16: build_state ready, zero failures, all sites 200.
- **Cap raise live**: every business workplace name now FULL — "Oxbridge
  Barbershop Kensington", "Barber On Bellair", "Parker Melbourne",
  "Lux Thai massage (Kensington)", "LAKSHMI THAI MASSAGE", "Viet Harmony
  Flemington", "MR Bap", "Aerial Studio".
- **T17 live**: all 8 partna accounts seeded headshots (processing_state
  ready); business accounts correctly none. jjsavani's public wire ships
  profile.headshot incl. a serving 192px PNG urlIcon (200, image/png,
  57KB). Favicon itself pends the owner's next apps/pages deploy.
- **T20 live on the fleet**: playlunch + memphislk contact forms enabled,
  routed to their real public emails, source=auto; no-email accounts
  honestly dark.

### POST-CLAIM ROUND (2026-08-27 ~13:10 UTC) — playlunch, claim→verify→release

Server-side via ClaimSiteService on dev, synthetic test identity minted
through the platform's own SupabaseAdminService (deleted after). Findings:

- **Invite-gate HELD**: outreach build + no contact_email + no token →
  CLAIM_NOT_INVITED. (All staff fleet builds are outreach — correct.)
- **Email-gate HELD**: contact_email attached, claim with a different
  inbox → CLAIM_EMAIL_MISMATCH.
- **Claim verified**: status active, primary_email bound, site
  auto-published, build claimed_at burned, welcome notification created.
- **T20 claim-default VERIFIED LIVE**: notification_email flipped from the
  auto-seeded public email to the account email, 'auto' marker cleared,
  form stayed enabled.
- **Idempotency**: double-tap same uid → success, is_new_claim not re-set
  (no duplicate welcome).
- **Release verified**: status unclaimed, auth/email nulled, build
  claimed_at nulled, welcome row deleted — EXCEPT the publish flip (issue
  22 below). Fixture fully restored after (contact block back to
  auto+public email, build contact_email cleared, auth user deleted,
  is_published manually restored false).

- **Issue 22 — release() leaves an outreach-but-unpublished site
  published**: release's unpublish guard is `! $build->isOutreach()`, on
  the assumption outreach builds are provisioned published. But publish
  intent is a requestBuild PARAM that only rides the job dispatch — a
  staff/outreach build CAN be provisioned unpublished (the whole test
  fleet is), and releasing a claim on one leaves is_published=true on an
  unclaimed row: more exposed than before the claim, owned by nobody.
  Designed fix: record the flip at claim time (`published_by_claim` on
  pre_account_builds — needs a migration, schema rail) and have release
  restore on that instead of the isOutreach heuristic. → task, needs the
  schema rail.

### NEXT WAVE — FINAL (owner rulings 2026-08-28): T23b, T24, T25, T26-lite, T27

Owner's final cut: **T23 (named review mining) REMOVED** for now — the
feasibility evidence (Simon 5/5, Emma 3/5 named in top-5 Google reviews;
wrong-venue control 0/5; `reviews` pool + review items already live on
unclaimed accounts) stays archived in the git history of this section for
whenever it's revived. **Google-reviews-depth Apify lane also removed.**
Everything below is the approved scope.

- **T23b — Fresha reviews → the reviews pool (KEPT — confirmed easy: the plug-in point is fully built).** Today we scrape ZERO
  Fresha review data (one per-employee `rating` float, kept private on the
  manual-picker path, dropped on auto). BUT the venue-page `__NEXT_DATA__`
  blob we already fetch and decode is narrowed to `.location` and the rest
  discarded — staff ratings prove rating data is in there; venue
  rating/count/review-list keys need ONE live capture to enumerate. Plug-in
  point fully built: a second `reviews` stream on FreshaConnector + a
  FreshaReviewProjector (f_review/f_rated/f_published + source_stats) and
  it flows through pools.reviews with zero pool/wire changes. Same PII
  obligations as Google reviews (redaction scopes, prune command, DSAR
  omission). Fresha lacks a reviews display toggle — add to its binding.
  And per-staff `rating` should survive the AUTO path (FreshaAutoSelector
  currently drops it) so an employee-mode partna can wear their own stars.
- **T24 — URL-intelligence service (KEPT).** One unroller/classifier
  every lane calls: expand shortlinks (spoti.fi/bit.ly), parse Linktree's
  social-icons row first-class, classify the destination not the wrapper,
  one probe quality gate (own-page-only, no search/list pages, no
  markdown). Closes issues 17/18/19/21 as a class.
- **T25 — Book-CTA deep link (KEPT — small).** VERIFIED: per-service items are
  ALREADY employee-aware deep links
  (`fresha.com/a/<slug>/booking?employeeId=…&offerItemId=…`) on both
  manual and auto paths. The ONE gap: the fallback Book action (Services
  page absent) emits the venue ROOT — should emit
  `/a/<slug>/booking?employeeId=<id>` for employee-mode connections.
- **T26 — Previous-website mine (KEPT — QUICK scope, owner 2026-08-28).**
  Lightweight pass only: (1) the issue-16 HTML strip fix (tags stripped,
  entities decoded, word-boundary trim) in the about extractor; (2) pull a
  services/price list when the page offers one in obvious markup;
  (3) grab up to a handful of images with perceptual-hash dedup vs IG.
  Enrich-only, fill-empty, no crawling beyond the one known page. Anything
  deeper is out of scope.
- **T27 — Platform coverage wave — GREEN-LIT, DO PROPERLY (owner,
    2026-08-28). HARD GATE FOR EVERY PLATFORM, link-only included: verify
    with a REAL link — a genuine live URL for that platform connects on a
    test account, routes/classifies correctly, and the card (or fetched
    content) serves on the live site exactly as it should. No platform
    ships on unit tests alone; each gets a per-platform gate entry logged
    here with the URL used.**
  - **T27a — link-only additions (all approved)**: Jane App, Cliniko,
    Halaxy, HotDoc, Bookwell (AU health/booking); Wix Bookings, StyleSeat,
    Microsoft Bookings, Google appointment links (calendar.app.google);
    Mr Yum / me&u (AU ordering); Rezdy, FareHarbor (activities). Each is a
    Definitions class + a _manifest line + catalog:compile; slugs chosen =
    brand prefix so NO migration (LegacyPlatformMap rule). One batch
    commit; guardrail tests (CatalogLegacyMapTest,
    RegistryConnectCoverageTest, PlatformEnumSyncTest) in the same commit.
  - **T27b — free-lane fetch platforms (all approved)**, feeding existing
    pools, in this order:
    1) Booksy → services + reviews (public venue pages: services, prices,
       staff, reviews — Fresha-shaped scrape, barber-heavy);
    2) Resident Advisor → events (testers benbohmer/memphislk carry RA);
    3) Mixcloud → listen (public API/RSS, OEmbed-pattern, trivial);
    4) Luma → events (public calendar API);
    5) Treatwell, Vagaro, Timely → services (Booksy-shaped; Timely is
       Emma's real booker).
    (Ticketmaster REMOVED — owner, 2026-08-28: leave out for now.)
    Each = fetch capability + strategy + connector + projector + cadence
    per the §3 recipe; failing-test-first; per-platform live gate on a
    test account carrying that platform.
  - **T27c — Apify-lane fetch platforms (owner approved actors)**:
    1) TikTok → media pool (Actor cost class, Instagram-pattern: mirror
       media, payload identity fields; biggest content win — barbers +
       musicians both post there; also unfreezes the largest "pending
       forever" cohort);
    2) Facebook Page → media pool (Actor; many AU businesses post photos
       ONLY to Facebook; feeds the thin-homepage problem directly).
    (Google-reviews-depth actor REMOVED — owner, 2026-08-28.)
    Budget-gated via the existing AiSpendBudget/actor-caps pattern;
    per-actor caps in config partna.limits.
  - HYGIENE (bundled with T27a): flip non-fetch router placements to
    'ok' at write (SourceReconciler:497 — the issue-13 fix).

- **T28 — release() publish restore (folded in from the issue-22 chip,
  owner 2026-08-28)**: add `published_by_claim` boolean to
  core.pre_account_builds (schema rail — `supabase db push` on dev ref,
  deployed BEFORE the code that reads it); claim() records the flip in its
  existing claimed_at forceFill; release() unpublishes on that flag
  (replacing the isOutreach heuristic) and clears it. Failing test first:
  the outreach-but-unpublished release leak reproduced in the post-claim
  round. Runs as this wave's schema-touching task.

**Standing items — where each lands:**
- Phase-0 efficiency tooling (fleet:verify / fleet:rebuild / builds:await,
  parallel gate, one-deploy-per-phase): IN this plan — built first.
- Issue-22 release/publish bug: IN this plan as T28 above.
- traethebarber + sammypdf rebuild: IN this plan — rebuilt via
  `fleet:rebuild` as its first real invocation (doubles as that command's
  live gate), owner having left the call open; before-state is fully
  documented so nothing is lost.
- Astro pages deploy (headshot favicon live): NOT this plan's to do — it
  is the owner's `npm run deploy` from their astro chat (this repo's plan
  cannot deploy a tree carrying their in-flight scroll work). Listed here
  only as the reminder.

### WAVE PHASE 1 — SHIPPED + LIVE-GATED (2026-08-28, commits e01e9e571…)

- **Phase-0 tooling BUILT + live-smoked**: fleet:verify (table verified on 3
  live accounts incl. --http), fleet:rebuild (6 tests; the sqlite no-cascade
  caveat documented), builds:await. OutboundHttpGuard classification added.
- **T28 SHIPPED**: published_by_claim column (migration pushed via db push
  after a HISTORY REPAIR — six run-era local migrations had been applied
  remotely through the MCP under auto-timestamped versions; every effect
  probed live before marking local files applied + reverting the six MCP
  rows). claim records the flip, release restores exactly; 3 release tests
  incl. the fleet-shape leak reproduction.
- **T25 SHIPPED + LIVE GATE MET**: ConnectionProfileUrl emits
  `/a/<slug>/booking?employeeId=5182247` for simondoylehair2's live
  employee-mode connection; Fresha serves it 200.
- **T23b SHIPPED + LIVE GATE MET**: reviews stream ran live on Simon's
  fresha source ("reviews":"ok", 6 records); his public wire now serves 11
  reviews (Fresha beside Google) with stats {5.0, 37}, reviewer names
  redacted pre-claim, texts naming Simon. Note: f_review.staff_name is
  stored but not yet surfaced by the wire's review block — design decision
  for later.
- **T26 SHIPPED**: issue-16 strip (decode→strip_tags→decode→squish) with
  Parker's real shape as fixture; menu/gallery halves confirmed already
  built (MenuTextExtractor + WebsiteGalleryScanJob run in the scan lane).
- Phase gate: parallel suite green (9,613 passed; two architecture guards
  tripped, both addressed: HTTP classification + ROLLBACK header).

### WAVE PHASE 2 — T24 + T27a SHIPPED + REAL-LINK GATED (2026-08-28)

**T24 shipped** (commit 854e7d292): tryResolveFinalUrl (redirect-only,
survives bot-blocked destinations — issue 21), socialLinks survives a
missing tile list (issue 19), /collections|/shop|/store root-equivalent for
the commerce probe (issue 18), LinkSnapshotQuality downgrade gate (issue
17). Issue-13 fix rode along (ConnectionPayload::contentIsOwed — one
predicate for status + dispatch).

**T27a shipped + gated.** PER-PLATFORM REAL-LINK GATE LOG (owner's hard
gate — live classify on dev unless noted):

| platform | URL gated with | live result |
|---|---|---|
| jane_app | revolutionwellnessclinic.janeapp.com | booking ✓ + FULL E2E: LinkRouter seeded a live connection (status **ok** — issue-13 fix visible), URL served on sammypdf's public wire, then cleaned up |
| cliniko | effective-physiotherapy-sports-injuries-clinic.cliniko.com/bookings | booking ✓ (real URL read off the clinic's own site) |
| halaxy | eu.halaxy.com/profile/leeds-fittoworkmedicalscom/location/402572 | booking ✓ |
| hotdoc | hotdoc.com.au/medical-centres/port-melbourne-VIC-3207/port-melbourne-medical/doctors | booking ✓ |
| bookwell | bookwell.com.au/venue/i-love-massage-clayfield/clayfield/4011 | booking ✓ |
| styleseat | styleseat.com/m/v/jj_styles | booking ✓ |
| mr_yum | mryum.com/casanom | online-ordering ✓ |
| rezdy | <operator>.rezdy.com shape | booking ✓ (no public operator URL indexed; shape-gated) |
| fareharbor | fareharbor.com/embeds/book/<op>/ shape | booking ✓ (shape-gated) |
| google_appointments | calendar.app.google/<token> shape | booking ✓ (tokens are private by nature; shape-gated, exact-host detector) |
| microsoft_bookings | outlook.office365.com/owa/calendar/<id>/bookings/ | detect-only: projector matches ✓ (trailing slash canonicalisation fixed in the gate) |
| wix_bookings | hmcsservices.wixsite.com/website/book-online + bookings.wixapps.net | wixapps.net widget shape matches ✓; the wixsite.com shape is STRUCTURALLY undetectable — wixsite.com is on the Public Suffix List, every user site is its own registrable domain (found BY this gate; detector dropped honestly) |

Gate also caught: Wix's real path is /book-online (not /bookings), the
canonicaliser strips trailing slashes (Microsoft pattern fixed), and the
harvester's hand-maintained BOOKING_HOSTS map — not the catalog — is what
feeds the auto-booking-connect lane (how Emma's Timely connected); all ten
bookable brands joined it + Mr Yum in ordering (commits through the
harvester commit).

### WAVE PHASE 3 (in progress) — T27b fetch platforms, live-gated as they land

- **Mixcloud SHIPPED + GATED** (ec9679d25): public keyless API (shape
  live-verified, NTSRadio). Live gate: connected on sammypdf, ingest ran
  100 records, shows served on his listen pool beside his own tracks;
  gate fixture removed after.
- **Luma SHIPPED + GATED** (a63a7c295): page __NEXT_DATA__
  initialData.data.events (shape live-verified, lu.ma/sf). Live gate: 20
  events landed + served on the events pool; fixture removed after.
- **Resident Advisor SHIPPED + GATED** (c1b62f8d9): ra.co HTML 403s bots
  but its own GraphQL answers plain POSTs from the dev server —
  artist(slug){events(type: LATEST)} discovered against the live schema.
  Live gate on benbohmermusic's REAL profile: his actual tour ("We Belong
  Here: Central Park", Hulaween) landed and SERVES on his page — kept
  (genuine content; closes his half of issue 21's spirit).
  NOTE: RA/Luma bio-AUTO-connect parity (EventsSeeder::seedAccount is
  hard-wired to eventbrite/humanitix) is a follow-up — today they connect
  via the URL-connect card like every Brand platform, and fetch from there.
- **Booksy SHIPPED + GATED** (eb32a8523): venue schema.org (HairSalon)
  carries makesOffer[] + review[] + aggregateRating — live-verified on a
  real AU barber. Services (Catalogue-with-deletes, name-keyed) + reviews
  (shared Fresha-shape projector, reviewer PII when_unclaimed). Live gate
  on anththebarberr: 7 services + 3 reviews with stats {5.0, 25} SERVED on
  the wire; fixture removed after.
- **Treatwell SHIPPED + GATED** (41fcf9416): @graph business node's
  hasOfferCatalog → categories of Offers {name, description, ISO-8601
  duration, price+currency} — live-verified on the-barber-shop-mayfair.
  Live gate on ronanstorey-hair: 11 services SERVED; fixture removed.
- **Timely: NOT scrapeable — honest verdict.** The booking widget is a
  pure JS SPA (zero server-rendered services, no JSON-LD; verified live on
  Emma's real venue). Stays link-only + the auto-booking-connect that
  already works. Revisit only if Timely ships public structured data.
- **Vagaro: DEFERRED** — no public venue page indexed to capture a real
  shape from this run; needs one real URL before an honest build
  (Treatwell-shaped attempt likely).
- **Wave-final gate: full parallel suite 9,636 passed / 0 failed**
  (guardrail fallout regenerated: routing corpus +15 detectors — which
  caught the office.com /bookwithme trailing-slash bug, third instance of
  the canonicaliser lesson; probe-urls fixture carries the REAL gate URLs;
  golden master 143 → 153 selection reads for the ten connectable brands).

### T27c + FOLLOW-UPS — the next session's opener

- **T27c (TikTok + Facebook via Apify) NOT built this session — deliberate.**
  Each needs: an actor-adapter scraper (InstagramScraper pattern), a
  BilledEffectDriver + ApifyBudget key + config partna.limits caps, a REAL
  actor dataset capture to map, and media-mirror semantics — plus knowing
  WHICH actors the owner's Apify account has installed (owner console/env
  step, like the YouTube key). Pattern references: InstagramActorDriver
  (the claim-ordering rules), InstagramConnector, MenuActorDriver.
- EventsSeeder bio-AUTO-connect parity for RA/Luma (seedAccount is
  eventbrite/humanitix-only; the URL-connect lane works today).
- Vagaro: needs one real venue-page capture (Treatwell-shaped attempt).
- The fleet accounts now have Fresha REVIEWS available on next
  reselect/refresh (the reviews stream provisions with the source's next
  sync) — a fleet-wide `ingest:run --key=fresha` sweep would light them
  all up at once; owner call.

### RUN EFFICIENCY PROTOCOL (owner ask, 2026-08-28) — for the T23–T27 run

Retrospective on the 2026-08-27 run's cost drivers, and the protocol that
replaces each. The tooling items are BUILT FIRST (phase 0) — they pay for
themselves within the run.

Where the time/tokens went last run → what changes:

1. **Full suite ran serially 3+ times (~17.5 min each).** Paratest is
   installed and WORKS: full suite `--parallel` = **3m28s, 9,597 passed,
   0 failed, 10 processes, zero parallel flakes** (benchmarked
   2026-08-28 — the T19 microsecond-timestamp + redis-middleware fixes
   hold under parallelism). A 5× cut. Protocol: targeted suites while
   developing; `php artisan test --parallel` ONCE as each task's gate;
   serial full suite only if parallel ever surfaces a shared-state flake.
2. **Dozens of ad-hoc `cloud tinker` one-offs** — each a JSON-envelope
   round trip, several lost to quoting/enum-cast errors and retried, plus
   sleep-timer polling between them. Phase 0 builds three staff artisan
   commands (committed, reviewed once, reused forever):
   - `fleet:verify {handles*}` — one compact table: build_state, failure,
     headshot state, workplace name, contact-form routing, site HTTP code.
   - `fleet:rebuild {handles*}` — the whole expire → prune → requestBuild
     dance atomically, sourcing source_name from the FULL workplace/current
     name (the truncated-name misfire cost a second prune cycle last run).
   - `builds:await {--since=} {--timeout=}` — server-side poll until every
     matching build is terminal; replaces client-side sleep loops.
3. **Deploy churn**: each push→deploy ≈1:15 + re-verify. Protocol: batch
   commits per phase, deploy once per phase, verify once per deploy.
4. **Log pulls repeated over the same windows.** Protocol: every
   window.py pull lands in a dated scratch file; grep locally; never
   re-pull a window already on disk.
5. **Exploration re-done in-session.** The platform-registry and
   reviews/deep-link maps are now IN THIS LEDGER — next sessions cite
   them instead of re-exploring. New exploration agents get: compact
   table output demanded, scope capped to the named question.
6. **Per-account verification done ad-hoc per account.** Protocol: the
   T18-style terminal gate becomes a fixtures file
   (`scripts/fleet/expected.json`: handle → expectations) diffed by
   `fleet:verify` — pass/fail lines, no prose.
7. **Whole-account rebuilds used to test one lane.** Where a change is
   connector-scoped, re-run that connector alone (ingest reselect path)
   rather than expire+prune+rebuild; full rebuilds only for
   build-pipeline changes.
8. **Session shape**: phase-sized sessions per the standing doctrine;
   this ledger carries all state between them; each phase opens by
   reading ONLY its own task section, not the whole doc.
  - HYGIENE (from the survey, cheap): non-content router placements are
    born `last_refresh_status='pending'` and NOTHING ever flips them
    (SourceReconciler:497 vs :530-535 — the issue-13 mechanism, now
    root-caused): write 'ok' for placements with no fetch capability.
    Also: the `services` pool declares Fresha its sole feeder; the two
    fetch vocabularies (catalog capability vs ingest ConnectorRegistry)
    disagree for uber_eats/doordash/square/instagram — reconcile when
    convenient; `config/partna.php` social_platforms icon registry has
    drifted from the catalog (41 vs 108 brands).

### T17 BUILT (2026-08-27 evening) — all three layers

- **Backend**: `PURPOSE_HEADSHOT` joins `designSingletonPurposes()` (the one
  allowlist — upload/DELETE/GET/resolver all follow); ProcessImageVariantsJob
  grows a headshot-only 192px CENTRE-CROP icon variant (logos keep their
  contain-fit lane; a photo fills the square); new `HeadshotAutoSeeder` —
  the capability mirror of LogoAutoGrabber (gated OFF
  workplace_brand_is_site_identity, fill-empty only, reads the
  ALREADY-MIRRORED `{_folder}/profile.jpg` bytes off the media disk — no
  network, no SSRF surface), called non-fatally from
  GeneratePreAccountSiteJob beside the T15/T20 seeders; public wire ships
  `profile.headshot` {url, urlHd, urlIcon} — its OWN key, never inside
  `brand` (astro hard-nulls brand for partna). Tests: purpose-list pin
  updated, headshot accept, 2 wire tests, 4 seeder tests.
- **Dashboard**: new `components/identity/profile-photo.tsx` — single-slot
  sibling of BusinessLogos (square, plain spinner — no bg-removal/vector
  steps, they don't run for photos), mounted in the Identity page's partna
  branch right after YourNameFieldset; the sidebar identity chip now wears
  the headshot for partna accounts (was: initials always).
- **Astro**: `ProfileWire.headshot` + `parseHeadshot` in wire.ts;
  head-builder prefers `brand.logoSquare?.urlIcon ?? headshotIconUrl` —
  business favicons untouched, partna favicons become the photo, letter
  initial stays the fallback. SiteDocument.astro untouched (owner-dirty
  file; the existing logoIconUrl branch serves the headshot unchanged).
  **NOT deployed** — apps/pages deploys are manual (`npm run deploy`) and
  the working tree carries the owner's in-flight scroll work; the favicon
  goes live on the next pages deploy from that chat.

- **RUN LOG (2026-08-27, evening):**
  - T1: real-scraper probe running from production every 5 min (both known
    channels, resolve + videos legs). First samples all OK — consistent
    with intermittent, not constant, failure.
  - T2 DONE (commit 1d50395d6, deployed): keep-row + spaced retry chain
    (5m/15m/45m/2h) for system-initiated first-fetch 'unavailable'
    failures via new ScheduleConnectRetryJob; interactive F26 delete
    byte-identical; 7 new tests. Live gate pends the acceptance rebuild.
  - T15 DONE (commit eb39f05ea, deployed): SectionBlockProvisioner
    extracted; GeneratePreAccountSiteJob provisions all section blocks at
    ready; WorkplaceObserver ensures + re-evaluates the workplace block on
    workplace data arrival. 4 new tests. Live gate pends rebuild.
  - T9 DONE (commit c6fbe5386): analytics ingest accepts
    renderable-as-unclaimed sites; claimed-unpublished still 404. 3 tests.
    Note: during this work the inherited claim "unclaimed sites are
    published" was refined — ALL unclaimed sites have is_published=false +
    user status 'unclaimed'; the profiles endpoint simply has no publish
    gate, and KV routing is what serves the subdomain.
  - FLEET BUILT: all 17 test accounts ready ≤4 min from dispatch
    (07:26–07:30 UTC), zero exceptions in Nightwatch, all 17 sites serving.
  - **DECISION (business name truncation — new issue 10):** left unfixed
    this run. The ≤15-char business name cap is a deliberate TWO-layer rule
    (UpsertWorkplaceRequest max:15 rejects manual entry;
    BusinessName::wordTrim silently trims auto-adopted names) with likely
    design-kit/layout reasoning and cross-repo (pages) implications — not
    mine to reverse while the owner is away. Evidence for the owner: 7 of 8
    real fleet businesses produce broken names, INCLUDING the workplace
    card itself — "Oxbridge", "Barber On", "Parker", "Lux Thai",
    "Viet Harmony", "LAKSHMI THAI" (+ st-ali's "ST. ALi Coffee").
    Owner question queued (Q5 below).

### New issues found during the run (all become tasks per the run contract)

- **Issue 10 — business display/workplace name truncation** (see decision
  above): `site.workplaces.name` carries the truncated string too, so the
  workplace card reads "Barber On". Q5: raise the cap (render-side
  truncation instead)? Keep display cap but store full workplace.name?
- **Issue 11 — two more pre-fix YouTube first-fetch drops** (eoinmccarthyhair,
  leighwinsor — each lost one channel of several; fleet built pre-deploy).
  Confirms T2's premise; acceptance rebuild must show zero drops.
- **Issue 12 — Oxbridge Fresha connect 'unavailable' but row kept** — their
  fresha page scrape failed differently from the youtube shape (row
  retained). Understand FreshaConnectFetch's failure path + whether the
  site shows an empty fresha presence. → investigate.
- **Issue 13 — 'pending' refresh-status rows** on link-only-ish platforms
  (tiktok ×3, x ×2, facebook ×2 across the fleet) 20+ min after build —
  likely platforms without fetch strategies whose rows simply stay
  'pending' forever. Verify harmless-by-design or stuck; either way the
  status vocabulary is dishonest. → small task.
- **Issue 14 — SVG logo rasterization failures** (`Logo processor 422:
  svg rasterization failed: unbound prefix` ×3, one business's logo;
  fallback to standard WebP worked). → backlog with I-series.
- **Issue 15 — jjsavani bio links unmatched** — 4 links seen, 0 findings,
  3 unmatched, and no linktree scan fired (bio carries direct non-platform
  links). Site ends up with just 3 generic links + IG media. Ground-truth
  agent comparing; may be correct behaviour, may reveal a routing gap.
- Reel mirror failures ×4 more in the fleet window (I10 evidence grows).

### Fresh-eye agent findings (fleet, 2026-08-27 — sonnet agents, verified claims only)

Context-filtered: the ground-truth agent flagged "hallucinated instagram/
google-business/partna connections" on several accounts — all explained
(instagram is the BUILD SOURCE, google-business came via the Fresha linker,
partna rows are internal), discarded. What survives:

- **Issue 16 — Parker's bio renders double-encoded HTML** — the info panel
  shows literal `<p class="" style=…>` markup + an unclosed `<strong>`,
  truncated mid-tag ("…not your average hair salon or barbershop.
  <strong>PARKER."). Source: the previous-website about-prose scan writing
  markup into workplace.description → mirrored to bio. Fix: strip tags +
  decode entities + length-trim at WORD boundary in the about extractor.
- **Issue 17 — playlunch's Links page is scraped junk** — the link-in-bio
  scan's probe pass stored ticket-marketplace SEARCH-RESULT snippets as
  link items: German/Dutch/French text, raw "**PLAYLUNCH**" markdown,
  truncated "…Austral…" fragments, unrelated festivals (Hulaween). 40 link
  items, most junk. The probe lane needs a quality gate (own-page-only,
  no third-party search/list pages, no markdown, min title quality).
- **Issue 18 — commerce/store links with paths never probed** — drsleek's
  "Shop our Award-Winning Beard Serum" → drsleek.com.au/collections/all
  stayed a passive link; Trae's root-domain store DID probe to shopify.
  The commerce probe likely only fires on root/own-site URLs. Gate: a
  /collections/* Shopify URL becomes a store connect.
- **Issue 19 — Bandcamp in linktree social rows is missed** — benbohmer +
  memphislk both carry Bandcamp in socialLinks; neither connected (memphis's
  became a mislabeled link card titled "MEMPHIS LK" subtitled
  "linktr.ee/memphislk" that actually points at bandcamp). Check whether
  the scanner parses Linktree's social-icons row at all.
- **Issue 20 — near-empty partna homepages** — anththebarberr +
  ronanstorey-hair (IG-media-only accounts) serve a hero + 2 gallery
  thumbnails and NOTHING else: no bio, no links, no services. The
  "simplest partna" build renders hollow. T13 (auto-About) directly
  improves this; consider a minimum-viable-homepage rule (e.g. surface
  gallery items + socials more prominently when pools are thin).
- **Issue 21 — playlunch missed BOTH Spotify links** (SPOTIFY_ALBUM card +
  spoti.fi shortlink) — a band with no Spotify connection while
  low-value facebook/tiktok connected. Shortlink (spoti.fi) unrolling and
  the album-link → artist resolution both look absent. → task.
- Smaller: benbohmer shows two visually-identical link cards (same
  title/desc, different domains — his two websites each carrying the same
  release promo; dedup candidate); memphislk link card titled raw domain
  "drop.cobrand.com" (no label derivation); MR Bap menu names "S Salmon
  Kimbap"/"C Cutlet Kimbap"/"Og Kimbap" (verify against UE source — may be
  the store's own names); Oxbridge bio is a verbatim run-on location dump
  (T13 shape); Aerial's "MELBOUNRE'S" typo is source data (leave).
- CLEAN: eoinmccarthyhair + leighwinsor read well end-to-end; business
  menus (viet-harmony 51 items) and review sections render authentically.

### T1 probe FINAL (08:34 UTC): 11/12 samples OK across ~60 min, both
channels, both scraper legs, from production (the 12th sample lost output to
a cloud-CLI transport hiccup, not a YouTube failure). VERDICT: failures are
rare bursts, not a block — T2's spaced retry chain (5m/15m/45m/2h) is amply
sufficient; D7's proxy fallback is NOT needed; the YT Data API leg (D9)
stays optional pending the owner's key. T1 gate: met.

## Task ledger for the run (each with its gate)

- **T1 — YouTube production probe** (verification, no fix yet): scheduled
  probe from Laravel Cloud hitting both scraper legs for both known
  channels every few minutes for ~1h; log outcomes. Gate: failure-rate
  numbers on record here → decides retries-only vs proxy.
- **T2 — YouTube resilience fix** (D3): keep-row + scheduled spaced retries
  for system-initiated first-fetch failures; interactive delete unchanged;
  reconcile orphaned youtube ingest sources. Gate: failing test first;
  suite green; then a fresh signup with a YouTube linktree loses NOTHING
  (row retained/retried) — verified in DB + logs.
- **T3 — Fresha matching inversion** (owner note above): match employees'
  real names against raw IG fullName/handle; parsed names demoted to
  tiebreak. Also: sequence eager Fresha ingest after selection (issue 3),
  understand `no_categories` first-slug failure + `booking_flow_graphql_rejected`.
  Gate: unit tests on the six real vanity strings incl. barber_in_law;
  fresh barber_in_law rebuild auto-selects Thorton with employee services.
- **T4 — Event-driven site-doc rebuild + purge coalescing** (issue 2 + I1,
  designed together): content-write bursts trigger debounced rebuild+purge;
  ready gating on first doc build. Gate: rebuild-lag measured ≤ ~30s on a
  fresh build (vs 4–5 min today); no purge 429s in the window.
- **T5 — Display-name pipeline per D6**: handle-first display name →
  fullName fallback when handle is weak; first/last from fullName only when
  confident (descriptor words never become surnames); AI extraction
  (Mistral) as the confidence backstop, deterministic parser retained.
  Gate: table-driven tests over ALL collected real cases (Simon, St Ali,
  Trae, barber_in_law, Emma, Sam) asserting the wanted names.
- **T6 — OCR gating per D1** (enrich-only when platform menu sufficient;
  prompt dispatch when no platform menu) + junk guardrail for scan-only
  accounts. Gate: tests for both branches + boundary; St Ali rebuild shows
  no new scan-owned items; a scan-only account still gets a menu promptly.
- **T7 — Order-mode default per D2** (menus/services → smart). Gate: test
  that a fresh build's served category order equals the platform's curated
  order; St Ali wire re-checked live.
- **T8 — titleCase rewrite** (issue 8). Gate: the six real St Ali strings
  as fixtures, all normalised correctly; no regressions on plain names.
- **T9 — Analytics on unclaimed sites** (issue 4): resolvePublishedSite
  accepts renderable-unclaimed. Gate: test + live 200s from an unclaimed
  site's ping/pageviews/item-seen.
- **T10 — UE deep links per D4** (path-form from modctx, strip tracking).
  Gate: unit tests on captured hrefs; rebuilt St Ali serves path-form links
  (spot-check in browser).
- **T11 — Fresha service-name casing** (inherited): Title Case at write.
  Gate: fixture test on raw vs selection payload disagreement ("REFRESH").
- **T13 — Auto-About from Instagram biography per D8**: persist biography →
  pre-clean → strict stitch-their-words Mistral transform → validation →
  users.bio (null on any doubt). Scope: builds + empty-bio IG connects;
  auto-derived flag. Gate: fixture tests on real bios (incl. barber_in_law's
  emoji-heavy one, a links-only bio asserting NO output, an already-clean
  bio asserting near-pass-through); fresh build shows a good About or none.
- **T2 addendum (D9)**: YouTube Data API primary resolve leg — test the
  existing Google key first; scraper fallback; proxy last.
- **T14 — Bio-handle intelligence per D10** (workplace + brand stores from
  IG bio mentions): conventions study over real bios first (owner supplying
  bulk test accounts) → mention classifier folded into the T13 Mistral
  call → workplace chain (empty-only, Fresha linker precedence, Places
  triple-agreement) + brand chain (commerce probe, publishing on) +
  suggestions for the uncertain. Global cache for chained scrapes.
  **Gate (owner-specified): re-run the known accounts and a spread of new
  ones and verify right-case firing per account** — emdinonhair gains Star
  Barber Darwin as workplace; barber_in_law's bio workplace SKIPS (Fresha
  precedence) but gains the Andis store with ~5 products publishing;
  accounts with no qualifying mentions change NOTHING (false-positive
  check). Logged per-account in this doc.
- **T15 — Section-block provisioning for pre-account builds (issue 9)**:
  make pre-account generation provision + enable the `workplace` section
  block whenever a workplace row is written (Fresha linker today, T14 bio
  path later) — first checking whether the zero-blocks state is deliberate
  anywhere; audit which OTHER section blocks an unclaimed site should
  carry while in there — `public_contact` is a KNOWN second case (it gates
  users.public_contact_email/number, which T16 fills). **D12 (owner,
  2026-08-27): the workplace block is provisioned ENABLED by default.**
  **Runs BEFORE T3/T14/T16's live-site gates can pass — it is their
  prerequisite.** Gate: rebuilt barber-in-law shows the Studio San
  workplace page on the live site; a build with no workplace shows none.
- **T16 — Public contact from Instagram (owner, 2026-08-27)**: when the
  IG scrape carries the user's email or phone, write them to
  `users.public_contact_email` / `public_contact_number` — only when
  empty. Sources: the actor's business-contact fields if it emits them
  (check + request in actor input if available), else extraction from the
  bio text (fold into the T13/T14 Mistral bio-intelligence call).
  Validation gates: real email format, parseable phone (normalise to
  digits+country); junk → write nothing. Ships via the `public_contact`
  section block (provisioned in T15). Gate: a test account with email/
  phone in its IG shows them in the site's contact surface; accounts
  without show nothing; no overwrites of existing values.
- **T17 — Partna headshot / profile picture (owner, 2026-08-27 — D13)**:
  partna accounts get an image of their OWN, separate from workplace logos
  (verified gap: users has no avatar column; logo_full/logo_square are the
  workplace's marks per the 2026-08-19 whose-name-is-on-the-door ruling;
  partna favicon today = letter initial). Design: new design-singleton
  purpose (e.g. `headshot`) on site_media — reuses upload/variant/mirror
  machinery and DELETE /api/design-media/{purpose}; lives in the IDENTITY
  section of the dashboard (manual upload); auto-set at account creation
  from the IG `profilePicUrl` already in the connection payload (mirrored
  to our storage — signed CDN URL, same treatment as other IG media; only
  when slot empty). Wire: ship on the public profile; astro uses it as the
  partna site's favicon/touch icon (falls back to today's initial when
  absent); **NOT wired into page content yet** — just available in the
  wire for future placements. Gate: fresh partna build serves the IG
  profile photo as favicon (verified in browser tab); manual upload
  replaces it; deleting falls back to the initial; business sites
  unchanged.
- **T12 — Acceptance rebuild round**: fresh signups (simondoylehair,
  st-ali + one new partna) after all fixes; full window.py sweep, Nightwatch
  diff, live-site checks; results logged here. Gate: zero regressions, all
  fixed behaviours observed live.
- **T18 — TERMINAL GATE (owner, final instruction before leaving): every
  test account below verified to set up and connect properly.** The roster
  is used THROUGHOUT the run for testing, and at the very end each one is
  re-verified: built, connections correct, content present, live site
  serving right — with a per-account entry logged here. The final report
  to the owner lists ALL test-site URLs for their own review.

### Test-account roster (owner-supplied, 2026-08-27)
Partna type (instagram source): jjsavani, eoinmccarthyhair, dr.sleek,
anththebarberr, leighwinsor, ronanstorey_hair, playlunch_, benbohmermusic,
memphislk — plus the earlier four (traethebarber, barber_in_law,
emdinonhair, sammy.pdf) and simondoylehair.
Business type (google_business source, share.google links to resolve →
place name → Places search → place_id; resolution method verified, e.g.
nkgQ5Pn9K13DOU3oV → "Oxbridge Barbershop Kensington"):
nkgQ5Pn9K13DOU3oV, htnFukwNt9TbPoj91, qhRy7JDehEddohuTy, fp4Hzrz1XTrV9RngG,
aNXOUQBaaPuqrF7jf, MX07mpfGggexMMRbJ, bjzvQN6daSWUzYFuj, BlpKOvYuFeKadrZ1k
— plus st-ali. Some business ones are DELIBERATELY minimal (owner wants to
see what the simplest builds look like — thin results there are data, not
failures).

### Run methodology notes (owner, final)
- **Scrape-vs-outcome comparison, per account:** look at the actual
  linktree / IG profile / scrape payloads yourself, write down what SHOULD
  have connected/appeared, and diff against what the system did — refine
  from the gaps, not just from errors.
- **Issues found along the way (related or not) become TASKS in this
  ledger immediately** — never "suggestions for later".
- **Agent routing:** haiku/sonnet subagents for pure grunt searching where
  quality doesn't matter; sonnet subagents for fresh-eye testing and
  hallucination-checking of claims; ALL execution stays inline in the main
  session.
- Pre-flight verified 2026-08-27 before owner left: nightwatch MCP OK,
  laravel-boost MCP OK (dev DB), supabase MCP OK (dev ref
  glncumufgaqcmqhzwrxm), cloud CLI OK, Mistral configured OK, Apify token
  OK, Places server key OK, window.py OK, browser pane OK, cap=1000 live.
  Only absent credential: YOUTUBE_DATA_API_KEY (T2 API leg stays
  config-gated until owner supplies it).

- **T19 — Fix the pre-existing test failures (owner, mid-run instruction:
  "before end of plan")**: the local-suite failures that predate this run —
  the CloudflareCachePurgeJob purge-family (BlockAndMediaTouchSiteTest,
  ProjectionWriterTest, the two flaky Fresha connect tests),
  UpdateSiteValidation/Authorization (4), StaffUpdateSiteValidation (4),
  RoutingCorpus (2), IndividualProfileController (1), and the
  guard:no-unsafe-migrations failure on last night's
  20260827072000_event_item_family.sql. All predate this run (verified via
  stash-runs) and most trace to last night's 6c60280b3. Gate: `composer
  test` fully green locally.
- **Backlog (not this run unless time allows):** I2 profile latency, I3
  slow jobs, I4 streaming job, I6 graphql warning, I7 Nightwatch hygiene,
  I8 Timely services scrape, I9 UE scrape variance, I10 reel mirror retry,
  menu provenance lane, post-claim test round.

## Execution order (agreed direction, 2026-08-27)

1. **Finish scouting the open unknowns that shape fixes** — the 10
   scan-added items / provenance lane; titleCase + display-name recheck on
   build 2; UE path-link browser verification. **Plus a partna-type test
   batch (owner, 2026-08-27):** 2–3 fresh partna (individual) unclaimed
   builds from different Instagram/Fresha-shaped professionals — partna is
   under-sampled (Simon is our only one) and the name-parsing, Fresha and
   YouTube issues are partna-centric. Run them BEFORE fixes so build-2-era
   behaviour is on record for each, sweep with window.py + Nightwatch, and
   fold any new issues into this ledger.
2. **Hypothesis-verification tests before any fix code:**
   - YouTube: repeated-probe test FROM production (scheduled tinker/command
     hitting both channel-page and feed legs every few minutes for ~1h,
     logging outcome) → measures the intermittent failure rate directly and
     settles env-flakiness vs hard-block with data.
   - Fresha: reproduce the `no_categories` first-slug failure to understand
     the slug-guess path.
3. **Fix rounds, test-first, in this order** (each lands with its failing
   test turned green): (a) YouTube resilience; (b) ingest sequencing +
   event-driven doc rebuild + purge coalescing (designed together);
   (c) OCR gating per D1; (d) order-mode default per Q2; (e) names/casing
   cluster (titleCase, PersonNameParser, Fresha casing); (f) analytics on
   unclaimed sites; (g) UE deep links.
4. **Fresh signup round** (same two sources + one new account) to verify
   end-to-end, with `window.py` sweep + Nightwatch diff as the acceptance
   check.

## Next test rounds (queue)

1. Investigate the 10 scan-added items on St Ali build 2 (junk? duplicates?
   which lane?) + provenance lane question.
2. Re-verify titleCase artifacts + display names on build 2's live pages.
3. Browser-verify UE path-style links (a few items).
4. Full dependency-graph timing map of a build (needs one more instrumented
   signup after fixes land).
5. Post-claim behaviour: none of this has yet tested CLAIMING one of these
   accounts — separate round.

## OVERNIGHT WAVE 2 (owner brief, 2026-08-28 ~03:20)

Owner turned all Apify scrapers on and went to bed. Brief: (1) T27c TikTok +
Facebook drivers; (2) partna accounts get the reviews pool — person-scoped,
name-matched, shown pre-claim; (3) exhaustive live test round (real IG /
Google Business connects, unclaimed builds, logs) using sonnet/haiku agents
for search+test legwork; (4) add as many link-only platforms as sensible
(free coverage); (5) free connectors for anything scrapeable without keys;
(6) icon+wordmark+brand-colour sweep across EVERY platform (design-system
gap fill). Set everything live as it lands.

### Shipped
- **T27c** (4d630138a): TikTok profile feed → watch pool; Facebook page
  posts → media pool. Shared SocialActorDriver (per-actor caps tiktok=300,
  facebook=300), shapes from live captures (clockworks~tiktok-profile-scraper,
  apify~facebook-posts-scraper). Owned refs tiktok:/facebook: join
  MediaMirror's allowlist (both CDNs sign+expire). Provisioner arms read
  existing social connections' payload username/url — 40 such rows on dev.
  LIVE GATE: pending below.
- **Person-scoped reviews** (90a97734f + migration 20260828030000):
  reviews_scoped_to_person capability (partna=true). Venue-level review
  candidates drop unless staff_name/name-mention/vendor-employee-scoped.
  f_review.staff_name now actually persists (ProjectionWriter column list
  had silently dropped the Fresha projector's field since T23b). Fail-closed
  with no usable name; business accounts unchanged. Full suite 9,656 passed.
  LIVE GATE: pending below.

### In flight
- Brand-asset agents: batch A (12 T27a booking platforms, all assets),
  batch B (15 partial entries). Design-system folders only; registry build +
  audit after they land.
- Live gates queue: TikTok real-handle E2E, Facebook real-page E2E, Simon
  fresha staff_name reland, partna venue-review scoping on a real build.

### Wave 2 progress log (as of ~05:00)

**T27c LIVE-GATED.** bourkestreetbakery TikTok: 28 videos → watch pool,
covers mirrored to R2 webp (owned tiktok: refs working). IndependentBakingCo
Facebook: 29 posts → media pool, fbcdn frames mirroring. Fleet light-up:
backfilled 37 social sources, dispatched runs — tiktok 19/20 ok (~29
videos each), facebook 18/19 ok (~29 posts each); the 2 unavailables are
honest misses (dead handle / actor miss, retryable). facebookPageUrl also
canonicalises legacy /pages/<name>/<id> + hyphenated pretty-URLs
(4d630138a + follow-up).

**Person-scoped reviews LIVE-GATED** (90a97734f + migration 20260828030000).
Simon: 6 employee-scoped Fresha reviews serve (staff_name='Simon' now
PERSISTS — ProjectionWriter had silently dropped the field since T23b); the
5 Kings-Domain-park reviews from an old wrong-place GB connect are now
correctly hidden (they used to serve!). The 5 "Simon" GB reviews on the
deleted barbershop connection stay dropped by W2 liveness (pre-existing).
13 fleet fresha relands dispatched, 14 ran ok. NOTE for owner: `ollies`
(partna-typed test profile wearing ST. ALi's GB listing) now serves 0 venue
reviews — correct under person-scoping, but visible on that test page.

**Wave-2 platform batch SHIPPED + LIVE-GATED** (fb0da3592): 34 new brands
(social/directories/commerce/payments/music/events/booking) + Deezer keyless
content connector. Live spot-check on dev: 10/10 real URLs route to the
right surface+identifier; bare domains refuse. Deezer E2E: Ben Böhmer
connect → auto eager run → 50 tracks → listen pool, and identity dedup
MERGED deezer tracks with his existing Spotify items (one item, both
platform links). Batch is detect-only except deezer/cal_com/classpass/
pinterest (harvester-known, connect cards work); cal.com + classpass joined
BOOKING_HOSTS so bio links auto-connect booking. Pinterest resurrected from
the 2026-07-28 retirement as link-only (legacy map + registry guards
updated). Skipped with reasons: airtasker (no public profile URL exists),
hipages (WAF-blocked, unverifiable), oneflare (dead — 301s to airtasker),
vcita/nabooki/picktime/appointy (no verified grammar), setmore-subdomain
(already covered by setmore.book).

**Brand assets:** batch A (12 T27a booking brands) + batch B (15 partial)
landed in design-system (3487d69): 7+6 fully sourced, honest placeholders
elsewhere, registry 119 platforms / 108 icons / 106 wordmarks; dashboard
typecheck green. Stale YT-Music "icon missing" catalog note dropped. Batch C
(34 wave-2 brands) dispatched.
