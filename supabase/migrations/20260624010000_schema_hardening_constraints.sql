-- 20260624010000_schema_hardening_constraints.sql
--
-- Bundles 6 metadata-only / empty-table schema-hardening fixes from the
-- triaged-survivors audit (DINT-2, DINT-3, DINT-10, DINT-11, SCHEMA-2,
-- SCHEMA-7). Each fix is surgical: only constraint DDL, no data changes,
-- no app-logic impact.
--
-- CONCURRENTLY indexes are split into separate files per CONVENTIONS.md §1
-- (DINT-7 → 20260624010100, DINT-8 → 20260624010200).

BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

-- DINT-2: notifications.broadcast_email_receipts.subscription_id has no FK to
-- notifications.email_subscriptions(id). The column is part of the composite PK
-- so it can never be NULL, but there's nothing preventing a dangling UUID.
-- NOT VALID + VALIDATE separates the catalog write (fast, lock-light) from the
-- full scan so the index / RLS policies on the parent table are not disturbed.
ALTER TABLE notifications.broadcast_email_receipts
    ADD CONSTRAINT broadcast_email_receipts_subscription_id_fkey
    FOREIGN KEY (subscription_id) REFERENCES notifications.email_subscriptions(id)
    ON DELETE CASCADE NOT VALID;
ALTER TABLE notifications.broadcast_email_receipts
    VALIDATE CONSTRAINT broadcast_email_receipts_subscription_id_fkey;

-- DINT-3: audit.auth_factor_events.user_id has no FK to core.users(id), and the
-- column is NOT NULL — meaning a deleted user can leave orphaned audit rows that
-- can't be joined. Matching the sibling audit tables' pattern: drop NOT NULL so a
-- future user deletion can SET NULL rather than blocking the delete, then add the
-- FK. Audit rows themselves are kept (append-only table; the user_id going null is
-- the intended sentinel for "deleted user").
ALTER TABLE audit.auth_factor_events ALTER COLUMN user_id DROP NOT NULL;
ALTER TABLE audit.auth_factor_events
    ADD CONSTRAINT auth_factor_events_user_fk
    FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE SET NULL NOT VALID;
ALTER TABLE audit.auth_factor_events
    VALIDATE CONSTRAINT auth_factor_events_user_fk;

-- DINT-10: site.platform_connections has no BEFORE UPDATE updated_at trigger.
-- Eloquent sets it in app code, but raw updates (sync jobs, admin SQL) leave the
-- column stale. Reuses the shared public.set_updated_at() trigger function
-- (defined in baseline, pinned in 20260606040000) — same pattern as
-- set_timestamp_smart_links in 20260615000000.
CREATE OR REPLACE TRIGGER set_timestamp_platform_connections
    BEFORE UPDATE ON site.platform_connections
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- DINT-11: site.site_media.pool allows 'brand_gallery' in the CHECK constraint
-- but that pool value was removed in the standalone strip-down and no rows use it.
-- Drop and re-add the constraint without it. NOT VALID + VALIDATE for the same
-- lock-minimisation reason as DINT-2; existing rows are all in the remaining pools.
ALTER TABLE site.site_media DROP CONSTRAINT site_media_pool_check;
ALTER TABLE site.site_media
    ADD CONSTRAINT site_media_pool_check
    CHECK (pool IN ('gallery', 'content', 'design', 'product', 'documents')) NOT VALID;
ALTER TABLE site.site_media VALIDATE CONSTRAINT site_media_pool_check;

-- SCHEMA-2: site.site_subdomain_aliases.id is a uuid PK but has no DEFAULT,
-- meaning a raw-SQL / admin INSERT that omits the id column will error rather
-- than auto-generating a UUID. SET DEFAULT is catalog-only; no row data is
-- touched.
ALTER TABLE site.site_subdomain_aliases ALTER COLUMN id SET DEFAULT gen_random_uuid();

-- SCHEMA-7: pin the search_path on two security-critical trigger functions that
-- were not included in the 20260606040000 sweep. Both are no-arg trigger
-- functions whose bodies already use only fully-qualified names (core.partna_staff,
-- site.site_media) and pg_catalog builtins, so the empty path is safe and fully
-- behaviour-preserving.
ALTER FUNCTION core.prevent_staff_escalation() SET search_path = '';
ALTER FUNCTION core.enforce_site_gallery_max6() SET search_path = '';

COMMIT;
