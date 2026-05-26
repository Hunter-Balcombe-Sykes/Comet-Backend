- [ ] **#SCALE-1** · P1 — Index creation on `site.site_media` (hot) without `CONCURRENTLY` in `20260411000000_add_custom_product_photos.sql`
    - **Where:** supabase/migrations/20260411000000_add_custom_product_photos.sql:10-12
    - **Affects:** All concurrent upload / media operations on `site_media`; migration blocks writes for the duration of the index build.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace with `CREATE INDEX CONCURRENTLY IF NOT EXISTS …` run outside a transaction block.
        - Ensure subsequent deploys use `CONCURRENTLY` for any `site_media` index creation.
    - **Technical:** `site_media` is a write-heavy table at scale (gallery uploads, variant rows). A plain `CREATE INDEX` acquires a `SHARE` lock on the table, preventing all concurrent `INSERT`/`UPDATE`/`DELETE`. Postgres must scan the entire table with that lock held; at hundreds of thousands of rows the write block lasts minutes, dropping upload requests.
    - **Plain English:** Building this index without the “non-blocking” switch is like closing the warehouse for an hour while you rearrange the shelves — every incoming delivery truck sits outside until you’re done.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS site_media_product_gid_idx
            ON site.site_media (site_id, product_gid)
            WHERE product_gid IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-2** · P1 — `site_media` unique indexes rebuilt without `CONCURRENTLY` in `20260414100000`
    - **Where:** supabase/migrations/20260414100000_site_media_design_pool.sql:70-76
    - **Affects:** All writes to `site_media` while the unique indexes are created — gallery, design, content, product uploads stall.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Convert each `CREATE UNIQUE INDEX` to `CREATE UNIQUE INDEX CONCURRENTLY` in a separate, non-transactional migration.
        - Consider batching `DROP INDEX` first (drops are quick) and then add new indexes with `CONCURRENTLY` later.
    - **Technical:** Unique index creation must verify uniqueness across every row, requiring a full-table scan and an `ACCESS EXCLUSIVE` lock on the table. Without `CONCURRENTLY`, the `CREATE UNIQUE INDEX` blocks all concurrent write activity on `site_media` — uploads, soft-deletes, reorders. At scale this can cause a backlog of file-processing jobs.
    - **Plain English:** This is like locking the building while you check every box for a duplicate label — nobody can bring in a new box until you’re finished, and the queue piles up outside.
    - **Evidence:**
        ```sql
        CREATE UNIQUE INDEX site_media_site_pool_sort_active_uq
            ON site.site_media (site_id, pool, sort_order)
            WHERE deleted_at IS NULL
              AND is_active = true
              AND pool IN ('gallery', 'content', 'product', 'brand_gallery');

        CREATE UNIQUE INDEX site_media_design_logo_uq
            ON site.site_media (site_id)
            WHERE pool = 'design'
              AND alt_text = 'logo'
              AND deleted_at IS NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-3** · P1 — Index creation on `commerce.commission_ledger_entries` (hot) without `CONCURRENTLY` in `20260416000000`
    - **Where:** supabase/migrations/20260416000000_add_commission_grace_period.sql:33-41
    - **Affects:** All commission ledger writes (accruals, payouts, reversals) during index build — webhook ingress backs up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace with `CREATE INDEX CONCURRENTLY` for both `idx_cle_voidable` and `idx_professionals_grace_period`.
    - **Technical:** `commission_ledger_entries` is the single highest-throughput commerce table; every Shopify webhook and payment sweep writes to it. Adding an index without `CONCURRENTLY` locks the table, halting those writes. At the scale target (1M orders/year, ~10k daily payout jobs) a multi-second index build translates to hundreds of stalled jobs.
    - **Plain English:** The cash-register drawer is jammed open while maintenance is being done — no transactions can go through until the drawer closes.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_cle_voidable
            ON commerce.commission_ledger_entries (affiliate_professional_id, status, created_at)
            WHERE status = 'pending' AND payout_id IS NULL;

        CREATE INDEX IF NOT EXISTS idx_professionals_grace_period
            ON core.professionals (stripe_grace_period_ends_at)
            WHERE stripe_connect_status != 'active'
              AND stripe_grace_period_ends_at IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-4** · P1 — `CHECK` constraint added to `core.professionals` without `NOT VALID` in `20260421000000`
    - **Where:** supabase/migrations/20260421000000_add_about_to_professionals.sql:10-12
    - **Affects:** All professional create/update operations (bootstrap, onboarding, profile edits) during the constraint validation scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use the two-step pattern: `ADD CONSTRAINT … NOT VALID`, then `VALIDATE CONSTRAINT` in a separate transaction.
        - Apply the same approach for any future `CHECK` on hot tables.
    - **Technical:** `ALTER TABLE … ADD CONSTRAINT CHECK (jsonb_typeof(about) = 'object')` immediately validates every existing row against the expression, acquiring an `ACCESS EXCLUSIVE` lock. While `core.professionals` holds only ~10k rows today, at 200 brands × 50 affiliates it will be 10x that — and every row is touched on every update. The lock blocks all professional mutations for the duration of the scan.
    - **Plain English:** Imagine closing every individual’s locker to check that a new safety label is already stuck on the inside — nobody can open a locker until the inspection finishes.
    - **Evidence:**
        ```sql
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_about_is_object
            CHECK (jsonb_typeof(about) = 'object');
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-5** · P1 — Index creation on `site.site_media` covering index rebuilt without `CONCURRENTLY` in `20260421010000`
    - **Where:** supabase/migrations/20260421010000_add_caption_to_site_media.sql:25-28
    - **Affects:** All media upload/update operations during the index rebuild — public site payload view also reads this index.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Perform the index swap with `DROP INDEX CONCURRENTLY` then `CREATE INDEX CONCURRENTLY` (non-transactional) to avoid blocking writes.
    - **Technical:** The migration drops the old covering index and creates a new one on `site_media`. The `CREATE INDEX` locks the table for a full scan; `site_media` sees frequent inserts from upload endpoints (`ProfessionalUploadController`) and variant jobs. Without `CONCURRENTLY`, every in-flight upload 500s during the index build.
    - **Plain English:** Replacing the warehouse’s loading-dock map requires shutting down the loading dock — every truck from that point waits until the new map is hung.
    - **Evidence:**
        ```sql
        DROP INDEX IF EXISTS site.site_media_site_active_sort_covering_idx;

        CREATE INDEX site_media_site_active_sort_covering_idx
            ON site.site_media (site_id, sort_order)
            INCLUDE (alt_text, caption, media_type, pool)
            WHERE deleted_at IS NULL AND is_active = true;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-6** · P1 — BRIN indexes created on analytics event tables without `CONCURRENTLY` in `20260506000000`
    - **Where:** supabase/migrations/20260506000000_create_orders_schema.sql:379-387
    - **Affects:** Ingestion of `site_visits`, `link_clicks`, and `cart_events` across all public storefronts — every page view, click, and cart add stalls.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rebuild these three `CREATE INDEX` statements as `CREATE INDEX CONCURRENTLY` in separate non-transactional migration files.
    - **Technical:** `analytics.site_visits` will grow to ~90M rows at scale; `link_clicks` and `cart_events` similarly. Building a BRIN index still requires a full sequential scan of the table under a `SHARE` lock. Without `CONCURRENTLY`, the lock blocks every public-facing storefront event ingest — the entire analytics pipeline stalls until the index is built.
    - **Plain English:** The visitor counter freezes for several minutes while the database reorganises its filing cabinet — every new visitor, click, and cart add gets put on hold.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_site_visits_occurred_brin
            ON analytics.site_visits USING BRIN(occurred_at)
            WITH (pages_per_range = 64);

        CREATE INDEX IF NOT EXISTS idx_link_clicks_occurred_brin
            ON analytics.link_clicks USING BRIN(occurred_at)
            WITH (pages_per_range = 64);

        CREATE INDEX IF NOT EXISTS idx_cart_events_occurred_brin
            ON analytics.cart_events USING BRIN(occurred_at)
            WITH (pages_per_range = 64);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-7** · P2 — Multiple index creations on commerce hot tables without `CONCURRENTLY` in later migrations
    - **Where:** 
        - supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
        - supabase/migrations/20260428000000_payout_grace_and_app_fee.sql
        - supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql
        - supabase/migrations/20260513500000_add_payout_eligible_at_to_orders.sql
        - supabase/migrations/20260513700000_add_pi_and_status_columns_to_payouts.sql
    - **Affects:** Payout processing, webhook reconciler, and dashboard queries that touch `commission_ledger_entries`, `commission_payouts`, and `orders` during index builds.
    - **Effort:** M (~2–4h) — requires splitting each migration into `CONCURRENTLY` steps.
    - **What to do:**
        - For each index creation on a hot commerce table, adopt the `CONCURRENTLY` + no-transaction-wrapper pattern.
        - Audit pending deploy migrations with rules analogous to `supabase/migrations/CONVENTIONS.md` §6.
    - **Technical:** These tables are central to the payout pipeline (~10k daily payout jobs, ~3k daily Shopify webhooks). Each uncaring `CREATE INDEX` locks the target table, stalling the nightly batch, webhook handlers, and any concurrent Stripe status updates. The blast radius is smaller than `commission_ledger_entries` or `site_media`, but repeated across multiple migrations it accumulates to avoidable downtime.
    - **Plain English:** Every time you add a new filing tab without telling the office to keep working, the whole room stops for a few minutes. Multiply that by five tabs, and it adds up to a noticeable pause.
    - **Evidence:**
        ```sql
        -- Example from 20260420220000
        CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
            ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-8** · P2 — Missing `lock_timeout` / `statement_timeout` on schema changes against hot tables
    - **Where:** Multiple early migration files (e.g. 20260411000000, 20260414100000, 20260416000000, 20260421010000, 20260506000000) — none set session-level timeouts.
    - **Affects:** Every ALTER TABLE / CREATE INDEX on a hot table risks an indefinite lock queue if a long-running transaction is open, blocking all subsequent writes until the queued migration completes or fails.
    - **Effort:** S (~0.5–1h) — add `SET LOCAL lock_timeout = '2s'` and `SET LOCAL statement_timeout = '30s'` to the top of each target migration.
    - **What to do:**
        - Prepend `SET LOCAL lock_timeout = '3s'; SET LOCAL statement_timeout = '60s';` (or comparable) before every `ALTER TABLE` / `CREATE INDEX` in future migrations.
        - Retroactively update existing migration templates; at minimum, enforce for all new files via a PR checklist.
    - **Technical:** Postgres waits indefinitely for locks by default. A single open transaction holding a `ROW EXCLUSIVE` lock (e.g. a long-running analytics aggregate) will cause `ALTER TABLE … ADD INDEX` to queue behind it, and all subsequent writes to that table queue behind the DDL. A lock timeout makes the migration fail fast instead of wedging the entire system.
    - **Plain English:** It’s like asking a technician to wait outside a room until the current meeting ends, without setting a timer — if the meeting runs 30 minutes, the technician (and everyone else with an appointment) just stands there.
    - **Evidence:**
        ```sql
        -- No lock_timeout or statement_timeout set
        BEGIN;
        ALTER TABLE brand.brand_profiles
            ADD COLUMN IF NOT EXISTS setup_complete boolean NOT NULL DEFAULT false;
        COMMIT;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SCALE-9** · P3 — Index creation on medium‑growth alias tables without `CONCURRENTLY` in `20260519100000`
    - **Where:** supabase/migrations/20260519100000_handle_alias_lifecycle.sql:142-149
    - **Affects:** Handle/subdomain alias lookups — currently low-volume table, but at 200 brands with frequent renames it could grow.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `CREATE INDEX CONCURRENTLY` for the two `expires_at` partial indexes on `professional_handle_aliases` and `site_subdomain_aliases`.
    - **Technical:** While these tables hold modest row counts today, a brand rename generates multiple alias rows; multiply by 200 brands and occasional subdomain changes, and the tables reach thousands of rows. The `CREATE INDEX` locks the table briefly; using `CONCURRENTLY` is a low-effort hardening that avoids a surprise write block if the table grows faster than anticipated.
    - **Plain English:** It’s a small filing cabinet today, but the same rule applies: if you ever need to add a new tab, you don’t want to lock everyone out while you do it.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS professional_handle_aliases_expires_at_idx
            ON site.professional_handle_aliases (expires_at)
            WHERE expires_at IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.70]`
