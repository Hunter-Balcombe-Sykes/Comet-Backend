# Drill log — 02 Vendor outage during platform refresh

- **Date:** 2026-08-06 (AEST)
- **Runbook:** [../02-vendor-outage.md](../02-vendor-outage.md)
- **Operator:** Claude (Opus 5), driven by Josh
- **Code under test:** `drills/rerun-2026-08-06` @ `de8fcff7`
- **Environment:** LOCAL — worktree `backend-wt/drills-rerun-2026-08-06`, local Supabase stack,
  Homebrew Redis, Horizon 1 master / 5 supervisors (`supervisor-1` covers `platform_refresh`).
- **Variants run:** 1 (translated, `spotify`), 2 (raw exception, `fresha`), breaker + notifier,
  recovery. Plus a direct check of the `FeatureAvailability` cache-fault change.

## Preconditions — verified, not assumed

The local DB had **zero** `site.platform_connections`, so four were seeded on the drill user
`drill-rd-20260806` (`resource_id` is NOT NULL with no default and nothing else supplies it — the
platform slug was used, as in the 2026-08-05 run).

| Connection | Role | Baseline |
|---|---|---|
| `spotify` | Variant 1 victim, then recovery subject | `is_active=true`, `fails=0`, `status=NULL`, due (`last_refreshed_at` −30 d) |
| `fresha` | Variant 2 victim (raw path) | same |
| `soundcloud` | control | same |
| `bandcamp` | control | same |

| Config | Verified value |
|---|---|
| `partna.refresh.max_consecutive_failures` | **10** |
| Horizon | master 1, supervisors 5 |
| `RefreshConnectionJob` | `$maxExceptions` 3, `$backoff` [30, 120, 300] |

## Variant 1 — hard outage, translated path (`spotify`)

Payload poisoned to a non-resolving RFC 2606 host (`https://vendor-outage-drill.invalid/x`),
scoped to one connection, nothing machine-global.

| Observation | Value |
|---|---|
| Attempts | **1** |
| Horizon outcome | **DONE** (5 s) — not a failure |
| `last_refresh_status` | `unavailable` |
| `last_refresh_error` | `spotify_oembed_failed` |
| `consecutive_failures` | 0 → **1** |
| `last_refreshed_at` | unchanged (correct — the refresh did not succeed) |
| `queue:failed` | **empty** |

The upstream failure was translated, not thrown: the job completes successfully and the bookkeeping
carries the user-visible truth. Exactly as documented.

## Variant 2 — raw-exception path (`fresha`)

🔴 **The runbook's seed recipe cannot reach this path — see finding 1.** With
`payload => ['url' => …]` and no `selection`, `FreshaFetch::fetch()` throws
`FetchNotModifiedException` on its first guard, the connection records **`status='ok'`,
`fails=0`**, and no network call is ever made. The first attempt of this drill did exactly that and
would have been recorded as "Variant 2: 1 attempt, fine".

Re-seeded with a storewide selection (`{url, selection:{mode:'storewide', storeName, hiddenServiceIds}}`),
which is what makes `FreshaFetch` actually scrape:

| Attempt | Time | Δ from dispatch | Outcome |
|---|---|---|---|
| 1 | 23:48:48 | t+0 | FAIL (125.06 ms) |
| 2 | 23:49:23 | **t+35 s** | FAIL (25.79 ms) — `backoff[0]` = 30 |
| 3 | 23:51:24 | **t+156 s** | FAIL (13.89 ms) — `backoff[1]` = 120, `maxExceptions` 3 reached → terminal |

| After | Value |
|---|---|
| `last_refresh_status` | `error` |
| `last_refresh_error` | `Host did not resolve: vendor-outage-drill.invalid` — the raw `SafeUrlException` |
| `consecutive_failures` | **1** |
| `queue:failed` | **1 entry** |

**Bounded at exactly 3 attempts.** No retry storm, no rate-limit amplification.

Note the counter: `fails=1` after **three** attempts. `RefreshConnectionJob::failed()` writes the
bookkeeping once per *terminal* failure, not once per attempt — correct, and worth remembering when
reading the counter. Same as the 2026-08-05 observation.

## Other platforms unaffected mid-outage

Both controls were dispatched while `fresha` was mid-retry:

| Control | Outcome | Duration |
|---|---|---|
| `soundcloud` | DONE | 497.78 ms |
| `bandcamp` | DONE | 50.29 ms |

Both completed in under half a second while the victim was failing. The `platform-refresh` limiter
is keyed per platform, so one dead vendor cannot starve the others. (`bandcamp` recorded
`unavailable`/`bandcamp_no_releases` — its seeded URL is fabricated. That is the *translated* path
behaving correctly, not starvation.)

## Circuit breaker + notifier

Fast-forwarded rather than looping ten real failures. `spotify` used because its translated path
costs ~5 s per increment; `fresha`'s raw path costs ~156 s.

| Step | Result |
|---|---|
| `consecutive_failures` set to 9, one failing refresh | → **10**, `status='unavailable'` |
| Notifications table | 17 → **18**, a `Warning` at 23:52:34 — **fired once** |
| One more failing refresh | → **11** |
| Notifications table | **still 18** — no second notification, dedupe key held |
| Force every candidate due, then `php artisan integrations:refresh` | **"selected 3 due connection(s)"** |

The dispatcher selected **3 of 4** — a non-zero count that *excludes* the tripped connection, which
is the attributable form of the evidence. A bare `selected 0` would have proved nothing, since the
dispatcher gates on TTL-dueness first; forcing everything due first is what makes the skip
attributable to `consecutive_failures` alone.

## Recovery

`spotify`'s real payload restored, one **targeted** refresh dispatched with the breaker **still
open** (`fails=11`):

| Field | Before | After |
|---|---|---|
| `consecutive_failures` | 11 | **0** |
| `last_refresh_status` | `unavailable` | **`ok`** |
| `last_refresh_error` | `spotify_oembed_failed` | **NULL** |
| `last_refreshed_at` | −30 d | 23:53:44 (now) |

One healthy refresh fully resets the connection, and it executed **despite the open breaker** —
confirming the breaker gates the cron fan-out, not the job. If the counter did not reset on success,
the breaker would hold connections hostage after a vendor recovered; it does reset.

## `FeatureAvailability` on a cache fault — the merged change in scope here

The execute-prompt lists `FeatureAvailability::for()` no longer throwing on a cache fault as in
scope for drills 01/02 because it sits on queue paths (`ConnectFetchJob`, `ShopBrandConnectJob`,
`FreshaConnectFetch`, `CustomLinkSeeder`).

Worth stating plainly: **during a full Redis outage those queue paths do not run at all** — cache
and queue share one Redis server, so a total outage stops job delivery before any job can consult
`FeatureAvailability`. The guard therefore matters for a *cache-only* fault, not a total one.

Verified directly against a real outage (`brew services stop redis`, connection refused confirmed):

```
FeatureAvailability::for($user)  → RETURNED, class App\Services\FeatureAvailability\UserFeatureAvailability
  ->allows('integration.spotify') → true      (resolved from Postgres)
  breadcrumbs: 1 × feature_availability.cache_unavailable
```

No throw, no fail-open guess, one breadcrumb. **Change 3 confirmed** on the shared code path.

## Verdict

| Criterion (from runbook) | Result | Notes |
|---|---|---|
| Vendor failure → bounded attempts (1 translated, ≤3 raw), no unbounded retries | **PASS** | 1 attempt translated; exactly 3 raw at t+0 / +35 s / +156 s, matching `backoff` [30,120,300] and `maxExceptions` 3. |
| Failure bookkeeping written — user-visible state is honest | **PASS** | Both paths wrote `status`, `error` and the counter. Raw path writes once per terminal failure (`fails=1` after 3 attempts). |
| Other platforms unaffected mid-outage | **PASS** | Both controls DONE in <0.5 s while the victim was failing. |
| Breaker opens at 10; dispatcher skips; notifier fires exactly once | **PASS** | 10 → notification; 11 → no second notification. Dispatcher selected 3 of 4, excluding the victim. |
| Recovery: one healthy refresh fully resets the connection | **PASS** | 11 → 0, `unavailable` → `ok`, error cleared, **with the breaker still open**. |

**Overall: PASS**, matching 2026-08-05 on every criterion.

## Findings

1. **🔴 P2 — the runbook's `fresha` seed recipe cannot reach Variant 2, and fails silently as a
   PASS.** `FreshaFetch::fetch()` opens with
   `if (! $url || ! is_array($selection)) { throw new FetchNotModifiedException('fresha'); }`, so a
   connection seeded as `payload => ['url' => '…']` — exactly what the runbook prescribes — 304s on
   the first guard. **Failure scenario:** the operator poisons the URL, dispatches, sees one attempt
   and a job that completed, and records "Variant 2: bounded at 1 attempt". In reality `fresha`
   recorded **`status='ok'`, `fails=0`**, no HTTP request was made, no `SafeUrlFetcher` call
   happened, and the raw-exception path — the entire point of Variant 2, and the only path that can
   exhaust `maxExceptions` — was never executed. This run produced that result before the guard was
   traced. **Fixed here** — the runbook's seed now includes a storewide `selection`, with the reason.

2. **🟢 Confirmed — `RefreshConnectionJob::failed()` writes bookkeeping once per terminal failure,
   not per attempt.** `consecutive_failures` = 1 after three attempts. Correct, but it means the
   counter measures *episodes*, not *attempts* — relevant when reasoning about the breaker's
   threshold of 10.

3. **🟢 Confirmed — the breaker gates the cron fan-out, not the job.** A targeted refresh executed
   and fully reset a connection sitting at `fails=11`. That is the intended escape hatch for
   "vendor is back, fix my connection now".

4. **🟢 Confirmed — `FeatureAvailability::for()` no longer throws on a cache fault**, resolving from
   Postgres with a single `feature_availability.cache_unavailable` breadcrumb. Also recorded: a
   *total* Redis outage stops queue delivery outright, so this guard is about cache-only faults.

5. **🟢 No regression from the 2026-08-05 merge.** Every pass criterion matches the previous run;
   the refresh path is untouched by the auth/resilience work.

## Runbook corrections

Applied in this branch:

1. **`02-vendor-outage.md` Variant 2 seed** — the `fresha` connection must carry a `selection`, not
   just a `url`, or `FreshaFetch` 304s before any network call and Variant 2 silently tests nothing.
2. **`02-vendor-outage.md` Variant 2** — noted that a fabricated `.invalid` URL is fine for the
   attempt-count evidence but unusable for recovery, and that recovery is cleanest on a *translated*
   platform (`spotify`) whose real URL resolves — which is what both this run and 2026-08-05 did.

## Next run due

On material change to `RefreshConnectionJob`, `PlatformRefresher`, the fetch strategies, or the
rate-limiter / circuit-breaker config.
