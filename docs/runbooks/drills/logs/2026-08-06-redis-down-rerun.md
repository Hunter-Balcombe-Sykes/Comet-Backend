# Drill log — 03 Redis down (RE-RUN, post enquiry-resilience merge)

- **Date:** 2026-08-06 (AEST)
- **Runbook:** [../03-redis-down.md](../03-redis-down.md)
- **Purpose:** discharge the acceptance criterion of
  `docs/superpowers/specs/2026-08-06-enquiry-path-redis-resilience-design.md` — the earlier run
  (`2026-08-06-redis-down.md`) recorded **PARTIAL** on "no non-analytics data loss" because
  enquiries submitted during an outage were dropped (finding 3). This run tests whether the fix
  holds against a **real** Valkey outage rather than a Pest double.
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `drills/redis-down-2026-08-06-rerun` @ `a3e14ddc2` — i.e. `development`
  **after** the enquiry-resilience merge `759fb7c52`.
- **Environment:** LOCAL — worktree `.claude/worktrees/drill-redis-2026-08-06`, local Supabase
  stack (`supabase_db_Partna-Development`, :54322), Homebrew Redis :6379, Horizon 5 supervisors,
  `php artisan serve --no-reload` on 127.0.0.1:8000 with `PHP_CLI_SERVER_WORKERS=8`.
- **Variant run:** full outage (`brew services stop redis`). Scenario C (hung Redis) NOT run —
  see "Not covered".

## Preconditions — verified, not assumed

| Precondition | Verified value |
|---|---|
| `APP_ENV` | **`staging`** (flipped AFTER Horizon start, per the ordering trap) |
| Analytics ingestor binding | **`QueuedIngestor`** — the trap that produced a false PASS on 2026-08-05 |
| `horizon.environments` | `production`, `development`, `local` — **`staging` absent**, trap still live |
| `cache.default` / `queue.default` / `session.driver` | `redis` / `redis` / `cookie` |
| `partna.throttle.enabled` | **`true`** — forced on; local `.env` ships it false |
| `partna.public_domain` | `localhost` ⇒ Origin `http://drill-rd-0806b.localhost` |
| leads limits | `3`/min/IP primary, `3`/min/IP degraded |
| Horizon before injection | master 1, **supervisors 5** |
| Drill site | `drill-rd-0806b`, published, **1 active `contact` block** in `sections` |

**Local DB had to be migrated first.** The local Supabase stack was 6 migrations behind
(`20260803100001`), missing both enquiry-resilience migrations *and* the four from the 08-05
wave. Drilling without applying them would have thrown `42703` on the very endpoint under test —
the same hazard the previous run avoided by pinning an older base commit. All six applied cleanly.

## BASELINE → OUTAGE → RECOVERED

| Probe | Baseline | Redis DOWN | Recovered |
|---|---|---|---|
| `health` | 200 · 0.022s | **200 · 0.020s** | 200 · 0.018s |
| `profile` | 200 · 0.104s | **200 · 0.069s** | 200 · 0.056s |
| `pageview` | 201 · 0.042s | **201 · 0.017s** | 201 · 0.016s |
| `enquiry` | 200 · 0.037s | **200 · 0.023s** | 200 · 0.023s |

Injection verified before probing: `redis-cli ping` → `Connection refused`, no `redis-server`
process, nothing listening on :6379.

**No probe degraded at all — not in status, not in latency.** Every outage probe was FASTER than
its baseline, because the cache round-trips that normally happen were replaced by fail-fast
refusals caught by the per-request breaker (`redis.request_breaker.opened` ×3).

## The finding-3 acceptance criterion — PASS

`enquiry` returned **200 · 0.023s** during a full outage, where the 2026-08-06 pre-fix run
recorded **503 with the lead discarded**. Verified beyond the status code:

- `site.enquiries` row **persisted**, with `notifications_pending_since = 2026-08-06 10:59:10+00`.
- `analytics.lead_submissions` gained an `outcome='created'` row during the outage.
- After recovery, `php artisan enquiries:reconcile-notifications` printed
  `Reconciled 4 enquiry notification(s).`, markers went **4 → 0**, and Horizon ran all 8 jobs
  (4 × `DispatchEnquiryNotificationsJob`, 4 × `SendEnquiryConfirmationJob`) to `DONE`.
  `queue:failed` → **No failed jobs found.**

## The gate stays SHUT against abuse — the result that matters most

Five enquiries fired back-to-back from one IP **during the outage**:

```
burst 1 -> 200   {"ok":true}
burst 2 -> 429   {"message":"Too many submissions. Please wait before trying again."}
burst 3 -> 429
burst 4 -> 429
burst 5 -> 429
```

This is the property the whole design exists for: with Redis unreachable the limiter is neither
open nor 503ing — it is **answering from Postgres and still rejecting**. A fail-open change would
have returned five 200s here.

## All FIVE gates observed firing

| Breadcrumb | Count | Gate |
|---|---|---|
| `lead.rate_limit_dedup_unavailable` | 4 | 1 — abuse signal kept alive |
| `throttle.store_unavailable` (`limiter:leads`, **`mode:fallback`**) | 6 | 2 — the Postgres fallback |
| `enquiry.blocklist.unavailable` | 4 | 4a — blocklist read fails open |
| `enquiry.notify.dispatch_failed` | 4 | 4b — guarded dispatch + marker |
| `customer.count_invalidation_failed` | 3 | **5 — the gate the final review found** |
| `analytics.ingest.dispatch_failed` | 1 | beacon fail-open (pre-existing) |

`throttle.store_unavailable` also shows `limiter:analytics` and `limiter:public-profile` at
`mode:open` — the pre-existing `FAIL_OPEN_LIMITERS` behaviour, unchanged.

**Gate 1 is worth calling out.** `analytics.lead_submissions` recorded `rate_limited ×4` during
the outage. Before Task 4, `Cache::add()` threw ahead of the insert and the outer catch swallowed
it, so **zero** abuse rows would have been written — the monitoring table would have been blind
during exactly the window an attacker would choose. The four 429s above are visible because of it.

## Horizon

Master 1 and supervisors 5 stayed alive throughout. `horizon:work` grandchildren went 5 → **0**
during the outage and respawned to 5 automatically on recovery. **Expected, not a finding** —
the runbook already warns that checking `horizon:work` mid-outage reads like a master crash.
Recovery was hands-off: no cache repair, no Horizon restart, no manual intervention of any kind.

## Verdict

| Criterion | Result |
|---|---|
| No probe hangs multi-second | **PASS** — slowest outage probe 0.069s |
| Public profile reads survive | **PASS** — 200 · 0.069s |
| Beacon fail-open end-to-end | **PASS** — 201, breadcrumb present |
| Breadcrumb trail + escalation tiers | **PASS** — all five gates observed |
| Recovery hands-off | **PASS** — workers respawned unaided |
| **No non-analytics data loss** | **PASS** — was PARTIAL; enquiries now persist, reconcile, and deliver |

**Overall: PASS.** Drill 03 finding 3 is **CLOSED**, and the fifth gate found in the pre-merge
whole-branch review is confirmed to sit on the live hot path and to be correctly guarded.

## Findings

1. **🟢 Finding 3 CLOSED, verified against a real outage.** The design's own acceptance criterion
   is now discharged: 2xx, row present, marker set, reconciler drains, jobs deliver.

2. **🟡 P3 (runbook accuracy) — the enquiry probe does NOT exercise the fifth gate on its own.**
   `03-redis-down.md` currently claims "this single enquiry probe already exercises it, since the
   upsert runs on every submission regardless of outcome." That is **wrong**. `upsertEnquiryCustomer()`
   is an upsert: when the submitter's `Customer` already exists with identical attributes, Eloquent
   fires no `updated` event, so `CustomerObserver` never runs and the cache call never happens. The
   first outage probe in this run produced `customer.count_invalidation_failed = 0` for exactly that
   reason — the baseline probe had already created that customer. **The probe must use a fresh email
   address** to force a `Customer` create. Fixed in the runbook by this run.

3. **🟢 Latency improved rather than degraded.** Every outage probe beat its own baseline. The
   per-request breaker (`ArmRedisRequestBreaker`) turns a dead store into a fast refusal instead of
   a timeout, so the degraded path costs less than the healthy one. Worth remembering when reading
   future drills: "faster than baseline" is the signature of a working breaker, not a skipped probe.

---

# Scenario C — Redis hung, not down (same day, second pass)

Run after the full-outage pass, on Josh's prompt that a hung Redis is a materially different
failure mode and should not be skipped. He was right: a hung server blocks until `read_timeout`
rather than refusing instantly, so nothing in the full-outage pass proves the Postgres fallback
behaves under it. **All six probes were run this pass, including `authed` and `strict`** — an
ES256 JWT was minted from the local GoTrue admin API and linked to `core.users.auth_user_id`,
and both probes were confirmed 200 at baseline before being trusted (an unauthenticated 401
arrives *before* the throttle layer and is not evidence).

Setup: `enable-debug-command local` added to `/opt/homebrew/etc/redis.conf` + restart, **reverted
in RESTORE** (verified: `DEBUG` now returns `ERR DEBUG command not allowed`). Blocking verified
against its **own** injection first — `verifying ping blocked 5.57s` against a `DEBUG SLEEP 6` —
never inline before the probes.

## Solo control — authoritative (each probe alone against its own 12s hang)

| Probe | Runbook expectation | Measured |
|---|---|---|
| `health` | < 0.1s (zero Redis commands) | **200 · 0.021s** |
| `profile` | ~3s | **200 · 3.120s** |
| `pageview` | ~3–4s | **201 · 3.051s** |
| `authed` | ~3s, 503 (designed degradation) | **503 · 3.063s** |
| `strict` | ~3s, 503 (fails closed by design) | **503 · 3.034s** |
| `enquiry` | ~3s, **2xx since 2026-08-06** | **200 · 3.084s** |

## Parallel round — 40s hang, all six concurrent

```
WITNESS: redis hung 39.74s
health   200  0.030s
authed   503  3.038s
strict   503  3.038s
pageview 201  3.040s
enquiry  200  3.051s
profile  200  3.075s
```

The five request-path probes **cluster at 3.038–3.075s — a 37ms spread**. That is the signature of
real, independent per-request bounds. The serialisation trap this runbook warns about would have
produced an evenly-spaced metronome (3 / 6 / 9 / 12 / 15s); it did not, because the server was
started with `--no-reload` + `PHP_CLI_SERVER_WORKERS=8` (9 workers, 0 WARN lines). Solo and
parallel agree to within **~45ms**, so contention is excluded as an explanation.

## What Scenario C adds beyond the full-outage pass

- **The enquiry fix holds under a hung store too — 200 in BOTH the solo and parallel rounds.**
  This is the material addition: a hung Redis blocks until `read_timeout` instead of refusing
  instantly, exercising a different code path into `consultFallback()`. `throttle.store_unavailable`
  carried `mode:fallback` ×6, and both hung-round enquiries persisted with
  `notifications_pending_since`; the reconciler then drained 2 → 0 with no failed jobs.
- **The 503 attribution is correct and the 2026-08-05 priority pin still holds.** Exactly one
  `auth.revocation_unverified_on_strict_route` and one `RateLimiterUnavailableException` — i.e.
  `strict` was answered by the **revocation gate** and `authed` by the **limiter**, which is the
  designed split. A `strict` 503 attributed to the limiter would have been a real regression.
- **The per-request breaker is doing the bounding**: `redis.request_breaker.opened` ×5 per round,
  and every request-path probe lands at exactly one `read_timeout` rather than N × stacking.
- **The fifth gate fires here too** — `customer.count_invalidation_failed` ×1 per round (fresh
  email per probe, per the correction in finding 2).

## Not covered

- **Connect-timeout path.** `DEBUG SLEEP` leaves a server that still completes the TCP handshake,
  so failures surface as `read error on connection`. A packet-drop outage (security group, fenced
  node, partition) instead throws `Operation timed out` from `connect()`. Only the first is
  reachable from this drill — a green Scenario C is not evidence about the second. Unchanged
  limitation, already documented in the runbook.
- **`POST /api/public/customers`** was not probed. It shares `throttle:leads` and the identical
  `CustomerObserver` path, both exercised here via `/api/public/enquiry`.
