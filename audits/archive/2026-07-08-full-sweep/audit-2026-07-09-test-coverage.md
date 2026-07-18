# Test Coverage Audit — 2026-07-09

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- tests/Feature/{Security,Policies,PublicSite,Subdomain,Webhooks,Account,Accounts,Analytics,Moderation,Cache,Resources,Export,Console,Content,Gallery,Jobs,Media,Observers,Platforms,Notifications,User,Staff,Architecture,Requests,Bootstrap}
- tests/Unit/{Policies,Jobs,Resources,Analytics}
- app/Policies/*.php
- app/Http/Resources/{PublicSite/IndividualProfileResource.php,UserStaffResource.php,UserPublicResource.php}
- app/Jobs/{Cloudflare/SyncSubdomainToKvJob.php,DeleteMediaArtifactsJob.php,Notifications/SendTransactionalNotificationEmailJob.php,Analytics/RecordAnalyticsEventJob.php}
- app/Http/Controllers/Api/PublicSite/*.php
- app/Providers/AppServiceProvider.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 7 complete

---

**Adjudication note:** DeepSeek's draft (6 scope chunks) contained 47 raw findings, of which **28 were dropped as hallucinated or stale** after verification against the actual repo — the draft repeatedly claimed test files/directories "do not exist" that in fact do, and repeatedly claimed critical paths (public sitepage resolution, handle-alias 301/404, handle-rename lifecycle, GDPR export, analytics dedup, moderation state machine, media variant jobs, policy ability coverage) have **zero** coverage when comprehensive tests exist under file names/directories the per-chunk scans didn't discover (e.g. `tests/Feature/Analytics/ClickDedupTest.php`, `tests/Feature/PublicSite/IndividualProfileControllerTest.php`, `tests/Feature/PublicSite/PublicSiteControllerShowTest.php`, `tests/Feature/Subdomain/SubdomainAvailabilityTest.php`, `tests/Feature/Moderation/StaffCase{Triage,Decide,Escalate}Test.php`, `tests/Feature/Security/PolicyEnforcement/*.php` (14 policy-ability test files), `tests/Feature/User/{AccountDeletion,DataExport}/*.php`, `tests/Feature/Console/SweepStaleExportsCommandTest.php`, `tests/Unit/MediaJobReliabilityTest.php`, `tests/Feature/Cache/{CacheLockServiceTest,WarmPublicSiteCacheJobTest}.php`). This is a genuinely well-tested codebase; the survivors below are real, narrow, verified gaps.

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — No snapshot test for `IndividualProfileResource` / `UserStaffResource` — the two highest-risk PII-leak surfaces have no key-set guard
    - **Where:** `app/Http/Resources/PublicSite/IndividualProfileResource.php`; `app/Http/Resources/UserStaffResource.php`
    - **Affects:** Every visitor to a public sitepage (`IndividualProfileResource` is the unauthenticated `/api/public/profiles/{handle}` payload — the highest-traffic response in the platform) and every staff-facing user detail view (`UserStaffResource`).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Resources/IndividualProfileResourceTest.php` (or `tests/Unit/Resources/...`) that resolves the resource against a fixture `User`/`Site` and asserts the exact top-level key set (`profile`, `designKit`, `designMedia`, `skeletonId`, `publicConfig` per the class docblock), explicitly asserting the absence of `primary_email`, `auth_user_id`, `first_name`, `last_name`.
        - Add `tests/Feature/Resources/UserStaffResourceTest.php` following the same key-set pattern, asserting staff-only fields ARE present (this one is the audience-inversion check).
        - Follow the exact pattern already established and working in `tests/Feature/Resources/UserPublicResourceTest.php` (`->toHaveKey(...)` / `->not->toHaveKey(...)` assertions against a fixture model).
    - **Technical:** `tests/Feature/Resources/` contains exactly one file — `UserPublicResourceTest.php` — which snapshots `UserPublicResource`'s key set correctly (asserts `display_name`/`partna_url` present, `handle`/`first_name`/`last_name`/`primary_email` absent). `IndividualProfileResource` (`app/Http/Resources/PublicSite/IndividualProfileResource.php`) and `UserStaffResource` (`app/Http/Resources/UserStaffResource.php`) have no equivalent anywhere in `tests/Feature/Resources/` or `tests/Unit/Resources/`. The existing `IndividualProfileControllerTest.php` mocks `IndividualProfilePayloadBuilder` entirely, so it never actually resolves the real `IndividualProfileResource` class — the controller-level 200 test proves the envelope shape (`{'data': ...}`) but not the resource's field allowlist. A future developer adding a field to the underlying User/Site model for an internal purpose has no guard preventing it from silently appearing in the public payload.
    - **Plain English:** The public profile page — the one every visitor sees — has no automated "packing list" check confirming exactly which fields go out the door. We do have that check for the simpler public user-card resource, and it works well; we're missing the same check on the two highest-stakes ones: the actual public profile payload, and the staff-only user view. If someone later adds a new internal field to a user record and it accidentally rides along into the public resource, nothing catches it before a visitor sees it.
    - **Evidence:**
        ```php
        // app/Http/Resources/PublicSite/IndividualProfileResource.php:3-19
        namespace App\Http\Resources\PublicSite;
        /**
         * Public-safe shape for an individual professional's profile page (§28.8).
         * ...
         *   - `profile` — content (engine fields + base profile)
         *   - `designKit` — per-user design vars (nested camelCase), partial
         *   - `designMedia` — content-pool media (polymorphic image/video, camelCase, ordered)
         *   - `skeletonId` — picks which code-side skeleton renders
         *   - `publicConfig` — analytics endpoint + platform-wide keys

        // tests/Feature/Resources/UserPublicResourceTest.php:7-25 — the ONLY resource
        // snapshot test that exists; no equivalent file targets IndividualProfileResource
        // or UserStaffResource.
        it('returns display_name and partna_url, no PII', function () {
            $array = (new UserPublicResource($pro))->toArray(Request::create('/'));
            expect($array)->toHaveKey('display_name', 'Evo')->not->toHaveKey('handle');
        ```

## P2 — Should fix

- [ ] **#TEST-2** · P2 — `ContentSelectionPolicy` has zero test coverage — its two abilities are the only ones in `app/Policies/` untested by any of the three policy-test locations
    - **Where:** `app/Policies/ContentSelectionPolicy.php` (entire class, registered at `app/Providers/AppServiceProvider.php:203`)
    - **Affects:** Authorization for the sitepage background-content picker (`ContentController`) — a regression in `ownerMatches()`'s site-relation resolution would silently pass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Security/PolicyEnforcement/ContentSelectionPolicyEnforcementTest.php` following the exact pattern of the 14 sibling files already in that directory (e.g. `FeedbackPolicyEnforcementTest.php`): `view` allowed for owner / 404 for non-owner; `manage` allowed for owner / 404 for non-owner / 423 for pending-deletion owner.
        - Seed the skeleton via `setRelation('site', $site)` per the class's own docblock contract.
    - **Technical:** Confirmed via exhaustive search: `app/Policies/*.php` has 15 classes; every one of the other 14 has at least one ability test across `tests/Unit/Policies/`, `tests/Feature/Policies/`, or `tests/Feature/Security/PolicyEnforcement/` (14 files) — except `ContentSelectionPolicy`, which appears in none of them despite being actively registered (`Gate::policy(ContentSelection::class, ContentSelectionPolicy::class)`, not in `POLICY_EXEMPT`). This is a genuine, isolated gap, not part of the broader "policies are untested" pattern several DeepSeek chunks incorrectly claimed (that pattern is false — see adjudication note above).
    - **Plain English:** Every other lock on every other door in the app has been tested by someone actually trying the handle. This one door — the background-picture picker — has never been tried. The lock code looks fine on paper, but nobody's confirmed it actually works.
    - **Evidence:**
        ```php
        // app/Policies/ContentSelectionPolicy.php:19-39
        class ContentSelectionPolicy extends BasePolicy
        {
            public function view(User $actor, Model $resource): bool|Response
            {
                return $this->ownerMatches($actor, $resource) ? true : $this->denyAsNotFound();
            }
            public function manage(User $actor, Model $resource): bool|Response
            {
                if ($denied = $this->denyIfPendingDeletion($actor)) { return $denied; }
                return $this->ownerMatches($actor, $resource) ? true : $this->denyAsNotFound();
            }
        ```

- [ ] **#TEST-3** · P2 — `IntegrationConnectionPolicy::connect` is the only ability on that policy left untested, in both places its siblings are tested
    - **Where:** `app/Policies/IntegrationConnectionPolicy.php:50-61`
    - **Affects:** The generic platform-connect flow's account-eligibility gate (`writeConnection()` → `authorizeForUser($user, 'connect', [$skeleton, $descriptor])`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend both `tests/Unit/Policies/IntegrationConnectionPolicyTest.php` and `tests/Feature/Security/PolicyEnforcement/IntegrationConnectionPolicyEnforcementTest.php` with a `describe('connect', ...)` block: allow when owner + `$descriptor->availableFor()` true; deny 403 when descriptor unavailable; deny 423 for pending-deletion owner (checked before the descriptor call); deny 404 for non-owner.
    - **Technical:** `view`, `update`, `delete`, and `create` each have dedicated tests in both `tests/Unit/Policies/IntegrationConnectionPolicyTest.php` and `tests/Feature/Security/PolicyEnforcement/IntegrationConnectionPolicyEnforcementTest.php` (11 tests total between the two files, confirmed by direct read). `connect()` — the newer, extra-argument ability that gates platform availability per account — appears in neither. It's the one ability with genuinely bespoke logic (the `$descriptor->availableFor($actor)` check), making it the highest-value ability to leave untested on this class.
    - **Plain English:** When someone tries to link a new platform (like Instagram) to their page, there's a second check beyond "is this your account" — "does your plan actually allow this platform." That second check has never been exercised by a test, even though every other check on this same lock has been.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationConnectionPolicy.php:50-61
        public function connect(User $actor, Model $skeleton, PlatformDescriptor $descriptor): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) { return $denied; }
            if (! $descriptor->availableFor($actor)) {
                return Response::deny('This platform is not available for your account.', 403);
            }
            return $this->ownerMatches($actor, $skeleton);
        }
        // Neither tests/Unit/Policies/IntegrationConnectionPolicyTest.php nor
        // tests/Feature/Security/PolicyEnforcement/IntegrationConnectionPolicyEnforcementTest.php
        // contains a "connect" describe block — both stop at view/update/delete/create.
        ```

- [ ] **#TEST-4** · P2 — `EnquiryPolicyTest` proves denial happens but never asserts the anti-enumeration contract (404, not 403)
    - **Where:** `tests/Feature/Policies/EnquiryPolicyTest.php:22-31`
    - **Affects:** Enquiry inbox — the CLAUDE.md doctrine mandates 404-not-403 for "not yours" denials specifically to prevent enumeration; this policy's test never checks which status the denial actually carries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three `Gate::forUser($stranger)->allows(...)->toBeFalse()` assertions with `Gate::forUser($stranger)->inspect('view', $enquiry)->status()` (or equivalent) and assert `404`, mirroring the pattern already used correctly in `tests/Feature/Security/PolicyEnforcement/FeedbackPolicyEnforcementTest.php` (`it('returns 404 (not 403) when a non-owner tries to view feedback')`).
    - **Technical:** `allows()` collapses any denial reason to a boolean — it cannot distinguish `denyAsNotFound()` (404) from a bare `false` (403). Every sibling file in `tests/Feature/Security/PolicyEnforcement/` explicitly asserts `->status()`; `EnquiryPolicyTest.php` (the one file in `tests/Feature/Policies/`) is the sole outlier that doesn't, despite `EnquiryPolicy` actually calling `denyAsNotFound()` in production. A regression that swapped `denyAsNotFound()` for a bare `false` return would leak enquiry existence to any authenticated stranger and this test would still pass.
    - **Plain English:** When someone tries to open another professional's private message thread, the system is supposed to say "not found" — not "that's not yours" — so a snooper can't tell whether the thread even exists. The test confirms the door stays shut, but never checks what's actually printed on the door, so a bug that swapped the polite "not found" sign for a revealing "forbidden" sign would sail through unnoticed.
    - **Evidence:**
        ```php
        // tests/Feature/Policies/EnquiryPolicyTest.php:22-31
        it('another pro cannot view/update/delete', function () {
            $owner = makeInboxUser();
            $stranger = makeInboxUser();
            $enquiryId = seedInboxEnquiry($owner->id, (string) Str::uuid());
            $enquiry = Enquiry::find($enquiryId);

            expect(Gate::forUser($stranger)->allows('view', $enquiry))->toBeFalse();
            expect(Gate::forUser($stranger)->allows('update', $enquiry))->toBeFalse();
            expect(Gate::forUser($stranger)->allows('delete', $enquiry))->toBeFalse();
        });
        ```

- [ ] **#TEST-5** · P2 — `PublicSiteController::showByHeader` cache-hit (200) path is never exercised — only header validation and the cache-miss 404 are tested
    - **Where:** `tests/Feature/PublicSite/PublicSiteControllerShowByHeaderTest.php`
    - **Affects:** The `X-Site-Subdomain` header-routed lookup endpoint (`/api/public/site-by-slug`) — a secondary public-resolution path alongside the (already well-tested) `IndividualProfileController` and `PublicSiteController::show`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that overrides the `SiteCacheService` mock to return a real payload for a known subdomain and asserts 200 + the expected JSON shape, per the test file's own `beforeEach` comment: "Tests that need a live payload hit should use a separate test class."
    - **Technical:** `beforeEach` unconditionally binds `SiteCacheService::getPublicSitePayload()` to return `null`, so every one of the file's 8 tests terminates in either a 400 (header validation) or a 404 (cache miss) — there is no test overriding that mock to prove a 200 response. This is narrower than DeepSeek's original framing: the platform's two other public-resolution paths (`IndividualProfileController` via `IndividualProfileControllerTest.php`, and `PublicSiteController::show` via `PublicSiteControllerShowTest.php` and `SubdomainChangeTest.php`) both have thorough happy-path coverage — this is the one sibling endpoint left uncovered for its success case.
    - **Plain English:** This endpoint has a test file that checks every way a request can be rejected, but never checks what a normal, successful lookup actually returns. The test author even left a comment saying a follow-up file was needed for that case — it was never written.
    - **Evidence:**
        ```php
        // tests/Feature/PublicSite/PublicSiteControllerShowByHeaderTest.php:12-18
        // Bind a mock SiteCacheService that always returns null (cache miss / no site found).
        // This lets us test the controller's header-validation logic in isolation without
        // needing the site.public_site_payload DB view that doesn't exist in the SQLite test
        // DB. Tests that need a live payload hit should use a separate test class.
        $mock = Mockery::mock(SiteCacheService::class);
        $mock->shouldReceive('getPublicSitePayload')->andReturn(null);
        ```

- [ ] **#TEST-6** · P2 — Supabase auth-hook re-delivery is untested — signature verification is thorough, but a replayed payload isn't proven to be a no-op
    - **Where:** `tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php`; `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php`
    - **Affects:** `SupabaseAuthHookController`'s MFA-verification hook, which records `verify_success`/`verify_failed` events used for brute-force lockout counting (`partna.mfa.verify_max_failures`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that POSTs the identical signed payload (same `Webhook-Id`, same body) to `/api/webhooks/supabase/auth/mfa-verification` twice, and asserts only one `audit.auth_factor_events` row is written (or, if duplicates are acceptable, that the failure counter isn't double-incremented).
    - **Technical:** Signature verification itself is exhaustively covered — `SupabaseAuthHookSignatureTest.php` tests the underlying `StandardWebhookVerifier` against 8 scenarios (valid, Supabase-format secret, forged, wrong secret, stale timestamp, non-numeric timestamp, empty headers, multi-signature header), and `SupabaseAuthHookBruteForceTest.php` drives the full controller with valid/invalid signatures. Neither tests a **replay** of the same `Webhook-Id` — Supabase's own retry-on-timeout behavior could otherwise double-count a `verify_failed` event and prematurely trip the lockout window for a legitimate user.
    - **Plain English:** We've thoroughly tested that a forged "letter" gets rejected. We haven't tested what happens if Supabase's own delivery system sends the *same real letter* twice (which webhook systems do, by design, whenever a response is slow). If a failed-login event gets counted twice because of a network retry, a legitimate user could get locked out early for something that only happened once.
    - **Evidence:**
        ```php
        // tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php:37-58 — happy/fail paths
        // exist, but no test posts the same Webhook-Id twice and asserts a single event row.
        it('returns continue and records verify_success for a valid signed success', function () {
            postSignedHook([...])->assertOk()->assertJson(['decision' => 'continue']);
            $event = DB::connection('pgsql')->table('audit.auth_factor_events')->where('user_id', $userId)->first();
            expect($event->event_type)->toBe('verify_success');
        });
        ```

- [ ] **#TEST-7** · P2 — `SyncSubdomainToKvJob` has no test proving two dispatches for the same site produce a single KV write
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`; `tests/Unit/Jobs/SyncSubdomainToKvJobTest.php`
    - **Affects:** Edge routing correctness — this job is the sole writer to `SUBDOMAIN_KV`; a race between two dispatches for the same handle could leave a stale entry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that dispatches the job twice for the same `$proId` via `Bus::dispatch()` (not direct `->handle()` calls, which bypass `ShouldBeUnique` entirely) and asserts the mocked `CloudflareKvService::put`/`bulkPut` is invoked exactly once.
    - **Technical:** `SyncSubdomainToKvJobTest.php` is thorough (11 tests) but every one calls `->handle($kv)` directly on a fresh instance — this exercises the KV-write *logic* correctly but never goes through the queue dispatcher, so the `ShouldBeUnique`/`uniqueFor=45` contract (confirmed present via `it('implements ShouldBeUnique...')`, which only checks the interface/property values) is never actually exercised end-to-end. This mirrors the pattern the codebase already uses correctly for `WarmPublicSiteCacheJob` (see `WarmPublicSiteCacheJobUniquenessTest.php`), so the fix is small — just missing here specifically for the single KV writer.
    - **Plain English:** There's one delivery person allowed to update the master signpost that tells browsers where to send visitors. We've checked the delivery person's rulebook says "only one trip per 45 seconds," but we've never actually sent them out twice in a row to see if the rule is enforced or just written down.
    - **Evidence:**
        ```php
        // tests/Unit/Jobs/SyncSubdomainToKvJobTest.php:21-28 — interface-only check
        it('implements ShouldBeUnique with a 45s window keyed by user_id (§28.6a)', function () {
            $job = new SyncSubdomainToKvJob($proId);
            expect($job)->toBeInstanceOf(ShouldBeUnique::class)
                ->and($job->uniqueFor)->toBe(45)
                ->and($job->uniqueId())->toBe($proId);
        });
        // Every other test in the file calls (new SyncSubdomainToKvJob($proId))->handle($kv)
        // directly — none dispatch through the queue to exercise the uniqueness lock itself.
        ```

- [ ] **#TEST-8** · P2 — `DeleteMediaArtifactsJob`'s `failed()` handler is unreachable in the test suite
    - **Where:** `app/Jobs/DeleteMediaArtifactsJob.php`; `tests/Unit/Jobs/DeleteMediaArtifactsJobTest.php`
    - **Affects:** Storage cleanup after moderation takedowns / account deletion — if `failed()` doesn't correctly report the exhausted-retry state, orphaned R2 artifacts go unnoticed (no Nightwatch signal per the observability doctrine: "a failure that needs attention must throw or `$this->fail($e)`").
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that calls `(new DeleteMediaArtifactsJob(...))->failed(new RuntimeException(...))` directly and asserts the expected side-effect (report to Nightwatch, or whatever the production `failed()` body does).
    - **Technical:** `DeleteMediaArtifactsJobTest.php` is 17 lines and only asserts `$tries`/`$backoff`. The job's actual *cleanup logic* is well covered elsewhere (`tests/Unit/MediaJobReliabilityTest.php` has 4 tests confirming delete-cleanup rethrows transient failures, normalizes legacy paths, and best-effort-clears DB rows even when storage listing fails) — so this finding is narrower than DeepSeek's original framing (`handle()` delegation is NOT untested). The specific remaining gap is the terminal `failed()` callback that fires only after retries are exhausted, which no test in the suite reaches for this job.
    - **Plain English:** The crew that deletes files after a takedown has been tested thoroughly on the "keep trying" part — retry, retry again, clean up what you can. Nobody has tested what happens on the very last step, when all retries are used up and the job has to raise a flag saying "I gave up, someone needs to look at this."
    - **Evidence:**
        ```php
        // tests/Unit/Jobs/DeleteMediaArtifactsJobTest.php — entire file
        it('retries with exponential backoff so a transient R2 outage does not orphan files', function () {
            $job = new DeleteMediaArtifactsJob('media-id', 'videos/pro/media-id', 'gallery');
            expect($job->tries)->toBe(4)->and($job->backoff)->toBe([60, 300, 900]);
        });
        // No handle() or failed() invocation anywhere in this file.
        ```

- [ ] **#TEST-9** · P2 — No functional test proves an `AccountCapabilities`-gated route actually rejects the action when the capability is absent
    - **Where:** `tests/Feature/Account/AccountCapabilitiesTest.php`; `tests/Feature/Architecture/CapabilityDispatchTest.php`
    - **Affects:** Every notification dispatcher / route guard that consults `AccountCapabilities::for($user)`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Pick at least one HTTP-reachable, capability-gated action (e.g. a notification-preference or design-editor endpoint) and add a test that sets up a user whose relevant capability resolves `false`, then asserts the endpoint rejects the action (403/404 as appropriate) — and a complementary allowed-case test.
    - **Technical:** `AccountCapabilitiesTest.php` (confirmed to exist and to be substantial) tests the capability *computation* only (`can_edit_design`, `notification_categories`, `worker_kv_type`, memoization) — never an HTTP route. `CapabilityDispatchTest.php` (confirmed to exist) is a structural sweep asserting every notification-dispatcher job's *source code* references `AccountCapabilities` or is documented exempt — it never runs a job and checks the gate actually blocks. Between the two, no test exercises the "capability is false → action is rejected" path end-to-end. Given Partna today has only two account types with narrow capability variance (`partna`/`business`), this is real but not urgent.
    - **Plain English:** We've tested that the "rules engine" correctly calculates who's allowed to do what. We've also confirmed every notification job at least mentions the rules engine in its code. But nobody has actually tried performing a gated action as someone who shouldn't be allowed to, and checked that they get turned away.
    - **Evidence:**
        ```php
        // tests/Feature/Account/AccountCapabilitiesTest.php:23-25 — value-only assertion
        it('keeps its own design editor', function () {
            expect($this->caps->can_edit_design)->toBeTrue();
        });
        // tests/Feature/Architecture/CapabilityDispatchTest.php:19 — source-reference sweep only
        it('every notification dispatcher job consults AccountCapabilities or is documented as exempt', function () {
        ```

- [ ] **#TEST-10** · P2 — `DesignKitRequestDriftTest` silently skips on SQLite — the standard CI test driver never runs the drift guard it exists to provide
    - **Where:** `tests/Feature/Requests/DesignKitRequestDriftTest.php:34-42`
    - **Affects:** Every new `site.design_kits` column — the guard that's supposed to catch a migration adding/dropping a column without updating `UpdateSiteRequest`/`StaffUpdateSiteRequest` doesn't run in the environment that actually gates merges.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Rework `fetchDesignKitDbColumns()` to parse `ADD COLUMN` statements for `site.design_kits` out of the migration SQL files (the same grep-based pattern the codebase already uses for CHECK-constraint invariant tests) instead of querying live `information_schema`, so the drift check runs under the SQLite test driver too.
    - **Technical:** `fetchDesignKitDbColumns()` returns `null` whenever `DB::connection('pgsql')->getDriverName() !== 'pgsql'`, and each test then calls `markTestSkipped('Requires real PostgreSQL — skipped in SQLite test env.')`. Since Pest's standard suite runs on SQLite (per the codebase's own testing conventions), this drift guard never actually executes in CI — a migration adding a new design-kit column without updating the Form Request allowlists would pass every automated check. The codebase already has an established, working pattern for exactly this class of problem (grep the migration SQL instead of querying a live Postgres schema) — it just wasn't applied here because column *names* (not CHECK constraint text) were being compared.
    - **Plain English:** There's a security-camera test meant to catch a very specific mistake: adding a new design option to the database without teaching the API about it. That camera only turns on when pointed at a real production-style database — but the normal daily test run never uses one. So the camera has never actually recorded anything, even though it looks like it's working.
    - **Evidence:**
        ```php
        // tests/Feature/Requests/DesignKitRequestDriftTest.php:34-42
        function fetchDesignKitDbColumns(): ?array
        {
            $conn = DB::connection('pgsql');
            // information_schema is a PostgreSQL feature — skip on SQLite.
            if ($conn->getDriverName() !== 'pgsql') {
                return null;
            }
        ```

- [ ] **#TEST-11** · P2 — `AccountCanonicalKeyDedupeTest` proves the DB-level unique index rejects a duplicate, but not that the API responds gracefully when the race actually happens
    - **Where:** `tests/Feature/Platforms/AccountCanonicalKeyDedupeTest.php`
    - **Affects:** Any concurrent double-submit of `POST /api/platforms/{platform}/connect` for the same canonical account (a plausible frontend double-click / retry scenario).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that simulates the write path racing past the application-level SELECT-then-CREATE check (e.g. by creating the first row, then invoking the connect controller/service action a second time with the same canonical key) and asserts the response is a graceful 2xx/409, not an unhandled 500 from the raw `QueryException`.
        - Confirm the relevant write path actually catches the duplicate-key `QueryException` and falls back to an update-in-place; if it doesn't, this is the fix, not just the test.
    - **Technical:** The existing test calls `IntegrationConnection::create()` directly twice and asserts the second throws `QueryException` — this correctly proves the partial unique index `(user_id, platform, canonical_key)` exists and works at the DB layer. It does not go through the HTTP controller/service, so it says nothing about what a real user sees when two rapid requests race past the application's own pre-check.
    - **Plain English:** Two people trying to register the same account at the same instant will be stopped by the database's own "no duplicates" rule — that part is proven. What's not proven is what the *user* sees when that happens: a friendly "already connected" message, or a raw server crash page.
    - **Evidence:**
        ```php
        // tests/Feature/Platforms/AccountCanonicalKeyDedupeTest.php:16-31 (setup)
        beforeEach(function () {
            setupUsersTable();
            setupSitesTable(); // also creates site.platform_connections
        });
        // The test asserts IntegrationConnection::create() called twice with the same
        // canonical_key throws QueryException — no assertion against the HTTP layer.
        ```

- [ ] **#TEST-12** · P2 — `SendTransactionalNotificationEmailJob` has no test for a retry after `email_sent_at` was already stamped
    - **Where:** `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`; `tests/Feature/Notifications/NotificationPublisherTest.php:192-206`
    - **Affects:** Transactional emails (policy updates, account notices) — a Horizon retry after a partial failure (row stamped, then a mail-provider error) could double-send.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('does not re-send when email_sent_at is already set', ...)` that seeds a row with `email_sent_at` pre-populated, calls `handle()`, and asserts `Mail::assertNothingSent()`.
    - **Technical:** Confirmed both existing tests in `NotificationPublisherTest.php` seed a fresh row (`email_sent_at` implicitly null) and assert the mail IS sent — neither tests the guard's own boundary condition (a row where `email_sent_at` is already set, simulating a retry after the column was committed but delivery failed). If the guard is ever inverted or removed, no test in the suite would catch it.
    - **Plain English:** The system is supposed to mark a notification "sent" the moment it starts, so that if the same job runs again — say, after a network blip — it knows not to send a second copy. We've tested the normal case (a fresh notification gets sent once) but never tested the retry case that the whole guard exists to protect against.
    - **Evidence:**
        ```php
        // tests/Feature/Notifications/NotificationPublisherTest.php:192-195
        (new SendTransactionalNotificationEmailJob('notif-1', 'policy_update', 'pro-1'))->handle();
        Mail::assertSent(PolicyUpdateMail::class);
        // No test seeds email_sent_at pre-set and asserts a second handle() sends nothing.
        ```

## P3 — Nice to have

- [ ] **#TEST-13** · P3 — Two observer tests mock the Eloquent `Site` model instead of using a real factory-backed row
    - **Where:** `tests/Feature/User/UserObserverHandleChangeTest.php:118-132`; `tests/Feature/Core/ServiceObserverTouchSiteTest.php:26-38`
    - **Affects:** Test reliability for `UserObserver`/`ServiceObserver`'s cache-busting `touch()` call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Mockery::mock(Site::class)` with a real, minimally-seeded `Site` row (factory or direct insert) in both files, and assert the real `updated_at` column advances instead of relying on `shouldReceive('touch')->once()`.
    - **Technical:** Both files construct `Mockery::mock(Site::class)` with `shouldReceive('touch')->once()` and attach it via `setRelation('site', $site)`, directly violating the stated house rule ("Do not mock Eloquent models; the DB layer must be real"). Note the practical risk is narrower than it first appears: because the mock asserts `touch()` is called exactly once, a regression that *removed* the `touch()` call would still fail these tests — what the mock can't prove is that a real `touch()` actually advances `updated_at` and correctly cascades to downstream cache invalidation, since Eloquent's real column-update behavior never executes.
    - **Plain English:** These two tests check that the code *tries* to update a page's "last changed" timestamp, using a fake stand-in for the page instead of a real one. If the code stopped trying, the test would notice — but it can't confirm the timestamp actually updates in the database the way a real save would.
    - **Evidence:**
        ```php
        // tests/Feature/User/UserObserverHandleChangeTest.php:121-123
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();
        // tests/Feature/Core/ServiceObserverTouchSiteTest.php:27-28
        $site = Mockery::mock(Site::class);
        $site->shouldReceive('touch')->once();
        ```

- [ ] **#TEST-14** · P3 — `GalleryMixedReorderTest` binds mocks with zero expectations, providing no actual guard
    - **Where:** `tests/Feature/Gallery/GalleryMixedReorderTest.php:65-68`
    - **Affects:** False confidence in reorder-path isolation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `shouldNotReceive()` expectations on both mocks (the reorder path should never touch variant services), or remove the mocks if the real services are safe to instantiate.
    - **Technical:** `Mockery::mock(ImageVariantService::class)` and `Mockery::mock(VideoVariantService::class)` are bound into the container with no `shouldReceive`/`shouldNotReceive` calls at all. Mockery's default behavior for an unconfigured mock is to silently accept any call and return `null` — so if the reorder controller ever accidentally invoked a variant-processing method, this test would not detect it.
    - **Plain English:** This test installs a security camera but never loads film into it. It looks like a safeguard, but if something unexpected happens during a reorder, nothing gets recorded.
    - **Evidence:**
        ```php
        // tests/Feature/Gallery/GalleryMixedReorderTest.php:65-68
        $mediaService = Mockery::mock(ImageVariantService::class);
        $videoVariant = Mockery::mock(VideoVariantService::class);
        app()->instance(ImageVariantService::class, $mediaService);
        app()->instance(VideoVariantService::class, $videoVariant);
        ```

- [ ] **#TEST-15** · P3 — `SendFeedbackEmailJobTest`'s idempotency assertion silently depends on `CACHE_STORE=array` state persisting between two `handle()` calls
    - **Where:** `tests/Feature/Jobs/SendFeedbackEmailJobTest.php:146-160`
    - **Affects:** Test correctness if `phpunit.xml`'s `CACHE_STORE` is ever changed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make the dependency explicit: assert the cache driver is `array` at the top of the test, or replace the implicit-state approach with an explicit `Cache::has()`/`Cache::add()` assertion between the two `handle()` calls.
    - **Technical:** The test calls `handle()` twice in a row and relies on the `array` cache driver's in-process state surviving between the calls (no `Cache::flush()` between them) to prove the per-recipient `Cache::add()` guard prevents a duplicate send. This is correctly documented in an inline comment, so it's a known trade-off rather than a hidden bug — but it means a future `phpunit.xml` change to a different cache driver would silently turn this into a no-op test rather than fail loudly.
    - **Plain English:** This test proves a duplicate-email guard works, but only because it happens to run against a particular kind of test memory that doesn't get wiped between steps. If someone swaps that test memory for a different kind later, the test would keep passing without actually testing anything.
    - **Evidence:**
        ```php
        // tests/Feature/Jobs/SendFeedbackEmailJobTest.php:146-154
        it('does not re-send to an already-emailed recipient when the job runs twice (per-recipient cache idempotency)', function () {
            // TEST-3: the per-recipient Cache::add key prevents duplicate delivery on retry.
            // Cache driver is 'array' (phpunit.xml CACHE_STORE=array) — not flushed between
            // the two handle() calls to simulate a Horizon retry with the cache still warm.
            (new SendFeedbackEmailJob($id))->handle();
            (new SendFeedbackEmailJob($id))->handle();
        ```

- [ ] **#TEST-16** · P3 — `BooleanDefaultsTest` patches the shared SQLite schema with an ad-hoc `ALTER TABLE` instead of the canonical `setupSitesTable()` helper
    - **Where:** `tests/Feature/Bootstrap/BooleanDefaultsTest.php:22-26`
    - **Affects:** Test-schema consistency — every other test that creates a `site.sites` row via `setupSitesTable()` operates on a schema shape that diverges from this file's patched one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `skeleton_id` column definition into `setupSitesTable()` in `tests/Pest.php` so every test shares one schema definition, and delete the local `ALTER TABLE` patch.
    - **Technical:** The test acknowledges in its own comment that `setupSitesTable()` doesn't include `skeleton_id`, and works around it locally with a raw `ALTER TABLE ... ADD COLUMN` wrapped in a try/catch for "already exists." This is a one-off patch to a shared fixture, exactly the kind of drift the canonical helper pattern exists to prevent — if `setupSitesTable()` is ever updated to include `skeleton_id` with a different type/default, this file's patch could silently conflict.
    - **Plain English:** All the other tests build their "test house" from one shared blueprint. This one test bolts an extra room onto the house by itself, after the fact, instead of updating the blueprint everyone else uses. It works for now, but it's the kind of shortcut that causes confusing failures later.
    - **Evidence:**
        ```php
        // tests/Feature/Bootstrap/BooleanDefaultsTest.php:19-26
        // Shared setupSitesTable() doesn't include skeleton_id. Site::$fillable
        // includes it post-cleanup, so Eloquent inserts the column — patch the
        // SQLite shadow schema to match.
        try {
            DB::connection('pgsql')->statement("ALTER TABLE site.sites ADD COLUMN skeleton_id TEXT NOT NULL DEFAULT 'bento'");
        } catch (Throwable $e) {
            // Column already exists from a prior test run in the same process.
        }
        ```

- [ ] **#TEST-17** · P3 — `ShopRelationalStorageTest` reaches into a private Laravel internal (`Cache::getStore()->locks`) to reset a unique-job lock
    - **Where:** `tests/Feature/Platforms/ShopRelationalStorageTest.php:282`
    - **Affects:** Test fragility if `Illuminate\Cache\ArrayStore`'s internal property layout changes in a future Laravel upgrade.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the direct property assignment with a public-API alternative — e.g. `Cache::store('array')->flush()` (safe, since the array store is test-scoped and in-memory), or restructure the assertion to accept the coalesced dispatch count instead of forcing a lock reset.
    - **Technical:** `CloudflareCachePurgeJob` implements `ShouldBeUnique`, and its uniqueness lock is acquired via the array cache store's internal `$locks` property (not part of the public `Illuminate\Contracts\Cache\Store` contract). The test reaches directly into that private property to release the lock between two assertions. A Laravel upgrade that renames or restructures this internal would break the test for a reason unrelated to application correctness.
    - **Plain English:** The test reaches inside a locked drawer to reset a switch, instead of using the drawer's front door. It works today, but if the drawer's internal layout changes in a future update, this test breaks even though nothing in the actual app is wrong.
    - **Evidence:**
        ```php
        // tests/Feature/Platforms/ShopRelationalStorageTest.php:277-282
        // / Cache::flush() does not touch (Illuminate\Cache\ArrayLock/ArrayStore).
        // Reset it directly to release the lock the first write took, so the
        // SECOND write's dispatch attempt isn't swallowed by the coalescing
        // window this test isn't exercising (that's covered separately by
        // PlatformLoopTest's connect+disconnect-coalesce assertion).
        Cache::getStore()->locks = [];
        ```

- [ ] **#TEST-18** · P3 — `Site::delete` (EDGE-4) is proven to purge the Cloudflare edge but not to clear the Redis payload cache, unlike its `SiteMedia::delete` sibling test
    - **Where:** `tests/Feature/Observers/BlockAndMediaTouchSiteTest.php:193-202`
    - **Affects:** A deleted site's public payload could remain warm in Redis after the edge is purged, so the next cache-fill request could briefly re-serve stale content.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Cache::put($key, ...)` seed before `Site::delete()` and a `expect(Cache::get($key))->toBeNull()` assertion after, mirroring the pattern already used two tests earlier in the same file for `SiteMedia::delete`.
    - **Technical:** `SiteMedia::delete clears the public payload cache via SiteObserver` (lines 161-182) explicitly seeds a `CacheKeyGenerator::publicSitePayload()` key and asserts it's cleared after deletion. The `Site::delete dispatches CloudflareCachePurgeJob` test (EDGE-4, lines 193-202) only asserts the job dispatch — it never seeds or checks the Redis key for the direct-delete path.
    - **Plain English:** One test proves that deleting a photo correctly clears the cached copy of a page. The sibling test — for deleting the entire page — only checks that the edge network gets told to purge; it never checks whether the same page is still sitting warm in the server's own cache.
    - **Evidence:**
        ```php
        // tests/Feature/Observers/BlockAndMediaTouchSiteTest.php:193-201
        it('Site::delete dispatches CloudflareCachePurgeJob for the deleted handle (EDGE-4)', function () {
            $fixture = seedTouchFixture();
            Queue::fake();
            Site::find($fixture['site_id'])->delete();
            Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
                return $job->handle === 'touchtest';
            });
        });
        // Compare to the SiteMedia::delete test at line 176-181, which seeds and
        // asserts a Cache::get($key) === null check that this test omits.
        ```

- [ ] **#TEST-19** · P3 — `AnalyticsQueryService`'s Postgres-only queries (`ILIKE`/`DATE`/`DISTINCT ::text`) never actually execute against a database in the test suite
    - **Where:** `app/Services/Analytics/AnalyticsQueryService.php`; `tests/Unit/Analytics/AnalyticsQueryServiceConfigDrivenTest.php`
    - **Affects:** The staff analytics dashboard's referrer/top-sections queries — a typo in a Postgres-specific SQL expression wouldn't surface until a human looks at production data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a smoke test gated behind a real Postgres connection (skipped, not silently passed, on SQLite) that seeds a few analytics rows and calls `referrers()`/`topSections()` end-to-end, asserting no SQL error and a sane shape.
    - **Technical:** The test file's own docblock states the limitation plainly: it exercises the title/label config logic via reflection specifically *because* the real `referrers()`/`topSections()` queries use Postgres-only SQL that can't run against the SQLite test DB. This is an honestly-documented, self-acknowledged gap rather than a lie-about-coverage pattern, but it means the actual SQL never runs anywhere in CI.
    - **Plain English:** We've tested the "recipe" for the analytics dashboard's charts — the labels and titles are checked carefully — but the actual database instructions that fetch the numbers use features our everyday test database doesn't understand, so those instructions have never actually been run and checked in an automated way.
    - **Evidence:**
        ```php
        // tests/Unit/Analytics/AnalyticsQueryServiceConfigDrivenTest.php:3-9
        /**
         * Pure-logic guards for #FOUND-2 (section titles moved to config) and #FOUND-3
         * (referrer CASE + labels single-sourced from REFERRER_SOURCES). No DB — the
         * real referrers()/topSections() queries are Postgres-only (ILIKE/DATE/DISTINCT
         * ::text) and don't run against the SQLite test DB, so this file exercises the
         * title/label logic directly via reflection instead of executing SQL.
         */
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public profile payload coverage:** #TEST-1, #TEST-5
    - **Why grouped:** Same public sitepage-resolution surface (`IndividualProfileResource` payload + `PublicSiteController::showByHeader`), same "add the missing happy-path/snapshot test" fix shape.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Webhook & edge-sync idempotency:** #TEST-6, #TEST-7
    - **Why grouped:** Both are "prove a redelivery/re-dispatch is a no-op" gaps on infrastructure jobs (Supabase webhook, KV sync); same test-writing pattern (dispatch twice, assert single side-effect).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Job failure/retry-path coverage:** #TEST-8, #TEST-12
    - **Why grouped:** Both are single-job, S-effort "add the missing failure/retry-edge test" fixes in `app/Jobs/`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Cross-environment query/schema test hygiene:** #TEST-10, #TEST-19
    - **Why grouped:** Both are "this guard doesn't actually run against real Postgres SQL in standard CI" gaps, same root cause (SQLite test driver can't exercise Postgres-only behavior).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Platform test hygiene:** #TEST-11, #TEST-17
    - **Why grouped:** Both live in `tests/Feature/Platforms/`, same "test proves the mechanism exists but not the full user-facing behavior / reaches into internals" theme.
    - **Model:** Plan: Sonnet (combine plan+impl, S/M effort) · Review: Sonnet.

- **Bundle 6 — P3 mechanical test-hygiene sweep:** #TEST-13, #TEST-14, #TEST-15, #TEST-16, #TEST-18
    - **Why grouped:** All are small, mechanical, single-file test-quality fixes (dead mocks, DB-mock convention, implicit cache-driver dependency, ad-hoc schema patch, asymmetric cache-invalidation assertion) with no cross-file dependencies — efficient to review together in one pass.
    - **Model:** Plan: Sonnet (combine plan+impl) · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-2 — ContentSelectionPolicy zero coverage** · touches `app/Policies/` authorization surface.
- **#TEST-3 — IntegrationConnectionPolicy::connect untested** · touches `app/Policies/` authorization surface.
- **#TEST-4 — EnquiryPolicy 404-vs-403 assertion gap** · touches `app/Policies/` authorization surface.
- **#TEST-9 — AccountCapabilities functional gate test** · touches account-capability/authorization gating.
