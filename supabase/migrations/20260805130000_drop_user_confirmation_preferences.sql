-- Decommission core.user_confirmation_preferences (2026-08-05 platform audit).
--
-- The "don't ask again" confirmation-preference lane (routes, controller,
-- service, model, data-export section) is deleted in the same change set: no
-- dashboard ever called the endpoints, the table held zero rows on dev, and
-- the FE's ConfirmAction component manages its prompts without it.
-- ROLLBACK: NONE. Zero rows on dev and the owning code lane is deleted in
-- this change set; there is no state to restore.
DROP TABLE IF EXISTS "core"."user_confirmation_preferences";
-- The trigger drops with its table; its function does not — remove it too.
DROP FUNCTION IF EXISTS "core"."set_user_confirmation_preferences_updated_at"();
