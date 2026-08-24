# Backend Inheritance & Consolidation — Execution Audit (verified 2026-07-27)

Executable via
`execute audit audits/sweeps/2026-07-27-dead-code/BACKEND-INHERITANCE-CONSOLIDATION-VERIFIED.md`
(follows `scripts/audit/fix-flow.md`). Suggested branch slug: **`backend-inheritance`** →
`audit-fix/backend-inheritance-2026-07-27`.

## Scope

Source: `FULL-CODEBASE-BLOAT-INHERITANCE-AUDIT-PLAN.md` in this folder, section 4 — *"Comet-Backend —
Inheritance & consolidation opportunities — 2026-07-25"*, Tiers 1 & 2. That audit was run by the
frontend dev; this file is the **backend** re-verification pass, checked against the live working tree
on `development` (this repo — no staleness caveat).

**Not produced by `scripts/audit/audit.sh`** — the source was a manual sweep. This file re-verifies its
backend claims and reformats them to the `execute audit` contract.

**Verdict: all 17 backend items are real** — unlike the frontend section, where the flagship item was
fictional. Every file, class and method named below was confirmed to exist at the stated path. Four
scope corrections (where the audit under- or over-counted) are flagged **⚠ inline on the finding they
affect** — do not lose them in implementation.

**One item carries a live bug** (INH-1: protocol-relative URLs are mangled by three hand-rolled
`absolutize()` copies). Everything else is pure duplication removal that changes live behaviour without
changing intended behaviour — which is exactly the profile that needs an independent reviewer and a
real test-pass.

---

## Findings at a glance

| Tier | Count | IDs |
|---|---|---|
| P0 — blockers | 0 | — |
| P1 — high | 1 | INH-1 |
| P2 — medium | 5 | INH-3, INH-6, INH-7, INH-8, INH-D |
| P3 — low | 11 | INH-2, INH-4, INH-5, INH-9, INH-10, INH-A, INH-B, INH-C, INH-E, INH-F, INH-G |
| **Total actionable** | **17** | |
| Rejected / do-not-touch guards | 2 | see *Verified NOT to merge* |

---

## Execution policy  (how `execute audit` runs this file)

- **Plan:**       Opus 5
- **Implement:**  Sonnet 5
- **Review:**     Sonnet 5 — a *separate, independent* instance (never the implementer)
- **Combine plan+impl:** YES for XS/S effort · NO for M or for any unit carrying a behaviour-change
  risk flag below.
- **Per-item override:** escalate implement → Opus for **INH-6** (the `normalizeName` lockstep is
  load-bearing — silent drift is a correctness bug, not a style issue) and **INH-8** (transaction +
  row-lock + cache-invalidation choreography).
- **Verification bar specific to this file:** these are refactors, so "tests pass" is a weak signal —
  the pre-existing tests were written against the pre-existing structure. **Every unit's reviewer must
  confirm output/behaviour is unchanged**, ideally by response-shape assertion (INH-2, INH-4) or by a
  characterization test added *before* the refactor. A green `composer test` alone does not clear a
  unit here.
- **Blocker-gate note:** no unit in this file touches auth, money, or a DB migration. **INH-7** trips
  the *"auth"* keyword — it is **not** authorization; it is honeypot/timing anti-spam on public
  unauthenticated forms. It still gets its own bundle and its own review because it is a security
  *consistency* change across 4 entry points, but it does **not** require a sign-off pause.
- **Trigger:** say `execute audit <path to this file>` to run plan → implement → independent review per
  bundle/item. Everything under `## Suggested Bundled Sessions` **auto-runs**; everything under
  `## Standalone — do NOT bundle` **pauses for explicit go-ahead**. Full runbook:
  `scripts/audit/fix-flow.md`.

---

## Progress

- **Total findings: 17** — 1 / 17 done.
- P1 High: 1 of 1 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 11 complete
- **Scope decision 2026-07-27:** Bundle 1 (the only real defect) shipped. Bundles 2–7 and both
  standalone items **deliberately deferred to post-pilot** — they are pure duplication removal with no
  user-visible gain, and the verification bar in this file's own Execution policy makes them expensive
  to clear. Do not treat the remaining unticked boxes as neglected.
- Auto-run: Bundle 1 (1), Bundle 2 (1), Bundle 3 (1), Bundle 4 (3), Bundle 5 (3), Bundle 6 (3),
  Bundle 7 (3) = **15**.
- Standalone (pause for sign-off): INH-4, INH-8 = **2**.

---

## Suggested Bundled Sessions

Order: **Bundle 1 → 2 → 3** first (the bug fix, the one-line inconsistency, the biggest file-count
reduction). Then 4 → 7. Keep all of this off any dead-code **deletion** PR — these change live code
paths and need their own test-pass.

### Bundle 1 — `absolutize()` consolidation + the protocol-relative URL bug (P1 · XS) — AUTO-RUN, own review

Ships alone and first. It is the only unit in this file with a real defect attached, so it should not
wait behind refactor debates.

- [x] **INH-1** · P1 — Three hand-rolled `absolutize()` copies in `WebsiteScan` lack the
  protocol-relative guard their canonical sibling has, so `//cdn.example.com/logo.png` is mangled into
  `https://host//cdn.example.com/logo.png`
    - **Where:** `app/Services/WebsiteScan/FaviconFetcher.php`,
      `app/Services/WebsiteScan/PdfLinkDetector.php`,
      `app/Services/WebsiteScan/WebsiteGalleryCandidateExtractor.php` — canonical implementation lives
      at `app/Services/Http/MetadataParser.php::absolutize()`.
    - **Affects:** Website-scan asset discovery. Any source page serving favicons, PDFs or gallery
      images over protocol-relative URLs (still common on older sites and CDN-hosted assets) yields a
      broken absolute URL — the fetch then fails or silently drops the candidate.
    - **Effort:** XS (~20 min)
    - **What to do:**
        - Point the three buggy extractors at `MetadataParser::absolutize()`, or extract it to a small
          `App\Services\Http\UrlAbsolutizer` service if the `MetadataParser` dependency is awkward.
          Removes ~60 lines *and* the bug in one change.
        - `WebsiteLogoCandidateExtractor` and `MetadataParser` already have the guard — use one of them
          as the reference behaviour.
        - Add a regression case for a `//host/path` input asserting the scheme is inherited, not
          doubled.
    - **⚠ Do NOT merge in `app/Services/Platforms/WebsiteLinkHarvester.php::absolutize()`** — verified
      as a *genuinely different and correct* algorithm: directory-aware relative resolution via
      `dirname()`, and it rejects `mailto:` / `tel:` / `data:`. The source audit's correction here is
      right. Leave that method alone; folding it in would be a regression.
    - **Technical:** the buggy copies end with `$href[0] === '/' ? $origin.$href : …`. A
      protocol-relative URL also starts with `/`, so it takes the origin-prefix branch instead of the
      scheme-inherit branch. Confirmed by reading `FaviconFetcher`.
    - **Plain English:** Some websites link images with a shortcut that means "use the same http/https
      the page used" — it starts with two slashes. Three copies of our URL-fixing helper only check for
      *one* slash, so they treat those links as site-relative and glue the site's address onto the
      front, producing a nonsense URL. A fourth copy of the same helper already handles it correctly;
      the fix is to delete the three broken copies and use the good one.
    - **✅ DONE 2026-07-27 — and this finding's own prescription was WRONG. Do not re-apply it.**
      `MetadataParser::absolutize()` is **not** a superset of the buggy copies: it **drops the base-URL
      port**, has **no `data:` guard**, and returns the raw relative string (not `null`) on a hostless
      base. Pointing the three extractors at it would have fixed the `//` bug while introducing three
      new ones. The true canonical for this family was
      `WebsiteLogoCandidateExtractor::absolutize()`, which was extracted verbatim to
      `app/Services/Http/UrlAbsolutizer::absolutize()` (static, stateless — the consumers are `new`-ed
      in existing tests, so a constructor dep would have broken them). All **four** WebsiteScan
      extractors now delegate to it (9 call sites); `PdfLinkDetector`'s extra `mailto:`/`tel:`
      rejection moved up into `find()` to keep its behaviour byte-identical.
    - **⚠ A SIXTH copy exists that this audit missed:** `app/Services/Design/LogoAutoGrabber.php`
      (~`:471`) is directory-aware via `dirname()`, same as `WebsiteLinkHarvester` — **also do not fold
      it in.** `MetadataParser::absolutize()` left untouched; its port-dropping and `data:`-mangling
      still affect `LinkCardScraper` and `ScanPreviousWebsiteContentJob::menuPointerUrl` and are a
      separate open follow-up, not part of this unit.

### Bundle 2 — `BandcampConnectionResource` → extend `TileConnectionResource` (P3 · XS) — AUTO-RUN

- [ ] **INH-2** · P3 — `BandcampConnectionResource` hand-builds a `toArray()` that its two siblings get
  for free from the existing abstract base
    - **Where:** `app/Http/Resources/Platforms/BandcampConnectionResource.php`; siblings
      `YoutubeConnectionResource.php`, `AppleMusicConnectionResource.php`; base
      `app/Http/Resources/Platforms/TileConnectionResource.php`.
    - **Affects:** Nothing functionally today — it's an inconsistency that guarantees Bandcamp drifts
      out of sync the next time the tile response shape changes.
    - **Effort:** XS (~15 min)
    - **What to do:**
        - Change `BandcampConnectionResource` to `extends TileConnectionResource`, move its flat fields
          into `flatFields()`, delete the bespoke `toArray()`.
        - **Verification is the real work:** assert the JSON output is byte-identical before/after via a
          response/snapshot test. Do not ship on "tests still pass".
    - **Technical:** verified — `Youtube` and `AppleMusic` both `extends TileConnectionResource` and
      implement only `flatFields()`. `Bandcamp` produces the identical output shape but `extends
      ApiResource` directly.

### Bundle 3 — `BaseAnalyticsRequest` for the 8 public analytics requests (P2 · S) — AUTO-RUN

- [ ] **INH-3** · P2 — Eight analytics Form Requests each re-declare the same
  `prepareForValidation()` and the same site/session/visitor/utm rule block
    - **Where:** all 8 under `app/Http/Requests/Api/PublicSite/Analytics/` — `ActionSeenRequest`,
      `ActionTapRequest`, `ClickRequest`, `ItemSeenRequest`, `PageviewRequest`, `PingRequest`,
      `SectionDwellRequest`, `SectionSeenRequest`. Shared trait already exists at
      `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php` — **verified all 8 already use it**.
    - **Affects:** The public analytics beacon surface. A rule added to one file today silently misses
      the other seven; only 1–2 event-specific fields actually differ per class.
    - **Effort:** S (~30 min). Biggest single file-count reduction in this file — ~30 lines × 8.
    - **What to do:**
        - Add `BaseAnalyticsRequest` exposing the shared rules as a method; each subclass
          `array_merge()`s its own event-specific fields on top.
        - Keep all 8 classes — see the note below.
    - **Not a blocker:** `ActionTapRequest`'s comment about *"keeping its own class so beacons can
      diverge"* is about not sharing the **class**, not the **rules**. Every request stays its own
      subclass; only the rule block is inherited. Do not let this comment be read as a veto.

### Bundle 4 — Menu helper consolidation (P2 · M) — AUTO-RUN, own review · escalate implement → Opus

One session. These all touch the menu pipeline and would collide if split across parallel units.
**INH-6 is the highest-value item in the whole file** — the duplication is already known to be
load-bearing and fragile.

- [ ] **INH-6** · P2 — `cleanString()` / `normalizeName()` / `nextPosition()` are re-declared across the
  menu pipeline, and the `normalizeName` copies are documented as required-to-stay-identical
    - **Where:** `app/Services/Platforms/NormalizesMenuData.php` (the trait that should own all three),
      `app/Services/Platforms/MenuAiExtractor.php`, `app/Services/Platforms/MenuScanApplier.php`,
      `app/Services/Platforms/MenuMerger.php`,
      `app/Http/Controllers/Api/Platforms/MenuContentController.php`,
      `app/Jobs/Platforms/MenuFetchJob.php`.
    - **Affects:** Menu scan/merge correctness. `MenuContentController` + `MenuMerger` (`norm()`) carry
      a comment saying the implementation must stay **IDENTICAL to `MenuFetchJob::normalizeName`** —
      a suppressed dish must still match at rebuild time. Silent drift between those copies is a real
      correctness bug, not cosmetics.
    - **Effort:** S–M (~30 min)
    - **What to do:**
        - Move all three helpers into `NormalizesMenuData`; every caller uses the trait's version.
        - **Prioritise `normalizeName`/`norm()`** — that's the fragile one. Do it first, verify a
          suppressed-dish-survives-rebuild case.
        - `nextPosition()` — `MenuScanApplier` + `MenuContentController`. Straightforward.
    - **⚠ SCOPE CORRECTION (audit under-counted):** `cleanString` appears in **6 files, not 4** — also
      `app/Http/Requests/BaseFormRequest.php` and
      `app/Services/Platforms/GoogleBusinessApifyScraper.php`. **Byte-check those two against the menu
      version before folding them in.** A form-request `cleanString` may be a different helper that
      merely shares the name; merging non-identical implementations here would be a silent behaviour
      change on an unrelated surface. If they differ, leave them and note it.

- [ ] **INH-D** · P2 — `resolveMenu()` duplicated across three menu callers
    - **Where:** `app/Http/Controllers/Api/Platforms/MenuContentController.php`,
      `app/Services/Platforms/MenuScanApplier.php`, **and
      `app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php`**.
    - **⚠ SCOPE CORRECTION (audit under-counted):** the source audit listed **2** files; it missed
      `ScanPreviousWebsiteContentJob`. It is **3**. Refactoring only two would leave the job on a
      diverging copy — the exact failure mode this item exists to prevent.
    - **Effort:** S
    - **What to do:** extract a `MenuResolver` service; all three callers use it.

- [ ] **INH-G** · P3 — Menu Create/Update Request pairs duplicate their common rule blocks
    - **Where:** `app/Http/Requests/Platforms/{Create,Update}Menu{Category,Item}Request.php`.
    - **Effort:** XS. Lowest-stakes item in this file.
    - **What to do:** shared base/trait for the common rules; the Create/Update subclasses layer
      required-ness on top.

### Bundle 5 — Platform scraper / seeder / request bases (P3 · M) — AUTO-RUN

Three independent extractions in the same `app/Services/Platforms` + `app/Http/Requests/Platforms`
neighbourhood. One session, one review over the whole diff.

- [ ] **INH-5** · P3 — Shop scrapers duplicate `MAX_IMAGES` and a byte-identical private `json()` helper
    - **Where:** `app/Services/Platforms/{Shopify,WooCommerce,BigCartel,Generic,Squarespace}Scraper.php`,
      base `app/Services/Platforms/PlatformScraper.php`.
    - **⚠ SCOPE CORRECTION (audit over-counted):** it is **not** a clean family of five.
      `Shopify`, `WooCommerce` and `BigCartel` share **both** `MAX_IMAGES = 25` and the identical
      `json()` fetch-and-decode helper — that trio is the clean win. `GenericShopScraper` has
      `MAX_IMAGES = 25` but **no** `json()`. `SquarespaceScraper` has **neither**. The source audit's
      "structurally the same family" hedge is doing real work here.
    - **Effort:** S (~30 min)
    - **What to do:** add `ShopScraper extends PlatformScraper` holding `MAX_IMAGES` + `json()`; the
      **trio** extends it. Generic may adopt the constant. Squarespace opts in only if it genuinely
      matches — do not force it.

- [ ] **INH-9** · P3 — Two shop Requests duplicate an identical url-normalizing `prepareForValidation()`
    - **Where:** `app/Http/Requests/Platforms/AddShopBrandRequest.php`,
      `app/Http/Requests/Platforms/AddShopProductRequest.php` — both normalize `url` via
      `PlatformInput::urlish()`.
    - **Effort:** XS (~10 min)
    - **What to do:** a small `NormalizesUrlField` trait, or a shared shop-request base. Safe.

- [ ] **INH-10** · P3 — Two shop seeders duplicate the tombstone bail-out and the lock→upsert→refresh
  choreography
    - **Where:** `app/Services/Platforms/ShopBrandSeeder.php`,
      `app/Services/Platforms/ShopProductSeeder.php` — both docblocks already say *"same convention as
      `EventsSeeder`"*.
    - **What's duplicated:** identical soft-delete tombstone bail-out, plus a near-identical
      `Cache::lock()` → `updateOrCreate` an `IntegrationConnection` with a MARKER → mutate → refresh
      cache.
    - **Effort:** S (~30 min)
    - **What to do:** `PlatformSeederBase` with `checkTombstone()` + `acquireConnectionLock()`. Aligns
      with the existing platform write-locking convention (`withConnectionLock()`); the reviewer should
      confirm the lock semantics are preserved exactly, not merely approximated.

### Bundle 6 — Public form-submission controllers: spam guard, customer upsert, lead logging (P2 · M) — AUTO-RUN, own review

One session — all three findings touch the same four public controllers and would collide if split.
Trips the *auth* keyword but is **not** authorization (see Execution policy); no sign-off pause.

- [ ] **INH-7** · P2 — Four public controllers each hand-roll the same honeypot + form-timing anti-spam
  checks
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php`,
      `PublicEnquiryController.php`, `PublicEmailSubscriptionController.php`,
      `PublicEarlyAccessController.php` — **verified: all 4** carry the honeypot (`website` field) and
      the timing check.
    - **Affects:** Public unauthenticated form intake. Duplicated spam logic means the timing window can
      drift between entry points — an attacker only needs the weakest of the four. This is a
      security-**consistency** win, not just DRY.
    - **Effort:** S (~25 min)
    - **What to do:** a `GuardsAgainstFormSpam` trait exposing `assertHoneypot()` and
      `assertFormTiming()`. Preserve the existing behaviour exactly: non-empty `$data['website']` →
      **fake success** (not an error), and `form_started_at_ms` delta checked against
      `config('partna.form_timing.min_ms')` / `max_ms`. Keep the same log events (`honeypot_hit`,
      `too_fast`).
    - **⚠ Reviewer must confirm** the fake-success path still returns the same shape — turning a silent
      honeypot into a visible rejection tells the spammer they were caught.

- [ ] **INH-E** · P3 — Customer upsert-by-email duplicated across two public controllers
    - **Where:** `PublicEnquiryController.php`, `PublicEmailSubscriptionController.php`.
    - **⚠ SCOPE CORRECTION (audit over-counted):** **confirmed 2 of 3.**
      `PublicCustomerLeadController` only *normalizes* the email — its write path differs. Verify before
      treating it as a third caller; folding it in blindly would change its write semantics.
    - **Effort:** S
    - **What to do:** `PublicCustomerUpsertService::upsertByEmail(userId, email, fullName, source)`; the
      two confirmed callers adopt it.

- [ ] **INH-F** · P3 — Lead-submission logging duplicated across two public controllers
    - **Where:** `PublicCustomerLeadController.php`, `PublicEnquiryController.php` — both build
      identical `LeadSubmission` rows (`ip_hash` / `user_agent` / `referrer` via
      `AnalyticsEventSanitizer`).
    - **Effort:** XS
    - **What to do:** a `LogsLeadSubmissions` trait. Confirm the sanitizer is still applied on both
      paths — the `ip_hash` is a privacy control, not a formatting detail.

### Bundle 7 — Platform strategy bases (Tier 2, P3 · S) — AUTO-RUN

Low-urgency, low-churn. Fold into whatever PR already touches these files if this bundle hasn't run yet.

- [ ] **INH-A** · P3 — Two Highlights strategies duplicate the same `apply()` shape
    - **Where:** `app/Services/Platforms/Strategies/Highlights/VimeoHighlights.php`,
      `YoutubeMusicHighlights.php` — the `keyBy → map → filter → take(MAX) → values` pipeline.
    - **⚠ Do NOT widen to four.** `Youtube` and `Bandcamp` already share `RefreshesLatestTile`, which is
      a **different contract**. Forcing all four into one abstraction is a regression risk, not a win.
      The source audit got this right; carry the guard forward.
    - **Effort:** XS. Trait for the shared pipeline, this pair only.

- [ ] **INH-B** · P3 — Two Apple Fetch strategies duplicate the same four-step body
    - **Where:** `app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php`,
      `ApplePodcastFetch.php` — both are "call scraper → extract latest → merge → update flat fields".
    - **Effort:** XS. Parameterized base. Only 2 files, low churn — genuinely opportunistic.

- [ ] **INH-C** · P3 — `parseEvent` duplicated across the two event scrapers
    - **Where:** `app/Services/Platforms/EventbriteScraper.php`,
      `app/Services/Platforms/HumanitixScraper.php` — the `Humanitix` comment already states it
      *"mirrors `EventbriteScraper::parseEvent`"*, i.e. the lockstep is self-aware and undefended.
    - **Effort:** S. Shared `PlatformScraper::parseEventNode()`.

---

## Standalone — do NOT bundle (pause for sign-off before implementing)

- [ ] **INH-4** · P3 — Reservation-provider Connect classes + Resources: 6 files → 2
    - **Where:** `app/Services/Platforms/Strategies/Connect/{NowBookit,OpenTable,ResDiary}Connect.php`
      and `app/Http/Resources/Platforms/{NowBookit,OpenTable,ResDiary}ConnectionResource.php`.
    - **Why standalone:** it is the largest structural change in this file — collapsing six concrete
      classes into two configurable ones. It rewires a live third-party connect surface, so the shape of
      the abstraction (config-driven vs. abstract-base) is a design call worth your sign-off before an
      implementer commits to one.
    - **What's duplicated (shape confirmed):** each Connect class `implements ConnectStrategy` with
      `resolve(string): ConnectResult`, following the same resolve-URL → extract-identifier →
      `ConnectResult::ok/fail` flow. They differ only in the identifier field names
      (`accountId`+`venueId` / `rid` / `microsite`) and which service they call. The three Resources are
      the same story on the response side.
    - **Effort:** S–M (~45 min). Own commit.
    - **What to do:** one field-name-configurable Connect class + one configurable Resource (config =
      identifier field names + the service to call). **Verification:** assert each provider's JSON
      response is byte-identical before/after, and that a failed resolve still produces the same
      `ConnectResult::fail` payload per provider.

- [ ] **INH-8** · P2 — `design_kits` transactional write choreography duplicated in a controller and a
  service
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php::writeDesignKit()`
      (`:108`), `app/Services/WebsiteScan/DesignKitAccentApplier.php::apply()` (`:21`).
    - **Why standalone:** both copies run `transaction → row lock → updateOrInsert() → cache
      invalidation`. That is exactly the kind of sequence where a "harmless" refactor silently changes
      lock scope or invalidation ordering and produces an intermittent stale-cache or deadlock bug that
      no test will catch. Plan first; escalate implement → Opus.
    - **Effort:** S (~30 min). Two callers today, a third likely.
    - **What to do:** a `WriteDesignKitAction` owning the transaction / lock / upsert / invalidate
      choreography. **Each caller keeps its own pre-processing** — column-filtering for the controller,
      the fill-if-empty guard for the auto-accent applier — and shares only the risky transactional
      write. Aligns with the platform write-locking convention (`withConnectionLock()` etc.).
    - **⚠ Reviewer must confirm:** the lock is still acquired before the read that decides the upsert,
      and cache invalidation still happens inside/after the same transaction boundary as before — not
      moved.

---

## Verified NOT to merge — recorded so a future sweep doesn't rediscover them

These look like duplication and are not. Both guards were correct in the source audit and are carried
forward deliberately.

1. **`app/Services/Platforms/WebsiteLinkHarvester.php::absolutize()`** — do not fold into INH-1. It is a
   different, correct algorithm: directory-aware relative resolution via `dirname()`, and it rejects
   `mailto:` / `tel:` / `data:` schemes. Merging it into the `MetadataParser` version would lose both
   behaviours.
2. **`YoutubeHighlights` / `BandcampHighlights`** — do not fold into INH-A. They already share
   `RefreshesLatestTile`, a different contract from the Vimeo/YoutubeMusic `apply()` pipeline. Four-way
   unification is a regression, not a consolidation.

---

## Nothing was dropped as a false positive

Every backend item in the source audit verified true. The only edits versus the raw audit are:

- the **four scope corrections** flagged inline — INH-5 (trio, not five), INH-6 (`cleanString` in 6
  files, not 4), INH-D (3 files, not 2), INH-E (2 callers confirmed, not 3);
- the **two do-not-merge guards** above, which the source audit already got right;
- the structural reformat into bundles, IDs and checkboxes for `execute audit`.

## Appendix — not examined here

The source doc's `Partna-Frontend` inheritance section is covered separately by
`FRONTEND-INHERITANCE-CONSOLIDATION-VERIFIED.md` in this folder (where the flagship item was found to
be **fictional**). Nothing in this file should be read as covering it, and none of it is executable
from this repo.
