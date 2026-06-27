Both confirmed. Now I have everything needed. Let me summarize my adjudication decisions before writing the final document:

**Dropped:**
- DATA-1 (commerce.orders FKs) — explicitly out of scope (commerce schema shipped + audited)
- DATA-2 (booking_events PII) — out of scope (booking feature dropped); confidence 0.7
- DATA-3 (site.enquiries PII) — core claim falsified: `RedactCustomerJob` hard-deletes `site.enquiries` by email at lines 110-114; `DataExportPayloadBuilder` includes enquiries in exports
- DATA-4 (commission_movements.rate_source) — out of scope (commission_movements explicitly excluded)

**Kept from DeepSeek:** DATA-5→DATA-2, DATA-6→DATA-3, DATA-7→DATA-4, DATA-8→DATA-5

**New finding (DeepSeek missed):** Both `core.brand_status_history` and `core.wallet_currency_switch_audit` carry `ON DELETE CASCADE` on `professional_id` — the lens description explicitly calls `brand_status_history` out as an audit table that must not vanish on parent delete. Same root cause, same fix.

`★ Insight ─────────────────────────────────────`
The three most instructive patterns found during adjudication: (1) DeepSeek over-reported inside the explicitly-excluded commerce schema — always scope-check first; (2) the GDPR job coverage claim failed because DeepSeek didn't read the actual job file, which hard-deletes enquiries by email; (3) DeepSeek missed the CASCADE-on-audit-table pattern despite the lens description naming `brand_status_history` as an explicit example — suggesting DeepSeek reads lens *categories* but not the *exemplars* within each category.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260404000002_drop_professional_legal_contents.sql`
- `supabase/migrations/20260404000003_rename_comet_staff_to_sidest_staff.sql`
- `supabase/migrations/20260414100000_site_media_design_pool.sql`
- `supabase/migrations/20260415120000_add_purpose_to_site_media.sql`
- `supabase/migrations/20260422040000_create_site_enquiries.sql`
- `supabase/migrations/20260423000001_create_gdpr_requests.sql`
- `supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql`
- `supabase/migrations/20260505000001_create_brand_status_history.sql`
- `supabase/migrations/20260508400000_rename_sidest_staff_to_partna_staff.sql`
- `supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql`
- `app/Jobs/Shopify/Gdpr/RedactCustomerJob.php` (verified via Grep)
- `app/Services/Professional/DataExportPayloadBuilder.php` (verified via Grep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — Audit tables brand_status_history and wallet_currency_switch_audit use ON DELETE CASCADE, destroying records on professional deletion
    - **Where:** `supabase/migrations/20260505000001_create_brand_status_history.sql:4` and `supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql:7`
    - **Affects:** Platform audit trail completeness. When a professional account is hard-deleted (the documented `pending_deletion` → purge workflow), all rows in `core.brand_status_history` and `core.wallet_currency_switch_audit` referencing that professional are silently CASCADE-deleted. `wallet_currency_switch_audit` is explicitly described as an "AUSTRAC-grade" financial audit trail — losing these rows during account closure is a financial compliance gap. `brand_status_history` records every brand lifecycle transition; losing it means disputed onboarding history (e.g. "when did they go live?") cannot be reconstructed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For both tables: `ALTER COLUMN professional_id DROP NOT NULL`, then `DROP CONSTRAINT … REFERENCES core.professionals(id) ON DELETE CASCADE` and re-add `REFERENCES core.professionals(id) ON DELETE SET NULL`.
        - This mirrors the established pattern in `20260419000002_nullable_commission_fks.sql` (payouts/topups) and `20260505200000_commission_ledger_entries_set_null_professional_fks.sql`.
        - Additionally, add `ENABLE ROW LEVEL SECURITY` and appropriate staff-read + app_backend-write policies to both tables — neither migration currently includes RLS, leaving them relying solely on table-level GRANTs.
        - Update any application code that queries `WHERE professional_id = ?` to handle NULL professional_id (render as "Deleted account" in staff UI).
    - **Technical:** Both audit tables were created after the SET NULL pattern was established for financial tables, but didn't follow it. The `professional_deletion_audit`, `data_export_audit`, and `gdpr_requests` tables all use `ON DELETE SET NULL` — they survive professional hard-delete with `professional_id = NULL` and rely on `*_snapshot` columns for identity. `brand_status_history` and `wallet_currency_switch_audit` have no snapshot columns and will silently vanish. Because neither table has RLS enabled, they currently depend entirely on role-level GRANTs; adding RLS brings them into line with the rest of the commerce/brand schema.
    - **Plain English:** Think of these tables as pages in a case file: the brand's lifecycle timeline and their currency-change history. Right now, the moment a professional deletes their account, both sets of pages are shredded automatically — even the parts that the platform needs to keep for its own records (like proving when a brand went live, or recording that a wallet currency was changed before a payout). Every other similar file in the system is designed to keep those pages with a note saying "account deleted." These two were missed.
    - **Evidence:**
        ```sql
        -- core.brand_status_history (20260505000001)
        professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,

        -- core.wallet_currency_switch_audit (20260504200000)
        professional_id  uuid  not null references core.professionals(id) on delete cascade,
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — site.site_media.pool has no CHECK constraint despite being a load-bearing discriminator
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (site_media table); no subsequent migration adds a CHECK
    - **Affects:** Data integrity for all media uploads. The `pool` value drives the `enforce_site_gallery_max6()` trigger (scoped to `pool = 'gallery'`), the `site_media_site_pool_sort_active_uq` partial unique index (scoped to specific pool values), and every subselect in the `site.public_site_payload` view (`WHERE sm.pool = 'gallery'`, `'content'`, `'documents'`). A row with a mistyped pool (e.g. `'galery'`, `'design '` with trailing space) falls through every index and view — it exists in the DB but is completely invisible to all read paths and silently skips gallery-limit enforcement.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CONSTRAINT site_media_pool_check CHECK (pool IN ('gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'))` in a new migration.
        - Verify the allowed list against the full migration chain before adding — `documents` was added in `20260422010000`, `design` in `20260414100000`.
        - Note that `media_type` and `processing_state` on the same table already have CHECK constraints — `pool` was the one omission.
    - **Technical:** The `pool` column is `varchar(20) NOT NULL DEFAULT 'gallery'` with no CHECK anywhere in the migration chain through the latest migration (`20260511100000`). The `site_media_site_pool_sort_active_uq` partial index enumerates allowed pools explicitly (`pool IN ('gallery', 'content', 'product', 'brand_gallery')`), and the gallery trigger now checks `new.pool = 'gallery'` — both guards rely on the application writing a valid pool value. A DB-level CHECK is the backstop that makes an invalid pool a transaction error rather than a silent data loss.
    - **Plain English:** The media table has a "drawer" field — gallery, content, design, etc. Each drawer has its own rules and its own slot in the display. The database checks what type of file you're putting in (photo, video, document) and what processing state it's in, and will reject bad values. But for the drawer itself, there's no such guard. If you misspell the drawer name, the file goes in but is never found again — it just sits there invisibly forever, wasting space. Adding a check here costs nothing and closes a silent black hole.
    - **Evidence:**
        ```sql
        pool varchar(20) NOT NULL DEFAULT 'gallery',
        -- no CHECK constraint in baseline or any migration through 20260511100000
        -- contrast with same table:
        CONSTRAINT site_media_media_type_check CHECK (media_type IN ('image', 'video', 'document')),
        CONSTRAINT site_media_processing_state_check CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed'))
        ```

- [ ] **#DATA-3** · P2 — core.partna_staff.role has no CHECK constraint despite being the sole gating column for admin privileges
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (comet_staff table creation, renamed via `20260404000003` and `20260508400000` to `core.partna_staff`); no migration adds a CHECK
    - **Affects:** Staff access control correctness. The `role` column is checked with exact-string comparisons (`role = 'admin'`) in the `prevent_staff_escalation()` trigger and in every RLS policy that grants admin-level access. A row inserted with `role = 'Admin'` (capital A), `role = 'admin '` (trailing space), or any other variant fails every admin check silently — the staff member authenticates successfully but has no admin access and receives no error message explaining why.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CONSTRAINT partna_staff_role_check CHECK (role IN ('admin', 'support'))` in a new migration.
        - If additional roles are planned, add them to the enum as part of that feature's migration — never add them speculatively.
    - **Technical:** The column definition is `role text DEFAULT 'support' NOT NULL` with no enum guard in the baseline or in either rename migration (`20260404000003`, `20260508400000`). Every admin-privilege decision in the RLS layer uses a verbatim `cs.role = 'admin'` comparison (case-sensitive), and the `prevent_staff_escalation()` trigger hardcodes `'admin'`. The constraint costs nothing to add and converts a "staff can't do anything and doesn't know why" failure mode into an immediate transaction error at insert/update time. This is the same class of gap as `orders.rate_source` before `20260510400000` added its CHECK.
    - **Plain English:** There's a staff permission system where some staff are "admin" and some are "support." The database checks for the exact word "admin" in about twenty different places. But it doesn't actually enforce what values are allowed in the role field — it'll happily store "Admin" with a capital A, or "admin " with a space. Anyone inserted with a slightly-wrong spelling passes login but gets locked out of every admin screen with no explanation. A one-line fix tells the database to reject anything that isn't exactly "admin" or "support" from the start.
    - **Evidence:**
        ```sql
        -- core.comet_staff (baseline) — later renamed to core.partna_staff
        role text DEFAULT 'support' NOT NULL,
        -- no CHECK constraint in baseline or rename migrations

        -- contrast: same file, same session:
        CONSTRAINT professionals_professional_type_check CHECK (
            professional_type IN ('professional', 'influencer', 'barber', ...)
        )
        ```

- [ ] **#DATA-4** · P2 — brand.brand_store_settings.default_commission_rate uses NUMERIC(5,2) while all downstream rate columns use NUMERIC(7,4)
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (brand_store_settings table)
    - **Affects:** Commission rate display reconciliation. The store settings default rate is the upstream source that populates `commission_rate` on orders and ledger rows. When a brand stores `12.35` (2 decimal places) as their default and later views an order with `commission_rate = 12.3500` (4 decimal places), the values compare unequal in string form despite being numerically identical. If a brand ever sets a rate with more than 2 decimal places (e.g., `12.3333%`), the value is silently truncated at the settings layer before propagating to orders — silent precision loss with no error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE brand.brand_store_settings ALTER COLUMN default_commission_rate TYPE numeric(7,4)` in a new migration. Widening precision is non-destructive (no data loss, no existing value changes).
        - The existing `bss_commission_range` CHECK (`>= 0 AND <= 100`) is compatible with `numeric(7,4)` and does not need to change.
    - **Technical:** `commerce.commission_ledger_entries.commission_rate`, `commerce.orders.commission_rate`, and `commerce.order_items.commission_rate` all use `NUMERIC(7,4)` — consistent across all transaction-recording tables. The settings table (`brand.brand_store_settings.default_commission_rate`) is `NUMERIC(5,2)`. When the application reads the default and writes it to a new order, the conversion from `5,2` → `7,4` is lossless for existing stored values. The problem is the reverse: any future UI path that allows sub-cent rates would truncate at the settings layer with no warning, producing an invisible mismatch between the stored default and the effective rate applied to orders.
    - **Plain English:** Every table that records commission rates stores them down to four decimal places — like writing 12.3456%. The settings table where brands set their default rate only stores two decimal places — like writing 12.34%. Right now this doesn't cause actual calculation errors because brands tend to set round percentages. But if a brand ever tries to set 12.3333% as their rate, the settings table quietly rounds it to 12.33% and orders get calculated at that lower rate. There's no error, no warning, just a silent difference between what the brand set and what affiliates get paid. A one-line fix aligns the precision.
    - **Evidence:**
        ```sql
        -- brand.brand_store_settings: 2 decimal places
        default_commission_rate numeric(5,2) NOT NULL DEFAULT 15

        -- every other rate column in the commerce schema: 4 decimal places
        commission_rate numeric(7,4) NOT NULL  -- commerce.commission_ledger_entries
        commission_rate numeric(7,4) NOT NULL  -- commerce.orders
        commission_rate numeric(7,4) NOT NULL  -- commerce.order_items
        ```

---

## P3 — Nice to have

- [ ] **#DATA-5** · P3 — public.failed_jobs.failed_at is TIMESTAMP WITHOUT TIME ZONE in an otherwise TIMESTAMPTZ schema
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (public.failed_jobs table)
    - **Affects:** Incident response. Comparing `failed_at` against `created_at` on any other table requires a manual timezone offset calculation — `failed_at` stores session-local time with no timezone recorded, while every other `created_at` / `updated_at` in the schema is `timestamptz`. Under normal operating conditions this is invisible; under a 3am production incident when correlating failed jobs against order events or webhook receipts, the offset is easy to get wrong and the tooling won't warn you.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE public.failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC'` in a new migration. Safe assumption that the existing column was populated in UTC (Laravel's default clock runs in UTC).
    - **Technical:** Laravel's upstream `failed_jobs` schema uses `timestamp(0) without time zone` — a framework carryover. The Partna schema standardised on `timestamptz` for all domain tables, but the two infrastructure tables (`failed_jobs`, `job_batches`) were imported from Laravel defaults without the adjustment. `job_batches` uses integer epoch timestamps so there's no timezone concern; only `failed_at` is affected.
    - **Plain English:** Every timestamp in the database includes the timezone — it's like every clock in the building says "3:00 PM Sydney time." Except the clock in the server room that logs failed background jobs — it says "3:00 PM" with no city label. When you're in the middle of an outage trying to match up error timestamps with other events, you'll forget, do the offset wrong, and waste minutes chasing a ghost. It's a ten-second fix.
    - **Evidence:**
        ```sql
        failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
        -- contrast with same table and every other table in schema:
        created_at timestamptz DEFAULT now() NOT NULL
        ```
