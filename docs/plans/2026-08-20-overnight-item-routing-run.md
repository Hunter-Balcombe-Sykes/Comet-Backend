# Overnight run: item routing hardening + scan lanes (2026-08-20)

**HANDOFF DOCUMENT.** This plan will be executed in a FRESH session with no
prior context — everything needed is in here. The owner starts it with
"handoff" + this file. Owner-granted authority below is standing for the
whole run.

## Authority & run method (owner grant, 2026-08-20 — overrides everything)

- **Full permission**: build, commit, merge, push, deploy to dev, run
  remote commands, use/modify test accounts. There are NO live users — every
  account is a test account; nothing can break for a real person.
- **Spend is unconstrained tonight** (owner, 2026-08-20): Apify runs,
  Places calls, AI extraction — spend whatever the testing needs, budgets
  do not matter for this run. (T9 raises the coded caps; if a cap still
  blocks a test, raise it further and note it.)
- **Standing rule — found issues become tasks**: any issue discovered
  along the way, related or unrelated, is APPENDED to this plan's ledger
  as a todo (Fnn — one line, file ref) and then FIXED during the run,
  with the same gates as every task. Nothing gets noted-and-dropped.
- **Task gates are DURING-run gates; T10's Instagram accounts are the
  gate for the WHOLE run.** No task ticks without its own gate; the run
  doesn't end without T10 clean.
- **Use the full toolbelt** (owner, 2026-08-20): the supabase MCP for
  direct DB inspection/queries, laravel-boost (tinker/schema/routes) for
  backend introspection, the nightwatch MCP for exception/issue triage,
  `cloud` CLI for dev logs + remote commands (`cloud env:logs` for logs,
  never boost's log tools), the built-in Browser pane for UI and for the
  T10 scanner-vs-reality browsing. Pick the best tool, not the habitual
  one.
- **Agents, two distinct uses — both stand**: (1) the MANDATORY Sonnet
  critic reviews and gates per task are unchanged and non-negotiable;
  (2) additionally, Sonnet or Haiku subagents MAY be used for pure
  grunt work where quality genuinely does not matter — bulk searching,
  file location, log sifting, inventory sweeps. Never for building,
  designing, diagnosing, or anything whose output ships; that stays
  inline in the main session.
- **Localhost, already running**: the previous session started the
  dashboard dev server on :3000 (launch config `dashboard`) so the owner
  could log in before bed — TAKE IT OVER, don't double-start
  (`preview_start {name: "dashboard"}` reuses it). The local backend
  (launch config `comet-backend`, :8000) may be started the same way
  whenever local iteration is faster.
- **This section OVERRIDES the giant-run skill and any other operating
  instructions** where they conflict. The existing skills don't cover this
  work; this plan defines its own method:
  - **Inline main-session work** for all building — we have all night, no
    parallel implementation fleets.
  - **Critic agents ARE required** (an explicit exception to giant-run's
    no-subagents rule): after each task's build+tests pass, dispatch a
    Sonnet critic agent (Agent tool, model sonnet) with the task's diff,
    its acceptance gates, and the instruction to try to REFUTE the work —
    wrong behaviour, missed edge, stale comment, contract drift. Findings
    are fixed before the task ticks. A task may not tick on the builder's
    own say-so.
  - **Harsh gates, every task**: typecheck + lint + targeted tests before
    every commit; the FULL backend suite (`php artisan test --parallel`)
    before every merge to development; real-page verification for anything
    touching readers/classifiers (fetch real URLs, not just fixtures);
    remote verification on dev after every deploy (cloud command:run
    tinker); browser-pane verification for dashboard UI changes (measure,
    don't eyeball; auth-gated flows verified at the API layer + a logged
    test account if one is available in the session).
  - **Checkpoint discipline**: commit per task with reasoning in the
    message; tick the ledger below with the real hash in the same breath;
    after two failed correction attempts on one issue, write down what was
    learned and restate the approach before continuing.
  - Repos: Comet-Backend (feature branch → merge development → auto-deploys
    dev-api) and partna-monorepo (work on main, push deploys Vercel).
    `git fetch` + pull before starting and before every merge.

## Context (for the fresh session)

The last two days built "Eventbrite-grade" item/account routing: a pasted
item URL becomes a real pool ITEM (events 2026-08-19, watch/listen media
2026-08-20), an account URL gets a connect hint, and the add sheets show
step-1 guidance bands from `PastedLinkClassifier` (pure URL grammar, no
fetch) via `POST /content/links/classify`. Key files:

- `app/Services/Platforms/MediaPageReader.php` — media grammar + oEmbed/OG reader
- `app/Services/Platforms/EventPageReader.php` — events reader (schema.org)
- `app/Services/Content/PastedLinkClassifier.php` — the step-1 grammar
- `app/Http/Controllers/Api/Content/PoolItemCreateController.php` — pool add
  lanes (shop STORE-FIRST, events EVENT-FIRST, media ITEM-FIRST); 201 carries
  `addedItemId`
- Dashboard: `components/blocks/pool-add-sheet.tsx` (step-1 debounced
  guidance band, Continue disabled while shown),
  `components/blocks/link-add-sheet.tsx` (band on step 2 off the preview's
  `classification`), `lib/queries/content-pools.ts` (classifyLink, types)
- Scan lanes: `LinkRouter` + `LinkInBioImporter` → `EventsSeeder` (events
  only so far), `CommerceProbeJob` → `StoreBrandSeeder` /
  `ShopProductSeeder`; `WebsiteLinkHarvester::classify()` is the scan-side
  grammar (returns real brand keys for events since 2026-08-19)

Owner test account: handle `gsnwilliams` (vintageboutiquedarwin@gmail.com)
on dev. Backend deploys from `development` to Laravel Cloud dev
(`cloud deployment:list env-a0ca75cb-dd45-40ac-8e8e-557cd6f11467`); remote
commands via `cloud command:run <env-id> --cmd="php artisan …"`.

## Tasks

### T1 — Kill the surviving error toast (Enter-key race)

Pool add sheets: the guidance band disables the Continue BUTTON, but the
URL field's Enter handler calls `create.run()` with only a host check
(`pool-add-sheet.tsx`, onKeyDown), and a fast paste-and-enter also beats
the 350ms classify debounce. Fix: Enter respects the same disabled
condition, AND `create` itself awaits/uses the latest classification (run
classify inline before posting when no tagged answer exists for the
current URL) so no race path reaches the 422. Gate: pasting a Spotify
track into Watch and hitting Enter immediately shows the band, never the
toast.

### T2 — Links sheet guidance moves to step 1 (parity with pool sheets)

Today Links only learns the classification from the preview response, so
the band shows on step 2. Give the Links sheet the same debounced
`classifyLink` call on the URL field as the pool sheets, band in step 1
(keep the step-2 band as reinforcement or drop it — match whichever reads
cleaner). Advisory on Links (Add stays enabled).

### T3 — Non-Links pools refuse unknown-platform URLs (owner rule, 2026-08-20)

"No events or listen items for random foreign links." Rule: every pool
EXCEPT custom_links accepts a URL only when it belongs to a platform the
system knows (the catalog's ~100+ brands / the harvester's host tables /
ItemLinkRules hosts) AND that platform is relevant to the pool. Everything
else is refused with a hand-off: 422 + step-1 band "we don't recognise
this as a {noun} — add it to Links instead" WITH the button (client), and
the server enforces it (the sheet band is UX, the 422 is the contract).

Design decisions to make during the task (record in the commit):
- Events loses host-agnostic JSON-LD adds for unknown hosts (the venue's
  own site case) — blocked per the owner rule; known events brands keep
  working. Meetup counts as known (classify names it).
- Shop/Sell keeps its own STORE-FIRST behaviour (its reader already reads
  any product page — owner has NOT asked to restrict Sell; confirm before
  changing it, default = leave Sell as is).
- The known-platform test lives server-side in ONE place (extend
  `PastedLinkClassifier` or a sibling — the classifier already answers
  step 1, so the sheet band and the 422 read the same source).

### T4 — Diagnose + fix: a Shopify store URL gets NO store suggestion anywhere

Reproduction: `natalieanne.com` (a Shopify store) pasted into Links — no
"connect as store" suggestion; pasted into Listen — was silently added as
a bare item (T3 fixes the add; this task fixes the missing suggestion).

Diagnosis CONFIRMED on dev (routing.link_observations, gsnwilliams,
2026-08-19 15:19 UTC — the natalieannehair Instagram bio scan):
1. **Scan side**: the importer spends its ONE commerce probe per host on
   whichever URL it reaches first — it probed
   `/pages/natalie-anne-education`, that read answered
   `probe_unreachable`, and the HOMEPAGE (which identifies Shopify
   instantly — the paste lane connected this exact store at 15:49) was
   never probed. Fix: probe the host ROOT/origin, not the first deep URL,
   and fall back to the origin when a deep-page probe is unreachable.
   Also investigate why GenericShopScraper found the education page
   unreachable while LinkCardScraper fetched the same site fine seconds
   later (different fetch posture?).
2. **Sheet side** (as scoped): the pool lane never probes, and
   `PastedLinkClassifier` is pure grammar with no storefront knowledge.
   Fix:
- Links preview already fetches the page (`LinkCardScraper`) — detect
  storefront markers from the HTML already in hand (Shopify/Woo/BigCartel
  signatures; `ShopProviderDetector` has the knowledge) and return a
  `connectAsStore` classification on the preview.
- Pool sheets (no fetch in step 1): at minimum the T3 refusal band; if the
  URL's host is a KNOWN store platform host, say so ("this looks like a
  store — connect it on the Sell page").
- Gate: natalieanne.com pasted into Links shows the store suggestion with
  a connect action; pasted into Listen shows the refusal band; both
  verified in the browser pane against dev.

### T5 — Suggestion-band UI matches the connection sheet's pattern

The step-1 bands currently use the generic `Banner` from `ui/alert`. The
connection sheet has its own established suggestion/inbox presentation —
find it (`connection-sheet.tsx`, the routing suggestions inbox on the
Platforms page) and restyle the add-sheet bands to the same visual
language (designing-partna-ui skill governs judgement). One shared
component if the shapes genuinely converge; no fork if they don't.

### T6 — Scan lanes: media items (the agreed next run, folded in)

Extend the 2026-08-20 media-paste work to the scan lanes, mirroring how
events did it on 2026-08-19:
- `WebsiteLinkHarvester::classify()` gains item answers for media —
  a `content-item` category carrying platform + kind (video/track/episode)
  from the same grammar `MediaPageReader::classifyItem` holds (share, don't
  copy).
- A `MediaSeeder` twin of `EventsSeeder`: reads through `MediaPageReader`,
  writes the manual pool item (same canonical-URL folding), never
  resurrects a removed item, tags origin (`link_in_bio` etc.).
- Wire into `LinkRouter` + `LinkInBioImporter` match arms (both already
  pass classify's answer through — the events arms show the shape).
- Per-run cap (events uses 10) so one Linktree can't flood the Watch pool.
- Scan-found media lands IN THE LIBRARY, NOT auto-pinned (differs from
  events; flag to owner in the report if this reads wrong in practice).
- Gate: a fixture bio page with a channel link + 3 video links yields one
  connection/suggestion + 3 real video items (not link cards), idempotent
  on re-scan, removed items stay removed.

### T6b — Media catalog surfaces stop placing ITEM urls as accounts

CONFIRMED live repro (gsnwilliams, 2026-08-19 15:19 UTC): the
natalieannehair bio's `open.spotify.com/episode/6AZW9ZuDZrI3f7MTFnh4j9`
link AUTO-CONNECTED as a "Spotify" platform at confidence 99 — resource_id
= the episode id, payload just {url, source} (no name, so the UI shows
"open.spotify.com"). Cause: `spotify.player`'s detector deliberately
accepts ALL SEVEN entity kinds (artist|album|playlist|track|show|episode|
user) as a connection — a pre-pools "embed any Spotify URL" design. The
same Eventbrite-/e/ class of bug the events work fixed in the catalog.

- Spotify: the detector narrows to the ACCOUNT kinds (artist|show|user —
  playlist is an owner call, note the decision); track/album/episode
  become reservedPaths-style item shapes that fall through to the T6
  media seeder (Listen items). Existing wrongly-placed connections on dev:
  find and convert/remove (one-off command, counts in the report).
- AUDIT every Content-class surface's detectors for item-shaped paths
  that can place a connection (youtube-music watch URLs, soundcloud track
  paths, apple-music song/album, vimeo video ids, twitch vods, mixcloud
  shows…) — the grammar in MediaPageReader::classifyItem is the reference
  for what counts as an item. Add the coverage to T8's matrix.
- Gate: re-run the natalieannehair bio scan on dev — the episode link
  yields a Listen item (or a card until T6 lands, never a connection),
  and no bogus platform row is created.

### T7 — Scan-seeded products auto-connect their store (the known gap)

`ConnectStoreFromProductJob` is dispatched ONLY from `ProductPageAdder`
(pool paste). `ShopProductSeeder` (the scan lanes' product seeding via
`CommerceProbeJob::probe`) seeds the product item and stops — a scanned
Shopify product link never connects its store. Run the same
origin-detection + connect dispatch from the scan seeder (tombstone-safe:
never resurrect a disconnected store — `StoreBrandSeeder`'s reconciler
path already owns that; do NOT hand-roll a second tombstone check).
Gate: scan fixture with a Shopify product URL → product item + store
connection/suggestion per policy; a store the user disconnected stays
disconnected.

### T8 — One routing brain EVERYWHERE — every add lane AND every scan lane (owner, 2026-08-20)

The full determination stack — catalog projection (platform account →
connect/suggest with bands, slots, tombstones), item grammars (video /
track / episode / event / product → pool item), store detection (storefront
→ store platform + brand) — must apply to EVERY URL on EVERY surface a
URL can enter through. No lane gets a partial brain: nothing may shortcut
to a link card when a richer determination exists.

**Scan lanes (where it matters most):**
- Audit every lane and trace what each URL type actually gets:
  `LinkInBioImporter` (bio + previous-website), `InstagramAutoSync`,
  `GoogleBusinessAutoSync` / `WebsiteLinkHarvester::harvest`,
  `PreviousWebsiteGate`, `LinkRouter` (the engine lanes 2–3 call), and
  `CommerceProbeJob`. T6/T7 add the media and store-connect arms; this
  task is the COVERAGE guarantee across lanes — find and close any lane
  that bypasses part of the chain (e.g. a lane that consults classify()
  but never the item seeders, or probes commerce but never events/media).
  A video link in a scan lands as a WATCH item; a product link lands as a
  product item AND its store connects (T7); a storefront lands as a store
  platform; an event page lands as an event item — in every lane alike.

**Add lanes (every pool's add sheet, Links included):**
- Every `POST /content/pools/{pool}/items` entry point runs the same
  determination, not just the pools that happen to have a bespoke arm
  today: a product URL pasted into LINKS gets the product/store
  determination (suggest "add as product / connect store", per T4), a
  video gets the Watch hand-off (T2 band + button), an event page the
  Events hand-off — and the T3 refusal covers what none of the grammars
  claim on the non-Links pools. The Links pool remains the one place a
  plain card is always allowed, but never SILENTLY when a richer home
  exists: the suggestion must show.
- The routing paste lane (RoutingController / connect surfaces) is
  audited to the same standard — it already probes; confirm its arms
  match the pool lanes' after T6/T6b/T7.

**Coverage matrix test** as the gate: a fixture set of URL types
(platform profile, video, track, episode, event page, organiser page,
product page, storefront, unknown host) × EVERY entry surface — each scan
lane, each pool add endpoint (links included), the routing paste —
asserting the exact outcome per cell (connection or suggestion / pool
item of the right kind / store platform + brand / link card ONLY where a
card is the right answer). The matrix is the pinned contract for every
future lane and platform.

Order note: run AFTER T6+T6b+T7 land (they supply the arms this audits).

### T9 — Every cap and budget raised 2–3x (EXPLICIT OWNER PERMISSION, 2026-08-20)

Owner grant, verbatim intent: "for every cap / budget etc make them way
less conservative — I give explicit permission to increase them by a
decent amount, double and triple what they are now. Much rather we waste
more or take longer than it hits a limit and doesn't work." This task IS
that permission — no further confirmation needed during the run.

Target values (2–3x current; runner may land anywhere in that band, and
records the final number per knob in the commit):

Scan/import:
- LinkInBioImporter::MAX_LINKS 100 → 300; MAX_PAGES 20 → 50;
  MAX_PROBES 6 → 18 (T4 also makes the per-host probe smarter — root-first
  + unreachable fallback; keep 1-per-host DEDUP but the budget stops
  starving multi-host bios)
- WebsiteImporter::MAX_LINKS 200 → 500
- WebsiteLinkHarvester extractLinks 500 → 1000
- ScanPreviousWebsiteContentJob::MAX_PDF_SCANS 5 → 12
- GoogleMenuPhotoScanJob::MAX_OCR_CALLS 12 → 30

Per-user content caps:
- ManualEventWriter::MAX_STANDALONE_EVENTS 10 → 30 (mirror copy in the
  controller/pool 422s reads the const — no second number)
- EventsSeeder::MAX_ACCOUNTS 5 → 10; catalog multiAccount(5) → 10 across
  the events/content surfaces (one sweep, note in CatalogSync)
- ProductPageAdder::MAX_INDIVIDUAL_PRODUCTS 20 → 50
- ShopController::MAX_BRANDS + ConnectStoreFromProductJob::MAX_BRANDS
  5 → 10 (keep the two in lockstep — same number, same commit)
- partna.platform_links_max 7 → 15 (frontend mirrors this constant —
  update the dashboard mirror in the SAME push)
- streaming max_live_check_per_site 5 → 10

Paid budgets (config/partna.php `limits.*` — env-tunable defaults; bump
the DEFAULTS so dev/prod inherit without env work):
- apify.global_daily_cap 1000 → 3000; instagram 200 → 600;
  menu 300 → 900; google-business 300 → 900; music actors 50 → 150 each
- ai_spend.global 500 → 1500; mistral_ocr/deepseek_structure 300 → 900
- places.global 500 → 1500; per_user 60 → 180; details 200 → 600;
  photos 400 → 1200
- T6's new media-scan per-run cap: set generous from birth (30, not 10)

Fetch/time budgets (raise, but RESPECT the pinned invariants — raise the
PAIRS together or the suite fails by design):
- http_fetch.timeout_seconds 8 → 15; connect_timeout 3 → 6;
  max_redirects 5 → 8; max_bytes 10MB → 25MB
- connect_budget_seconds 20 → 45
- refresh_budget_seconds 90 → 100 AND RefreshConnectionJob $timeout
  120 → 150 (RefreshBudgetInvariantTest pins budget < job timeout)
- apify run_sync_timeout 110 → 125 ONLY with the calling jobs' timeouts
  raised in step (HorizonQueueCoverageTest pins those bounds) — if the
  arithmetic gets delicate, leave this one and note it
- fetch_many pool 6 → 12; ytimg pool 10 → 20; places pool 5 → 8

Route throttles: previews/classify 60/120 per min → 180/300; leave the
public-site/leads/bot throttles ALONE (abuse surface, not a product cap).

Rules for the task:
- Every knob change lands with its test updated in the same commit (many
  are pinned); grep for each old literal so no stale mirror survives
  (dashboard mirrors of platform_links_max and MAX_INDIVIDUAL_PRODUCTS
  especially).
- Do NOT touch SSRF/security bounds (denied_host_suffixes, bot tokens,
  moderation throttles) — those are not "limits", they're the fence.
- Report lists every knob: old → new.

### T9b — Item → parent account suggestions (the store pattern, for media)

The Sell lane already does this: paste a product, the product's STORE
auto-connects (`ConnectStoreFromProductJob`). Same shape for media items
(owner, 2026-08-20 — scoped as suggest-first, nothing set in stone):

- **Easy set (no extra requests)**: the oEmbed we already fetch for the
  item carries `author_url` — YouTube (channel URL), Vimeo, SoundCloud,
  Mixcloud; Bandcamp's artist IS the subdomain; Twitch clips carry the
  channel in the path. When an item lands (paste lane AND scan lanes),
  derive the parent account and — if that platform isn't already
  connected for that account — file it through the EXISTING platform
  suggestions inbox. Never auto-connect from a scan (bios are full of
  other people's content); the paste lane MAY auto-connect like the store
  lane does, but default to suggest and flag the auto-connect upgrade as
  an owner decision in the report.
- **Moderate set (needs a page/JSON read — do only if time allows)**:
  Spotify track→artist, Apple Music song→artist, Tidal, Twitch VODs. Skip
  cleanly if not reached; note in report.
- Tombstone-safe: a platform the user disconnected is never re-suggested
  from its own items (the suggestion lane's existing rules apply — reuse,
  don't re-implement).
- Gate: paste a YouTube video on a test account with no YouTube connected
  → video item + a channel suggestion in the inbox naming the right
  channel; same via a scan fixture; disconnected platform stays silent.

### T10 — Live E2E validation loop: three real Instagrams, until clean (owner, 2026-08-20)

At the END of the run (after T1–T9 are live on dev), validate the WHOLE
scan stack against real accounts — the exact method used for the
natalieannehair diagnosis (routing.link_observations + Supabase queries +
Laravel tinker + dev logs), but as a fix-and-repeat LOOP:

1. Connect each test Instagram on a test account, let the auto scan lanes
   run to completion:
   - https://www.instagram.com/the_046_official/
   - https://www.instagram.com/barber.milo.le/
   - https://www.instagram.com/by.albertkim/
2. Trace EVERYTHING that happened per account: every bio link observed,
   its verdict, what it became (connection / suggestion / pool item of
   the right kind / store / link card), what was missed, what was wrong.
   This covers ALL scan behaviour — platforms, socials, booking,
   stores, menus, findings — not just the new item lanes.
3. Fix every issue found (real fixes with tests, not patches), deploy,
   RESET the test state (disconnect/remove what the bad pass created),
   and re-run the same account until it comes through perfectly clean.
   Only then move to the next account.
4. **Shopify (and store) breadth**: beyond the Instagrams, test several
   DIFFERENT Shopify stores (and at least one WooCommerce/other) through
   both lanes — pasted product, pasted storefront, storefront found in a
   scan — asserting product items + store connect/suggestion per policy.
5. **natalieannehair reconnect must come through clean**: reconnect it
   (any test user) and verify the full corrected outcome — store
   suggested/connected, the Spotify episode NEVER a platform (T6b), the
   YouTube link a video item (T6), no junk link cards for things with a
   richer home.

Standing freedoms for this task (owner grant): use ANY Supabase user or
the account already logged in on localhost in the built-in browser;
add/remove items and platforms freely on test accounts; run the LOCAL
backend (keys are configured) when that's faster for iteration — but the
FINAL clean pass per account must be verified against DEV, since dev is
what the dashboard talks to. Paid-scrape spend for these connects is
expected and approved (T9 raised the budgets).

6. **Scanner-vs-reality audit (owner add)**: for each account's link-in-
   bio page (Linktree or whatever they use) and any linked websites,
   OPEN the page yourself in the built-in browser and inventory what is
   ACTUALLY there — every link, store, video, booking button, social —
   then diff it against what the scanner extracted. Everything the
   scanner could have got but missed is a found-issue (standing rule:
   append to ledger, fix, re-run). This is explicitly a WORK item, not
   just a check: improve the bio/website scanners' extraction where the
   diff shows gaps.
7. **Platform-integrity check (owner add)**: for every platform row the
   scans created, verify it is a REAL account of a REAL platform, done
   right — correct resource identity, a name (not a bare URL), the right
   surface — so nothing like the spotify-episode-as-platform bug ships
   again. Any bogus row is a found-issue: fix the cause, purge the row,
   re-run.

Exit gate: all three Instagrams + the natalieannehair reconnect each have
a documented clean pass (the trace table per account goes in the report),
scanner-vs-reality diffs are empty or every gap is a fixed-and-retested
issue, every platform row passes the integrity check, and every fix made
inside this loop has its own committed test. T10 clean is the gate for
the WHOLE run.

### T11 — Full-night backstop gates (run at the end, before the report)

- Full backend suite green; dashboard typecheck/lint clean.
- Deploy both; remote-verify each task's gate on dev (tinker battery +
  real URLs; the T4 reproduction re-run live).
- Fresh dev-log scan (10 min window) after the last deploy.
- A final Sonnet critic pass over the WHOLE run diff (both repos) with
  this plan as its brief: anything unticked, half-done, or contradicting a
  gate goes back to work or into the report as an honest open item.
- Outcome-first report: what shipped, what was verified and HOW, what's
  open. Delete this plan file per convention only if every task ticked;
  otherwise leave it with the ledger updated.

## Execution refinement (run session, 2026-08-20 — superpowers:writing-plans pass)

The plan above is the owner contract and stands unchanged. This section pins
what the executing session verified and decided at run start: exact paths,
dependency order, and interfaces between tasks — so no task starts on a
guessed filename and the order respects what each task consumes.

### Execution order & batching (dependency-driven)

1. **Batch A — dashboard sheets (monorepo, main):** T1 then T2 — same two
   files (`pool-add-sheet.tsx`, `link-add-sheet.tsx`), one typecheck+lint
   gate, one critic pass over both, commit + push. Browser verification on
   **app.partna.au** (owner logged a session into the built-in browser tab;
   localhost:3000 belongs to another chat's server AND fails account
   bootstrap — F1 below).
2. **Batch B — T3 then T4.** T3's known-platform test lands server-side in
   `PastedLinkClassifier` (one source for the sheet band AND the 422), so
   T4's sheet-side band reuses T3's shape. T4's scan-side probe fix
   (`LinkInBioImporter` root-first probing) is independent and may land
   first if T3 drags.
3. **T5** (dashboard-only restyle, `components/blocks/overlays/connection-sheet.tsx`
   is the reference) — after T1–T4 so every band it restyles exists.
4. **Batch C — backend scan arms:** T6 (harvester media grammar +
   MediaSeeder) → T6b (catalog Definitions narrowed; its audit list feeds
   T8's matrix) → T7 (ShopProductSeeder → ConnectStoreFromProductJob).
5. **T8** after Batch C (it audits the arms C supplies). The coverage
   matrix test is T8's deliverable and becomes the pinned contract.
6. **T9** (caps; mechanical but touches many pinned tests) after T8 so the
   matrix pins behaviour before knobs move. **T9b** after T6 (it consumes
   MediaPageReader's oEmbed author_url plumbing).
7. **T10** fix-and-repeat loop (whole-run gate) → **T11** backstop + report.

### Pinned paths (all verified to exist on disk, 2026-08-20)

Backend (Comet-Backend, branch `feat/overnight-item-routing-2026-08-20`):
`app/Services/Platforms/{MediaPageReader,EventPageReader,LinkRouter,EventsSeeder,ShopProductSeeder,WebsiteLinkHarvester,ShopProviderDetector,InstagramAutoSync,GoogleBusinessAutoSync,PreviousWebsiteGate,LinkCardScraper,GenericShopScraper}.php`,
`app/Services/Content/{PastedLinkClassifier,ManualEventWriter}.php`,
`app/Services/Brand/StoreBrandSeeder.php`, `app/Services/Shop/ProductPageAdder.php`,
`app/Routing/Importers/{LinkInBioImporter,WebsiteImporter}.php`,
`app/Jobs/Platforms/{CommerceProbeJob,ConnectStoreFromProductJob,ScanPreviousWebsiteContentJob,GoogleMenuPhotoScanJob,RefreshConnectionJob}.php`,
`app/Http/Controllers/Api/Content/PoolItemCreateController.php`,
`app/Http/Controllers/Api/Routing/RoutingController.php`,
`app/Http/Controllers/Api/Platforms/ShopController.php`, `config/partna.php`.
Platform detectors are DATA in `app/Catalog/Definitions/*.php`
(Spotify = `Definitions/Spotify.php`) compiled via `app/Catalog/*` —
T6b's sweep is that directory, not a service class.

Dashboard (partna-monorepo, main): `components/blocks/pool-add-sheet.tsx`,
`components/blocks/link-add-sheet.tsx`,
`components/blocks/overlays/connection-sheet.tsx`,
`lib/queries/content-pools.ts` (classifyLink + `LinkClassification`
{belongsTo: {pool, kind, pageLabel} | null, account: string | null}).

### Interfaces the tasks share

- `LinkClassification` is the wire contract for every band (T1–T4): any T3
  server extension (e.g. a refusal/`unknown` answer) EXTENDS this type in
  `content-pools.ts` in the same push — both sheets read it.
- T3's known-platform authority = `PastedLinkClassifier` (or a sibling it
  owns); `PoolItemCreateController` enforces the 422 from the SAME source.
- T6's media grammar = `MediaPageReader::classifyItem` shared into
  `WebsiteLinkHarvester::classify()` (share, don't copy); `MediaSeeder`
  mirrors `EventsSeeder`'s shape (canonical-URL folding, tombstones,
  origin tag, per-run cap — T9 sets it to 30).
- T7 reuses `ConnectStoreFromProductJob` exactly as `ProductPageAdder`
  dispatches it; tombstone logic stays in `StoreBrandSeeder`'s reconciler.

### Decisions taken at execution time (recorded as made)

- **T1:** Enter shares the button's `submitBlocked` condition; `create`
  settles the classification INLINE (tagged answer for the exact URL →
  else inline `classifyLink` → silent return when a band applies, band
  tagged so it shows). Classify failure stays non-blocking; the server 422
  remains the backstop.
- **T2:** step-1 band added with the same 350ms debounced classify +
  tagged-answer staleness; step-2 band KEPT as reinforcement — StepCard
  bodies render only while `active`, so the two can never co-display.
  Cross-pool "Add to X" action shared between both bands.

## Ledger (tick with the real commit hash)

- [x] T1 — Enter-key race / error toast — monorepo `2fc118e`; critic
  blocker (success-tick on cancelled add) fixed; gate verified LIVE on
  app.partna.au: spotify track in Watch → band, Continue disabled, Enter
  inert, zero toasts
- [x] T2 — Links sheet step-1 guidance — same commit; verified LIVE:
  step-1 band + Add-to-Listen action, Continue stays enabled (advisory)
- [x] T3 — non-Links pools refuse unknown platforms — backend `fd6d8fa18`
  (claims() in PastedLinkClassifier, controller gate, events host-agnostic
  add closed, social-profile answers with strict shape check) + monorepo
  `53e91bd` (refusal band + Add-to-Links action); critic blockers (media
  pool dead end, reel-as-profile) fixed same commit
- [x] T4 — store suggestion everywhere — same commits. Root cause
  CONFIRMED: the bio's education link 404s for every UA (dead link, no
  fetch-posture bug); CommerceProbeJob asks the ORIGIN on unreachable;
  preview carries storefront markers (StorefrontMarkers); classifier
  answers store for store-platform hosts; both sheets show Connect-store
  bands wired to routeLink
- [x] T5 — suggestion band UI matches the suggestions inbox's row grammar
  (the Platforms page's "Found on your platforms" list — the established
  presentation) — monorepo `3eabb76`, SuggestionBanner shared by both
  sheets, verified LIVE on app.partna.au (screenshot: Item row, kind
  glyph, solid commit button, Continue disabled below)
- [x] T6 — scan lanes: media items — backend `89df37f64` + critic fixes in
  `08ab05389` (F2 both halves + order-independence test). Gate test green:
  channel + 3 videos → 1 connection + 3 REAL video items, canonical
  folding, library-only, origin-tagged, idempotent, tombstone-safe
- [x] T6b — item URLs never place connections — same commits. Spotify
  detector → artist|playlist|show|user (playlist KEPT, owner-flagged);
  SoundCloud detector → single-segment profile; SpotifyConnect +
  SoundcloudConnect (the manual door) narrowed the same way (critic);
  `platforms:convert-media-item-connections` ready for the dev sweep
- [x] T7 — scan-seeded products connect their store — `14e381bf8`, via
  StoreBrandSeeder (policy-owned tombstone, pinned); NOT
  ConnectStoreFromProductJob (no tombstone check — paste-only)
- [x] T8 — one routing brain everywhere — audit (10-gap trace table),
  paste-lane item arms (`0c16ff999`), WebsiteImporter revived + item arms
  wired into the previous-website scan, Links product suggestion,
  RoutingCoverageMatrixTest as the pinned contract; critic blockers fixed
  in `db630910f` (importer AFTER the rich seed — the false-conflict-bell
  race; scheme-less pastes; paste observation + FetchBudget); remaining
  architecture gaps documented above as P8 debt
- [x] T9 — every cap/budget raised 2–3x — `08ab05389`, full knob table
  (old → new, every one) in the commit message; 12 pinned-test collisions
  re-pinned with the caps still binding; pairs raised together
  (connect_budget 45 WITH job timeouts 75; refresh 100 WITH 150; apify
  125 under the callers' bounds); probe daily caps raised as the T4
  fallback mitigation; SSRF/abuse throttles untouched; full suite green
- [x] T9b — item → parent suggestions (easy set: oEmbed author_url +
  Bandcamp subdomain + Twitch clip path) — `be85bb856`; suggest-only on
  EVERY lane incl. paste (auto-connect upgrade = owner decision, see
  report); critic blocker fixed in `db630910f` (a DERIVED parent never
  inherits paste-directness — dismissed suggestions stay dismissed,
  pinned). Moderate set (Spotify/Apple/Tidal) deliberately not reached.
- [x] T10 — live E2E loop, CLEAN: the_046_official (3 passes; surfaced
  F6/F7/F9), barber.milo.le (surfaced F10/F11), by.albertkim
  (clean-per-state: Fresha held as suggestion because broken-oven is
  business+food — gateAllows denies booking auto-connect by design;
  fresha auto-connected fine on the non-food user). Shopify/store
  breadth: pasted product → item + store auto-connect (natalieanne),
  pasted storefronts → suggestions incl. WooCommerce
  (barefootbuttons.com, choose woocommerce.store), scan storefronts →
  named connections (The 046 Store, 4BARBERS Supply). natalieannehair
  reconnect: episode NEVER a platform, youtube link a video item +
  parent-channel suggestion (T9b live), store connected + named via T4
  origin fallback, and after F12 her TikTok/Facebook/Instagram all
  observed + placed — scanner-vs-reality diff EMPTY. Platform
  integrity: every scan-created row swept — real accounts, correct
  resource identities, named (apple_music/youtube name = latest
  release BY DESIGN of their fetch strategies; dvlpmnttv backfilled
  via F9's ConnectFetchJob path, refresh ok; fresha names live at
  selection.storeName; relational store rows carry names in
  content.storefronts+collections). No bogus rows.
- [x] T11 — backstop gates: full backend suite green on every merge
  (final runs 8600+ passing, exit 0); dashboard typecheck 0 errors,
  lint 0 errors (38 pre-existing warnings); final deploys live on both
  rails (backend `84ffb915d` on Laravel Cloud dev, monorepo `48e65fa`
  READY on Vercel production); fresh 10-min post-deploy log scan — 0
  errors, one pre-existing slow_public_profile telemetry warning;
  final whole-run Sonnet critic over both repos' full diff — its two
  findings became F13 (its BLOCKER escalation on the summary screen
  included) and F14, both fixed with pins, re-gated, merged, deployed;
  F14 re-verified LIVE (accepting the @agencypodcast T9b suggestion
  enriched + named the row in seconds). F13's UI path note: no tile
  offered to the test account reaches the generic-router arm (roster
  is capability-filtered), so the fix is typecheck/critic-verified
  defensive hardening — the flow could not be driven in the browser
  on this account, and equally the original lie could not fire there.

### T8 audit — remaining gaps DOCUMENTED as open items (architecture debt,
### not tonight's scope; full trace table in the run report)

- CommerceProbeJob's `event`/`event-organiser` match arms are dead code —
  no dispatcher ever passes those categories (every call site passes none
  or 'shop'). Harmless; remove in P8.
- Organiser pages have two writers with two id schemes: catalog paste →
  SourceReconciler generic row; scans → EventsSeeder `acct-*` rows. Same
  brand, two shapes — P8 consolidation.
- A pasted `x.myshopify.com` root places via the catalog's generic
  connection write, not StoreBrandSeeder/ShopContentWriter — whether the
  generic ingest-source path syncs the catalogue equivalently is
  unverified; T10's Shopify breadth step exercises it live.
- ProductPageAdder::writeIndividual and ShopProductSeeder::seed are two
  implementations of the same individual-bucket merge (caps read one
  const, so they can't drift on the number) — P8 consolidation.
- The note-arm (classify → item/event seeding) now exists in THREE places
  (LinkRouter, LinkInBioImporter, WebsiteImporter) — flagged for one
  shared service in P8; tonight the three are line-for-line parallel.
- GoogleBusinessAutoSync's harvestHtml socials bucket never sees the media
  arm (dormant for media keys — seedSocials only acts on fb/tiktok/x/
  linkedin/instagram); the NEW WebsiteImporter wiring covers the same
  page's media/event links properly.

### Found-issues ledger (standing rule — append here during the run, then fix)

- [x] F2 — LinkRouter consumed the per-platform slot for ITEM categories
  (`routeClassified`, handled:true → seenPlatforms): the 2nd event/video
  from one platform in a run degraded to a bare card. Items aren't
  connections. Fixed in T6's commit (slot skip for event/content-item).
- [x] F3 — InstagramAutoSync surfaced `custom(handled:true)` routes as
  unmatched custom-link suggestions (`InstagramAutoSync.php` ~163),
  duplicating seeded events (and ordering Swap offers) as link cards —
  contradicts RouteResult's documented handled contract. Fixed in T6's
  commit (`&& ! $result->handled`).
- [x] F6 — LinkRouter's 'link' arm CARDED catalog-connectable brands
  (the_046's Apple Music artist → "The 046 on Apple Music" card) while
  every Engine-1 lane connected them. The arm asks Engine 1 first now;
  marketplaces (no catalog surfaces) stay cards, pinned. `badcee370`.
- [x] F7 — shopify.store (+6 sibling store surfaces) had NO multiAccount
  in the catalog → Engine-1 store placements capped at ONE store while
  every other door allows ten (the046.com blocked on cap_reached beside a
  single store). All store surfaces ride multiAccount(10) now, pinned
  per-surface; golden master re-pinned for gumroad's /accounts route.
  `badcee370`.
- F8 — WITHDRAWN: the "missing" YouTube bio link was a CONFLICT finding
  against broken-oven's existing different-channel YouTube row (correct
  Swap behaviour); the trace query's LIMIT had hidden the rows.
- [x] F9 — reconciler-applied apple_music.artist row was a NAMELESS
  URL-keyed account on the Platforms wire (id=<url>, name=null — the
  integrity-check failure class). Detectors capture the numeric id now +
  applyIntent dispatches ConnectFetchJob for content-class surfaces
  (booking deliberately excluded — the claimed-user picker rule caught
  the first cut). F9 commit on the branch.
- [x] F10 — CustomLinkSeeder::MAX_LINKS (20) starved scan-miss cards on a
  full Links pool (milo pass: urstudio.com.au + oneshot.behindthechair.com
  dropped at 21 cards). 20 → 50 under the T9 grant (a knob the sweep
  missed — the plan's list named platform_links_max, a different cap);
  fixtures re-pinned, F10 commit on the branch.
- [x] F5 — the sheets' Connect-store toast said "couldn't connect" while
  the queued probe was about to file the suggestion (verified live: the
  intent landed 3s after the click). Copy now says what happens
  (monorepo, F5 commit).
- [x] F4 — EventsSeeder ignored `routing.item_tombstones` entirely: a
  deleted event card came back on every rescan. Same contract as
  MediaSeeder now — scan origins respect tombstones, an explicit paste
  (origin 'paste') wins them; `tombstonedThisRun` counted so suppression
  is visible in the run tally. Fixed in T6's commit, pinned.
- [x] F11 — two lanes carding slash variants of ONE bio link
  ('…com.au' from the probe's miss path, '…com.au/' from the unroll)
  wrote TWO cards: normalizeUrl() doesn't fold trailing slashes.
  seedCustom() canonicalizes via IriCanonicalizer now (best-effort — an
  uncanonicalisable URL keeps its normalized form). `ba7a970d5`; the
  stray milo dup was removed by hand as hygiene before the fix landed.
- [x] F12 — natalieannehair's own TikTok and Facebook produced NO
  observations from her stan.store scan. TWO-part fix, and the first
  live re-run corrected the diagnosis: (1) the importer merges
  SOCIAL-classified shell anchors into an API unroll (`3aa4ab2e9`,
  critic pass `57d03cf92` gated it on the API arm answering + pinned
  the merge as additive) — but the re-run still missed them, because
  stan's RAW shell ships no social anchors at all; the browser
  inventory had seen the HYDRATED DOM. (2) The Stan API carries them
  at `user.data.socials` in mixed shapes (facebook: full URL;
  tiktok/instagram: bare handles) — stanStore() now emits them: URLs
  pass through, only live-confirmed handle keys expand (a wrong guess
  would MINT a URL), mail_to refused by collect()'s http test
  (`f0df9a884`; second critic pass `c36daf886` — no @-only mints, no
  silent non-JSON 200s). The anchor merge stays as insurance for a
  future server-rendering API host. VERIFIED by dev re-run 21:32Z:
  place tiktok.profile + facebook.profile + instagram.profile — tiktok
  and facebook became connections with real handle identities, the
  instagram place folded into her existing row (no dup), tiles
  unchanged. Scanner-vs-reality diff for stan.store/Natalieanne: EMPTY.
- VERIFIED-NOT-A-BUG — natalieannehair run tally said `suggested: 0`
  while a `choose youtube.channel` observation (@agencypodcast) landed
  in the same second. That observation is MediaParentSuggester's (T9b:
  the seeded video's PARENT channel — a URL that never appeared on the
  stan page), written with the scan's origin as source. The importer
  tally counts the importer's own route verdicts; the parent suggestion
  is the seeder's product. Accounting is honest; no change.
- [x] F13 — (whole-run critic) connection-sheet arm 4 said "Successfully
  connected" for EVERY non-null routeLink outcome: a video pasted under
  a marketplace tile became a content ITEM on the backend while the
  sheet reported a platform connection that never happened (same lie,
  pre-existing, for 'link' and 'review'). The done screen now names the
  real destination, with explainers for item/review. Monorepo F13
  commit; state reset on every startConnect.
- [x] F14 — (whole-run critic) F9's enrichment fetch covered only the
  reconciler's AUTO-place path, but T9b is suggest-only BY DESIGN — so
  every connection its parent-suggestion feature produces is born in
  SuggestionApplier::apply() via accept, and landed as the exact
  nameless URL-as-account row F9 exists to prevent (self-healing at the
  next scheduled refresh, but wrong on arrival). apply() now dispatches
  ConnectFetchJob afterCommit under F9's rule verbatim: content class
  only, fetch-capable surfaces only, new rows only. Two pins. F14
  commit on the branch.
- [x] F1 — localhost:3000 "We couldn't load your account": NOT a bug.
  `apps/dashboard/.env.local` deliberately points
  NEXT_PUBLIC_API_BASE_URL at http://localhost:8000 (switched 2026-08-18;
  the backup beside it still shows dev-api.partna.au) and the LOCAL
  backend wasn't running — the bootstrap had no API to load the account
  from. Fixed by starting the `comet-backend` launch config (:8000,
  health 200); localhost login will bootstrap now. No code change needed.

## Owner additions (queue below as they come — plan is open until "handoff")

- (pending — owner is scoping more items)
