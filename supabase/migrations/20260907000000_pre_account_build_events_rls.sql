-- Get Started rebuild pre-flight (2026-09-07): core.pre_account_build_events was
-- created in the 2026-07-26 baseline collapse without RLS or grants — every sibling
-- table in this feature (core.pre_account_builds) has RLS enabled; this one was an
-- oversight. It has no user_id column (it's build_id-scoped, joins to
-- core.pre_account_builds for ownership), so mirror pre_account_builds' own
-- service_role + staff-select policy pair rather than a per-row owner policy.
--
-- ROLLBACK: DROP POLICY IF EXISTS "pre_account_build_events_service_role_all" ON "core"."pre_account_build_events";
--             DROP POLICY IF EXISTS "pre_account_build_events_staff_select" ON "core"."pre_account_build_events";
--             ALTER TABLE "core"."pre_account_build_events" DISABLE ROW LEVEL SECURITY;

BEGIN;

ALTER TABLE "core"."pre_account_build_events" ENABLE ROW LEVEL SECURITY;

CREATE POLICY "pre_account_build_events_service_role_all" ON "core"."pre_account_build_events"
    TO "service_role" USING (true) WITH CHECK (true);

CREATE POLICY "pre_account_build_events_staff_select" ON "core"."pre_account_build_events"
    FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
        FROM "core"."partna_staff" "ps"
        WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));

COMMIT;
