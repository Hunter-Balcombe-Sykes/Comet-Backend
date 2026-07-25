-- B21 (audit foundation-audit-v1, items #P2-36 + #P3-18):
-- Tighten public RLS INSERT policies on waitlist_signups and email_subscriptions
-- (currently WITH CHECK (true), accepting any payload from anon clients) and
-- document the intentional staff-only site_media DELETE asymmetry.
--
-- Email regex ^[^@\s]+@[^@\s]+\.[^@\s]+$ is intentionally pragmatic: stricter
-- than nothing, looser than RFC 5321. Laravel's email:rfc rule on both Form
-- Requests is strictly looser, so legitimate inserts pass; the regex's job is
-- defence-in-depth against direct anon writes that bypass the application.
--
-- The audit's original suggestion also included length(name) > 0; we drop that
-- clause because (a) waitlist_signups.name was made NULLABLE in
-- 20260526010000_relax_waitlist_signups_constraints.sql to support email-only
-- landing-page signups, and (b) email_subscriptions has no `name` column
-- (its column is `full_name`). A NULL on either would make the WITH CHECK
-- expression NULL and silently block all legitimate signups.
--
-- ALTER POLICY is metadata-only and safe on a live table — no row scan, no
-- ACCESS EXCLUSIVE on data pages.

BEGIN;

ALTER POLICY waitlist_public_insert ON core.waitlist_signups
    WITH CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$');

ALTER POLICY email_subs_public_insert ON notifications.email_subscriptions
    WITH CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$');

COMMENT ON POLICY site_media_delete_staff ON site.site_media IS
    'Intentionally staff-only. The app soft-deletes via the Eloquent SoftDeletes '
    'trait (UPDATE deleted_at), then PurgeSoftDeleted hard-deletes after the '
    '30-day retention window. Hard DELETE is reserved for staff/admin cleanup; '
    'granting it to professionals would orphan R2 artifacts and bypass the '
    'retention guarantee.';

COMMIT;
