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

**Pool size itself is unsettled between dev and prod — do not assert a specific number as
current fact beyond what's stated here.** A note from a prior investigation records
`default_pool_size` being raised to 30 on dev on 2026-07-26, with prod left at `null`
(Supabase's default of 15). But the live measurement above shows the pool still capping at 15
connections on dev, and the app's own error message says `pool_size: 15` — i.e. the recorded
30-on-dev change is not what's actually in effect, or it applies to a different pool mode than
the one the app uses. **UNVERIFIED — read `GET
https://api.supabase.com/v1/projects/<ref>/config/database/pooler`; note the Management API
is documented to only surface the port-6543 (transaction-mode) pooler entry, so session
mode's `pool_size` may be governed by a setting this endpoint doesn't expose at all** — don't
assume a clean answer is waiting there.

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
  migrations and `pg_dump` only (transaction mode doesn't support session-level features like
  advisory locks or `SET` that some migration tooling relies on). This requires setting
  `PDO::ATTR_EMULATE_PREPARES => true` in the `pgsql` connection's `options` array in
  `config/database.php` — that key doesn't exist there today (checked 2026-07-30), so this is
  a real code change, not just an env-var flip, and needs its own test pass before shipping.
- **Or tighten `maxProcesses` per lane** (`config/horizon.php:376-390`) to shrink Horizon's own
  floor/ceiling share of the pool — cheaper than a pooler resize but reduces job throughput.

## What's deliberately NOT here

- **No automated pool-size tuning.** This is a manual Dashboard change, considered and applied
  by a human, not something a script adjusts reactively.
- **No transaction-mode migration.** Moving runtime traffic to port 6543 is a real change
  (see Prevention) that needs its own implementation and test pass — this runbook documents
  the option, it doesn't execute it.
- **No Postgres-level `max_connections` increase.** That's a Supabase plan-tier lever, not
  something this doc assumes is available on the Free plan this project is currently on (see
  this repo's `CLAUDE.md` — Supabase org is on Free, no PITR/managed backups either).
- **Cross-reference: `docs/runbooks/queue-backed-up.md`.** A pool exhaustion incident can
  present as a queue backup — jobs that need a DB connection block on one, queue depth climbs,
  and it looks like a worker-throughput problem when the real bottleneck is upstream at the
  pool. If queue depth is climbing *and* you see `EMAXCONNSESSION` in the same window, fix the
  pool first — scaling up workers to "clear the backlog" only asks for more connections you
  don't have, and can make the pool exhaustion worse.
