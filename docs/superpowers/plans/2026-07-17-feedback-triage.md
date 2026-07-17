# Staff Feedback Triage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff can change a feedback row's triage status (support + admin) and soft-delete junk feedback (admin only), with a `status` filter on the staff list.

**Architecture:** Approach B from the approved spec (`docs/superpowers/specs/2026-07-17-feedback-triage-design.md`): thin `StaffFeedbackController` actions delegate to `FeedbackService` (which already owns the feedback lifecycle). Role gates live in `FeedbackPolicy` (`staffTriage` = support+admin, `staffDelete` = admin), the DELETE route additionally sits behind the `staff.admin` middleware. Zero migrations — `status`, `deleted_at`, and their indexes already exist in `core.feedback`.

**Tech Stack:** Laravel 12, Pest 4 (SQLite in-memory), existing staff middleware chain (`supabase.jwt` → `staff` → `require.aal2` → `staff.audit`).

## Global Constraints

- **Never create Laravel migration files** — composer guard rejects them. This plan needs no migrations at all.
- **No inline 403 checks in controllers** (CI-enforced) — all authorization via `$this->authorizeForUser($staff, 'ability', $resource)`, never `authorize()` (Supabase JWT ⇒ `Auth::user()` is null).
- **404, not 403,** for missing resources; 403 only for role-gate failures.
- **API responses via Resource classes** — `StaffFeedbackResource` for feedback rows.
- **SQLite tests don't enforce the Postgres CHECK `feedback_status_check`** — `Rule::in(Feedback::STATUSES)` in the FormRequest is the enforcement CI exercises; the `STATUSES` const MUST stay identical to the migration list `('new', 'triaged', 'in_progress', 'shipped', 'wontfix', 'duplicate')`.
- **Pint only on files this branch authored/modified** (baseline churn is reverted on surgical commits): `php artisan pint <paths>`.
- **Never push without permission.** Commits per task are fine; pushing/PR is Josh's call.
- Base branch: `origin/development`. Worktree needs its own `composer install` + a **copied** (not symlinked) `.env` — symlinked vendor/.env breaks feature tests.

---

### Task 1: Workspace setup + spec commit

**Files:**
- Create: worktree at `../wt-feedback-triage` (sibling of `backend/`), branch `feat/feedback-triage-2026-07-17`
- Commit: `docs/superpowers/specs/2026-07-17-feedback-triage-design.md`

**Interfaces:**
- Consumes: nothing.
- Produces: a green-baseline worktree every later task runs inside. All later task paths are relative to the worktree root.

- [x] **Step 1: Create the worktree off origin/development**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git fetch origin
git worktree add ../wt-feedback-triage -b feat/feedback-triage-2026-07-17 origin/development
```

- [x] **Step 2: Install dependencies + copy env (never symlink)**

```bash
cd "/Users/joshuahunter/Herd/Side Street/wt-feedback-triage"
cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
composer install --no-interaction --quiet
```

- [x] **Step 3: Verify a green baseline**

Run: `composer test`
Expected: full suite PASSES. If anything fails here, STOP — the failure predates this work; report it instead of proceeding.

- [x] **Step 4: Bring in the approved spec + this plan and commit them**

```bash
cp "/Users/joshuahunter/Herd/Side Street/backend/docs/superpowers/specs/2026-07-17-feedback-triage-design.md" docs/superpowers/specs/
cp "/Users/joshuahunter/Herd/Side Street/backend/docs/superpowers/plans/2026-07-17-feedback-triage.md" docs/superpowers/plans/
git add docs/superpowers/specs/2026-07-17-feedback-triage-design.md docs/superpowers/plans/2026-07-17-feedback-triage.md
git commit -m "docs(spec+plan): staff feedback triage — status updates + delete"
```

(If the main checkout no longer has these files — e.g. a concurrent merge cleaned untracked files — they are also committed on whatever branch Josh saved them to; recover from git history rather than re-deriving.)

---

### Task 2: Policy abilities — `staffTriage` + `staffDelete`

**Files:**
- Modify: `app/Policies/FeedbackPolicy.php`
- Test (create): `tests/Feature/Staff/StaffFeedbackTriageTest.php`

**Interfaces:**
- Consumes: `PartnaStaff::ROLE_SUPPORT`, `PartnaStaff::ROLE_ADMIN`, `PartnaStaff::isAdmin()` (all exist).
- Produces: `FeedbackPolicy::staffTriage(PartnaStaff $actor, Feedback $feedback): bool` (support+admin) and `FeedbackPolicy::staffDelete(PartnaStaff $actor, Feedback $feedback): bool` (admin only). Tasks 4 and 5 call these via `authorizeForUser`.

- [x] **Step 1: Create the test file with shared setup + failing Gate tests**

Create `tests/Feature/Staff/StaffFeedbackTriageTest.php`. Helper functions are file-scoped but PHP-global — the `triage` prefix keeps them unique across the suite (the list test owns `ovd*`).

```php
<?php

/**
 * Staff feedback triage writes (2026-07-17 design):
 *   PATCH  /staff/feedback/{feedback}  — status change, support or admin
 *   DELETE /staff/feedback/{feedback}  — junk removal, admin only (soft
 *                                        delete; purged after 30 days)
 * Archive semantics live in terminal statuses (shipped/wontfix/duplicate),
 * NOT in deletion — see docs/superpowers/specs/2026-07-17-feedback-triage-design.md.
 */

use App\Models\Core\Feedback;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Feedback\FeedbackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);

    setupUsersTable();
    setupFeedbackTable();
    setupPartnaStaffTable();

    // staff.audit middleware writes here after each staff response.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function triageSupportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'support-triage@partna.au';

    return $staff;
}

function triageAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-triage@partna.au';

    return $staff;
}

function triageSeedUser(string $handle): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.test",
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::findOrFail($id);
}

function triageSeedFeedback(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feedback')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'idea',
        'type' => 'idea',
        'area' => 'analytics',
        'message' => 'seed feedback',
        'status' => 'new',
        'internal_notes' => '[]',
        'tags' => '[]',
        'source' => 'dashboard',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

// ── Policy abilities (Gate-resolved — house rule: never `new Policy()`) ──

it('allows support and admin to staffTriage', function () {
    $feedback = new Feedback(['status' => 'new']);

    expect(Gate::forUser(triageSupportStaff())->allows('staffTriage', $feedback))->toBeTrue();
    expect(Gate::forUser(triageAdminStaff())->allows('staffTriage', $feedback))->toBeTrue();
});

it('allows only admin to staffDelete', function () {
    $feedback = new Feedback(['status' => 'new']);

    expect(Gate::forUser(triageSupportStaff())->allows('staffDelete', $feedback))->toBeFalse();
    expect(Gate::forUser(triageAdminStaff())->allows('staffDelete', $feedback))->toBeTrue();
});
```

- [x] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: both `it` blocks FAIL — `allows('staffTriage', …)` returns false because the policy has no such method yet.

- [x] **Step 3: Add the two abilities to FeedbackPolicy**

In `app/Policies/FeedbackPolicy.php`, append after `staffView()`:

```php
    /**
     * Staff triage write (PATCH /staff/feedback/{feedback}) — status changes
     * are routine triage, open to any staff role. Same rule as staffView.
     */
    public function staffTriage(PartnaStaff $actor, Feedback $feedback): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }

    /**
     * Staff junk removal (DELETE /staff/feedback/{feedback}) — destructive
     * (soft delete; PurgeSoftDeleted hard-deletes after 30 days), admin only.
     * Mirrors EarlyAccessSignupPolicy::staffManage. The route also sits behind
     * the staff.admin middleware — defence-in-depth, keep both.
     */
    public function staffDelete(PartnaStaff $actor, Feedback $feedback): bool
    {
        return $actor->isAdmin();
    }
```

Also update the class docblock: the sentence

> `A staff triage-write ability (update / delete-by-staff) is not yet wired to a controller — OV-D only shipped the read side (staffView, below).`

becomes:

> `Staff triage: staffView (list, any staff role), staffTriage (status write, any staff role), staffDelete (junk removal, admin only).`

- [x] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: PASS (2 tests).

- [x] **Step 5: Pint + commit**

```bash
php artisan pint app/Policies/FeedbackPolicy.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git add app/Policies/FeedbackPolicy.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git commit -m "feat(feedback): staffTriage + staffDelete policy abilities"
```

---

### Task 3: Service methods — `updateStatus` + `deleteByStaff`

**Files:**
- Modify: `app/Services/Feedback/FeedbackService.php`
- Test (extend): `tests/Feature/Staff/StaffFeedbackTriageTest.php`

**Interfaces:**
- Consumes: `Feedback` model (SoftDeletes), helpers from Task 2's test file.
- Produces: `FeedbackService::updateStatus(Feedback $feedback, string $status): Feedback` and `FeedbackService::deleteByStaff(Feedback $feedback): void`. Tasks 4/5's controller actions call exactly these.

- [ ] **Step 1: Append failing service tests**

Append to `tests/Feature/Staff/StaffFeedbackTriageTest.php`:

```php
// ── Service layer ──

it('updateStatus persists the new status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    $updated = app(FeedbackService::class)->updateStatus(Feedback::findOrFail($id), 'triaged');

    expect($updated->status)->toBe('triaged');
    expect(Feedback::findOrFail($id)->status)->toBe('triaged');
});

it('deleteByStaff soft deletes — row leaves the default scope but survives withTrashed', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    app(FeedbackService::class)->deleteByStaff(Feedback::findOrFail($id));

    expect(Feedback::find($id))->toBeNull();
    expect(Feedback::withTrashed()->findOrFail($id)->deleted_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: the two new tests FAIL with `Call to undefined method …FeedbackService::updateStatus()`.

- [ ] **Step 3: Implement the service methods**

In `app/Services/Feedback/FeedbackService.php`, add after `submit()` (before `hashIp()`):

```php
    /**
     * Staff triage: set the row's status. Input is validated upstream by
     * StaffFeedbackUpdateRequest (Rule::in(Feedback::STATUSES)); the DB CHECK
     * feedback_status_check is the last line of defence on real Postgres.
     */
    public function updateStatus(Feedback $feedback, string $status): Feedback
    {
        $feedback->status = $status;
        $feedback->save();

        return $feedback;
    }

    /**
     * Staff junk removal: soft delete. NOT an archive — PurgeSoftDeleted
     * hard-deletes the row after the 30-day retention window. Outcomes that
     * should be kept forever use terminal statuses instead (shipped /
     * wontfix / duplicate).
     */
    public function deleteByStaff(Feedback $feedback): void
    {
        $feedback->delete();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Pint + commit**

```bash
php artisan pint app/Services/Feedback/FeedbackService.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git add app/Services/Feedback/FeedbackService.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git commit -m "feat(feedback): FeedbackService updateStatus + deleteByStaff"
```

---

### Task 4: PATCH endpoint — model const, FormRequest, controller `update()`, route

**Files:**
- Modify: `app/Models/Core/Feedback.php`
- Create: `app/Http/Requests/Api/Staff/Feedback/StaffFeedbackUpdateRequest.php`
- Modify: `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php`
- Modify: `routes/api/staff.php` (any-staff group)
- Test (extend): `tests/Feature/Staff/StaffFeedbackTriageTest.php`

**Interfaces:**
- Consumes: `FeedbackPolicy::staffTriage` (Task 2), `FeedbackService::updateStatus` (Task 3).
- Produces: `Feedback::STATUSES` (array const — Task 6's filter uses it), route `staff.feedback.update`, response envelope `{"feedback": {…StaffFeedbackResource…}}`.

- [ ] **Step 1: Append failing endpoint tests**

Append to `tests/Feature/Staff/StaffFeedbackTriageTest.php`:

```php
// ── PATCH /staff/feedback/{feedback} ──

it('lets support set a triage status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    $response = actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'in_progress']);

    $response->assertStatus(200);
    expect($response->json('feedback.status'))->toBe('in_progress');
    expect(Feedback::findOrFail($id)->status)->toBe('in_progress');
});

it('lets admin set a triage status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageAdminStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'shipped'])
        ->assertStatus(200);

    expect(Feedback::findOrFail($id)->status)->toBe('shipped');
});

it('422s an out-of-vocabulary status (archived is NOT a status)', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'archived'])
        ->assertStatus(422);

    expect(Feedback::findOrFail($id)->status)->toBe('new');
});

it('422s a missing status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", [])
        ->assertStatus(422);
});

it('404s an unknown feedback id on PATCH', function () {
    actingAsStaff(triageSupportStaff())
        ->patchJson('/api/staff/feedback/'.Str::uuid(), ['status' => 'triaged'])
        ->assertStatus(404);
});

it('404s when PATCHing a soft-deleted row', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id, ['deleted_at' => now()->toDateTimeString()]);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(404);
});

it('rejects a non-staff authenticated user PATCH with 403 (real EnsurePartnaStaff)', function () {
    $intruder = triageSeedUser('intruder');
    $id = triageSeedFeedback($intruder->id);

    actingAsUser($intruder)
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(403);
});

it('rejects an unauthenticated PATCH with 401', function () {
    $this->patchJson('/api/staff/feedback/'.Str::uuid(), ['status' => 'triaged'])
        ->assertStatus(401);
});

it('records a staff audit row for the status write', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(200);

    $writes = DB::connection('pgsql')->table('audit.staff_audit_log')
        ->where('http_method', 'PATCH')
        ->count();
    expect($writes)->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: the new PATCH tests FAIL — most with 404/405 (route doesn't exist). The 401 test may already pass (middleware is route-group level); that's fine.

- [ ] **Step 3: Add the STATUSES const to the model**

In `app/Models/Core/Feedback.php`, add directly above `protected $table`:

```php
    /**
     * App-layer mirror of the DB CHECK feedback_status_check
     * (supabase/migrations/20260526210001_create_feedback_table.sql).
     * SQLite tests don't enforce the CHECK, so Rule::in(self::STATUSES) in
     * StaffFeedbackUpdateRequest is the enforcement CI actually exercises —
     * keep the two lists identical.
     */
    public const STATUSES = ['new', 'triaged', 'in_progress', 'shipped', 'wontfix', 'duplicate'];
```

- [ ] **Step 4: Create the FormRequest**

Create `app/Http/Requests/Api/Staff/Feedback/StaffFeedbackUpdateRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\Staff\Feedback;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Feedback;
use Illuminate\Validation\Rule;

// Staff triage write: status is the ONLY editable field (2026-07-17 design —
// internal_notes/tags stay dormant until a staff-UI need appears).
class StaffFeedbackUpdateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Feedback::STATUSES)],
        ];
    }
}
```

(`BaseFormRequest::authorize()` is `final` and returns true — authorization happens in the controller via the policy, per house pattern.)

- [ ] **Step 5: Add `update()` to the controller**

In `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php`:

Add imports:

```php
use App\Http\Requests\Api\Staff\Feedback\StaffFeedbackUpdateRequest;
use App\Services\Feedback\FeedbackService;
```

Add a constructor (the class has none) directly above `index()`:

```php
    public function __construct(private readonly FeedbackService $service) {}
```

Add after `index()`:

```php
    /** PATCH /staff/feedback/{feedback} — set triage status (support or admin). */
    public function update(StaffFeedbackUpdateRequest $request, Feedback $feedback): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffTriage', $feedback);

        $feedback = $this->service->updateStatus($feedback, $request->validated()['status']);

        return $this->success([
            'feedback' => StaffFeedbackResource::make(
                $feedback->load('user:id,handle,display_name,primary_email')
            )->resolve(),
        ]);
    }
```

Replace the entire class docblock with:

```php
/**
 * OV-D: staff triage surface for all users' feedback — consumed by the staff
 * dashboard's Feedback page (OV-A-FE). List + status write for any staff role
 * (FeedbackPolicy::staffView / staffTriage on the core.partna_staff role);
 * junk removal is admin-only (staffDelete + the staff.admin route group).
 *
 * No detail/show route. StaffFeedbackResource already returns every column
 * (message, reply_email, tags, internal_notes, ip_hash, …) so the list
 * response is self-sufficient for the staff UI.
 */
```

- [ ] **Step 6: Register the route (any-staff group)**

In `routes/api/staff.php`, directly after the existing `staff.feedback.index` route (inside the same any-staff group):

```php
        // Feedback triage — status write. Support or admin; FeedbackPolicy::
        // staffTriage adds the role gate (this group has no staff.admin).
        Route::patch('/feedback/{feedback}', [StaffFeedbackController::class, 'update'])
            ->whereUuid('feedback')
            ->name('staff.feedback.update');
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: PASS (13 tests).

- [ ] **Step 8: Pint + commit**

```bash
php artisan pint app/Models/Core/Feedback.php app/Http/Requests/Api/Staff/Feedback/StaffFeedbackUpdateRequest.php app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php routes/api/staff.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git add app/Models/Core/Feedback.php app/Http/Requests/Api/Staff/Feedback/StaffFeedbackUpdateRequest.php app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php routes/api/staff.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git commit -m "feat(feedback): PATCH /staff/feedback/{id} — staff triage status write"
```

---

### Task 5: DELETE endpoint — controller `destroy()`, admin route

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php`
- Modify: `routes/api/staff.php` (admin editing group)
- Test (extend): `tests/Feature/Staff/StaffFeedbackTriageTest.php`

**Interfaces:**
- Consumes: `FeedbackPolicy::staffDelete` (Task 2), `FeedbackService::deleteByStaff` (Task 3).
- Produces: route `staff.feedback.destroy`, returns `204 No Content` (mirrors `StaffEarlyAccessController::destroy`).

- [ ] **Step 1: Append failing DELETE tests**

Append to `tests/Feature/Staff/StaffFeedbackTriageTest.php`:

```php
// ── DELETE /staff/feedback/{feedback} ──

it('lets admin soft-delete junk feedback (204) and hides it from the list', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageAdminStaff())
        ->deleteJson("/api/staff/feedback/{$id}")
        ->assertStatus(204);

    expect(Feedback::find($id))->toBeNull();
    expect(Feedback::withTrashed()->findOrFail($id)->deleted_at)->not->toBeNull();

    // Gone from the staff list too (default SoftDeletes scope).
    $list = actingAsStaff(triageAdminStaff())->getJson('/api/staff/feedback');
    expect($list->json('meta.total'))->toBe(0);
});

it('rejects support DELETE with 403 (staff.admin middleware)', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->deleteJson("/api/staff/feedback/{$id}")
        ->assertStatus(403);

    expect(Feedback::find($id))->not->toBeNull();
});

it('404s on double delete and unknown ids', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);
    $admin = triageAdminStaff();

    actingAsStaff($admin)->deleteJson("/api/staff/feedback/{$id}")->assertStatus(204);
    actingAsStaff($admin)->deleteJson("/api/staff/feedback/{$id}")->assertStatus(404);
    actingAsStaff($admin)->deleteJson('/api/staff/feedback/'.Str::uuid())->assertStatus(404);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: the three new tests FAIL (404/405 — no DELETE route yet).

- [ ] **Step 3: Add `destroy()` to the controller**

In `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php`, add after `update()`:

```php
    /**
     * DELETE /staff/feedback/{feedback} — junk/spam removal (admin only).
     * Soft delete; PurgeSoftDeleted hard-deletes after 30 days. Workflow
     * outcomes use terminal statuses (shipped/wontfix/duplicate) instead.
     */
    public function destroy(Request $request, Feedback $feedback): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffDelete', $feedback);

        $this->service->deleteByStaff($feedback);

        return response()->json(null, 204);
    }
```

(`Request` is already imported — `index()` uses it.)

- [ ] **Step 4: Register the route (admin editing group)**

In `routes/api/staff.php`, in the **"Authorised Staff Admin Editing"** group (`staff.admin` middleware), directly after the early-access block (`Route::delete('/early-access/{signup}', …)`):

```php
        // Feedback — junk/spam removal (soft delete; purged after 30 days).
        // FeedbackPolicy::staffDelete adds defence-in-depth on top of staff.admin.
        Route::delete('/feedback/{feedback}', [StaffFeedbackController::class, 'destroy'])
            ->whereUuid('feedback')
            ->name('staff.feedback.destroy');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: PASS (16 tests).

- [ ] **Step 6: Pint + commit**

```bash
php artisan pint app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php routes/api/staff.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git add app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php routes/api/staff.php tests/Feature/Staff/StaffFeedbackTriageTest.php
git commit -m "feat(feedback): DELETE /staff/feedback/{id} — admin junk removal (soft delete)"
```

---

### Task 6: `status` filter on the staff list

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php` (`index()`)
- Test (extend): `tests/Feature/Staff/StaffFeedbackListTest.php`

**Interfaces:**
- Consumes: `Feedback::STATUSES` (Task 4).
- Produces: `GET /staff/feedback?status=<value>` — filters when the value is in `Feedback::STATUSES`, silently ignored otherwise (the controller's existing `type`-filter convention).

- [ ] **Step 1: Append failing filter tests to the LIST test file**

Append to `tests/Feature/Staff/StaffFeedbackListTest.php` (this file owns the `ovd*` helpers — do NOT redefine them):

```php
it('filters by status', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['status' => 'new', 'message' => 'fresh']);
    ovdSeedFeedback($alice->id, ['status' => 'shipped', 'message' => 'done']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?status=shipped');

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(1);
    expect($items[0]['message'])->toBe('done');
});

it('ignores an unrecognised status filter rather than erroring', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['status' => 'new']);
    ovdSeedFeedback($alice->id, ['status' => 'shipped']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?status=bogus');

    $response->assertStatus(200);
    expect($response->json('feedback'))->toHaveCount(2);
});
```

- [ ] **Step 2: Run tests to verify the new one fails**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackListTest.php`
Expected: `filters by status` FAILS (2 rows returned — filter not applied). `ignores an unrecognised status filter` may already pass (unknown query params are ignored by default); that's expected.

- [ ] **Step 3: Add the filter to `index()`**

In `StaffFeedbackController::index()`, directly after the `area` filter block:

```php
        // Triage status — same silent-ignore convention as `type` above.
        $status = $request->query('status');
        if (is_string($status) && in_array($status, Feedback::STATUSES, true)) {
            $query->where('status', $status);
        }
```

Also update the `index()` docblock line to include the new param:

```php
    /** GET /staff/feedback?type=&area=&status=&from=&to=&per_page= */
```

- [ ] **Step 4: Run both feedback test files to verify green**

Run: `./vendor/bin/pest tests/Feature/Staff/StaffFeedbackListTest.php tests/Feature/Staff/StaffFeedbackTriageTest.php`
Expected: PASS (all tests, both files).

- [ ] **Step 5: Pint + commit**

```bash
php artisan pint app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php tests/Feature/Staff/StaffFeedbackListTest.php
git add app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php tests/Feature/Staff/StaffFeedbackListTest.php
git commit -m "feat(feedback): status filter on GET /staff/feedback"
```

---

### Task 7: Full-suite verification

**Files:** none new — verification only.

**Interfaces:**
- Consumes: everything above.
- Produces: a branch ready for Josh's review/merge decision. Do NOT push.

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: full suite PASSES. If a failure appears outside this feature, do NOT wave it off as pre-existing — `git stash && composer test` on the prior commit to prove it, then report (house rule: verify "pre-existing failure" claims).

- [ ] **Step 2: Confirm no stray changes**

```bash
git status --short   # expect: clean
git log --oneline origin/development..HEAD   # expect: exactly 6 commits (spec + 5 feature)
git diff origin/development..HEAD --stat     # expect: only the 8 planned files
```

- [ ] **Step 3: Report**

Summarize: endpoints added, test counts, commit list. Merge/PR/push is Josh's decision — stop here.

---

## Self-review notes (kept for the executor)

- Spec §1–§7 map to Tasks 4/5 (endpoints), 4 (validation), 2 (policy), 3 (service), 4/5/6 (controller), 2–6 (tests), and the out-of-scope list is untouched by any task. No migrations anywhere.
- Names are consistent everywhere: `staffTriage`, `staffDelete`, `updateStatus`, `deleteByStaff`, `Feedback::STATUSES`, routes `staff.feedback.update` / `staff.feedback.destroy`.
- `feedback` route param does not collide with the group-level `whereUuid('professional')`; both write routes pin `whereUuid('feedback')` explicitly.
- The audit-row test asserts `count() === 1` on PATCH writes only — the GET-list audit rows in the same table use method GET, so they can't pollute the count.
