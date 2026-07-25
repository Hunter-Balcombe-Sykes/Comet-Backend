# Handoff prompt 3 of 3 — production-cutover launch gate (RUN the suite against prod)

Use at production cutover, AFTER prompts 1 + 2 have built the full launch-check suite. This is a RUN/runbook prompt, not a build — it points the suite at the real prod env to gate the launch. Paste everything below the line into a fresh session.

---

Run the launch-check suite against the PRODUCTION environment as the cutover gate, and work the manual-residue checklist. The suite (`scripts/launch-check/`) must already be fully built (schema-drift gate + smoke + supabase-check + runner + deployed-env config + runtime health + Vigil).

## Cutover context (read first)
Today, prod Laravel Cloud is STOPPED and prod Supabase (`edplucmvkcnokyygxqsb`) is PAUSED; the **development** env serves BOTH `dev-api.partna.au` and `api.partna.au`, backed by the DEV Supabase. "Production cutover" = unpause prod Supabase + prod Laravel Cloud env, re-baseline the prod DB (it's still on the OLD pre-standalone schema — a major gated operation, NOT a single migration), then cut `api.partna.au` to the real prod env. **You do NOT perform the cutover** (unpause / re-baseline / DNS) — Josh drives those. This prompt runs the READ-ONLY assurance gate around them.

## Run the suite against prod (each step: confirm with Josh before pointing at prod)
1. **Schema snapshot vs prod** — the prod DB is a fresh re-baseline, so the dev snapshot doesn't apply. Regenerate against prod (`PROJECT_REF=edplucmvkcnokyygxqsb` in `refresh-schema-snapshot.php`, or the Supabase MCP against that ref) and eyeball that prod's NOT NULL/CHECK set matches expectations. READ-only.
2. **Task 8 deployed-env config — the real gate**: `cloud command:run production --cmd="launch-check:env --target=launch"`. At `--target=launch` it FAILS (not warns) on `APP_DEBUG=on`, `QUEUE_CONNECTION≠redis` (must not be `sync`), `CACHE_STORE≠redis`, or any missing required secret. This catches the deployed-config incidents.
3. **Task 9 runtime health — `--target=launch` against prod**: Horizon masters actually running (not 0), queue not backing up, `failed_jobs` sane, Redis reachable, media disk writable, scheduler wired; edge probe against a REAL prod `<handle>.partna.au` (200 + `cf-cache-status` HIT + alias 301 + TLS ≥ 21d).
4. **Task 5 supabase-check vs prod**: RLS coverage + security advisors on the fresh prod project. Any **RLS-DISABLED** finding → STOP, report to Josh; do NOT write RLS migrations.
5. **Task 4 smoke vs prod**: `smoke.sh --base-url https://api.partna.au` (prod, not dev-api) — no stack traces, telescope/horizon gated, 404-not-403, throttle, headers.
6. **Full runner + report**: `launch-check.sh` with prod targets → `audits/launch-check/<date>/REPORT.md`, plus `vigil:audit --fail-on=critical` and the Group H manual-residue checklist: PITR/backups + one real restore drill, Cloudflare dashboard (Cache Deception Armor ON, edge rate-limiting, SSL Full-strict), Supabase dashboard (SSL enforced, network restrictions, auth rate limits, custom SMTP), rollback path per migration, runbooks, Nightwatch verified firing, DAST pass.

## PROD-SAFETY RULES (non-negotiable)
- Prod Supabase ref is `edplucmvkcnokyygxqsb` — the OPPOSITE of dev `glncumufgaqcmqhzwrxm`. Point snapshot/supabase-check reads at prod ONLY here, and confirm each with Josh first.
- The suite is READ-ONLY assurance. It must NOT mutate prod, run migrations, or perform the cutover. Every prod-facing command is a check.
- **Fresh-prod-DB caveat:** after the prod re-baseline, `app_backend` is created NOLOGIN (fail-closed) — Josh must run `ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret>'` in the SQL editor before the app connects; Laravel Cloud `DB_USERNAME` must be `app_backend.edplucmvkcnokyygxqsb` (Supavisor tenant prefix), port 5432.
- **Never push. Never mutate prod without explicit per-action confirmation.** Josh drives the cutover; you run and report the gate.

## Gate outcome
Report a clear PASS/FAIL per group. A FAIL on Task 8 (config) or Task 9 (runtime) or an RLS-DISABLED in Task 5 is a launch blocker — surface it; don't soften a check to make it green. Deliver the `audits/launch-check/<date>/REPORT.md` + the outstanding manual items as the go/no-go for cutover.
