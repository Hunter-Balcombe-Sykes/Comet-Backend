- [ ] **#DINT-1** · P1 — `core.waitlist_signups` stores PII (email, name, phone, IP hash) with no retention or deletion mechanism
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (table creation); supabase/migrations/20260526010000_relax_waitlist_signups_constraints.sql (relaxes NOT NULL but adds no retention)
    - **Affects:** Every signup who submitted email/name/phone through the public waitlist form or the bootstrap divert — their PII is stored with no path to deletion under a GDPR erasure request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `deleted_at timestamptz NULL` column to `core.waitlist_signups` and an index `WHERE deleted_at IS NULL` mirrored from `core.users`.
        - Wire the table into the `partna:purge-soft-deletes` scheduled command or add a dedicated retention job.
        - Wire email into `DataExportPayloadBuilder` / `AccountDeletionService` so a user's waitlist entry is exported and redacted when they exercise their GDPR rights.
    - **Technical:** The table has `name`, `email`, `phone`, and `consent_ip_hash` — all PII under GDPR. There is no `deleted_at` column and no mention of this table in any purge command or GDPR export/deletion service path from the provided files. The RLS policy allows `anon` INSERT, so every public signup creates an undeletable PII row. Post-GDPR-launch, a verified email-tie to a user account would make this an un-redactable PII anchor.
    - **Plain English:** Someone signs up for the waitlist with their real name and email. Later they create an account and later still request full deletion of their data. Their waitlist entry — with the same email — stays in the database forever because nobody built a way to delete it. Like writing a name in a visitor's book and then burning every other record in the building but keeping the book open on the counter.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.waitlist_signups (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            name text NOT NULL,
            email text NOT NULL,
            email_lc text NOT NULL,
            phone text NOT NULL,
            ...
            consent_ip_hash text NULL,
            consent_user_agent text NULL,
            ...
        );
        -- No deleted_at column. No FK to core.users. No purge command in scope.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-2** · P2 — `notifications.email_subscriptions` retains PII (email, full_name) indefinitely after unsubscription
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (table creation)
    - **Affects:** Any person who subscribed and later unsubscribed — their email and full name remain in the table permanently. GDPR data-minimisation exposure.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled job that NULLs out `email`, `full_name`, `consent_ip_hash`, and `consent_user_agent` for rows where `status = 'unsubscribed'` and `updated_at` is older than the configured retention window.
        - Alternatively, add `deleted_at` and purge after retention, but note the `unsubscribe_token` is needed for re-subscription gating — token-only retention after PII strip is the lighter touch.
    - **Technical:** The `status` column flips to `'unsubscribed'` on opt-out, but the row's PII columns (`email`, `full_name`, `consent_ip_hash`) are never cleared. There's no `deleted_at` column and no purge command visible for this table. GDPR principle of storage limitation requires PII to be removed when the purpose expires; unsubscription means the consent basis is gone and retention for "maybe re-subscribe" needs a documented, time-limited window.
    - **Plain English:** A visitor subscribes to a newsletter, then clicks unsubscribe. The system stops sending emails, but their email address and name sit in the database forever. Like keeping someone's business card in your Rolodex after they've asked to be removed from your mailing list — the card should be thrown out, not just flipped face-down.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            ...
            email text NOT NULL,
            full_name text,
            status varchar(20) DEFAULT 'subscribed' NOT NULL,
            ...
            consent_ip_hash text,
            consent_user_agent text,
            ...
        );
        -- No deleted_at column. No purge job visible.
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-3** · P2 — `core.feedback.reply_email` PII column survives user deletion
    - **Where:** supabase/migrations/20260526210001_create_feedback_table.sql
    - **Affects:** Authenticated users who submit feedback with a reply email and later delete their account — their email remains in the feedback table after `user_id` is NULLed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `AccountDeletionService`, add a step that NULLs out `reply_email` on all feedback rows where `user_id = :deletedUserId` before the FK cascades to SET NULL.
        - Or add an ON DELETE trigger that strips `reply_email` when `user_id` is set to NULL.
    - **Technical:** The FK is `user_id REFERENCES core.users(id) ON DELETE SET NULL`, which severs the linkage but leaves the `reply_email` column untouched. That column is free-text PII entered by the user. GDPR erasure requires removal of personal data, not just unlinking. Since the deletion service owns the deletion flow, adding a pre-delete scrub query there is the canonical fix.
    - **Plain English:** A user writes in with a bug report, includes their email so we can follow up, and later deletes their account. Their user record is gone, but the bug report still has their email address sitting in it — like erasing someone from the employee directory but leaving their name on a sticky note on the office fridge.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.feedback (
            ...
            user_id uuid NULL,
            reply_email text NULL,
            ...
            CONSTRAINT feedback_user_fk FOREIGN KEY (user_id)
                REFERENCES core.users(id) ON DELETE SET NULL,
            ...
        );
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-4** · P2 — `moderation.case_signals.reporter_email` — reporter PII without retention or redaction controls
    - **Where:** supabase/migrations/20260528000000_create_moderation_schema.sql (case_signals table)
    - **Affects:** Reporters who submit content reports — their email persists in the moderation database indefinitely.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled job that NULLs out `reporter_email` and `reporter_ip_hash` on signals attached to resolved cases older than the retention window (e.g. 90 days post-resolution).
        - Document the retention policy in the privacy policy and ensure the data export builder includes these fields when a reporter exercises a Subject Access Request.
    - **Technical:** The `case_signals` table holds `reporter_email` (raw email, not hashed), `reporter_ip_hash`, and `reporter_user_id`. The table cascades from `moderation.cases ON DELETE CASCADE`, but cases are expected to be retained long-term (abuse patterns, recidivism tracking). There's no redaction mechanism — even when a case is resolved, the reporter's PII remains. This is a GDPR data-minimisation issue: PII collected for moderation should have a defined retention period.
    - **Plain English:** Someone reports a spam account and provides their email. Six months later the case is long resolved, but the reporter's email is still sitting in the database. Like a police department keeping a crime witness's contact details filed in an open-access drawer years after the case closed.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS moderation.case_signals (
            ...
            reporter_email      VARCHAR(255) NULL,
            reporter_ip_hash    VARCHAR(64) NULL,
            reporter_user_id    UUID NULL,
            ...
        );
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-5** · P2 — `notifications.broadcast_email_receipts.subscription_id` has no FK constraint
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (broadcast_email_receipts table)
    - **Affects:** Broadcast email idempotency — orphan receipt rows accumulate if email subscriptions are deleted, but functionally the PK dedup still works.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FOREIGN KEY (subscription_id) REFERENCES notifications.email_subscriptions(id) ON DELETE CASCADE` so receipts are cleaned up when a subscription is removed.
    - **Technical:** The table's PK is `(notification_id, subscription_id)` and `notification_id` has an `ON DELETE CASCADE` FK to `notifications.notifications`. But `subscription_id` conceptually references `notifications.email_subscriptions(id)` with no FK constraint. If an email_subscription row is deleted (say, by a future GDPR purge), the corresponding broadcast_email_receipts row becomes an orphan. The PK dedup still functions, but accumulated orphan rows bloat the table over time.
    - **Plain English:** The system keeps a "sent receipt" for every broadcast email delivered. Each receipt links to both the broadcast and the subscriber. If the subscriber record is ever deleted, the receipt is left dangling — pointing at a subscriber that no longer exists, like a delivery confirmation slip for a package addressed to a demolished house.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
            notification_id uuid NOT NULL,
            subscription_id uuid NOT NULL,
            email_sent_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT broadcast_email_receipts_pkey PRIMARY KEY (notification_id, subscription_id),
            CONSTRAINT broadcast_email_receipts_notification_id_fkey
                FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE CASCADE
        );
        -- No FK on subscription_id
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-6** · P2 — `core.auth_factor_events.user_id` references `auth.users` with no FK constraint
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (auth_factor_events table)
    - **Affects:** MFA audit trail integrity — orphan event rows can accumulate when Supabase Auth users are deleted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FOREIGN KEY (user_id) REFERENCES auth.users(id) ON DELETE CASCADE` so MFA events are cleaned up when the Supabase Auth user is removed.
        - Confirm the `auth` schema is in the same database (it is — Supabase hosts it) so cross-schema FK is valid.
    - **Technical:** The `user_id` column is `uuid NOT NULL` with no REFERENCES clause. Every other table that references `auth.users` carries an explicit FK (e.g., `core.users.auth_user_id REFERENCES auth.users(id) ON DELETE CASCADE`). This table is the exception. When a Supabase Auth user is deleted (either by `AccountDeletionService` or admin action), the MFA event rows survive as orphans pointing at a non-existent auth user. The table is append-only in practice, so these orphans accumulate forever.
    - **Plain English:** Every 2FA enrollment, challenge, and verification is logged for security. But if the person's account is deleted, those security logs stay in the database pointing at a ghost — like a building's security camera footage archived with a label referencing an employee ID that was deleted from the HR system years ago.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS core.auth_factor_events (
            id uuid DEFAULT gen_random_uuid() NOT NULL,
            user_id uuid NOT NULL,
            session_id uuid,
            ...
            -- No FOREIGN KEY constraint on user_id
        );
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-7** · P2 — `site.smart_links` has no unique constraint — upsert race condition
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql
    - **Affects:** Users who add the same URL twice in quick succession — duplicate smart link rows can be inserted before `firstOrCreate` sees the first one.
    - **Effort:** M (~2h)
    - **What to do:**
        - Add `CREATE UNIQUE INDEX CONCURRENTLY ... ON site.smart_links (site_id, canonical_url) WHERE deleted_at IS NULL`.
        - Ensure the application upsert uses `ON CONFLICT` against this constraint rather than relying on `firstOrCreate`.
    - **Technical:** The table models a `firstOrCreate`-style idempotent insert (one canonical URL per site). But there's no DB-level UNIQUE constraint — only two btree indexes on `(site_id, family, sort_order)` and `(last_refreshed_at)`. Concurrent requests inserting the same URL will both pass the SELECT check and create duplicate rows. This is the classic Eloquent `firstOrCreate` race condition — the fix is always a DB-level unique constraint with `ON CONFLICT` handling in the upsert.
    - **Plain English:** A user pastes the same Shopify product link twice, quickly. The app checks "do we already have this link?" — both requests see "no" and insert a row. Now there are two identical smart link cards on the sitepage. Like two clerks at a warehouse both checking the "already shipped?" list at the same moment and both shipping the same item.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.smart_links (
            ...
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
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-8** · P2 — `site.smart_links.type` and `site.smart_links.platform` are TEXT without CHECK constraints
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql
    - **Affects:** Direct DB writes or future code changes can insert invalid type/platform values that the application can't handle.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add `CHECK (type IN (...))` enumerating all valid smart link types from the `SmartLinkType` enum.
        - Add `CHECK (platform IN (...))` mirroring the `platform_connections` pattern (comma-separated allowlist of supported platforms).
    - **Technical:** The sibling `family` column has `CHECK (family IN ('commerce', 'content'))` and `last_refresh_status` has its CHECK, but `type` and `platform` are bare `text NOT NULL` with no constraint. `site.platform_connections.platform` has a painstakingly-maintained CHECK allowlist — `smart_links.platform` should follow the same pattern. Without CHECKs, a bug in the resolver or a raw DB insert can write values that the application's `match()` or switch blocks don't handle, causing silent failures at render time rather than at write time.
    - **Plain English:** The database has guardrails on most columns — "family can only be commerce or content," "refresh status must be ok, unavailable, or error." But `type` and `platform` have no guardrails at all. Anyone writing to the database directly (or a bug in the app) can put nonsense like `type = 'pizza'` in there, and nobody will notice until the sitepage breaks trying to render it.
    - **Evidence:**
        ```sql
        family                text NOT NULL CHECK (family IN ('commerce', 'content')),
        type                  text NOT NULL,       -- no CHECK
        platform              text NOT NULL,       -- no CHECK
        ...
        last_refresh_status   text CHECK (last_refresh_status IN ('ok', 'unavailable', 'error')),
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-9** · P3 — `analytics.section_views.block_id` FK column has no index
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (section_views table)
    - **Affects:** Any dashboard query that filters or joins on `block_id` — Postgres performs a sequential scan.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `CREATE INDEX CONCURRENTLY IF NOT EXISTS section_views_block_id_idx ON analytics.section_views (block_id) WHERE block_id IS NOT NULL`.
    - **Technical:** The FK `section_views_block_fk REFERENCES site.blocks(id) ON DELETE SET NULL` exists, but PostgreSQL does not auto-index FK columns. The three existing indexes cover `(user_id, occurred_at)`, `(site_id, section_key, occurred_at)`, and `(session_id, section_key)` — none cover `block_id`. Any query doing `WHERE block_id = ?` or a JOIN through `block_id` hits a seqscan. The scale concern is analytics volume across thousands of sites — this table grows proportionally to traffic.
    - **Plain English:** Every section-view event knows which block triggered it, via a `block_id` pointer. But there's no quick lookup table for that pointer — finding all views for a specific block means scanning every row in the table one-by-one. Like a library with books shelved by author but no card catalog for the illustrator field on each book.
    - **Evidence:**
        ```sql
        CONSTRAINT section_views_block_fk FOREIGN KEY (block_id) REFERENCES site.blocks(id) ON DELETE SET NULL

        CREATE INDEX section_views_professional_occurred_idx ON analytics.section_views (professional_id, occurred_at DESC);
        CREATE INDEX section_views_site_section_occurred_idx ON analytics.section_views (site_id, section_key, occurred_at DESC);
        CREATE INDEX section_views_session_section_idx ON analytics.section_views (session_id, section_key);
        -- No index on block_id
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#DINT-10** · P3 — `core.feature_flag_overrides.created_by` FK column has no index
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (feature_flag_overrides table)
    - **Affects:** Staff audit queries that look up "all overrides created by this staff member" — seqscan.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_created_by_idx ON core.feature_flag_overrides (created_by) WHERE created_by IS NOT NULL`.
    - **Technical:** The FK `feature_flag_overrides_created_by_fkey REFERENCES core.partna_staff(id) ON DELETE SET NULL` exists, but there's no index on `created_by`. The table has indexes on `(flag_key, user_id)`, `(user_id, flag_key)`, `(expires_at)`, and `(flag_key, created_at DESC)` — none cover `created_by`. Staff-facing dashboards that filter by creator hit a seqscan on what should be a small, slow-growing table, but the omission is inconsistent with the rest of the schema.
    - **Plain English:** Every feature flag override records which staff member created it. But finding "all overrides created by Alice" requires reading every override row — there's no quick lookup by creator. Like an employee directory with no index by department.
    - **Evidence:**
        ```sql
        CONSTRAINT feature_flag_overrides_created_by_fkey
            FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL

        CREATE UNIQUE INDEX feature_flag_overrides_pro_unique ON core.feature_flag_overrides (flag_key, professional_id);
        CREATE INDEX feature_flag_overrides_pro_lookup ON core.feature_flag_overrides (professional_id, flag_key) WHERE professional_id IS NOT NULL;
        CREATE INDEX feature_flag_overrides_expires_at ON core.feature_flag_overrides (expires_at) WHERE expires_at IS NOT NULL;
        CREATE INDEX feature_flag_overrides_flag_key_created ON core.feature_flag_overrides (flag_key, created_at DESC);
        -- No index on created_by
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-11** · P3 — `site.smart_links` has no `updated_at` trigger
    - **Where:** supabase/migrations/20260531000000_create_smart_links.sql
    - **Affects:** Any raw SQL update or job that touches `smart_links` outside Eloquent — `updated_at` won't advance.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add a `BEFORE UPDATE` trigger calling `public.set_updated_at()`, mirroring every other site-schema table.
    - **Technical:** The table has `updated_at timestamptz` and `created_at timestamptz` columns with no DB-level defaults or trigger. The 20260609000000 hardening migration added `SET DEFAULT now()` for timestamps on `platform_connections` — `smart_links` needs the same treatment. Eloquent's `BaseModel` may set `updated_at` automatically on save, but background jobs that use `DB::table()->update()` (e.g., the cron staleness scan) will leave `updated_at` frozen at its last Eloquent-saved value.
    - **Plain English:** Every table in the site schema has an automatic "last modified" clock that ticks on every update. Except `smart_links` — if a background job refreshes a smart link's snapshot metadata, the "last modified" timestamp doesn't move. Like a car with a speedometer that only works when the driver touches the steering wheel.
    - **Evidence:**
        ```sql
        created_at            timestamptz,
        updated_at            timestamptz,
        deleted_at            timestamptz
        -- No BEFORE UPDATE trigger defined anywhere in the migration file.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-12** · P3 — `site.platform_connections` has no `updated_at` trigger
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql; supabase/migrations/20260609000000_harden_platform_connections.sql
    - **Affects:** Same as DINT-11 — background jobs that refresh platform connection state bypass Eloquent and `updated_at` stalls.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add a `BEFORE UPDATE` trigger calling `public.set_updated_at()`.
    - **Technical:** 20260609000000 added `ALTER COLUMN created_at SET DEFAULT now()` and `ALTER COLUMN updated_at SET DEFAULT now()`, but column defaults only fire on INSERT, not UPDATE. There's no `BEFORE UPDATE` trigger. The platform refresh job (`RefreshPlatformConnectionJob` or similar) likely does `DB::table('site.platform_connections')->where(...)->update(...)` outside Eloquent, which means `updated_at` never advances. The hardening migration closed the RLS and default gaps but missed the trigger.
    - **Plain English:** Same problem as smart_links — the "last modified" clock only ticks when the web app touches the row, not when a background worker does.
    - **Evidence:**
        ```sql
        -- From 20260609000000:
        ALTER TABLE site.platform_connections
            ALTER COLUMN created_at SET DEFAULT now(),
            ALTER COLUMN updated_at SET DEFAULT now();
        -- No BEFORE UPDATE trigger created.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-13** · P3 — `site.site_media.pool` CHECK includes dead `'brand_gallery'` value
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql (site_media_pool_check)
    - **Affects:** No functional impact — the value is in the CHECK allowlist but no application code writes it. Carries confusion risk for future developers.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Drop and recreate the CHECK constraint without `'brand_gallery'`, then validate. Or leave a comment documenting it as intentionally dead vocabulary from the pre-standalone era.
    - **Technical:** The pool CHECK enumerates `'gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'`. The `'brand_gallery'` value was for the brand/commerce model (brand-curated media shown on affiliate pages). With the standalone strip, the `brand` schema is gone and no application code references `'brand_gallery'` as a pool value. It's dead vocabulary — harmless but misleading. A future engineer might see it and assume brand galleries are supported.
    - **Plain English:** The database says "pool can be gallery, content, design, product, brand_gallery, or documents." But `brand_gallery` no longer exists anywhere in the application — it's a leftover label from the old e-commerce features that were removed. Like a light switch on the wall that isn't connected to anything.
    - **Evidence:**
        ```sql
        CONSTRAINT site_media_pool_check CHECK (pool IN (
            'gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'
        ))
        ```
    - `[DRAFT, confidence: 0.90]`
