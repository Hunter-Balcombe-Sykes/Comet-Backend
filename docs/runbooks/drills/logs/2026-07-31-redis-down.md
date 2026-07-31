# Drill log — 03 Redis down

- **Date:** 2026-07-31
- **Runbook:** [../03-redis-down.md](../03-redis-down.md) (at commit `49ec65a1`; repo HEAD `b19ca5d3`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test`, local Supabase
  (`supabase_db_Partna-Development`), local Redis 6379 (Homebrew service).
- **Preconditions met:** `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=cookie`,
  `partna.throttle.enabled=true` (**had to be turned on** — local `.env` shipped
  `SIDEST_THROTTLE_ENABLED=false`, which would have made this drill prove nothing), Horizon running.
- **Scenarios run:** BASELINE / DOWN / RECOVER. **Scenario C (hung, not down) executed in a second
  pass later the same day** — see "Scenario C" below. Findings 3 and 5 are both now **RESOLVED**.

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

## Scenario C — Redis hung, not down (second pass, same day)

Run after `B4` landed, so this pass measures **both** the pre-fix and post-fix behaviour rather than
only confirming a risk. Environment differs from the first pass in one respect: the app was served by
`php artisan serve` on `127.0.0.1:8787` from an isolated worktree (branch
`audit-fix/drill-followups-2026-07-31`), not the Herd site, so the bound under test is the one in the
branch. Local Supabase + local Redis as before; `PARTNA_THROTTLE_ENABLED=true`, `CACHE_STORE=redis`,
`QUEUE_CONNECTION=redis`, `SESSION_DRIVER=cookie`.

### Injection — verified this time

`enable-debug-command local` set in `/opt/homebrew/etc/redis.conf` + `brew services restart redis`
(config restored afterwards). Proof it blocks, per the runbook's own instruction:

```
(redis-cli DEBUG SLEEP 6 &) ; sleep 0.5 ; time redis-cli ping   →  ping blocked for 5.55s
```

Every probe round below carries its own **concurrent hang witness** — a `redis-cli ping` started
alongside the probes and timed independently — so each measurement is paired with proof that Redis
was genuinely hung for the whole window. Witness values were 29.75s / 19.76s / 19.77s against
`DEBUG SLEEP` values of 30 / 20 / 20.

### The measurement

| Config | Redis hung for | `GET /api/public/profiles/{handle}` | `POST …/analytics/pageviews` |
|---|---|---|---|
| **Unbounded** (`read_timeout=0.0`, pre-B4) | 19.76s (witness) | **200 · 19.842s** | not run |
| **Bounded** (`read_timeout=3.0`, B4) | 29.75s (witness) | **500 · 3.057s** | **500 · 3.036s** |
| Bounded, repeat ×3 | 25s each | 500 · 3.056 / 3.039 / 3.043s | 500 · 3.033 / 3.036 / 3.034s |
| RECOVERED (hands-off) | — | 200 · 0.120s | 201 · 0.066s |

Baseline before injection: profile `200 · 0.106s`, pageview `201 · 0.070s`, authed `401 · 0.029s`.

Both timeout bands measured directly against a 40s hang, confirming the split is real and not just
present in `config()`:

```
cache    (request path, 3.0)  → actual  3.00s  RedisException: read error on connection
default  (worker path, 15.0)  → actual 15.00s  RedisException: read error on connection
```

### What this answers

**The hang duration, unbounded, is the hang duration.** With `read_timeout=0.0` the request waited
**19.84s** against a 19.76s hang and then returned **200**. It did not time out, degrade, or fail — it
waited exactly as long as Redis was unresponsive. A 60s hang would have held the request 60s; an
indefinite one, indefinitely. That is the number Scenario C existed to produce and never had.

**The bound holds, exactly.** 6/6 probes at **3.03–3.06s** against hangs of 25–30s. The failure is
`read error on connection to 127.0.0.1:6379` surfaced as a 500. Recovery is hands-off.

**The trade is real and worth stating.** Unbounded eventually returned **200**; bounded returns
**500**. B4 converts "correct after an unbounded wait" into "fails fast at 3s". A Redis hiccup shorter
than 3s is still absorbed transparently; anything longer now fails fast rather than holding a
PHP-FPM worker hostage. That is the intended trade — a held worker under a real hang is how a single
slow dependency becomes a full outage — but it is a behaviour change, not a pure win.

**The public-read path is unchanged in kind.** Finding 1 (a Redis outage is a full public-site
outage) is *not* affected by B4: the profile read still 500s, just at 3s instead of never. B4 bounds
the failure; it does not fail open. `B1`/`B2` remain the fix for that and are out of scope here.

### New traps found in this pass

9. **The runbook's own verification step destroys the injection window if run inline.**
   `(redis-cli DEBUG SLEEP 6 &) ; sleep 0.5 ; time redis-cli ping` is correct as a *prerequisite*
   check, but the verifying `ping` **blocks for the full sleep**. Run it immediately before the
   probes and it consumes the hang; the probes then execute against a recovered Redis and return a
   full set of fast, healthy, entirely false PASSes. This happened on the first attempt here
   (profile `200 · 0.039s` during a "hung" Redis). Verify with a **separate** injection, and during
   the real round put the witness **concurrent with** the probes, never before them.
10. **`php artisan serve` silently discards environment overrides.**
    `REDIS_READ_TIMEOUT=0 php artisan serve …` has **no effect**:
    `Illuminate\Foundation\Console\ServeCommand::$passthroughVariables` whitelists 14 variables
    (`APP_ENV`, `PATH`, the `HERD_*` and `XDEBUG_*` sets, …) and maps every other variable to
    `false`, which unsets it in the child process. The app then reads the `.env` value and the
    control run silently measures the *unmodified* config. Caught here only because the "unbounded"
    control returned 3.05s — identical to the bounded run. Put drill overrides in `.env`, and
    confirm with `php artisan tinker --execute='...config(...)'` before trusting a control.

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
3. ✅ **RESOLVED — phpredis timeouts were unbounded.** No `timeout`/`read_timeout` was configured on
   any redis connection, so phpredis used `0.0` (unlimited) — visible in the trace as
   `connect('127.0.0.1','6379', 0.0, NULL, 0, 0.0)`. A *refused* socket fails instantly (measured),
   so this did not bite in the DOWN scenario — but a *hung or packet-dropping* server is the case
   with no bound at all, and Scenario C has now measured it: **19.84s of hang held the request for
   19.84s**.
   **Fixed by `B4`** (`fix(redis): B4 — bound phpredis connect and read timeouts`): all five
   connections carry `timeout 2.0`; request path (`cache`, `session`, `cache_locks`)
   `read_timeout 3.0`, worker path (`default`, `queue`) `read_timeout 15.0` — the latter must stay
   above the queues' `block_for` (default 5) or every worker throws `read error on connection`
   silently, into the log only. Re-measured under a live hang: **3.06s** on the request path,
   **15.00s** on the worker path. Pinned by `tests/Feature/Architecture/RedisTimeoutBoundsTest.php`.
4. 🟠 **Horizon did not exit or self-heal on Redis loss.** The master dropped 15 → 10 processes and
   then spun in a reconnect loop, logging a `RedisException` stack trace per poll (it flooded
   `laravel.log` badly enough to bury the HTTP traces). A plain `pkill` did not stop it; `pkill -9`
   was needed. Per the runbook's own note, "a Horizon master that needs a human after a Redis blip
   is itself a finding for the deployed-env runbook" — recording it as exactly that. **Not fixed.**
5. ✅ **RESOLVED — Scenario C (hung, not down) is now executed and measured.** It could not be run in
   the first pass: `redis-cli DEBUG SLEEP` is refused on modern Redis
   (`ERR DEBUG command not allowed … enable-debug-command`), and a substitute black-hole listener
   (`nc -k -l 6380` + `REDIS_PORT=6380`) does not reproduce a true hang — nc resets rather than
   holding the socket silently, producing a fast `read error on connection` instead.
   **Executed in the second pass** by setting `enable-debug-command local` in `redis.conf` and
   restarting Redis, with the block proven (`ping` held 5.55s) and a concurrent hang witness on every
   round. Results in "Scenario C" above. Two further silent-no-op traps were found in the process —
   see new Findings 9 and 10; the runbook's own inline verification step was one of them.
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
`EscalatesRepeatedFaults`.

Scenario C is **done** — Findings 3 and 5 are resolved and the hung-server case is measured
(19.84s unbounded → 3.06s bounded). Re-run it specifically if `read_timeout` or any queue's
`block_for` changes, since the two are coupled and `RedisTimeoutBoundsTest` pins only their
*relative* order, not that either value is still operationally right.

Still open from this drill, unchanged by the second pass: Finding 1 (a Redis outage is a full public
site outage), Finding 2 (the analytics fail-open is unreachable) — both belong to `B1`/`B2`;
Finding 4 (Horizon does not self-heal); Finding 7 (`handle`/`handle_lc` desync); Finding 8 (the
enquiry-flow write-path probe was never run).
