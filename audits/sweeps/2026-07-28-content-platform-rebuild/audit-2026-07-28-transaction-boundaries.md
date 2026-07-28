# Transaction Boundary Correctness Audit — 2026-07-28

**Branch:** development
**Lens:** Transaction boundary correctness — every `DB::transaction`/`DB::beginTransaction` site measured against the gold-standard discipline: no external I/O, no queue dispatch, no cache writes, no side-effecting event dispatch, and no unguarded observer side effects inside a DB transaction.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/Content/ItemMerger.php
- app/Site/Documents/BuildState.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Runtime/EffectLedger.php
- app/Services/User/AccountDeletionService.php
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php
- app/Services/Moderation/ModerationDecisionService.php
- app/Services/Moderation/ModerationCaseService.php
- app/Services/Moderation/ContentReportService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Services/Moderation/ModerationAuditService.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Services/Media/MediaUploadService.php
- app/Services/Feedback/FeedbackService.php
- app/Services/User/UserBootstrapService.php
- app/Services/User/SignupSideEffects.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Moderation/Concerns/DedupesRecipientSends.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php
- app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php
- app/Console/Commands/Moderation/ModerationReverseDecisionCommand.php
- (plus every file in the DeepSeek draft chunks: domain-services, vendor-jobs, platforms, controllers-catalog-routing, controllers-staff-console, media-design)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication. Every DeepSeek draft finding was checked against the real source and dropped:

- **`ItemMerger::merge()` → `BuildState::bump()` (draft TXN-1, domain-services chunk):** dropped — hallucinated premise. `BuildState::bump()` (`app/Site/Documents/BuildState.php`) is a pure `DB::table('site.site_build_state')->upsert(...)` call — a Postgres row write, not a Redis/cache write. It shares the connection with the rest of the transaction and rolls back correctly with everything else. No violation.
- **`ProjectionWriter::projectStream()` and `Lander::land()` (draft TXN-1/TXN-2, ingest-content-site-1 chunk):** dropped — out of lens scope. Neither method contains a `DB::transaction`/`DB::beginTransaction` call at all; the finding is really "this should have been wrapped in a transaction," which is a data-integrity/atomicity concern (better suited to a DINT or LIFE lens pass), not a violation of discipline *within* an existing transaction boundary as this lens defines it.
- **`EffectLedger::once()` (draft TXN-3, ingest-content-site-1 chunk):** dropped — same out-of-scope reasoning, and the draft itself concludes the claim-then-act-without-a-transaction shape is the deliberate, correct design (a transaction can't hold across the billed external effect), which the class's own docblock states explicitly.
- **vendor-jobs / platforms / controllers-catalog-routing / controllers-staff-console / media-design chunks:** DeepSeek reported no findings; spot-checks below did not surface any it missed.

Beyond re-checking the drafts, targeted verification was run across the highest-stakes paths this lens calls out by name (account deletion/GDPR, moderation decisions + audit, signup bootstrap, media upload, feedback) since none of the DeepSeek chunks covered `app/Services/User`, `app/Services/Moderation`, `app/Services/Media`, or `app/Services/Feedback` in depth. All of it holds to the gold standard already:

- `AccountDeletionService` — every `DB::transaction`/`DB::connection('pgsql')->transaction` block (`request()`, `executeConfirmation()`, `restoreSiteAndStatus()`) contains DB writes only. The one queue dispatch inside a transaction (`SendAccountDeletionRequestMailJob::dispatch(...)` in `request()`) is correctly protected: the job sets `$this->afterCommit = true` in its constructor (confirmed in `app/Jobs/Account/SendAccountDeletionRequestMailJob.php`), avoiding the typed-property footgun this codebase has hit before. Mail sends and the idempotency-cache purge both run after the transaction, snapshotting the real email beforehand. `purge()` deliberately runs as an uncommitted sequence of independent, individually-logged steps rather than one transaction — the correct shape for a multi-step teardown with external I/O (R2, Supabase Admin API), matching category 6's guidance verbatim.
- `ModerationDecisionService` / `ModerationCaseService` / `ContentReportService` / `ModerationActionDispatcher` — decision writes, action_log rows, and audit rows are all DB-only inside the transaction; every Horizon dispatch is wrapped in `DB::afterCommit(...)` inside `ModerationActionDispatcher::dispatchFor()`. `ContentReportService::submit()`'s `NotifyStaffOfCaseUpdateJob::dispatch(...)` runs after the transaction closure returns, not inside it.
- `MediaUploadService` / `UserSectionBlockController` / `UserUploadController` — transactions wrap only DB writes plus `pg_advisory_xact_lock`/`lockForUpdate`; all queue dispatches (image/video processing jobs) happen after the transaction returns.
- `FeedbackService::submit()` — transaction is DB-only (advisory lock + insert); the notification job dispatch uses `->afterCommit()` outside the transaction.
- `UserBootstrapService::bootstrap()` — both side-effect calls made inside the outer transaction (`SignupSideEffects::ensureSidestUpdatesSubscription`, `::createWelcomeNotification`) are plain `insertOrIgnore` DB writes with no queue/cache/HTTP calls; cache invalidation (`$this->cache->invalidateUser(...)`) runs after the transaction commits.
- `SendEnquiryNotificationJob` / `SendEnquiryConfirmationJob` — their internal `DB::transaction` blocks are read-only lock guards (idempotency checks only); mail sends and cache/rate-limiter calls all happen after the closure returns.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
