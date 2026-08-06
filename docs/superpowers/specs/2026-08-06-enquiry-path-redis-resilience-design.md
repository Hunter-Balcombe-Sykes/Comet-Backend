# Enquiry path Redis resilience — design

**Date:** 2026-08-06
**Status:** Implemented 2026-08-06 — see `docs/superpowers/plans/2026-08-06-enquiry-path-redis-resilience.md`.
Drill 03's re-run (the plan's real acceptance criterion — see
`docs/runbooks/drills/03-redis-down.md`) has not happened yet; this line records that the
code landed, not that a live outage has confirmed it.
**Origin:** Drill 03 (`docs/runbooks/drills/logs/2026-08-06-redis-down.md`) finding 3 — P2,
"`throttle:leads` fails closed, so a Redis outage silently drops customer enquiries."

## 1. Problem

The filed finding understates the failure. `throttle:leads` is the **first of four Redis gates**
on `POST /public/enquiry` and `POST /public/customers`. Adding `leads` to
`FailOpenThrottleRequests::FAIL_OPEN_LIMITERS` does not save the lead — it moves the failure
downstream and makes it dirtier.

| # | Gate | Redis? | With Redis dead |
|---|------|--------|-----------------|
| 1 | `lead.log` (`LogLeadRateLimits`) | Only in `terminate()`, only on 429, fully try/caught | Safe (but see §5.3 — it goes blind) |
| 2 | `throttle:leads` | Yes | **503** — the filed P2 |
| 3 | `bot.token:enquiry` (`VerifyBotToken`) | Yes, breaker-unavailable → fail-open already | Safe |
| 4a | `EnquirySpamBlocklist::contains()` — `Redis::connection('app')->zscore()` | Yes, **unguarded** | **500** |
| 4b | `DispatchEnquiryNotificationsJob` + `SendEnquiryConfirmationJob` `::dispatch()` | Redis queue | **500, after `$enquiry->save()` has committed** |

Gates 4a and 4b are **enquiry-only**. `PublicCustomerLeadController` makes no `dispatch()` call and
touches neither `Redis::` nor `Cache::` directly, so `POST /public/customers` is fully repaired by
gate 2 alone (§4). Verified 2026-08-06.

`PublicEnquiryController::submit()` has no surrounding transaction. Fixing gate 2 alone therefore
produces: enquiry row committed → visitor sees a 500 → nobody is ever notified → visitor retries →
the `Customer` upsert dedupes but `Enquiry` does not, accruing duplicate rows. That is strictly
worse than today's clean 503, which is why the drill correctly refused a drill-time edit.

## 2. Goal and non-goals

**Goal.** With Redis unreachable, `POST /public/enquiry` and `POST /public/customers` return a
normal `200 {ok:true}`, durably persist the lead, and **remain rate limited** — no unmetered public
write path during an outage.

**Non-goals.**
- A general-purpose failover cache store. Explicitly rejected before (it makes Tier 2 escalation in
  `EscalatesRepeatedFaults` unreachable by letting Tier 1 quietly succeed) and rejected again here.
  This design adds a fallback for **one named limiter**, keeping every fault breadcrumb intact.
- Making the dashboard, staff surfaces, or authenticated routes survive a Redis outage.
- Changing `FAIL_OPEN_LIMITERS`. That list is untouched.

## 3. Decisions taken

| Decision | Choice | Rationale |
|---|---|---|
| Scope | End-to-end enquiry survival (all four gates) | Fixing gate 2 alone is a net regression (§1) |
| Rate limiting without Redis | Count `analytics.lead_submissions` in Postgres | Substrate already exists; `lead_submissions_ip_time_idx` already indexed; both controllers on this limiter already write rows |
| Degraded limits | Same numbers, separate config keys | Unchanged visitor behaviour; clampable mid-incident from an env var |
| Bucket disambiguation | Combined question (§4.3 option b) | Avoids coupling to Laravel's limiter-key derivation internals |
| Notification durability | Guarded dispatch + scheduled reconciler | Self-healing, no new queue infrastructure |
| Blocklist failure mode | Read fails open, write stays loud | Fail-closed read returns a fake 200 and silently bins real leads |
| Visitor confirmation staleness | Config'd window, default 60 min | A late "we received your message" reads worse than none |

## 4. Design — rate limiting without Redis

### 4.1 `ResilientRateLimiter` gains a third mode

The wrapper is currently binary: `useFailOpen(bool $failOpen, string $limiterName)`. "Keep
limiting, differently" is a genuine third state.

```php
// app/Enums/ThrottleFailureMode.php
enum ThrottleFailureMode { case Open; case Closed; case Fallback; }
```

`useFailOpen()` becomes `useMode(ThrottleFailureMode $mode, string $limiterName, ?Request $request)`.
`FailOpenThrottleRequests` maps limiter names to modes:

| List | Mode | Members |
|---|---|---|
| `FAIL_OPEN_LIMITERS` | `Open` | unchanged — `public-site`, `public-profile`, `analytics`, `analytics-click`, `health-check` |
| `FALLBACK_LIMITERS` (**new**) | `Fallback` | `leads` |
| everything else | `Closed` | unchanged; `handle()` still pins `Closed` for inline `throttle:N,M` |

`onStoreFault()` branches on the mode:

- `Open` — return `false`, as today.
- `Closed` — throw `RateLimiterUnavailableException`, as today.
- `Fallback` — consult `LeadSubmissionRateLimiter`. **If Postgres also throws, degrade to `Closed`.**
  A 503 is the honest answer when both stores are gone, and it preserves the existing
  clean-failure property.

`hit()` / `increment()` return `0` silently in `Fallback` mode. This is deliberate: the limiter has
no counter of its own to write, because **the counter is `analytics.lead_submissions`, written by
the controller**. The audit trail is the rate-limit state — one source of truth, not two.

Header/metadata reads (`attempts`, `remaining`, `availableIn`, …) keep their existing
always-degrade behaviour unchanged. They run after `$next($request)`; throwing there would convert
a served 200 into a 500.

### 4.2 `LeadSubmissionRateLimiter`

**Location:** `app/Http/Middleware/Throttle/`, alongside `ResilientRateLimiter`. It is coupled to
that seam and used nowhere else. A new `app/Services/RateLimiting/` directory would require wiring
into `codebase_chunks()` and a lens scope-group for `AuditPipelineIntegrityTest` — real cost, no
benefit here.

Two counts, executed **only while Redis is faulting**:

```php
LeadSubmission::query()
    ->where('ip_hash', $ipHash)
    ->where('occurred_at', '>', now()->subMinute())
    ->where('outcome', '!=', 'rate_limited')
    ->count();
```

…and the same keyed on `subdomain`.

Three constraints encoded in that query:

1. **`->where('occurred_at', '>', now()->subMinute())`, never raw `interval '1 minute'` SQL.** The
   Feature suite runs SQLite and would not parse a Postgres interval literal.
2. **`outcome != 'rate_limited'` is load-bearing.** Laravel's `ThrottleRequests` does *not* call
   `hit()` once already over the limit, so a rejected request never extends the Redis window. But
   `LogLeadRateLimits::terminate()` writes an `outcome='rate_limited'` row on every 429. Counting
   those would make the Postgres fallback **stricter than Redis** — an over-limit client's own 429s
   would keep them locked out indefinitely. Excluding them matches Redis semantics exactly.
3. **`ip_hash` is comparable to the limiter key.** `bootstrap/app.php:74` sets
   `trustProxies(at: '*')`, so `$request->ip()` already resolves to the real client IP from
   `X-Forwarded-For`; `hashIp()` is a deterministic HMAC. The Postgres-side and Redis-side counters
   describe the same client. The service re-derives its inputs from the `Request` (CF-Connecting-IP,
   `route('subdomain')`), mirroring the `RateLimiter::for('leads', …)` closure in
   `AppServiceProvider`.

The fallback covers `POST /public/customers` for free — it shares `throttle:leads`, and
`PublicCustomerLeadController::logLead()` writes the same rows on every outcome.

### 4.3 Bucket disambiguation

`tooManyAttempts()` receives an opaque hashed key, and `leads` returns **two** `Limit` objects, so
the method is called twice with different keys. The fallback cannot identify the bucket from `$key`.

**Chosen (b):** the `Request` is passed down from `handleRequestUsingNamedLimiter()` (which has it),
and `LeadSubmissionRateLimiter` answers one combined question — *"is this request over either
limit?"* — returning the same verdict for both calls.

Consequence, accepted: the visitor always receives the per-IP 429 wording ("Too many submissions.
Please wait before trying again.") even when it was the subdomain bucket that tripped. Cosmetic.

**Rejected (a):** precomputing `md5('leads'.$key)` for each bucket and matching against `$key`.
Gives precise wording but pins the implementation to Laravel's limiter-key derivation internals,
survivable only with a test that fails loudly on framework upgrade. Not worth it for a message
string.

### 4.4 Configuration

```php
// config/partna.php — 'throttle' block
'leads_degraded_per_minute_ip' => (int) env(
    'PARTNA_THROTTLE_LEADS_DEGRADED_PER_MINUTE_IP',
    env('PARTNA_THROTTLE_LEADS_PER_MINUTE_IP', 3),
),
'leads_degraded_per_minute_subdomain' => (int) env(
    'PARTNA_THROTTLE_LEADS_DEGRADED_PER_MINUTE_SUBDOMAIN',
    env('PARTNA_THROTTLE_LEADS_PER_MINUTE_SUBDOMAIN', 100),
),
```

Defaulting to the primary values keeps visitor behaviour identical, while allowing the limit to be
clamped mid-incident from an env var without touching the healthy-path numbers.

## 5. Design — the remaining gates

### 5.1 Gate 4a — `EnquirySpamBlocklist`

Failure mode splits by direction:

- **`contains()` fails open** (treat as not-blocklisted), with a `enquiry.blocklist.unavailable`
  breadcrumb. Its blocked branch returns a *fake* `200 {ok:true}` and discards the enquiry, so
  failing closed would silently bin every legitimate enquiry with no error surfaced anywhere. The
  blocklist is a convenience filter, not a security boundary: one spam enquiry reaching an inbox
  during an outage is trivially recoverable; silently discarding real leads is not.
- **`add()` / `addWithExpiry()` keep throwing.** These are reached from
  `UserEnquiryController::report()` — the professional clicking "block this sender". A silent no-op
  would leave them believing a sender is blocked when they are not. Reads degrade; writes stay loud.

### 5.2 Gate 4b — guarded dispatch

Both `dispatch()` calls in `PublicEnquiryController::submit()` (steps 8 and 9) move behind a helper
that catches a dispatch fault, emits an `enquiry.notify.dispatch_failed` breadcrumb, and stamps:

```sql
ALTER TABLE site.enquiries
    ADD COLUMN IF NOT EXISTS notifications_pending_since timestamptz;

CREATE INDEX IF NOT EXISTS enquiries_notifications_pending_idx
    ON site.enquiries (notifications_pending_since)
    WHERE notifications_pending_since IS NOT NULL;
```

The partial index is **empty in steady state**. That is the point.

**Why a new column when `email_sent_at` and `confirmation_sent_at` already exist.** Those stamps
are the *jobs'* post-send idempotency guards and they stay — they are what makes re-dispatch safe.
They cannot drive the reconciler, because both jobs are gated: a correctly-skipped notification
also leaves the stamp NULL. Querying "NULL stamp" would re-dispatch permanently-skipped enquiries
every five minutes for the whole retention window. The marker must record *dispatch failed*, not
*not yet sent*.

**Why one column for two jobs.** A Redis fault kills both `dispatch()` calls microseconds apart,
so the realistic case is all-or-nothing. In the split case, re-dispatching both is safe:
`SendEnquiryConfirmationJob` is `ShouldBeUnique` (`uniqueFor 300`) and stamps `confirmation_sent_at`
post-send; `DispatchEnquiryNotificationsJob` guards on
`Cache::add('enquiry:notified:'.$id, …)` SETNX plus `email_sent_at`. Two columns would buy nothing.

The visitor receives a normal `200 {ok:true}`. The lead is committed. That is the outcome this
design buys.

### 5.3 Gate 1 — restore the abuse signal

`LogLeadRateLimits::terminate()` calls `Cache::add()` for burst-dedup **before** inserting the
`outcome='rate_limited'` row. With Redis dead that throws, the outer `catch (Throwable)` swallows
it, and **no abuse row is written at all** — the monitoring table goes blind during exactly the
window in which you most want to see who is hammering the form.

Fix: wrap only the `Cache::add()` call. On fault, breadcrumb and **proceed to the insert** (accepting
duplicate rows from browser auto-retry bursts during an outage — a duplicate row is strictly better
than no row). The existing outer try/catch and its `report()` throttling stay as-is.

This also matters to §4.2: without it, degraded-mode 429s leave no trace at all.

### 5.4 The reconciler

`php artisan enquiries:reconcile-notifications`, scheduled every 5 minutes.

- Selects rows with `notifications_pending_since IS NOT NULL`, oldest first, `FOR UPDATE SKIP LOCKED`
  (matching the claim-vs-prune pattern in `PreAccountBuildService`).
- Re-dispatches `DispatchEnquiryNotificationsJob` unconditionally.
- Re-dispatches `SendEnquiryConfirmationJob` **only if** `notifications_pending_since` is within
  `partna.enquiry.confirmation_reconcile_window_minutes` (default 60). Past that it is skipped with
  an `enquiry.notify.confirmation_stale` breadcrumb: a "we received your message" arriving six hours
  later reads worse than none. The professional's notification has no such staleness problem and
  always reconciles.
- Clears `notifications_pending_since` once `dispatch()` returns without throwing — i.e. the job is
  *enqueued*, not *executed*. Delivery from there is the queue's problem, guarded by each job's own
  `tries`/`backoff` and the failed-jobs table. If `dispatch()` throws again (Redis still down), the
  marker is left in place and the row is retried on the next tick.
- Batch bound: `partna.enquiry.reconcile_batch_size` (default 200) per run, so a long outage drains
  over several ticks rather than flooding the queue on the first tick after recovery.

### 5.5 Observability

`Log::warning` breadcrumbs do not page anyone — Nightwatch alerts on exceptions/reports and
auto-detected slow routes/jobs, never on log queries (already noted in `LogLeadRateLimits`).

The reconciler therefore `report()`s when it finds a marker older than
`partna.enquiry.notifications_pending_alert_minutes` (default 30). That is the alarm that matters:
**"leads were captured but nobody was told."** Reported once per run, not once per row.

Breadcrumb namespace, all new:

| Key | Emitted by |
|---|---|
| `throttle.store_unavailable` (existing, gains `mode: fallback`) | `ResilientRateLimiter` |
| `enquiry.blocklist.unavailable` | `EnquirySpamBlocklist::contains()` |
| `enquiry.notify.dispatch_failed` | `PublicEnquiryController` |
| `enquiry.notify.confirmation_stale` | reconciler |
| `enquiry.notify.reconcile_failed` | reconciler |
| `lead.rate_limit_dedup_unavailable` | `LogLeadRateLimits` |

`LeadSubmissionRateLimiter` emits no breadcrumb of its own. Every consultation is already
immediately preceded by `throttle.store_unavailable` carrying `mode: fallback` from the wrapper
that called it; a second line per request would double the log volume during an outage and say
nothing new.

## 6. Schema changes

Two files in `supabase/migrations/`, neither using `CONCURRENTLY` (both tables are small and
production carries no customer data), so the one-`CONCURRENTLY`-per-file rule is not engaged:

1. `<ts>_add_enquiry_notifications_pending_since.sql` — the column plus its partial index (§5.2).
2. `<ts>_add_lead_submissions_subdomain_time_idx.sql`:
   ```sql
   CREATE INDEX IF NOT EXISTS lead_submissions_subdomain_time_idx
       ON analytics.lead_submissions (subdomain, occurred_at DESC);
   ```
   `lead_submissions_ip_time_idx (ip_hash, occurred_at DESC)` already exists in the 2026-07-26
   baseline. The subdomain one does not, and the middleware cannot use the existing
   `lead_submissions_site_time_idx` because site resolution happens later, in the controller.

**Both must be applied to dev before the branch merges** — otherwise `development` serves a 42703
on the public enquiry endpoint. Apply to prod as part of the same deploy.

## 7. Test plan

**Feature suite (SQLite lane), extending `DeadCacheStoreTest` where it already covers this seam:**

1. `leads` in `Fallback` mode with a dead store: under limit → request proceeds to the controller.
2. Over limit in `Fallback` mode → **429**, not 503 and not 200.
3. `rate_limited` rows do not count toward the window (§4.2 constraint 2).
4. Both stores dead → 503 (degrades to `Closed`).
5. `EnquirySpamBlocklist::contains()` fails open; `add()` still throws.
6. Dispatch fault → 200 `{ok:true}` + `notifications_pending_since` set + enquiry row committed.
7. Reconciler re-dispatches both jobs and clears the marker.
8. Reconciler skips the confirmation past its window, still dispatches the notification.
9. `LogLeadRateLimits` writes the abuse row when `Cache::add` throws.
10. Guard: `FALLBACK_LIMITERS` membership is pinned, mirroring the "this is a security decision,
    keep it reviewed, do not accrete into it" convention on `FAIL_OPEN_LIMITERS`.

**Postgres lane (`tests/Postgres/`):** both new indexes exist with the expected predicates. Schema
assertions do not run in `composer test`.

**Not provable in either lane:** that a real Valkey outage exercises this path end-to-end. That is
drill 03's job — this design's acceptance criterion is a re-run of
`docs/runbooks/drills/03-redis-down.md` showing the enquiry probe returning 2xx with the row
present, replacing the current PARTIAL verdict on "no non-analytics data loss".

## 8. Risks and accepted trade-offs

1. **A dead Redis makes the limiter cheaper to defeat than usual.** The Postgres window is a true
   sliding window (stricter than Redis's fixed decay bucket at the same numbers), so this is a
   narrower exposure than fail-open would be — but the fallback counts only requests that reached
   the controller. An attacker who could kill Valkey would still face 3/min per IP.
2. **Two extra indexed counts per lead submission during an outage.** At 3/min/IP the volume is
   negligible, and they execute only on the fault path.
3. **Postgres becomes load-bearing for rate limiting during a cache outage.** If Postgres is also
   down there is no lead to save, and the design degrades to `Closed` (503) rather than open.
4. **Duplicate abuse rows during an outage** (§5.3), from browser auto-retry bursts that the
   Redis SETNX dedup would normally collapse. Accepted: a duplicate row beats a blind table.
5. **Wrong 429 wording** when the subdomain bucket trips in fallback mode (§4.3). Cosmetic.

## 9. Follow-ups explicitly out of scope

- A Cloudflare WAF rate-limiting rule on the enquiry path as defence-in-depth in a separate failure
  domain. Attractive, but it needs `api.partna.au` proxy status confirmed and the CF plan's rule
  budget checked, and it does not remove the need for any of the above.
- Extending `Fallback` mode to `public-subscribe` or other public write forms. Deliberately not
  done here: each addition to `FALLBACK_LIMITERS` is a security decision with its own substrate
  question ("what Postgres table already counts this?"), and `public-subscribe` has no equivalent
  of `analytics.lead_submissions`.
