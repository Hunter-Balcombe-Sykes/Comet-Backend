# DB Pool Exhausted — Operator Runbook

## Scope

The Supavisor **session-mode** connection pool (port 5432, role `app_backend.<project_ref>`)
that every deployed Laravel process — web requests and all five Horizon supervisors — shares
against a single Supabase Postgres project. Session mode pins one pooler slot per OS process
for the life of that process (not per query), so the pool empties only when a process exits.
This is not the whole Postgres instance running out of connections — `max_connections` has
headroom; it's Supavisor's smaller session-mode ceiling (`pool_size`) filling up.

This runbook does not cover: the transaction-mode pooler (port 6543, currently unused by the
app — see Prevention below), PostgREST's own connections, or Postgres itself refusing new TCP
connections (a different, more severe failure with a different Postgres-level error).

## Symptoms — what Josh sees first

The exact error, verbatim — this is what you'll search Cloud logs for:

```
SQLSTATE[08006] FATAL: (EMAXCONNSESSION) max clients reached in session mode - max clients are limited to pool_size: 15
```

- **Intermittent and self-healing.** It comes and goes as processes cycle; a request that
  fails now may succeed on retry seconds later with no code change.
- **Recurs** — this is not a one-off, it's a standing ceiling the app sits at rest (see
  Confirm below), so anything that briefly adds one more connection-holding process tips it
  over.
- **Nightwatch will mislead you.** It groups the exception under whichever query happened to
  lose the race for a pool slot — usually a `site.platform_connections` lookup. **That query
  is not the cause.** Any query, on any endpoint, can be the one that draws the short straw.
  Chasing the `platform_connections` code path is the trap; look at the pool, not the query.

## Confirm

Run via the Supabase MCP `execute_sql` tool or the Supabase Dashboard SQL editor against the
target project (dev `glncumufgaqcmqhzwrxm`, prod `edplucmvkcnokyygxqsb`):

```sql
-- Who's actually holding a session-mode slot right now, and are they active or idle?
select usename, application_name, state, count(*)
from pg_stat_activity
where backend_type = 'client backend'
group by 1, 2, 3
order by 4 desc;
```

Signature of this incident: roughly 15 rows of `app_backend`/`Supavisor`, and they're **idle,
not active** — the pool is full of connections doing nothing, not connections stuck on slow
queries. If you instead see 15 rows in `state=active`, that's a genuinely different problem
(a query pileup, not idle-slot exhaustion) — don't apply this runbook's fixes to that.

```sql
-- How long have these backends been open, and how long have they sat idle?
select state, count(*), max(now() - backend_start) as oldest,
       max(now() - state_change) as longest_idle
from pg_stat_activity where usename = 'app_backend' group by 1;
```

**Measured on dev (`glncumufgaqcmqhzwrxm`), 2026-07-30** — the baseline this runbook was
written against, useful for judging whether what you're seeing now is normal-at-rest or an
active incident: `max_connections=60`, `superuser_reserved_connections=3`, 21 total client
backends. Of those, `app_backend`/Supavisor held **15, all `state=idle`** — oldest backend
16h37m old, longest single idle stretch 10m30s. The other 6: PostgREST ×2,
`Supavisor (auth_query)` ×1, mgmt-api ×1, `supabase_admin`/exporter ×2. **The pool sits at its
ceiling at rest, on dev, with no incident happening** — 15/15 is not itself proof of an
ongoing problem; a burst of NEW client backends beyond the Supabase-owned 6 is the actual
signal.

Session mode / port 5432 / `app_backend.<project_ref>` config: `config/database.php:91`,
`.env.example:200`, and `CLAUDE.md:23` in this repo.

## Immediate mitigation

**Instant unblock:** touching the pooler's own config in the Supabase Dashboard (Project
Settings → Database → Connection pooling) restarts the pooler process itself, which drops
every held session slot at once — observed going from 15 held connections down to 1. Use this
when you need the pool clear right now and can accept every in-flight query being cut. Any
setting you don't intend to change, re-save unchanged to trigger the restart without an
actual config change.

**Shed load, in this order** (real supervisor names from `config/horizon.php:210-333` — do
not invent a name that doesn't exist in that file):

```bash
# 1. First to go — scraping/gdpr jobs are the least time-sensitive lane.
cloud command:run development --cmd="php artisan horizon:pause-supervisor supervisor-long"

# 2. Next — video encoding, long-running but not user-blocking in the moment.
cloud command:run development --cmd="php artisan horizon:pause-supervisor supervisor-videos"

# 3. Last resort before touching the untouchable lanes — periodic ingest dispatch.
cloud command:run development --cmd="php artisan horizon:pause-supervisor supervisor-ingest"
```

**Never pause `supervisor-mail`** (`config/horizon.php:250-261` — carries transactional
OTP/claim/password-reset mail; pausing it silently stops account-critical email) **or
`supervisor-1`** (`config/horizon.php:214-230` — carries `moderation_high`, `default`, and
`cloudflare`, i.e. the routing-critical KV sync path). Pausing either just to free a
connection slot trades a DB incident for a worse user-facing one.

Resume a paused supervisor with `php artisan horizon:continue-supervisor <name>`.
`horizon:pause` (no supervisor name) stops the **whole Horizon master** — every lane, not just
one — and `horizon:terminate` kills and lets the process manager restart the **entire**
Horizon process, which re-acquires every connection from scratch. Know which one you're
running: `pause-supervisor`/`continue-supervisor` are scoped and reversible per-lane;
`pause`/`terminate` are not scoped at all.

## Root cause

**Worker share of the pool — corrected from a stale "six of fifteen" figure**, do not repeat
that number. `config/horizon.php:374-390` defines the per-environment `maxProcesses` for five
supervisors: `supervisor-1` (2) + `supervisor-mail` (2) + `supervisor-long` (1) +
`supervisor-videos` (1) + `supervisor-ingest` (1). At the **idle floor** every lane still runs
at least 1 worker (`config/horizon.php:341-355`), so Horizon alone holds **5 of 15** slots
before any HTTP request lands; at the **busy ceiling**, when `supervisor-1` and
`supervisor-mail` both scale to their max of 2, that's **7 of 15**. Either way, that leaves
roughly 8–10 slots for the web tier before the pool is exhausted purely by request volume —
narrower headroom than it looks from the total pool size alone.

Whether the Horizon **master** process and its per-lane **middleman** processes also pin their
own pooler slots (separately from the worker processes counted above) is
**UNVERIFIED — check by comparing the `pg_stat_activity` count query above with Horizon
stopped vs. running on the same environment.** If they do, the real Horizon-side floor is
higher than 5.

**Pool size — RESOLVED 2026-09-04, this section previously said the opposite.** The
Management API's `default_pool_size` **does** govern session mode, even though the entry it
returns is labelled `pool_mode: transaction` / port 6543. Proof: the app's own 5432 error on
dev now reads `pool_size: 30`, matching the 2026-07-26 raise. The earlier "the 30-on-dev
change is not what's actually in effect" reading was drawn from an error message that still
said 15 because the raise post-dated it. Read or change it with:

```bash
TOK=$(security find-generic-password -s "Supabase CLI" -w)   # strip a go-keyring-base64: prefix and base64 -d
curl -s -H "Authorization: Bearer $TOK" \
  https://api.supabase.com/v1/projects/<ref>/config/database/pooler
```

**Live values, 2026-09-04:** dev (`glncumufgaqcmqhzwrxm`) = **30**, prod
(`edplucmvkcnokyygxqsb`) = **40**. Both projects `max_connections = 60`. Note prod's 40 sits
at the very edge of the budget computed under Prevention below (~42) — there is no meaningful
headroom left to buy by raising it again.

## Recovery + rollback

There is nothing to "roll back" here in the usual sense — this incident is a resource ceiling,
not a bad deploy. Recovery is: shed load (above) until the pool has headroom, or force-clear it
via the pooler-restart trick, then let normal traffic refill it and watch it stabilize below 15.
If you paused any supervisors, resume them once the pool has sat with headroom for a few
minutes — resuming into an already-exhausted pool just re-creates the incident immediately.

If the instant-unblock pooler restart doesn't bring the count down (i.e. it's back at 15
within seconds with the same idle-not-active signature), that points at something loitering on
processes outside your control — Supabase-side (PostgREST, mgmt-api) — rather than the app.
Escalate to Supabase support rather than continuing to cycle the app's own workers.

## How you know it's over

- The `pg_stat_activity` confirm query shows `app_backend` count sitting comfortably below 15
  (not pinned at the ceiling) across a few consecutive checks a few minutes apart.
- `SQLSTATE[08006]` / `EMAXCONNSESSION` stops appearing in Cloud logs:
  `cloud env:logs partna development --minutes 10` (swap `production` when relevant — confirm
  before running against prod).
- Any paused supervisors have been resumed and Horizon has picked their queues back up (see
  **queue-backed-up.md**'s Observe section for how to confirm a lane is actually draining
  again, not just unpaused).

## Verification commands

```bash
# Re-run the confirm query any time to check current pool state (via Supabase MCP execute_sql
# or the Dashboard SQL editor — no CLI equivalent exists for this).

# Tail logs for the exact error signature.
cloud env:logs partna development --minutes 15

# Check which Horizon lanes are currently paused.
cloud command:run development --cmd="php artisan horizon:status"
```

## Prevention

- **Raise `default_pool_size` deliberately, with the arithmetic shown.** Budget:
  `60 max_connections − 3 superuser_reserved − ~15 Supabase-owned (PostgREST/mgmt-api/auth_query/exporter)`
  ≈ 42 connections available for `app_backend`. 30 is safe inside that budget; 40 is not — it
  leaves almost no margin for `superuser_reserved_connections` variance or a Supabase-side
  connection-count bump. Change this in the Supabase Dashboard pooler settings, not in this
  app's code — **there is no `DB_POOL_*` env key**; the pool is entirely Supavisor-side and
  invisible to `config/database.php`. Don't waste time grepping the app for one.
- **Or move runtime traffic to port 6543 (transaction mode)**, keeping 5432 reserved for
  migrations and `pg_dump` only. **The code side of this is now PREPARED and inert** (branch
  `feat/pg-transaction-mode-ready-2026-09-04`) — see the checklist below. It is no longer a
  code change; on dev it is one env var plus one `ALTER ROLE`.
- **Or tighten `maxProcesses` per lane** (`config/horizon.php:376-390`) to shrink Horizon's own
  floor/ceiling share of the pool — cheaper than a pooler resize but reduces job throughput.

## Switching to transaction mode (port 6543)

### TRIED AND ROLLED BACK — 2026-09-04. Read this before trying again.

Dev ran on 6543 for roughly ten minutes (08:49–09:00 UTC) and was rolled back. **The blocker
was `PDO::ATTR_EMULATE_PREPARES => true`**, which the first cut of this work turned on
because transaction mode cannot promise that PREPARE and EXECUTE reach the same backend.

Emulation makes PDO interpolate bound values itself — and a PHP `true` interpolates as `1`,
which Postgres refuses against a boolean column:

```
SQLSTATE[42883]: Undefined function: operator does not exist: boolean = integer
... "platform_connections"."user_id" and "is_active" = 1 ...
```

Every `->where('some_bool', true)` in the codebase hits this, so it is not a call-site fix.

**How it presented matters more than the error.** `GET /api/public/profiles/{handle}` kept
returning **200**, with `pools: {}` — every dev sitepage silently empty, because the content
lane fails open and logs `sitepage.content_read_failed` at warning level rather than throwing.
Health checks were green throughout. Nothing about the response status told you anything was
wrong. This is the exact "quiet failure" this runbook's watch section warns about, and it is
why the flip wants an explicit pool-and-payload check, not just a smoke test.

`config/database.php` now carries a comment block naming this, and
`tests/Feature/Architecture/PoolerModeConfigTest.php` fails if the attribute is set again.

### Second attempt, same day: native prepares FAIL TOO. The path is closed.

09:03–09:06 UTC, dev on 6543 with `ATTR_EMULATE_PREPARES` left at Laravel's default (false),
verified in-app (`config port 6543`, `pdo_emulate false`, errors reporting `Port: 6543`):

```
SQLSTATE[26000]: Invalid sql statement name: prepared statement "pdo_stmt_00000002" does not exist
... select * from "core"."users" where "handle_lc" = probe-nonexistent-8 ...
```

PDO issues PREPARE and EXECUTE as two messages; transaction mode can route the second to a
backend that never saw the first. Supavisor's prepared-statement support does not cover this.

**So both settings fail, for opposite reasons:**

| prepares | failure | shape |
|---|---|---|
| emulated (`true`) | `42883 boolean = integer` | total — every `where(bool, true)` |
| native (`false`) | `26000 prepared statement … does not exist` | intermittent, load-dependent |

**The native failure is invisible to a serial test.** Measured with the same 60-request burst
at 30 concurrency, minutes apart:

```
6543:  56 × 404,  4 × 500     ← ~7% of requests
5432:  60 × 404,  0 × 500     ← control
```

Every single sequential probe on 6543 passed — payload populated, health 200, timeouts and
`search_path` correct, boolean binds correct. Only concurrency exposed it. **Any future
attempt must include a concurrent burst; a smoke test will tell you it works.**

Reproduce the burst with:

```bash
seq 1 60 | xargs -P 30 -I{} curl -s -o /dev/null -w "%{http_code}\n" \
  "https://dev-api.partna.au/api/public/profiles/probe-nonexistent-{}" | sort | uniq -c
```

(Unknown handles are deliberate — they miss the payload cache and hit the DB every time.)

**What would actually be needed** to reach transaction mode from here: emulation ON *plus* a
custom connection that stringifies boolean bindings as `'true'`/`'false'` (an override of
`Illuminate\Database\Connection::prepareBindings`). That changes the binding path for every
query in the app and is a real project with its own risk, not a config flip. Nobody should
start it without deciding the pool ceiling is actually the binding constraint.

**Cheaper levers for the pool problem, in order:** trim Horizon's `maxProcesses` per lane
(`config/horizon.php`) to shrink its ~15-slot floor; or raise Supabase compute, which raises
`max_connections` above 60 and lets `default_pool_size` go past today's ~42 budget.

### What IS in place and safe

`supabase/migrations/20260905120000_app_backend_role_timeout_defaults.sql` puts
`statement_timeout` / `lock_timeout` on the **role**, because
`DatabaseServiceProvider::boot()`'s per-connection `SET` does not survive multiplexing. The
values mirror the config defaults exactly, so it changes nothing on 5432. Applied to dev.

**Per-environment step that is NOT in the migration — `search_path`.** Laravel issues
`set search_path` at connect, which has the same problem as the timeouts, but the correct
value differs per environment so it cannot be baked into a shared migration. Run the one that
matches the env's own `DB_SEARCH_PATH` **before** flipping its port:

```sql
-- dev (glncumufgaqcmqhzwrxm)  — applied 2026-09-04
ALTER ROLE app_backend SET search_path = core, site, public, analytics, moderation;

-- prod (edplucmvkcnokyygxqsb) — note the extra `audit`. NOT applied.
ALTER ROLE app_backend SET search_path = core, site, public, analytics, moderation, audit;
```

**Do not quote the list.** `SET search_path = 'core,site,public'` is one schema NAME containing
commas, not three schemas — Postgres accepts it silently and `current_schemas(false)` then
returns `{}`, i.e. nothing resolves. The identifiers go in bare and comma-separated. Verify
with the effective list, never just the raw string:

```sql
select rolname, rolconfig from pg_roles where rolname = 'app_backend';
-- and from a session that has the setting applied:
select current_setting('search_path') as raw, current_schemas(false) as effective;
```

`effective` must list every schema individually. All three role settings should appear in
`rolconfig`. **If `DB_SEARCH_PATH` is ever changed on an env, the role
default must be changed with it**; they are two copies of one value and nothing enforces
agreement.

**Then the flip itself:** `cloud environment:variables <env> --action=set --key=DB_PORT
--value=6543 --force`, then `cloud deploy partna <env>` — an env-var change takes no effect
until a redeploy, because config is baked by `php artisan optimize` at build time.
**Rollback is the same two commands with 5432** and took 1m54s on 2026-09-04. No migration to
reverse, no schema change, no data touched. The role defaults are harmless in session mode
(they duplicate what the app already sets), so leave them in place across a rollback.

**Check the payload, not just the status code.** Fetch a known-good profile and assert the
pools are populated:

```bash
curl -s https://dev-api.partna.au/api/public/profiles/<handle> \
  | python3 -c "import json,sys; p=json.load(sys.stdin)['data']['profile']; \
      print({k: len(v.get('items',[])) for k,v in p.get('pools',{}).items()})"
```

An empty dict there is the failure signature. A 200 is not evidence of anything.

**What is already safe, checked 2026-09-04:** every advisory lock in `app/` uses
`pg_advisory_xact_lock` (transaction-scoped, released at commit), not the session-scoped
`pg_advisory_lock`. `AdvisoryLock.php`'s `SET LOCAL lock_timeout` is likewise transaction-
scoped. Neither needs changing.

**What to watch on dev afterwards, and why a green suite proves none of it:** the test suite
runs SQLite, which has no pooler, no `search_path`, no timeouts and no advisory locks — it is
structurally blind to every risk here. Watch for:

- **Timeouts silently gone.** `select * from pg_settings where name in ('statement_timeout',
  'lock_timeout')` from an app connection, plus watch for endpoints that hang rather than
  erroring at 30s.
- **Unqualified table names failing** (`failed_jobs`, `jobs`, `cache` — Laravel's own). These
  surface only when a job fails, i.e. the error path breaks exactly when something else has
  already gone wrong.
- **Type handling under emulated prepares** — values arrive as quoted literals rather than
  typed parameters. Shows up as a wrong result or a changed plan, not a crash.
- **New exhaustion shape:** slots are now held per *transaction*, so a long transaction
  (especially one making an external HTTP call inside `DB::transaction`) pins one for its
  whole duration. Far harder to exhaust 30, but it is a different thing to look for.

None of these throw. Nightwatch catches thrown exceptions well and will not catch "the
timeout quietly stopped existing" — which is the whole reason this wants a watch period on
dev rather than a deploy-and-forget.

## What's deliberately NOT here

- **No automated pool-size tuning.** This is a manual Dashboard change, considered and applied
  by a human, not something a script adjusts reactively.
- **No transaction-mode flip.** The groundwork is in place (see the checklist above) but
  `DB_PORT` is deliberately still 5432 in both envs. Flipping it is an owner decision with a
  watch period, not something this runbook does on your behalf.
- **No Postgres-level `max_connections` increase.** That's a Supabase plan-tier lever, not
  something this doc assumes is available on the Free plan this project is currently on (see
  this repo's `CLAUDE.md` — Supabase org is on Free, no PITR/managed backups either).
- **Cross-reference: `docs/runbooks/queue-backed-up.md`.** A pool exhaustion incident can
  present as a queue backup — jobs that need a DB connection block on one, queue depth climbs,
  and it looks like a worker-throughput problem when the real bottleneck is upstream at the
  pool. If queue depth is climbing *and* you see `EMAXCONNSESSION` in the same window, fix the
  pool first — scaling up workers to "clear the backlog" only asks for more connections you
  don't have, and can make the pool exhaustion worse.
