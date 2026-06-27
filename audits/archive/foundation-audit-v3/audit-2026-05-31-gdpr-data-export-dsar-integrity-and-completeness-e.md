All evidence verified. `design_kits` has real columns (`color_accent`, `color_bg`, `color_text`, `typography_font_*`, etc.) and zero references exist in `DataExportPayloadBuilder`. I now have everything needed.

`★ Insight ─────────────────────────────────────`
**Key adjudication patterns applied here:**
1. **DSAR-3 description error** — DeepSeek says "no email is sent" but the source shows the email is sent *before* `markCompleted()`. The actual failure mode is an orphaned R2 object + misleading FAILED status when the export was already delivered. Evidence must be corrected or the finding is misleading.
2. **DSAR-4 intentional behavior** — The code itself documents at-least-once semantics with an explicit comment saying it's "preferable to silent loss." Findings where the author already made the trade-off consciously are P3 (polish), not P2 (ships bad behavior).
3. **DSAR-2 verification pattern** — `CaseSignal` uses `$guarded = ['id']` (no `$fillable`), so column names can't be read from the model. Grep on `reporter_email` in production services (`ContentReportService`, `NotifyReporterJob`, redact command) confirms the columns are real before keeping the finding.
`─────────────────────────────────────────────────`

# GDPR DSAR Integrity & Completeness Audit — 2026-05-31

**Branch:** development
**Lens:** GDPR data export DSAR integrity and completeness, every PII-bearing table represented, export audit trail, email-sent tracking, missing relations, export job idempotency
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/DataExport/DataExportZipWriter.php
- app/Services/User/DataExport/DataExportService.php
- app/Models/Core/Gdpr/DataExportAudit.php
- app/Models/Core/Feedback.php
- app/Models/Moderation/CaseSignal.php
- app/Models/Moderation/ModerationCase.php
- app/Mail/Gdpr/UserDataExportMail.php
- app/Http/Controllers/Api/User/Account/UserDataExportController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffDataExportController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **#DSAR-1** · P1 — Feedback submissions missing from DSAR export
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php — `stream()` method, no `feedback` section
    - **Affects:** Any professional who has submitted dashboard feedback and later requests a GDPR Article 15 data export. Their own message content (`message`), reply email (`reply_email`), submission metadata, and status are absent from the export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `streamFeedback(string $userId)` generator that queries `core.feedback` where `user_id = $userId`.
        - Select only the user-visible columns: `id`, `kind`, `severity`, `message`, `reply_email`, `page_url`, `status`, `created_at`, `updated_at`. Drop `ip_hash` and `user_agent` following the existing technical-fingerprint redaction pattern established by `streamEnquiries()`.
        - Yield the new section descriptor from `stream()` with `'csv_columns' => null`.
        - Add the same section key to `build()` for completeness of the non-streaming path.
    - **Technical:** `DataExportPayloadBuilder::stream()` yields 22 distinct sections. It covers the professional's profile, site, customers, enquiries, email subscriptions, notifications, UI preferences, notification preferences, auth events, and five audit tables. The `core.feedback` table stores authenticated dashboard submissions by `user_id`, carrying `reply_email` (the professional's own contact email for follow-up), `message` (their words verbatim), and `kind`/`severity`/`status` metadata. Under GDPR Article 15 these are unambiguously the data subject's own personal data and must be disclosed in a subject access response. The builder currently has no `streamFeedback()` method and no `feedback` section yield.
    - **Plain English:** When someone uses the "Send Feedback" button in the Partna dashboard — say to report a bug or suggest a feature — that message and the email they gave for replies is their personal data. If they later click "Download all my data," today's export includes their services, customers, notification settings, even their deletion history — but not their own feedback submissions. It's like handing over a complete personnel file with one chapter torn out. Adding the missing section is a single generator method and one new yield in the builder.
    - **Evidence:**
        ```php
        // app/Services/User/DataExport/DataExportPayloadBuilder.php
        // stream() final section — no feedback section exists anywhere in stream()
        yield [
            'name' => 'audit.deletion_audit',
            'kind' => 'rows',
            'rows' => $this->streamDeletionAudit($userId),
            'csv_columns' => null,
        ];
        // END of stream() — no feedback section follows

        // app/Models/Core/Feedback.php — the PII fields being omitted
        protected $fillable = [
            'user_id',
            'reply_email',  // user's own email for replies
            'kind',
            'severity',
            'message',      // user's own words
            'page_url',
            'user_agent',
            'viewport',
            'app_version',
            'request_id',
            'status',
            'internal_notes',
            'tags',
            'source',
            'ip_hash',
        ];
        ```

- [ ] **#DSAR-2** · P1 — Content-report submissions by the professional missing from DSAR export
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php — `stream()` method, no moderation/content-report section
    - **Affects:** Any professional who submitted a content report via the public report form (authenticated or with an email address provided) and later requests a GDPR Article 15 data export. Their `reporter_email`, hashed IP, reason code, and submission timestamps are absent from the export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `streamContentReports(string $userId, ?string $lookupEmail)` generator. Query `moderation.case_signals` for rows where `reporter_user_id = $userId` OR (where `reporter_user_id IS NULL AND reporter_email = $lookupEmail`), using the same `$lookupEmail` already resolved in `stream()` for email-lc lookups.
        - Select: `id`, `case_id`, `signal_source`, `reason_code`, `reason_details`, `reporter_email`, `created_at`. Drop `reporter_ip_hash` (technical fingerprint, follows the pattern from `streamEnquiries()`).
        - Add the section to `stream()` and `build()`.
    - **Technical:** `moderation.case_signals` stores the `reporter_email` (the submitter's contact email) and `reporter_ip_hash` on every content report created by `ContentReportService::submit()`. The `reporterUser()` relationship on `CaseSignal` confirms the `reporter_user_id` FK. A separate GDPR erasure command (`moderation:redact-reporter-pii`) already acknowledges these as PII fields and is the canonical Article 17 erasure path — the absence of an Article 15 disclosure path for the same data is inconsistent. When a professional is logged in and submits a report, `reporter_user_id` is set; for reports submitted by email without login, the lookup falls through to the `$lookupEmail` path already used for waitlist and email subscription cross-tenant lookups.
    - **Plain English:** If a professional clicks "Report this profile" on another user's page and enters their email in the form, that report is their personal data — they provided it and it's stored with their email address. If they later ask Partna to send them everything stored about them, those reports aren't in the export. It's like requesting your records from a bank and getting your loan applications but not the fraud alerts you filed yourself. Adding this section closes the gap using the same lookup pattern the builder already uses for email subscriptions.
    - **Evidence:**
        ```php
        // app/Services/Moderation/ContentReportService.php — writes the PII fields
        'case_id'          => $case->id,
        'signal_source'    => 'content_report',
        'signal_data'      => ['details' => $dto->details],
        'reporter_email'   => $dto->reporterEmail,   // PII — not in DSAR export
        'reporter_ip_hash' => $reporterIpHash,        // PII — not in DSAR export

        // app/Console/Commands/Moderation/ModerationRedactReporterPiiCommand.php
        // Erasure path exists for these fields — disclosure path does not
        CaseSignal::query()
            ->where('case_id', $case->id)
            ->update([
                'reporter_email'   => null,
                'reporter_ip_hash' => null,
            ]);

        // app/Models/Moderation/CaseSignal.php — reporter_user_id FK confirmed
        public function reporterUser(): BelongsTo
        {
            return $this->belongsTo(User::class, 'reporter_user_id');
        }

        // DataExportPayloadBuilder::stream() — no moderation section anywhere in the 22-section list
        ```

---

## P2 — Should fix

- [ ] **#DSAR-3** · P2 — Orphaned R2 export zip when post-upload DB write fails
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php — `handle()` method, upload-to-DB-write sequence
    - **Affects:** Any export where the R2 upload succeeds but a subsequent failure (email send error or `markCompleted()` DB write failure) causes the catch block to mark the job FAILED. The zip file remains in R2 indefinitely; the audit row blocks future exports via the early-return guard; `file_path` is never recorded on the audit row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `catch (Throwable $e)` block, check if `$remotePath` has been uploaded (track with a `$uploaded = false` flag set to `true` after `$disk->put()` succeeds) and delete the R2 object before calling `markFailed()`. This ensures the catch block is a clean rollback regardless of where the failure occurred.
        - Alternatively, record `file_path` on the audit row immediately after the upload (before email send) so at minimum the file is discoverable and a manual re-send is possible.
    - **Technical:** The job uploads the zip to R2, optionally sends the export email, then calls `markCompleted()`. If any step after the upload throws — including `Mail::send()` or the DB write inside `markCompleted()` — the catch block calls `markFailed()` and re-throws. The re-throw increments the retry counter. However, after the second failure the status is set to `STATUS_FAILED`, and the early-return guard on line 41 (`in_array($audit->status, [STATUS_COMPLETED, STATUS_FAILED], true)`) prevents any further retry. The R2 object at `exports/{userId}/{auditId}.zip` consumes storage indefinitely; no cleanup code references `$remotePath` in the catch or `finally` blocks.
    - **Plain English:** The export builder creates the zip and uploads it to cloud storage. Then it tries to record the upload details and send the email. If anything goes wrong at those steps, the system marks the export as "failed" and stops. But the zip is already sitting in cloud storage — like a package left on a loading dock after the paperwork got lost. Nobody knows it's there, it can never be found again through normal channels, and it sits there accumulating storage costs indefinitely. A one-line cleanup in the error handler would delete the file whenever the later steps fail.
    - **Evidence:**
        ```php
        // app/Jobs/Gdpr/ExportUserDataJob.php — the gap: upload committed before any error handler
        $stream = fopen($written['path'], 'rb');
        $disk->put($remotePath, $stream);   // R2 upload committed
        if (is_resource($stream)) {
            fclose($stream);
        }
        // ...
        if ($shouldSendEmail) {
            Mail::to($audit->recipient_email)->send(new UserDataExportMail(...));
            $audit->markEmailSent();
        }

        $audit->markCompleted(               // if this throws...
            filePath: $remotePath,
            fileSizeBytes: $written['size'],
            fileSha256: $written['sha256'],
            recordCounts: $written['record_counts'],
        );

        // catch block — no R2 cleanup:
        } catch (Throwable $e) {
            $audit->markFailed($e->getMessage());
            // ...
            throw $e;  // retry; but status is now FAILED
        }

        // Line 41 early-return guard — FAILED status prevents any retry:
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }
        ```

- [ ] **#DSAR-4** · P2 — Stale PROCESSING status if Horizon worker is killed mid-export
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php:41–43 — early-return guard excludes PROCESSING; app/Jobs/Gdpr/ExportUserDataJob.php:103–111 — `failed()` hook not called on SIGKILL
    - **Affects:** Any export where the worker process is forcibly terminated (SIGKILL, OOM killer, host failure) after `markProcessing()` but before completion. The audit row stays in PROCESSING indefinitely. Within the 30-minute dedup window, new export requests get a 409 "already in progress." After the window expires the dedup check passes, but the orphaned PROCESSING row remains in the audit table permanently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a scheduled command (daily via `Schedule::command(...)`) that queries `audit.data_export_audit` for rows with `status = 'processing'` AND `created_at < now() - interval '1 hour'` and sets them to `status = 'failed'` with `error_message = 'stale processing — worker death assumed'`.
        - The 1-hour threshold safely exceeds the job's 600s timeout + the 660s supervisor cap, so a legitimately running job is never incorrectly marked failed.
    - **Technical:** Laravel's `failed()` callback on the job class is invoked by the queue worker after all retries are exhausted. It is NOT called when the worker process itself is killed with SIGKILL (OOM killer, `docker kill`, kernel OOM). In that scenario, `markProcessing()` has already written `STATUS_PROCESSING` to the audit row but the job will never complete or call `failed()`. The early-return guard checks only `STATUS_COMPLETED` and `STATUS_FAILED` — `STATUS_PROCESSING` does not trigger early return, so if a new job is dispatched for the same user after the dedup window, it could reach `markProcessing()` and attempt to run against the same audit ID as the orphaned row. A scheduled cleanup command (already the established pattern for `handles:prune-expired-aliases`) is the minimal, non-invasive fix.
    - **Plain English:** Imagine a courier marks a package "in transit" then their van breaks down — completely. The clipboard says "in transit" forever. The next person who asks about that package is told "it's already being handled — please wait." A daily check that says "anything that's been 'in transit' for more than an hour is probably lost — mark it failed so we can try again" is how you recover from this. Same idea here: a simple scheduled sweep finds these zombie export jobs and resets them so the professional can re-request.
    - **Evidence:**
        ```php
        // app/Jobs/Gdpr/ExportUserDataJob.php
        // Line 41 — PROCESSING is NOT in the early-return list
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // ...
        $audit->markProcessing();  // status = 'processing' — survives SIGKILL forever

        // Line 103 — failed() only runs after Laravel exhausts retries, NOT on SIGKILL
        public function failed(Throwable $e): void
        {
            $audit = DataExportAudit::find($this->auditId);
            if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
                $audit->markFailed('Job failed after retries: '.$e->getMessage());
            }
        }
        ```

---

## P3 — Nice to have

- [ ] **#DSAR-5** · P3 — At-least-once email send on process crash between `Mail::send` and `markEmailSent`
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php — `if ($shouldSendEmail)` block
    - **Affects:** Any export where the process is killed (OOM, SIGKILL, restart) in the gap between `Mail::send()` returning and `$audit->markEmailSent()` completing. The user receives two identical download-link emails on the subsequent retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The existing code already documents this trade-off explicitly with the comment "At-least-once: a crash between send and stamp causes a retry to re-send — preferable to silent loss." No correctness change is required.
        - Add a short comment at the call site noting the two-email window so future maintainers understand the design intent without tracing the full lock path.
        - Optional hardening: set a Redis key (e.g. `gdpr:email-sent:{auditId}`) before calling `Mail::send()` and check it on retry to suppress the duplicate — this closes the window without a DB round-trip.
    - **Technical:** The idempotency gate (`shouldSendEmail`) is a `lockForUpdate` transaction that checks `email_sent_at IS NULL`. `Mail::send()` runs outside this transaction. If the process dies between `Mail::send()` completing and `saveQuietly()` inside `markEmailSent()`, `email_sent_at` remains null. On retry (if the job restarts, not blocked by FAILED status), `shouldSendEmail` returns true again and a second email is dispatched. The code comment explicitly acknowledges this as "preferable to silent loss for GDPR right-of-access requests" — the at-least-once design is intentional and correct for this use case.
    - **Plain English:** When Partna sends the "your export is ready" email, it writes "sent at 2:15pm" to the database right after. If the server restarts in that split-second gap, the email goes out but the timestamp never gets saved. On retry, Partna sees no timestamp and sends the email again. The user gets two identical download links — annoying but not harmful. The existing code already has a comment acknowledging this, and it's deliberately designed this way (better two emails than none). The optional Redis key is a clean way to close the gap without changing the database structure.
    - **Evidence:**
        ```php
        // app/Jobs/Gdpr/ExportUserDataJob.php
        // At-least-once: a crash between send and stamp causes
        // a retry to re-send — preferable to silent loss for GDPR right-of-access requests.
        $shouldSendEmail = DB::transaction(function () use ($audit): bool {
            $fresh = DataExportAudit::query()->lockForUpdate()->find($audit->id);
            return $fresh !== null && $fresh->email_sent_at === null;
        });

        if ($shouldSendEmail) {
            Mail::to($audit->recipient_email)->send(...);  // crash window begins here
            $audit->markEmailSent();                        // crash window ends here
        }
        ```

- [ ] **#DSAR-6** · P3 — No email delivery or bounce tracking for export notification
    - **Where:** app/Mail/Gdpr/UserDataExportMail.php; app/Models/Core/Gdpr/DataExportAudit.php — `markEmailSent()` method
    - **Affects:** Support staff investigating a "I never received my export" ticket. They can confirm `email_sent_at` is populated, but have no evidence of whether the email actually landed or bounced.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an `email_delivery_status` nullable column (migration: `TEXT NULL CHECK (email_delivery_status IN ('sent','delivered','bounced','complaint'))`) to `audit.data_export_audit`, defaulting to `'sent'` when `markEmailSent()` is called.
        - If Resend (the transactional mail provider used by `BaseTransactionalMail`) supports delivery/bounce webhooks, add a webhook handler that updates the column for the matching audit row by email/timestamp correlation.
        - This column exists as a hook for future webhook plumbing even before the handler is wired.
    - **Technical:** `DataExportAudit::markEmailSent()` calls `forceFill(['email_sent_at' => now()])->saveQuietly()`. There is no downstream delivery confirmation pipeline. `UserDataExportMail` extends `BaseTransactionalMail` which routes through Resend — a provider that supports delivery event webhooks — but no Resend webhook listener is wired for the export audit path. When a user files a support ticket claiming they never received the link, support can confirm `email_sent_at` is set but cannot distinguish "Resend accepted it and delivered" from "Resend accepted it and it bounced to a spam folder."
    - **Plain English:** When Partna sends the download link, it records "email handed to the mail provider at 2:15pm." That's like a post office writing "package dropped off at 2:15pm" — it doesn't mean it arrived. If a user emails support saying they never got their download link, today Partna can only say "we sent it." With a delivery receipt from the mail provider, support could say "it was delivered to your inbox at 2:17pm" or "it bounced — here's your download link directly." Adding the column now (even before wiring up the webhook) means the infrastructure is ready.
    - **Evidence:**
        ```php
        // app/Models/Core/Gdpr/DataExportAudit.php
        protected $fillable = [
            // ...
            'email_sent_at',  // only handoff timestamp — no delivery confirmation column exists
        ];

        public function markEmailSent(): void
        {
            $this->forceFill(['email_sent_at' => now()])->saveQuietly();
            // No email_delivery_status or downstream webhook correlation
        }
        ```

- [ ] **#DSAR-7** · P3 — Waitlist entry has no CSV companion in the export zip
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:142–147 — waitlist section yield
    - **Affects:** Professionals who signed up via the waitlist and request a machine-readable export. Their signup data is in `data.json` but no `waitlist.csv` is generated, unlike customers and enquiries which have CSV companions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `csv_columns` on the waitlist section descriptor to match the columns already selected in `streamWaitlistSignups()`: `['id', 'name', 'email', 'phone', 'applicant_type', 'applicant_type_other', 'industry', 'industry_other', 'pilot_program_opt_in', 'number_of_team_members', 'consent_source', 'last_submitted_at', 'created_at', 'updated_at']`.
        - `DataExportZipWriter::streamRowsArray()` already handles CSV emission automatically when `csv_columns` is non-null — this is a one-line change in the builder.
    - **Technical:** `DataExportZipWriter::streamRowsArray()` checks for a non-null `csv_columns` array and writes a companion CSV file via `writeCsvRow()`. The customers and enquiries sections use this. The waitlist section explicitly sets `'csv_columns' => null`, opting out of CSV output despite the `streamWaitlistSignups()` method already selecting a well-defined column set. GDPR Article 20 (right to data portability) encourages machine-readable formats. Adding CSV columns for waitlist requires no new infrastructure.
    - **Plain English:** The export zip includes a `data.json` with everything, plus convenience spreadsheets (CSV files) for customers and enquiries so the user can open them in Excel. The waitlist signup entry — the user's own pre-launch registration — is only available in the JSON blob. The machinery to generate a CSV already exists, and the data is already being fetched. It just needs a one-word config change to switch from "no CSV" to "yes CSV with these columns."
    - **Evidence:**
        ```php
        // app/Services/User/DataExport/DataExportPayloadBuilder.php
        // Waitlist — explicitly opted out of CSV
        yield [
            'name' => 'waitlist',
            'kind' => 'rows',
            'rows' => $this->streamWaitlistSignups($lookupEmail),
            'csv_columns' => null,   // <-- opted out
        ];

        // Compare with customers — CSV companion enabled:
        yield [
            'name' => 'customers',
            'kind' => 'rows',
            'rows' => $this->streamCustomers($userId),
            'csv_columns' => ['id', 'email', 'phone', 'full_name', 'source', 'notes', 'created_at'],
        ];
        ```

- [ ] **#DSAR-8** · P3 — Design kit preferences not included in DSAR export
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php — `site()` method and `stream()` only fetch `site.sites` + `site.blocks`; `site.design_kits` is never queried
    - **Affects:** Professionals who have customised their site design (colors, typography, layout preferences) and request a GDPR Article 15 data export. Their stored design preferences — a user-generated personalisation record — are absent from the export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `streamDesignKit(string $siteId)` generator that fetches the row from `site.design_kits` where `site_id = $siteId` (the table has exactly one row per site via a PK = site_id).
        - Yield it from `stream()` nested under the `site` group: `['name' => 'site.design_kit', 'kind' => 'rows', ...]`.
        - Resolve the `site_id` from the site already loaded inside `stream()` before the section yields begin.
    - **Technical:** The skeleton-system cleanup introduced `site.design_kits` as the canonical per-user design variable store (one row per site, column-per-var, all nullable). Migrations added columns including `color_accent`, `color_bg`, `color_text`, `typography_font_heading`, `typography_font_body`, and more (13+ columns across migrations `20260527080000` through `20260530130000`). The `site()` method in `DataExportPayloadBuilder` only queries `site.sites` and `site.blocks` — it has no JOIN or secondary query for the design kit table. Design preferences are user-generated choices stored specifically about the identified professional and fall within the Article 15 disclosure obligation.
    - **Plain English:** When a professional customises their page — choosing brand colors, fonts, layout style — those choices are stored in the database as their preferences. If they request their data export, the design preferences aren't included, even though the rest of their site configuration is. It's like getting a furniture store's records of your purchase but not the custom color swatches you chose. Adding a new section is a small method addition following the exact same pattern as the 22 other sections already in the builder.
    - **Evidence:**
        ```php
        // app/Services/User/DataExport/DataExportPayloadBuilder.php
        // site() fetches only site.sites + site.blocks — no design_kits query
        private function site(string $userId): array
        {
            $site = DB::connection('pgsql')
                ->table('site.sites')
                ->where('user_id', $userId)
                ->first();
            // ...
            $blocks = $this->collect(
                $this->lazyRows(
                    DB::connection('pgsql')
                        ->table('site.blocks')
                        ->where('site_id', $site->id)
                        ->orderBy('sort_order')
                )
            );

            return [
                'site' => (array) $site,
                'blocks' => $blocks,
                // design_kits: not fetched
            ];
        }

        // supabase/migrations/20260527080000_design_kit_initial_vars.sql — confirmed real columns
        ALTER TABLE site.design_kits
          ADD COLUMN color_accent TEXT NULL,
          ADD COLUMN color_bg TEXT NULL,
          ADD COLUMN color_text TEXT NULL,
          ADD COLUMN typography_font_heading TEXT NULL,
          ADD COLUMN typography_font_body TEXT NULL;
        ```
