`★ Insight ─────────────────────────────────────`
PostgreSQL's `ADD CONSTRAINT … NOT VALID` + `VALIDATE CONSTRAINT` split is the key safe-migration pattern here. `NOT VALID` acquires only a brief `ShareRowExclusiveLock` for the metadata change, while `VALIDATE CONSTRAINT` (in a separate transaction) uses `ShareUpdateExclusiveLock` — which allows concurrent reads *and* writes. Without `NOT VALID`, every `ADD CONSTRAINT CHECK` acquires `AccessExclusiveLock` for the duration of a full-table scan, blocking all readers and writers.
`─────────────────────────────────────────────────`

# Migration Safety Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — migration lens
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260506000000_create_orders_schema.sql`
- `supabase/migrations/20260416000000_add_commission_grace_period.sql`
- `supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql`
- `supabase/migrations/20260428000000_payout_grace_and_app_fee.sql`
- `supabase/migrations/20260503000000_expand_brand_status.sql`
- `supabase/migrations/20260505000000_redesign_brand_status_stages.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#MIG-1** · P1 — CHECK constraint on `commission_ledger_entries` added without `NOT VALID` (orders schema migration)
    - **Where:** `supabase/migrations/20260506000000_create_orders_schema.sql:40–45`
    - **Affects:** All webhook ingestion and commission writes during deployment to any environment (development or production). The `AccessExclusiveLock` blocks Shopify order-paid webhooks for the duration of the full-table scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `ADD CONSTRAINT … CHECK (…)` to `ADD CONSTRAINT … CHECK (…) NOT VALID` in this DO block.
        - Add a follow-up migration file (e.g. `20260506000001_validate_ledger_entry_type_check.sql`) containing `ALTER TABLE commerce.commission_ledger_entries VALIDATE CONSTRAINT commission_ledger_entries_entry_type_check;` — this runs under `ShareUpdateExclusiveLock`, allowing concurrent writes.
        - No backfill needed: the IF EXISTS guard already ensures rows without 'clawback' won't be present.
    - **Technical:** PostgreSQL validates every existing row when `ADD CONSTRAINT CHECK` is used without `NOT VALID`, holding `AccessExclusiveLock` for the full scan. Even inside a PL/pgSQL `DO` block, the lock behaviour is identical. The table is renamed to `commission_movements` in `20260506600000_rename_ledger_to_movements.sql`, but the lock hits at the earlier migration's execution time. The safe two-step pattern (`NOT VALID` → `VALIDATE CONSTRAINT` in a separate transaction) uses a weaker `ShareUpdateExclusiveLock` for the validation pass, which allows concurrent inserts and updates.
    - **Plain English:** This migration checks every historical commission record against a new rule before allowing any new records to be saved. While it's checking, the system cannot record new orders at all — like locking the cash register to recount every receipt in the drawer. Split it into "apply the new rule now, but defer checking old receipts" and "quietly verify old receipts while the register stays open."
    - **Evidence:**
        ```sql
        ALTER TABLE commerce.commission_ledger_entries
            DROP CONSTRAINT IF EXISTS commission_ledger_entries_entry_type_check;
        ALTER TABLE commerce.commission_ledger_entries
            ADD CONSTRAINT commission_ledger_entries_entry_type_check
            CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'));
        ```

- [ ] **#MIG-2** · P1 — CHECK constraint and index on `commission_ledger_entries` both lock the table during grace-period migration
    - **Where:** `supabase/migrations/20260416000000_add_commission_grace_period.sql:19–28`
    - **Affects:** Same table as MIG-1. The `ADD CONSTRAINT` acquires `AccessExclusiveLock` for a full-table scan; the `CREATE INDEX` immediately after acquires the same lock again — two back-to-back blackout windows in a single migration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `NOT VALID` to the `ADD CONSTRAINT commission_ledger_status_check` statement and add a separate follow-up migration to `VALIDATE CONSTRAINT`.
        - Change `CREATE INDEX IF NOT EXISTS idx_cle_voidable` to `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cle_voidable` and move it to its own migration file (PostgreSQL prohibits `CREATE INDEX CONCURRENTLY` inside a transaction block, and Supabase wraps each migration in a transaction unless the file contains no DDL transaction markers).
    - **Technical:** The constraint expands the status enum to include `'voided'`; without `NOT VALID`, Postgres re-validates every existing row. The `idx_cle_voidable` index creation immediately after acquires a second `AccessExclusiveLock` before releasing the first — effectively a compound blackout. Note that `idx_professionals_grace_period` further down the same file affects `core.professionals`, a different table, so that index is a separate (lower-impact) concern.
    - **Plain English:** Two door-locks back to back: first the system checks every old commission status, then immediately blocks again to build a new lookup table. No commission records can be written during either phase. Fix both: defer the old-record check, and build the lookup table in the background while orders keep flowing.
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

- [ ] **#MIG-3** · P1 — Two analytics indexes on `commission_ledger_entries` created without `CONCURRENTLY`
    - **Where:** `supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql:4–8`
    - **Affects:** All reads and writes on `commission_ledger_entries` (Shopify webhook ingestion, analytics queries) for the duration of each index build during deployment.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Change both `CREATE INDEX IF NOT EXISTS` statements to `CREATE INDEX CONCURRENTLY IF NOT EXISTS`.
        - Since `CONCURRENTLY` cannot run inside a transaction, verify this migration file has no explicit `BEGIN`/`COMMIT` wrapper (it currently doesn't — safe to add `CONCURRENTLY` directly).
    - **Technical:** B-tree index builds without `CONCURRENTLY` hold `AccessExclusiveLock` for the entire scan-and-sort phase. On a ledger table that accumulates rows with every Shopify order event, this directly stalls webhook processing. `CONCURRENTLY` builds the index in the background under `ShareUpdateExclusiveLock`, allowing concurrent inserts throughout.
    - **Plain English:** Building a new filing index while locking the whole filing room. Change one word (`CONCURRENTLY`) and the index builds quietly in the background while the team keeps working.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
            ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);

        CREATE INDEX IF NOT EXISTS idx_cle_affiliate_occurred_at
            ON commerce.commission_ledger_entries (affiliate_professional_id, occurred_at);
        ```

- [ ] **#MIG-4** · P1 — `ALTER COLUMN void_at SET NOT NULL` triggers full-table scan despite prior backfill
    - **Where:** `supabase/migrations/20260428000000_payout_grace_and_app_fee.sql:36–37`
    - **Affects:** `commerce.commission_payouts` — all payout reads and writes block during the constraint enforcement scan.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Before the `SET NOT NULL`, add: `ALTER TABLE commerce.commission_payouts ADD CONSTRAINT commission_payouts_void_at_notnull CHECK (void_at IS NOT NULL) NOT VALID;`
        - Follow it with: `ALTER TABLE commerce.commission_payouts VALIDATE CONSTRAINT commission_payouts_void_at_notnull;` (separate migration, or immediately after the backfill UPDATE in a second statement — the VALIDATE step uses `ShareUpdateExclusiveLock`).
        - After validation, `ALTER COLUMN void_at SET NOT NULL` becomes a metadata-only operation (Postgres recognises the validated CHECK and skips the scan).
        - The two indexes that follow (`commission_payouts_void_at_idx`, `commission_payouts_app_fee_idx`) also lack `CONCURRENTLY` — add it to both for consistency.
    - **Technical:** PostgreSQL only skips the `SET NOT NULL` rescan when it can prove no NULLs exist via an already-validated `CHECK (col IS NOT NULL)` constraint. A backfill UPDATE alone is insufficient — the planner doesn't trust it without the constraint proof. At pilot scale the payout table is small, but this is a table that grows monotonically and the pattern will hurt on promotion to production with real volume. The two indexes below it compound the lock window.
    - **Plain English:** You've already gone through every folder and filled in the missing date. But the filing system won't accept the "dates are now mandatory" rule until it re-checks every folder itself — even though you just did that. Teach it to trust your check first, then the rule goes in instantly with no disruption.
    - **Evidence:**
        ```sql
        UPDATE commerce.commission_payouts
        SET void_at = created_at + interval '60 days'
        WHERE void_at IS NULL;

        ALTER TABLE commerce.commission_payouts
            ALTER COLUMN void_at SET NOT NULL;
        ```

---

## P2 — Should fix

- [ ] **#MIG-5** · P2 — CHECK constraints on `brand.brand_profiles` replaced without `NOT VALID` across two status-expansion migrations
    - **Where:** `supabase/migrations/20260503000000_expand_brand_status.sql:11–16`, `supabase/migrations/20260505000000_redesign_brand_status_stages.sql:18–23`
    - **Affects:** `brand.brand_profiles` writes during deployment. Pre-beta the table is tiny so the scan is currently negligible, but the pattern is structurally identical to MIG-1 through MIG-4 and will break silently when the table grows.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Apply the same `NOT VALID` → `VALIDATE CONSTRAINT` split to both migrations.
        - Alternatively, add an inline SQL comment on each `ADD CONSTRAINT` explicitly documenting "table is currently empty / pre-beta — NOT VALID omitted intentionally" so reviewers don't copy the pattern to larger tables.
    - **Technical:** Both migrations drop the existing `chk_brand_profiles_brand_status` constraint and add a widened replacement. At current scale this is a sub-millisecond scan. The risk is precedent: these are the most recently-edited migration files and a developer extending the pattern to `commerce.*` tables without the `NOT VALID` guard would reproduce MIG-1 through MIG-4 verbatim.
    - **Plain English:** Locking a small supply closet to re-check its shelves is harmless today, but if the same approach gets applied to the main warehouse later, it'll bring the business to a halt. Either fix the pattern or add a note explaining why this specific case is safe, so no one copies it to the warehouse.
    - **Evidence:**
        ```sql
        ALTER TABLE brand.brand_profiles
          DROP CONSTRAINT IF EXISTS chk_brand_profiles_brand_status;
        ALTER TABLE brand.brand_profiles
          ADD CONSTRAINT chk_brand_profiles_brand_status
          CHECK (brand_status IN ('active', 'deactivated', 'building', 'preview', 'live', 'systems_down'));
        ```

`★ Insight ─────────────────────────────────────`
The `NOT VALID` + `VALIDATE CONSTRAINT` split is a PostgreSQL-specific pattern that doesn't exist in most other databases — it's one of Postgres's most important production-safety features. The key insight is that `VALIDATE CONSTRAINT` acquires `ShareUpdateExclusiveLock` (same as `CREATE INDEX CONCURRENTLY`), which blocks only DDL and `VACUUM FULL`, not normal DML. This means validation can run safely during business hours on a live table without interrupting writes.
`─────────────────────────────────────────────────`
