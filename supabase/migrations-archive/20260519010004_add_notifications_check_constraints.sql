-- Audit fix #SCHEMA-4: CHECK constraint on notifications.notifications.type.
--
-- The 202605190000002_add_enum_check_constraints.sql sweep missed
-- notifications.notifications.type — treated as an application-level enum
-- (Notification::FRONTEND_TYPES) but unconstrained at the DB level. A typo or
-- raw DB::update() could write an unrecognised value that silently falls
-- through to the default 'Info' icon mapping on the frontend.
--
-- (Two values from the audit were already covered by the v2 baseline and
-- need no change here:
--   - notifications.severity has CHECK (severity IN ('info','warning','critical'))
--     from baseline line 964.
--   - notification_email_policies.mode has CHECK (mode IN ('default','force_on','force_off'))
--     from baseline line 1020.)
--
-- Uses the NOT VALID + VALIDATE pattern (§2 of CONVENTIONS.md) to avoid write
-- downtime, matching the sweep migration this completes.

-- ─── Step 1: add constraint as NOT VALID ───────────────────────────────────

BEGIN;

-- notifications.notifications.type — valid values: Notification::FRONTEND_TYPES.
ALTER TABLE notifications.notifications
    ADD CONSTRAINT notifications_type_check
    CHECK (type IN (
        'Success', 'Critical', 'Warning', 'Invitation', 'To do', 'Info'
    )) NOT VALID;

COMMIT;

-- ─── Step 2: validate (separate transaction, weaker lock) ──────────────────

ALTER TABLE notifications.notifications VALIDATE CONSTRAINT notifications_type_check;

-- DOWN (manual rollback — supabase db push is one-way; run in SQL editor if needed):
-- ALTER TABLE notifications.notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
