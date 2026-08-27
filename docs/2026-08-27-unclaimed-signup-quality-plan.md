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
- **T12 — Acceptance rebuild round**: fresh signups (simondoylehair,
  st-ali + one new partna) after all fixes; full window.py sweep, Nightwatch
  diff, live-site checks; results logged here. Gate: zero regressions, all
  fixed behaviours observed live.
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
