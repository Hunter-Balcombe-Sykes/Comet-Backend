# Handoff prompts — finish the launch-check suite (split into 3 runs)

The remaining launch-check work is split into **three independent prompts**. Paste ONE prompt — everything below its `━━━` divider — into a fresh Claude Code session; each block is self-contained.

**Recommended timing (sequencing decision, 2026-07-23):**
- **Prompt 1 (Tasks 4, 5, 7 + Vigil)** and **Prompt 2 (T3-Step-2 Pest.php triage)** — after the frontend E2E testing session, before prod cutover. Both are env-agnostic, so nothing blocks them. Prompt 2 is independent of Prompt 1 and can run in either order or in parallel.
- **Prompt 3 (Tasks 8 & 9, deployed-env)** — at prod cutover + baseline. They only WARN against the current dev env, so their first meaningful green run should be against the real prod target. **Depends on Prompt 1** — the runner they wire into must exist.

**Global DONE state — do NOT redo (shipped to origin/development @ a474737b, 2026-07-18):**
- **Task 1** — `scripts/launch-check/refresh-schema-snapshot.php` + `schema-snapshot.json`.
- **Task 2** — `Tests\Support\SchemaDrift\{Snapshot,SqliteIntrospector,DriftComparator}` + unit tests.
- **Task 3** — `tests/Feature/Architecture/SchemaDriftGuardTest.php` gate + `schema-drift-baseline.json` (baseline-first: all current drift grandfathered).
- **Task 6** — CI supply-chain job (gitleaks + npm audit) + `.gitleaks.toml` allowlist + wrangler v4 bump.
- A dedicated `schema-drift` CI job.

**Plan (source of per-task detail + complete code, all three prompts):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md` — 9 tasks / 8 probe groups (A schema-drift, B runtime smoke, C supabase-check, D supply-chain, E drill-freshness, F deployed-env config, G deployed runtime health, H manual residue).

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

## Prompt 1 — Core suite: Tasks 4, 5, 7 + Vigil

*(run after the frontend testing session, before cutover — env-agnostic, nothing blocks it)*

━━━

Execute the core repeatable portion of the launch-check suite — the pre-pilot runtime/config assurance toolkit under `scripts/launch-check/`. The schema-drift gate (Group A) and the supply-chain CI job already shipped; build the runtime smoke probe, the supabase-check probe, the runner that unifies them, and wire in the Vigil security audit.

**Plan (per-task detail + complete code):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md`.

**Already DONE — do NOT redo (@ a474737b, 2026-07-18):** Tasks 1/2/3 (schema-drift gate + baseline), Task 6 (supply-chain CI), a dedicated `schema-drift` CI job.

### DO — in this order, one task at a time
1. **Task 4 — Group B runtime smoke** (`scripts/launch-check/smoke.sh`). Pure shell/curl vs the live env (default `https://dev-api.partna.au`).
2. **Task 5 — Group C** (`scripts/launch-check/supabase-check.sh`): RLS coverage + security advisors + snapshot staleness. **Build the base probe only** — the full repo↔`schema_migrations` migration set-diff is a Task 9 extension (Prompt 3); leave a clear extension point/TODO for it.
3. **Task 7 — Group E + runner**: `drill-freshness.sh` (read-only; EXPECTED to fail until the pre-pilot drill session runs — routine runs use `--only` without `drills`), the runner `launch-check.sh`, `MANUAL-CHECKLIST.md`, `README.md`. **Build the runner so Groups F/G (Tasks 8/9, Prompt 3) slot in later with zero rework** — invoke each group's script/command if present, warn-skip cleanly if absent.

### Vigil wiring
`php artisan vigil:audit` (the `filastudio/laravel-vigil` package — security/config checks: filesystem, `cfg.env`/`cfg.cors`/`cfg.session`, `http.headers`, `dep.composer_audit`, `ext.hardcoded_secrets`, `ext.debug_routes`, `ext.telescope_debugbar`) is ALREADY a CI gate: `APP_DEBUG=false php artisan vigil:audit --fail-on=critical`, run right after `php artisan checkpoint:scan`. As part of this run:
- **Add it to the runner** (`scripts/launch-check/launch-check.sh`) as a security-audit probe group so a single suite run includes it — invoke `APP_DEBUG=false php artisan vigil:audit --fail-on=critical` and surface its result in the report (note that env-dependent checks like APP_DEBUG/headers are only meaningful against the deployed env — the CI gate covers filesystem/secrets/dependency checks).
- **Run it now** and confirm the new `scripts/launch-check/` files + `.env.example` introduce NO critical findings (watch `cfg.env` / `ext.hardcoded_secrets` — keep `.env.example` values empty placeholders; the gitignored `scripts/launch-check/.env` must never be committed).
- Fix any critical finding at root; only regenerate the integrity baseline (`php artisan vigil:baseline`) if legitimately warranted — never to mask a real finding. Keep both `checkpoint:scan` and `vigil:audit` green.

### Execution mode
Use the **superpowers:subagent-driven-development** skill. Per task: fresh implementer subagent (`model: sonnet`) → independent reviewer subagent (`model: sonnet`) → fix loop → mark complete in the ledger. After all tasks: one final whole-branch review (`model: opus`), then **superpowers:finishing-a-development-branch**. Keep a durable ledger at `.superpowers/sdd/progress.md`. **Set `model` explicitly on every subagent** — they inherit the session model otherwise.

### Workspace — ISOLATE (the shared checkout is contended by other live sessions)
Work in a plain git worktree off `origin/development`, NOT the main checkout:
`git worktree add ../backend-wt/launch-check-core -b launch-check-core origin/development`, then its OWN `composer install` + copy `.env` from the main checkout. (Harness-symlinked worktrees break feature tests; a plain worktree + own install runs the suite green.) **Stage EXPLICIT paths only — NEVER `git add -A` / `git add .`** (other sessions' uncommitted WIP must not be swept in). Verify each commit's file list before committing.

### Tooling + environment gotchas
- Focused tests: `php artisan test --filter=X`. Full suite: bare `composer test` (`composer test -- --filter=X` does NOT work — composite script). Style: `vendor/bin/pint` (NOT `php artisan pint`).
- **Task 5 (Supabase):** use the Supabase MCP (`execute_sql`, `get_advisors`) against dev ref `glncumufgaqcmqhzwrxm` — **NEVER** the prod ref `edplucmvkcnokyygxqsb`. No PAT needed.

### Blocker gates — STOP and ask Josh
- Any **RLS-DISABLED** finding in Task 5 → report it; do NOT write RLS migrations.
- **Never push** (Josh pushes). Present integration options via finishing-a-development-branch and let Josh decide.

Read each task's section in the plan before dispatching its implementer (the subagent-driven skill's `task-brief` script extracts it). The plan sections carry complete code and step-by-step verification.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

## Prompt 2 — Schema-drift T3-Step-2 Pest.php triage

*(independent of Prompt 1 — run after the testing session, before cutover; can go in parallel with or before Prompt 1)*

━━━

Execute the **T3-Step-2 triage** — the deferred, high-blast-radius half of the launch-check schema-drift work. Tighten `tests/Pest.php` for the 5 constraint-bound tables (`site.platform_connections`, `site.sites`, `site.design_kits`, `site.menus`, `core.users`) to mirror the Postgres NOT NULL / CHECK constraints, fix any factory/test fallout **forward** (never loosen a constraint back), then regenerate the drift baseline: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`. This un-grandfathers `platform_connections.payload` NOT NULL + `last_refresh_status` CHECK — the two prod incidents.

**Plan (detail + complete code):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md` (the T3-Step-2 section).

**Already DONE — do NOT redo (@ a474737b, 2026-07-18):** the schema-drift gate itself (Tasks 1/2/3) already ships with a baseline-first `schema-drift-baseline.json` that grandfathers all current drift. This triage tightens `Pest.php` and un-grandfathers the two incidents — it does not rebuild the gate.

### Execution mode
This is **ONE careful unit**, not a multi-task fan-out. Implement it, then get an independent reviewer subagent (`model: sonnet`) pass and fix loop, then **superpowers:finishing-a-development-branch**. Keep a ledger note at `.superpowers/sdd/progress.md`. Set `model` explicitly on any subagent.

### Workspace — ISOLATE
`git worktree add ../backend-wt/schema-drift-triage -b schema-drift-triage origin/development`, then its OWN `composer install` + copy `.env` from the main checkout. **Stage EXPLICIT paths only — NEVER `git add -A` / `git add .`** Verify each commit's file list before committing.

### Tooling + environment gotchas
- **Tests run SQLite, prod is Postgres.** Verify each tightened constraint against the actual DDL in `supabase/migrations/`, not just a passing suite — CHECK/NOT NULL drift is exactly what this triage exists to catch.
- Full suite: bare `composer test`. Style: `vendor/bin/pint`.
- Baseline regen is `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest` — run it **only after** the tightened tests are green and all fallout is fixed forward. Regenerating with red tests bakes the breakage into the baseline.

### Blocker gate — STOP and ask Josh
- This is **high-blast-radius** — `tests/Pest.php` is the shared test bootstrap. Fix test-data fallout **forward**; never loosen a constraint back to make a test pass. If it cascades into many failures or feels larger than expected, **pause and report** before pushing further.
- **Never push** (Josh pushes). Present integration options via finishing-a-development-branch and let Josh decide.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

## Prompt 3 — Deployed-env groups: Tasks 8 & 9

*(run at prod cutover + baseline — first meaningful green run is against the real prod target; **depends on Prompt 1**)*

━━━

Execute the deployed-env probes of the launch-check suite — Groups F and G, which assert a real deployed environment's config + runtime health. Run this **at prod cutover**: against the current dev env they only WARN (`QUEUE_CONNECTION=sync` + 0 Horizon masters), so their first meaningful green run is against the real prod target. **Depends on Prompt 1** — the runner (`scripts/launch-check/launch-check.sh`) they wire into must already exist.

**Plan (per-task detail + complete code):** `docs/superpowers/plans/2026-07-02-launch-check-suite.md`.

**Already DONE — do NOT redo:** Prompt 1 (Tasks 4/5/7 + Vigil + the runner), Prompt 2 (schema-drift triage), and the 07-18 baseline (Tasks 1/2/3/6).

### DO — in this order, one task at a time
1. **Task 8 — Group F deployed-env config**: `App\Support\LaunchCheck\EnvManifest` + `App\Console\Commands\LaunchCheckEnvCommand` (`launch-check:env`) + `scripts/launch-check/env-check.sh`.
2. **Task 9 — Group G deployed runtime health**: `scripts/launch-check/edge-check.sh` + `App\Console\Commands\LaunchCheckRuntimeCommand` (`launch-check:runtime`) + `scripts/launch-check/runtime-health.sh`. **Task 9 Step 4** also extends Prompt 1's `supabase-check.sh` (Task 5) with a full repo↔`schema_migrations` migration set-diff — fill the extension point Prompt 1 left.
3. **Wire Groups F/G into the runner** (`launch-check.sh`) via the invoke-if-present / warn-skip extension points Prompt 1 built.

### Execution mode
Use the **superpowers:subagent-driven-development** skill. Per task: fresh implementer subagent (`model: sonnet`) → independent reviewer subagent (`model: sonnet`) → fix loop → mark complete in the ledger. After all tasks: one final whole-branch review (`model: opus`), then **superpowers:finishing-a-development-branch**. Keep the ledger at `.superpowers/sdd/progress.md`. **Set `model` explicitly on every subagent.**

### Workspace — ISOLATE
`git worktree add ../backend-wt/launch-check-deployed -b launch-check-deployed origin/development`, then its OWN `composer install` + copy `.env`. **Stage EXPLICIT paths only — NEVER `git add -A` / `git add .`** Verify each commit's file list before committing.

### Tooling + environment gotchas
- **Deployed checks:** use the `cloud` CLI at `~/.composer/vendor/bin/cloud` (`cloud command:run development --cmd="…"`). The deployed dev env runs `QUEUE_CONNECTION=sync` + 0 Horizon masters, so Tasks 8/9 will pilot-WARN on Horizon/queue — that's EXPECTED (pilot warns, launch fails), not a failure.
- **Task 8:** read `config()`, NOT `env()` (config:cache nulls env on the deployed env). Verify the integration key paths (`services.supabase.*`, `services.cloudflare.*`, `nightwatch.*`) against `config/services.php` + `config/partna.php` — a wrong key reads null and false-fails. Assert PRESENCE for secrets, exact VALUE only for the launch-config keys.
- **Task 9:** the edge probe needs a real `<handle>.partna.au` via `LAUNCH_CHECK_HANDLE` — build it to warn-and-skip if absent, and ask Josh for a canary sitepage handle. Verify the Horizon `MasterSupervisorRepository` binding against the installed Horizon version.
- **Task 5 migration set-diff:** Supabase MCP (`execute_sql`) against the target ref — dev `glncumufgaqcmqhzwrxm` for rehearsal, the prod ref `edplucmvkcnokyygxqsb` only for the real cutover run.

### Blocker gates — STOP and ask Josh
- Provide the canary handle (`LAUNCH_CHECK_HANDLE`) before Task 9's edge probe can run for real.
- Any **RLS-DISABLED** finding surfaced by the migration set-diff / advisors → report it; do NOT write RLS migrations here.
- **Never push** (Josh pushes). Present integration options via finishing-a-development-branch and let Josh decide.

Read each task's section in the plan before dispatching its implementer (the subagent-driven skill's `task-brief` script extracts it). The plan sections carry complete code and step-by-step verification.
