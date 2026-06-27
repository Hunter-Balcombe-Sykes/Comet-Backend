Now I have the complete picture. `VideoVariantService::deleteVariants()` uses `$disk->allFiles($basePrefix)` (directory listing, not DB lookups) so the cascade removing `media_variants` DB rows doesn't block R2 cleanup — but permanent job failure still leaves files orphaned with no recovery path. Let me write the final audit.

# GDPR Deletion Completeness Audit — 2026-05-31

**Branch:** development
**Lens:** GDPR deletion completeness, cascade gaps, models missing from deletion flow, soft vs hard delete correctness, retention enforcement, orphaned rows/media after account deletion
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/User/AccountDeletionService.php
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Services/Media/VideoVariantService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/DataExport/DataExportService.php
- app/Services/User/UserBootstrapService.php
- app/Models/Core/Feedback.php
- app/Models/Core/Gdpr/DataExportAudit.php
- app/Models/Moderation/CaseSignal.php
- app/Models/Core/Waitlist/WaitlistSignup.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Core/Site/SiteMedia.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260526210001_create_feedback_table.sql
- supabase/migrations/20260528000000_create_moderation_schema.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#GDPR-1** · P1 — Video R2 files permanently orphaned if cleanup job exhausts retries after account purge
    - **Where:** app/Services/User/AccountDeletionService.php:purgeVideoArtifacts(), app/Jobs/DeleteMediaArtifactsJob.php, app/Services/Media/VideoVariantService.php:deleteVariants()
    - **Affects:** Users with video uploads whose hard-delete falls on a degraded `redis_video` queue or transient R2 outage; video files remain in cloud storage indefinitely with no DB reference and no programmatic way to find them.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Capture each video's `$media->path` (the base directory) in a durable store — either the `audit.user_deletion_audit` metadata JSONB or a separate cleanup-ledger table — before `forceDelete()` runs. This gives an ops path to replay cleanup if the job fails permanently.
        - Add a periodic `gdpr:sweep-orphaned-video-artifacts` command that lists the `videos/` prefix on R2 and cross-references against `site.site_media` (with `withTrashed()`); flag or delete paths that have no DB reference older than 24 hours.
        - As a shorter-term fix, increase `$tries` on `DeleteMediaArtifactsJob` and switch to `$backoff = [60, 300, 900]` (matching `ExportUserDataJob`) to survive longer R2 hiccups before declaring permanent failure.
    - **Technical:** `purgeVideoArtifacts` dispatches `DeleteMediaArtifactsJob::dispatch($media->id, $media->path, $media->pool)` onto the `redis_video` connection. The job runs `VideoVariantService::deleteVariants()`, which correctly uses `$disk->allFiles($basePrefix)` (directory listing — not DB lookups) to find and delete all files under the video path, then calls `MediaVariant::where('media_id', $mediaId)->delete()`. The directory-listing approach means the DB cascade that `forceDelete()` triggers (User → sites → site_media → media_variants) does **not** prevent R2 cleanup when the job actually runs. The gap is what happens when it doesn't run: the job has only 3 retries with a fixed 30-second backoff. If the `redis_video` connection is down at purge time, dispatch may itself fail silently; if it dispatches but R2 is degraded, all three retries can exhaust in under 2 minutes. After exhaustion, `failed()` logs the path but takes no further action, and the only remaining record of that path is a row in the `failed_jobs` table — which is routinely pruned. GDPR Article 17 requires erasure of all personal media; an unrecoverable job leaves files in R2 with no database anchor and no subsequent mechanism to locate them.
    - **Plain English:** When a user deletes their account, their videos need to be removed from our cloud storage. We do this by sending a cleanup task to a worker process. The worker correctly knows how to find and delete all the video files — it lists everything in the video's storage folder. The problem is: if that worker fails three times in a row (say, the storage service is briefly unavailable), the task is abandoned. After that, the video files stay in our storage bucket with no record pointing to them. It's like shredding the address card for a storage unit after the removal truck was turned away three times — the boxes are still in the unit, but nobody knows where to find them to try again.
    - **Evidence:**
        ```php
        // AccountDeletionService::purgeVideoArtifacts
        private function purgeVideoArtifacts(SiteMedia $media): void
        {
            if (! $media->path) {
                return;
            }

            DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->pool);
        }
        ```
        ```php
        // VideoVariantService::deleteVariants — called by the job; relies on directory listing, not DB
        public function deleteVariants(string $mediaId, string $basePath): void
        {
            $disk = $this->disk();
            $basePrefix = $this->normalizeVideoCleanupBasePath($basePath);

            $files = [];
            try {
                $files = $disk->allFiles($basePrefix);
            } catch (\Throwable $e) {
                $listError = $e;
            }
            // ... deletes files, then:
            MediaVariant::where('media_id', $mediaId)->delete(); // no-op after cascade
        }
        ```
        ```php
        // DeleteMediaArtifactsJob::failed — no re-queue, no durable path record
        public function failed(Throwable $e): void
        {
            report($e);
            Log::error('DeleteMediaArtifactsJob: cleanup exhausted retries.', [
                'media_id' => $this->mediaId,
                'base_path' => $this->basePath,
                ...
            ]);
        }
        ```

---

## P2 — Should fix

- [ ] **#GDPR-5** · P2 — Moderation case signal PII not redacted when the reporter deletes their account
    - **Where:** supabase/migrations/20260528000000_create_moderation_schema.sql (moderation.case_signals), app/Services/User/AccountDeletionService.php:purge()
    - **Affects:** Users who have used the public "report" button; their `reporter_email`, `reason_details` (freetext, up to 4,000 chars), and `signal_data` JSONB persist after account deletion with only `reporter_user_id` nulled.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `purge()`, before `forceDelete()`, query `moderation.case_signals WHERE reporter_user_id = $professional->id` and null out `reporter_email`, `reason_details`, and the personally identifying portions of `signal_data`.
        - Retain `reason_code`, `signal_source`, `dedup_hash`, and `case_id` for Trust & Safety analytics continuity — the anonymised signal still informs case severity.
        - Get legal sign-off on whether GDPR Art. 17(3)(b) (legal obligation) justifies full retention of these rows; if so, document the legal basis in code comments. The safe pre-pilot default is to redact PII fields while retaining the anonymised record.
    - **Technical:** `moderation.case_signals` defines `CONSTRAINT case_signals_reporter_user_fk FOREIGN KEY (reporter_user_id) REFERENCES core.users(id) ON DELETE SET NULL`. When `User::forceDelete()` runs, the DB cascade nulls `reporter_user_id` but leaves `reporter_email VARCHAR(255)`, `reason_details TEXT`, and `signal_data JSONB` (which may contain the user's description of the reported content) fully intact. `AccountDeletionService::purge()` has no step that touches the `moderation` schema at all. The `moderation` schema was granted to `app_backend` in `20260530000000_grant_moderation_schema_to_app_backend.sql`, so a PHP-side DELETE or UPDATE from `purge()` would work without further infra changes.
    - **Plain English:** If a user taps "Report" on another user's profile and then later deletes their own account, the system correctly forgets which account filed the report (it blanks out the user ID link). But it keeps the reporter's email address and the written description of what they found objectionable. Erasing the name tag on a signed complaint letter but keeping the handwriting and the envelope with a return address still on it doesn't satisfy a right-to-be-forgotten request.
    - **Evidence:**
        ```sql
        CONSTRAINT case_signals_reporter_user_fk FOREIGN KEY (reporter_user_id)
            REFERENCES core.users(id) ON DELETE SET NULL
        ```
        `moderation.case_signals` also stores `reporter_email VARCHAR(255) NULL`, `reason_details TEXT NULL`, and `signal_data JSONB NOT NULL DEFAULT '{}'`; none are touched by `AccountDeletionService::purge()`.

- [ ] **#GDPR-3** · P2 — Waitlist signup entries not deleted when the associated account is purged
    - **Where:** app/Services/User/AccountDeletionService.php:purge(), supabase/migrations/20260526000000_baseline_standalone_user.sql (core.waitlist_signups)
    - **Affects:** Users who joined the waitlist before creating an account; their `name`, `email`, `email_lc`, `phone`, and consent metadata persist in `core.waitlist_signups` after purge because there is no FK linking the two tables.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `purge()`, resolve the user's original (pre-pseudonymisation) email from `audit.user_deletion_audit` using the same pattern already implemented in `DataExportPayloadBuilder::resolveLookupEmail()` — query `WHERE user_id = $professional->id AND event IN ('requested','admin_initiated') ORDER BY created_at LIMIT 1` for `professional_email_snapshot`.
        - Use that email to hard-delete the matching `core.waitlist_signups` row: `DB::connection('pgsql')->table('core.waitlist_signups')->where('email_lc', mb_strtolower(trim($originalEmail)))->delete()`.
        - Note: at purge time `primary_email` is already `deleted+{id}@partna.au` (pseudonymised at confirmation 30 days earlier); the audit snapshot is the only reliable source of the original email.
    - **Technical:** `core.waitlist_signups` has no `user_id` column and no FK back to `core.users`; rows are linked to a user only by `email_lc`. `AccountDeletionService::purge()` contains no step targeting this table. `DataExportPayloadBuilder` already implements the full lookup chain — `resolveLookupEmail()` recovers the pre-pseudonymisation email from `audit.user_deletion_audit`, and `streamWaitlistSignups()` queries by `email_lc` — demonstrating the pattern is well-understood in the codebase. The deletion path simply never adopted it. The `waitlist_signups_email_lc_unique` index makes the lookup a point read.
    - **Plain English:** Before signing up, people fill out a waitlist form with their name, phone, and email. This form is stored in a completely separate table with no connection back to the account they later create. When the account is deleted, that waiting-list form stays on file permanently. It's like erasing a customer's account but keeping the pre-order enquiry card they filled out in the shop window before they ever walked in — complete with their phone number.
    - **Evidence:**
        ```sql
        -- core.waitlist_signups has no FK to core.users
        CREATE TABLE IF NOT EXISTS core.waitlist_signups (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            name text NOT NULL,
            email text NOT NULL,
            email_lc text NOT NULL,
            phone text NOT NULL,
            ...
            CONSTRAINT waitlist_signups_pkey PRIMARY KEY (id)
            -- no FOREIGN KEY referencing core.users
        );
        ```
        `AccountDeletionService::purge()` has no step targeting `core.waitlist_signups`.

- [ ] **#GDPR-6** · P2 — Global `sidest_updates` email subscription not removed when account is purged
    - **Where:** app/Services/User/UserBootstrapService.php:ensureSidestUpdatesSubscription(), app/Services/User/AccountDeletionService.php:purge()
    - **Affects:** All users — the sidest_updates subscription is created for every account at bootstrap time; the user's real email persists in `notifications.email_subscriptions` after purge and marketing emails may continue to be dispatched to it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `purge()`, after resolving the original email from the audit snapshot (same pattern as GDPR-3), delete the global subscription: `EmailSubscription::query()->whereNull('user_id')->where('list_key', 'sidest_updates')->where('email_lc', $originalEmailLc)->delete()`.
        - Audit whether any other global (`user_id = null`) subscriptions may have been created for this email via other code paths; if so, include them in the same cleanup step.
    - **Technical:** `UserBootstrapService::ensureSidestUpdatesSubscription()` inserts an `EmailSubscription` with `user_id = null`, keyed only by `email_lc`. When the user is force-deleted, the FK `CONSTRAINT email_subscriptions_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE` only fires for rows where `user_id = $professional->id`. Rows with `user_id IS NULL` have no FK linkage and survive the cascade untouched. `DataExportPayloadBuilder::streamEmailSubscriptions()` correctly identifies and exports these rows under branch 2 of its OR clause ("Global rows — Bootstrap creates sidest_updates rows with no owner"), confirming the system knows about them; no equivalent step exists in the purge path. As with GDPR-3, `primary_email` is pseudonymised at purge time and the original email must be resolved from the deletion audit snapshot.
    - **Plain English:** When someone creates an account, we automatically subscribe them to Partna product news. This subscription is stored under their email address with no link back to the actual account. When the account is deleted, we clean up everything connected to it — but this floating subscription (with the real email address still on it) stays behind. If we ever send a product update, it would still land in the deleted user's inbox. Saying "you're forgotten" while still emailing them doesn't hold up.
    - **Evidence:**
        ```php
        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            ...
            $sub = new EmailSubscription([
                'user_id' => null,
                'list_key' => $listKey,
                'email' => $email,
                'email_lc' => $email,
                'full_name' => null,
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
            ]);

            $sub->markSubscribed(['source' => 'bootstrap']);
            $sub->save();
        }
        ```
        `AccountDeletionService::purge()` has no step that queries `notifications.email_subscriptions` for global rows matched by `email_lc`.

- [ ] **#GDPR-4** · P2 — Feedback submissions retain message content and reply email after account deletion
    - **Where:** supabase/migrations/20260526210001_create_feedback_table.sql, app/Models/Core/Feedback.php, app/Services/User/AccountDeletionService.php:purge()
    - **Affects:** Users who submitted in-app feedback; their `message` (up to 5,000 chars of freetext), `reply_email`, `page_url`, `user_agent`, and `ip_hash` survive indefinitely after purge with only `user_id` nulled.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Simplest fix: change the FK on `core.feedback(user_id)` to `ON DELETE CASCADE`. Feedback without an originating user has no support value and no business reason to retain.
        - If hard-deletion is undesirable (e.g., for product analytics on submission rates), add a purge step: `Feedback::where('user_id', $professional->id)->forceDelete()` (the model uses `SoftDeletes`; `forceDelete()` will wipe soft-deleted rows too).
    - **Technical:** The feedback migration sets `CONSTRAINT feedback_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE SET NULL`. `Feedback` uses `SoftDeletes`, so both live and soft-deleted rows are affected. After `User::forceDelete()`, only `user_id` is nulled; `message`, `reply_email`, `page_url`, `user_agent`, and `ip_hash` remain in the database forever. `AccountDeletionService::purge()` has no step targeting `core.feedback`. Because `app_backend` has full CRUD on `core` (baseline grant), no DB permission change is needed — only a cleanup step or FK modification.
    - **Plain English:** When a user sends feedback from inside the app — a bug report, a feature idea, a complaint — we store what they wrote along with the email address they put in the reply-to field. When their account is deleted, we correctly stop associating the feedback with their account, but we keep the written message and their contact email. If someone exercises their right to be forgotten, they should also get to take back the letters they wrote to us.
    - **Evidence:**
        ```sql
        CONSTRAINT feedback_user_fk FOREIGN KEY (user_id)
            REFERENCES core.users(id) ON DELETE SET NULL
        ```
        The columns `message TEXT NOT NULL`, `reply_email TEXT NULL`, `page_url TEXT NULL`, `user_agent TEXT NULL`, and `ip_hash TEXT NULL` are all retained; `AccountDeletionService::purge()` has no step targeting `core.feedback`.

- [ ] **#GDPR-2** · P2 — Data export ZIP files are not deleted when the account is purged
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php:handle(), app/Services/User/AccountDeletionService.php:purge()
    - **Affects:** Users who requested a data export at any point before account deletion; the ZIP containing their entire personal data footprint remains in R2 under `exports/{user_id}/{audit_id}.zip` after purge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `purge()`, add a step before `forceDelete()` that reads `audit.data_export_audit WHERE user_id = $professional->id AND file_path IS NOT NULL` and deletes each `file_path` from the media disk: `Storage::disk(config('partna.media_disk'))->delete($audit->file_path)`.
        - As a belt-and-suspenders measure, also do a prefix scan: `Storage::disk(...)->deleteDirectory("exports/{$professional->id}")` — this catches any file whose audit row was already nulled by a prior partial purge.
        - Do this before `forceDelete()` so the `user_id` FK on `data_export_audit` is still populated and queryable.
    - **Technical:** `ExportUserDataJob` writes export ZIPs to `exports/{$audit->user_id}/{$audit->id}.zip` on the configured media disk and records the path in `audit.data_export_audit.file_path`. The `DataExportAudit` FK is `ON DELETE SET NULL`, so after `User::forceDelete()`, `user_id` becomes null but `file_path` still holds the full R2 key. `AccountDeletionService::purge()` has no step that reads `file_path` or deletes from the `exports/` prefix. The generated signed URL expires in 7 days (config `partna.gdpr.signed_url_ttl_days`), so the user cannot access the file after it expires — but the file itself, containing every piece of personal data the system holds, remains in R2 indefinitely. This directly contradicts an Article 17 erasure request, which asked us to delete all data about that person.
    - **Plain English:** When a user asks to download all their data, we generate a zip file and put it in cloud storage for them to download. The download link expires after 7 days, so they can no longer access it. But the zip file itself — with their full name, email, customer list, services, enquiries, and everything else they ever put into Partna — is never deleted. When someone exercises their right to erasure, we should be deleting that copy too. Right now we're erasing their account but keeping a complete backup of it in our storage bucket.
    - **Evidence:**
        ```php
        $remotePath = "exports/{$audit->user_id}/{$audit->id}.zip";
        $disk->put($remotePath, $stream);
        ```
        `AccountDeletionService::purge()` contains no step reading `file_path` from `audit.data_export_audit` or deleting from the `exports/` prefix on the media disk.
