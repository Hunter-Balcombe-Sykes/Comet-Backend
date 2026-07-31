# Drill log — 03 Redis down

- **Date:** 2026-07-31
- **Runbook:** [../03-redis-down.md](../03-redis-down.md) (at commit `49ec65a1`; repo HEAD `b19ca5d3`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test`, local Supabase
  (`supabase_db_Partna-Development`), local Redis 6379 (Homebrew service).
- **Preconditions met:** `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=cookie`,
  `partna.throttle.enabled=true` (**had to be turned on** — local `.env` shipped
  `SIDEST_THROTTLE_ENABLED=false`, which would have made this drill prove nothing), Horizon running.
- **Scenarios run:** BASELINE / DOWN / RECOVER. **Scenario C (hung, not down) NOT faithfully
  executed** — see Finding 5.

## Probe substitution — stated up front

Probe 3 ("authed dashboard read") was run **unauthenticated**. Minting a valid Supabase JWT
locally is not possible without the project's signing key. It is still a useful probe — it
exercises the auth + throttle middleware stack — but **its 401 is not evidence of resilience**,
because auth rejects the token before the throttle layer is reached. Do not read row 3 as a pass.

The runbook's URL for it is also wrong: the route is `api/site`, not `api/professional/site`
(which 404s as "Endpoint not found").

## Results — the core artifact

| Probe | BASELINE (cold) | WARM | **DOWN** | RECOVERED |
|---|---|---|---|---|
| `GET /api/public/profiles/{handle}` | 200 · 0.563s | 200 · 0.089s | **500 · 0.052s** | 200 · 0.063s |
| `POST /api/public/analytics/pageviews` | 201 · 0.172s | 201 · 0.038s | **500 · 0.034s** | 201 · 0.040s |
| `GET /api/site` (unauthed\*) | 401 · 0.119s | 401 · 0.027s | 401 · 0.030s | 401 · 0.027s |

Sustained beacon volume under outage: **20 of 20 → 500**.

## The open question, answered

The runbook's stated reason for existing:

> "every beacon route sits behind `throttle:analytics`, and throttle middleware is cache-backed.
> Does the throttle layer 500 *before* the controller's fail-open is ever reached?"

**Yes. The throttle layer 500s first. The controller is never reached.**

Stack trace under outage, top-down:

```
Illuminate\Routing\Middleware\ThrottleRequests->handle(Object(Illuminate\Http\Request), …)
  → Illuminate\Cache\RateLimiter->tooManyAttempts('d0646563995c920…', …)
    → Illuminate\Cache\RateLimiter->attempts('d0646563995c920…')
      → Illuminate\Cache\RedisStore->connection()
        → RedisException(code: 0): Connection refused
```

Corroborating counts from a clean log across the 20-beacon burst:

```
analytics.ingest.dispatch_failed breadcrumbs : 0     # controller never reached
ThrottleRequests->handle frames              : 120   # the blocking layer, every time
```

`QueuedIngestor::ingest()`'s documented fail-open (catch `Throwable` → warn → 2xx) is
**unreachable code during a full Redis outage**. The beacon data is still lost, but the request
now fails loudly with a 500 instead of succeeding quietly — the opposite of the designed behaviour.

## Evidence

Injection verification (this mattered — see Finding 6):

```
redis-cli SHUTDOWN NOSAVE  →  redis ping: PONG          # ← did NOT go down
brew services stop redis   →  Could not connect to Redis at 127.0.0.1:6379: Connection refused
                              redis procs: 0             # ← genuinely down
```

Response bodies under outage:

```
profile : {"message":"Connection refused"}
pageview: {"message":"Connection refused"}
authed* : {"error":"unauthenticated","message":"Invalid token"}
```

Recovery — no manual cache repair of any kind was performed:

```
brew services start redis → PONG
RECOVERED  profile   200  0.062579s
RECOVERED  pageview  201  0.040210s
RECOVERED  authed*   401  0.027471s
```

Static timeout evidence (Scenario C could not be executed, but this stands on its own):

```
config/database.php: no 'timeout' or 'read_timeout' key for any redis connection
runtime:             default: timeout=unset read_timeout=unset
                     cache:   timeout=unset read_timeout=unset
stack trace:         Redis->connect('127.0.0.1', '6379', 0.0, NULL, 0, 0.0)
                                                          ^^^            ^^^
                                          connect timeout ─┘   read timeout ┘   (0.0 = unbounded)
```

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| No probe hangs multi-second — failures are FAST whatever the status code | **PASS** | Every failing probe returned in **34–52 ms**. A refused socket fails immediately. This was the runbook's top-priority criterion and it is comfortably met *for the down case*. |
| Public profile reads survive (or the failure is understood + accepted in writing) | **FAIL** | 500. A Redis outage takes **every sitepage** down with it, despite the profile read being DB-backed. Documented here, not accepted. |
| Beacon fail-open works end-to-end (2xx) OR the blocking layer is identified precisely | **PARTIAL** | Fail-open does **not** work (20/20 → 500). The blocking layer *is* identified precisely: `ThrottleRequests` → `RateLimiter` → `RedisStore`. Criterion met only in its second clause. |
| Breadcrumb trail exists; escalation matches the trait's documented tiers | **FAIL** | **Zero** `analytics.ingest.dispatch_failed` breadcrumbs across 20 beacons — the code that emits them never runs. There is no breadcrumb trail for a full Redis outage. |
| Recovery is hands-off (except possibly Horizon restart) | **PASS** | All probes returned to baseline codes and timings on `brew services start redis` with no cache repair. Horizon needed a manual kill — see Finding 4. |
| No non-analytics data loss | **PASS (partial coverage)** | No data loss observed on the probes run. The optional enquiry-flow probe was **not run**, so write-path data-loss shape is unverified. |

**Overall: PARTIAL — with two FAILs that matter more than the PASSes.**

The system fails *fast and cleanly*, which is the good news. But it fails **closed** on the public
read path and on the beacon path, and it does so **silently** (no breadcrumbs).

## Findings

1. 🔴 **A Redis outage is a full public-site outage.** `throttle:public-site` sits in front of
   `GET /api/public/profiles/{handle}` and hard-fails on a dead cache store, so a DB-backed read
   that needs no Redis to produce its answer returns 500 anyway. Every sitepage goes down with
   Redis. **Not fixed** — the fix (a fail-open limiter, or moving the limiter off the cache store)
   is a design decision on the public wire and out of scope for a drill.
2. 🔴 **The analytics fail-open is unreachable during a full outage.** `QueuedIngestor::ingest()`'s
   catch-and-warn is real and correct, but nothing reaches it: `throttle:analytics` throws first.
   The "beacon data is lost by design, request succeeds" contract holds only when Redis is healthy
   enough to serve the limiter — i.e. exactly when it isn't needed. **Not fixed.**
3. 🟠 **phpredis timeouts are unbounded.** No `timeout`/`read_timeout` is configured on any redis
   connection, so phpredis uses `0.0` (unlimited) — visible in the trace as
   `connect('127.0.0.1','6379', 0.0, NULL, 0, 0.0)`. A *refused* socket fails instantly (measured),
   so this did not bite here — but a *hung or packet-dropping* server is the case with no bound at
   all, and that is the operationally worse failure the runbook explicitly warns about. Recommend
   explicit `REDIS_TIMEOUT` / `REDIS_READ_TIMEOUT`. **Not fixed** (touches shared config).
4. 🟠 **Horizon did not exit or self-heal on Redis loss.** The master dropped 15 → 10 processes and
   then spun in a reconnect loop, logging a `RedisException` stack trace per poll (it flooded
   `laravel.log` badly enough to bury the HTTP traces). A plain `pkill` did not stop it; `pkill -9`
   was needed. Per the runbook's own note, "a Horizon master that needs a human after a Redis blip
   is itself a finding for the deployed-env runbook" — recording it as exactly that. **Not fixed.**
5. **Scenario C (hung, not down) could not be executed.** `redis-cli DEBUG SLEEP` is refused on
   modern Redis (`ERR DEBUG command not allowed … enable-debug-command`). A substitute black-hole
   listener (`nc -k -l 6380` + `REDIS_PORT=6380`) also failed to reproduce a true hang — nc resets
   rather than holding the socket silently, producing a fast `read error on connection` instead.
   **The scenario the runbook calls its most valuable remains unmeasured.** Finding 3 is the
   static evidence that the risk it targets is real.
6. **`redis-cli SHUTDOWN NOSAVE` does not take Redis down on this machine.** Redis runs as a
   Homebrew service under launchd with KeepAlive, which restarts it within ~1s. The first DOWN
   probe round was accidentally run against a **live** Redis and produced a full set of
   plausible, entirely false PASSes (200/201, sub-100ms). Caught only by pinging Redis after
   injecting. Use `brew services stop redis`, and **always verify the injection landed**.
7. **`handle` and `handle_lc` can silently desync, and nothing prevents it.** Found during ARRANGE:
   overriding only `handle` left `handle_lc` at its factory value, and every public read path
   resolves on `handle_lc` — so the profile 404'd while looking perfectly correct in the DB and to
   any dashboard read. There is **no model mutator, no INSERT trigger, and no CHECK constraint**
   tying them (the two existing triggers fire only `ON UPDATE OF handle`); both columns are plain
   `$fillable`. Any write path that sets `handle` without `handle_lc` makes a site publicly
   unreachable, invisibly. **Flagged, not fixed** — it touches the public wire and deserves its own
   decision. This is the most consequential thing this drill turned up that it was not looking for.
8. **The optional enquiry-flow probe was not run** — the drill's write-path data-loss question
   (save-then-500 vs save-cleanly-without-email vs fail-entirely) is **unanswered**.

## Runbook corrections

Applied to `../03-redis-down.md` in the same commit as this log:

1. **Replace `redis-cli SHUTDOWN NOSAVE` with `brew services stop redis` as the primary INJECT**,
   and add a mandatory "verify the injection landed" step (`redis-cli ping` must return
   *Connection refused*). Without it the drill produces confident false PASSes (Finding 6).
2. **Fix the authed probe URL** — `api/site`, not `api/professional/site`. Note that without a
   valid Supabase JWT the probe is unauthenticated and its 401 proves nothing about resilience.
3. **Add the correct local Origin for the beacon** — it must match the site's subdomain on the
   *local* host (`http://<handle>.partna-drill.test`), not `…partna.au`, or SEC-1 fails it closed
   with 404 and the probe silently measures the wrong thing.
4. **Flag that `DEBUG SLEEP` is disabled by default** on modern Redis, so Scenario C needs
   `enable-debug-command local` in `redis.conf` + a restart. State that `nc` is not an adequate
   substitute.
5. **Add the throttle precondition** — `PARTNA_THROTTLE_ENABLED`/`SIDEST_THROTTLE_ENABLED` must be
   **true**, or the drill's central question is untestable and the run is worthless.

## Next run due

On material change to cache/queue wiring, analytics ingest, throttle middleware, or
`EscalatesRepeatedFaults`. **Re-run Scenario C specifically** once Findings 3 and 5 are addressed —
the hung-server case is still the one nobody has measured.
