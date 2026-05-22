- [ ] **SCHEMA-1** · P2 — `site.services.deleted_origin` is an application-level enum without a DB CHECK constraint
    - **Where:** supabase/migrations/20260504100000_add_deleted_origin_to_services.sql:5
    - **Affects:** Any raw SQL write, manual edit, or future backfill that writes an unrecognised value will be accepted and cause incorrect sync behaviour.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint: `deleted_origin IS NULL OR deleted_origin = 'square'`.
        - Validate with the NOT VALID + VALIDATE pattern to avoid locking writes.
    - **Technical:** The column is documented as holding NULL (manual delete) or `'square'` (sync-triggered delete). Without a CHECK the domain is enforced only in application code; a stray `DB::update` or a human error in a SQL editor can write an invalid value that silently breaks the sync logic.
    - **Plain English:** The database has a column that says "why was this service deleted?" — it should only ever be empty or "square", but there’s no lock on the door. Someone could type "sqaure" by accident and the system wouldn’t notice until the sync behaved weirdly.
    - **Evidence:**
        ```sql
        ALTER TABLE site.services ADD COLUMN IF NOT EXISTS deleted_origin varchar(16) NULL;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCHEMA-2** · P2 — `site.site_media.purpose` has no CHECK constraint
    - **Where:** supabase/migrations/20260415120000_add_purpose_to_site_media.sql:13
    - **Affects:** Brand design asset uploads — a typo in `purpose` would silently break logo/placeholder singleton enforcement.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CHECK constraint: `purpose IS NULL OR purpose IN ('logo_full','logo_square','placeholder')`.
        - Backfill NULL purposes if any exist (current data has been backfilled, so finalise with NOT VALID + VALIDATE).
    - **Technical:** The column drives SITE_MEDIA design pool uniqueness (logo_full, logo_square, placeholder). A value like `'logo'` (old alt_text) or a typo would slip in and violate singleton expectations without any database-level error.
    - **Plain English:** This column labels design assets like “logo_full”. If someone mislabels an image as “logo_ful” the system won’t stop them, and later the “only one logo” rule might break silently.
    - **Evidence:**
        ```sql
        ALTER TABLE site.site_media
            ADD COLUMN IF NOT EXISTS purpose text;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCHEMA-3** · P2 — Missing FK index on `core.feature_flag_overrides.created_by`
    - **Where:** supabase/migrations/20260519010001_fix_feature_flag_overrides_created_by_fk.sql:1-10 (FK repointing) and no index in following files
    - **Affects:** Staff-delete operations on `core.partna_staff` — cascade SET NULL will scan the overrides table sequentially.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a partial index: `CREATE INDEX CONCURRENTLY ON core.feature_flag_overrides (created_by) WHERE created_by IS NOT NULL;`
    - **Technical:** The FK was repointed from `core.professionals` to `core.partna_staff` but no index was created. ON DELETE SET NULL on a staff row will trigger a full table scan on every delete, growing painful as overrides accumulate. The partial index keeps it lean.
    - **Plain English:** When a staff member’s account is removed, the system needs to clear any feature-flag overrides they created. Without an index, it has to flip through every single override row — like looking for a name in an unsorted phone book. Adding a quick-reference index makes that instant.
    - **Evidence:**
        ```sql
        -- from fix: ALTER TABLE core.feature_flag_overrides
        --     ADD CONSTRAINT feature_flag_overrides_created_by_fkey
        --     FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL
        --     NOT VALID;
        -- No accompanying CREATE INDEX statement.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCHEMA-4** · P3 — `analytics.section_views` missing BRIN index on `occurred_at`
    - **Where:** supabase/migrations/20260515100000_create_analytics_section_views.sql:1-57
    - **Affects:** Time-range scans for shop analytics — will become seq-scan heavy as the table grows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add: `CREATE INDEX CONCURRENTLY idx_section_views_occurred_brin ON analytics.section_views USING BRIN(occurred_at) WITH (pages_per_range = 64);`
    - **Technical:** Tables like `cart_events` and `site_visits` received BRIN indexes for their append-only nature. `section_views` is an identical event stream (IntersectionObserver pings from storefronts) and will reach millions of rows under scale; B-tree on (professional_id, occurred_at DESC) is good for filtered queries, but broad time-range queries will scan many blocks. BRIN keeps the index tiny and effective because rows are inserted in occurred_at order.
    - **Plain English:** This event table records every time a visitor scrolls past a shop section. It’ll grow fast. Most queries look at a date range, and a BRIN index acts like chapter markers in a book — letting the database skip huge chunks without reading every page — while adding almost no storage cost.
    - **Evidence:**
        ```sql
        -- migration creates only B-tree indexes:
        CREATE INDEX IF NOT EXISTS section_views_professional_occurred_idx
            ON analytics.section_views (professional_id, occurred_at DESC);
        CREATE INDEX IF NOT EXISTS section_views_site_section_occurred_idx
            ON analytics.section_views (site_id, section_key, occurred_at DESC);
        -- No BRIN index created, contrasting with sibling event tables.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCHEMA-5** · P3 — `brand.brand_profiles.industries` JSONB column missing GIN index
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:667 (table definition)
    - **Affects:** Any dashboard query that filters by industry with `@>` (e.g., “show brands in beauty_products”) will seq-scan the entire brand_profiles table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `CREATE INDEX CONCURRENTLY idx_brand_profiles_industries_gin ON brand.brand_profiles USING GIN (industries);`
    - **Technical:** The JSONB array column stores multiple industry values. Queries like `industries @> '"beauty_products"'` are the natural filter for a brand directory or admin dashboard. Without a GIN index, every such filter forces a sequential scan; at scale this becomes a performance sink.
    - **Plain English:** The database stores which industries a brand operates in as a list of labels. Searching for “show me all beauty brands” currently means the system has to read every brand’s row one by one. Adding a specialised index is like putting the labels on the outside of the filing cabinet so the search can jump straight to the right drawer.
    - **Evidence:**
        ```sql
        industries jsonb DEFAULT '[]'::jsonb NOT NULL,
        ```
        and no GIN index exists on this column.
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCHEMA-6** · P1 — Append-only enforcement missing on `brand.brand_partner_link_events` audit table
    - **Where:** supabase/migrations/20260420000000_add_brand_partner_link_events.sql:13-55
    - **Affects:** Audit log integrity — a buggy job or future migration could UPDATE or DELETE historical event rows, destroying the only record of brand‑affiliate link lifecycle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a BEFORE UPDATE OR DELETE trigger that raises an exception.
        - Optionally revoke UPDATE/DELETE from `app_backend` on this table.
    - **Technical:** The table is explicitly documented as “audit log” and must outlive the link rows it describes. FKs were changed to SET NULL in a later migration to survive professional deletion, but there is no DB‑level guard against UPDATE or DELETE of the event rows themselves. The established pattern (`staff_audit_log_reject_mutation`, `handle_change_log_no_update`) should be applied here for defence‑in‑depth.
    - **Plain English:** This table is the permanent record of who partnered with whom and when. Right now nothing stops a stray line of code from editing or deleting those records, which would erase history the same way tearing pages out of a logbook would.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
            id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            ...
            -- no trigger, no revoked privileges
        );
        ```
        Compare with `core.staff_audit_log` which has:
        ```sql
        CREATE TRIGGER staff_audit_log_reject_mutation
            BEFORE UPDATE OR DELETE ON core.staff_audit_log
            FOR EACH ROW
            EXECUTE FUNCTION core.reject_staff_audit_log_mutation();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCHEMA-7** · P1 — Append-only enforcement missing on `core.brand_status_history` audit table
    - **Where:** supabase/migrations/20260505000001_create_brand_status_history.sql:1-10
    - **Affects:** Audit reliability — status transition history could be altered or deleted, breaking compliance and debugging.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a BEFORE UPDATE OR DELETE trigger that raises an exception.
    - **Technical:** `brand_status_history` is an audit trail of every brand status change. It should be immutable after insert. Currently no trigger prevents mutation; the table is guarded only by application discipline.
    - **Plain English:** This is the official diary of every status change a brand goes through. Currently, if someone accidentally ran a database command that overwrites a row, the history would be silently rewritten. Adding a locked cover on the diary prevents that.
    - **Evidence:**
        ```sql
        CREATE TABLE core.brand_status_history (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            ...
        );
        -- No trigger or privilege revoke.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCHEMA-8** · P2 — Append-only enforcement missing on `core.wallet_currency_switch_audit` audit table
    - **Where:** supabase/migrations/20260504200000_create_wallet_currency_switch_audit.sql:1-14
    - **Affects:** Financial audit trail — a currency switch event could be modified or deleted, undermining AUSTRAC-grade record keeping.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a BEFORE UPDATE OR DELETE trigger that raises an exception.
    - **Technical:** This table records every wallet currency denomination change. Row content must be immutable for financial audit. Currently only application-level discipline prevents mutation.
    - **Plain English:** Every time a brand switches the currency of their commission wallet, it’s logged here. If someone later changes that record, it’s like erasing a bank statement entry — the audit trail becomes unreliable.
    - **Evidence:**
        ```sql
        create table core.wallet_currency_switch_audit (
            ... 
        );
        -- No trigger or privilege revoke.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCHEMA-9** · P2 — `analytics.lead_submissions.outcome` has no CHECK constraint
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:1265 (lead_submissions definition)
    - **Affects:** Any process inserting raw lead events with a typo in `outcome` will be accepted, causing downstream analytics filters to silently miss rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Identify the valid application-level outcome values (e.g., `'success'`, `'error'`, `'duplicate'`) and add a CHECK constraint.
    - **Technical:** The application likely writes a closed set of values, but the column is plain `text`. A CHECK constraint locks the domain and catches bugs early.
    - **Plain English:** The lead submission table records whether a contact form was successful or failed. Right now any value can be written — a misspelling like “sucess” would be stored and the reporting would never count it. Locking to the real list of options prevents that.
    - **Evidence:**
        ```sql
        outcome text NOT NULL,
        ```
        and no CHECK constraint.
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCHEMA-10** · P3 — `public.failed_jobs` uses BIGINT primary key instead of UUID
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql:2194-2213
    - **Affects:** Inconsistency — every other Partna table uses UUID; this is the only exception, making tooling and conventions harder to rely on.
    - **Effort:** M (~2–4h) — changing PK type on an existing table requires care, but in pre‑beta the table is tiny.
    - **What to do:**
        - Migrate `public.failed_jobs.id` to UUID with `DEFAULT gen_random_uuid()`.
        - Update the `failed_jobs_uuid_unique` index (already a UUID column) to become the primary key, or keep the separate UUID column and relax the PK requirement.
    - **Technical:** The baseline creates a `bigint` PK with a separate `uuid` column. This is a Laravel convention, but the codebase convention mandates UUIDs. The `uuid` column already holds UUIDs and is UNIQUE, so it could be promoted to PK without data loss.
    - **Plain English:** Every other table in the database uses a universal “UUID” as its identifier — like having a single key format. The failed‑jobs table is the lone holdout using a different format, which is inconsistent and can cause confusion during maintenance.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS public.failed_jobs (
            id bigint NOT NULL,
            uuid varchar(255) NOT NULL,
            ...
        );
        ALTER TABLE ONLY public.failed_jobs ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);
        ```
    - `[DRAFT, confidence: 0.75]`
