# Data Integrity & Privacy Audit — 2026-06-13

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `supabase/migrations/` (all 80 migrations)
- `app/Models/` (all model classes)
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/User/ConfirmationPreferenceService.php`
- `app/Services/User/UserBootstrapService.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`
- `supabase/migrations/20260526210001_create_feedback_table.sql`
- `supabase/migrations/20260527010000_reorganize_schemas.sql`
- `supabase/migrations/20260528000000_create_moderation_schema.sql`
- `supabase/migrations/20260531000000_create_smart_links.sql`
- `supabase/migrations/20260609000000_harden_platform_connections.sql`

## Adjudication notes

**12 draft findings dropped** after tool verification:

- *Schema DINT-1 (waitlist PII)* — `purgeWaitlistSignup` in `AccountDeletionService` and `streamWaitlistSignups` in `DataExportPayloadBuilder` already implement deletion and export. The claimed gap does not exist.
- *Schema DINT-3 (feedback reply_email)* — `purgeFeedbackRows` explicitly deletes the entire feedback row (including `reply_email`) during account purge. Already handled.
- *Models DINT-1 (LeadSubmission created_at)* — `analytics.lead_submissions` has no `created_at` column in the DB schema (only `occurred_at`). The model correctly omits the cast. False positive.
- *Models DINT-2 (email uniqueness)* — `CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL)` exists in the baseline. DB-level constraint is present.
- *Models DINT-3 (ConfirmationPreference unique)* — `CONSTRAINT professional_confirmation_preferences_professional_action_uq UNIQUE (professional_id, action_key)` exists in the baseline; survives the rename migration. DB-level constraint is present.
- *Models DINT-5 (insertOrIgnore conflict target)* — `email_subscriptions_unique_global_list_email_lc ON notifications.email_subscriptions (list_key, email_lc) WHERE (professional_id IS NULL)` is exactly the partial unique index that backs the `insertOrIgnore` for `sidest_updates`. The idempotency is guaranteed.

**2 draft findings re-tiered downward:**

- *Schema DINT-4 (case_signals reporter PII)* — P2→P3. `purgeCaseSignalPii` already nulls `reporter_email` for registered user deletions; the residual gap is only for signals filed by anonymous (non-account) reporters with no deletion trigger. Timed retention is a hardening measure, not an urgent correctness gap.
- *Models DINT-4 (welcome notification firstOrCreate)* — P2→P3. Duplicate welcome notifications are cosmetic; no data loss or PII exposure.

**1 draft finding re-tiered upward after additional verification:** none.

---

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 9 complete

---

## P2 — Should fix

- [ ] **#DINT-1** · P2 — `notifications.email_subscriptions` retains PII indefinitely after unsubscription
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (table creation); `supabase/migrations/20260527030000_rename_professional_to_user.sql` (column rename)
    - **Affects:** Any person who unsubscribes from a Partna marketing list without deleting their account — their `email`, `full_name`, `consent_ip_hash`, and `consent_user_agent` columns remain indefinitely. GDPR storage-limitation principle (Article 5(1)(e)) requires PII to be removed when the processing purpose expires; unsubscription terminates the consent basis.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled Artisan command (or extend the existing `partna:purge-soft-deletes` schedule) that NULLs `email`, `full_name`, `consent_ip_hash`, and `consent_user_agent` for rows where `status = 'unsubscribed'` and `unsubscribed_at <= NOW() - INTERVAL '90 days'`. Retain the row skeleton (id, list_key, email_lc, status, unsubscribed_at, unsubscribe_token) for re-subscription gating.
        - Document the 90-day window (or whatever retention period is chosen) in `config/partna.php` and reference it from the privacy policy.
        - Optionally extend the `streamEmailSubscriptions` generator in `DataExportPayloadBuilder` to include `unsubscribed_at` in the export so the user can see when their opt-out was recorded.
    - **Technical:** `AccountDeletionService::purgeGlobalEmailSubscriptions` already deletes global subscription rows on account hard-delete. The gap is for users who unsubscribe but keep their account: `status` flips to `'unsubscribed'` and `unsubscribed_at` is stamped, but no downstream job strips the PII columns. This is a category (9) PII-retention finding. The `user_id`-linked rows (subscriptions owned by a professional) are CASCADE-deleted when that professional hard-deletes; the retention gap primarily affects global platform-list rows (e.g., `sidest_updates`) and cross-tenant subscriber rows (a visitor who subscribed to someone else's newsletter).
    - **Plain English:** Someone signs up for email updates, then clicks the unsubscribe link. The system stops sending emails, but their name and email address sit in the database permanently — like stopping sending letters to someone but keeping their address card in your filing cabinet forever. After a reasonable period (say, three months), those personal details should be blanked out.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            -- renamed professional_id → user_id in 20260527030000
            user_id uuid,
            list_key varchar(50) DEFAULT 'marketing' NOT NULL,
            email text NOT NULL,
            full_name text,
            status varchar(20) DEFAULT 'subscribed' NOT NULL,
            subscribed_at timestamptz,
            unsubscribed_at timestamptz,
            unsubscribe_token varchar(80) NOT NULL,
            consent_source varchar(50),
            consent_ip_hash text,
            consent_user_agent text,
            ...
            CONSTRAINT email_subscriptions_status_check CHECK (status IN ('subscribed', 'unsubscribed'))
        );
        -- No deleted_at column. No scheduled PII-strip job for unsubscribed rows.
        ```

- [ ] **#DINT-2** · P2 — `notifications.broadcast_email_receipts.subscription_id` has no FK constraint; orphan rows accumulate on subscription deletion
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (broadcast_email_receipts table)
    - **Affects:** Broadcast email idempotency table — if a GDPR purge or future data-management job deletes `email_subscriptions` rows, the corresponding receipt rows become permanent orphans, bloating the table and muddying delivery analytics.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `CONSTRAINT broadcast_email_receipts_subscription_id_fkey FOREIGN KEY (subscription_id) REFERENCES notifications.email_subscriptions(id) ON DELETE CASCADE` so receipts are cleaned up when a subscription is deleted.
        - Because DINT-1's fix will NULL columns rather than delete rows, this FK is important for the delete path when subscriptions are fully removed (e.g., during account purge).
    - **Technical:** The table's PK is `(notification_id, subscription_id)`. `notification_id` has an `ON DELETE CASCADE` FK to `notifications.notifications`, but `subscription_id` has no FK at all. As `purgeGlobalEmailSubscriptions` hard-deletes subscription rows during account purge, the corresponding receipt rows are left as orphans. The orphan accumulation is silent — no exception, no alert. Category (1) FK-constraint-in-code-only finding.
    - **Plain English:** The system keeps a delivery receipt for every broadcast email. Each receipt links to the email broadcast and the subscriber. When a subscriber's record is deleted, the receipt is left pointing at a subscriber that no longer exists — like keeping a delivery confirmation on file for an address that was demolished.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
            notification_id uuid NOT NULL,
            subscription_id uuid NOT NULL,
            email_sent_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT broadcast_email_receipts_pkey PRIMARY KEY (notification_id, subscription_id),
            CONSTRAINT broadcast_email_receipts_notification_id_fkey
                FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE CASCADE
            -- No FK on subscription_id
        );
        ```

- [ ] **#DINT-3** · P2 — `audit.auth_factor_events.user_id` has no FK constraint; column is NOT NULL but orphans after auth user deletion
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (table creation as `core.auth_factor_events`); `supabase/migrations/20260527010000_reorganize_schemas.sql` (moved to `audit` schema)
    - **Affects:** MFA audit trail integrity — when a Supabase Auth user is deleted (via `AccountDeletionService` or admin action), `auth_factor_events` rows survive with a `user_id` pointing at a non-existent `auth.users` row. The column is declared `NOT NULL`, so `ON DELETE SET NULL` cannot be added without first making the column nullable. Meanwhile, every other table that references `auth.users` carries an explicit FK.
    - **Effort:** S (~1h)
    - **What to do:**
        - Make `user_id` nullable and add FK with `ON DELETE SET NULL` — matching the `audit.handle_change_log.professional_id` pattern which deliberately preserves audit rows after user deletion:
          ```sql
          ALTER TABLE audit.auth_factor_events ALTER COLUMN user_id DROP NOT NULL;
          ALTER TABLE audit.auth_factor_events ADD CONSTRAINT auth_factor_events_user_fk
              FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE SET NULL;
          ```
        - Do **not** use `ON DELETE CASCADE` — that would destroy MFA audit records on user deletion, which is the opposite of what a security audit log requires.
        - Add a schema comment: `COMMENT ON COLUMN audit.auth_factor_events.user_id IS 'auth.users UUID; SET NULL on auth-user deletion — orphan rows are retained for forensic MFA audit.'`
    - **Technical:** The reorganize migration (`20260527010000`) explicitly moved this table to the `audit` schema alongside `handle_change_log` and `staff_audit_log`, all of which use `ON DELETE SET NULL` to preserve audit rows after parent deletion. `auth_factor_events` was the only table in this group that retained `user_id NOT NULL` without a FK — an inconsistency rather than an intentional design choice. Category (1) FK-without-explicit-ON-DELETE finding. The `DataExportPayloadBuilder::streamAuthFactorEvents` correctly reads from `audit.auth_factor_events` using the auth UUID.
    - **Plain English:** Every time a user enrols in two-factor authentication, challenges it, or fails a verification, the system logs it for security. But if the account is deleted, those logs are still in the database pointing at a user that no longer exists — like a security camera log that refers to an employee badge number that was deactivated. The fix is to allow that column to go blank on deletion (keeping the log entry, just unlinking the deleted badge).
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CREATE TABLE IF NOT EXISTS core.auth_factor_events (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            user_id uuid NOT NULL,  -- no FOREIGN KEY constraint; moved to audit schema below
            session_id uuid,
            event_type text NOT NULL,
            factor_id uuid,
            factor_type text,
            ip inet,
            user_agent text,
            metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT auth_factor_events_pkey PRIMARY KEY (id),
            ...
        );
        -- supabase/migrations/20260527010000_reorganize_schemas.sql
        ALTER TABLE core.auth_factor_events SET SCHEMA audit;
        -- user_id NOT NULL, no FK, no ON DELETE clause — unique among audit tables
        ```

- [ ] **#DINT-4** · P2 — `site.smart_links` has no UNIQUE constraint on `(site_id, canonical_url)` — `firstOrCreate`-style idempotency relies on application code only
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql`
    - **Affects:** Users who trigger two simultaneous smart-link additions (rapid double-tap, tab race, or webhook replay) — both requests pass the application-layer check and insert, producing duplicate smart link cards on the public sitepage. The site renderer would display two identical cards.
    - **Effort:** M (~2h)
    - **What to do:**
        - Add a partial unique index to enforce the natural key:
          ```sql
          CREATE UNIQUE INDEX CONCURRENTLY uq_smart_links_site_canonical
              ON site.smart_links (site_id, canonical_url) WHERE deleted_at IS NULL;
          ```
        - Update the application-layer upsert to handle the resulting `UniqueViolationException` and return the existing row, rather than silently proceeding.
    - **Technical:** The `site.smart_links` table has indexes on `(site_id, family, sort_order)` and `(last_refreshed_at)` but no uniqueness constraint on the natural key `(site_id, canonical_url)`. Any `firstOrCreate`-style SELECT-then-INSERT pattern is vulnerable to Read-Committed phantom insertion: two concurrent transactions both see zero matching rows, both insert, and both commit. The `CONCURRENTLY` qualifier avoids a table-level lock on the (currently small) table. Category (7) race-condition / category (8) composite-uniqueness finding.
    - **Plain English:** The system wants only one card per URL per sitepage. It checks "do we already have this URL?" before adding it. But if two requests check at exactly the same moment, they both see "no" and both add a card. Adding a uniqueness rule to the database itself — not just the code — means the database will reject the second one automatically, regardless of timing.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260531000000_create_smart_links.sql
        CREATE TABLE IF NOT EXISTS site.smart_links (
            id                    uuid PRIMARY KEY,
            user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            site_id               uuid NOT NULL REFERENCES site.sites (id) ON DELETE CASCADE,
            canonical_url         text NOT NULL,
            ...
            deleted_at            timestamptz
        );
        -- No UNIQUE constraint on (site_id, canonical_url)
        CREATE INDEX IF NOT EXISTS idx_smart_links_site_family_sort
            ON site.smart_links (site_id, family, sort_order)
            WHERE deleted_at IS NULL;
        CREATE INDEX IF NOT EXISTS idx_smart_links_last_refreshed
            ON site.smart_links (last_refreshed_at)
            WHERE deleted_at IS NULL;
        ```

- [ ] **#DINT-5** · P2 — `site.smart_links.type` and `.platform` are bare `text NOT NULL` with no CHECK constraint
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql`
    - **Affects:** Any code path (job, migration backfill, direct SQL) that writes a `type` or `platform` value outside the application enum — the DB accepts it silently, the renderer encounters an unknown value, and the sitepage block fails to render with no DB-layer signal.
    - **Effort:** S (~1h)
    - **What to do:**
        - Enumerate valid `type` values from the `SmartLinkType` enum and add: `ADD CONSTRAINT smart_links_type_check CHECK (type IN (...))`.
        - Enumerate valid `platform` values (mirroring the `platform_connections.platform` CHECK allowlist pattern) and add: `ADD CONSTRAINT smart_links_platform_check CHECK (platform IN (...))`.
        - Both are catalog-only changes (no row rewrite) when added as `NOT VALID` first, then `VALIDATE CONSTRAINT` separately.
    - **Technical:** The sibling `family` column and `last_refresh_status` column both have CHECK constraints in the same migration. `type` and `platform` — which are the higher-cardinality, application-code-matched values — have none. The `site.platform_connections.platform` column established the CHECK-per-platform pattern (`20260602150238` and its subsequent hardening migrations). `smart_links.platform` should follow the same governance. Category (4) enum/CHECK-coverage finding.
    - **Plain English:** Every other "category" column in this table has guardrails — `family` can only be "commerce" or "content," `last_refresh_status` can only be "ok," "unavailable," or "error." But `type` and `platform` have no guardrails at all. A bug or a manual edit could write anything — `type = "mystery"` — and the database would accept it. The sitepage would then try to render an unknown card type and break silently.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260531000000_create_smart_links.sql
        family                text NOT NULL CHECK (family IN ('commerce', 'content')),
        type                  text NOT NULL,       -- no CHECK constraint
        platform              text NOT NULL,       -- no CHECK constraint
        ...
        last_refresh_status   text CHECK (last_refresh_status IN ('ok', 'unavailable', 'error')),
        ```

---

## P3 — Nice to have

- [ ] **#DINT-6** · P3 — `moderation.case_signals.reporter_email` has no TIMED retention for signals filed by non-account reporters
    - **Where:** `supabase/migrations/20260528000000_create_moderation_schema.sql`
    - **Affects:** Reporters who submit a content signal without a Partna account (e.g., via an email-only report form), or whose account is later deleted before the case is resolved — their `reporter_email` remains in the signal row indefinitely after case resolution, with no scheduled cleanup.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled job that NULLs `reporter_email` and `reason_details` on signal rows where the parent case `status IN ('resolved', 'auto_actioned')` and `resolved_at <= NOW() - INTERVAL '90 days'`. (Keep `reporter_user_id`, `reporter_ip_hash`, `reason_code`, and `signal_source` for T&S analytics; `reporter_ip_hash` is a one-way hash, not directly identifiable.)
        - Document the retention window in the privacy policy and `config/partna.php`.
    - **Technical:** `AccountDeletionService::purgeCaseSignalPii` already NULLs `reporter_email` and `reason_details` when the reporter's account is deleted — that path is handled. The gap is for (a) reporters without a Partna account (no deletion trigger) and (b) reporters whose cases remain in `'open'` or `'under_review'` at deletion time but aren't captured by the `WHERE reporter_user_id = ?` filter. Category (9) PII retention finding; the account-deletion path is solved, the timed-retention path is not.
    - **Plain English:** When someone reports spam or abuse, they may give an email address. If they later delete their Partna account, the system clears their details — that works. But if they reported without an account, or the case was still open when they deleted, their email address sits in the database long after the case was resolved. Like a crime tip line that shreds records for registered informants but keeps anonymous tipster notes forever.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260528000000_create_moderation_schema.sql
        CREATE TABLE IF NOT EXISTS moderation.case_signals (
            id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            case_id             UUID NOT NULL,
            reporter_user_id    UUID NULL,
            reporter_email      VARCHAR(255) NULL,   -- no timed retention
            reporter_ip_hash    VARCHAR(64) NULL,
            reason_details      TEXT NULL,
            ...
            CONSTRAINT case_signals_reporter_user_fk FOREIGN KEY (reporter_user_id)
                REFERENCES core.users(id) ON DELETE SET NULL
        );
        ```
        ```php
        // app/Services/User/AccountDeletionService.php
        // Runs only on account deletion — no parallel retention schedule:
        private function purgeCaseSignalPii(User $professional): void
        {
            DB::connection('pgsql')
                ->table('moderation.case_signals')
                ->where('reporter_user_id', $professional->id)
                ->update(['reporter_user_id' => null, 'reporter_email' => null, ...]);
        }
        ```

- [ ] **#DINT-7** · P3 — `analytics.section_views.block_id` FK column has no index
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql` (section_views table)
    - **Affects:** Any dashboard or analytics query that filters or groups by `block_id` — Postgres performs a sequential scan on the full table instead of an index seek.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY IF NOT EXISTS section_views_block_id_idx ON analytics.section_views (block_id) WHERE block_id IS NOT NULL;`
    - **Technical:** The FK `section_views_block_fk REFERENCES site.blocks(id) ON DELETE SET NULL` exists, but PostgreSQL does not auto-index FK columns. Existing indexes cover `(professional_id, occurred_at)`, `(site_id, section_key, occurred_at)`, and `(session_id, section_key)` — none cover `block_id`. This table grows proportionally to public sitepage traffic and is the most likely analytics table to be queried per block. Category (1) FK-missing-index finding.
    - **Plain English:** When the dashboard asks "how many views did this specific block get?", the database has to read every row in the table one by one. Adding a lookup index for `block_id` makes it jump straight to the relevant rows — like adding a page number to a book that currently has no index.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CONSTRAINT section_views_block_fk FOREIGN KEY (block_id)
            REFERENCES site.blocks(id) ON DELETE SET NULL

        CREATE INDEX section_views_professional_occurred_idx
            ON analytics.section_views (professional_id, occurred_at DESC);
        CREATE INDEX section_views_site_section_occurred_idx
            ON analytics.section_views (site_id, section_key, occurred_at DESC);
        CREATE INDEX section_views_session_section_idx
            ON analytics.section_views (session_id, section_key);
        -- No index on block_id
        ```

- [ ] **#DINT-8** · P3 — `core.feature_flag_overrides.created_by` FK column has no index
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql`
    - **Affects:** Staff audit queries that look up "all overrides created by this staff member" — sequential scan on a small but slow-growing table.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_created_by_idx ON core.feature_flag_overrides (created_by) WHERE created_by IS NOT NULL;`
    - **Technical:** The FK `feature_flag_overrides_created_by_fkey REFERENCES core.partna_staff(id) ON DELETE SET NULL` exists without an index. Four other indexes exist on this table (`flag_key/user_id`, `user_id/flag_key`, `expires_at`, `flag_key/created_at`) but none cover `created_by`. Category (1) FK-missing-index finding.
    - **Plain English:** Every feature flag override records which staff member enabled it. Finding "all overrides created by Alice" currently requires reading every row. Like an employee timesheet with no index by name — it works but wastes time.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CONSTRAINT feature_flag_overrides_created_by_fkey
            FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL

        CREATE UNIQUE INDEX feature_flag_overrides_pro_unique
            ON core.feature_flag_overrides (flag_key, professional_id);
        CREATE INDEX feature_flag_overrides_pro_lookup
            ON core.feature_flag_overrides (professional_id, flag_key) WHERE professional_id IS NOT NULL;
        CREATE INDEX feature_flag_overrides_expires_at
            ON core.feature_flag_overrides (expires_at) WHERE expires_at IS NOT NULL;
        CREATE INDEX feature_flag_overrides_flag_key_created
            ON core.feature_flag_overrides (flag_key, created_at DESC);
        -- No index on created_by
        ```

- [ ] **#DINT-9** · P3 — `site.smart_links` has no `BEFORE UPDATE` trigger; `updated_at` stalls on background job writes
    - **Where:** `supabase/migrations/20260531000000_create_smart_links.sql`
    - **Affects:** Any background job (e.g., the cron staleness scan that refreshes link metadata) that uses `DB::table('site.smart_links')->update(...)` outside Eloquent — `updated_at` never advances, making "last modified" timestamps unreliable for stale-detection logic.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add a trigger in a new migration, mirroring the pattern used on every other site-schema table:
          ```sql
          CREATE OR REPLACE TRIGGER set_timestamp_smart_links
              BEFORE UPDATE ON site.smart_links
              FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
          ```
        - Also add `ALTER COLUMN created_at SET DEFAULT now(), ALTER COLUMN updated_at SET DEFAULT now()` to close the raw-insert gap (mirroring `20260609000000_harden_platform_connections.sql`).
    - **Technical:** `site.smart_links` defines `created_at timestamptz` and `updated_at timestamptz` with no DEFAULT and no BEFORE UPDATE trigger. Every other `site.*` table (sites, blocks, services, site_media, enquiries, site_subdomain_aliases) has the `set_updated_at()` trigger binding. The staleness-scan cron that refreshes link snapshots is the most likely raw-update path; without the trigger, `updated_at` freezes at the last Eloquent save. Category (5) timestamp-hygiene finding.
    - **Plain English:** Every other table in the system has an automatic "last modified" clock that ticks whenever a row changes. The smart links table was set up without one. When a background worker updates a link's snapshot metadata, the "last modified" time doesn't move — like a car's odometer that only turns when the owner drives, not the mechanic.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260531000000_create_smart_links.sql
        created_at            timestamptz,    -- no DEFAULT
        updated_at            timestamptz,    -- no DEFAULT
        deleted_at            timestamptz
        -- No BEFORE UPDATE trigger anywhere in the migration file.
        -- Compare: baseline line 1641:
        -- CREATE OR REPLACE TRIGGER set_timestamp_site_media
        --     BEFORE UPDATE ON site.site_media FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
        ```

- [ ] **#DINT-10** · P3 — `site.platform_connections` has no `BEFORE UPDATE` trigger; column DEFAULT added but trigger not
    - **Where:** `supabase/migrations/20260602150238_create_platform_connections.sql`; `supabase/migrations/20260609000000_harden_platform_connections.sql`
    - **Affects:** The platform refresh worker (which updates connection metadata outside Eloquent) — `updated_at` doesn't advance, making staleness detection by the integrations dashboard unreliable.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add in a new migration:
          ```sql
          CREATE OR REPLACE TRIGGER set_timestamp_platform_connections
              BEFORE UPDATE ON site.platform_connections
              FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
          ```
    - **Technical:** `20260609000000_harden_platform_connections.sql` added `ALTER COLUMN created_at SET DEFAULT now()` and `ALTER COLUMN updated_at SET DEFAULT now()` — correct for INSERT defaults — but a column DEFAULT only fires on INSERT, not UPDATE. The BEFORE UPDATE trigger is the missing layer. Category (5) timestamp-hygiene finding; structurally identical to DINT-9.
    - **Plain English:** The 2026-06-09 hardening migration gave the platform connections table a working timestamp on insert (when a new connection is created), but not on update (when the connection is refreshed). The clock only ticks when creating, not when updating.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260609000000_harden_platform_connections.sql
        ALTER TABLE site.platform_connections
            ALTER COLUMN created_at SET DEFAULT now(),
            ALTER COLUMN updated_at SET DEFAULT now();
        -- No BEFORE UPDATE trigger created.
        -- COMMIT;
        ```

- [ ] **#DINT-11** · P3 — `site.site_media.pool` CHECK includes dead `'brand_gallery'` value from the pre-standalone era
    - **Where:** `supabase/migrations/20260526000000_baseline_standalone_user.sql`
    - **Affects:** Developer comprehension — the CHECK implies brand galleries are a supported concept, but no application code references `'brand_gallery'` as a pool value (confirmed by grep across `app/`). Confusion risk for new contributors.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Either drop and recreate the constraint without `'brand_gallery'` (requires `NOT VALID` pattern since the table has rows), or add a SQL comment above the CHECK documenting it as dead vocabulary: `-- 'brand_gallery' was a pre-standalone pool; no app code writes it; retained to avoid CHECK violation on any historical rows`.
        - If no historical rows use `'brand_gallery'`, prefer the clean DROP + ADD CONSTRAINT NOT VALID approach.
    - **Technical:** The `brand` schema was removed in the 2026-05-22 standalone strip. The `pool` CHECK was not updated. A grep of `app/` returns zero matches for `brand_gallery`, confirming it is dead vocabulary. Category (4) enum-coverage hygiene finding. No functional impact — the value can never be written — but it misrepresents the current data model to any developer reading the schema. Per the intentional-dormancy drop rule, this is not the same as "dormant CSAM vocabulary" — the CHECK constraint is a data-contract statement about what values are valid, and dead values in it are misleading.
    - **Plain English:** The database's rule for "what pool can a media file belong to" still includes "brand gallery" — a feature that was removed over a month ago. Like a restaurant menu that still lists a dish that was taken off. Nobody can order it, but it's confusing.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260526000000_baseline_standalone_user.sql
        CONSTRAINT site_media_pool_check CHECK (pool IN (
            'gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'
        ))
        -- No application code writes 'brand_gallery' (confirmed grep across app/).
        ```

- [ ] **#DINT-12** · P3 — `UserBootstrapService::createWelcomeNotification` uses `firstOrCreate` without a DB-level unique constraint on `(user_id, type, title)`
    - **Where:** `app/Services/User/UserBootstrapService.php:156-172`
    - **Affects:** Users who trigger two near-simultaneous bootstrap calls (mobile app + web client racing after Supabase signup) — both may insert a "Welcome to Partna" notification, resulting in two identical dashboard messages.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - The `notifications.notifications` table has `notifications_dedupe_key_per_pro_uq ON notifications.notifications (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL`. Use this existing mechanism: generate a stable `dedupe_key` for the welcome notification (e.g., `'welcome_v1'`) and pass it in `firstOrCreate`; the index then enforces idempotency at the DB level.
        - Alternatively, add `UNIQUE(user_id, type, title)` but that is a broader constraint than needed — the `dedupe_key` approach is canonical for this table.
    - **Technical:** `firstOrCreate` is a SELECT-then-INSERT pair with no locking between steps. Under Read Committed isolation, two concurrent calls can both SELECT zero matching rows and both proceed to INSERT. Impact is cosmetic (duplicate welcome notifications) but is the same class of constraint-in-code-only pattern the lens targets. The `dedupe_key` partial unique index already exists precisely for this use case.
    - **Plain English:** The welcome message is created with a "does this already exist?" check. If two browser tabs both try to finish signup at the exact same moment, both checks see "no message yet" and both create one. The user then has two identical welcome cards in their inbox. Using a unique ID for the welcome message (which the database already supports) prevents duplicates at the source.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function createWelcomeNotification(User $professional): void
        {
            Notification::query()->firstOrCreate(
                [
                    'user_id' => $professional->id,
                    'type' => 'Info',
                    'title' => 'Welcome to Partna',
                ],
                [
                    'body' => 'Your account is ready. Complete your profile and start building your professional page from your dashboard.',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
        ```
        ```sql
        -- Existing dedup index (not used by the above call):
        -- CREATE UNIQUE INDEX notifications_dedupe_key_per_pro_uq
        --     ON notifications.notifications (professional_id, dedupe_key)
        --     WHERE dedupe_key IS NOT NULL;
        ```

- [ ] **#DINT-13** · P3 — `GdprRequest` model references `core.gdpr_requests` table, which does not exist in the standalone schema
    - **Where:** `app/Models/Core/Gdpr/GdprRequest.php:33`
    - **Affects:** Any accidental reference to `GdprRequest` in a new code path — a query against this model would throw a table-not-found PDO exception. The model carries Shopify constants (`TOPIC_CUSTOMERS_DATA_REQUEST`, `TOPIC_CUSTOMERS_REDACT`, `TOPIC_SHOP_REDACT`) and a PII-carrying `payload` cast that no longer serves any purpose in the standalone architecture.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Verify the `core.gdpr_requests` table is absent from the dev and prod databases (it should be — it's not in the baseline migration).
        - Delete `app/Models/Core/Gdpr/GdprRequest.php` and `app/Policies/GdprPolicy.php` (which references `GdprRequest`).
        - Check for any remaining references (`Grep`) and remove them.
    - **Technical:** `core.gdpr_requests` is absent from the `20260526000000_baseline_standalone_user.sql` baseline and from all subsequent migrations — the table was removed during the 2026-05-22 standalone strip. The model class remains, still declaring `protected $table = 'core.gdpr_requests'`, Shopify topic constants, and `protected $casts = ['payload' => 'array']`. Dead code that references a non-existent PII-carrying table is a latent compliance confusion risk: a future developer might assume the table exists and attempt to query it. The `GdprPolicy` also still references this model. Category (9) hygiene / dead-PII-model finding.
    - **Plain English:** The platform used to process Shopify GDPR requests, which were stored with customer email addresses and shop details. That entire feature was removed, but the code blueprint for it is still sitting around. The database table it references no longer exists — so if anyone accidentally used it, the app would crash. More importantly, the blueprint implies there's sensitive data to handle that no longer exists, which is confusing during compliance reviews.
    - **Evidence:**
        ```php
        // app/Models/Core/Gdpr/GdprRequest.php
        // V2: Audit row for Shopify GDPR webhooks.
        class GdprRequest extends BaseModel
        {
            public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';
            public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';
            public const TOPIC_SHOP_REDACT = 'shop/redact';

            protected $table = 'core.gdpr_requests';   // table absent from baseline

            protected $casts = [
                'payload' => 'array',                   // payload carried Shopify customer PII
                ...
            ];
        }
        ```

- [ ] **#DINT-14** · P3 — `FeatureFlagOverride` hand-rolls UUID generation in `booted()` instead of using the `HasUuids` trait
    - **Where:** `app/Models/Core/FeatureFlagOverride.php:32-38`
    - **Affects:** Any `FeatureFlagOverride::insert()` or `DB::table()->insert()` call that bypasses Eloquent model events — the UUID is generated in a `creating` observer, which fires only on Eloquent `->save()`/`->create()`, not on raw query builder inserts. A raw insert would leave `id` empty and trigger a constraint violation.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace the manual `booted()` UUID logic with `use HasUuids;` and remove the `booted()` override:
          ```php
          use HasUuids;
          // Remove: protected static function booted() { ... }
          ```
        - `HasUuids` correctly handles `$incrementing = false` and `$keyType = 'string'` internally; the explicit declarations can be kept for clarity.
        - Also add `ALTER COLUMN id SET DEFAULT gen_random_uuid()` in a migration so raw SQL inserts get a UUID without Eloquent involvement — mirroring the `20260609000000_harden_platform_connections.sql` pattern.
    - **Technical:** Every other UUID-keyed model in the codebase (`User`, `Site`, `Block`, `SmartLink`, `Notification`, `Feedback`, `SiteMedia`, `LeadSubmission`, `LinkClick`, `SectionView`, etc.) uses `HasUuids`. `FeatureFlagOverride` is the sole holdout. The `creating` event only fires through `Model::save()` or `Model::create()`. Direct DB table inserts (migrations, data patches, mass-ops using the query builder) bypass the event, leaving `id` as whatever the column default is — which is `gen_random_uuid()` if the DB default exists (currently it does not, unlike `site.platform_connections` after its hardening migration). Category (1) constraint-in-code-only finding.
    - **Plain English:** Every "ID badge generator" in the system uses the official machine (the `HasUuids` trait), except for feature flag overrides, which still use a handwritten process. The handwritten process is wired into the app's save event — but if anyone writes directly to the database (e.g., via a migration script), no ID gets generated and the database rejects the row. Switching to the standard machine fixes both paths.
    - **Evidence:**
        ```php
        // app/Models/Core/FeatureFlagOverride.php
        protected static function booted(): void
        {
            static::creating(function (self $row): void {
                if (empty($row->id)) {
                    $row->id = (string) Str::uuid();
                }
            });
        }
        // No `use HasUuids;` declaration
        // No DB-level DEFAULT gen_random_uuid() on the id column
        ```
        ```php
        // Canonical pattern — every other UUID model:
        // app/Models/Analytics/LeadSubmission.php
        use HasUuids;
        // (no booted() UUID logic; DB default + trait covers all insert paths)
        ```

