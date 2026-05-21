`★ Insight ─────────────────────────────────────`
Three key patterns emerged during adjudication:
1. **Same root cause, same tier**: DeepSeek tiered three identical "audit table missing append-only trigger" findings at P1, P1, and P2 — the `wallet_currency_switch_audit` table is a financial audit trail and should match the others at P1.
2. **False positive via column type verification**: The `AuthFactorEventRepository` ISO8601 finding looked suspicious — checking the migration reveals `created_at` is `TIMESTAMPTZ` with a purpose-built partial index covering the exact brute-force query, making this a non-issue.
3. **Always-drop for naming**: The `form_started_at_ms` ambiguity finding is a naming/clarity issue — drops under the style/naming always-drop rule.
`─────────────────────────────────────────────────`

# Schema & Data-Integrity Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — 'schemarls' lens (migrations + svc-rest-models chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/`
- `app/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **SCHEMA-1** · P1 — Append-only enforcement missing on `brand.brand_partner_link_events` audit table
    - **Where:** supabase/migrations/20260420000000_add_brand_partner_link_events.sql:4
    - **Affects:** Brand-affiliate link lifecycle audit integrity — a buggy job, accidental migration, or future code change could UPDATE or DELETE historical event rows, destroying the only permanent record of who partnered with whom and when.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `core.reject_brand_partner_link_events_mutation()` function (or reuse a generic reject function if one exists) that raises an exception unconditionally.
        - Add a `BEFORE UPDATE OR DELETE` trigger on `brand.brand_partner_link_events` that calls it.
        - Optionally `REVOKE UPDATE, DELETE ON brand.brand_partner_link_events FROM app_backend` to add a third enforcement layer, mirroring the pattern in `20260517300000_create_staff_audit_log.sql`.
    - **Technical:** The table is explicitly documented as an audit log that "must outlive the link rows it describes." No trigger or privilege revoke currently prevents mutation. The `core.staff_audit_log` migration (`20260517300000`) established the canonical three-layer pattern (split RLS policies + explicit REVOKE + BEFORE trigger) specifically because application-level discipline alone was considered insufficient for audit tables. `brand_partner_link_events` predates that migration and was not retrofitted.
    - **Plain English:** This table is the permanent record of every brand-affiliate partnership — who joined, who was removed, and why. Right now nothing stops a bug from quietly editing or erasing those records. Adding a database-level lock means even a runaway script can't touch them — like welding the logbook shut.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            brand_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
            affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
            event_type text NOT NULL,
            -- ... no trigger, no REVOKE
        );
        ```

- [ ] **SCHEMA-2** · P1 — Append-only enforcement missing on `core.brand_status_history` audit table
    - **Where:** supabase/migrations/20260505000001_create_brand_status_history.sql:2
    - **Affects:** Brand status transition history — a bad migration or accidental UPDATE could silently rewrite the audit trail used for compliance and debugging.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BEFORE UPDATE OR DELETE` trigger that raises unconditionally (same pattern as `staff_audit_log`).
    - **Technical:** `brand_status_history` records every brand status transition and is the authoritative compliance trail. The table has no mutation guard — only a btree index and a cascade-delete FK. The `staff_audit_log` migration explicitly noted that prior audit tables "lean on application discipline" and documented why that is insufficient. `brand_status_history` is in the same category.
    - **Plain English:** This is the official diary of every status change a brand goes through — approved, suspended, etc. Without a lock on the diary, a bug or a rushed database command could rewrite an entry and nobody would know. A database-level trigger makes those entries permanent.
    - **Evidence:**
        ```sql
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            professional_id UUID NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
            from_status VARCHAR(50),
            to_status VARCHAR(50) NOT NULL,
            reason VARCHAR(100),
            metadata JSONB DEFAULT '{}',
            created_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        -- No trigger or privilege revoke.
        ```

- [ ] **SCHEMA-3** · P1 — Append-only enforcement missing on `core.wallet_currency_switch_audit` financial audit table
    - **Where:** supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql:5
    - **Affects:** Financial audit trail for currency denomination changes — row content must be immutable for AUSTRAC-grade record-keeping; currently only a code comment enforces this.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `BEFORE UPDATE OR DELETE` trigger that raises unconditionally.
    - **Technical:** Same root cause as SCHEMA-1 and SCHEMA-2. The migration comment even acknowledges "Rows are append-only; no updates" — but this intent lives only in a comment, not in the database. A `REVOKE UPDATE, DELETE` plus trigger brings it to the same standard as `staff_audit_log`. Re-tiered from P2 to P1 to match SCHEMA-1/SCHEMA-2 (identical root cause, financial context makes it at least as critical).
    - **Plain English:** Every time a brand changes the currency of their commission wallet, it's logged here — like a bank statement. The migration's own comment says these records should never be changed, but there's no actual enforcement. A stray database command could alter an entry, which is the financial equivalent of erasing a bank record.
    - **Evidence:**
        ```sql
        -- Audit trail for wallet currency switches. A switch is financially meaningful:
        -- ...
        -- Rows are append-only; no updates.
        create table core.wallet_currency_switch_audit (
            id               uuid        primary key default gen_random_uuid(),
            ...
            created_at        timestamptz not null default now()
        );
        -- No trigger or privilege revoke despite "append-only" comment.
        ```

---

## P2 — Should fix

- [ ] **SCHEMA-4** · P2 — `site.services.deleted_origin` column has no CHECK constraint
    - **Where:** supabase/migrations/20260504100000_add_deleted_origin_to_services.sql:4
    - **Affects:** Square sync logic — any raw SQL write, manual edit, or future backfill that writes an unrecognised value (e.g., `'sqaure'`) is silently accepted and can break restore-on-resync behaviour.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.services ADD CONSTRAINT services_deleted_origin_check CHECK (deleted_origin IS NULL OR deleted_origin = 'square') NOT VALID;` followed by `VALIDATE CONSTRAINT` in a separate transaction.
    - **Technical:** The migration comment documents exactly two valid states: `NULL` (manually deleted) and `'square'` (sync-triggered). Without a CHECK, any write goes through. The `NOT VALID → VALIDATE` pattern avoids locking active writes while still enforcing the domain.
    - **Plain English:** This column is a label that says "why was this service deleted?" — it should only ever be empty or say "square." There's no lock on it, so a typo like "sqaure" would be accepted and the sync logic would silently malfunction.
    - **Evidence:**
        ```sql
        ALTER TABLE site.services ADD COLUMN IF NOT EXISTS deleted_origin varchar(16) NULL;

        COMMENT ON COLUMN site.services.deleted_origin IS
            'square = deleted by Square catalog sync; NULL = manually deleted in Side St';
        ```

- [ ] **SCHEMA-5** · P2 — `site.site_media.purpose` column has no CHECK constraint
    - **Where:** supabase/migrations/20260415120000_add_purpose_to_site_media.sql:20
    - **Affects:** Brand design asset singleton enforcement — a typo like `'logo_ful'` bypasses the partial unique indexes (which are purpose-scoped), silently allowing duplicate logo rows and breaking the one-logo-per-site invariant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ALTER TABLE site.site_media ADD CONSTRAINT site_media_purpose_check CHECK (purpose IS NULL OR purpose IN ('logo_full', 'logo_square', 'placeholder')) NOT VALID;` followed by `VALIDATE CONSTRAINT`.
    - **Technical:** Three partial unique indexes enforce singleton semantics by `purpose` value — but only for the exact strings they were written for. A write with any other value slips through all three indexes without collision and is stored silently. A CHECK constraint closes the gap before the unique indexes even fire.
    - **Plain English:** This column labels design assets as "logo_full," "logo_square," or "placeholder." The database has rules that say "only one logo_full per site," but those rules only apply to that exact label. A misspelling gets stored with no complaint and the "one logo" rule silently breaks.
    - **Evidence:**
        ```sql
        ALTER TABLE site.site_media
            ADD COLUMN IF NOT EXISTS purpose text;
        ```

- [ ] **SCHEMA-6** · P2 — Missing FK index on `core.feature_flag_overrides.created_by`
    - **Where:** supabase/migrations/20260519010001_fix_feature_flag_overrides_created_by_fk.sql:25
    - **Affects:** Staff account deletion — the `ON DELETE SET NULL` cascade will sequential-scan `feature_flag_overrides` on every staff member removal as the table grows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY idx_feature_flag_overrides_created_by ON core.feature_flag_overrides (created_by) WHERE created_by IS NOT NULL;`
    - **Technical:** The FK was repointed from `core.professionals` to `core.partna_staff` in this migration but no index was created. PostgreSQL does not auto-create indexes for FK columns. `ON DELETE SET NULL` triggers a heap scan to null out the column on every staff-delete operation; the partial index keeps this O(1) regardless of table size.
    - **Plain English:** When a staff member's account is deleted, the database needs to clear their name from any feature-flag overrides they created. Without an index, it has to check every single override row — like looking for a name in an unsorted phone book. A quick-reference index makes that instant.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feature_flag_overrides
            ADD CONSTRAINT feature_flag_overrides_created_by_fkey
            FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL
            NOT VALID;
        -- No accompanying CREATE INDEX statement.
        ```

- [ ] **SCHEMA-7** · P2 — `analytics.lead_submissions.outcome` has no CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1190
    - **Affects:** Lead analytics — a typo in `outcome` (e.g., `'sucess'`) is accepted, silently dropping those rows from any filter-based reporting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Identify the full set of valid `outcome` values from application code (e.g., `'success'`, `'error'`, `'duplicate'`) and add a CHECK constraint.
    - **Technical:** The column is plain `text NOT NULL` with no domain constraint. Application code writes a closed set of values, but the database has no knowledge of that set. A CHECK constraint catches bad writes at the source rather than silently corrupting analytics.
    - **Plain English:** The lead submission table records whether a contact form was successful or failed. Any value can be written right now — a misspelling would be stored and the reporting would never count it. Locking to the real list of options prevents silent data loss.
    - **Evidence:**
        ```sql
        outcome text NOT NULL,
        ```

---

## P3 — Nice to have

- [ ] **SCHEMA-8** · P3 — `analytics.section_views` missing BRIN index on `occurred_at`
    - **Where:** supabase/migrations/20260515100000_create_analytics_section_views.sql
    - **Affects:** Broad time-range analytics queries against section view data as the table grows to millions of rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY idx_section_views_occurred_brin ON analytics.section_views USING BRIN(occurred_at) WITH (pages_per_range = 64);`
    - **Technical:** `section_views` is an append-only event stream (IntersectionObserver pings). Sibling event tables (`cart_events`, `site_visits`) received BRIN indexes for their append-only nature. BRIN is ideal here: rows are inserted in `occurred_at` order, the index is tiny, and it enables efficient block-range skipping for date-range scans. The existing B-tree composite indexes are correct for filtered per-site/section queries; BRIN supplements for cross-site time-range aggregations.
    - **Plain English:** This table records every time a visitor scrolls past a shop section. It'll grow fast. A BRIN index acts like chapter markers in a book — letting the database skip huge chunks of old data when searching a date range — while adding almost no storage overhead.
    - **Evidence:**
        ```sql
        -- Only B-tree indexes created:
        CREATE INDEX IF NOT EXISTS section_views_professional_occurred_idx
            ON analytics.section_views (professional_id, occurred_at DESC);
        CREATE INDEX IF NOT EXISTS section_views_site_section_occurred_idx
            ON analytics.section_views (site_id, section_key, occurred_at DESC);
        -- No BRIN index; contrasts with sibling event tables.
        ```

- [ ] **SCHEMA-9** · P3 — `brand.brand_profiles.industries` JSONB column missing GIN index
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:725
    - **Affects:** Any admin or dashboard query filtering brands by industry (`industries @> '"beauty_products"'`) will seq-scan the full `brand_profiles` table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY idx_brand_profiles_industries_gin ON brand.brand_profiles USING GIN (industries);`
    - **Technical:** The JSONB array column is the natural filter for a brand directory. Without a GIN index, `@>` operator queries force a full table scan. At 200 brands this is trivial; once the directory grows it becomes a recurring slow query.
    - **Plain English:** The database stores which industries a brand operates in as a list. Searching for "all beauty brands" currently means the system reads every brand's row one by one. Adding a GIN index is like putting labels on the outside of filing cabinet drawers — the search jumps straight to the right drawer.
    - **Evidence:**
        ```sql
        industries jsonb DEFAULT '[]'::jsonb NOT NULL,
        -- No GIN index on this column in the baseline or any subsequent migration.
        ```

- [ ] **SCHEMA-10** · P3 — `public.failed_jobs` uses `bigint` primary key, breaking UUID convention
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (failed_jobs table definition)
    - **Affects:** Tooling and convention consistency — every other Partna table uses UUID PKs; this is the only exception.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - The table already has a UNIQUE `uuid varchar(255)` column. Promote it to UUID type, make it the primary key, and drop the `bigint` sequence PK. Safe at pre-beta with no production data.
    - **Technical:** Laravel's default `failed_jobs` schema uses a bigint autoincrement PK with a separate `uuid` column. The codebase convention mandates UUID PKs on all tables. The `uuid` column is already present and unique, so it can be promoted without data loss. The change is cosmetic at scale but reduces friction for tooling that assumes UUID PKs (e.g., Horizon UI references, audit tooling).
    - **Plain English:** Every table in the database uses a UUID — a standardised unique identifier format — as its primary key. The failed-jobs table is the odd one out, using an old-style auto-incrementing number instead. It works fine, but it's the one door in the house with a different lock.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS public.failed_jobs (
            id bigint NOT NULL,
            uuid varchar(255) NOT NULL,
            ...
        );
        ALTER TABLE ONLY public.failed_jobs ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);
        ```
