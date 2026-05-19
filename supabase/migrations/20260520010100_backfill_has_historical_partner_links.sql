-- Plan §28.16 Migration A (backfill).
--
-- Sets has_historical_partner_links=true for every professional who appears
-- as an affiliate in any brand_partner_links row. At backfill time no row is
-- soft-deleted yet (deleted_at column was just added), so "any row" = "any
-- active or historical link". Going forward, the observer maintains the
-- column on every link create / soft-delete / force-delete.
--
-- Idempotency: the `AND has_historical_partner_links = false` guard makes
-- re-runs no-ops. Per CONVENTIONS §5 the backfill runs outside a transaction
-- so the UPDATE's row locks don't stack with the column-add's catalog locks.
--
-- To revert: UPDATE core.professionals SET has_historical_partner_links = false;

UPDATE core.professionals AS p
   SET has_historical_partner_links = true
 WHERE p.has_historical_partner_links = false
   AND EXISTS (
       SELECT 1
         FROM brand.brand_partner_links l
        WHERE l.affiliate_professional_id = p.id
   );
