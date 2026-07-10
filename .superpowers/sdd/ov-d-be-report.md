# OV-D-BE — Feedback tool backend (plan + report)

Branch `tobias/ov-d-feedback` off `origin/development`. Scope: backend only (Comet). Consumers: OV-D-FE (dashboard `/account/feedback` page) + OV-A-FE (staff dashboard Feedback page) — see **Frontend contract** below.

## Existing infra found (extend, don't duplicate)

`core.feedback` table (migrations `20260526210001_create_feedback_table.sql` + `20260526210002_feedback_hardening.sql`) already has a full user-submit pipeline with **zero live consumers** (grepped `Partna-Frontend` — no `me/feedback` caller exists yet; this was speculative infra):

| Layer | File |
|---|---|
| Model | `app/Models/Core/Feedback.php` |
| Migration | `supabase/migrations/20260526210001_create_feedback_table.sql` |
| Controller | `app/Http/Controllers/Api/User/Feedback/FeedbackController.php` (`store`/`index`/`show`) |
| FormRequest | `app/Http/Requests/Api/User/Feedback/SubmitFeedbackRequest.php` |
| Service | `app/Services/Feedback/FeedbackService.php` (dedup lock, IP hash, dispatches email job) |
| Policy | `app/Policies/FeedbackPolicy.php` (owner-only view/delete, `viewAny` any-authed, `create` gated on `AccountCapabilities::can_submit_feedback`) |
| Resource | `app/Http/Resources/FeedbackResource.php` (owner-facing, strips triage fields) |
| Routes | `routes/api/user.php:287-292` (`GET/POST /me/feedback`, `GET /me/feedback/{feedback}`) |
| Mail/Job | `app/Mail/FeedbackSubmittedMail.php`, `app/Jobs/Notifications/SendFeedbackEmailJob.php` |

Existing taxonomy: `kind` (bug/idea/praise/question/other, **NOT NULL**, required in the FormRequest) + `severity` (low/medium/high/critical, required only when kind=bug). This is a **different** taxonomy from OV-D's requested `type` (error/good/bad_ui/idea) — not a rename target, a parallel/superseding one (see Design decision 1).

**OV-A staff pattern already merged** (`b43ecf38`, PR #255) and directly reusable:
- `EnsurePartnaStaff` middleware (`staff` alias) gates `routes/api/staff.php`'s first `Route::prefix('staff')->group()` — attaches `partna_staff` (a `PartnaStaff` model instance, not `User`) to the request, 403 `staff_required` for non-staff, 403 `insufficient_staff_role` for a role-scoped route.
- Sibling "any staff role" list endpoints (`StaffAggregateAnalyticsController`, `UserSegmentPolicy::staffView`, `EarlyAccessSignupPolicy::staffView`) all follow the SAME shape: Policy method `staffView(PartnaStaff $actor, ...)` → `in_array($actor->role, [ROLE_SUPPORT, ROLE_ADMIN], true)`, controller does `$staff = $request->attributes->get('partna_staff'); $this->authorizeForUser($staff, 'staffView', X::class);`.
- **`AccountCapabilitySet::staff_view_feedback` already exists** (added by OV-A-BE alongside `staff_view_aggregate_analytics`, both derived `$isStaffRole` in `AccountCapabilities::staffPowers()`) but isn't consumed by any controller yet — it mirrors, rather than replaces, the `PartnaStaff`-actor Policy pattern above (that field is for the parallel `User`-with-`account_type=staff` capability surface, not the route-level gate). I'm following the ACTUALLY-WIRED `PartnaStaff` pattern for consistency with every other `/staff/*` route — see Design decision 3.
- Pagination envelope: `ReturnsPaginatedResponse::paginatedResponse()` + `NormalizesPerPage`, `config('partna.staff.pagination.per_page', 25)` / `per_page_max`. Same trait the existing owner-facing `FeedbackController::index` already uses.

## Design decisions

1. **`type` is a new, separate column from `kind` — not a rename/overload.** `kind`'s vocabulary (bug/idea/praise/question/other) doesn't map cleanly onto OV-D's (error/good/bad_ui/idea) — e.g. nothing in `kind` means "good", nothing in `type` means "question". Since `kind` has zero live callers, I'm not deprecating it (no cost to keep it), just making it optional and deriving a value from `type` when the caller omits it, so the DB's `kind NOT NULL` stays satisfied without forcing every new caller to know about the legacy field:
   ```
   type=error   → kind=bug
   type=bad_ui  → kind=bug
   type=good    → kind=praise
   type=idea    → kind=idea
   ```
   Caller-supplied `kind` (if ever sent) always wins over the derived value.

2. **`area` required, `target` optional** — matches the task wording exactly ("accept a free-form subject/area string **plus an optional structured target**"). Canonical field name is `area` (not `subject` — matches the DB column name and the task's own JSON example `{area: 'analytics', ...}`). `target` is a loosely-validated JSON object (size-capped, no shape enforced — same "open shape" posture as the existing `tags`/`internal_notes` columns).

3. **Staff gate uses the `PartnaStaff`-actor pattern** (route middleware + `FeedbackPolicy::staffView(PartnaStaff $actor, ...)`), not `AccountCapabilities::for($user)->staff_view_feedback`. This matches 100% of existing `/staff/*` endpoints (`EarlyAccessSignupPolicy`, `UserSegmentPolicy`, `StaffAggregateAnalyticsController`) — none of them call `AccountCapabilities::for()`; the `AccountCapabilitySet` field is forward-looking symmetry for a parallel `User`-based staff surface that no route currently uses. Consulted `account-capability-audit` reasoning but the codebase's actual capability-gate for `/staff/*` powers today is the role check on `PartnaStaff`, and I'm matching precedent over introducing a second, inconsistent gate style.

4. **No DB `CHECK` constraint on `type`/`area`.** `scripts/guard-no-unsafe-migrations.php` (Check 3) fails any `ADD CONSTRAINT ... CHECK` without `NOT VALID` + a follow-up `VALIDATE CONSTRAINT` migration — unlike its index check, there's **no same-file-`ADD COLUMN`exemption** for CHECK constraints. Adding the two-step NOT VALID/VALIDATE dance for a low-traffic internal tool is unwarranted complexity; `SubmitFeedbackRequest` is the enforcement point, matching the existing precedent that `page_url`/`user_agent`/`viewport`/`app_version`/`request_id`/`reply_email` on this same table also have no DB CHECK.

5. **Indexes on `type`/`area` created inline (not `CONCURRENTLY`)** — safe because both columns are `ADD COLUMN`-ed in the same migration file (every existing row reads NULL for them), which the guard's Check 1 explicitly exempts from the `CONCURRENTLY` requirement. Mirrors the original migration's own reasoning ("safe here because the table is empty").

6. **Staff list endpoint scope: list only, no detail/show, no triage-write.** Task asks for a list with filters; the `StaffFeedbackResource` used by the list already includes every field (message, reply_email, request_id, tags, internal_notes, ip_hash) so the staff dashboard needs no separate detail call. A staff triage-write endpoint (status transitions, internal_notes) is a natural next step but wasn't asked for — flagged in dashboard-batch `## Tail`, not built.

## Files

| File | Change |
|---|---|
| `supabase/migrations/20260711153000_feedback_type_area_target.sql` | NEW — `type`/`area`/`target` columns + 2 inline indexes + column comments. NOT applied (orchestrator applies). |
| `app/Models/Core/Feedback.php` | add `type`/`area`/`target` to `$fillable`; `target` → `array` cast |
| `app/Http/Requests/Api/User/Feedback/SubmitFeedbackRequest.php` | `kind` required→nullable; add `type` (required, enum), `area` (required, string≤120), `target` (nullable, size-capped array) |
| `app/Services/Feedback/FeedbackService.php` | derive `kind` from `type` when absent; persist `type`/`area`/`target` |
| `app/Http/Resources/FeedbackResource.php` | expose `type`/`area`/`target` to the owner (not internal/sensitive) |
| `app/Policies/FeedbackPolicy.php` | add `staffView(PartnaStaff $actor, ...)` |
| `app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php` | NEW — `index()`: type/area/date filters + pagination |
| `app/Http/Resources/Staff/StaffFeedbackResource.php` | NEW — full triage view incl. nested `user` summary |
| `routes/api/staff.php` | 1 route, read-only staff group: `GET /staff/feedback` |
| `tests/Pest.php` | `setupFeedbackTable()` gains `type`/`area`/`target` columns |
| `tests/Feature/Feedback/FeedbackApiTest.php` | existing payloads gain `type`; new tests for type/area/target round-trip |
| `tests/Feature/Security/PolicyEnforcement/FeedbackPolicyEnforcementTest.php` | add `staffView` ability coverage |
| `tests/Feature/Staff/StaffFeedbackListTest.php` | NEW — list, filters, non-staff 403 |
| `docs/api.md` | endpoint + payload doc (§7 user, §8 staff) |

## Frontend contract (OV-D-FE + OV-A-FE)

### 1. User submit — `POST /api/me/feedback` (existing endpoint, extended)

```jsonc
// Request body
{
  "type": "error",              // REQUIRED — one of: error | good | bad_ui | idea
  "area": "analytics",          // REQUIRED — free-form string ≤120 chars, no fixed vocabulary (frontend owns the picker's option list)
  "target": {                    // optional — structured companion to `area`, open shape, ≤4KB encoded
    "area": "analytics",
    "elementId": "chart-page-views"
  },
  "message": "The page-views chart is empty for the last 7 days.",  // REQUIRED, 1-5000 chars (unchanged)

  // All pre-existing fields still accepted, all still optional (unchanged):
  "kind": null,                  // legacy taxonomy, now OPTIONAL — omit it, backend derives a value from `type`
  "severity": null,               // only meaningful if you also send kind=bug
  "page_url": "https://app.partna.au/dashboard/analytics",
  "user_agent": null,             // falls back to the request header
  "viewport": "1440x900",
  "app_version": "2026.07.11-abc",
  "request_id": "01HV-...",
  "reply_email": null
}
```

```jsonc
// 201 response
{
  "feedback": {
    "id": "uuid",
    "kind": "bug",               // derived from type=error per the mapping above (unless you sent kind explicitly)
    "severity": null,
    "type": "error",
    "area": "analytics",
    "target": { "area": "analytics", "elementId": "chart-page-views" },
    "message": "The page-views chart is empty for the last 7 days.",
    "status": "new",
    "page_url": "https://app.partna.au/dashboard/analytics",
    "app_version": "2026.07.11-abc",
    "created_at": "2026-07-11T03:00:00+00:00"
  }
}
```

Errors: `422` (validation — missing `type`, `type` not in the 4-value enum, `area` missing/too long, `message` missing/too long, `target` not an object / too large), `429` (identical message resubmitted within the duplicate window — unchanged), `401` (unauthenticated). `GET /api/me/feedback` and `GET /api/me/feedback/{id}` (owner-only, 404 not 403 for someone else's row) are unchanged except the resource now also returns `type`/`area`/`target`.

### 2. Staff list — `GET /api/staff/feedback` (NEW)

Staff-gated (any role — support or admin; same `staff` + `require.aal2` middleware as every other read-only `/staff/*` route). **Non-staff → 403** (`EnsurePartnaStaff`, matches every sibling staff endpoint in this codebase — see the "concerns" note below on the task brief's "404 not 403" phrase).

```
GET /api/staff/feedback?type=idea&area=analytics&from=2026-07-01&to=2026-07-11&per_page=25&page=1
```

Query params (all optional):
- `type` — one of `error|good|bad_ui|idea`; unrecognised values are silently ignored (no filter applied), matching `StaffEarlyAccessController`'s `status` filter convention.
- `area` — exact match string.
- `from` / `to` — `YYYY-MM-DD`, inclusive, filters `created_at`. Invalid date → `422`.
- `per_page` — default 25, max 100 (`partna.staff.pagination.*`).

```jsonc
// 200 response — house pagination envelope (ReturnsPaginatedResponse)
{
  "feedback": [
    {
      "id": "uuid",
      "user": { "id": "uuid", "handle": "janedoe", "display_name": "Jane Doe", "email": "jane@example.com" }, // null if the submitting user was hard-deleted
      "kind": "bug",
      "severity": null,
      "type": "error",
      "area": "analytics",
      "target": { "area": "analytics", "elementId": "chart-page-views" },
      "message": "The page-views chart is empty for the last 7 days.",
      "status": "new",
      "page_url": "https://app.partna.au/dashboard/analytics",
      "user_agent": "Mozilla/5.0 ...",
      "viewport": "1440x900",
      "app_version": "2026.07.11-abc",
      "request_id": "01HV-...",
      "reply_email": null,
      "source": "dashboard",
      "tags": [],
      "internal_notes": [],
      "ip_hash": "sha256 hex or null",
      "created_at": "2026-07-11T03:00:00+00:00",
      "updated_at": "2026-07-11T03:00:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 42,
    "last_page": 2,
    "next_page_url": "...",
    "prev_page_url": null
  }
}
```

No staff write/triage endpoint exists yet (status transitions, internal_notes, tags) — flagged as a Tail follow-up, not built tonight.

## Status

- [x] Plan written
- [x] Implementation
- [x] Tests green (`composer test`) + pint
- [x] Commit + push + PR (no merge)

## Implementation report

**Status: COMPLETE.** Full suite green both before and after rebasing onto `origin/development` (OV-G-BE's analytics/insight-engine commit landed mid-task, zero file overlap with this change). Pre-rebase run: 3504 passed, one failure in `VerifyBotTokenTest` (unrelated bot-protection middleware, Redis-cooldown-timing-sensitive) — reproduced as a suite-order artifact only: passes 17/17 in isolation, and its test/middleware files were last touched by an old, unrelated commit (`76ebe08`, captcha HTTP-status fix), confirming it's not caused by this branch. **Post-rebase run (the one that ships): `composer test` exit 0 — 3533 passed, 119 skipped, 0 failed** (2 deprecated / 1 warning / 1 risky — pre-existing suite noise; the flaky test passed clean this time). **34 new/updated Pest tests** across four files (18 in `FeedbackApiTest` — 15 updated to send `type`/`area`, 9 new for type/area/target validation + kind-derivation + target round-trip; 3 new `staffView` policy tests; 10 new in `StaffFeedbackListTest` — list/nested-user/filters/pagination/403/401), plus `tests/Pest.php`'s `setupFeedbackTable()` gained the three columns. Migration guard (`scripts/guard-no-unsafe-migrations.php`) and pint both clean.

Decisions locked during implementation (all reflected above + in code comments):
- `type` is a genuinely separate taxonomy from `kind`, not a rename — `kind` stays NOT NULL at the DB layer and gets a derived value (`FeedbackService::TYPE_TO_KIND`) only when the caller omits it; an explicit caller-supplied `kind` always wins.
- `area` required, `target` optional — the task's own wording draws this line explicitly ("free-form subject/area string **plus an optional structured target**").
- Staff gate is the `PartnaStaff`-actor Policy pattern (`FeedbackPolicy::staffView`), not `AccountCapabilities::for($user)->staff_view_feedback`. Verified via the `account-capability-audit` skill + a whole-tree grep: `User::isStaff()` and every `staff_view_*`/`staff_manage_*` `AccountCapabilitySet` field have **zero consumers anywhere in `app/`** outside `AccountCapabilities.php` itself — the capability-set field is forward-looking symmetry OV-A-BE added, not the actual gate. Every existing `/staff/*` "any staff role" endpoint (`StaffAggregateAnalyticsController`, `EarlyAccessSignupPolicy`, `UserSegmentPolicy`) uses the same `PartnaStaff $actor` role-check pattern I followed.
- No DB `CHECK` on `type`/`area` — the unsafe-migration guard requires `NOT VALID` + a follow-up `VALIDATE CONSTRAINT` migration for any `ADD CONSTRAINT CHECK` (no same-file exemption, unlike its index check), which is disproportionate ceremony for a low-traffic internal tool. `SubmitFeedbackRequest` is the sole enforcement point, matching this table's own existing precedent (`page_url`/`user_agent`/`viewport`/`app_version`/`request_id`/`reply_email` also have no DB CHECK).
- Indexes on `type`/`area` created inline, not `CONCURRENTLY` — both columns are `ADD COLUMN`-ed in the same migration file, which the guard explicitly exempts from the concurrency requirement (every existing row is NULL for a same-file new column).
- New staff route named `staff.feedback.index` and added to `RecordStaffAuditEntry::PII_READ_ROUTE_NAMES` — the response includes the submitter's email/handle (nested `user`) and `ip_hash`, which is exactly the PRIV-4 convention this codebase already enforces on every other PII-returning staff GET route.

Concerns / follow-ups (also appended to `docs/superpowers/plans/2026-07-10-dashboard-batch.md`'s `## Tail`):
- **The "404 not 403" line in this task's brief conflicts with its own Pest-test instruction and with repo convention.** I implemented `403` for non-staff on `GET /staff/feedback` (via the real `EnsurePartnaStaff` middleware, matching literally every other `/staff/*` endpoint and `Comet-Backend/CLAUDE.md`'s explicit "403 only for role/type restrictions (\"staff-only\")" rule) — and per the task's own Pest constraint, "non-staff → 403 on the staff list." I did not find a "missing resource → 404" case that applies to a list endpoint (no per-id lookup), so I read the "404 not 403" phrase as generic boilerplate that doesn't fit this specific endpoint shape. Flagging in case the actual intent was different.
- No staff triage-write endpoint (status/internal_notes/tags) — wasn't asked for; `status`/`internal_notes`/`tags` are pre-existing schema-ready columns nothing writes yet. Tail item filed with a concrete implementation sketch.
- `type→kind` derivation map is a judgment call (`bad_ui→bug` in particular) with zero downstream readers to validate against today. Tail item filed.
- Worktree note: real `composer install` per the task's instruction (no vendor symlink) — confirmed `vendor/` is a real directory, not inherited from the main checkout.
