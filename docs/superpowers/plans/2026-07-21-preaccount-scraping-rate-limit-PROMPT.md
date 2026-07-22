# PROMPT — Per-provider rate limit for pre-account scraping jobs

**Type:** Small follow-up (implementation). Deferred from the signup-flows-and-early-access branch (final whole-branch review, 2026-07-21).
**Effort:** S–M. One shared middleware wired onto two jobs + config + tests. No migration.
**Branch:** `feature/preaccount-scraping-rate-limit` off `development` (isolate in a git worktree — this repo has concurrent sessions).

---

## The problem

Two scraping-lane jobs call the source generators, which hit **external vendors** — Instagram via **Apify** (the flagged legal red path) and Google Business via the **Places API**:

- `app/Jobs/PreAccount/GeneratePreAccountSiteJob` — the initial pre-account build scrape.
- `app/Jobs/PreAccount/ApproveEarlyAccessBuildJob` — the early-access approval re-scrape.

Both do `SourceGeneratorRegistry->for($build->source_type)->generate($user, $site, $build->source_ref)` with **no per-provider rate limiter**. So a burst fans out unthrottled vendor calls:

- **Bulk early-access approval** is the concrete trigger: `StaffEarlyAccessController::approveBulk()` (`POST /api/staff/early-access/approve-bulk` with `{all_waitlisted:true}` or a large `ids[]`) dispatches one `ApproveEarlyAccessBuildJob` **per lead**, each re-scraping Instagram via Apify → an unthrottled Apify burst.
- The same latent risk exists on a burst of `GeneratePreAccountSiteJob` (many signups / a staff-build blast).

Hammering Apify risks rate-limit blocks/bans and amplifies the legal exposure (see the platform-legal notes: IG-Apify is CRITICAL). Cost isn't the concern — **vendor protection + not getting blocked** is.

## Goal

A **per-provider** (per `source_type`) rate limiter on the scraping lane, applied to **both** jobs, so no matter how many are dispatched at once, vendor calls are paced. `instagram` (Apify) and `google_business` (Places) get independent limits. It must be **cache/Redis-backed so the cap is global across all workers** (not per-process).

## Investigate FIRST (do not assume — mirror the real precedent)

1. **The closest precedent is the connect lane, NOT refresh.** `app/Jobs/Platforms/ConnectFetchJob` (async Apify connect) hits the SAME vendor (Apify) as these pre-account IG scrapes. Read its `middleware()` method and find how it rate-limits (the `RateLimited`/`RateLimitedWithRedis` middleware + the named `RateLimiter`). `app/Jobs/Platforms/RefreshConnectionJob` is a second reference but it paces the *refresh* lane (official APIs) via the `platform-refresh` limiter — **different vendor, different limiter**. Confirm which limiter the connect/Apify lane uses.
2. **Where are `RateLimiter`s registered?** grep `RateLimiter::for(` across `app/Providers/` (likely `AppServiceProvider` or a platform provider). Find the connect/Apify limiter's name + how its per-provider rate is read from config.
3. **Config.** Check `config/partna.php` for the connect rate-limit block (there's a deliberate split — a comment near `refresh.rate_limits` notes "connect (Apify) and refresh (official APIs) hit different vendors", so a `connect.rate_limits` or equivalent may already exist). If a suitable per-provider limiter + config already exist, **reuse them** — do not create a parallel one. If not, add a `partna.<lane>.rate_limits` map keyed by provider with an env-overridable default.
4. **Does `SourceGeneratorRegistry`/the generators expose the provider key** you'll rate-limit on? The job already has `$build->source_type` (`'instagram'` | `'google_business'`) — that's your limiter key.

## Implementation shape (adapt to what you find)

- Add a `public function middleware(): array` to **both** `GeneratePreAccountSiteJob` and `ApproveEarlyAccessBuildJob` returning the connect/Apify `RateLimited`-style middleware keyed on the job's `source_type` (so `instagram` and `google_business` throttle independently). Prefer reusing the connect lane's existing limiter/key scheme so pre-account scrapes and dashboard connects share one global Apify budget (they hit the same Apify account).
- If the two jobs load the build in `handle()` (they do — `PreAccountBuild::find($this->buildId)` / by signup), and `middleware()` runs BEFORE `handle()`, you need the `source_type` available in `middleware()`. Options: pass `source_type` into the job constructor at dispatch (cleanest — both dispatch sites can include it), or resolve it in `middleware()`. Verify the dispatch call sites: `PreAccountBuildService::requestBuild()` (dispatches `GeneratePreAccountSiteJob`), `PreAccountBuildService::reserve()`, and `StaffEarlyAccessController::approve/approveBulk` (dispatch `ApproveEarlyAccessBuildJob`). Threading `source_type` (or for the approve job, resolving via the signup→build) through is fine — keep it minimal.
- Do NOT break the existing `ShouldBeUnique` (uniqueFor) or `tries=1`/`timeout=300` on either job. The rate limiter middleware releases the job back to the queue when throttled — confirm that interaction with `ShouldBeUnique` (a released job should not be dropped by the unique lock; check the `uniqueFor` window vs. the backoff).

## Constraints

- The limiter must be **cache-backed (Redis in prod)** so the cap is shared across all workers, mirroring the existing connect/refresh limiters — a static/in-process counter is wrong (see the Nightwatch-failopen / PHP-FPM note: static counters don't work across processes).
- **Deployed dev env runs `QUEUE_CONNECTION=sync`** (jobs run inline). Verify the rate-limiter middleware degrades sanely under the sync driver (a `RateLimited` middleware that `release()`s a job has nothing to release to under sync — confirm it doesn't hang or silently drop the scrape; if the sync path can't honor the limiter, document that the throttle only bites under the real Redis/Horizon queue, which is what prod uses).
- Follow the repo's job conventions (`config('partna.queues.scraping')`, no typed `public bool $afterCommit` property — trait conflict).

## Acceptance criteria / tests

- Both `GeneratePreAccountSiteJob` and `ApproveEarlyAccessBuildJob` declare the rate-limit middleware keyed per provider.
- A feature/unit test proving the middleware is present and keyed on `source_type` (e.g. assert `->middleware()` returns the limiter with the expected key for an `instagram` vs a `google_business` build), OR a test that dispatching N jobs only lets the configured number through per window (if the test harness supports exercising the limiter — the connect/refresh lane's own tests are the pattern to copy; find and mirror them).
- `JobHygienePolicyTest` and any queue-coverage guard still green.
- `composer test` green (mind the unrelated `ConnectResolverYoutubeTest` order-dependent flake — it passes in isolation; not your regression).

## Context pointers

- Feature spec: `docs/superpowers/specs/2026-07-21-signup-flows-and-early-access-design.md` (§3.4 refresh/treadmill split explains the Apify-vs-Places vendor distinction).
- Platform-legal posture: Instagram-via-Apify is the red path — this throttle reduces exposure.
- The two jobs' current shape: `tries=1`, `timeout=300`, `ShouldBeUnique` on the build id / signup id, `onQueue(config('partna.queues.scraping'))`.
