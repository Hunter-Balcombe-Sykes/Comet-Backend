# Drill log — 03 Redis down (re-run: per-request circuit breaker)

- **Date:** 2026-08-05 (AEST; times below UTC)
- **Runbook:** [../03-redis-down.md](../03-redis-down.md)
- **Purpose:** acceptance test for the per-request Redis circuit breaker. Supersedes nothing —
  it is the follow-up the [2026-08-05 log](2026-08-05-redis-down.md) closed with
  ("the remaining fix is architectural… tracked as the next piece of work, not closed").
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL — Herd site `partna-drill.test` on worktree
  `backend-wt/redis-request-breaker-2026-08-05` (branch
  `audit-fix/redis-request-breaker-2026-08-05`, based on `d9426d31`), local Supabase
  (67 migrations), Homebrew Redis 6379, Horizon running (13 processes), keyspace split to match
  deployed.
- **Mode/variants run:** full outage, **and** Scenario C (hung Redis, `DEBUG SLEEP`, parallel
  probes + concurrent witness), **and** a controlled breaker-on/breaker-off A/B.

## Preconditions — all verified, not assumed

| Precondition | Verified value |
|---|---|
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER` | `redis` / `redis` / `cookie` |
| `config('partna.throttle.enabled')` | **`true`** |
| `APP_ENV` | **`staging`** — `get_class(app(AnalyticsIngestor::class))` = `App\Services\Analytics\Ingestors\QueuedIngestor` |
| Horizon | started under `APP_ENV=local` **then** flipped to `staging`; 13 processes. `array_keys(config('horizon.environments'))` = `production,development,local` (confirms `staging` would have run zero supervisors) |
| Redis keyspace | `default=0 app=0 cache=1 session=2 queue=3 cache_locks=4` |
| Redis read_timeouts | `default=15.0 app=3.0 cache=3.0 cache_locks=3.0` |
| Connection class | `Redis::connection('cache')` → `App\Services\Redis\GuardedPhpRedisConnection` |
| **Authed probe** | **real ES256 Supabase JWT** from local GoTrue (`aal1`, `session_id` present), **200** at baseline |
| Drill site | `loadtest`, published, seeded from `scripts/launch-check/k6/seed.sql` (6 gallery items + variants, 15 services, 10 link blocks, 4.5k analytics rows) |

Note for the next operator: local GoTrue has **Turnstile captcha enabled**
(`supabase/config.toml` `[auth.captcha] enabled = true`), so the password grant returns
`400 captcha_failed` and cannot mint a token. `POST /auth/v1/admin/generate_link` (service-role)
→ `POST /auth/v1/verify` with the returned `token_hash` bypasses captcha and yields a real ES256
access token. Also: the enquiry probe needs `form_started_at_ms` **and** a `subject` present in the
contact block's `subject_options` (the k6 seed sets `["General"]`) — a wrong subject returns 422,
which looks like a probe result but is a payload bug.

## Baseline (Redis healthy, warm)

```
profile  200  0.022s      pageview 201  0.026s      authed 200  0.032s
health   200  0.025s      enquiry  200  0.043s
```

## Where the 18s actually came from — `redis-cli monitor`, warm profile request

Filtered to app traffic (Horizon's own `LLEN`/`BLPOP`/`ZADD` polling removed):

```
SELECT 1                                          # `cache`
GET   …laravel-cache-<md5>                        # throttle:public-profile
EVAL  …setex…<md5>:timer / exists <md5>:timer     #   limiter
EVAL  …setex…<md5>      / exists <md5>            #   limiter
GET   …laravel-cache-handle.resolve:loadtest      # SWR
GET   …laravel-cache-handle.resolve.floor:loadtest
GET   …laravel-cache-public.profile:loadtest:…
GET   …laravel-cache-<md5>                        # throttle headers (post-response)
SELECT 0                                          # `app`
HINCRBY cache_metrics:2026-08-05-01 × 3           # RecordCacheMetrics (post-response)
```

**Ten in-request touches on `cache`**, each independently bounded at 3.0 s and each failing open.
Six of them belong to the rate limiter alone — before the controller runs. `cache_locks` (DB 4) is
never touched on the warm path. That is 18 s in one screen, and it is why a timeout value cannot
fix it.

## Result 1 — Redis fully DOWN. No regression.

Injection verified: `redis-cli ping` → `Connection refused`, `lsof -nP -iTCP:6379 -sTCP:LISTEN`
empty.

| Probe | Parallel | Serial | Parallel (re-check, post review fixes) |
|---|---|---|---|
| `profile` | 200 · 0.068 s | 200 · 0.053 s | 200 · 0.094 s |
| `pageview` | 201 · 0.037 s | 201 · 0.022 s | 201 · 0.055 s |
| `authed` | 503 · 0.037 s | 503 · 0.020 s | 503 · 0.054 s |
| `health` | 200 · 0.050 s | 200 · 0.019 s | 200 · 0.075 s |
| `enquiry` | 503 · 0.051 s | 503 · 0.020 s | 503 · 0.075 s |

All ≤ 94 ms — unchanged from the 2026-08-05 baseline run. Enquiry failed atomically:
`site.enquiries` and `site.customers` both unchanged at 3.

Beacon fail-open, end-to-end on the queued path:

```
20 beacons -> 201 x20
analytics.ingest.dispatch_failed breadcrumbs: 19 -> 39   (+20, one per beacon)
redis.request_breaker.opened     breadcrumbs: 57 -> 77   (+20, one per REQUEST)
```

The breaker breadcrumb is one per request, not one per skipped command — deliberate, and confirmed
here. Sample line:

```
staging.WARNING: redis.request_breaker.opened
{"connection":"(connecting)","command":"connect","exception":"RedisException","error":"Connection refused"}
```

`connection: (connecting)` shows the **connect-time** guard tripping: with Redis refused, the first
`connect()` fails in ~40 ms and the remaining four connections are skipped rather than each
attempting their own connect.

Recovery hands-off: all probes back to baseline with no cache repair, Horizon self-healed to 13
processes with no restart. `queue:failed` contains only a pre-existing `RefreshConnectionJob` from
an earlier session.

## Result 2 — Redis HUNG (Scenario C). This is the fix.

`enable-debug-command local` appended to `/opt/homebrew/etc/redis.conf` for the session, restored
byte-identical afterwards (`shasum` verified equal, and `DEBUG SLEEP` confirmed refused again).
`DEBUG SLEEP` verified to block against its own injection (5.58 s against a 6 s sleep) before any
probe was trusted.

**Probes fired in PARALLEL against a single 40 s hang, with a concurrent witness:**

| Probe | 2026-08-05 (post connection-pin) | **Now** | Change |
|---|---|---|---|
| `GET /api/public/profiles/<handle>` | 18.11 s | **3.11 s** | **−83 %** ✅ |
| `POST /api/public/analytics/pageviews` | 15.06 s | **3.10 s** | **−79 %** ✅ |
| `GET /api/site` (real JWT) | 12.06 s | **3.05 s** | **−75 %** ✅ |
| `POST /api/public/enquiry` | 3.97 s | **3.04 s** | −23 % |
| `GET /api/health` | 1.97 s | **0.063 s** | **−97 %** ✅ |

`WITNESS: redis hung 39.77s` — the injection was real.

These are the **post-review-fix** numbers. Scenario C was run twice: once on the as-implemented
code (`profile 3.13 s`, `pageview 3.93 s`, witness 39.76 s), and again after the independent review
changed the breaker's internals (breaker resolved through a closure rather than held readonly, and
three connect-side message fragments added). Re-running rather than carrying the earlier table
forward is the point — the second run is the one that describes the merged code.

Breaker breadcrumbs across that round: **+4 for 5 probes.** `health` never tripped it because it
issues zero Redis commands, which is exactly what the 2026-08-05 liveness fix was for.

### Re-measured in isolation, one probe per hang — not contention

```
profile ALONE   200  3.105s     (witness: hung 24.79s)
pageview ALONE  201  3.048s     (witness: hung 24.77s)
authed ALONE    503  3.050s     (witness: hung 24.76s)
health ALONE    200  0.037s     (witness: hung 24.75s)
```

Every request-path probe now lands within ~50 ms of a single `read_timeout`. That is the design
target: one bound per request instead of one bound per operation.

### Controlled A/B on the same stack — the number is attributable

The before-column above is from a different session. To remove that as a variable, the breaker was
disabled by commenting out the single `$middleware->prepend(ArmRedisRequestBreaker::class)` line in
`bootstrap/app.php`, with everything else identical (same warm cache, same hang, same process):

```
profile NO-BREAKER  200  18.117s   (witness: hung 39.77s)
profile RE-ARMED    200   3.102s   (witness: hung 24.77s)
```

18.117 s lands within 7 ms of the 18.11 s recorded on 2026-08-05. One line accounts for the whole
difference.

## Verdict

| Criterion (from runbook) | Result |
|---|---|
| No probe hangs multi-second — failures are FAST | **PASS.** Down: ≤ 0.07 s. Hung: ≤ 3.13 s, i.e. one `read_timeout`. Previously 10–32 s on 4 of 5 probes. |
| Public profile reads survive | **PASS.** 200 in both modes; 3.13 s hung, 0.07 s dead. |
| Beacon fail-open works end-to-end | **PASS.** 20/20 × 201, +20 breadcrumbs, on the queued path with `QueuedIngestor` asserted bound. |
| Breadcrumb trail exists | **PASS**, and now includes `redis.request_breaker.opened` naming the first failing connection + command. |
| Recovery is hands-off | **PASS.** Horizon self-healed; no cache repair. |
| No non-analytics data loss | **PASS.** Enquiry failed atomically, zero rows. |

**Overall: PASS.** Both the dead-Redis and hung-Redis paths now degrade fast. The residual verdict
from the previous log — "a hung Redis still degrades a public sitepage read to ~18 s" — is closed.

## Residual / honest limits

1. **~3 s is the floor, not zero.** The breaker collapses N × `read_timeout` to 1 ×
   `read_timeout`; the *first* failing operation still pays its bound. Getting below 3 s means
   lowering `read_timeout`, which trades correctness on a healthy Redis for latency on a broken one
   — explicitly rejected (worst legitimate single op measured: 314 ms).
2. **Revocation is skipped slightly earlier in the degradation curve.** If the breaker opens before
   `TokenRevocationService::isRevoked()` runs, the check is skipped. `VerifySupabaseJwt` already
   catches and sets `$revoked = false`, so the *decision* is unchanged — the band widens from "this
   check timed out" to "any Redis op earlier in this request timed out". Same fail-open outcome,
   reached sooner. Deliberate; revisit if fail-closed-on-revocation ever becomes a requirement.
3. **Worker path is deliberately not covered.** The breaker is inert unless armed, and only the
   HTTP middleware arms it. A queue worker under a hung Redis still pays N × `read_timeout` per
   job. That is the intended trade: no user is waiting, and skipping a Redis op inside a job makes
   the job *wrong*, not fast.
4. **`scan`/`pipeline`/`transaction`/`subscribe` bypass `Connection::command()`** and are therefore
   unguarded. None are on the request path in this repo. Recorded as a boundary, not worked around.
5. **A single TRANSIENT failure now degrades the whole request, where it previously did not.**
   Surfaced by the independent review, not by this drill — the drill only ever injects a
   *sustained* outage. Today one `read error on connection` costs op 1 its fail-open branch and
   Laravel's eager reconnect lets ops 2..N succeed; with the breaker, ops 2..N are skipped. On an
   authenticated route that can turn a 200 into a 503 (`VerifySupabaseJwt` is pinned ahead of
   `ThrottleRequests`, and `FailOpenThrottleRequests` fails CLOSED outside its five-entry
   allow-list). Accepted deliberately, but it is a real behaviour change and not "only the wait
   disappears". Recorded in `GuardedPhpRedisConnection`'s docblock and in the plan.
6. **This drill structurally cannot exercise the connect-timeout path.** `DEBUG SLEEP` leaves the
   kernel completing the TCP handshake, so failures surface as `read error on connection` at
   `SELECT`. A packet-drop outage throws `Operation timed out` from `connect()` instead — a
   different string, which the trip predicate missed until the independent review caught it against
   real phpredis on an unroutable address. It is covered now (`operation timed out`, `getaddrinfo`,
   `connection reset` added, with unit cases), but a green Scenario C is not evidence about it.
7. **The beacon's ordering dependency is now covered twice.** `pageview` improved to ~3 s because
   the throttle limiter trips the breaker before the queue dispatch runs. The new
   `queue.connections.redis_request` (Redis connection `app`, keyspace-identical to `redis`) covers
   the case where a dispatch is the *first* failing touch and would otherwise inherit `default`'s
   15 s worker bound.

## Next run due

On material change to cache/queue wiring, throttle middleware, the breaker itself, or
`EscalatesRepeatedFaults` — and on any Laravel upgrade that touches
`Illuminate\Redis\Connections\PhpRedisConnection` or `Connectors\PhpRedisConnector`, since
`GuardedPhpRedisConnector::connect()` reproduces vendor logic and the breaker suppresses the
vendor's eager reconnect.
