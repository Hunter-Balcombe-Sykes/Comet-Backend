-- F13 (P2): the two-person CSAM-override rule did not stop a single staffer from
-- satisfying it by naming themselves as the second approver. Strengthen the DB
-- CHECK so the second approver must differ from the deciding staffer.
--
-- CONVENTIONS.md §2: ADD CONSTRAINT ... NOT VALID, then VALIDATE in a separate
-- transaction (lock-light even though decisions is empty pre-launch).

BEGIN;

ALTER TABLE moderation.decisions
    DROP CONSTRAINT IF EXISTS decisions_csam_override_requires_second_staff;

ALTER TABLE moderation.decisions
    ADD CONSTRAINT decisions_csam_override_requires_second_staff CHECK (
        decision_type <> 'override_csam_auto_action'
        OR (
            second_staff_approval_id IS NOT NULL
            AND second_staff_approved_at IS NOT NULL
            AND second_staff_approval_id <> decided_by_staff_id
        )
    ) NOT VALID;

COMMIT;

BEGIN;

ALTER TABLE moderation.decisions
    VALIDATE CONSTRAINT decisions_csam_override_requires_second_staff;

COMMIT;
