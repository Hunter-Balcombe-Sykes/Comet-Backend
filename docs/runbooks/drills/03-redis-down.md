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
curl -s -o /dev/null -w "pageview %{http_code}  %{time_total}s\n" \
  -X POST "$BASE/api/public/analytics/pageviews" \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://<handle>.partna.au' \
  -d '{"subdomain": "<handle>"}'

# 3. an authed dashboard read (grab a real bearer token from the frontend session)
curl -s -o /dev/null -w "authed   %{http_code}  %{time_total}s\n" \
  -H "Authorization: Bearer $TOKEN" "$BASE/api/professional/site"
```

All should be 2xx with sub-second times. Record them.

## INJECT

```bash
redis-cli SHUTDOWN NOSAVE      # hard-down, closest to a real crash
```

(`brew services stop redis` if the shutdown command is refused.)

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

Immediately re-run the baseline curls. If requests hang for the full 15s, PHP's Redis
timeouts (`REDIS_*` / phpredis `timeout`/`read_timeout`) are effectively unbounded — file
as a finding with the measured hang times. This scenario frequently produces the drill's
only real action item.

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
