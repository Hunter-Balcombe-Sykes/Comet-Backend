-- moderation.ncmec_submissions — outbox pattern for CyberTipline submissions.
-- Worker writes the row first, attempts submission, updates with ncmec_tip_id on success.
-- After 5 failed attempts → status='manual_fallback_required' + Nightwatch alert.

BEGIN;

CREATE TABLE IF NOT EXISTS moderation.ncmec_submissions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    csam_quarantine_id       UUID NOT NULL,
    payload                  JSONB NOT NULL,
    status                   VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts                 SMALLINT NOT NULL DEFAULT 0,
    ncmec_tip_id             VARCHAR(64) NULL,
    ncmec_response_payload   JSONB NULL,
    last_error               TEXT NULL,
    submitted_at             TIMESTAMPTZ NULL,
    response_received_at     TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT ncmec_submissions_quarantine_fk FOREIGN KEY (csam_quarantine_id)
        REFERENCES moderation.csam_quarantine(id) ON DELETE RESTRICT,
    CONSTRAINT ncmec_submissions_status_check CHECK (status IN (
        'pending', 'submitting', 'submitted', 'failed', 'manual_fallback_required'
    ))
);

COMMIT;
