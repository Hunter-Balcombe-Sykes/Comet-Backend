-- Add structured "about" payload to core.professionals.
-- Shape (enforced in the application layer, not the DB):
--   { "credentials": [{ title, issuer, year }], "experience": [{ role, place, start, end, description }] }
-- The DB only guarantees that `about` is a JSON object so queries can safely use -> / ->> operators.

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS about jsonb NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS professionals_about_is_object;

ALTER TABLE core.professionals
    ADD CONSTRAINT professionals_about_is_object
    CHECK (jsonb_typeof(about) = 'object');
