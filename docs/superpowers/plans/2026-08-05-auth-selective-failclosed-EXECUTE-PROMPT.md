# EXECUTE PROMPT — C + D: selective fail-closed revocation, and token lifetime

**Run this AFTER `2026-08-05-redis-request-breaker-EXECUTE-PROMPT.md` (E) has shipped.** E changes
when the revocation check gets skipped, so doing C first would mean designing against semantics
that are about to move.

Paste everything below the line into a fresh session. It is self-contained.

---

Read `CLAUDE.md`, `docs/auth/mfa-foundation.md` and
`../../runbooks/drills/logs/2026-08-05-redis-down.md` first. This is an **auth** change, so the
CLAUDE.md blocker gate applies: **plan → sign-off → implement → independent review → merge →
verify**, and the plan needs Josh's explicit decision on one product question before any code.

## Background — what exists today

Partna uses Supabase JWTs, which are self-proving: the server verifies a signature with maths, no
lookup. That makes "sign out everywhere", bans, and stolen-device revocation impossible on the
token alone, so `App\Services\Auth\TokenRevocationService` keeps a **blocklist in Redis**
(`auth:revoked-session:<session_id>`, TTL-bounded, on the `app` connection — DB 0,
`read_timeout` 3.0). Every authenticated request asks Redis one question: is this session revoked?

**That check fails open.** `app/Http/Middleware/Auth/VerifySupabaseJwt.php:137-146` (JWKS path)
and `:242-251` (auth-server fallback):

```php
try {
    $revoked = $this->revocation->isRevoked($sessionId);
} catch (\Throwable $revocationEx) {
    $revoked = false;   // ← Redis unreachable ⇒ treat the session as valid
    Log::warning('Revocation check failed after successful JWT verification', [...]);
}
```

So during a Redis outage, a session that was signed out or revoked is **accepted** for the
remainder of its refresh-token life (up to 30 days —
`TokenRevocationService::MAX_LIFETIME_SECONDS`).

This is a deliberate availability choice, not a bug: failing closed would mean one Redis blip
locks every logged-in user out of their dashboard. Drill 03 (2026-08-05) confirmed the behaviour
and the reasoning is recorded in that log. The `read_timeout` was tightened 15 s → 3 s on
2026-08-05, which narrowed how long the system tries before giving up — measured worst legitimate
Redis op is 314 ms, so 3 s is ~10× headroom.

## C — the change

**Keep fail-open for reads. Fail closed only where a revoked session could do real damage.**

Blanket fail-closed is the wrong answer and should not be proposed: it converts a narrow security
window into a total availability outage for every customer.

### The decision Josh must make before any code

**Which routes are "sensitive" enough that a legitimate user should be blocked during a Redis
outage rather than risk a revoked session acting?**

Present a concrete proposed list in the plan and get explicit sign-off. Starting point to argue
from — verify each exists before proposing it:

- Password / email change, and any credential mutation
- Account deletion and GDPR export (`app/Services/User/AccountDeletionService.php`)
- Handle change (it's an identity surface with alias/redirect consequences)
- Anything under `routes/api/staff.php` — staff already require AAL2, and staff sessions are the
  highest-value revocation target
- Payout / billing details **if any exist yet** — check; much of commerce is out of scope per
  CLAUDE.md, so do not invent surfaces

Explicitly *not* sensitive (stay fail-open): reading your own dashboard, public site reads,
analytics beacons, anything a revoked user could equally do by simply having been logged in a
minute earlier.

### Implementation shape

Prefer a **route middleware** (e.g. `revocation.strict`) applied to the sensitive list, over a
hardcoded path list inside `VerifySupabaseJwt` — routes are where the decision is legible, and a
middleware can be asserted in a test. Register it in `bootstrap/app.php` alongside the existing
aliases (`bot.token`, `require.aal2`).

Get these right:

- **Status code.** A failed revocation check is not "you are unauthorised" (401) — it is "we
  cannot verify right now" (**503** with `Retry-After`). The app already returns exactly that
  shape for degraded Redis; match it rather than inventing a new one.
- **Ordering.** The strict check must run after JWT verification (an invalid token is still 401)
  and must not double-check what `VerifySupabaseJwt` already did.
- **Interaction with E.** If E's request-scoped breaker is already open when a strict route is
  hit, the strict route must fail **closed**, not inherit the breaker's skip. State this
  explicitly — it is the whole point of C, and the easiest thing to get wrong.
- **AAL2 interaction.** `require.aal2` already gates staff. Check whether strict revocation is
  redundant there or complementary; do not stack two middleware that do the same job.

## D — token lifetime

Supabase **access-token lifetime is a dashboard setting, not code** (Auth → Sessions/Tokens), and
it applies per project — `edplucmvkcnokyygxqsb` (prod) and `glncumufgaqcmqhzwrxm` (dev).

Shorter access tokens reduce how much the blocklist has to carry: a revoked session self-expires
sooner regardless of Redis. Do this as **investigation + a recommendation, not a unilateral
change** — it is a live auth setting on a project that will soon have customers.

Report: current value on both projects; what shortening it would cost (more refresh round-trips,
and whether the frontend refresh flow handles a shorter window cleanly — check the frontend repo,
do not clone it from here); and a recommendation. Note that `MAX_LIFETIME_SECONDS` (30 days) in
`TokenRevocationService` tracks the **refresh** token, not the access token — do not conflate them.

## Verification

- Unit/feature tests: a strict route returns 503 when the revocation store is unreachable; a
  non-strict route still returns 200 under the same condition. Both must be **proven
  non-vacuous** — break each once, show the failure, restore.
- **Re-run drill 03** (`docs/runbooks/drills/03-redis-down.md`) with one strict route added to the
  probe set. Expected: strict route 503 **fast**, non-strict routes unchanged from E's numbers.
- Read that runbook's preconditions before running — they encode traps that produced false PASSes:
  `APP_ENV` must not be `local` (binds `SyncIngestor`, making the beacon probe vacuous); start
  Horizon before flipping `APP_ENV` to `staging` (no `staging` key in `config/horizon.php`
  environments ⇒ zero supervisors, silently); fire Scenario C probes **in parallel** or the later
  ones measure a recovered Redis.
- Mint a **real** Supabase JWT for the authed probes — a 401 from an unauthenticated request is
  not evidence of anything, because auth rejects before the layer under test. Local GoTrue is
  captcha-gated; the admin API + `generate_link` → follow the `action_link` → read `access_token`
  from the redirect fragment works.

## Gotchas

- `Redis::connection('app')` vs the bare `Redis::` facade flips PHPStan's error text between
  `"Method Redis::…"` and `"Static method Redis::…"`, breaking `phpstan.neon` `ignoreErrors`
  twice over. Rewrite the affected `message:` lines; never regenerate the baseline.
- `tests/Feature/Architecture/RedisConnectionPinningTest.php` bans bare `Redis::<command>` in
  `app/Http/Middleware`, `app/Services`, `app/Listeners` — new code there must use
  `Redis::connection('app')`.
- Pest's `toContain` is variadic; a second string argument is another **needle**, not a message.
- `composer test` hits Composer's 300 s process timeout — run `./vendor/bin/pest` directly.
- Work in a git worktree off `origin/development` with its own `.env`; never edit the main
  checkout's `.env`. `herd link` rewrites `CLAUDE.md` — `git diff` and strip it.

## Workflow

1. **Plan first, and stop for sign-off on the sensitive-route list.** That list is a product
   decision about locking real customers out during an outage; it is not yours to choose.
2. Implement with Sonnet subagents; forbid `git stash`/`reset`/`checkout .` in every prompt.
3. **Independent Opus review** before merge — point it at the fail-open/fail-closed boundary, the
   E-breaker interaction, and whether any strict route can be reached by a path that bypasses the
   middleware.
4. Merge to `development`, push, re-run drill 03, record the result in a new drill log.
