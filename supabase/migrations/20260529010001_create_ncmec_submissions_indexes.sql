CREATE INDEX IF NOT EXISTS ncmec_submissions_pending_idx
    ON moderation.ncmec_submissions (status, created_at)
    WHERE status IN ('pending', 'submitting', 'failed');

CREATE INDEX IF NOT EXISTS ncmec_submissions_quarantine_idx
    ON moderation.ncmec_submissions (csam_quarantine_id);
