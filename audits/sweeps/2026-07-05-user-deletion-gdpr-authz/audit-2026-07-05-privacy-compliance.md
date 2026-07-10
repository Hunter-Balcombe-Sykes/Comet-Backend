# Privacy & Data-Rights Compliance Audit — 2026-07-05

**Branch:** development
**Lens:** Privacy & data-rights compliance — PII inventory, export/delete completeness, retention enforcement, processor flows (bundle: rights-machinery, collection-retention, schema-pii chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/AccountDeletionService.php
- app/Observers/User/UserObserver.php
- app/Models/Analytics/{SiteVisit,LinkClick,SectionView,LeadSubmission}.php
- app/Services/Analytics/AnalyticsEventSanitizer.php, Writers/PostgresEventWriter.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Notifications/EnquirySpamBlocklist.php
- app/Console/Commands/{PruneCompletedExportsCommand,PruneWaitlistSignupsCommand,PruneUnsubscribedSubscriptionsCommand,PurgeRawAnalyticsEvents,PruneNotifications,Moderation/ModerationShowCaseCommand,Moderation/ModerationRedactReporterPiiCommand}.php
- app/Models/Core/User/Customer.php, app/Models/Core/Site/Enquiry.php
- config/partna.php, routes/console.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql + 20260527030000/20260527050000/20260625000000/20260527160000

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 9 complete

---

## P1 — Fix before pilot launch

- [ ] **#PRIV-1** · P1 — Declared 7-year handle-audit retention has no scheduled enforcement job
    - **Where:** config/partna.php:55 (`handle.audit_retention_years`); routes/console.php (no matching `Schedule::` entry)
    - **Affects:** `core.user_handle_change_log` / `audit.handle_change_log` rows — every handle rename accumulates forever, contradicting the config's own stated retention.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `handles:prune-audit-log` command that hard-deletes `audit.handle_change_log` rows older than `config('partna.handle.audit_retention_years')` years.
        - Register it in `routes/console.php` on a weekly cadence, mirroring the `onOneServer()->withoutOverlapping()->onFailure()` convention every other scheduled command already follows.
        - Log the purged row count (not contents).
    - **Technical:** `config/partna.php` declares `'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7)` inside the `handle` array. Every other retention value in that file now has a corresponding scheduled command — `soft_delete_retention_days` → `partna:purge-soft-deletes`, `analytics_raw_event_retention_days` → `partna:analytics:purge-raw-events`, `gdpr.export_retention_days` → `gdpr:prune-completed-exports`, `waitlist.retention_days` → `waitlist:prune-old-signups`, `notifications.unsubscribed_retention_days` → `notifications:prune-unsubscribed-subscriptions`, `moderation.signal_pii_retention_days` → `moderation:prune-resolved-signal-pii` — confirmed present in `routes/console.php`. `audit_retention_years` is the one declared value with no matching command anywhere in `app/Console/Commands` or `routes/console.php`. This is exactly the "config that lies" pattern the other five retention values in this same file have already been fixed for.
    - **Plain English:** The system's settings file promises "we only keep handle-change history for 7 years" — but every other similar promise in that file has a worker that actually enforces it, and this is the one that was never wired up. It's the same filing-cabinet-with-no-cleaner problem the platform already solved five other times in this exact file; this is the one that got missed.
    - **Evidence:**
        ```php
        // config/partna.php
        'handle' => [
            'reclaim_days' => (int) env('SIDEST_HANDLE_RECLAIM_DAYS', 14),
            'redirect_days' => (int) env('SIDEST_HANDLE_REDIRECT_DAYS', 90),
            'subdomain_cooldown_days' => (int) env('SIDEST_HANDLE_SUBDOMAIN_COOLDOWN_DAYS', 30),
            'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ],
        ```

- [ ] **#PRIV-2** · P1 — Visitor analytics (SiteVisit, LinkClick, SectionView) absent from the GDPR export builder
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:123-169 (`sectionDescriptors()`)
    - **Affects:** Any professional who requests a data export — their site's pageview/click/section-view analytics (visitor activity they collected as data controller) never appears in the ZIP, even though `lead_submissions` (a structurally identical analytics table) already does.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add three entries to `sectionDescriptors()`: `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`.
        - Add one `streamX()` method per table, scoped by `user_id`, reusing the `lazyRows()` cursor pattern already used by `streamLeadSubmissions()`.
        - Drop `ip_hash` and `user_agent` from the selects, mirroring the redaction already applied in `streamEnquiries()` and `streamLeadSubmissions()`.
    - **Technical:** `sectionDescriptors()` is the single manifest both `build()` and `stream()` derive from (per its own docblock, closing FOUND-1). It already includes `lead_submissions` (line 154, wired to `streamLeadSubmissions()`), which is the correct precedent — visitor-analytics-as-part-of-the-professional's-own-DSAR. `SiteVisit`, `LinkClick`, and `SectionView` all carry a `user_id` FK (confirmed in their `$fillable`) but have no corresponding manifest entry or stream method. This is a real Article 15 / APP 12 gap for the three largest analytics tables, inconsistent with the precedent the same file already sets for `lead_submissions`.
    - **Plain English:** A professional requests their data export and receives their profile, customers, enquiries, and lead-form submissions — but gets nothing about who viewed their page or clicked their links, even though the platform already agreed (by including the near-identical "lead submissions" data) that this kind of visitor analytics belongs in the export. It's an inconsistency in what counts as "your data" that a regulator would notice.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder::sectionDescriptors() — lead_submissions IS wired in...
        ['name' => 'lead_submissions', 'kind' => 'rows', 'resolve' => fn () => $this->streamLeadSubmissions($userId)],
        ['name' => 'feedback', 'kind' => 'rows', 'resolve' => fn () => $this->streamFeedback($userId)],
        // ...but analytics.site_visits, analytics.link_clicks, analytics.section_views have no entry anywhere in this array.
        ```

- [ ] **#PRIV-3** · P1 — Platform integration connections (`site.platform_connections`) excluded from GDPR export via a stale stub
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:245-249 (`streamIntegrations()`)
    - **Affects:** Every professional who connected a platform (Instagram, YouTube, Spotify, etc.) — their stored connection payload (handle, profile identity, curated highlights) never appears in their data export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `yield from []` stub with a `lazyRows()` query on `site.platform_connections` scoped by `user_id`.
        - Select all columns except internal refresh-bookkeeping fields (`refresh_etag`, `refresh_last_modified`, `consecutive_failures`, `apify_status`).
        - Update the comment — it predates the FOUND-24 platform-connect convergence (commits `0a236d55`…`75f81794`, merged `ed5b62fd`) which made platform connections available to all account types, not just brand accounts.
    - **Technical:** `IntegrationConnection` (`app/Models/Core/Site/IntegrationConnection.php`) maps to `site.platform_connections` and has a `user_id` in `$fillable` — confirmed a first-class, user-scoped, actively-written table. `streamIntegrations()` still reads `// No integrations for individual-standalone accounts; yield nothing.` — a comment that was true before the FOUND-24 convergence but is now false. The table is populated for every connected platform and holds the user's external handles/profile identity, unambiguously in-scope for Article 15.
    - **Plain English:** A professional connects Instagram, YouTube, and Spotify to their page. All of that connection data lives in Partna's database, but a leftover comment from before this feature existed for individual accounts tells the export system to skip it entirely. If that professional requests their data, they get everything except the section describing the platforms they linked.
    - **Evidence:**
        ```php
        private function streamIntegrations(string $userId): Generator
        {
            // No integrations for individual-standalone accounts; yield nothing.
            yield from [];
        }
        ```

## P2 — Should fix

- [ ] **#PRIV-4** · P2 — `core.supabase_email_events` (hashed auth-email forensic trail) has no retention rule or purge job
    - **Where:** supabase/migrations/20260625000000_create_supabase_email_events.sql
    - **Affects:** Every user who triggers a Supabase auth email (signup, magic link, password reset). The hashed-email forensic row persists indefinitely — no `deleted_at`, no `user_id` FK, no scheduled command anywhere in `app/Console/Commands` references this table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a retention config key (e.g. `webhooks.supabase_email_event_retention_days`, default 90) and a scheduled command that hard-deletes rows older than the window — this is short-term webhook-debugging data, not a long-term audit requirement.
    - **Technical:** The table stores `recipient_email_hash` (SHA256 HMAC, peppered with `app.key`) and a token-stripped `raw_payload`, and correctly uses `FORCE ROW LEVEL SECURITY`. Its own comment states its purpose is "WHK-3: Forensic trail of Supabase auth-email webhook outcomes" — an operational-debugging table, not a compliance ledger. No column or scheduled job bounds its growth.
    - **Plain English:** Every time someone signs up or resets their password, the system files a scrambled record of "someone triggered this email" for debugging purposes. There's no rule for how long that filing cabinet gets kept, so it grows forever. It's already scrambled (hashed), but a 90-day debugging window is plenty — it doesn't need to live years.
    - **Evidence:**
        ```sql
        COMMENT ON TABLE core.supabase_email_events IS
            'WHK-3: Forensic trail of Supabase auth-email webhook outcomes. '
            'One row per unique webhook_id; status is queued/failed/unhandled. '
            'Email is hashed (SHA256 HMAC); raw_payload is token-stripped. '
            'WHK-4 (replay) is deferred — no token column here.';
        -- No deleted_at column. No FK to core.users. No retention rule declared.
        ```

- [ ] **#PRIV-5** · P2 — Audit snapshot columns (`user_deletion_audit`, `data_export_audit`) retain plaintext email indefinitely with no declared retention or minimisation
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:489-494, 519-526; app/Console/Commands/PruneCompletedExportsCommand.php:10-19
    - **Affects:** Every user who has ever been through account deletion or requested a data export — the email snapshot on those audit rows has no expiry, unlike the artifacts/aliases it documents.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Declare an explicit retention rule for `professional_email_snapshot` on `audit.data_export_audit` (e.g. null it out alongside `file_path`/`file_size_bytes`/`file_sha256` once `gdpr:prune-completed-exports` runs), or document why the email is kept as part of the permanent legal record.
        - For `audit.user_deletion_audit`, either declare and enforce a retention window (mirroring `handle.audit_retention_years`'s model) or explicitly document — the way the handle-audit exception is documented — that indefinite retention of the email snapshot is a deliberate compliance decision, not an oversight.
    - **Technical:** `PruneCompletedExportsCommand` already nulls the R2 artifact columns (`file_path`, `file_size_bytes`, `file_sha256`) after 30 days but explicitly keeps `professional_email_snapshot` forever — its own docblock says "Rows in audit.data_export_audit are KEPT (they are the legal record that an export happened)." That's a reasonable design choice for the fact-of-export, but the plaintext email specifically has no matching justification or retention bound, unlike `handle.audit_retention_years` which is an explicit, documented 7-year exception. `user_deletion_audit` similarly has no retention rule at all for its `professional_email_snapshot`/`professional_handle_snapshot` columns.
    - **Plain English:** When someone requests their data or deletes their account, the system correctly deletes the file itself after 30 days — but keeps a permanent sticky note with their email address forever, with no plan to ever remove it and no written reason for why. The handle-rename history has an explicit "we keep this for 7 years, on purpose, for fraud investigation" rule; these two records have no equivalent explicit rule — just silent forever-retention.
    - **Evidence:**
        ```sql
        -- core.professional_deletion_audit (renamed to audit.user_deletion_audit)
        professional_handle_snapshot text NOT NULL,
        professional_email_snapshot text NOT NULL,
        ```
        ```php
        // PruneCompletedExportsCommand — audit row (incl. email_snapshot) is KEPT forever
        * Rows in audit.data_export_audit are KEPT (they are the legal record that an
        * export happened). Only the R2 ZIP artifact and its DB columns are removed.
        ```

- [ ] **#PRIV-6** · P2 — `analytics.lead_submissions` FK is `ON DELETE SET NULL`, the lone outlier among sibling analytics tables that all `CASCADE`
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:1202 (`lead_submissions_professional_fk`, renamed `lead_submissions_user_fk` by 20260527050000)
    - **Affects:** Visitor `ip_hash`/`user_agent` on lead-submission rows — when a professional's account is deleted, these rows keep visitor identifiers with `user_id` nulled instead of being removed with the rest of the account's analytics.
    - **Effort:** S (~0.5–1h) — requires a schema migration
    - **What to do:**
        - Align `lead_submissions_user_fk` to `ON DELETE CASCADE`, matching `site_visits_user_fk`, `link_clicks_user_fk`, and `section_views_user_fk`.
    - **Technical:** `site_visits`, `link_clicks`, and `section_views` all use `ON DELETE CASCADE` on their user FK (confirmed in baseline + the `20260527050000_rename_professional_constraints_indexes.sql` rename), but `lead_submissions` alone uses `ON DELETE SET NULL`. In practice this is bounded, not unbounded: `PurgeRawAnalyticsEvents` (`partna:analytics:purge-raw-events`, scheduled daily) sweeps all four tables by `occurred_at` age regardless of `user_id`, so an orphaned lead-submission row is still purged within the declared 90-day analytics retention window — this is a consistency/hardening gap, not a permanent-retention gap.
    - **Plain English:** When an account is deleted, five out of six visitor-analytics tables get scrubbed clean immediately as part of that cascade. The sixth (lead-form submissions) instead just has the professional's ID blanked out, leaving the visitor's scrambled IP and browser fingerprint behind — that data does still get automatically cleaned up within about 90 days by a separate routine, but the inconsistency is worth fixing so all six tables behave the same way on deletion day.
    - **Evidence:**
        ```sql
        -- lead_submissions (the outlier):
        CONSTRAINT lead_submissions_professional_fk
            FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL
        -- Sibling tables in the same baseline:
        CONSTRAINT site_visits_professional_fk    FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
        CONSTRAINT link_clicks_professional_fk    FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
        CONSTRAINT section_views_professional_fk  FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
        ```

- [ ] **#PRIV-7** · P2 — `ModerationShowCaseCommand` dumps reporter PII to stdout via unfiltered `toArray()`
    - **Where:** app/Console/Commands/Moderation/ModerationShowCaseCommand.php:31-37
    - **Affects:** Reporters who submitted content reports — their `reporter_email`/`reporter_ip_hash` on unresolved or recently-resolved cases print to the terminal whenever a staff member runs this diagnostic command.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Redact `reporter_email` and `reporter_ip_hash` from the signals array before JSON-encoding, or route through a Resource class that excludes those fields.
    - **Technical:** The command calls `$case->signals->map->toArray()->values()` directly and prints the result as pretty JSON. `CaseSignal::toArray()` returns every column with no `$hidden` filtering applied. `moderation:prune-resolved-signal-pii` (the scheduled weekly job) only redacts reporter PII on cases resolved 90+ days ago, so any case still open or recently closed still has live `reporter_email` that this command will print in full.
    - **Plain English:** There's a support command staff can run to inspect a moderation case in detail. It prints the reporter's email address and IP fingerprint straight to the terminal, unredacted, for any case that hasn't yet passed its 90-day PII-cleanup window. On a server that logs terminal sessions, that's a PII leak into a log file with weaker protections than the database has.
    - **Evidence:**
        ```php
        $this->line(json_encode([
            'case_id' => $case->id,
            'case' => $case->toArray(),
            'signals' => $case->signals->map->toArray()->values(),
            'evidence' => $case->evidence->map->toArray()->values(),
            'decisions' => $case->decisions->map->toArray()->values(),
        ], JSON_PRETTY_PRINT));
        ```

- [ ] **#PRIV-8** · P2 — Staff audit log stores plaintext staff/impersonator email snapshots with no retention rule
    - **Where:** app/Services/Audit/StaffAuditService.php:30-45
    - **Affects:** Staff members — their work email addresses are written into every `audit.staff_audit_log` row on every staff write action, with no declared retention and no scheduled pruning.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either declare a retention window for `audit.staff_audit_log` and schedule a pruning command, or drop the plaintext `staff_email_snapshot`/`impersonator_email_snapshot` columns in favour of the existing `staff_id`/`impersonator_staff_id` FKs (the email is already reachable through the FK while the staff record exists).
    - **Technical:** `StaffAuditService::record()` writes `staff_email_snapshot` and `impersonator_email_snapshot` as plaintext on every staff write action (`RecordStaffAuditEntry` middleware). No `app/Console/Commands` file references `staff_audit_log`, and no retention key for it exists in `config/partna.php` — unlike the moderation and export audit trails, which now have declared (even if imperfect) retention postures.
    - **Plain English:** Every action a staff member takes on the platform writes their email address into a permanent log, with no plan to ever clean it up. Staff are people with privacy rights too, and an unbounded plaintext-email ledger is the same "config that lies" pattern flagged elsewhere in this audit — except here there isn't even a declared promise, just silent indefinite storage.
    - **Evidence:**
        ```php
        return StaffAuditEntry::query()->create([
            'staff_id' => $staff?->id,
            'staff_email_snapshot' => $staff?->primary_email,
            'impersonator_staff_id' => $impersonator?->id,
            'impersonator_email_snapshot' => $impersonator?->primary_email,
            'user_id' => $professional?->id,
            'professional_handle_snapshot' => $professional?->handle,
            ...
        ]);
        ```

- [ ] **#PRIV-9** · P2 — `EnquirySpamBlocklist` has no `remove()`/`clear()` method and is never invoked by account deletion
    - **Where:** app/Services/Notifications/EnquirySpamBlocklist.php:1-50
    - **Affects:** Visitors whose hashed email lands on a professional's spam blocklist — if that professional deletes their account, the Redis entry survives until its 90-day TTL lapses naturally; there's no way to clear it early.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `clearUser(string $userId)` method that deletes the Redis key for that professional.
        - Call it from `AccountDeletionService::purge()` alongside the other Redis/R2 cleanup steps already in that method.
    - **Technical:** The class only exposes `add()` and `contains()` against a Redis sorted set keyed `enquiry_spam:{userId}`. `AccountDeletionService::purge()` explicitly cleans up R2 export ZIPs, waitlist rows, feedback rows, case-signal PII, evidence PII, and email subscriptions — but never touches this Redis key. The data is peppered (HMAC-SHA256 with `app.key`) and self-expires within 90 days regardless, so the residual risk is bounded, but it's an inconsistency with every other Redis/R2 cleanup step `purge()` performs.
    - **Plain English:** When a visitor gets flagged as a spam sender on a professional's contact form, their scrambled email address goes into a short-term "blocked senders" list. If that professional deletes their account, everything else gets cleaned up immediately except this list, which just sits there for up to three months until it expires on its own.
    - **Evidence:**
        ```php
        public function add(string $userId, string $email): void { ... }
        public function contains(string $userId, string $email): bool { ... }
        // No remove() method. No clearUser() method.
        // AccountDeletionService::purge() does not reference EnquirySpamBlocklist.
        ```

- [ ] **#PRIV-10** · P2 — `country_code` and `timezone` are not pseudonymised during the 30-day deletion grace period
    - **Where:** app/Services/User/AccountDeletionService.php:257-272 (`pseudonymiseAccountPii`)
    - **Affects:** Users in the pending-deletion window — two residual identifiers remain on the live row while every other location field is zeroed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'country_code' => null` and `'timezone' => null` to the `forceFill` call.
    - **Technical:** `core.users` has `country_code text` and `timezone text` columns (confirmed in baseline + `User::$fillable`), and `pseudonymiseAccountPii()` zeros every other location field (`location_street_address`, `location_postcode`, `location_city`, `location_state`, `location_country`) but leaves these two untouched for the full 30-day grace period. The cancel path (`restoreEmailFromAuditSnapshot`) doesn't depend on them, so nulling is safe.
    - **Plain English:** When someone requests account deletion, the platform immediately wipes their street address and city — but leaves their country and timezone sitting there untouched for the entire 30-day waiting period. It's a small, low-risk gap, but it's an inconsistency a privacy reviewer would flag on sight.
    - **Evidence:**
        ```php
        $professional->forceFill([
            'phone' => 'redacted',
            'primary_email' => "deleted+{$professional->id}@partna.au",
            'first_name' => 'Deleted',
            'last_name' => null,
            'public_contact_email' => null,
            'public_contact_number' => null,
            'location_street_address' => null,
            'location_postcode' => null,
            'location_city' => null,
            'location_state' => null,
            'location_country' => null,
        ])->save();
        // country_code and timezone are not in this list.
        ```

- [ ] **#PRIV-11** · P2 — `Customer::redact()` / `Enquiry::redact()` PII-erasure methods exist but are never invoked anywhere in the application
    - **Where:** app/Models/Core/User/Customer.php:104-124; app/Models/Core/Site/Enquiry.php:135-149
    - **Affects:** Third-party customers/enquirers (second-subject data) whose contact details a professional collected — there is a fully-built, unit-tested erasure mechanism for this data that no code path ever triggers.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled command (mirroring `waitlist:prune-old-signups`) that calls `Customer::redact()` on rows past a defined inactivity window with no `external_id` (to avoid touching customers synced from an external POS/booking system) — this cascades to linked `Enquiry::redact()` automatically.
        - Separately, call `Enquiry::redact()` directly for enquiries with no linked `customer_id` past the same window.
        - Declare the retention window in `config/partna.php` alongside the platform's other retention values.
    - **Technical:** `Customer::redact()` nulls `email`/`full_name`/`phone`/`notes`, stamps `redacted_at`, and bulk-cascades to every linked `Enquiry` (nulling `name`/`email`/`phone`/`message`/`ip_hash`/`user_agent` and scrubbing the linked notification title/body) — a complete, correct, and already-tested (`tests/Unit/Models/CustomerRedactTest.php`, `EnquiryTest.php`) erasure path. But a repo-wide search for `->redact()`/`::redact(` finds callers only in tests — no controller, no console command, and no observer invokes either method in production. The `redacted_at` column these methods stamp exists precisely so downstream consumers can recognise a sanitised row, but nothing ever sets it outside a test.
    - **Plain English:** The engineering team already built the machinery to permanently scrub a customer's contact details and their message history — it's tested and it works. But nothing in the running application ever presses the button. It's a fire extinguisher mounted on the wall that no one has plumbed into the sprinkler system: fully functional, completely unused. Third-party customers and enquirers currently have no path — automated or otherwise — to having their data actually erased from a professional's records short of the professional manually intervening in the database.
    - **Evidence:**
        ```php
        // app/Models/Core/User/Customer.php
        public function redact(): void
        {
            $this->update([
                'email' => null, 'full_name' => null, 'phone' => null,
                'notes' => null, 'redacted_at' => now(),
            ]);
            // ...cascades to Enquiry::redact() for every linked enquiry...
        }
        // Repo-wide search for "->redact()" / "::redact(" outside tests/ and docs/: no matches.
        ```

- [ ] **#PRIV-12** · P2 — Raw (length-capped) `user_agent` stored unhashed across all four analytics tables despite `device_type` already serving every dashboard need
    - **Where:** app/Services/Analytics/AnalyticsEventSanitizer.php:45-58; app/Services/Analytics/Writers/PostgresEventWriter.php:104,173,222; app/Models/Analytics/SiteVisit.php:26-38
    - **Affects:** Every visitor to every public sitepage — their full (256-char-capped) browser User-Agent string is stored verbatim in `analytics.site_visits`, `link_clicks`, `section_views`, and `lead_submissions` for the full 90-day analytics retention window.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Hash `user_agent` at ingest (SHA-256/HMAC, same construction already used for `ip_hash`) instead of only length-capping it.
        - Confirm `device_type` (already derived and already the only field `AnalyticsQueryService` queries) continues to serve every dashboard breakdown.
    - **Technical:** `AnalyticsEventSanitizer::userAgent()` caps the UA at 256 characters but stores it otherwise verbatim; its own docblock says "device_type is derived separately, so the raw UA adds no dashboard value beyond this." A repo-wide check confirms `AnalyticsQueryService` never references the raw `user_agent` column — every breakdown uses only `device_type`. A capped-but-verbatim UA string is still a strong cross-site fingerprint when combined with `visitor_id`/`session_id`/timestamps; APP 3.4 minimisation calls for hashing (mirroring the existing `ip_hash` treatment) rather than length-capping alone.
    - **Plain English:** The platform already scrambles visitors' IP addresses for privacy — good practice — but stores their full browser fingerprint string as-is (just shortened), even though no report or dashboard ever reads that raw string; only a simple "desktop or mobile" label derived from it gets used. The fix is to apply the same scrambling already used for IP addresses to this field too.
    - **Evidence:**
        ```php
        // AnalyticsEventSanitizer::userAgent()
        // "device_type is derived separately, so the raw UA
        //  adds no dashboard value beyond this."
        public static function userAgent(?string $userAgent): ?string
        {
            if ($userAgent === null || $userAgent === '') { return null; }
            return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, '');
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Export completeness (`DataExportPayloadBuilder`):** #PRIV-2, #PRIV-3
    - **Why grouped:** same file, same manifest-entry + stream-method fix pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — New retention-enforcement commands:** #PRIV-1, #PRIV-4
    - **Why grouped:** both are "add a new scheduled Artisan command mirroring `PruneWaitlistSignupsCommand`" — same shape, different tables.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Deletion-path PII residue (`AccountDeletionService` + Redis):** #PRIV-9, #PRIV-10
    - **Why grouped:** both are small additions to the existing `AccountDeletionService::purge()` cleanup sequence.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Internal/staff PII hygiene:** #PRIV-5, #PRIV-7, #PRIV-8
    - **Why grouped:** all three are staff/audit-facing PII-discipline gaps (retention posture or unredacted output), same review lens.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Second-subject erasure wiring:** #PRIV-11
    - **Why grouped:** standalone fix, but low-risk (application-layer only, no schema change) — wiring an existing, tested mechanism into a scheduled command.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Analytics UA minimisation:** #PRIV-12
    - **Why grouped:** standalone; touches the shared sanitizer used by all four analytics write paths.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#PRIV-6 — `lead_submissions` FK CASCADE alignment** · reason: requires a Supabase schema migration (`ALTER TABLE ... DROP CONSTRAINT / ADD CONSTRAINT ... ON DELETE CASCADE`) — all DB migrations run standalone with their own plan + sign-off regardless of effort size.
