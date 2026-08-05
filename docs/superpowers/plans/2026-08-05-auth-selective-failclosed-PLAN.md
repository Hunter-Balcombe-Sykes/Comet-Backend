# Plan — C: selective fail-closed revocation, D: token lifetime

**Branch:** `feature/auth-selective-failclosed-2026-08-05` (worktree, off `origin/development` @ `61f7f209`)
**Gate:** auth change → CLAUDE.md blocker gate. Plan → **sign-off** → implement → independent Opus review → merge → verify.
**Prereq:** E (`RedisRequestBreaker`) shipped @ `61f7f209`. Confirmed present: `app/Services/Redis/RedisRequestBreaker.php`, `ArmRedisRequestBreaker`, `RedisBreakerServiceProvider`.

---

## 1. Verified facts (research, not assumption)

### The fail-open, as it stands

`VerifySupabaseJwt` catches any throwable from `isRevoked()` and sets `$revoked = false` on both
paths — JWKS at `:136-146`, auth-server fallback at `:241-251`. The exception is **swallowed**:
no request attribute, no rethrow. By the time any later middleware runs, the evidence that the
check was unanswered no longer exists.

### How the E breaker changes it

`GuardedPhpRedisConnection::command()` throws `RedisUnavailableException::forSkippedCommand()`
*before* touching Redis once `RedisRequestBreaker::isOpen()`. So on a degraded request the
revocation check does not merely fail slowly — it is **skipped entirely**, and lands in the same
`catch` that sets `$revoked = false`. This is precisely the case C must not inherit.

### The 503 shape already in the codebase

`app/Exceptions/Http/RateLimiterUnavailableException.php` — `implements HttpStatusCodeInterface`,
`getHttpStatusCode() => 503`, `getHttpHeaders() => ['Retry-After' => 5]`, message
*"Service temporarily unavailable. Please try again shortly."* The renderer has a dedicated branch
for `HttpStatusCodeInterface` that preserves both status and headers; the generic branch drops the
header and masks the message. **Match this exactly.** Do not invent a shape.

### `require.aal2` — complementary, not redundant

`app/Http/Middleware/Auth/RequireAal2.php` reads the `supabase_aal` request attribute and returns
401 `mfa_required`. **Zero Redis calls.** It proves MFA was satisfied *at login*; it says nothing
about revocation *since*. Stacking strict revocation on staff routes is therefore not two
middleware doing the same job. Confirmed: no overlap to collapse.

### Billing / payout surfaces

**None exist.** `grep -niE "payout|billing|stripe|invoice"` over `routes/` and
`app/Http/Controllers` returns nothing. Matches CLAUDE.md (commerce out of scope). Not proposed —
not invented.

### Existing route stacks

- Staff: **one** group in `routes/api/staff.php:34` —
  `['supabase.jwt','require.email_verified','staff','require.aal2','throttle:staff','staff.audit']`.
  Every staff route inherits it. One-line change.
- User: `user.api` group = `['supabase.jwt','require.email_verified','current.pro']`.
- Handle/subdomain writes go through **`PATCH /api/site`** (`UpdateSiteRequest:91` validates
  `subdomain`) — the same endpoint that saves bio, links, design and every other dashboard field.

---

## 2. Implementation shape

### 2a. Record whether the revocation answer was authoritative

`VerifySupabaseJwt` sets a new request attribute on **both** verification paths:

```php
$request->attributes->set('supabase_revocation_verified', true);   // isRevoked() returned normally
```

`false` in three cases, all of which must fail closed on a strict route:

1. `isRevoked()` threw — Redis unreachable, timed out, **or skipped by an open E breaker**.
2. `session_id` absent **and** `config('supabase.require_session_id')` was flipped false (the
   documented same-day incident revert). A session-id-less token can never be revoked.
3. The attribute was never set at all — i.e. the request did not pass `VerifySupabaseJwt`.

Case 3 is the bypass defence: the strict middleware defaults to `false` when the attribute is
absent, so any route reachable by a path that skips the JWT verifier 503s rather than passing.
Fail-safe by construction, not by keeping two lists in sync.

**This does not re-check anything.** It records the outcome of the check `VerifySupabaseJwt`
already performed — satisfying the prompt's "must not double-check" constraint.

### 2b. `revocation.strict` middleware

`app/Http/Middleware/Auth/RequireVerifiedRevocation.php`:

```php
if ($request->attributes->get('supabase_revocation_verified', false) !== true) {
    throw new RevocationUnverifiableException();   // 503 + Retry-After: 5
}
return $next($request);
```

New `app/Exceptions/Auth/RevocationUnverifiableException.php`, modelled byte-for-byte on
`RateLimiterUnavailableException` (implements `HttpStatusCodeInterface`).

Registered in `bootstrap/app.php` alongside `bot.token` / `require.aal2`:
`'revocation.strict' => RequireVerifiedRevocation::class`.

**Ordering.** Reads only a request attribute, so it must run after `VerifySupabaseJwt`. It is
unlisted in the priority list and applied at route level, which places it after the group's
`supabase.jwt`. An invalid token still 401s from the verifier and never reaches this. No priority
list entry needed — but a test will pin the ordering rather than trusting it.

**No new Redis calls.** The middleware is a pure attribute read. It cannot itself fail.

---

## 3. THE DECISION — proposed sensitive-route list

Every route below was verified to exist. Grouped by whether I recommend including it.

### Tier 1 — recommend STRICT (irreversible or credential-level)

| Route | Why a revoked session here is unacceptable |
|---|---|
| `DELETE /api/account/mfa/factors/{factorId}` | Credential mutation. Strips the victim's MFA. |
| `POST /api/me/deletion/request` | Starts irreversible destruction. |
| `POST /api/me/deletion/confirm` | **Destroys the account.** No undo. |
| `POST /api/me/deletion/cancel` | Cancels a deletion the real owner started. |
| `POST /api/me/data-export` | Exfiltrates the entire GDPR PII bundle in one call. |
| **all of `routes/api/staff.php`** | Staff act on *every* user: purge accounts, export data, moderate. Highest-value revocation target by a wide margin. One-line change at `staff.php:34`. |

Availability cost: near zero. These are rare, deliberate actions. A user who cannot delete their
account for the duration of a Redis outage is not harmed.

### Tier 2 — recommend FAIL-OPEN, with reasoning stated

| Route | Why not strict |
|---|---|
| `PATCH /api/site` | This is **the main dashboard save endpoint** — bio, links, design, visibility all flow through it. Making it strict means nobody can edit anything during a Redis blip. It is sensitive only in one field (`subdomain`). A hijacked handle is also *recoverable*: `site_subdomain_aliases` keeps a 301 for 90 days and `reclaim-handle` exists with a 14-day window. Irreversibility is what earns Tier 1; this is not irreversible. |
| `POST /api/me/site/reclaim-handle` | Same recoverable-identity reasoning; low blast radius. |
| `PUT/POST/DELETE /api/site/custom-domain*` | Judgement call — see the open question below. |
| `POST /api/sessions/logout` | Logging **yourself** out is not damage. Strict here would block a legitimate user from signing out during an outage, for no security gain. |
| `GET /api/sessions` | Read-only. |
| `POST /api/sessions/logout-others`, `DELETE /api/sessions/{id}` | These are Redis **writes** — during an outage they fail on their own. Strict would only change a 500 into a cleaner 503. Real but marginal benefit; see open question. |
| Everything else — dashboard reads, public site, analytics beacons | Explicitly out of scope per the brief: a revoked user could do these equally by having been logged in a minute earlier. |

### SIGNED OFF — Josh, 2026-08-05

Tier 1 approved as proposed. Q1 (custom domain) → **fail-open**. Q2 (`logout-others` /
`DELETE /sessions/{id}`) → **include**. D → **report only, no dashboard change**.

**Final strict list (8 surfaces):**

```
DELETE /api/account/mfa/factors/{factorId}
POST   /api/me/deletion/request
POST   /api/me/deletion/confirm
POST   /api/me/deletion/cancel
POST   /api/me/data-export
POST   /api/sessions/logout-others
DELETE /api/sessions/{sessionId}
ALL    routes/api/staff.php
```

Explicitly left fail-open, recorded so a later reader does not mistake it for an oversight:
`PATCH /api/site` (main dashboard save; handle hijack is recoverable via aliases +
`reclaim-handle`), `POST /api/me/site/reclaim-handle`, all `/api/site/custom-domain*`,
`GET /api/sessions`, `POST /api/sessions/logout` (logging yourself out is not damage), and every
dashboard read, public site read and analytics beacon.

---

## 4. Verification

1. **Feature test — strict route 503s when the revocation store is unreachable.**
   Bind a `TokenRevocationService` whose `isRevoked()` throws; assert 503 + `Retry-After`.
2. **Feature test — non-strict route still 200s under the identical condition.** This is the
   whole point of "selective"; without it the test suite cannot tell C from blanket fail-closed.
3. **Feature test — open E breaker ⇒ strict route 503s.** Arm and trip `RedisRequestBreaker`,
   then hit a strict route. Pins the interaction the prompt calls the easiest thing to get wrong.
4. **Feature test — ordering.** An invalid/absent JWT on a strict route still returns **401**,
   not 503. Proves the verifier runs first.
5. **Feature test — missing attribute defaults closed.** Strict middleware in isolation, no
   `supabase.jwt` ahead of it ⇒ 503.
6. **Non-vacuity.** Break each assertion once, capture the failure output, restore. (Memory:
   negated `toContain` is vacuous; `toContain` is variadic and a second argument is another
   *needle*, not a message — avoid both.)

   **Done — five mutations, each producing exactly the predicted failures, tree restored clean
   after every one:**

   | # | Mutation | Result | Proves |
   |---|---|---|---|
   | A | `RequireVerifiedRevocation` always passes | 3 failed / 5 passed — the outage, breaker and bypass tests | the gate is what produces every 503 |
   | B | `VerifySupabaseJwt` records `true` in the Redis-failure catch | 2 failed / 6 passed — outage + breaker only; bypass still passed | the **attribute contract** drives the outcome, not the middleware alone |
   | C | drop `revocation.strict` from `/api/me/data-export` | wiring guard failed: *"a traversable contains 'api/me/data-export'"* | the route-table guard really reads the route table |
   | D | `RequireVerifiedRevocation` always throws | 1 failed — "serves a strict route normally" | the gate is not trivially closed |
   | E | add `revocation.strict` to the **lenient** test route | 1 failed — "still serves a NON-strict route" | the *selective* half is genuinely measured, not assumed |

   B and E are the two that matter most: B distinguishes this design from "a middleware that
   happens to 503", and E is the only thing standing between this change and blanket fail-closed.
7. **Drill 03 re-run** with one strict route added to the probe set. Read
   `docs/runbooks/drills/03-redis-down.md` preconditions first: `APP_ENV` must not be `local`;
   start Horizon *before* flipping to `staging`; fire Scenario C probes **in parallel**. Mint a
   real Supabase JWT (admin API → `generate_link` → follow `action_link` → read `access_token`
   from the redirect fragment) — a 401 from an unauthenticated probe proves nothing.
   Expected: strict route 503 **fast**; non-strict routes unchanged from E's numbers.
8. `./vendor/bin/pest` (not `composer test` — 300 s Composer timeout), `php artisan pint`,
   `composer analyse`.

**PHPStan gotcha:** if any new code calls Redis, it must use `Redis::connection('app')` —
`RedisConnectionPinningTest` bans bare `Redis::<command>` under `app/Http/Middleware`. The
connection form flips PHPStan's message between `"Method Redis::…"` and `"Static method Redis::…"`,
breaking `phpstan.neon` `ignoreErrors` twice over; **rewrite the `message:` lines, never
regenerate the baseline.** As designed, the middleware makes no Redis calls at all, so this should
not bite — flagged because it has bitten twice before.

---

## 5. D — token lifetime (investigation + recommendation, no unilateral change)

### Current values — both projects, read live from the Management API

| Setting | prod `edplucmvkcnokyygxqsb` | dev `glncumufgaqcmqhzwrxm` |
|---|---|---|
| `jwt_exp` (access token) | **3600 s (1 h)** | **3600 s (1 h)** |
| `refresh_token_rotation_enabled` | true | true |
| `security_refresh_token_reuse_interval` | 10 s | 10 s |
| `sessions_timebox` | 0 (off) | 0 (off) |
| `sessions_inactivity_timeout` | 0 (off) | 0 (off) |
| `sessions_single_per_user` | false | false |

Both are on the Supabase **default** of 1 hour; neither has been tuned. Identical, so a change
can be rehearsed on dev with real fidelity.

**Not to be conflated:** `TokenRevocationService::MAX_LIFETIME_SECONDS` (30 days) bounds the
**refresh** token — the window a blocklist entry must survive. `jwt_exp` bounds the **access**
token. Shortening `jwt_exp` does not shorten the blocklist TTL.

### What shortening would cost

Frontend (`partna-frontend-main` @ `718db34`, 2026-07-25, clean `main` — read-only):

- `lib/supabase-client.ts` calls `createClient` with **no options**, so supabase-js v2 defaults
  apply: `autoRefreshToken: true`, `persistSession: true`.
- `lib/auth-session.ts` adds a proactive, **single-flight** refresh: `ensureFreshAccessToken()`
  refreshes when the token has **< 90 s** left (`minValiditySeconds ?? 90`), dedupes concurrent
  refreshes via `inflightRefresh`, declines to resurrect a session cleared mid-flight, and falls
  back to the stored token when the refresh network call fails.
- `lib/auth-errors.ts:82` already maps **503 → "We're temporarily unavailable. Please try again
  shortly."** — so C's new 503 renders a sensible message with no frontend change.

The refresh flow handles a shorter window cleanly. The binding constraint is the **90 s** refresh
threshold: `jwt_exp` must stay well clear of it or the client refreshes continuously.

Cost of `3600 → 900` (15 min): refresh round-trips rise ~4× (roughly 1/h → 4/h per active
session). These hit Supabase GoTrue, not this backend, so there is no load cost here. Benefit: a
revoked session self-expires within 15 min instead of 60, regardless of Redis — which is exactly
the window C's fail-open leaves open on non-strict routes.

### Recommendation

**Shorten `jwt_exp` to 900 s on dev first, observe, then prod — as a separate change, after C
ships.** Rationale: it shrinks the fail-open exposure window 4× on every route C leaves
fail-open, for no backend cost and no frontend change. 900 s is 10× the client's 90 s refresh
threshold, so there is ample margin. **Not doing it as part of this branch** — it is a live auth
setting on a project about to carry customers, and it should be observable in isolation rather
than tangled with a middleware change. Flagged for Josh's decision; no dashboard edit made.

---

## 5a. Independent review — findings and resolution

Independent Opus review of `24f230e0` returned **DO-NOT-MERGE**. It was right, and the headline
finding was a real hole, not a style objection.

| # | Sev | Finding | Resolution |
|---|---|---|---|
| 1 | **CRITICAL** | `routes/api/staff.php` has **three** top-level `Route::prefix('staff')` groups, each with its own complete middleware array. Only the first was edited ⇒ **58 of 104 staff routes fail open**, including `DELETE /professionals/{id}/force`, deletion initiate/cancel, and status changes. Posture was **inverted**: staff *reads* failed closed, staff *hard-deletes* failed open. | Gate added to groups 2 and 3. Verified 104/104. A ⚠️ note on the first group records the three-group trap. |
| 2 | **HIGH** | The wiring test written to catch exactly finding 1 was **vacuous** — `expect(...staff...)->not->toBeEmpty()` is satisfied by one route, and passed green with 58 missing. | Replaced with an exhaustive check: every `api/staff*` route in the route table must carry the gate, and the failure message names the offending routes. |
| 3 | MEDIUM | The E-breaker test was **neutralised**: `ArmRedisRequestBreaker` is prepended global middleware and `arm()` sets `open=false`, so the request closed the breaker the test had opened. It would have passed with all four breaker lines deleted. | Rewritten to drive the **real** stack — real `TokenRevocationService`, real `GuardedPhpRedisConnection`, unroutable Redis — and to assert `isOpen()` is true *after* the request, so the breaker must genuinely have tripped. A matching lenient-route test proves selectivity against the real breaker. |
| 4 | MEDIUM | No test exercised a single **real** strict route; all behaviour was proven on ad-hoc `/api/__test/*` routes. | New `tests/Feature/Auth/StrictRevocationRoutesTest.php`: `POST /api/me/deletion/confirm`, `DELETE /api/staff/professionals/{id}/force` (group 2), and the notifications route (group 3) each 503; each has a passing control; `GET /api/site` still 200s under the same failure. |
| 5 | LOW | `IdempotencyKey` sorts ahead of the gate on `me/deletion/*`, so a key replay re-serves a cached response without it. Reviewer could construct no damaging scenario (a replay re-serves, never re-runs the handler; under a real outage the idempotency `Cache::get` throws and falls through). | Documented in `routes/api/user.php` rather than "fixed", so nobody later reorders it believing it matters. |
| 6 | LOW | The fail-closed event raised **no Nightwatch alert** — `Log::warning` is breadcrumb-only in this app; `report()` is what pages someone. | Added a throttled `report()` (1/min, `cache_locks` store, try/catch), matching `VerifySupabaseJwt::jwksOutage()`. |
| 7 | NIT | A comment contradicted the line beneath it. | Comment corrected. |

Reviewer's clean findings, recorded so the absence isn't mistaken for not looking: middleware
**ordering/bypass** verified sound on every strict route (nothing outside `VerifySupabaseJwt` and
the two test stubs writes the attribute; `prependToPriorityList` cannot hoist an unlisted
middleware); the **fail-open boundary** is byte-for-byte unchanged on non-strict routes; the
**auth-server fallback** records the attribute on every path reaching `$next()`; the **503 shape**
matches `RateLimiterUnavailableException`; `RedisConnectionPinningTest` unaffected (no Redis calls
added); PHPStan clean.

### Second non-vacuity pass, on the fixes

| # | Mutation | Result |
|---|---|---|
| F | revert the staff admin group | exhaustive guard fails, naming `DELETE api/staff/professionals/{professional}/force` |
| G | strip the gate from staff groups 2+3 and `me/deletion` | exactly the three real-route 503 tests fail; all three controls still pass |

### One process note worth keeping

During mutation F I restored with `git checkout -- routes/api/staff.php` and silently **lost the
uncommitted fix**, because checkout restores the *committed* state, not the pre-mutation state.
The tell was the test still failing after "restore". Mutation testing on uncommitted work must use
a file copy (`cp`), not git — which is how G was run.

## 6. Sequence

1. ⏸ **STOP — sign-off on §3 (list + Q1 + Q2).**
2. Implement 2a + 2b with Sonnet subagents. Forbid `git stash` / `reset` / `checkout .` in every
   prompt.
3. Tests §4.1–6, proven non-vacuous.
4. Independent **Opus** review: the fail-open/fail-closed boundary, the E-breaker interaction, and
   whether any strict route is reachable by a path bypassing the middleware.
5. Merge to `development`, push.
6. Re-run drill 03; record in a new drill log.
7. D: report only. No dashboard change without a separate explicit go-ahead.
