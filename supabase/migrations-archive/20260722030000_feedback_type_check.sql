-- #SCHEMA-6: add the DB CHECK that the OV-D migration (20260711153000_feedback_
-- type_area_target.sql) deliberately deferred to the FormRequest layer.
--
-- core.feedback.type is a NULLABLE column with a closed four-value vocabulary
-- (error | good | bad_ui | idea). SubmitFeedbackRequest already enforces it at the
-- app layer; this adds DB-level defence-in-depth so a direct write / buggy job /
-- migration error can't seed a fifth value. `area` (free-form) and `target` (open
-- jsonb) stay unconstrained by design — their vocabularies live in the frontend.
--
-- Two-step NOT VALID → VALIDATE in SEPARATE transactions (guard Check 8): the ADD
-- takes a brief metadata lock and releases it at COMMIT; VALIDATE then re-scans
-- under the lighter SHARE UPDATE EXCLUSIVE. core.feedback is a low-traffic staff
-- table (not a HOT_TABLES table), so validation is trivial regardless.

BEGIN;
ALTER TABLE core.feedback
    ADD CONSTRAINT feedback_type_check
    CHECK (type IS NULL OR type IN ('error', 'good', 'bad_ui', 'idea')) NOT VALID;
COMMIT;

BEGIN;
ALTER TABLE core.feedback VALIDATE CONSTRAINT feedback_type_check;
COMMIT;
