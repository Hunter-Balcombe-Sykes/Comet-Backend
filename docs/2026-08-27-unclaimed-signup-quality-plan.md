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

## INHERITED claims — not yet re-verified on the rebuilds

- **Menu `titleCase()` artifacts** ("Cold Brew/oat Latte Can", trailing
  periods, acronym mangling) — served on build 1's live page; recheck build 2,
  then fix in `NormalizesMenuData::titleCase()` with the real strings as tests.
- **Display name parsing** — "SIMON DOYLE | Barber & Educator" verbatim from
  Instagram `fullName`; "St Ali Coffee Roasters." trailing period from
  `PreAccountBuildService` fallback. Wanted: "Simon Doyle". Fix in
  `PersonNameParser` + both call sites. Recheck what build 2 produced.
- **Fresha service-name casing** at ingest (store Title Case once, at write).
- **Menu provenance** — build 1 attributed all menu items to `manual`
  (priority 200) with the `uber_eats` ingest source never run. Build 2:
  `content.items` has exactly 86 menu_items (= UE count) — lane question
  still open.
- **OCR junk items** (ingredient-lines-as-names) — NOT reproduced in
  `content.items` on build 2 (no long-name items), BUT the scan applied
  `added: 10, updated: 0` — zero cross-source matches this time (build 1
  matched and enriched). Where did the 10 go, are they duplicates of UE
  items under different names, and did junk land in the legacy `menus` lane?
  → next investigation round.

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

## Open owner questions

- (none currently — new ones get added here as work raises them)

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
