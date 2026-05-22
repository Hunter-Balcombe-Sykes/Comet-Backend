-- To revert: DROP TABLE IF EXISTS brand.signup_code_audit;

BEGIN;

-- MIG-5 guard: ensures gen_random_uuid() is available on fresh CI databases
-- and restored backups where pgcrypto may not yet be enabled.
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;

CREATE TABLE brand.signup_code_audit (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  brand_profile_id      uuid NOT NULL REFERENCES brand.brand_profiles(id),
  event                 text NOT NULL CHECK (event IN ('generated','rotated','deactivated','reactivated','claimed','failed_claim')),
  actor_type            text NOT NULL CHECK (actor_type IN ('system','brand','staff','public')),
  actor_professional_id uuid REFERENCES core.professionals(id),
  staff_user_id         uuid,
  source_ip             text,
  -- First 4 chars of the code, hashed — for forensic correlation without storing the live code.
  code_prefix_hash      text,
  -- Set only on 'claimed' events; enforced by compound CHECK below.
  joined_professional_id uuid,
  created_at            timestamptz NOT NULL DEFAULT now(),
  -- Compound integrity: a 'claimed' event must record who claimed it.
  CHECK ((event = 'claimed' AND joined_professional_id IS NOT NULL) OR (event <> 'claimed'))
);

-- Covers the most common query shapes: per-brand dashboard aggregates and
-- forensic lookups by event type within a time window.
CREATE INDEX ON brand.signup_code_audit (brand_profile_id, event, created_at DESC);

COMMIT;
