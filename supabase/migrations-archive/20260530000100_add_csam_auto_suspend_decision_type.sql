-- F2/F3/F4 support: add a dedicated 'csam_auto_suspend' decision_type.
--
-- The CSAM auto-action previously reused 'suspend_user', whose action set is
-- shared with human-report suspensions. That set lacked quarantine_media and
-- notify_oncall_staff (so CSAM media was never quarantined and on-call was never
-- paged), and it included notify_reported_user (which must NOT fire for CSAM —
-- never tip off a CSAM uploader). A distinct decision_type gives CSAM its own
-- explicit, auditable action set without affecting manual suspensions.
--
-- CONVENTIONS.md §2: ADD CONSTRAINT ... NOT VALID, then VALIDATE in a separate
-- transaction (lock-light even though decisions is empty pre-launch).

BEGIN;

ALTER TABLE moderation.decisions
    DROP CONSTRAINT IF EXISTS decisions_decision_type_check;

ALTER TABLE moderation.decisions
    ADD CONSTRAINT decisions_decision_type_check CHECK (decision_type IN (
        'dismiss', 'warn', 'hide_content', 'hide_site', 'suspend_user', 'ban_user',
        'csam_auto_suspend',
        'override_csam_auto_action', 'escalate_law_enforcement', 'escalate_esafety'
    )) NOT VALID;

COMMIT;

BEGIN;

ALTER TABLE moderation.decisions
    VALIDATE CONSTRAINT decisions_decision_type_check;

COMMIT;
