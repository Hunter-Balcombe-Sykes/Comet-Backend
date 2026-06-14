
<!-- ═══ LENS: data-integrity | CHUNK: schema ═══ -->

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

<!-- ═══ LENS: data-integrity | CHUNK: models-gdpr ═══ -->

- [ ] **#DINT-1** · P2 — `LeadSubmission` model has no `created_at` population path; rows land with NULL timestamps
    - **Where:** app/Models/Analytics/LeadSubmission.php:10-29
    - **Affects:** Every `analytics.lead_submissions` row — time-based retention queries, the analytics purge command, and any dashboard that relies on `created_at` for ordering or windowing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'created_at' => 'datetime'` to `$casts` so the column is cast correctly (mirrors `LinkClick`, `SectionView`, `SiteVisit`).
        - Confirm the migration has a `DEFAULT now()` on `created_at`, or add a `static::creating()` hook that stamps it — otherwise every row lands with NULL and the scheduled `partna:analytics:purge-raw-events` retention window can never match.
    - **Technical:** `LeadSubmission` sets `public $timestamps = false;` (Laravel skips automatic `created_at`/`updated_at` management) but unlike the other three analytics models it does not list `created_at` in `$casts`. It is not in `$fillable` either, so even if application code tries to set it, mass-assignment protection may silently drop the value. The column will be NULL on every insert unless a DB DEFAULT exists — and if the DEFAULT was added in the migration it is invisible to the model layer's type system because no cast is declared.
    - **Plain English:** Imagine a warehouse where every incoming box is supposed to get a date-stamp so you know when it arrived. Three of the four loading docks have a working date-stamper; the fourth doesn't. Boxes pile up there with no arrival date. When it's time to clear inventory older than 90 days, you can't tell how old those boxes are — so they either get deleted too early (data loss) or never get deleted (storage bloat).
    - **Evidence:**
        ```php
        // app/Models/Analytics/LeadSubmission.php

        // analytics tables don't have updated_at
        public $timestamps = false;

        protected $fillable = [
            'occurred_at',
            'subdomain',
            'site_id',
            'user_id',
            'customer_id',
            'ip_hash',
            'user_agent',
            'referrer',
            'outcome',
            'form_started_at_ms',
        ];

        protected $casts = [
            'occurred_at' => 'datetime',
        ];
        ```
        ```php
        // Compare with app/Models/Analytics/LinkClick.php which DOES include it:
        protected $casts = [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-2** · P2 — Email uniqueness enforced only in application code; no DB-level unique constraint visible
    - **Where:** app/Services/User/UserBootstrapService.php:108-117
    - **Affects:** New user registration — a direct DB write, a migration backfill, or a race between two concurrent bootstrap requests can create two `core.users` rows with the same `primary_email`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial unique index: `CREATE UNIQUE INDEX CONCURRENTLY ... ON core.users (LOWER(primary_email)) WHERE deleted_at IS NULL;` so the constraint respects soft-deletes.
        - Keep the application-level guard as a fast-path 409 but treat the index as the safety net.
    - **Technical:** `UserBootstrapService::guardAgainstEmailReuseByDifferentAuthUser()` runs a `SELECT EXISTS(...)` inside a transaction before inserting. Two concurrent requests can both pass the SELECT, both insert, and both commit — the transaction isolation level (Read Committed) does not prevent this phantom. The guard is a best-effort check, not a constraint. The lens explicitly calls out "uniqueness enforced only via `->unique()` in a Form Request" as a finding; a service-layer SELECT is the same class of gap.
    - **Plain English:** It's like checking whether a seat is taken by looking at it from the aisle before walking down the row. If two people look at the same moment from opposite ends of the plane, they both see it empty and both try to sit. A DB unique constraint is the equivalent of an assigned seat on the ticket — the database itself rejects the second person regardless of timing.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
        {
            $emailLc = strtolower(trim($email));
            if ($emailLc === '') {
                return;
            }

            $existingByEmail = User::query()
                ->whereRaw('lower(primary_email) = ?', [$emailLc])
                ->where('auth_user_id', '!=', $uid)
                ->exists();

            if ($existingByEmail) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-3** · P2 — `ConfirmationPreferenceService` uses `updateOrCreate` / `updateOrCreate` without a visible DB unique constraint on `(user_id, action_key)`
    - **Where:** app/Services/User/ConfirmationPreferenceService.php:45-54, 59-67
    - **Affects:** `core.user_confirmation_preferences` — concurrent "don't ask again" toggles or a rapid double-click on delete-media confirmation can create duplicate rows. The service reads via `pluck('skip_confirmation', 'action_key')` which picks the last row for a given key, so duplicate data is silently non-deterministic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `UNIQUE(user_id, action_key)` on `core.user_confirmation_preferences` (with `WHERE deleted_at IS NULL` if the table uses soft-deletes, which it currently does not — the model has no `SoftDeletes` trait).
        - The transaction in `updateForProfessional` already serialises writes per user; the unique constraint protects against the transaction-less `enableForProfessional` path.
    - **Technical:** `updateOrCreate` in Laravel performs a SELECT-then-INSERT-or-UPDATE cycle. Under Read Committed isolation, two concurrent calls can both SELECT zero rows, both proceed to INSERT, and the second INSERT succeeds alongside the first if no unique constraint blocks it. The natural key `(user_id, action_key)` is exactly the lookup tuple used by `getForProfessional`, so the DB must guard it.
    - **Plain English:** Two browser tabs both check the "don't ask again" box at roughly the same time. Without a database rule that says "only one preference per action per user," both tabs succeed and now there are two rows. The next time the preference is read, one of them wins arbitrarily — the user's actual choice is buried under a coin flip.
    - **Evidence:**
        ```php
        // app/Services/User/ConfirmationPreferenceService.php
        // updateForProfessional() — inside DB::transaction()
        UserConfirmationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'action_key' => $actionKey],
            ['skip_confirmation' => $skipConfirmation]
        );

        // enableForProfessional() — NO transaction wrapper
        UserConfirmationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'action_key' => $actionKey],
            ['skip_confirmation' => true]
        );
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-4** · P2 — `UserBootstrapService::createWelcomeNotification` uses `firstOrCreate` without a DB unique constraint on `(user_id, type, title)`
    - **Where:** app/Services/User/UserBootstrapService.php:139-152
    - **Affects:** New user onboarding — two near-simultaneous bootstrap requests (e.g., app and web client racing after Supabase signup) can create duplicate welcome notifications. The user sees two identical "Welcome to Partna" messages in their dashboard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `UNIQUE(user_id, type, title)` (or `UNIQUE(user_id, type)` if the intent is one Info notification per user) on `notifications.notifications`.
        - Alternatively, make the notification creation idempotent by checking existence with a lock before insert, matching the `DataExportService` dedup pattern.
    - **Technical:** `firstOrCreate` is a SELECT + INSERT pair. Under concurrency, the same gap as DINT-3 applies. The impact is lower (duplicate welcome messages are cosmetic, not data corruption), but the pattern itself is a constraint-in-code-only anti-pattern that should be fixed consistently across the codebase.
    - **Plain English:** It's the "Welcome to Partna" greeting card. When a user signs up, the system puts one in their inbox. But if the signup flow fires twice (maybe the mobile app and the website both try to finish setup at the same moment), two identical cards land in the inbox. It's not harmful, but it's untidy and erodes the polished first impression the product aims for.
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
                    'body' => 'Your account is ready. Complete your profile...',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#DINT-5** · P2 — `EmailSubscription::insertOrIgnore` uses PostgreSQL `ON CONFLICT DO NOTHING` without an explicit conflict target; idempotency depends on any unique index matching by coincidence
    - **Where:** app/Services/User/UserBootstrapService.php:124-137
    - **Affects:** `notifications.email_subscriptions` — if the table's unique constraint is on a column set that doesn't fully overlap with the INSERT columns, or if the constraint is dropped during a migration, duplicate `sidest_updates` subscription rows appear. The user's subscription state becomes ambiguous.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the migration has a `UNIQUE(list_key, email_lc)` (or equivalent) that matches the dedup intent.
        - If constraint exists, add a comment above the `insertOrIgnore` call documenting which constraint provides the idempotency guarantee, so future schema changes don't accidentally remove it.
        - Consider using `updateOrInsert` with an explicit key tuple for self-documenting intent.
    - **Technical:** Laravel's `insertOrIgnore` compiles to `INSERT ... ON CONFLICT DO NOTHING` in PostgreSQL. Without an `ON CONFLICT (col1, col2)` target clause, Postgres relies on ANY unique constraint violation to trigger the ignore. This works but is fragile — if the only matching unique index is on `id` (the UUID PK, which never collides), every call inserts a new row and `insertOrIgnore` silently degrades to a plain INSERT. An explicit `ON CONFLICT (list_key, email_lc)` in a raw statement would be self-documenting.
    - **Plain English:** The system wants to make sure every user gets exactly one "product updates" subscription. It says "add this subscription, but if something already exists that conflicts, just skip it." The problem: it doesn't specify WHAT should conflict. If the database's uniqueness rule ever changes (or doesn't exist yet), the system silently creates duplicates, and the user starts getting two copies of every update email.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            // ...
            EmailSubscription::insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => null,
                'list_key' => 'sidest_updates',
                'email' => $email,
                'email_lc' => $email,
                'status' => 'subscribed',
                'subscribed_at' => $now,
                'consent_source' => 'bootstrap',
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-6** · P2 — `GdprRequest` model still references `core.gdpr_requests` table; deprecated Shopify GDPR webhook store with potential orphaned PII
    - **Where:** app/Models/Core/Gdpr/GdprRequest.php:11-65
    - **Affects:** Any leftover rows in `core.gdpr_requests` from the pre-standalone era — the `payload` column (`$casts = ['payload' => 'array']`) can contain Shopify customer emails, shop domains, and request IDs. No export or deletion wiring covers this table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the table still exists in the DB and has rows: write a one-time purge migration that NULLs the `payload` column (or drops the table entirely) since Shopify GDPR webhooks are removed.
        - If the table is already dropped: delete the `GdprRequest` model class and any remaining references to it — dead code that imports a PII-carrying model is a ticking compliance bomb.
        - Verify no controller or job still queries `GdprRequest`.
    - **Technical:** The architectural notes state "Shopify GDPR webhooks are removed. GDPR work is now first-party." The `DataExportAudit` table was moved to `audit.data_export_audit` in the schema reorganisation migration, but `GdprRequest` was left behind pointing at `core.gdpr_requests`. The `payload` column is cast as `array` and can contain PII from Shopify webhook deliveries (customer email addresses, shop domains). If the table still holds rows, they are outside the data export payload builder AND the account deletion service — meaning a GDPR Article 15 or Article 17 request would miss them entirely.
    - **Plain English:** The platform used to connect to Shopify, and Shopify would send GDPR requests (like "delete this customer's data"). Those requests were stored in a database table along with the customer's actual email address and shop details. Shopify is gone now, but the table — and any old rows in it — might still be sitting there. If a user asks "what data do you have on me?" the system won't look in that table. It's like an old filing cabinet full of customer records that everyone forgot about in a storage closet.
    - **Evidence:**
        ```php
        // app/Models/Core/Gdpr/GdprRequest.php
        // V2: Audit row for Shopify GDPR webhooks. payload_hash unique index provides
        // idempotency against Shopify retries...
        class GdprRequest extends BaseModel
        {
            use HasUuids;

            public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';
            public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';
            public const TOPIC_SHOP_REDACT = 'shop/redact';
            // ...
            protected $table = 'core.gdpr_requests';   // <-- NOT audit.*, not covered by export/deletion

            protected $casts = [
                'payload' => 'array',                   // <-- can contain customer PII
                // ...
            ];
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#DINT-7** · P3 — `FeatureFlagOverride` hand-rolls UUID generation instead of using the `HasUuids` trait; inconsistent with every other UUID-keyed model
    - **Where:** app/Models/Core/FeatureFlagOverride.php:24-28
    - **Affects:** Any code path that inserts a `FeatureFlagOverride` via `insert()` (bypassing Eloquent events) — the `id` column will be empty, causing a constraint violation or a row with an empty-string PK.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual `booted()` UUID logic with `use HasUuids;` to match the rest of the codebase.
        - Set `protected $keyType = 'string';` and `public $incrementing = false;` (already present — keep).
    - **Technical:** Every other UUID-keyed model in the codebase (`User`, `Site`, `Block`, `Enquiry`, `Customer`, `Service`, `SmartLink`, `LeadSubmission`, `LinkClick`, `SectionView`, `SiteVisit`, `MediaVariant`, `Notification`, `Feedback`, etc.) uses `HasUuids`. `FeatureFlagOverride` is the sole holdout that manually generates a UUID in a `creating` event. The `HasUuids` trait also hooks into `creating` but additionally sets `$incrementing = false` and `$keyType = 'string'` on boot — the manual approach duplicates this config but without the trait's static analysis guarantees. If a future developer copies the pattern without also setting `$incrementing` and `$keyType`, they get silent auto-increment behaviour on a string column.
    - **Plain English:** Every ID card printer in the building uses the same machine — except one desk where someone writes IDs by hand. It works, but if that person leaves and someone else tries to use the same desk with the standard machine, it jams. Consistency across models means fewer surprises when someone new reads the code.
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
        ```
        ```php
        // canonical pattern used by all other UUID models:
        // app/Models/Analytics/LeadSubmission.php
        use HasUuids;
        // (no manual booted UUID logic needed)
        ```
    - `[DRAFT, confidence: 0.95]`
