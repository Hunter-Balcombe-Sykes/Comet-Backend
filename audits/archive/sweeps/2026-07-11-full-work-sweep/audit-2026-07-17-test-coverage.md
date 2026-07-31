# Test Coverage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Policies/*.php` (all 18 policy classes, cross-checked against `tests/Unit/Policies/`, `tests/Feature/Policies/`, `tests/Feature/Security/PolicyEnforcement/`)
- `tests/Pest.php`
- `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`, `tests/Feature/PublicSite/*.php`, `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `tests/Feature/Staff/StaffUserSearchFiltersTest.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `tests/Feature/Site/CustomDomainTest.php`, `tests/Feature/Database/CheckConstraintsTest.php`, `tests/Feature/Database/IndexCoverageTest.php`
- `tests/Feature/Media/DesignSingletonMediaTest.php`, `tests/Feature/Api/User/SiteManagement/WriteDesignKitConcurrencyTest.php`
- `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php`, `app/Observers/User/UserObserver.php`
- `tests/Feature/Platforms/ReservationProvidersTest.php`, `tests/Feature/Platforms/MenuTest.php`, `tests/Unit/Jobs/EnrichLinkCardJobTest.php`
- `tests/Unit/Jobs/SyncSubdomainToKvJobTest.php`, `tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`, `tests/Feature/Moderation/StaffCase*.php`
- `tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php`, `tests/Unit/Http/SafeUrlFetcherTest.php`
- Remaining scope files from the original draft bundle (`tests/Feature/Api/**`, `tests/Feature/Account*/**`, `tests/Feature/Design/**`, `tests/Feature/Platforms/**`, `tests/Unit/**` per the source list) — cross-checked via repo-wide `Glob`/`Grep` rather than trusted at face value

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **#TEST-1** · P2 — `ContentSelectionPolicy` has zero test coverage
    - **Where:** `app/Policies/ContentSelectionPolicy.php` — no test file anywhere under `tests/` references this class.
    - **Affects:** The sitepage background-content-picker mutation surface (`replace`/`toggle`/`upload`/`delete` all authorize through `manage`). A regression that drops the `denyIfPendingDeletion` guard or the owner check would go undetected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Security/PolicyEnforcement/ContentSelectionPolicyEnforcementTest.php` mirroring the existing `FeedbackPolicyEnforcementTest.php` / `StaffManageBlockPolicyTest.php` pattern.
        - Assert `view`: owner → allowed, non-owner → 404 (`denyAsNotFound`).
        - Assert `manage`: owner+active → allowed; non-owner → 404; owner+pending-deletion → 423.
    - **Technical:** This is the only policy in the 18-class `app/Policies/` set with no test file at all — every sibling policy (`SitePolicy`, `UserSelfPolicy`, `FeedbackPolicy`, `CasePolicy`, `DecisionPolicy`, `GdprPolicy`, etc.) has dedicated `allowed`/`denied` coverage in `tests/Unit/Policies/` or `tests/Feature/Security/PolicyEnforcement/`. `ContentSelectionPolicy` follows the identical owner-via-relation + `denyIfPendingDeletion` pattern documented in its own comments ("mirrors SitePolicy's SiteMedia resolution"), so the fix is a direct copy of an existing exemplar, not new pattern design.
    - **Plain English:** Every "who's allowed to touch this" rule in the app has an automatic test proving the lock works — except one: the rule that controls who can change the background content pictures on a professional's page. It's built the same way as all the other locks we've already tested, so writing the missing test is quick, but right now nothing would notice if that lock quietly stopped working.
    - **Evidence:**
        ```php
        public function manage(User $actor, Model $resource): bool|Response
        {
            if ($denied = $this->denyIfPendingDeletion($actor)) {
                return $denied;
            }

            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        ```

- [ ] **#TEST-2** · P2 — `FeatureAvailabilityPolicy` and `UserSegmentPolicy` staff abilities have no dedicated ability tests
    - **Where:** `app/Policies/FeatureAvailabilityPolicy.php`, `app/Policies/UserSegmentPolicy.php` — no test file references either class name anywhere in `tests/`.
    - **Affects:** Staff tooling for feature-availability rule management and user-segment management. `staffManage` (admin-only, create/update/delete rules or segments) and `staffView` (support+admin read) have no regression test proving the role split is enforced.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Security/PolicyEnforcement/FeatureAvailabilityPolicyEnforcementTest.php` and `.../UserSegmentPolicyEnforcementTest.php` following the `StaffEarlyAccessInviteTest.php` pattern (`it('rejects invite sends from support-role staff (admin-only power)')`) which already proves this exact pattern works for the sibling `EarlyAccessSignupPolicy`.
        - Assert `staffView`: support role → allowed, admin role → allowed, unknown role → denied.
        - Assert `staffManage`: admin → allowed, support → denied (403).
    - **Technical:** All three OV-A staff-only policies (`EarlyAccessSignupPolicy`, `FeatureAvailabilityPolicy`, `UserSegmentPolicy`) share an identical `staffView`/`staffManage` role-gate shape. `EarlyAccessSignupPolicy::staffManage` is already exercised end-to-end via `StaffEarlyAccessInviteTest.php` (admin-allowed + support-denied-with-403), but no equivalent HTTP or Gate-level test exists for `FeatureAvailabilityPolicy` or `UserSegmentPolicy` — `FeatureAvailabilityReadSideTest.php` and `SegmentResolverTest.php` only test the read-side rule-resolution logic, not the staff CRUD authorization gate. Both policies are registered in `AppServiceProvider::boot()` (`Gate::policy(UserSegment::class, ...)`, `Gate::policy(FeatureAvailabilityRule::class, ...)`), so `PolicyCoverageTest.php`'s registration sweep passes even though behavioral coverage doesn't exist.
    - **Plain English:** Two staff-only admin tools — one for turning features on/off platform-wide, one for managing customer segments — have a rule that says "only full admins can change these, support staff can only look." We've proven that exact rule works for a third, similar tool (the early-access waitlist), but never wrote the equivalent test for these two. If a future change accidentally let support staff make changes here, nothing would catch it.
    - **Evidence:**
        ```php
        public function staffView(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
        {
            return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
        }

        public function staffManage(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
        {
            return $actor->isAdmin();
        }
        ```

- [ ] **#TEST-3** · P2 — Singleton design-media replace has no concurrent-request test
    - **Where:** `tests/Feature/Media/DesignSingletonMediaTest.php:151-189` (`it('replaces the existing singleton of the same purpose on re-upload')`)
    - **Affects:** Users uploading logos/cover images back-to-back or from two tabs/devices at once — a race between the soft-delete-old and insert-new steps could leave two "active" singleton rows for the same purpose.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-only test (gated like `WriteDesignKitConcurrencyTest.php`, which already establishes the exact pattern for the sibling `design_kits` lock) that runs two interleaved singleton-replace calls against the same `(site_id, pool, purpose)` and asserts exactly one active row survives.
        - If the controller doesn't already take a row lock or rely on a unique constraint for this, note that as a companion production-code fix.
    - **Technical:** The existing test only proves sequential replacement works (soft-delete old, insert new, `toHaveCount(1)`). SQLite can't exercise real row-level locking, and `WriteDesignKitTest.php`/`WriteDesignKitConcurrencyTest.php` already show the house pattern for testing this class of race on a `pgsql`-only, driver-gated test. `DesignSingletonMediaTest.php` has no equivalent, so the singleton invariant for `SiteMedia` (unlike `design_kits`) is currently unverified under concurrency.
    - **Plain English:** If two uploads for the same profile photo slot happen at almost the same instant, the system should end up with exactly one photo in that slot, not two competing ones. We've proven "upload twice, one after the other" works cleanly. We haven't proven "upload twice at the same instant" works — and we already know how to write that kind of test, because we did it for a similar feature (the design-kit editor).
    - **Evidence:**
        ```php
        $active = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', 'design')
            ->where('purpose', 'logo_full')
            ->get();

        expect($active)->toHaveCount(1);
        ```

- [ ] **#TEST-4** · P2 — Staff user search `q` parameter path has zero automated test coverage
    - **Where:** `tests/Feature/Staff/StaffUserSearchFiltersTest.php:63-65` (explicit code comment acknowledging the gap)
    - **Affects:** Staff dashboard user search — every operator searching by handle, email, display name, or sector text via the `q` parameter.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-only Pest test (gated on `DB::getDriverName() === 'pgsql'`, matching the house convention used elsewhere for ILIKE-dependent assertions) exercising the `q` path across handle/email/display_name/sector matches.
        - Alternatively, extract the ILIKE query into a small query-builder class that can be unit-tested independently of the driver-specific SQL.
    - **Technical:** `StaffUserController::index()` builds an `ILIKE` query across several columns when `q` is present. SQLite has no `ILIKE`, so the test author explicitly left this path untested with an inline comment. This is a genuine, self-acknowledged gap: a regression in the search query (wrong columns, broken sector join, malformed LIKE pattern) ships undetected on the primary internal search surface for the admin dashboard.
    - **Plain English:** The staff search box is how your team finds a user account. The code that powers it is admittedly untested — there's a comment in the test file that says as much — because the lightweight test database can't run the exact search syntax used in production. If a future change breaks the search, nobody finds out until a staff member complains.
    - **Evidence:**
        ```php
        // NOTE: the q ILIKE path (which now also matches sector) is Postgres-only
        // syntax — the SQLite test mirror can't execute it, same as the pre-existing
        // handle/email ILIKE branches, so it stays covered by prod behaviour only.
        ```

- [ ] **#TEST-5** · P2 — Staff search filter tests bypass the HTTP stack entirely
    - **Where:** `tests/Feature/Staff/StaffUserSearchFiltersTest.php:35-41` (`ovaSearchIds()` helper)
    - **Affects:** Staff-only search endpoint — authorization enforcement, `staff`/`require.aal2` middleware, and response formatting are never exercised by these tests.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Convert the filter tests to real HTTP requests via `actingAsStaff()` + `getJson('/api/staff/professionals?...')`.
        - Add a companion test asserting a non-staff actor is rejected on this route.
        - Assert the response matches the `StaffUserResource` shape, not just the raw `id` column.
    - **Technical:** The test instantiates the controller directly (`app(StaffUserController::class)->index($request)`), skipping the JWT middleware, the `staff` gate, and any `authorizeForUser` call the controller makes. A controller refactor that drops the authorization check would still pass this entire test file. This is a textbook instance of category-4 "mock-vs-integration discipline" — the DB layer is real, but the HTTP/auth layer is bypassed entirely for every test in the file.
    - **Plain English:** Imagine testing a locked door by climbing in through an open window instead of trying the door. You can check what's inside the room, but you never actually test whether the lock works. These tests call the search function directly, skipping login and permission checks — so they can't tell you if someone without staff access could reach this search.
    - **Evidence:**
        ```php
        function ovaSearchIds(Request $request): array
        {
            $response = app(StaffUserController::class)->index($request);
            $body = json_decode($response->getContent(), true);

            return array_column($body['professionals'], 'id');
        }
        ```

- [ ] **#TEST-6** · P2 — `CustomDomainTest` never exercises a Cloudflare API failure response
    - **Where:** `tests/Feature/Site/CustomDomainTest.php` — all 7 tests fake either a `200 success` Cloudflare response or a missing-config `503`; none fakes a Cloudflare error/outage response.
    - **Affects:** Users connecting a custom domain when Cloudflare is degraded or rejects the request — the failure path from the vendor HTTP client into the controller is completely unverified.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test faking `Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 500)])` on connect, and assert the endpoint returns a clean error (not an unhandled 500) with a user-facing message.
        - Add an equivalent test for the `/verify` polling endpoint.
    - **Technical:** Every `Http::fake()` call in this file returns `'success' => true` except the single config-precheck test, which checks a *missing* `zone_id`/token (a 503 short-circuit before any HTTP call is made), not an actual Cloudflare API error after the precheck passes. If Cloudflare returns a 500 or the connection times out, whatever exception Laravel's HTTP client throws is untested end-to-end.
    - **Plain English:** The tests rehearse "everything goes perfectly with Cloudflare" several times over, but never rehearse "Cloudflare is having a bad day." If that happens while a real user is connecting their custom domain, they might see a generic crash page instead of a helpful "try again" message — and nobody would know until it happens live.
    - **Evidence:**
        ```php
        it('returns 503 when Cloudflare for SaaS is not configured', function () {
            config([
                'services.cloudflare.zone_id' => '',
                'services.cloudflare.saas_api_token' => '',
                'services.cloudflare.api_token' => '',
            ]);
            [$user] = domainUserWithSite('domu2');

            actingAsUser($user)->putJson('/api/site/custom-domain', ['domain' => 'bookwith.me'])
                ->assertStatus(503);
        });
        ```

- [ ] **#TEST-7** · P2 — `LifestyleConnectionCleanup` observer test only covers the positive path; no test proves unrelated field updates don't trigger it
    - **Where:** `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php:97-109`; guard lives in `app/Observers/User/UserObserver.php:99` (`if ($professional->wasChanged('account_type'))`)
    - **Affects:** Every user profile update — a regression that widens or drops the `wasChanged('account_type')` guard would silently soft-delete a user's active platform connections on an unrelated edit (e.g. changing their display name).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('does not clean up lifestyle connections when a non-account_type field is updated')`: update `display_name` (or another field) on a business-account user with active lifestyle connections, and assert the count is unchanged.
    - **Technical:** `UserObserver::updated()` gates the cleanup call on `$professional->wasChanged('account_type')` — a single boolean condition with no test proving the negative case. Since the cleanup path soft-deletes rows, a broken guard is a silent data-loss bug, not just a coverage gap: every `User::update()` call in the app (there are many) would start pruning connections on accounts that never changed type.
    - **Plain English:** There's a rule that says "only clean up a user's connected apps when they switch from a personal to a business account." Right now we've only proven the rule fires correctly when that switch happens — we've never proven it stays silent for any other kind of profile edit. If that guard ever breaks, editing your name could accidentally wipe out your connected apps, and nothing today would catch it.
    - **Evidence:**
        ```php
        it('cleans up lifestyle connections when a user switches to business (observer)', function () {
            $pro = lccTenant('lcc-switch', 'partna');
            lccConnection($pro->id, 'apple-music');
            lccConnection($pro->id, 'skool');
            lccConnection($pro->id, 'shop');
            expect(lccActiveCount($pro->id))->toBe(3);

            // The switch fires UserObserver::updated → cleanup.
            $pro->update(['account_type' => AccountType::Business->value]);
            AccountCapabilities::flushCache();

            expect(lccActiveCount($pro->id))->toBe(1); // only shop survives
        });
        ```

## P3 — Nice to have

- [ ] **#TEST-8** · P3 — Custom-domain race-condition test's ad-hoc unique index isn't cross-checked against the real migration
    - **Where:** `tests/Feature/Site/CustomDomainTest.php:143-146`; no matching assertion in `tests/Feature/Database/CheckConstraintsTest.php` or `IndexCoverageTest.php`.
    - **Affects:** Confidence that the TOCTOU race test (`LIFE-5`) is actually exercising the same constraint shape that exists in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a grep-based invariant assertion (matching the `CheckConstraintsTest.php`/`WriteDesignKitTest.php` pattern) confirming the real migration defines a unique index on `lower(custom_domain) WHERE custom_domain IS NOT NULL`.
    - **Technical:** The race test creates its own `CREATE UNIQUE INDEX IF NOT EXISTS` statement at test time to simulate the production constraint that triggers the `UniqueConstraintViolationException` path. `CheckConstraintsTest.php` and `IndexCoverageTest.php` (the project's canonical schema-invariant sweeps) contain no reference to `custom_domain`, so nothing guarantees the ad-hoc test index still matches the real migration if either drifts.
    - **Plain English:** This test rehearses a race condition using a copy of the database rule that prevents duplicate custom domains — but it's a hand-built copy, not a check against the real rule in the migration files. The behavior itself is well tested; we just don't have a tripwire that would catch the two definitions drifting apart.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS site.sites_custom_domain_unique '.
            'ON sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL'
        );
        ```

- [ ] **#TEST-9** · P3 — No forget/disconnect test for the `nowbookit` reservation provider
    - **Where:** `tests/Feature/Platforms/ReservationProvidersTest.php:135-141` has the pattern for `resdiary`; no equivalent exists for `nowbookit` despite `nowbookit` having full connect/detect/selection coverage elsewhere in the same file.
    - **Affects:** Users who connect NowBookit and later remove it via the provider-agnostic forget endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('clears a nowbookit reservation via reservations forget (single-slot)')` mirroring the existing resdiary test exactly, swapping the connect call and platform string.
    - **Technical:** The single-slot forget route (`DELETE /api/platforms/reservations`) is provider-agnostic, and `resdiary` already has a dedicated forget test proving the pattern. `nowbookit` is otherwise the most heavily tested reservation provider in this file (connect, detect-routing, selection read-back, 5-key shape) but has no forget test — a one-line gap given the exemplar already exists two providers over.
    - **Plain English:** The reservations page has a "remove" button. We've tested it works for one booking provider (ResDiary) but not for another (NowBookit), even though we thoroughly test everything else about NowBookit. It's a five-minute copy-paste of a test we've already written.
    - **Evidence:**
        ```php
        it('clears a resdiary reservation via reservations forget (single-slot)', function () {
            $user = resUser('rp4');
            actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertOk();
            actingAsUser($user)->deleteJson('/api/platforms/reservations')->assertOk()->assertJsonPath('connected', false);
            expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
        });
        // No analogous test for nowbookit exists in this file.
        ```

- [ ] **#TEST-10** · P3 — Custom domain connect test doesn't verify the KV sync job carries the right data
    - **Where:** `tests/Feature/Site/CustomDomainTest.php:58` (connect test); compare the disconnect test at line 112 in the same file, which already uses the correct pattern.
    - **Affects:** Confidence that connecting a custom domain queues a KV sync for the *correct* user/site, not just "a" `SyncSubdomainToKvJob`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a callback to the `Queue::assertPushed` call on the connect test verifying the dispatched job targets the acting user's site, mirroring the callback already used in the disconnect test on the very next test in the same file.
    - **Technical:** `Queue::assertPushed(SyncSubdomainToKvJob::class)` with no callback only proves the job class was dispatched, not that it carries the right identifying data. The disconnect test two tests later in the same file already demonstrates the correct pattern (`fn ($job) => $job->retireCustomDomain === 'bookwith.me'`), so this is a one-line fix that brings the connect test up to the standard the file already sets for itself.
    - **Plain English:** This is like confirming a package was shipped without checking the address label. The test proves a sync job was queued after connecting a domain, but not that it's queued for the right user — even though the very next test in the same file already checks the label correctly.
    - **Evidence:**
        ```php
        // Connect test — asserts class only, no payload verification:
        Queue::assertPushed(SyncSubdomainToKvJob::class);

        // Disconnect test in the same file — correctly verifies payload:
        Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->retireCustomDomain === 'bookwith.me');
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Staff search test hygiene:** #TEST-4, #TEST-5
    - **Why grouped:** Same file (`StaffUserSearchFiltersTest.php`); fixing the HTTP-bypass issue naturally requires rewriting the tests as real HTTP calls, which is also the vehicle for adding the Postgres-only `q` coverage.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Custom domain test hardening:** #TEST-6, #TEST-8, #TEST-10
    - **Why grouped:** All three live in `tests/Feature/Site/CustomDomainTest.php` and touch the same custom-domain subsystem; a single session can add the Cloudflare-failure test, the KV-payload callback, and the migration cross-check together without re-loading context.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-1 — `ContentSelectionPolicy` has zero test coverage** · touches authorization/policy surface.
- **#TEST-2 — `FeatureAvailabilityPolicy`/`UserSegmentPolicy` staff ability tests missing** · touches authorization/policy surface.
- **#TEST-3 — Singleton design-media replace has no concurrent-request test** · isolated (no shared file/pattern with other findings); requires the same driver-gated-test care as a locking change.
- **#TEST-7 — `LifestyleConnectionCleanup` reverse-guard test missing** · isolated; protects a data-deletion code path, warrants its own review pass.
- **#TEST-9 — No forget test for `nowbookit`** · isolated (no shared file with other bundles); trivial but standalone since it has no natural bundle partner.
