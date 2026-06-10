`★ Insight ─────────────────────────────────────`
The DeepSeek draft flagged DATA-7 (booking_events GDPR) and DATA-8 (email_subscriptions GDPR) as uncovered — but the actual `RedactCustomerJob` already handles both. Always verify against the application code, not just the schema. Similarly, DATA-5 (category text column) is effectively dead code — the `public_site_payload` view derives category exclusively from `service_categories.title` via `category_id`, ignoring the `category` text column entirely.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql
- supabase/migrations/20260427000000_add_missing_fk_indexes.sql
- supabase/migrations/20260505000001_create_brand_status_history.sql
- supabase/migrations/20260403000000_v2_baseline.sql (site_media / blocks / email_subscriptions)
- supabase/migrations/20260407000000_billing_stripe_integration.sql
- supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Jobs/Shopify/Gdpr/RedactShopJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#DATA-1** · P2 — Missing index on FK `site.sites.theme_id`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql — `sites` table FK declaration
    - **Affects:** Any admin operation that deletes or replaces a theme triggers a full sequential scan of `site.sites` to null-out the `theme_id` column. Theme deletion is rare today, but the sites table grows one row per professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial B-tree index: `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sites_theme_id ON site.sites (theme_id) WHERE theme_id IS NOT NULL;`
    - **Technical:** Category (1). The FK `sites_theme_fk` carries `ON DELETE SET NULL`, meaning Postgres must locate every row in `site.sites` that references the deleted theme in order to null the column. Without an index on `theme_id`, this is a full sequential scan. Postgres never auto-creates indexes on FK columns. The partial form (`WHERE theme_id IS NOT NULL`) stays compact because the trigger `set_default_theme_on_sites` ensures new sites always get a theme, making null rows the exception rather than the rule.
    - **Plain English:** When a theme is removed from the platform, the database has to check every user's site to see if it was using that theme. Right now it does that by reading every site row one by one. An index lets it jump directly to the affected rows instead of reading the whole book.
    - **Evidence:**
        ```sql
        ALTER TABLE ONLY site.sites
            ADD CONSTRAINT sites_theme_fk FOREIGN KEY (theme_id) REFERENCES site.themes(id) ON DELETE SET NULL;

        -- No corresponding CREATE INDEX on site.sites(theme_id) anywhere in the migration set.
        CREATE UNIQUE INDEX sites_professional_unique ON site.sites (professional_id);
        CREATE UNIQUE INDEX core_sites_subdomain_lower_unique ON site.sites (lower(subdomain));
        ```

- [ ] **#DATA-2** · P2 — Missing standalone index on FK `commerce.affiliate_product_selections.brand_professional_id`
    - **Where:** supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql
    - **Affects:** Brand-professional hard-delete cascade must scan the entire `affiliate_product_selections` table by brand; also penalises any query filtering only by brand (not the composite affiliate-first index).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_aps_brand_professional_id ON commerce.affiliate_product_selections (brand_professional_id);`
    - **Technical:** Category (1). The existing index `affiliate_product_selections_brand_idx` is composite on `(affiliate_professional_id, brand_professional_id)`. Postgres can only use a composite index efficiently when the leading column is also in the filter. An `ON DELETE CASCADE` from `core.professionals` on `brand_professional_id` looks up rows by that column alone — the composite index is useless for this, producing a full table scan. The brand-scoped RLS `product_selections_brand_select` policy (`WHERE brand_professional_id = (SELECT id ...)`) has the same problem.
    - **Plain English:** The existing index is like a phone book sorted by affiliate name first, then brand name. Finding all rows for a given brand means reading the whole directory. A separate index sorted by brand name alone gives the database a direct page reference.
    - **Evidence:**
        ```sql
        ALTER TABLE commerce.affiliate_product_selections
            ADD COLUMN IF NOT EXISTS brand_professional_id uuid
                REFERENCES core.professionals(id) ON DELETE CASCADE;

        CREATE INDEX IF NOT EXISTS affiliate_product_selections_brand_idx
            ON commerce.affiliate_product_selections (affiliate_professional_id, brand_professional_id);
        -- No standalone index on (brand_professional_id) alone.
        ```

- [ ] **#DATA-3** · P2 — `site.blocks.block_type` stored as free-text with no CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql — `site.blocks` table definition
    - **Affects:** Invalid `block_type` values can be persisted and later cause rendering failures on public sites; the public-site-payload view returns `block_type` verbatim to Hydrogen.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint enumerating the allowed types. Confirm the current set from `app/Enums/` or the dashboard's block-type picker before writing the constraint, then: `ALTER TABLE site.blocks ADD CONSTRAINT blocks_block_type_check CHECK (block_type IN ('link', 'service', 'contact', 'gallery', 'about', ...));`
    - **Technical:** Category (4). `block_group` already has a CHECK constraint (`links` | `sections`), but the sibling `block_type` column — which drives rendering — does not. A bug, a direct DB write, or a future code path could store an unrecognised type that the Hydrogen theme has no component for, silently rendering nothing on the public site. Adding the CHECK at the DB layer means any drift from the application enum fails loudly at insert time rather than silently at render time.
    - **Plain English:** The block type tells the website how to draw each section — "this is a link block," "this is a gallery," etc. Right now the database will happily store a typo like "galleryy" and the site will just show nothing for that block with no error. Adding a whitelist at the database level means bad data is rejected at the door.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.blocks (
            -- …
            block_type text DEFAULT 'link' NOT NULL,
            -- …
            CONSTRAINT link_blocks_block_group_check CHECK (block_group = ANY (ARRAY['links', 'sections']))
            -- No corresponding CHECK on block_type.
        );
        ```

- [ ] **#DATA-4** · P2 — `site.site_media.pool` stored as varchar with no CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql — `site.site_media` table definition
    - **Affects:** A typo like `'galery'` would be stored silently and become invisible to every fetch query that filters by `pool`; the media would be orphaned on ingest with no error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.site_media ADD CONSTRAINT site_media_pool_check CHECK (pool IN ('gallery', 'content', 'design', 'documents', 'product', 'brand_gallery'));`
        - Cross-reference against `config/sidest.php` image_pools keys to ensure the list is complete before adding.
    - **Technical:** Category (4). The `media_type` and `processing_state` columns both have CHECK constraints; `pool` — the column that partitions all media serving — does not. Application code uses `pool` in every media read path (`sm_pool_active`, `sm_pool_media_active` indexes, `public_site_payload` view subqueries, `BrandDesignMediaService`). A misspelled pool value bypasses all of these silently. Note that `purpose` (added in `20260415120000`) also has no CHECK; add one there too (`logo_full`, `logo_square`, `placeholder`) if the design pool is locked down.
    - **Plain English:** Media files are sorted into "pools" — gallery, content, design, etc. — like folders. Without a whitelist rule, a mistyped folder name creates a hidden folder that the website never looks in. The file appears to upload successfully but never shows up anywhere.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.site_media (
            -- …
            pool varchar(20) NOT NULL DEFAULT 'gallery',
            -- …
            CONSTRAINT site_media_media_type_check CHECK (media_type IN ('image', 'video')),
            CONSTRAINT site_media_processing_state_check CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed'))
            -- No CHECK on pool.
        );
        ```

- [ ] **#DATA-5** · P2 — `core.brand_status_history` CASCADE deletes the audit trail on professional hard-delete
    - **Where:** supabase/migrations/20260505000001_create_brand_status_history.sql
    - **Affects:** The full lifecycle audit of a brand's status transitions (onboarding → shopify_linked → ready_for_affiliates etc.) is permanently destroyed the moment a professional is hard-purged, eliminating any post-deletion forensic capability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the FK: `ALTER TABLE core.brand_status_history DROP CONSTRAINT brand_status_history_pkey_...; ALTER TABLE core.brand_status_history ALTER COLUMN professional_id DROP NOT NULL; ALTER TABLE core.brand_status_history ADD CONSTRAINT brand_status_history_professional_fk FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;`
        - Add a `professional_handle_snapshot text` column populated at insert time (matching the pattern in `core.professional_deletion_audit`) so post-deletion rows remain attributable.
    - **Technical:** Category (2/1). Every other audit/history table in the codebase that must survive a professional purge uses `ON DELETE SET NULL` — `core.professional_deletion_audit` (`20260419000001`), `core.gdpr_requests` (`20260423000001`), `core.data_export_audit` (`20260425000002`) all use `ON DELETE SET NULL` with `*_snapshot` columns for identity. `brand_status_history` is structurally identical in purpose but uses `ON DELETE CASCADE`, contradicting the established pattern. A purge of a soft-deleted professional (30-day retention + `PurgeDeletedProfessionalsJob`) silently voids the entire status transition log, which may be needed for support investigations or regulatory audits.
    - **Plain English:** When a brand account is permanently closed, we keep a paper trail of things like deletion requests and data exports — but we accidentally shred the brand's entire status history (every time their account moved from "onboarding" to "live" etc.). Other audit logs in the system are designed to survive account deletion; this one wasn't given the same treatment.
    - **Evidence:**
        ```sql
        -- brand_status_history uses CASCADE (destroys the audit trail on purge):
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            from_status VARCHAR(50),
            to_status VARCHAR(50) NOT NULL,
            reason VARCHAR(100),
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        -- Contrast: professional_deletion_audit correctly uses SET NULL + snapshot columns:
        CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
            professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
            professional_handle_snapshot text NOT NULL,
            professional_email_snapshot text NOT NULL,
            …
        );
        ```

- [ ] **#DATA-6** · P2 — Global `notifications.email_subscriptions` rows have no GDPR deletion path
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (email_subscriptions) + app/Jobs/Shopify/Gdpr/RedactCustomerJob.php:104–108
    - **Affects:** Platform-wide marketing subscribers (`professional_id IS NULL`) who submit a GDPR right-to-erasure request have their data retained indefinitely; the Shopify GDPR webhook covers only professional-scoped rows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a staff-triggered or self-service endpoint that deletes `email_subscriptions` rows by `email_lc` regardless of `professional_id` (including NULL).
        - Alternatively, add a DELETE step to `RedactCustomerJob` for the global list: `->whereNull('professional_id')->where('email_lc', $emailLc)->delete()`.
        - Add a similar step to `RedactShopJob` for subscriptions tied to a shop's professional that a shop-level redact should also clean up.
        - Add a test asserting that a `customers/redact` webhook removes the subscriber's global-list row.
    - **Technical:** Category (7). `RedactCustomerJob` deletes `notifications.email_subscriptions` only where `professional_id = $professionalId AND email_lc = $emailLc` (line 104–108). This correctly handles per-professional mailing list subscriptions. However, the table allows `professional_id IS NULL` for global platform marketing subscriptions, and those rows are never touched by any GDPR redaction path. The `unsubscribe_token` mechanism grants opt-out rights but not erasure — under GDPR Article 17 these are distinct obligations. The `RedactShopJob` similarly omits a corresponding shop-scoped email subscription cleanup.
    - **Plain English:** When someone signs up for Partna's own platform newsletter (not a specific brand's list), their name and email are stored without any way to fully delete them if they later ask us to. They can unsubscribe from future emails, but their details still sit in the database. Unsubscribing is like being taken off a mailing list — data deletion is shredding the card entirely. We need to shred it on request.
    - **Evidence:**
        ```sql
        -- Table allows NULL professional_id (platform-wide subscribers):
        CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
            email text NOT NULL,
            full_name text,
            professional_id uuid,  -- NULL = global platform marketing subscription
            …
        );
        ```
        ```php
        // RedactCustomerJob.php:104–108 — professional_id filter excludes global rows:
        $deletedSubs = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('professional_id', $professionalId)   // ← NULL-professional_id rows skipped
            ->where('email_lc', $emailLc)
            ->delete();
        ```

- [ ] **#DATA-7** · P2 — Missing `updated_at` auto-update triggers on 13 mutable tables
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (trigger bindings section) and subsequent migration files
    - **Affects:** Out-of-band updates (queue workers, direct DB writes, Supabase dashboard) leave `updated_at` stale, breaking cache-invalidation logic that keys on the timestamp, and making `updated_at`-based audit queries unreliable.
    - **Effort:** M (~2–4h) — one trigger per table, ~13 total
    - **What to do:**
        - Add `BEFORE UPDATE … FOR EACH ROW EXECUTE FUNCTION public.set_updated_at()` triggers for each of the following tables:
            - `site.services`
            - `site.enquiries`
            - `brand.brand_profiles`
            - `brand.brand_partner_links`
            - `brand.brand_affiliate_invites`
            - `brand.brand_store_settings`
            - `commerce.affiliate_product_selections`
            - `notifications.notifications`
            - `notifications.notification_receipts`
            - `notifications.notification_email_preferences`
            - `notifications.notification_email_policies`
            - `notifications.email_subscriptions`
            - `core.gdpr_requests`
    - **Technical:** Category (5). The baseline migration establishes the `public.set_updated_at()` function and applies it to a core set of tables (`professionals`, `customers`, `sites`, `blocks`, `site_media`, `commission_ledger_entries`, billing tables, etc.). A sizeable set of mutable tables with `updated_at timestamptz DEFAULT now()` columns was created without corresponding trigger bindings. Eloquent's ORM sets `updated_at` in PHP on model saves, but raw `DB::update()` calls, queue-job bulk updates (like `RedactCustomerJob`'s `->update()`), trigger-fired side effects, and Supabase dashboard edits all bypass Eloquent. The `CacheLockService` and SWR cache (CLAUDE.md: "push-invalidated on every commerce write") depend on freshness signals that can silently stale under this gap.
    - **Plain English:** Each piece of data has a "last changed" timestamp. The app normally updates it when it saves something. But some operations — background jobs, database scripts, admin dashboard edits — bypass the app and directly change the database. In those cases the timestamp stays at its old value, making caches think nothing changed. Installing a database-level clock-updater on these tables ensures the timestamp advances no matter who changes the row.
    - **Evidence:**
        ```sql
        -- site.services has updated_at but no trigger in the migration set:
        CREATE TABLE IF NOT EXISTS site.services (
            …
            updated_at timestamptz DEFAULT now() NOT NULL,
            …
        );

        -- Compare with site.blocks, which correctly has a trigger:
        CREATE OR REPLACE TRIGGER set_timestamp_link_blocks
            BEFORE UPDATE ON site.blocks
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

        -- brand.brand_store_settings also has updated_at but no trigger binding:
        CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
            …
            updated_at timestamptz NOT NULL DEFAULT now(),
            …
        );
        ```

---

## P3 — Nice to have

- [ ] **#DATA-8** · P3 — `billing.subscriptions.status` has no CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql — `billing.subscriptions` table definition
    - **Affects:** Invalid Stripe status strings (e.g., from a new Stripe API version introducing a new lifecycle state, or a typo in the webhook handler) are stored silently; subscription-gating logic that switches on `status` will enter an unhandled branch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Enumerate the current Stripe subscription statuses and add: `ALTER TABLE billing.subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'incomplete', 'incomplete_expired', 'past_due', 'canceled', 'unpaid', 'paused'));`
        - Cross-reference against the Stripe API version pinned in `STRIPE_API_VERSION` env to ensure completeness.
    - **Technical:** Category (4). Every other status/type column in the billing schema has a CHECK constraint (`plans.billing_interval` implicitly through the seed data shape, `bct_status_check`, `cp_status_check`). `billing.subscriptions.status` is the only column that drives subscription entitlement checks and is left unconstrained. Contrast with `billing.plans` which has a typed enum and the `billing_one_current_sub_per_professional` partial index which assumes `ended_at IS NULL` — a corrupt status could break that invariant silently.
    - **Plain English:** The subscription status field tells the system whether someone is an active paying user, in a trial, or lapsed. Without a whitelist, a malformed status value from Stripe (or a future Stripe API change) gets stored unchecked, and the system may give access to users who shouldn't have it or deny access to paying users. A one-line rule at the database level prevents this class of bug.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS billing.subscriptions (
            …
            status text NOT NULL,
            -- No CHECK constraint on status.
            …
        );

        -- Contrast: commission_payouts has an explicit status check:
        CONSTRAINT cp_status_check CHECK (status IN (
            'pending', 'pending_funds', 'collecting', 'collected',
            'transferring', 'completed', 'failed', 'cancelled', 'reversed'
        ))
        ```

- [ ] **#DATA-9** · P3 — `core.wallet_currency_switch_audit.topup_id` is a soft FK with no DB referential constraint
    - **Where:** supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql
    - **Affects:** If a `brand_commission_topup` row is deleted (or never existed for a legacy row), the `topup_id` UUID in the audit log points nowhere; there is no way to join the audit row back to its originating topup at query time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.wallet_currency_switch_audit ADD CONSTRAINT wallet_currency_switch_audit_topup_fk FOREIGN KEY (topup_id) REFERENCES commerce.brand_commission_topups(id) ON DELETE SET NULL;`
        - Add a supporting index: `CREATE INDEX wallet_currency_switch_audit_topup_idx ON core.wallet_currency_switch_audit (topup_id) WHERE topup_id IS NOT NULL;`
    - **Technical:** Category (3). The `topup_id` column is declared `uuid` (nullable) with a comment `-- BrandCommissionTopup that triggered the switch` but no `REFERENCES` clause. This is an application-layer soft FK — Postgres has no knowledge of the relationship. `brand_commission_topups` uses `ON DELETE RESTRICT` for its `brand_professional_id` FK, so topups themselves can't be deleted while professionals exist, limiting the practical orphan risk today. But there is also no index on `topup_id`, so a "show me the currency switch that resulted from this topup" query requires a full scan of the audit table.
    - **Plain English:** The audit log notes which top-up payment triggered each currency switch by storing an ID number, but doesn't tell the database "this ID number must refer to a real top-up." It's like writing a reference number on a form but not checking that the reference number exists in the filing cabinet. If the top-up record were ever cleaned up, the audit log would have a dangling reference. Adding a formal link and a lookup index makes future reconciliation queries reliable.
    - **Evidence:**
        ```sql
        create table core.wallet_currency_switch_audit (
            id               uuid        primary key default gen_random_uuid(),
            professional_id  uuid        not null references core.professionals(id) on delete cascade,
            previous_currency char(3)    not null,
            new_currency      char(3)    not null,
            actor_type        text        not null,
            actor_id          uuid,
            topup_id          uuid,       -- BrandCommissionTopup that triggered the switch (no FK)
            metadata          jsonb,
            created_at        timestamptz not null default now()
        );
        ```
