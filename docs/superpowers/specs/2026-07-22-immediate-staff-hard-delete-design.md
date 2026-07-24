# Immediate staff hard-delete (email-freeing purge)

**Date:** 2026-07-22
**Status:** Design approved — pending spec review
**Author:** Josh + Claude

## 1. Problem & context

Staff need an on-demand action that **fully erases a professional**, including the
Supabase Auth user, so the email address frees up for reuse — instead of waiting
out the 30-day self-service grace period.

Investigation turned up two things that reshape the work:

1. **The capability already exists but is gated.** `AccountDeletionService::purge()`
   already deletes the Supabase auth user (`DELETE /auth/v1/admin/users/{id}` with the
   service-role key), cleans R2 media, erases PII across satellite tables the FK cascade
   can't reach, `forceDelete()`s the row, retires KV pointers, and writes a `PURGED`
   audit entry. It only runs **after** the 30-day window, driven by the daily
   `partna:purge-soft-deletes` command.

2. **`purge()` is currently broken for the accounts staff would target.** The staff
   immediate hard-delete path (`StaffUserController::forceDestroy`) does a bare
   `forceDelete()` that never calls `purge()`, leaving the Supabase auth user orphaned
   (email permanently taken). And `purge()` itself **500s** for any staff- or
   ManyChat-created account because of a schema conflict (see §2).

So this is not a greenfield endpoint — it's: **fix `purge()`, then expose it to staff
as an immediate action.**

## 2. Root cause of the `purge()` failure (verified against migrations)

- `core.users.auth_user_id → auth.users` is **`ON DELETE CASCADE`**
  (`20260526000000_baseline_standalone_user.sql:353`). Deleting the Supabase auth user
  cascade-deletes the `core.users` row inside that same DB transaction.
- That cascade then fires **`ON DELETE SET NULL`** on the two append-only audit tables
  that reference `core.users`:
  - `audit.staff_audit_log.user_id` (FK `staff_audit_log_professional_fk`, baseline:622)
  - `audit.handle_change_log.user_id` (FK `handle_change_log_professional_id_fkey`, baseline:675)
- `SET NULL` requires an **UPDATE** on those rows — but each table has an unconditional
  `BEFORE UPDATE OR DELETE` reject-mutation trigger that `RAISE`s on any mutation:
  - `staff_audit_log_reject_mutation` → `core.reject_staff_audit_log_mutation()`
  - `handle_change_log_no_update` → `core.trg_handle_change_log_append_only()`
- The whole transaction aborts with Postgres `P0001`, surfaced as an HTTP **500** from
  the GoTrue admin delete. **Any staff/ManyChat-created account has these audit rows, so
  none of them are currently deletable.**

**Scope is bounded:** these are the *only two* reject-mutation triggers in the schema.
Every other append-only table (`user_deletion_audit`, `supabase_email_events`,
`email_suppressions`, …) enforces via GRANT revocation only — and FK cascade actions run
in the constraint owner's context, which bypasses column grants — so those `SET NULL`s
already succeed. `data_export_audit` was explicitly granted UPDATE (`20260624000000`).
**Fixing two trigger functions makes `purge()` work for every account.**

**Hidden from tests:** the suite runs on SQLite, which has no `auth.users`, no cascade,
and no triggers. The fix therefore cannot be proven by the suite alone (see §7).

**Secondary ordering bug in `purge()`:** because the auth-delete cascades `core.users`
away first, the later R2/PII cleanup steps query already-deleted rows and silently no-op
→ orphaned R2 media + un-erased email-keyed PII.

## 3. Goals / non-goals

**Goals**
- `purge()` succeeds for all account types (self-service, GDPR/admin, staff-created,
  ManyChat-created, provisional/unclaimed).
- A staff endpoint performs the full purge **immediately**, deleting the Supabase auth
  user so the email frees up.
- Append-only integrity is preserved for every mutation except the FK-mandated `SET NULL`.
- Retryability preserved: a Supabase-side failure must leave the account in a safe,
  retryable state, never a half-deleted one.

**Non-goals**
- No change to the self-service deletion UX or the 30-day grace period.
- No change to the soft-delete `destroy` path.
- Frontend work (the staff dashboard force-delete button) is **flagged, not built** here.

## 4. Design

> **§4.1–§4.2 superseded.** The `purge()`/schema fix was subsequently redesigned in its
> own authoritative doc — **`docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md`**.
> That approach (relax the FK to `SET NULL` + a `SECURITY DEFINER` null-link helper) is the
> one carried into the implementation plan; it is strictly better than the trigger-narrowing
> sketched below (no reorder needed, and it removes the dashboard-delete footgun). The
> summaries below are kept for context but **defer to the 2026-07-23 spec**.

### 4.1 Schema migration (per 2026-07-23 spec)

One migration `supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql`:
- **(a)** Relax `core.users.auth_user_id → auth.users` from `ON DELETE CASCADE` to
  `ON DELETE SET NULL`, so deleting the Supabase auth user no longer destroys `core.users`
  mid-purge (the cleanup steps then run against live rows) and the Supabase-dashboard
  delete footgun is removed. Safe: `auth_user_id` is nullable and its unique index is
  partial (`WHERE deleted_at IS NULL`, NULLs distinct).
- **(b)** `SECURITY DEFINER` helper `audit.null_user_audit_links(uuid)` — modeled on the
  existing `audit.prune_handle_change_log` precedent — that disables the two reject
  triggers only for its own atomic `UPDATE ... SET user_id = NULL`, so the append-only
  guard is never left off. `EXECUTE` granted to `app_backend`; `SET search_path = ''`.

### 4.2 `purge()` change (per 2026-07-23 spec)

**No reordering.** With the FK now `SET NULL`, the existing Step 1 auth-delete leaves
`core.users` intact, so Steps 2–5 (R2, cache, email-keyed PII) already run against live
rows. A single Postgres-guarded call is inserted immediately before `$professional->forceDelete()`:

```php
if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
    DB::connection('pgsql')->select('SELECT audit.null_user_audit_links(?)', [$professional->id]);
}
$professional->forceDelete();
```

The helper pre-nulls the two append-only links so `forceDelete()`'s `SET NULL` cascade
matches 0 rows there and never trips the reject trigger. `forceDelete()` remains the single
row-removal + observer trigger (handle-KV retire). Failure semantics unchanged: an
auth-delete failure returns false and is retried; a retry after partial success finds the
auth user already gone (404 = success) and `auth_user_id` already NULL (guard skips
re-delete).

### 4.3 Immediate-purge service method

Add `AccountDeletionService::adminPurgeNow(User $professional, string $staffActorId,
string $staffActorHandle, string $reason, bool $overrideObligations, Request $request): array`.

- If the account is **not** already `pending_deletion`: run the confirmation writes
  (status → `pending_deletion`, pseudonymise PII, `ADMIN_INITIATED` audit snapshot,
  unpublish site) via `executeConfirmation()` with a **new `$suppressMail` flag**
  (default `false`, so every existing caller is byte-for-byte unchanged). The flag skips
  the `AccountDeletionScheduledMail` queue (a "deleted in 30 days" email is nonsensical
  for an instant purge). Respect `checkObligations()` unless `overrideObligations`,
  returning `{success:false, code:422, reasons:[...]}` — mirrors `adminInitiate()`.
- If the account **is** already `pending_deletion` (grace period running): skip the
  confirmation writes (snapshot already exists) and go straight to `purge()` — this
  doubles as a "finish the deletion now" action.
- Call `purge()`. Map the result:
  - `true` → `{success:true, code:200}` → `{ permanently_deleted:true, email_freed:true }`.
  - `false` → the account remains safely in `pending_deletion` + pseudonymised (the exact
    state the daily command retries) → `{success:false, code:502}` "auth deletion failed;
    account marked for deletion and will retry automatically."

### 4.4 Endpoint upgrade

Upgrade `StaffUserController::forceDestroy` (`DELETE /professionals/{professional}/force`):

- Keep the fresh-AAL2 gate and the `staffForceDelete` (admin-only) policy exactly as-is.
- Add a `StaffForceDestroyRequest` FormRequest requiring `reason` (string, 10–500 chars,
  for the GDPR audit snapshot) and optional `override_obligations` (bool, default false).
- Resolve the staff actor (`$request->attributes->get('partna_staff')`) for
  `staffActorId`/`staffActorHandle`, delegate to `adminPurgeNow()`, and translate the
  returned array to the JSON response. Preserve the existing 409 related-data path.

### 4.5 Contract / frontend / docs impact (flagged, not built here)

- `DELETE /professionals/{professional}/force` now takes a JSON body
  `{ reason, override_obligations? }` and now **also deletes the Supabase auth user**
  (email freed). The staff dashboard force-delete button must collect a reason.
- Update `docs/api.md` (staff professionals §).
- No `AccountCapabilities` gate — destructive admin action, not a user-facing feature
  (consistent with the current `forceDestroy`).

## 5. Edge cases & error handling

| Case | Behaviour |
|------|-----------|
| Provisional/unclaimed user (no `auth_user_id`) | Auth-delete skipped; `forceDelete()` removes the row; observer fires normally. |
| Already `pending_deletion` | Skip confirmation writes, purge immediately ("finish now"). |
| Outstanding obligations, no override | 422 with `reasons` (reuse `checkObligations()`). |
| Supabase auth-delete fails | Row stays in `pending_deletion` + pseudonymised; 502; daily command retries. |
| Stale / missing AAL2 | 401 `mfa_fresh_required` (existing gate). |
| Non-admin staff | 403 (existing `staffForceDelete` policy). |
| Concurrent staff purge + daily command on same row | `executeConfirmation()`'s `lockForUpdate` + `status='pending_deletion'` idempotency guard already serialises; `purge()` on an already-gone row returns false safely. |

## 6. Security considerations

- Append-only integrity is preserved for **every** mutation except the exact FK-mandated
  `SET NULL` — enforced by the `to_jsonb(NEW) - 'user_id' = to_jsonb(OLD) - 'user_id'`
  equality check. No DELETE is ever permitted.
- The `SET NULL` de-links but **keeps** the audit event — the schema's declared intent.
- No new privilege escalation, no `SECURITY DEFINER`, no `DISABLE TRIGGER`.
- Destructive action stays behind fresh-AAL2 + admin-only policy; `reason` is captured in
  the immutable deletion audit trail.

## 7. Testing strategy

**SQLite (Pest feature/unit) — everything the test DB can express:**
- Gating: non-admin → 403; stale AAL2 → 401; missing/short `reason` → 422;
  obligations-without-override → 422.
- `Mail::fake()` asserts `AccountDeletionScheduledMail` is **not** queued on the immediate
  path, **is** queued on the existing grace-period path (regression guard for the flag).
- `Http::fake()` asserts the `DELETE /auth/v1/admin/users/{id}` call fired; response maps
  200 on success and 502 on a faked non-2xx.
- `purge()` reorder: assert media/PII purge helpers run before the row is deleted (spy /
  ordering assertions), and that the handle-KV `SyncSubdomainToKvJob` is dispatched.
- ⚠ Per `project_gdpr_export_broken_prod`: assert on **data**, not "the query ran" —
  SQLite treats unknown quoted identifiers as string literals.

**Postgres rehearsal on dev (mandatory — the suite cannot prove the fix):**
- Apply the §4.1 migration to the dev Supabase ref (surgically, not `db push` — dev has
  drift).
- Create a throwaway **staff-created** account (has `staff_audit_log` + `handle_change_log`
  rows), then force-delete it via the endpoint.
- Confirm: `core.users` row gone, `auth.users` row gone, email reusable, the audit rows'
  `user_id` is now `NULL` (event preserved), KV route retired, `PURGED` audit written.
- This is the class of bug (`view-drop`, cascade behaviour) that only a Postgres rehearsal
  catches — SQLite is blind to it.

## 8. Migration application & rollout

- Author the migration under `supabase/migrations/`, apply **surgically** to the dev ref
  (single-migration apply; dev DB has known drift so `db push` is unsafe), and record it
  in `supabase_migrations.schema_migrations`.
- Dev serves both domains, so this ships to both APIs on the next `development` deploy.
- Order of operations: migration to dev → Postgres rehearsal → merge code → deploy.
- Prod cutover inherits the fixed trigger definitions via the normal migration replay.

## 9. Decisions locked

- Expose via the **existing `/force` endpoint** (not a new route).
- Fix the `purge()` conflict per the **2026-07-23 deletion-path spec**: relax the
  `auth_user_id` FK to `SET NULL` + a `SECURITY DEFINER` null-link helper (this
  supersedes the trigger-narrowing originally chosen here).
- Immediate purge = suppressed-mail `executeConfirmation()` → `purge()`, reusing all
  existing hardened machinery.
