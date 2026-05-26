BEGIN;

-- AUSTRAC-grade money-movement ledger for a single professional's wallet.
-- Every credit (top_up, reversal_credit) and debit (payout_debit, clawback_debit, etc.)
-- is recorded here with a mandatory idempotency_key so re-delivered webhooks/jobs
-- can detect and skip duplicates at the DB layer.
CREATE TABLE IF NOT EXISTS commerce.wallet_movements (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id     uuid NOT NULL REFERENCES core.professionals(id) ON DELETE RESTRICT,
    direction           text NOT NULL CHECK (direction IN ('credit','debit')),
    amount_cents        bigint NOT NULL CHECK (amount_cents > 0),
    currency_code       char(3) NOT NULL,
    reason              text NOT NULL CHECK (reason IN
        ('top_up','payout_debit','retry_refund','currency_mismatch_refund',
         'manual_adjustment','reversal_credit','clawback_debit')),
    -- AUSTRAC audit trail — every row records who/what initiated the movement.
    actor_type          text NOT NULL CHECK (actor_type IN ('system','webhook','job','admin','professional')),
    actor_id            text,
    related_payout_id   uuid REFERENCES commerce.commission_payouts(id) ON DELETE SET NULL,
    related_session_id  text,
    idempotency_key     text NOT NULL,
    metadata            jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at         timestamptz NOT NULL DEFAULT now(),
    created_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (idempotency_key)
);

-- Non-system actors must supply an actor_id for the audit trail.
ALTER TABLE commerce.wallet_movements
    ADD CONSTRAINT chk_wallet_movements_actor_id_required
    CHECK (actor_type = 'system' OR actor_id IS NOT NULL);

CREATE INDEX idx_wallet_movements_pro_occurred
    ON commerce.wallet_movements (professional_id, occurred_at DESC);

CREATE INDEX idx_wallet_movements_payout
    ON commerce.wallet_movements (related_payout_id) WHERE related_payout_id IS NOT NULL;

CREATE INDEX idx_wallet_movements_session
    ON commerce.wallet_movements (related_session_id) WHERE related_session_id IS NOT NULL;

CREATE INDEX idx_wallet_movements_actor
    ON commerce.wallet_movements (actor_type, actor_id) WHERE actor_id IS NOT NULL;

-- RLS: tenant-scoped read. Matches the commerce.* pattern — professional_id resolved
-- via subquery on core.professionals.auth_user_id = auth.uid().
ALTER TABLE commerce.wallet_movements ENABLE ROW LEVEL SECURITY;

CREATE POLICY wallet_movements_tenant_read ON commerce.wallet_movements
    FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.professionals WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

-- Writes come exclusively from the app_backend role (BYPASSRLS) or staff corrections.
-- Professionals never write ledger rows directly.
CREATE POLICY wallet_movements_staff_insert ON commerce.wallet_movements
    FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

GRANT SELECT, INSERT ON commerce.wallet_movements TO app_backend;

COMMIT;

-- DOWN:
-- BEGIN;
-- DROP POLICY IF EXISTS wallet_movements_staff_insert ON commerce.wallet_movements;
-- DROP POLICY IF EXISTS wallet_movements_tenant_read ON commerce.wallet_movements;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_actor;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_session;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_payout;
-- DROP INDEX IF EXISTS commerce.idx_wallet_movements_pro_occurred;
-- DROP TABLE IF EXISTS commerce.wallet_movements;
-- COMMIT;
