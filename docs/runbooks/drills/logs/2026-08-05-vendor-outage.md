# Drill log — 02 Vendor outage during platform refresh

- **Date:** 2026-08-05 (AEST; all times below UTC)
- **Runbook:** [../02-vendor-outage.md](../02-vendor-outage.md) (at commit `d6caef96`; repo HEAD `d6caef96`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test` on worktree
  `backend-wt/drills-2026-08-05`, local Supabase (67 migrations from-zero), local Redis with the
  deployed keyspace split, bare `queue:work redis --queue=platform_refresh`.
- **Mode/variants run:** **Variant 1** (hard outage, `.invalid` host) **and Variant 2
  (the raw-exception path — unexercised on 2026-07-31 and now closed)**, plus breaker,
  notifier, dispatcher-skip and recovery.

## Connections seeded

Drill user `drill-vo-20260805`, four active connections, all `last_refresh_status='ok'`,
`consecutive_failures=0`, `last_refreshed_at` 30 days old (so the dispatcher sees them as due):

| platform | surface_key | strategy | role in the drill |
|---|---|---|---|
| `spotify` | `spotify.player` | `OEmbedFetch` | Variant 1 victim + breaker/notifier subject |
| `soundcloud` | `soundcloud.player` | `OEmbedFetch` | control — must stay healthy mid-outage |
| `youtube` | `youtube.channel` | `YoutubeFetch` | control — must stay healthy mid-outage |
| `fresha` | `fresha.book` | `FreshaFetch` | **Variant 2 victim (raw-exception path)** |

`max_consecutive_failures=10`, `refresh_budget_seconds=90`, `notifications.email_enabled=false`
(so the in-app `notifications.notifications` row is the notifier evidence, not an inbox).

## Timeline

| Time (UTC) | Phase | Action / observation |
|------------|-------|----------------------|
| 15:33:20 | ARRANGE | Four connections seeded, baseline recorded. |
| 15:33:40 | INJECT V1 | `spotify.payload.link` → `https://vendor-outage-drill.invalid/x`; one `RefreshConnectionJob` dispatched. |
| 15:33:46 | OBSERVE V1 | **1 attempt**, job `DONE` in 5 s. `status=unavailable`, `consecutive_failures=1`, `error=spotify_oembed_failed`. Translated path, exactly as hypothesised. |
| 15:34:30 | INJECT V2 | `fresha.payload.url` → `https://vendor-outage-drill.invalid/a/drill`; controls (`soundcloud`, `youtube`) dispatched **during** the outage alongside it. |
| 15:34:31–33 | OBSERVE | Controls `DONE` in 493 ms and 2 s. **No starvation, no rate-limit amplification.** |
| 15:34:33 | OBSERVE V2 | Attempt 1 → `FAIL` (78 ms). `SafeUrlException` escaped `PlatformRefresher` uncaught, as designed. |
| 15:35:05 | OBSERVE V2 | Attempt 2 → `FAIL`. **t+32 s** (backoff[0]=30). |
| 15:37:06 | OBSERVE V2 | Attempt 3 → `FAIL`. **t+153 s** (backoff[1]=120). `maxExceptions`=3 reached → terminal. |
| 15:37:06 | OBSERVE V2 | `failed()` ran: `status=error`, `consecutive_failures=1`, `error='Host did not resolve: vendor-outage-drill.invalid'`; one `failed_jobs` row; one `integrations.refresh.job_failed` log line. |
| 15:38:18 | BREAKER | `spotify` primed to `consecutive_failures=9`, one failing refresh → **10**. Notification row created. |
| 15:38:5x | BREAKER | Second failing refresh → `consecutive_failures=11`, notification count **still 1**. Dedupe holds. |
| 15:39:1x | BREAKER | All connections forced due; `php artisan integrations:refresh` → **"selected 3 due connection(s)"** — the tripped `spotify` excluded, the three healthy ones selected. |
| 15:39:31 | RECOVER | Payloads restored; targeted refresh dispatched for `spotify` **while its breaker was open** (`consecutive_failures=11`). |
| 15:39:32 | RECOVER | `spotify` → `status=ok`, `consecutive_failures=0`, `error=null`. One healthy refresh fully resets the connection. |

## Evidence

Variant 1 — translated failure, one attempt:

```
poisoned spotify id=019fcd68-6d48-7117-ae3b-f91555d42773
  15:33:40 App\Jobs\Platforms\RefreshConnectionJob ........ RUNNING
  15:33:46 App\Jobs\Platforms\RefreshConnectionJob ........ 5s DONE

fresha      status=ok           fails=0
soundcloud  status=ok           fails=0
spotify     status=unavailable  fails=1  err=spotify_oembed_failed
youtube     status=ok           fails=0
```

Variant 2 — the raw path, end to end (**new; 2026-07-31 could not reach this**):

```
  15:34:30 RefreshConnectionJob RUNNING
  15:34:31 RefreshConnectionJob .. 493.16ms DONE     # control: soundcloud
  15:34:31 RefreshConnectionJob RUNNING
  15:34:33 RefreshConnectionJob ........ 2s DONE     # control: youtube
  15:34:33 RefreshConnectionJob RUNNING
  15:34:33 RefreshConnectionJob ... 78.10ms FAIL     # fresha attempt 1
  15:35:05 RefreshConnectionJob .... 8.49ms FAIL     # attempt 2  (t+32s, backoff 30)
  15:37:06 RefreshConnectionJob ... 23.55ms FAIL     # attempt 3  (t+153s, backoff 120) -> terminal

queue:failed
  2026-08-04 15:37:06  d2574dfc-…  redis@platform_refresh  App\Jobs\Platforms\RefreshConnectionJob
failed_jobs row: App\Services\Http\SafeUrlException: Host did not resolve: vendor-outage-drill.invalid

fresha  status=error  fails=1  err=Host did not resolve: vendor-outage-drill.invalid
```

Breaker + notifier:

```
spotify primed to fails=9
notifications(platform_connection) before = 0
-> spotify fails=10 status=unavailable
   notifications(platform_connection) after = 1
   dedupe=platform_connection_failed:019fcd68-…:2026-07-05T15:33:20.000000Z

second failing refresh -> spotify fails=11
   notifications(platform_connection) = 1        # notifier did NOT fire again
```

Dispatcher skip — a non-zero count that excludes the victim, not a zero count:

```
all forced due (last_refreshed_at = now()-30d on every row)
$ php artisan integrations:refresh
Platform refresh: selected 3 due connection(s) for dispatch.    # 4 connections, spotify excluded
```

Recovery:

```
payloads restored
targeted recovery refresh dispatched for spotify (breaker OPEN, fails=11)
-> spotify  status=ok  fails=0  err=(null)
   soundcloud status=ok fails=0 / youtube status=ok fails=0
```

Log-quietness check — an outage must stay quieter than a shape error:

```
grep -c integrations.refresh.bad_shape  storage/logs/laravel.log  ->  0
grep -c integrations.refresh.job_failed storage/logs/laravel.log  ->  1
```

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| Vendor failure → bounded attempts (1 translated, ≤3 raw), no unbounded retries | **PASS** | 1 attempt translated; exactly 3 attempts raw at t+0 / +32 s / +153 s, matching `backoff` [30,120,300] and `maxExceptions` 3. |
| Failure bookkeeping written — user-visible state is honest | **PASS** | Both paths wrote `status`, `error` and the counter. The raw path's write comes from `RefreshConnectionJob::failed()`, and it fires **once per terminal failure, not once per attempt** (`fails=1` after 3 attempts) — correct, and worth knowing when reading the counter. |
| Other platforms unaffected mid-outage | **PASS** | Both controls completed in <2 s while the victim was failing. The `platform-refresh` limiter is keyed per platform, so one dead vendor cannot starve the others. |
| Breaker opens at 10; dispatcher skips; notifier fires exactly once | **PASS** | 10 → notification; 11 → no second notification (episode-scoped dedupe key). Dispatcher selected 3 of 4. |
| Recovery: one healthy refresh fully resets the connection | **PASS** | `spotify` went 11 → 0 and `error` → `ok` on a single targeted refresh, **with the breaker still open** — confirming the breaker gates the cron fan-out, not the job. |

**Overall: PASS**, and materially more complete than 2026-07-31 — the raw-exception path is now
evidenced rather than assumed.

## Findings

1. **The runbook's named platform for Variant 2 is wrong, and the reason matters.** It says to
   use `google-business` to reach the raw-exception path. **No registered platform stores a
   credential in `payload`** — `google-business` authenticates with an app-level
   `services.google_maps.server_api_key` header, and *every* non-ok Places response funnels
   through `fetchPlaceDetails() → null → FetchUnavailableException`. So a dead Google key
   produces a **translated** `unavailable`, never a raw escape. Variant 2 as written is
   unreachable for that platform, which is why 2026-07-31 skipped it and recorded the raw path
   as unexercised. **The platform that does reach it is `fresha`**: `FreshaScraper::fetchLocation()`
   is the only place in `app/Services/Platforms/` that calls the throwing `SafeUrlFetcher::fetch()`
   rather than `tryFetch()`, and `PlatformRefresher` deliberately rethrows a genuine
   `SafeUrlException`. **Fixed in this branch** — runbook corrected.
2. **"Vendor up, token dead" is not an injectable failure mode on this platform today.** That is
   a *finding about the architecture*, not a gap in the drill: with no per-connection credentials,
   there is no stored secret to poison, so the auth-failure class of outage cannot occur
   per-connection. Good news for blast radius; worth stating explicitly so nobody re-plans a
   Variant 2 around a credential that does not exist. Revisit if any platform ever stores an
   OAuth token on the connection row.
3. **`RefreshConnectionJob::failed()` is the only writer that can un-stick a `pending` row, and
   it only runs on the raw path.** The code comments already say this; the drill confirms it
   end-to-end. No change needed — recorded because it is the load-bearing reason `failed()`
   must never be simplified away.
4. **The local worker's `--tries=1` is silently overridden by the job's `$tries = 0`.** A bare
   `php artisan queue:work` looks like it will retry once, and retried three times. Correct
   Laravel behaviour (job property wins), but it means the `tries` column in Horizon's config
   is not what bounds these jobs — `maxExceptions` is. Anyone reasoning about retry blast radius
   from `config/horizon.php` alone will get it wrong. **Fixed in this branch** — noted in
   `docs/runbooks/queue-backed-up.md`, which currently tells operators the opposite ("every
   `supervisor-*` lane sets `'tries' => 1` — Horizon does not retry a job that throws").

## Runbook corrections

Applied to `../02-vendor-outage.md` in the same commit as this log:

1. **Replace `google-business` with `fresha` as the Variant 2 target**, with the one-line reason
   (only `FreshaScraper::fetchLocation()` uses the throwing `fetch()`), so the next run does not
   re-discover this.
2. **Drop the "poison the stored credential" framing** — no registered platform has one. Variant 2
   is now "poison a payload URL on a strategy that lets `SafeUrlException` escape".
3. **Add the seed recipe.** There is no `IntegrationConnectionFactory`; the runbook said "connect
   one through the dashboard" which is not practical on a fresh local DB. Added the four-line
   `IntegrationConnection::create()` seed used here, including the `last_refreshed_at` back-date
   needed for the dispatcher to consider a row due.
4. **Warn that the seeded URL must be a real page if you want to test recovery.** This run's
   `fresha` connection could not be recovered because its seed URL was fabricated — the restore
   step pointed at a non-existent Fresha page and kept failing (`abort(502)` → raw path again).
   Seed artifact, not a system fault, but it cost a cycle and will cost the next operator one too.

## Next run due

On material change to `RefreshConnectionJob`, `PlatformRefresher`, the fetch strategies, or the
rate-limiter / circuit-breaker config.
