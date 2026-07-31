# Drill 03 — Redis down

**Question:** Redis backs the cache (DB 0), sessions (DB 1) and queue (DB 2) through one
server process. When it dies, do public reads and analytics beacons degrade gracefully —
or does the site 500-cascade? And when Redis comes back, does everything recover without
manual repair?

## What the code SAYS happens (hypotheses to verify)

- **Analytics ingest fails open.** `QueuedIngestor::ingest()` catches the dispatch
  `Throwable` → `Log::warning('analytics.ingest.dispatch_failed')` breadcrumb → beacon
  data is *lost by design*, request succeeds.
- **Sustained-outage escalation.** `EscalatesRepeatedFaults`: Tier 1 counts faults in a
  RateLimiter bucket (5 in 600s → one `report()`), **but the counter itself is
  Redis-backed** — with Redis fully down Tier 1 throws and falls back to Tier 2's
  stateless 1-in-200 sample. So during THIS drill expect *sampled* escalation (may not
  fire at low volume — that's the documented honest limit, not a failure).
- **The open question the drill exists to answer:** every beacon route sits behind
  `throttle:analytics`, and throttle middleware is cache-backed. Does the throttle layer
  500 *before* the controller's fail-open is ever reached? Same question for
  `throttle:public-site` in front of the public profile read, and for any cache-backed
  read in `SiteCacheService`. Nothing in the test suite answers this — the middleware is
  the layer `Http::fake`-style tests never exercise against a dead socket.
- **Failure speed matters as much as failure shape.** phpredis with default timeouts can
  *hang* rather than fast-fail. A 30s hang per request is operationally worse than a clean
  500. Time every failing request.

## Preconditions

- [ ] Local `.env`: `CACHE_STORE=redis` (NOT a failover store — that silently breaks the
      escalation invariant), `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=cookie`.
- [ ] 🔴 **Throttle must be ON**: `PARTNA_THROTTLE_ENABLED=true` (or `SIDEST_THROTTLE_ENABLED=true`).
      The local `.env` has historically shipped this **false**. The throttle layer is the layer
      under test — with it off, every probe passes and the drill proves nothing. Verify:
      `php artisan tinker --execute='var_dump(config("partna.throttle.enabled"));'`
- [ ] A drill user with an active site (reuse the pattern from drill 01) — note its
      `handle` and `site_id`.
- [ ] Horizon running (`php artisan horizon`) — part of the drill is watching it die and
      come back.
- [ ] `BASE=<local site URL>` (from `herd links`), e.g. `export BASE=https://backend.test`.

## BASELINE — record before injecting

```bash
# 1. public profile read (edge-uncached, straight to Laravel)
curl -s -o /dev/null -w "profile  %{http_code}  %{time_total}s\n" "$BASE/api/public/profiles/<handle>"

# 2. analytics beacon (Origin required — SEC-1 rejects header-less callers)
#    ⚠️ The Origin must match the site's subdomain on the LOCAL host, not partna.au —
#    SEC-1 fails closed with 404 "Site not found" otherwise, and you silently measure
#    the wrong thing. Locally that is <handle>.<your herd site host>.
curl -s -o /dev/null -w "pageview %{http_code}  %{time_total}s\n" \
  -X POST "$BASE/api/public/analytics/pageviews" \
  -H 'Content-Type: application/json' \
  -H "Origin: http://<handle>.partna-drill.test" \
  -d '{"subdomain": "<handle>"}'

# 3. an authed dashboard read (grab a real bearer token from the frontend session)
#    ⚠️ The route is api/site — api/professional/site does not exist (404 "Endpoint not found").
#    Without a valid Supabase JWT this probe runs unauthenticated and returns 401; that 401
#    is NOT evidence of resilience, because auth rejects before the throttle layer is reached.
curl -s -o /dev/null -w "authed   %{http_code}  %{time_total}s\n" \
  -H "Authorization: Bearer $TOKEN" "$BASE/api/site"
```

All should be 2xx with sub-second times. Record them.

## INJECT

```bash
brew services stop redis       # primary — unloads the launchd agent so it STAYS down
```

🔴 **`redis-cli SHUTDOWN NOSAVE` does NOT work when Redis runs as a Homebrew service.** launchd
has `KeepAlive` set and restarts it within ~1s. The 2026-07-31 run did this and collected a full
set of plausible, entirely false PASSes (200/201, sub-100ms) against a **live** Redis.

**Always verify the injection landed before probing:**

```bash
redis-cli ping           # MUST print: Could not connect … Connection refused
ps aux | grep -c '[r]edis-server'   # MUST be 0
```

If `ping` returns `PONG`, you are measuring nothing. Do not proceed.

## OBSERVE

Re-run all three baseline curls **and time them**. For each, record `http_code` +
`time_total`. Then:

1. **Beacon POST** — the key probe. Outcomes, best → worst:
   - 2xx, fast → throttle survived or failed open AND ingest failed open. Best case.
   - 5xx, fast (<1s) → throttle or ingest failed closed but *cleanly*. A finding to fix,
     operationally tolerable.
   - anything, slow (multi-second hang) → connection timeouts are misconfigured. The most
     important possible finding of this drill.
2. **Public profile GET** — does the read path have a Redis dependency that hard-fails a
   *DB-backed* read? A 500 here means a Redis outage takes every sitepage down with it.
3. **Authed endpoint** — expected to be the most fragile (throttle + any cache reads).
   Record, judge against "acceptable degraded".
4. **Horizon terminal** — did the master crash, spin in reconnect loops, or exit cleanly?
5. **Log trail**: `tail -20 storage/logs/laravel.log` — expect
   `analytics.ingest.dispatch_failed` breadcrumbs (if beacons reached the controller) and
   connection-refused exceptions elsewhere. Fire ~20 beacon POSTs in a loop to give Tier 2
   sampling a chance; absence of a `report()` at n=20 is expected (1-in-200), presence is
   a bonus. The *breadcrumbs* are the required evidence.
6. **A dispatch-heavy user flow** (optional but valuable): submit a public enquiry
   (`POST $BASE/api/public/enquiry` — note it also sits behind `throttle:leads` +
   `bot.token:enquiry`, both potential Redis touchpoints) — it dispatches notification
   jobs to Redis. Does the enquiry save-then-500, save-cleanly-without-email, or fail
   entirely? This is a data-loss-shape judgment call — record exactly what happened.

## RECOVER

```bash
brew services start redis   # or: redis-server /opt/homebrew/etc/redis.conf --daemonize yes
```

1. Re-run the three baseline curls → all back to baseline codes/times with **no manual
   cache repair**.
2. Horizon: if the master died, restart it (`php artisan horizon`) and note that a real
   deployment needs a supervisor to do this — a Horizon master that needs a human after a
   Redis blip is itself a finding for the deployed-env runbook.
3. Verify no poisoned state: beacon POST lands (check `analytics` queue drains in
   Horizon), profile read serves fresh, `php artisan queue:failed` reviewed.
4. Data-loss accounting: beacons fired during the outage are gone (by design — fail-open).
   Anything ELSE lost (enquiries? jobs?) goes in the log as a finding.

## Optional Scenario C — Redis hung, not down

Worse than dead: a server that accepts connections but doesn't answer.

```bash
redis-cli DEBUG SLEEP 15 &
```

🔴 **`DEBUG` is disabled by default on modern Redis** — this returns
`ERR DEBUG command not allowed. If the enable-debug-command option is set to "local" …`
and does nothing. Silently. To actually run this scenario you must set
`enable-debug-command local` in `redis.conf` and restart Redis first. **Verify it blocks**
before trusting any timing:

```bash
(redis-cli DEBUG SLEEP 6 &) ; sleep 0.5 ; time redis-cli ping   # must take >5s
```

🔴 **Run that check against its OWN injection, never inline before the probes.** The verifying
`ping` blocks for the *whole* sleep, so if you run it immediately before the curls it consumes the
hang window and the probes execute against a **recovered** Redis — returning a full set of fast,
healthy, entirely false PASSes. This bit the 2026-07-31 second pass (profile `200 · 0.039s` during a
supposedly hung Redis). In the real round, start the witness **concurrently with** the probes:

```bash
(redis-cli -t 60 DEBUG SLEEP 30 &) ; sleep 0.3
( S=$(perl -MTime::HiRes=time -e 'print time'); redis-cli -t 60 ping >/dev/null; \
  E=$(perl -MTime::HiRes=time -e 'print time'); \
  perl -e "printf(\"witness: hung %.2fs\n\", $E-$S)" ) &
# …probes here, then `wait` and read the witness
```

`nc -k -l <port>` is **not** an adequate substitute — it resets the connection instead of
holding it open silently, producing a fast `read error on connection` rather than a hang.

🔴 **Do not pass drill overrides on the `php artisan serve` command line.**
`ServeCommand::$passthroughVariables` whitelists 14 variables and maps every other one to `false`,
unsetting it in the child process — so `REDIS_READ_TIMEOUT=0 php artisan serve …` silently serves
the *unmodified* config. Put overrides in `.env` and confirm with
`php artisan tinker --execute='var_dump(config("database.redis.cache.read_timeout"));'` first.

Immediately re-run the baseline curls and record the elapsed times.

**Expected as of 2026-07-31 (post-`B4`):** request-path probes fail at **~3s** with
`read error on connection` and a 500 — that is `read_timeout 3.0` on the `cache` connection doing
its job. Measured: 6/6 probes at 3.03–3.06s against 25–30s hangs. Worker-path connections
(`default`, `queue`) are bounded at **15.0s** instead, because they must exceed the queues'
`block_for`.

If a request-path probe hangs **past 3s**, `B4` did not take effect on the connection actually
serving it — find out which connection that is rather than raising the timeout. If it hangs for the
full sleep duration, the timeouts are unbounded again (pre-`B4` behaviour measured at
**19.84s against a 19.76s hang**, returning 200 — it waits exactly as long as Redis is unresponsive).

## Pass criteria

- [ ] No probe hangs multi-second — failures are FAST, whatever their status code
- [ ] Public profile reads survive (or the failure is understood + accepted in writing)
- [ ] Beacon fail-open works end-to-end (2xx) OR the blocking layer is identified precisely
- [ ] Breadcrumb trail exists; escalation behavior matches the trait's documented tiers
- [ ] Recovery is hands-off (except possibly Horizon restart — note it)
- [ ] No non-analytics data loss

## RESTORE

Redis running, Horizon running, drill user deleted, any loop artifacts cleaned. Nothing to
revert in `.env` unless you changed it.

## Record

`logs/<YYYY-MM-DD>-redis-down.md` — the response-code + timing table (baseline / down /
recovered × 3 probes) is the core artifact; plus Horizon behavior, log evidence, Scenario C
timings if run, and the enquiry-flow judgment.
