-- Phase 3.5 follow-up: payout items can now reference either a (legacy) ledger entry
-- or a (new) commerce.orders row. Swap the NOT NULL + unique constraints accordingly.

-- Make commission_ledger_entry_id nullable.
ALTER TABLE commerce.commission_payout_items
    ALTER COLUMN commission_ledger_entry_id DROP NOT NULL;

-- Drop the old single-column unique constraint (was: cpi_unique_entry on commission_ledger_entry_id).
ALTER TABLE commerce.commission_payout_items
    DROP CONSTRAINT IF EXISTS cpi_unique_entry;

-- Add a CHECK: at least one of {ledger_entry_id, order_id} must be set.
ALTER TABLE commerce.commission_payout_items
    DROP CONSTRAINT IF EXISTS cpi_link_target_check,
    ADD CONSTRAINT cpi_link_target_check
        CHECK (commission_ledger_entry_id IS NOT NULL OR order_id IS NOT NULL);

-- Re-create unique constraints, but now as partial indexes (one for each link type).
CREATE UNIQUE INDEX IF NOT EXISTS cpi_unique_ledger_entry
    ON commerce.commission_payout_items (commission_ledger_entry_id)
    WHERE commission_ledger_entry_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS cpi_unique_order
    ON commerce.commission_payout_items (order_id)
    WHERE order_id IS NOT NULL;

-- FK from order_id to commerce.orders (added now that schema is stable).
ALTER TABLE commerce.commission_payout_items
    DROP CONSTRAINT IF EXISTS cpi_order_fk,
    ADD CONSTRAINT cpi_order_fk
        FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE RESTRICT;
