`★ Insight ─────────────────────────────────────`
**Key additional discoveries from file inspection:**
- `PurgeRawAnalyticsEvents` covers `link_clicks`, `site_visits`, `lead_submissions` (good) but not `booking_events` or `cart_events` — neither is a PII concern post-redact, so no new finding there.
- `site.enquiries` uses `SoftDeletes` trait but is **absent** from `PurgeSoftDeleted::handle()` — confirmed PII accumulation gap (name, email, phone, message from real contact-form visitors).
- Three `core.*` tables created after `20260420200000` (the mass-RLS migration) never received `ENABLE ROW LEVEL SECURITY`: `professional_deletion_audit` (contains `professional_email_snapshot`!), `wallet_currency_switch_audit`, `brand_status_history`.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql
- supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql
- supabase/migrations/20260422040000_create_site_enquiries.sql
- supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql
- supabase/migrations/20260505000001_create_brand_status_history.sql
- supabase/migrations/20260506000000_create_orders_schema.sql
- supabase/migrations/20260506600000_rename_ledger_to_movements.sql
- supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql
- app/Console/Commands/PurgeSoftDeleted.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Models/Core/Site/Enquiry.php
- (25 additional migration files verified inline)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — Three `core.*` audit tables created after the mass-RLS migration have no Row Level Security
    - **Where:** supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql (professional_deletion_audit), supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql, supabase/migrations/20260505000001_create_brand_status_history.sql
    - **Affects:** Any authenticated Supabase JWT user — via PostgREST — can read all rows from these tables for all professionals/brands. `professional_deletion_audit.professional_email_snapshot` is direct PII of deleted accounts visible cross-tenant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration that enables RLS and adds policies for all three tables:
            - `core.professional_deletion_audit`: `ENABLE ROW LEVEL SECURITY` + staff-only select (no professional policy — the professional is deleted) + app_backend passthrough via `BYPASSRLS`.
            - `core.wallet_currency_switch_audit`: `ENABLE ROW LEVEL SECURITY` + tenant-scoped select (`professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)`) + staff-all policy.
            - `core.brand_status_history`: `ENABLE ROW LEVEL SECURITY` + tenant-scoped select + affiliate-via-link select (brand partner can see their brand's status) + staff-all policy.
        - Follow the same pattern as `core.data_export_audit` (20260425000002) which correctly gets RLS and explicit `GRANT … TO app_backend`.
    - **Technical:** The mass-RLS migration `20260420200000_add_rls_to_remaining_tables.sql` enumerated every table that existed at that point. Three tables were created afterward — `professional_deletion_audit` (20260419000001, same day but different sequence), `wallet_currency_switch_audit` (20260504200000), and `brand_status_history` (20260505000001) — and none triggered a follow-up RLS migration. Since `app_backend` holds `BYPASSRLS`, the Laravel backend is unaffected. But PostgREST (Supabase's auto-generated REST API), which is what JWT-authenticated clients hit, applies the authenticated role — which has no policy, meaning Postgres defaults to allow-all for tables without RLS enabled. Any professional with a valid JWT can `GET /rest/v1/professional_deletion_audit` and receive every row, including `professional_email_snapshot` values for users who requested deletion. The sister table `data_export_audit` was created in the same period (20260425000002) and correctly includes `ENABLE ROW LEVEL SECURITY` — the pattern exists, it just wasn't applied to these three.
    - **Plain English:** Imagine you have a hotel where three back-office filing rooms were never fitted with locks. Every guest who has a room key can walk in and read the contents — including the folder labelled "Guest Deletion Requests" that contains names and email addresses of people who asked to have their data erased. The rest of the hotel is locked correctly. These three rooms just got built after the locksmith finished and nobody went back to add locks. A one-morning fix covers all three rooms.
    - **Evidence:**
        ```sql
        -- 20260505000001_create_brand_status_history.sql
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            from_status VARCHAR(50),
            to_status VARCHAR(50) NOT NULL,
            reason VARCHAR(100),
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        -- No ALTER TABLE core.brand_status_history ENABLE ROW LEVEL SECURITY; follows.

        -- 20260504200000_create_wallet_currency_switch_audit.sql
        create table core.wallet_currency_switch_audit (
            id               uuid        primary key default gen_random_uuid(),
            professional_id  uuid        not null references core.professionals(id) on delete cascade,
            previous_currency char(3)    not null,
            new_currency      char(3)    not null,
            actor_type        text        not null,
            actor_id          uuid,
            topup_id          uuid,
            metadata          jsonb,
            created_at        timestamptz not null default now()
        );
        -- No ENABLE ROW LEVEL SECURITY follows.

        -- 20260419000001_add_deletion_fields_to_professionals.sql
        CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
            professional_handle_snapshot text NOT NULL,
            professional_email_snapshot text NOT NULL,
            event text NOT NULL CHECK (event IN ('requested', 'confirmed', 'cancelled', 'purged', 'purge_failed')),
            ...
        );
        ALTER TABLE core.professional_deletion_audit OWNER TO postgres;
        -- No ENABLE ROW LEVEL SECURITY follows.

        -- Compare: data_export_audit (20260425000002) has it right:
        ALTER TABLE core.data_export_audit ENABLE ROW LEVEL SECURITY;
        CREATE POLICY data_export_audit_app_backend_all ON core.data_export_audit FOR ALL TO app_backend ...
        ```

- [ ] **#DATA-2** · P1 — `site.enquiries` PII accumulates indefinitely — model is absent from `PurgeSoftDeleted`
    - **Where:** app/Console/Commands/PurgeSoftDeleted.php:31–33; supabase/migrations/20260422040000_create_site_enquiries.sql
    - **Affects:** Visitor PII (name, email, phone, subject, message) from contact-form submissions. When a professional soft-deletes an enquiry (marks it read and removes it from view), the row is never hard-deleted. Over years, deleted contact-form records from visitors who may have submitted and forgotten accumulate without end.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Enquiry::class` to the `purgeModel` calls in `PurgeSoftDeleted::handle()`, matching the existing pattern for `Customer`, `Service`, and `SiteMedia`.
        - Confirm `Enquiry` imports `SoftDeletes` and that `onlyTrashed()` returns the correct scope — both are already true (`app/Models/Core/Site/Enquiry.php` line 14 confirms `use SoftDeletes`).
        - Secondary: `site.blocks` (URL links, no PII) and `site.service_categories` (category titles, no PII) also have `deleted_at` columns and no purge — add them too if consistent retention is a goal, but the PII urgency applies only to enquiries.
    - **Technical:** `PurgeSoftDeleted` runs daily at 03:20 (routes/console.php:43) and correctly purges `Customer`, `Service`, and `SiteMedia`. The `site.enquiries` table was added in 20260422040000, after this command was written, and was never added to the list. The `Enquiry` model uses the `SoftDeletes` trait, so it appears as a candidate for `onlyTrashed()` — it's a one-line fix. Under GDPR, contact-form submissions should be treated as personal data subject to the same retention limits as customer records: if a professional "deletes" an enquiry, the physical row should be GC'd within the retention window.
    - **Plain English:** When a business owner receives a contact-form message and archives it, the message appears gone from their dashboard. But the database keeps the original — including the visitor's name, email, phone number, and what they wrote — forever. A visitor who submitted a message years ago and later asked "please delete my data" can have their email scrubbed from the main customer list, but their contact-form message (with their name, email, phone number) would survive in a pile of "deleted" records that nobody empties. Adding one line to the existing daily cleanup script closes this gap.
    - **Evidence:**
        ```php
        // app/Console/Commands/PurgeSoftDeleted.php:31-33
        $total += $this->purgeModel(Customer::class, $cutoff);
        $total += $this->purgeModel(Service::class, $cutoff);
        $total += $this->purgeModel(SiteMedia::class, $cutoff);
        // Enquiry::class is absent — soft-deleted enquiry rows are never hard-deleted.
        ```
        ```sql
        -- 20260422040000_create_site_enquiries.sql
        CREATE TABLE IF NOT EXISTS site.enquiries (
            ...
            name varchar(100) NOT NULL,
            email varchar(255) NOT NULL,
            phone varchar(30),
            subject varchar(100) NOT NULL,
            message text NOT NULL,
            ...
            deleted_at timestamptz,
            ...
        );
        ```

---

## P2 — Should fix

- [ ] **#DATA-3** · P2 — PII columns on `core.professionals` remain fully readable during the 30-day pending_deletion grace period
    - **Where:** supabase/migrations/20260419000001_add_deletion_fields_to_professionals.sql; supabase/migrations/20260403000000_v2_baseline.sql (professionals PII columns)
    - **Affects:** Privacy hardening — `phone`, `primary_email`, `first_name`, `last_name`, and `location_*` are accessible to staff and to the professional themselves throughout the full grace period after deletion is confirmed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - On `deletion_confirmed_at` stamp, immediately overwrite the PII columns (`phone`, `primary_email`, `first_name`, `last_name`, `location_street_address`, `location_postcode`, `location_city`, `location_state`, `location_country`) with placeholder values. The `professional_deletion_audit` table already captures `professional_email_snapshot` and `professional_handle_snapshot` so account recovery doesn't require the live PII columns.
        - Keep `handle`, `display_name`, `auth_user_id`, `deleted_at`, and `status` intact for the recovery window.
        - Exclude the PII columns from any staff-facing API response when `status = 'pending_deletion'` as a defence-in-depth layer.
    - **Technical:** The deletion workflow (20260419000001) adds `deletion_token_hash`, `deletion_requested_at`, `deletion_confirmed_at`, and `deletion_previous_status` to `core.professionals`, and creates `core.professional_deletion_audit` which snapshots `professional_email_snapshot` and `professional_handle_snapshot`. Despite this, the row's PII columns are never overwritten during the grace period. The `professionals_all_authenticated` RLS policy grants the professional and all staff access to the row — so the data is readable through both the Supabase API and the Laravel backend for the full 30 days. Under GDPR Article 17, erasure should be fulfilled "without undue delay." A defined recovery window is accepted practice, but pseudonymizing the PII immediately at confirmation while retaining the recovery skeleton (handle, auth_user_id, status) is the standard compliant approach.
    - **Plain English:** A user clicks "Delete my account" and confirms. From their perspective, they've asked for their data to be gone. In reality, for the next 30 days their full profile — home address, phone number, email — is still sitting in an open database record that your support team can read. The recovery window (so they can change their mind) is legitimate, but the name, address, and phone could be replaced immediately with placeholder text. Your audit table already captures a snapshot for recovery purposes — so you don't need the live columns to stay intact.
    - **Evidence:**
        ```sql
        -- 20260419000001: deletion state columns added but PII columns untouched
        ALTER TABLE core.professionals
          ADD COLUMN IF NOT EXISTS deletion_token_hash text,
          ADD COLUMN IF NOT EXISTS deletion_requested_at timestamptz,
          ADD COLUMN IF NOT EXISTS deletion_confirmed_at timestamptz,
          ADD COLUMN IF NOT EXISTS deletion_previous_status text;
        -- phone, primary_email, first_name, last_name, location_* remain in baseline:
        -- phone text NOT NULL,
        -- primary_email text NOT NULL,
        -- first_name text NOT NULL,
        -- last_name text,
        -- location_street_address text,
        -- location_postcode text,
        -- location_city text,
        -- location_state text,
        -- location_country text,
        ```

- [ ] **#DATA-4** · P2 — `commerce.commission_movements.rate_source` has only a not-blank CHECK, no enum constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (commission_ledger_entries definition, since renamed in 20260506600000); supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql (orders sibling)
    - **Affects:** Data quality — any non-blank string is accepted in `rate_source` on commission movements while the sibling `commerce.orders` table rejects non-enum values. Reconciliation queries that cross-reference these two tables are fragile.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a migration that mirrors the orders constraint:
          `ALTER TABLE commerce.commission_movements ADD CONSTRAINT commission_movements_rate_source_check CHECK (rate_source IN ('product_metafield', 'metafield_override', 'brand_default', 'platform_default', 'manual', 'pending'));`
        - Run `UPDATE commerce.commission_movements SET rate_source = 'manual' WHERE rate_source NOT IN (...)` before applying the constraint if any legacy values are present.
    - **Technical:** The baseline created `commission_ledger_entries` (now `commission_movements`) with `CONSTRAINT commission_ledger_rate_source_not_blank CHECK (btrim(rate_source) <> '')` — only a not-blank guard. Migration 20260510400000 added a proper six-value enum CHECK to `commerce.orders`. Since `commission_movements` now holds only payout, clawback, and adjustment rows (post-Phase-4), all three entry types inherit their `rate_source` semantics from the parent order — so the same enum vocabulary applies. A typo on insert creates a row that cross-references an order with a conforming `rate_source`, breaking reconciliation.
    - **Plain English:** Two filing systems are supposed to use the same category labels. The newer one has a built-in validator that rejects misspellings. The older one only checks that the label isn't blank — "brand_defualt" goes in without complaint. Over time the two systems disagree about categories, and cross-checking them produces nonsense. Installing the same validator on both takes five minutes.
    - **Evidence:**
        ```sql
        -- Baseline (commission_ledger_entries, now commission_movements):
        CONSTRAINT commission_ledger_rate_source_not_blank CHECK (btrim(rate_source) <> '')
        -- Orders (20260510400000) — proper enum:
        ALTER TABLE commerce.orders
            ADD CONSTRAINT chk_orders_rate_source
            CHECK (rate_source IN
                ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));
        ```

- [ ] **#DATA-5** · P2 — `core.professional_integrations.provider` lacks a CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (professional_integrations definition)
    - **Affects:** Data integrity — any string can be written to `provider`, creating invisible integrations that the application never finds because it always queries `WHERE provider = 'shopify'`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.professional_integrations ADD CONSTRAINT professional_integrations_provider_check CHECK (provider IN ('shopify'));`
        - When new providers are added, expand this CHECK in the same migration that adds their application support — keeping DB and app in lockstep.
    - **Technical:** `provider` is typed `varchar(64) COLLATE "C" NOT NULL` with no CHECK constraint. A generated stored column `shopify_shop_domain` depends on `provider = 'shopify'`, and the `professional_integrations_professional_provider_uq` unique index scopes uniqueness per professional per provider. Despite this structure, an INSERT with `provider = 'shopifyy'` (typo) succeeds silently: the generated column produces NULL, the unique index treats it as a distinct provider, and every application query using `WHERE provider = 'shopify'` never finds the row. The integration appears configured to the user but fails all syncs with no error surfaced.
    - **Plain English:** The app always reaches for the key labelled exactly "shopify." But nothing stops someone from hanging a key labelled "shopifyy" on the same hook. That mistyped key will never be picked up, the integration silently fails, and nobody knows why the Shopify sync isn't working. A one-line guard on the database accepts only the correct spelling.
    - **Evidence:**
        ```sql
        provider varchar(64) COLLATE "C" NOT NULL,
        -- No CHECK constraint follows this line.
        ```

- [ ] **#DATA-6** · P2 — `commerce.brand_affiliate_rollup` has no documented or implemented full-rebuild procedure
    - **Where:** supabase/migrations/20260506000000_create_orders_schema.sql (rollup trigger definitions)
    - **Affects:** Operations — a trigger bug, a bulk migration that disables triggers, or a partial database restore can leave the rollup silently inconsistent with `commerce.orders`. Every brand and affiliate dashboard reads aggregated commission figures from the rollup.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write a `RebuildBrandAffiliateRollup` artisan command (or job) that: (1) truncates `commerce.brand_affiliate_rollup`, (2) replays every non-stub `commerce.orders` row by calling the `rollup_apply_delta()` logic from PHP, and (3) replays every `clawback`-type `commerce.commission_movements` row through `rollup_apply_clawback()`.
        - Add a nightly integrity check: `SELECT SUM(commission_cents) FROM commerce.orders WHERE status NOT IN ('stub','cancelled','voided') GROUP BY brand_professional_id` should match `SUM(commission_cents) FROM commerce.brand_affiliate_rollup GROUP BY brand_professional_id`. Alert on divergence via Nightwatch.
        - Document the rebuild procedure in a runbook entry.
    - **Technical:** Category 8 (backup/restore correctness) requires that trigger-maintained projections have a verified rebuild path. `brand_affiliate_rollup` is maintained by two triggers: `trg_rollup` on `commerce.orders` and `trg_rollup_clawback` on `commerce.commission_movements`. If triggers are temporarily disabled for a bulk operation (e.g., a future backfill migration uses `SET LOCAL session_replication_role = replica`), the rollup diverges silently. No rebuild command exists in `app/Console/Commands/` and no integrity check is scheduled. Since the rollup feeds every analytics dashboard, silent divergence would show wrong commission figures to brands and affiliates until someone manually investigates.
    - **Plain English:** The brand dashboard's "earned commissions" number is pre-calculated from a summary table, like a running subtotal on a receipt. The actual orders are the ground truth. If the summary table ever gets out of sync — say after a database restore or a bulk data fix — the dashboard shows the wrong number and nobody notices until a brand complains. We need a tested, one-command way to throw away the summary and rebuild it from the actual order records, plus a nightly sanity check that the two agree.
    - **Evidence:**
        ```sql
        -- 20260506000000: trigger-maintained projection with no rebuild path
        CREATE OR REPLACE FUNCTION commerce.rollup_apply_delta()
        RETURNS TRIGGER LANGUAGE plpgsql AS $$ ... $$;
        CREATE TRIGGER trg_rollup
            AFTER INSERT OR UPDATE ON commerce.orders
            FOR EACH ROW EXECUTE FUNCTION commerce.rollup_apply_delta();

        CREATE OR REPLACE FUNCTION commerce.rollup_apply_clawback()
        RETURNS TRIGGER LANGUAGE plpgsql AS $$ ... $$;
        CREATE TRIGGER trg_rollup_clawback
            AFTER INSERT ON commerce.commission_ledger_entries
            FOR EACH ROW EXECUTE FUNCTION commerce.rollup_apply_clawback();
        ```

- [ ] **#DATA-7** · P2 — `site.site_media` rows with `processing_state = 'failed'` accumulate indefinitely and reduce usable gallery capacity
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (site_media definition); supabase/migrations/20260416120000_fix_site_gallery_trigger_pool_scope.sql (gallery trigger)
    - **Affects:** Professional users — a failed upload permanently occupies one of the gallery limit slots; `PurgeSoftDeleted` covers `SiteMedia` only by `deleted_at` so failed (non-deleted) rows are never cleaned up.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `site.site_media` rows where `processing_state = 'failed'` and `created_at < NOW() - INTERVAL '7 days'` to the `PurgeSoftDeleted` or a dedicated `PurgeFailedMedia` command.
        - Update the `enforce_site_gallery_max6` trigger to exclude `processing_state = 'failed'` rows from the count, so a failed upload doesn't permanently block the slot.
    - **Technical:** The `site_media_processing_state_check` constraint permits `'failed'` as a terminal state. Rows that reach `'failed'` retain `deleted_at IS NULL` and `is_active = true` by default; `PurgeSoftDeleted` only removes rows where `deleted_at IS NOT NULL`. The gallery backstop trigger (updated in 20260416120000) counts all `pool = 'gallery' AND deleted_at IS NULL` rows without filtering by `processing_state`, so a permanently-failed image or video reduces the professional's effective gallery capacity from 6 to 5. At 5% upload failure rate across many media uploads, this becomes a noticeable user-experience degradation. Failed video uploads also retain `original_size_bytes`, `poster_path`, and `duration_ms` fields, wasting S3 TOAST storage.
    - **Plain English:** When a portfolio video upload fails — corrupted file, network timeout, transcoding error — it gets stuck in the media library as a broken item. It takes up one of the professional's six gallery slots and counts against the limit, so they can only upload five more items instead of six. Nobody tells them this happened. The broken item just sits there like a placeholder frame with no picture in it, and the professional has to manually find and delete it before the slot frees up. An automatic weekly cleanup would remove failed uploads older than a week and give the slot back.
    - **Evidence:**
        ```sql
        -- Baseline: processing_state allows 'failed' with no GC
        CONSTRAINT site_media_processing_state_check CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed'))

        -- 20260416120000: gallery trigger counts failed rows in the limit
        select count(*)
            into cnt
        from site.site_media si
        where si.site_id = new.site_id
            and si.pool = 'gallery'
            and si.deleted_at is null
            and (tg_op <> 'UPDATE' or si.id <> new.id);
        -- No 'AND si.processing_state <> ''failed''' filter.
        ```

---

## P3 — Nice to have

- [ ] **#DATA-8** · P3 — `notifications.email_subscriptions.status` lacks a CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (email_subscriptions definition)
    - **Affects:** Data quality — a typo in `status` creates a row that never appears in "subscribed" or "unsubscribed" index scans, producing a silent unsubscribe with no way to detect or repair it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE notifications.email_subscriptions ADD CONSTRAINT email_subscriptions_status_check CHECK (status IN ('subscribed', 'unsubscribed'));`
    - **Technical:** `status varchar(20) DEFAULT 'subscribed' NOT NULL` with no CHECK constraint. The partial indexes `email_subs_global_list_status_idx` and `email_subs_pro_list_status_idx` filter on `status`, so a row with `status = 'subscrbed'` (typo) bypasses both indexes and never participates in list queries — an invisible subscriber who receives no emails. The presence of `subscribed_at` and `unsubscribed_at` columns strongly implies a two-value enum.
    - **Plain English:** The subscriber list has two categories: subscribed and unsubscribed. But the intake form accepts any label — if "subscrbed" (missing i) slips in, that subscriber lands in a third invisible category and never receives emails. Nobody can see or fix the problem without digging into raw database records.
    - **Evidence:**
        ```sql
        status varchar(20) DEFAULT 'subscribed' NOT NULL,
        -- No CHECK constraint follows this line.
        subscribed_at timestamptz,
        unsubscribed_at timestamptz,
        ```

- [ ] **#DATA-9** · P3 — `public.failed_jobs.failed_at` is a timezone-naive `timestamp`
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (failed_jobs definition)
    - **Affects:** Operations — comparing queue failure timestamps against application logs (all `TIMESTAMPTZ`) requires manual offset math during incident debugging.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE public.failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC';`
    - **Technical:** This is Laravel's default `failed_jobs` schema which uses `timestamp(0) without time zone`. Every other audit and log table in the system uses `timestamptz`. When correlating a failed job against an exception in Nightwatch or a `commission_payouts.updated_at` timestamp, the naive timestamp requires knowing what server timezone was active when the failure occurred. The fix is a single-statement migration with no application code changes required.
    - **Plain English:** Every clock in the building syncs to the same timezone automatically, except one wall clock in the server room that keeps whatever time it had when it was plugged in. Changing it to auto-sync is a one-minute job and prevents mental arithmetic during incidents.
    - **Evidence:**
        ```sql
        failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
        -- Compare: every other audit table uses timestamptz:
        -- created_at timestamptz DEFAULT now() NOT NULL,
        ```
