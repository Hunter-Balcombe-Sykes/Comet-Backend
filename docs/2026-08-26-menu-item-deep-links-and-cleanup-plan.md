# Plan: Square platform build-out + per-item ordering deep links + legacy teardown

Date: 2026-08-26 (v2 — expanded to one combined plan per owner direction).
Status: DRAFT — open decisions D1–D6 at the bottom still need sign-off;
structural decisions Q1–Q4 are RESOLVED (recorded below).
Companion research: `docs/2026-08-26-uber-item-deep-links-handoff.md` (Uber
proof — superseded by this plan for build details).

Owner direction (2026-08-26): one plan covering (1) delete ALL legacy found
in this research, (2) wire Square Online exactly like the CURRENT Uber Eats
setup — catalog-first, never the legacy registry path, (3) fix the platform
plumbing gaps (routing, harvester, custom domains), (4)+(5) storage
additions/removals for menu items.

## Resolved structural decisions (owner, 2026-08-26)

- **Q1 Square scope: FULL, including custom domains.** Catalog detector for
  square.site ordering URLs AND a new Square Online storefront marker so the
  commerce-probe path recognises custom-domain stores (order.fat-tuna.com).
- **Q2 square.site host clash: PATH-BASED SPLIT.** An ordering path on
  square.site detects `square.order`; bare square.site stays `square.book`.
  Mirrors Uber Eats' path-detector fix (see UberEats.php header war story).
- **Q3 PD-registry purge: ALL catalog-covered ordering entries** —
  square-ordering, bopple, hungrypanda, easi (each has a catalog definition).
  Per-entry verification required (availability meta, payload filters).
- **Q4 Menu content priority: Square stays FIRST** (merchant-canonical
  pricing/images; delivery apps carry markups).

## The architecture rule for everything below

**Current-truth wiring (copy this):** catalog surface with URL detector →
connect sheet lights the tile from `catalog.surfaces` where
`isConnectable && legacyPlatform` (connection-sheet.tsx:1172) → connection
row carries `surface_key` + `routing_class='ordering'` → `MenuSource`
selects by routing_class and re-derives the scraper platform from the URL
HOST (config('partna.menu.platforms') host_pattern) → `MenuFetchJob` scrapes
→ `MenuProjectionMapper` lands content.*. Menus deliberately stay on the
MenuFetchJob lane, NOT the ingest connector lane (owner ruling R8,
SourceProvisioner:203).

**Legacy (never extend, delete where found):** static PD entries in
`PlatformRegistryServiceProvider` for platforms the catalog now defines
(Uber Eats/DoorDash have NONE — that's the tell); the retired
'online-ordering' pseudo-platform; anything keyed off `site.menu_items` /
`site.menu_item_platforms` as if they were live tables (dropped slice 7 —
storage is content.*, `ManualMenuItems` folds back the wire shape).

---

# Part A — Square Online platform build-out

## A0. Research spikes

- **A0.1 Square ordering URL anatomy — RESOLVED (live probe, 2026-08-26,
  order.fat-tuna.com). Square needs NO Apify actor at all.** Square Online
  stores (Weebly-based) expose an unauthenticated structured JSON API:
  `https://cdn5.editmysite.com/app/store/api/v28/editor/users/{userId}/sites/{siteId}/store-locations/{locationId}/products?page=1&per_page=100&visibilities[]=visible&include=images,options,modifiers,category,categories`
  The three ids come from one fetch of the store page itself
  (`window.__BOOTSTRAP_STATE__` → siteData/storeInfo; also in the HTML for
  a non-JS fetch — verify exact extraction in build). Each product carries:
  Square catalog `id` (e.g. `IK2KD23G6W5PNGDDGD5BOGI7`), numeric
  `site_product_id`, **`absolute_site_link` — a ready-made per-item URL**
  (`https://order.fat-tuna.com/product/bowls/river-dancer/1`), name, price,
  description, images, categories, `in_stock`, options/modifiers. The
  `?item=<site_product_id>#<categoryId>` modal form also verified opening
  the item card in-browser (hash = category anchor, optional).
  **Consequences:** SquareMenuDriver becomes a first-party HTTP scraper
  (no Apify, no AI extraction, zero scrape billing for Square); the
  menus-r-us actor is a deletion casualty (Part C). The `MenuPlatformDriver`
  seam assumes an Apify run (buildInput/mapItems) — the registry needs a
  driver-lane field (actor vs http) so MenuApifyScraper dispatches Square to
  the HTTP fetcher. The old failed menus-r-us runs ("All discovered sources
  failed extraction" on square.site test URLs) stop mattering entirely.
- **A0.2 Square ordering PATH shapes.** Enumerate real Square Online
  ordering URL paths (`/s/order`, `/order`, others?) across a few live
  stores — feeds the Q2 path detector. Also confirm what `order.square.*`
  hosts exist (the frontend regex references `order.square`).
- **A0.3 Square Online storefront markers.** Fetch 2–3 custom-domain Square
  Online stores; identify stable HTML signatures (Square Online is
  Weebly-based — expect `editmysite.com` asset hosts / Square Online JS
  context objects). Feeds A3.

## A1. Catalog surface (the Uber Eats pattern)

In `app/Catalog/Definitions/Square.php`:
- `square.order`: remove `notConnectable()`; add detector(s):
  `Detector::url('square.site')->path(<ordering-path pattern from A0.2>)`
  with a strength that clears RoutingPolicy's ordering suggest bar (55) —
  compute the score the way UberEats.php documents (base 40 + path 35 +
  strength delta; verify against RoutingPolicy, don't copy blind). Add
  `order.square.*` host detector if A0.2 confirms it exists.
- `square.book` keeps bare `square.site` (path-less) — verify a
  square.site/order URL now outscores the book claim; add a regression test
  pinning both outcomes.
- Single-account (no `multiAccount()`) matching the Uber Eats owner ruling
  2026-08-16.
- Keep `legacyPlatform('square-ordering')` — that string is the derived
  descriptor slug (`DerivedDescriptorFactory`: square.order →
  'square-ordering') and what the connect sheet's `catalogConnect` set keys
  on. It is a wire identity, not a legacy dependency.

## A2. Connection → menu pipeline

- Verify (dev, live) that a catalog-placed square.order connection lands
  with `routing_class='ordering'` and that `MenuSource::platformOf`
  classifies the stored URL as `square` (the `^order\.`/square.site
  host_pattern in config/partna.php:966 predates this work — re-test it
  against real square.site hosts; square.site matches the FIRST alternative,
  order.* custom domains the third).
- Confirm `MenuFetchJob` runs the Square driver end-to-end on a real
  connected store and the merged menu lands in content.* with Square as the
  top-priority content source (Q4). ollies/fat-tuna on dev is the fixture.
- DoorDash-style locale: not needed (Square menus are location-independent —
  driver already documents this).

## A3. Custom-domain ingress (Q1 full scope)

- Add a Square Online signature to `StorefrontMarkers` (from A0.3).
- Wire the commerce-probe path: an unrecognized store-ish URL ("add as
  link" flow) that probes as Square Online should offer the square.order
  connect, the same way probed Shopify/WooCommerce stores surface as shop
  brands. Identify the exact seam in the probe → suggestion pipeline
  (CommerceProbeJob / SuggestionApplier) and land the ordering suggestion
  with `surface_key='square.order'` + the custom-domain URL.
- The menu registry host_pattern already matches `order.*` custom domains;
  for custom domains NOT starting with `order.` (rare but possible), the
  scrape registry needs a fallback — OPEN: D7 below.

## A4. Harvester

- Add Square Online to `WebsiteLinkHarvester::ORDERING_HOSTS` +
  `ORDERING_PLATFORM` (`'Square Online' => 'square.order'`): pattern for
  square.site ordering paths (host list is host-keyed — check whether the
  harvester can carry a path condition; if host-only, add square.site and
  let the catalog's path detector sort book-vs-order downstream, mirroring
  how the Bopple note handles catalog-vs-harvester divergence).

## A5. Frontend (dashboard)

- The tile already exists (`connect.ts:474`, key `square-ordering`). Once
  square.order is connectable, `catalogConnect` includes it — verify the
  tile goes live through the CATALOG seam, not the PD-registry availability
  fallback (which Part C deletes — test the tile still shows AFTER the PD
  entry is gone; that ordering matters).
- Update the tile's `match` regex + input hint to agree with A0.2's real
  path shapes and custom-domain reality (placeholder "your-store.square.site"
  stays a fine hint).
- Connect-sheet routing preview: a custom-domain Square URL will still show
  the "add as link → probe" message — that is CORRECT flow post-A3; verify
  the probe round-trip offers the connect.

---

# Part B — Per-item deep links + storage (all three platforms)

## B0. Research/verification spikes

- **B0.1 DoorDash — DONE, NEW RECIPE FOUND (2026-08-26, Don Tojo Carlton,
  clean sessions with storage cleared, verified with two different items):**
  the OLD `?event_type=item_click&item_id=` form is DEAD (regressed on
  DoorDash's side since the 2026-07-17 verification — no modal on a clean
  session). The CURRENT form is **`{storeUrl}?itemId={item_id}`**
  (camelCase), e.g.
  `https://www.doordash.com/store/don-tojo-carlton-27782934/32843359/?itemId=12046382620`
  → opens exactly that dish's modal. Item ids are STABLE (08-24 scrape ids
  match today's live Apollo cache). Notes: it opens a MODAL over the store,
  not a standalone product page — DoorDash web has no full-page item URL
  (no SEO item pages indexed; `/item/<id>` paths redirect away; item
  clicks don't change the URL). Uber remains the only full product-page
  form. Build must verify `?itemId=` also survives the redirect from the
  BARE store URL we persist (`/store/27782934`) — the old param did.
  Recipe fragility is now a known hazard (it silently changed once):
  consider a scheduled canary that loads one stored deep link headless and
  alerts on no-modal.
- **B0.2 Uber cost**: billed units, run `dTQULHB7Vbuhb1WNN` (flag on) vs
  `mCRmFlLmVvpJXteIH` (baseline). Material delta → revisit
  `PARTNA_MENU_APIFY_DAILY_CAP` (900) before enabling.
- **B0.3 Square items**: RESOLVED — see A0.1; per-item URL comes free from
  the first-party API (`absolute_site_link`), plus `in_stock` for D2.

## B1. Schema (supabase db push, own rail, lands first)

`content.offers` gains three nullable columns:
- `platform` text — ordering-platform slug on per-platform offers; NULL on
  the three aggregate offers. Kills ManualMenuItems' host-matching heuristic.
- `item_url` text — per-item deep link, mode-agnostic (D1). Only ever a
  REAL item URL (MenuItemDeepLinks contract); `url` stays the mode-typed
  store link.
- `external_ref` text — the platform's item id (itemUuid / item_id /
  Square id per A0.1). Generalizes dd_external_id; enables exact-id
  matching.
Sold-out: reuse existing `availability` (slice 5a `in_stock` spelling) — no
new column.

## B2. Drivers

- **Uber**: `buildInput` + `includeItemCustomizations: true`; `mapItems`
  fills `externalId` = itemUuid, adds `itemUrl`
  (`https://www.ubereats.com` + `href`, fallback compose
  `/{sectionUuid}/{subsectionUuid}/{itemUuid}` onto the store URL) and
  `soldOut` (from isSoldOut). Customization trees NOT persisted (D5).
- **DoorDash**: `itemUrl` = `{storeUrl}?itemId={item_id}` (B0.1's new
  recipe — one spelling, lives in the driver); `externalId` (item_id) →
  external_ref for matching.
- **Square**: NEW first-party HTTP driver (A0.1) — fetch store page for
  ids, hit the editmysite products API, map name/price/description/images/
  categories/in_stock/options; `externalId` = Square catalog id, `itemUrl` =
  `absolute_site_link`, `soldOut` = !in_stock. Registry gains a lane field
  so MenuApifyScraper dispatches Square to HTTP instead of an actor.
  `dietary_info`/`variants` (D4) become whatever the API exposes (options/
  modifiers) rather than the AI actor's guess.
- Update the normalized-shape doc in `MenuApifyScraper` (~line 27):
  `+ itemUrl, soldOut`.

## B3. Merge + projection

- `MenuMerger::platformEntry` carries `itemUrl` + `externalId` per platform
  row; the single `ddExternalId` passthrough becomes a per-platform id map.
- Matching becomes external-id-FIRST (both sides have ids), name-match
  fallback — implemented TOGETHER with backend-fixes item 2 (see
  Sequencing).
- `MenuProjectionMapper::offers()` writes `platform`, `item_url`,
  `external_ref`, `availability` on per-platform offers.
- `MenuFetchJob::platformRows` plumbs the fields.

## B4. Read side + wire

- `ManualMenuItems`: select the new columns; `platforms()` pairs by
  `offer.platform`; host-heuristic stays only as fallback for
  pre-migration rows and is DELETED after the next full scrape cycle
  (menus rebuild wholesale every scrape).
- `MenuItemDeepLinks`: rewrite to read stored `item_url`s keyed by platform
  (wire keys underscore-cased: uber_eats / doordash / square). Un-regresses
  DoorDash, lights Uber + Square in one change.
- `MenuPayloadComposer` (dashboard) + sitepage wire: per-dish `links[]`
  carries the item URLs. Frontend "ORDER ON" overlay: zero changes.

---

# Part C — Legacy teardown (nothing legacy survives this plan)

Ordered AFTER the replacement is live in each case.

1. **PD-registry ordering entries** (after A1/A5 verification): delete
   `square-ordering`, `bopple`, `hungrypanda`, `easi` registrations +
   HostMatches from `PlatformRegistryServiceProvider` (each has a catalog
   definition). Per-entry checklist: catalog surface connectable-or-
   deliberately-not, tile availability re-verified through the catalog seam,
   `IntegrationsMetaController` availability behaviour for the removed key,
   payload allowlists (`PublicIntegrationConnectionResource`,
   `DsarPayloadFilter`) keep working for EXISTING connections (rows keep
   platform='square-ordering' etc. — the derived descriptor supplies the
   slug; verify DerivedDescriptorFactory covers all four).
2. **`pickup_source` / `delivery_source`** — dead since slice 7 (always
   null via ManualMenuItems:114). Remove from MenuItem model + wire + docs.
3. **`dd_external_id`** — superseded by offers.external_ref. Remove model
   field, MenuMerger:541 passthrough, MenuPayloadComposer null-plumbing,
   ManualMenuItems carve-out. (Column itself lives in the dropped table —
   only code references remain.)
4. **MenuItemDeepLinks derivation path** — replaced in B4; DoorDash recipe
   knowledge moved to the driver in B2.
5. **Badges — KEEP AND FIX (D3 resolved: keep).** Not a deletion any more:
   diagnose why zero `tag_type='badge'` rows landed on dev, fix the
   projection, and verify a DoorDash badge flows scrape → content.item_tags
   → wire on a dev store.
6. **ManualMenuItems host-matching heuristic** — deleted after one full
   scrape cycle post-B3 (see B4).
7. **Doc sweep** — MenuItem/MenuItemPlatform docblocks rewritten as "wire
   shape only; storage is content.*"; MenuApifyScraper header; mark the
   Uber handoff doc superseded; update apps/dashboard connect.ts comments
   that still cite the PD-availability gate for ordering tiles.
8. **Frontend `connect.ts` fixture drift** — align the square-ordering
   entry (A5); remove any roster comments referencing the retired
   'online-ordering' pseudo-platform as if current.
9. **menus-r-us actor** — delete from `config/partna.php` menu registry
   once the HTTP Square driver is live (A0.1: the actor is obsolete — AI
   extraction, no ids, and it was failing on square.site URLs anyway).
   Remove its Apify budget assumptions if any are Square-specific.
10. **Menu-registry key spelling** — `'uber-eats'` (hyphen) in
    config/partna.php vs `uber_eats` everywhere post-convergence
    (suggestions-handoff §E FYI). Unify or document deliberately while
    touching the registry; verify MenuSource + ConnectorRegistry both
    resolve after the change.

---

# Absorbed from `docs/2026-08-26-backend-fixes-for-dev.md` (owner, 2026-08-26)

Verified 2026-08-26 evening: the backend dev has NOT started that doc
(only the doc commit exists; none of the implicated files changed). Its
menu-domain items now belong to THIS plan — tell the dev items 1–3 are
taken so there's no parallel work:

- **Item 1 → phase B-R (gate G2):** menu re-fetch revives dishes retired by
  reconciliation. Implement exactly per that doc's "The fix": in
  `MenuFetchJob::persist()`'s write loop, `ManualMenuWriter::clearRemoved()`
  for scraper-owned resolved dishes with `removed_at` set; guardrails —
  never revive `menus.suppressed_items` names, never touch
  `ownerLockedCoords`, non-scraper dishes never candidates, slug re-mint
  collision behaviour respected; update the §3.5 spec note to name the
  second legitimate caller. Tests: the doc's three (reconnect revives with
  stable ids; owner-delete sticky; owner-edit preserved). ollies needs no
  backfill (revived manually on dev 2026-08-26 ~03:45).
- **Item 2 → B3** (already merged there): matcher rework — external-id-first
  PLUS the doc's name-match learnings (parenthetical suffixes,
  generic-wrapper prefixes, retail-vs-marketplace naming, price NOT a
  tiebreaker, typo tolerance, trailing punctuation), using its confirmed
  misses as test fixtures.
- **Item 3 → NEW phase B5 (gate joins G5):** scraped-name casing + category
  quality. 3a: title-case transform at projection/write time in the three
  producers (Google photo OCR, website HTML scan, services scrapers) —
  ONLY when uniformly cased; connector words lowercase mid-name; AU
  state + dietary-mark allowlist; unit tokens untouched; item AND category
  names; ordering-platform names out of scope. 3b: per-provider category
  denylist as data ("Featured items", "Save on Select Items", "Picked for
  you", "Menu", "All", "Home"); synthesized "More" fallback category
  (materializes only when needed, sorts last, auto-dissolves); never let a
  scan slug become a display category; log-only wrapper-category flag.
  Tests per the doc's list.
- **Suggestions-handoff §E FYI → C10 (new teardown item):** the menu
  scraper registry keys Uber Eats as `'uber-eats'` (config/partna.php:969)
  while catalog + ConnectorRegistry use `uber_eats`. Resolve the spelling
  while we're in that registry (MenuSource re-derives from HOST so scraping
  works today, but the inconsistency is a trap; document or unify).
- Items 4–5 of that doc are routing-domain → they live in
  `docs/2026-08-26-routing-fixes-plan.md` (plan 3).

# Sequencing

1. B1 schema (own rail) — nothing depends on timing, land early.
2. B-R (backend-fixes item 1, reconciliation revival) — independent,
   urgent, lands first among code changes.
3. A0 + B0 spikes in parallel (browser + Apify probes, no code).
4. A1→A2 (Square catalog + pipeline) — gets Square scraping live on
   square.site URLs.
5. B2→B4 with backend-fixes item 2 folded into B3's matcher work (one
   matcher rework, not two).
6. B5 (casing + category quality) — after B3 so the matcher sees clean
   names from day one of its rework, verified together at G5.
7. A3–A5 (custom domains, harvester, frontend polish).
8. Part C teardown, in its listed order, each item only after its
   replacement is verified live.
9. One full dev scrape cycle → delete the host heuristic (C6) → done.

# Decisions — ALL RESOLVED (owner, 2026-08-26)

- **D1 Mode handling: item URL REPLACES the per-dish mode URLs.** A dish's
  platform entry carries `item_url` only; per-dish pickup_url/delivery_url
  go away. Store-level mode CTAs are unaffected (they come from the menu's
  store links, not per-dish rows). CONSEQUENCE for the build: the wire's
  per-dish platforms[] shape changes — sweep BOTH consumers (dashboard menu
  payload + sitepage wire) in the same unit and verify neither renders a
  now-absent per-dish mode URL. The aggregate pickup/delivery PRICES stay.
- **D2 Sold-out: PERSIST NOW** via offers.availability (existing spelling).
  Sitepage hide/badge treatment is a separate later frontend task.
- **D3 Badges: KEEP.** Do not drop the chain. Instead FIX the projection so
  badges actually land (slice 4's backfill landed zero `tag_type='badge'`
  rows on dev — that is now a bug to fix, not evidence for deletion), and
  verify a badge flows scrape → wire on a dev store. C5 below is amended
  accordingly.
- **D4 Square extras: ONLY IF TRIVIAL** while writing the new HTTP driver —
  dietary → item_tags, size/variant prices → offers qualifier 'from';
  otherwise note-and-defer. No dedicated build effort.
- **D5 Customization trees: DO NOT PERSIST** (no flag either).
- **D6 — RESOLVED BY EVIDENCE.** All THREE platforms have verified per-item
  recipes: Uber (uuid path / href), DoorDash (`?itemId=`, B0.1), Square
  (`absolute_site_link`, A0.1). Store-link-only remains only as the
  contract's escape hatch if a recipe breaks (see B0.1's canary note).
- **D7 Custom domains: STAMP PLATFORM AT PROBE TIME.** When the probe
  identifies a Square Online store, persist the platform on the connection;
  `MenuSource` trusts the stamp over host re-derivation (host patterns stay
  as fallback for unstamped rows).

# Cross-plan coordination

`docs/2026-08-26-backend-fixes-for-dev.md`: item 1 lands first; item 2
merges into B3. Whoever executes must not run both matcher reworks
independently.

After this plan completes and its final gate passes, execution AUTO-CONTINUES
into `docs/2026-08-26-routing-fixes-plan.md` (plan 3 — platform quality
fixes: routing + Instagram media; smallish but built to the same golden
standard, never rushed), then into
`docs/2026-08-26-pd-registry-retirement-plan.md` (plan 2 — the big
refactor). Owner directive 2026-08-26 — no pause for a new
go-ahead between plans. NOTE: execution of ALL plans starts only on the
owner's explicit go signal — nothing runs before that.

# EXECUTION METHOD (owner directive 2026-08-26 — governs the run)

This section supersedes any general run doctrine for THIS run. Explicit
owner permission to ignore the giant-run skill and any other big-run
skill/instruction where it conflicts — those are written for other kinds of
work. The method is what is written here.

1. **Inline, one main session.** No background agent fleets doing the
   building. Subagents are used ONLY as the fresh-eyes testers/critics in
   the gates (below) and for bounded read-only research.
2. **Full permission to set everything live.** Push as you go: Comet-Backend
   on a feature branch pushed per unit (merge to `development` when its gate
   passes — that deploys via Laravel Cloud); schema via `supabase db push`
   on the dev ref; monorepo commits on main, pushed (dashboard auto-deploys
   on push; pages deploy via `npm run deploy`). No holding work back for
   review.
3. **Fix everything found.** Any issue discovered along the way — related
   or unrelated to this plan — is NOT ignored and NOT written up as a
   suggestion. It goes onto the run's todo list and gets fixed as part of
   the run (sequenced so it doesn't derail the current gate; truly huge
   finds get a todo entry sized honestly and slotted after the current
   phase).
3b. **Auto-add improvements (owner permission, 2026-08-26).** Wherever a
   plan improves an area (menu OCR/casing logic, matcher, mirror pipeline,
   routing scoring, …), the executor has FULL permission to conceive and
   add further improvements to that area on their own judgement — add them
   to the todos and the plan doc, build them to the same gate standard.
   Quality of the end state outranks sticking narrowly to the written
   task list.
4. **Hard gates between phases.** A phase is DONE only when its gate
   passes; execution never advances past a failing gate. Every gate
   includes, as applicable: typecheck + lint + tests green; live
   verification on dev (real scrape run, real connection, real wire
   payload inspected — evidence, not assumption); AND a fresh-eyes review —
   spawn a Sonnet subagent that has NOT seen the working context, pointed
   at the diff + the phase's goal, tasked to find what's wrong or missing
   as a critic/tester. Its findings are triaged: real ones get fixed before
   the gate closes.
5. **Budget.** Apify / AI-extraction spend is unrestricted for this run —
   test scrapes and verification runs whenever useful, no budget hesitation.
6. **Checkpointing.** After each gate, write a short dated checkpoint into
   this doc (what landed, evidence, what's next) so the run survives a
   session break.
7. **Doctrine that still applies:** evidence before claims; no reformatting
   untouched code; match existing patterns; `git fetch && git status`
   before committing (other lanes exist); never force-push.

Gate map for this plan: G1 after B1 (schema live on dev ref) · G2 after
backend-fixes item 1 · G3 after A1+A2 (Square scrapes a real square.site
store end-to-end on dev) · G4 after B2+B3 (all three drivers + projection;
dev scrape shows item_url/external_ref/availability landing in
content.offers) · G5 after B4 (wire carries per-dish links; dashboard +
sitepage verified live) · G6 after A3–A5 (custom-domain probe → connect →
scrape proven on order.fat-tuna.com or equivalent) · G7 after Part C
teardown (registry purge + removals; full regression: connect each of the
three platforms fresh on dev, plus one existing legacy-connected profile
still renders) · G8 final: end-to-end on ollies (all three platforms
connected, per-item links verified clicking through from the live sitepage).
