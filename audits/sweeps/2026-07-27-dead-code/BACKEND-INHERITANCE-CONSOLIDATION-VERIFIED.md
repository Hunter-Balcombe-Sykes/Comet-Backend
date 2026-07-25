# Backend inheritance & consolidation — verified & needed

Distilled from `FULL-CODEBASE-BLOAT-INHERITANCE-AUDIT-PLAN.md` (section 4, *"Comet-Backend —
Inheritance & consolidation opportunities — 2026-07-25"*, Tiers 1 & 2). The audit was run by
the frontend dev; this file re-verifies its **backend** findings against the live code in this
repo.

- **Verified against:** the working tree on branch `development` (this repo — live code, no staleness caveat).
- **Result:** unlike the frontend section (where the flagship item was fictional), **all 17 backend items are real** — the duplication exists as described. Four scope nuances are flagged inline where the audit slightly under- or over-counted.
- **All paths verified.** Every file/class/method named below exists at the stated path.

---

## Do first — best win-per-effort

### 1. Consolidate `absolutize()` — and fix a live bug in the same change  *(Tier 1, item 1)*
- **Files:** `app/Services/WebsiteScan/{FaviconFetcher,PdfLinkDetector,WebsiteGalleryCandidateExtractor,WebsiteLogoCandidateExtractor}.php`, canonical `app/Services/Http/MetadataParser.php::absolutize()`.
- **What's duplicated + the bug (verified):** `FaviconFetcher`, `PdfLinkDetector`, `WebsiteGalleryCandidateExtractor` each hand-roll `absolutize()` **without a protocol-relative guard**. Confirmed by reading `FaviconFetcher`: the final line is `$href[0] === '/' ? $origin.$href : …`, so a protocol-relative URL like `//cdn.example.com/logo.png` (starts with `/`) becomes `https://host//cdn.example.com/logo.png` — **mangled**. `WebsiteLogoCandidateExtractor` and `MetadataParser` already have the fix.
- **Fix:** point the 3 buggy extractors at `MetadataParser::absolutize()` (or extract it to a tiny `App\Services\Http\UrlAbsolutizer` service). Fixes the bug and removes ~60 lines in one change.
- **⚠ Do NOT merge in `app/Services/Platforms/WebsiteLinkHarvester.php::absolutize()`** — it's a genuinely different, correct algorithm (directory-aware relative resolution via `dirname()`, rejects `mailto:`/`tel:`/`data:`). The audit's correction here is right. Leave it alone.
- **Effort:** ~20 min. **Highest value** — it's the only item with a real bug bundled in.

### 2. `BandcampConnectionResource` → extend `TileConnectionResource`  *(Tier 1, item 3)*
- **Files (verified):** `app/Http/Resources/Platforms/{Youtube,AppleMusic,Bandcamp}ConnectionResource.php` + abstract base `TileConnectionResource.php`.
- **What's wrong (verified):** `YoutubeConnectionResource` and `AppleMusicConnectionResource` both `extends TileConnectionResource` and only implement `flatFields()`. `BandcampConnectionResource` has the identical output shape but `extends ApiResource` directly and hand-builds the whole `toArray()`. The shared base already exists — this one file just doesn't use it.
- **Fix:** change `BandcampConnectionResource` to `extends TileConnectionResource`, move its flat fields into `flatFields()`, delete the bespoke `toArray()`.
- **Effort:** ~15 min. Near one-line inconsistency fix; verify the JSON output is byte-identical after (snapshot/response test).

### 3. `BaseAnalyticsRequest` — 8 files  *(Tier 1, item 9)*
- **Files (verified):** all 8 under `app/Http/Requests/Api/PublicSite/Analytics/` — `ActionSeenRequest`, `ActionTapRequest`, `ClickRequest`, `ItemSeenRequest`, `PageviewRequest`, `PingRequest`, `SectionDwellRequest`, `SectionSeenRequest`. **Confirmed all 8 use the `ResolvesPublicSiteSubdomain` trait** (`app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php`).
- **What's duplicated:** identical `prepareForValidation()` one-liner + identical `site_id`/`subdomain`/`session_id`/`visitor_id`/`referrer`/`utm_*` rule block; only 1–2 event-specific fields differ per class.
- **Fix:** a `BaseAnalyticsRequest` exposing the shared rules as a method; each subclass `array_merge()`s its own fields. Cuts ~30 lines × 8.
- **Not a blocker:** `ActionTapRequest`'s comment about "keeping its own class so beacons can diverge" is about not sharing the *class*, not the *rules* — both still subclass independently.
- **Effort:** ~30 min. Biggest single file-count reduction.

---

## Do next — genuine, similar-effort wins (batch as one "consolidation" PR)

> These touch live behavior — give them their own test-pass, separate from any dead-code deletion PR.

### 4. Reservation-provider Connect classes + Resources: 6 → 2  *(Tier 1, item 2)*
- **Files (verified):** `app/Services/Platforms/Strategies/Connect/{NowBookit,OpenTable,ResDiary}Connect.php` + `app/Http/Resources/Platforms/{NowBookit,OpenTable,ResDiary}ConnectionResource.php`.
- **What's duplicated (shape confirmed):** each Connect class `implements ConnectStrategy`, `resolve(string): ConnectResult`, same resolve-URL → extract-identifier → `ConnectResult::ok/fail` shape, differing only in field names (`accountId+venueId` / `rid` / `microsite`) and which service it calls. Resources are the same story on the response side.
- **Fix:** one field-name-configurable Connect class + one configurable Resource (config: identifier field names + service). 6 files → 2.
- **Effort:** ~45 min. Bigger refactor than 2/3; worth its own commit.

### 5. Shop scrapers — extract a `ShopScraper` base  *(Tier 1, item 4)*
- **Files (verified):** `app/Services/Platforms/{Shopify,WooCommerce,BigCartel,Generic,Squarespace}Scraper.php` + base `PlatformScraper.php`.
- **What's duplicated + scope correction:** `Shopify`, `WooCommerce`, `BigCartel` all define `MAX_IMAGES = 25` **and** a byte-identical private `json()` fetch-and-decode helper. **Correction:** `GenericShopScraper` has `MAX_IMAGES = 25` but **no** `json()` helper, and `SquarespaceScraper` has **neither** (the audit's "structurally the same family" hedge is doing real work). So the clean win is the **Shopify/Woo/BigCartel trio**.
- **Fix:** `ShopScraper extends PlatformScraper` holding `MAX_IMAGES` + `json()`; the trio extends it. Generic can adopt the constant; Squarespace opt in only if it actually matches.
- **Effort:** ~30 min.

### 6. Menu helper trio → into the `NormalizesMenuData` trait  *(Tier 1, item 5)*
- **Files (verified):** `app/Services/Platforms/{NormalizesMenuData,MenuAiExtractor,MenuScanApplier,MenuMerger}.php`, `app/Http/Controllers/Api/Platforms/MenuContentController.php`, `app/Jobs/Platforms/MenuFetchJob.php`.
- **What's duplicated:**
  - `cleanString()` — the trait already has one; `MenuAiExtractor`, `MenuScanApplier`, `MenuContentController` re-declare it. **⚠ Scope correction:** `cleanString` also appears in `app/Http/Requests/BaseFormRequest.php` and `app/Services/Platforms/GoogleBusinessApifyScraper.php` — **6 files total, not 4.** Verify those two are byte-identical to the menu one before folding them in; a form-request `cleanString` may be a different helper that happens to share the name.
  - `normalizeName()`/`norm()` — `MenuContentController` + `MenuMerger` (`norm()`), and the comment says it must stay "IDENTICAL to `MenuFetchJob::normalizeName`" — **duplication already known to be load-bearing and fragile** (a suppressed dish must match at rebuild). This is the highest-value part: a silent drift here is a real correctness bug.
  - `nextPosition()` — `MenuScanApplier` + `MenuContentController`.
- **Fix:** move all three into `NormalizesMenuData`; every caller uses the trait's version. Prioritize `normalizeName` (fragility). Per-file byte-check `cleanString` first.
- **Effort:** ~30 min.

### 7. `GuardsAgainstFormSpam` trait — 4 public controllers  *(Tier 1, item 8)*
- **Files (verified):** `app/Http/Controllers/Api/PublicSite/{PublicCustomerLead,PublicEnquiry,PublicEmailSubscription,PublicEarlyAccess}Controller.php` — all 4 have the honeypot (`website` field) + form-timing checks.
- **What's duplicated:** identical honeypot (`$data['website']` non-empty → fake success) + timing check (`form_started_at_ms` delta vs `config('partna.form_timing.min_ms'/'max_ms')`), same log events (`honeypot_hit`, `too_fast`).
- **Fix:** a `GuardsAgainstFormSpam` trait with `assertHoneypot()` / `assertFormTiming()`. Centralizes the logic **and** keeps the timing window consistent across all 4 entry points — a security-consistency win, not just DRY.
- **Effort:** ~25 min.

### 8. `WriteDesignKitAction` service  *(Tier 1, item 10)*
- **Files (verified):** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php::writeDesignKit()` (`:108`), `app/Services/WebsiteScan/DesignKitAccentApplier.php::apply()` (`:21`).
- **What's duplicated:** both do `transaction → row lock → updateOrInsert() → cache invalidation` against `design_kits`.
- **Fix:** a `WriteDesignKitAction` owning the transaction/lock/upsert/invalidate choreography; each caller keeps its own pre-processing (column-filtering for the controller, fill-if-empty guard for the auto-accent applier) and shares the risky transactional write. (Aligns with the platform write-locking convention — `withConnectionLock()` etc.)
- **Effort:** ~30 min. 2 callers today, a 3rd likely.

### 9. `NormalizesUrlField` trait — 2 shop requests  *(Tier 1, item 6)*
- **Files (verified):** `app/Http/Requests/Platforms/{AddShopBrand,AddShopProduct}Request.php` — identical `prepareForValidation()` normalizing `url` via `PlatformInput::urlish()`.
- **Fix:** a tiny `NormalizesUrlField` trait (or a shared shop-request base). Small, safe.
- **Effort:** ~10 min.

### 10. `PlatformSeederBase` — 2 shop seeders  *(Tier 1, item 7)*
- **Files (verified):** `app/Services/Platforms/{ShopBrand,ShopProduct}Seeder.php` (both docblocks say "same convention as `EventsSeeder`").
- **What's duplicated:** identical soft-delete tombstone bail-out + near-identical `Cache::lock() → updateOrCreate IntegrationConnection with a MARKER → mutate → refresh cache`.
- **Fix:** `PlatformSeederBase` with `checkTombstone()` + `acquireConnectionLock()`; ready for the next seeder needing the shape.
- **Effort:** ~30 min.

---

## Tier 2 — real, lower-urgency (pick up opportunistically)

| # | What | Files (verified) | Fix | Note |
|---|------|------------------|-----|------|
| a | Highlights `apply()` pair | `Strategies/Highlights/{Vimeo,YoutubeMusic}Highlights.php` | Trait for the `keyBy→map→filter→take(MAX)→values` shape | **Only this pair** — `Youtube`/`Bandcamp` already share `RefreshesLatestTile` (different contract). Don't force all 4 into one. |
| b | Apple Fetch base | `Strategies/Fetch/{AppleMusic,ApplePodcast}Fetch.php` | Parameterized base ("call scraper → extract latest → merge → update flat fields") | Only 2 files; low churn. |
| c | Event `parseEvent` | `Platforms/{Eventbrite,Humanitix}Scraper.php` | Shared `PlatformScraper::parseEventNode()` | `Humanitix` comment already says it "mirrors `EventbriteScraper::parseEvent`" — self-aware lockstep. |
| d | Menu `resolveMenu()` | `MenuContentController`, `MenuScanApplier`, **+`Jobs/Platforms/ScanPreviousWebsiteContentJob.php`** | `MenuResolver` service | **Scope correction: 3 files, not 2** — audit missed `ScanPreviousWebsiteContentJob`. |
| e | Customer upsert | `PublicEnquiryController`, `PublicEmailSubscriptionController` | `PublicCustomerUpsertService::upsertByEmail(userId, email, fullName, source)` | **Confirmed 2 of 3.** `PublicCustomerLeadController` only normalizes email — its write path differs; verify before treating as a 3rd caller. |
| f | Lead-submission logging | `PublicCustomerLeadController`, `PublicEnquiryController` | `LogsLeadSubmissions` trait | Both build identical `LeadSubmission` rows (ip_hash/user_agent/referrer via `AnalyticsEventSanitizer`). |
| g | Menu Request pairs | `Requests/Platforms/{Create,Update}Menu{Category,Item}Request.php` | Shared base/trait for common rules; Create/Update layer required-ness | Lowest-stakes. |

---

## Suggested order
**1 → 2 → 3** first (bug fix, one-line inconsistency, biggest reduction). Then batch **4–10** as a single dedicated "consolidation" PR with its own test-pass (they change live behavior — keep them off any deletion PR). Tier 2 a–g: fold into whatever PR already touches those files.

## Nothing was dropped as false
Every backend item verified true. The only edits vs. the raw audit are the four scope corrections flagged inline (items 5, 5-cleanString, Tier 2 d, Tier 2 e) and the explicit "don't merge `WebsiteLinkHarvester`" / "don't force all 4 Highlights" guards, which the audit already got right and I'm carrying forward.
