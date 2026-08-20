# Scan refinement + real-account testing — handoff for a new session

**This is a self-contained handoff.** Read it top to bottom before starting.
It is written to be run in a FRESH chat with no memory of tonight's earlier
overnight run (item-routing plan, F1–F14) — that run is DONE, merged,
deployed, and not this plan's concern except as background: the scanners,
importers, seeders and pools it touched are exactly what this plan tests and
keeps refining.

## Authority & run method (owner grant, 2026-08-20 — overrides everything)

This section is the owner's standing grant for the WHOLE run. It overrides
any other operating instruction, skill, or doctrine where they conflict —
including this repo's own CLAUDE.md gates, giant-run's default posture, and
any skill's suggested workflow. Do not defer to a skill's prescribed process
if it would slow this down or add a gate the owner hasn't asked for; work out
HOW to run this yourself.

- **Full permission**: build, commit, merge, push, deploy to dev, run remote
  commands, create/wipe/modify TEST accounts freely. "Any test account in
  backend" — not just gsnwilliams — use whichever is convenient, including
  creating new ones. **Real/live user accounts remain out of scope** —
  nothing in the owner's ask touches that boundary; this grant is about
  removing friction on test-account work, not about live data.
- **Unconstrained spend** on Apify, Google Places, and AI calls for this run.
- **Inline main-session building** for the actual code changes — no parallel
  implementation fleets writing product code.
- **MANDATORY Sonnet critic agents** per fix: every fix gets a fresh-eyes
  Sonnet agent dispatched with the diff + the gates below + an explicit
  instruction to REFUTE, not rubber-stamp. Findings get fixed before the fix
  is considered done.
- **Sonnet/Haiku agents freely for grunt work** — bulk fetches, mechanical
  reads, gathering a real-page inventory at scale, running scripted DB
  queries, collecting trace data. Never for the judgment calls: whether a
  scan result is good enough, what "best case" looks like for a given
  platform, whether a fix is correct. Those stay with the main session or a
  Sonnet critic — never delegated to a grunt-tier agent.
- **Any issue found along the way — related or not — gets appended to this
  plan's found-issues ledger (below) and FIXED during the run**, same
  standing rule as last night's overnight run. Don't defer, don't just note
  it.
- **Harsh gates, every fix**: typecheck + lint + targeted tests per commit;
  full `php artisan test --parallel` before every merge to `development`;
  dashboard `npm run typecheck` + `npm run lint` clean before any monorepo
  push; a fresh dev-log scan after each deploy; real-page browser
  verification for every scan-quality claim — never claim a scan gap is
  fixed without re-driving the actual connect and comparing against the live
  source again, in the browser, yourself.
- This is real-world refinement work, not a one-shot bugfix pass: **loop.**
  Fix, redeploy, wipe the test account, reconnect, re-diff. Repeat per
  platform until the diff against reality is empty or every remaining gap is
  a deliberate, recorded product decision — not a bug you're deferring.

## Context — why this run exists

Last night's run (see `2026-08-20-overnight-item-routing-run.md`, same repo)
hardened the item-routing pipeline and found real gaps (F1–F14) by tracing
what real accounts' scans actually produced. This run is that same method,
generalised and scaled: connect real platforms to empty test accounts, go
look at the real source yourself, diff it against what the system captured,
and fix every gap — not just for link-in-bio scans, but for every scan lane:
Instagram auto-sync, Google Business, previous-website scraping, commerce
probes, pools.

**Related, separate plan**: `2026-08-20-connect-only-platform-status.md` (same
`docs/plans/` dir) scopes a different fix — the permanent "syncing"/false
"attention" badge on connect-only social platforms (TikTok, Facebook, etc.).
It's parked on two open product decisions the owner hasn't made yet (see its
"Open questions" section) — do NOT fold it into this run or decide those
questions yourself under this grant; it's genuinely separate work awaiting
the owner. Only touch it if the owner explicitly says to include it.

## Task 1 — Store auto-select-5-products-on-connect (small, do first)

**Spec:** when an online store connects (any provider — Shopify, WooCommerce,
etc., via any lane: dedicated connect OR a scan-suggested store the user
accepts) and the user currently has **no products selected/curated** for
that store's Sell pool, auto-select up to **5 of the store's most recent
products** (ordered by the store's own creation/publish date, not our
discovery order) — **once, at the point of connection.** Never re-run this
automatically after the first time; a user who deliberately cleared their
selection down to zero later should not have it silently repopulated.

**Known starting points** (confirmed live against the dev schema this
session — verify current before building, don't trust this blindly):
- `content.storefronts` carries a `products_curated_at` column — this is
  almost certainly the "has the owner done their initial curation" marker
  and the natural gate for "no products selected yet."
- Products themselves are very likely `content.items` rows (`kind` enum
  includes at least media/link/video/episode/release seen this session —
  confirm whether `product` is a kind, or whether products live somewhere
  else entirely). The exact storage of "which products are SELECTED for the
  Sell pool" was not confirmed this session — this is the first thing to
  discover, not assume.
- **F14 lesson, applies directly here**: a store can be connected through
  MORE THAN ONE lane (dedicated connect sheet vs. a scan-suggested store the
  user accepts via the suggestions inbox — same `SuggestionApplier`/
  `SourceReconciler` split that F14 fixed for enrichment). Whatever hooks
  this in must fire from BOTH lanes, or you'll reproduce exactly the class of
  bug F14 was. Check both write sites before calling this done.

**Gates**: real tests (a fresh store connect with zero products selected
auto-picks ≤5, most-recent-first; a store connect where products are ALREADY
selected does nothing; a second connect/reconnect doesn't re-trigger it);
Sonnet critic pass; verified live on a real store connect (dev).

### Task 1 implementation design (added at run start, 2026-08-20 evening —
### grounded in a full code scan; verify line numbers before editing)

Confirmed mechanics:
- "Selected for Sell" = `site.section_items` rows `state='pinned'` on the
  `pool:shop` section (`PoolRegistry::sectionKey('shop')`,
  `PoolSectionProvisioner::ensure`). No pivot/flag anywhere else.
- Products = `content.items` `kind='product'`; store↔product =
  `content.collection_items(collection_id,item_id,position)` where
  **position is already newest-first catalogue order**
  (`ShopContentWriter::syncStore`, `SectionCandidates.php:456`). Pin in
  position order — same source `ProvisionShopPinsCommand` and the
  grandfather migration used.
- **Do NOT stamp `products_curated_at`** — it means "user hand-picked" and
  suppresses scheduled `ShopFetch` sync (`ShopFetch.php:85`). Add a new
  nullable `content.storefronts.products_autoselected_at` column as the
  once-only marker; it needs the same COALESCE treatment in
  `ShopContentWriter::upsertStore()`'s ON CONFLICT arm (42702 lesson,
  `tests/Postgres/ShopStorefrontUpsertConflictTest.php`) + a `StoreRecord`
  field + `tests/Schema/ContentStorefrontsConstraintsTest.php` update.
- Gate: `products_autoselected_at IS NULL` AND `products_curated_at IS
  NULL` AND zero pinned rows for this store's items on `pool:shop`. A user
  who cleared selection later is protected by the marker, not the count.
- **Hook, lane (a)**: `ShopBrandConnectJob.php:215-226` right after the
  initial catalogue fill (`ShopCatalog::syncLatest`), before the logo job.
  Structural model: `MenuFetchJob::seedPins()` (`MenuFetchJob.php:826-860`)
  — seed-only, append after max sort_key, best-effort try/catch.
- **Hook, lane (b)** (scan-suggested store accepted): the accept path
  bypasses `SuggestionApplier` — `SuggestionsController::accept():225` →
  `CommerceProbeJob` → `StoreBrandSeeder::seed()` finishing at
  `StoreBrandSeeder.php:181-187`. Lane (b) **never fills the catalogue
  today** (found issue — ledger) — dispatch `ShopBrandConnectJob` (or an
  equivalent fill + auto-select) from the placed outcome so both lanes
  converge on one code path. Also lane (b) leaves `auto_sync_latest`
  absent=ON while dedicated connects write it false
  (`ShopConnections.php:126-128`) — align lane (b) to false as part of
  this, so the 5 pins are the single publish mechanism (record as a
  deliberate decision in the ledger).
- Tests to model on: `ConnectStoreFromProductJobTest`,
  `ShopAsyncConnectTest`, `StoreBrandSeederTest`,
  `ShopPinProvisioningTest` (+ helpers `makeShopStore`,
  `makeShopStoreProduct`, `poolItem(...$publishedAt)`).

## Task 1.5 — Known-issue fix block (owner live-testing findings, FI-1..FI-5)

The owner connected instagram.com/sammy.pdf/ to gsnwilliams and observed
four failures. All were root-caused at run start via code scan + live
inspection of the real pages. Fix ALL of these BEFORE starting the
Instagram block so all seven accounts run against fixed code. Each fix:
tests + Sonnet critic + gates, per the Authority section.

### Ground truth for sammy.pdf (captured live 2026-08-20 evening)

Instagram bio ("linktr.ee/samakhurst and 1 more"):
1. `https://linktr.ee/samakhurst`
2. `https://open.spotify.com/track/5WOkoJzd6nDzKJXlVgVU5q` — Spotify
   TRACK "Open Your Eyes (And Dance)" (behind l.instagram.com redirector)

Linktree `linktr.ee/samakhurst` — 6 profile links, 3 of which are Linktree
"music embed" types that do NOT render as `<a>` anchors (they live only in
the page's `__NEXT_DATA__` JSON, `props.pageProps.links[]`):
1. SPOTIFY_SONG embed — `open.spotify.com/track/5WOkoJzd6nDzKJXlVgVU5q`
   ("Open Your Eyes (And Dance)")
2. SOUNDCLOUD_SONG embed — `soundcloud.com/sam-akhurst/are-you-the-one-...`
   ("Are You The One (Sam Akhurst Remix)")
3. SPOTIFY_ARTIST — `open.spotify.com/artist/4WoNQlu21ftnkouDsSUtmS`
4. CLASSIC — `music.apple.com/au/artist/sam-akhurst/1810969283` (artist)
5. CLASSIC — `on.soundcloud.com/fh433tMk6lU9xgP3TM` — short link, expands
   (verified via curl) to `soundcloud.com/sam-akhurst` = the ARTIST profile
6. INSTAGRAM_PROFILE — `instagram.com/ssml.wav` — a SECOND Instagram

**Expected best-case outcome after fixes** (T8's pass criteria):
- Connections: Instagram (sammy.pdf), Spotify artist, Apple Music artist,
  SoundCloud artist (via short-link expansion)
- Suggestion (NOT a connection): Instagram @ssml.wav — single-account
  platform already occupied → suggestions inbox at top of Platforms
- Listen items: the Spotify track + the SoundCloud track
- Custom links: none of the above mis-filed

### FI-1 — Socials are single-account, everywhere (owner decree)

Owner: "for instagram say its only ever up to 1 account connected so thats
for all social; if scan lanes detect another while one is connected it's
added as suggested at top."

Root cause of the violation: **engine divergence.** Legacy Engine 2
(`InstagramAutoSync`→`LinkRouter`) already enforces 1-per-social via
`RouteContext::$seenPlatforms` + `resolveSocialLink()` conflict findings.
But Engine 1 (`LinkInBioImporter`/`WebsiteImporter`/paste) reads the
catalog, where ALL 12 socials declare `->multiAccount(5)`
(`app/Catalog/Definitions/Instagram.php:40`, Facebook:51, X:42, Tiktok:39,
Threads:38, Linkedin:41, Snapchat:35, Reddit:40, Telegram:41, Discord:44,
Kick:41, Medium:41) — so a harvest auto-creates a 2nd..5th connection
(`SourceReconciler::applyIntent`). That's exactly how @ssml.wav became a
second Instagram connection.

Fix: remove `multiAccount(5)` from all 12 social definitions (builder
default = 1). `SourceReconciler::capReached()` then Holds the 2nd account
with `block_reason='cap_reached'` **and records
`conflicting_connection_id`** (only happens when max<=1,
`SourceReconciler.php:108-110`) → the suggestions inbox renders it with
Swap — precisely the owner's requested behaviour, no new UI needed.
Dashboard `lib/data/connect.ts` / `PLATFORM_RULES` already say instagram
single — sweep all socials to match. Tests: second-social-account harvest
→ Hold+Swap suggestion, never a second connection; same handle → alias
reuse (existing #R4) still green.

### FI-2 — Dashboard: one row per account, kill the "+N" badge

Root cause (confirmed): `apps/dashboard/lib/queries/platforms.ts:119-167`
`mapConnections()` groups by platform unconditionally, drops
`WireConnection.id`, and emits `accountCount` → rendered as "+N" by
`accountLabelFor` (`components/blocks/pools/platforms-page.tsx:147-152`).
`multiAccount` is never consulted; stores are the one precedent already
doing one-row-per-account (`store:{id}` keys, platforms-page.tsx:105-124).

Fix: emit one row per `WireConnection`, keyed `${platform}:${connection.id}`
(mirrors the store precedent; `getRowId` reads `row.key` so it just works);
delete `accountLabelFor`. **Critical detail**: `connectedKeys={rows.map(r =>
r.key)}` (platforms-page.tsx:390) feeds the connect sheet's `isExhausted`
check which expects bare platform keys — derive connectedKeys from each
row's platform, not the compound key, or single-account platforms stop
showing "Connected" in the roster. After FI-1, a single-account platform
with >1 wire rows is a data-integrity anomaly — don't badge it, surface it.
Gates: `npm run typecheck` + `npm run lint`; verify in browser on a
multi-account content platform (e.g. two Spotify artists) = two rows.

### FI-3 — Short-link expansion layer (the on.soundcloud.com case)

Root cause (confirmed): there is NO redirect expansion anywhere in routing.
`IriCanonicalizer.php:9-11` promises "shortener expansion is a runtime
concern layered above this by the observer" — **that layer was never
built**. Worse, `app/Catalog/Hosts.php:23` aliases `on.soundcloud.com` →
`soundcloud.com`, so a lowercase short code matches the profile detector
and mints a FAKE SoundCloud "account" (confidence 75 ≥ auto 70); the
mixed-case real-world shape (like `fh433tMk6lU9xgP3TM`) falls to
no-rule-matched → custom link card. Both outcomes wrong. `spotify.link`
is in no routing list at all.

Fix:
- New `app/Routing/ShortLinkExpander` service: for an allowlist of short
  hosts (the canonicalizer's SHORTENERS minus aggregators, plus
  `on.soundcloud.com`, `spotify.link`, `spoti.fi`), follow redirects via
  `SafeUrlFetcher` (existing SSRF-safe fetcher that already returns
  `finalUrl`, `SafeUrlFetcher.php:207-241`), capped redirects + tight
  timeout, cache expansions (table or cache store) so re-scans don't
  re-fetch, then feed the FINAL URL back through canonicalize→route.
- Call it from `LinkRoutingService::route()` (and `preview()`) when the
  canonicalizer rejects with reason 'shortener' or host is on the list —
  and from the Engine 2 classify path so the Instagram lane gets it too.
- **Remove the `on.soundcloud.com` alias from Hosts.php** — expansion
  replaces it; the alias is the fake-profile bug.
- `linktr.ee` stays an aggregator at the entry point; a nested aggregator
  found INSIDE another aggregator currently silently drops
  (`handleUnrouted` 'shortener' reject) — make it a card instead
  (zero-loss principle), depth stays 1. Record as deliberate.
- Tests: on.soundcloud short → SoundCloud artist connection (mock the
  fetcher); unexpandable/timeout short link → card (never dropped, never a
  fake profile); expansion loop/depth cap; spotify.link → routed target.

### FI-4 — Track/song URLs must never become platform-account connections;
### derive the artist instead

Owner case: "connected an Instagram and it auto added an Apple Music
account but the link was actually a single track — add the item in Listen
and if possible add the actual artist account as the platform."

Code scan says the artist/item split mostly exists (item grammar wins in
both engines; catalog detectors are artist-shaped) — but confirmed holes:
- Locale-less Apple Music URLs (`music.apple.com/album/x/123?i=456`,
  `/song/...` without `/xx/` locale) are claimed by NEITHER the item
  grammar (requires locale, `MediaPageReader.php:189-202`) NOR the artist
  detector → fall to custom link. Make locale optional in both the item
  grammar and `accountPlatformLabel` (`:279-281`), matching the catalog
  detector which already accepts locale-less artist paths.
- The exact URL that produced the owner's bad connection is unknown (the
  reset wiped the trace): FIRST reproduce — connect sammy.pdf to
  gsnwilliams BEFORE fixing, capture `routing.link_observations` +
  `site.platform_connections` rows, identify which URL/lane minted it,
  then fix that specific hole too if it isn't one of the above.
- "Add the artist as the platform": `MediaSeeder::seedItem()` already
  calls `MediaParentSuggester::suggest()` (`MediaSeeder.php:156`) — verify
  it actually resolves a track's parent artist for Spotify/Apple
  Music/SoundCloud (may need oEmbed/API lookup); it must produce a
  SUGGESTION, not an auto-connection. Whatever it can't resolve, record.
- Tests: track URL via every lane (paste, bio, linktree, website) →
  Listen item + artist suggestion, zero platform connections; artist URL →
  connection as before.

### FI-5 — Linktree scraping misses embed links (Spotify/Apple Music case)

Root cause (confirmed live): `LinkInBioImporter::unroll()` uses the API
unroller only for linkin.bio/taplink.cc/stan.store; `linktr.ee` falls to
the **anchor harvest** — but Linktree renders music links as embed players
(SPOTIFY_SONG / SOUNDCLOUD_SONG / SPOTIFY_ARTIST types), which produce NO
anchors. Verified on linktr.ee/samakhurst: anchor scrape yields only 3 of
6 profile links; all 6 (with titles + types) sit in `__NEXT_DATA__` JSON
at `props.pageProps.links[]`.

Fix: add a Linktree arm to `LinkInBioApiUnroller` (or a sibling): fetch
the page HTML via the existing fetcher, parse the `__NEXT_DATA__` script
tag JSON, extract `props.pageProps.links[].url` (+ titles as link labels)
and any `socialLinks[]` array; fall back to anchor harvest on any parse
failure. Keep the same-host chrome rule (footer noise, F12 merge rule).
Tests: fixture of a real Linktree `__NEXT_DATA__` payload (embeds + classic
+ socials) → all profile links extracted, zero linktr.ee chrome links;
malformed JSON → anchor-harvest fallback.

### FI execution order

1. Reproduce first: reset gsnwilliams → connect sammy.pdf via the real
   dashboard flow → capture full trace (which URL, which lane, which
   detector minted what) → reset again. This confirms FI-4's unknown and
   gives the before-fixes baseline.
2. Fix FI-1 + FI-3 + FI-4 + FI-5 (backend, one commit each, tests +
   critic each), FI-2 (dashboard, own commit).
3. Deploy backend + dashboard; reconnect sammy.pdf; diff against the
   expected-outcome checklist above. Loop until it passes clean.
4. Then run the Instagram block (T2..T8) — sammy.pdf (T8) is the final
   clean-loop confirmation on a fresh reset.

## Task 2 — Real-account scan refinement loop (the bulk of this run)

### Method — repeat for EACH platform/account below

1. **Prepare the test account.** Confirm it's the right `account_type`
   (`partna` for the Instagram block, `business` for the Google Business
   block — gate on `AccountCapabilities`, never branch on `account_type`,
   per this repo's standing doctrine, but the ACCOUNT itself needs the right
   type to reach the workplace/Google Business flow at all).
2. **Wipe it clean.** `php artisan partna:reset-test-user <handle> --yes` on
   dev (same command used twice tonight for gsnwilliams), then also clear
   `analytics.site_visits` / `analytics.site_sessions` for that user_id
   directly via SQL — the command doesn't touch those, and last night's runs
   showed they accumulate from verification traffic. Confirm before/after
   counts, same as tonight.
3. **Connect the real platform** through the real flow (Instagram via the
   dashboard's Instagram connect, which drives Apify; Google Business via
   the dashboard's workplace search-and-pick, which drives Places). Let
   every downstream lane run to completion: bio-link scan, media mirror,
   commerce probes, item seeding, parent-suggestion. Same trace method as
   last night — `routing.link_observations`, `routing.import_runs`,
   `site.platform_connections`, `content.items`, all timestamped, via the
   Supabase dev project (`glncumufgaqcmqhzwrxm`).
4. **Go look at the real source yourself.** Open the actual Instagram
   profile / actual Google Business listing in the browser pane as a human
   would — bio link, highlights, story highlights, pinned/tagged posts,
   product tags, every visible field, every button. This step is NOT
   delegable to a grunt agent — the judgment of "what's actually there and
   would a user expect us to have captured it" is yours.
5. **Diff scanned-vs-reality, field by field.** Every gap — something the
   scanner could have gotten but didn't, or got wrong — is a found issue:
   append it to the ledger below.
6. **Fix it.** Real fix with tests, not a data patch — same rule as last
   night. Sonnet critic reviews the diff before it's considered done. Full
   gates from the Authority section above.
7. **Reset the account and reconnect from scratch** to prove the fix closes
   the gap on a clean run, not just on the already-polluted data.
8. **Loop** until the diff is empty or every remaining gap is a recorded,
   deliberate product decision (write down WHY it's deliberate in the
   ledger, same as F8's "withdrawn" entry last night).
9. Move to the next account.

Scope of "fix" is broad, per the owner: platforms, scan lanes, previous-
website scrapes, pools, classifiers, importers — anything in the capture
path. Fix errors in what WAS scanned too, not only gaps in what wasn't.

### Instagram block (partna-type test account)

Reuse **gsnwilliams** (`019f8dda-c144-73ca-9d6d-31db673e5c0d`, handle
`gsnwilliams`, account_type `partna`) — already wiped clean tonight, ready
to go. If it gets tangled mid-run, wipe it again (same command) rather than
switching accounts — a longer trace history on one account makes cross-run
comparison easier. Only switch/add accounts if genuinely useful.

Test each of these, one at a time, full loop per account:

1. https://www.instagram.com/samtobin__/
2. https://www.instagram.com/barber_in_law/
3. https://www.instagram.com/suzi_thestudiox/
4. https://www.instagram.com/hayleyj_thestudiox/
5. https://www.instagram.com/livplumbarber/
6. https://www.instagram.com/onefour_official/
7. https://www.instagram.com/sammy.pdf/

### Google Business block (business-type test account)

**Confirmed live this session**: `user-kvjm7i`
(`019f6e92-c5c2-733a-9236-7174f06bc97c`, account_type `business`) is
already empty — 0 connections, 0 items. Use this one; no need to create a
new account. (`broken-oven` also exists as a business-type account but is
NOT empty — 101 connections, 1791 items, clearly in active use elsewhere —
do not wipe it without checking with the owner first.)

The links below are Google Maps **share links** (`share.google/...`), not
something you can paste into the connect flow — the dashboard's Google
Business connect is a name-search-and-autocomplete picker (Places API), not
a URL field. **First step per link**: open it in the browser, read off the
actual business name (and suburb/address if the name alone is ambiguous),
THEN use that in the dashboard's search-and-pick flow. Note in the ledger if
any of these resolve to a business Places can't find, or to multiple
plausible matches — that's itself a finding worth recording, not a silent
skip.

1. https://share.google/R9VPJsY2VoRFeZ62Q
2. https://share.google/pUftI5XeflNMxqh0e
3. https://share.google/xylTpeD7ioG028w2e
4. https://share.google/1hkwuAmCP4csJQtHN
5. https://share.google/HCHwd4JQHCwVLdH82
6. https://share.google/hVh5P1PZvQX0461YO
7. https://share.google/tHxENiFeHNrpJnv0S

Connect each as a **workplace** (the business-type Partna flow — this repo's
platform-state doctrine: workplaces are how a `business` account attaches a
physical location, distinct from the `partna` account's plain platform
connections). For each, verify and record: does the workplace attach
cleanly; does the sitepage's workplace section render correctly (address,
hours, phone, map); does `AccountCapabilities` gate the section correctly
for a business-type account (never branch on `account_type` directly per
doctrine — if you find code that does, that's a found issue); does anything
about the connect flow behave differently or worse than the Instagram
block's connect flow, and if so, is that a genuine platform difference or a
gap.

## Found-issues ledger (append here as you go, standing rule)

Pre-seeded at run start (2026-08-20 evening) from owner live testing +
code scans. FI-1..FI-5 are specced in Task 1.5 above; L-1..L-5 are latent
issues the scans surfaced — fix during the run, same standing rule:

- **FI-1** Engine 1 auto-creates up to 5 connections per social platform
  (catalog `multiAccount(5)`) while Engine 2 enforces 1 — @ssml.wav became
  a second Instagram connection. → single-account socials everywhere.
- **FI-2** Dashboard groups all of a platform's connections into one row
  with a "+N" badge (`mapConnections`), instead of one row per account.
- **FI-3** No short-link expansion layer exists (canonicalizer docblock
  promises one); `on.soundcloud.com` short link became a custom link.
- **FI-4** Track/song URL became a music-platform "account" connection
  (owner-observed; exact URL/lane to be confirmed by reproduction).
- **FI-5** Linktree embed-type links (SPOTIFY_SONG etc.) are invisible to
  the anchor harvest — Spotify artist + both tracks missed on samakhurst.
- **L-1** `Hosts.php:23` alias `on.soundcloud.com`→`soundcloud.com` can
  mint a FAKE SoundCloud profile from a lowercase short code (detector
  matches the code as a username, confidence 75 ≥ auto 70).
- **L-2** Locale-less Apple Music album/song URLs claimed by neither the
  item grammar nor the artist detector → mis-filed as custom link. Same
  asymmetry: `accountPlatformLabel` requires locale, catalog detector
  doesn't.
- **L-3** `linktr.ee` is simultaneously an unrollable aggregator
  (LinkInBioDetector) and a rejected shortener (IriCanonicalizer) — a
  nested Linktree silently drops instead of becoming a card.
- **L-4** Scan-suggested store acceptance (lane b) never fills the
  catalogue — `ShopBrandConnectJob` is unreachable from `StoreBrandSeeder`,
  so an accepted store has zero products until the 6h `ShopFetch`.
- **L-5** Lane (b) leaves `auto_sync_latest` absent=ON while dedicated
  connects write false — suggestion-accepted stores silently auto-publish
  their newest product; dedicated ones don't. Align (Task 1 folds this in).

**T1.5a baseline trace (2026-08-20 08:33-08:35 UTC, sammy.pdf connected to
gsnwilliams via the real dev API flow — reproduction BEFORE fixes):**
- Note: the account was NOT clean — an 08:18 UTC natalieannehair connect
  (owner's own pre-handoff test) was present; sammy.pdf rows are cleanly
  timestamped ≥08:34 so the baseline still reads.
- Spotify track (bio link 2) → Listen item "Open Your Eyes (And Dance)" ✓
  (T6/T9 from last night working) — but NO spotify.artist parent
  suggestion was proposed → FI-4's suggest-the-artist half confirmed
  missing.
- `instagram.com/ssml.wav` (from linktree) → verdict PLACE, conf 75 →
  full second Instagram connection → **FI-1 reproduced live.**
- `on.soundcloud.com/fh433tMk6lU9xgP3TM` → note/no-rule-matched → "Sam
  Akhurst" custom link card → **FI-3 reproduced live** (mixed-case short
  code, so no fake-profile mint — matches L-1's analysis).
- Only 3 links observed from the linktree at all (apple music artist ✓
  placed, ssml.wav, on.soundcloud) — the 3 embed-type links (Spotify
  artist, Spotify track, SoundCloud track) never reached routing →
  **FI-5 reproduced live.**
- Apple Music artist placed correctly from the linktree — the owner's
  "Apple Music but the link was a track" report was almost certainly the
  SPOTIFY track tile pre-T6/T9 (the bio track), since the AM link is and
  was an artist URL; the bug class is covered by FI-4's grammar holes
  regardless.

## Task list (tick as you go)

- [x] T1 — auto-select up to 5 most-recent products on store connect
      (Task 1 above), when none are already selected, once (+ L-4/L-5) —
      committed + critic-hardened (transactional seed, engaged re-check,
      3rd sync lane, ShopFetch late hook); live store-connect verify rides
      the run's later store work
- [x] T1.5a — reproduced: trace in the ledger above (ssml.wav placed as a
      2nd IG, on.soundcloud carded, embeds invisible, no artist suggestion)
- [x] T1.5b — FI-1 single-account socials + alias-fold cap skip + legacy
      convergence migration (critic-passed)
- [x] T1.5c — FI-3 ShortLinkExpander both engines + budgeted preview +
      L-1 alias removals + L-3 shortener rejects card (critic-passed)
- [x] T1.5d — FI-4 track→item + artist derivation via Spotify embed page /
      Apple Music page + L-2 locale-less grammar
- [x] T1.5e — FI-5 Linktree __NEXT_DATA__ arm; full sammy.pdf replay test
- [x] T1.5f — FI-2 dashboard one-row-per-account, verified in browser
      (typecheck/lint clean, no '+N', per-account rows)
- [ ] T1.5g — LOCAL full-stack loop (local API+worker against dev DB, no
      deploy needed until merge). Round 1: FI-1/FI-4/FI-5 verified live;
      caught the expander 4KB body-cap throw (fixed) + FI-6 apple
      artistName (fixed). Round 2 in flight — diff must be fully clean.
- [ ] T2 — Instagram block: samtobin__
- [ ] T3 — Instagram block: barber_in_law
- [ ] T4 — Instagram block: suzi_thestudiox
- [ ] T5 — Instagram block: hayleyj_thestudiox
- [ ] T6 — Instagram block: livplumbarber
- [ ] T7 — Instagram block: onefour_official
- [ ] T8 — Instagram block: sammy.pdf
- [ ] T9 — Google Business block: all 7 workplaces on user-kvjm7i
- [ ] T10 — backstop: full backend suite green, dashboard typecheck/lint
      clean, both repos deployed, fresh post-deploy log scan, final
      whole-run Sonnet critic pass over the entire diff, outcome-first report

## Handoff — how to start

**State re-confirmed at run start (2026-08-20 evening, live dev SQL):**
gsnwilliams = 0 connections / 0 items / 0 link_observations / 0
site_visits; user-kvjm7i = 0 connections / 0 items / 0 site_visits. Both
clean and ready. The owner's sammy.pdf test data was wiped by the earlier
reset, hence T1.5a's reproduce-first step.

This file is complete and self-contained. In the fresh session: read this
whole file, confirm gsnwilliams and user-kvjm7i's current state (they may
have moved since this was written — re-check, don't assume), then start with
Task 1 (small, contained, fast feedback), then work the Instagram block
account-by-account, then the Google Business block. Tick tasks as they
close. Append every found issue to the ledger the moment you find it, fix it
before moving on — don't batch discoveries for later. Report outcome-first
when the whole list is done, same style as last night's final report.

- **FI-6** (found during FI-2 verification, 2026-08-20): the Apple Music
  connection row prints its raw numeric resource id ("1810969283") as the
  Account label — ConnectionDisplayName resolves no artist name for
  apple_music.artist connections and the id is only 10 digits so
  looksLikeResourceId misses it. Fix during the Instagram block loop:
  enrichment (or the connect write) should stamp payload.name with the
  artist name.

- **FI-7** (T1.5g round 2): a transient oEmbed failure on a link whose
  item ANOTHER lane had just seeded fell through to the card write —
  duplicate "Spotify – Web Player" card. Fixed: existing-item dedupe now
  runs before the page read in MediaSeeder.
