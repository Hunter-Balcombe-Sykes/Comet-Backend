# Drill log — 03 Redis down

- **Date:** 2026-08-06 (AEST)
- **Runbook:** [../03-redis-down.md](../03-redis-down.md)
- **Purpose:** re-run after the 2026-08-05 auth/resilience merge (`b9c66318` → `c4283f5a`), per
  `docs/superpowers/plans/2026-08-05-drills-rerun-EXECUTE-PROMPT.md`. Confirm the four merged
  changes behave as designed and refresh the baseline.
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `drills/rerun-2026-08-06` @ `de8fcff7` (branched from local `development`)
- **Environment:** LOCAL — worktree `backend-wt/drills-rerun-2026-08-06`, local Supabase stack
  (`Partna-Development` containers incl. real GoTrue), Homebrew Redis 6379, Horizon 5 supervisors,
  `php artisan serve` on `127.0.0.1:8000`.
- **Variants run:** full outage (`brew services stop redis`) **and** Scenario C (hung Redis via
  `DEBUG SLEEP`), the latter measured three ways — see the Scenario C section.

## Why this base commit, not `origin/development`

`origin/development` was 22 commits ahead (`ae63fb857`) and carries four migrations the local
Supabase DB does not have, including `CREATE TABLE content.item_links`. Drilling it would have
thrown `42P01`/`42703` on the very public endpoints this drill probes — a false FAIL dressed as a
resilience finding. The drift was verified **drill-irrelevant** first: `revocation.strict` wiring is
byte-identical across `routes/api/staff.php`, `routes/api/user.php` and `bootstrap/app.php`, and the
`AnalyticsIngestor` binding in `AppServiceProvider` is untouched.

## Preconditions — verified, not assumed

| Precondition | Command | Verified value |
|---|---|---|
| `APP_ENV` | `config('app.env')` | **`staging`** — not `local` |
| Analytics ingestor binding | `get_class(app(AnalyticsIngestor::class))` | **`QueuedIngestor`** |
| — trap confirmed live | same, under `APP_ENV=local` | `SyncIngestor` (would have made every beacon probe vacuous) |
| `CACHE_STORE` | `config('cache.default')` | `redis` |
| `QUEUE_CONNECTION` | `config('queue.default')` | `redis` |
| `SESSION_DRIVER` | `config('session.driver')` | `cookie` |
| `config('partna.throttle.enabled')` | tinker | **`true`** (local `.env` ships `false`; forced on) |
| `config('partna.public_domain')` | tinker | `localhost` ⇒ beacon `Origin: http://drill-rd-20260806.localhost` |
| Redis keyspace | `.env` | `REDIS_DB=0`, `CACHE_DB=1`, `SESSION_DB=2`, `QUEUE_DB=3`, locks `4`; `REDIS_PASSWORD` cleared |
| Horizon | started under `APP_ENV=local` **before** the flip to `staging` | master 1, **supervisors 5** |
| `horizon.environments` | tinker | `production`, `development`, `local` — **no `staging`**, confirming the ordering trap is still live |
| Authed probe | minted ES256 JWT from local GoTrue admin API | `sub=359c37cb-…` **matches** `core.users.auth_user_id`; `session_id` present; `aal1`; `email_verified=true`; baseline **200**, not 401 |
| Injection | `redis-cli ping` / `lsof -nP -iTCP:6379 -sTCP:LISTEN` | *Connection refused* / **0 listeners** |

Drill user `drill-rd-20260806` (`user_id=804c181d-…`, `site_id=019fd428-…`, `is_published=true`),
auth user created through GoTrue's admin API so the FK to `auth.users` and the JWT are both real.

**Token minting:** the password grant is unusable — local GoTrue returns
`captcha_failed: request disallowed (no captcha_token found)`. The admin `generate_link` path (magic
link → follow without redirect → read `access_token` from the `Location` fragment) is not
captcha-gated and is what `mint-drill-token.sh` uses.

## BASELINE — Redis healthy, throttle ON, parallel

Token minted 09:06:26.

| Probe | Status | Time |
|---|---|---|
| pageview beacon | 201 | 0.045 s |
| authed `GET /api/site` | 200 | 0.091 s |
| profile (public) | 200 | 0.165 s |
| **STRICT `POST /api/sessions/logout-others`** | 200 | 0.197 s |
| health | 200 | 0.225 s |
| enquiry | 200 | 0.276 s |

## OBSERVE — Redis DOWN, throttle ON, parallel

Injection verified before probing (see table above). Fired 09:06:50.

| Probe | Status | Time | Notes |
|---|---|---|---|
| authed `GET /api/site` (non-strict control) | **503** | 0.046 s | **not** a 500 — the 2026-08-05 fix holds |
| health | 200 | 0.067 s | unthrottled by design |
| **STRICT `logout-others`** | **503** | 0.097 s | attributed below |
| profile (public) | 200 | 0.159 s | public reads unaffected |
| pageview beacon | 201 | 0.187 s | fail-open works end to end |
| enquiry | **503** | 0.212 s | attributed below |

Everything degrades **fast** — nothing above 0.25 s. Breadcrumbs across the round:
5 × `redis.request_breaker.opened`, 2 × `RateLimiterUnavailableException`,
1 × `RevocationUnverifiableException`, 1 × `auth.revocation_unverified_on_strict_route`,
1 × `analytics.ingest.dispatch_failed`.

### Attribution — the point of this re-run

A 503 alone cannot say which layer answered: `RevocationUnverifiableException` and
`RateLimiterUnavailableException` are byte-identical on the wire. Log truncated, **one** probe
fired, then grepped:

| Probe | Status | Time | `auth.revocation_unverified_on_strict_route` | `RateLimiterUnavailableException` | Verdict |
|---|---|---|---|---|---|
| **STRICT `logout-others`** | 503 | **0.037 s** | **1** | **0** | **the GATE answered** ✅ |
| non-strict `GET /api/site` | 503 | 0.033 s | 0 | **1** | the limiter answered (control, unchanged) |
| enquiry | 503 | 0.031 s | 0 | **1** | the limiter answered (`throttle:leads`) |

**The priority pin holds.** `RateLimiterUnavailableException` does not appear on the strict probe at
all — the exact inversion of the 2026-08-05 pre-fix run, where the limiter was the only thing that
fired and the gate never ran. The non-strict control being unchanged is the important half: the
priority-list edit is global, so "did it move anything else" needed an answer rather than an
assumption.

### Horizon

Master and all **5 supervisors survived** the outage. Worker *children* exited (they cannot reach
Redis) and respawned automatically on recovery — master 1 / supervisors 5 / workers 5, with no
manual repair. See finding 4: the runbook's liveness command reads this as a crash.

### Data-loss accounting

| Data | Outcome |
|---|---|
| Analytics beacons during the outage | lost **by design** (fail-open, `analytics.ingest.dispatch_failed`) |
| **Enquiries during the outage** | **lost** — 503 from `throttle:leads` *before* the controller, so nothing half-written. Clean shape, but the lead is gone. See finding 3. |
| Jobs | none failed — `queue:failed` has one entry dated 2026-08-04, a leftover from the previous drill 02 |

Drill-site enquiries persisted: 2, both timestamped in the baseline rounds (23:05:13Z, 23:06:27Z),
none during the outage window.

## RECOVER

`brew services start redis` → `PONG`. All probes back to baseline, hands-off:

| Probe | Status | Time |
|---|---|---|
| authed | 200 | 0.041 s |
| health | 200 | 0.062 s |
| pageview | 201 | 0.090 s |
| STRICT `logout-others` | 200 | 0.115 s |
| profile | 200 | 0.179 s |
| enquiry | 200 | 0.215 s |

## Scenario C — Redis hung, not down

`enable-debug-command local` added to `/opt/homebrew/etc/redis.conf` + restart; reverted in RESTORE.
Blocking verified against its **own** injection (verifying `ping` blocked 5.54 s against a
`DEBUG SLEEP 6`), never inline before the probes.

Measured three ways, because the first result was a measurement artifact and not a system property:

**Round 1 — "parallel" probes, `artisan serve` as normally started (witness: hung 39.76 s):**

| Probe | Time |
|---|---|
| health | 0.028 s |
| profile | 3.094 s |
| authed | 6.135 s |
| pageview | 9.173 s |
| STRICT-lo | 12.219 s |
| enquiry | 15.258 s |

Increments of 3.06 / 3.04 / 3.04 / 3.05 / 3.04 s — a metronome, not six independent bounds. That is
the signature of **serialisation**, and it mimics N × `read_timeout` stacking almost perfectly: the
precise failure mode Scenario C exists to detect. See finding 2.

**Round 2 — solo probes, one hang each (the runbook's contention control):**

| Probe | Status | Time | Runbook expectation |
|---|---|---|---|
| health | 200 | **0.029 s** | < 0.1 s ✅ |
| profile | 200 | **3.075 s** | ~3 s ✅ |
| authed | **503** | **3.043 s** | ~3 s ✅ |
| STRICT `logout-others` | **503** | **3.045 s** | ~3 s ✅ |
| pageview | 201 | **3.054 s** | ~3–4 s ✅ |
| enquiry | **503** | **3.045 s** | ~3 s ✅ |

**Round 3 — genuinely parallel** (`--no-reload` + `PHP_CLI_SERVER_WORKERS=8`, 9 server processes;
witness: hung 39.76 s):

| Probe | Status | Time |
|---|---|---|
| health | 200 | 0.028 s |
| enquiry | 503 | 3.055 s |
| pageview | 201 | 3.081 s |
| STRICT `logout-others` | 503 | 3.083 s |
| authed | 503 | 3.083 s |
| profile | 200 | 3.101 s |

Rounds 2 and 3 agree to within ~40 ms. **Every request-path probe is bounded at exactly one
`read_timeout` (3.0 s), concurrently**, with 5 × `redis.request_breaker.opened` — one per degraded
request, none for `health`, which issues zero Redis commands. `ArmRedisRequestBreaker` is intact
under the new middleware order.

⚠️ As the runbook notes, `DEBUG SLEEP` cannot exercise the connect-timeout path — a sleeping server
still completes the TCP handshake. A green Scenario C says nothing about a packet-drop outage.

## Verdict

| Criterion (from runbook) | Result | Notes |
|---|---|---|
| No probe hangs multi-second — failures are FAST | **PASS** | ≤ 0.25 s on a full outage; exactly one `read_timeout` under a hang |
| Public profile reads survive | **PASS** | 200 throughout, both variants |
| Beacon fail-open works end-to-end (2xx) | **PASS** | 201 throughout, with `analytics.ingest.dispatch_failed` breadcrumbs |
| Breadcrumb trail exists; escalation matches documented tiers | **PASS** | Tier 1 counter is Redis-backed so it falls to Tier 2's 1-in-200 sample; no `report()` at this volume, which is the documented honest limit |
| Recovery is hands-off | **PASS** | No manual cache repair; Horizon respawned its own workers |
| No non-analytics data loss | **PARTIAL** | Enquiries submitted during the outage are lost — clean fail-closed, but lost. Finding 3. |

**Overall: PASS**, with one PARTIAL on data loss.

All four merged changes behaved as designed:

1. **`revocation.strict` fails closed** — 503 + `Retry-After: 5`, by design, not recorded as a regression.
2. **Priority pin holds** — the gate answers the strict route with zero limiter involvement, and the
   non-strict control is unmoved.
3. **`FeatureAvailability::for()` no longer 500s on a cache fault** — `GET /api/site` is 503, and the
   enquiry path (which calls `assertPublicFeatureAvailable`) never reaches a 500 either.
4. **`jwt_exp = 900`** — verified out-of-band against the Management API on **both** hosted projects
   (`edplucmvkcnokyygxqsb` and `glncumufgaqcmqhzwrxm`), with `refresh_token_rotation_enabled=true`
   and `security_refresh_token_reuse_interval=10` unchanged. Not observable locally — finding 5.

## Findings

1. **🟢 Confirmed — both 2026-08-05 P2s stay fixed under a real outage.** The strict gate answers in
   0.037 s (vs 0.145 s when the limiter answered pre-fix), and `GET /api/site` degrades to 503
   rather than the old raw 500. Evidence is the gate's own log line, not the status code.

2. **🟡 P2 (methodology) — `php artisan serve` serialises "parallel" probes, and the resulting
   pattern is indistinguishable from a broken timeout.** Laravel's `ServeCommand` reads
   `PHP_CLI_SERVER_WORKERS` with a default of **1**, and *silently refuses to honour any higher
   value unless `--no-reload` is also passed* — it only warns, into the serve log nobody reads
   during a drill. **Failure scenario:** an operator follows Scenario C exactly as written, fires
   five probes in parallel, and reads back 3 s / 6 s / 9 s / 12 s / 15 s. That is the textbook shape
   of N × `read_timeout` stacking — the drill's headline finding — and it would be filed as a P0
   against a system that is in fact behaving perfectly. This round would have produced exactly that
   report had the solo control not been run. **Fixed here** — runbook corrected to require
   `--no-reload` + `PHP_CLI_SERVER_WORKERS`, and to treat evenly-spaced increments as a
   serialisation smell.

3. **🟡 P2 — `throttle:leads` fails closed, so a Redis outage silently drops customer enquiries.**
   `FailOpenThrottleRequests::FAIL_OPEN_LIMITERS` is `public-site`, `public-profile`, `analytics`,
   `analytics-click`, `health-check`. `leads` is **not** in it, despite being a public, unauthenticated
   path. **Failure scenario:** Redis goes down during business hours; every "Contact" form on every
   sitepage returns 503 "Service temporarily unavailable", the enquiry is never persisted, and most
   visitors will not come back to retry — while pageview beacons, which are worth far less, continue
   to succeed by design. The failure is at least *clean* (the limiter answers before the controller,
   so there is no half-written row). **Filed, not fixed** — adding `leads` to the allow-list removes
   spam rate-limiting from a public write endpoint during precisely the window an attacker would
   choose, so it is a security trade-off that needs its own decision, not a drill-time edit.

4. **🟡 P3 — the "is Horizon alive" precondition command is wrong and reads a healthy Horizon as
   dead.** Both the runbook and the execute-prompt use `ps aux | grep -c '[h]orizon:work'`. Horizon's
   long-lived processes are the master (`artisan horizon`) and five `horizon:supervisor` children;
   `horizon:work` are short-lived worker grandchildren that **exit during a Redis outage and respawn
   after it**. **Failure scenario:** an operator checks supervisors mid-outage, reads `0`, and files
   "Horizon master crashed on a Redis blip" — a P1-shaped finding — when the master and all five
   supervisors are running. That happened in this session and was only caught by inspecting the
   process table. **Fixed here** — runbook now checks the master and `horizon:supervisor`.

5. **🟡 P3 — local `supabase/config.toml` has `jwt_expiry = 3600`, against 900 on both hosted
   projects.** The 2026-08-05 change was applied through the Management API to prod and dev; the
   local stack was never brought into line. **Failure scenario:** any local drill or manual test of
   token-expiry behaviour silently exercises a 1-hour window while production runs 15 minutes, so a
   regression that only bites inside the shorter window cannot be reproduced locally. Concretely, it
   makes the execute-prompt's "re-mint before each phase" precaution untestable here — this run's
   tokens all decoded to `exp - iat = 3600`. **Filed, not fixed:** the one-line parity fix is
   obvious, but it quadruples local refresh churn during ordinary frontend development, which is
   Josh's call rather than a drill-time edit.

6. **🟢 Not a finding — `ExportUserDataJob` fails locally.** Horizon logged a FAIL during baseline.
   The audit row exists (`audit.data_export_audit`, status `failed`) and the recorded error is
   `Unable to write file … The PutObject operation requires non-empty bucket` — `AWS_BUCKET` is
   empty in the local `.env`. A local storage gap, not a code defect and not related to Redis.
   `DataExportService::dispatch()` correctly uses `->afterCommit()`.

## Runbook corrections

Applied in this branch:

1. **`03-redis-down.md` Scenario C** — the "fire all five probes in PARALLEL" instruction is not
   achievable with `php artisan serve` as normally started. Added the `--no-reload` +
   `PHP_CLI_SERVER_WORKERS` requirement, the "evenly-spaced increments = serialisation" tell, and
   kept the solo re-measure as the authoritative control.
2. **`03-redis-down.md` Preconditions** — Horizon liveness now checks the master and
   `horizon:supervisor`, with a note that `horizon:work` children legitimately vanish mid-outage.
3. **`03-redis-down.md` BASELINE** — the enquiry probe needs `form_started_at_ms` (bot-protection
   timing rule), a `subject` drawn from `config('partna.contact_subject_defaults')`, and an active
   `contact` block in the `sections` group on the drill site, or it returns 422 rather than 2xx.
   Also `throttle:leads` allows 3/min per IP, which bounds how often the probe can be repeated.
4. **`03-redis-down.md` BASELINE** — `/api/me/data-export` is **POST** (a GET returns 405), and its
   inline `throttle:1,1440` allows one call per day, so it is unusable as a repeated per-phase probe.
5. **`README.md`** — the local-only justification's "[deployed development] serves BOTH
   `dev-api.partna.au` and `api.partna.au` (it *is* production right now)" has been untrue since the
   2026-07-26 cutover. The rule stands; the reason is corrected.

## Next run due

On material change to cache/queue wiring, analytics ingest, throttle middleware,
`EscalatesRepeatedFaults`, `ArmRedisRequestBreaker`, or the middleware priority list in
`bootstrap/app.php`.
