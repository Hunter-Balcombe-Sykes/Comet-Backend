-- OV-D: feedback tool — category/target picker + type taxonomy.
--
-- Extends core.feedback (not a new table) per the "extend, don't duplicate"
-- directive. Three new NULLABLE columns, no DB default — every existing row
-- reads NULL for all three; nothing to backfill.
--
--   type    - which of the four reaction buckets this feedback is:
--             error | good | bad_ui | idea. Deliberately a SEPARATE
--             taxonomy from the existing `kind` column (bug/idea/praise/
--             question/other) rather than a rename or overload of it —
--             `kind` predates any shipped frontend consumer (see
--             docs/superpowers/plans/2026-05-25-feedback-system.md) and
--             OV-D's dashboard page (`/account/feedback`, OV-D-FE) is the
--             first real caller, submitting `type` going forward.
--             App-layer enforces the 4-value vocabulary
--             (SubmitFeedbackRequest) — `kind` stays NOT NULL at the DB
--             layer, backfilled from `type` when a caller omits `kind`
--             (see FeedbackService::deriveKind()).
--   area    - free-form "which feature/page/tool" string the frontend
--             picker sends (e.g. "analytics", "gallery", "booking"). No
--             CHECK — the picker's option set lives in the frontend, not
--             the DB, so new areas ship without a migration.
--   target  - optional structured companion to `area` for machine-
--             readable context (e.g. {"area":"analytics","elementId":"x"}).
--             Open shape by design — same posture as tags/internal_notes
--             on this table.
--
-- No CHECK constraint on `type`/`area`: scripts/guard-no-unsafe-migrations.php
-- (Check 3) fails any ADD CONSTRAINT ... CHECK without NOT VALID + a
-- follow-up VALIDATE CONSTRAINT migration, and unlike its index check there
-- is no same-file-ADD-COLUMN exemption for CHECK constraints. That two-step
-- dance is unwarranted complexity for a low-traffic internal tool —
-- SubmitFeedbackRequest is the enforcement point, mirroring the existing
-- precedent that page_url/user_agent/viewport/app_version/request_id/
-- reply_email on this same table also have no DB CHECK.
--
-- Indexes below are created INLINE (not CONCURRENTLY): both indexed columns
-- are ADD COLUMN-ed in this same file, so every existing row is NULL for
-- them — the guard's own Check 1 exempts same-file new columns from the
-- CONCURRENTLY requirement, and this mirrors the original migration's own
-- "table is new/empty" reasoning (supabase/migrations/20260526210001).

BEGIN;

ALTER TABLE core.feedback
    ADD COLUMN type text NULL,
    ADD COLUMN area text NULL,
    ADD COLUMN target jsonb NULL;

CREATE INDEX feedback_type_idx
    ON core.feedback (type)
    WHERE deleted_at IS NULL;

CREATE INDEX feedback_area_idx
    ON core.feedback (area)
    WHERE deleted_at IS NULL;

COMMENT ON COLUMN core.feedback.type IS
    'OV-D reaction bucket: error | good | bad_ui | idea. Separate from `kind` (legacy bug/idea/praise/question/other taxonomy) — validated at the FormRequest layer, no DB CHECK (see migration header).';
COMMENT ON COLUMN core.feedback.area IS
    'OV-D free-form feature/page/tool picker value the frontend sends (e.g. "analytics"). No fixed vocabulary at the DB layer.';
COMMENT ON COLUMN core.feedback.target IS
    'OV-D optional structured companion to `area` for machine-readable context, e.g. {"area":"analytics","elementId":"x"}. Open shape, size-capped at the FormRequest layer.';

COMMIT;
