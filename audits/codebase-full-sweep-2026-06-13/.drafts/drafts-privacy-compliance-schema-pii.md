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
