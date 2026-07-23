# Signup v2 — Execution Plan (items 11-17, all decisions resolved)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, per
> the giant-run standing methodology — no subagent dispatch) to implement this plan
> task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two remaining scraper defects (Instagram media, SVG logos), add
store/product/event/organiser intelligence to all bio/link scanning, and rebuild signup
into: identity → OTP → mandatory password → claim → skippable per-type setup steps.

**Architecture:** Backend-first (phases A-C are pure Comet-Backend, independently
shippable); frontend phases D-F extend the existing `pre-account-signup-form.tsx` step
machine and reuse existing endpoints everywhere one exists — exactly one new backend
endpoint (`GET /api/onboarding/suggestions`) drives every setup step for both account types.

**Tech stack:** Laravel 12 + Pest (Comet-Backend), Next.js 16 + vitest (Partna-Frontend),
Supabase Auth (email provider: OTP today, + password after phase D).

**Diagnostic history:** `2026-07-23-signup-testing-repairs.md` items 11-17 hold the full
root-cause evidence. THIS doc is canonical for decisions and execution; that one is the
why-record.

---

## Resolved decisions (owner-confirmed 2026-07-23)

| # | Decision | Resolution |
|---|---|---|
| D1 | Password | **Mandatory** at signup (no skip), both signup + claim flows. **No retrofit** for existing accounts — new signups only. Login page gains password sign-in (OTP stays as fallback). |
| D2 | Item 17 adds | **Auto-add** (not suggest-and-confirm). Caps bound blast radius: 5 stores / 20 products / 10 events / 20 custom links; everything one-click removable. |
| D3 | Business setup steps | **No** store step, **no** sector step, **no** workplace step (auto-seeded by GB connect). Business gets: Instagram-if-missing + up to 2 sector suggestions. |
| D4 | Partna setup steps | Sector-if-missing → Store (+discount code, product select) → Workplace (sector-gated, list below) → up to 2 sector suggestions. All individually skippable. |
| D5 | Link-in-bio hosts | Add the full curated set (13 new, list in A2). |
| D6 | Workplace-ask sectors | Determined below (26 slugs) — "works AT a venue that isn't their own site identity." |
| D7 | Suggestion count | ≤2 for both account types. |
| D8 | Booking suggestion UX | ONE url input; provider (Fresha/Square) smart-detected by the registry; Fresha continues into its existing staff-selection flow. |

### D6 — workplace-ask sector list (partna only; canonical, lives as a backend const)

Ask: **Beauty & Personal Care** (all 8: hair-salon, barber, nail-technician, makeup-artist,
esthetician, spa, tattoo-artist, brows-lashes) · **Health & Fitness** (all 8:
personal-trainer, gym, yoga-instructor, nutritionist, physiotherapist, chiropractor,
therapist, dentist) · **Professional Services** (6: accountant, lawyer, financial-advisor,
real-estate-agent, insurance-broker, mortgage-broker) · **Hospitality** (1: bartender) ·
**Education** (3: music-teacher, driving-instructor, dance-instructor). **26 total.**

Never ask: all Food & Drink (the account IS the venue), Retail, Home & Trade, Automotive,
Creative & Entertainment, consultant/marketing-agency/it-services/virtual-assistant,
accommodation/event-venue/event-planner/wedding-planner, tutor/life-coach/course-creator,
other. Rationale: those are own-business/mobile/venue-identity sectors where a "your
workplace" ask reads as a category error.

---

## Final flows

**Partna signup** (`/account/sign-up`, pre-account form):
`options` → `identity` (Instagram handle) → `auth` (email → OTP → **password [new,
mandatory]** → claim) → **`setup` [new]** (Sector? → Store? → Workplace? → ≤2 suggestions,
each skippable, driven by `GET /api/onboarding/suggestions`) → hard-navigate to dashboard.

**Business signup:** same until `setup`, which renders: Instagram-if-missing? → ≤2
suggestions (gap-fill only — GB auto-sync already seeded booking/reservations/ordering/
workplace/socials per capability matrix; see repairs doc item 15 corrected section).

**Claim flow** (`/claim/[subdomain]`, invited/staff-built): email → OTP → **password
[new, mandatory]** → claim → dashboard. **No setup steps here (explicit non-goal this
pass)** — invited users land on the dashboard's existing surfaces.

---

## Phase A — quick independent fixes (backend, ship first)

> **✅ PHASE A COMPLETE + LIVE-VERIFIED (2026-07-23).** Commits `a7e00849` (A1+A2),
> `18be63d2`, `c33a7224`. Live verification against `supernormal_180` surfaced TWO further
> stacked root causes the code-reading passes couldn't see, both fixed:
> 1. **Media disk Unauthorized** — `InstagramConnectionSeeder` + `DeleteMirroredMediaJob`
>    hardcoded `Storage::disk('media')`, whose config-cached creds are stale at runtime on
>    Laravel Cloud (platform R2 creds inject post-`config:cache`). Every mirror put/delete
>    ever attempted returned Unauthorized — ALSO the true mechanism behind item 3's
>    `UnableToDeleteFile` (not an absent-key quirk). Both now route through
>    `MediaDiskResolver` like every working media path.
> 2. **snake_case actor fields** — the figue actor returns raw Instagram GraphQL naming
>    (`profile_pic_url_hd`, `display_url`, `video_url`); the reader only knew legacy
>    camelCase, so profile pics never resolved and reels were counted but never picked.
>    Both shapes now read.
>
> Final live state (3rd run, 09:06 UTC): `posts: 12`, payload has `images[0]` on R2,
> `videoUrl` + `videoPoster` + `profilePicUrl` all populated, `last_refresh_status: ok`,
> zero storage exceptions. `LinkInBioScanJob` now fires for the linkin.bio URL (802ms DONE;
> its outbound-link typing is Phase C's job).

### Task A1 — Instagram media: send `includeRecentPosts`, delete dead `resultsLimit` (item 11)

**Files:** Modify `app/Services/Platforms/InstagramScraper.php` · Test
`tests/Unit/Platforms/InstagramScraperTest.php` (or nearest existing scraper test home)

- [ ] A1.1 In `fetchProfile()` (~line 38) change the request body to
      `['profiles' => [$username], 'includeRecentPosts' => true]`; delete the
      `RESULTS_LIMIT` const (line 17-18) and its comment; note the actor's real cap (12
      posts) where `latestMedia()`'s docblock mentions the pool.
- [ ] A1.2 Grep `latestMedia(`/`latestPosts` consumers for any ≥12-pool assumption
      (`InstagramConnectionSeeder`, content selection); adjust comments only — logic
      already tolerates any count.
- [ ] A1.3 Test: fixture profile WITH `latestPosts` → `latestMedia()` picks photo+video
      (exists); add a request-body assertion via `Http::fake()` that
      `includeRecentPosts: true` is sent and `resultsLimit` is absent.
- [ ] A1.4 `composer test` targeted → full. Commit
      `fix: enable includeRecentPosts on Instagram scrape (actor default suppressed all posts)`.

### Task A2 — link-in-bio host list (item 16 + D5)

**Files:** Modify `app/Services/Platforms/LinkInBioDetector.php` · Test alongside existing
detector tests

- [ ] A2.1 `HOSTS` becomes:
      `['linktr.ee', 'msha.ke', 'beacons.ai', 'stan.store', 'linkin.bio', 'lnk.bio',
      'bio.link', 'campsite.bio', 'snipfeed.co', 'komi.io', 'hoo.be', 'taplink.cc',
      'solo.to', 'liinks.co', 'heylink.me', 'allmylinks.com', 'direct.me']`.
- [ ] A2.2 Unit test: each new host (+ a subdomain form) matches; `example.com` doesn't.
- [ ] A2.3 Full suite, commit, **deploy with A1** (one push).
- [ ] A2.4 LIVE VERIFY (owner reconnects `supernormal_180` Instagram, or staff re-run):
      `instagram.latest_media` log shows `posts > 0`, payload gains `images[0]`/`videoUrl`;
      `LinkInBioScanJob` fires for the linkin.bio URL (log + resulting rows). Record the
      result in this doc.

## Phase B — SVG logo path (item 12)

> **✅ PHASE B COMPLETE + LIVE-VERIFIED (2026-07-23).** Commits `2c96b5e8` (B1 SVG branch)
> + `6e27ecb7` (extractor fixes). B0 gate PASSED live against the deployed dev container
> (`removed=true vectorized=yes` for SVG input) — B1 shipped as the pure storage-layer
> branch, pinned by 3 tests (SVG+logo+flag-on stores `.svg` + dispatches logo job;
> flag-off and non-logo SVGs keep rejecting; HTTP layer still never admits SVG).
>
> Live verify then exposed the REAL blocker for this site, different from item 12's
> inference: supernormal.net.au's logo is NOT an inline SVG — it's a plain
> `<img class="Header-branding-logo" src="//images.squarespace-cdn.com/...jpg?format=1500w">`
> in a div OUTSIDE the semantic `<header>`, which `WebsiteLogoCandidateExtractor` (a)
> never collected (first-`<header>`-scoped pass caught only UI icon SVGs — the source of
> the `svg-unsafe` rejections) and (b) would have URL-mangled anyway (protocol-relative
> `//` treated as a path). Both fixed: bounded doc-wide logo/branding-hinted rescue pass
> (max 6, deduped) + protocol-relative resolution.
>
> Final live state: logo-grab decision `uploaded (875x87)` on the true wordmark; row
> `logo_full` `ready` with variants `optimized:webp, maximized:webp, vector:svg`. The
> SVG-input branch itself is proven by the B0 container test + suite (this site's logo
> happened to be raster).

### Task B0 — GATE: container SVG support

- [ ] B0.1 Verify the logo-processor container accepts `image/svg+xml` input: one dev call
      through `LogoProcessorClient::process()` with a small safe SVG (tinker or a throwaway
      test hitting the dev processor URL), or read the processor service's source if in
      reach. **If NO → STOP this phase, write findings here, re-plan (needs server-side
      rasterization — materially bigger).**

### Task B1 — SVG-aware logo singleton path

**Files:** Modify `app/Services/Media/MediaUploadService.php` (`uploadSingleton`,
~line 160-200) · Modify nothing in `ImageVariantService` (stays raster-only) · Test
`tests/Feature/Media/` singleton coverage home

- [ ] B1.1 In `uploadSingleton()`: when `$file` MIME is `image/svg+xml` AND purpose is
      `logo_full`/`logo_square` AND `config('partna.logo_removal.enabled')` — store the
      original directly (correct `.svg` key + hash naming mirroring `storeOriginal()`'s
      `{basePath}/original_{hash}.svg`, `private` visibility, `original_mime` recorded) and
      dispatch `dispatchLogoJob()`; skip `ImageVariantService::storeOriginal()`. All other
      inputs: unchanged path.
- [ ] B1.2 Non-logo SVG (gallery/cover uploads) must still reject exactly as today — add an
      explicit test pinning that.
- [ ] B1.3 Tests: SVG+logo purpose+flag-on → row created, `.svg` original stored, logo job
      dispatched (Queue::fake); flag-off → rejected as today; raster logo unchanged.
- [ ] B1.4 Full suite, commit, deploy.
- [ ] B1.5 LIVE VERIFY: re-run supernormal website scan (or owner does a fresh throwaway
      signup) → `logo_full` fills from the inline header SVG; check `site_media` +
      `media_variants` (vector row when VTracer produced one). Record here.

## Phase C — commerce/event link intelligence (item 17)

> **✅ PHASE C COMPLETE + LIVE-VERIFIED (2026-07-23).** Commits `874e5250` + `e35e4f20`.
> Delivered: C1 classifier categories (event/event-organiser/shop, scraper-delegated
> patterns, humanitix org-before-event + square-stays-booking pinned by tests); C2 as
> job-context seeders (EventsSeeder / ShopBrandSeeder / ShopProductSeeder) — **deliberate
> deviation from the plan text:** controllers were NOT refactored to delegate; the seeders
> mirror the controller flows' write mechanics (caps/locks/dedup/tombstones, constants
> cross-referenced "keep in lockstep") following the codebase's established
> parallel-implementation convention for scraped seeds (GoogleBusinessAutoSync /
> InstagramAutoSync), which keeps every frozen HTTP contract byte-untouched. Also: the
> plan's "no organiser cap exists" note was wrong — `maxAccounts() = 5` lives in the
> controller trait; the seeder mirrors it. C3 routing with DISC-7 fail-closed at BOTH
> layers; C4 single-URL `ProbeCommerceLinksJob` with 6-probe budgets at both dispatch
> sites. 15 new tests; suite 4895 green.
>
> **Live verification (supernormal account):**
> - linkin.bio page: unrolls, but serves a 6.4KB client-rendered JS shell with ZERO
>   anchors (verified by direct fetch) — the documented JS-rendering limit, exactly as
>   accepted up front. Nothing to classify; nothing vanished.
> - Real store probe (`supernormal.net.au/-store`): first run exposed a probe gap — a
>   Squarespace store LISTING has no product JSON-LD, so `readProductPage` said
>   no_product and fell to a custom link while the provider detector would have
>   recognized it. Fixed (`e35e4f20`: detector gets a second chance on reachable
>   no-product pages). Re-probe: `resolved: true` → real `ShopBrand` row
>   (`supernormal-net-au`, provider squarespace, "Supernormal Restaurant | Melbourne")
>   via marker connection, zero products (picker fills later — same contract as manual
>   connect). Stale pre-fix custom link cleaned up.

### Task C1 — Tier-1 classifier categories

**Files:** Modify `app/Services/Platforms/WebsiteLinkHarvester.php` (`classify()`) · Test
existing harvester test home

- [ ] C1.1 Inject/instantiate nothing new — add pure host+path checks delegating to the
      scrapers' own normalizers for validation:
      `event-organiser` when `EventbriteScraper::normalizeOrgUrl($url)` or
      `HumanitixScraper::normalizeOrgUrl($url)` returns non-null (platform =
      eventbrite/humanitix; label = platform label);
      `event` when `normalizeEventUrl` hits (Humanitix org check MUST precede event —
      shared host, `/host/` discriminates);
      `shop` for decisive store hosts `*.myshopify.com` / `*.bigcartel.com`.
      `square.site`/`squareup.com` stays `booking` (documented ambiguity).
- [ ] C1.2 Unit tests per category incl. the humanitix org-before-event ordering pin and
      square-stays-booking pin.
- [ ] C1.3 Targeted suite green; commit.

### Task C2 — seeder extractions (controllers keep frozen contracts)

**Files:** Create `app/Services/Platforms/ShopBrandSeeder.php`,
`app/Services/Platforms/ShopProductSeeder.php`,
`app/Services/Platforms/EventsSeeder.php` (account + standalone in one class, per-platform
scraper resolved via ctor map) · Modify `ShopController::addBrand()`/`addProduct()` and
`EventsPlatformController::addAccount()`/`addStandaloneEvent()` to delegate · Tests: new
seeder feature tests + existing controller tests MUST pass unchanged

- [ ] C2.1 `ShopBrandSeeder::seed(User, array $detected, ?string $discount): ?ShopBrand` —
      extracted from `addBrand()`'s post-detection body (lock, `MAX_BRANDS = 5` cap,
      updateOrCreate by brand_id, catalog-cache warm). Controller keeps detection + its
      422 shapes, then delegates.
- [ ] C2.2 `ShopProductSeeder::seed(User, array $product): ?ShopBrand` — extracted from
      `addProduct()`'s locked body (individual bucket, `MAX_INDIVIDUAL_PRODUCTS = 20`,
      dedup by productId, transactional rebuild, refresher).
- [ ] C2.3 `EventsSeeder::seedAccount(User, string $platform, string $url): bool` +
      `seedStandalone(User, string $platform, string $url): bool` — extracted from
      `EventsPlatformController` (normalize → fetch → lock → cap → `writeConnection`).
      `MAX_STANDALONE_EVENTS = 10` enforced. **Organiser-account cap: none exists in the
      controller — ADD one to the seeder path (propose 5; scanned-page auto-add must not
      be unbounded) and leave the manual controller path exactly as-is.**
- [ ] C2.4 Feature tests per seeder: cap hit → null/false (no throw), dedup, idempotent
      re-seed. Existing controller tests untouched and green (contract freeze proof).
- [ ] C2.5 Full suite; commit.

### Task C3 — routing new categories through `handleClassifiedLink()`

**Files:** Modify `app/Services/Platforms/InstagramAutoSync.php` · Tests alongside its
existing suite

- [ ] C3.1 Extend `ACTIONABLE` with `shop => 'shop'`, `eventbrite/humanitix` orgs/events →
      categories `event-organiser`/`event`; route: `shop` → dispatch into C4's probe job
      (host-decisive shops still need the provider probe → brand profile); `event` →
      `EventsSeeder::seedStandalone`; `event-organiser` → `EventsSeeder::seedAccount`.
- [ ] C3.2 Gates, all mandatory: `can_autosync_scraped_connections` (DISC-7 fail-closed),
      `isPendingDeletion()` short-circuit, soft-delete tombstone respect for
      `shop`/`eventbrite`/`humanitix` rows (tombstoned → custom-link fallback, never
      resurrect), per-run `seenPlatforms` dedup.
- [ ] C3.3 Tests: each category seeds; each gate blocks; tombstone routes to fallback.
- [ ] C3.4 Full suite; commit.

### Task C4 — `ProbeCommerceLinksJob` + fallback rewiring

**Files:** Create `app/Jobs/Platforms/ProbeCommerceLinksJob.php` · Modify
`InstagramConnectionSeeder::autoSaveUnmatchedLinks()` + `LinkInBioScanJob` (both fallback
sites) · Tests: new job feature tests

- [ ] C4.1 Job: `(string $userId, array $urls)` — scraping queue, `ShouldBeUnique` on
      user+sha1(sorted urls), timeout 90s, ≤6 URLs honoured (excess → straight to
      `CustomLinkSeeder`, logged count). Per URL: `GenericShopScraper::readProductPage()`
      → product hit → `ShopProductSeeder`; `OUTCOME_STORE_PAGE` (use returned `storeUrl`)
      or Tier-1 `shop` class → `ShopProviderDetector::detectDetailed()` →
      `ShopBrandSeeder`; miss/unreachable → `CustomLinkSeeder`. Every branch respects
      C3.2's gates (resolve `User` once).
- [ ] C4.2 Rewire: `autoSaveUnmatchedLinks()` and `LinkInBioScanJob`'s unclassified +
      unmatched fallbacks now dispatch this job instead of calling `CustomLinkSeeder`
      directly. (Site-chrome same-host filter in `LinkInBioScanJob` stays upstream,
      unchanged.)
- [ ] C4.3 Tests: product-hit, store-hit, miss→custom-link, budget cap + logging,
      uniqueness, gates.
- [ ] C4.4 Full suite; commit; deploy phases C1-C4 together.
- [ ] C4.5 LIVE VERIFY (needs A2 deployed): owner reconnects `supernormal_180` → its
      linkin.bio page unrolls → record exactly what each outbound link produced (typed
      adds vs custom links) in this doc. Postgres-writer smoke rule applies: check the
      real rows in Supabase, not just logs.

## Phase D — mandatory password (item 13, both auth surfaces + login)

> **✅ PHASE D COMPLETE (2026-07-23).** FE commit `d5320c9a`, deployed to production
> (Vercel READY). D0 gate passed empirically without waiting on a dashboard check: the
> project's public GoTrue settings endpoint reports `email: true` + `disable_signup:
> false` (fetched with the publishable key) — the Email provider carries both OTP and
> password auth. **D3 was already done before this phase existed**: the login page has
> had full password sign-in end to end (identifier resolution, `AuthPasswordField`,
> forgot-password reset flow, MFA challenge) — only the SIGNUP surfaces were
> passwordless. Shipped: shared `components/fields/auth-password-pane.tsx` (mandatory,
> ≥8 chars client-side with the server policy as authority, no skip control) inserted
> post-OTP/pre-claim in BOTH the wizard's auth step and `/claim/[subdomain]`; claim-side
> generic failures now return to the password pane (retryable) instead of a dead verify
> pane. 15 tests across the two surfaces; suite 614 green; typecheck+lint clean; both
> flows' reachable states browser-verified with zero console errors. Final proof of the
> full set-password→sign-back-in loop rides the owner's live signup (verification
> matrix row D).

### Task D0 — GATE (owner): Supabase email+password enabled

- [ ] D0.1 Owner confirms in Supabase dashboard (project `glncumufgaqcmqhzwrxm` → Auth →
      Providers → Email) that password sign-in is enabled + notes the minimum-length
      policy. Client validation mirrors it (≥8 unless policy says more).

### Task D1 — password phase in signup auth step

**Files:** Modify
`app/(app)/account/(auth)/sign-up/pre-account/auth-and-processing-step.tsx` · Test
sibling `.test.tsx`

- [ ] D1.1 Extend the step's phase union: `"email" | "verify" | "password"`. On OTP verify
      success (after `saveStoredSession`, line ~125): `setPhase("password")` instead of
      `void claim(...)`; stash the access token in state.
- [ ] D1.2 Password pane (AuthCardHeader "Create your password" + one `Input
      type="password"` + inline `AuthFieldError`): validate ≥8 (mirror D0 policy);
      `await supabase.auth.updateUser({ password })`; success → `void claim(storedToken)`.
      Failure → inline error, stay. **No skip control** (D1 decision). No back-to-OTP from
      here (code already consumed).
- [ ] D1.3 Copy tweak: the email pane's "no password needed" subtitle (line ~166) now
      reads "We'll send you a one-time code to verify it's you."
- [ ] D1.4 Tests: OTP success → password pane (not claim); short password → error, no
      claim; updateUser success → claim called with token; updateUser failure → error
      shown, claim NOT called.

### Task D2 — password phase in claim flow

**Files:** Modify `app/(app)/claim/[subdomain]/page.tsx` · Test sibling

- [ ] D2.1 Same insertion: `Phase` union gains `"password"` between verify success
      (line ~156) and `claim()` (line ~157). Same pane composition (shared small component
      if clean — co-located `password-pane.tsx` under sign-up/pre-account with the claim
      page importing it is a cross-page-dir violation; instead put it in
      `components/fields/auth-password-pane.tsx` since both flows are auth surfaces).
- [ ] D2.2 Tests mirroring D1.4 for this page.

### Task D3 — login page password option

**Files:** Read `app/(app)/account/(auth)/log-in/` first (not yet read — STOP-gate rule);
modify its form · Tests

- [ ] D3.1 Add password sign-in (`supabase.auth.signInWithPassword`) as the primary path
      with "email me a code instead" fallback to the existing OTP flow. Existing OTP-only
      accounts (all current users — no retrofit per D1 decision) keep working via the
      fallback.
- [ ] D3.2 Tests: password path, wrong-password error, OTP fallback intact.
- [ ] D3.3 `npm run typecheck && npm run lint`; deploy FE; owner live-verifies one fresh
      signup end to end (sets password, signs out, signs back in WITH the password).

## Phase E — partna setup steps (item 14)

> **✅ PHASES E + F COMPLETE (2026-07-23).** Backend `673cfe0c` (E1, deployed to dev
> Laravel Cloud), FE `24808bd7` (E2+F, pushed to production). E1:
> `GET /api/onboarding/suggestions` (`OnboardingSuggestions` service) — flags +
> capability-filtered ≤2 suggestions; the `can_use_*` filter IS the type divergence
> (food business → reservations+ordering, partna → booking) so ONE sector table serves
> both types; `WORKPLACE_SECTORS` (26, D6) lives here as the canonical const; prefills
> classify the Instagram scan's unmatched links; new dirs wired into the audit-sweep
> chunks per the pipeline-integrity guard; 10 endpoint tests. E2+F: `setup-steps.tsx`
> as wizard step 4 of 4 — partna: Sector (Combobox) → Store (connect + feature-products
> pick) → Workplace (sector-gated) → suggestions; business: Instagram-if-missing →
> suggestions. Suggestion cards post to the existing smart-detect facades
> (`booking/detect`, `reservations/detect`, `events/add` — hence ONE `events` card for
> Eventbrite+Humanitix, deviation noted in E1's docblock) or the platform's `/connect`
> with its registry field name. Every step skippable; flags-call failure or an empty
> step list goes straight to the dashboard (fail-open — setup is a bonus, never a
> blocker); drafts never restore into the post-claim step. 6 component tests covering
> both type sequences; suite 620 green; typecheck+lint clean; wizard renders
> "Step 1 of 4" live with zero console errors. Final proof rides the owner's live
> partna + business signups (verification matrix rows E/F).

### Task E1 — backend: `GET /api/onboarding/suggestions`

**Files:** Create `app/Services/Onboarding/OnboardingSuggestions.php` +
`app/Http/Controllers/Api/User/Onboarding/OnboardingController.php` · Route in
`routes/api/user.php` (authed group) · Tests `tests/Feature/Onboarding/`

- [ ] E1.1 Service returns:
      `{ sector, askSector, askStore, askWorkplace, askInstagram, suggestions: [{key,
      label, prefillUrl}] }` where: `askSector` = partna && sector null; `askStore` =
      partna; `askWorkplace` = partna && sector ∈ D6 list (const `WORKPLACE_SECTORS`)
      && no `site.workplaces` row; `askInstagram` = business && no live instagram
      connection; `suggestions` = the item-14 table (const, keyed by sector slug; the
      repairs doc's table is the source of truth) filtered to not-already-connected
      platforms, ≤2, `prefillUrl` from the Instagram payload's `unmatched` when a URL
      classifies to that suggestion's platform family.
- [ ] E1.2 Capability discipline: derive account type ONLY via `AccountCapabilities`
      (`google_business_full_sync` as the business discriminator — never raw
      `account_type`), per the repo LAW.
- [ ] E1.3 Feature tests: partna no-sector, partna sector-with-workplace, partna
      already-connected filtering, business food vs non-food (reservations/ordering vs
      booking gaps), prefill from unmatched, ≤2 cap.
- [ ] E1.4 Full suite; commit; deploy (endpoint is additive, safe ahead of FE).

### Task E2 — frontend: `setup` step + sub-steps

**Files:** Modify `pre-account-signup-form.tsx` (Step union + `handleClaimed`) · Create
sibling `setup-steps.tsx` (+ sub-components co-located) · Tests

- [ ] E2.1 Step union += `"setup"`; `TOTAL_STEPS = 4`; `handleClaimed` now
      `clearDraft(); setStep("setup")` (keep the `PENDING_BUILD_STORAGE_KEY` write); the
      hard `window.location.replace("/account/overview")` moves to setup completion/skip.
      Mid-setup refresh: draft is already cleared → user lands per proxy/authed routing;
      acceptable (steps are skippable) — no draft-restore INTO setup.
- [ ] E2.2 `SetupSteps` fetches E1 once (authed — session cookie already saved), renders
      the applicable sequence for partna: Sector picker (`GET /profile/sector-options`,
      `PUT /profile/sector`) → Store (url + optional discount code →
      `POST /platforms/shop/brands`; on success show fetched products with select-any →
      `PUT /platforms/shop/brands/{id}/selection`; `store_unreachable`/`unsupported_store`
      422s render the API's own message + a skip nudge) → Workplace (name input + optional
      Places lookup reusing the identity step's search UI → `PUT /site/workplace`) →
      Suggestions (≤2 cards; booking = one URL input smart-detected per D8; socials/media
      platforms = their standard connect input; prefillUrl pre-populates). Every step:
      "Skip" button, advancing state machine; finishing/last-skip → hard navigate.
- [ ] E2.3 Tests: sequence renders per flags; each skip advances; store 422 shows message
      + skip; completion navigates. typecheck+lint.
- [ ] E2.4 Deploy; owner live retest: fresh partna signup end to end (this also
      re-exercises A1/A2/C live behaviour on a brand-new account). Record results here.

## Phase F — business setup steps (item 15; depends on E1/E2 scaffolding)

- [ ] F1.1 `SetupSteps` business branch: Instagram-if-missing (existing instagram connect
      input; `askInstagram`) → Suggestions (same component as E2, server already computed
      the gap-fill set). No sector/store/workplace steps (D3 decision).
- [ ] F1.2 Tests: business flags render the right sequence; suggestions reflect food vs
      non-food gap logic (mock E1 payloads). typecheck+lint.
- [ ] F1.3 Deploy; owner live retest: fresh business signup (supernormal pattern) —
      confirm no workplace/store/sector asks appear and suggestions only show genuine
      gaps. Record here.

---

## Verification matrix (what "done" means per phase)

| Phase | Proof |
|---|---|
| A | Live reconnect: `posts > 0` in `instagram.latest_media`, media in payload; linkin.bio unrolled (job log + rows) |
| B | `logo_full` populated from inline SVG on a real re-scan; variants present |
| C | linkin.bio page's links produce typed rows (ShopBrand/products/events/custom) in Supabase — enumerated in this doc |
| D | Fresh signup sets a password and can sign back in with it; OTP fallback intact |
| E | Fresh partna signup walks Sector→Store→Workplace→Suggestions, all skippable, lands on dashboard |
| F | Fresh business signup sees only Instagram-if-missing + gap suggestions |

## Non-goals (explicit, this pass)

- No password retrofit surface for existing accounts (D1 decision).
- No setup steps on the claim flow (`/claim/[subdomain]`) — password only.
- No JS rendering for link-in-bio pages (plain fetch; client-rendered aggregators yield
  nothing — accepted).
- No Square-Online store detection (`square.site` stays booking).
- No referral-code field in the store step (`referralUrl` remains a dashboard follow-up).
- SerpApi menu-images work stays shelved (owner instruction, earlier).

## Execution order

A → B → C (backend, in order; each deployed + live-verified before the next) → D → E → F
(frontend, sequential). D0/B0 are hard gates — if either fails, stop that phase and
re-plan in this doc before touching code.
