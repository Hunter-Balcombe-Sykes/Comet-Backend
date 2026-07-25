# PROMPT — Bound the scheduled/on-demand refresh path with a FetchBudget

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).

---

## Where this sits

This is the escalation **R8 explicitly deferred** while shipping the connect-async
residual cleanup (merged to `development` 2026-07-25, `1a5503e8`). R8 bounded the
Fresha booking-GraphQL leg *within an open budget*, and then recorded that the
**scheduled/on-demand refresh path opens no fetch budget for any platform** — so R8's
own clamp, and every other budget-aware fetch leg, runs unbounded during a refresh.

Concretely: `FreshaFetch` (the Fresha refresh strategy) can reach **≈108 s** worst case
against `RefreshConnectionJob`'s **120 s** `$timeout` — a 12 s margin, on the one
platform whose scraper is redirect-heavy. When the job's timeout fires it throws
mid-write, and the job retries (unlimited `$tries`, capped by `maxExceptions = 3` and
`retryUntil(+2h)`), so a genuinely slow Fresha refresh burns three 120 s timeouts and
their backoffs before it fails for real.

**This is not a live incident today** — the ≈108 s figure is a pathological tail (five
redirect hops each taking the full 8 s per-hop ceiling, doubled by SafeUrlFetcher's
403 honest-UA retry). But the margin is thin, it is unbounded by design, and it is the
kind of latent risk worth closing while the context is fresh. The win is that the
refresh path gains the same wall-clock discipline the connect path already has.

## What the fix is, in one sentence

Open a `FetchBudget` at the **`RefreshConnectionJob::handle()` job boundary**, wrapping
the `PlatformRefresher::refresh()` call, so every budget-aware fetch leg reached during
a scheduled or on-demand refresh — for **every** platform, not just Fresha — is bounded
to a value that sits safely under the job's `$timeout`.

## Non-negotiables

- Read `CLAUDE.md` first. `scripts/audit/fix-flow.md` is the runbook.
- Branch `audit-fix/refresh-fetch-budget-2026-07-25` off **latest `development`** — it
  must include R8 (`FreshaScraper` is already budget-aware; that is load-bearing here).
  `git fetch origin development` first; `development` moves under you (a sibling merged
  PR #272 and eight CI commits during the R1–R8 run alone).
- Work in a **dedicated worktree** with its own `composer install` and a **copied**
  `.env` — do **not** symlink `vendor` or `.env`, that breaks feature tests
  (`uses(TestCase::class)->in('Feature')` resolves to the main checkout's path and the
  app never boots). Base it explicitly: `git worktree add .worktrees/<name>
  origin/development -b audit-fix/refresh-fetch-budget-2026-07-25`.
- **Verify every line number below before acting.** They were correct at `1a5503e8`;
  `development` moves and R8's own lines shifted. Grep the symbol, do not trust the
  citation.
- **Run tests in the FOREGROUND.** Do not background a test run and wait for a
  notification.
- Full suite: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (green was 5496 passed at
  `1a5503e8`). Never run it beside a running implementer subagent — the SQLite test DB
  and config cache are shared.
- **Forbid `git stash` explicitly in every subagent prompt.** A foreign stash entry
  from another branch lives in this repo; a bare `stash push` that no-ops followed by a
  `pop` would restore another session's WIP into this worktree.
- `vendor/bin/pint --dirty` only (`php artisan pint` does not exist here).
- `vendor/bin/phpstan analyse --memory-limit=1G` — the 128 M default OOMs on this
  codebase. It was `[OK] No errors` at `1a5503e8` (the ci-green-gates merge cleared the
  old `ShopBrand` drift). Your gate is **still zero**, none in a file you touched.
- **No Laravel migration files, no schema change.** Nothing here needs one. If a unit
  seems to, stop and escalate.
- Pest loads every test file into one PHP process, so a bare `function foo()` in a test
  file is a **global** symbol. Run `git grep -n "^function " -- tests/` before
  committing.

## Execution policy

Plan **Opus 4.8** · Implement **Sonnet 4.6** · Review a **separate** Sonnet 4.6 ·
final whole-branch review **Opus 4.8**. Keep plan and implement **separate** — this
changes a queued job's failure semantics platform-wide, so it is not a combine-in-one
S unit.

**Blocker gate — plan first, then surface to Josh before implementing.** This changes
the terminal state of a *slow* refresh for every platform (see "The one real decision"
below), which is user-facing (circuit breaker + a "connection refresh failing"
notification). Produce the plan, present the terminal-state decision and blast radius,
and get explicit go-ahead before writing code.

---

## The verified problem (all citations at `1a5503e8` — re-verify)

The refresh chain, and where a budget is (not) opened:

- `app/Jobs/Platforms/RefreshConnectionJob.php:98` — `handle(PlatformRefresher $refresher)`.
  Injects **no** `FetchBudget`, opens none. Calls `$refresher->refresh($connection)` at
  `:116`. Job `$timeout = 120` (`:43`), `$tries = 0` (`:36`, unlimited, bounded by
  `retryUntil(+2h)` at `:82`), `$maxExceptions = 3` (`:38`).
- `app/Services/Platforms/PlatformRefresher.php:36` — `refresh()` → `$strategy->run($connection)`
  at `:49`. No budget. Catches exactly three `Fetch*Exception` subclasses
  (`FetchNotModified` → quiet, `FetchShape` → `error` + report, `FetchUnavailable` →
  `unavailable`). **Anything else propagates uncaught.**
- `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php:29` — `run()` →
  `$this->fetch->fetch($connection)`. No budget.
- `app/Services/Platforms/Strategies/Fetch/FreshaFetch.php:50,:55` — calls
  `FreshaScraper::fetchEmployeeServices()` (budget-aware post-R8) and/or
  `fetchLocation()` (budget-aware via `SafeUrlFetcher`). With **no budget open**,
  `FetchBudget::remaining()` returns `null` → every leg runs at its flat ceiling → the
  ≈108 s worst case.

Both triggers reach this same `handle()`: the hourly cron
(`RefreshIntegrationConnectionsCommand` / `integrations:refresh`) and the manual
"refresh" button (`RefreshController` dispatches `RefreshConnectionJob`; it does not
call `PlatformRefresher` synchronously). So the job boundary is the single chokepoint
for the entire refresh path.

The budget mechanism you are extending:
`app/Services/Http/FetchBudget.php` — `open(float $seconds, callable $work)` sets a
wall-clock deadline for `$work`'s duration and clears it in a `finally`; `remaining()`
returns seconds left, or **`null` when no budget is open** ("unbounded", never "out of
time" — callers check `<= 0`, never falsiness). It is bound **`scoped`**
(`AppServiceProvider.php:112`), and Laravel's queue worker calls
`forgetScopedInstances()` between jobs, so within one job every `app(FetchBudget::class)`
resolution shares one instance and no deadline leaks between jobs.

---

## The two traps you must not fall into

### Trap 1 — the budget MUST be opened at the job boundary, NOT in `PlatformRefresher`

`PlatformRefresher::refresh()` is the obvious-looking home, and it is **wrong**. Grep
`->refresh(` / `PlatformRefresher` — it has ~11 callers, and several run in
web-request scope where a budget may already be open:

- `ShopController` — **seven** synchronous calls (`:329,:478,:535,:680,:805,:832,:877`)
- `IntegrationConnectionObserver` — three (`:53,:407,:449`), fired on save, possibly
  mid-connect-flow
- `ShopBrandConnectJob:197`
- `RefreshConnectionJob:116` (the one you want)

Wrapping `PlatformRefresher::refresh()` in `open()` would **nest** inside any caller
that already has a budget open — and **nesting fails OPEN**: the inner `finally` clears
the *outer* deadline, leaving the outer operation unbounded for the rest of its run
(strictly worse than today). `FetchBudget`'s own docblock (`:44-50`) states this;
`FreshaConnectFetch` documents the same constraint for the connect path.

Open the budget in `RefreshConnectionJob::handle()` — a fresh job scope with no open
budget — and nowhere else. Confirm nothing in the job's middleware or the queue
pipeline opens a `FetchBudget` before `handle()` runs (the `RateLimited` middleware does
not). Leave the `ShopController`/observer/`ShopBrandConnectJob` synchronous paths
untouched: they are in web-request scope, already bounded by the PHP-FPM/nginx request
timeout, and are a separate (pre-existing) concern from the job's SIGKILL-only bound.

### Trap 2 — prove the scoped instance actually crosses the job → scraper boundary

The whole fix rests on the budget opened in `handle()` being the **same** scoped
instance the scraper reads via `remaining()`. R8 relied on exactly this for the
controller → scraper hop within one HTTP request; you are relying on it for the
job → scraper hop within one queued job. It *should* hold (`scoped` binding, one
container scope per job, autowired `app(FreshaScraper::class)` inside `FreshaFetch`),
but "should" is not evidence. **Add a test that opens a small budget through the real
job/refresh path and asserts a budget-aware leg is actually clamped** — do not assert
the mechanism only in the unit test for `FetchBudget`.

`remaining() === null` is already handled correctly by every budget-aware fetcher
(`SafeUrlFetcher`, R8's `FreshaScraper` clamp) — you are not re-introducing that logic,
only ensuring a budget *is* open so `remaining()` stops returning null on this path.
Do not add any `if (! $remaining)`-style check anywhere.

---

## The one real decision — take it to Josh in the plan

Today, a slow Fresha refresh that exceeds ~108 s hits the **120 s job SIGKILL/timeout**
→ `TimeoutException` → retry (×3 via `maxExceptions`) → eventually
`RefreshConnectionJob::failed()` writes `last_refresh_status = 'error'`, increments
`consecutive_failures`, `report()`s to Nightwatch, and the health notifier warns the
user.

After the fix, a refresh that exceeds the **budget** (e.g. 90 s) will have
`SafeUrlFetcher` throw `SafeUrlException` (budget exhausted) mid-fetch. Trace where that
lands: `FreshaFetch` does **not** catch it around `fetchLocation()` (`:55` is
unguarded), `PlatformRefresher` catches only the three `Fetch*Exception` subclasses, so
it propagates uncaught to `RefreshConnectionJob::failed()` — the **same** `'error'` +
Nightwatch + breaker + notification terminal state, just reached deterministically at
90 s instead of after 3×120 s of timeouts.

That is arguably a strict improvement (cheaper, deterministic, and it keeps the failure
*under* the SIGKILL so `failed()`'s clean terminal write is reliable rather than racing
a killed process). **But decide deliberately:** should a *budget-exhaustion* during
refresh be `'error'` (loud — pages Nightwatch, trips the breaker, emails the user) or
`'unavailable'` (quiet — preserves the last-known payload, no page)? `FreshaFetch`'s own
philosophy is that "an unreachable/empty menu throws `unavailable` so a transient scrape
failure never wipes a selection" — and a self-imposed deadline is closer to a transient
miss than to data corruption. Mapping it to `unavailable` would mean catching
`SafeUrlException` (and any budget-exhaustion signal) at the right seam and rethrowing
as `FetchUnavailableException`. Present both options with the blast radius; this is the
decision Josh needs to sign off before implementation.

Whatever you choose, **verify the resulting terminal state with a test** that opens a
tiny budget around a real refresh and asserts the exact `last_refresh_status` and
whether Nightwatch/the notifier fire.

---

## The value, and a guard for it

Mirror `connect_budget_seconds` (`config/partna.php:1307` —
`'connect_budget_seconds' => (int) env('PARTNA_CONNECT_BUDGET_SECONDS', 20)`): add a new
key in the same `http_fetch` block, e.g.
`'refresh_budget_seconds' => (int) env('PARTNA_REFRESH_BUDGET_SECONDS', 90)`. A config
key is warranted here (unlike R8's single-use const): it is a genuine operational
tunable, parallel to the connect budget.

**The invariant:** `refresh_budget_seconds` must stay meaningfully **below**
`RefreshConnectionJob::$timeout` (120 s), with headroom for the non-fetch work a refresh
still does after the budget closes — the `Cache::lock($key, 10)->block(5, …)` acquisition
(`ScheduledRefresh.php:47`, up to 5 s), the projector `sync()` DB upserts, the model
write + observer cache purge, and the health notifier. ~90 s leaves ~30 s of headroom;
recommend that, but justify your number. If the budget ever meets or exceeds the job
timeout, the SIGKILL wins and the budget is moot.

Consider a **lockstep guard test** (in the spirit of `StrandedPendingWindowLockstepTest`
from the R1–R8 run) that fails if `refresh_budget_seconds >= RefreshConnectionJob::$timeout`,
so a future edit to either value can't silently invert the invariant. Judge whether it
earns its place; if you add it, make it fail for the right reason with a clear message.

---

## Explicitly OUT of scope

- **Wrapping `PlatformRefresher::refresh()`** — see Trap 1. Job boundary only.
- **The `ShopController` (×7), observer (×3), and `ShopBrandConnectJob` synchronous
  refresh calls** — web-request scope, bounded by the request timeout, and some already
  sit inside an open budget. Bounding those is a separate unit and must not be folded in.
- **`FreshaScraper` internals / R8's GraphQL clamp** — already correct; do not touch.
- **The unbudgeted-DNS overshoot** noted in `FetchBudget`'s docblock (`:52-56`) — a
  resolver-level concern, out of scope everywhere.
- **Any new config key beyond `refresh_budget_seconds`, any route/contract change, any
  migration.**

---

## Tests

Add coverage that proves the fix and pins the decision, following the existing Fresha /
refresh test conventions (read them first — `FreshaRefreshTest`,
`FreshaEmployeeMenuObservabilityTest`, `ConnectFetchBudgetTest`,
`tests/Unit/Http/FetchBudgetTest.php`, `tests/Unit/Http/SafeUrlFetcherBudgetTest.php` —
the last two are the `open(...)`-around-the-call-with-`Http::fake`-`usleep` pattern to
copy). At minimum:

1. **A budget is open during a refresh job** — drive `RefreshConnectionJob::handle()`
   (or the tightest faithful equivalent) for a budget-aware platform and assert a fetch
   leg actually sees a clamped `remaining()`/timeout. This is the Trap 2 proof: it must
   fail against the pre-fix code (no budget → `null` → flat ceiling).
2. **The chosen terminal state on budget exhaustion** — open a tiny budget around a
   refresh whose fetch overruns it and assert the exact `last_refresh_status`
   (`error` vs `unavailable`, per Josh's decision) and whether Nightwatch/the notifier
   fire.
3. **A fast refresh is unaffected** — a healthy platform whose fetch finishes well
   inside the budget behaves byte-identically (no premature failure, same
   `last_refresh_status`).
4. **The invariant guard**, if you add it (see above).

Run these **targeted** files in the foreground. `tests/Feature/Platforms/DarkMergeProofTest.php`
is the merge-safety guarantee for the whole connect programme and must stay green.

---

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green (5496 passed at `1a5503e8`, plus
   your new tests).
2. `vendor/bin/pint --dirty`; `vendor/bin/phpstan analyse --memory-limit=1G` — clean
   (`[OK] No errors` at `1a5503e8`).
3. Independent whole-branch review on **Opus 4.8**, diff handed over as a file.
4. **`DarkMergeProofTest` must still hold** — if any change here makes it fail, you have
   changed behaviour, not tidied it.
5. Report: the terminal-state decision as taken; unit done / deferred with reason; the
   worst-case wall clock before and after; test status; branch name. **Do not merge or
   push without Josh's say-so.**

## Reference

- The R8 unit that recorded this escalation, and its full refresh-chain trace, are in
  the connect-residual run — commit `d800b8f8` (`fix(platforms): R8 — bound Fresha's
  booking-GraphQL leg against the open FetchBudget`) and the surrounding R1–R8 commits
  (`56d85e23`..`1a5503e8`).
- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`
- The connect-side budget precedent (W1): `App\Services\Http\FetchBudget`,
  `App\Services\Http\SafeUrlFetcher`, `FreshaController::saveSelection()`'s
  `budget->open(...)`.
- Runbook: `scripts/audit/fix-flow.md`.
