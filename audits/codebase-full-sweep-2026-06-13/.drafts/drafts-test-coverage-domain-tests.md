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
