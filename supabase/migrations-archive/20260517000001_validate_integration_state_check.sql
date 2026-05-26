-- DATA-2 follow-up — validate the webhook_registration_state CHECK.
--
-- The previous migration added the CHECK as NOT VALID and backfilled every
-- row to a value in the allowed set (or left it NULL). This VALIDATE pass
-- scans the table under SHARE UPDATE EXCLUSIVE — concurrent reads and
-- writes continue uninterrupted.

BEGIN;

ALTER TABLE core.professional_integrations
    VALIDATE CONSTRAINT professional_integrations_webhook_registration_state_check;

COMMIT;
