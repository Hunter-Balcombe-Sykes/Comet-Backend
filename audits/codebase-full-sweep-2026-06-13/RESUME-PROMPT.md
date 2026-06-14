# Resume prompt — full-sweep audit-fix run (paste as the first message in a fresh session)

Everything from `=== PROMPT START ===` to the end is the copy-paste prompt.

```
=== PROMPT START ===

You are resuming an autonomous, BUNDLE-AWARE audit-fix run that is already partly done.
Continue it to the end WITHOUT re-asking any of the decisions already made below. Follow the
bundled runbook `scripts/audit/PROMPT-audit-fix-runner-bundled.md` for the per-bundle mechanics,
with the overrides in this prompt taking precedence.

## PARAMETERS
AUDIT_FILE:         audits/codebase-full-sweep-2026-06-13/audit-2026-06-13-CONSOLIDATED.md
INTEGRATION_BRANCH: development
WORK_BRANCH:        audit-fix/consolidated-<today's date YYYY-MM-DD>   (create fresh off origin/development)

## ONE-TIME SETUP
1. `git fetch origin`. Confirm a clean working tree (if dirty, commit/stash unrelated WIP first — a prior
   session already committed all audit tooling + output, so the tree should be clean).
2. `git checkout -B WORK_BRANCH origin/development` (base off the REMOTE; local lags; default branch is production).
3. The baseline is ALREADY GREEN on origin/development: `composer test` passes (≈2149 passing, 108 skipped,
   0 failed). The migration-safety guard and 3 formerly-failing bootstrap tests were fixed in earlier commits.
   The push gate is therefore: **composer test stays fully green (0 failed)** — never push red.
4. Create a TodoWrite/Task list with one item per REMAINING bundle (see list below).

## ALREADY DECIDED — DO NOT RE-ASK (these were resolved in the prior session)
- **Runner:** bundled (this file's audit is a CONSOLIDATED bundle file). Unit of work = the bundle.
- **Run mode:** RIP THROUGH the autonomous (git-pushable PHP) bundles in document order; SKIP every Rule-A
  bundle and ALL standalones — surface them only in the final summary. Do NOT stop to ask per Rule-A item.
- **Genuine guards still apply, but resolve them autonomously (no questions):** if a bundle hits a stale
  premise on a finding, OR a fix would break a *documented* contract / can't be made green, then DEFER that
  finding (or the whole bundle): leave its box `- [ ]`, append ` — _deferred <date>: <reason>_` after its line
  in AUDIT_FILE, note it in the final summary, and CONTINUE to the next bundle. Only truly destructive/
  irreversible actions warrant stopping. Tick a finding only when its fix is implemented, reviewed, and green.
- **Contract-safety rule:** for changes to LIVE public endpoints/forms, prefer NON-BREAKING (e.g. new request
  fields nullable + enforce-when-present) over a required field the frontend may not send yet; note the
  "tighten later" follow-up. Never make analytics `subdomain` required (breaks the documented site_id-OR-
  subdomain beacon contract — that's why SEC-1 was deferred).
- **Per-bundle process:** premise-verify each finding (read its `→ audit-…-<lens>.md` backref + the cited
  code) → (if `plan=` set, run a plan subagent) → implement the whole bundle via ONE subagent (use the
  bundle's `impl=` model) → `vendor/bin/pint` on changed files only → review subagent using the bundle's
  `review=` model (ALWAYS run a real review subagent for `review=opus` bundles — do not inline-review them)
  → `composer test` (full, in THIS checkout, never a worktree) → tick findings + the `Bundle Bn complete`
  box in AUDIT_FILE → ONE commit → fetch→rebase→push to development → next bundle.
- Commit footer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## LESSONS FROM THE PRIOR SESSION — APPLY THESE
- **Jobs + afterCommit:** never add a typed `public bool $afterCommit` to a Queueable JOB (conflicts with
  the trait's untyped property → output-less FATAL at class composition, kills the whole suite with no Pest
  output). Use `->afterCommit()` on the dispatch site instead.
- **Policy resolution:** `authorizeForUser($actor,$ability,$resource)` resolves the policy from `$resource`'s
  CLASS (its `Gate::policy()` registration), not from where you define the method. Put a staff-actor ability
  on the policy registered for the resource (e.g. a `User` resource → `UserSelfPolicy`). TEST policies THROUGH
  the Gate (`Gate::forUser($actor)->allows/inspect(...)`), never via `new SomePolicy()->ability()` (that
  bypasses resolution and hides mis-wiring).
- **Staff-controller tests:** do NOT write full-HTTP `actingAsStaff()->postJson(...)` tests for staff routes —
  they need the `audit.staff_audit_log` SQLite stand-in + full AAL2/admin middleware and fail on setup. Test
  the policy ability via the Gate, or invoke the controller directly with `$request->attributes->set('partna_staff', $staff)`
  (mirror `StaffUserControllerIndexShowTest` / the `*PolicyEnforcementTest` files).
- **Migrations:** `CONCURRENTLY` index ops go outside any transaction; CHECK/FK constraints use `NOT VALID`
  then `VALIDATE` (CONVENTIONS.md §1/§2). Editing already-applied-on-dev migration files is safe.
- **Audit checkoff:** flip `- [ ] **<ID>** ·` → `- [x]` per finding + the bundle box; the IDs are unique so
  matching `[ ] **<ID>** ·` works.

## STATE — what's DONE vs REMAINING
DONE & pushed (boxes already ticked; SKIP these): baseline (MIG-1/MIG-4 + 3 tests), B1 (EDGE-4; EDGE-5 stale),
B3, B5, B6, B7 (SEC-3/6/11), B9.

DEFERRED (leave alone — boxes intentionally open, already annotated): EDGE-2 (B1), SEC-1 (B7).

SKIP — Rule-A (do NOT implement; list them in the final summary for human handling):
  B2 (Cloudflare Worker), B4 (InstagramConnectJob — media-mirror + Horizon restart), B8 (Supabase MFA/auth
  hook), B16 (ProcessImageVariantsJob — media-processing), B25 (SEM-1 needs a DB backfill), B26 (Worker +
  wrangler.toml), and ALL standalones S1–S8.
  Note: B16 and B25 each have a git-pushable subset (everything except the one risky finding) — you MAY do
  those subsets if straightforward, else skip and note.

REMAINING autonomous bundles to WORK, in document order:
  B10 (observer cache fan-out [@10k]), B11 (notification-job idempotency), B12 (silent-success observability),
  B13 (fail-open middleware + log hygiene), B14 (.env.example drift), B15 (config fail-safe + hardcoded→config;
  coordinate CFG-9 queue names with B4's untouched state), B17 (API Resource discipline; API-6 overlaps the
  already-done StaffUserController — re-verify), B18 (API pagination/error contract — flag client-visible
  changes; pagination is a potential breaking change, keep non-breaking or note), B19 (CORS fixes), B20
  (caching jitter/lock — GS-1 no-raw-Cache:: standard), B21 (transaction pgsql-contract + concurrency;
  LIFE-5 is a real concurrency bug; review=opus), B22 (code dedup + IP-hash), B23 (untested policies — write
  via the Gate per the lesson above), B24 (policy edge-cases + job-path tests; TEST-4 touches
  ProcessImageVariantsJob — test-only is fine, but if it requires changing the media job, defer that finding),
  B27 (model cleanup + job backoff; JOB-11 changes ProcessImage/VideoVariantsJob backoff — if that edit is a
  trivial property change keep it, else defer JOB-11 and do DINT-12/13/14).

## FINAL SUMMARY (when the remaining bundles are done/deferred)
Print a table: each remaining Bn → pushed ✅ / deferred ⏸ (reason) ; then the consolidated list of all
SKIPPED Rule-A bundles (B2/B4/B8/B16/B25/B26) + standalones (S1–S8) + the two earlier deferrals (EDGE-2,
SEC-1) for human handling. State the final `composer test` status and how many commits landed on development.

=== PROMPT END ===
```
