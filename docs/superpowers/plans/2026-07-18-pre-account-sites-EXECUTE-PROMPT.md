# Execute prompt — implement Pre-Account Sites (Fable orchestrator + Sonnet workers)

Paste everything below the line into a fresh Claude Code session **running Fable** in this repo.

---

Implement the **Pre-Account Sites** plan end-to-end: `docs/superpowers/plans/2026-07-18-pre-account-sites.md` (the plan is the source of truth — read it fully first; spec at `docs/superpowers/specs/2026-07-18-pre-account-sites-design.md` for background only). Flagged decisions F1–F6 in the plan are ACCEPTED as written — implement them; if code contradicts one, stop and ask Josh rather than redesigning.

## Your role: ORCHESTRATOR ONLY

You (Fable) never write implementation code yourself. You dispatch subagents, review their reports, manage the loop, tick plan checkboxes, and talk to Josh. Use the superpowers:subagent-driven-development skill.

**Model discipline (hard rule):** you are a Fable session — child agents INHERIT the main-loop model unless overridden. EVERY `Agent` call MUST explicitly pass `model: "sonnet"`. No exceptions, no parallel implementation agents (shared files + test-run conflicts).

## Setup (do once, yourself)

1. `git fetch && git pull` on `development`, then branch **`feat/pre-account-sites-2026-07-18`** off `origin/development`. Work in the MAIN checkout (harness worktrees break feature tests via vendor/.env symlinks). Commit early and often — this is a shared repo; uncommitted work can be wiped by a concurrent merge.
2. Confirm `composer test` is green on the base commit before Task 1. If not, stop and report to Josh.

## The per-task loop (Tasks 1–19, strictly in plan order)

For each task:

**A. Implement — spawn a fresh `model: "sonnet"` agent (a NEW agent for every task — never reuse an implementer across tasks; over the run you will spawn ~19 implementers + ~19 reviewers plus fix-cycle agents).** Its prompt contains:
- The FULL task text copied verbatim from the plan (files, interfaces, every step incl. code blocks).
- The plan's **Global Constraints** section and the **Verified premises & corrections** entries the task references.
- The gotchas block below.
- Instruction: follow the TDD steps in order; run exactly the test commands the task specifies; where a step says VERIFY (enum literals, helper names, method signatures), grep/read the named file and use the real value — never guess; complete any sketched test bodies fully (Tasks 4, 13, 17, 18 have sketches with discovery commands); commit with the task's commit message; report back files changed, test output (verbatim tail), and ANY deviation from the task text with justification.

**B. Review — spawn a fresh, INDEPENDENT `model: "sonnet"` agent** (no implementer context, new agent every time — never reuse the implementer or a previous reviewer). Its prompt contains: the same full task text + constraints + gotchas, the commit SHA(s) from step A, and instructions to:
- `git show` the commit(s); verify the diff implements every step and ONLY this task (no scope creep, no unrelated files — check `git diff --stat` file list).
- Verify premises: any claim the implementer made ("pre-existing failure", "verified enum value") must be independently confirmed — for "pre-existing failure" claims, `git stash` the state and run the failing test on the prior commit to prove it.
- Re-run the task's test file(s) AND, where the task demands it, the full suite. NEVER run tests while another agent is running tests (you, the orchestrator, guarantee this by running agents sequentially).
- Check the hard rules: no Laravel migration files; `SyncSubdomainToKvJob` remains the only KV writer; `authorizeForUser` not `authorize`; no inline 403s in controllers; jobs declare `$tries`/`$timeout`/`$backoff`; `->afterCommit()` on dispatch, never a typed `$afterCommit` property; tenancy FKs via `associate()` not `$fillable`; 404-not-403 on public routes; Resources for responses.
- Return a verdict: **PASS** or **FAIL with a numbered findings list**.

**C. Gate.** On FAIL: spawn a fresh sonnet fixer with the findings + task text, then a fresh reviewer again. Max 3 fix cycles per task — then stop and ask Josh. Only after PASS: tick the task's checkboxes in the plan file, note the commit SHA next to the task heading, and proceed to the next task.

**Task 1 is special (apply autonomously, no permission needed):** apply the migration to dev Supabase (`glncumufgaqcmqhzwrxm` — NEVER the prod ref) via the Supabase MCP `apply_migration` tool — non-interactive, no CLI link required (the CLI `db push` path needs an interactive `supabase link`; only fall back to it if MCP application fails, asking Josh to run the link with a `!` command). The DDL is backward-compatible with the currently deployed code (nullability widening + new table + view predicate widening), so applying it before the code ships is safe. Verify the applied DDL via Supabase MCP per the task, then run the snapshot refresh. Do the DB-facing parts yourself; delegate only the file-writing.

## Gotchas block (include verbatim in EVERY implementer/reviewer prompt)

- Tests run on SQLite with a fully permissive schema — Postgres NOT NULLs/CHECKs/partial indexes/triggers are NOT enforced. Constraint-bound writes must be verified against `supabase/migrations/` DDL, not a green suite.
- Bind scraper/service mocks BEFORE the first `IntegrationConnection` save in a test — the SEC-1 saving-guard resolves `PlatformRegistry` eagerly on first save and captures the real scraper otherwise.
- Never run `composer test` (or any pest run) concurrently with another session/agent running tests.
- This machine is PHP 8.4, the project targets 8.2 — never mutate process-global ini in a test; PCRE2 drift can crash locally on CI-green code.
- Run `php artisan pint` only on the files you changed; revert any baseline churn it introduces outside your diff.
- Before every commit: `git diff --cached --stat` and verify the file list is exactly your task's files — the index can contain staged work from prior sessions.
- Refactor/extraction tasks (5, 8, 12): run the FULL suite, not a filtered subset — same-namespace short refs and fakes break invisibly under filters.
- `attachTestSchemas()` / `SQLITE_MAX_ATTACHED` is already at its limit — follow the existing `tests/Pest.php` patterns exactly when adding tables.

## After Task 19: overall review

1. Spawn a fresh `model: "sonnet"` reviewer over the WHOLE branch: `git diff origin/development...HEAD`. Charge it with: (a) plan-vs-diff reconciliation — every task's deliverable present, every plan checkbox ticked maps to a commit, no orphaned/unticked items (reconcile the count, don't trust the sections); (b) cross-task consistency (signatures/names match between tasks); (c) the hard-rules checklist above across the full diff; (d) the frontend-contract section of the plan matches what actually shipped.
2. Yourself: run `composer test` (full), `php artisan pint --dirty` (no baseline churn), and explicitly `PolicyCoverageTest`, `JobHygienePolicyTest`, `AuditPipelineIntegrityTest`.
3. Fix-cycle any findings (fresh sonnet fixer → fresh sonnet reviewer) until PASS.

## Ship (fully authorized in advance — do NOT stop to ask permission for Supabase or the development push)

1. `git fetch origin` and rebase the branch onto `origin/development`. A CLEAN rebase can still be a semantic break — re-run the FULL suite after rebasing, always. (Only exception below: conflicts on files outside this feature.)
2. Merge the branch into `development` (regular merge), push `origin development`, then delete the feature branch local + remote — one atomic cleanup, no asking. Be aware `development` is push-to-deploy and serves both api domains — which is why the full-suite-after-rebase step is non-negotiable, not a reason to pause.
3. Supabase: the migration was already applied in Task 1; confirm via MCP `list_migrations` on the dev ref that it shows as applied, and state that in the final report. No prod Supabase action ever — prod is paused and out of scope.
4. Final report to Josh: what shipped, test evidence, the frontend-contract section (his partner needs it), and any follow-ups deferred (service create-branch deletion, source-mapping v2 seam, captcha hook).

## Failure posture

Stop and ask Josh (don't improvise) when: a migration/DDL surprise contradicts the plan's verified premises; a flagged decision F1–F6 turns out unimplementable as written; 3 fix cycles fail on one task; the rebase conflicts on files outside this feature; or anything requires touching prod Supabase.
