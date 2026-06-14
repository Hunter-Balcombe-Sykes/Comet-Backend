
<!-- ═══ LENS: privacy-compliance | CHUNK: rights-machinery ═══ -->

- [ ] **#PRIV-1** · P1 — Export of DataExportAudit rows leaks staff recipient email
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:streamAudit (no column selection; exports all columns from `audit.data_export_audit`)
    - **Affects:** Any professional who had a staff-triggered export (`send_to = staff`) – the export ZIP’s audit history will expose the staff member’s email address to the professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit column selection in `streamAudit` that omits `recipient_email`, or conditionally include it only when `send_to` is not `'staff'`.
        - Alternatively, filter the column out in the stream before yielding the row.
    - **Technical:** `streamAudit` uses `DB::table` which ignores Eloquent’s `$hidden`; the `recipient_email` column (which may contain a staff email) is included verbatim in the export JSON. This is third-party PII disclosed to the data subject without legitimate reason.
    - **Plain English:** When a professional downloads a copy of all the data you hold about them, the file includes a log of previous export requests. If a support person once asked for a copy and had it sent to their own email, that support person’s email address shows up in the professional’s file. It’s like giving a customer a receipt that also tells them the bank teller’s personal email — information they don’t need.
    - **Evidence:**
        ```php
        private function streamAudit(string $userId): Generator
        {
            return $this->lazyRows(
                DB::connection('pgsql')
                    ->table('audit.data_export_audit')
                    ->where('user_id', $userId)
            );
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-2** · P1 — No scheduled enforcement found for GDPR export artifact retention (declared 30 days)
    - **Where:** config/partna.php (`gdpr.export_retention_days = 30`) and the reviewed source files (no scheduled cleanup of export ZIPs exists outside account deletion)
    - **Affects:** Export ZIPs and their audit rows accumulate indefinitely; a user’s old exports remain accessible long after the promised 30-day window.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a scheduled artisan command (e.g., `gdpr:prune-exports`) that deletes `audit.data_export_audit` rows (and their R2 ZIPs) where `created_at < now() - retention_days`.
        - Register the command to run daily in `app/Console/Kernel.php` or `routes/console.php`.
    - **Technical:** `AccountDeletionService::purgeExportZips()` removes ZIPs only when an account is hard-deleted, not for active accounts. The declared retention rule must be enforced by a periodic cleanup that applies to all users.
    - **Plain English:** The platform says it will delete export files after 30 days, but there’s no scheduled cleaner to actually do it. Unless a user deletes their whole account, that export will sit there forever — like promising to toss out old paperwork but never actually putting it in the bin.
    - **Evidence:**
        ```php
        // AccountDeletionService::purgeExportZips() – only invoked during account purge.
        private function purgeExportZips(User $professional): void { … }
        ```
        No other code in the reviewed files triggers export cleanup on a timer.
    - `[DRAFT, confidence: 0.8]`

- [ ] **#PRIV-3** · P1 — No scheduled enforcement found for analytics raw event retention (declared 90 days)
    - **Where:** config/partna.php (`analytics_raw_event_retention_days = 90`) and the reviewed files (no scheduled command to prune old analytics rows)
    - **Affects:** Analytics tables (site_visits, link_clicks, section_views, lead_submissions) retain visitor IP hashes and user agents forever, violating the stated 90-day limit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write a scheduled command (e.g., `analytics:prune-raw-events`) that deletes rows older than `analytics_raw_event_retention_days` from each analytics table.
        - Schedule daily.
    - **Technical:** The config declares a 90-day data retention for raw analytics, but no corresponding prune job appears among the provided files. Without enforcement, pseudonymous visitor data (IP hash, user agent) is stored indefinitely, which may breach Australian Privacy Principle 11 (data retention) and the GDPR storage limitation principle.
    - **Plain English:** You advertise that analytics data is kept for only 90 days, but right now there’s no process that actually removes older records. It’s like a gym that posts “lockers cleared nightly” but never checks inside — lockers fill up and nothing is ever thrown away.
    - **Evidence:** No reference to a scheduled analytics-purge command in the reviewed code.
    - `[DRAFT, confidence: 0.7]`

- [ ] **#PRIV-4** · P1 — No scheduled enforcement found for handle change audit retention (declared 7 years)
    - **Where:** config/partna.php (`audit_retention_years = 7`) and the reviewed files (no scheduled command to prune `audit.handle_change_log`)
    - **Affects:** The `audit.handle_change_log` table grows unbounded; old handle histories (some containing IP addresses and user agents) are never deleted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a scheduled command (e.g., `audit:prune-handle-logs`) that deletes rows where `changed_at < now() - 7 years`.
        - Register daily.
    - **Technical:** Handle change logs contain PII (IP address, user agent). The 7-year retention rule needs a corresponding purge job; otherwise the table accumulates PII well past its retention period, weakening the platform’s compliance posture.
    - **Plain English:** You’ve set a rule to keep handle-change history for seven years, but there’s nobody actually tidying up older records. Without that, the records pile up forever, like filing cabinets that never get emptied — eventually you’re holding personal information the policy says you shouldn’t have.
    - **Evidence:** No reference to a handle-change-log cleanup job in the reviewed files.
    - `[DRAFT, confidence: 0.7]`

<!-- ═══ LENS: privacy-compliance | CHUNK: collection-retention ═══ -->

- [ ] **#PRIV-1** · P1 — Moderation evidence snapshots embed reported-user PII with no account-deletion path
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php:80-96
    - **Affects:** Any professional whose sitepage is reported — their handle, display_name, and bio are captured into an immutable `moderation.evidence` JSONB row that survives their account deletion.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `moderation.evidence` to the account-deletion purge path — either hard-delete rows owned by the deleted user's `reportable_owner_user_id`, or redact the PII keys from the JSONB payload.
        - Document the retention rationale for evidence rows that belong to *other* users' reports (reporter-triggered retention).
    - **Technical:** `EvidenceSnapshotService::snapshotSite()` eagerly loads `$site->user` and writes `handle`, `display_name`, and `bio` into the evidence payload. The evidence table has no `deleted_at` column (no SoftDeletes) and does not appear in `PurgeSoftDeleted::PURGE_HANDLED` or any other retention sweep visible in `routes/console.php`. When a professional deletes their account, the moderation evidence rows that captured their PII remain — an erasure-rights gap. The content report was filed by a third party, but the *subject's* PII is platform-held data and must be accounted for.
    - **Plain English:** If someone reports a professional's page, we take a permanent photo of what the page looked like — including the professional's name, handle, and bio. If that professional later deletes their account, the photo stays in our filing cabinet forever. The professional has a right to ask for that cabinet to be cleaned out too, and we have no way to do it today.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService.php:80-96
        private function snapshotSite(string $siteId): array
        {
            $site = Site::query()->with(['user', 'blocks'])->findOrFail($siteId);

            return [
                'site_id' => $site->id,
                'site_subdomain' => $site->subdomain ?? null,
                'user_id' => $site->user_id,
                'handle' => $site->user?->handle ?? null,
                'display_name' => $site->user?->display_name ?? null,
                'bio' => $site->user?->bio ?? null,
                'block_count' => $site->blocks?->count() ?? 0,
                'block_types' => $site->blocks?->pluck('block_type')->all() ?? [],
            ];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-2** · P1 — Handle-audit retention (7 years) declared in config with no enforcement job scheduled
    - **Where:** config/partna.php (handle.audit_retention_years) + routes/console.php (no corresponding schedule entry)
    - **Affects:** `core.user_handle_aliases` and any handle-change audit log — rows accumulate unboundedly, contradicting the declared 7-year retention policy.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled command (e.g., `handles:prune-audit-logs`) that hard-deletes handle-audit rows older than 7 years.
        - Register it in `routes/console.php` with the standard `onOneServer()` / `withoutOverlapping()` / `runInBackground()` / `onFailure()` pattern.
        - Log the count of pruned rows (not contents) so compliance is verifiable.
    - **Technical:** `config('partna.handle.audit_retention_years')` declares a 7-year retention policy for handle-change audit logs. `routes/console.php` schedules `handles:prune-expired-aliases` (which cleans up expired redirect aliases) and `handles:notify-expiry` (which emails about upcoming alias expiry), but neither prunes audit-log rows that have passed the 7-year mark. A config value that declares a retention policy without a scheduled enforcer is "config that lies" — the data accumulates forever regardless of what the config says. Under Australian Privacy Act APP 11.2, an entity must take reasonable steps to destroy or de-identify personal information once it is no longer needed — an unbounded audit log with a declared-but-unenforced retention window fails that test.
    - **Plain English:** We tell our lawyers and our privacy policy that we keep handle-change records for 7 years and then delete them. But nobody actually takes out the trash — the records just pile up forever. If we ever need to prove we delete old data on schedule, we can't, because the scheduled cleanup job doesn't exist.
    - **Evidence:**
        ```php
        // config/partna.php
        'handle' => [
            // …
            // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
            'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ],
        ```
        ```php
        // routes/console.php — handles:prune-expired-aliases exists…
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            // …
        // …but no handles:prune-audit-logs or equivalent appears anywhere in the file.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#PRIV-3** · P2 — Analytics referrer stored with full query string — PII minimisation gap compared to lead-submission pipeline
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:140-155 (visitRow) + app/Services/Analytics/AnalyticsEvent.php:59 (referrer property)
    - **Affects:** Every visitor to every public sitepage — their Referer header (potentially containing UTM parameters with email addresses, search terms, or other PII) is stored verbatim in `analytics.site_visits`, `analytics.link_clicks`, and `analytics.section_views`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip query-string and fragment from the referrer before writing to analytics tables, matching the existing `LogLeadRateLimits::sanitizeReferrer()` implementation (origin + path only, capped at 512 chars).
        - Apply the sanitisation in `PostgresEventWriter::visitRow()` / `clickRow()` / the section-view row builder, or upstream in the controller that constructs the `AnalyticsEvent`.
    - **Technical:** The `LogLeadRateLimits` middleware already strips query strings from the Referer header before writing to `analytics.lead_submissions`, with an explicit comment that "Query strings from marketing tools routinely embed subscriber emails / UTM PII." But the main analytics pipeline — which handles every pageview, click, and section-view — stores the referrer field from `AnalyticsEvent` verbatim with no sanitisation. `PostgresEventWriter` writes `$e->referrer` directly into Postgres. An Instagram ad click with `?utm_source=instagram&utm_medium=paid&utm_campaign=launch&utm_content=user%40example.com` lands the visitor's email address in the analytics warehouse, retained for 90 days — a clear minimisation gap the platform already knows how to fix (the fix is implemented, just not applied here).
    - **Plain English:** When someone clicks a link to a Partna sitepage, we record where they came from — helpful for the professional's dashboard. But sometimes that "where" includes things like email addresses or search terms accidentally baked into the link by the sender. Our lead-submission pipeline already strips those out before saving. The main analytics pipeline — which handles every single page visit — forgets to do the same thing. It's like having a security camera that blurs faces at the front door but leaves the lobby camera on full resolution.
    - **Evidence:**
        ```php
        // PostgresEventWriter.php — referrer stored verbatim
        private function visitRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'referrer' => $e->referrer,
                // …
            ];
        }
        ```
        ```php
        // LogLeadRateLimits.php — the SAME concern, already handled correctly
        // "Query strings from marketing tools routinely embed subscriber emails /
        // UTM PII — keeping only origin + path retains forensic value without
        // the GDPR retention burden."
        private function sanitizeReferrer(?string $referer): ?string
        {
            // … parse_url, strip query + fragment, cap at 512 chars
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-4** · P2 — Moderation evidence table has no retention policy or scheduled purge — unbounded PII accumulation
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php (writes) + routes/console.php (no scheduled purge) + config/partna.php (no retention value)
    - **Affects:** Every professional whose sitepage is reported — their PII snapshot lives in `moderation.evidence` indefinitely, even after the case is resolved and the retention purpose has expired.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `moderation.evidence_retention_days` config value (e.g., matching the 7-year handle-audit baseline, or shorter if the legal basis is case-resolution only).
        - Add a scheduled command that hard-deletes evidence rows belonging to resolved cases older than the retention window.
        - Register it in `routes/console.php` and log purged counts.
    - **Technical:** The `EvidenceSnapshotService` creates rows in `moderation.evidence` containing JSONB payloads with `handle`, `display_name`, `bio`, and other PII. `ModerationCase` has a `resolved_at` timestamp that could anchor a retention clock, but there is no scheduled job in `routes/console.php` that prunes evidence for long-resolved cases. The `PurgeSoftDeleted` command does not list any moderation models. Without a retention rule and enforcer, evidence payloads accumulate forever — unnecessary retention is itself a Privacy Act APP 11.2 concern ("destroy or de-identify personal information once no longer needed").
    - **Plain English:** When someone reports a page, we take a snapshot of the evidence so staff can review it. After the case is closed, that snapshot has served its purpose. But we never throw it away — it sits in the database forever. Under Australian privacy law, holding onto personal data longer than you need to is its own kind of problem, separate from whether the data is secure. We need a retention policy and an automatic cleanup for old, resolved evidence.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService.php — PII written to evidence payload with no expiry
        return Evidence::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'signal_id' => $signalId,
            'evidence_type' => 'content_snapshot',
            'payload' => $payload,   // contains handle, display_name, bio
            'content_hash' => $contentHash,
        ]);
        ```
        ```php
        // PurgeSoftDeleted.php — no moderation model listed
        public const PURGE_HANDLED = [
            Customer::class,
            Service::class,
            SiteMedia::class,
            Enquiry::class,
            ServiceCategory::class,
            Block::class,
            Feedback::class,
            SmartLink::class,
            IntegrationConnection::class,
            // Moderation\Evidence is absent
        ];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#PRIV-5** · P2 — User-agent strings stored verbatim in every analytics table without minimisation
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php (visitRow, clickRow, appendSectionRow, upsertSession) + app/Http/Middleware/Logging/LogLeadRateLimits.php:81
    - **Affects:** Every visitor to every public sitepage — their full User-Agent string is stored in `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`, `analytics.site_sessions`, and `analytics.lead_submissions`. Combined with IP hash and visitor ID, this forms a strong browser fingerprint retained for 90 days.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate user-agent to a fixed length (e.g., 256 characters) or extract only the browser family + OS (e.g., "Chrome 125 / Windows") using a server-side parser.
        - Apply the same truncation/extraction consistently across all five analytics tables + the lead-submission path.
    - **Technical:** `AnalyticsEvent` carries `userAgent` as a nullable string with no length constraint. `PostgresEventWriter` writes it verbatim into every analytics row. User-agent strings routinely exceed 500 characters on modern browsers and, combined with IP hash + visitor ID, uniquely re-identify visitors across sessions. The analytics tables already have a 90-day retention window (enforced by `PurgeRawAnalyticsEvents`), so the data is not retained forever, but APP 3.4 requires that collection be limited to what is "reasonably necessary." A device-type enum (`desktop`/`mobile`/`other`) is already derived and stored in `device_type` — the raw user-agent is not needed for analytics and constitutes over-collection. The fix is low-effort: truncate at the ingest boundary.
    - **Plain English:** Every time someone visits a Partna sitepage, we save the full "fingerprint" of their browser — a long string that, combined with other data we collect, can uniquely identify them across different sites and sessions. We only actually use a simple "desktop or mobile" label for the dashboard charts. Keeping the full fingerprint is like taking a photocopy of someone's driver's license when all you needed was "over 18." Australian privacy law says only collect what you reasonably need.
    - **Evidence:**
        ```php
        // PostgresEventWriter.php — user_agent stored verbatim in every table
        private function visitRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'user_agent' => $e->userAgent,   // verbatim, no truncation
                // …
            ];
        }

        private function clickRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'user_agent' => $e->userAgent,   // same
                // …
            ];
        }
        ```
        ```php
        // LogLeadRateLimits.php — also verbatim
        LeadSubmission::query()->create([
            // …
            'user_agent' => $request->userAgent(),   // verbatim
            // …
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#PRIV-6** · P3 — `EnquirySpamBlocklist` hashed-email entries survive account deletion until natural TTL expiry
    - **Where:** app/Services/Notifications/EnquirySpamBlocklist.php:16-36
    - **Affects:** Professionals who delete their account — their per-user spam-blocklist Redis sorted set (keyed by `user_id`) persists for up to 90 days after the account is gone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a Redis `DEL` of the `enquiry_spam:{userId}` key to the account-deletion purge path.
        - Alternatively, document the 90-day auto-expiry as a deliberate grace period and accept the residual data.
    - **Technical:** `EnquirySpamBlocklist` stores HMAC-SHA256-hashed email addresses in a Redis sorted set keyed `enquiry_spam:{userId}` with a 90-day TTL. The entries are hashed with the app key, so the raw email is not recoverable from Redis alone, but the data is still user-associated (the key contains the `user_id`). When a professional deletes their account via `AccountDeletionService`, the `PurgeSoftDeleted` command handles DB rows and media but does not clean up Redis keys. The Redis entries auto-expire after 90 days, so this is a bounded leak rather than permanent retention. Low risk due to the HMAC hashing, but worth noting for completeness of the deletion ledger.
    - **Plain English:** When a professional blocks a spammer's email address, we store a scrambled version of that email in a list tied to their account. If they delete their account, that list hangs around in our cache for up to 90 days until it expires naturally. Because the emails are scrambled, nobody can read them — but they're still technically tied to a deleted account. This is a small loose end, not an emergency.
    - **Evidence:**
        ```php
        // EnquirySpamBlocklist.php
        private function key(string $userId): string
        {
            return "enquiry_spam:{$userId}";  // keyed by user_id, TTL 90 days
        }

        public function addWithExpiry(string $userId, string $email, int $expiresAt): void
        {
            $key = $this->key($userId);
            $member = $this->hash($email);    // HMAC-SHA256 with app key
            Redis::zadd($key, $expiresAt, $member);
            // …
            Redis::expire($key, self::TTL_DAYS * 86400);  // 90-day TTL
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ LENS: privacy-compliance | CHUNK: schema-pii ═══ -->

- [ ] **#PRIV-1** · P1 — Analytics raw-event retention is nullified by user hard-delete cascade
    - **Where:** `analytics.site_visits` FK, `analytics.link_clicks` FK, `analytics.section_views` FK, `analytics.site_sessions` FK (baseline migration §6)
    - **Affects:** Account holders who delete their account; all analytics rows younger than 90 days are erased immediately, violating the 90‑day retention commitment.
    - **Effort:** M (~2–4 h)
    - **What to do:**
        - Change the FK constraints on analytics event tables from `ON DELETE CASCADE` to `ON DELETE SET NULL` or `NO ACTION`.
        - Implement a scheduled purge job that deletes rows where `occurred_at < now() - interval '90 days'` independent of the user row.
    - **Technical:** The FK `FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE` means that when the user row is finally hard-deleted after the 30‑day soft-delete grace period, all linked analytics rows are deleted at once, regardless of their age. The config value `analytics_raw_event_retention_days` (90) cannot be honoured because the cascade overrides it. Retention must be driven by event time, not by user lifecycle.
    - **Plain English:** Imagine a shop that promises to keep your sales records for 90 days. If the shop closes, the landlord immediately destroys all records instead of waiting 90 days. Here, deleting a user’s account wipes their analytics history early — it breaks the promise to keep it for 90 days.
    - **Evidence:**
        ```sql
        -- analytics.site_visits FK definition from baseline migration
        CONSTRAINT site_visits_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE
        ```
        (identically present for `link_clicks`, `section_views`, `site_sessions`).
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-2** · P1 — No observable enforcement of the 30‑day soft-delete purge within the schema
    - **Where:** config value `soft_delete_retention_days` (30); no scheduled job or DB‑side cleanup function appears in the provided migration files.
    - **Affects:** Every soft-deleted row across the platform — user profiles, customer records, enquiries, services, blocks, media, etc. Data may persist forever if the purge job is missing.
    - **Effort:** S (~0.5–1 h) to create and register a scheduled Artisan command.
    - **What to do:**
        - Create a command (e.g., `privacy:purge-soft-deleted`) that, for every table with a `deleted_at` column, hard-deletes rows where `deleted_at < now() - interval '30 days'`.
        - Register it in `app/Console/Kernel.php` to run daily.
    - **Technical:** Laravel’s soft-delete only sets `deleted_at`; the rows must be physically removed by a separate scheduled task. The schema contains `deleted_at` on core.users, site.customers, site.enquiries, site.blocks, site.site_media, core.waitlist_signups (? no), etc., but no migration creates or references a scheduled purge. Without it, soft-deleted data accumulates indefinitely, contradicting the 30‑day retention.
    - **Plain English:** Think of a “shred after 30 days” box. People put papers in, but nobody ever empties the box. The system marks data as “to be deleted” but never actually deletes it unless someone builds the shredder.
    - **Evidence:** All soft‑deletable tables (`deleted_at` column) in the baseline migration; no corresponding `scheduled artisan` or pg_cron job in any migration file. (The expected registration point, `routes/console.php`, is not part of the provided scope.)
    - `[DRAFT, confidence: 0.8]`

- [ ] **#PRIV-3** · P1 — Analytics raw event retention (90 days) lacks a visible enforcement mechanism
    - **Where:** config `analytics_raw_event_retention_days` (90); analytics tables (`site_visits`, `link_clicks`, `lead_submissions`, `section_views`) show no scheduled purge in the migration files.
    - **Affects:** Analytics data may accumulate forever or require manual intervention, violating retention promises and data minimisation.
    - **Effort:** S (~0.5–1 h) to implement and schedule a purge job.
    - **What to do:**
        - Add a scheduled command that deletes rows from those tables where `occurred_at < now() - interval '90 days'` (or equivalent for `lead_submissions`).
        - Ensure the job runs daily and logs the count of deleted rows for auditability.
    - **Technical:** The retention config promises 90 days, but the migration files contain no trigger, function, or scheduled command that enforces it. Rows will pile up indefinitely, consuming storage and violating the data‑retention commitment. A time‑based purge is required.
    - **Plain English:** A warehouse that says “we only keep packages for 90 days” but never throws anything away. Boxes pile up forever. The platform says it will delete old visitor data after 90 days, but there’s no scheduled janitor to actually do it.
    - **Evidence:** No scheduled purge command or pg_cron entry in the migration files; analytics tables have no `deleted_at` column and no cleanup function.
    - `[DRAFT, confidence: 0.8]`

- [ ] **#PRIV-4** · P1 — Handle and subdomain alias tables are not cleaned up on account deletion
    - **Where:** `core.user_handle_aliases` and `site.site_subdomain_aliases` table definitions (baseline and supplementary migrations).
    - **Affects:** Deleted users — their old handle and subdomain remain reserved via the alias tables for up to 90 days, blocking reuse and linking the handle to a non‑existent account.
    - **Effort:** S (~0.5–1 h) to modify `AccountDeletionService`.
    - **What to do:**
        - In the account‑deletion flow, set `expires_at` to `now()` or directly delete alias rows for the deleted user, releasing the handle/subdomain immediately.
    - **Technical:** The alias tables are designed for handle‑rename grace periods (14‑day reclaim, 90‑day redirect). On account deletion, no mechanism truncates these periods. As a result, a deleted user’s handle remains locked and still redirects to their (now‑deleted) site, violating the right to erasure and creating unnecessary identifier retention.
    - **Plain English:** When someone closes their account, their old username should be freed up. Instead, a “reserved” sign stays on it for 90 days — like a parking spot held for a car that’s been scrapped.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.user_handle_aliases (
            user_id uuid NOT NULL,
            handle varchar(63) NOT NULL,
            …
            reclaim_until timestamptz,
            expires_at timestamptz,
            …
        );
        ```
        No deletion trigger or migration comment about clearance on account deletion.
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-5** · P2 — Waitlist signups retain personal data indefinitely with no retention policy
    - **Where:** `core.waitlist_signups` table — no `deleted_at`, no automated cleanup, no linkage to user accounts for erasure requests.
    - **Affects:** Individuals who signed up for the waitlist; their name, email, phone, industry, etc. are stored forever.
    - **Effort:** S (~0.5–1 h) to add retention and a scheduled purge.
    - **What to do:**
        - Define a retention period (e.g., 2 years) and implement a scheduled command that deletes rows older than that period.
        - Provide a data‑subject erasure interface for waitlist records.
    - **Technical:** The table collects direct PII (name, email, phone) at signup but has no soft‑delete or purge mechanism. This is an unbounded retention risk.
    - **Plain English:** A sign‑up sheet at a store that’s never thrown away, even years after the store opened. Names and emails sit there forever.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.waitlist_signups (
            id uuid …,
            name text NOT NULL,
            email text NOT NULL,
            phone text NOT NULL,
            …
        );
        -- No deleted_at column.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-6** · P2 — Feedback table retains reply email and IP hash after account deletion
    - **Where:** `core.feedback` table — columns `reply_email`, `ip_hash`; `user_id` with `ON DELETE SET NULL`.
    - **Affects:** Users who submitted feedback; after they delete their account, their email and IP hash remain in the database.
    - **Effort:** S (~0.5–1 h) to redact on deletion or apply a retention purge.
    - **What to do:**
        - In `AccountDeletionService`, set `reply_email` and `ip_hash` to `NULL` for feedback rows belonging to the deleted user.
        - Alternatively, add a scheduled command that deletes feedback rows older than a defined retention period.
    - **Technical:** The FK `ON DELETE SET NULL` only nullifies `user_id`, leaving the direct PII untouched. This conflicts with the right to erasure for a user’s own submitted data.
    - **Plain English:** You send a suggestion card with your email, then later delete your account. The card stays in the company’s box with your email still on it.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.feedback (
            user_id uuid NULL,
            reply_email text NULL,
            ip_hash text NULL,
            …
            CONSTRAINT feedback_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE SET NULL,
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-7** · P2 — Email subscriptions retain email and full name with no retention rule
    - **Where:** `notifications.email_subscriptions` — no automatic purge, `user_id` nullable.
    - **Affects:** Subscribers; their email and name remain indefinitely even after unsubscription or account deletion.
    - **Effort:** S (~0.5–1 h) to implement a retention purge.
    - **What to do:**
        - Add a scheduled job that deletes rows where `status = 'unsubscribed'` and the record is older than, say, 1 year.
        - On account deletion, cascade-soft-delete or clear `user_id` and email if no longer needed.
    - **Technical:** The table accumulates PII (email, full_name) without any time‑based cleanup. It has no `deleted_at` column, so even unsubscribed records persist indefinitely.
    - **Plain English:** An email list that never removes people who unsubscribed; their emails sit in the database taking up space forever.
    - **Evidence:** Table definition in baseline — no retention comment, no `deleted_at`.
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-8** · P2 — Audit tables retain email/handle snapshots permanently without documented retention limits
    - **Where:** `audit.user_deletion_audit` (professional_email_snapshot), `audit.data_export_audit` (professional_email_snapshot), `audit.handle_change_log` (old_handle), `audit.staff_audit_log` (professional_handle_snapshot).
    - **Affects:** Users’ historical handles and email snapshots survive indefinitely after account deletion, with no time‑based purge.
    - **Effort:** M (~2–4 h) to define per‑table retention policies and implement scheduled purging.
    - **What to do:**
        - Decide retention periods for each audit log (e.g., 7 years for handle‑change logs, shorter for export audit rows).
        - Add scheduled commands that delete rows older than the chosen period, accompanied by logging.
    - **Technical:** The `audit` schema is append‑only and designed to outlast the user, but no time‑bound cleanup exists. This creates indefinite PII retention. The config value `audit_retention_years` (7) appears intended only for handle‑change logs; other audit tables lack any retention declaration.
    - **Plain English:** The company keeps a permanent diary of every time someone changes their username — including the old one — and never throws those pages away. Even after the person leaves, their diary entry stays forever.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS audit.user_deletion_audit (
            professional_id uuid,
            professional_handle_snapshot text NOT NULL,
            professional_email_snapshot text NOT NULL,
            …
        );
        -- No retention comment.
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#PRIV-9** · P2 — Raw user‑agent strings stored without minimisation in multiple tables
    - **Where:** `site.enquiries.user_agent`, `analytics.site_visits.user_agent`, `analytics.link_clicks.user_agent`, `analytics.section_views.user_agent`, `core.feedback.user_agent`, `core.waitlist_signups.consent_user_agent`, etc.
    - **Affects:** Visitor privacy — user‑agent strings can fingerprint devices and browsers.
    - **Effort:** S (~0.5–1 h) to implement truncation or hashing at collection time.
    - **What to do:**
        - Store a simplified or derived form of the user agent (e.g., browser family + OS) instead of the full raw string.
        - Update analytics/enquiry collectors to apply this transformation before persistence.
    - **Technical:** Raw user‑agent strings are considered personal data under the APPs and GDPR because they can be combined with other attributes to identify individuals. The schema presently stores the full, un‑minimised string, which is more data than needed for analytics or spam prevention.
    - **Plain English:** Instead of noting “used a phone,” the system writes down every detail about the visitor’s device — like a detective’s notebook. That’s far more information than is necessary.
    - **Evidence:**
        ```sql
        -- example from site.enquiries
        user_agent varchar(500),
        ```
    - `[DRAFT, confidence: 0.9]`
