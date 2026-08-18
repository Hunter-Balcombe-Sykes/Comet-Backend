# Pipeline assurance — replacing live build waves with layered, repeatable tests

**Date:** 2026-08-18 · **Status:** A1, A2, B1–B4 shipped 2026-08-19; B5 → Plan 2 · **Scope:** signup → scrape → route → connect → pool → public wire, both account types, every catalog platform.

## 1. Why

Since 2026-08-05 the pipeline has been verified by **live build waves**: a 200–430-line prompt drives a fresh session to create real pre-account sites on dev and read back what happened (`docs/reviews/2026-08-10-*-build-wave-PROMPT.md` and successors). Five Instagram waves ran; the Google Business wave was written but never run (no RESULTS file exists).

Deduping the eight RESULTS files gives **51 distinct findings**. Classified by the cheapest way each could have been caught:

| Cheapest catch | Count | Examples |
|---|---|---|
| (a) unit test on one function with a literal input | 17 | R1 Fresha detector vs `book-now/<slug>/all-offer`; N1 `classify('x.bandcamp.com')` → null; F8 default `$findings = []`; N-D `[redacted]` written into a URL |
| (b) feature test with a *recorded* real payload behind `Http::fake` | 11 | SIGNUP-2 actor went snake_case, seeder read camelCase → every IG site nameless; N2 linkin.bio is a JS SPA → 0 anchors; N3/R7 `og:type=product` with no price published a WordPress draft as a product |
| (c) real Postgres | 3 | F9 partial unique index; N-E CHECK; R3 missing FK |
| (d) real queue/deploy/KV/log tooling | 8 | KV alias self-loop; `cloud env:logs` 100-record cap; cascade only on the 15-min tick |
| (e) genuinely needs a live third party | 5 | actor returned 0 posts; IG withholds contacts logged-out; Fresha GraphQL screen shape; CDN rejects; YouTube `unavailable` |
| premise error (the prompt was wrong) | 7 | wrong table (`$selection` vs raw item, `content.item_links` vs `f_link`, `location_*` vs `workplaces`) |

**28 of 44 real findings (64%) needed no network and no DB.** Four waves and ~21 billed Apify scrapes surfaced bugs a fixture would catch in milliseconds; three findings were carried forward after the code was already fixed. Structurally the wave cannot do better: coverage is whatever hosts a real profile happens to link, it cannot be re-run after a fix, the per-IP cap of 3 forces batching, and the 8-section × 6-account report sits past the recall cliff that produced the 14% premise-error rate.

The existing suite does not close the gap either. ~113 test files use `Http::fake`, but the IG/Places payloads in them are **hand-invented** (`['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane']`, 15 files for IG, 11 for Places). Outside `tests/fixtures/shop/` (7 real captures) the repo holds no recorded reality. Connect tests exist per platform (`FreshaAsyncConnectTest`, `ShopAsyncConnectTest`, …) but are hand-written, so a new definition gets coverage only if someone remembers — the exact drift that produced N1.

## 2. Goal

Each failure class is owned by the cheapest layer that can see it, so that:

- **our** handling of every scraper, every link, every platform and every pool is proven offline, in seconds, on real recorded payloads, and re-provable after every fix;
- coverage is **derived from the registries** (`CompiledCatalog`, `config('partna.pre_account.sources')`, `SectorTaxonomy`, `LinkRouter` categories) so adding a platform/sector/source without coverage turns CI red;
- live checks shrink to the one question only they can answer — *did the outside world change* — and are small, scripted, self-cleaning and scheduled;
- every live finding is converted into a fixture, so the system ratchets.

## 3. Non-goals

- Fixing any product defect the new tests surface. A red test is a result; fixes are separate units under `fix-flow.md`.
- Teaching `LinkRouter` the catalog's routing classes (the P8 migration). Category `link` stays "recognised, never auto-connected".
- Production. Everything here targets dev and the local suite; prod lacks the `content`/`ingest`/`routing`/`catalog` schemas outright.
- Replacing `scripts/audit/` (static code) or `scripts/launch-check/` (runtime config). This sits between them: **behaviour on recorded reality**.

## 4. Architecture — four layers

```
D  live, small, scheduled      canaries · per-deploy build smoke · rare 1-question waves
C  pipeline replay             per-scraper contracts · Linktree ledger · full build replay + golden master · media mirror · PG lane gaps
B  registry-derived matrices   classify sweep · gate×account · sector fold · name→handle→subdomain · connect contract for every surface
A  foundation                  recorded fixture corpus + capture command · fixture mutation helper
```

A feeds B5 and all of C. B1–B4 need nothing and can land first. D needs A only for "expected shape".

## 5. Components

Each entry: what · where · interface · depends on · done-when.

### A1 — Fixture corpus + `fixtures:capture`

**What.** A directory of *real* upstream responses and a command that produces them.

**Where.** `tests/fixtures/recorded/<source>/<name>.<ext>` + `tests/fixtures/recorded/MANIFEST.json`.

```
tests/fixtures/recorded/
  instagram/   actor-item.personal.json  actor-item.business-hair.json  actor-item.restaurant.json
               actor-item.artist.json  actor-item.empty-bio.json  actor-item.zero-posts.json
  places/      details.barber.json  details.restaurant.json  details.gelateria.json
  fresha/      venue.<slug>.html  (and the GraphQL screen JSON FreshaConnector reads)
  linkinbio/   linktree.mixed.html  beacons.<x>.html  linkinbio.spa.html
  websites/    squarespace.<x>.html  wix.<x>.html  wordpress-draft-product.html  shopify.<x>.head.html
  shop/        (existing 7 files move here unchanged; paths updated in their tests)
  media/       ig-cdn.jpg  ig-cdn.expired-403.headers.json  favicon.ico  logo.png
  menus/       squarespace-menu.html  bar-liberty-a-la-carte.pdf
```

Manifest entry per file: `{ "path", "source_url", "captured_at", "sha256", "captured_by": "fixtures:capture|manual", "notes" }`. Sensitive strings (reviewer names, emails, phone numbers) are **redacted at capture** — `GoogleBusinessPayload::stripThirdPartyPii` runs on Places captures; IG captures keep only the fields the pipeline reads plus a `_captured_keys` list so a future actor field rename (SIGNUP-2 shape) is still visible.

**Interface.** `php artisan fixtures:capture {source} {ref} [--from=db|r2|live] [--name=]` writes one file + manifest row. `--from=db` pulls `site.platform_connections.payload` / `content.*` rows for an existing dev build (no spend); `--from=r2` copies a mirrored asset; `--from=live` performs one fetch through the real scraper (Places/Apify spend — the command prints the cost class and requires `--confirm-spend`). A `fixtures:verify` mode re-hashes every file against the manifest.

**Depends on.** Nothing.

**Done when.** ≥ 6 IG actor items, ≥ 3 Places details, ≥ 3 link-in-bio pages, ≥ 4 websites, ≥ 2 Fresha, ≥ 3 media assets, 2 menus are captured with manifest rows; `fixtures:verify` is green; `AuditPipelineIntegrityTest`-style guard asserts every file under `recorded/` has a manifest row (orphan file = red).

### A2 — Fixture mutation helper

**What.** `tests/Support/Fixtures/Recorded.php` — `Recorded::json('instagram/actor-item.business-hair')`, `Recorded::html(...)`, `Recorded::bytes(...)`; and `Recorded::mutate($payload)->without('biography')->nullify('externalUrl')->snakeCaseKeys()->set('businessCategoryName', 'None,Fast food restaurant')->get()`.

**Why.** Edge cases *shaped like reality*, one line each: SIGNUP-2 (`snakeCaseKeys()`), F5 (`"None"` / compound category), F4 (`set('postsCount', 0)`).

**Depends on.** A1. **Done when.** Helper has its own unit test and is used by ≥ 1 C1 file.

### B1 — Platform classification sweep

Exactly `docs/reviews/2026-08-18-platform-coverage-sweep-PROMPT.md`, run as written: `tests/Feature/Platforms/CatalogClassificationSweepTest.php` enumerates `CompiledCatalog::surfaces()` at runtime, probes `WebsiteLinkHarvester::classify()` with the definition's `canonicalUrl` (46 declare one) or a hand-written URL from a single fixture map, and sorts every surface into **connectable / link-only / invisible**. A surface with no probe URL is red, not skipped. Report to `docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md`.

### B2 — Gate × account matrix + signup pairing table

`tests/Feature/Platforms/LinkRouterGateMatrixTest.php`: every `LinkRouter` category × {`partna`, `business` non-food, `business` food} → expected `seeded | probe | custom (gate-denied)`, driven through `LinkRouter::route()` with in-memory users, following `LinkRouterHostDedupeTest`. Pins the two product consequences: booking inverts on food; an IG-sourced restaurant is `partna` so reservations/online-ordering demote to custom.

`tests/Feature/PreAccount/SignupPairingMatrixTest.php`: derives cells from `config('partna.pre_account.sources')` × `AccountType::cases()`; every allowed pair → 202, every other pair → 422 with the pairing error code. Adding a source without a generator entry is red.

### B3 — Sector fold table

`tests/Feature/Profile/SectorFoldTableTest.php`: a dataset of every Google `primaryType`/category and IG `businessCategoryName` seen in A1 fixtures and the RESULTS files → expected sector via `SectorTaxonomy::classifyText()`. Rows that fold to `null` are asserted *as null* and listed in the test's dataset name so the gelato-class gap is visible, not hidden. Also asserts `FOOD_SECTORS ⊂ all()` and that no `classifyText` output is outside `all()`.

### B4 — Name → handle → subdomain property test

`tests/Feature/PreAccount/HandleSubdomainPropertyTest.php` (extends the existing `HandleSubdomainConvergenceTest`): ~30 names — apostrophes, periods, `ñ`, `Beef's Barbers`, 23-char, emoji, leading digits, double spaces, trailing punctuation, all-caps — asserting the handle seed and `subdomainBaseFromHandle()` agree, the result is a valid subdomain label, and `BusinessName::wordTrim` output satisfies the `max:15` rule for **both** `display_name` and `first_name`.

### B5 — Registry-derived connect contract (every surface)

`tests/Feature/Platforms/CatalogConnectContractTest.php`. For each `CompiledCatalog::surfaces()` entry that is connectable (from B1's bucket, read at runtime not copied):

1. Resolve the connect entry point the dashboard uses (`GenericPlatformController` / bespoke controller — a small map from `RoutingClass`/`surface_key` to route, kept in the test).
2. `Http::fake` the vendor from `Recorded::*` (missing fixture ⇒ **fail with the surface key**, never skip).
3. Assert: `site.platform_connections` row with `platform`, `surface_key`, category as the definition declares; stored payload equals a **frozen snapshot** under `tests/fixtures/golden/connect/<surface_key>.json` (extends `IntegrationContractGoldenMasterTest` from ~15 platforms to all); the item appears in the right `data.profile.pools.*` on `GET /api/public/profiles/{handle}`; disconnect removes the row, the pool entry and dispatches `DeleteMirroredMediaJob` where applicable.
4. `IntegrationConnection::RETIRED_SURFACES` are asserted **absent** from the connectable set.

Snapshot updates are explicit (`GOLDEN_UPDATE=1`), diffs reviewed in PR.

### C1 — Per-scraper contract tests

One file per scraper under `tests/Feature/Platforms/Scrapers/`: `InstagramScraper`, `GoogleBusinessApifyScraper`, `FreshaScraper` (+ `FreshaConnector` screen parse), `GenericShopScraper`, `ShopifyScraper`, `WooCommerceScraper`, `SquarespaceScraper`, `BigCartelScraper`, `BandcampScraper`, `EventbriteScraper`, `HumanitixScraper`, `YoutubeScraper`, `MenuApifyScraper`, `GoogleMenuImagesScraper`, `LinkCardScraper`, `WebsiteLinkHarvester` (harvest + `harvestHtml`), `LinkInBioDetector`, and the `Actors/*Adapter` classes. Each:

- **happy**: recorded response in → asserted normalised shape out (name/bio/category/links/media/services/prices), including that the output survives `NoUntypedPayloadAccessTest`'s rules;
- **failure shapes**: empty item, `postsCount: 0` (thin scrape must be *suspect*, not success — F4), 402, 429, HTML-not-JSON, timeout, oversize body → asserted `ProfileFetchFailure`/status, no write;
- **shape drift**: `Recorded::mutate()->snakeCaseKeys()` and `->without(<field>)` for the fields the scraper reads — the SIGNUP-2 guard.

### C2 — Link ledger fixture (Linktree end-to-end)

`tests/Feature/Platforms/LinkInBioLedgerTest.php` over `recorded/linkinbio/linktree.mixed.html`, a real page (or a real page with links substituted at capture) carrying: IG, Fresha (`/a/` **and** `book-now/<slug>/all-offer`), Spotify, Bandcamp, a Shopify store, an unknown blog, a duplicate IG, a `canva.link`, `utm_`-tagged link, and enough shop hosts to exceed `RouteContext::DEFAULT_MAX_PROBES`. Runs `LinkInBioScanJob` and asserts **every anchor has exactly one outcome** (seeded / conflict / custom / skipped / pending / gate-denied) and the count balances — the invariant R2 broke (a `reject` verdict with no card path made a link vanish). Also pins `utm_` stripping (no `[redacted]` in a stored URL), first-link-per-platform, and probe-budget accounting (`probes_denied` counter — F3).

Second case: `recorded/linkinbio/linkinbio.spa.html` → 0 anchors is reported as `no_anchors`, never as success (N2).

### C3 — Full build replay + golden master

`tests/Feature/PreAccount/BuildReplayTest.php`. For each fixture account — IG→`partna` (personal, business-hair, restaurant, artist, zero-posts), GB→`business` non-food (barber), GB→`business` food (restaurant), GB gelateria — run `POST /api/public/signup/build` → `GeneratePreAccountSiteJob` synchronously with `Http::fake` **sequenced** over the recorded responses (actor → website → link-in-bio → Places → Fresha → CDN), `Queue::fake` for the async tail with explicit `assertPushed` for `LinkInBioScanJob`, `GoogleBusinessEnrichJob`, `SyncSubdomainToKvJob`, `MirrorMediaAssetJob`. Assert:

- identity: handle == subdomain, `display_name`/`first_name` trimmed, `sector` + `sector_source`, `status='unclaimed'`, `is_published=false`;
- connections + routing ledger balances; `routing.link_observations` written where the routing lane runs (URL stored verbatim);
- Places PII strip; `site.workplaces` fold (not `core.users.location_*`);
- Apify decision (`needsApify()`) matches the per-fixture prediction;
- **golden master** of `GET /api/public/profiles/{handle}` incl. `data.profile.pools.*` at `tests/fixtures/golden/profile/<fixture>.json`, with volatile fields (ids, timestamps, R2 URLs) normalised.

This is the offline replacement for a wave: what the 430-line prompt checked by hand in §1–§7 becomes one green run.

### C4 — Media mirror tests

`tests/Feature/Media/MirrorMediaAssetJobTest.php` (extend): real `recorded/media/ig-cdn.jpg` bytes → webp `site.media_variants` row, size cap, ≤ 6 gallery; recorded 403 / expired-signature response → `content.media_assets.mirror_attempts` incremented and `mirror_last_reason` set (R8, on `origin/development` at `4235d8d3b`), retry cap ends the loop, no silent drop; SSRF guard rejects a private-IP CDN URL.

### C5 — Postgres lane gap check

`tests/Postgres/` already carries the F9 partial index (`ClaimConcurrencyTest`) and the R3 cascade (`LinkObservationCascadeDeletionTest`). Remaining: the `link_observations` CHECK widened by X3 (N-E) — assert an insert with the routing lane's real payload shape lands; and confirm every table C3 writes to exists in the stand-in DDL (`LiveSourceScope` needs `content.source_items` → `sources` → `platform_connections`). Additive only.

### D1 — Scraper canaries

`php artisan canary:scrapers` scheduled daily (`routes/console.php`, off-peak, after the 03:xx window): for each scraper in C1, one known real target (a Linktree page, a Fresha venue, a Shopify store, a Places lookup, a YouTube channel; the Apify actor **weekly**, since it bills) → assert non-empty and the same top-level keys the C1 fixture has (`_captured_keys`). Failure → Nightwatch report with `canary.<scraper>` context; the remedy is *re-capture the fixture and re-run C1*, which shows what changed. Every canary fetch goes through `SafeUrlFetcher` or the scraper's own guarded transport (category A/B/C/D rules apply).

### D2 — Per-deploy build smoke (`launch-check` group `build`)

`scripts/launch-check/build-smoke.sh`, opt-in like `env`/`runtime`, dev-only (`launch_check_prod_gate`): 1 IG build + 1 GB build against `dev-api.partna.au` with two fixed, owner-approved source refs; polls to `ready`; asserts 202→ready ≤ N min, handle == subdomain, `https://<handle>.partna.au/` 200 via KV, `GET /profiles/{handle}` pools non-empty, mirror rate ≥ threshold with a reason on every miss, zero exceptions in the log window (polled `--minutes 2 --json` + dedupe, never `--live`), Apify spend equals prediction; then **deletes both builds** through the real deletion path. Every "could not check" is a FAIL (the launch-check verdict rule). Solves the per-IP cap by construction.

### D3 — Live wave template

`docs/reviews/TEMPLATE-live-wave.md`: ≤ 3 accounts, one question per wave, mandatory *verify every finding against the current tree* step, and a closing step *each finding becomes a fixture (A1) and a test (B/C)*. Existing waves are not re-run; the GB wave becomes the first D2 run plus C3's GB fixtures.

## 6. Ordering and effort

| Step | Depends on | Size | Value |
|---|---|---|---|
| B1 | — | S | headline invisible-platform count |
| B2, B3, B4 | — | S each | pins four known bug classes for free |
| A1, A2 | — | M | unlocks everything below |
| C1, C2, C4 | A1 | M | 22% of findings; scraper drift guard |
| B5, C3 | A1, B1 | L | the payoff — every platform + every pipeline shape offline |
| C5 | — | S | the 3 DB-only classes |
| D1, D2 | A1 | M | "did the world change", cheap and daily |
| D3 | all | S | doc |

Land in the order above; each step is mergeable alone.

## 7. Conventions and gotchas (each has cost a session)

- **Recall.** Keep each test file to one seam. `--filter` + Pint is broken in this repo — run by path.
- **Parallel suite.** No cross-file helper functions in test files (`reference_cross_file_test_helpers_break_parallel`); helpers live in `tests/Support/` or `Pest.php`. `paratest` accepts one path.
- **Vacuous assertions.** No negated `toContain`; chained `expect()` proves one assertion per run — isolate lanes; `toThrow(interface)` is vacuous.
- **SQLite ≠ Postgres.** Anything asserting a constraint goes in `tests/Postgres/`. `pgsql` driver is SQLite in the Feature suite.
- **Catalog is compiled.** B1/B5 must check `CompiledCatalog::isCompiled()` and compile the supported way; never hand-edit the artefact.
- **`surface_key`, not `platform`**, identifies `partna.*` surfaces. `RETIRED_SURFACES` is six items, not `partna.%`.
- **Routing lane stores the URL verbatim**; the three legacy write paths canonicalise. Ledger assertions must know which lane wrote the row.
- **Fixture PII.** Redact at capture; `PruneOrphanedReviewPiiCommand`-class data never lands in `tests/fixtures/`. LEGAL-2 applies to fixtures too.
- **Recorded ≠ raw item.** `platform_connections.payload` is the *stored* shape (PRIV-2 strip applied). Scraper contract fixtures must be the *raw* actor item — capture from `--from=live` once, or from `ingest.record_versions` where the ingest lane ran; label which in the manifest.
- **`cloud env:logs --live` is not a stream** — D2 polls `--minutes 2 --json` and dedupes.
- **Costs.** Only A1 `--from=live --confirm-spend`, D1's weekly actor canary, and D2 spend money. Everything else is free.
- **`Bus::dispatch` drops `ShouldBeUnique`**; C3 asserts dispatch via `Queue::fake`, not by side-effects.

## 8. Success criteria

- `composer test` (parallel) stays under its current wall-clock plus ≤ 20%.
- B1 reports the connectable / link-only / invisible split; B5 covers 100% of connectable surfaces; a new definition without fixture or golden file fails CI.
- C3 golden masters exist for all 8 fixture accounts; a deliberate one-field mutation in any recorded actor item is caught by C1 or C3.
- D2 runs green on dev on demand and after the next deploy; D1 is scheduled and reporting.
- The next live wave uses D3, ≤ 3 accounts, and closes by adding fixtures — no RESULTS finding of class (a) or (b) recurs.

## 9. Assumptions made (owner may override)

- Golden masters snapshot the **public wire** (`/profiles/{handle}` incl. pools) rather than DB rows — the wire is the contract consumers see, and DB shape churns with the pool programme.
- Fixture capture defaults to **the dev DB** (free) and only goes live with an explicit spend flag.
- D2's two source refs are chosen by the owner (a personal IG and a non-food GB listing) and stay fixed so runs are comparable.
- The GB wave prompt is retired in favour of C3's GB fixtures + D2, not run as a wave.
