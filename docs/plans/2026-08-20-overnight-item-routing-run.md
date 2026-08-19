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

## Ledger (tick with the real commit hash)

- [ ] T1 — Enter-key race / error toast
- [ ] T2 — Links sheet step-1 guidance
- [ ] T3 — non-Links pools refuse unknown platforms (server + sheets)
- [ ] T4 — store-suggestion diagnosis + fix (natalieanne.com repro)
- [ ] T5 — suggestion band UI matches connection sheet
- [ ] T6 — scan lanes: media items
- [ ] T6b — media catalog surfaces stop placing item urls as accounts (spotify episode repro)
- [ ] T7 — scan-seeded products connect their store
- [ ] T8 — one routing brain everywhere: every add lane (Links included) + every scan lane (audit + coverage matrix)
- [ ] T9 — every cap/budget raised 2–3x (owner permission recorded in the task)
- [ ] T9b — item → parent account suggestions (store pattern for media; easy set first)
- [ ] T10 — live E2E loop: 3 Instagrams + Shopify breadth + natalieannehair reconnect + scanner-vs-reality audit + platform integrity, until clean (WHOLE-RUN GATE)
- [ ] T11 — backstop gates + final critic pass + report

### Found-issues ledger (standing rule — append here during the run, then fix)

- (F1… — one line each, file ref, then ticked when fixed+tested)

## Owner additions (queue below as they come — plan is open until "handoff")

- (pending — owner is scoping more items)
