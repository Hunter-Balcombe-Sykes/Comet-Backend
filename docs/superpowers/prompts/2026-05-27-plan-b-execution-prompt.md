# Plan B — Trust & Safety Staff Workflow + Outcomes: Execution Prompt

**Hand this prompt to a fresh Claude Code session to execute Plan B.**

---

## Your task

Execute implementation Plan B (staff queue endpoints, outcome propagation jobs, lifecycle commands, capability gates) end-to-end via subagent-driven development, then conduct a comprehensive post-implementation review before declaring complete.

## Inputs you will read

| File | Purpose |
|------|---------|
| `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md` | Design spec (read sections 5, 7, 8, 9, 10) |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-b.md` | The plan you execute. 30 tasks across 9 phases |

## Dependencies

- **Plan A must be merged into `development`** before starting. Plan B reuses:
  - The `moderation` schema + all Plan A models (`ModerationCase`, `CaseSignal`, `Evidence`, `Decision`, `ActionLogEntry`, `AuditEvent`)
  - `CaseStateMachine`, `ModerationAuditService`, `EvidenceSnapshotService`
  - `SiteFactory`, `PartnaStaffFactory` (created by Plan A Task 7.5)
  - The existing public report submit path
- If Plan A is not on `origin/development`, STOP and escalate.

## Pre-flight (do these before dispatching any subagent)

1. **Pull latest:** `git fetch origin && git checkout development && git pull origin development`
2. **Verify Plan A is merged** — these must exist on `development`:
   ```bash
   ls supabase/migrations/ | grep 20260528000000_create_moderation_schema.sql
   php -r 'require "vendor/autoload.php"; echo class_exists(\App\Models\Moderation\ModerationCase::class) ? "OK" : "MISSING";'
   ```
   Both must be present. If not, STOP and escalate.
3. **Create feature branch:** `git checkout -b feat/ts-foundation-plan-b`
4. **Baseline tests pass:** `composer test` && `php artisan test --group=postgres`
5. **At least one PartnaStaff row exists for local smoke tests** — use the factory: `php artisan tinker --execute='App\Models\Core\Staff\PartnaStaff::factory()->admin()->create();'`

If any pre-flight fails, STOP and escalate.

## Execution mode (REQUIRED)

Use the `superpowers:subagent-driven-development` skill. For EACH of the 30 tasks:

1. Extract task text + context from the plan file.
2. Dispatch implementer subagent with full task text + relevant context.
3. Handle status: DONE | DONE_WITH_CONCERNS | NEEDS_CONTEXT | BLOCKED.
4. Dispatch spec-compliance reviewer.
5. If issues, re-dispatch implementer; loop until spec reviewer approves.
6. Dispatch code-quality reviewer.
7. If issues, re-dispatch implementer; loop until quality reviewer approves.
8. Mark complete in TodoWrite.
9. Move to next task. Execute continuously — no check-ins between tasks unless BLOCKED.

## Architectural decisions that are NON-NEGOTIABLE

| Decision | Why locked |
|----------|------------|
| `ModerationDecisionService::decide()` is the **only** legal write path to `moderation.decisions`. No controller bypasses; no inline `Decision::create()` calls outside the service | Audit + dispatch consistency depends on a single chokepoint |
| CSAM override (`decision_type='override_csam_auto_action'`) requires `second_staff_approval_id` AND it must differ from the deciding staff. Enforced both at the DB level (CHECK constraint from Plan A) and the FormRequest layer | Override of an auto-suspension is a high-trust operation — two-control surface mandatory |
| `AccountCapabilitySet` is extended by **adding to its constructor**, not by introducing a `->can(string)` method. Task 14 in the plan reflects this. Property access is by name | The `final readonly class` pattern is intentional — type-safe capability names |
| `SuspendUserJob` maps `decision_type` → `users.status` (`suspend_user`='suspended', `ban_user`='disabled'). Uses existing `core.users.status` enum; does NOT touch a `moderation_state` column on users (no such column exists) | Single source of truth on the user table |
| `actingAsStaff($staff, $claims)` is added to `tests/Pest.php` (Task 17) as a global helper mirroring `actingAsUser`. Pattern: bind `VerifySupabaseJwt` + `EnsurePartnaStaff` middleware stubs via the container | Matches the existing codebase pattern |
| On-call routing for `NotifyOnCallStaffJob`: `PartnaStaff::query()->where('role', 'admin')`. **No `is_on_call` or `is_active` filter** — those columns don't exist | Document this in the runbook so future tightening can add a column with intent |
| AAL2 enforcement returns 401 (not 403) — per `BasePolicy::requiresAal2()` contract. Tests assert 401 | Frontend interprets 401 as "trigger MFA step-up challenge" |
| Outcome jobs are idempotent: running twice has no extra effect on the target. They update `action_log` rows with `dispatched_at`/`completed_at`/`attempts` | Horizon retry semantics; bug-free re-runs |
| `PurgeModerationCacheJob` dispatches the existing `SyncSubdomainToKvJob` — does NOT write to `SUBDOMAIN_KV` directly | `SyncSubdomainToKvJob` is the canonical (and only) writer per project rules |
| Notification dispatchers check `AccountCapabilities::for($user)->receive_moderation_notifications` before sending | Fail-closed for suspended/banned users |

## Forbidden patterns — abort if a subagent introduces any

```bash
# Should return zero matches
git diff --cached | grep -E 'App\\Models\\Core\\Professional\\User|App\\Models\\Site\\Site|App\\Models\\Core\\PartnaStaff[^/]'
git diff --cached | grep -E '->where\\([\x27"]is_on_call|->where\\([\x27"]is_active'
git diff --cached | grep -E '->can\\([\x27"](receive_moderation_notifications|can_be_reported)'
git diff --cached | grep -E 'actingAsStaffWithAal2|actingAsSupabaseStaff'
git diff --cached | grep -E 'core\\.users[^;]+moderation_state'
git diff --cached app/Jobs/Cloudflare/ | grep -v '^$'   # don't accidentally modify the existing SyncSubdomainToKvJob signature
```

## Post-implementation review stage (REQUIRED — after all 30 tasks land)

### 1. Final code-reviewer subagent (whole branch diff)

Dispatch a final code-reviewer subagent with:
- Plan path, spec path
- Full diff: `git diff origin/development...HEAD`
- Task list: all 30 tasks marked complete

Reviewer's mandate:
- Spec coverage check (every Plan B claim ships)
- Decision service is the only `Decision::create()` call site (grep for stray writes)
- Dispatcher's `ACTIONS_BY_DECISION` map covers every legal `decision_type`
- Every outcome job updates `action_log` correctly (status transitions + timestamps)
- All staff endpoints AAL2-gated via middleware AND policy
- No PII leakage in `CaseResource` / `CaseDetailResource`
- `actingAsStaff` helper is in `tests/Pest.php` (not buried in a TestCase override)

Address every finding before moving on.

### 2. Full test suite — zero failures required

```bash
composer test
php artisan test --group=postgres
php artisan test tests/Feature/Moderation/
php artisan test tests/Feature/Commands/Moderation/
php artisan test tests/Feature/Security/
php artisan test tests/Unit/Services/Moderation/
php artisan test tests/Unit/Notifications/Moderation/
```

### 3. Forbidden-pattern sweep

Re-run the grep block from "Forbidden patterns" against the full branch diff. Any matches = re-open relevant task.

### 4. Specific security sweeps (must pass)

```bash
php artisan test tests/Feature/Security/ModerationAal2EnforcementTest.php
php artisan test tests/Feature/Security/StaffCaseAuthorizationTest.php
php artisan test tests/Feature/Moderation/CapabilityGatedDispatcherTest.php
php artisan test tests/Feature/Moderation/StaffCaseDecideTest.php   # covers CSAM override 2-staff rule
```

### 5. End-to-end smoke

Run the curl sequence from Task 30 Step 2:
- Submit report → confirm case lands
- Triage as staff (with AAL2) → confirm transition
- Take + decide (`hide_site`) → confirm `sites.moderation_state='hidden'`, `SyncSubdomainToKvJob` dispatched, reporter receives outcome email (Mailhog), reported user receives statement-of-reasons email

### 6. CSAM override smoke

Manually create a CSAM-style case (you can `forceFill` one via tinker since Plan C isn't merged yet), then attempt an override:
- Without `second_staff_approval_id` → 422
- With same staff as approver → 422
- With different staff → 200 + decision row with `supersedes_decision_id` populated

### 7. Operator runbook updated

Confirm `docs/moderation/staff-workflow.md` exists and `docs/moderation/README.md` status table marks Plan B complete (Task 29).

### 8. Generate PR

Use Task 30 Step 5's `gh pr create` template. Do not push without final user approval.

## Success criteria

- [ ] All 30 tasks marked complete in TodoWrite
- [ ] Per-task reviews all approved
- [ ] Final post-implementation reviewer approved
- [ ] Full test suite passes (SQLite + Postgres-tagged + group-specific runs)
- [ ] Forbidden-pattern sweep returns zero matches
- [ ] AAL2 enforcement + capability-gated dispatcher + CSAM override 2-staff tests all green
- [ ] Manual smoke test executed: report → triage → decide → outcomes propagated
- [ ] Manual CSAM override smoke verified
- [ ] `docs/moderation/staff-workflow.md` exists; README updated
- [ ] PR created with Task 30's template, awaiting user approval to push

## Escalation rules

Same as Plan A. Stop and ask if:
- BLOCKED implementer unresolvable in one re-dispatch
- An existing class/middleware has a different signature than the plan assumes
- Test failure unresolvable in 3 attempts
- Subagent proposes reverting any locked architectural decision
- Subagent proposes scope creep

## What ships after Plan B completes

- Full reporting workflow operational end-to-end: visitor reports → case → triage → decide → outcomes (suspend user, hide site, purge edge cache, notify reporter + reported user)
- Staff have a REST API for the queue (AAL2-gated)
- 4 lifecycle commands operational (`sla-scan`, `redact-reporter-pii`, `show-case`, `reverse-decision`)
- CSAM scanning still NOT live (Plan C)
- `ModerationDecisionService::decideAsSystem` is added by Plan C (NOT here) — Plan C is where the auto-action entry point lands

After landing this plan, hand off the Plan C execution prompt to a fresh session.

---

**Begin by reading the plan file completely, creating a TodoWrite list of all 30 tasks, and then dispatching the first implementer subagent for Task 1.**
