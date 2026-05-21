
<!-- ═══ CHUNK: migrations ═══ -->

- [ ] **#MIG-1** · P1 — CHECK constraint added without NOT VALID on commerce.commission_ledger_entries (hot table)
    - **Where:** supabase/migrations/20260506000000_create_orders_schema.sql: ~line 76 (the ALTER TABLE ADD CONSTRAINT CHECK block)
    - **Affects:** All writes to commission ledger entries during deployment; every subsequent webhook/order processing will stall until the full‑table validation completes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Split into two statements: `ADD CONSTRAINT … NOT VALID` in this file, then add a follow‑up migration with `VALIDATE CONSTRAINT` (separate transaction).
        - As an immediate backstop, backfill any rows that would violate the new CHECK before running the migration, so the VALIDATE pass is a no‑op.
    - **Technical:** Postgres 11+ validates every existing row when a new CHECK constraint is added without NOT VALID, holding an ACCESS EXCLUSIVE lock for the duration. At Partna’s scale (3k orders/day, growing ledger), this scan blocks all reads and writes on commerce.commission_ledger_entries — including Shopify webhook ingestion — until the table is rewritten. The safe pattern is `ADD CONSTRAINT … NOT VALID` (instant metadata change) followed by `VALIDATE CONSTRAINT` in a separate transaction under a weaker SHARE UPDATE EXCLUSIVE lock that allows concurrent operations.
    - **Plain English:** Imagine a shop that locks its front door to check every receipt before letting the next customer in. While the door is locked, no one can enter and the register can’t ring up new sales. That’s what this migration does — it blocks all commission tracking while it checks every past entry. Split the check into “latch the new rule now but don’t re‑check old receipts” and “quietly verify the old ones later while the shop stays open.”
    - **Evidence:**
        ```sql
        ALTER TABLE commerce.commission_ledger_entries
            DROP CONSTRAINT IF EXISTS commission_ledger_entries_entry_type_check;
        ALTER TABLE commerce.commission_ledger_entries
            ADD CONSTRAINT commission_ledger_entries_entry_type_check
            CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#MIG-2** · P1 — CHECK constraint added without NOT VALID on commerce.commission_ledger_entries status in separate migration
    - **Where:** supabase/migrations/20260416000000_add_commission_grace_period.sql (the ALTER TABLE ADD CONSTRAINT commission_ledger_status_check block)
    - **Affects:** Same as MIG‑1; all ledger‑entry writes stall during validation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same two‑step pattern: add the new CHECK with `NOT VALID`, then validate in a follow‑up migration.
        - Remove the `DROP CONSTRAINT IF EXISTS`; instead use `ALTER TABLE … DROP CONSTRAINT` only after the new constraint is validated (or rely on the new name to coexist temporarily).
    - **Technical:** This migration widens the status enum to include ‘voided’ and creates a partial index afterward. The `ADD CONSTRAINT` immediately triggers a full‑table scan; concurrent webhooks (e.g., order‑paid Shopify events) will queue behind it, potentially causing a cascade of timeouts. The `CREATE INDEX` that follows also lacks `CONCURRENTLY`, doubling the lock window on the same table in one transaction.
    - **Plain English:** The same door‑locking problem, but this time you’re checking every old commission entry before you’re even allowed to start building the new filing cabinet (index). The shop closes twice in a row. Do the checks separately so the shop can stay open.
    - **Evidence:**
        ```sql
        ALTER TABLE commerce.commission_ledger_entries
            DROP CONSTRAINT IF EXISTS commission_ledger_status_check;
        ALTER TABLE commerce.commission_ledger_entries
            ADD CONSTRAINT commission_ledger_status_check
            CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed', 'voided'));
        CREATE INDEX IF NOT EXISTS idx_cle_voidable
            ON commerce.commission_ledger_entries (affiliate_professional_id, status, created_at)
            WHERE status = 'pending' AND payout_id IS NULL;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#MIG-3** · P1 — Two new indexes on commerce.commission_ledger_entries created without CONCURRENTLY
    - **Where:** supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
    - **Affects:** Any analytics dashboard and ledger‑scoped queries run during deployment; the indexes block the table until built.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Use `CREATE INDEX CONCURRENTLY IF NOT EXISTS` for both statements, and place each in its own migration file (no transaction wrapper) per CONVENTIONS.md §1.
        - Ensure the time‑of‑deployment window allows the non‑blocking build to finish (can be run during low‑traffic hours).
    - **Technical:** Creating a B‑tree index on a table with many rows without `CONCURRENTLY` acquires an ACCESS EXCLUSIVE lock and holds it while every row is scanned and sorted. On a live ledger table, this prevents new accrual insertions from Shopify webhooks, effectively halting commission calculation for the duration. The fix is a one‑word change: append `CONCURRENTLY`.
    - **Plain English:** Building a new filing cabinet while blocking the whole office from using the room. Instead, build the cabinet in a corner while everyone keeps working.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
            ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);

        CREATE INDEX IF NOT EXISTS idx_cle_affiliate_occurred_at
            ON commerce.commission_ledger_entries (affiliate_professional_id, occurred_at);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#MIG-4** · P1 — ALTER COLUMN SET NOT NULL on commerce.commission_payouts without prior validated CHECK constraint
    - **Where:** supabase/migrations/20260428000000_payout_grace_and_app_fee.sql (the `ALTER COLUMN void_at SET NOT NULL` line after the backfill UPDATE)
    - **Affects:** Payout creation and processing pipelines; a full‑table scan under ACCESS EXCLUSIVE blocks all status transitions.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace the direct `SET NOT NULL` with the NOT VALID + VALIDATE pattern: add `CHECK (void_at IS NOT NULL) NOT VALID`, backfill, then `VALIDATE CONSTRAINT`, and only then `ALTER COLUMN void_at SET NOT NULL`.
        - Document the order because `SET NOT NULL` after a validated CHECK becomes metadata‑only, avoiding the rescan.
    - **Technical:** Postgres treats `ALTER TABLE … SET NOT NULL` as a full‑row scan when there isn’t a validated NOT‑NULL CHECK already in place. The backfill UPDATE ensures every row has a value, but the subsequent `SET NOT NULL` still locks the table for a scan, which on a payout table that grows with every payout batch is risky. Using a CHECK + VALIDATE first gives a weaker lock and then the SET NOT NULL is instant.
    - **Plain English:** It’s like the fire marshal coming in to verify every exit is clear before putting up a sign that says “Exits must be clear.” The verification is disruptive; do it when the building is quiet, then hang the sign instantly.
    - **Evidence:**
        ```sql
        UPDATE commerce.commission_payouts
        SET void_at = created_at + interval '60 days'
        WHERE void_at IS NULL;

        ALTER TABLE commerce.commission_payouts
            ALTER COLUMN void_at SET NOT NULL;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#MIG-5** · P2 — Missing `NOT VALID` on multiple CHECK constraints added to brand.brand_profiles in brand‑status migrations
    - **Where:** supabase/migrations/20260503000000_expand_brand_status.sql and supabase/migrations/20260505000000_redesign_brand_status_stages.sql
    - **Affects:** Brand status changes during deployment; a full‑table scan may briefly block brand‑profile updates.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Amend both migrations to use `ADD CONSTRAINT … CHECK (…) NOT VALID`, then follow up with `VALIDATE CONSTRAINT` in separate transactions.
        - Alternatively, note that the brand_profiles table is still small (pre‑beta) so the scan is trivial, but add a comment explaining the intentional deviation.
    - **Technical:** Each of these migrations drops the existing CHECK and creates a new one directly, triggering a scan of every row in brand.brand_profiles. While the table is currently tiny, the pattern is copied elsewhere and will break on larger tables. The CONVENTIONS.md §2 recommends the NOT VALID split for any constraint on a table that might hold data; at minimum a comment explaining the small‑table exemption would prevent future misuse.
    - **Plain English:** It’s fine to vacuum a single room without notice, but the same approach shouldn’t be used for the entire building. Add a note that the room is empty so the pattern doesn’t spread.
    - **Evidence:**
        ```sql
        ALTER TABLE brand.brand_profiles
          DROP CONSTRAINT IF EXISTS chk_brand_profiles_brand_status;
        ALTER TABLE brand.brand_profiles
          ADD CONSTRAINT chk_brand_profiles_brand_status
          CHECK (brand_status IN ('active', 'deactivated', 'building', 'preview', 'live', 'systems_down'));
        ```
    - `[DRAFT, confidence: 0.7]`
