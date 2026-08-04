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

- [ ] 🔴 **`APP_ENV=staging`, not `local`/`testing`.** `AppServiceProvider` binds
      `AnalyticsIngestor` to `SyncIngestor` (writes straight to Postgres, never touches Redis)
      when `app()->environment()` is `local` or `testing`
      (`app/Providers/AppServiceProvider.php:168-173`). A local drill run under the default
      `APP_ENV=local` therefore tests nothing about the queued fail-open path — it produced a
      clean-looking false PASS (zero `analytics.ingest.dispatch_failed` breadcrumbs, `201`) on
      2026-08-05 before this was caught. Set `APP_ENV=staging` and assert the binding flipped
      before trusting any beacon probe:
      `php artisan tinker --execute='echo get_class(app(\App\Services\Analytics\Contracts\AnalyticsIngestor::class));'`
      → must print `App\Services\Analytics\Ingestors\QueuedIngestor`.
- [ ] 🔴 **Ordering trap: start Horizon BEFORE flipping to `staging`, not after.**
      `config/horizon.php`'s `environments` key only defines `production`, `development`,
      `local` (no `staging`, no `*` wildcard). Run `php artisan horizon` under `APP_ENV=staging`
      and it prints "Horizon started successfully." while running **zero** supervisors — a
      silent false PASS that also breaks this runbook's own "Horizon running" precondition
      below and the OBSERVE step that watches Horizon die/recover. Fix: start Horizon while
      still on `APP_ENV=local` (`php artisan horizon`), THEN flip `.env` to `APP_ENV=staging`
      and `php artisan config:clear` before running any probes — Horizon itself does not need
      to be restarted after the flip. Verify before trusting the drill:
      `php artisan tinker --execute='var_dump(array_keys(config("horizon.environments")));'`
      confirms `staging` is absent, and `php artisan horizon:status` / the Horizon dashboard
      confirms supervisors are actually running.
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

# 4. liveness probe — belongs in the standard set, not as an afterthought. Was throttled until
#    2026-08-05 (throttle:health-check, cache-backed); now deliberately unthrottled by design
#    (routes/api.php:29-30, ~89), so a slow /api/health today would indicate something other
#    than the limiter — still worth carrying through every probe round for that reason.
curl -s -o /dev/null -w "health   %{http_code}  %{time_total}s\n" "$BASE/api/health"

# 5. public enquiry submit — subdomain resolves from Origin, same as the pageview beacon;
#    dispatch-heavy (notification jobs to Redis), and the one probe that measured closest to
#    its `read_timeout` in the 2026-08-05 run, so it's the cleanest signal for "did the
#    timeout actually take effect on this path".
curl -s -o /dev/null -w "enquiry  %{http_code}  %{time_total}s\n" \
  -X POST "$BASE/api/public/enquiry" \
  -H 'Content-Type: application/json' \
  -H "Origin: http://<handle>.partna-drill.test" \
  -d '{"name": "Drill", "email": "drill@example.com", "subject": "Drill", "message": "drill drill drill"}'
```

All should be 2xx with sub-second times. Record them. `health` and `enquiry` are part of the
standard probe set alongside `profile`/`pageview`/`authed` — carry all five through OBSERVE and
Scenario C, not just the original three.

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

Re-run all five baseline curls (`profile`, `pageview`, `authed`, `health`, `enquiry`) **and
time them**. For each, record `http_code` + `time_total`. Then:

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
4. **`/api/health`** — standard probe, not optional. It's documented liveness-only and meant
   to have no dependencies. It sat behind `throttle:health-check` (cache-backed) until
   2026-08-05; it's now deliberately unthrottled (`routes/api.php:29-30, ~89`), so a slow
   `/api/health` under a Redis problem is no longer a throttle-layer finding — it would now
   indicate something else has a Redis dependency on the liveness path, which is itself worth
   chasing down (a load balancer treating a slow liveness check as "dead" over any cache
   problem is still the operational risk either way).
5. **Horizon terminal** — did the master crash, spin in reconnect loops, or exit cleanly?
6. **Log trail**: `tail -20 storage/logs/laravel.log` — expect
   `analytics.ingest.dispatch_failed` breadcrumbs (if beacons reached the controller) and
   connection-refused exceptions elsewhere. Fire ~20 beacon POSTs in a loop to give Tier 2
   sampling a chance; absence of a `report()` at n=20 is expected (1-in-200), presence is
   a bonus. The *breadcrumbs* are the required evidence.
7. **`POST $BASE/api/public/enquiry`** — standard probe, not optional. Sits behind
   `throttle:leads` + `bot.token:enquiry`, both potential Redis touchpoints, and dispatches
   notification jobs to Redis. Does the enquiry save-then-500, save-cleanly-without-email, or
   fail entirely? This is a data-loss-shape judgment call — record exactly what happened.

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

🔴 **Fire all five probes in PARALLEL, not in sequence.** Sequential probes consume the hang
window themselves — five curls run one after another against a 30–40s hang means the later
ones execute after Redis has already recovered, and silently measure a healthy Redis instead
(a fourth variant of the same trap this section already warns about for the witness). This is
not hypothetical: the 2026-08-05 run's first sequential attempt produced `authed 200 · 0.05s`,
which was meaningless. Background every probe, plus the witness, then `wait`:

```bash
(redis-cli -t 60 DEBUG SLEEP 40 &) ; sleep 0.3
( S=$(perl -MTime::HiRes=time -e 'print time'); redis-cli -t 60 ping >/dev/null; \
  E=$(perl -MTime::HiRes=time -e 'print time'); \
  perl -e "printf(\"WITNESS: redis hung %.2fs\n\", $E-$S)" ) &

curl -s -o /dev/null -w "enquiry  %{http_code}  %{time_total}s\n" -X POST "$BASE/api/public/enquiry" \
  -H 'Content-Type: application/json' -H "Origin: http://<handle>.partna-drill.test" \
  -d '{"name":"Drill","email":"drill@example.com","subject":"Drill","message":"drill drill drill"}' &
curl -s -o /dev/null -w "health   %{http_code}  %{time_total}s\n" "$BASE/api/health" &
curl -s -o /dev/null -w "profile  %{http_code}  %{time_total}s\n" "$BASE/api/public/profiles/<handle>" &
curl -s -o /dev/null -w "pageview %{http_code}  %{time_total}s\n" -X POST "$BASE/api/public/analytics/pageviews" \
  -H 'Content-Type: application/json' -H "Origin: http://<handle>.partna-drill.test" \
  -d '{"subdomain": "<handle>"}' &
curl -s -o /dev/null -w "authed   %{http_code}  %{time_total}s\n" -H "Authorization: Bearer $TOKEN" "$BASE/api/site" &

wait
```

Also re-measure each probe **alone**, one per hang, to rule out contention as an explanation
before trusting the parallel numbers.

**Measured reality (2026-08-05) — only `enquiry` is fast; everything else is not:**

| Probe | Time (against a ~40s hang) |
|---|---|
| `enquiry` | **3.04s** |
| `health` | **9–10s** |
| `profile` | **18.3s** |
| `pageview` (beacon) | **29.3s** |
| `authed` | **32.0s** |

Two compounding causes, both confirmed — do not stop at "the timeout regressed":

1. **At the time of this drill, the request path used the `default` connection (DB 0), bounded
   at 15s, not 3s.** `TokenRevocationService` called the bare `Redis::` facade with no
   `connection()` call, so every authenticated request's session-blocklist/tracking calls
   landed on `default` — the same connection queue workers use, deliberately bounded at 15s
   because it must exceed the queues' `block_for`. `authed` at 32.0s ≈ 2 × 15s on `default`.
   **Fixed later this branch:** `TokenRevocationService::redis()` now explicitly resolves
   `Redis::connection('app')` (`app/Services/Auth/TokenRevocationService.php:473-476`,
   3.0s read_timeout) — same DB 0, so the blocklist's FLUSHDB exposure is unchanged, but a
   re-run of this drill would no longer reproduce this exact `authed` number via this cause.
2. **`read_timeout` bounds one *operation*, not one *request*.** A request making N sequential
   Redis calls inherits up to N × `read_timeout`. `/api/health` sits behind
   `throttle:health-check`, whose limiter performs ~3 ops on connections correctly bounded at
   3s — and still takes 9–10s, because the ops stack.

If a request-path probe hangs past its connection's nominal `read_timeout`, don't assume the
timeout regressed — find out which connection is actually serving the request and how many
Redis operations it makes per request. Stacking on the wrong connection, not a broken timeout,
is the more likely explanation.

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
