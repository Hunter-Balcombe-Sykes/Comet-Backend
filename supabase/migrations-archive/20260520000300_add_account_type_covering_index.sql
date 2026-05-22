-- Plan §28.1 step 4 (audit MIG-1).
--
-- NOTE: this migration intentionally runs WITHOUT a BEGIN/COMMIT wrapper
-- because CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
-- Do not add transaction wrapping here.
--
-- Covering index for capability-gating, notification fan-outs, and any list
-- endpoint that filters by account_type.
--
-- To revert: DROP INDEX CONCURRENTLY IF EXISTS core.professionals_account_type_idx;

CREATE INDEX CONCURRENTLY IF NOT EXISTS professionals_account_type_idx
    ON core.professionals (account_type);
