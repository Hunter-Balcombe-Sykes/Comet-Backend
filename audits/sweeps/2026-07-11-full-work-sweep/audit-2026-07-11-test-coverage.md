# Test Coverage Audit — 2026-07-13

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `tests/Pest.php`, `tests/Feature/**`, `tests/Unit/**`, `tests/Integration/**`, `tests/Helpers/**`
- `app/Policies/**`
- Cross-referenced production files: `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `app/Jobs/Analytics/RecordAnalyticsEventJob.php`, `app/Jobs/ProcessImageVariantsJob.php`, `app/Jobs/ProcessVideoVariantsJob.php`, `app/Jobs/DeleteMediaArtifactsJob.php`, `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`, `app/Services/Cache/CacheLockService.php`

## Note on this adjudication

The DeepSeek draft (8 chunks, ~80 raw findings) systematically **hallucinated an untested codebase** — nearly every "zero coverage" claim (public sitepage resolution, handle-alias 301s, `SyncSubdomainToKvJob`, `RecordAnalyticsEventJob` dedup, moderation state transitions, media processing jobs, `CacheLockService` concurrency, webhook signature/re-delivery, `AccountCapabilities` gating, staff authorization, migration CHECK-constraint invariants, factory determinism) was directly contradicted by extensive existing test files found via `Read`/`Grep`/`Glob`. Those findings are dropped as hallucinated per the adjudication mandate. Only 7 findings survived verification against actual repo state.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **TEST-1** · P2 — Four recently-added policies have zero test coverage (allowed + denied)
    - **Where:** `app/Policies/ContentSelectionPolicy.php`, `app/Policies/EarlyAccessSignupPolicy.php`, `app/Policies/FeatureAvailabilityPolicy.php`, `app/Policies/UserSegmentPolicy.php` — no corresponding test file anywhere under `tests/`
    - **Affects:** Content-ownership gating on the sitepage background-content picker (`ContentSelectionPolicy`), and three staff-only gates (early access, feature availability, user segments).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Policies/ContentSelectionPolicyTest.php` asserting `view`/`manage` allow the site owner and `denyAsNotFound()` (404) a non-owner, plus a pending-deletion 423 case for `manage`.
        - Add tests for `EarlyAccessSignupPolicy`, `FeatureAvailabilityPolicy`, `UserSegmentPolicy` covering `staffView` (support+admin allowed) and `staffManage` (admin only, support denied) per their role checks.
    - **Technical:** Verified by grepping the full `tests/` tree for each class name — zero matches for all four, despite 14 of the other 18 policy classes having dedicated coverage (`tests/Unit/Policies/*`, `tests/Feature/Security/PolicyEnforcement/*`, `tests/Feature/Policies/EnquiryPolicyTest.php`). `PolicyCoverageTest.php` only sweeps `Gate::policy()` registration, not ability-logic correctness, so a bug in `ownerMatches()` or the `staffView`/`staffManage` role checks would ship silently.
    - **Plain English:** Four newer security checkpoints — like "only the site owner can edit this content" or "only admin staff can manage segments" — were added without a matching automated check. Every other checkpoint in the system has one; these four don't. If someone accidentally loosens one of these rules later, nothing will catch it.
    - **Evidence:**
        ```php
        // app/Policies/ContentSelectionPolicy.php
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }

        // app/Policies/UserSegmentPolicy.php
        public function staffView(PartnaStaff $actor, UserSegment|string|null $segment = null): bool
        {
            return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
        }
        ```

- [ ] **TEST-2** · P2 — Two observer tests mock the Eloquent `Site` model instead of using a real factory row
    - **Where:** `tests/Feature/User/UserObserverHandleChangeTest.php:121,199`, `tests/Feature/Core/ServiceObserverTouchSiteTest.php:26,63`
    - **Affects:** Confidence that `UserObserver`/`ServiceObserver` actually bump `site.sites.updated_at` — a real cache-busting dependency for `SiteCacheService`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Mockery::mock(Site::class)` with a real `Site::factory()->create()` (or `setRelation('site', $realSite)`) seeded via `setupSitesTable()`.
        - Assert `$site->fresh()->updated_at` actually changed, instead of asserting a `shouldReceive('touch')->once()` call.
    - **Technical:** Confirmed via direct `Read` (not the earlier failed regex grep, which false-negatived on the unescaped parens) — both files construct `Mockery::mock(Site::class)` and assert `shouldReceive('touch')`. Per Partna conventions the DB layer must be real, factory-seeded SQLite — mocking an Eloquent model means the test would still pass even if `touch()` were removed from the observer's calling code (Larastan won't catch a removed *call*, only a removed *symbol*).
    - **Plain English:** Instead of actually updating a real database row and checking the timestamp moved, these two tests use a stand-in object that just nods along when asked "did you get touched?" If the real update logic breaks, the stand-in still says yes.
    - **Evidence:**
        ```php
        // tests/Feature/User/UserObserverHandleChangeTest.php:121-122
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();

        // tests/Feature/Core/ServiceObserverTouchSiteTest.php:26-27
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();
        ```

- [ ] **TEST-3** · P2 — No full key-set snapshot test for `IndividualProfileResource` or `UserStaffResource`
    - **Where:** `app/Http/Resources/IndividualProfileResource.php`, `app/Http/Resources/UserStaffResource.php` — only `tests/Feature/Resources/UserPublicResourceTest.php` exists; `tests/Feature/Staff/StaffAdminNotesTest.php` spot-checks a single field (`admin_notes`), not the full shape
    - **Affects:** PII exposure on the public sitepage / audience confusion between the public and staff API surfaces — the highest-risk resource split in the platform.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Resources/IndividualProfileResourceTest.php` snapshotting the exact key set for a standard professional (mirroring `UserPublicResourceTest.php`'s pattern: assert present keys and explicitly assert absence of `primary_email`, `admin_notes`, internal ids).
        - Add `tests/Feature/Resources/UserStaffResourceTest.php` snapshotting the full staff-only key set.
    - **Technical:** Grepped the full `tests/` tree for all three resource class names — only `UserPublicResourceTest.php` does a genuine key-set assertion (`toHaveKey`/`not->toHaveKey` on 6 fields). `StaffAdminNotesTest.php` checks one field crosses the staff/self split correctly but doesn't freeze the resource's full shape, so a new field added to either resource without a `hidden`/whitelist update would ship undetected.
    - **Plain English:** The public page a visitor sees and the internal view staff use are supposed to show different information — staff can see private notes, visitors can't. We only have a full contract test for one of the three "who sees what" templates. A future change could add a new private field to the public template and nothing would catch it.
    - **Evidence:**
        ```php
        // tests/Feature/Resources/UserPublicResourceTest.php:7-33 — the ONLY full snapshot-style test
        it('returns display_name and partna_url, no PII', function () {
            $array = (new UserPublicResource($pro))->toArray(Request::create('/'));
            expect($array)
                ->toHaveKey('display_name', 'Evo')
                ->not->toHaveKey('primary_email');
        });
        // No equivalent file for IndividualProfileResource or UserStaffResource.
        ```

## P3 — Nice to have

- [ ] **TEST-4** · P3 — `SupabaseAuthHookController`'s malformed-payload branch (invalid UUID format) is untested
    - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:75-78`; closest test file `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php`
    - **Affects:** Supabase MFA-verification webhook — a regression in the UUID-format guard would go undetected until a malformed delivery actually arrives in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns 400 for a non-UUID user_id or factor_id', ...)` posting a signed payload with `user_id: 'not-a-uuid'` and asserting `assertStatus(400)->assertJson(['message' => 'Malformed payload'])`.
    - **Technical:** The controller already defends this path (`if (! preg_match($uuidPattern, $userId) ...) return response()->json(['message' => 'Malformed payload'], 400);`), but no test in `SupabaseAuthHookBruteForceTest.php` exercises it — that file only covers unsigned requests, success/failure recording, redelivery dedup, lockout, and the absent-webhook-id guard (all of which ARE well covered). The sibling `SupabaseEmailHookController` (same `VerifySupabaseHookSignature` middleware) has an equivalent `it('returns 422 when the payload is missing required fields', ...)` test — this is the one gap in an otherwise thoroughly tested webhook surface.
    - **Plain English:** The system already has code to politely reject a garbled sign-in verification message, but nobody has written a test proving that code actually works. It's a small, already-fixed gap in an otherwise well-tested area.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:75-78
        if (! preg_match($uuidPattern, $userId) || ! preg_match($uuidPattern, $factorId)) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }
        ```

- [ ] **TEST-5** · P3 — `ConditionalFetchStrategiesTest.php` claims three wired strategies but only tests two
    - **Where:** `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php:5,34,52`
    - **Affects:** Confidence that the third conditional-fetch strategy correctly raises `FetchNotModifiedException` on a 304.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Identify the third strategy implementing conditional-fetch (grep `app/Services/Platforms` for classes implementing the same interface as `YoutubeMusicFetch`/`OEmbedFetch`) and add a matching `it()` block, or correct the file-header comment if only two strategies are actually wired.
    - **Technical:** The file's header comment reads "304 behaviour for the three wired strategies," but only `it('YoutubeMusicFetch raises FetchNotModifiedException on a 304', ...)` and `it('OEmbedFetch raises FetchNotModifiedException on a 304', ...)` exist — verified via grep, no third `it()` block present.
    - **Plain English:** A code comment promises three safety checks were tested, but only two tests actually exist. Either a test is missing or the comment is stale — either way it's a small gap in an accounting note that should be trustworthy.
    - **Evidence:**
        ```php
        // tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php:5
        // 304 behaviour for the three wired strategies. Scrapers are mocked (hermetic — no
        // ... only 2 it() blocks follow: YoutubeMusicFetch (line 34), OEmbedFetch (line 52)
        ```

- [ ] **TEST-6** · P3 — `GoogleBusinessEnrichJob::failed()` has no test, unlike the sibling `MenuFetchJob`
    - **Where:** `app/Jobs/Platforms/GoogleBusinessEnrichJob.php:169`; closest existing test `tests/Feature/Platforms/GoogleBusinessApifyTest.php`
    - **Affects:** Operator visibility when Google Business enrichment fails terminally — a broken `failed()` handler would silently stop alerting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test analogous to `MenuScrapeFailureNotifyTest.php`'s `it('publishes a non-critical content_scrape warning when the menu job fails terminally', ...)`: instantiate `GoogleBusinessEnrichJob`, call `->failed(new RuntimeException(...))`, assert the expected notification/log side-effect fires exactly once.
    - **Technical:** Confirmed `GoogleBusinessEnrichJob` defines a `failed()` method (line 169) and confirmed via grep that no test in `GoogleBusinessApifyTest.php` (or elsewhere) exercises it, whereas the structurally identical `MenuFetchJob::failed()` has dedicated coverage. This is a narrow, single-job gap in an otherwise well-tested platform-integration job family.
    - **Plain English:** If the Google Business info-refresh job fails outright, nothing confirms an alert actually fires — unlike the very similar menu-scraping job, which already has this safety net tested.
    - **Evidence:**
        ```php
        // app/Jobs/Platforms/GoogleBusinessEnrichJob.php:169
        public function failed(Throwable $e): void
        {
        ```

- [ ] **TEST-7** · P3 — `StaffAccountCapabilitiesTest.php` seeds users via raw `DB::insert` instead of a factory
    - **Where:** `tests/Feature/Accounts/StaffAccountCapabilitiesTest.php:29,101`
    - **Affects:** Test maintainability — a required-column addition to `core.users` will fail these raw inserts at test time instead of picking up a factory default.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace both `DB::connection('pgsql')->table('core.users')->insert([...])` calls with `User::factory()->create([...])`.
    - **Technical:** Confirmed both occurrences via grep. Every other staff/user test in scope uses `User::factory()` or the `makeSiteOwner()`/`ovaMakeStaffUser()`-style helpers backed by factories; this file bypasses that, so it won't benefit from centralized factory defaults when the schema evolves.
    - **Plain English:** Most tests build a fake staff member using a standard "recipe" (the factory) that keeps working as the database changes shape. This one file hand-assembles the fake row by hand instead — it works today but will need a manual fix the next time the users table changes, instead of updating automatically like the rest of the suite.
    - **Evidence:**
        ```php
        // tests/Feature/Accounts/StaffAccountCapabilitiesTest.php:29
        DB::connection('pgsql')->table('core.users')->insert([
            'id' => $userId,
            'handle' => 'staff-'.Str::random(6),
            'display_name' => 'Staff Member',
            'account_type' => 'staff',
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Policy ability test coverage:** TEST-1
    - **Why grouped:** Standalone new-test-file work covering the four untested policy classes; no production code changes, low risk.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Test-fixture hygiene (mocks/raw inserts → real factories):** TEST-2, TEST-7
    - **Why grouped:** Same root-cause pattern — tests bypassing the standard factory-seeded-SQLite fixture convention — across different files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Resource snapshots + small job/webhook/platform test gaps:** TEST-3, TEST-4, TEST-5, TEST-6
    - **Why grouped:** All independent, small (S/M effort), test-only additions with no cross-file dependencies; efficient to knock out in one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0, auth/money/migration-touching, or L/XL-effort findings survived adjudication.
