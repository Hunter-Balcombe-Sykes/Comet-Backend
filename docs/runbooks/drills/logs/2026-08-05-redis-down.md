# Drill log — 03 Redis down

- **Date:** 2026-08-05 (AEST; all times below UTC)
- **Runbook:** [../03-redis-down.md](../03-redis-down.md) (at commit `d6caef96`; repo HEAD `d6caef96`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test` on worktree
  `backend-wt/drills-2026-08-05`, local Supabase (67 migrations from-zero), Homebrew Redis 6379,
  Horizon running (12 processes), keyspace split to match deployed (queue=0, cache=1, sessions=2,
  locks=4).
- **Mode/variants run:** Full outage (`brew services stop redis`, injection verified) **and**
  Scenario C (hung Redis, `DEBUG SLEEP`, with a concurrent witness **and** parallel probes).

## Preconditions — all verified, not assumed

| Precondition | Verified value |
|---|---|
| `CACHE_STORE` | `redis` (not a failover store) |
| `QUEUE_CONNECTION` | `redis` |
| `SESSION_DRIVER` | `cookie` |
| `config('partna.throttle.enabled')` | **`true`** — the layer under test is actually on |
| Redis timeouts | `default` db0 t=2 **rt=15**, `cache` db1 t=2 rt=3, `cache_locks` db4 t=2 rt=3, `session` db2 t=2 rt=3, `queue` db3 t=2 rt=15 |
| Drill user | `drill-rd-20260805`, site published |
| **Authed probe** | **a real ES256 Supabase JWT** minted from the local GoTrue admin API (`aal1`, `sid=7826754c`), returning **200** at baseline — not an unauthenticated 401 |

The authed probe being genuinely authenticated is new. The 2026-07-31 run noted that without a
valid JWT the probe returns 401 before it ever reaches the throttle layer, so the 401 proves
nothing. This run closes that.

## Baseline (Redis healthy)

```
profile   200  0.057s / 0.031s
pageview  201  0.041s / 0.038s
authed    200  0.040s / 0.037s
health    200  0.026s / 0.026s
```

## ⚠️ A false PASS caught mid-drill — the beacon result had to be re-taken

First pass with Redis down showed `pageview 201` and **zero** `analytics.ingest.dispatch_failed`
breadcrumbs. Zero breadcrumbs with a dead Redis is not success, it is a signal the code under
test never ran. Cause: `AppServiceProvider` binds `AnalyticsIngestor` to **`SyncIngestor`** when
`app()->environment()` is `local` or `testing` (`app/Providers/AppServiceProvider.php:168-173`).
A local drill therefore writes analytics **straight to Postgres and never touches Redis**, so the
201 says nothing about the queued fail-open path the drill exists to verify.

Fixed for the rest of the run by setting `APP_ENV=staging` in the drill `.env` and confirming the
binding flipped:

```
env=staging  ingestor=App\Services\Analytics\Ingestors\QueuedIngestor
```

**Every "Redis down" number below was taken with `QueuedIngestor` bound.** This is the third
distinct false-PASS trap this drill has produced across runs; the runbook already documents two.

## Result 1 — Redis fully DOWN (connection refused)

Injection verified before probing, exactly as the runbook demands:

```
$ redis-cli ping
Could not connect to Redis at 127.0.0.1:6379: Connection refused
$ lsof -nP -iTCP:6379 -sTCP:LISTEN     # (empty)
$ ps aux | grep '[r]edis-server'       # (empty)
```

| Probe | Baseline | **Redis DOWN** | Recovered |
|---|---|---|---|
| `GET /api/public/profiles/<handle>` | 200 · 0.031s | **200 · 0.061–0.086s** | 200 · 0.050s |
| `POST /api/public/analytics/pageviews` | 201 · 0.038s | **201 · 0.022–0.048s** | 201 · 0.036s |
| `GET /api/site` (real JWT) | 200 · 0.037s | **503 · 0.019–0.033s** | 200 · 0.036s |
| `GET /api/health` | 200 · 0.026s | **200 · 0.020–0.026s** | 200 · 0.025s |
| `POST /api/public/enquiry` | — | **503 · 0.047s** | — |

Beacon fail-open, verified end-to-end on the production path:

```
20 beacons fired in <1s -> 201 ×20
analytics.ingest.dispatch_failed breadcrumbs: 2 -> 22   (+20, one per beacon)
```

The authed 503 is a *designed* degradation, not an accidental 500:

```
HTTP/1.1 503 Service Unavailable
Retry-After: 5
Cache-Control: max-age=0, no-store, private
{"message":"Service temporarily unavailable. Please try again shortly."}
```

Enquiry flow — the data-loss-shape judgment: it **fails entirely and cleanly**. 503 in 47 ms,
and nothing half-landed:

```
site.enquiries = 0
site.customers = 0
```

Horizon: dropped 12 → 7 processes during the outage, and **self-healed back to 12 with no human
intervention** once Redis returned. The master never died.

Recovery was completely hands-off — no cache repair, no Horizon restart, no poisoned state, and
the only `queue:failed` entry was drill 02's pre-existing fresha job.

## Result 2 — Redis HUNG (Scenario C) — this is where it fails

`enable-debug-command local` added to `/opt/homebrew/etc/redis.conf` for the session (reverted
and verified byte-identical afterwards). `DEBUG SLEEP` confirmed to actually block against its
own injection (5.53 s against a 6 s sleep) before any probe was trusted.

**Probes fired in PARALLEL** against a single 40 s hang, with a concurrent witness — sequential
probes consume the hang window and the later ones silently measure a recovered Redis (a fourth
variant of the same trap; the first sequential attempt produced `authed 200 · 0.05s`, which was
meaningless):

| Probe | Code | **Time** | vs. runbook expectation (~3s) |
|---|---|---|---|
| `POST /api/public/enquiry` | 503 | **3.04 s** | ✅ exactly `read_timeout` 3.0 |
| `GET /api/health` | 200 | **10.25 s** | ❌ 3.4× |
| `GET /api/public/profiles/<handle>` | 200 | **18.31 s** | ❌ 6× |
| `POST /api/public/analytics/pageviews` | 201 | **29.27 s** | ❌ 9.8× |
| `GET /api/site` (real JWT) | 502 | **32.02 s** | ❌ 10.7× |

`WITNESS: redis hung 39.85s`

Re-measured in isolation, one probe per hang, to remove any contention explanation:

```
authed ALONE   502  32.01s        (witness: hung 44.84s)
health ALONE   200   9.06s
```

Reproducible. Not contention. **Root cause below.**

## Root cause of the hung-Redis latency

Two compounding causes, both confirmed:

**1. The request path uses the `default` connection, which is bounded at 15 s, not 3 s.**
`config/database.php` sets `default` (DB 0) `read_timeout = 15.0` deliberately — it must exceed
the queues' `block_for`. But DB 0 is not only the worker path. At the time of this drill
`TokenRevocationService` used the **bare `Redis::` facade with no `connection()` call**, so every
authenticated request's session-blocklist and session-tracking calls landed on `default`.
(Fixed in the same branch as this log: a new `app` connection on the same DB 0 at
`read_timeout` 3.0, with the request-path callers pinned to it. The keyspace is unchanged —
same DB, same `laravel_database_` prefix — verified by cross-connection read/write.)
Verified by keyspace inspection after a single authed request against a freshly flushed Redis:

```
DB0: laravel_database_auth:session-touch:7826754c-…   (ttl 305,     string)
     laravel_database_auth:session-meta:7826754c-…    (ttl 2591705, hash)
     laravel_database_auth:user-sessions:3c4e0c4b-…   (ttl 2591705, set)
DB1: laravel_database_laravel-cache-pro:map:auth:3c4e0c4b-…
DB2, DB3, DB4: empty
```

The arithmetic matches exactly: `authed` 32.01 s ≈ 2 × 15 s on `default`.

**2. `read_timeout` bounds one *operation*, not one *request*.** A request that touches Redis N
times inherits N × read_timeout. `/api/health` sits behind `throttle:health-check`, whose limiter
does ~3 ops on 3 s-bounded connections → **9–10 s**, which is what was measured. So even the
correctly-bounded connections stack.

This is precisely the diagnosis the runbook asks for — *"If a request-path probe hangs past 3 s,
`B4` did not take effect on the connection actually serving it — find out which connection that
is rather than raising the timeout."* The connection is **`default` (DB 0), at 15 s**.

## Verdict

| Criterion (from runbook) | Result | Notes |
|---|---|---|
| No probe hangs multi-second — failures are FAST | **PASS when Redis is DOWN** (all ≤ 0.09 s) / **FAIL when Redis is HUNG** (10–32 s on 4 of 5 probes) | The headline result of this drill. |
| Public profile reads survive | **PASS (down) / FAIL (hung)** | 200 · 0.06 s dead; 200 · 18.31 s hung. It survives, but 18 s is not survival any user experiences as such. |
| Beacon fail-open works end-to-end (2xx) | **PASS** | 20/20 × 201 with one breadcrumb each, on the **queued** path — only after the `SyncIngestor` false PASS was caught. |
| Breadcrumb trail exists; escalation matches the trait's tiers | **PASS** | 22 `analytics.ingest.dispatch_failed` breadcrumbs. No Tier-2 `report()` at n=20 — expected at 1-in-200, documented as an honest limit, not a failure. |
| Recovery is hands-off (except possibly Horizon restart) | **PASS, better than expected** | Horizon self-healed 7 → 12 processes with no restart. The runbook's "a master needing a human is itself a finding" case did not arise. |
| No non-analytics data loss | **PASS** | Enquiry failed atomically — 0 rows, clean 503 + `Retry-After`, nothing half-written. |

**Overall: PARTIAL.** A dead Redis is handled well — arguably exemplary. A **hung** Redis is not,
and a hung cache is both more likely and more damaging than a dead one, which is the whole reason
Scenario C exists.

## Findings

1. **🔴 P1 — The request path is bounded at 15 s, not 3 s, because `TokenRevocationService`
   uses the `default` connection.** Every authenticated request makes session-blocklist calls on
   DB 0, whose `read_timeout` is 15 s by design (it must exceed the queues' `block_for`).
   Measured: authed request **32.01 s** against a hung Redis, reproducible in isolation. The `B4`
   hardening bounded `cache`/`cache_locks`/`session` and missed that the request path also uses
   `default`. **Fix shape:** add a request-path connection pointing at DB 0 with
   `read_timeout` 3.0 and pin `TokenRevocationService` to it; leave `default` at 15 s for
   workers. Do **not** lower `default` itself — that would break `BLPOP`.

   ⚠️ **This fix is a trade, not a free win, and should not be described as one.** The revocation
   check already **fails open**: `VerifySupabaseJwt.php:137-146` (and `:242-251`) catches any
   `Throwable` from `isRevoked()` and sets `$revoked = false`, so a timed-out check means the
   request proceeds with a session that may have been revoked. Tightening 15 s → 3 s does not
   introduce that behaviour, but it **widens the band in which "slow" becomes "revocation
   silently skipped"** from ">15 s" to ">3 s". Against the measured baseline in
   `config/database.php` (whole-request p99 376 ms; worst legitimate single op 314 ms) 3 s is
   ~10× headroom, so practical exposure is small — but the net effect under a hung Redis is the
   same fail-open outcome reached in ~6 s instead of ~32 s, not a fail-closed one. Accepting that
   is a deliberate choice; revisit it if fail-closed-on-revocation ever becomes a requirement.
2. **🔴 P1 — `read_timeout` bounds an operation, not a request.** `/api/health` took **9–10 s**
   under a hung Redis on connections correctly bounded at 3 s, because its rate limiter performs
   ~3 sequential ops. `/api/health` is documented as a **liveness** endpoint; a load balancer
   that treats a 10 s response as dead would pull the app out of rotation over a *cache* problem.
   **Fix shape:** exempt `/api/health` from the cache-backed throttle (a liveness probe should
   have no Redis dependency at all), and record the ops×timeout property so nobody reads
   "read_timeout 3.0" as "requests bounded at 3 s".
   **Shipped in this branch:** `/api/health` and `/api/ping` are now unthrottled and were
   confirmed with `redis-cli monitor` to issue **zero** Redis commands, against `/api/ready`
   which still issues five. `/ready`, `/health/scheduler` and `/internal/env-check` stay
   throttled — they are readiness and diagnostics, not liveness.
3. **🟠 P2 — Auth session-revocation state lives on DB 0, and the queue runbook's blast-radius
   warning does not know it.** `docs/runbooks/queue-backed-up.md` tells operators that a DB 0
   `FLUSHDB` "destroys Horizon's own bookkeeping (not just queued jobs — supervisor state,
   metrics, everything)". It also destroys `auth:revoked-session:*`, which means **every
   signed-out or revoked session silently becomes valid again** for the remainder of its refresh
   token's life (up to 30 days). Same exposure via eviction: DB 0 shares the instance-wide
   250 MB `volatile-lru` budget, and these keys all carry TTLs, so they are eviction candidates
   under queue memory pressure. The keys are otherwise well-built (every one has a TTL; the
   metadata hash uses a Lua script precisely to avoid a TTL-less orphan) — the issue is
   placement and the missing warning, not the code. **Fixed in this branch** (docs + CLAUDE.md);
   the placement change rides with Finding 1.
4. **🟡 P3 — A local drill silently tests `SyncIngestor`, not `QueuedIngestor`.** `APP_ENV=local`
   flips the binding, so the drill's headline hypothesis is unreachable in the environment the
   runbook tells you to use. This produced a clean-looking false PASS before it was caught.
   **Fixed in this branch** — runbook now requires `APP_ENV=staging` and a binding assertion
   before any probe is trusted.
5. **🟢 Positive, worth recording:** the dead-Redis path is genuinely good. Public sitepage reads
   stay up, beacons fail open with breadcrumbs, the authed path degrades to a well-formed 503
   with `Retry-After`, the enquiry flow fails atomically with no partial write, and Horizon
   self-heals. That is the fail-open work (`@c575ac2d`) doing exactly what it was built to do.

## Runbook corrections

Applied to `../03-redis-down.md` in the same commit as this log:

1. **Add the `APP_ENV` precondition.** `local`/`testing` binds `SyncIngestor` and makes the
   beacon probe vacuous. The runbook must require `APP_ENV=staging` and the
   `get_class(app(AnalyticsIngestor::class))` assertion alongside the existing throttle check.
2. **Scenario C must fire probes in PARALLEL.** The runbook already warns that the *witness*
   must not run before the probes; it does not warn that the probes must not run in series.
   Five sequential probes consumed a 30 s hang and the last three measured a recovered Redis,
   returning fast, healthy, entirely false results. Corrected with the parallel-probe block used
   here.
3. **Update the "Expected as of 2026-07-31" block.** It says request-path probes fail at ~3 s and
   that anything past 3 s means `B4` did not take effect. Measured now: only `enquiry` is at 3 s;
   everything else is 9–32 s. Rewritten to name `default`/15 s as the cause and the ops×timeout
   stacking as the second cause, so the next operator does not spend the run re-deriving it.
4. **Add `/api/health` and `/api/public/enquiry` to the standard probe set.** Health surfaced the
   ops-stacking problem and enquiry was the only probe that behaved as the runbook predicted;
   both belong in the table rather than as optional extras.

## Next run due

On material change to cache/queue wiring, analytics ingest, throttle middleware, or
`EscalatesRepeatedFaults` — and **immediately after Findings 1 and 2 are fixed**, to confirm the
hung-Redis probe times actually drop.
