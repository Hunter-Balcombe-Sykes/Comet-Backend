# PROMPT — Make FetchBudget re-entrant, then bound the refresh path with it

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
>
> **This supersedes `2026-07-25-refresh-fetch-budget-PROMPT.md`.** That one scoped the
> refresh fix alone and was forced to place the budget at the job boundary *because*
> `FetchBudget` could not be opened re-entrantly. Unit A here removes that constraint,
> which lets Unit B place the budget better. Delete the older prompt when this ships.
>
> **Both of Unit B's design decisions are already made by Josh — they are not open
> questions for the plan phase. Build to them.** (1) Placement: **broad** — bound every
> refresh caller via `PlatformRefresher`. (2) Terminal state on budget exhaustion:
> **quiet** — a non-event that preserves the payload and does NOT trip the breaker,
> notify the user, or page Nightwatch. Details and the two traps that make each
> non-trivial are in Unit B.

---

## Where this sits

Two coupled units, in order. The first is foundational; the second is the fix that
motivated it and that gets *better* because of it.

- **Unit A — make `FetchBudget` re-entrant.** Today `FetchBudget::open()` cannot nest:
  a second `open()` inside a running one clears the outer deadline in its `finally`
  ("fails OPEN"). That single limitation is *why* the budget is opened ad-hoc at
  scattered call sites, each author having to remember to do it — and why reviews on
  the connect programme kept finding places that forgot (the refresh path being the
  latest). Add a re-entrant entry point so "this operation runs inside a budget" can be
  a **structural guarantee at a shared chokepoint**, not a thing each caller remembers.

- **Unit B — bound the scheduled/on-demand refresh path.** This is the escalation R8
  deferred (connect-residual run, merged `1a5503e8`): the refresh path opens no fetch
  budget for any platform, so `FreshaFetch` can reach **≈108 s** against
  `RefreshConnectionJob`'s **120 s** `$timeout`. With Unit A in hand, the budget goes at
  the shared `PlatformRefresher` chokepoint and covers **every** refresh caller.

**Neither is a live incident today.** The ≈108 s figure is a pathological tail (five
redirect hops at the 8 s per-hop ceiling, doubled by SafeUrlFetcher's 403 honest-UA
retry). This is latent-risk and consistency work — worth doing while the context is
fresh, and worth doing *foundationally* so the same "someone forgot the budget" bug
stops recurring. The registry/strategy/budget spine is the durable core the upcoming
API-based connections will be built on; this hardens it without over-investing in the
scrapers that are on their way out.

## Non-negotiables

- Read `CLAUDE.md` first. `scripts/audit/fix-flow.md` is the runbook.
- Branch `audit-fix/fetch-budget-reentrancy-2026-07-25` off **latest `development`** —
  it must include R8 (`FreshaScraper` is already budget-aware; load-bearing for Unit B's
  tests). `git fetch origin development` first; `development` moves under you.
- Work in a **dedicated worktree** with its own `composer install` and a **copied**
  `.env` — do **not** symlink `vendor` or `.env` (breaks feature tests: the
  `uses(TestCase::class)->in('Feature')` binding resolves to the main checkout's path
  and the app never boots). Base it explicitly: `git worktree add .worktrees/<name>
  origin/development -b audit-fix/fetch-budget-reentrancy-2026-07-25`.
- **Verify every line number below before acting.** Correct at `1a5503e8`;
  `development` moves. Grep the symbol, never trust the citation.
- **Run tests in the FOREGROUND.** Never background a test run and wait for a notice.
- Full suite: `COMPOSER_PROCESS_TIMEOUT=0 composer test` (green was 5496 passed at
  `1a5503e8`). Never run it beside a running implementer subagent — the SQLite test DB
  and config cache are shared.
- **Forbid `git stash` explicitly in every subagent prompt.** A foreign stash entry
  from another branch lives in this repo.
- `vendor/bin/pint --dirty` only (`php artisan pint` does not exist here).
- `vendor/bin/phpstan analyse --memory-limit=1G` — the 128 M default OOMs. It was
  `[OK] No errors` at `1a5503e8`. Your gate is **still zero**, none in a file you touched.
- **No Laravel migration files, no schema change.** Nothing here needs one.
- Pest loads every test file into one PHP process, so a bare `function foo()` in a test
  file is a **global** symbol. Run `git grep -n "^function " -- tests/` before committing.

## Execution policy

Plan **Opus 4.8** · Implement **Sonnet 4.6** · Review a **separate** Sonnet 4.6 ·
final whole-branch review **Opus 4.8**. Keep plan and implement **separate** for both
units. Do **Unit A first, fully reviewed and green, before starting Unit B** — B depends
on A's new method.

**No mid-run sign-off gate.** Josh has pre-approved both of Unit B's design decisions
(placement = broad, terminal state = quiet); the plan phase implements them, it does not
re-open them. Proceed straight through plan → implement → review for both units. The
final report must still state the shipped behaviour changes plainly (they are
user-observable), and the whole-branch review must confirm they landed as decided.

---

## Unit A — re-entrant `FetchBudget`

### The current limitation (verify at `app/Services/Http/FetchBudget.php`)

- `:69-78` — `open(float $seconds, callable $work)` sets `$this->deadlineAt` then clears
  it in a `finally`, unconditionally.
- `:85-88` — `remaining()` returns seconds left, or **`null` when no budget is open**.
- `:91-96` — `exhausted()` returns `true` only once a deadline is open AND has passed;
  `false` when no budget is set at all. **This is load-bearing for Unit B — read it.**
- `:37` — `private ?float $deadlineAt = null;`
- `:44-50` — the docblock spelling out that nesting fails OPEN.
- Bound `scoped` at `AppServiceProvider.php:112`; the queue worker calls
  `forgetScopedInstances()` between jobs, so within one request/job every
  `app(FetchBudget::class)` resolution shares one instance.

### What to build

**Do NOT change `open()`'s semantics.** W1, R8, and every connect controller depend on
"`open()` sets a fresh deadline for its duration and clears it in `finally`". Changing
that contract is a wide, risky blast radius. Instead **add a re-entrant entry point** —
recommended name `ensureOpen()` (finalise the name in the plan):

```php
/**
 * Like open(), but re-entrant: if a budget is ALREADY open on this instance,
 * run $work() inside it untouched (the OUTERMOST deadline governs) rather than
 * starting — and, on return, clearing — a second one. This is what lets a
 * shared chokepoint (e.g. PlatformRefresher) guarantee "bounded" without
 * caring whether an outer caller already opened a budget. When none is open,
 * behaves exactly like open().
 */
public function ensureOpen(float $seconds, callable $work): mixed
{
    if ($this->deadlineAt !== null) {
        return $work();          // inside an open budget — outermost wins, do not touch the deadline
    }

    return $this->open($seconds, $work);
}
```

Preserve `open()`'s generic `@template TReturn` typing on `ensureOpen()` too, so wrapping
a call stays type-transparent (the docblock at `:58-66` explains why a bare `mixed`
erases caller array-shapes and downgrades PHPStan).

### Why this shape, not the alternatives (state your choice in the plan)

- **Not "make `open()` itself re-entrant".** It would silently change behaviour for every
  existing caller — the exact kind of contract change this codebase burned on before.
- **Not "nested open takes the min of the two deadlines".** The `FetchBudget` docblock
  already argues that silently combining two budgets into one number is "one wrong
  number". Outermost-wins is the honest rule for a wall-clock operation deadline.
- A new method is a pure addition: zero risk to the green suite, and it makes the intent
  ("ensure bounded, don't stomp an outer budget") explicit at the call site.

### Tests (add to `tests/Unit/Http/FetchBudgetTest.php`)

1. **No active budget → behaves like `open()`**: `ensureOpen(5, …)` sets a deadline for
   the duration and `remaining()` is `null` again after it returns.
2. **Active budget → runs inside it, deadline untouched**: open an outer `open(2, …)`,
   call `ensureOpen(60, …)` inside it, assert `remaining()` *inside* reflects the
   **outer** ~2 s (not 60), and that after the inner returns the outer deadline is still
   live (not cleared). This is the "fails OPEN is fixed" proof — it must fail against a
   naive implementation that delegates to `open()` unconditionally.
3. **Inner `$seconds` ignored when nested**: a short outer budget still governs even when
   the inner asks for a longer one.
4. **Exception transparency**: `$work` throwing inside the nested branch propagates and
   leaves the outer deadline intact (nothing to clear — the inner set nothing).

Commit: `feat(http): FetchBudget — add re-entrant ensureOpen()`.

---

## Unit B — bound the refresh path (depends on Unit A) — DECIDED

### The verified problem (re-verify at `1a5503e8`)

- `app/Jobs/Platforms/RefreshConnectionJob.php:98` — `handle(PlatformRefresher $refresher)`;
  opens no budget; calls `$refresher->refresh($connection)` at `:116`. Job `$timeout = 120`
  (`:43`), `$tries = 0` bounded by `retryUntil(+2h)` (`:82`), `$maxExceptions = 3` (`:38`).
- `app/Services/Platforms/PlatformRefresher.php:36` — `refresh()` → `$strategy->run($connection)`
  at `:49`. Constructor `:31-34` injects `PlatformRegistry` + `PlatformHealthNotifier`
  only. Catches exactly three `Fetch*Exception` subclasses; **anything else propagates**.
- `app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php:29` → `$this->fetch->fetch()`.
- `app/Services/Platforms/Strategies/Fetch/FreshaFetch.php:50,:55` — `fetchEmployeeServices()`
  (budget-aware post-R8; returns `null` on exhaustion) and/or `fetchLocation()`
  (budget-aware via `SafeUrlFetcher`; **throws `SafeUrlException` on exhaustion**). With
  no budget open, `remaining()` is `null` → every leg runs at its flat ceiling.

Both triggers reach `handle()`: the hourly cron (`RefreshIntegrationConnectionsCommand`)
and the manual "refresh" button (`RefreshController` dispatches the job).

### Decision 1 (MADE) — placement: broad, at `PlatformRefresher::refresh()`

Wrap the body of `PlatformRefresher::refresh()` in `ensureOpen(refresh_budget, …)`. This
is the chokepoint **every** refresh flows through (grep `->refresh(` — ~11 callers):
the cron job, the manual button, seven synchronous `ShopController` calls
(`:329,:478,:535,:680,:805,:832,:877`), the observer (`:53,:407,:449`), and
`ShopBrandConnectJob:197`. One edit bounds all of them, and Unit A's `ensureOpen()`
makes it **safe** where a caller already holds a budget (outermost wins — a refresh
fired inside a connect budget simply shares the connect's tighter deadline). Inject
`FetchBudget` into `PlatformRefresher`'s constructor.

The synchronous `ShopController` callers are fast platforms already bounded by the
request timeout, so the 90 s ceiling is neutral-to-beneficial there. Applying it to them
is the intended consistency win of the broad placement.

### Decision 2 (MADE) — terminal state: a budget-exhaustion is a QUIET non-event

When a refresh overruns the budget, `SafeUrlFetcher` throws `SafeUrlException` (budget
exhausted). Today that propagates uncaught to `RefreshConnectionJob::failed()` →
`last_refresh_status = 'error'` + `consecutive_failures++` + Nightwatch report + a user
notification. **That must become quiet:** our own deadline is a transient miss, not a
vendor outage — it must preserve the last-known payload, must NOT page Nightwatch, must
NOT trip the circuit breaker, and must NOT email the user. This matches `FreshaFetch`'s
own philosophy ("a transient scrape failure never wipes a selection").

**Trap A — `recordFailure(status: 'unavailable')` does NOT satisfy "quiet".** Read
`PlatformRefresher::recordFailure()` (`:88-109`): it increments `consecutive_failures`
**and** calls `$this->healthNotifier->connectionRefreshFailing()` for **every** status,
not only `'error'`. So routing budget-exhaustion through the existing `'unavailable'`
path would still arm the breaker and can still email the user — exactly the two things
this decision rules out. You must add a **dedicated quiet handler**, e.g.
`recordBudgetExhausted(IntegrationConnection $connection)`, that:
- sets an observable `last_refresh_status = 'unavailable'` + a `last_refresh_error` like
  `'refresh_budget_exhausted'` (for debugging / the backlog command), **via
  `updateQuietly()`** so the observer's edge-cache purge doesn't fire on a non-change;
- **does NOT** increment `consecutive_failures`, **does NOT** call the health notifier,
  **does NOT** `report()` to Nightwatch;
- leaves the row due for the next refresh cycle (do not stamp `last_refreshed_at`);
- emits one `Log::warning`/`info` breadcrumb for observability.
The `CheckPlatformRefreshBacklogCommand` remains the safety net for a connection that
perpetually exhausts its budget — it will show up as chronically stale. (Confirm the
exact status/marker in the plan; the constraint is the side-effect list above, not the
literal string.)

**Trap B — check `exhausted()` while the budget is still OPEN.** The discriminator
between "our own deadline" and "a genuine fetch failure" is `FetchBudget::exhausted()`
(true only when a deadline is open and has passed). Once `ensureOpen()`/`open()` returns,
the `finally` clears the deadline and `exhausted()` reads `false`. So the
`catch (SafeUrlException)` MUST live **inside** the `ensureOpen()` closure, alongside the
existing `Fetch*Exception` catches — not outside it. A genuine `SafeUrlException`
(budget **not** exhausted — a real SSRF block or connection failure) must be **rethrown
unchanged**, so real failures keep today's loud `'error'` terminal state. Only our own
deadline is quieted.

Sketch (finalise in the plan; verify the `SafeUrlException` FQN):

```php
public function refresh(IntegrationConnection $connection): IntegrationConnection
{
    $seconds = (float) config('partna.http_fetch.refresh_budget_seconds', 90);

    return $this->budget->ensureOpen($seconds, function () use ($connection) {
        $descriptor = $this->registry->get($connection->platform);
        $strategy = $descriptor?->refreshStrategy();

        if ($strategy === null || ! $strategy->isRefreshable()) {
            return $this->recordFailure($connection, 'unsupported_platform', 'error');
        }

        try {
            return $strategy->run($connection);
        } catch (FetchNotModifiedException $e) {
            return $this->recordNotModified($connection);
        } catch (FetchShapeException $e) {
            report($e);
            return $this->recordFailure($connection, $e->getMessage(), 'error');
        } catch (FetchUnavailableException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'unavailable');
        } catch (SafeUrlException $e) {
            if ($this->budget->exhausted()) {
                return $this->recordBudgetExhausted($connection);   // our deadline → quiet
            }
            throw $e;                                               // real failure → unchanged 'error'
        }
    });
}
```

### The value, and a guard for it

Add a new key in the `http_fetch` block of `config/partna.php` (mirror `:1307` —
`'connect_budget_seconds' => (int) env('PARTNA_CONNECT_BUDGET_SECONDS', 20)`), e.g.
`'refresh_budget_seconds' => (int) env('PARTNA_REFRESH_BUDGET_SECONDS', 90)`. A config
key is warranted (a real operational tunable, parallel to the connect budget).

**Invariant:** `refresh_budget_seconds` must stay meaningfully **below**
`RefreshConnectionJob::$timeout` (120 s), with headroom for the non-fetch work a refresh
still does after the fetch closes — the `Cache::lock($key,10)->block(5,…)` acquisition
(`ScheduledRefresh.php:47`), the projector `sync()` upserts, the model write + observer
purge, the health notifier. ~90 s leaves ~30 s headroom; recommend that, justify your
number. If the budget ever meets or exceeds the job timeout the SIGKILL wins and the
budget is moot. Add a **lockstep guard test** (in the spirit of
`StrandedPendingWindowLockstepTest`) that fails if
`refresh_budget_seconds >= RefreshConnectionJob::$timeout`, so a future edit to either
value can't silently invert the invariant. Make it fail with a clear message.

### The mechanism you must prove with a test

The fix rests on the budget opened in `PlatformRefresher` being the **same scoped
instance** the scraper reads via `remaining()`. R8 relied on this for the controller →
scraper hop; you rely on it for the refresher → scraper hop (and, in a job, across the
job's container scope). It *should* hold (`scoped` binding, one scope per request/job,
autowired `app(FreshaScraper::class)` inside `FreshaFetch`) — **prove it**: open a small
budget through the real refresh path and assert a budget-aware leg is actually clamped,
failing against pre-fix code. Do not assert the mechanism only in the `FetchBudget` unit
test.

### Tests (follow existing conventions — read them first)

`FreshaRefreshTest`, `FreshaEmployeeMenuObservabilityTest`, `ConnectFetchBudgetTest`,
`tests/Unit/Http/FetchBudgetTest.php`, `tests/Unit/Http/SafeUrlFetcherBudgetTest.php`
(the `open(...)`-around-the-call-with-`Http::fake`+`usleep` pattern to copy). At minimum:

1. **A budget is open during a refresh** — drive the refresh path for a budget-aware
   platform and assert a fetch leg sees a clamped `remaining()`/timeout. Must fail
   against pre-fix code (no budget → `null` → flat ceiling).
2. **Budget exhaustion is quiet** — open a tiny budget around a refresh that overruns it;
   assert `last_refresh_status` is the quiet marker, the **payload is preserved**,
   `consecutive_failures` is **NOT** incremented, the health notifier is **NOT** called,
   and **nothing is reported** to Nightwatch. This pins Trap A.
3. **A genuine fetch failure still errors** — a `SafeUrlException` with the budget **not**
   exhausted (e.g. a real connection failure well inside the budget) still reaches the
   loud `'error'` terminal state and increments `consecutive_failures`. This pins Trap B
   (that we didn't over-quiet real outages).
4. **A fast refresh is unaffected** — a healthy platform finishing well inside the budget
   behaves byte-identically.
5. **A synchronous `ShopController` refresh** still behaves correctly under the new
   `ensureOpen()` on the fast path (broad placement touches it).
6. **The invariant guard** (above).

Run these targeted files in the foreground. `tests/Feature/Platforms/DarkMergeProofTest.php`
is the merge-safety guarantee for the whole connect programme and must stay green.

Commit: `fix(platforms): bound every refresh caller with a re-entrant FetchBudget`.

---

## Explicitly OUT of scope

- **Changing `open()`'s existing semantics** — Unit A adds a method, it does not alter
  `open()`.
- **`FreshaScraper` internals / R8's GraphQL clamp** — already correct; do not touch.
- **Centralising the *connect*-side budget opens** — the connect controllers and
  `ConnectFetchJob` already open budgets and work; re-entrancy makes a future
  consolidation *possible*, but it is a separate unit and not in this one.
- **The unbudgeted-DNS overshoot** noted in `FetchBudget`'s docblock (`:52-56`) — a
  resolver-level concern, out of scope.
- **Any new config key beyond `refresh_budget_seconds`, any route/contract change, any
  migration.**

## Completion

1. `COMPOSER_PROCESS_TIMEOUT=0 composer test` — green (5496 passed at `1a5503e8`, plus
   your new tests).
2. `vendor/bin/pint --dirty`; `vendor/bin/phpstan analyse --memory-limit=1G` — clean.
3. Independent whole-branch review on **Opus 4.8**, diff handed over as a file. It must
   check the seam between the two units — that `ensureOpen()` genuinely no-ops when
   nested and that Unit B relies on that no-op — and confirm both traps are handled: the
   quiet handler has none of `recordFailure`'s side effects, and `exhausted()` is checked
   inside the open budget while a genuine `SafeUrlException` still errors.
4. **`DarkMergeProofTest` must still hold.**
5. Report: the shipped behaviour changes stated plainly (every refresh caller is now
   budget-bounded; a budget-exhausted refresh is a quiet `unavailable` non-event that
   preserves the payload and does not notify/report/trip the breaker; a genuine failure
   is unchanged); worst-case wall clock before/after; units done/deferred; test status;
   branch name. **Do not merge or push without Josh's say-so.** Delete the superseded
   `2026-07-25-refresh-fetch-budget-PROMPT.md` as part of the change.

## Reference

- The R8 unit that recorded the escalation: commit `d800b8f8` and the surrounding
  R1–R8 commits (`56d85e23`..`1a5503e8`).
- The superseded standalone refresh prompt: `2026-07-25-refresh-fetch-budget-PROMPT.md`
  (delete on ship).
- Design: `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md`.
- Budget precedent (W1): `App\Services\Http\FetchBudget`, `App\Services\Http\SafeUrlFetcher`,
  `FreshaController::saveSelection()`'s `budget->open(...)`.
- Runbook: `scripts/audit/fix-flow.md`.
