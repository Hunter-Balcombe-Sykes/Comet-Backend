# Staff Feedback Triage — Status Updates + Delete

**Date:** 2026-07-17
**Status:** Approved design, pending implementation
**Base:** branch off `origin/development`. No migrations — every column this feature touches already exists.

## Problem

`core.feedback` shipped (20260526210001) with triage columns reserved for a future admin UI — `status` (CHECK-pinned, defaults `'new'`), `internal_notes`, `tags`, `deleted_at` — but OV-D only built the read side (`GET /staff/feedback`). Nothing ever updates `status`, so every submission sits at `'new'` forever, and staff have no way to remove junk/spam.

## Decisions (made with Josh, 2026-07-17)

1. **Archive model — both mechanisms.** Normal workflow uses terminal statuses (`shipped` / `wontfix` / `duplicate`) — rows kept forever for product analytics (matching the table's `ON DELETE SET NULL` intent). A separate DELETE soft-deletes true junk, which `PurgeSoftDeleted` hard-purges after 30 days.
2. **Role gating — split.** Status changes are routine triage: support + admin. Deletion is destructive: admin-only.
3. **PATCH scope — status only.** `internal_notes` / `tags` stay dormant until a real staff-UI need appears.
4. **Shape — service-seam (Approach B).** Controller delegates to `FeedbackService`, which already owns the feedback lifecycle (submission, dedup, notification dispatch). The methods are small pass-throughs today; they are the seam where future side effects (e.g. notify submitter on `shipped`) land without touching the controller. Precedent: `StaffEarlyAccessController` → `EarlyAccessService`.

## Design

### 1. Endpoints & routes (`routes/api/staff.php`)

| Endpoint | Route group | Who |
|----------|------------|-----|
| `PATCH /staff/feedback/{feedback}` | any-staff group (where moderation-case writes live) | support + admin, via policy |
| `DELETE /staff/feedback/{feedback}` | "Staff Admin Editing" group (`staff.admin` middleware) | admin — middleware **and** policy |

- Both `->whereUuid('feedback')`, named `staff.feedback.update` / `staff.feedback.destroy`.
- Implicit model binding + `SoftDeletes` global scope ⇒ 404 on missing AND already-deleted rows for free (also makes double-DELETE and PATCH-after-delete 404).
- `RecordStaffAuditEntry` audits both automatically (PATCH/DELETE ∈ `WRITE_METHODS`) — no extra wiring.

### 2. Validation & status vocabulary

- New `public const STATUSES = ['new', 'triaged', 'in_progress', 'shipped', 'wontfix', 'duplicate']` on `App\Models\Core\Feedback` — app-layer mirror of the DB CHECK (`feedback_status_check`), with a comment pointing at migration 20260526210001.
- New `StaffFeedbackUpdateRequest`: `status => ['required', 'string', Rule::in(Feedback::STATUSES)]`. That is the entire request body.
- **SQLite caveat, made explicit:** tests don't enforce the Postgres CHECK, so the FormRequest IS the enforcement in CI. `Rule::in` matching the migration list is what keeps real Postgres from 500ing on an out-of-vocabulary write.

### 3. Policy (`app/Policies/FeedbackPolicy.php`)

- `staffTriage(PartnaStaff $actor, Feedback $feedback): bool` → `in_array($actor->role, [ROLE_SUPPORT, ROLE_ADMIN])` (same rule as `staffView`).
- `staffDelete(PartnaStaff $actor, Feedback $feedback): bool` → `$actor->isAdmin()` (mirrors `EarlyAccessSignupPolicy::staffManage`).
- Update the class docblock — the "staff triage-write ability … not yet wired to a controller" note becomes false.
- Delete is deliberately double-gated (middleware + policy): middleware fails fast; the policy keeps the rule true if the route ever moves groups. Policies are the sole role-truth since the staff capability layer was retired (2026-07-12).

### 4. Service (`app/Services/Feedback/FeedbackService.php`)

- `updateStatus(Feedback $feedback, string $status): Feedback` — set, save, return. Docblock: assumes FormRequest-validated input.
- `deleteByStaff(Feedback $feedback): void` — soft delete. Docblock states the consequence: purged permanently after 30 days by `PurgeSoftDeleted`. Named to distinguish from a future owner-initiated delete (the policy's owner `delete` ability exists but is unwired).

### 5. Controller (`StaffFeedbackController`)

- `update(StaffFeedbackUpdateRequest $request, Feedback $feedback)`: resolve `partna_staff` from request attributes → `authorizeForUser($staff, 'staffTriage', $feedback)` → service → return updated row as `StaffFeedbackResource` (user relation loaded, matching index shape).
- `destroy(Request $request, Feedback $feedback)`: same pattern with `staffDelete` → success message (mirror `StaffEarlyAccessController::destroy` return shape).
- `index()` gains a `status` query filter — validated against `Feedback::STATUSES`, silently ignored when unrecognized (the controller's existing `type`-filter convention).
- Class docblock updated — no longer "Read-only; list only".

### 6. Testing (`tests/Feature/Staff/`)

`status`-filter coverage extends the existing `StaffFeedbackListTest.php`; write coverage goes in a new `StaffFeedbackTriageTest.php`:

- support PATCHes status → 200, row updated
- admin PATCHes status → 200
- invalid status → 422
- unknown id / soft-deleted id → 404
- support DELETE → 403; admin DELETE → 200, row soft-deleted + absent from index
- PATCH after delete → 404
- `status` filter on index (valid filters, invalid silently ignored)
- staff audit row asserted for a write (follow the existing staff-write test pattern)
- policy abilities tested via `Gate::forUser()` (house rule — never `new Policy()`)

### 7. Out of scope (explicit)

- `internal_notes` / `tags` writes
- restore endpoint for soft-deleted feedback
- submitter notifications on status change (future service-seam occupant)
- an `'archived'` status value (would require a CHECK migration: drop + re-add `NOT VALID` + `VALIDATE`)
- staff dashboard UI (frontend repo)

## Files touched (expected)

| File | Change |
|------|--------|
| `routes/api/staff.php` | +2 routes (one per group) |
| `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php` | +`update`, +`destroy`, index `status` filter, docblock |
| `app/Http/Requests/Api/Staff/Feedback/StaffFeedbackUpdateRequest.php` | new |
| `app/Policies/FeedbackPolicy.php` | +`staffTriage`, +`staffDelete`, docblock |
| `app/Services/Feedback/FeedbackService.php` | +`updateStatus`, +`deleteByStaff` |
| `app/Models/Core/Feedback.php` | +`STATUSES` const |
| `tests/Feature/Staff/StaffFeedbackListTest.php` | extend — `status` filter cases |
| `tests/Feature/Staff/StaffFeedbackTriageTest.php` | new — write coverage per §6 |

No migrations. No new policies to register (`FeedbackPolicy` already registered in `AppServiceProvider`).
