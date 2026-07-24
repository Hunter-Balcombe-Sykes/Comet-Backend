# Immediate Staff Hard-Delete Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give staff an on-demand `/force` action that fully erases a professional — including the Supabase Auth user, so the email frees up — by first fixing the two latent bugs in `AccountDeletionService::purge()` and then invoking it immediately.

**Architecture:** A migration (per `docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md`) relaxes `core.users.auth_user_id → auth.users` from `ON DELETE CASCADE` to `ON DELETE SET NULL`, so the auth-delete no longer destroys `core.users` mid-purge (fixing the R2/PII ordering bug with **no reordering**), and adds a `SECURITY DEFINER` helper `audit.null_user_audit_links()` that pre-nulls the two append-only audit links so `forceDelete()`'s `SET NULL` cascade matches 0 rows there and never trips their reject triggers. `purge()` gains a single guarded call before `forceDelete()`. On top of the now-correct `purge()`, a new `adminPurgeNow()` service method + a `reason` FormRequest upgrade the existing `DELETE /professionals/{id}/force` endpoint to run the full immediate purge behind the existing fresh-AAL2 + admin gates.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, PostgreSQL (Supabase) via raw SQL migrations, SQLite in-memory for tests, Laravel Horizon jobs.

**Specs implemented:** `2026-07-23-deletion-path-appendonly-fix-design.md` (Tasks 1–2, the purge/schema fix) + `2026-07-22-immediate-staff-hard-delete-design.md` (Tasks 3–5, the staff endpoint).

## Global Constraints

- **No Laravel migration files.** Schema changes are raw SQL under `supabase/migrations/` only (a Composer guard rejects Laravel migrations).
- **Tests run SQLite; prod is Postgres.** SQLite has no `auth.users`, no FK cascade, no triggers, and the `pgsql` test connection is aliased to SQLite — so the schema fix is **not** provable by the suite. Assert on **data**, not "the query ran" (SQLite treats unknown quoted identifiers as string literals).
- **Never return raw Eloquent from endpoints** — use the controller `success()`/`error()` helpers.
- **Authorization via policies**, never inline 403. Use `authorizeForUser($staff, ...)` (Supabase JWT → `Auth::user()` is null).
- **Staff FormRequests must NOT define `authorize()`** — `BaseFormRequest::authorize()` is `final` and returns `true`.
- **Apply migrations to dev surgically** (single-migration apply), NOT `db push` — the dev DB has known drift. Rehearse a from-zero apply with `scripts/db/fresh-reset.sh` locally first (no `CONCURRENTLY` here → no pipeline issue).
- **`SECURITY DEFINER` functions must pin `SET search_path = ''`** and be registered in `tests/Feature/Security/FunctionSearchPathTest.php`'s allow-list.
- **Do not `git commit`/`push` unless the user asks.** Josh handles commits; the commit steps below stage the work — run them only on Josh's go-ahead.

---

### Task 1: Migration — relax auth FK to SET NULL + add null-link helper

**Files:**
- Create: `supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql`
- Modify: `tests/Feature/Security/FunctionSearchPathTest.php` (add the new function to the allow-list)

**Interfaces:**
- Produces: `core.users.auth_user_id` FK re-defined as `ON DELETE SET NULL`; function `audit.null_user_audit_links(p_user_id uuid) RETURNS void` (`SECURITY DEFINER`, `EXECUTE` granted to `app_backend`), consumed by Task 2.

**Context:** `core.users.auth_user_id → auth.users` is `ON DELETE CASCADE` (baseline:353), so deleting the Supabase auth user removes `core.users` immediately — which (Bug 1) fires `ON DELETE SET NULL` on `audit.staff_audit_log`/`audit.handle_change_log`, blocked by their unconditional reject triggers (`P0001`, surfaced as HTTP 500), and (Bug 2) runs the R2/PII cleanup steps against already-deleted rows. Relaxing the FK to `SET NULL` fixes Bug 2 (rows survive the auth-delete); the helper fixes Bug 1 (pre-null the two guarded links so the cascade touches 0 rows there). Only these two tables carry a reject trigger; every other `SET NULL` FK to `core.users` is unguarded and already succeeds.

> **Why no Pest step here:** the SQLite suite has none of `auth.users`, the cascade, the triggers, or `pg_proc` — this migration's correctness is proven in **Task 6** (Postgres rehearsal on dev). The `FunctionSearchPathTest` addition (Step 2) also only runs against a real `pgsql` connection (it `markTestSkipped`s on SQLite).

- [ ] **Step 1: Write the migration file**

Create `supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql`:

```sql
-- Fix the two account hard-delete bugs — see
-- docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md.
--
-- (a) Relax core.users.auth_user_id -> auth.users from ON DELETE CASCADE to SET NULL:
--     deleting the Supabase auth user no longer destroys core.users mid-purge (so the
--     R2/PII cleanup steps run against live rows), and it removes a footgun where deleting
--     an auth user in the Supabase dashboard silently nukes the whole site + all data.
-- (b) SECURITY DEFINER helper that nulls the two append-only audit links for a user about
--     to be hard-deleted, so forceDelete's ON DELETE SET NULL cascade matches 0 rows on
--     those tables and never trips their reject-mutation triggers. Modeled exactly on
--     audit.prune_handle_change_log (20260718010000): disable + update + enable run in the
--     one implicit function transaction, so any failure rolls back and never leaves a
--     guard trigger off. SET NULL keeps the audit event, severs the user link — the
--     schema's own declared intent and GDPR-appropriate.

BEGIN;
SET LOCAL lock_timeout = '2s';

-- (a) auth_user_id FK: CASCADE -> SET NULL. Safe: auth_user_id is nullable (unclaimed /
-- pre-account users already carry NULL) and users_auth_user_id_unique is a partial index
-- (WHERE deleted_at IS NULL) with btree treating NULLs as distinct, so nulling during
-- purge cannot collide. Existing rows already satisfy the FK, so ADD validates cleanly.
ALTER TABLE core.users DROP CONSTRAINT users_auth_user_id_fkey;
ALTER TABLE core.users ADD CONSTRAINT users_auth_user_id_fkey
    FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE SET NULL;

-- (b) Null the append-only audit links for the user being hard-deleted.
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

-- Least privilege: no PUBLIC; only app_backend (the app's connection role) may call it.
-- Guarded so this is a no-op where the role doesn't exist yet (fresh local stack /
-- connected as postgres).
REVOKE ALL ON FUNCTION audit.null_user_audit_links(uuid) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT EXECUTE ON FUNCTION audit.null_user_audit_links(uuid) TO app_backend';
    END IF;
END $$;

COMMIT;
```

- [ ] **Step 2: Register the new function in the search-path guard**

In `tests/Feature/Security/FunctionSearchPathTest.php`, add to the `$searchPathFunctions` array (after the `['audit', 'prune_data_export_audit']` entry, ~line 66):

```php
    ['audit', 'null_user_audit_links'],
```

- [ ] **Step 3: Verify it is the latest migration and parses**

Run: `ls supabase/migrations/ | tail -5`
Expected: `20260723010000_deletion_path_appendonly_fix.sql` sorts last. If an equal/greater timestamp exists, bump the prefix.

Run: `grep -c "auth.null_user_audit_links\|users_auth_user_id_fkey" supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql`
Expected: `≥ 3` (one FK drop, one FK add, and the function references).

- [ ] **Step 4: Confirm the SQLite suite is unaffected**

Run: `php artisan test tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php tests/Feature/Security/FunctionSearchPathTest.php`
Expected: PASS — `PurgePendingDeletionTest` unchanged; `FunctionSearchPathTest` skips on SQLite (its cases `markTestSkipped`).

- [ ] **Step 5: Stage (commit only on Josh's go-ahead)**

```bash
git add supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql tests/Feature/Security/FunctionSearchPathTest.php
git commit -m "fix(deletion): relax auth_user_id FK to SET NULL + add audit.null_user_audit_links helper"
```

---

### Task 2: Insert the null-link helper call into `purge()` (no reordering)

**Files:**
- Modify: `app/Services/User/AccountDeletionService.php` (method `purge()`, insert immediately before the `$professional->forceDelete()` block, ~line 692)
- Test: `tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php` (extend)

**Interfaces:**
- Consumes: `audit.null_user_audit_links(uuid)` (Task 1).
- Produces: `purge(User $professional): bool` — same signature; one guarded statement added. **No reordering** — with the FK now `SET NULL`, the existing Step 1 auth-delete leaves `core.users` intact, so Steps 2–5 already run against live rows.

**Context:** The helper pre-nulls the two append-only audit links so `forceDelete()`'s `SET NULL` cascade matches 0 rows there. It is Postgres-guarded via the driver name — under the test suite the `pgsql` connection is aliased to SQLite, so `getDriverName()` returns `'sqlite'` and the call is skipped (the function/triggers don't exist there).

- [ ] **Step 1: Write the tests**

Add to `tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php`:

```php
it('purges to completion on the SQLite test driver without invoking the pgsql-only helper', function () {
    // Proves the driver guard holds: if purge() called audit.null_user_audit_links()
    // unconditionally, SQLite would throw "no such function" here.
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    expect((new AccountDeletionService)->purge($pro))->toBeTrue();
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();
});

it('does not hard-delete the row when the Supabase auth-delete fails (retryable)', function () {
    $pro = seedPurgeableUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 500)]);

    expect((new AccountDeletionService)->purge($pro))->toBeFalse();
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run them (the first passes pre-change; keep as the guard)**

Run: `php artisan test tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php`
Expected: both PASS against the current code (no helper call yet). They are the regression guard that the Step-3 insertion must not break under SQLite.

- [ ] **Step 3: Insert the guarded helper call**

In `app/Services/User/AccountDeletionService.php`, immediately before the `// Step 4: hard-delete professional row.` comment / the `try { $professional->forceDelete(); }` block (~line 690), insert:

```php
        // Pre-null the two append-only audit links (staff_audit_log, handle_change_log) so
        // forceDelete's ON DELETE SET NULL cascade matches 0 rows there and never trips
        // their reject-mutation triggers. Postgres-only: the helper/triggers don't exist
        // under the SQLite test driver (the 'pgsql' connection is aliased to SQLite in
        // tests, so getDriverName() returns 'sqlite' and this is skipped).
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            DB::connection('pgsql')->select('SELECT audit.null_user_audit_links(?)', [$professional->id]);
        }
```

`DB` is already imported in this file (used elsewhere). Leave the existing auth-delete (Step 1) and `forceDelete()` (Step 4) exactly where they are.

- [ ] **Step 4: Run the purge suite to confirm no regression**

Run: `php artisan test tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php`
Expected: PASS (both cases). On SQLite the insertion is a no-op; its real effect is proven in Task 6.

- [ ] **Step 5: Stage (commit only on Josh's go-ahead)**

```bash
git add app/Services/User/AccountDeletionService.php tests/Feature/User/AccountDeletion/PurgePendingDeletionTest.php
git commit -m "fix(deletion): pre-null append-only audit links before forceDelete in purge()"
```

---

### Task 3: `executeConfirmation` mail-suppression flag + `adminPurgeNow()`

**Files:**
- Modify: `app/Services/User/AccountDeletionService.php` (method `executeConfirmation()` at 231; add public `adminPurgeNow()` near `adminInitiate()` at 437)
- Test: `tests/Feature/User/AccountDeletion/AdminPurgeNowTest.php` (create)

**Interfaces:**
- Consumes: `purge(User): bool` (Task 2); `executeConfirmation(...)`; `checkObligations(User): array` (1041, currently returns `[]`); `UserDeletionAuditEntry::EVENT_ADMIN_INITIATED`, `::ACTOR_TYPE_STAFF_ADMIN`.
- Produces: `adminPurgeNow(User $professional, string $staffActorId, string $staffActorHandle, string $reason, bool $overrideObligations, Request $request): array` returning `{success: bool, code: int, error?: string, reasons?: array}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/User/AccountDeletion/AdminPurgeNowTest.php`:

```php
<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\AccountDeletionScheduledMail;

// Boots the config (supabase.url = test.supabase.co, service key) + all schema
// the purge touches. Mirrors PurgePendingDeletionTest.
require_once __DIR__.'/AccountDeletionTestCase.php';

beforeEach(function () {
    AccountDeletionTestCase::boot();
    Mail::fake();
});

function seedAdminPurgeUser(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro User',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

function makeStaffRequest(): Request
{
    $request = Request::create('/', 'DELETE');
    $request->attributes->set('supabase_uid', (string) Str::uuid());

    return $request;
}

it('purges immediately and does NOT queue the grace-period email', function () {
    $pro = seedAdminPurgeUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Spam account — support ticket #999',
        overrideObligations: false,
        request: makeStaffRequest(),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['code'])->toBe(200);

    $exists = DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists();
    expect($exists)->toBeFalse();

    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});

it('returns 502 and leaves the row present when the auth-delete fails', function () {
    $pro = seedAdminPurgeUser();

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 500)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Spam account — support ticket #999',
        overrideObligations: false,
        request: makeStaffRequest(),
    );

    expect($result['success'])->toBeFalse()
        ->and($result['code'])->toBe(502);

    $exists = DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists();
    expect($exists)->toBeTrue();

    // Left in the same retryable state the daily command handles.
    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->value('status'))
        ->toBe('pending_deletion');
});

it('skips the confirmation writes for an account already in the grace period', function () {
    $pro = seedAdminPurgeUser(['status' => 'pending_deletion', 'deletion_confirmed_at' => now()]);

    Http::fake(['test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);

    $result = (new AccountDeletionService)->adminPurgeNow(
        professional: $pro,
        staffActorId: (string) Str::uuid(),
        staffActorHandle: 'Admin One',
        reason: 'Finishing an in-progress deletion now',
        overrideObligations: false,
        request: makeStaffRequest(),
    );

    expect($result['success'])->toBeTrue();
    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/User/AccountDeletion/AdminPurgeNowTest.php`
Expected: FAIL — `Call to undefined method App\Services\User\AccountDeletionService::adminPurgeNow()`.

- [ ] **Step 3: Add the `$suppressMail` param to `executeConfirmation()`**

In `executeConfirmation()` (signature at line 231), append a trailing parameter:

```php
        ?string $reason = null,
        bool $suppressMail = false,
    ): Carbon {
```

Then wrap the existing mail dispatch (currently lines ~332-347) so it is skipped when suppressed:

```php
        if (! $suppressMail) {
            try {
                Mail::to($realEmail)->queue(
                    new AccountDeletionScheduledMail(
                        displayName: (string) ($professional->display_name ?? 'there'),
                        deletesAt: $deletesAt->toDayDateTimeString(),
                        cancelUrl: $cancelUrl,
                    )
                );
            } catch (\Throwable $e) {
                Log::error('Account deletion scheduled mail dispatch failed', [
                    'user_id' => $professional->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
```

Every existing caller omits the new arg, so `false` preserves current behaviour.

- [ ] **Step 4: Add `adminPurgeNow()`**

Insert after `adminInitiate()` (ends ~line 480):

```php
    /**
     * Staff immediate hard-delete: run the confirmation writes (WITHOUT the
     * grace-period email) then purge() right away — deleting the Supabase auth
     * user so the email frees up. Skips the 30-day window; reuses the same
     * hardened machinery as the daily purge. Idempotent for an account already
     * in the grace period (skips the confirmation writes and purges).
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function adminPurgeNow(
        User $professional,
        string $staffActorId,
        string $staffActorHandle,
        string $reason,
        bool $overrideObligations,
        Request $request,
    ): array {
        $obligations = $this->checkObligations($professional);

        if (! empty($obligations) && ! $overrideObligations) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled or explicitly overridden.',
                'reasons' => $obligations,
            ];
        }

        // A live account needs the confirmation writes (pseudonymise PII + write the
        // ADMIN_INITIATED audit snapshot that purge() reads for email-keyed erasure).
        // An account already pending_deletion already has them — go straight to purge.
        if ($professional->status !== 'pending_deletion') {
            $metadata = ! empty($obligations) ? ['obligations_overridden' => $obligations] : [];

            $this->executeConfirmation(
                $professional,
                UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
                $request,
                $metadata,
                UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
                $staffActorId,
                $staffActorHandle,
                $reason,
                suppressMail: true,
            );
        }

        if (! $this->purge($professional)) {
            return [
                'success' => false,
                'code' => 502,
                'error' => 'Auth deletion failed; the account is marked for deletion and will be retried automatically.',
            ];
        }

        return ['success' => true, 'code' => 200];
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `php artisan test tests/Feature/User/AccountDeletion/AdminPurgeNowTest.php`
Expected: PASS (all 3).

- [ ] **Step 6: Confirm the grace-period email regression guard still holds**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php`
Expected: PASS — `adminInitiate` still queues `AccountDeletionScheduledMail` (proves the default `$suppressMail = false` path is untouched).

- [ ] **Step 7: Stage (commit only on Josh's go-ahead)**

```bash
git add app/Services/User/AccountDeletionService.php tests/Feature/User/AccountDeletion/AdminPurgeNowTest.php
git commit -m "feat(deletion): add adminPurgeNow() immediate-purge with suppressed grace-period mail"
```

---

### Task 4: `StaffForceDestroyRequest` FormRequest

**Files:**
- Create: `app/Http/Requests/Api/Staff/StaffForceDestroyRequest.php`
- Test: `tests/Feature/Staff/AccountDeletion/StaffForceDestroyRequestTest.php` (create)

**Interfaces:**
- Produces: `App\Http\Requests\Api\Staff\StaffForceDestroyRequest` with `rules()` → `reason` (required|string|min:10|max:500), `override_obligations` (nullable|boolean). No `authorize()` (base is `final`).

> Namespace is `App\Http\Requests\Api\Staff\` (mirrors `StaffInitiateDeletionRequest`), **not** `App\Http\Requests\Staff\`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Staff/AccountDeletion/StaffForceDestroyRequestTest.php`:

```php
<?php

use App\Http\Requests\Api\Staff\StaffForceDestroyRequest;
use Illuminate\Support\Facades\Validator;

function forceDestroyValidator(array $data)
{
    $request = new StaffForceDestroyRequest;

    return Validator::make($data, $request->rules(), $request->messages());
}

it('rejects a missing reason', function () {
    expect(forceDestroyValidator([])->fails())->toBeTrue();
});

it('rejects a reason shorter than 10 chars', function () {
    expect(forceDestroyValidator(['reason' => 'too short'])->fails())->toBeTrue();
});

it('rejects a reason longer than 500 chars', function () {
    expect(forceDestroyValidator(['reason' => str_repeat('a', 501)])->fails())->toBeTrue();
});

it('accepts a valid reason with optional override_obligations', function () {
    $v = forceDestroyValidator(['reason' => 'Spam account — ticket #123', 'override_obligations' => true]);
    expect($v->fails())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/StaffForceDestroyRequestTest.php`
Expected: FAIL — class `StaffForceDestroyRequest` not found.

- [ ] **Step 3: Create the FormRequest**

Create `app/Http/Requests/Api/Staff/StaffForceDestroyRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff;

use App\Http\Requests\BaseFormRequest;

class StaffForceDestroyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'override_obligations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'Reason must be at least 10 characters — record the support ticket reference and the justification.',
            'reason.max' => 'Reason must be 500 characters or fewer.',
        ];
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/StaffForceDestroyRequestTest.php`
Expected: PASS (all 4).

- [ ] **Step 5: Stage (commit only on Josh's go-ahead)**

```bash
git add app/Http/Requests/Api/Staff/StaffForceDestroyRequest.php tests/Feature/Staff/AccountDeletion/StaffForceDestroyRequestTest.php
git commit -m "feat(staff): add StaffForceDestroyRequest (reason + override_obligations)"
```

---

### Task 5: Upgrade `forceDestroy` to the full immediate purge + fix existing tests + docs

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` (method `forceDestroy` at 322-373)
- Modify: `tests/Feature/Security/StaffUserControllerFreshAal2Test.php` (the `forceDestroy` describe block, ~196-236)
- Test: `tests/Feature/Staff/AccountDeletion/ImmediateForceDeleteTest.php` (create)
- Modify: `docs/api.md` (staff professionals section)

**Interfaces:**
- Consumes: `AccountDeletionService::adminPurgeNow(...)` (Task 3), `StaffForceDestroyRequest` (Task 4), `ApiController::success()/error()`.
- Produces: `DELETE /professionals/{professional}/force` accepting body `{ reason, override_obligations? }`, returning 200 `{ message, permanently_deleted: true, email_freed: true }` or an error (422 validation, 401 stale MFA, 403 non-admin, 502 auth-delete failure).

> **Behaviour change:** the old inline `forceDelete()` + EDGE-1 domain capture in the controller is removed — `purge()` (via `adminPurgeNow`) now owns the teardown, the Supabase auth-delete, and the custom-domain retire. The old 409 "related data" branch is superseded by `purge()`'s 502-on-failure mapping. Add `use` imports for `AccountDeletionService`, `StaffForceDestroyRequest`, and `PartnaStaff` if not already present.

- [ ] **Step 1: Update the existing fresh-AAL2 test to send a reason (it now precedes the gate)**

Because `StaffForceDestroyRequest` validation runs before the in-controller fresh-AAL2 gate, the existing no-body `deleteJson` calls would 422 before reaching the gate. In `tests/Feature/Security/StaffUserControllerFreshAal2Test.php`, update the three `forceDestroy` cases (~196-236) to pass a valid body and fake Supabase so the fresh case doesn't error:

```php
describe('forceDestroy — fresh-AAL2 gate', function () {
    beforeEach(function () {
        Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);
    });

    it('rejects with 401 + mfa_fresh_required when MFA is stale (3600s)', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, aal2ClaimsWithFreshTotp(3600))
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('rejects with 401 + mfa_fresh_required when there is no MFA amr entry', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        actingAsStaff($staff, ['aal' => 'aal2', 'amr' => []])
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123'])
            ->assertStatus(401)
            ->assertJsonFragment(['code' => 'mfa_fresh_required']);
    });

    it('allows the request (passes the gate) when MFA is fresh', function () {
        $staff = makeStaff();
        $pro = makeProfessional();

        $response = actingAsStaff($staff)
            ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Force delete — ticket #123']);

        expect($response->status())->not->toBe(401);
    });
});
```

Add `use Illuminate\Support\Facades\Http;` at the top of that file if absent.

- [ ] **Step 2: Run it to confirm it now fails against the OLD controller**

Run: `php artisan test tests/Feature/Security/StaffUserControllerFreshAal2Test.php --filter="forceDestroy"`
Expected: the "allows … when MFA is fresh" case FAILS or errors (old controller ignores `reason`, has no `StaffForceDestroyRequest`, and may 500 hitting real teardown paths). The two 401 cases should still pass. This confirms the test now exercises the new contract.

- [ ] **Step 3: Write the new endpoint test**

Create `tests/Feature/Staff/AccountDeletion/ImmediateForceDeleteTest.php`. Model `beforeEach` on `StaffUserControllerFreshAal2Test.php:31-54` (it stands up the users + `audit.staff_audit_log` schema needed for the full HTTP stack incl. the `staff.audit` terminate middleware); add the Supabase config + fake. If a table is missing at run time, copy the matching `CREATE TABLE` from that file's `beforeEach`.

```php
<?php

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\AccountDeletionScheduledMail;

// Reuse the fresh-AAL2 test's schema/staff scaffolding.
require_once __DIR__.'/../../Security/StaffUserControllerFreshAal2Test.php';

beforeEach(function () {
    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-role-key',
        'app.frontend_url' => 'https://app.sidest.test',
    ]);
    Mail::fake();
    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);
});

it('admin force-delete returns 200 with email_freed and removes the row', function () {
    $staff = makeStaff();               // admin staff helper from the required-once file
    $pro = makeProfessional();

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Confirmed spam — ticket #4242'])
        ->assertStatus(200)
        ->assertJsonFragment(['permanently_deleted' => true, 'email_freed' => true]);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();
    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_contains($r->url(), "/auth/v1/admin/users/{$pro->auth_user_id}"));
    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});

it('rejects a missing reason with 422 before touching the account', function () {
    $staff = makeStaff();
    $pro = makeProfessional();

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", [])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('maps an auth-delete failure to 502 and leaves the account present', function () {
    $staff = makeStaff();
    $pro = makeProfessional();

    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 500)]);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Confirmed spam — ticket #4242'])
        ->assertStatus(502);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
});
```

> If `makeStaff()` in the required-once file builds a non-admin (support) staff, add a local admin builder setting `role = PartnaStaff::ROLE_ADMIN` (the `/force` route is under the `staff.admin` group at `routes/api/staff.php:187`). A non-admin should get 403 — add that assertion if an admin builder is introduced.

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/ImmediateForceDeleteTest.php`
Expected: FAIL — old controller has no `StaffForceDestroyRequest`, returns no `email_freed`, and does not call the Supabase admin API.

- [ ] **Step 5: Rewrite `forceDestroy`**

Replace `StaffUserController::forceDestroy` (322-373) with:

```php
    public function forceDestroy(StaffForceDestroyRequest $request, User $professional): JsonResponse
    {
        $gate = $this->requiresFreshAal2($request);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        // Admin-only even if the route group ever widened to support staff.
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffForceDelete', $professional);

        $handle = $professional->handle;

        // Full immediate purge: pseudonymise + delete the Supabase auth user (frees the
        // email) + hard-delete + retire KV. Skips the 30-day grace period.
        $result = app(AccountDeletionService::class)->adminPurgeNow(
            professional: $professional,
            staffActorId: (string) $staff->id,
            staffActorHandle: (string) ($staff->name ?? $staff->primary_email ?? ''),
            reason: (string) $request->validated('reason'),
            overrideObligations: (bool) $request->validated('override_obligations', false),
            request: $request,
        );

        if (! ($result['success'] ?? false)) {
            return $this->error(
                $result['error'] ?? 'Account deletion failed.',
                $result['code'] ?? 400,
                [],
                isset($result['reasons']) ? ['reasons' => $result['reasons']] : [],
            );
        }

        return $this->success([
            'message' => "Professional '{$handle}' permanently deleted",
            'permanently_deleted' => true,
            'email_freed' => true,
        ]);
    }
```

Add near the other `use` statements at the top of the file (skip any already present):

```php
use App\Http\Requests\Api\Staff\StaffForceDestroyRequest;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\User\AccountDeletionService;
```

Remove the now-unused `SyncSubdomainToKvJob` / `Exception` imports **only if** nothing else in the file uses them (grep first — `destroy()` and others may still use them; do not remove blindly).

- [ ] **Step 6: Run the new + updated endpoint tests**

Run: `php artisan test tests/Feature/Staff/AccountDeletion/ImmediateForceDeleteTest.php tests/Feature/Security/StaffUserControllerFreshAal2Test.php`
Expected: PASS (all).

- [ ] **Step 7: Update `docs/api.md`**

In the staff professionals section, update the `DELETE /api/staff/professionals/{id}/force` entry to document: request body `{ "reason": "10–500 chars", "override_obligations"?: bool }`; that it now **permanently deletes the Supabase auth user (frees the email)**; responses 200 `{ permanently_deleted, email_freed }`, 422 (reason), 401 (`mfa_fresh_required`), 403 (non-admin), 502 (auth-delete failure, retried by the daily purge). Note the frontend force-delete button must collect a reason.

- [ ] **Step 8: Run the broader staff + deletion suites for regressions**

Run: `php artisan test tests/Feature/Staff tests/Feature/User/AccountDeletion tests/Feature/Security/StaffUserControllerFreshAal2Test.php`
Expected: PASS. Investigate any other test that called `/force` with no body (grep `professionals/.*force` under `tests/`) and add a `reason`.

- [ ] **Step 9: Stage (commit only on Josh's go-ahead)**

```bash
git add app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php \
        tests/Feature/Staff/AccountDeletion/ImmediateForceDeleteTest.php \
        tests/Feature/Security/StaffUserControllerFreshAal2Test.php docs/api.md
git commit -m "feat(staff): /force now runs the full immediate purge (deletes Supabase auth user, frees email)"
```

---

### Task 6: Local from-zero rehearsal + apply to dev + Postgres purge rehearsal (the real proof)

**Files:** none (ops task: `scripts/db/fresh-reset.sh` locally, then the dev Supabase ref `glncumufgaqcmqhzwrxm`).

> **BLOCKER GATE — do not run without Josh's go-ahead.** This mutates the live dev DB (which serves both domains) and hard-deletes a real row. Auth + DB + destructive.

**Context:** SQLite proved everything it can; the FK relaxation, the append-only helper, and the email-freeing are Postgres-only. This task proves them before the code deploys.

- [ ] **Step 1: Local from-zero apply rehearsal**

Run: `scripts/db/fresh-reset.sh`
Expected: applies every migration including `20260723010000_deletion_path_appendonly_fix.sql` cleanly via the psql simple-query loop (no `CONCURRENTLY` in this file → no pipeline issue). Confirms the migration is from-zero safe for the eventual prod cutover.

- [ ] **Step 2: Confirm no test pins the FK as CASCADE**

Run: `grep -rniP "users_auth_user_id_fkey|auth_user_id.*cascade" tests/`
Expected: no hits asserting `CASCADE` (verified during planning — kept as a guard).

- [ ] **Step 3: Apply the migration to dev surgically**

Apply `20260723010000_deletion_path_appendonly_fix.sql` to the dev ref via the Supabase MCP `apply_migration` (name `deletion_path_appendonly_fix`, the file's SQL) **or** `psql "$DEV_DB_URL" -f supabase/migrations/20260723010000_deletion_path_appendonly_fix.sql`. Do **not** `supabase db push` (dev has drift). Record it in `supabase_migrations.schema_migrations` if applied via psql.

- [ ] **Step 4: Verify the FK and the helper on dev**

Run against dev:
```sql
-- FK is now SET NULL
SELECT confdeltype FROM pg_constraint WHERE conname = 'users_auth_user_id_fkey';   -- expect 'n' (SET NULL), not 'c' (CASCADE)
-- helper exists + pinned search_path
SELECT proname, array_to_string(proconfig, ',') FROM pg_proc WHERE proname = 'null_user_audit_links';  -- expect search_path=
```

Run: `DB_CONNECTION=pgsql DB_HOST=<dev> php artisan test tests/Feature/Security/FunctionSearchPathTest.php --filter="null_user_audit_links"`
Expected: PASS (the new function carries a pinned search_path).

- [ ] **Step 5: Seed a throwaway staff-created account with audit rows**

Against dev, create a `core.users` row (status `active`, real-looking `auth_user_id`) plus one `audit.staff_audit_log` row and one `audit.handle_change_log` row referencing its `user_id` (and a `site.sites` + `site.site_media` row so the R2/cascade coverage is real). These are exactly the rows that made purge 500 before.

- [ ] **Step 6: Run the real purge end-to-end**

Trigger the purge via the deployed dev endpoint against this throwaway account, or `cloud tinker development` → `app(App\Services\User\AccountDeletionService::class)->adminPurgeNow(...)`. Confirm on dev:
- `purge()`/`adminPurgeNow` returns success; `core.users` row gone; child rows (sites, services, media) cascaded.
- `auth.users` row gone; **the email is reusable** (`SupabaseAdminService::findUserByEmail` returns none / a fresh signup with that email succeeds).
- `audit.staff_audit_log` / `audit.handle_change_log` rows **still present** with `user_id = NULL` (event preserved).
- A `PURGED` row in `audit.user_deletion_audit`; the `<handle>.partna.au` KV route retired; R2 media objects gone.

- [ ] **Step 7: Record the result**

Note the rehearsal outcome in the plan/PR. If anything failed, stop and reconcile before deploying. On success, the code is safe to deploy to `development`.

---

## Out of scope (follow-up)

- **`core.partna_staff.auth_user_id` is also `ON DELETE CASCADE`** (baseline:393) → deleting a *staff* auth identity would hit the same append-only block (via `staff_audit_log.staff_id` / `impersonator_staff_id` `SET NULL`). No staff-deletion flow runs through `purge()`, so it is left as a follow-up. If a staff-deletion path is ever added, extend `audit.null_user_audit_links` (or a sibling) to null those columns and give `partna_staff.auth_user_id` the same `SET NULL` treatment.

## Self-Review

**Spec coverage:**
- 2026-07-23 §1(a) FK relax → Task 1 Step 1 ✓
- 2026-07-23 §1(b) `null_user_audit_links` helper + GRANT → Task 1 Step 1 ✓; search-path guard → Task 1 Step 2 ✓
- 2026-07-23 §2 single guarded `purge()` insertion, no reordering → Task 2 ✓
- 2026-07-23 testing (SQLite guard holds; Postgres rehearsal; no CASCADE pin; FunctionSearchPathTest) → Task 2 Step 1 + Task 6 Steps 1/2/4/6 ✓
- 2026-07-23 migration application (surgical dev, fresh-reset first) → Task 6 Steps 1/3 ✓
- 2026-07-23 out-of-scope (`partna_staff` CASCADE) → Out of scope section ✓
- 2026-07-22 §4.3 adminPurgeNow + suppressMail → Task 3 ✓
- 2026-07-22 §4.4 endpoint upgrade → Task 5 ✓
- 2026-07-22 §4.5 docs/frontend flag → Task 5 Step 7 ✓
- 2026-07-22 §5 edge cases: already-pending (Task 3 test 3), Supabase failure (Tasks 3+5), stale/non-admin gates (Task 5) ✓

**Placeholder scan:** none — all steps carry concrete code/SQL/commands. Task 1's absent Pest step is explicitly justified (SQLite can't express FK cascades, triggers, or `pg_proc`) and covered by Task 6.

**Type consistency:** `adminPurgeNow(User, string, string, string, bool, Request): array` defined in Task 3, consumed identically in Task 5. `StaffForceDestroyRequest` namespace `App\Http\Requests\Api\Staff\` consistent across Tasks 4–5. `audit.null_user_audit_links(uuid)` — same signature in the migration (Task 1), the `purge()` call (Task 2), and the guard list (Task 1 Step 2). `success($data)` / `error($message, $status, $errors, $extra)` match Task-5 usage.

**Obligations note:** `checkObligations()` currently returns `[]` (commerce stripped), so the 422 branch is presently unreachable — kept for API parity; no test fakes an impossible obligation.
