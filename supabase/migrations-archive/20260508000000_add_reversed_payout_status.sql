-- Expand commission_payouts.status to include 'reversed'.
--
-- 'reversed' is semantically distinct from 'failed': transfer.failed means funds
-- never left the platform; transfer.reversed means funds reached the affiliate
-- and were subsequently clawed back by Stripe (compliance hold, account closure).
-- A reversed payout requires manual recovery — the brand was charged but the
-- affiliate's Stripe balance was drained.

ALTER TABLE commerce.commission_payouts
    DROP CONSTRAINT IF EXISTS cp_status_check;

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT cp_status_check CHECK (
        status IN (
            'pending',
            'pending_funds',
            'collecting',
            'collected',
            'transferring',
            'completed',
            'failed',
            'cancelled',
            'reversed'
        )
    );
