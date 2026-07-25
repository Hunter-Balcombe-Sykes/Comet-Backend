-- guard:no-unsafe-migrations:disable-file — collapsed baseline snapshot of the verified dev
-- schema (2026-07-26). Applies ONLY to an empty from-zero DB (prod cutover via psql, or
-- scripts/db/fresh-reset.sh locally) — never against live traffic, so lock-safety patterns
-- don't apply. Without this marker, Check 2 fails (pg_dump emits validated FKs as plain
-- ADD CONSTRAINT ... FOREIGN KEY without NOT VALID) and Check 5 fails (hot-table ALTER TABLE
-- with no BEGIN + SET LOCAL lock_timeout). See docs/deploy/production-cutover.md
-- "Migration collapse (rationale + method)" and its Phase-0 collapse checkbox, which record
-- how this file was produced and the parity proofs it passed.

-- Extensions: a --schema-filtered pg_dump does not emit CREATE EXTENSION.
-- pg_trgm MUST live in "public": this dump runs with search_path = '' and its trigram
-- indexes reference "public"."gin_trgm_ops" explicitly (verified against dev 2026-07-26,
-- where pg_extension reports pg_trgm installed in schema public).
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;

-- Runtime role bootstrap (cluster-level: pg_dump never emits roles or role attributes).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        -- NOLOGIN; password + LOGIN set out-of-band at prod cutover:
        --   ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret-store>';
        CREATE ROLE app_backend NOLOGIN;
    END IF;

    -- LOAD-BEARING (baseline decision 16): several FORCE-RLS tables have no explicit
    -- app_backend policy; without BYPASSRLS the app is default-denied on them.
    -- Verified on dev 2026-07-26: pg_roles.rolbypassrls = true for app_backend.
    EXECUTE 'ALTER ROLE app_backend BYPASSRLS';
END $$;




SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;


CREATE SCHEMA IF NOT EXISTS "analytics";


ALTER SCHEMA "analytics" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "audit";


ALTER SCHEMA "audit" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "core";


ALTER SCHEMA "core" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "moderation";


ALTER SCHEMA "moderation" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "notifications";


ALTER SCHEMA "notifications" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "public";


ALTER SCHEMA "public" OWNER TO "pg_database_owner";


COMMENT ON SCHEMA "public" IS 'standard public schema';



CREATE SCHEMA IF NOT EXISTS "site";


ALTER SCHEMA "site" OWNER TO "postgres";


CREATE TYPE "site"."enquiry_status" AS ENUM (
    'new',
    'read',
    'replied',
    'archived',
    'spam'
);


ALTER TYPE "site"."enquiry_status" OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "audit"."null_user_audit_links"("p_user_id" "uuid") RETURNS "void"
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO ''
    AS $$
BEGIN
    ALTER TABLE audit.staff_audit_log DISABLE TRIGGER staff_audit_log_reject_mutation;
    UPDATE audit.staff_audit_log SET user_id = NULL WHERE user_id = p_user_id;
    ALTER TABLE audit.staff_audit_log ENABLE TRIGGER staff_audit_log_reject_mutation;

    ALTER TABLE audit.handle_change_log DISABLE TRIGGER handle_change_log_no_update;
    UPDATE audit.handle_change_log SET user_id = NULL WHERE user_id = p_user_id;
    ALTER TABLE audit.handle_change_log ENABLE TRIGGER handle_change_log_no_update;
END;
$$;


ALTER FUNCTION "audit"."null_user_audit_links"("p_user_id" "uuid") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "audit"."prune_data_export_audit"("p_cutoff" timestamp with time zone) RETURNS integer
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO ''
    AS $$
DECLARE
    v_redacted integer;
BEGIN
    UPDATE audit.data_export_audit
       SET professional_email_snapshot = NULL,
           recipient_email = '[redacted]',
           professional_handle_snapshot = '[redacted]',
           error_message = NULL
     WHERE created_at < p_cutoff
       AND recipient_email <> '[redacted]';
    GET DIAGNOSTICS v_redacted = ROW_COUNT;
    RETURN v_redacted;
END;
$$;


ALTER FUNCTION "audit"."prune_data_export_audit"("p_cutoff" timestamp with time zone) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "audit"."prune_handle_change_log"("p_cutoff" timestamp with time zone) RETURNS integer
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO ''
    AS $$
DECLARE
    v_deleted integer;
BEGIN
    ALTER TABLE audit.handle_change_log DISABLE TRIGGER handle_change_log_no_update;
    DELETE FROM audit.handle_change_log WHERE changed_at < p_cutoff;
    GET DIAGNOSTICS v_deleted = ROW_COUNT;
    ALTER TABLE audit.handle_change_log ENABLE TRIGGER handle_change_log_no_update;
    RETURN v_deleted;
END;
$$;


ALTER FUNCTION "audit"."prune_handle_change_log"("p_cutoff" timestamp with time zone) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "audit"."prune_user_deletion_audit"("p_cutoff" timestamp with time zone) RETURNS integer
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO ''
    AS $$
DECLARE
    v_redacted integer;
BEGIN
    UPDATE audit.user_deletion_audit
       SET professional_email_snapshot = '[redacted]',
           professional_handle_snapshot = '[redacted]',
           ip_address = NULL,
           user_agent = NULL,
           actor_handle_snapshot = NULL,
           reason = NULL,
           metadata = NULL
     WHERE created_at < p_cutoff
       AND professional_email_snapshot <> '[redacted]';
    GET DIAGNOSTICS v_redacted = ROW_COUNT;
    RETURN v_redacted;
END;
$$;


ALTER FUNCTION "audit"."prune_user_deletion_audit"("p_cutoff" timestamp with time zone) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."enforce_site_gallery_max6"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
declare
  cnt int;
begin
  if new.pool is distinct from 'gallery' then
    return new;
  end if;

  if new.deleted_at is not null then
    return new;
  end if;

  if tg_op = 'UPDATE' then
    if old.site_id = new.site_id and old.deleted_at is null and new.deleted_at is null then
      return new;
    end if;
  end if;

  select count(*)
    into cnt
  from site.site_media si
  where si.site_id = new.site_id
    and si.pool = 'gallery'
    and si.deleted_at is null
    and si.processing_state <> 'failed'
    and (tg_op <> 'UPDATE' or si.id <> new.id);

  if cnt >= 6 then
    raise exception 'Gallery limit reached: max 6 images per site';
  end if;

  return new;
end;
$$;


ALTER FUNCTION "core"."enforce_site_gallery_max6"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."live_session_ids"("p_user_id" "uuid") RETURNS SETOF "uuid"
    LANGUAGE "sql" STABLE SECURITY DEFINER
    SET "search_path" TO ''
    AS $$
    SELECT id FROM auth.sessions WHERE user_id = p_user_id
$$;


ALTER FUNCTION "core"."live_session_ids"("p_user_id" "uuid") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."prevent_staff_escalation"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
declare
  uid uuid := (select auth.uid());
  is_admin boolean;
begin
  if uid is null then
    return new;
  end if;

  select exists (
    select 1
    from core.partna_staff cs
    where cs.auth_user_id = uid
      and cs.role = 'admin'
  ) into is_admin;

  if not is_admin then
    if new.role is distinct from old.role then
      raise exception 'Only admins can change staff role';
    end if;

    if new.auth_user_id is distinct from old.auth_user_id then
      raise exception 'Only admins can change auth_user_id';
    end if;
  end if;

  return new;
end;
$$;


ALTER FUNCTION "core"."prevent_staff_escalation"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."reject_staff_audit_log_mutation"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
    RAISE EXCEPTION 'audit.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;


ALTER FUNCTION "core"."reject_staff_audit_log_mutation"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."set_media_variants_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;


ALTER FUNCTION "core"."set_media_variants_updated_at"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."set_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


ALTER FUNCTION "core"."set_updated_at"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."set_user_confirmation_preferences_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;


ALTER FUNCTION "core"."set_user_confirmation_preferences_updated_at"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."trg_handle_change_log_append_only"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
    RAISE EXCEPTION 'audit.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;


ALTER FUNCTION "core"."trg_handle_change_log_append_only"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."trg_user_handle_alias_check"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
DECLARE v_blocking_user uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN RETURN NEW; END IF;
    SELECT user_id INTO v_blocking_user FROM core.user_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle) AND user_id <> NEW.id
       AND (expires_at IS NULL OR expires_at > now()) LIMIT 1;
    IF v_blocking_user IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another user', NEW.handle USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION "core"."trg_user_handle_alias_check"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."trg_user_handle_change"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
DECLARE v_reclaim_days int := 14; v_redirect_days int := 90;
BEGIN
    INSERT INTO core.user_handle_aliases (user_id, handle, reclaim_until, expires_at)
    VALUES (NEW.id, OLD.handle, now() + (v_reclaim_days || ' days')::interval, now() + (v_redirect_days || ' days')::interval)
    ON CONFLICT DO NOTHING;
    RETURN NEW;
END;
$$;


ALTER FUNCTION "core"."trg_user_handle_change"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."set_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."set_updated_at"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "site"."compute_user_url"("p_professional_id" "uuid") RETURNS "text"
    LANGUAGE "plpgsql" STABLE
    SET "search_path" TO ''
    AS $$
DECLARE v_subdomain text;
BEGIN
    SELECT s.subdomain INTO v_subdomain FROM site.sites s WHERE s.user_id = p_professional_id;
    IF v_subdomain IS NULL THEN RETURN NULL; END IF;
    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;


ALTER FUNCTION "site"."compute_user_url"("p_professional_id" "uuid") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "site"."create_empty_design_kit"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
  INSERT INTO site.design_kits (site_id) VALUES (NEW.id) ON CONFLICT (site_id) DO NOTHING;
  RETURN NEW;
END;
$$;


ALTER FUNCTION "site"."create_empty_design_kit"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "site"."trg_recompute_partna_url"("p_professional_id" "uuid") RETURNS "void"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_user_url(p_professional_id);

    UPDATE core.users
       SET partna_url = v_url
     WHERE id = p_professional_id;
END;
$$;


ALTER FUNCTION "site"."trg_recompute_partna_url"("p_professional_id" "uuid") OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "site"."trg_sites_url_sync"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO ''
    AS $$
BEGIN
    PERFORM site.trg_recompute_partna_url(NEW.user_id);
    RETURN NEW;
END;
$$;


ALTER FUNCTION "site"."trg_sites_url_sync"() OWNER TO "postgres";

SET default_tablespace = '';

SET default_table_access_method = "heap";


CREATE TABLE IF NOT EXISTS "analytics"."action_events" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "site_id" "uuid" NOT NULL,
    "action_id" "text" NOT NULL,
    "event" "text" NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "country_code" "text",
    "device_type" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "action_events_event_check" CHECK (("event" = ANY (ARRAY['seen'::"text", 'tap'::"text"])))
);


ALTER TABLE "analytics"."action_events" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."action_events" IS 'Raw exposure/tap events for the unified actions system. action_id = ActionVocabulary id. Read by RankedActionsComputer for demand-rate scoring; PII purged on account deletion (AccountDeletionService::purgeActionEventsPii).';



CREATE TABLE IF NOT EXISTS "analytics"."content_popularity_scores" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "site_id" "uuid" NOT NULL,
    "content_type" "text" NOT NULL,
    "content_key" "text" NOT NULL,
    "score" double precision NOT NULL,
    "rank" integer NOT NULL,
    "computed_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "content_popularity_scores_content_type_check" CHECK (("content_type" = ANY (ARRAY['page'::"text", 'action'::"text", 'shop_product'::"text", 'menu_item'::"text", 'menu_category'::"text", 'service'::"text", 'block'::"text", 'gallery_item'::"text", 'engine_item'::"text", 'listen_item'::"text", 'watch_item'::"text", 'link_item'::"text"])))
);

ALTER TABLE ONLY "analytics"."content_popularity_scores" FORCE ROW LEVEL SECURITY;


ALTER TABLE "analytics"."content_popularity_scores" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."content_popularity_scores" IS 'Popularity ranks per site x content (pages + scored items). Upserted by analytics:compute-popularity. content_type enumerated in-code (SitepageId + scored-item taxonomy); content_key = page-id for pages, item/product id otherwise.';



CREATE TABLE IF NOT EXISTS "analytics"."item_views" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "site_id" "uuid" NOT NULL,
    "item_type" "text" NOT NULL,
    "item_id" "text" NOT NULL,
    "item_title" "text",
    "section_key" "text",
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "country_code" "text",
    "device_type" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "item_views_item_type_check" CHECK (("item_type" = ANY (ARRAY['shop_product'::"text", 'menu_item'::"text", 'menu_category'::"text", 'service'::"text", 'block'::"text", 'gallery_item'::"text", 'engine_item'::"text", 'listen_item'::"text", 'watch_item'::"text", 'link_item'::"text"])))
);

ALTER TABLE ONLY "analytics"."item_views" FORCE ROW LEVEL SECURITY;


ALTER TABLE "analytics"."item_views" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."item_views" IS 'Item-level impression events for popularity scoring. Mirrors section_views columns; item_type per scored-item taxonomy, item_id = product/item id. Dedup via app-side Redis, not DB.';



CREATE TABLE IF NOT EXISTS "analytics"."lead_submissions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "subdomain" "text",
    "site_id" "uuid",
    "user_id" "uuid",
    "customer_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "outcome" "text" NOT NULL,
    "form_started_at_ms" bigint
);


ALTER TABLE "analytics"."lead_submissions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "analytics"."link_clicks" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "link_block_id" "uuid",
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "utm_source" "text",
    "utm_medium" "text",
    "utm_campaign" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "url" "text",
    "platform" "text",
    "product_id" "text",
    "product_title" "text",
    "section_key" "text",
    "label" "text",
    "country_code" "text",
    "region_code" "text",
    "device_type" "text"
);


ALTER TABLE "analytics"."link_clicks" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "analytics"."section_views" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "block_id" "uuid",
    "section_key" "text" NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "utm_source" "text",
    "utm_medium" "text",
    "utm_campaign" "text",
    "country_code" "text",
    "device_type" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "duration_ms" integer
);


ALTER TABLE "analytics"."section_views" OWNER TO "postgres";


COMMENT ON COLUMN "analytics"."section_views"."duration_ms" IS 'Cumulative visible-time (ms) from section-dwell beacons; GREATEST-merged, capped at 600000. NULL = no dwell reported.';



CREATE TABLE IF NOT EXISTS "analytics"."site_metrics_daily" (
    "day" "date" NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "timezone" "text" NOT NULL,
    "visits_count" integer DEFAULT 0 NOT NULL,
    "unique_visitors" integer DEFAULT 0 NOT NULL,
    "clicks_count" integer DEFAULT 0 NOT NULL,
    "unique_clickers" integer DEFAULT 0 NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "analytics"."site_metrics_daily" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "analytics"."site_metrics_hourly" (
    "hour_start" timestamp with time zone NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "timezone" "text" NOT NULL,
    "visits_count" integer DEFAULT 0 NOT NULL,
    "unique_visitors" integer DEFAULT 0 NOT NULL,
    "clicks_count" integer DEFAULT 0 NOT NULL,
    "unique_clickers" integer DEFAULT 0 NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "analytics"."site_metrics_hourly" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "analytics"."site_sessions" (
    "id" "uuid" NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "visitor_id" "uuid",
    "started_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "last_seen_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "duration_seconds" integer DEFAULT 0 NOT NULL,
    "country_code" "text",
    "region_code" "text",
    "device_type" "text",
    "referrer" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "site_sessions_duration_check" CHECK ((("duration_seconds" >= 0) AND ("duration_seconds" <= 86400)))
);

ALTER TABLE ONLY "analytics"."site_sessions" FORCE ROW LEVEL SECURITY;


ALTER TABLE "analytics"."site_sessions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "analytics"."site_visits" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "utm_source" "text",
    "utm_medium" "text",
    "utm_campaign" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "country_code" "text",
    "device_type" "text",
    "region_code" "text",
    "city" "text",
    "latitude" double precision,
    "longitude" double precision
);


ALTER TABLE "analytics"."site_visits" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "audit"."auth_factor_events" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "session_id" "uuid",
    "event_type" "text" NOT NULL,
    "factor_id" "uuid",
    "factor_type" "text",
    "ip" "inet",
    "user_agent" "text",
    "metadata" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "webhook_id" "text",
    CONSTRAINT "auth_factor_events_event_type_check" CHECK (("event_type" = ANY (ARRAY['enroll_started'::"text", 'enroll_completed'::"text", 'unenroll'::"text", 'challenge_issued'::"text", 'verify_success'::"text", 'verify_failed'::"text", 'verify_rejected_by_hook'::"text"]))),
    CONSTRAINT "auth_factor_events_factor_type_check" CHECK ((("factor_type" IS NULL) OR ("factor_type" = ANY (ARRAY['totp'::"text", 'phone'::"text", 'webauthn'::"text", 'recovery'::"text"]))))
);


ALTER TABLE "audit"."auth_factor_events" OWNER TO "postgres";


COMMENT ON COLUMN "audit"."auth_factor_events"."webhook_id" IS 'Supabase Standard Webhooks message id (webhook-id header) for hook-originated rows (verify_success/verify_failed/verify_rejected_by_hook). NULL for rows recorded by a direct user action (e.g. MfaController::destroy unenroll) — there is no webhook delivery behind those. Durable backstop to the Redis Cache::add idempotency anchor in SupabaseAuthHookController; see the partial unique index auth_factor_events_webhook_id_uk in the companion migration.';



CREATE TABLE IF NOT EXISTS "audit"."data_export_audit" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "professional_handle_snapshot" "text" NOT NULL,
    "professional_email_snapshot" "text",
    "triggered_by" "text" NOT NULL,
    "triggered_by_staff_id" "uuid",
    "recipient_email" "text" NOT NULL,
    "send_to" "text",
    "status" "text" DEFAULT 'queued'::"text" NOT NULL,
    "file_path" "text",
    "file_size_bytes" bigint,
    "file_sha256" "text",
    "record_counts" "jsonb",
    "error_message" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "completed_at" timestamp with time zone,
    "email_sent_at" timestamp with time zone,
    "email_delivery_status" "text",
    CONSTRAINT "data_export_audit_email_delivery_status_check" CHECK (("email_delivery_status" = ANY (ARRAY['sent'::"text", 'delivered'::"text", 'bounced'::"text", 'complaint'::"text"]))),
    CONSTRAINT "data_export_audit_send_to_chk" CHECK ((("send_to" IS NULL) OR ("send_to" = ANY (ARRAY['professional'::"text", 'staff'::"text"])))),
    CONSTRAINT "data_export_audit_status_chk" CHECK (("status" = ANY (ARRAY['queued'::"text", 'processing'::"text", 'completed'::"text", 'failed'::"text"]))),
    CONSTRAINT "data_export_audit_triggered_by_chk" CHECK (("triggered_by" = ANY (ARRAY['self'::"text", 'staff'::"text"])))
);


ALTER TABLE "audit"."data_export_audit" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "audit"."handle_change_log" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "old_handle" "text",
    "new_handle" "text" NOT NULL,
    "reason" "text" NOT NULL,
    "actor_id" "uuid",
    "ip_address" "inet",
    "user_agent" "text",
    "changed_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "handle_change_log_reason_check" CHECK (("reason" = ANY (ARRAY['rename'::"text", 'reclaim'::"text", 'staff_rename'::"text", 'system'::"text"])))
);


ALTER TABLE "audit"."handle_change_log" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "audit"."moderation_events" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "actor_kind" character varying(16) NOT NULL,
    "actor_staff_id" "uuid",
    "action" character varying(64) NOT NULL,
    "target_type" character varying(32),
    "target_id" "uuid",
    "payload" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "moderation_events_actor_kind_check" CHECK ((("actor_kind")::"text" = ANY ((ARRAY['staff'::character varying, 'system'::character varying])::"text"[]))),
    CONSTRAINT "moderation_events_actor_xor" CHECK ((((("actor_kind")::"text" = 'staff'::"text") AND ("actor_staff_id" IS NOT NULL)) OR ((("actor_kind")::"text" = 'system'::"text") AND ("actor_staff_id" IS NULL))))
);

ALTER TABLE ONLY "audit"."moderation_events" FORCE ROW LEVEL SECURITY;


ALTER TABLE "audit"."moderation_events" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "audit"."staff_audit_log" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "staff_id" "uuid",
    "staff_email_snapshot" "text",
    "impersonator_staff_id" "uuid",
    "impersonator_email_snapshot" "text",
    "user_id" "uuid",
    "professional_handle_snapshot" "text",
    "route" "text" NOT NULL,
    "http_method" "text" NOT NULL,
    "status_code" smallint NOT NULL,
    "payload_summary" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "user_agent" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "ip_hash" "text",
    CONSTRAINT "staff_audit_log_http_method_check" CHECK (("http_method" = ANY (ARRAY['POST'::"text", 'PATCH'::"text", 'PUT'::"text", 'DELETE'::"text", 'GET'::"text"]))),
    CONSTRAINT "staff_audit_log_status_code_check" CHECK ((("status_code" >= 100) AND ("status_code" <= 599)))
);


ALTER TABLE "audit"."staff_audit_log" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "audit"."user_deletion_audit" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "professional_handle_snapshot" "text" NOT NULL,
    "professional_email_snapshot" "text" NOT NULL,
    "event" "text" NOT NULL,
    "ip_address" "text",
    "user_agent" "text",
    "metadata" "jsonb",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "actor_type" "text" NOT NULL,
    "actor_id" "uuid",
    "actor_handle_snapshot" "text",
    "reason" "text",
    CONSTRAINT "user_deletion_audit_actor_type_check" CHECK (("actor_type" = ANY (ARRAY['professional'::"text", 'staff_admin'::"text", 'system'::"text"]))),
    CONSTRAINT "user_deletion_audit_event_check" CHECK (("event" = ANY (ARRAY['requested'::"text", 'confirmed'::"text", 'cancelled'::"text", 'purged'::"text", 'purge_failed'::"text", 'admin_initiated'::"text", 'admin_cancelled'::"text"])))
);


ALTER TABLE "audit"."user_deletion_audit" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."early_access_signups" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "email" "text" NOT NULL,
    "email_lc" "text" NOT NULL,
    "type" "text" NOT NULL,
    "workplace_or_industry" "text",
    "platforms" "jsonb" DEFAULT '[]'::"jsonb" NOT NULL,
    "status" "text" DEFAULT 'waitlist'::"text" NOT NULL,
    "source" "text" DEFAULT 'marketing'::"text" NOT NULL,
    "invited_at" timestamp with time zone,
    "invite_token_hash" "text",
    "invite_meta" "jsonb",
    "invited_by" "uuid",
    "signed_up_at" timestamp with time zone,
    "consent_ip_hash" "text",
    "consent_user_agent" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "source_type" "text",
    "source_ref" "text",
    "user_id" "uuid",
    CONSTRAINT "early_access_signups_platforms_is_array" CHECK (("jsonb_typeof"("platforms") = 'array'::"text")),
    CONSTRAINT "early_access_signups_source_check" CHECK (("source" = ANY (ARRAY['marketing'::"text", 'manual'::"text"]))),
    CONSTRAINT "early_access_signups_source_type_check" CHECK ((("source_type" IS NULL) OR ("source_type" = ANY (ARRAY['instagram'::"text", 'google_business'::"text"])))),
    CONSTRAINT "early_access_signups_status_check" CHECK (("status" = ANY (ARRAY['waitlist'::"text", 'invited'::"text", 'signed_up'::"text"]))),
    CONSTRAINT "early_access_signups_type_check" CHECK (("type" = ANY (ARRAY['partna'::"text", 'business'::"text"])))
);

ALTER TABLE ONLY "core"."early_access_signups" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."early_access_signups" OWNER TO "postgres";


COMMENT ON TABLE "core"."early_access_signups" IS 'Early-access waitlist + invite lifecycle (waitlist -> invited -> signed_up). Public marketing endpoint creates; staff manage/invite; bootstrap consumes invite tokens (sha256 in invite_token_hash).';



CREATE TABLE IF NOT EXISTS "core"."email_suppressions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "email_hash" "text" NOT NULL,
    "reason" "text" NOT NULL,
    "source" "text",
    "detail" "text",
    "first_seen_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "email_suppressions_reason_check" CHECK (("reason" = ANY (ARRAY['hard_bounce'::"text", 'complaint'::"text", 'manual'::"text"])))
);

ALTER TABLE ONLY "core"."email_suppressions" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."email_suppressions" OWNER TO "postgres";


COMMENT ON TABLE "core"."email_suppressions" IS 'Send-time suppression list. One row per suppressed recipient (email stored as SHA256 HMAC only). reason = hard_bounce | complaint | manual. Written by the Resend bounce/complaint webhook; read by the MessageSending gate. Protects shared partna.au sender reputation.';



CREATE TABLE IF NOT EXISTS "core"."feature_availability" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "feature_key" "text" NOT NULL,
    "mode" "text" NOT NULL,
    "segment_id" "uuid",
    "created_by" "uuid",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "feature_availability_key_format" CHECK (("feature_key" ~ '^[a-z][a-z0-9._-]*$'::"text")),
    CONSTRAINT "feature_availability_mode_check" CHECK (("mode" = ANY (ARRAY['enabled'::"text", 'disabled'::"text"])))
);

ALTER TABLE ONLY "core"."feature_availability" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."feature_availability" OWNER TO "postgres";


COMMENT ON TABLE "core"."feature_availability" IS 'Staff-managed feature/integration availability. segment_id NULL = global row. Read via FeatureAvailability::for($user); absence of rows = available. Keys: integration.<platform> | feature.<name>.';



CREATE TABLE IF NOT EXISTS "core"."feature_flag_overrides" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "flag_key" "text" NOT NULL,
    "user_id" "uuid",
    "enabled" boolean NOT NULL,
    "reason" "text",
    "expires_at" timestamp with time zone,
    "created_by" "uuid",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "feature_flag_overrides_scope_set" CHECK (("user_id" IS NOT NULL))
);


ALTER TABLE "core"."feature_flag_overrides" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."feature_flags" (
    "key" "text" NOT NULL,
    "description" "text" DEFAULT ''::"text" NOT NULL,
    "default_enabled" boolean DEFAULT false NOT NULL,
    "rollout_percent" smallint DEFAULT 0 NOT NULL,
    "deleted_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "feature_flags_key_format" CHECK (("key" ~ '^[a-z][a-z0-9_]*$'::"text")),
    CONSTRAINT "feature_flags_key_length" CHECK (("length"("key") <= 128)),
    CONSTRAINT "feature_flags_rollout_percent_range" CHECK ((("rollout_percent" >= 0) AND ("rollout_percent" <= 100)))
);


ALTER TABLE "core"."feature_flags" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."feedback" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "reply_email" "text",
    "kind" "text" NOT NULL,
    "severity" "text",
    "message" "text" NOT NULL,
    "page_url" "text",
    "user_agent" "text",
    "viewport" "text",
    "app_version" "text",
    "request_id" "text",
    "status" "text" DEFAULT 'new'::"text" NOT NULL,
    "internal_notes" "jsonb" DEFAULT '[]'::"jsonb" NOT NULL,
    "tags" "jsonb" DEFAULT '[]'::"jsonb" NOT NULL,
    "source" "text" DEFAULT 'dashboard'::"text" NOT NULL,
    "ip_hash" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "type" "text",
    "area" "text",
    "target" "jsonb",
    CONSTRAINT "feedback_kind_check" CHECK (("kind" = ANY (ARRAY['bug'::"text", 'idea'::"text", 'praise'::"text", 'question'::"text", 'other'::"text"]))),
    CONSTRAINT "feedback_message_length_check" CHECK ((("char_length"("message") >= 1) AND ("char_length"("message") <= 5000))),
    CONSTRAINT "feedback_severity_check" CHECK ((("severity" IS NULL) OR ("severity" = ANY (ARRAY['low'::"text", 'medium'::"text", 'high'::"text", 'critical'::"text"])))),
    CONSTRAINT "feedback_source_check" CHECK (("source" = ANY (ARRAY['dashboard'::"text", 'public_site'::"text", 'mobile'::"text", 'email'::"text", 'api'::"text"]))),
    CONSTRAINT "feedback_status_check" CHECK (("status" = ANY (ARRAY['new'::"text", 'triaged'::"text", 'in_progress'::"text", 'shipped'::"text", 'wontfix'::"text", 'duplicate'::"text"]))),
    CONSTRAINT "feedback_type_check" CHECK ((("type" IS NULL) OR ("type" = ANY (ARRAY['error'::"text", 'good'::"text", 'bad_ui'::"text", 'idea'::"text"]))))
);

ALTER TABLE ONLY "core"."feedback" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."feedback" OWNER TO "postgres";


COMMENT ON TABLE "core"."feedback" IS 'In-app feedback submissions (bug/idea/praise/question/other). Authenticated dashboard submissions only at first; source/user_id are nullable-friendly to accept anonymous public-site submissions in a future iteration without schema change. Notification delivery is fire-and-forget via SendFeedbackEmailJob — failures do not block submission.';



COMMENT ON COLUMN "core"."feedback"."type" IS 'OV-D reaction bucket: error | good | bad_ui | idea. Separate from `kind` (legacy taxonomy) — validated at the FormRequest layer, no DB CHECK.';



COMMENT ON COLUMN "core"."feedback"."area" IS 'OV-D free-form feature/page/tool picker value the frontend sends (e.g. "analytics"). No fixed vocabulary at the DB layer.';



COMMENT ON COLUMN "core"."feedback"."target" IS 'OV-D optional structured companion to `area` for machine-readable context. Open shape, size-capped at the FormRequest layer.';



CREATE TABLE IF NOT EXISTS "core"."partna_staff" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "auth_user_id" "uuid" NOT NULL,
    "role" "text" DEFAULT 'support'::"text" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "primary_email" "text",
    "name" "text",
    "phone" "text",
    CONSTRAINT "partna_staff_role_check" CHECK (("role" = ANY (ARRAY['admin'::"text", 'support'::"text"])))
);

ALTER TABLE ONLY "core"."partna_staff" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."partna_staff" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."pre_account_builds" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "source_type" "text" NOT NULL,
    "source_ref" "text" NOT NULL,
    "source_ref_lc" "text" NOT NULL,
    "built_via" "text" NOT NULL,
    "built_by_staff_id" "uuid",
    "build_state" "text" DEFAULT 'pending'::"text" NOT NULL,
    "failure_code" "text",
    "created_ip_hash" "text",
    "expires_at" timestamp with time zone,
    "claimed_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "contact_email" "text",
    "invited_at" timestamp with time zone,
    "auto_invite" boolean DEFAULT true NOT NULL,
    CONSTRAINT "pre_account_builds_build_state_check" CHECK (("build_state" = ANY (ARRAY['pending'::"text", 'building'::"text", 'ready'::"text", 'failed'::"text"]))),
    CONSTRAINT "pre_account_builds_built_via_check" CHECK (("built_via" = ANY (ARRAY['signup'::"text", 'staff'::"text", 'early_access'::"text"]))),
    CONSTRAINT "pre_account_builds_source_type_check" CHECK (("source_type" = ANY (ARRAY['instagram'::"text", 'google_business'::"text"])))
);


ALTER TABLE "core"."pre_account_builds" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."supabase_email_events" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "webhook_id" "text" NOT NULL,
    "request_id" "text",
    "action_type" "text" NOT NULL,
    "recipient_email_hash" "text",
    "raw_payload" "jsonb" NOT NULL,
    "status" "text" DEFAULT 'queued'::"text" NOT NULL,
    "error" "text",
    "queued_at" timestamp with time zone,
    "failed_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "supabase_email_events_status_check" CHECK (("status" = ANY (ARRAY['queued'::"text", 'failed'::"text", 'unhandled'::"text"])))
);

ALTER TABLE ONLY "core"."supabase_email_events" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."supabase_email_events" OWNER TO "postgres";


COMMENT ON TABLE "core"."supabase_email_events" IS 'WHK-3: Forensic trail of Supabase auth-email webhook outcomes. One row per unique webhook_id; status is queued/failed/unhandled. Email is hashed (SHA256 HMAC); raw_payload is token-stripped. WHK-4 (replay) is deferred — no token column here.';



CREATE TABLE IF NOT EXISTS "core"."user_confirmation_preferences" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "action_key" "text" NOT NULL,
    "skip_confirmation" boolean DEFAULT false NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "core"."user_confirmation_preferences" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."user_handle_aliases" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "handle" character varying(63) NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "reclaim_until" timestamp with time zone,
    "expires_at" timestamp with time zone,
    "notified_t3_at" timestamp with time zone,
    "notified_t1_at" timestamp with time zone
);


ALTER TABLE "core"."user_handle_aliases" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."user_segment_members" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "segment_id" "uuid" NOT NULL,
    "user_id" "uuid" NOT NULL,
    "added_by" "uuid",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL
);

ALTER TABLE ONLY "core"."user_segment_members" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."user_segment_members" OWNER TO "postgres";


COMMENT ON TABLE "core"."user_segment_members" IS 'Manual segment membership rows — additive on top of the segment''s dynamic filters.';



CREATE TABLE IF NOT EXISTS "core"."user_segments" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "name" "text" NOT NULL,
    "description" "text",
    "filters" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_by" "uuid",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "user_segments_filters_is_object" CHECK (("jsonb_typeof"("filters") = 'object'::"text"))
);

ALTER TABLE ONLY "core"."user_segments" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."user_segments" OWNER TO "postgres";


COMMENT ON TABLE "core"."user_segments" IS 'Staff-defined user segments: dynamic JSONB filter definition + manual members (core.user_segment_members). Resolved live to a user-id set by SegmentResolver; used for staff notifications, feature availability scoping, aggregate analytics.';



CREATE TABLE IF NOT EXISTS "core"."users" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "auth_user_id" "uuid",
    "handle" "text" NOT NULL,
    "display_name" "text" NOT NULL,
    "country_code" "text",
    "timezone" "text",
    "status" "text" DEFAULT 'active'::"text" NOT NULL,
    "onboarding_step" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "phone" "text",
    "primary_email" "text",
    "first_name" "text" NOT NULL,
    "last_name" "text",
    "public_contact_number" "text",
    "public_contact_email" "text",
    "location_street_address" "text",
    "location_postcode" "text",
    "location_city" "text",
    "location_state" "text",
    "location_country" "text",
    "handle_lc" "text" NOT NULL,
    "deleted_at" timestamp with time zone,
    "account_type" "text" DEFAULT 'partna'::"text" NOT NULL,
    "deletion_token_hash" "text",
    "deletion_requested_at" timestamp with time zone,
    "deletion_confirmed_at" timestamp with time zone,
    "deletion_previous_status" "text",
    "partna_url" "text",
    "admin_notes" "text",
    "deletion_mail_sent_at" timestamp with time zone,
    "sector" "text",
    "sector_source" "text",
    CONSTRAINT "users_account_type_check" CHECK (("account_type" = ANY (ARRAY['partna'::"text", 'business'::"text"]))),
    CONSTRAINT "users_sector_check" CHECK ((("sector" IS NULL) OR ("sector" = ANY (ARRAY['restaurant'::"text", 'cafe'::"text", 'bakery'::"text", 'bar'::"text", 'food-truck'::"text", 'caterer'::"text", 'personal-chef'::"text", 'hair-salon'::"text", 'barber'::"text", 'nail-technician'::"text", 'makeup-artist'::"text", 'esthetician'::"text", 'spa'::"text", 'tattoo-artist'::"text", 'brows-lashes'::"text", 'personal-trainer'::"text", 'gym'::"text", 'yoga-instructor'::"text", 'nutritionist'::"text", 'physiotherapist'::"text", 'chiropractor'::"text", 'therapist'::"text", 'dentist'::"text", 'accountant'::"text", 'lawyer'::"text", 'financial-advisor'::"text", 'consultant'::"text", 'real-estate-agent'::"text", 'insurance-broker'::"text", 'mortgage-broker'::"text", 'marketing-agency'::"text", 'it-services'::"text", 'virtual-assistant'::"text", 'clothing-boutique'::"text", 'jewellery'::"text", 'florist'::"text", 'gift-shop'::"text", 'homewares'::"text", 'artisan-maker'::"text", 'plumber'::"text", 'electrician'::"text", 'builder'::"text", 'painter'::"text", 'cleaner'::"text", 'landscaper'::"text", 'handyman'::"text", 'removalist'::"text", 'pest-control'::"text", 'accommodation'::"text", 'event-venue'::"text", 'event-planner'::"text", 'wedding-planner'::"text", 'bartender'::"text", 'mechanic'::"text", 'car-detailer'::"text", 'auto-electrician'::"text", 'tyre-service'::"text", 'photographer'::"text", 'videographer'::"text", 'graphic-designer'::"text", 'artist'::"text", 'musician'::"text", 'content-creator'::"text", 'writer'::"text", 'tutor'::"text", 'life-coach'::"text", 'music-teacher'::"text", 'driving-instructor'::"text", 'dance-instructor'::"text", 'course-creator'::"text", 'other'::"text"])))),
    CONSTRAINT "users_sector_source_check" CHECK ((("sector_source" IS NULL) OR ("sector_source" = ANY (ARRAY['manual'::"text", 'google-business'::"text", 'instagram'::"text"])))),
    CONSTRAINT "users_status_check" CHECK (("status" = ANY (ARRAY['active'::"text", 'suspended'::"text", 'disabled'::"text", 'pending_deletion'::"text", 'unclaimed'::"text"])))
);


ALTER TABLE "core"."users" OWNER TO "postgres";


COMMENT ON COLUMN "core"."users"."public_contact_number" IS 'Optional contact number the user chooses to display publicly on their site. NULL = not shared.';



COMMENT ON COLUMN "core"."users"."public_contact_email" IS 'Optional contact email the user chooses to display publicly on their site. NULL = not shared. Distinct from primary_email which is never exposed publicly.';



COMMENT ON COLUMN "core"."users"."admin_notes" IS 'Staff-only free-text notes. Exposed via the staff resource only — never through /me.';



CREATE TABLE IF NOT EXISTS "moderation"."action_log" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "decision_id" "uuid" NOT NULL,
    "action_type" character varying(48) NOT NULL,
    "action_target" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "job_uuid" character varying(36),
    "status" character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    "attempts" smallint DEFAULT 0 NOT NULL,
    "failure_reason" "text",
    "dispatched_at" timestamp with time zone,
    "completed_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "action_log_action_type_check" CHECK ((("action_type")::"text" = ANY ((ARRAY['sync_subdomain_kv'::character varying, 'suspend_user'::character varying, 'suspend_site'::character varying, 'quarantine_media'::character varying, 'file_cybertip_report'::character varying, 'notify_reported_user'::character varying, 'notify_reporter'::character varying, 'notify_oncall_staff'::character varying, 'purge_cloudflare_cache'::character varying, 'redact_reporter_pii'::character varying])::"text"[]))),
    CONSTRAINT "action_log_status_check" CHECK ((("status")::"text" = ANY ((ARRAY['pending'::character varying, 'dispatched'::character varying, 'completed'::character varying, 'failed'::character varying, 'cancelled'::character varying])::"text"[])))
);

ALTER TABLE ONLY "moderation"."action_log" FORCE ROW LEVEL SECURITY;


ALTER TABLE "moderation"."action_log" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "moderation"."case_signals" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "case_id" "uuid" NOT NULL,
    "signal_source" character varying(32) NOT NULL,
    "signal_data" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "reporter_user_id" "uuid",
    "reporter_email" character varying(255),
    "reporter_ip_hash" character varying(64),
    "reason_code" character varying(64) NOT NULL,
    "reason_details" "text",
    "dedup_hash" character varying(64) NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "case_signals_details_length" CHECK ((("reason_details" IS NULL) OR ("length"("reason_details") <= 4000))),
    CONSTRAINT "case_signals_reason_code_check" CHECK ((("reason_code")::"text" = ANY ((ARRAY['spam'::character varying, 'harassment'::character varying, 'impersonation'::character varying, 'illegal_content'::character varying, 'sexual_content'::character varying, 'self_harm'::character varying, 'hate_speech'::character varying, 'intellectual_property'::character varying, 'fake_profile'::character varying, 'other'::character varying, 'auto_csam_hash_match'::character varying, 'auto_other'::character varying])::"text"[]))),
    CONSTRAINT "case_signals_signal_source_check" CHECK ((("signal_source")::"text" = ANY ((ARRAY['content_report'::character varying, 'csam_scan'::character varying, 'trusted_flagger'::character varying, 'manual_staff'::character varying, 'esafety_notice'::character varying])::"text"[])))
);

ALTER TABLE ONLY "moderation"."case_signals" FORCE ROW LEVEL SECURITY;


ALTER TABLE "moderation"."case_signals" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "moderation"."cases" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "case_type" character varying(32) NOT NULL,
    "reportable_type" character varying(64) NOT NULL,
    "reportable_id" "uuid" NOT NULL,
    "reportable_owner_user_id" "uuid",
    "severity" smallint DEFAULT 2 NOT NULL,
    "status" character varying(20) DEFAULT 'open'::character varying NOT NULL,
    "signal_count" integer DEFAULT 1 NOT NULL,
    "auto_actioned" boolean DEFAULT false NOT NULL,
    "priority" smallint DEFAULT 5 NOT NULL,
    "sla_due_at" timestamp with time zone,
    "triaged_at" timestamp with time zone,
    "triaged_by_staff_id" "uuid",
    "resolved_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "cases_case_type_check" CHECK ((("case_type")::"text" = ANY ((ARRAY['content_report'::character varying, 'csam_match'::character varying, 'trusted_flagger'::character varying, 'manual'::character varying, 'esafety_takedown'::character varying])::"text"[]))),
    CONSTRAINT "cases_priority_check" CHECK ((("priority" >= 1) AND ("priority" <= 10))),
    CONSTRAINT "cases_reportable_type_check" CHECK ((("reportable_type")::"text" = ANY ((ARRAY['Site'::character varying, 'SiteMedia'::character varying, 'User'::character varying, 'Block'::character varying, 'Service'::character varying])::"text"[]))),
    CONSTRAINT "cases_severity_check" CHECK ((("severity" >= 1) AND ("severity" <= 5))),
    CONSTRAINT "cases_signal_count_check" CHECK (("signal_count" >= 1)),
    CONSTRAINT "cases_status_check" CHECK ((("status")::"text" = ANY ((ARRAY['open'::character varying, 'triaged'::character varying, 'under_review'::character varying, 'resolved'::character varying, 'auto_actioned'::character varying])::"text"[])))
);

ALTER TABLE ONLY "moderation"."cases" FORCE ROW LEVEL SECURITY;


ALTER TABLE "moderation"."cases" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "moderation"."decisions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "case_id" "uuid" NOT NULL,
    "decision_type" character varying(32) NOT NULL,
    "reason" "text",
    "decided_by_staff_id" "uuid",
    "decided_by_system" boolean DEFAULT false NOT NULL,
    "auto_actioned" boolean DEFAULT false NOT NULL,
    "supersedes_decision_id" "uuid",
    "second_staff_approval_id" "uuid",
    "second_staff_approved_at" timestamp with time zone,
    "decided_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "decisions_actor_xor" CHECK (((("decided_by_staff_id" IS NOT NULL) AND ("decided_by_system" = false)) OR (("decided_by_staff_id" IS NULL) AND ("decided_by_system" = true)))),
    CONSTRAINT "decisions_csam_override_requires_second_staff" CHECK (((("decision_type")::"text" <> 'override_csam_auto_action'::"text") OR (("second_staff_approval_id" IS NOT NULL) AND ("second_staff_approved_at" IS NOT NULL) AND ("second_staff_approval_id" <> "decided_by_staff_id")))),
    CONSTRAINT "decisions_decision_type_check" CHECK ((("decision_type")::"text" = ANY ((ARRAY['dismiss'::character varying, 'warn'::character varying, 'hide_content'::character varying, 'hide_site'::character varying, 'suspend_user'::character varying, 'ban_user'::character varying, 'csam_auto_suspend'::character varying, 'override_csam_auto_action'::character varying, 'escalate_law_enforcement'::character varying, 'escalate_esafety'::character varying])::"text"[])))
);

ALTER TABLE ONLY "moderation"."decisions" FORCE ROW LEVEL SECURITY;


ALTER TABLE "moderation"."decisions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "moderation"."evidence" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "case_id" "uuid" NOT NULL,
    "signal_id" "uuid",
    "evidence_type" character varying(32) NOT NULL,
    "payload" "jsonb" NOT NULL,
    "content_hash" character varying(64),
    "captured_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "evidence_type_check" CHECK ((("evidence_type")::"text" = ANY ((ARRAY['content_snapshot'::character varying, 'csam_hash_match'::character varying, 'upload_metadata'::character varying, 'staff_attachment'::character varying])::"text"[])))
);

ALTER TABLE ONLY "moderation"."evidence" FORCE ROW LEVEL SECURITY;


ALTER TABLE "moderation"."evidence" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."broadcast_email_receipts" (
    "notification_id" "uuid" NOT NULL,
    "subscription_id" "uuid" NOT NULL,
    "email_sent_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "notifications"."broadcast_email_receipts" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."email_subscriptions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "list_key" character varying(50) DEFAULT 'marketing'::character varying NOT NULL,
    "email" "text" NOT NULL,
    "full_name" "text",
    "status" character varying(20) DEFAULT 'subscribed'::character varying NOT NULL,
    "subscribed_at" timestamp with time zone,
    "unsubscribed_at" timestamp with time zone,
    "unsubscribe_token" character varying(80) NOT NULL,
    "consent_source" character varying(50),
    "consent_ip_hash" "text",
    "consent_user_agent" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "email_lc" "text" NOT NULL,
    "confirmation_sent_at" timestamp with time zone,
    CONSTRAINT "email_subscriptions_status_check" CHECK ((("status")::"text" = ANY ((ARRAY['subscribed'::character varying, 'unsubscribed'::character varying])::"text"[])))
);


ALTER TABLE "notifications"."email_subscriptions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."notification_email_policies" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "category_key" "text" NOT NULL,
    "mode" "text" DEFAULT 'default'::"text" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "notification_email_policies_mode_check" CHECK (("mode" = ANY (ARRAY['default'::"text", 'force_on'::"text", 'force_off'::"text"])))
);


ALTER TABLE "notifications"."notification_email_policies" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."notification_email_preferences" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "category_key" "text" NOT NULL,
    "enabled" boolean DEFAULT true NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "notifications"."notification_email_preferences" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."notification_receipts" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "notification_id" "uuid" NOT NULL,
    "user_id" "uuid" NOT NULL,
    "read_at" timestamp with time zone,
    "dismissed_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "notifications"."notification_receipts" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "notifications"."notifications" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid",
    "type" "text" NOT NULL,
    "title" "text" NOT NULL,
    "body" "text" NOT NULL,
    "cta_url" "text",
    "severity" "text" DEFAULT 'info'::"text" NOT NULL,
    "starts_at" timestamp with time zone,
    "ends_at" timestamp with time zone,
    "primary_action_label" character varying(255),
    "secondary_action_label" character varying(255),
    "secondary_action_url" "text",
    "category" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "dedupe_key" "text",
    "email_sent_at" timestamp with time zone,
    "critical" boolean DEFAULT false NOT NULL,
    CONSTRAINT "notifications_severity_check" CHECK (("severity" = ANY (ARRAY['info'::"text", 'warning'::"text", 'critical'::"text"]))),
    CONSTRAINT "notifications_type_check" CHECK (("type" = ANY (ARRAY['Success'::"text", 'Critical'::"text", 'Warning'::"text", 'Invitation'::"text", 'To do'::"text", 'Info'::"text"])))
);


ALTER TABLE "notifications"."notifications" OWNER TO "postgres";


COMMENT ON COLUMN "notifications"."notifications"."critical" IS 'Delivery escalation: true -> in-app + email (dispatcher path, OV-H); false -> in-app only. Independent of display severity.';



CREATE TABLE IF NOT EXISTS "public"."failed_jobs" (
    "id" bigint NOT NULL,
    "uuid" character varying(255) NOT NULL,
    "connection" "text" NOT NULL,
    "queue" "text" NOT NULL,
    "payload" "text" NOT NULL,
    "exception" "text" NOT NULL,
    "failed_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE "public"."failed_jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."failed_jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNED BY "public"."failed_jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."job_batches" (
    "id" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "total_jobs" integer NOT NULL,
    "pending_jobs" integer NOT NULL,
    "failed_jobs" integer NOT NULL,
    "failed_job_ids" "text" NOT NULL,
    "options" "text",
    "cancelled_at" integer,
    "created_at" integer NOT NULL,
    "finished_at" integer
);


ALTER TABLE "public"."job_batches" OWNER TO "postgres";


CREATE OR REPLACE VIEW "site"."all_site_data" AS
SELECT
    NULL::"uuid" AS "site_id",
    NULL::"uuid" AS "user_id",
    NULL::"text" AS "subdomain",
    NULL::boolean AS "is_published",
    NULL::"text" AS "architecture_id",
    NULL::"jsonb" AS "site_settings",
    NULL::timestamp with time zone AS "site_created_at",
    NULL::timestamp with time zone AS "site_updated_at",
    NULL::"text" AS "handle",
    NULL::"text" AS "display_name",
    NULL::"text" AS "location_street_address",
    NULL::"text" AS "location_city",
    NULL::"text" AS "location_state",
    NULL::"text" AS "location_postcode",
    NULL::"text" AS "location_country",
    NULL::"jsonb" AS "blocks",
    NULL::"text" AS "account_type";


ALTER VIEW "site"."all_site_data" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."blocks" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "block_type" "text" DEFAULT 'link'::"text" NOT NULL,
    "title" "text",
    "url" "text",
    "icon_key" "text",
    "sort_order" integer DEFAULT 0 NOT NULL,
    "is_active" boolean DEFAULT true NOT NULL,
    "settings" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "block_group" "text" DEFAULT 'links'::"text" NOT NULL,
    "deleted_at" timestamp with time zone,
    "is_enabled" boolean DEFAULT false NOT NULL,
    "live_check_enabled" boolean DEFAULT false NOT NULL,
    "category" "text",
    "platform" "text",
    "handle" "text",
    CONSTRAINT "blocks_category_check" CHECK ((("category" IS NULL) OR ("category" = ANY (ARRAY['social'::"text", 'booking'::"text", 'education'::"text", 'content'::"text", 'events'::"text", 'streaming'::"text", 'other'::"text"])))),
    CONSTRAINT "blocks_group_type_check" CHECK (((("block_group" = 'links'::"text") AND ("block_type" = 'link'::"text")) OR (("block_group" = 'sections'::"text") AND ("block_type" = ANY (ARRAY['gallery'::"text", 'services'::"text", 'booking'::"text", 'contacts_collection'::"text", 'barbershop_info'::"text", 'documents'::"text", 'newsletter'::"text", 'contact'::"text", 'public_contact'::"text", 'workplace'::"text"])))))
);


ALTER TABLE "site"."blocks" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."content_selection" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "site_id" "uuid" NOT NULL,
    "position" smallint NOT NULL,
    "entry_type" "text" NOT NULL,
    "media_id" "uuid",
    "external_ref" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "content_selection_entry_type_check" CHECK (("entry_type" = ANY (ARRAY['upload'::"text", 'google-photo'::"text", 'ig-reel'::"text", 'ig-post'::"text"]))),
    CONSTRAINT "content_selection_position_range" CHECK ((("position" >= 1) AND ("position" <= 15))),
    CONSTRAINT "content_selection_ref_shape" CHECK (((("entry_type" = 'upload'::"text") AND ("media_id" IS NOT NULL) AND ("external_ref" IS NULL)) OR (("entry_type" = 'google-photo'::"text") AND ("external_ref" IS NOT NULL) AND ("media_id" IS NULL)) OR (("entry_type" = ANY (ARRAY['ig-reel'::"text", 'ig-post'::"text"])) AND ("media_id" IS NULL) AND ("external_ref" IS NULL))))
);

ALTER TABLE ONLY "site"."content_selection" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."content_selection" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."customers" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "email" "text",
    "phone" "text",
    "full_name" "text",
    "source" "text",
    "notes" "text",
    "external_id" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "marketing_opt_in_cached" boolean,
    "redacted_at" timestamp with time zone
);


ALTER TABLE "site"."customers" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."design_kits" (
    "site_id" "uuid" NOT NULL,
    "color_accent" "text",
    "color_text" "text",
    "typography_font_family" "text",
    "color_text_muted" "text",
    "color_accent_contrast" "text",
    "border_thickness" "text",
    "border_color" "text",
    "border_radius" "text",
    "icon_size" "text",
    "icon_color" "text",
    "motion_expand_duration" "text",
    "motion_fade_duration" "text",
    "typography_uppercase" boolean,
    "color_contrasting_bg" "text",
    "color_contrasting_text" "text",
    "color_placeholder" "text",
    "space_xs" "text",
    "space_s" "text",
    "space_regular" "text",
    "space_medium" "text",
    "space_large" "text",
    "space_desktop_regular" "text",
    "icons_xl_size" "text",
    "icons_xxl_size" "text",
    "icons_stroke_width" "text",
    "icons_large_stroke_width" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "space_xl" "text",
    "color_secondary_text" "text",
    "typography_line_height" "text",
    "typography_logo_height" "text",
    "weight_regular" "text",
    "weight_medium" "text",
    "weight_semibold" "text",
    "weight_bold" "text",
    "border_small_radius" "text",
    "space_xxs" "text",
    "icons_large_size" "text",
    "icons_brand_logo_height" "text",
    "motion_pace" "text",
    "motion_spin_duration" "text",
    "motion_spring_curve" "text",
    "theme_mode" "text",
    "space_desktop_xl" "text",
    "weight_light" "text",
    "effect_shadow_style" "text",
    "effect_link_style" "text",
    "effect_image_treatment" "text",
    "layout_density" "text",
    "border_style" "text",
    "theme_night_shift_auto" boolean,
    "text_caption" "text",
    "text_body" "text",
    "text_h3" "text",
    "text_h2" "text",
    "text_h1" "text",
    "text_display" "text",
    "text_desktop_body" "text",
    "text_desktop_h1" "text",
    "text_desktop_display" "text",
    "typography_tracking" "text",
    "theme_contrast" "text",
    "weight_heading" "text",
    "typography_weight" "text",
    CONSTRAINT "design_kits_theme_contrast_check" CHECK ((("theme_contrast" IS NULL) OR ("theme_contrast" = ANY (ARRAY['soft'::"text", 'normal'::"text", 'stark'::"text"])))),
    CONSTRAINT "design_kits_typography_tracking_check" CHECK ((("typography_tracking" IS NULL) OR ("typography_tracking" = ANY (ARRAY['tight'::"text", 'normal'::"text", 'wide'::"text"]))))
);

ALTER TABLE ONLY "site"."design_kits" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."design_kits" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."enquiries" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "name" character varying(100) NOT NULL,
    "email" character varying(255) NOT NULL,
    "phone" character varying(30),
    "subject" character varying(100) NOT NULL,
    "message" "text" NOT NULL,
    "ip_hash" character varying(64),
    "user_agent" character varying(500),
    "read_at" timestamp with time zone,
    "deleted_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "email_sent_at" timestamp with time zone,
    "status" "site"."enquiry_status" DEFAULT 'new'::"site"."enquiry_status" NOT NULL,
    "customer_id" "uuid",
    "notification_id" "uuid",
    "replied_at" timestamp with time zone,
    "archived_at" timestamp with time zone,
    "spam_at" timestamp with time zone,
    "redacted_at" timestamp with time zone,
    "confirmation_sent_at" timestamp with time zone
);


ALTER TABLE "site"."enquiries" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."item_slugs" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "item_type" "text" NOT NULL,
    "item_key" "text" NOT NULL,
    "slug" "text" NOT NULL,
    "is_current" boolean DEFAULT true NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "item_slugs_type_check" CHECK (("item_type" = ANY (ARRAY['event'::"text", 'menu_item'::"text"])))
);

ALTER TABLE ONLY "site"."item_slugs" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."item_slugs" OWNER TO "postgres";


COMMENT ON TABLE "site"."item_slugs" IS 'Per-profile human-readable URL slug registry for public sitepage detail items (events + menu items). is_current=false rows are retired slugs kept as 301 redirect targets. Owned by App\Services\Site\ItemSlugAllocator.';



CREATE TABLE IF NOT EXISTS "site"."media_variants" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "media_id" "uuid" NOT NULL,
    "variant_key" character varying(40) NOT NULL,
    "artifact_type" character varying(20) NOT NULL,
    "disk" character varying(40) DEFAULT 'media'::character varying NOT NULL,
    "path" "text" NOT NULL,
    "mime" character varying(100),
    "width" integer,
    "height" integer,
    "bitrate_kbps" integer,
    "file_size_bytes" bigint,
    "duration_ms" integer,
    "metadata" "jsonb",
    "content_hash" character varying(16),
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "site"."media_variants" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."menu_categories" (
    "id" "uuid" NOT NULL,
    "menu_id" "uuid" NOT NULL,
    "name" "text" NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    "source_platform" "text",
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone
);

ALTER TABLE ONLY "site"."menu_categories" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menu_categories" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."menu_item_categories" (
    "menu_item_id" "uuid" NOT NULL,
    "menu_category_id" "uuid" NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone
);

ALTER TABLE ONLY "site"."menu_item_categories" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menu_item_categories" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."menu_item_platforms" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "menu_item_id" "uuid" NOT NULL,
    "platform" "text" NOT NULL,
    "pickup_price" numeric(10,2),
    "pickup_url" "text",
    "delivery_price" numeric(10,2),
    "delivery_url" "text",
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone
);

ALTER TABLE ONLY "site"."menu_item_platforms" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menu_item_platforms" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."menu_items" (
    "id" "uuid" NOT NULL,
    "menu_id" "uuid" NOT NULL,
    "name" "text" NOT NULL,
    "description" "text",
    "image_url" "text",
    "rating" numeric(5,2),
    "rating_count" integer,
    "badges" "jsonb",
    "base_price" numeric(10,2),
    "pickup_price" numeric(10,2),
    "pickup_source" "text",
    "delivery_price" numeric(10,2),
    "delivery_source" "text",
    "dd_external_id" "text",
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone,
    "currency" "text",
    "images" "jsonb",
    "is_manual" boolean DEFAULT false NOT NULL
);

ALTER TABLE ONLY "site"."menu_items" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menu_items" OWNER TO "postgres";


COMMENT ON COLUMN "site"."menu_items"."currency" IS 'ISO 4217 code from the Uber Eats scrape (per item); NULL for DoorDash-only dishes.';



COMMENT ON COLUMN "site"."menu_items"."is_manual" IS 'TRUE = owner-authored dish (created or edited via the dashboard). Preserved across scrape rebuilds; a colliding scraped dish is skipped in its favour.';



CREATE TABLE IF NOT EXISTS "site"."menu_platform_links" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "menu_id" "uuid" NOT NULL,
    "platform" "text" NOT NULL,
    "store_url" "text",
    "synced_at" timestamp with time zone,
    "status" "text",
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone,
    CONSTRAINT "menu_platform_links_status_check" CHECK (("status" = ANY (ARRAY['pending'::"text", 'ok'::"text", 'unavailable'::"text"])))
);

ALTER TABLE ONLY "site"."menu_platform_links" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menu_platform_links" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."menus" (
    "id" "uuid" NOT NULL,
    "user_id" "uuid" NOT NULL,
    "rating" numeric(3,2),
    "review_count" integer,
    "currency" "text",
    "fetch_status" "text" DEFAULT 'pending'::"text" NOT NULL,
    "last_fetched_at" timestamp with time zone,
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone,
    "deleted_at" timestamp with time zone,
    "content_source" "text",
    "store_name" "text",
    "logo_url" "text",
    "pickup_platform" "text",
    "delivery_platform" "text",
    "dining_modes" "jsonb",
    "scan_items" "jsonb",
    "suppressed_items" "jsonb",
    CONSTRAINT "menus_fetch_status_check" CHECK (("fetch_status" = ANY (ARRAY['pending'::"text", 'ok'::"text", 'unavailable'::"text"])))
);

ALTER TABLE ONLY "site"."menus" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."menus" OWNER TO "postgres";


COMMENT ON COLUMN "site"."menus"."dining_modes" IS 'Store-level supported dining modes from the Uber Eats scrape (e.g. ["DELIVERY","PICKUP"]); NULL when unavailable (DoorDash exposes none).';



COMMENT ON COLUMN "site"."menus"."suppressed_items" IS 'List of {category, name} scraped dishes the owner deleted — the scrape rebuild must not resurrect them. NULL = none suppressed.';



CREATE TABLE IF NOT EXISTS "site"."platform_connections" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "platform" "text" NOT NULL,
    "resource_id" "text" NOT NULL,
    "payload" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "sort_order" integer DEFAULT 0 NOT NULL,
    "is_active" boolean DEFAULT true NOT NULL,
    "last_visited_at" timestamp with time zone,
    "last_refreshed_at" timestamp with time zone,
    "last_refresh_status" "text",
    "last_refresh_error" "text",
    "consecutive_failures" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"(),
    "updated_at" timestamp with time zone DEFAULT "now"(),
    "deleted_at" timestamp with time zone,
    "apify_status" "text",
    "place_id" "text",
    "refresh_etag" "text",
    "refresh_last_modified" "text",
    "canonical_key" "text",
    "resource_kind" "text",
    "display_settings" "jsonb",
    CONSTRAINT "platform_connections_apify_status_check" CHECK ((("apify_status" IS NULL) OR ("apify_status" = ANY (ARRAY['pending'::"text", 'ok'::"text", 'unavailable'::"text"])))),
    CONSTRAINT "platform_connections_last_refresh_status_check" CHECK (("last_refresh_status" = ANY (ARRAY['ok'::"text", 'unavailable'::"text", 'error'::"text", 'pending'::"text"]))),
    CONSTRAINT "platform_connections_resource_kind_check" CHECK ((("resource_kind" IS NULL) OR ("resource_kind" = ANY (ARRAY['event'::"text", 'link'::"text"]))))
);

ALTER TABLE ONLY "site"."platform_connections" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."platform_connections" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."service_categories" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "title" "text" NOT NULL,
    "sort_order" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "source" "text",
    CONSTRAINT "service_categories_sort_order_non_negative" CHECK (("sort_order" >= 0)),
    CONSTRAINT "service_categories_source_check" CHECK (("source" = 'fresha'::"text"))
);


ALTER TABLE "site"."service_categories" OWNER TO "postgres";


COMMENT ON COLUMN "site"."service_categories"."source" IS '''fresha'' = auto-created from a Fresha category label during projection; NULL = owner-authored.';



CREATE TABLE IF NOT EXISTS "site"."service_category_assignments" (
    "service_id" "uuid" NOT NULL,
    "service_category_id" "uuid" NOT NULL,
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone
);

ALTER TABLE ONLY "site"."service_category_assignments" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."service_category_assignments" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."services" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "title" "text" NOT NULL,
    "description" "text",
    "price_cents" integer NOT NULL,
    "currency_code" character(3) DEFAULT 'AUD'::"bpchar" NOT NULL,
    "duration_minutes" integer,
    "is_active" boolean DEFAULT true NOT NULL,
    "sort_order" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "deleted_origin" character varying(16),
    "source" "text",
    "is_manual" boolean DEFAULT false NOT NULL,
    "external_id" "text",
    CONSTRAINT "services_duration_minutes_check" CHECK ((("duration_minutes" IS NULL) OR ("duration_minutes" > 0))),
    CONSTRAINT "services_price_cents_check" CHECK (("price_cents" >= 0)),
    CONSTRAINT "services_source_check" CHECK (("source" = 'fresha'::"text"))
);


ALTER TABLE "site"."services" OWNER TO "postgres";


COMMENT ON COLUMN "site"."services"."source" IS '''fresha'' = projected from the Fresha scrape; NULL = owner-authored (manual). Public services section reads only NULL.';



COMMENT ON COLUMN "site"."services"."is_manual" IS 'TRUE = owner edited a projected row (sync broken): the re-scrape never overwrites it; revert via /services/{id}/resync.';



COMMENT ON COLUMN "site"."services"."external_id" IS 'Fresha serviceId (s:…) — projection identity; duplicate ids in one scrape collapse to one row.';



CREATE TABLE IF NOT EXISTS "site"."site_media" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "site_id" "uuid" NOT NULL,
    "bucket" "text" DEFAULT 'public-assets'::"text" NOT NULL,
    "path" "text" NOT NULL,
    "alt_text" "text",
    "sort_order" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "is_active" boolean DEFAULT true NOT NULL,
    "pool" character varying(20) DEFAULT 'gallery'::character varying NOT NULL,
    "media_type" character varying(10) DEFAULT 'image'::character varying NOT NULL,
    "processing_state" character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    "original_mime" character varying(100),
    "original_size_bytes" bigint,
    "duration_ms" integer,
    "poster_path" "text",
    "processing_error" "text",
    "caption" character varying(200),
    "original_filename" character varying(255),
    "purpose" "text",
    "scanned_at" timestamp with time zone,
    "dominant_color" "text",
    "palette" "jsonb",
    CONSTRAINT "site_media_media_type_check" CHECK ((("media_type")::"text" = ANY ((ARRAY['image'::character varying, 'video'::character varying, 'document'::character varying])::"text"[]))),
    CONSTRAINT "site_media_pool_check" CHECK ((("pool")::"text" = ANY ((ARRAY['gallery'::character varying, 'content'::character varying, 'design'::character varying, 'product'::character varying, 'documents'::character varying])::"text"[]))),
    CONSTRAINT "site_media_processing_state_check" CHECK ((("processing_state")::"text" = ANY ((ARRAY['pending'::character varying, 'processing'::character varying, 'scanning'::character varying, 'ready'::character varying, 'failed'::character varying, 'quarantined'::character varying])::"text"[])))
);


ALTER TABLE "site"."site_media" OWNER TO "postgres";


COMMENT ON COLUMN "site"."site_media"."scanned_at" IS 'Set when CSAM scan completes. NULL = pre-scanning-era media (grandfathered) or scan not yet run.';



COMMENT ON COLUMN "site"."site_media"."dominant_color" IS '#RRGGBB dominant colour of the processed image, or NULL. Convenience mirror of palette->>dominant. #76.';



COMMENT ON COLUMN "site"."site_media"."palette" IS 'Extracted colour metadata { dominant, colors[], saturation(0..1), warm(bool) } for the ImageryPaletteFactor, or NULL. #76.';



CREATE TABLE IF NOT EXISTS "site"."sites" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "user_id" "uuid" NOT NULL,
    "subdomain" "text" NOT NULL,
    "is_published" boolean DEFAULT true NOT NULL,
    "settings" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "subdomain_changed_at" timestamp with time zone,
    "unpublished_at" timestamp with time zone,
    "architecture_id" "text" DEFAULT 'staple'::"text" NOT NULL,
    "moderation_state" character varying(20) DEFAULT 'active'::character varying NOT NULL,
    "custom_domain" "text",
    "custom_domain_status" "text",
    "custom_domain_verified_at" timestamp with time zone,
    "custom_domain_cf_id" "text",
    "custom_domain_primary" boolean DEFAULT false NOT NULL,
    "show_branding" boolean,
    "charlie_enabled" boolean,
    "services_auto_sync_enabled" boolean,
    "booking_mode" "text",
    "manual_booking_url" "text",
    "content_instagram_auto_enabled" boolean,
    "shop_link_mode" "text" DEFAULT 'checkout'::"text" NOT NULL,
    "shop_auto_latest" boolean DEFAULT true NOT NULL,
    CONSTRAINT "sites_architecture_id_check" CHECK (("architecture_id" = 'staple'::"text")),
    CONSTRAINT "sites_booking_mode_check" CHECK ((("booking_mode" IS NULL) OR ("booking_mode" = ANY (ARRAY['manual'::"text", 'none'::"text"])))),
    CONSTRAINT "sites_custom_domain_status_check" CHECK ((("custom_domain_status" IS NULL) OR ("custom_domain_status" = ANY (ARRAY['pending'::"text", 'active'::"text", 'error'::"text"])))),
    CONSTRAINT "sites_moderation_state_check" CHECK ((("moderation_state")::"text" = ANY ((ARRAY['active'::character varying, 'warned'::character varying, 'hidden'::character varying])::"text"[]))),
    CONSTRAINT "sites_shop_link_mode_check" CHECK (("shop_link_mode" = ANY (ARRAY['checkout'::"text", 'product'::"text"])))
);


ALTER TABLE "site"."sites" OWNER TO "postgres";


COMMENT ON COLUMN "site"."sites"."unpublished_at" IS 'Set by AccountDeletionService when a pending_deletion transition forces is_published=false. NULL means the site was manually unpublished.';



CREATE OR REPLACE VIEW "site"."public_site_payload" AS
 SELECT "s"."id" AS "site_id",
    "s"."user_id",
    "s"."subdomain",
    "jsonb_build_object"('site', "jsonb_build_object"('id', "s"."id", 'subdomain', "s"."subdomain", 'settings', ("s"."settings" || "jsonb_strip_nulls"("jsonb_build_object"('show_branding', "s"."show_branding", 'charlie_enabled', "s"."charlie_enabled", 'services_auto_sync_enabled', "s"."services_auto_sync_enabled", 'booking_mode', "s"."booking_mode", 'manual_booking_url', "s"."manual_booking_url"))), 'is_published', "s"."is_published", 'skeleton_id', "s"."architecture_id", 'gallery', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "sm"."id", 'alt_text', "sm"."alt_text", 'caption', "sm"."caption", 'sort_order', "sm"."sort_order", 'variants', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'webp'::"text"))), '{}'::"jsonb")) ORDER BY "sm"."sort_order", "sm"."created_at") AS "jsonb_agg"
           FROM "site"."site_media" "sm"
          WHERE (("sm"."site_id" = "s"."id") AND (("sm"."pool")::"text" = 'gallery'::"text") AND (("sm"."media_type")::"text" = 'image'::"text") AND ("sm"."deleted_at" IS NULL) AND ("sm"."is_active" = true))), '[]'::"jsonb"), 'content_images', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "sm"."id", 'alt_text', "sm"."alt_text", 'caption', "sm"."caption", 'sort_order', "sm"."sort_order", 'variants', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'webp'::"text"))), '{}'::"jsonb")) ORDER BY "sm"."sort_order", "sm"."created_at") AS "jsonb_agg"
           FROM "site"."site_media" "sm"
          WHERE (("sm"."site_id" = "s"."id") AND (("sm"."pool")::"text" = 'content'::"text") AND (("sm"."media_type")::"text" = 'image'::"text") AND ("sm"."deleted_at" IS NULL) AND ("sm"."is_active" = true))), '[]'::"jsonb"), 'gallery_videos', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "sm"."id", 'alt_text', "sm"."alt_text", 'caption', "sm"."caption", 'sort_order', "sm"."sort_order", 'media_type', "sm"."media_type", 'processing_state', "sm"."processing_state", 'duration_ms', "sm"."duration_ms", 'poster', "sm"."poster_path", 'variants', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'mp4'::"text"))), '{}'::"jsonb"), 'streams', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'hls_playlist'::"text"))), '{}'::"jsonb")) ORDER BY "sm"."sort_order", "sm"."created_at") AS "jsonb_agg"
           FROM "site"."site_media" "sm"
          WHERE (("sm"."site_id" = "s"."id") AND (("sm"."pool")::"text" = 'gallery'::"text") AND (("sm"."media_type")::"text" = 'video'::"text") AND ("sm"."deleted_at" IS NULL) AND ("sm"."is_active" = true))), '[]'::"jsonb"), 'content_videos', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "sm"."id", 'alt_text', "sm"."alt_text", 'caption', "sm"."caption", 'sort_order', "sm"."sort_order", 'media_type', "sm"."media_type", 'processing_state', "sm"."processing_state", 'duration_ms', "sm"."duration_ms", 'poster', "sm"."poster_path", 'variants', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'mp4'::"text"))), '{}'::"jsonb"), 'streams', COALESCE(( SELECT "jsonb_object_agg"("mv"."variant_key", "mv"."path") AS "jsonb_object_agg"
                   FROM "site"."media_variants" "mv"
                  WHERE (("mv"."media_id" = "sm"."id") AND (("mv"."artifact_type")::"text" = 'hls_playlist'::"text"))), '{}'::"jsonb")) ORDER BY "sm"."sort_order", "sm"."created_at") AS "jsonb_agg"
           FROM "site"."site_media" "sm"
          WHERE (("sm"."site_id" = "s"."id") AND (("sm"."pool")::"text" = 'content'::"text") AND (("sm"."media_type")::"text" = 'video'::"text") AND ("sm"."deleted_at" IS NULL) AND ("sm"."is_active" = true))), '[]'::"jsonb"), 'document', ( SELECT "jsonb_build_object"('id', "sm"."id", 'title', "sm"."alt_text", 'caption', "sm"."caption", 'original_mime', "sm"."original_mime", 'original_size_bytes', "sm"."original_size_bytes", 'original_filename', "sm"."original_filename", 'preview_url', "sm"."path", 'created_at', "sm"."created_at") AS "jsonb_build_object"
           FROM "site"."site_media" "sm"
          WHERE (("sm"."site_id" = "s"."id") AND (("sm"."pool")::"text" = 'documents'::"text") AND (("sm"."media_type")::"text" = 'document'::"text") AND ("sm"."deleted_at" IS NULL) AND ("sm"."is_active" = true))
         LIMIT 1)), 'professional', "jsonb_build_object"('id', "p"."id", 'handle', "p"."handle", 'display_name', "p"."display_name", 'country_code', "p"."country_code", 'timezone', "p"."timezone", 'public_contact_number', "p"."public_contact_number", 'public_contact_email', "p"."public_contact_email"), 'skeleton_id', "s"."architecture_id", 'links', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "b"."id", 'block_type', "b"."block_type", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'settings', "b"."settings", 'platform', "b"."platform", 'category', "b"."category", 'live_check_enabled', "b"."live_check_enabled") ORDER BY "b"."sort_order", "b"."created_at") AS "jsonb_agg"
           FROM "site"."blocks" "b"
          WHERE (("b"."site_id" = "s"."id") AND ("b"."block_group" = 'links'::"text") AND ("b"."is_active" = true) AND ("b"."deleted_at" IS NULL))), '[]'::"jsonb"), 'sections', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "b"."id", 'block_type', "b"."block_type", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'is_enabled', "b"."is_enabled", 'is_active', "b"."is_active", 'settings', "b"."settings") ORDER BY "b"."sort_order", "b"."created_at") AS "jsonb_agg"
           FROM "site"."blocks" "b"
          WHERE (("b"."site_id" = "s"."id") AND ("b"."block_group" = 'sections'::"text") AND ("b"."is_enabled" = true) AND ("b"."is_active" = true) AND ("b"."deleted_at" IS NULL))), '[]'::"jsonb"), 'services', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "sv"."id", 'title', "sv"."title", 'description', "sv"."description", 'price_cents', "sv"."price_cents", 'currency_code', "sv"."currency_code", 'duration_minutes', "sv"."duration_minutes", 'is_active', "sv"."is_active", 'sort_order', "sv"."sort_order", 'category', COALESCE("sc"."title", 'Services'::"text")) ORDER BY COALESCE("sc"."sort_order", 2147483647), ("lower"(COALESCE("sc"."title", 'Services'::"text"))), "sv"."sort_order", "sv"."created_at") AS "jsonb_agg"
           FROM ("site"."services" "sv"
             LEFT JOIN LATERAL ( SELECT "c"."title",
                    "c"."sort_order"
                   FROM ("site"."service_category_assignments" "a"
                     JOIN "site"."service_categories" "c" ON ((("c"."id" = "a"."service_category_id") AND ("c"."deleted_at" IS NULL))))
                  WHERE ("a"."service_id" = "sv"."id")
                  ORDER BY "c"."sort_order", ("lower"("c"."title"))
                 LIMIT 1) "sc" ON (true))
          WHERE (("sv"."user_id" = "p"."id") AND ("sv"."source" IS NULL) AND ("sv"."is_active" = true) AND ("sv"."deleted_at" IS NULL))), '[]'::"jsonb")) AS "payload"
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."is_published" = true) AND ("p"."status" = ANY (ARRAY['active'::"text", 'unclaimed'::"text"])) AND ("p"."deleted_at" IS NULL));


ALTER VIEW "site"."public_site_payload" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."shop_brands" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "connection_id" "uuid" NOT NULL,
    "brand_id" "text" NOT NULL,
    "provider" "text" NOT NULL,
    "url" "text",
    "source_url" "text",
    "name" "text",
    "currency" "text",
    "favicon" "text",
    "logo" "text",
    "discount_code" "text",
    "fetch_mode" "text",
    "is_individual" boolean DEFAULT false NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    "style_analysis" "jsonb",
    "created_at" timestamp with time zone DEFAULT "now"(),
    "updated_at" timestamp with time zone DEFAULT "now"(),
    "selection_mode" "text" DEFAULT 'manual'::"text" NOT NULL,
    "link_mode" "text" DEFAULT 'product'::"text" NOT NULL,
    "referral_query" "text" DEFAULT ''::"text" NOT NULL,
    "connect_status" "text",
    "connect_error" "text",
    CONSTRAINT "shop_brands_connect_status_check" CHECK ((("connect_status" IS NULL) OR ("connect_status" = ANY (ARRAY['pending'::"text", 'failed'::"text"])))),
    CONSTRAINT "shop_brands_link_mode_check" CHECK (("link_mode" = ANY (ARRAY['product'::"text", 'checkout'::"text"]))),
    CONSTRAINT "shop_brands_selection_mode_check" CHECK (("selection_mode" = ANY (ARRAY['manual'::"text", 'latest'::"text"])))
);

ALTER TABLE ONLY "site"."shop_brands" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."shop_brands" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."shop_products" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "brand_id" "uuid" NOT NULL,
    "product_id" "text" NOT NULL,
    "position" integer DEFAULT 0 NOT NULL,
    "data" "jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"(),
    "updated_at" timestamp with time zone DEFAULT "now"()
);

ALTER TABLE ONLY "site"."shop_products" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."shop_products" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."site_subdomain_aliases" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "site_id" "uuid" NOT NULL,
    "subdomain" character varying(63) NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "reclaim_until" timestamp with time zone,
    "expires_at" timestamp with time zone,
    "notified_t3_at" timestamp with time zone,
    "notified_t1_at" timestamp with time zone
);


ALTER TABLE "site"."site_subdomain_aliases" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "site"."workplaces" (
    "site_id" "uuid" NOT NULL,
    "name" "text",
    "address_line1" "text",
    "city" "text",
    "state" "text",
    "postcode" "text",
    "country" "text",
    "latitude" double precision,
    "longitude" double precision,
    "phone" "text",
    "website" "text",
    "previous_website" "text",
    "category" "text",
    "description" "text",
    "created_at" timestamp with time zone,
    "updated_at" timestamp with time zone,
    "opening_hours" "jsonb",
    "contact_email" "text",
    "field_sources" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL
);

ALTER TABLE ONLY "site"."workplaces" FORCE ROW LEVEL SECURITY;


ALTER TABLE "site"."workplaces" OWNER TO "postgres";


ALTER TABLE ONLY "public"."failed_jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."failed_jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "analytics"."action_events"
    ADD CONSTRAINT "action_events_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."content_popularity_scores"
    ADD CONSTRAINT "content_popularity_scores_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."content_popularity_scores"
    ADD CONSTRAINT "content_popularity_scores_site_type_key_uniq" UNIQUE ("site_id", "content_type", "content_key");



ALTER TABLE ONLY "analytics"."item_views"
    ADD CONSTRAINT "item_views_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."section_views"
    ADD CONSTRAINT "section_views_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."site_metrics_daily"
    ADD CONSTRAINT "site_metrics_daily_pkey" PRIMARY KEY ("day", "user_id", "site_id");



ALTER TABLE ONLY "analytics"."site_metrics_hourly"
    ADD CONSTRAINT "site_metrics_hourly_pkey" PRIMARY KEY ("hour_start", "user_id", "site_id");



ALTER TABLE ONLY "analytics"."site_sessions"
    ADD CONSTRAINT "site_sessions_pkey" PRIMARY KEY ("id", "site_id");



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."auth_factor_events"
    ADD CONSTRAINT "auth_factor_events_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."data_export_audit"
    ADD CONSTRAINT "data_export_audit_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."handle_change_log"
    ADD CONSTRAINT "handle_change_log_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."moderation_events"
    ADD CONSTRAINT "moderation_events_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."staff_audit_log"
    ADD CONSTRAINT "staff_audit_log_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "audit"."user_deletion_audit"
    ADD CONSTRAINT "user_deletion_audit_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."early_access_signups"
    ADD CONSTRAINT "early_access_signups_email_lc_unique" UNIQUE ("email_lc");



ALTER TABLE ONLY "core"."early_access_signups"
    ADD CONSTRAINT "early_access_signups_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."email_suppressions"
    ADD CONSTRAINT "email_suppressions_email_hash_unique" UNIQUE ("email_hash");



ALTER TABLE ONLY "core"."email_suppressions"
    ADD CONSTRAINT "email_suppressions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."feature_availability"
    ADD CONSTRAINT "feature_availability_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."feature_flag_overrides"
    ADD CONSTRAINT "feature_flag_overrides_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."feature_flags"
    ADD CONSTRAINT "feature_flags_pkey" PRIMARY KEY ("key");



ALTER TABLE ONLY "core"."feedback"
    ADD CONSTRAINT "feedback_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."partna_staff"
    ADD CONSTRAINT "partna_staff_Primary Email_key" UNIQUE ("primary_email");



ALTER TABLE ONLY "core"."partna_staff"
    ADD CONSTRAINT "partna_staff_auth_user_id_key" UNIQUE ("auth_user_id");



ALTER TABLE ONLY "core"."partna_staff"
    ADD CONSTRAINT "partna_staff_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."pre_account_builds"
    ADD CONSTRAINT "pre_account_builds_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."pre_account_builds"
    ADD CONSTRAINT "pre_account_builds_user_id_key" UNIQUE ("user_id");



ALTER TABLE ONLY "core"."supabase_email_events"
    ADD CONSTRAINT "supabase_email_events_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."supabase_email_events"
    ADD CONSTRAINT "supabase_email_events_webhook_id_unique" UNIQUE ("webhook_id");



ALTER TABLE ONLY "core"."user_confirmation_preferences"
    ADD CONSTRAINT "user_confirmation_preferences_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."user_confirmation_preferences"
    ADD CONSTRAINT "user_confirmation_preferences_user_action_uq" UNIQUE ("user_id", "action_key");



ALTER TABLE ONLY "core"."user_handle_aliases"
    ADD CONSTRAINT "user_handle_aliases_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."user_segment_members"
    ADD CONSTRAINT "user_segment_members_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."user_segment_members"
    ADD CONSTRAINT "user_segment_members_unique" UNIQUE ("segment_id", "user_id");



ALTER TABLE ONLY "core"."user_segments"
    ADD CONSTRAINT "user_segments_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."users"
    ADD CONSTRAINT "users_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "moderation"."action_log"
    ADD CONSTRAINT "action_log_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "moderation"."case_signals"
    ADD CONSTRAINT "case_signals_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "moderation"."cases"
    ADD CONSTRAINT "cases_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "moderation"."decisions"
    ADD CONSTRAINT "decisions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "moderation"."evidence"
    ADD CONSTRAINT "evidence_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "notifications"."broadcast_email_receipts"
    ADD CONSTRAINT "broadcast_email_receipts_pkey" PRIMARY KEY ("notification_id", "subscription_id");



ALTER TABLE ONLY "notifications"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "notifications"."notification_email_policies"
    ADD CONSTRAINT "notification_email_policies_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "notifications"."notification_email_preferences"
    ADD CONSTRAINT "notification_email_preferences_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "notifications"."notification_email_preferences"
    ADD CONSTRAINT "notification_email_preferences_user_category_uq" UNIQUE ("user_id", "category_key");



ALTER TABLE ONLY "notifications"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "notifications"."notifications"
    ADD CONSTRAINT "notifications_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_uuid_unique" UNIQUE ("uuid");



ALTER TABLE ONLY "public"."job_batches"
    ADD CONSTRAINT "job_batches_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."content_selection"
    ADD CONSTRAINT "content_selection_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."content_selection"
    ADD CONSTRAINT "content_selection_site_position_unique" UNIQUE ("site_id", "position");



ALTER TABLE ONLY "site"."customers"
    ADD CONSTRAINT "customers_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."design_kits"
    ADD CONSTRAINT "design_kits_pkey" PRIMARY KEY ("site_id");



ALTER TABLE ONLY "site"."enquiries"
    ADD CONSTRAINT "enquiries_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."item_slugs"
    ADD CONSTRAINT "item_slugs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."blocks"
    ADD CONSTRAINT "link_blocks_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."media_variants"
    ADD CONSTRAINT "media_variants_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."menu_categories"
    ADD CONSTRAINT "menu_categories_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."menu_item_categories"
    ADD CONSTRAINT "menu_item_categories_pkey" PRIMARY KEY ("menu_item_id", "menu_category_id");



ALTER TABLE ONLY "site"."menu_item_platforms"
    ADD CONSTRAINT "menu_item_platforms_menu_item_id_platform_key" UNIQUE ("menu_item_id", "platform");



ALTER TABLE ONLY "site"."menu_item_platforms"
    ADD CONSTRAINT "menu_item_platforms_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."menu_items"
    ADD CONSTRAINT "menu_items_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."menu_platform_links"
    ADD CONSTRAINT "menu_platform_links_menu_id_platform_key" UNIQUE ("menu_id", "platform");



ALTER TABLE ONLY "site"."menu_platform_links"
    ADD CONSTRAINT "menu_platform_links_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."menus"
    ADD CONSTRAINT "menus_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."platform_connections"
    ADD CONSTRAINT "platform_connections_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."service_categories"
    ADD CONSTRAINT "service_categories_id_user_unique" UNIQUE ("id", "user_id");



ALTER TABLE ONLY "site"."service_categories"
    ADD CONSTRAINT "service_categories_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."service_category_assignments"
    ADD CONSTRAINT "service_category_assignments_pkey" PRIMARY KEY ("service_id", "service_category_id");



ALTER TABLE ONLY "site"."services"
    ADD CONSTRAINT "services_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."shop_brands"
    ADD CONSTRAINT "shop_brands_connection_id_brand_id_key" UNIQUE ("connection_id", "brand_id");



ALTER TABLE ONLY "site"."shop_brands"
    ADD CONSTRAINT "shop_brands_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."shop_products"
    ADD CONSTRAINT "shop_products_brand_id_product_id_key" UNIQUE ("brand_id", "product_id");



ALTER TABLE ONLY "site"."shop_products"
    ADD CONSTRAINT "shop_products_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."site_media"
    ADD CONSTRAINT "site_media_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."site_subdomain_aliases"
    ADD CONSTRAINT "site_subdomain_aliases_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."sites"
    ADD CONSTRAINT "sites_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "site"."workplaces"
    ADD CONSTRAINT "workplaces_pkey" PRIMARY KEY ("site_id");



CREATE INDEX "action_events_occurred_at_idx" ON "analytics"."action_events" USING "btree" ("occurred_at");



CREATE INDEX "action_events_site_occurred_idx" ON "analytics"."action_events" USING "btree" ("site_id", "occurred_at");



CREATE INDEX "analytics_link_clicks_user_occurred_idx" ON "analytics"."link_clicks" USING "btree" ("user_id", "occurred_at");



CREATE INDEX "analytics_site_visits_user_occurred_idx" ON "analytics"."site_visits" USING "btree" ("user_id", "occurred_at");



CREATE INDEX "content_popularity_scores_site_type_rank_idx" ON "analytics"."content_popularity_scores" USING "btree" ("site_id", "content_type", "rank");



CREATE INDEX "item_views_occurred_at_idx" ON "analytics"."item_views" USING "btree" ("occurred_at");



CREATE INDEX "item_views_site_item_idx" ON "analytics"."item_views" USING "btree" ("site_id", "item_type", "item_id");



CREATE INDEX "item_views_site_occurred_idx" ON "analytics"."item_views" USING "btree" ("site_id", "occurred_at");



CREATE INDEX "lead_submissions_customer_id_idx" ON "analytics"."lead_submissions" USING "btree" ("customer_id") WHERE ("customer_id" IS NOT NULL);



CREATE INDEX "lead_submissions_ip_time_idx" ON "analytics"."lead_submissions" USING "btree" ("ip_hash", "occurred_at" DESC);



CREATE INDEX "lead_submissions_occurred_at_idx" ON "analytics"."lead_submissions" USING "btree" ("occurred_at");



CREATE INDEX "lead_submissions_prof_time_idx" ON "analytics"."lead_submissions" USING "btree" ("user_id", "occurred_at" DESC);



CREATE INDEX "lead_submissions_site_time_idx" ON "analytics"."lead_submissions" USING "btree" ("site_id", "occurred_at" DESC);



CREATE INDEX "link_clicks_link_time_idx" ON "analytics"."link_clicks" USING "btree" ("link_block_id", "occurred_at");



CREATE INDEX "link_clicks_occurred_at_idx" ON "analytics"."link_clicks" USING "btree" ("occurred_at");



CREATE INDEX "link_clicks_pro_date_range_idx" ON "analytics"."link_clicks" USING "btree" ("user_id", "occurred_at" DESC) INCLUDE ("link_block_id");



CREATE INDEX "link_clicks_site_time_idx" ON "analytics"."link_clicks" USING "btree" ("site_id", "occurred_at");



CREATE INDEX "link_clicks_user_platform_idx" ON "analytics"."link_clicks" USING "btree" ("user_id", "platform", "occurred_at" DESC) WHERE ("platform" IS NOT NULL);



CREATE INDEX "link_clicks_user_product_idx" ON "analytics"."link_clicks" USING "btree" ("user_id", "product_id", "occurred_at" DESC) WHERE ("product_id" IS NOT NULL);



CREATE INDEX "section_views_block_id_idx" ON "analytics"."section_views" USING "btree" ("block_id") WHERE ("block_id" IS NOT NULL);



CREATE INDEX "section_views_occurred_at_idx" ON "analytics"."section_views" USING "btree" ("occurred_at");



CREATE INDEX "section_views_session_section_idx" ON "analytics"."section_views" USING "btree" ("session_id", "section_key");



CREATE INDEX "section_views_site_section_occurred_idx" ON "analytics"."section_views" USING "btree" ("site_id", "section_key", "occurred_at" DESC);



CREATE INDEX "section_views_user_occurred_idx" ON "analytics"."section_views" USING "btree" ("user_id", "occurred_at" DESC);



CREATE INDEX "site_metrics_daily_site_day_idx" ON "analytics"."site_metrics_daily" USING "btree" ("site_id", "day" DESC);



CREATE INDEX "site_metrics_daily_user_day_idx" ON "analytics"."site_metrics_daily" USING "btree" ("user_id", "day" DESC);



CREATE INDEX "site_metrics_hourly_site_hour_idx" ON "analytics"."site_metrics_hourly" USING "btree" ("site_id", "hour_start" DESC);



CREATE INDEX "site_metrics_hourly_user_hour_idx" ON "analytics"."site_metrics_hourly" USING "btree" ("user_id", "hour_start" DESC);



CREATE INDEX "site_sessions_last_seen_at_idx" ON "analytics"."site_sessions" USING "btree" ("last_seen_at");



CREATE INDEX "site_sessions_user_last_seen_idx" ON "analytics"."site_sessions" USING "btree" ("user_id", "last_seen_at" DESC);



CREATE INDEX "site_sessions_user_started_idx" ON "analytics"."site_sessions" USING "btree" ("user_id", "started_at" DESC);



CREATE INDEX "site_visits_occurred_at_idx" ON "analytics"."site_visits" USING "btree" ("occurred_at");



CREATE INDEX "site_visits_pro_date_range_idx" ON "analytics"."site_visits" USING "btree" ("user_id", "occurred_at" DESC) INCLUDE ("country_code", "device_type");



CREATE INDEX "site_visits_site_time_idx" ON "analytics"."site_visits" USING "btree" ("site_id", "occurred_at");



CREATE INDEX "auth_factor_events_failed_window_idx" ON "audit"."auth_factor_events" USING "btree" ("user_id", "factor_id", "created_at" DESC) WHERE ("event_type" = ANY (ARRAY['verify_failed'::"text", 'verify_rejected_by_hook'::"text"]));



CREATE INDEX "auth_factor_events_user_created_idx" ON "audit"."auth_factor_events" USING "btree" ("user_id", "created_at" DESC);



CREATE UNIQUE INDEX "auth_factor_events_webhook_id_uk" ON "audit"."auth_factor_events" USING "btree" ("webhook_id") WHERE ("webhook_id" IS NOT NULL);



CREATE INDEX "data_export_audit_triggered_by_staff_idx" ON "audit"."data_export_audit" USING "btree" ("triggered_by_staff_id") WHERE ("triggered_by_staff_id" IS NOT NULL);



CREATE INDEX "data_export_audit_user_status_created_idx" ON "audit"."data_export_audit" USING "btree" ("user_id", "status", "created_at" DESC);



CREATE INDEX "handle_change_log_changed_at_idx" ON "audit"."handle_change_log" USING "btree" ("changed_at" DESC);



CREATE INDEX "handle_change_log_pro_changed_idx" ON "audit"."handle_change_log" USING "btree" ("user_id", "changed_at" DESC);



CREATE INDEX "idx_pda_actor_type_created" ON "audit"."user_deletion_audit" USING "btree" ("actor_type", "created_at" DESC) WHERE ("actor_type" <> 'professional'::"text");



CREATE INDEX "idx_pda_event_created" ON "audit"."user_deletion_audit" USING "btree" ("event", "created_at" DESC);



CREATE INDEX "idx_staff_audit_log_staff_created" ON "audit"."staff_audit_log" USING "btree" ("staff_id", "created_at" DESC);



CREATE INDEX "idx_staff_audit_log_user_created" ON "audit"."staff_audit_log" USING "btree" ("user_id", "created_at" DESC) WHERE ("user_id" IS NOT NULL);



CREATE INDEX "idx_user_deletion_audit_user_id" ON "audit"."user_deletion_audit" USING "btree" ("user_id");



CREATE INDEX "moderation_events_action_idx" ON "audit"."moderation_events" USING "btree" ("action", "created_at");



CREATE INDEX "moderation_events_staff_idx" ON "audit"."moderation_events" USING "btree" ("actor_staff_id", "created_at") WHERE ("actor_staff_id" IS NOT NULL);



CREATE INDEX "moderation_events_target_idx" ON "audit"."moderation_events" USING "btree" ("target_type", "target_id", "created_at") WHERE ("target_id" IS NOT NULL);



CREATE UNIQUE INDEX "core_users_handle_lc_unique" ON "core"."users" USING "btree" ("handle_lc") WHERE ("deleted_at" IS NULL);



CREATE UNIQUE INDEX "early_access_signups_invite_token_hash_unique" ON "core"."early_access_signups" USING "btree" ("invite_token_hash") WHERE ("invite_token_hash" IS NOT NULL);



CREATE INDEX "early_access_signups_status_idx" ON "core"."early_access_signups" USING "btree" ("status");



CREATE UNIQUE INDEX "early_access_signups_user_id_unique" ON "core"."early_access_signups" USING "btree" ("user_id") WHERE ("user_id" IS NOT NULL);



CREATE INDEX "email_suppressions_reason_created_idx" ON "core"."email_suppressions" USING "btree" ("reason", "created_at" DESC);



CREATE UNIQUE INDEX "feature_availability_global_key_unique" ON "core"."feature_availability" USING "btree" ("feature_key") WHERE ("segment_id" IS NULL);



CREATE UNIQUE INDEX "feature_availability_key_segment_unique" ON "core"."feature_availability" USING "btree" ("feature_key", "segment_id") WHERE ("segment_id" IS NOT NULL);



CREATE INDEX "feature_availability_segment_idx" ON "core"."feature_availability" USING "btree" ("segment_id") WHERE ("segment_id" IS NOT NULL);



CREATE INDEX "feature_flag_overrides_created_by_idx" ON "core"."feature_flag_overrides" USING "btree" ("created_by") WHERE ("created_by" IS NOT NULL);



CREATE INDEX "feature_flag_overrides_expires_at" ON "core"."feature_flag_overrides" USING "btree" ("expires_at") WHERE ("expires_at" IS NOT NULL);



CREATE INDEX "feature_flag_overrides_flag_key_created" ON "core"."feature_flag_overrides" USING "btree" ("flag_key", "created_at" DESC);



CREATE INDEX "feature_flag_overrides_pro_lookup" ON "core"."feature_flag_overrides" USING "btree" ("user_id", "flag_key") WHERE ("user_id" IS NOT NULL);



CREATE UNIQUE INDEX "feature_flag_overrides_pro_unique" ON "core"."feature_flag_overrides" USING "btree" ("flag_key", "user_id");



CREATE INDEX "feature_flags_active" ON "core"."feature_flags" USING "btree" ("key") WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_area_idx" ON "core"."feedback" USING "btree" ("area") WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_created_at_idx" ON "core"."feedback" USING "btree" ("created_at" DESC) WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_ip_hash_recent_idx" ON "core"."feedback" USING "btree" ("ip_hash", "created_at" DESC) WHERE (("deleted_at" IS NULL) AND ("ip_hash" IS NOT NULL));



CREATE INDEX "feedback_kind_idx" ON "core"."feedback" USING "btree" ("kind") WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_status_created_idx" ON "core"."feedback" USING "btree" ("status", "created_at" DESC) WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_type_idx" ON "core"."feedback" USING "btree" ("type") WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_user_created_idx" ON "core"."feedback" USING "btree" ("user_id", "created_at" DESC) WHERE ("deleted_at" IS NULL);



CREATE INDEX "feedback_user_id_idx" ON "core"."feedback" USING "btree" ("user_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_users_deletion_token_hash" ON "core"."users" USING "btree" ("deletion_token_hash") WHERE ("deletion_token_hash" IS NOT NULL);



CREATE INDEX "idx_users_display_name_trgm" ON "core"."users" USING "gin" ("display_name" "public"."gin_trgm_ops");



CREATE INDEX "idx_users_first_name_trgm" ON "core"."users" USING "gin" ("first_name" "public"."gin_trgm_ops");



CREATE INDEX "idx_users_handle_trgm" ON "core"."users" USING "gin" ("handle" "public"."gin_trgm_ops");



CREATE INDEX "idx_users_last_name_trgm" ON "core"."users" USING "gin" ("last_name" "public"."gin_trgm_ops") WHERE ("last_name" IS NOT NULL);



CREATE INDEX "idx_users_pending_deletion_cutoff" ON "core"."users" USING "btree" ("deletion_confirmed_at") WHERE ("status" = 'pending_deletion'::"text");



CREATE INDEX "idx_users_primary_email_trgm" ON "core"."users" USING "gin" ("primary_email" "public"."gin_trgm_ops");



CREATE INDEX "idx_users_sector_trgm" ON "core"."users" USING "gin" ("sector" "public"."gin_trgm_ops") WHERE ("sector" IS NOT NULL);



CREATE INDEX "pre_account_builds_expiry_idx" ON "core"."pre_account_builds" USING "btree" ("expires_at") WHERE ("claimed_at" IS NULL);



CREATE INDEX "pre_account_builds_ip_idx" ON "core"."pre_account_builds" USING "btree" ("created_ip_hash") WHERE (("claimed_at" IS NULL) AND ("created_ip_hash" IS NOT NULL));



CREATE UNIQUE INDEX "pre_account_builds_live_source_unique" ON "core"."pre_account_builds" USING "btree" ("source_type", "source_ref_lc") WHERE ("claimed_at" IS NULL);



CREATE INDEX "supabase_email_events_status_created_idx" ON "core"."supabase_email_events" USING "btree" ("status", "created_at" DESC);



CREATE INDEX "user_confirmation_preferences_user_idx" ON "core"."user_confirmation_preferences" USING "btree" ("user_id");



CREATE INDEX "user_handle_aliases_expires_at_idx" ON "core"."user_handle_aliases" USING "btree" ("expires_at") WHERE ("expires_at" IS NOT NULL);



CREATE UNIQUE INDEX "user_handle_aliases_handle_lc_uq" ON "core"."user_handle_aliases" USING "btree" ("lower"(("handle")::"text"));



CREATE INDEX "user_handle_aliases_user_idx" ON "core"."user_handle_aliases" USING "btree" ("user_id");



CREATE INDEX "user_segment_members_user_idx" ON "core"."user_segment_members" USING "btree" ("user_id");



CREATE INDEX "users_account_type_idx" ON "core"."users" USING "btree" ("account_type");



CREATE UNIQUE INDEX "users_auth_user_id_unique" ON "core"."users" USING "btree" ("auth_user_id") WHERE (("auth_user_id" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE INDEX "users_deleted_at_idx" ON "core"."users" USING "btree" ("deleted_at") WHERE ("deleted_at" IS NULL);



CREATE INDEX "users_email_search_idx" ON "core"."users" USING "btree" ("lower"("primary_email"));



CREATE UNIQUE INDEX "users_email_unique" ON "core"."users" USING "btree" ("lower"("primary_email")) WHERE (("primary_email" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE INDEX "users_partna_url_idx" ON "core"."users" USING "btree" ("partna_url") WHERE ("partna_url" IS NOT NULL);



CREATE UNIQUE INDEX "users_public_contact_email_unique" ON "core"."users" USING "btree" ("public_contact_email") WHERE ("public_contact_email" IS NOT NULL);



CREATE UNIQUE INDEX "users_public_contact_number_unique" ON "core"."users" USING "btree" ("public_contact_number") WHERE ("public_contact_number" IS NOT NULL);



CREATE INDEX "action_log_decision_idx" ON "moderation"."action_log" USING "btree" ("decision_id", "created_at");



CREATE INDEX "action_log_pending_idx" ON "moderation"."action_log" USING "btree" ("status", "created_at") WHERE (("status")::"text" = ANY ((ARRAY['pending'::character varying, 'dispatched'::character varying])::"text"[]));



CREATE INDEX "case_signals_case_idx" ON "moderation"."case_signals" USING "btree" ("case_id", "created_at");



CREATE UNIQUE INDEX "case_signals_dedup_uniq" ON "moderation"."case_signals" USING "btree" ("dedup_hash");



CREATE INDEX "case_signals_reporter_ip_idx" ON "moderation"."case_signals" USING "btree" ("reporter_ip_hash", "created_at") WHERE ("reporter_ip_hash" IS NOT NULL);



CREATE INDEX "case_signals_reporter_user_idx" ON "moderation"."case_signals" USING "btree" ("reporter_user_id") WHERE ("reporter_user_id" IS NOT NULL);



CREATE INDEX "cases_open_queue_idx" ON "moderation"."cases" USING "btree" ("severity" DESC, "priority", "created_at") WHERE (("status")::"text" = ANY (ARRAY[('open'::character varying)::"text", ('triaged'::character varying)::"text", ('under_review'::character varying)::"text"]));



CREATE INDEX "cases_owner_status_idx" ON "moderation"."cases" USING "btree" ("reportable_owner_user_id", "status") WHERE ("reportable_owner_user_id" IS NOT NULL);



CREATE INDEX "cases_sla_due_idx" ON "moderation"."cases" USING "btree" ("sla_due_at") WHERE ((("status")::"text" = ANY ((ARRAY['open'::character varying, 'triaged'::character varying, 'under_review'::character varying])::"text"[])) AND ("sla_due_at" IS NOT NULL));



CREATE INDEX "cases_target_open_idx" ON "moderation"."cases" USING "btree" ("reportable_type", "reportable_id") WHERE (("status")::"text" = ANY ((ARRAY['open'::character varying, 'triaged'::character varying, 'under_review'::character varying])::"text"[]));



CREATE INDEX "decisions_case_idx" ON "moderation"."decisions" USING "btree" ("case_id", "decided_at");



CREATE INDEX "decisions_supersedes_idx" ON "moderation"."decisions" USING "btree" ("supersedes_decision_id") WHERE ("supersedes_decision_id" IS NOT NULL);



CREATE INDEX "evidence_case_idx" ON "moderation"."evidence" USING "btree" ("case_id", "captured_at");



CREATE INDEX "evidence_content_hash_idx" ON "moderation"."evidence" USING "btree" ("content_hash") WHERE ("content_hash" IS NOT NULL);



CREATE INDEX "email_subs_global_list_status_idx" ON "notifications"."email_subscriptions" USING "btree" ("list_key", "status") WHERE ("user_id" IS NULL);



CREATE INDEX "email_subs_pro_list_status_idx" ON "notifications"."email_subscriptions" USING "btree" ("user_id", "list_key", "status") WHERE ("user_id" IS NOT NULL);



CREATE UNIQUE INDEX "email_subscriptions_unique_global_list_email_lc" ON "notifications"."email_subscriptions" USING "btree" ("list_key", "email_lc") WHERE ("user_id" IS NULL);



CREATE UNIQUE INDEX "email_subscriptions_unique_pro_list_email_lc" ON "notifications"."email_subscriptions" USING "btree" ("user_id", "list_key", "email_lc") WHERE ("user_id" IS NOT NULL);



CREATE UNIQUE INDEX "email_subscriptions_unsubscribe_token_unique" ON "notifications"."email_subscriptions" USING "btree" ("unsubscribe_token");



CREATE UNIQUE INDEX "notification_receipts_notification_user_uq" ON "notifications"."notification_receipts" USING "btree" ("notification_id", "user_id");



CREATE INDEX "notifications_broadcast_active_idx" ON "notifications"."notifications" USING "btree" ("created_at" DESC) WHERE ("user_id" IS NULL);



CREATE UNIQUE INDEX "notifications_dedupe_key_per_pro_uq" ON "notifications"."notifications" USING "btree" ("user_id", "dedupe_key") WHERE ("dedupe_key" IS NOT NULL);



CREATE INDEX "notifications_pro_active_idx" ON "notifications"."notifications" USING "btree" ("user_id", "created_at" DESC) WHERE ("user_id" IS NOT NULL);



CREATE INDEX "receipts_pro_idx" ON "notifications"."notification_receipts" USING "btree" ("user_id", "updated_at" DESC);



CREATE INDEX "receipts_unread_idx" ON "notifications"."notification_receipts" USING "btree" ("user_id", "notification_id") WHERE (("read_at" IS NULL) AND ("dismissed_at" IS NULL));



CREATE UNIQUE INDEX "uq_notif_email_policies_global" ON "notifications"."notification_email_policies" USING "btree" ("category_key") WHERE ("user_id" IS NULL);



CREATE UNIQUE INDEX "uq_notif_email_policies_per_user" ON "notifications"."notification_email_policies" USING "btree" ("user_id", "category_key") WHERE ("user_id" IS NOT NULL);



CREATE UNIQUE INDEX "blocks_links_site_group_sort_uq" ON "site"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE (("block_group" = 'links'::"text") AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "blocks_sections_site_group_sort_uq" ON "site"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE (("block_group" = 'sections'::"text") AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "blocks_sections_site_group_type_uq" ON "site"."blocks" USING "btree" ("site_id", "block_group", "block_type") WHERE (("block_group" = 'sections'::"text") AND ("deleted_at" IS NULL));



CREATE INDEX "blocks_site_group_active_idx" ON "site"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "blocks_site_type_active_idx" ON "site"."blocks" USING "btree" ("site_id", "block_type", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "core_link_blocks_user_sort_idx" ON "site"."blocks" USING "btree" ("user_id", "sort_order");



CREATE INDEX "core_site_subdomain_aliases_site_id_idx" ON "site"."site_subdomain_aliases" USING "btree" ("site_id");



CREATE UNIQUE INDEX "core_site_subdomain_aliases_subdomain_lower_unique" ON "site"."site_subdomain_aliases" USING "btree" ("lower"(("subdomain")::"text"));



CREATE UNIQUE INDEX "core_sites_subdomain_lower_unique" ON "site"."sites" USING "btree" ("lower"("subdomain"));



CREATE INDEX "customers_marketing_opt_in_cached_idx" ON "site"."customers" USING "btree" ("user_id", "marketing_opt_in_cached") WHERE ("marketing_opt_in_cached" IS NOT NULL);



CREATE INDEX "customers_user_deleted_at_idx" ON "site"."customers" USING "btree" ("user_id", "deleted_at");



CREATE INDEX "customers_user_email_search_idx" ON "site"."customers" USING "btree" ("user_id", "lower"("email")) WHERE (("email" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "customers_user_email_unique" ON "site"."customers" USING "btree" ("user_id", "lower"("email")) WHERE ("email" IS NOT NULL);



CREATE INDEX "customers_user_id_idx" ON "site"."customers" USING "btree" ("user_id");



CREATE INDEX "customers_user_name_search_idx" ON "site"."customers" USING "btree" ("user_id", "lower"("full_name")) WHERE (("full_name" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE INDEX "customers_user_phone_search_idx" ON "site"."customers" USING "btree" ("user_id", "phone") WHERE (("phone" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "customers_user_phone_unique" ON "site"."customers" USING "btree" ("user_id", "phone") WHERE ("phone" IS NOT NULL);



CREATE INDEX "enquiries_ip_hash_idx" ON "site"."enquiries" USING "btree" ("ip_hash", "created_at") WHERE ("deleted_at" IS NULL);



CREATE INDEX "enquiries_site_idx" ON "site"."enquiries" USING "btree" ("site_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_blocks_live_check_enabled_active" ON "site"."blocks" USING "btree" ("site_id") WHERE ("live_check_enabled" AND ("block_group" = 'links'::"text") AND ("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "idx_enquiries_customer" ON "site"."enquiries" USING "btree" ("customer_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_enquiries_notification" ON "site"."enquiries" USING "btree" ("notification_id") WHERE ("notification_id" IS NOT NULL);



CREATE INDEX "idx_enquiries_user_status_created" ON "site"."enquiries" USING "btree" ("user_id", "status", "created_at" DESC) WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_menu_categories_menu" ON "site"."menu_categories" USING "btree" ("menu_id", "position");



CREATE INDEX "idx_menu_item_categories_category" ON "site"."menu_item_categories" USING "btree" ("menu_category_id", "position");



CREATE INDEX "idx_menu_item_platforms_item" ON "site"."menu_item_platforms" USING "btree" ("menu_item_id");



CREATE INDEX "idx_menu_items_menu" ON "site"."menu_items" USING "btree" ("menu_id");



CREATE INDEX "idx_menu_platform_links_menu" ON "site"."menu_platform_links" USING "btree" ("menu_id");



CREATE UNIQUE INDEX "idx_menus_user_unique" ON "site"."menus" USING "btree" ("user_id") WHERE ("deleted_at" IS NULL);



CREATE UNIQUE INDEX "idx_platform_connections_canonical" ON "site"."platform_connections" USING "btree" ("user_id", "platform", "canonical_key") WHERE (("canonical_key" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE INDEX "idx_platform_connections_last_refreshed" ON "site"."platform_connections" USING "btree" ("last_refreshed_at") WHERE (("deleted_at" IS NULL) AND "is_active");



CREATE UNIQUE INDEX "idx_platform_connections_unique_active" ON "site"."platform_connections" USING "btree" ("user_id", "platform", "resource_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_platform_connections_user_place_id" ON "site"."platform_connections" USING "btree" ("user_id", "place_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_platform_connections_user_platform_sort" ON "site"."platform_connections" USING "btree" ("user_id", "platform", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "idx_service_category_assignments_category" ON "site"."service_category_assignments" USING "btree" ("service_category_id");



CREATE INDEX "idx_shop_brands_connection" ON "site"."shop_brands" USING "btree" ("connection_id");



CREATE INDEX "idx_shop_products_brand" ON "site"."shop_products" USING "btree" ("brand_id", "position");



CREATE INDEX "idx_sites_subdomain_trgm" ON "site"."sites" USING "gin" ("subdomain" "public"."gin_trgm_ops");



CREATE INDEX "item_slugs_lookup" ON "site"."item_slugs" USING "btree" ("user_id", "item_type", "item_key");



CREATE UNIQUE INDEX "item_slugs_one_current" ON "site"."item_slugs" USING "btree" ("user_id", "item_type", "item_key") WHERE "is_current";



CREATE UNIQUE INDEX "item_slugs_unique_slug" ON "site"."item_slugs" USING "btree" ("user_id", "slug");



CREATE INDEX "link_blocks_pro_group_sort_idx" ON "site"."blocks" USING "btree" ("user_id", "block_group", "sort_order");



CREATE INDEX "link_blocks_site_group_sort_idx" ON "site"."blocks" USING "btree" ("site_id", "block_group", "sort_order");



CREATE INDEX "link_blocks_site_id_idx" ON "site"."blocks" USING "btree" ("site_id", "sort_order");



CREATE INDEX "mv_media_artifact_type" ON "site"."media_variants" USING "btree" ("media_id", "artifact_type");



CREATE INDEX "mv_media_id" ON "site"."media_variants" USING "btree" ("media_id");



CREATE UNIQUE INDEX "mv_media_variant_artifact" ON "site"."media_variants" USING "btree" ("media_id", "variant_key", "artifact_type");



CREATE UNIQUE INDEX "service_categories_unique_title_per_user" ON "site"."service_categories" USING "btree" ("user_id", "lower"("title")) WHERE ("deleted_at" IS NULL);



CREATE INDEX "service_categories_user_sort_idx" ON "site"."service_categories" USING "btree" ("user_id", "sort_order");



CREATE INDEX "services_active_order_idx" ON "site"."services" USING "btree" ("user_id", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "services_pro_active_sort_covering_idx" ON "site"."services" USING "btree" ("user_id", "sort_order") INCLUDE ("title", "price_cents", "is_active") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "services_prof_sort_idx" ON "site"."services" USING "btree" ("user_id", "sort_order", "created_at");



CREATE UNIQUE INDEX "services_user_fresha_external_uq" ON "site"."services" USING "btree" ("user_id", "external_id") WHERE (("source" = 'fresha'::"text") AND ("deleted_at" IS NULL));



CREATE INDEX "services_user_id_deleted_at_idx" ON "site"."services" USING "btree" ("user_id", "deleted_at");



CREATE UNIQUE INDEX "services_user_sort_order_uq" ON "site"."services" USING "btree" ("user_id", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "site_images_site_active_idx" ON "site"."site_media" USING "btree" ("site_id") WHERE ("deleted_at" IS NULL);



CREATE UNIQUE INDEX "site_images_site_sort_active_unique" ON "site"."site_media" USING "btree" ("site_id", "pool", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "site_images_site_sort_idx" ON "site"."site_media" USING "btree" ("site_id", "sort_order");



CREATE UNIQUE INDEX "site_images_site_sort_order_active_uq" ON "site"."site_media" USING "btree" ("site_id", "pool", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE UNIQUE INDEX "site_media_design_placeholder_sort_uq" ON "site"."site_media" USING "btree" ("site_id", "sort_order") WHERE ((("pool")::"text" = 'design'::"text") AND ("purpose" = 'placeholder'::"text") AND ("deleted_at" IS NULL) AND ("is_active" = true));



CREATE UNIQUE INDEX "site_media_design_singleton_purpose_uq" ON "site"."site_media" USING "btree" ("site_id", "purpose") WHERE ((("pool")::"text" = 'design'::"text") AND ("deleted_at" IS NULL));



CREATE INDEX "site_media_scanning_idx" ON "site"."site_media" USING "btree" ("created_at") WHERE ((("processing_state")::"text" = 'scanning'::"text") AND ("scanned_at" IS NULL));



CREATE INDEX "site_media_site_active_sort_covering_idx" ON "site"."site_media" USING "btree" ("site_id", "sort_order") INCLUDE ("alt_text", "caption", "media_type", "pool", "original_mime", "original_size_bytes", "path", "original_filename") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "site_subdomain_aliases_expires_at_idx" ON "site"."site_subdomain_aliases" USING "btree" ("expires_at") WHERE ("expires_at" IS NOT NULL);



CREATE UNIQUE INDEX "sites_custom_domain_unique" ON "site"."sites" USING "btree" ("lower"("custom_domain")) WHERE ("custom_domain" IS NOT NULL);



CREATE UNIQUE INDEX "sites_user_unique" ON "site"."sites" USING "btree" ("user_id");



CREATE INDEX "sm_pool_active" ON "site"."site_media" USING "btree" ("site_id", "pool", "is_active");



CREATE INDEX "sm_pool_media_active" ON "site"."site_media" USING "btree" ("site_id", "pool", "media_type", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE OR REPLACE VIEW "site"."all_site_data" AS
 SELECT "s"."id" AS "site_id",
    "s"."user_id",
    "s"."subdomain",
    "s"."is_published",
    "s"."architecture_id",
    ("s"."settings" || "jsonb_strip_nulls"("jsonb_build_object"('show_branding', "s"."show_branding", 'charlie_enabled', "s"."charlie_enabled", 'services_auto_sync_enabled', "s"."services_auto_sync_enabled", 'booking_mode', "s"."booking_mode", 'manual_booking_url', "s"."manual_booking_url"))) AS "site_settings",
    "s"."created_at" AS "site_created_at",
    "s"."updated_at" AS "site_updated_at",
    "p"."handle",
    "p"."display_name",
    "p"."location_street_address",
    "p"."location_city",
    "p"."location_state",
    "p"."location_postcode",
    "p"."location_country",
    COALESCE("jsonb_agg"("jsonb_build_object"('id', "b"."id", 'site_id', "b"."site_id", 'user_id', "b"."user_id", 'block_type', "b"."block_type", 'block_group', "b"."block_group", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'is_active', "b"."is_active", 'settings', "b"."settings", 'platform', "b"."platform", 'category', "b"."category", 'live_check_enabled', "b"."live_check_enabled", 'created_at', "b"."created_at", 'updated_at', "b"."updated_at") ORDER BY "b"."sort_order") FILTER (WHERE ("b"."id" IS NOT NULL)), '[]'::"jsonb") AS "blocks",
    "p"."account_type"
   FROM (("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
     LEFT JOIN "site"."blocks" "b" ON (("b"."site_id" = "s"."id")))
  GROUP BY "s"."id", "p"."id";



CREATE OR REPLACE TRIGGER "handle_change_log_no_update" BEFORE DELETE OR UPDATE ON "audit"."handle_change_log" FOR EACH ROW EXECUTE FUNCTION "core"."trg_handle_change_log_append_only"();



CREATE OR REPLACE TRIGGER "staff_audit_log_reject_mutation" BEFORE DELETE OR UPDATE ON "audit"."staff_audit_log" FOR EACH ROW EXECUTE FUNCTION "core"."reject_staff_audit_log_mutation"();



CREATE OR REPLACE TRIGGER "prevent_staff_escalation" BEFORE UPDATE ON "core"."partna_staff" FOR EACH ROW EXECUTE FUNCTION "core"."prevent_staff_escalation"();



CREATE OR REPLACE TRIGGER "set_timestamp_email_suppressions" BEFORE UPDATE ON "core"."email_suppressions" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_feature_flag_overrides" BEFORE UPDATE ON "core"."feature_flag_overrides" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_feature_flags" BEFORE UPDATE ON "core"."feature_flags" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_feedback" BEFORE UPDATE ON "core"."feedback" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_partna_staff" BEFORE UPDATE ON "core"."partna_staff" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_supabase_email_events" BEFORE UPDATE ON "core"."supabase_email_events" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_users" BEFORE UPDATE ON "core"."users" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "trg_user_confirmation_preferences_set_updated_at" BEFORE UPDATE ON "core"."user_confirmation_preferences" FOR EACH ROW EXECUTE FUNCTION "core"."set_user_confirmation_preferences_updated_at"();



CREATE OR REPLACE TRIGGER "user_handle_alias_check_bu" BEFORE UPDATE OF "handle" ON "core"."users" FOR EACH ROW WHEN (("old"."handle" IS DISTINCT FROM "new"."handle")) EXECUTE FUNCTION "core"."trg_user_handle_alias_check"();



CREATE OR REPLACE TRIGGER "user_handle_change_au" AFTER UPDATE OF "handle" ON "core"."users" FOR EACH ROW WHEN (("old"."handle" IS DISTINCT FROM "new"."handle")) EXECUTE FUNCTION "core"."trg_user_handle_change"();



CREATE OR REPLACE TRIGGER "set_timestamp_email_subscriptions" BEFORE UPDATE ON "notifications"."email_subscriptions" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_notification_email_policies" BEFORE UPDATE ON "notifications"."notification_email_policies" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_notification_email_preferences" BEFORE UPDATE ON "notifications"."notification_email_preferences" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_notification_receipts" BEFORE UPDATE ON "notifications"."notification_receipts" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_notifications" BEFORE UPDATE ON "notifications"."notifications" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "enforce_site_gallery_max6" BEFORE INSERT OR UPDATE OF "site_id", "deleted_at" ON "site"."site_media" FOR EACH ROW EXECUTE FUNCTION "core"."enforce_site_gallery_max6"();



CREATE OR REPLACE TRIGGER "set_timestamp_customers" BEFORE UPDATE ON "site"."customers" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_design_kits" BEFORE UPDATE ON "site"."design_kits" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_enquiries" BEFORE UPDATE ON "site"."enquiries" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_link_blocks" BEFORE UPDATE ON "site"."blocks" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_platform_connections" BEFORE UPDATE ON "site"."platform_connections" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_services" BEFORE UPDATE ON "site"."services" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_site_media" BEFORE UPDATE ON "site"."site_media" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_site_subdomain_aliases" BEFORE UPDATE ON "site"."site_subdomain_aliases" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_sites" BEFORE UPDATE ON "site"."sites" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "sites_url_sync_aiu" AFTER INSERT OR UPDATE OF "subdomain" ON "site"."sites" FOR EACH ROW EXECUTE FUNCTION "site"."trg_sites_url_sync"();



CREATE OR REPLACE TRIGGER "trg_create_empty_design_kit" AFTER INSERT ON "site"."sites" FOR EACH ROW EXECUTE FUNCTION "site"."create_empty_design_kit"();



CREATE OR REPLACE TRIGGER "trg_media_variants_set_updated_at" BEFORE UPDATE ON "site"."media_variants" FOR EACH ROW EXECUTE FUNCTION "core"."set_media_variants_updated_at"();



CREATE OR REPLACE TRIGGER "trg_service_categories_updated_at" BEFORE UPDATE ON "site"."service_categories" FOR EACH ROW EXECUTE FUNCTION "core"."set_updated_at"();



ALTER TABLE ONLY "analytics"."action_events"
    ADD CONSTRAINT "action_events_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."content_popularity_scores"
    ADD CONSTRAINT "content_popularity_scores_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."item_views"
    ADD CONSTRAINT "item_views_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_customer_fk" FOREIGN KEY ("customer_id") REFERENCES "site"."customers"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_block_fk" FOREIGN KEY ("link_block_id") REFERENCES "site"."blocks"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."section_views"
    ADD CONSTRAINT "section_views_block_fk" FOREIGN KEY ("block_id") REFERENCES "site"."blocks"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."section_views"
    ADD CONSTRAINT "section_views_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."section_views"
    ADD CONSTRAINT "section_views_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_metrics_daily"
    ADD CONSTRAINT "site_metrics_daily_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_metrics_daily"
    ADD CONSTRAINT "site_metrics_daily_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_metrics_hourly"
    ADD CONSTRAINT "site_metrics_hourly_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_metrics_hourly"
    ADD CONSTRAINT "site_metrics_hourly_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_sessions"
    ADD CONSTRAINT "site_sessions_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_sessions"
    ADD CONSTRAINT "site_sessions_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "audit"."auth_factor_events"
    ADD CONSTRAINT "auth_factor_events_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."data_export_audit"
    ADD CONSTRAINT "data_export_audit_staff_fk" FOREIGN KEY ("triggered_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."data_export_audit"
    ADD CONSTRAINT "data_export_audit_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."handle_change_log"
    ADD CONSTRAINT "handle_change_log_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."moderation_events"
    ADD CONSTRAINT "moderation_events_staff_fk" FOREIGN KEY ("actor_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."staff_audit_log"
    ADD CONSTRAINT "staff_audit_log_impersonator_fk" FOREIGN KEY ("impersonator_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."staff_audit_log"
    ADD CONSTRAINT "staff_audit_log_staff_fk" FOREIGN KEY ("staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."staff_audit_log"
    ADD CONSTRAINT "staff_audit_log_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "audit"."user_deletion_audit"
    ADD CONSTRAINT "user_deletion_audit_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."early_access_signups"
    ADD CONSTRAINT "early_access_signups_invited_by_fkey" FOREIGN KEY ("invited_by") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."early_access_signups"
    ADD CONSTRAINT "early_access_signups_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."feature_availability"
    ADD CONSTRAINT "feature_availability_created_by_fkey" FOREIGN KEY ("created_by") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."feature_availability"
    ADD CONSTRAINT "feature_availability_segment_id_fkey" FOREIGN KEY ("segment_id") REFERENCES "core"."user_segments"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."feature_flag_overrides"
    ADD CONSTRAINT "feature_flag_overrides_created_by_fkey" FOREIGN KEY ("created_by") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."feature_flag_overrides"
    ADD CONSTRAINT "feature_flag_overrides_flag_key_fkey" FOREIGN KEY ("flag_key") REFERENCES "core"."feature_flags"("key") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."feature_flag_overrides"
    ADD CONSTRAINT "feature_flag_overrides_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."feedback"
    ADD CONSTRAINT "feedback_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."partna_staff"
    ADD CONSTRAINT "partna_staff_auth_user_id_fkey" FOREIGN KEY ("auth_user_id") REFERENCES "auth"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."pre_account_builds"
    ADD CONSTRAINT "pre_account_builds_built_by_staff_id_fkey" FOREIGN KEY ("built_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."pre_account_builds"
    ADD CONSTRAINT "pre_account_builds_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."user_confirmation_preferences"
    ADD CONSTRAINT "user_confirmation_preferences_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."user_handle_aliases"
    ADD CONSTRAINT "user_handle_aliases_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."user_segment_members"
    ADD CONSTRAINT "user_segment_members_added_by_fkey" FOREIGN KEY ("added_by") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."user_segment_members"
    ADD CONSTRAINT "user_segment_members_segment_id_fkey" FOREIGN KEY ("segment_id") REFERENCES "core"."user_segments"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."user_segment_members"
    ADD CONSTRAINT "user_segment_members_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."user_segments"
    ADD CONSTRAINT "user_segments_created_by_fkey" FOREIGN KEY ("created_by") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."users"
    ADD CONSTRAINT "users_auth_user_id_fkey" FOREIGN KEY ("auth_user_id") REFERENCES "auth"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."action_log"
    ADD CONSTRAINT "action_log_decision_fk" FOREIGN KEY ("decision_id") REFERENCES "moderation"."decisions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "moderation"."case_signals"
    ADD CONSTRAINT "case_signals_case_fk" FOREIGN KEY ("case_id") REFERENCES "moderation"."cases"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "moderation"."case_signals"
    ADD CONSTRAINT "case_signals_reporter_user_fk" FOREIGN KEY ("reporter_user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."cases"
    ADD CONSTRAINT "cases_owner_user_fk" FOREIGN KEY ("reportable_owner_user_id") REFERENCES "core"."users"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."cases"
    ADD CONSTRAINT "cases_triaged_by_staff_fk" FOREIGN KEY ("triaged_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."decisions"
    ADD CONSTRAINT "decisions_case_fk" FOREIGN KEY ("case_id") REFERENCES "moderation"."cases"("id") ON DELETE RESTRICT;



ALTER TABLE ONLY "moderation"."decisions"
    ADD CONSTRAINT "decisions_second_staff_fk" FOREIGN KEY ("second_staff_approval_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."decisions"
    ADD CONSTRAINT "decisions_staff_fk" FOREIGN KEY ("decided_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."decisions"
    ADD CONSTRAINT "decisions_supersedes_fk" FOREIGN KEY ("supersedes_decision_id") REFERENCES "moderation"."decisions"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "moderation"."evidence"
    ADD CONSTRAINT "evidence_case_fk" FOREIGN KEY ("case_id") REFERENCES "moderation"."cases"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "moderation"."evidence"
    ADD CONSTRAINT "evidence_signal_fk" FOREIGN KEY ("signal_id") REFERENCES "moderation"."case_signals"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "notifications"."broadcast_email_receipts"
    ADD CONSTRAINT "broadcast_email_receipts_notification_id_fkey" FOREIGN KEY ("notification_id") REFERENCES "notifications"."notifications"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."broadcast_email_receipts"
    ADD CONSTRAINT "broadcast_email_receipts_subscription_id_fkey" FOREIGN KEY ("subscription_id") REFERENCES "notifications"."email_subscriptions"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."notification_email_policies"
    ADD CONSTRAINT "notification_email_policies_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."notification_email_preferences"
    ADD CONSTRAINT "notification_email_preferences_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_notification_id_fkey" FOREIGN KEY ("notification_id") REFERENCES "notifications"."notifications"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "notifications"."notifications"
    ADD CONSTRAINT "notifications_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."blocks"
    ADD CONSTRAINT "blocks_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."blocks"
    ADD CONSTRAINT "blocks_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."content_selection"
    ADD CONSTRAINT "content_selection_media_id_fkey" FOREIGN KEY ("media_id") REFERENCES "site"."site_media"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."content_selection"
    ADD CONSTRAINT "content_selection_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."customers"
    ADD CONSTRAINT "customers_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."design_kits"
    ADD CONSTRAINT "design_kits_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."enquiries"
    ADD CONSTRAINT "enquiries_customer_fk" FOREIGN KEY ("customer_id") REFERENCES "site"."customers"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "site"."enquiries"
    ADD CONSTRAINT "enquiries_notification_fk" FOREIGN KEY ("notification_id") REFERENCES "notifications"."notifications"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "site"."enquiries"
    ADD CONSTRAINT "enquiries_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."enquiries"
    ADD CONSTRAINT "enquiries_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."item_slugs"
    ADD CONSTRAINT "item_slugs_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."media_variants"
    ADD CONSTRAINT "media_variants_media_id_fkey" FOREIGN KEY ("media_id") REFERENCES "site"."site_media"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_categories"
    ADD CONSTRAINT "menu_categories_menu_id_fkey" FOREIGN KEY ("menu_id") REFERENCES "site"."menus"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_item_categories"
    ADD CONSTRAINT "menu_item_categories_menu_category_id_fkey" FOREIGN KEY ("menu_category_id") REFERENCES "site"."menu_categories"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_item_categories"
    ADD CONSTRAINT "menu_item_categories_menu_item_id_fkey" FOREIGN KEY ("menu_item_id") REFERENCES "site"."menu_items"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_item_platforms"
    ADD CONSTRAINT "menu_item_platforms_menu_item_id_fkey" FOREIGN KEY ("menu_item_id") REFERENCES "site"."menu_items"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_items"
    ADD CONSTRAINT "menu_items_menu_id_fkey" FOREIGN KEY ("menu_id") REFERENCES "site"."menus"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menu_platform_links"
    ADD CONSTRAINT "menu_platform_links_menu_id_fkey" FOREIGN KEY ("menu_id") REFERENCES "site"."menus"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."menus"
    ADD CONSTRAINT "menus_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."platform_connections"
    ADD CONSTRAINT "platform_connections_user_id_fkey" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."service_categories"
    ADD CONSTRAINT "service_categories_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."service_category_assignments"
    ADD CONSTRAINT "service_category_assignments_service_category_id_fkey" FOREIGN KEY ("service_category_id") REFERENCES "site"."service_categories"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."service_category_assignments"
    ADD CONSTRAINT "service_category_assignments_service_id_fkey" FOREIGN KEY ("service_id") REFERENCES "site"."services"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."services"
    ADD CONSTRAINT "services_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."shop_brands"
    ADD CONSTRAINT "shop_brands_connection_id_fkey" FOREIGN KEY ("connection_id") REFERENCES "site"."platform_connections"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."shop_products"
    ADD CONSTRAINT "shop_products_brand_id_fkey" FOREIGN KEY ("brand_id") REFERENCES "site"."shop_brands"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."site_media"
    ADD CONSTRAINT "site_media_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."site_subdomain_aliases"
    ADD CONSTRAINT "site_subdomain_aliases_site_fk" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."sites"
    ADD CONSTRAINT "sites_user_fk" FOREIGN KEY ("user_id") REFERENCES "core"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "site"."workplaces"
    ADD CONSTRAINT "workplaces_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "site"."sites"("id") ON DELETE CASCADE;



ALTER TABLE "analytics"."action_events" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "analytics"."content_popularity_scores" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "content_popularity_scores_owner_select" ON "analytics"."content_popularity_scores" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "u" ON (("u"."id" = "s"."user_id")))
  WHERE (("s"."id" = "content_popularity_scores"."site_id") AND ("u"."auth_user_id" = "auth"."uid"()) AND ("u"."deleted_at" IS NULL)))));



CREATE POLICY "content_popularity_scores_service_role_all" ON "analytics"."content_popularity_scores" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "content_popularity_scores_staff_select" ON "analytics"."content_popularity_scores" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "analytics"."item_views" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "item_views_owner_select" ON "analytics"."item_views" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "item_views_service_role_all" ON "analytics"."item_views" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "item_views_staff_select" ON "analytics"."item_views" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "analytics"."lead_submissions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "lead_submissions_owner_select" ON "analytics"."lead_submissions" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "lead_submissions_staff_select" ON "analytics"."lead_submissions" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "analytics"."link_clicks" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "link_clicks_anyone_insert_valid_block" ON "analytics"."link_clicks" FOR INSERT TO "anon" WITH CHECK ((EXISTS ( SELECT 1
   FROM ("site"."blocks" "b"
     JOIN "site"."sites" "s" ON (("s"."id" = "b"."site_id")))
  WHERE (("b"."id" = "link_clicks"."link_block_id") AND ("b"."site_id" = "link_clicks"."site_id") AND ("b"."user_id" = "link_clicks"."user_id") AND ("b"."is_active" = true) AND ("s"."is_published" = true)))));



CREATE POLICY "link_clicks_staff_all" ON "analytics"."link_clicks" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "analytics"."section_views" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "section_views_owner_select" ON "analytics"."section_views" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "section_views_service_role_all" ON "analytics"."section_views" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "section_views_staff_select" ON "analytics"."section_views" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "analytics"."site_metrics_daily" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_metrics_daily_pro_select" ON "analytics"."site_metrics_daily" FOR SELECT TO "authenticated" USING ((("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_metrics_daily_staff_update" ON "analytics"."site_metrics_daily" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "site_metrics_daily_staff_write" ON "analytics"."site_metrics_daily" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "analytics"."site_metrics_hourly" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_metrics_hourly_pro_select" ON "analytics"."site_metrics_hourly" FOR SELECT TO "authenticated" USING ((("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_metrics_hourly_staff_update" ON "analytics"."site_metrics_hourly" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "site_metrics_hourly_staff_write" ON "analytics"."site_metrics_hourly" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "analytics"."site_sessions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_sessions_owner_select" ON "analytics"."site_sessions" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



ALTER TABLE "analytics"."site_visits" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_visits_anyone_insert_valid_site" ON "analytics"."site_visits" FOR INSERT TO "anon" WITH CHECK ((EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "site_visits"."site_id") AND ("s"."user_id" = "site_visits"."user_id") AND ("s"."is_published" = true)))));



CREATE POLICY "site_visits_staff_all" ON "analytics"."site_visits" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "audit"."auth_factor_events" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "audit"."data_export_audit" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "data_export_audit_app_backend_all" ON "audit"."data_export_audit" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "data_export_audit_owner_select" ON "audit"."data_export_audit" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "data_export_audit_staff_select" ON "audit"."data_export_audit" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "audit"."handle_change_log" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "handle_change_log_app_backend_all" ON "audit"."handle_change_log" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "handle_change_log_staff_select" ON "audit"."handle_change_log" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "audit"."moderation_events" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "moderation_events_app_backend_insert" ON "audit"."moderation_events" FOR INSERT TO "app_backend" WITH CHECK (true);



CREATE POLICY "moderation_events_app_backend_select" ON "audit"."moderation_events" FOR SELECT TO "app_backend" USING (true);



CREATE POLICY "moderation_events_staff_select" ON "audit"."moderation_events" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



CREATE POLICY "professional_deletion_audit_app_backend_all" ON "audit"."user_deletion_audit" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "professional_deletion_audit_staff_select" ON "audit"."user_deletion_audit" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



CREATE POLICY "service role inserts" ON "audit"."auth_factor_events" FOR INSERT TO "service_role" WITH CHECK (true);



CREATE POLICY "service role reads" ON "audit"."auth_factor_events" FOR SELECT TO "service_role" USING (true);



ALTER TABLE "audit"."staff_audit_log" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "staff_audit_log_app_backend_insert" ON "audit"."staff_audit_log" FOR INSERT TO "app_backend" WITH CHECK (true);



CREATE POLICY "staff_audit_log_app_backend_select" ON "audit"."staff_audit_log" FOR SELECT TO "app_backend" USING (true);



CREATE POLICY "staff_audit_log_staff_select" ON "audit"."staff_audit_log" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "audit"."user_deletion_audit" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "confirmation_prefs_pro_all" ON "core"."user_confirmation_preferences" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "confirmation_prefs_staff_all" ON "core"."user_confirmation_preferences" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."early_access_signups" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "early_access_signups_service_role_all" ON "core"."early_access_signups" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "early_access_signups_staff_select" ON "core"."early_access_signups" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."email_suppressions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "email_suppressions_staff_read" ON "core"."email_suppressions" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."feature_availability" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "feature_availability_service_role_all" ON "core"."feature_availability" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "feature_availability_staff_select" ON "core"."feature_availability" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."feature_flag_overrides" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "feature_flag_overrides_app_backend_all" ON "core"."feature_flag_overrides" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "feature_flag_overrides_staff_all" ON "core"."feature_flag_overrides" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"])))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."feature_flags" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "feature_flags_app_backend_all" ON "core"."feature_flags" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "feature_flags_staff_all" ON "core"."feature_flags" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"])))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."feedback" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "feedback_all_authenticated" ON "core"."feedback" TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "feedback"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "feedback"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "handle_aliases_owner_select" ON "core"."user_handle_aliases" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "handle_aliases_staff_select" ON "core"."user_handle_aliases" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."partna_staff" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "partna_staff_delete_admin" ON "core"."partna_staff" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text")))));



CREATE POLICY "partna_staff_insert_admin" ON "core"."partna_staff" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text")))));



CREATE POLICY "partna_staff_select_authenticated" ON "core"."partna_staff" FOR SELECT TO "authenticated" USING ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "partna_staff_update_authenticated" ON "core"."partna_staff" FOR UPDATE TO "authenticated" USING ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text")))))) WITH CHECK ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



ALTER TABLE "core"."pre_account_builds" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "pre_account_builds_service_role_all" ON "core"."pre_account_builds" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "pre_account_builds_staff_select" ON "core"."pre_account_builds" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."supabase_email_events" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "supabase_email_events_staff_read" ON "core"."supabase_email_events" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."user_confirmation_preferences" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."user_handle_aliases" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."user_segment_members" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "user_segment_members_service_role_all" ON "core"."user_segment_members" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "user_segment_members_staff_select" ON "core"."user_segment_members" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."user_segments" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "user_segments_service_role_all" ON "core"."user_segments" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "user_segments_staff_select" ON "core"."user_segments" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "core"."users" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "users_delete_owner_or_admin" ON "core"."users" FOR DELETE TO "authenticated" USING ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "users_insert_owner_or_admin" ON "core"."users" FOR INSERT TO "authenticated" WITH CHECK ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "users_select_authenticated" ON "core"."users" FOR SELECT TO "authenticated" USING ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "users_update_owner_or_admin" ON "core"."users" FOR UPDATE TO "authenticated" USING ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text")))))) WITH CHECK ((("auth_user_id" = "auth"."uid"()) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



ALTER TABLE "moderation"."action_log" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "action_log_app_backend_all" ON "moderation"."action_log" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "action_log_staff_select" ON "moderation"."action_log" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "moderation"."case_signals" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "case_signals_app_backend_all" ON "moderation"."case_signals" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "case_signals_staff_select" ON "moderation"."case_signals" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "moderation"."cases" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "cases_app_backend_all" ON "moderation"."cases" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "cases_staff_select" ON "moderation"."cases" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "moderation"."decisions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "decisions_app_backend_all" ON "moderation"."decisions" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "decisions_staff_select" ON "moderation"."decisions" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "moderation"."evidence" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "evidence_app_backend_all" ON "moderation"."evidence" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "evidence_staff_select" ON "moderation"."evidence" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "notifications"."broadcast_email_receipts" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "broadcast_email_receipts_app_backend_all" ON "notifications"."broadcast_email_receipts" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "broadcast_email_receipts_staff_select" ON "notifications"."broadcast_email_receipts" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



CREATE POLICY "email_policies_read" ON "notifications"."notification_email_policies" FOR SELECT TO "authenticated" USING ((("user_id" IS NULL) OR ("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "email_policies_staff_delete" ON "notifications"."notification_email_policies" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "email_policies_staff_update" ON "notifications"."notification_email_policies" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "email_policies_staff_write" ON "notifications"."notification_email_policies" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "email_prefs_pro_all" ON "notifications"."notification_email_preferences" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "email_prefs_staff_all" ON "notifications"."notification_email_preferences" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "email_subs_pro_all" ON "notifications"."email_subscriptions" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "email_subs_public_insert" ON "notifications"."email_subscriptions" FOR INSERT TO "anon" WITH CHECK (("email" ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::"text"));



CREATE POLICY "email_subs_public_unsubscribe" ON "notifications"."email_subscriptions" FOR SELECT TO "anon" USING (("unsubscribe_token" IS NOT NULL));



CREATE POLICY "email_subs_staff_all" ON "notifications"."email_subscriptions" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "notifications"."email_subscriptions" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "notifications"."notification_email_policies" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "notifications"."notification_email_preferences" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "notifications"."notification_receipts" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "notification_receipts_pro_all" ON "notifications"."notification_receipts" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "notification_receipts_staff_all" ON "notifications"."notification_receipts" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "notifications"."notifications" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "notifications_pro_select" ON "notifications"."notifications" FOR SELECT TO "authenticated" USING ((("user_id" IS NULL) OR ("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "notifications_staff_delete" ON "notifications"."notifications" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "notifications_staff_update" ON "notifications"."notifications" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "notifications_staff_write" ON "notifications"."notifications" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "public"."failed_jobs" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "failed_jobs_staff_all" ON "public"."failed_jobs" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "public"."job_batches" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "job_batches_staff_all" ON "public"."job_batches" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



CREATE POLICY "aliases_pro_all" ON "site"."site_subdomain_aliases" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("p"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "aliases_public_read" ON "site"."site_subdomain_aliases" FOR SELECT TO "anon" USING ((EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("s"."is_published" = true)))));



CREATE POLICY "aliases_staff_all" ON "site"."site_subdomain_aliases" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "site"."blocks" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "site"."content_selection" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "content_selection_owner_select" ON "site"."content_selection" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "u" ON (("u"."id" = "s"."user_id")))
  WHERE (("s"."id" = "content_selection"."site_id") AND ("u"."auth_user_id" = "auth"."uid"()) AND ("u"."deleted_at" IS NULL)))));



CREATE POLICY "content_selection_service_role_all" ON "site"."content_selection" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "content_selection_staff_select" ON "site"."content_selection" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "site"."customers" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "customers_delete_owner_or_admin" ON "site"."customers" FOR DELETE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "customers"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "customers_insert_owner_or_admin" ON "site"."customers" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "customers"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "customers_select_authenticated" ON "site"."customers" FOR SELECT TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "customers"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "customers_update_owner_or_admin" ON "site"."customers" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "customers"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text")))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "customers"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE (("cs"."auth_user_id" = "auth"."uid"()) AND ("cs"."role" = 'admin'::"text"))))));



ALTER TABLE "site"."design_kits" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "design_kits_delete_authenticated" ON "site"."design_kits" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "design_kits"."site_id") AND ((("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)) OR (EXISTS ( SELECT 1
           FROM "core"."partna_staff" "cs"
          WHERE ("cs"."auth_user_id" = "auth"."uid"()))))))));



CREATE POLICY "design_kits_insert_authenticated" ON "site"."design_kits" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "design_kits"."site_id") AND ((("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)) OR (EXISTS ( SELECT 1
           FROM "core"."partna_staff" "cs"
          WHERE ("cs"."auth_user_id" = "auth"."uid"()))))))));



CREATE POLICY "design_kits_public_read_published" ON "site"."design_kits" FOR SELECT TO "anon" USING ((EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "design_kits"."site_id") AND ("s"."is_published" = true)))));



CREATE POLICY "design_kits_select_authenticated" ON "site"."design_kits" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "design_kits"."site_id") AND (("s"."is_published" = true) OR (("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)) OR (EXISTS ( SELECT 1
           FROM "core"."partna_staff" "cs"
          WHERE ("cs"."auth_user_id" = "auth"."uid"()))))))));



CREATE POLICY "design_kits_update_authenticated" ON "site"."design_kits" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "design_kits"."site_id") AND ((("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)) OR (EXISTS ( SELECT 1
           FROM "core"."partna_staff" "cs"
          WHERE ("cs"."auth_user_id" = "auth"."uid"())))))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "design_kits"."site_id") AND ((("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)) OR (EXISTS ( SELECT 1
           FROM "core"."partna_staff" "cs"
          WHERE ("cs"."auth_user_id" = "auth"."uid"()))))))));



ALTER TABLE "site"."enquiries" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "enquiries_app_backend_all" ON "site"."enquiries" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "enquiries_owner_select" ON "site"."enquiries" FOR SELECT TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "enquiries_staff_select" ON "site"."enquiries" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



ALTER TABLE "site"."item_slugs" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "item_slugs_app_backend_all" ON "site"."item_slugs" TO "app_backend" USING (true) WITH CHECK (true);



CREATE POLICY "link_blocks_delete_authenticated" ON "site"."blocks" FOR DELETE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "blocks"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_insert_authenticated" ON "site"."blocks" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "blocks"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_public_read_active_published" ON "site"."blocks" FOR SELECT TO "anon" USING ((("is_active" = true) AND (EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "blocks"."site_id") AND ("s"."is_published" = true))))));



CREATE POLICY "link_blocks_select_authenticated" ON "site"."blocks" FOR SELECT TO "authenticated" USING (((("is_active" = true) AND (EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "blocks"."site_id") AND ("s"."is_published" = true))))) OR (EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "blocks"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_update_authenticated" ON "site"."blocks" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "blocks"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "blocks"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "site"."media_variants" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "media_variants_pro_all" ON "site"."media_variants" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM (("site"."site_media" "sm"
     JOIN "site"."sites" "si" ON (("si"."id" = "sm"."site_id")))
     JOIN "core"."users" "p" ON (("p"."id" = "si"."user_id")))
  WHERE (("sm"."id" = "media_variants"."media_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM (("site"."site_media" "sm"
     JOIN "site"."sites" "si" ON (("si"."id" = "sm"."site_id")))
     JOIN "core"."users" "p" ON (("p"."id" = "si"."user_id")))
  WHERE (("sm"."id" = "media_variants"."media_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))));



CREATE POLICY "media_variants_public_read" ON "site"."media_variants" FOR SELECT TO "anon" USING ((EXISTS ( SELECT 1
   FROM ("site"."site_media" "sm"
     JOIN "site"."sites" "si" ON (("si"."id" = "sm"."site_id")))
  WHERE (("sm"."id" = "media_variants"."media_id") AND ("sm"."deleted_at" IS NULL) AND ("si"."is_published" = true)))));



CREATE POLICY "media_variants_staff_all" ON "site"."media_variants" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "site"."menu_categories" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menu_categories_app_backend_all" ON "site"."menu_categories" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."menu_item_categories" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menu_item_categories_app_backend_all" ON "site"."menu_item_categories" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."menu_item_platforms" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menu_item_platforms_app_backend_all" ON "site"."menu_item_platforms" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."menu_items" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menu_items_app_backend_all" ON "site"."menu_items" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."menu_platform_links" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menu_platform_links_app_backend_all" ON "site"."menu_platform_links" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."menus" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "menus_app_backend_all" ON "site"."menus" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."platform_connections" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "platform_connections_app_backend_all" ON "site"."platform_connections" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."service_categories" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "service_categories_pro_all" ON "site"."service_categories" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "service_categories_public_read" ON "site"."service_categories" FOR SELECT TO "anon" USING ((("deleted_at" IS NULL) AND (EXISTS ( SELECT 1
   FROM "site"."sites" "si"
  WHERE (("si"."user_id" = "service_categories"."user_id") AND ("si"."is_published" = true))))));



CREATE POLICY "service_categories_staff_all" ON "site"."service_categories" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "s"
  WHERE ("s"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "site"."service_category_assignments" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "service_category_assignments_app_backend_all" ON "site"."service_category_assignments" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."services" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "services_anon_select" ON "site"."services" FOR SELECT TO "anon" USING ((("is_active" = true) AND ("deleted_at" IS NULL) AND (EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."user_id" = "services"."user_id") AND ("s"."is_published" = true))))));



CREATE POLICY "services_pro_all" ON "site"."services" TO "authenticated" USING (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL))))) WITH CHECK (("user_id" = ( SELECT "users"."id"
   FROM "core"."users"
  WHERE (("users"."auth_user_id" = "auth"."uid"()) AND ("users"."deleted_at" IS NULL)))));



CREATE POLICY "services_staff_all" ON "site"."services" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."partna_staff"
  WHERE ("partna_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "site"."shop_brands" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "shop_brands_app_backend_all" ON "site"."shop_brands" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."shop_products" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "shop_products_app_backend_all" ON "site"."shop_products" TO "app_backend" USING (true) WITH CHECK (true);



ALTER TABLE "site"."site_media" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_media_delete_staff" ON "site"."site_media" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))));



COMMENT ON POLICY "site_media_delete_staff" ON "site"."site_media" IS 'Intentionally staff-only. The app soft-deletes via the Eloquent SoftDeletes trait (UPDATE deleted_at), then PurgeSoftDeleted hard-deletes after the 30-day retention window. Hard DELETE is reserved for staff/admin cleanup; granting it to professionals would orphan R2 artifacts and bypass the retention guarantee.';



CREATE POLICY "site_media_insert_authenticated" ON "site"."site_media" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_media"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_media_public_read_published" ON "site"."site_media" FOR SELECT TO "anon" USING ((("deleted_at" IS NULL) AND (EXISTS ( SELECT 1
   FROM "site"."sites" "s"
  WHERE (("s"."id" = "site_media"."site_id") AND ("s"."is_published" = true))))));



CREATE POLICY "site_media_select_authenticated" ON "site"."site_media" FOR SELECT TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_media"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_media_update_authenticated" ON "site"."site_media" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_media"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "p" ON (("p"."id" = "s"."user_id")))
  WHERE (("s"."id" = "site_media"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "site"."site_subdomain_aliases" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "site"."sites" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "sites_delete_authenticated" ON "site"."sites" FOR DELETE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "sites"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_insert_authenticated" ON "site"."sites" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "sites"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_public_read_published" ON "site"."sites" FOR SELECT TO "anon" USING (("is_published" = true));



CREATE POLICY "sites_select_authenticated" ON "site"."sites" FOR SELECT TO "authenticated" USING ((("is_published" = true) OR (EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "sites"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_update_authenticated" ON "site"."sites" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "sites"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."users" "p"
  WHERE (("p"."id" = "sites"."user_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."partna_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "site"."workplaces" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "workplaces_owner_select" ON "site"."workplaces" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("site"."sites" "s"
     JOIN "core"."users" "u" ON (("u"."id" = "s"."user_id")))
  WHERE (("s"."id" = "workplaces"."site_id") AND ("u"."auth_user_id" = "auth"."uid"()) AND ("u"."deleted_at" IS NULL)))));



CREATE POLICY "workplaces_service_role_all" ON "site"."workplaces" TO "service_role" USING (true) WITH CHECK (true);



CREATE POLICY "workplaces_staff_select" ON "site"."workplaces" FOR SELECT TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."partna_staff" "ps"
  WHERE (("ps"."auth_user_id" = "auth"."uid"()) AND ("ps"."role" = ANY (ARRAY['admin'::"text", 'support'::"text"]))))));



GRANT USAGE ON SCHEMA "analytics" TO "anon";
GRANT USAGE ON SCHEMA "analytics" TO "authenticated";
GRANT USAGE ON SCHEMA "analytics" TO "service_role";
GRANT USAGE ON SCHEMA "analytics" TO "app_backend";



GRANT USAGE ON SCHEMA "audit" TO "service_role";
GRANT USAGE ON SCHEMA "audit" TO "app_backend";



GRANT USAGE ON SCHEMA "core" TO "anon";
GRANT USAGE ON SCHEMA "core" TO "authenticated";
GRANT USAGE ON SCHEMA "core" TO "service_role";
GRANT USAGE ON SCHEMA "core" TO "app_backend";



GRANT USAGE ON SCHEMA "moderation" TO "app_backend";
GRANT USAGE ON SCHEMA "moderation" TO "service_role";



GRANT USAGE ON SCHEMA "notifications" TO "anon";
GRANT USAGE ON SCHEMA "notifications" TO "authenticated";
GRANT USAGE ON SCHEMA "notifications" TO "service_role";
GRANT USAGE ON SCHEMA "notifications" TO "app_backend";



GRANT USAGE ON SCHEMA "public" TO "postgres";
GRANT USAGE ON SCHEMA "public" TO "anon";
GRANT USAGE ON SCHEMA "public" TO "authenticated";
GRANT USAGE ON SCHEMA "public" TO "service_role";
GRANT USAGE ON SCHEMA "public" TO "app_backend";



GRANT USAGE ON SCHEMA "site" TO "anon";
GRANT USAGE ON SCHEMA "site" TO "authenticated";
GRANT USAGE ON SCHEMA "site" TO "service_role";
GRANT USAGE ON SCHEMA "site" TO "app_backend";



REVOKE ALL ON FUNCTION "audit"."null_user_audit_links"("p_user_id" "uuid") FROM PUBLIC;
GRANT ALL ON FUNCTION "audit"."null_user_audit_links"("p_user_id" "uuid") TO "app_backend";



REVOKE ALL ON FUNCTION "audit"."prune_data_export_audit"("p_cutoff" timestamp with time zone) FROM PUBLIC;
GRANT ALL ON FUNCTION "audit"."prune_data_export_audit"("p_cutoff" timestamp with time zone) TO "app_backend";



REVOKE ALL ON FUNCTION "audit"."prune_handle_change_log"("p_cutoff" timestamp with time zone) FROM PUBLIC;
GRANT ALL ON FUNCTION "audit"."prune_handle_change_log"("p_cutoff" timestamp with time zone) TO "app_backend";



REVOKE ALL ON FUNCTION "audit"."prune_user_deletion_audit"("p_cutoff" timestamp with time zone) FROM PUBLIC;
GRANT ALL ON FUNCTION "audit"."prune_user_deletion_audit"("p_cutoff" timestamp with time zone) TO "app_backend";



REVOKE ALL ON FUNCTION "core"."live_session_ids"("p_user_id" "uuid") FROM PUBLIC;
GRANT ALL ON FUNCTION "core"."live_session_ids"("p_user_id" "uuid") TO "app_backend";



GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "anon";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "service_role";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."action_events" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."content_popularity_scores" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."item_views" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."lead_submissions" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."link_clicks" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."section_views" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."site_metrics_daily" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."site_metrics_hourly" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."site_sessions" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."site_visits" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "audit"."auth_factor_events" TO "app_backend";



GRANT SELECT,INSERT,UPDATE ON TABLE "audit"."data_export_audit" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "audit"."handle_change_log" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "audit"."moderation_events" TO "service_role";
GRANT SELECT,INSERT ON TABLE "audit"."moderation_events" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "audit"."staff_audit_log" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "audit"."user_deletion_audit" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."early_access_signups" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."email_suppressions" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."feature_availability" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."feature_flag_overrides" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."feature_flags" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."feedback" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."partna_staff" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."pre_account_builds" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."supabase_email_events" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."user_confirmation_preferences" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."user_handle_aliases" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."user_segment_members" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."user_segments" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "core"."users" TO "app_backend";



GRANT SELECT,INSERT,UPDATE ON TABLE "moderation"."action_log" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "moderation"."case_signals" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "moderation"."cases" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "moderation"."decisions" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "moderation"."evidence" TO "app_backend";



GRANT SELECT,INSERT ON TABLE "notifications"."broadcast_email_receipts" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "notifications"."email_subscriptions" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "notifications"."notification_email_policies" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "notifications"."notification_email_preferences" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "notifications"."notification_receipts" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "notifications"."notifications" TO "app_backend";



GRANT ALL ON TABLE "public"."failed_jobs" TO "anon";
GRANT ALL ON TABLE "public"."failed_jobs" TO "authenticated";
GRANT ALL ON TABLE "public"."failed_jobs" TO "service_role";
GRANT ALL ON TABLE "public"."failed_jobs" TO "app_backend";



GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "service_role";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "app_backend";



GRANT ALL ON TABLE "public"."job_batches" TO "anon";
GRANT ALL ON TABLE "public"."job_batches" TO "authenticated";
GRANT ALL ON TABLE "public"."job_batches" TO "service_role";
GRANT ALL ON TABLE "public"."job_batches" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."all_site_data" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."blocks" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."content_selection" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."customers" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."design_kits" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."enquiries" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."item_slugs" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."media_variants" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menu_categories" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menu_item_categories" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menu_item_platforms" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menu_items" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menu_platform_links" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."menus" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."platform_connections" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."service_categories" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."service_category_assignments" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."services" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."site_media" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."sites" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."public_site_payload" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."shop_brands" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."shop_products" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."site_subdomain_aliases" TO "app_backend";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "site"."workplaces" TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "analytics" GRANT SELECT,USAGE ON SEQUENCES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "analytics" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "audit" GRANT SELECT,INSERT ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "audit" GRANT SELECT,INSERT ON TABLES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "core" GRANT SELECT,USAGE ON SEQUENCES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "core" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "moderation" GRANT SELECT,USAGE ON SEQUENCES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "moderation" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "notifications" GRANT SELECT,USAGE ON SEQUENCES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "notifications" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "service_role";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "app_backend";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "app_backend";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "site" GRANT SELECT,USAGE ON SEQUENCES TO "app_backend";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "site" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "app_backend";




