# Deletion-path fix: append-only conflict + purge() ordering

**Date:** 2026-07-23
**Status:** Design approved, pre-implementation
**Area:** `App\Services\User\AccountDeletionService::purge()`, `supabase/migrations/`, append-only audit tables
**Related memory:** `project_deletion_appendonly_setnull_conflict`

## Summary

Account hard-deletion (`purge()`, run by `PurgeSoftDeleted` after the 30-day grace
period, and re-usable for immediate test-account teardown) is broken in two ways
against the real Postgres schema. Both are hidden by the test suite because it runs
on SQLite with a faked Supabase HTTP call, so no `auth.users` cascade and no audit
triggers ever fire.

This was discovered on 2026-07-22 while deleting a test account ("ST. ALi Coffee",
handle `stali`) — `purge()` returned `FAILED` at its first fail-safe gate.

## Root cause

`purge()` relies on the `auth.users` delete cascade to remove `core.users`:

```
core.users.auth_user_id → auth.users(id)  ON DELETE CASCADE
```

Step 1 of `purge()` deletes the Supabase auth user; the author's intent was that
the `core.users` row survives until Step 4 (`forceDelete`). But the FK is `CASCADE`
(confirmed in the migration source, not drift — `20260526000000_baseline_standalone_user.sql:353`),
so deleting the auth user removes `core.users` **immediately**. That single design
mismatch produces both bugs.

### Bug 1 — append-only block (the visible 500)

Deleting `core.users` fires `ON DELETE SET NULL` on audit tables that reference it.
Two of them are append-only, guarded by an unconditional reject-mutation trigger:

| Table | FK column → core.users | On delete | Reject trigger |
|-------|------------------------|-----------|----------------|
| `audit.staff_audit_log`  | `user_id` | SET NULL | `staff_audit_log_reject_mutation` |
| `audit.handle_change_log` | `user_id` | SET NULL | `handle_change_log_no_update` |

The `SET NULL` needs an **UPDATE** on those rows; the trigger raises
`P0001: audit.staff_audit_log is append-only (OPS-2)...` on any UPDATE/DELETE with
no role exemption, so the whole delete transaction aborts. When the delete is driven
by GoTrue (Supabase auth admin API), GoTrue surfaces this as **HTTP 500**, which
`deleteSupabaseAuthUser()` logs as a bare status with the body stripped for privacy.

Any account that is the *subject* of a `staff_audit_log` / `handle_change_log` row —
i.e. every staff- or ManyChat-created account, and any account a staff member has
acted on — currently cannot be deleted. `purge()`'s own `forceDelete()` (Step 4)
would hit the identical wall if it ever reached it.

### Bug 2 — R2/PII ordering (silent GDPR gap)

Because Step 1's cascade removes `core.users` (and its `site.sites`, `site.site_media`,
etc.) *before* Steps 2–3 run, those steps operate on already-deleted rows and silently
no-op:

- **R2 media** — `purgeMediaArtifacts()` finds no site → uploaded objects orphaned.
- **Export ZIPs** — `data_export_audit.user_id` already `SET NULL` → 0 paths found.
- **Feedback rows** — deleted by `user_id`, already `SET NULL` → PII (`message`,
  `reply_email`) survives.
- **Case-signal reporter PII** — `reporter_user_id` already `SET NULL` → missed.

Email-keyed erasure (`early_access_signups`, global subscriptions) still works because
it keys on the audit-snapshot email, not `user_id`. `item_views` still works
(no FK, keyed on `user_id`). Everything keyed on `user_id` with a `SET NULL` FK is
missed.

## Chosen design

**Auth-first + FK → `SET NULL`.** Relaxing the FK so the auth-delete no longer
destroys `core.users` fixes Bug 2 with no reordering (the existing Steps 2–5 then run
against live rows as the author intended), and a `SECURITY DEFINER` helper fixes Bug 1.

### 1. Migration — `supabase/migrations/<ts>_deletion_path_appendonly_fix.sql`

**(a) Relax the FK:**

```sql
ALTER TABLE core.users DROP CONSTRAINT users_auth_user_id_fkey;
ALTER TABLE core.users ADD CONSTRAINT users_auth_user_id_fkey
    FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE SET NULL;
```

Safe: `auth_user_id` is nullable live (unclaimed/pre-account users already carry NULL);
`users_auth_user_id_unique` is a partial index `WHERE deleted_at IS NULL` and btree
treats NULLs as distinct, so nulling during purge cannot collide.

*Bonus:* removes a footgun — today, deleting an auth user in the Supabase dashboard
silently destroys the whole site + all data with no soft-delete / KV / R2 cleanup.
After the change it merely orphans the link (recoverable).

**(b) `SECURITY DEFINER` null-link helper** — modeled exactly on
`audit.prune_handle_change_log` (`20260718010000_handle_change_log_retention_prune.sql`),
which already establishes the sanctioned pattern (owner-privileged function that
disables the reject trigger only for its own atomic mutation, all inside the implicit
function transaction so any failure rolls back and never leaves a guard off):

```sql
CREATE OR REPLACE FUNCTION audit.null_user_audit_links(p_user_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
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

REVOKE ALL ON FUNCTION audit.null_user_audit_links(uuid) FROM PUBLIC;
-- GRANT EXECUTE TO app_backend, guarded by pg_roles existence check (see precedent).
```

Only these two tables carry a reject trigger; every other `SET NULL` FK to
`core.users` is unguarded and already succeeds. The `SET NULL` semantics (keep the
audit event, sever the user link) are the schema's own intent and GDPR-appropriate.

### 2. App change — `AccountDeletionService::purge()`

Single insertion immediately before `$professional->forceDelete()`, Postgres-guarded
so SQLite tests skip it (the function/tables/triggers don't exist there):

```php
if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
    DB::connection('pgsql')->select('SELECT audit.null_user_audit_links(?)', [$professional->id]);
}
$professional->forceDelete();
```

No reordering. With the FK now `SET NULL`, Step 1's auth-delete leaves `core.users`
intact, so Steps 2–5 (R2, cache, email-keyed PII) run against live rows. The helper
pre-nulls the two append-only links so the `forceDelete` `SET NULL` cascade matches 0
rows and never fires the reject trigger.

Failure semantics unchanged and idempotent: auth-delete fails → `EVENT_PURGE_FAILED` +
return false → retried next run; a retry after a partial success finds the auth user
already gone (404 = success) and `auth_user_id` already NULL (guard skips re-delete).

## Testing

- **SQLite feature tests** (`tests/Feature/Account/AccountDeletionPurge*Test`): remain
  green; add an assertion that the pgsql-guard holds (helper not invoked under SQLite).
  These tests give false confidence on the cascade — note that in the plan.
- **Real proof is a Postgres rehearsal** (this is exactly what SQLite hid). After
  applying the migration to dev: seed a user with `staff_audit_log` rows, run `purge()`
  via `cloud tinker development`, assert it returns `true`, the audit rows survive with
  `user_id IS NULL`, and the site/media/R2 are gone.
- Confirm no existing test pins `users_auth_user_id_fkey = CASCADE`.
- `tests/Schema/FunctionSearchPathTest.php` covers the new function's pinned
  `search_path = ''`.

## Migration application (dev)

Per `reference_supabase_migration_drift` / `project_b8_audit_pii_prune_authored`: the
dev DB has drift, so **do not `db push`**. Apply this single migration surgically
against the dev ref and record it in `supabase_migrations.schema_migrations`. Rehearse
a from-zero apply with `scripts/db/fresh-reset.sh` locally first (no `CONCURRENTLY`
here, so no pipeline issue).

## Out of scope (noted, not fixed here)

- **`core.partna_staff.auth_user_id` is also `CASCADE`** → deleting a *staff* auth
  identity hits the same append-only block (via `staff_audit_log.staff_id` /
  `impersonator_staff_id` `SET NULL`). No staff-deletion flow runs through `purge()`,
  so it's left as a follow-up. If a staff-deletion path is ever added, extend the
  helper to null those columns and apply the same FK treatment.

## Appendix — manual workaround used 2026-07-22

To delete the `stali` test account before this fix existed, an atomic transaction
disabled the two reject triggers, deleted `core.users` (cascade + intended `SET NULL`)
and `auth.users`, then re-enabled the triggers; R2 was cleaned via `purgeMediaArtifacts`
beforehand and the KV route retired via `SyncSubdomainToKvJob(id, handle)` afterward.
This fix makes that one-off dance unnecessary.
