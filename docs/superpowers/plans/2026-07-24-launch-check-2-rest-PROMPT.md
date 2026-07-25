# Handoff prompt 2 of 3 — the rest of the launch-check suite (build once current work is done, before cutover)

Supersedes the build portion of `2026-07-18-launch-check-remaining-PROMPT.md` (the T3 triage is now prompt 1; DAST + failure drills are excluded — separate later work). Paste everything below the line into a fresh session.

---

Build the remaining launch-check probes + runner (the pre-pilot runtime/config assurance toolkit under `scripts/launch-check/`). Group A (schema-drift gate) and Group D (supply-chain CI) already shipped. Build Groups B, C, E, F, G + the runner + wire in Vigil.

**Plan (per-task detail + complete code):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md` — 9 tasks / 8 groups.

## Already DONE — do NOT redo (on origin/development)
Tasks 1, 2, 3 (schema-drift gate + baseline), Task 6 (supply-chain CI + wrangler v4), the dedicated `schema-drift` CI job. (The T3-Step-2 triage is prompt 1 — assume it's either done or independent of this.)

## DO — one task at a time
1. **Task 4 — Group B runtime smoke** (`scripts/launch-check/smoke.sh`): shell/curl vs the live env (default `https://dev-api.partna.au`) — `.env` not reachable, no stack traces (APP_DEBUG off), telescope/horizon gated, 404-not-403, throttle fires, security headers (WARN).
2. **Task 5 — Group C** (`scripts/launch-check/supabase-check.sh`): RLS coverage + security advisors + snapshot staleness (+ the migration set-diff added in Task 9 Step 4).
3. **Task 7 — Group E + runner**: `drill-freshness.sh` (read-only; EXPECTED to fail until the drill session runs — routine runs use `--only` without `drills`), the runner `launch-check.sh`, `MANUAL-CHECKLIST.md`, `README.md`.
4. **Task 8 — Group F deployed-env config**: `App\Support\LaunchCheck\EnvManifest` + `App\Console\Commands\LaunchCheckEnvCommand` (`launch-check:env`) + `scripts/launch-check/env-check.sh`.
5. **Task 9 — Group G deployed runtime health**: `scripts/launch-check/edge-check.sh` + `App\Console\Commands\LaunchCheckRuntimeCommand` (`launch-check:runtime`) + `scripts/launch-check/runtime-health.sh`. Step 4 also extends Task 5's `supabase-check.sh` with a full repo↔`schema_migrations` migration set-diff.

## Also — wire in the Vigil security audit
`php artisan vigil:audit` (the `filastudio/laravel-vigil` package) is ALREADY a CI gate (`APP_DEBUG=false php artisan vigil:audit --fail-on=critical`, after `php artisan checkpoint:scan`). Add it to the runner (`launch-check.sh`, Task 7) as a security-audit probe group, run it now to confirm the new `scripts/launch-check/` files + `.env.example` introduce NO critical findings (watch `cfg.env` / `ext.hardcoded_secrets`; keep `.env.example` empty; `scripts/launch-check/.env` must never be committed). Keep both `checkpoint:scan` and `vigil:audit` green.

## Note on the deployed-env probes (Tasks 8 + 9)
Against dev they will pilot-WARN (the deployed dev env runs `QUEUE_CONNECTION=sync` + 0 Horizon — expected, not a failure). You BUILD them here; their full launch verdict happens at production cutover (`--target=launch` against the real prod env) — that's prompt 3. Build them to warn-skip cleanly when a prereq is absent (e.g. Task 9's edge probe needs a real `<handle>.partna.au` via `LAUNCH_CHECK_HANDLE` — ask Josh for a dev canary handle).

## Execution mode
superpowers:subagent-driven-development. Per task: fresh implementer (`model: sonnet`) → independent reviewer (`model: sonnet`) → fix loop → ledger. After all tasks: final whole-branch review (`model: opus`), then superpowers:finishing-a-development-branch. Ledger at `.superpowers/sdd/progress.md`. **Set `model` explicitly on every subagent.**

## Workspace — ISOLATE
Plain git worktree off origin/development: `git worktree add ../backend-wt/launch-check-rest -b launch-check-rest origin/development`, own `composer install` + copy `.env`. Stage EXPLICIT paths only — NEVER `git add -A`. Verify each commit's file list.

## Tooling + environment gotchas
- Focused: `php artisan test --filter=X`. Full: bare `composer test` (`composer test -- --filter` does NOT work). Style: `vendor/bin/pint` (NOT `php artisan pint`).
- Supabase (Task 5 / migration diff): use the Supabase MCP (`execute_sql`, `get_advisors`) against dev ref `glncumufgaqcmqhzwrxm` — NEVER prod `edplucmvkcnokyygxqsb`. No PAT needed.
- Deployed checks (Tasks 8/9): `cloud` CLI at `~/.composer/vendor/bin/cloud` (`cloud command:run development --cmd="…"`).
- Task 8: read `config()`, NOT `env()` (config:cache nulls env on the deployed env). Verify the integration key paths (`services.supabase.*`, `services.cloudflare.*`, `nightwatch.*`) against `config/services.php` + `config/partna.php` — a wrong key reads null and false-fails. Assert PRESENCE for secrets, exact VALUE only for launch-config keys.
- Task 9: verify the Horizon `MasterSupervisorRepository` binding against the installed Horizon version.

## Blocker gates — STOP and ask Josh
- Any RLS-DISABLED finding (Task 5) → report; do NOT write RLS migrations.
- Never push (Josh pushes). Present via finishing-a-development-branch.

Read each task's plan section before dispatching its implementer (the skill's `task-brief` extracts it) — the plan carries complete code + step-by-step verification.
