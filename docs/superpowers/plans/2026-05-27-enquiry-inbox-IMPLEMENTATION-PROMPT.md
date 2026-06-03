# Enquiry Inbox — Implementation Prompt

Copy the block below into a fresh Claude Code session in the Partna backend repo to kick off implementation. It's self-contained: the plan file is the source of truth for tasks; this prompt sets the rules of engagement.

---

## Prompt to paste

You're implementing the Enquiry Inbox feature for Partna's backend.

**Plan file:** `docs/superpowers/plans/2026-05-27-enquiry-inbox.md` — 28 tasks (Task 0 + Tasks 1–27). Read it now before doing anything else.

**Spec file (background context):** `docs/superpowers/specs/2026-05-26-enquiry-inbox-design.md` — read the **Problem**, **Scope**, **Architecture**, and **Models and file paths** sections so you understand the why behind each task.

**Project rules — read `CLAUDE.md` and honour them strictly. Highlights:**

- Schema changes go in `supabase/migrations/` as raw SQL — **never create Laravel migration files** (a composer guard rejects them).
- Authorization is via Laravel Policies in `app/Policies/`. **Never inline 403 in controllers.** Use `$this->authorizeForUser($user, ...)` not `authorize()` (Supabase JWT means `Auth::user()` is null).
- Cross-tenant → return 404 (not 403). Public unauthenticated endpoints → always 404 for missing/inaccessible resources.
- Resources for all API responses (no raw Eloquent models in responses). Form Requests for input validation.
- Business logic in `Services/`, not controllers.
- For Laravel logs, use `cloud env:logs partna development --minutes 10` — **NEVER** `mcp__laravel-boost__read-log-entries` or `last-error` (those are stale test output).

**Testing pattern — critical:**

This codebase does NOT use Eloquent model factories. Tests use raw SQL `CREATE TABLE` + helper functions. The plan's **Testing conventions** section (top of the plan file) tells you how to translate. **Read it before writing any test.** The TL;DR:
- Setup: `setupUsersTable()` + `setupContactInboxSchema()` (helper you create in Task 0)
- Seeding: `makeInboxUser()`, `seedInboxEnquiry($userId, $siteId, $overrides)`, `seedContactBlock($siteId, $userId, $settings)`, `seedInboxCustomer($userId, $overrides)`
- Auth bypass: `requestAs($user, 'POST', '/api/me/enquiries/{id}/replied')` then `app(Ctrl::class)->method($request, $id)` — direct controller invocation, NOT `$this->postJson(...)`
- The request attribute is still `'professional'` (request attributes weren't renamed when the column was)
- Public routes: keep using HTTP `postJson`/`getJson` so middleware runs

When you see `Model::factory()->create([...])` in any task's test snippet, **translate** to the helper-based pattern. The plan's test code is intent-specification, not literal copy-paste.

**Project context worth knowing before you start:**

- **Branch:** start from `development`. Create `feat/enquiry-inbox` off `development`. Never push without explicit approval.
- **Recent refactors that touched files in this work:**
  - `professional_id` column → `user_id` (migration `20260527030000_rename_professional_to_user.sql`). All FK columns now use `user_id`.
  - `core.customers` table → `site.customers`.
  - `Api/Professional/Customers/ProfessionalEnquiryController` → `Api/User/Customers/UserEnquiryController`.
  - `App\Models\Core\Professional\*` → `App\Models\Core\User\*`.
  - `VerifyTurnstileCaptcha` middleware → DELETED. Replaced by bot-protection-foundation (`VerifyBotToken`, `CaptchaManager`, `BOT_PROTECTION_DRIVER`/`MODE`/`FAIL_OPEN` env vars). The `bot.token:enquiry` middleware is ALREADY on `POST /public/enquiry`.

**Execution approach (subagent-driven):**

For each task in the plan:
1. Read the task fully — files, every step, every code block.
2. Spawn a fresh subagent (general-purpose, sonnet) with: the task's content + the relevant project rules from CLAUDE.md + a reminder to follow the plan's Testing conventions.
3. Subagent does: write failing test → run (verify fail) → minimal implementation → run (verify pass) → `pint` → `composer test` → commit with the message in the task.
4. You (main loop) verify the subagent's diff before moving to the next task. Check: do the changes match what the task said? Did the test really fail then pass? Did the commit happen? Don't trust the subagent's summary — `git diff HEAD~1 HEAD` and read the actual changes.

**If you hit any of these, STOP and ask the user:**
- A task's prerequisite (e.g., a file or method) doesn't exist in the codebase. Don't fabricate; verify first.
- A test passes without making the expected real-code change (false positive — implementation might be wrong).
- The migration push needs prod credentials (Task 2 stays in dev only — never push to prod without explicit user instruction).
- You can't get a task green after 3 attempts. Surface the blocker.
- You discover that something the plan says is already implemented differently. Verify which is right before changing it.

**Verification gates:**
- After each task: `./vendor/bin/pest` (relevant tests) + `./vendor/bin/pint`.
- After every 5 tasks: `composer test` (full suite) — catches inter-task regressions early.
- At the end (Task 26): full suite green + Pint clean + manual smoke in dev + log scan via Cloud CLI.

**What NOT to do:**
- Don't run prod operations (`supabase db push` against prod, `gh pr create` targeting `production`, force push, etc.) without explicit user approval.
- Don't `--no-verify` on commits. If the hook fails, fix the underlying issue.
- Don't skip the Pest TDD cycle ("I'll just write the code, tests later"). The plan is structured failing-test-first for a reason.
- Don't restructure files the plan didn't ask you to touch.
- Don't add a Laravel migration file. The composer guard will reject it and you'll waste a cycle.

**Starting command:**

```bash
cd "/Users/joshuahunter/Herd/Side Street/backend"
git fetch
git pull
git checkout -b feat/enquiry-inbox development
git log --oneline -5  # confirm you're at the expected HEAD
```

Then read the plan file end-to-end before spawning the first subagent.

Begin with Task 0 (shared test helpers).

---

## Operator notes (don't paste — for the human kicking this off)

- **Estimated effort:** ~3-5 hours of agentic work. The slowest tasks are Task 2 (waits on `supabase db push`) and Tasks 14-15 (the queued job + submit wiring — most code, most assertions).
- **If using `/loop`:** invoke as `/loop 10m read the next pending task in docs/superpowers/plans/2026-05-27-enquiry-inbox.md and execute it via a fresh subagent` — the 10m interval gives Horizon-style supervision.
- **If executing in-line:** invoke `superpowers:executing-plans` and point it at the plan file. That skill batches with checkpoints.
- **Frontend handoff:** Task 27 creates `docs/superpowers/handoffs/2026-05-27-enquiry-inbox-frontend.md`. After the PR is open, switch to your frontend session and tell that Claude to consume the handoff doc.
- **Mode for bot-protection:** during dev rollout (Task 26+), keep `BOT_PROTECTION_MODE=shadow` for a week before flipping to `enforce`. Watch `bot_protection.shadow_reject` log lines.
