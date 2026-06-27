# Test Coverage Audit — 2026-06-13

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4-5`
**Source files audited:**
- `app/Policies/`
- `app/Http/Controllers/Api/PublicSite/`
- `app/Jobs/`
- `app/Jobs/Cloudflare/`
- `app/Jobs/Notifications/`
- `app/Jobs/Concerns/GuardsMediaProcessing.php`
- `tests/Feature/PublicSite/`
- `tests/Feature/Site/HandleAliasLifecycleTest.php`
- `tests/Feature/Jobs/`
- `tests/Feature/Moderation/`
- `tests/Feature/Analytics/`
- `tests/Feature/Security/PolicyEnforcement/`
- `tests/Feature/Webhooks/`
- `tests/Unit/Jobs/`
- `tests/Unit/MediaJobReliabilityTest.php`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 0 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **TEST-1** · P0 — IndividualProfileController (the primary public-profile API) has zero tests
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:56`; closest test host would be `tests/Feature/PublicSite/` (no file exists)
    - **Affects:** Every public visitor requesting a professional's sitepage via `GET /api/public/profiles/{handle}` — the Astro Worker subrequest target. Any regression here silently breaks every professional's public page.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Feature/PublicSite/IndividualProfileControllerTest.php` with at minimum:
            - `it('returns 404 for an unknown handle')` — verify the `not_found` sentinel is respected
            - `it('returns 404 when the profile row is deleted between resolve and build')` — the deleted-race path (lines 109–129)
            - `it('returns a 200 payload for a known valid handle')` — cache-miss path via mocked `CacheLockService`
            - `it('returns 404 on blank handle')` — the `$handleLc === ''` guard
        - Use `Mockery::mock(CacheLockService::class)` to control the cache layer; avoid needing the full `site.public_site_payload` DB view.
    - **Technical:** `IndividualProfileController::show` is the single most-read API endpoint in the system — the Astro Worker issues a subrequest here on every edge-cache miss. It implements a two-level cache (handle.resolve → public.profile:{handle}:{ts}), a deleted-race path that busts both the primary and `:stale` twin keys, and a slow-request log. None of these paths are covered. The recent git history (c7a016f4, feat custom domains) routed `*/*` Cloudflare-for-SaaS requests through the Worker, increasing traffic to this endpoint. A refactor of `CacheKeyGenerator`, `IndividualProfilePayloadBuilder`, or the deleted-race key-busting logic can now silently corrupt the public page for every professional without CI catching it.
    - **Plain English:** This is the engine room that powers every professional's public page. When a visitor types in a professional's URL, our system hits this code to fetch and serve their profile. We have zero automated checks for it — it's like building an airplane engine and never running it on a test stand. Every code change (and there have been many lately) could break every professional's public page without us knowing until a real visitor gets a blank screen.
    - **Evidence:**
        ```php
        // IndividualProfileController::show — no test covers any of this
        $resolved = $this->cache->rememberLocked(
            CacheKeyGenerator::handleResolve($handleLc),
            (int) config('partna.public_profile.resolve_cache_ttl', 30),
            function () use ($handleLc) {
                $pro = User::query()->where('handle_lc', $handleLc)->first();
                if (! $pro) {
                    return ['not_found' => true];
                }
                // …
            }
        );

        if ($resolved['not_found'] ?? false) {
            return $this->error('Not found.', 404);
        }
        // deleted-race bust (no test):
        Cache::deleteMultiple([$resolveKey, $resolveKey.':stale']);
        ```

---

## P1 — Fix before pilot launch

- [ ] **TEST-2** · P1 — `PublicSiteController::show` (domain-routing alias redirect) is untested; its logic diverges from the tested `showByHeader` path
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicSiteController.php:23–55`; closest test host: `tests/Feature/Site/HandleAliasLifecycleTest.php`
    - **Affects:** Visitors hitting `{old-subdomain}.partna.au/public/site` (domain-routed path). The existing test in `HandleAliasLifecycleTest.php` (line 110) covers the 301 only via `showByHeader` — the `show` method is a separate code path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test asserting `show` issues a 301 to the canonical subdomain when an active alias is found (mock `SiteCacheService` to return `null` for the alias subdomain, non-null for the canonical subdomain).
        - Add a test asserting `show` returns 404 when the alias exists but `getPublicSitePayload` returns `null` for the canonical subdomain (the published-but-not-cached guard).
        - Add a test asserting `show` returns 404 for an expired alias.
    - **Technical:** `show` and `showByHeader` have materially different alias-redirect conditions. `showByHeader` (line 89) checks `where('is_published', true)` and always redirects to `/`. `show` (line 43) checks whether the canonical site's payload is live in the cache, redirects to `$request->getRequestUri()`, and skips the `Cache-Control` header. A site that is published but has an evicted payload would not get a 301 from `show` but would from `showByHeader`. This divergence is undocumented and untested. Handle renames are a documented lifecycle event — the 301 path WILL be hit for every renamed professional during the 90-day alias window.
    - **Plain English:** When a professional changes their handle, visitors who type the old URL must be automatically redirected to the new one. We have one automated test for this redirect, but it only checks the "header-based" version of the page loader — not the "domain-based" version that visitors actually hit. The two versions have subtly different rules, and the domain-based one is untested. A bug there means some old URLs silently 404 instead of redirecting.
    - **Evidence:**
        ```php
        // PublicSiteController::show — alias redirect block (no test for this path)
        $alias = SiteSubdomainAlias::query()
            ->active()
            ->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)])
            ->first();

        if ($alias) {
            $site = Site::query()->find($alias->site_id);
            if ($site) {
                // Only redirect if the canonical site is actually published (exists in payload view)
                $canonicalPayload = $this->siteCache->getPublicSitePayload($site->subdomain);
                if ($canonicalPayload) {
                    $host = $site->subdomain.'.'.config('partna.public_domain');
                    $url = $request->getScheme().'://'.$host.$request->getRequestUri();
                    return redirect()->to($url, 301);
                }
            }
        }
        ```

---

## P2 — Should fix

- [ ] **TEST-3** · P2 — `SendFeedbackEmailJob` per-recipient cache idempotency guard is not tested
    - **Where:** `app/Jobs/Notifications/SendFeedbackEmailJob.php:80–84`; existing test: `tests/Feature/Jobs/SendFeedbackEmailJobTest.php`
    - **Affects:** Staff who receive feedback notification emails — the idempotency guard prevents duplicate sends on retry, but a regression removing it would be invisible to the current test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('does not re-send to a recipient already marked sent when job runs twice')`: prime the cache key manually, run `handle()`, assert `Mail::assertNothingSent()`.
        - Add `it('retries only the failed recipient, not already-sent recipients')`: mock `Mail::to` to throw on recipient A, assert recipient B was sent exactly once across two `handle()` calls.
    - **Technical:** The job uses `Cache::add($idempotencyKey, true, 86400)` per recipient to guarantee at-most-once delivery within 24 hours. On `Mail::send` failure it calls `Cache::forget($idempotencyKey)` to release the claim. The existing test asserts total `Mail::assertSent` count but never seeds or inspects the cache state, so a refactor that removes `Cache::add` (or changes the key format) would pass the current test while silently eliminating the only dedup guard.
    - **Plain English:** The feedback notification job has a clever "don't send twice" rule — it marks each recipient as done before sending, and unmarks them if the send fails, so retries only re-attempt the ones that actually failed. The existing test checks that emails were sent but never checks whether this mark-as-done logic actually runs. Someone could accidentally delete those three lines and the test would still pass — we'd only find out when we start spamming our own team.
    - **Evidence:**
        ```php
        // SendFeedbackEmailJob::handle — cache guard untested
        $idempotencyKey = 'feedback-email-sent:'.$this->feedbackId.':'.sha1($recipient);
        if (! Cache::add($idempotencyKey, true, 86400)) {
            // Already sent to this recipient on a prior attempt — skip.
            continue;
        }
        // …on failure:
        Cache::forget($idempotencyKey);
        ```

- [ ] **TEST-4** · P2 — `ProcessImageVariantsJob` lock-acquire gate path (concurrent-worker guard) has no test
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:69–76`; existing test: `tests/Feature/Jobs/ProcessImageVariantsJobTest.php`
    - **Affects:** Image processing correctness under Horizon scale-out — the lock prevents two workers racing on the same image.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('skips processing silently when another worker holds the image lock')`: mock `CacheLockService` (or use `Cache::shouldReceive('add')->andReturn(false)`), assert no call to `ImageVariantService::processVariants` and `SiteMedia` state remains `PROCESSING`.
    - **Technical:** `GuardsMediaProcessing::acquireProcessingLock` uses a Redis `SET NX` key (`image:processing-lock:{id}`). When the lock is already held, `handle()` logs and returns — the job completes with no error, but also no variant generation. The existing tests never simulate a held lock. A refactor that breaks the early-return path (e.g., accidentally changing `!` to `&&`) could cause two workers to generate variants concurrently, race on `Storage::put`, and corrupt the PROCESSING state without CI detecting it.
    - **Plain English:** When two workers accidentally pick up the same image processing job at the same time, the locking system is supposed to make one of them stand down immediately. The test suite never tries to trigger this "stand down" situation — it always gives the job a free lock. If the stand-down code got accidentally broken, two workers would fight over the same image and could produce garbled output, with no test ever catching it.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob::handle — lock gate untested
        $lockKey = "image:processing-lock:{$this->imageId}";
        if (! $this->acquireProcessingLock($lockKey)) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.', [
                'image_id' => $this->imageId,
            ]);
            return;
        }
        ```

- [ ] **TEST-5** · P2 — `CasePolicy` methods have no functional test for the staff-only gate
    - **Where:** `app/Policies/CasePolicy.php:16–55`; no test file exists in `tests/Feature/Policies/` or `tests/Feature/Security/PolicyEnforcement/`
    - **Affects:** Moderation staff operations — a regression breaking the `instanceof PartnaStaff` check silently opens the entire moderation queue to regular professionals.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/CasePolicyTest.php` with: `viewAny` and `view` allowed for `PartnaStaff`, denied (expect `AuthorizationException`) for `User` actor; `triage`, `take`, `release`, `decide`, `escalate` type-checked for `PartnaStaff`.
        - The `ModerationPolicyCoverageTest` structural sweep only confirms registration, not that the `instanceof` check is present and correct.
    - **Technical:** `viewAny` and `view` are the only two methods that accept both `User|PartnaStaff` — the others are typed `PartnaStaff` only (PHP enforces them at the type level). The risk is specifically on `viewAny` and `view`: a future refactor that changes `instanceof PartnaStaff` to `instanceof User` (or drops it) would silently allow professionals to enumerate the moderation queue without any test catching it.
    - **Plain English:** The moderation queue has a "staff only" sign on the door, but no one has ever tested whether a regular user walking up to that door actually gets turned away. The code looks correct, but code-that-looks-correct-but-has-never-been-tested is code that can silently break on any future edit.
    - **Evidence:**
        ```php
        // app/Policies/CasePolicy.php
        public function viewAny(User|PartnaStaff $actor): bool
        {
            return $actor instanceof PartnaStaff;
        }

        public function view(User|PartnaStaff $actor, ModerationCase $case): bool
        {
            return $actor instanceof PartnaStaff;
        }
        ```

- [ ] **TEST-6** · P2 — `DecisionPolicy` abilities are untested
    - **Where:** `app/Policies/DecisionPolicy.php:14–22`; no test file exists
    - **Affects:** Staff decision-reversal operations — the only path for moderation audit integrity.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Policies/DecisionPolicyTest.php` asserting `view` and `reverse` are allowed for `PartnaStaff`, and that a `User` actor cannot call them (a `User` cannot be the first argument here, so the test should verify that `Gate::forUser($user)` on a `User` actor fails type dispatch rather than silently passing).
    - **Technical:** Both methods are typed `PartnaStaff` first argument, meaning Laravel's Gate will throw a `TypeError` (not a 403) if a `User` actor somehow reaches the policy. The structural sweep confirms the policy is registered; this test confirms the `PartnaStaff` type is enforced rather than widened in a future refactor.
    - **Plain English:** The moderation team's filed decisions have rules governing who can view or reverse them. Those rules have never been tested end-to-end. It's a small policy with two methods, but untested policies are the ones where a one-line change introduces a silent security hole.
    - **Evidence:**
        ```php
        // app/Policies/DecisionPolicy.php
        public function view(PartnaStaff $staff, Decision $decision): bool
        {
            return true;
        }

        public function reverse(PartnaStaff $staff, Decision $decision): bool
        {
            return true;
        }
        ```

- [ ] **TEST-7** · P2 — `FeatureFlagPolicy` deny-all for `User` actors is untested
    - **Where:** `app/Policies/FeatureFlagPolicy.php:21–34`; no test file exists
    - **Affects:** Defense-in-depth for feature flag management — a misconfigured route that skips the `staff` middleware must still be blocked by the policy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Policies/FeatureFlagPolicyTest.php` asserting `viewAny`, `view`, and `manage` all return `false` for a `User` (professional) actor; use `Gate::forUser($user)->check('manage', new FeatureFlag(...))` pattern so the assertion goes through the Gate layer.
    - **Technical:** The policy comment explicitly documents the defense-in-depth intent: "a misconfigured non-staff route cannot grant access via Gate::forUser($pro)." That documented guarantee is only valuable if it's tested. The `staff` middleware is the primary gate; the policy is the secondary. Without a test the secondary gate's correctness is assumed, not verified.
    - **Plain English:** The feature flag controls have two locks: the staff-route middleware is the main one, and the policy is a backup in case the main one is ever accidentally skipped. We've never checked whether the backup lock actually works. Since we're explicitly relying on it for defense-in-depth, it deserves at least one test.
    - **Evidence:**
        ```php
        // app/Policies/FeatureFlagPolicy.php
        public function viewAny(User $pro): bool
        {
            return false;
        }

        public function view(User $pro, FeatureFlag|FeatureFlagOverride $resource): bool
        {
            return false;
        }

        public function manage(User $pro, FeatureFlag|FeatureFlagOverride|null $resource = null): bool
        {
            return false;
        }
        ```

- [ ] **TEST-8** · P2 — `GdprPolicy::view` ownership gate and 404-not-403 denial are untested
    - **Where:** `app/Policies/GdprPolicy.php:18–25`; no test file exists
    - **Affects:** GDPR export/deletion status endpoints — a regression could expose one professional's export status to another.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Policies/GdprPolicyTest.php` with: owner allowed (200), non-owner denied via `denyAsNotFound()` (assert `AuthorizationException` with status 404, not 403).
        - The 404-not-403 assertion is load-bearing per CLAUDE.md: a 403 response leaks existence of the GDPR request.
    - **Technical:** `GdprPolicy::view` is the sole ability and uses `denyAsNotFound()` on ownership mismatch. `denyIfPendingDeletion()` is intentionally absent (documented: pending-deletion users must still read their own status). Without a test, a future change that calls `denyIfPendingDeletion()` "for safety" would silently block GDPR access for users who are trying to cancel their deletion, violating the documented contract.
    - **Plain English:** The page that shows a professional their GDPR export and deletion status has a simple rule: "your data, your eyes only." Nobody has ever written a test that pretends to be the wrong person asking for that page and confirms they get a "not found" response (not a "permission denied" one — the difference matters because "permission denied" tells the wrong person the data exists).
    - **Evidence:**
        ```php
        // app/Policies/GdprPolicy.php
        public function view(User $actor, Model $resource): bool|Response
        {
            if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
                return $this->denyAsNotFound();
            }

            return true;
        }
        ```

- [ ] **TEST-9** · P2 — `FeedbackPolicy` capability gate and owner-isolation are untested
    - **Where:** `app/Policies/FeedbackPolicy.php:20–56`; no test file exists
    - **Affects:** User-submitted feedback — owner isolation (`view`, `delete`) and the `can_submit_feedback` capability gate (`create`) could both regress silently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/FeedbackPolicyTest.php` covering: `view` owner-allowed/non-owner-404; `create` with capability present (allowed) and `can_submit_feedback = false` (denied, returns false); `delete` non-owner-404; `viewAny` always true.
        - Note: `create` intentionally does NOT call `denyIfPendingDeletion()` — the test should confirm that a pending-deletion user CAN create feedback (this invariant is documented in the policy comment and must not silently regress).
    - **Technical:** `FeedbackPolicy::create` has two distinct checks: `AccountCapabilities::for($actor)->can_submit_feedback` (capability gate) and `$skeleton->user_id === $actor->id` (ownership). The capability gate is currently always-true, but the documented intent is "reserved for future per-account bans" — when that ban bit is flipped, the test ensures it works. The pending-deletion exemption is the most fragile invariant here: the documented contract says these users must be able to give feedback about the deletion flow, but without a test a future "standardize denyIfPendingDeletion everywhere" refactor would silently break that guarantee.
    - **Plain English:** The rules for submitting and viewing feedback have several layers — "only your own messages," "only if your account is allowed to post," and specifically "even accounts being deleted can submit feedback about the deletion." Those last two rules have never been tested, so a future cleanup could accidentally block users from giving feedback right when they're most likely to need to.
    - **Evidence:**
        ```php
        // app/Policies/FeedbackPolicy.php
        public function create(User $actor, Feedback $skeleton): bool|Response
        {
            // Intentionally NOT calling denyIfPendingDeletion()...
            if (! AccountCapabilities::for($actor)->can_submit_feedback) {
                return false;
            }

            return (string) $skeleton->user_id === (string) $actor->id;
        }

        public function view(User $actor, Feedback $feedback): bool|Response
        {
            if ((string) $feedback->user_id !== (string) $actor->id) {
                return $this->denyAsNotFound();
            }

            return true;
        }
        ```

- [ ] **TEST-10** · P2 — `NotificationPolicy::view` global-broadcast edge case is not directly tested
    - **Where:** `app/Policies/NotificationPolicy.php:22–33`; existing test: `tests/Feature/Security/PolicyEnforcement/NotificationPolicyEnforcementTest.php`
    - **Affects:** Notification visibility correctness — the global-broadcast (null `user_id`) permission differs from targeted ownership, and the two paths need independent assertions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `NotificationPolicyEnforcementTest.php` with direct `Gate::forUser($actor)->inspect('view', $globalNotification)` and `Gate::forUser($actor)->inspect('view', $targetedNotification)` assertions so the `view` ability is confirmed independently of which controller action happens to invoke it.
        - Add an assertion that `update` on a global notification (null `user_id`) returns a 404 exception, not 200 — the policy blocks mutations to global notifications, but the existing `dismiss` / `markRead` tests are at 200 (suggesting those controller actions may not go through `update`; if they use `view` instead, the `update` blocking of global mutations is never exercised).
    - **Technical:** The existing tests exercise `markRead` and `dismiss` controller actions, which return 200 on global notifications. `NotificationPolicy::update` explicitly blocks global notification mutations (`return $this->denyAsNotFound()`). If `markRead`/`dismiss` use `view` rather than `update` as their policy gate, the `update` block for global notifications is dead code in terms of test coverage — and the `delete` method (which delegates to `update`) is likewise untested for the global-notification path. A future endpoint that does use `update` as its gate would inherit an untested assumption.
    - **Plain English:** Notifications come in two flavours: ones sent to a specific professional, and "broadcast" ones sent to everyone. The rules for each flavour are different — broadcasts can be read by anyone but nobody should be able to modify them. The tests check that the read-mark and dismiss buttons work for broadcasts, but they never directly verify the "no modifying broadcasts" rule. If a future endpoint accidentally allowed editing a broadcast notification, the current tests wouldn't catch it.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php
        public function update(User $actor, Model $resource): bool|Response
        {
            // Global notifications have no single owner — deny all mutations.
            if ($resource instanceof Notification && $resource->user_id === null) {
                return $this->denyAsNotFound();
            }
            // …
        }
        ```

- [ ] **TEST-11** · P2 — `PartnaStaffPolicy` self-edit and self-delete guards are untested
    - **Where:** `app/Policies/PartnaStaffPolicy.php:31–72`; no test file exists
    - **Affects:** Staff record management — the self-edit/self-delete block prevents an admin from locking the org out of admin access; broken, this would be a safety incident.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/PartnaStaffPolicyTest.php` with:
            - `view`: admin sees any staff record (200); support sees own (200); support denied for another staff member (expect 404).
            - `update`: admin allowed for another staff (200); admin denied for self (expect 403); support denied entirely (expect 403).
            - `delete`: same shape as `update`.
        - Use `actingAsStaff` helper; test both `admin` and `support` role actors.
    - **Technical:** The policy has three critical invariants: (a) admins can manage anyone but not themselves, (b) support can only view their own record, (c) support cannot update or delete. Invariant (a) is the most operationally dangerous — a broken self-edit guard means an admin can accidentally promote/demote themselves, removing the second-actor requirement for role changes. None of these invariants are under test. The `ModerationPolicyCoverageTest` confirms registration but not correctness.
    - **Plain English:** The rules for managing staff accounts include an important safety net — a staff admin cannot change or delete their own account, preventing someone from accidentally removing the last admin or changing their own permissions. That safety net has never been tested. It's like an organization's "two-person rule" for critical decisions that nobody has ever drilled.
    - **Evidence:**
        ```php
        // app/Policies/PartnaStaffPolicy.php
        public function update(PartnaStaff $actor, PartnaStaff $target): bool|Response
        {
            if (! $actor->isAdmin()) {
                return false;
            }
            // No self-edit — an admin must not mutate their own staff record
            if ((string) $actor->id === (string) $target->id) {
                return false;
            }
            return true;
        }
        ```

- [ ] **TEST-12** · P2 — `UserSelfPolicy` staff-actor abilities (`staffManage`, `staffForceDelete`, `staffBulkManage`) are untested
    - **Where:** `app/Policies/UserSelfPolicy.php:85–113`; existing test: `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php`
    - **Affects:** Staff-side user management — the admin-only gates on hard-delete and bulk-status-update are the only policy-layer check preventing support staff from running irreversible mass operations.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add to `UserSelfPolicyEnforcementTest.php` (or a sibling file):
            - `staffManage`: admin allowed (true); support denied (false).
            - `staffForceDelete`: admin allowed (true); support denied (false). Assert the policy comment's "defense-in-depth" intent — the route group has `staff.admin`, the policy is the fallback.
            - `staffBulkManage`: admin allowed (true); support denied (false).
        - Add a `view` test: owner allowed (200); different professional denied (expect 404, not 403).
    - **Technical:** The comment in `UserSelfPolicy` explicitly states that `destroy` and `restore` are behind staff-only routes with no `staff.admin` middleware — the policy IS the enforcement point for those operations for support staff. `staffForceDelete` and `staffBulkManage` are defense-in-depth for admin-gated routes. Neither role is tested. The existing `UserSelfPolicyEnforcementTest` covers `User`-actor paths only (update, deletion confirm, AAL2 gate) — no `PartnaStaff` actor test exists.
    - **Plain English:** There's a special set of rules for staff managing user accounts — only admins can permanently delete accounts or run bulk operations, while regular support staff can only do reversible things. Those rules are enforced in this policy, but nobody has ever tested them. A support staff member who shouldn't be able to bulk-suspend users could potentially do so if the gate code ever had a bug, and we wouldn't catch it until it happened.
    - **Evidence:**
        ```php
        // app/Policies/UserSelfPolicy.php
        public function staffForceDelete(PartnaStaff $actor, User $target): bool
        {
            // Admin-only: irreversible action requires the highest privilege tier.
            return $actor->isAdmin();
        }

        public function staffBulkManage(PartnaStaff $actor): bool
        {
            return $actor->isAdmin();
        }
        ```

- [ ] **TEST-13** · P2 — `IntegrationConnectionPolicy` owner isolation and 404-on-not-yours are untested
    - **Where:** `app/Policies/IntegrationConnectionPolicy.php:14–57`; no test file exists
    - **Affects:** Every platform connection (Instagram, Twitch, YouTube, etc.) — cross-tenant access could expose one professional's OAuth tokens to another.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/IntegrationConnectionPolicyTest.php` with: `view` owner-allowed, non-owner-404; `update` pending-deletion-423, non-owner-404, owner-allowed; `delete` same shape as `update`; `create` (skeleton pattern) owner-allowed, non-owner returns `false`.
        - Verify the `ownerMatches` raw-attribute read (`getAttributes()['user_id']`) is exercised with a null `user_id` (should return false, not error).
    - **Technical:** `IntegrationConnectionPolicy` uses `getAttributes()` directly (not the Eloquent accessor) to read `user_id`, with an `array_key_exists` null guard. The policy covers `view`, `update`, `delete`, and `create` — four abilities, all ownership-gated, none tested. Platform connections carry OAuth refresh tokens; a broken ownership gate would be a direct credential leak across tenants. `PolicyCoverageTest` confirms the model is registered; it does not confirm the ownership logic is correct.
    - **Plain English:** Each professional's linked social accounts (Instagram, Twitch, etc.) are supposed to be completely private — only they can see or change their own connections. Nobody has ever written a test that tries to view or edit someone else's connections to make sure they get a "not found" response. This is the highest-stakes untested policy in the system because the data it protects is OAuth credentials.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationConnectionPolicy.php
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }

        private function ownerMatches(User $actor, Model $resource): bool
        {
            $rawAttrs = $resource->getAttributes();

            return array_key_exists('user_id', $rawAttrs)
                && $rawAttrs['user_id'] !== null
                && (string) $rawAttrs['user_id'] === (string) $actor->id;
        }
        ```

