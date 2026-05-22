-- Add deletion state columns to core.professionals
ALTER TABLE core.professionals
  ADD COLUMN IF NOT EXISTS deletion_token_hash text,
  ADD COLUMN IF NOT EXISTS deletion_requested_at timestamptz,
  ADD COLUMN IF NOT EXISTS deletion_confirmed_at timestamptz,
  ADD COLUMN IF NOT EXISTS deletion_previous_status text;

-- Index for fast token lookup during confirmation
CREATE INDEX IF NOT EXISTS idx_professionals_deletion_token_hash
  ON core.professionals (deletion_token_hash)
  WHERE deletion_token_hash IS NOT NULL;

-- Index for efficient purge query (finding accounts past grace period)
CREATE INDEX IF NOT EXISTS idx_professionals_pending_deletion_cutoff
  ON core.professionals (deletion_confirmed_at)
  WHERE status = 'pending_deletion';

-- Add status CHECK constraint (currently plain text with no validation)
ALTER TABLE core.professionals
  DROP CONSTRAINT IF EXISTS professionals_status_check;

ALTER TABLE core.professionals
  ADD CONSTRAINT professionals_status_check
  CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion'));

-- Audit table — survives the professional's hard delete via identity snapshots
CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid REFERENCES core.professionals(id) ON DELETE SET NULL,
    professional_handle_snapshot text NOT NULL,
    professional_email_snapshot text NOT NULL,
    event text NOT NULL CHECK (event IN ('requested', 'confirmed', 'cancelled', 'purged', 'purge_failed')),
    ip_address text,
    user_agent text,
    metadata jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE core.professional_deletion_audit OWNER TO postgres;

CREATE INDEX idx_pda_professional_id ON core.professional_deletion_audit (professional_id);
CREATE INDEX idx_pda_event_created ON core.professional_deletion_audit (event, created_at DESC);
