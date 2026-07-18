# Handoff prompt — finish the launch-check suite (deferred groups + triage)

Paste everything below the line into a fresh Claude Code session in this repo.

---

Execute the remaining tasks of the launch-check suite — the pre-pilot runtime/config assurance toolkit under `scripts/launch-check/`. Group A (schema-drift gate) and the supply-chain CI job already shipped; finish the deployed-env probes, the runner, and the deferred schema-drift triage.

**Plan (source of per-task detail + complete code):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md` — 9 tasks / 8 probe groups (A schema-drift, B runtime smoke, C supabase-check, D supply-chain, E drill-freshness, F deployed-env config, G deployed runtime health, H manual residue).

## Already DONE — do NOT redo (shipped to origin/development @ a474737b, 2026-07-18)
- **Task 1** — `scripts/launch-check/refresh-schema-snapshot.php` + `schema-snapshot.json`.
- **Task 2** — `Tests\Support\SchemaDrift\{Snapshot,SqliteIntrospector,DriftComparator}` + unit tests.
- **Task 3** — `tests/Feature/Architecture/SchemaDriftGuardTest.php` gate + `schema-drift-baseline.json` (baseline-first: all current drift grandfathered).
- **Task 6** — CI supply-chain job (gitleaks + npm audit) + `.gitleaks.toml` allowlist + wrangler v4 bump.
- A dedicated `schema-drift` CI job.

## DO now — in this order, one task at a time
1. **Task 4 — Group B runtime smoke** (`scripts/launch-check/smoke.sh`). Pure shell/curl vs the live env (default `https://dev-api.partna.au`).
2. **Task 5 — Group C** (`scripts/launch-check/supabase-check.sh`): RLS coverage + security advisors + snapshot staleness.
3. **Task 7 — Group E + runner** : `drill-freshness.sh` (read-only; EXPECTED to fail until the pre-pilot drill session runs — routine runs use `--only` without `drills`), the runner `launch-check.sh`, `MANUAL-CHECKLIST.md`, `README.md`.
4. **Task 8 — Group F deployed-env config**: `App\Support\LaunchCheck\EnvManifest` + `App\Console\Commands\LaunchCheckEnvCommand` (`launch-check:env`) + `scripts/launch-check/env-check.sh`.
5. **Task 9 — Group G deployed runtime health**: `scripts/launch-check/edge-check.sh` + `App\Console\Commands\LaunchCheckRuntimeCommand` (`launch-check:runtime`) + `scripts/launch-check/runtime-health.sh`. Task 9 Step 4 also extends Task 5's `supabase-check.sh` with a full repo↔`schema_migrations` migration set-diff.
6. **THEN, as a SEPARATE careful unit — the T3-Step-2 triage**: tighten `tests/Pest.php` for the 5 constraint-bound tables (`site.platform_connections`, `site.sites`, `site.design_kits`, `site.menus`, `core.users`) to mirror the Postgres NOT NULL / CHECK constraints, fix any factory/test fallout, then regenerate the baseline: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`. This un-grandfathers `platform_connections.payload` NOT NULL + `last_refresh_status` CHECK (the two prod incidents).

## Execution mode
Use the **superpowers:subagent-driven-development** skill. Per task: fresh implementer subagent (`model: sonnet`) → independent reviewer subagent (`model: sonnet`) → fix loop → mark complete in the ledger. After all tasks: one final whole-branch review (`model: opus`), then **superpowers:finishing-a-development-branch**. Keep a durable ledger at `.superpowers/sdd/progress.md`. **Set `model` explicitly on every subagent** — they inherit the session model otherwise.

## Workspace — ISOLATE (the shared checkout is contended by other live sessions)
Work in a plain git worktree off `origin/development`, NOT the main checkout:
`git worktree add ../backend-wt/launch-check-rest -b launch-check-rest origin/development`, then its OWN `composer install` + copy `.env` from the main checkout. (Harness-symlinked worktrees break feature tests; a plain worktree + own install runs the suite green.) **Stage EXPLICIT paths only — NEVER `git add -A` / `git add .`** (other sessions' uncommitted WIP must not be swept in). Verify each commit's file list before committing.

## Tooling + environment gotchas
- Focused tests: `php artisan test --filter=X`. Full suite: bare `composer test` (`composer test -- --filter=X` does NOT work — composite script). Style: `vendor/bin/pint` (NOT `php artisan pint`).
- **Supabase (Task 5 / migration set-diff):** use the Supabase MCP (`execute_sql`, `get_advisors`) against dev ref `glncumufgaqcmqhzwrxm` — **NEVER** the prod ref `edplucmvkcnokyygxqsb`. No PAT needed.
- **Deployed checks (Tasks 8/9):** use the `cloud` CLI at `~/.composer/vendor/bin/cloud` (`cloud command:run development --cmd="…"`). The deployed dev env runs `QUEUE_CONNECTION=sync` + 0 Horizon masters, so Tasks 8/9 will pilot-WARN on Horizon/queue — that's EXPECTED (pilot warns, launch fails), not a failure.
- **Task 8:** read `config()`, NOT `env()` (config:cache nulls env on the deployed env). Verify the integration key paths (`services.supabase.*`, `services.cloudflare.*`, `nightwatch.*`) against `config/services.php` + `config/partna.php` — a wrong key reads null and false-fails. Assert PRESENCE for secrets, exact VALUE only for the launch-config keys.
- **Task 9:** the edge probe needs a real `<handle>.partna.au` via `LAUNCH_CHECK_HANDLE` — build it to warn-and-skip if absent, and ask Josh for a dev canary sitepage handle. Verify the Horizon `MasterSupervisorRepository` binding against the installed Horizon version.

## Blocker gates — STOP and ask Josh
- Any **RLS-DISABLED** finding in Task 5 → report it; do NOT write RLS migrations.
- The **T3-Step-2 Pest.php triage** is high-blast-radius (shared test bootstrap). Fix test-data fallout FORWARD — never loosen a constraint back. If it cascades into many failures or feels larger than expected, pause and report before pushing further.
- **Never push** (Josh pushes). Present integration options via finishing-a-development-branch and let Josh decide.

Read each task's section in the plan before dispatching its implementer (the subagent-driven skill's `task-brief` script extracts it). The plan sections carry complete code and step-by-step verification.
