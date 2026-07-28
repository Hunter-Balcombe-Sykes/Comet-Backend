# Content & Platform Rebuild — Execution Log

Companion to `2026-07-27-content-platform-rebuild.md`. One entry per phase; updated as each phase lands. Owner approved full autonomous execution 2026-07-27 evening ("everything is sweet and good to go — execute it all"); all §23-E approvals granted by that sign-off.

| Phase | Status | Branch / commits | Gate result |
|---|---|---|---|
| P0 early hotfixes | ✅ landed | `rebuild/p0-hotfixes` → development | full suite + new regression tests green |
| P1 catalog | ✅ landed | `rebuild/p1-catalog` → development | compile+contract gates, 26 catalog tests (1,161 asserts), sync idempotence on real PG, full suite green |
| P2 routing | ✅ landed | `rebuild/p2-routing` → development | 449-case corpus, 100 routing tests, full suite + architecture guards green |
| P3 ingest + pilots | ✅ core landed | development | 105 ingest tests; schema live on dev |
| P4 content/identity | 🔄 core landed | development | 33-table content schema live; 28 resolver/value tests |
| P5 sections/serving + creator cohort | 🔄 core landed | development | sections+documents schema live; 21 builder/rule tests |
| P6 frontend | queued | — | — |
| P7 connectors/upgrades + food cohort | queued | — | — |
| P8 deletions + re-measure | queued | — | — |

## P0 — Early security hotfixes (2026-07-27)

**Scope (§17 EARLY):**

1. **Host-spoofing patterns closed.** Every open-ended `brand\.[a-z.]+$` suffix let `brand.<attacker-domain>` classify as the brand (reserve buttons / order links / connect flows pointing at attacker hosts). Replaced with enumerated real-TLD sets in:
   - `WebsiteLinkHarvester` — all nine (pinterest, opentable, thefork, quandoo, deliveroo, just-eat ×both stems, treatwell, eventbrite, ticketmaster)
   - `OpenTableService` — shared `TLDS` const across `parseRid`/`isOpenTableUrl`/`nameFromUrl`; the bare `?rid=` fallback now also requires a real OpenTable host (a rid from `random.com/?rid=5` no longer parses)
   - `EventbriteScraper` — all four pattern sites (org/event normalizers, org-page event extraction, link canonicaliser)
   - `GoogleBusinessService` — structured Google TLD shapes (`com`, `com.XX`, `co.XX`, `XX`) for the Maps-host gate + interstitial body extraction
   - `PlatformRegistryServiceProvider` — the four `HostMatch` detectors (eventbrite, quandoo, ticketek, ticketmaster). Interim-only: P2 replaces this whole layer, but it is live until then.
2. **`SafeUrlFetcher`:** own-infrastructure denylist (partna.au, supabase.co, laravel.cloud, r2.cloudflarestorage.com, r2.dev, workers.dev — exact or subdomain, checked before DNS resolution; config `partna.http_fetch.denied_host_suffixes`, env `PARTNA_HTTP_FETCH_DENIED_HOST_SUFFIXES`) + `connectTimeout` clamped to the hop/chunk budget window in both the serial loop and `pooledGet`.
3. **`SiteMedia::svgVariantUrl()`:** the 2026-07-17 original-file fallback is removed — only the container-sanitised vector variant is ever served. The fallback fired exactly when the sanitiser had been skipped (GD fallback / pipeline off), serving auto-grabbed third-party SVG that had passed only `LogoAutoGrabber`'s regex pre-filter. Missing vector now degrades to the webp raster.

**Tests:** `tests/Unit/Security/HostSpoofingHotfixTest.php` pins both directions (spoof rejected, real host accepted) for the harvester, OpenTableService, the registry detectors, the SafeUrlFetcher denylist, and the svg fallback removal. Full suite green.

**Behaviour notes:** unknown ccTLD variants of enumerated brands no longer auto-classify (fail-safe direction; extend the lists if a real market host surfaces). Sites whose logo processing had fallen back to GD render raster instead of unsanitised vector.

## P1 — Platform catalog (2026-07-27)

**Shipped (plan §1):**

- **Four-plane catalog, compiled.** `app/Catalog/` value objects (Surface/Detector/Brand + 7 enums + SurfaceBuilder/DetectorBuilder + assert-only contracts), 103 definition files under `Definitions/` (every registered brand, every WLH label promoted to a real brand+surface, config-only brands, and Partna's reserved surfaces), `_manifest.php` (explicit list, no scanning), `_capabilities.php` (44 versioned capability names → the EXISTING strategy classes; factory closures where construction composes — UrlConnect+normalizer, OEmbedFetch+endpoint). `catalog:compile` runs contracts/uniqueness/capability-coverage and emits the content-addressed `bootstrap/catalog/compiled.php` (committed; CI guard = `--check`). **Compiled: 103 brands, 110 surfaces, 268 detectors.**
- **`catalog` schema** (pushed to dev Supabase 20260727100000 after repairing the collapse-era migration history — history-table-only repair): brands/surfaces/detectors/host_aliases/suffix_overrides + runtime tables (detector_suspensions with deliberately no FK, unmatched_domains) + sync_state. `catalog:sync` = upsert-tombstone only, digest short-circuit, hourly schedule as the convergence net; deploy runbook gained the explicit step. Idempotence + tombstone/resurrect proven against real Postgres (`tests/Postgres/CatalogSyncIdempotenceTest`).
- **Connections cutover (20260727110000):** `surface_key` (+`routing_class`, `is_primary`, detector/digest provenance) is the identity; unique indexes swapped to surface_key; one-primary-per-(user,routing_class) partial index added; **`platform` is now a GENERATED alias column** (14 special back-maps + brand-prefix rule) — raw writes to it error, killing dual truth structurally. `LegacyPlatformMap` is the single bridge; six lockstep tests pin PHP map ↔ backfill SQL ↔ alias SQL ↔ SQLite test schema ↔ compiled artefact. Model writes translate transparently (`platform` mutator → surface_key), so **zero call sites changed and zero behaviour changed**: pseudo-bucket rows map to hidden `partna.*_link` surfaces that alias back verbatim; real-brand upgrades happen at P2's reproject.
- `GET /api/catalog/surfaces` (authed) — successor feed for the FE platform mirror; ETag = artefact digest.
- CatalogIntegrityCheck boot handshake (unservable → hidden not 500; orphan capabilities reported).

## P2 — Link routing (2026-07-27)

**Shipped (plan §2):** `PublicSuffixList` (vendored PSL, exception/wildcard algorithm) → `IriCanonicalizer` (the ONE canonicaliser) → `Rulepack` (registrable-key index + build-time specificity order, baked into the catalog artefact) → `LinkProjector` (pure, 0–100 confidence + margin) → `PlacementPolicy` (both gates PRE-write; sealed Place/Choose/Note/Hold/Reject) → `SourceReconciler` (intents → connections; booking XOR; caps; never replaces user data) → `LinkObserver` (append-only, best-effort). `LinkRoutingService` orchestrates; `POST /api/routing/preview` + `/api/routing/links` succeed `CustomLinksController::addLink` with a compatible envelope. `routing` schema (20260727120000): month-partitioned observations, source_intents (5 states / 9 block reasons), item_tombstones, import_runs. `routing:reproject` replays recorded traffic against the current or another checkout's artefact and **exits non-zero when a classification would be LOST**, so it can gate a rules PR.

**The spoofable-host class is dead structurally**, not by patch: identity is the PSL registrable domain, so `brand.attacker.com` resolves to `attacker.com` and no brand rule can reach it — regardless of what any pattern says.

**Corpus (the P2 gate): 449 cases.** The 285 positives are GENERATED by walking every compiled detector, synthesising a URL its own pattern accepts and asserting the real pipeline round-trips it — so the corpus cannot fall behind the catalog, and a detector-coverage test proves none is missed. 164 hand-written attacker cases (spoofed hosts across 32 brands, lookalikes, IDN confusables, userinfo tricks, path-embedded brands, reserved paths, bare domains, own-infra, non-http schemes, shorteners, ordinary businesses, malformed input) assert nothing ever connects.

**Defects the corpus caught (all fixed):** `apple_music`/`apple_podcasts` detectors were keyed on FULL HOSTS (`music.apple.com`), which the registrable-domain lookup never produces — **both surfaces were undetectable**; three patterns anchored on a trailing slash could never match the canonical form; Shopify/Big Cartel storefront detection had been lost entirely in the P1 catalog pass (restored); OpenTable's `?rid=` rule covered only `.com`, missing every regional booking URL including AU.

**Messy-input robustness** (owner requirement, now plan §2): locale/UI params, `/amp` + `/index.html`, copy-paste damage, prose-embedded links, and line-wrapped URLs are all normalised — identity params never touched.

**Also fixed en route:** a pre-existing test-suite fragility (`RefreshFetchBudgetTest` borrowed four helpers from three other test files; it only passed when Pest co-located them, and new test files broke it) and SQLite's 10-database ATTACH cap silently swallowing the new schemas (four long-deleted schemas dropped from the attach list). New directories wired into all 12 audit lenses + their `.md` scope groups — no exemptions, no weakened assertions.

**Decisions logged during build:** capability naming standardised `role.brand.method.v1`; legacy `shop` rows map to `partna.storefront` (real storefront connections), with `partna.manual_product` reserved separately for §16 manual adds — a deliberate refinement of the plan's pseudo-platform wording; Instagram's surface carries no fetch capability until the P3 connector exists (bespoke Apify flow noted); `isConnectable` means "the catalog can drive this" (bespoke-only GBP/Skool are notConnectable with notes); brand plane's design-system `brand.json`/colors.css generation deferred to its consumers (P5/P6); youtube_music icon asset still missing — P6 item. Three pre-existing test failures from the dev's 27-provider stopgap were repaired en route (registry coverage now asserts registry==LegacyPlatformMap lockstep), plus two latent test bugs the stricter SQLite schema mirror exposed.

## P3 — Ingestion (2026-07-27)

**Shipped:** `ingest` schema (15 tables: sources with the DB claim as THE run lock and an EWMA cadence band, streams with coverage/health/guard/run_seq fence, runs, hash-partitioned content-addressed `record_versions`, `record_state`, the effects ledger, anomalies). Manifest/StreamSpec/SourceProfile — one declaration deciding cadence, whether absence may EVER mean deletion, and the dashboard's pinnable/editable flags. Message sum type (Record | Covered | Bookmark | Note | Deferred | Unavailable). Coverage + domination. DocHasher (volatility on the hash INPUT only) and Redactor (applied BEFORE landing). EffectLedger (charge-once; a dead claim is REFUSED, not retried). Lander (unchanged content writes zero rows; ≥40% single-run vanish trips the guard, files a critical anomaly and deletes nothing). Connector contract + HttpIo/ReplayIo + RunExecutor. SourceScheduler. Projection layer with instrumented reads. Connector CLASSES: Bandcamp, Vimeo, Spotify oEmbed, YouTube RSS, Fresha, Google Business (+ Apple Music, Apple Podcasts, Substack) — but `ConnectorRegistry::MAP` registered only 4 of the 9 (apple_music, apple_podcasts, bandcamp, substack), so the other five were undispatchable until the P6-round-5 audit repair registered them all and added a directory-walking drift guard. `ingest:dispatch` (15 min) + `ingest:stranded` (hourly) + a dedicated Horizon lane.

**Corrections made to delivered work:** the Horizon lane was sized 2×256 MiB, pushing the box's permitted ceiling ~514 MiB past 2048 — capped to ONE worker at 192 MiB everywhere, with the residual ~194 MiB over-commit stated plainly in the config (the box had only ~88 MiB of headroom before a fifth lane existed, so *any* fifth lane crosses it; `memory` is a restart threshold, not a reservation; the real fix is the resize §19 already carries). `RunExecutor` read `is_claimed` off a column that does not exist and so always defaulted TRUE — which would have landed third-party personal data for unclaimed accounts, exactly the regression claim-state-scoped redaction prevents. `PlacesBudgetGuardTest` caught the GoogleBusiness connector naming the Places host in its manifest; it makes no direct HTTP calls at all (the billed call goes through the ledgered effect whose driver claims PlacesBudget), so the host list is now empty with the reason stated.

**Also:** phpstan caught absence-folding calling `Covered::dominates()` instead of `Covered->coverage->dominates()` — it would have fataled the first time any stream tried to fold absence.

## P4 — Content & identity (2026-07-27)

**Shipped:** `content` schema (33 tables) — items (thin spine: no pool, no is_selected, no sort_order, no identifier), source_items with stable coords, identity_keys as EVIDENCE (deliberately no unique index — two sources sharing an ISRC is the signal, not a write failure), identity_decisions, anchors, merges, candidates, `manual_overrides` (typed per-column, NULL = explicit clear), 14 typed facet singletons keyed (item, source), set-union collections (offers/media/tags/variants/actions/refs), media_assets as entities, collections and slugs.

**The Resolver is pure (C7):** source items + human decisions in, groups out, no DB and no clock, so identity is RECOMPUTED rather than accumulated. Pass order is load-bearing — join → user's "same" → user's "different" as a CUT → corroborating unions — which is what makes a human ruling survive a joining key that disagrees (C8). Poisoned keys (one source attaching a value to two of its own records) are dropped entirely. Evidential keys never merge; they queue, and never re-ask an answered question.

**ValueResolver:** per-column rules (recency/priority/longest/union) with manual override absolute. Recency ranks by when CONTENT changed, past a 24h dwell — otherwise an hourly re-publisher owns every volatile column forever.

## P5 — Sections & serving (2026-07-27)

**Shipped:** pages/sections/section_items/section_groups + source_routes + versioned site_documents + site_build_state + design_kit_restyles. Nav is PAGES (three sections on a Menu page = one nav row). Capability sits on the page and is checked at WRITE time. The rule DSL is bounded at 7 operators / 5 predicates — that bound is the feature, since it is what lets one rule be validated, rendered as an English sentence, and explained per candidate.

**DocumentBuilder:** read revision → build → hash → commit under CAS. Byte-identical output inserts nothing and purges nothing; a builder whose revision moved cannot mark the site clean. Two bugs found in testing: a raw `now()` that tied BuildState to Postgres for no benefit, and `commit()` against a site with no state row affecting zero rows — the FIRST build of every new site would have reported "superseded" forever.

## Frontend (P6 groundwork)

`lib/catalog.ts` reads `GET /catalog/surfaces` instead of mirroring the backend registry in 565 hand-maintained lines. Module-level cache is safe because the ETag is the artefact digest — the payload can only change on deploy. `legacyPlatform` is carried so the old mirror can be deleted screen by screen rather than in one flag day.

## Going live (2026-07-28)

**The showcase accounts were not reachable, then were blank.** Two separate faults, both only findable from outside the code:

1. `showcase-creator` and `showcase-eats` were published in the database but had no Cloudflare KV entry, so `<handle>.partna.au` 404'd. Dev and prod share one KV namespace (`ollies` was present, the showcase pair was not), so `partna:backfill-subdomain-kv` was all that was needed. Both now serve 200.
2. Once reachable, every platform page rendered the bare home shell with zero outbound links. The connections were real, active and correctly routed — but the public wire served `"payload":[]` for all 20 of them. `PublicIntegrationConnectionResource` filters payload through a per-platform allowlist (socials expect `{username,url}`), and the showcase seeder wrote only `{source:'showcase'}`, so filtering left nothing. The sitepage reads `payload.url`, and Instagram reads `payload.username` exclusively, so an empty payload renders an empty page.

The seeder was the immediate cause, but `SourceReconciler` had the same bug latent: it wrote `url` and never `username`, so a genuinely routed Instagram connection would also have rendered blank. Both writers now go through `App\Routing\ConnectionPayload`, which also refuses to publish composite (`artist/4gzp…`) or opaque (`1419227`) identifiers as a username. **This survived 5,969 passing tests because nothing asserted what reaches the public wire.** After backfilling 28 rows, showcase-creator renders 10 platforms and showcase-eats 3.

Apple Music and Apple Podcasts still render nothing, correctly: their allowlists expect `link`, which is *content* supplied by a refresh job, not identity written at link time. `last_refresh_status = 'pending'` is the honest state.

**Deploy trap, re-derived the hard way.** The pages worker deploy was correct and active, yet the live page kept serving pre-removal Pinterest markup. The router caches at `PRIMARY_CACHE_TTL_S = 86400` and builds its cache key without the query string, so a `?cb=` buster does nothing. `apps/pages/CLAUDE.md:125` already documents both facts; reading it first would have saved the detour. Purge is not optional after a pages deploy.

**Pinterest removal completed** across the monorepo (design-system assets, page taxonomy, platform sections, actions vocabulary). The whole Pinterest data path in apps/pages turned out to be already dead — no component read `surfaces.pinterest` — so removing it changed no behaviour. The "Other" platform-section category went with it, having existed solely to hold Pinterest.

**Monorepo CI had been red since 2026-07-25** on the tokens-only audit, failing on five values `apps/pages/CLAUDE.md` already sanctioned. The regex lived in both `ci.yml` and CLAUDE.md and the copies drifted. Both now call `apps/pages/scripts/tokens-only-audit.sh`. Verified the gate still fails on a real violation rather than being green by construction; that check exposed a blind spot (only line-leading declarations are inspected, so single-line rules and `@media` breakpoints go unseen) which is documented in the script rather than papered over, since widening it would flag the two structural breakpoints.

**Link-add sheet mounted** on `/account/custom-links`, replacing the single-field modal with the URL-plus-category-shelves drawer. Wiring it up exposed two more defects: `lib/catalog.ts` cast its response without parsing it (a non-catalog body threw inside a render and took the surface down), and `useCatalog` fetched on mount, so a closed sheet bought a request on every page load. Both fixed, and `lib/catalog` went from zero tests to ten.

**P8 remains blocked** — see `2026-07-28-p8-deletion-readiness.md`. Five capabilities the new pipeline does not have, not five tasks nobody got to. The one with real user-visible harm is the missing `routing.item_tombstones` backfill: migrating a scan path before it would resurrect connections users deliberately deleted, and no test would catch it.

## P6 — Frontend rebuild (2026-07-28, overnight run)

**The answer to "why am I not seeing it":** deploys were reaching app.partna.au
all along (verified: latest Vercel deployment Ready, domain serving it). What
was missing was the rebuild itself — the previous session's frontend changes
were small fixes; the visible IA work had not been built. It has now:

- **Platforms.** The sidebar's seven platform-ish rows (Integrations,
  E-commerce, Events, Booking, Reservations, Online ordering, Custom links)
  are ONE "Platforms" entry. Everything lives under `/account/platforms`:
  the index (connected rows + links panel + one "Add link" sheet), the
  `[platform]` detail template (moved wholesale, history preserved), and the
  class pages as static segments. Old URLs redirect forever with subpath
  preserved — load-bearing, since notification rows persist
  `/account/integrations` as cta_url. New rows write `/account/platforms`
  (backend f7960678).
- **One add flow.** The routing link-add sheet (URL on top, catalog shelves
  underneath) is the index's single entry; guided flows (Instagram scan,
  booking/reservations setup, store sync) hand off to their wizards from
  inside it. The old Add-integration picker died.
- **Sharing folded into Overview; Notifications page retired** (the bell
  panel expands rows in place — the snapshot already carried body + actions);
  BETA mark on the sidebar wordmark.
- **Every Dialog is a bottom sheet on mobile** (Vercel's geist/modal
  behaviour), implemented once at the primitive with forceModal escape
  hatches (lightbox, cropper, command palette). The named ceremonies became
  sheets outright: site URL, custom domain, 2FA, privacy/terms, add contact,
  and every data-table row detail via the DetailDialog conversion.
- **Geist alignment round:** critique agents audited against
  vercel.com/geist + the repo's own taste.md; the fixes ranged from a lying
  "Disconnect" (a destructive-styled link that only navigated — now a real
  disconnect with confirm) to skeletons that didn't match their loaded
  layout, spinner-less pending buttons, first-person system copy, and an
  overview URL-change checklist that contradicted its own success screen
  about whether old links redirect (they do: 301 aliases, 14 days).
- **Consolidation:** EntitySheetSuccessBody / PageHeaderSkeleton /
  ItemRowSkeleton / StatValue / ghost-destructive variant / one date-format
  vocabulary; dead code deleted (toggle-group, scripts/overhaul, undeclared
  deps trued up).

Font was already Geist Sans/Mono via the `geist` package on both layouts —
verified against Vercel's own wiring (variables on <html>, antialiased body)
and left alone.

## P6 round 2 — owner IA correction (2026-07-28, mid-run)

The owner clarified overnight: platforms must have NO pages of their own —
the Platforms surface is a DATA TABLE whose rows open management SHEETS —
and the content pools (Events / Media / Watch, plus Services) are LHS pages
on ONE shared single-page template.

Shipped in `c1c3c303`: the [platform] page tree deleted (every old URL
redirects into the table's ?platform= deep link, which auto-opens the right
sheet — persisted cta_urls keep working); the sheet mounts the SAME section
component the old page did, with Settings stacked below; the PoolPage
template; /account/events restored as a real single page (events +
organisers + settings stacked); /account/watch built new (per-platform
featured-video managers); Media and Services collapsed to single pages.
Multi-page class configs (booking/reservations/ordering/e-commerce) keep
their pages under /account/platforms/* and their rows navigate.

taste.md carries the round-2 verdicts so round 1's entry can't mislead.

## P6 round 3 — pools template + connections-only sheets (2026-07-28)

Owner's final concept landed: platform sheets show connections only (info +
accounts + rules + disconnect — every content grid moved out); Links is its
own table page; and ONE shared template (components/pools/content-pool.tsx)
drives every content pool — Events, Watch, and the new Listen — each page
now a ~60-line config over a central selected-items table with the shared
add sheet (manual URL + pick-from-connected-accounts). Products is a pool
page again with store accounts in its sheet; the mobile menu is full-screen.
One template to edit, every pool follows.

## P6 round 4 — one table grammar + real rows + commerce catalog (2026-07-28)

Owner's round-4 corrections, all live:

**One table grammar everywhere** (frontend `da0d2ed3`): the products-table
pattern is now THE pattern — every pool table (Events, Watch, Listen, Links,
Products) and the Platforms index runs bulk select with a confirmed bulk
Remove/Disconnect, Add + Refresh in the table toolbar, and row-click detail
popups. Links rebuilt ONTO the pool template (its URL editor rides the
template's renderDetail seam). The pool template grew removeMany — one
full-replace save per highlights group, the same race products-section
documents — plus a row detail with link-out and a URL-only add mode.

**Platforms rows got real**: booking/reservations rows show the DETECTED
provider (Fresha, Square, OpenTable…) with its brand mark unless the
provider is a custom link; every connected store is its own row (favicon,
provider · product count, per-store disconnect); EVERY row opens its sheet —
the class pages (booking/reservations/online-ordering) are deleted, their
URLs redirect into ?platform= stubs like every other platform URL.

**Commerce catalog** (backend `9185e01e`): the commerce shelf now lists
WooCommerce (new definition), Shopify (notConnectable removed), Bandcamp
(second surface, detector-less so URL routing stays on bandcamp.artist) and
Gumroad. Every add-sheet step carries a routed URL field (start, category,
platform). Catalog-only surfaces get real brand marks via
EXTRA_SURFACE_ICONS — Gumroad's generic globe is gone, WooCommerce/Shopify/
Mixcloud/Big Cartel/Ticketmaster glyphs added (simple-icons, CC0).

**CI unbroken** (backend `b22612dc`): the pipeline had been red since
2026-07-27 15:53 — Checkpoint flagged the DAST seeder's runtime-generated
password as a hardcoded secret and SKIPPED the migration guard + tests
behind it. Suppressed with vetting note; the applied catalog migration
(20260727110000) carries the sanctioned guard disable-file marker with
justification. Local vendor refreshed to lock (guzzle 7.12.1 → 7.15.1
patched line).

Frontend: 766 tests green, tsc 0, lint 0 errors, production build clean;
app.partna.au verified byte-identical with the newest deployment. Seeding
sweep (Maha Restaurant, one real connection per platform) running as the
E2E case study — results land in this log's next entry.

**Maha sweep — results.** The promised E2E case study ran against the real
API on user handle `ollies`: 25+ platforms connected end-to-end (paste →
route → connection → public wire). Two platforms surfaced kill-switched
(strava, skool — FeatureAvailability rules, expected); fresha correctly
capability-blocked for the food business (booking is the non-food side of
the sector gate, so a restaurant pasting a Fresha link gets the gate, not a
connection). The sweep's bug list — headlined by the Links-page add flow
blocking every unrecognised URL — was handed to the fix rounds below.

## P6 round 5 — audit repairs (2026-07-28)

Four audit findings fixed, each with tests, all on `development`:

1. **Note read as a block (LIVE, E2E-verified).** Pasting an unrecognised
   business URL (broadsheet.com.au/melbourne) on the Links page showed
   blockReason "unknown-domain / unrecognised link" and disabled Add —
   despite `Verdict::Note` being documented "keep as a link item, never
   dropped". `LinkRoutingService::describe()` now makes blockReason a
   Reject-only signal (Note → routedTo:null, blockReason:null, "We'll keep
   this as a link on your site."); `PlacementPolicy` splits unmatched into
   Note (healthy unknown domain) vs Reject (canonicaliser-refused:
   malformed / own-infra / shortener / confusable), matching Verdict's own
   doc. Also fixed en route: `route()`'s reconciler verdict override was
   dead code (`+` keeps the left operand), so Place→Hold downgrades
   misreported "place" on the wire.
2. **Note now WRITES the link.** `/routing/links` answered 202 "pending"
   for a Note and persisted nothing — the URL vanished. The legacy custom-
   link write (rid, minimal card, lock, dedupe, 20-cap, EnrichLinkCardJob)
   was extracted into `CustomLinkSeeder::writeCard()`; the new `addManual()`
   entry (no previous-website skip — an explicit paste is intent) backs a
   Note branch in `RoutingController::store`, so the same row the legacy
   endpoint writes lands and `GET /platforms/custom/links` + the sitepage
   render it. Cap full → structured 422 `link_cap_reached`. The response
   gained the ADDITIVE `outcome` field: connected | link | review | null.
3. **Suggestions payload leak.** `SuggestionsController::accept` wrote
   `['url','source']` by hand — a third writer bypassing
   `ConnectionPayload::forWrite`, i.e. the blank-sitepage regression class
   resurrected for accepted suggestions (Instagram renders from `username`
   alone). Routed through `ConnectionPayload::forWrite`; test pins
   username+url+source on an accepted Instagram-like suggestion.
4. **Connector registry drift** — see the corrected P3 entry: 5 of 9
   connector classes were unregistered and undispatchable; all 9 now
   registered under their manifest source keys with a two-way drift guard
   (`tests/Unit/Ingest/ConnectorRegistryTest.php`).

## LANE A landed + stitch (2026-07-28, overnight continuation)

The ingest lane's final tranche is on `development` and live-verified:
sources seam (8cc57133), real projection (e0382908), and the full 7-op rule
DSL with its callers — `BuildSiteDocumentJob`, `site:build-documents`, and
the every-five-minutes `--stale` sweeper (c721afc8). Suites: 368 passed
(Ingest + Site) plus Api/Content/PreAccount/Routing chunks green.

**Live Maha numbers (dev, user `ollies`):** 10 ingest.sources, 10 streams,
9 runs (7 ok, 2 unavailable), **142 content.items** — projection is real on
live data.

**Live bug found and fixed during the stitch (e64bd999).** Maha had 142
items but ZERO site_build_state rows: `bumpSite()` and
`site:build-documents` filtered `site.sites` on `whereNull('deleted_at')` —
a column that exists only in the SQLite test stand-in, never in the real
schema. Postgres threw 42703 on every projection; RunExecutor's deliberate
projection_error note-catch kept run outcomes 'ok', so the sweep had
nothing to sweep and no document ever built. Fix removed the phantom filter
(3 sites) AND the phantom column from the Pest stand-in, which is the
regression net — the covering tests fail against the corrected stand-in.

**End-to-end verified on dev after the fix:** inline build wrote document
v1 (pages/navigation/warnings/builderRevision); a manual BuildState::bump
(rev 1 > built 0) was picked up by the scheduled sweeper within 3 minutes —
Horizon ran the job, the builder hashed byte-identical output, committed
built_revision=1 with no new version and no purge, exactly per protocol.

## WAVE-2B + WAVE-2C merged (2026-07-28, overnight continuation)

Both wave agents (relaunched after the prior session died) landed and are
deployed to dev.

**WAVE-2B (merge e76c8456):** #26 primary-CTA API (`GET
/api/routing/connections` with per-class is_primary + class→id primary map;
`POST /routing/connections/{id}/primary` demote+promote in one transaction —
no migration, the column and partial index already existed). #27
`DisjointSet::separate()` now cuts in both argument orders (the old code
always re-rooted its second argument — a no-op whenever that side had won
the union, so coord-sorted cuts silently failed about half the time).
Tombstones are origin-aware: a direct paste wins over a prior removal and
deletes the superseded refusal; scan/harvest resurrection stays blocked
(C8). B4: `SyncFindingsBridge` folds Hold intents into the Instagram
synced-modal response at read time; `SuggestionApplier` extracted so intent
application stays single-writer. 1941 tests green on the merged result.

**WAVE-2C (merge 8cf04af5):** probe cascade speaks all five shop platforms
(Woo/Squarespace/BigCartel/generic ported into app/Routing/Probes;
`squarespace.store` surface added, artefact recompiled, corpus regenerated).
StoreBrandProfiler successor reads the catalog instead of re-fetching. §13
design-kit autopilot (fromBrandPalette + fromWebsiteEvidence →
site.design_kit_restyles, WCAG tone-mapping, restyle/undo endpoints). §15
preset library + PresetInstantiator (ten arrangements + blank; no cold-build
caller yet — that is the §14 pipeline-swap item). §14 field_bindings:
migration 20260728150000 (manual=priority-0 CHECK is the lock, C2) APPLIED
to dev ref via db push; FieldBindingSeeder rides PresetInstantiator;
FieldBindingResolver fails closed; IdentitySync remains the live writer
until the pipeline swap. 729 tests green on the merged result (one
non-reproducing order-flake in the first run; two full reruns clean).

Merge-review note: both waves based off fcd8eb15 and merged cleanly over
the deleted_at stitch fix; the corrected sites stand-in survived the merge.

## Connector fleet merged + EffectLedger semantics (2026-07-28, overnight continuation)

**Fleet (merge 11c399ab):** all ten §11 scope items — eventbrite + humanitix
(shared SchemaOrgEvent path), soundcloud (Spotify's oEmbed twin), twitch
(channel + Helix VODs under existing client-creds), skool + strava (built
behind their kill-switches), #20 youtube_music (empty feed lands nothing,
claims nothing), #21 gumroad (Inertia payload verified against live
profiles), instagram (Actor cost class ⇒ manual-only by construction,
hosts: []), square/uber_eats/doordash menus (one landed shape, one
projector). 21 connector classes, all registered, drift + lockstep guards
green. No schema changes needed. tiktok/booksy stay owner-gated; nothing
built. 2,221 tests green on the merged result (counts re-verified after a
zsh word-split bug produced two false-negative runs — unquoted $var does
not split in zsh).

**EffectLedger charge-once gap closed (694906b7, chip from the fleet
agent):** settled effects now persist their result (≤1MB inline in meta)
and replays return it with cached:true; pre-fix or oversized rows REFUSE on
replay instead of ok-with-null; digests take a freshness window
(partna.ingest.effect_freshness_seconds, default 7d) so sibling streams and
retries dedupe with data while recurring billed fetches re-bill
deliberately. HttpIo::effect() stops hardcoding cached:false. Was dormant
(no P7 driver) but the fleet's instagram + menu actors depend on it.

CatalogArtefactTest rewrites bootstrap/catalog/compiled.php in place on
every run (same digest, cosmetic churn) — flagged as its own task chip.
