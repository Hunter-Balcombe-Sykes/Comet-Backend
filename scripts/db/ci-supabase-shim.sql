-- Minimum Supabase surface a from-zero apply of supabase/migrations/ needs, for a
-- BARE postgres:16 (the CI service container). Supabase's own platform provides all
-- of this; a stock Postgres provides none of it, which is why the schema lane could
-- not exist before.
--
-- Deliberately the SMALLEST shim that works, not a Supabase emulator. The whole
-- dependency surface of the baseline is three roles, one function and one table:
--
--   anon / authenticated / service_role  — GRANT targets throughout the dump
--   auth.uid()                           — referenced by 173 RLS policies
--   auth.users(id)                       — target of two FKs (core.users,
--                                          core.partna_staff)
--
-- Nothing here is asserted ON. This file exists so the migrations can APPLY; the
-- tests that then run assert the constraints the migrations created. If a future
-- migration needs more Supabase surface, the apply fails loudly in CI rather than
-- silently skipping — which is the failure mode this whole lane replaces.

-- Roles are cluster-level; the baseline creates app_backend itself but assumes these.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
        CREATE ROLE "anon" NOLOGIN NOINHERIT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
        CREATE ROLE "authenticated" NOLOGIN NOINHERIT;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
        CREATE ROLE "service_role" NOLOGIN NOINHERIT BYPASSRLS;
    END IF;
END $$;

CREATE SCHEMA IF NOT EXISTS "auth";

-- Stands in for auth.users purely as an FK target. Only `id` matters: both
-- referencing FKs point at it, and no test in this lane authenticates.
CREATE TABLE IF NOT EXISTS "auth"."users" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "email" text
);

-- Supabase resolves this from the request JWT. In this lane there is no request,
-- so it returns NULL — which makes every auth.uid()-based RLS policy deny. That is
-- the correct posture here: the lane asserts that constraints EXIST and are
-- VALIDATED (pg_constraint), never that a policy admits a particular caller.
CREATE OR REPLACE FUNCTION "auth"."uid"() RETURNS uuid
    LANGUAGE sql STABLE
    AS $$ SELECT NULL::uuid $$;

GRANT USAGE ON SCHEMA "auth" TO "anon", "authenticated", "service_role";
