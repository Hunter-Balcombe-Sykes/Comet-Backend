# Privacy & Data-Rights Compliance Audit — 2026-06-13

**Branch:** development
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Console/Commands/PurgeSoftDeleted.php`
- `config/partna.php`
- `routes/console.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`
- `supabase/migrations/20260526210001_create_feedback_table.sql`
- `supabase/migrations/20260527010000_reorganize_schemas.sql`
- `supabase/migrations/20260527030000_rename_professional_to_user.sql`
- `supabase/migrations/20260528000000_create_moderation_schema.sql`
- `supabase/migrations/20260604000000_add_email_delivery_status_to_data_export_audit.sql`

## Adjudication Notes (dropped findings)

- **Rights-machinery PRIV-3 / Schema-pii PRIV-3 (analytics retention):** Dropped. `routes/console.php` schedules `partna:analytics:purge-raw-events` daily at 03:00 — enforcement job exists.
- **Schema-pii PRIV-2 (30-day soft-delete purge):** Dropped. `partna:purge-soft-deletes` is scheduled and `PurgeSoftDeleted::PURGE_HANDLED` covers all relevant models including `Feedback::class`.
- **Schema-pii PRIV-6 (feedback retains reply_email on deletion):** Dropped. `AccountDeletionService::purgeFeedbackRows()` (#P2-10) explicitly force-deletes feedback rows on account purge. Finding was false.
- **Schema-pii PRIV-1 (analytics CASCADE overrides 90-day retention):** Dropped. The 90-day config is a maximum retention window, not a minimum guarantee. Deleting a user's analytics on account hard-delete is correct privacy behaviour.
- **Collection-retention PRIV-6 (EnquirySpamBlocklist hashed emails):** Dropped. Entries are HMAC-SHA256 hashed with the app key (non-reversible without the key), auto-expire after 90 days, and the user ID key is the only linkage. Bounded, non-recoverable residual — acceptable.
- **Schema-pii PRIV-4 (handle aliases not cleared on deletion request):** Dropped. `site.professional_handle_aliases` has `ON DELETE CASCADE` to `core.users`; aliases are cascade-deleted on hard-delete. The 30-day grace retention is intentional (deletion can be cancelled).
- **Schema-pii PRIV-9 / Collection-retention PRIV-5 (user-agent verbatim):** Deduplicated — merged into PRIV-6.
- **Schema-pii PRIV-8 (audit tables retain PII permanently):** Partially absorbed. `audit.handle_change_log` is covered by PRIV-3. `audit.user_deletion_audit` and `audit.staff_audit_log` are append-only by design with legitimate long-term retention basis; no separate finding warranted beyond PRIV-3.
- **Collection-retention PRIV-1 + PRIV-4 (moderation evidence):** Merged into PRIV-4 — same root cause, two gaps (deletion + retention).

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#PRIV-1** · P1 — Export includes staff member's email in the professional's data package
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:610–617`
    - **Affects:** Any professional whose export was triggered by support staff with `send_to = 'staff'` — the staff member's personal work email appears verbatim in the professional's downloaded ZIP.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit column allowlist to `streamAudit()` that omits `recipient_email` when `send_to != 'professional'`, or always redact it to a masked constant like `[redacted]`.
        - Update the `csv_columns` hint in `stream()` (line 269) to match.
    - **Technical:** `streamAudit()` queries `audit.data_export_audit` via `DB::table()` with no column selection, returning all columns including `recipient_email`. When `triggered_by = 'staff'` and `send_to = 'staff'`, `recipient_email` holds the staff member's email address. `DB::table()` bypasses Eloquent `$hidden`, so there is no safety net at the model layer. Every other sensitive builder method (`streamEnquiries`, `streamFeedback`, `streamHandleChangeLog`) already carries an explicit `->select([...])` allowlist; `streamAudit` is the lone exception. The `triggered_by_staff_id` column is also returned (a UUID, low risk), but `recipient_email` is a direct identifier of a third party who did not consent to disclosure.
    - **Plain English:** When a support person asks for a copy of a professional's data — perhaps to investigate a problem — we currently include the support person's own email address inside that copy. If the professional downloads their data package, they can see it. The support person never agreed to have their email in a stranger's records. Every other section of the export carefully filters out similar fingerprints; this one was just missed.
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
        ```sql
        -- audit.data_export_audit — columns returned verbatim
        recipient_email text NOT NULL,   -- staff email when send_to = 'staff'
        send_to text,                    -- 'professional' | 'staff' | NULL
        triggered_by text NOT NULL,      -- 'self' | 'staff'
        ```

- [ ] **#PRIV-2** · P1 — GDPR export artifacts are never pruned for active accounts — 30-day retention declared but unenforced
    - **Where:** `config/partna.php:1105` (`gdpr.export_retention_days = 30`); `routes/console.php` (no corresponding prune-completed-exports schedule entry)
    - **Affects:** Every professional who has ever requested a data export — their ZIP files and audit rows accumulate in R2 and Postgres indefinitely unless they delete their account.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a scheduled Artisan command `gdpr:prune-completed-exports` that hard-deletes `audit.data_export_audit` rows with `status = 'completed'` and `created_at < now() - interval '30 days'`, and issues `DELETE` calls to R2 for each `file_path` before deleting the row.
        - Register it in `routes/console.php` with `dailyAt('03:50')`, `onOneServer()`, `withoutOverlapping(60)`, and `onFailure(...)` per the scheduler convention.
        - Log purged row count (not contents) so compliance is verifiable in Nightwatch.
    - **Technical:** `config('partna.gdpr.export_retention_days')` declares a 30-day retention window. `routes/console.php` schedules `gdpr:sweep-stale-exports` (daily at 03:35), but that command's stated purpose is "watchdog for ExportUserDataJob rows orphaned in PROCESSING by SIGKILL" — it only recovers stuck PROCESSING rows, not expired COMPLETED exports. `AccountDeletionService::purgeExportZips()` removes ZIPs on account hard-delete, but active accounts never hit that path. The signed-URL TTL (`gdpr.signed_url_ttl_days = 7`) expires the download link after 7 days, but that only prevents re-download; the file itself remains in R2 and the audit row remains in Postgres until a manual purge or account deletion. A config that declares a 30-day window with no scheduled enforcer is "config that lies" and would fail an APP 11.2 audit.
    - **Plain English:** The platform says it keeps export files for 30 days and then deletes them. The download link expires after 7 days, but the actual file stays in storage forever unless the person deletes their account. There's a scheduled janitor for stuck exports, but no janitor for old finished exports. It's like saying "your package is held for 30 days" but the warehouse never actually clears out the uncollected packages — they pile up indefinitely.
    - **Evidence:**
        ```php
        // config/partna.php
        'gdpr' => [
            'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
            'signed_url_ttl_days' => (int) env('GDPR_EXPORT_SIGNED_URL_TTL_DAYS', 7),
        ],
        ```
        ```php
        // routes/console.php — gdpr:sweep-stale-exports exists, but is scoped to PROCESSING orphans only
        // P2-14: daily watchdog for ExportUserDataJob rows orphaned in PROCESSING by SIGKILL.
        Schedule::command('gdpr:sweep-stale-exports')
            ->dailyAt('03:35')
            // ...
        // No gdpr:prune-completed-exports or equivalent appears in routes/console.php.
        ```

- [ ] **#PRIV-3** · P1 — Handle audit 7-year retention declared in config with no enforcement job scheduled
    - **Where:** `config/partna.php:38` (`handle.audit_retention_years = 7`); `routes/console.php` (no `handles:prune-audit-logs` or equivalent)
    - **Affects:** `audit.handle_change_log` rows, which contain `old_handle`, `new_handle`, `ip_address`, and `user_agent` — handle identifiers plus device fingerprints accumulated over every rename since the platform launched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a scheduled Artisan command `handles:prune-audit-logs` that hard-deletes `audit.handle_change_log` rows where `changed_at < now() - (7 years)`, using the config value.
        - Register it in `routes/console.php` at `dailyAt('03:55')`, `onOneServer()`, `withoutOverlapping(120)`, `runInBackground()`, and `onFailure(...)`.
        - Log the count of pruned rows (not their content) so compliance enforcement is verifiable.
    - **Technical:** `config('partna.handle.audit_retention_years')` declares a 7-year retention policy for handle rename history. `routes/console.php` schedules `handles:prune-expired-aliases` (which hard-deletes expired redirect alias rows from `core.user_handle_aliases`/`site.site_subdomain_aliases`) and `handles:notify-expiry` (expiry email notifications) — but neither command touches `audit.handle_change_log`. Without a scheduled pruner, the audit table accumulates indefinitely. The FK is `ON DELETE SET NULL`, so rows survive the user's own hard-delete, which means the table genuinely requires a time-based retention enforcer. Under Australian Privacy Principle 11.2, holding personal information beyond the declared retention window is itself a breach.
    - **Plain English:** The platform has written down in its config that it will keep a history of handle changes for 7 years and then delete it. But there's no actual scheduled process that does the deleting. It's like writing "shred after 7 years" on a filing cabinet but never hiring anyone to operate the shredder — the cabinet fills up forever, well past the deadline.
    - **Evidence:**
        ```php
        // config/partna.php
        'handle' => [
            // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
            'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ],
        ```
        ```php
        // routes/console.php — handles:prune-expired-aliases cleans up redirect alias rows only
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            // ...
        Schedule::command('handles:notify-expiry')
            ->dailyAt('09:00')
            // ...
        // No handles:prune-audit-logs or equivalent appears in routes/console.php.
        ```
        ```sql
        -- audit.handle_change_log — survives user hard-delete (ON DELETE SET NULL)
        old_handle  text,          -- former handle identifier
        ip_address  inet,          -- request IP at rename time
        user_agent  text,          -- browser fingerprint at rename time
        changed_at  timestamptz NOT NULL DEFAULT now()
        ```

- [ ] **#PRIV-4** · P1 — Reported users' PII baked into moderation evidence payloads survives account deletion and accumulates without a retention rule
    - **Where:** `app/Services/Moderation/EvidenceSnapshotService.php:53–69`; `app/Services/User/AccountDeletionService.php` (no evidence redaction step); `routes/console.php` (no moderation evidence purge)
    - **Affects:** Any professional whose sitepage has been reported — their `handle`, `display_name`, and `bio` are captured into a JSONB payload in `moderation.evidence` that is never erased when they delete their account, and never cleaned up when the underlying case is resolved.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Deletion gap:** Add a `purgeSubjectEvidencePii(User $professional)` step to `AccountDeletionService::purge()` that, for all `moderation.cases` rows where `reportable_owner_user_id = $professional->id`, updates the `payload` JSONB of linked `moderation.evidence` rows to strip `handle`, `display_name`, `bio`, and `site_subdomain` — replacing them with a `[redacted]` marker while preserving structural keys (case and investigation still need the evidence shape).
        - **Retention gap:** Add `moderation.evidence_retention_days` to `config/partna.php` (suggested: 1825 days / 5 years for resolved cases). Create a scheduled command `moderation:prune-resolved-evidence` that hard-deletes evidence rows for cases whose `resolved_at < now() - retention_days`. Register in `routes/console.php` with weekly cadence (evidence volumes are low; weekly is sufficient).
        - Note: `purgeCaseSignalPii($professional)` already handles the *reporter* side (erasing the user's own filed reports); this finding is the *subject* side.
    - **Technical:** `EvidenceSnapshotService::snapshotSite()` eagerly loads `$site->user` and writes `handle`, `display_name`, and `bio` into the JSONB `payload`. The FK chain is: `moderation.evidence.case_id` → `moderation.cases.id` (ON DELETE CASCADE), and `moderation.cases.reportable_owner_user_id` → `core.users(id)` (ON DELETE SET NULL). When the reported user is hard-deleted, `reportable_owner_user_id` is nullified but the case and its evidence remain. `AccountDeletionService::purgeCaseSignalPii()` redacts the user's *reporter* PII from `moderation.case_signals` but has no parallel step for their *subject* PII in `moderation.evidence.payload`. Neither `PurgeSoftDeleted::PURGE_HANDLED` nor any scheduled command in `routes/console.php` addresses this table. Under APP 11.2, once the moderation purpose is served (case resolved), retaining the subject's identifiers without a bounded retention window is unnecessary holding.
    - **Plain English:** When someone reports a professional's page, the platform takes a snapshot of the page — including the professional's name and bio — so staff can see what it looked like at the time of the report. If the professional later deletes their account, their name and bio stay in that snapshot forever. There's also no plan to ever clean up old snapshots for cases that have been closed for years. Under Australian privacy law, once a case is closed, the person's information in those records should be scheduled for deletion — not kept indefinitely.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService.php:53–69
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
        ```php
        // AccountDeletionService — subject-PII step is absent; only reporter PII is handled
        $this->purgeExportZips($professional);          // #P2-08
        $this->purgeWaitlistSignup($lookupEmail);       // #P2-09
        $this->purgeFeedbackRows($professional);        // #P2-10
        $this->purgeCaseSignalPii($professional);       // #P2-11: reporter PII only
        $this->purgeGlobalEmailSubscriptions($lookupEmail); // #P2-12
        // No purgeSubjectEvidencePii() step exists.
        ```

---

## P2 — Should fix

- [ ] **#PRIV-5** · P2 — Analytics referrer stored with full query string — UTM-embedded emails land in the analytics warehouse
    - **Where:** `app/Services/Analytics/Writers/PostgresEventWriter.php` (`visitRow`, `clickRow`, `appendSectionRow`); `app/Http/Middleware/Logging/LogLeadRateLimits.php:81`
    - **Affects:** Every visitor to every public sitepage whose referrer URL contains query parameters — the full URL including any `utm_content=user%40example.com` or similar marketing-tool artefacts is stored verbatim in `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`, and `analytics.lead_submissions`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the `sanitizeReferrer()` logic already in `LogLeadRateLimits` (origin + path only, capped at 512 chars, query + fragment stripped) upstream in `PostgresEventWriter` before writing the referrer field to any analytics table.
        - Either move `sanitizeReferrer()` to a shared utility (e.g. `App\Services\Analytics\AnalyticsEventSanitizer`) and call it from both `PostgresEventWriter` and `LogLeadRateLimits`, or duplicate the one-liner — both are fine at this scale.
    - **Technical:** The existing comment in `LogLeadRateLimits::sanitizeReferrer()` states: "Query strings from marketing tools routinely embed subscriber emails / UTM PII — keeping only origin + path retains forensic value without the GDPR retention burden." That exact rationale applies equally to the main analytics pipeline (`PostgresEventWriter`), which handles orders of magnitude more events and writes `$e->referrer` verbatim with no sanitisation step. The fix is already written; it just isn't called in the right place. Raw query strings are retained for 90 days per `analytics_raw_event_retention_days`, so the exposure window is bounded — but it's over-collection during that window.
    - **Plain English:** The platform already knows that marketing links can embed email addresses in their URLs, and it strips those out before saving lead-submission records. But the main analytics tracking — which records every page visit and every link click — skips that same cleanup. An ad link like `?utm_content=subscriber%40example.com` gets saved verbatim to the database for up to 90 days. The fix already exists; it just needs to be applied to one more place.
    - **Evidence:**
        ```php
        // PostgresEventWriter — referrer stored verbatim
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
        // LogLeadRateLimits — the same concern, already handled correctly
        // "Query strings from marketing tools routinely embed subscriber emails /
        // UTM PII — keeping only origin + path retains forensic value without
        // the GDPR retention burden."
        private function sanitizeReferrer(?string $referer): ?string
        {
            // … parse_url, strip query + fragment, cap at 512 chars
        }
        ```

- [ ] **#PRIV-6** · P2 — User-agent strings stored verbatim across all analytics tables — unnecessary device fingerprint
    - **Where:** `app/Services/Analytics/Writers/PostgresEventWriter.php` (all row builders); `app/Http/Middleware/Logging/LogLeadRateLimits.php:81` (`LeadSubmission` creation)
    - **Affects:** Every visitor to every public sitepage — their full User-Agent string (commonly 400–700 chars, encoding exact OS version, browser build, rendering engine, and device model) is stored verbatim in `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`, and `analytics.lead_submissions`. Combined with `ip_hash` and `visitor_id`, this forms a strong cross-site re-identification fingerprint retained for 90 days.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate `user_agent` to 256 characters (most browser family + version fits in 120; 256 covers all known legitimate UA strings without the long appended comment blocks) before writing to any analytics table.
        - Apply the same truncation in `LogLeadRateLimits` for `analytics.lead_submissions`.
        - Alternative (stronger minimisation): derive a `browser_family` + `os_family` string (e.g. "Chrome/125 macOS") using a lightweight UA parser and store that instead of the raw string. The `device_type` column is already derived and stored — this would be parallel.
    - **Technical:** `AnalyticsEvent` carries `userAgent` as a nullable string with no length constraint. `PostgresEventWriter` writes it verbatim into every analytics row — the `user_agent varchar(500)` column (per the enquiries schema) is a 500-char ceiling, but analytics tables have no column-level constraint. `device_type` is already derived from the UA and stored separately, meaning the raw UA provides zero additional dashboard utility. Under APP 3.4, personal information collection must be limited to what is "reasonably necessary for the entity's functions." Retaining a 600-char browser fingerprint when `device_type` already serves the analytics use case fails that test.
    - **Plain English:** Every time someone visits a professional's page, the full description of their browser, operating system, and device model is saved — a long technical string that, combined with other data already stored, can uniquely pick out that visitor across many sites. The platform already turns this string into a simple "desktop or mobile" label for the dashboard. Keeping the full detailed version beyond that is like keeping a photocopy of someone's passport when all you needed was their age — more than is necessary under Australian privacy law.
    - **Evidence:**
        ```php
        // PostgresEventWriter.php — user_agent stored verbatim in every analytics table
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
        // LogLeadRateLimits.php — lead submissions also verbatim
        LeadSubmission::query()->create([
            // …
            'user_agent' => $request->userAgent(),
            // …
        ]);
        ```

- [ ] **#PRIV-7** · P2 — Cross-tenant and unsubscribed email subscriptions never purged — subscriber PII retained indefinitely
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (`notifications.email_subscriptions` table, no `deleted_at` column, FK `ON DELETE CASCADE` only covers the list-owner, not the subscriber); `app/Services/User/AccountDeletionService.php` (`purgeGlobalEmailSubscriptions` handles `user_id IS NULL` rows but not cross-tenant rows)
    - **Affects:** Two populations: (1) anyone who subscribed to a professional's newsletter via their public site form and later deletes their Partna account — the row persists because its `user_id` points to the *professional* (list owner), not the subscriber; (2) anyone who unsubscribed from any list — their `email`, `full_name`, `consent_ip_hash`, and `consent_user_agent` remain in the table indefinitely since there is no time-based cleanup and the table has no `deleted_at` column.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - **Cross-tenant deletion gap:** In `AccountDeletionService`, add a `purgeCrossTenantSubscriptions(?string $lookupEmail)` step that deletes `notifications.email_subscriptions` rows where `email_lc = $lookupEmail AND user_id != $professional->id`. This mirrors the current `purgeGlobalEmailSubscriptions` but covers list-owner-linked rows.
        - **Unsubscribed accumulation:** Add a scheduled command `notifications:prune-unsubscribed-subscriptions` that deletes rows where `status = 'unsubscribed' AND unsubscribed_at < now() - interval '1 year'` (1 year provides a reasonable suppression-list buffer). Register it in `routes/console.php` weekly.
    - **Technical:** `DataExportPayloadBuilder::streamEmailSubscriptions()` correctly includes cross-tenant rows (it documents: "Cross-tenant rows (user_id != X AND email_lc = user's email). The user subscribed to ANOTHER professional's newsletter … must surface in their DSAR"). But the deletion path does not mirror this coverage. `AccountDeletionService::purgeGlobalEmailSubscriptions()` only deletes rows where `user_id IS NULL AND email_lc = $lookupEmail`; it skips rows where `user_id = another_professional_id AND email_lc = $lookupEmail`. The export and deletion paths are inconsistent. Additionally, `notifications.email_subscriptions` has no `deleted_at` and the FK is `ON DELETE CASCADE` on `professional_id` — so only list-owner-linked rows are cascade-deleted when a list owner is purged; subscriber-email-linked rows to another professional's list are never cleaned up.
    - **Plain English:** When a visitor subscribes to a professional's newsletter on their public page, their email and name end up in the database. If they later delete their Partna account, their subscription record stays behind — because it's technically filed under the professional whose list they joined, not their own account. Separately, people who have already unsubscribed from lists stay in the database forever, even though they've opted out. Both situations mean we're holding contact data for people who have signalled they don't want a relationship with the platform any more.
    - **Evidence:**
        ```sql
        -- notifications.email_subscriptions — no deleted_at column
        CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            professional_id uuid,         -- the list owner, not the subscriber
            list_key varchar(50) DEFAULT 'marketing' NOT NULL,
            email text NOT NULL,
            full_name text,
            status varchar(20) DEFAULT 'subscribed' NOT NULL,
            unsubscribed_at timestamptz,
            consent_ip_hash text,
            consent_user_agent text,
            -- … no deleted_at column
            CONSTRAINT email_subscriptions_professional_fk
                FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
        );
        ```
        ```php
        // AccountDeletionService — purgeGlobalEmailSubscriptions covers user_id IS NULL only
        $this->purgeGlobalEmailSubscriptions($lookupEmail);  // #P2-12: global (user_id IS NULL) subscriptions
        // No step for cross-tenant rows where user_id = another professional's id.
        ```

- [ ] **#PRIV-8** · P2 — Waitlist signups retain full PII indefinitely for non-converting applicants
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (`core.waitlist_signups`, no `deleted_at` column, no FK to `core.users`); `config/partna.php` (no waitlist retention config); `routes/console.php` (no waitlist cleanup schedule)
    - **Affects:** Every person who submitted a waitlist application but never converted to a live account — their name, email, phone number, industry, and applicant type remain in `core.waitlist_signups` indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `waitlist.retention_days` config value to `config/partna.php` (suggested: 730 days / 2 years — enough to cover a realistic pilot window).
        - Create a scheduled command `waitlist:prune-old-signups` that deletes rows where `last_submitted_at < now() - retention_days`. Register weekly in `routes/console.php`.
        - Consider adding a data-subject access path for waitlist-only applicants (today there is none — they are not Partna users and cannot log in to request export or deletion).
    - **Technical:** `core.waitlist_signups` is keyed on `email_lc` with no FK to `core.users` — the join is email-based only. `AccountDeletionService::purgeWaitlistSignup()` handles the case where a converted user deletes their account, but applicants who never converted have no deletion path. The table has no `deleted_at` column, so `PurgeSoftDeleted` never reaches it. `DataExportPayloadBuilder::streamWaitlistSignups()` can export waitlist data for users who do have an account, but a waitlist-only applicant cannot trigger a DSAR at all through the current platform machinery. Without a time-based purge, contact details (name, email, phone) collected during the waitlist campaign are held forever, well past the point they serve the collection purpose (waitlist management).
    - **Plain English:** Anyone who signed up for the waitlist and was never accepted onto the platform has their name, email, and phone number sitting in the database indefinitely. There's no cleanup schedule, and because they're not full users, they have no way to log in and ask for their data to be deleted. Under Australian privacy law, once you no longer need someone's information for the purpose you collected it for, you're supposed to delete it. Waitlist contact data collected years ago, for a platform that has since launched, clearly no longer needs to be kept.
    - **Evidence:**
        ```sql
        -- core.waitlist_signups — no deleted_at, no user_id FK
        CREATE TABLE IF NOT EXISTS core.waitlist_signups (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            name text NOT NULL,
            email text NOT NULL,
            email_lc text NOT NULL,
            phone text NOT NULL,
            applicant_type text NOT NULL,
            industry text NOT NULL,
            -- … no deleted_at column, no foreign key to core.users
            CONSTRAINT waitlist_signups_pkey PRIMARY KEY (id)
        );
        ```
        ```php
        // config/partna.php — no waitlist retention value
        'waitlist' => [
            'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', ...),
            'types' => [...],
            'industries' => [...],
        ],
        // No waitlist.retention_days declared.
        ```
        ```php
        // routes/console.php — no waitlist:prune-old-signups or equivalent scheduled.
        ```
