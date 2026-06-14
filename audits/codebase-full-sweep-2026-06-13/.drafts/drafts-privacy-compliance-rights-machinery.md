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
