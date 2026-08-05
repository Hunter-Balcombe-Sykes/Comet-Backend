# Drill log — 03 Redis down (re-run with a strict-revocation probe)

- **Date:** 2026-08-05 (AEST)
- **Runbook:** [../03-redis-down.md](../03-redis-down.md)
- **Purpose:** verify C (selective fail-closed revocation) on a real outage — runbook step for
  `docs/superpowers/plans/2026-08-05-auth-selective-failclosed-PLAN.md` §4.7.
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `feature/auth-selective-failclosed-2026-08-05` @ `2bb1c377`
- **Environment:** LOCAL — worktree `backend-wt/auth-selective-failclosed-2026-08-05`, local
  Supabase stack (`Partna-Development` containers, incl. real GoTrue), Homebrew Redis 6379,
  Horizon 5 supervisors, `php artisan serve` on `127.0.0.1:8000`.
- **Variant run:** full outage (`brew services stop redis`, injection verified). Scenario C (hung
  Redis via `DEBUG SLEEP`) **not** re-run — E's bound was unchanged by this branch.

## Preconditions — verified, not assumed

| Precondition | Verified value |
|---|---|
| `CACHE_STORE` | `redis` |
| `QUEUE_CONNECTION` | `redis` |
| `SESSION_DRIVER` | `cookie` |
| `config('partna.throttle.enabled')` | `true` |
| `APP_ENV` | `staging` — **not** `local` |
| Analytics ingestor binding | `QueuedIngestor` (asserted; `local`/`testing` would bind `SyncIngestor` and make the beacon probe vacuous) |
| Horizon | **5 supervisors**, started under `APP_ENV=local` BEFORE the flip to `staging` (no `staging` key in `config/horizon.php` ⇒ starting after the flip yields zero supervisors, silently) |
| Authed probe | **real ES256 Supabase JWT** minted from local GoTrue admin API (`generate_link` → follow `action_link` → read `access_token` from the redirect fragment). Decoded: `aal1`, `session_id` present, `email_verified: true`, `sub` matching `core.users.auth_user_id`. Returned **200** at baseline — not an unauthenticated 401. |
| Injection | `redis-cli ping` → *Connection refused*; nothing listening on 6379 |

Incidental confirmation for item D: the minted token's `exp - iat` was **3600 s**, matching the
`jwt_exp` read from the Management API for both hosted projects.

## Baseline (Redis healthy)

| Probe | Status | Time |
|---|---|---|
| profile (public) | 200 | 0.374 s |
| health | 200 | 0.018 s |
| authed `GET /api/site` | 200 | 0.037 s |
| pageview beacon | 201 | 0.047 s |
| **STRICT `POST /api/sessions/logout-others`** | 200 | 0.017 s |

## OBSERVE — Redis down, throttle ON (production-shaped)

Probes fired **in parallel**.

| Probe | Status | Time |
|---|---|---|
| profile (public) | 200 | 0.073 s |
| health | 200 | 0.130 s |
| pageview beacon | 201 | 0.024 s |
| authed `GET /api/site` (non-strict) | 503 | 0.103 s |
| **STRICT `logout-others`** | 503 | 0.145 s |
| **STRICT `data-export`** | 503 | 0.119 s |

Everything degrades fast — no multi-second hangs. Public reads and the beacon are unaffected.

### 🔴 The headline finding: the 503 did NOT come from the new gate

The strict routes returned 503, which looks like a pass — and would have been recorded as one if
the drill had stopped at status codes. It is not. With the log truncated and a single probe fired:

```
POST /api/sessions/logout-others → 503
  RateLimiterUnavailableException     ← fired
  auth.revocation_unverified_on_strict_route  ← did NOT fire
```

**The rate limiter fails closed first and short-circuits before `revocation.strict` runs.** Two
facts combine to make this certain:

1. `revocation.strict` is **not** in `bootstrap/app.php`'s priority list and `ThrottleRequests`
   is, so `SortedMiddleware` always places the gate **after** throttle. Resolved order on
   `api/sessions/logout-others` ends `… → throttle → throttle → revocation.strict`.
2. `FailOpenThrottleRequests::FAIL_OPEN_LIMITERS` is `public-site`, `public-profile`, `analytics`,
   `analytics-click`, `health-check` — **all public**. Every strict route uses `authenticated`,
   `staff`, `session-writes` or an inline limiter, none of which are allow-listed, so all of them
   fail closed with `RateLimiterUnavailableException`.

The two 503s are also indistinguishable on the wire: `RevocationUnverifiableException` copies
`RateLimiterUnavailableException`'s message verbatim (deliberately — matching the existing shape
was a design requirement). **Status code alone can never attribute this; only the log can.**

### Isolating the gate — throttle OFF, Redis still down

To prove the gate actually works rather than infer it, the throttle layer was disabled so it could
not preempt. Per-probe, with the log truncated between each:

| Probe | Status | Layer that produced it |
|---|---|---|
| **STRICT `logout-others`** | **503** | **`auth.revocation_unverified_on_strict_route`** ✅ the new gate |
| STRICT `data-export` | 503 | `RateLimiterUnavailableException` — its `throttle:1,1440` is an **inline** limiter, which bypasses `handleRequestUsingNamedLimiter` and so ignores `PARTNA_THROTTLE_ENABLED` |
| `GET /api/site` (non-strict) | **500** | neither — unhandled `RedisException` |

**C is verified end to end**: a real Supabase JWT, a real Redis outage, a real strict route, the
gate's own log line, 503 + `Retry-After: 5`, in ~0.02–0.21 s.

## What this means for C

C works, but its practical role is **narrower than the plan assumed**. On a full Redis outage with
throttling on — the production shape — a strict route already 503s from the rate limiter, so the
gate changes nothing observable. It is load-bearing in exactly these cases:

- **Throttling disabled or bypassed.** `PARTNA_THROTTLE_ENABLED` has historically shipped `false`
  locally; if it were ever false in a deployed env, the gate is the *only* thing failing closed.
- **A limiter added to `FAIL_OPEN_LIMITERS` later.** Any strict route whose limiter becomes
  fail-open loses its accidental protection and keeps the gate.
- **Revocation unavailable independently of the limiter** — a partial failure, or a
  `TokenRevocationService` throw that is not a Redis outage at all.
- **Defence in depth.** Relying on the rate limiter as the security control for session revocation
  is *accidental*, not designed. It is one allow-list edit away from silently disappearing, and
  nothing today would catch that.

None of this argues against C. It does mean the honest claim is "second line of defence", not
"the thing that stops a revoked session during an outage".

## Findings

1. **🟡 P2 — `revocation.strict` sorts after `ThrottleRequests`.** Arguably backwards: "is this
   session still allowed to act" is more fundamental than "has it exceeded its quota", and the
   current order means the gate is unreachable on every strict route during a full outage. Fixing
   it means adding the gate to the priority list ahead of `ThrottleRequests`, which changes
   ordering globally — out of scope for the signed-off change, and it needs its own review.
   **Filed, not fixed.**
2. **🟡 P2 — `GET /api/site` returns 500, not a degraded response, when the throttle layer does
   not preempt it.** First traced only as far as
   `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` (the request path). The
   actual throw is one level down, in `FeatureAvailability::for()` — see the follow-up section for
   the real root cause. In production the limiter masks it as a 503, so this is latent rather than
   live — but it is a raw 500 the moment the limiter's behaviour changes. **Pre-existing; not
   introduced by this branch** (the branch adds no code to that path, and `/api/site` carries no
   strict gate).
3. **🟢 Informational — an inline `throttle:N,M` ignores `PARTNA_THROTTLE_ENABLED`.** Documented
   in `FailOpenThrottleRequests` already; worth remembering when designing probes, because it
   makes `data-export` a poor isolation probe.
4. **🟢 Confirmed — public paths are unaffected by a Redis outage.** profile 200, beacon 201,
   health 200 throughout, all sub-150 ms. Consistent with E's numbers.

## RECOVER

`brew services start redis` → `PONG`. All probes returned to baseline:

| Probe | Status | Time |
|---|---|---|
| profile | 200 | 0.261 s |
| health | 200 | 0.019 s |
| authed `GET /api/site` | 200 | 0.038 s |
| pageview | 201 | 0.042 s |
| STRICT `logout-others` | 200 | 0.017 s |

Horizon: **5 supervisors still running** after the outage — no crash, no manual repair needed.

## ✅ FOLLOW-UP — both P2s fixed and re-verified, 2026-08-05

Fixed on `feature/auth-followups-2026-08-05` (@`6d5eb099`) and re-probed against a real Redis
outage on the same local stack, with a real minted Supabase JWT.

**Finding 1 — gate ordering. FIXED.** `revocation.strict` is now pinned in the priority list ahead
of `IdempotencyKey` (and therefore `ThrottleRequests`). Resolved order is
`VerifySupabaseJwt → RequireVerifiedRevocation → IdempotencyKey → ThrottleRequests`.

Re-probe, Redis down, **throttle ON** (the production shape that previously hid the gate):

| Probe | Status | Time | Layer that answered |
|---|---|---|---|
| STRICT `logout-others` | 503 | **0.040 s** | **`auth.revocation_unverified_on_strict_route`** — the gate |
| `GET /api/site` (non-strict, control) | 503 | 0.023 s | `RateLimiterUnavailableException` — unchanged |

`RateLimiterUnavailableException` no longer appears on the strict probe **at all**. Compare the
original run above, where it was the only thing that fired and the gate never ran. The strict
route is also ~3.7× faster (0.145 s → 0.040 s), because the gate answers from a request attribute
without touching Redis, whereas the limiter had to fail a connection first.

The non-strict control being unchanged is the important half: the priority-list edit is global, so
"did it move anything else" needed an answer, not an assumption.

**Finding 2 — `GET /api/site` 500. FIXED.** Root cause was in
`FeatureAvailability::for()`: its try/catch was written for a DB fault, but the two `Cache::get()`
calls above the `try` threw first under a *cache* fault and escaped the fail-open handler entirely
— a guard placed one line too late. Both reads are optimisations (a re-fault sentinel and a
key-namespacing version token), so neither justifies a 500. The catch block's own sentinel write
is now guarded too, since it targets the same store that may have just failed.

Re-probe, Redis down, **throttle OFF** (the configuration that exposed the 500):

| Probe | Before | After |
|---|---|---|
| `GET /api/site` | **500** (unhandled `RedisException`) | **200**, with a `feature_availability.cache_unavailable` breadcrumb |
| STRICT `logout-others` | 503 (from the inline limiter) | 503 from the gate |

## Verdict

**PASS, with the attribution caveat recorded above — and both P2s now FIXED (see the follow-up section).** The strict gate does what it was built to
do, proven by its own log line rather than by a status code. The drill's most useful output is not
that pass — it is finding 1, which says the gate cannot currently be reached on a full outage and
that the protection observed in production today is the rate limiter's, not C's.
