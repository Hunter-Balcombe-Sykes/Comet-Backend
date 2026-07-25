# launch-check — runtime & config assurance suite

Counterpart to `scripts/audit/` (static code): verifies the **running system**.

| Group | What | How to run alone |
|---|---|---|
| A | Schema drift: SQLite test schema vs live dev Postgres snapshot | `php artisan test --filter=SchemaDriftGuardTest` (runs in CI via composer test) |
| B | Runtime smoke: .env exposure, debug leakage, telescope/horizon gates, 404-not-403, throttle | `scripts/launch-check/smoke.sh [--rate-limit]` |
| C | Supabase: RLS on, security advisors, snapshot staleness | `scripts/launch-check/supabase-check.sh` |
| D | Supply chain: composer audit + worker npm audit (+ gitleaks in CI) | via runner or CI |
| E | Security audit (Vigil): filesystem/secrets/dependency checks, same gate as CI (`--fail-on=critical`) | `APP_DEBUG=false php artisan vigil:audit --fail-on=critical` |
| F | Drill-log freshness: each failure-mode drill has a log postdating changes to its drilled path (read-only — never runs a drill; see `docs/runbooks/drills/`) | `scripts/launch-check/drill-freshness.sh` |
| G | Deployed env config: required vars present + APP_DEBUG/APP_ENV/queue/cache correct on the running Cloud env (via `cloud command:run`) | `scripts/launch-check/env-check.sh` |
| H | Manual residue | `MANUAL-CHECKLIST.md` |

Full run: `scripts/launch-check/launch-check.sh` → `audits/launch-check/<date>/REPORT.md`

**Group G (`env`) is opt-in only** — it needs the `cloud` CLI and a reachable, deployed
Laravel Cloud env, neither of which a plain local run can assume. It is not in the
default group set; run it explicitly with `scripts/launch-check/launch-check.sh --only env`
(or `scripts/launch-check/env-check.sh` directly). Defaults to `--env development --target
pilot`; `--env production` requires `LAUNCH_CHECK_CONFIRM_PROD=1` and is otherwise refused.

**After any schema change:** `supabase db push`, then `php scripts/launch-check/refresh-schema-snapshot.php`, commit the snapshot. If the drift gate then fails, mirror the constraint in `tests/Pest.php` (preferred) or regenerate the baseline (`SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`).

Setup: `cp .env.example .env` in this dir, add a Supabase PAT.
