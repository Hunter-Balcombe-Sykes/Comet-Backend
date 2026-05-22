-- Wrap in a single transaction so backfill + NOT NULL flip are atomic; without
-- this, a concurrent insert during deploy could land between the UPDATE and
-- SET NOT NULL with actor_type IS NULL and abort the migration.
BEGIN;

-- Extend deletion audit with actor identity + GDPR reason field
ALTER TABLE core.professional_deletion_audit
  ADD COLUMN IF NOT EXISTS actor_type text,
  ADD COLUMN IF NOT EXISTS actor_id uuid,
  ADD COLUMN IF NOT EXISTS actor_handle_snapshot text,
  ADD COLUMN IF NOT EXISTS reason text;

-- Backfill existing rows: every pre-existing audit row was self-service.
UPDATE core.professional_deletion_audit
SET actor_type = 'professional'
WHERE actor_type IS NULL;

-- Constrain actor_type after backfill.
ALTER TABLE core.professional_deletion_audit
  ALTER COLUMN actor_type SET NOT NULL;

ALTER TABLE core.professional_deletion_audit
  DROP CONSTRAINT IF EXISTS professional_deletion_audit_actor_type_check;

ALTER TABLE core.professional_deletion_audit
  ADD CONSTRAINT professional_deletion_audit_actor_type_check
  CHECK (actor_type IN ('professional', 'staff_admin', 'system'));

-- Widen event CHECK to allow admin events.
ALTER TABLE core.professional_deletion_audit
  DROP CONSTRAINT IF EXISTS professional_deletion_audit_event_check;

ALTER TABLE core.professional_deletion_audit
  ADD CONSTRAINT professional_deletion_audit_event_check
  CHECK (event IN (
    'requested',
    'confirmed',
    'cancelled',
    'purged',
    'purge_failed',
    'admin_initiated',
    'admin_cancelled'
  ));

-- Index for "show me all admin-initiated erasures in date range" reports.
CREATE INDEX IF NOT EXISTS idx_pda_actor_type_created
  ON core.professional_deletion_audit (actor_type, created_at DESC)
  WHERE actor_type <> 'professional';

COMMIT;
