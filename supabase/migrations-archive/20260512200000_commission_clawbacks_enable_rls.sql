-- Enable RLS on commerce.commission_clawbacks to match the codebase convention
-- established by commission_payouts, wallet_movements, and orders. The original
-- create migration (20260512000000) omitted RLS — this is the follow-up.
--
-- Policy shape mirrors commission_payouts (payouts_party_select / payouts_staff_update
-- / payouts_staff_write): SELECT for the parties (brand owner + affiliate owner) via
-- the parent payout's professional FKs, plus staff INSERT/UPDATE. app_backend bypasses
-- RLS via the BYPASSRLS role attribute so server-side writes are unaffected.

ALTER TABLE commerce.commission_clawbacks ENABLE ROW LEVEL SECURITY;

-- SELECT: brand owner, affiliate owner, or partna_staff. Resolved via the parent
-- payout's professional FKs so we don't duplicate the FK columns onto this table.
CREATE POLICY clawbacks_party_select
    ON commerce.commission_clawbacks
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1
            FROM commerce.commission_payouts p
            WHERE p.id = commission_clawbacks.payout_id
              AND (
                p.brand_professional_id = (
                    SELECT id FROM core.professionals
                    WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
                )
                OR p.affiliate_professional_id = (
                    SELECT id FROM core.professionals
                    WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
                )
            )
        )
        OR EXISTS (
            SELECT 1 FROM core.partna_staff s
            WHERE s.auth_user_id = auth.uid()
        )
    );

-- INSERT: staff only. app_backend bypasses RLS for server-issued writes.
CREATE POLICY clawbacks_staff_write
    ON commerce.commission_clawbacks
    FOR INSERT
    TO authenticated
    WITH CHECK (
        EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

-- UPDATE: staff only (e.g. manually flipping status to 'manual_recovered').
CREATE POLICY clawbacks_staff_update
    ON commerce.commission_clawbacks
    FOR UPDATE
    TO authenticated
    USING (
        EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    )
    WITH CHECK (
        EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );
