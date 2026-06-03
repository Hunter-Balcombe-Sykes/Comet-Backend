# Plan A — Trust & Safety Foundation: Execution Prompt

**Hand this prompt to a fresh Claude Code session to execute Plan A.**

---

## Your task

Execute implementation Plan A (Trust & Safety foundation: schema, services, public reporting endpoint) end-to-end via subagent-driven development, then conduct a comprehensive post-implementation review before declaring complete.

## Inputs you will read

| File | Purpose |
|------|---------|
| `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md` | The design spec (read once for context — sections 4, 5, 6, 7) |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-a.md` | The plan you execute. 26 tasks across 10 phases. Read fully once at start; extract every task's full text before dispatching subagents |

## Dependencies

- **None.** Plan A is the foundation; Plans B and C depend on A.

## Pre-flight (do these before dispatching any subagent)

1. **Pull latest:** `git fetch origin && git checkout development && git pull origin development`
2. **Verify baseline migrations present** — these must exist on origin/development:
   ```bash
   ls supabase/migrations/ | grep -E '20260527(010000|030000|070000)'
   ```
   Expect three matches (reorganize_schemas, rename_professional_to_user, skeleton_system_cleanup). If missing, STOP and escalate.
3. **Create feature branch:** `git checkout -b feat/ts-foundation-plan-a`
4. **Baseline tests pass:** `composer test` — must be green before you start.
5. **Composer autoload fresh:** `composer dump-autoload -o`

If any pre-flight fails, STOP and escalate to the human partner. Do not proceed with broken baseline.

## Execution mode (REQUIRED)

Use the `superpowers:subagent-driven-development` skill. For EACH of the 26 tasks in the plan:

1. **Extract** the task's full text + surrounding context from the plan file.
2. **Dispatch the implementer subagent** (`./implementer-prompt.md`) with the complete task text + relevant context (which models exist, what previous tasks landed, etc.). The subagent uses TDD per the plan's `Step 1 → fail → Step 3 → pass → Step 5 commit` pattern.
3. **Wait for implementer status:** DONE | DONE_WITH_CONCERNS | NEEDS_CONTEXT | BLOCKED. Handle each per the skill's status-handling rules.
4. **Dispatch the spec-compliance reviewer** (`./spec-reviewer-prompt.md`) when implementer reports DONE. Reviewer confirms the diff matches the task spec — nothing extra, nothing missing.
5. **If spec reviewer finds gaps:** re-dispatch the same implementer with the gap list. Repeat until spec reviewer approves.
6. **Dispatch the code-quality reviewer** (`./code-quality-reviewer-prompt.md`). Reviewer asserts the code is well-built, tests are meaningful, no obvious smells.
7. **If quality reviewer finds issues:** re-dispatch implementer with the findings. Repeat until quality reviewer approves.
8. **Mark task complete in TodoWrite.**
9. **Move to the next task.** Do not pause to check in with the human between tasks unless BLOCKED — execute continuously.

**Critical: do not skip either review.** Per-task review catches drift before it compounds. Order matters — spec compliance FIRST, code quality SECOND.

## Architectural decisions that are NON-NEGOTIABLE

These are decisions baked into the patched plan. If a subagent suggests reverting any of these, REJECT and re-prompt:

| Decision | Why it's locked |
|----------|-----------------|
| `core.users` uses existing `status` enum (`active`/`suspended`/`disabled`/`pending_deletion`) — **do NOT add a `moderation_state` column to users** | Existing column already covers the semantics; adding a parallel column duplicates state |
| `site.sites.moderation_state` IS added (Task 3) — distinct from `is_published` | Need a staff-driven hide independent of user unpublishing |
| `audit` schema already exists from mig `20260527010000_reorganize_schemas.sql` — **do NOT create it again** | Idempotency in migrations is fine but the schema and its policies are already in place |
| `core.partna_staff` filters use `role='admin'` for on-call routing — **do NOT introduce `is_on_call` or `is_active` filters** | No such columns exist on the table |
| Captcha verification is via `bot.token:report` middleware using existing `CaptchaManager` — **do NOT introduce a `TurnstileVerifier` class** | The bot-protection stack already provides multi-provider captcha verification with circuit breaker |
| Test auth uses global Pest helpers in `tests/Pest.php` (`actingAsUser`, `aal2ClaimsWithFreshTotp`) — **do NOT invent `actingAsStaffWithAal2` or `actingAsSupabaseStaff` helpers** | Plan B adds `actingAsStaff`; Plan A's tests use `actingAsUser` only |
| All Eloquent models extend `App\Models\BaseModel` (forces `pgsql` connection) | Project convention |
| All new tables go in the `moderation` schema, including the case lifecycle tables. Audit-only tables go in the `audit` schema (specifically `audit.moderation_events`) | Schema split per architecture |
| Model class is `ModerationCase` (not `Case`) | `case` is a PHP reserved keyword |

## Forbidden patterns — abort if a subagent introduces any

Grep the diff after each task. If any of these surface, REJECT the change:

```bash
# Should return zero matches across all new files
git diff --cached | grep -E 'App\\Models\\Core\\Professional\\User|App\\Models\\Site\\Site|App\\Models\\Core\\PartnaStaff[^/]|TurnstileVerifier|actingAsStaffWithAal2|actingAsSupabaseStaff'
git diff --cached supabase/migrations/ | grep -E 'CREATE SCHEMA IF NOT EXISTS audit|core\.users[^;]+moderation_state|professional_id'
```

## Post-implementation review stage (REQUIRED — after all 26 tasks land)

Once Phase 1 through Phase 10 are all marked complete, do **not** declare done. Run this full review pass:

### 1. Final code-reviewer subagent (whole diff)

Dispatch a final code-reviewer subagent with these inputs:
- Plan path
- Spec path
- The full diff: `git diff origin/development...HEAD`
- Explicit task list: confirm all 26 tasks have landed

Reviewer's mandate:
- Spec coverage: every spec section that Plan A claims to address is implemented
- Cross-task consistency: types/method signatures/property names match between tasks (e.g., `ContentReportSubmitResult::$receiptId` consistent across service + resource + tests)
- No drift from the architectural decisions above
- No orphan code (a class added but never called)

Address every finding before moving on.

### 2. Full test suite

Run all of these and require zero failures:
```bash
composer test
php artisan test --group=postgres
php artisan test tests/Feature/Moderation/
php artisan test tests/Feature/Security/
php artisan test tests/Unit/Services/Moderation/
```

### 3. Forbidden-pattern sweep

Re-run the grep sweeps from the "Forbidden patterns" section against the entire branch diff. Any matches = re-open the relevant task and fix.

### 4. Hot-path index verification

Run the EXPLAIN-based index tests (Task 23) explicitly to confirm the partial indexes are being used by the query planner. A green test here is the difference between "fast at 100k cases" and "table scans at 100k cases" — high-leverage check.

### 5. End-to-end smoke

Follow Task 25 (manual verification) exactly. Submit a real report via curl against `dev-api.partna.au`, confirm rows land, confirm Nightwatch shows no exceptions on the new route.

### 6. Operator runbook landed

Confirm `docs/moderation/README.md` exists and reflects Plan A's reality (Task 24).

### 7. Verify pre-flight conditions still hold

Confirm `git diff --stat origin/development...HEAD` looks reasonable — no surprise file changes outside the plan's scope.

### 8. Generate PR

Use Task 25 Step 5's `gh pr create` template verbatim, adjusted for what actually shipped. Do not push to remote without final user approval.

## Success criteria (you may declare done when ALL of these are true)

- [ ] All 26 tasks marked complete in TodoWrite
- [ ] Every per-task spec + code-quality review approved
- [ ] Final post-implementation code-reviewer approved
- [ ] Full test suite passes (SQLite + Postgres-tagged)
- [ ] Forbidden-pattern sweep returns zero matches
- [ ] Hot-path index plan tests pass
- [ ] Manual smoke test executed successfully against dev
- [ ] Operator runbook present at `docs/moderation/README.md`
- [ ] PR created with Task 25's template, awaiting user approval to push

## Escalation rules (stop and ask the human)

- **BLOCKED** implementer status that you can't resolve in one re-dispatch
- A required existing class/method has a different signature than the plan assumes — report the actual signature and ask whether to adapt the plan or the call site
- Test failure you can't fix in 3 attempts
- Ambiguity in the spec that genuinely prevents progress
- A subagent proposes scope creep (adding features beyond the plan)
- A subagent proposes to revert any of the locked architectural decisions above

Do NOT escalate to ask "should I continue?" — execute continuously until BLOCKED or done.

## What ships after Plan A completes

- Visitors can submit reports via `POST /v1/public/report` (Turnstile-gated, rate-limited, deduped)
- Cases land as `moderation.cases` rows with snapshotted evidence and a `dedup_hash`
- Staff have **no UI yet** — they read via `php artisan tinker` or raw SQL (Plan B adds the UI)
- No outcome jobs fire — decisions can't be recorded yet (Plan B)
- No CSAM scanning yet (Plan C)

After landing this plan, the next step is to hand off the Plan B execution prompt to a fresh session (or continue in the same session if context allows).

---

**Begin by reading the plan file completely, creating a TodoWrite list of all 26 tasks (including Task 7.5), and then dispatching the first implementer subagent for Task 1.**
