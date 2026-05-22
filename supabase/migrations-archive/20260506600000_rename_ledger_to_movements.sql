-- Phase 4 follow-up: rename commerce.commission_ledger_entries → commission_movements.
--
-- Decision #1 in the analytics-rebuild plan called for the rename to land in Phase 1.
-- It was deferred through Phase 1, Phase 3, and Phase 4 because each of those PRs had
-- a heavier blast radius — touching the rename at the same time would have made any
-- regression hard to bisect. Phase 4 left the cleanup explicit: "the rename is a
-- separate future PR." This is that PR.
--
-- The table now holds money-movement rows only (payout, clawback, adjustment) — the
-- new name describes what's actually stored. Accrual/reversal semantics live on
-- commerce.orders.commission_cents and commerce.brand_affiliate_rollup.reversed_*.
--
-- ALTER TABLE RENAME preserves the data, all rows, all indexes, all constraints, and
-- the trg_rollup_clawback trigger (Postgres re-points the trigger reference to the
-- new table name automatically). The trigger function body uses NEW only — no string
-- references to the old table name.
--
-- The CHECK constraint and FK names auto-rename when their owning table is renamed.
-- Index and constraint names that embedded the old table name (e.g.
-- commission_ledger_entries_entry_type_check, idx_cle_order_id) are renamed
-- explicitly here so that future queries against pg_indexes / pg_constraint are
-- self-describing.
--
-- Wrapped in a transaction — partial failure leaves the schema at the pre-rename state.

BEGIN;

ALTER TABLE commerce.commission_ledger_entries
    RENAME TO commission_movements;

-- The CHECK constraint that gates entry_type values keeps the old name post-rename;
-- rename it to match.
ALTER TABLE commerce.commission_movements
    RENAME CONSTRAINT commission_ledger_entries_entry_type_check
        TO commission_movements_entry_type_check;

-- FK on the order_id column added in Phase 1 — keep the constraint, rename for clarity.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'commission_ledger_entries_order_id_fkey'
    ) THEN
        ALTER TABLE commerce.commission_movements
            RENAME CONSTRAINT commission_ledger_entries_order_id_fkey
                TO commission_movements_order_id_fkey;
    END IF;
END $$;

-- Indexes embedded the old short-form name 'cle' for the order_id partial index.
ALTER INDEX IF EXISTS commerce.idx_cle_order_id
    RENAME TO idx_cm_order_id;

COMMIT;
