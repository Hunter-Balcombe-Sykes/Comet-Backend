# Drill log — 02 Vendor outage during platform refresh

- **Date:** 2026-07-31
- **Runbook:** [../02-vendor-outage.md](../02-vendor-outage.md) (at commit `c10d6990`; repo HEAD `b19ca5d3`)
- **Operator(s):** Claude (Opus 5), driven by Josh
- **Environment:** LOCAL stack — Herd site `partna-drill.test`, local Supabase
  (`supabase_db_Partna-Development`), local Redis 6379.
- **Mode/variants run:** **Variant 1 (hard outage)** via a non-resolving host, plus the full
  circuit-breaker / notifier / recovery sequence. **Variant 2 (auth failure) NOT run** — see
  Findings 3.

## Setup

Two connections on drill user `drill-wk-20260731`, both using `OEmbedFetch` (which takes its
URL from `payload['link']`) so the injection point is data, not DNS config:

| Role | Platform | surface_key | id |
|---|---|---|---|
| Outage victim | spotify | `spotify.player` | `019fb69d-7b90-…` |
| **Control** (isolation check) | soundcloud | `soundcloud.player` | `019fb69d-7bac-…` |

Healthy baseline captured first — a real oEmbed fetch to Spotify succeeded and enriched the
payload (`name: "Coldplay"`, `embedUrl`, `thumbnail`), confirming the happy path genuinely
reaches the vendor:

```
BASELINE spotify: status='ok' err=NULL fails=0 last_refreshed='2026-07-31T05:20:12+00:00'
```

## Injection technique — no `sudo`, and better than the runbook's

The runbook's Variant 1 says to `sudo` an `/etc/hosts` entry and flush DNS. That was replaced
with: **point `payload['link']` at an RFC-2606 reserved `.invalid` host**, which can never
resolve.

```
poisoned link -> https://vendor-outage-drill.invalid/artist/x
```

Same failure shape (DNS resolution failure → connection-level error), but it needs no `sudo`,
mutates no machine-global state, cannot leak past the drill, and is scoped to exactly one
connection instead of every caller of that vendor on the machine. Recommended as the default —
see Runbook corrections.

## Timeline

| Time (UTC) | Phase | Action / observation |
|------|-------|----------------------|
| 05:20:12 | ARRANGE | Healthy baseline refresh on spotify → `ok`, fails=0. |
| 05:20:3x | INJECT | `payload.link` → `https://vendor-outage-drill.invalid/…`; original saved. |
| 05:20:38 | OBSERVE | `RefreshConnectionJob` dispatched. **1 attempt**, `5s DONE` (job *succeeded*). No retry, no delayed entry. |
| 05:20:44 | OBSERVE | spotify: `status='unavailable'`, `fails=1`, `err='spotify_oembed_failed'`. `queue:failed` empty. No `bad_shape` breadcrumb. |
| 05:22:44 | OBSERVE | **Control**: soundcloud refresh dispatched mid-outage → `1s DONE`, `status='ok'`, fails=0. No starvation. |
| 05:23:08 | BREAKER | `consecutive_failures` fast-forwarded to 9, one more failing refresh → **fails=10**, notifications **0 → 1**. |
| 05:23:5x | BREAKER | Another failing refresh → fails=11, notifications **still 1**. Dedupe holds. |
| 05:24:0x | BREAKER | Both connections forced due; `integrations:refresh` → **selected 1**, and it was **soundcloud**. spotify skipped by the open breaker. |
| 05:24:19 | RECOVER | Link restored; **targeted** refresh dispatched while breaker still open → executed (`450.42ms DONE`). |
| 05:24:20 | RECOVER | spotify: `status='ok'`, **fails=0**, `err=NULL`. Full reset on one healthy refresh. |

## Evidence

Bounded attempts — the outage took the **translated** path, not the raw-exception path:

```
2026-07-31 05:20:38 App\Jobs\Platforms\RefreshConnectionJob ........ RUNNING
2026-07-31 05:20:44 App\Jobs\Platforms\RefreshConnectionJob ........ 5s DONE
=== attempt count (RUNNING lines) === 1
ready=0 reserved=0 delayed=0
```

Bookkeeping + control isolation:

```
soundcloud   status=NULL         fails=0 err=NULL          # untouched during outage
spotify      status='unavailable' fails=1 err='spotify_oembed_failed'
INFO  No failed jobs found.
integrations.refresh.bad_shape occurrences: 0              # correctly quiet for an outage
```

Control refreshed normally *while the other vendor was dead*:

```
2026-07-31 05:22:44 RefreshConnectionJob ........ RUNNING
2026-07-31 05:22:45 RefreshConnectionJob ........ 1s DONE
soundcloud AFTER: status='ok' fails=0 refreshed='2026-07-31T05:22:45+00:00'
```

Breaker + notifier (fires once, then dedupes):

```
max_consecutive_failures=10
notifications before=0
spotify: fails=10 status='unavailable'   notifications after=1

spotify fails=11
notifications after 2nd failure=1  (must still be 1 => dedupe works)
```

Breaker attribution — both due, only the healthy one selected:

```
soundcloud due(30d ago) fails=0
spotify    due(30d ago) fails=11
Platform refresh: selected 1 due connection(s) for dispatch.
job: App\Jobs\Platforms\RefreshConnectionJob | platform hint: ['soundcloud']
```

Recovery:

```
pre-recovery: fails=11 status='unavailable'
TARGETED refresh dispatched while breaker is OPEN (fails=11)
2026-07-31 05:24:19 RefreshConnectionJob ........ RUNNING
2026-07-31 05:24:20 RefreshConnectionJob .. 450.42ms DONE
RECOVERY spotify: status='ok' fails=0 err=NULL
```

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| Vendor failure → bounded attempts (1 translated, ≤3 raw), no unbounded retries | **PASS** | Exactly **1** attempt; job completed successfully. `FetchUnavailableException` translated as designed — never reached the job's retry machinery. |
| Failure bookkeeping written (`status`, `error`, counter) — user-visible state honest | **PASS** | `unavailable` / `spotify_oembed_failed` / `fails=1`. |
| Other platforms unaffected mid-outage | **PASS** | soundcloud refreshed `ok` in 1s during the spotify outage. |
| Breaker opens at 10; dispatcher skips; notifier fires exactly once | **PASS** | 10 → notification #1; 11 → still 1. Dispatcher selected soundcloud only, with both due. |
| Recovery: one healthy refresh fully resets the connection | **PASS** | `ok` / `fails=0` / `err=NULL`. Counter does **not** hold the connection hostage. |

**Overall: PASS** (Variant 1 + breaker/notifier/recovery. Variant 2 not run — Finding 3.)

## Findings

1. **The health notification was written with no email on the owner.** The drill user's
   `email` is `NULL`, yet a `notifications.notifications` row was created. That is correct for
   the database channel and consistent with the documented "provisional users have no email"
   rule, but it means **breaker notifications for an unclaimed/provisional user are recorded and
   never delivered anywhere a human will look**. Not a bug in this drill's path; worth deciding
   deliberately before pilot, because a silently-undeliverable "your integration is failing"
   notice is indistinguishable from a healthy one. **Flagged, not fixed.**
2. **`integrations:refresh` reporting 0 selected is ambiguous.** On the first dispatcher run it
   printed "selected 0" — which looked like breaker evidence but was actually just TTL
   non-dueness, since the control had refreshed seconds earlier. Only after forcing both
   connections due did the run become attributable. Any future drill/log asserting "the breaker
   skipped it" from a bare `selected 0` is asserting nothing. Reflected in the runbook.
3. **Variant 2 (auth failure, vendor up / token dead) was NOT run.** The platform chosen for
   Variant 1 (`spotify` via oEmbed) has **no stored credential to poison** — oEmbed is
   unauthenticated. Testing Variant 2 honestly needs a credentialed platform (e.g.
   `google-business`), which needs a real vendor credential in the local `.env`. **Deliberately
   left unrun rather than faked.** The raw-exception path (`$maxExceptions` 3, backoff
   [30,120,300]) is therefore **still unexercised** — this drill only proved the *translated*
   path. That is the meaningful residual gap.

## Runbook corrections

Applied to `../02-vendor-outage.md` in the same commit as this log:

1. **Replace the `sudo /etc/hosts` injection with a `.invalid` payload host** as the default
   Variant 1 technique. No `sudo`, no machine-global DNS mutation, no cleanup that can be
   forgotten, and scoped to one connection. Keep `/etc/hosts` only for platforms whose host is
   hardcoded in the fetch strategy rather than read from the payload.
2. **Warn that `selected 0` from `integrations:refresh` is not breaker evidence.** Add the step
   of forcing all candidate connections due (`last_refreshed_at` well in the past) so the skip
   is attributable to `consecutive_failures` and nothing else.
3. **Note that the translated and raw-exception paths need different platforms.** An
   unauthenticated fetch strategy can only demonstrate the translated path; state that
   exercising `$maxExceptions`/backoff requires a platform that can throw an untranslated error.

## Next run due

On material change to `RefreshConnectionJob`, `PlatformRefresher`, or rate-limiter /
circuit-breaker config. **Re-run including Variant 2** once a credentialed platform is
available locally — the raw-exception path remains unexercised (Finding 3).
