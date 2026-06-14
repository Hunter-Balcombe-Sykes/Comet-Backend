
<!-- ═══ LENS: test-coverage | CHUNK: sweep-tests ═══ -->

- [ ] **TEST-1** · P2 — CasePolicy methods lack any functional test
    - **Where:** app/Policies/CasePolicy.php:14‑35
    - **Affects:** Moderation staff operations – no safety net if a refactor breaks staff‑only access.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Policies/CasePolicyTest.php` with Pest tests for each public method (`viewAny`, `view`, `triage`, `take`, `release`, `decide`, `escalate`), asserting allowed for staff and denied for non‑staff.
        - Include a non‑staff actor test that expects a 404 via `denyAsNotFound`.
    - **Technical:** The policy class has seven methods that all return `true` for a `PartnaStaff` instance. There is no test that exercises any of them; the structural sweep (`ModerationPolicyCoverageTest`) only confirms registration, not correctness. A refactor that accidentally drops the `instanceof` check would silently open the gates.
    - **Plain English:** The rulebook for the moderation team says “staff only,” but nobody has ever walked through each rule to see if it actually keeps non‑staff out. It’s like a club with a bouncer at the door but no one has ever tried to walk in without showing ID.
    - **Evidence:**
        ```php
        // app/Policies/CasePolicy.php
        public function viewAny(User|PartnaStaff $actor): bool { return $actor instanceof PartnaStaff; }
        public function view(User|PartnaStaff $actor, ModerationCase $case): bool { return $actor instanceof PartnaStaff; }
        public function triage(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function take(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function release(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function decide(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function escalate(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        ```
        No test file matching `CasePolicy` in the provided `tests/Feature/Security/PolicyEnforcement/` or any other file.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-2** · P2 — DecisionPolicy abilities are untested
    - **Where:** app/Policies/DecisionPolicy.php:12‑22
    - **Affects:** Staff decision viewing and reversal – regression would break audit integrity.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Policies/DecisionPolicyTest.php` with two tests: `view` allowed for staff, `reverse` allowed for staff, and denial for non‑staff actors.
    - **Technical:** Both `view` and `reverse` unconditionally return `true` for a `PartnaStaff`. Without a test, a future tightening of the policy could mistakenly block staff without detection. The structural `ModerationPolicyCoverageTest` only ensures registration, not behavior.
    - **Plain English:** The decisions filed by the moderation team have their own set of rules, but those rules have never been checked. It’s like a filing cabinet with a lock that nobody has ever turned.
    - **Evidence:**
        ```php
        // app/Policies/DecisionPolicy.php
        public function view(PartnaStaff $staff, Decision $decision): bool { return true; }
        public function reverse(PartnaStaff $staff, Decision $decision): bool { return true; }
        ```
        No test file for `DecisionPolicy` in the provided test set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-3** · P2 — FeatureFlagPolicy has no test confirming its deny‑all stance
    - **Where:** app/Policies/FeatureFlagPolicy.php:17‑27
    - **Affects:** Defensive layer – if a future route accidentally drops the staff middleware, a missing test could allow professionals to manage flags.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Policies/FeatureFlagPolicyTest.php` verifying that `viewAny`, `view`, and `manage` all return `false` for a professional actor (User).
    - **Technical:** The policy is intentionally deny‑all for User actors; the real authorization is via the `staff` middleware. A test ensures that a misconfigured route that skips the middleware still cannot grant access, fulfilling the defense‑in‑depth promised by the policy.
    - **Plain English:** The feature‑flag locker says “staff only – everyone else keep out,” but we’ve never checked that a regular user actually gets turned away. It’s like a “employees only” sign with no lock.
    - **Evidence:**
        ```php
        // app/Policies/FeatureFlagPolicy.php
        public function viewAny(User $pro): bool { return false; }
        public function view(User $pro, FeatureFlag|FeatureFlagOverride $resource): bool { return false; }
        public function manage(User $pro, FeatureFlag|FeatureFlagOverride|null $resource = null): bool { return false; }
        ```
        No test file for `FeatureFlagPolicy` among the provided tests.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-4** · P2 — FeedbackPolicy abilities are not tested
    - **Where:** app/Policies/FeedbackPolicy.php:18‑47
    - **Affects:** User‑submitted feedback – owner isolation and the `can_submit_feedback` gate could regress.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/FeedbackPolicyTest.php` covering `view`, `create`, `delete`, and `viewAny` with owner‑allowed and non‑owner‑denied cases, plus a test that `create` is blocked when the capability is absent.
    - **Technical:** The policy enforces ownership checks and a capability gate. No test exercises these paths; a refactor that drops the `can_submit_feedback` check or the ownership comparison would go unnoticed. The pattern matches the existing policy enforcement tests for Customer.
    - **Plain English:** The feedback box rules say “only your own messages and only if you’re allowed,” but nobody has tried to peek at someone else’s feedback or to post when banned. It’s like a suggestion box with no lid.
    - **Evidence:**
        ```php
        // app/Policies/FeedbackPolicy.php
        public function view(User $actor, Feedback $feedback): bool|Response { … }
        public function create(User $actor, Feedback $skeleton): bool|Response { … }
        public function delete(User $actor, Feedback $feedback): bool|Response { … }
        public function viewAny(User $actor): bool { return true; }
        ```
        No test file for `FeedbackPolicy` in the provided test suite.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-5** · P2 — GdprPolicy has no test of its ownership gate
    - **Where:** app/Policies/GdprPolicy.php:15‑23
    - **Affects:** GDPR export/deletion status visibility – a refactor could expose requests to non‑owners.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Policies/GdprPolicyTest.php` testing `view` for allowed (owner) and denied (non‑owner → 404).
    - **Technical:** The policy’s only ability, `view`, compares `user_id` and returns `denyAsNotFound()` on mismatch. Without a test, a future change to the owner resolution logic (e.g., switching to a relationship) could break the denial without alert.
    - **Plain English:** The privacy‑request records have a strict “my eyes only” rule, but we’ve never verified that someone else’s request actually returns a “not found.” It’s like a confidential file drawer with no label.
    - **Evidence:**
        ```php
        // app/Policies/GdprPolicy.php
        public function view(User $actor, Model $resource): bool|Response {
            if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
                return $this->denyAsNotFound();
            }
            return true;
        }
        ```
        No test file for `GdprPolicy` among the provided tests.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-6** · P2 — IntegrationConnectionPolicy owner isolation is not tested
    - **Where:** app/Policies/IntegrationConnectionPolicy.php:15‑51
    - **Affects:** Platform connections (e.g., Instagram, Twitch) – cross‑tenant access could leak account data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/IntegrationConnectionPolicyTest.php` with tests for `view`, `update`, `delete`, and `create` for allowed (owner) and denied (non‑owner → 404).
    - **Technical:** The policy resolves ownership via `getAttributes()['user_id']`. None of its four abilities are exercised by the provided test files; the policy is registered via `PolicyCoverageTest` but correctness is unchecked.
    - **Plain English:** Each professional’s linked accounts (like social media) have a gate that says “only the account owner,” but no one has tried to open someone else’s connection. It’s like a phone lock screen with no PIN.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationConnectionPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function update(User $actor, Model $resource): bool|Response { … }
        public function delete(User $actor, Model $resource): bool|Response { … }
        public function create(User $actor, Model $skeleton): bool|Response { … }
        ```
        No test file for `IntegrationConnectionPolicy` in the file set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-7** · P2 — PartnaStaffPolicy self‑service and admin gates are untested
    - **Where:** app/Policies/PartnaStaffPolicy.php:28‑69
    - **Affects:** Staff record management – a broken self‑edit lock could allow an admin to accidentally lock the org out.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Policies/PartnaStaffPolicyTest.php` covering `view` (admin sees all, support sees own, support denied for others → 404), `update` (admin allowed, support denied, self‑edit denied), and `delete` (similar).
        - Use `actingAsStaff` helper with appropriate roles.
    - **Technical:** The policy encodes important invariants: self‑edit and self‑delete are forbidden; support staff can only see their own record. None of these paths are tested. A refactor could inadvertently allow self‑promotion or support‑role escalation without CI feedback.
    - **Plain English:** The rules for managing staff accounts include a safety net — an admin can’t accidentally fire themselves or change their own role. But that net has never been tested. It’s like an emergency brake that nobody has pulled.
    - **Evidence:**
        ```php
        // app/Policies/PartnaStaffPolicy.php
        public function view(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        public function update(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        public function delete(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        ```
        No test file for `PartnaStaffPolicy` in the provided set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-8** · P2 — NotificationPolicy `view`, `update`, and `delete` abilities are untested
    - **Where:** app/Policies/NotificationPolicy.php:21‑48
    - **Affects:** Notification read/update – only mark‑read and dismiss (which likely exercise `view`/`update` under the hood) have tests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `tests/Feature/Security/PolicyEnforcement/NotificationPolicyEnforcementTest.php` to include explicit tests for `view`, `update`, and `delete` on both targeted and global notifications, verifying 404 for non‑owners and 423 for pending‑deletion.
    - **Technical:** The provided test only covers `markRead` and `dismiss` via the controller. The policy also defines `view` (which may be used by other endpoints), `update`, and `delete`. Without tests for those abilities, a refactor could break ownership checks on new notification endpoints without detection.
    - **Plain English:** The notification rules have several different permissions (“see”, “change”, “remove”), but we’ve only checked two of them. It’s like a bank vault with three locks but we only tested two keys.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function update(User $actor, Model $resource): bool|Response { … }
        public function delete(User $actor, Model $resource): bool|Response { … }
        ```
        The existing test file `tests/Feature/Security/PolicyEnforcement/NotificationPolicyEnforcementTest.php` only exercises paths reachable via `markRead` and `dismiss` controller actions.
    - `[DRAFT, confidence: 0.8]`

- [ ] **TEST-9** · P2 — UserSelfPolicy staff abilities and `view` are untested
    - **Where:** app/Policies/UserSelfPolicy.php:74‑101
    - **Affects:** Staff‑side user management – missing tests for `staffManage`, `staffForceDelete`, `staffBulkManage`, and the simple `view` self‑service ability.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add tests to `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php` (or a new file) covering:
            - `view` for allowed (own profile) and denied (other professional → 404).
            - `staffManage`, `staffForceDelete`, `staffBulkManage` for admin allowed and support denied.
    - **Technical:** The policy already enforces admin‑only for staff write operations and owner‑only for self‑service `view`. The provided test only covers `update` and deletion confirm. Staff‑side abilities are not exercised; a future change that weakens the admin gate would pass CI unnoticed.
    - **Plain English:** The rules for staff managing user accounts include special restrictions — only admins can permanently delete or bulk‑update. But those restrictions have never been put to the test. It’s like having a “manager’s keycard only” door without ever checking if a regular employee can walk through.
    - **Evidence:**
        ```php
        // app/Policies/UserSelfPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function staffManage(PartnaStaff $actor, User $target): bool { return $actor->isAdmin(); }
        public function staffForceDelete(PartnaStaff $actor, User $target): bool { return $actor->isAdmin(); }
        public function staffBulkManage(PartnaStaff $actor): bool { return $actor->isAdmin(); }
        ```
        `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php` only tests `update` and deletion confirm; no staff‑actor tests are present.
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ LENS: test-coverage | CHUNK: domain-tests ═══ -->

- [ ] **#TEST-1** · P0 — Public sitepage resolution path (IndividualProfileController, PublicSiteController, PublicSiteResolver, IndividualProfilePayloadBuilder, SitepageDataResolverService) has no test coverage in the provided scope
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php, app/Http/Controllers/Api/PublicSite/PublicSiteController.php, app/Services/PublicSite/PublicSiteResolver.php, app/Services/PublicSite/IndividualProfilePayloadBuilder.php, app/Services/PublicSite/SitepageDataResolverService.php
    - **Affects:** Every public visitor requesting a professional’s sitepage. A regression here silently breaks the entire public-facing product.
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Add feature tests under `tests/Feature/PublicSite/` for:
          `it('returns cached profile payload for a valid handle')`,
          `it('returns 404 for unknown handle')`,
          `it('returns 404 for unpublished/hidden site')`,
          `it('returns 404 on deleted-race (resolve cache → miss)')`
        - Cover the handle.resolve short-TTL cache, the full-payload cache with SWR, and the slow-request warning log.
    - **Technical:** The hot path is a two-level cache: handle.resolve maps handle to pro_id/site_id/updated_at_ts, then public.profile:{handle}:{ts} builds the payload via CacheLockService::rememberLocked with single-flight and stale-while-revalidate. A missing test means any change to the builder shape, resolver filtering, or cache-key composition can break every individual sitepage at the edge before CI catches it.
    - **Plain English:** This is the engine that shows a professional’s public page to a visitor. If it breaks, every professional’s site goes blank. We have zero automated tests that simulate a visitor hitting those URLs — it’s like flying a plane without checking the altimeter before takeoff.
    - **Evidence:**
        ```php
        // IndividualProfileController::show – the entire §28.8 entry point
        $resolved = $this->cache->rememberLocked(
            CacheKeyGenerator::handleResolve($handleLc),
            (int) config('partna.public_profile.resolve_cache_ttl', 30),
            function () use ($handleLc) { … }
        );
        // … no test exercises this path or the subsequent payload build
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-2** · P0 — Handle alias 301s / KV sync / account deletion lifecycle have no test coverage
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSiteController.php (alias redirect), app/Services/PublicSite/PublicSiteResolver.php (resolvePublishedSite, active() scope), app/Jobs/Cloudflare/SyncSubdomainToKvJob.php (sole KV writer), app/Jobs/Account/SendAccountDeletionRequestMailJob.php, app/Jobs/Gdpr/ExportUserDataJob.php
    - **Affects:** Visitors hitting old handles → must 301 to canonical URL; expired aliases → 404; KV routing for Cloudflare Worker; GDPR right-of-access exports and deletion flow. A break here drops visitors, leaks stale KV entries, or silently fails deletion.
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Add tests for `PublicSiteController@show` alias redirect (active alias → 301, expired alias → 404).
        - Test `SyncSubdomainToKvJob` happy path (active user → upsert, soft-deleted → delete entry, handles aliases with TTL).
        - Test `SendAccountDeletionRequestMailJob` idempotency under concurrent dispatch and token mismatch.
        - Test `ExportUserDataJob` happy path, audit status transitions, and failed() fallback.
    - **Technical:** PublicSiteResolver uses `->active()` scope on `site.site_subdomain_aliases` to filter expired aliases. `SyncSubdomainToKvJob` is the only writer to Cloudflare KV — any bug in its logic (e.g., leaking an alias after expiry, failing to delete a soft-deleted user) lasts until the next prune cycle or a manual fix. The deletion mail job has elaborate idempotency and token-rotation guards that must be proven correct.
    - **Plain English:** When a professional changes their handle, their old page must redirect visitors to the new one. If our redirect logic has a bug, people land on error pages and think the professional is gone. We haven’t written automated checks to make sure the redirect works, the old handle eventually stops working, and the Cloudflare routing table stays in sync.
    - **Evidence:**
        ```php
        // PublicSiteController alias redirect — untested
        $alias = SiteSubdomainAlias::query()->active()->whereRaw('lower(subdomain) = ?', [$subdomain])->first();
        if ($alias) { /* redirect to canonical */ }
        // SyncSubdomainToKvJob::handle – untested
        $kv->put($current, ['type' => 'individual'], null);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-3** · P0 — Bootstrap / signup with waitlist diversion has zero test coverage
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php
    - **Affects:** Every new professional signup — the account creation path. A bug can prevent signups or allow bypass of the individual waitlist gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('creates a new professional and site on valid bootstrap')`
        - Add `it('diverts to individual waitlist when config is enabled')`
        - Add `it('returns WAITLIST_ONLY when global waitlist mode is on and user is new')`
        - Add `it('returns ACCOUNT_DISABLED when status is disabled')`
        - Add `it('returns EMAIL_ALREADY_REGISTERED conflict')`
    - **Technical:** BootstrapController orchestrates signup gating (waitlist mode, individual waitlist diversion, email‑in‑use checks) and delegates to UserBootstrapService. None of the branching — feature flags, error codes, 403 vs 409 responses — is unit‑tested. The individual waitlist divert writes to `core.waitlist_signups` with firstOrCreate; race resilience and data preservation are unchecked.
    - **Plain English:** This is the front door for every new professional. If the sign‑up logic breaks, the whole business stops growing. We don’t have a single automated test that pretends to be a new user hitting “Create Account” — we are literally guessing that it still works after every code change.
    - **Evidence:**
        ```php
        // BootstrapController::bootstrap – complex branching, no test
        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(…, 403, ['code' => 'WAITLIST_ONLY']);
        }
        if ((bool) config('partna.individual_waitlist_enabled', false) && ! $this->hasExistingProfessional($uid)) {
            // divert…
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-4** · P1 — Analytics ingest hot path (RecordAnalyticsEventJob, AnalyticsController dedup, ping) has no test coverage
    - **Where:** app/Jobs/Analytics/RecordAnalyticsEventJob.php, app/Http/Controllers/Api/PublicSite/AnalyticsController.php
    - **Affects:** All analytics data collection — pageviews, clicks, section‑seen, session pings. Wrongness here corrupts the analytics that professionals rely on and can hide site‑health problems.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Test `RecordAnalyticsEventJob` dedup: same event dispatched twice → writer receives one call.
        - Test `AnalyticsController` click/section‑seen Redis dedup (claim, duplicate returns original id).
        - Test bot‑UA filtering per endpoint (pageview exempt, others 200/fake‑success).
        - Test ping endpoint upsert path (no dedup, idempotent via GREATEST()).
    - **Technical:** The controller performs in‑memory dedup via `AnalyticsDedupGuard::claim` with per‑event Redis keys and short TTLs. The job uses `insertOrIgnore` on a minted PK for at‑least‑once dedup. Both are subtle and must be verified against retries, race conditions, and bot traffic. Without tests, any refactor of the analytics pipeline can silently double‑count or drop events.
    - **Plain English:** Every time a visitor views a page, clicks a link, or scrolls to a section, we record an event to help the professional understand their audience. If our counting logic has a bug — like counting the same click twice or ignoring real visitors — the analytics become misleading. We haven’t written any automated checks to make sure our counting works correctly.
    - **Evidence:**
        ```php
        // AnalyticsController click dedup — untested
        $claim = $this->dedup->claim("analytics:dedup:click:{$target}:{$identifier}", $id, 3);
        if (! $claim['novel']) { return …; }
        // RecordAnalyticsEventJob dedup — untested
        $writer->write($event); // relies on insertOrIgnore
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TEST-5** · P1 — Moderation enforcement and notification jobs (Suspend, Ban, Quarantine, Purge, Staff/User notifications) have no test coverage
    - **Where:** app/Jobs/Moderation/ (SuspendUserJob, SuspendSiteJob, QuarantineMediaJob, PurgeModerationCacheJob, NotifyOnCallStaffJob, NotifyReportedUserJob, NotifyReporterJob, NotifyStaffOfCaseUpdateJob)
    - **Affects:** Safety‑critical actions that hide content, ban users, and notify staff/reporters after review decisions. A silent failure can leave harmful content live or fail to alert on‑call staff.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add tests for each enforcement job: correct state transition applied, action log entry marked dispatched/completed, failed() updates status.
        - Add tests for notification jobs: correct notification sent based on decision type, no notification dropped when owner missing or capability‑gated.
        - Test that SuspendSiteJob resolves the site correctly for CSAM (SiteMedia) vs human‑report (Site) targets.
    - **Technical:** Every enforcement job uses `HasActionLogLifecycle` to mark dispatched/completed atop the DB transaction. Notification jobs route differently based on decision type (hide_content, ban_user, etc.) and capability gates (`AccountCapabilities::for($user)->receive_moderation_notifications`). Without tests, a mis‑mapped decision type or a null owner bypass could leave harmful content visible or fail to notify the victim/reporter.
    - **Plain English:** When our content moderation team decides to hide a page or ban a user, automated background jobs do the actual work. If those jobs break silently — maybe the user doesn’t get notified, or the page stays visible — victims are exposed and our trustworthiness evaporates. We haven’t written any tests that simulate a decision and then check that the right thing happened.
    - **Evidence:**
        ```php
        // SuspendUserJob — untested
        User::query()->where('id', $case->reportable_owner_user_id)->update(['status' => $newStatus]);
        // NotifyReportedUserJob — untested
        if (! AccountCapabilities::for($user)->receive_moderation_notifications) { return; }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TEST-6** · P1 — Video processing pipeline (ProcessVideoVariantsJob, DeleteMediaArtifactsJob) has no test coverage
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php, app/Jobs/DeleteMediaArtifactsJob.php
    - **Affects:** Professionals who upload video — their videos may never become visible, or orphaned media may accumulate indefinitely.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Test `ProcessVideoVariantsJob` happy path (variants created, state → READY), terminal‑state guard, soft‑deleted skip, original‑not‑found → fail, and failed() cleanup.
        - Test `DeleteMediaArtifactsJob` happy path (artifacts deleted), retry exhaustion, failed() reporting.
        - Mock `VideoVariantService`; use SQLite for DB state transitions.
    - **Technical:** ProcessVideoVariantsJob mirrors ProcessImageVariantsJob but with a vastly longer timeout (720s) and a dedicated Redis connection. The lock strategy via `GuardsMediaProcessing` is identical, and the same terminal‑state guard logic applies. DeleteMediaArtifactsJob has a critical exponential backoff (60→300→900) and must delete HLS segments from R2 — a missing test means orphaned segments could leak and inflate storage costs.
    - **Plain English:** When a professional uploads a video, it needs to be converted into formats the browser can play. That’s a complex, multi‑step process. We have zero automated tests for it — if a library upgrade or a config change breaks video conversion, we won’t know until a user complains their video isn’t showing up.
    - **Evidence:**
        ```php
        // ProcessVideoVariantsJob::runHandle – untested
        $service->processVariants(localOriginalPath: $localTmp, mediaId: $this->mediaId, basePath: $this->basePath);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TEST-7** · P2 — Many jobs lack dedicated tests for their `failed()` handlers and idempotency under retry
    - **Where:** Several jobs (CloudflareCachePurgeJob, SyncSubdomainToKvJob, ExportUserDataJob, SendEnquiryConfirmationJob, SendEnquiryNotificationJob, SendSubscriptionConfirmationJob, SendTransactionalNotificationEmailJob, etc.)
    - **Affects:** Observability of permanent failures (Nightwatch alerting) and correctness when Horizon retries after a partial success.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('calls failed() and clears token on permanent mail failure')` for the deletion mail job.
        - Add `it('marks export failed after retries')` for ExportUserDataJob.
        - Add `it('updates action log entry to failed on permanent failure')` for each moderation job.
        - Add idempotency‑under‑retry tests: run `handle()` twice and assert no double‑side‑effect.
    - **Technical:** Partna’s observability depends on `failed()` calling `report()` to trigger Nightwatch alerts. Jobs that don’t test `failed()` can silently lose exceptions after retries. Idempotency tests (e.g., two concurrent `SendEnquiryNotificationJob` runs with `lockForUpdate` stamp) are essential for at‑most‑once delivery guarantees.
    - **Plain English:** When a background job fails permanently — maybe the email server is down for hours — our monitoring system needs to hear about it so we can fix it before a professional notices. We aren’t testing that the alerting part of these jobs works, which means we could be silently dropping emails and never knowing.
    - **Evidence:**
        ```php
        // SendEnquiryNotificationJob — failed() calls report() but untested
        public function failed(\Throwable $e): void { report($e); … }
        // SyncSubdomainToKvJob::failed — untested
        public function failed(Throwable $e): void { report($e); … }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TEST-8** · P2 — ProcessImageVariantsJob tests miss concurrency lock and `failed()` cleanup coverage
    - **Where:** tests/Feature/Jobs/ProcessImageVariantsJobTest.php, app/Jobs/ProcessImageVariantsJob.php, app/Jobs/Concerns/GuardsMediaProcessing.php
    - **Affects:** Image processing pipeline reliability under Horizon scale‑out; orphaned R2 files after permanent failures.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('skips when another worker holds the processing lock')` — mock Redis SET NX return false, assert job returns without reprocessing.
        - Add `it('calls cleanupR2Artifacts on permanent failure')` — force `failed()` and verify `deleteVariants` is called.
    - **Technical:** The job acquires a Redis lock (`image:processing-lock:{id}`) via `GuardsMediaProcessing` to prevent parallel variant generation. The existing tests never exercise the lock-acquire-gate path. The `failed()` method triggers `cleanupR2Artifacts()` which calls `ImageVariantService::deleteVariants` — no test confirms the service mock receives that call when `failed()` runs.
    - **Plain English:** We added a safety lock so two workers don’t try to process the same image at the same time. The tests never check that the lock actually works — if we accidentally broke it, we wouldn’t know until a user saw garbled images. Also, when a job fails permanently, it’s supposed to clean up leftover files to save storage, but that cleanup isn’t tested either.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob::handle — lock acquire untested
        if (! $this->acquireProcessingLock($lockKey)) { return; }
        // cleanupR2Artifacts call in failed() — untested
        private function cleanupR2Artifacts(): void { app(ImageVariantService::class)->deleteVariants(…); }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TEST-9** · P2 — SendFeedbackEmailJob missing per‑recipient idempotency test under retry
    - **Where:** tests/Feature/Jobs/SendFeedbackEmailJobTest.php, app/Jobs/Notifications/SendFeedbackEmailJob.php
    - **Affects:** Staff feedback emails; a retry could send duplicate mails to recipients who already received one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('does not send to a recipient already marked sent via cache')` — run `handle()` twice with same feedbackId, assert `Mail::assertSent` count stays at initial value.
        - Add `it('retries only the failed recipient and re‑marks cache key on failure')` — throw on first recipient then re‑run, verify second recipient still gets one mail.
    - **Technical:** The job uses `Cache::add` per recipient (`feedback-email-sent:{feedbackId}:{sha1(recipient)}`) for at‑most‑once delivery within 24h. On failure, it deletes the idempotency key so the retry can attempt that recipient again. The existing test only asserts total mail count; it doesn’t assert the cache‑gated idempotency, so a regression that removes the cache check could go unnoticed.
    - **Plain English:** When a staff member gets feedback email, the job is supposed to make sure they don’t get the same email twice if the job retries. The test checks that the right number of emails are sent, but it doesn’t check that the “already sent” guard actually works. If we accidentally removed that guard, we’d spam our own team without realizing it.
    - **Evidence:**
        ```php
        // SendFeedbackEmailJob per-recipient idempotency — untested
        $idempotencyKey = 'feedback-email-sent:'.$this->feedbackId.':'.sha1($recipient);
        if (! Cache::add($idempotencyKey, true, 86400)) { continue; }
        ```
    - `[DRAFT, confidence: 0.85]`
