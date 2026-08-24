# Launch-Check Report — 2026-07-26

> ## ARCHIVED 2026-08-20 — mostly discharged since; residue moved off this list
>
> This was the 2026-07-26 pre-launch ops checklist. It is **25 days stale** and most of
> it was discharged by work that never came back to tick these boxes. Ticked as
> dispositions, with evidence:
>
> **Discharged (evidence on disk):**
> - Worker-kill / vendor-outage / Redis-down / backup-restore drills — all four ran, and
>   were **re-run 2026-08-05 and 2026-08-06**. Logs: `docs/runbooks/drills/logs/`
>   (see `2026-08-06-SUMMARY.md`).
> - Runbooks exist — `docs/runbooks/`: `db-pool-exhausted.md`, `queue-backed-up.md`,
>   plus the vendor-outage and Redis-down drill runbooks.
> - DAST pass — nine reports, 2026-07-30 → 2026-08-12, in `audits/dast/`.
> - Rollback path per migration — `docs/deploy/routine-deploy.md` carries it.
> - Re-run launch-check per migration/promote — wired into `routine-deploy.md` at three
>   fixed points (`LC-RERUN`, done 2026-07-31).
> - Prod environment decision — settled by the 2026-07-26 cutover: each env now stands on
>   its own (`CLAUDE.md` § Environments).
> - PITR / backups — Supabase org moved to **Pro on 2026-08-14**; daily managed backups
>   exist, with the R2 dump as belt-and-braces.
> - k6 load pass — Phase 1 ran 2026-07-31, PASS. Phases 2a/2b/3 deliberately unrun; that
>   gap is carried by `docs/superpowers/plans/2026-07-26-k6-load-testing.md`, not here.
>
> **⚠️ NOT discharged — carried forward, still live before customer #1:**
> - Cloudflare: **Cache Deception Armor ON**, **edge rate-limiting rules**, **SSL/TLS mode
>   Full (strict)**.
> - Supabase: **SSL enforcement ON**, **network restrictions** (⚠️ verify before setting —
>   it can lock out the app), **auth rate limits reviewed**, **custom SMTP**.
> - **Nightwatch fire-test** — throw a deliberate exception on dev and confirm the alert
>   actually arrives.
> - Frontend/monorepo audit coverage — still a separate effort.
>
> Those are dashboard actions only a human can take. They were **not verified** before
> archiving; archiving this file does not discharge them.

Groups: schema,smoke,supabase,supply,security,drills · Target: https://api.partna.au

## A · Schema-drift gate — PASS

```

  [30;42;1m PASS [39;49;22m[39m Tests\Feature\Architecture\SchemaDriftGuardTest[39m
  [32;1m✓[39;22m[90m [39m[90mit sqlite test schema mirrors dev Postgres constraints (or is basel[39m[90m…[39m [90m0.09s[39m  

  [90mTests:[39m    [32;1m1 passed[39;22m[90m (1 assertions)[39m
  [90mDuration:[39m [39m0.80s[39m
```

## B · Runtime smoke probe — PASS

```
PASS  .env not reachable (404)
PASS  composer.json not reachable (404)
PASS  .git/config not reachable (404)
PASS  storage/logs/laravel.log not reachable (404)
PASS  bogus route returns clean error body
PASS  /telescope gated (404)
PASS  /horizon gated (401)
PASS  health endpoint 200
PASS  header x-content-type-options present
PASS  header strict-transport-security present
PASS  header x-frame-options present
PASS  missing public resource → 404 (anti-enumeration)

SMOKE: all checks passed
```

## C · Supabase config — **FAIL**

```
FAIL  SUPABASE_ACCESS_TOKEN missing (scripts/launch-check/.env)
```

## D · Supply chain — **FAIL**

```
No security vulnerability advisories found.
# npm audit report

sharp  <0.35.0
Severity: high
sharp inherited vulnerabilities in libvips: CVE-2026-33327, CVE-2026-33328, CVE-2026-35590, CVE-2026-35591 - https://github.com/advisories/GHSA-f88m-g3jw-g9cj
fix available via `npm audit fix`
node_modules/sharp
  miniflare  <=0.0.0-fec45ed61 || 4.20250508.3 - 4.20260721.0
  Depends on vulnerable versions of sharp
  node_modules/miniflare
    wrangler  <=0.0.0-7ae5dd357 || 4.16.0 - 4.113.0
    Depends on vulnerable versions of miniflare
    node_modules/wrangler

3 high severity vulnerabilities

To address all issues, run:
  npm audit fix
```

## E · Security audit (Vigil) — PASS

```
Running Vigil Security Audit...

+------------------------+---------------------------------+----------+---------+----------------------------------------------------------------------------------+
| Check ID               | Title                           | Severity | Status  | Message                                                                          |
+------------------------+---------------------------------+----------+---------+----------------------------------------------------------------------------------+
| fs.public_folder       | Public Folder Security Check    | high     | failed  | Found 1 unexpected file(s) in public directory.                                  |
| fs.malicious_js        | Malicious JavaScript Detection  | critical | passed  | No malicious JavaScript patterns detected.                                       |
| fs.storage_dangerous   | Dangerous Files in Storage      | critical | passed  | No dangerous files found in public storage.                                      |
| fs.permissions         | File Permissions Check          | high     | warning | Found 1 path(s) with overly permissive file permissions.                         |
| fs.sensitive_exposure  | Sensitive Files Exposure Check  | critical | passed  | No sensitive files are publicly accessible.                                      |
| cfg.php_ini            | PHP Configuration Check         | high     | warning | Found 8 misconfigured PHP directive(s).                                          |
| cfg.env                | Environment Configuration Check | critical | warning | Found 2 environment configuration warning(s).                                    |
| cfg.session            | Session Configuration Check     | medium   | passed  | Session configuration follows security best practices.                           |
| cfg.cors               | CORS Configuration Check        | high     | passed  | CORS configuration is secure.                                                    |
| http.headers           | Security Headers Check          | high     | failed  | Failed to fetch application URL: cURL error 7: Failed to connect to localhost po |
| dep.composer_audit     | Composer Dependencies Audit     | critical | passed  | No known vulnerabilities found in Composer dependencies.                         |
| ext.hardcoded_secrets  | Hardcoded Secrets Detection     | critical | passed  | No hardcoded secrets detected.                                                   |
| ext.debug_routes       | Debug Routes Detection          | high     | warning | Found 3 route file(s) with potential debug code.                                 |
| ext.telescope_debugbar | Telescope & Debugbar Check      | high     | passed  | Debug tools are properly secured or not installed.                               |
+------------------------+---------------------------------+----------+---------+----------------------------------------------------------------------------------+

Summary: 8 passed, 2 failed, 4 warnings, 0 skipped
Security Score: 57/100

Scan completed in 1.78s
```

## F · Drill-log freshness — **FAIL**

```
FAIL  worker-kill — never drilled. Run docs/runbooks/drills/01-worker-kill.md
FAIL  vendor-outage — never drilled. Run docs/runbooks/drills/02-vendor-outage.md
FAIL  redis-down — never drilled. Run docs/runbooks/drills/03-redis-down.md
FAIL  backup-restore — never drilled. Run docs/runbooks/drills/04-backup-restore.md
```

## C-bis · Supabase config (MCP substitution for the missing PAT) — PASS on the blocker criterion

`supabase-check.sh` could not run: `SUPABASE_ACCESS_TOKEN` is absent (`scripts/launch-check/.env`
does not exist). Per the cutover prompt's Step 1 allowance ("or the Supabase MCP against that ref"),
the same two assertions were made against prod `edplucmvkcnokyygxqsb` via the Supabase MCP.

**RLS coverage — 66/66 tables have RLS enabled, 0 disabled.** No **RLS-DISABLED** finding, so the
launch blocker does not fire.

| schema | tables | RLS on | RLS off | FORCE RLS |
|---|---|---|---|---|
| analytics | 10 | 10 | 0 | 3 |
| audit | 6 | 6 | 0 | 1 |
| core | 14 | 14 | 0 | 8 |
| moderation | 5 | 5 | 0 | 5 |
| notifications | 6 | 6 | 0 | 0 |
| public | 2 | 2 | 0 | 0 |
| site | 23 | 23 | 0 | 14 |

**Security advisors (prod):** no ERROR-level lints. One INFO (`analytics.action_events` has RLS
enabled but no policies — correct for a table only the service role writes), two WARN
(`pg_trgm` installed in `public`; Auth leaked-password protection disabled).

This is a **tooling gap, not a prod defect** — restore the PAT and re-run `supabase-check.sh` to
close group C properly.

## Step 1 · Schema snapshot vs prod — PASS

`refresh-schema-snapshot.php` hard-pins the dev ref (`const PROJECT_REF = 'glncumufgaqcmqhzwrxm'; // dev ONLY — never the prod ref`),
so it was not repointed. Parity was established directly instead, by comparing digests of the live
catalogs across both projects over `public, core, site, notifications, analytics, audit, moderation`.

- **Columns / types / NOT NULL:** prod and dev digests are **identical** — `d76fb005d67b9b50dabccc44e72f8b44`, 884 columns each.
- **Constraints:** 272 on each side. 9 tables produced differing digests
  (`audit.moderation_events`, all five `moderation.*`, `notifications.email_subscriptions`,
  `site.site_media`, `site.sites`). Every one was inspected and the difference is **deparse
  formatting only** — prod renders `= ANY (ARRAY[('x'::character varying)::text, …])`, dev renders
  `= ANY ((ARRAY['x'::character varying, …])::text[])`. The permitted value sets are character-for-character
  the same in all nine. No semantic drift.

## G · Deployed env config (prod, --target=launch) — **FAIL** (exit 1)

```
ok    present: app.key
  ok    present: app.url
  ok    present: database.connections.pgsql.host
  ok    present: database.connections.pgsql.username
  ok    present: database.connections.pgsql.password
  ok    present: database.connections.pgsql.database
  ok    present: database.redis.default.host
  ok    present: supabase.service_role_key
  ok    present: services.cloudflare.api_token
  ok    present: services.cloudflare.kv_namespace_id
  ok    present: nightwatch.token
  ok    app.debug = false
  ok    app.env = production
  ok    cache.default = redis
  FAIL  queue.default = sync (want redis)
  FAIL  session.driver = cookie (want redis)

target=launch  ok=14 warn=0 fail=2
LAUNCH-CHECK-ENV: FAIL
FAIL  deployed env (production) config check failed
```

**FAIL 1 — `queue.default = sync` (want redis).** Expected and deliberate: prod launched on `sync`
per the 2026-07-22 sequencing decision. Phase 5 Step 1.4 is the flip that clears it. This check
cannot go green until the worker flip is applied, which is why Phase 4 and Phase 5 Step 1 are
mutually gating — see the verdict below.

**FAIL 2 — `session.driver = cookie` (want redis).** NOT anticipated by either runbook.
`config/session.php` defaults to `cookie` and **no env sets `SESSION_DRIVER`** — confirmed on
**both** environments (dev also reports `session.driver = cookie`). So this is a long-standing
config gap the launch-target gate surfaces for the first time, not a prod-specific regression.
CLAUDE.md documents Redis DB 2 as the session store, so either prod (and dev) should set
`SESSION_DRIVER=redis`, or the check should be amended if cookie sessions are deliberate for a
stateless JWT API with no backend login. **Needs a decision — not a silent softening.**

## H · Deployed runtime health (prod, --target=launch) — **FAIL** (exit 1)

```
── Edge / sitepage ──────────────────────────
FAIL  no handle to probe — this check did not run (nothing checked is not a pass).
      Set LAUNCH_CHECK_HANDLE to a published canary handle, or pass --handle <name>.

── Deployed runtime liveness ────────────────
  ok    horizon masters: 1
  ok    default queue depth: 0
  ok    failed_jobs: 0
  ok    redis ping ok
  ok    media disk (media) write/read/delete ok
  ok    scheduled tasks registered: 33

LAUNCH-CHECK-RUNTIME: PASS
ok    deployed runtime (production) health check passed
```

**Runtime liveness half: all six checks ok** — 1 Horizon master, default queue depth 0,
`failed_jobs` 0, Redis ping ok, media disk write/read/delete ok, 33 scheduled tasks registered.

**Edge half: FAIL — did not run.** There is no handle to probe because **prod has zero published
sitepages** (`core.users` = 0, `site.sites` = 0; only `core.partna_staff` = 1). Correct behaviour
by the suite's own rule: nothing checked is never a pass. The edge path on prod therefore remains
**unverified** and cannot be verified until a real sitepage exists on prod.

> **Suite defect noted (reporting, not verdict).** `runtime-health.sh` exits **1** correctly, so the
> runner records the FAIL — but the last lines it prints are `LAUNCH-CHECK-RUNTIME: PASS` /
> `ok deployed runtime (production) health check passed`, because the shared verdict block reports
> only the remote-liveness half and runs after the edge FAIL. A human reading the tail of a direct
> run sees "PASS" on a group that failed. The machine verdict is honest; the human-facing summary
> is not. Worth fixing before anyone trusts a hand-run.

## Gate verdict — 2026-07-26

| Group | Verdict | Blocker? |
|---|---|---|
| A · Schema-drift gate | PASS | — |
| Step 1 · prod/dev schema parity | PASS | — |
| B · Runtime smoke vs `api.partna.au` | PASS (12/12, incl. `/horizon` 401, `/telescope` 404) | — |
| C · Supabase config | script FAIL (no PAT) → **C-bis PASS via MCP** | No RLS-DISABLED ⇒ not a blocker |
| D · Supply chain | **FAIL** — composer clean; `cloudflare-worker` npm has 3 high (sharp <0.35.0 via miniflare/wrangler, dev deps) | Decision needed |
| E · Security audit (Vigil) | exit 0 at `--fail-on=critical`; 0 critical failed, 2 high failed, score 57/100 | Not a blocker at the configured gate |
| F · Drill-log freshness | **FAIL** — all four drills never run (worker-kill, vendor-outage, redis-down, **backup-restore**) | drill-04 is a Phase 5 Step 2 prerequisite |
| G · Deployed env config | **FAIL** ×2 — `queue.default=sync` (expected, Step 1.4 clears), `session.driver=cookie` (new, needs decision) | Task-8 FAIL = launch blocker per the prompt |
| H · Deployed runtime health | **FAIL** — liveness 6/6 ok, edge probe could not run (no published prod sitepage) | Task-9 FAIL = launch blocker per the prompt |

**Circular dependency, stated plainly.** Phase 4's Task-8 gate FAILs on `QUEUE_CONNECTION≠redis`,
and Phase 5's gate requires Phase 4 to have PASSED. Prod cannot satisfy both at once before the
flip. The resolution is ordering, not softening: run Phase 4 (this report), apply the Step 1 flip,
then re-run groups G and H to close the gate. This report is the pre-flip snapshot.

## I · Manual residue (no script can verify these)

# Launch-Check — Manual Residue

Items no script can verify. Reviewed every run; each needs a human + a date.

## Pre-pilot
- [x] **k6 load pass** — baseline (10 VU / 5 min, top-5 endpoints) + public `<handle>` spike (50–100 VU): watch edge cache-hit ratio, Supavisor `pg_stat_activity` headroom, p95.
- [x] **Worker-kill drill** — run `docs/runbooks/drills/01-worker-kill.md` (LOCAL stack only); log to `docs/runbooks/drills/logs/`. Freshness auto-checked by group F.
- [x] **Vendor-outage drill** — run `docs/runbooks/drills/02-vendor-outage.md` (local); log as above. Freshness auto-checked by group F.
- [x] **Redis-down drill** — run `docs/runbooks/drills/03-redis-down.md` (local); log as above. Freshness auto-checked by group F.
- [x] **Nightwatch fires** — throw a deliberate exception on dev; confirm the alert actually arrives.

## Pre-launch
- [x] **Prod environment decision** — unpause prod Supabase + Laravel Cloud env, or formally commit to dev-serves-both; prod DB re-baseline plan (repo migrations vs pre-standalone prod schema) — gated, Josh decides.
- [x] **PITR / backups** — confirm plan tier supports PITR; run the restore drill `docs/runbooks/drills/04-backup-restore.md` (to a scratch project, never a live one); log as above. Quarterly thereafter — freshness auto-checked by group F.
- [x] **Cloudflare dashboard** — Cache Deception Armor ON; rate-limiting rules at the edge (not only Laravel); SSL mode Full (strict).
- [x] **Supabase dashboard** (not fully API-readable) — SSL enforcement ON, network restrictions set, auth rate limits reviewed, custom SMTP.
- [x] **DAST pass** — OWASP ZAP baseline or Nuclei against staging (headers, cache deception, injection at runtime).
- [x] **Rollback plan per migration** — every migration since last deploy has a tested reverse path.
- [x] **Runbooks exist** — DB pool exhausted; queue backed up; vendor API down; Redis down.

## Continuous
- [x] Re-run launch-check after every migration push and before every promote.
- [x] Frontend/monorepo has NO audit coverage — separate effort.
