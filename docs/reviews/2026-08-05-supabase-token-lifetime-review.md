# Supabase access-token lifetime — review and recommendation

**Date:** 2026-08-05
**Status:** investigation + recommendation. **No setting was changed.**
**Context:** item D of the selective fail-closed revocation work (C shipped alongside this).

---

## Why this was looked at

Every authenticated request asks Redis "is this session revoked?", and that check
[fails open](../../app/Http/Middleware/Auth/VerifySupabaseJwt.php) — during a Redis outage a
revoked session is accepted. C narrowed the blast radius by failing closed on eight
high-damage surfaces, but on every other route the window stays open.

The access-token lifetime is the *other* lever on that same window, and it needs no Redis at
all: a shorter access token means a revoked session self-expires sooner regardless of whether the
blocklist is reachable. It is a Supabase **dashboard setting, not code** (Auth → Sessions/Tokens),
applied per project.

## Current values

Read live from the Supabase Management API (`GET /v1/projects/{ref}/config/auth`), 2026-08-05:

| Setting | prod `edplucmvkcnokyygxqsb` | dev `glncumufgaqcmqhzwrxm` |
|---|---|---|
| `jwt_exp` (access token) | **3600 s (1 h)** | **3600 s (1 h)** |
| `refresh_token_rotation_enabled` | true | true |
| `security_refresh_token_reuse_interval` | 10 s | 10 s |
| `sessions_timebox` | 0 (off) | 0 (off) |
| `sessions_inactivity_timeout` | 0 (off) | 0 (off) |
| `sessions_single_per_user` | false | false |

Both projects sit on the Supabase **default** of 1 hour; neither has ever been tuned. They are
identical, so a change can be rehearsed on dev with real fidelity before prod.

### Do not conflate these two numbers

`TokenRevocationService::MAX_LIFETIME_SECONDS` is **30 days** and bounds the **refresh** token —
it is how long a blocklist entry must survive to be useful. `jwt_exp` bounds the **access** token.
Shortening `jwt_exp` does **not** shorten the blocklist TTL and does not let
`MAX_LIFETIME_SECONDS` come down.

## What shortening would cost

Read from the frontend at `PartnaAu/partna-frontend` @ `main` `718db34` (2026-07-25, clean tree;
observed read-only from the local checkout, never pulled or modified):

- `lib/supabase-client.ts` calls `createClient` with **no options**, so supabase-js v2 defaults
  apply: `autoRefreshToken: true`, `persistSession: true`.
- `lib/auth-session.ts` adds a proactive **single-flight** refresh on top.
  `ensureFreshAccessToken()` refreshes when the token has **< 90 s** left
  (`minValiditySeconds ?? 90`), dedupes concurrent refreshes through `inflightRefresh`, refuses to
  resurrect a session cleared mid-flight, and falls back to the stored token if the refresh
  network call fails.
- `lib/auth-errors.ts:82` already maps **503 → "We're temporarily unavailable. Please try again
  shortly."**, so C's new 503 renders sensibly with no frontend change.

**The refresh flow handles a shorter window cleanly.** The binding constraint is the 90 s refresh
threshold: `jwt_exp` must stay well clear of it or the client refreshes continuously.

Cost of `3600 → 900` (15 min): refresh round-trips rise roughly 4× — about 1/h to 4/h per active
session. Those hit Supabase GoTrue, **not this backend**, so there is no load cost here and no new
Redis traffic. Benefit: a revoked session self-expires within 15 minutes instead of 60, on every
route C leaves fail-open.

## Recommendation

**Shorten `jwt_exp` to 900 s — dev first, observe, then prod — as a change separate from C.**

900 s is 10× the client's 90 s refresh threshold, so there is ample margin. The reason to keep it
out of the C branch is not risk-aversion about the value itself: it is that this is a live auth
setting on a project about to carry customers, and it should be observable in isolation rather
than tangled with a middleware change. If both ship together and sessions start behaving oddly,
there are two suspects instead of one.

### APPLIED ON DEV — 2026-08-05

Josh's initial call was report-only; he then approved the recommendation. **Dev
(`glncumufgaqcmqhzwrxm`) is now `jwt_exp = 900`.** Verified two ways, not one:

- Config re-read after the PATCH: `jwt_exp = 900`, `refresh_token_rotation_enabled = true`,
  `security_refresh_token_reuse_interval = 10` (unchanged).
- **A real token minted from dev GoTrue decodes to `exp - iat = 900`** — the setting is not just
  stored, it is being issued.

**Prod (`edplucmvkcnokyygxqsb`) deliberately left at 3600**, confirmed by a control read in the
same run. The whole point of the recommendation was dev-first-then-soak; changing both at once
would discard the only signal that makes the prod change safe.

### Before applying to prod

1. Let dev soak. Watch for unexpected 401s and for refresh loops in the frontend console.
2. **Re-confirm `ensureFreshAccessToken`'s 90 s threshold is still in the frontend**
   (`lib/auth-session.ts`, `minValiditySeconds ?? 90`). If that number ever rises toward
   `jwt_exp`, the margin disappears and the client refreshes continuously.
3. Then `PATCH /v1/projects/edplucmvkcnokyygxqsb/config/auth` with `{"jwt_exp": 900}` — see
   [[reference_supabase_management_api_token]] for the keychain token encoding.

`sessions_timebox` and `sessions_inactivity_timeout` are both **off** and were not evaluated here.
They are a separate lever (absolute session length vs. idle expiry) and would be a genuine product
decision about forcing re-login, not a security tightening with no user-visible cost.
