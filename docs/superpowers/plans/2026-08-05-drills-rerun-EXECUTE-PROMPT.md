# EXECUTE PROMPT — re-run all four failure-mode drills

**Why now:** the auth/resilience work merged on 2026-08-05 (`b9c66318` → `c4283f5a`) changed the
request path, the *global* middleware order, a queue-path cache guard, and the access-token
lifetime on **both** Supabase projects. Every drill log currently on disk predates at least part of
that. This re-run is to confirm nothing regressed and to refresh the baseline.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md`, `docs/runbooks/drills/README.md`, and each runbook's **Preconditions** section
before touching anything. Then re-run **all four** drills and record a log per drill.

## What changed since the last run — what you are actually re-testing

Merged 2026-08-05 (`c4283f5a`, CI 9/9 green):

1. **`revocation.strict` on 111 routes** — all 104 staff routes plus MFA-factor delete, the three
   `/api/me/deletion/*`, `/api/me/data-export`, `POST /api/sessions/logout-others`,
   `DELETE /api/sessions/{id}`. During a Redis outage these fail **closed** with 503 +
   `Retry-After: 5`. **That is by design. Do not record it as a regression.**
2. **Global middleware priority changed.** `RequireVerifiedRevocation` is now pinned between
   `VerifySupabaseJwt` and `IdempotencyKey`, i.e. ahead of `ThrottleRequests`. This reorders the
   stack on **every** route that carries the gate, so drill 03's authed numbers should differ from
   the last log.
3. **`FeatureAvailability::for()` no longer throws or fails open on a cache fault.** It resolves
   directly from Postgres. This sits on **queue paths** (`ConnectFetchJob`, `ShopBrandConnectJob`,
   `FreshaConnectFetch`, `CustomLinkSeeder`), so it is in scope for drills **01 and 02**, not just 03.
4. **`jwt_exp` is now 900 s (15 min) on BOTH prod and dev**, down from 3600. See the gotcha below —
   this one will bite you mid-drill.

Read `docs/reviews/2026-08-05-supabase-token-lifetime-review.md` and
`docs/runbooks/drills/logs/2026-08-05-redis-down-strict-revocation.md` first; the latter is the
most recent 03 run and documents a false-PASS trap you must not repeat.

## Scope

| Drill | Priority | Why |
|---|---|---|
| **03 — Redis down** | **highest** | Every one of the four changes lands on this path. |
| **01 — Worker kill** | high | The FeatureAvailability change is on queue paths; the last log predates it. |
| **02 — Vendor outage** | high | Same — platform refresh calls `FeatureAvailability::for()`. |
| **04 — Backup / restore** | normal | Unaffected by the code changes, but quarterly cadence (TECH-3 / OPS-S4-4) and the last run was 2026-08-05. Re-run to keep RTO/RPO current; say so plainly if you judge it unchanged rather than re-deriving. |

Run 03 first. If it surfaces something serious, stop and report before burning time on the rest.

## Preconditions and traps — every one of these has produced a false PASS before

**Environment**

- Work in a **git worktree** off `origin/development` with its **own `.env`**. Never edit the main
  checkout's `.env`. `herd link` rewrites `CLAUDE.md` — `git diff` and strip it if you use Herd.
- **The local `.env`'s `DB_HOST` points at a dead Supabase ref.** Point it at the local Supabase
  stack instead: `DB_HOST=127.0.0.1`, `DB_PORT=54322`, `DB_DATABASE=postgres`,
  `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`, and **`DB_SSLMODE=prefer`** — the default
  `require` fails with *"server does not support SSL"* against local Postgres.
- The local Supabase stack (`supabase start`, project `Partna-Development`) provides Postgres,
  **GoTrue**, and Kong. `supabase status` gives the URLs and keys.
- `SUPABASE_URL=http://127.0.0.1:54321`, `SUPABASE_JWKS_URL=http://127.0.0.1:54321/auth/v1/.well-known/jwks.json`,
  `SUPABASE_JWT_ISSUER=http://127.0.0.1:54321/auth/v1`, `SUPABASE_JWT_AUD=authenticated`.
- Redis keyspace must match deployed: `REDIS_DB=0` (queue + app), `REDIS_CACHE_DB=1`,
  `REDIS_SESSION_DB=2`, locks=4. Clear `REDIS_PASSWORD` — local Homebrew Redis has none.
- `php artisan serve` is fine and avoids the `herd link` trap. Setting `APP_ENV` in `.env` works;
  passing env vars on the *command line* to `artisan serve` does not (it whitelists ~14 and unsets
  the rest).

**🔴 `APP_ENV` must not be `local` or `testing`.** `AppServiceProvider` binds `AnalyticsIngestor`
to `SyncIngestor` there, which writes straight to Postgres and never touches Redis — making every
beacon probe vacuous. Assert the binding, don't assume it:

```bash
php artisan tinker --execute='echo get_class(app(\App\Services\Analytics\Contracts\AnalyticsIngestor::class));'
# MUST print App\Services\Analytics\Ingestors\QueuedIngestor
```

**🔴 Ordering trap: start Horizon BEFORE flipping `APP_ENV` to `staging`.** `config/horizon.php`
defines `production`, `development`, `local` — no `staging`, no wildcard. Started under `staging`,
Horizon prints "started successfully" and runs **zero** supervisors, silently. Start it on `local`,
then flip `.env` and `php artisan config:clear`; Horizon does not need restarting. Verify:
`ps aux | grep -c '[h]orizon:work'` must be > 0.

**🔴 Throttling is OFF in the local `.env` and ON in CI.** `.env` ships
`SIDEST_THROTTLE_ENABLED=false`; `.env.example` (which CI copies) sets
`PARTNA_THROTTLE_ENABLED=true`. The throttle layer is *under test* in drill 03, so force it on and
assert it: `php artisan tinker --execute='var_export(config("partna.throttle.enabled"));'` → `true`.
When it is false, every named limiter returns `Limit::none()` **before touching a store**, so
anything you do to the cache proves nothing.

**🔴 Verify the injection actually landed.** `redis-cli SHUTDOWN NOSAVE` does **not** work — launchd
`KeepAlive` restarts Redis within ~1 s, and a 2026-07-31 run collected a full set of plausible
false PASSes against a live Redis. Use `brew services stop redis`, then:

```bash
redis-cli ping                       # MUST be "Connection refused"
lsof -nP -iTCP:6379 -sTCP:LISTEN     # MUST be empty
```

**🔴 Mint a REAL Supabase JWT for authed probes.** A 401 from an unauthenticated request proves
nothing — auth rejects before the layer under test. Local GoTrue is captcha-gated, so use the admin
API:

```bash
SECRET=$(supabase status | awk '/Secret/ {print $4}')      # sb_secret_...
LINK=$(curl -s -X POST "http://127.0.0.1:54321/auth/v1/admin/generate_link" \
  -H "apikey: $SECRET" -H "Authorization: Bearer $SECRET" -H 'Content-Type: application/json' \
  -d '{"type":"magiclink","email":"<an auth.users email>"}' | python3 -c "import json,sys;print(json.load(sys.stdin)['action_link'])")
# follow WITHOUT redirect — the session comes back in the Location fragment
curl -s -o /dev/null -D - "$LINK" | grep -i '^location:' | sed -n 's/.*access_token=\([^&]*\).*/\1/p'
```

Decode it and assert `sub` matches a `core.users.auth_user_id`, plus `session_id`, `aal`, and
`email_verified`, before trusting any probe. Baseline must be **200**, not 401.

**🔴 NEW — tokens now expire in 15 minutes, not 60.** `jwt_exp` is 900 s on both projects as of
2026-08-05. A drill that takes longer than that will start returning 401s that look like findings
and are not. **Re-mint before each phase** (baseline / inject / recover) and note the mint time in
the log.

**🔴 NEW — a 503 alone cannot tell you which layer answered.** `RevocationUnverifiableException`
and `RateLimiterUnavailableException` are byte-identical on the wire: both 503, both *"Service
temporarily unavailable. Please try again shortly."*, both `Retry-After: 5`. The 2026-08-05 run
nearly recorded a PASS off the status code alone. **Truncate `storage/logs/laravel.log`, fire ONE
probe, and grep** — that is the only honest attribution:

```
auth.revocation_unverified_on_strict_route   → the revocation gate answered
RateLimiterUnavailableException              → the rate limiter answered
```

Post-merge, a strict route during an outage should now be answered by the **gate**. If the limiter
answers instead, the priority pin has regressed — that is a real finding.

**🔴 Analytics beacon `Origin` must match.** SEC-1 fails closed with 404 *"Site not found"*
otherwise, and you silently measure the wrong thing. The allowed host is
`<subdomain>.<config('partna.public_domain')>` — read the config, don't guess it, and note it
follows `APP_URL`.

**🔴 Scenario C probes must be fired in PARALLEL.** Sequential probes let the later ones measure a
recovered Redis.

**Reporting**

- Never quote k6's `max` — use percentiles; and separate `wait` (server) from `recv` (path).
- `cloud env:logs` caps at 100 entries.

## Expected results — state deviations, don't silently absorb them

Drill 03, Redis down, throttling **on**:

| Probe | Expect | Notes |
|---|---|---|
| public profile GET | 200, fast | unchanged |
| analytics beacon POST | 201, fast | fail-open by design |
| `/api/health` | 200, fast | liveness only, deliberately unthrottled |
| authed `GET /api/site` (non-strict) | 503 fast (limiter) | **not** a 500 — the 2026-08-05 500 was fixed |
| **strict route** (e.g. `POST /api/sessions/logout-others`) | **503 fast, from the GATE** | assert via the log line, not the status |

Anything multi-second is the most important possible finding — connection timeouts misconfigured.

If a drill's runbook preconditions no longer match reality, **fix the runbook in the same branch**
and say so in the log. One known staleness: `docs/runbooks/drills/README.md` justifies "01–03 run
on the LOCAL stack only" partly with *"[deployed development] serves BOTH `dev-api.partna.au` and
`api.partna.au` (it *is* production right now)"* — that has been untrue since the 2026-07-26
cutover. **The local-only rule still stands** (you cannot stop managed Redis, and the deployed env
runs `QUEUE_CONNECTION=sync`), but the stated reason needs correcting.

## Deliverables

1. One log per drill in `docs/runbooks/drills/logs/2026-<date>-<drill>.md`, following
   `logs/TEMPLATE.md`. Record **verified** precondition values, not assumed ones — a table of what
   you actually asserted, as the 2026-08-05 logs do.
2. Every finding tiered (P0–P3) with a concrete failure scenario, and marked fixed-here vs filed.
3. Any runbook staleness fixed in the same branch.
4. A short summary across all four: what changed vs the previous logs, and specifically whether the
   four merged changes above behaved as designed.
5. Restore everything: `brew services start redis`, restore the worktree `.env`, stop Horizon and
   the dev server, confirm `git status` is clean apart from intended files, and confirm `CLAUDE.md`
   is untouched.

## Workflow

- Plan mode first (this is 3+ steps). Drills are runbooks, not CI scripts — phase 4 is judgment.
- Do **not** run drills against the deployed `development` or `production` environments.
- Nothing here needs a migration. If you think it does, stop and ask.
- Commit per drill so a failure mid-session doesn't lose the earlier logs.
