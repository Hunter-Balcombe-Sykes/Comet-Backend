# Platform Refresh Foundation (Plan 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** ✅ Approved by Josh 2026-07-02 — ready to execute (not yet started).
**Adversarial review (2026-07-02, post-approval):** verified against source + vendor code; 3 defects found and folded in: (1) `GoogleBusinessDetailsTest` called the removed `--throttle-ms` option → Task 6 Step 5; (2) RateLimited releases count as `$tries` attempts → job now uses `$tries = 0` + `retryUntil()` + `$maxExceptions = 3` (Task 4); (3) Horizon only runs supervisors listed per-environment → Task 8 Step 2 wires `platform_refresh` into `production`/`development`/`local` explicitly. `uniqueFor` raised 900 → 7200 to match the retry horizon.

**Goal:** Replace the serial, 300/run daily refresh cron (audit finding **#SCALE-1**) with a fan-out dispatcher that queues one rate-limited job per *due* connection, plus a backlog alarm — so refresh capacity scales with the fleet instead of a fixed daily cap.

**Architecture:** A cheap scheduled **dispatcher** (`integrations:refresh`) selects connections due per a per-platform TTL and dispatches one **`RefreshConnectionJob`** each onto a dedicated `platform_refresh` Horizon queue. Each job wraps the existing `PlatformRefresher` (unchanged fetch + failure bookkeeping) and is throttled per-provider by a cache-backed `RateLimiter` (Redis in prod → shared across all workers). A second scheduled command reports a Nightwatch alert when too many connections fall overdue. "Due-ness" is computed from the **existing** `last_refreshed_at` column — **no database migration**.

**Tech Stack:** PHP 8.2, Laravel 12, Horizon (Redis queues), Pest 4 (SQLite in-memory), existing `PlatformRegistry` / `PlatformDescriptor` / `PlatformRefresher` spine.

**Source:** Strategy doc `docs/superpowers/plans/2026-07-01-platform-refresh-scaling-strategy.md` (§8 = agreed structure). This plan is the **Foundation (Plan 1) / spine-only** unit. Decisions locked with Josh 2026-07-02: **migration-free** scheduling state; **spine-only** scope (conditional-requests ETag/304 = first follow-on, NOT in this plan). Manual per-card refresh (`RefreshController`) stays **synchronous** in Plan 1 (async-ifying it changes the frontend response contract → JOB-1 follow-on).

## Global Constraints

- **NO Laravel migration files** — a composer guard rejects them. This plan intentionally adds **no schema change** at all (migration-free by design). Do not add columns.
- **Tests run on SQLite in-memory; prod is Postgres.** All queries in this plan must be DB-agnostic — no `power()`, no `interval` literals, no `now()` SQL function. Compute time cutoffs in PHP and bind them as parameters. (`ORDER BY … NULLS FIRST` is intentionally avoided; see Task 5.)
- **Every `ShouldQueue` job must define `$tries`, `$backoff` (or `backoff()`), and `$timeout`** — enforced by `tests/Feature/Queue/JobHygienePolicyTest.php`.
- **Do NOT modify `.env`** — add new keys to `.env.example` and read via `env()` with a safe default inside `config/partna.php` only.
- **Business logic in services/jobs, not commands.** Commands orchestrate; the job wraps `PlatformRefresher`.
- **Run `php artisan pint` on changed files before each commit**, but keep commits surgical (per repo convention, don't let Pint churn unrelated lines).
- **Commenting bar:** comment the non-obvious WHY (per CLAUDE.md), not the what.
- Config is the single source of the default TTL / rate limits / thresholds — a per-platform TTL override lives on the descriptor and wins when set.

---

## File Structure

**New files:**
- `app/Jobs/Platforms/RefreshConnectionJob.php` — one connection = one queued refresh; wraps `PlatformRefresher`.
- `app/Console/Commands/CheckPlatformRefreshBacklogCommand.php` — staleness/backlog alarm.
- `app/Exceptions/Platforms/PlatformRefreshBacklogException.php` — reported to Nightwatch when backlog over threshold.
- `tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php`
- `tests/Feature/Platforms/DueForRefreshScopeTest.php`
- `tests/Unit/Jobs/RefreshConnectionJobTest.php`
- `tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php`

**Modified files:**
- `app/Services/Platforms/Registry/PlatformDescriptor.php` — add `refreshEvery()` + `refreshInterval()`.
- `app/Models/Core/Site/IntegrationConnection.php` — add `scopeDueForRefresh()`.
- `config/partna.php` — add `platform_refresh` queue lane + a `refresh` config block.
- `app/Providers/PlatformRegistryServiceProvider.php` — register the `platform-refresh` RateLimiter.
- `app/Console/Commands/RefreshIntegrationConnectionsCommand.php` — **rewrite** serial worker → dispatcher.
- `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php` — **rewrite** to dispatch assertions.
- `config/horizon.php` — add `supervisor-platform-refresh`.
- `routes/console.php` — dispatcher cadence → hourly; schedule the backlog command.
- `.env.example` — document the new env keys.

---

## Task 1: Per-platform refresh TTL on the descriptor

**Files:**
- Modify: `app/Services/Platforms/Registry/PlatformDescriptor.php`
- Test: `tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php`

**Interfaces:**
- Produces: `PlatformDescriptor::refreshEvery(int $seconds): self` (fluent) and `PlatformDescriptor::refreshInterval(): ?int` (null = "use the config default"). Consumed by Tasks 5 & 6.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php

use App\Services\Platforms\Registry\PlatformDescriptor;

it('returns null refreshInterval when none is set', function () {
    expect(PlatformDescriptor::make('x')->label('X')->refreshInterval())->toBeNull();
});

it('stores a per-platform refresh interval via refreshEvery', function () {
    $d = PlatformDescriptor::make('x')->label('X')->refreshEvery(3600);
    expect($d->refreshInterval())->toBe(3600);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php`
Expected: FAIL — `Method App\Services\Platforms\Registry\PlatformDescriptor::refreshEvery does not exist`.

- [ ] **Step 3: Implement the descriptor methods**

In `app/Services/Platforms/Registry/PlatformDescriptor.php`, add the property near the other private state (after `private bool $refreshable = false;`):

```php
    /** Per-platform refresh cadence in seconds; null = fall back to config('partna.refresh.default_ttl_seconds'). */
    private ?int $refreshInterval = null;
```

Add the fluent setter + getter next to `refreshable()` / `isRefreshable()`:

```php
    /** Override how often this platform is re-fetched (seconds). Null (default) uses the global config TTL. */
    public function refreshEvery(int $seconds): self
    {
        $this->refreshInterval = $seconds;

        return $this;
    }

    public function refreshInterval(): ?int
    {
        return $this->refreshInterval;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/Registry/PlatformDescriptor.php tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php
git add app/Services/Platforms/Registry/PlatformDescriptor.php tests/Unit/Platforms/PlatformDescriptorRefreshIntervalTest.php
git commit -m "feat(refresh): per-platform refresh TTL on PlatformDescriptor (SCALE-1)"
```

---

## Task 2: `dueForRefresh` query scope

**Files:**
- Modify: `app/Models/Core/Site/IntegrationConnection.php`
- Test: `tests/Feature/Platforms/DueForRefreshScopeTest.php`

**Interfaces:**
- Produces: `IntegrationConnection::scopeDueForRefresh($query, \DateTimeInterface $cutoff, int $maxFailures)` — active, non-soft-deleted rows whose `last_refreshed_at` is null or older than `$cutoff`, excluding rows at/above the failure cap. DB-agnostic (bound datetime param). Consumed by Tasks 6 & 7.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/DueForRefreshScopeTest.php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function scopeUser(): User
{
    return User::create([
        'handle' => 'scope', 'handle_lc' => 'scope', 'display_name' => 'Scope',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'scope@example.com',
    ]);
}

function ytConn(User $user, array $attrs): IntegrationConnection
{
    return IntegrationConnection::create(array_merge([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'c'],
    ], $attrs));
}

it('includes stale, never-refreshed, and excludes fresh / capped / inactive rows', function () {
    $user = scopeUser();
    $cutoff = now()->subDay();

    $stale = ytConn($user, ['last_refreshed_at' => now()->subWeek()]);
    $never = ytConn($user, ['last_refreshed_at' => null, 'resource_id' => 'youtube2']);
    $fresh = ytConn($user, ['last_refreshed_at' => now()->subHour(), 'resource_id' => 'youtube3']);
    $capped = ytConn($user, ['last_refreshed_at' => now()->subWeek(), 'consecutive_failures' => 10, 'resource_id' => 'youtube4']);
    $inactive = ytConn($user, ['last_refreshed_at' => now()->subWeek(), 'is_active' => false, 'resource_id' => 'youtube5']);

    $due = IntegrationConnection::query()->dueForRefresh($cutoff, 10)->pluck('id');

    expect($due)->toContain($stale->id)
        ->toContain($never->id)
        ->not->toContain($fresh->id)
        ->not->toContain($capped->id)
        ->not->toContain($inactive->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Platforms/DueForRefreshScopeTest.php`
Expected: FAIL — `Call to undefined method …::dueForRefresh()`.

- [ ] **Step 3: Implement the scope**

In `app/Models/Core/Site/IntegrationConnection.php`, add after `scopeActive()`:

```php
    /**
     * Connections DUE for a refresh: active, refreshed longer ago than $cutoff (or
     * never), and below the consecutive-failure circuit breaker. $cutoff is computed
     * in PHP (per-platform TTL) and bound as a param so the query is identical on
     * Postgres and the SQLite test DB. Soft-deleted rows are already excluded by the
     * model's SoftDeletes global scope.
     */
    public function scopeDueForRefresh($query, \DateTimeInterface $cutoff, int $maxFailures)
    {
        return $query->active()
            ->where('consecutive_failures', '<', $maxFailures)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_refreshed_at')
                    ->orWhere('last_refreshed_at', '<', $cutoff);
            });
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/DueForRefreshScopeTest.php`
Expected: PASS (1 passed).

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Models/Core/Site/IntegrationConnection.php tests/Feature/Platforms/DueForRefreshScopeTest.php
git add app/Models/Core/Site/IntegrationConnection.php tests/Feature/Platforms/DueForRefreshScopeTest.php
git commit -m "feat(refresh): dueForRefresh scope (TTL + failure circuit breaker, SCALE-1)"
```

---

## Task 3: Config — queue lane + refresh settings

**Files:**
- Modify: `config/partna.php`
- Modify: `.env.example`
- Test: (folded into later tasks — config values are asserted indirectly by Tasks 4–7)

**Interfaces:**
- Produces: `config('partna.queues.platform_refresh')`, `config('partna.refresh.default_ttl_seconds')`, `config('partna.refresh.max_consecutive_failures')`, `config('partna.refresh.rate_limits.default')` (+ optional per-platform), `config('partna.refresh.backlog.grace_multiplier')`, `config('partna.refresh.backlog.alert_threshold')`. Consumed by Tasks 4–7.

- [ ] **Step 1: Add the queue lane**

In `config/partna.php`, inside the `'queues' => [ … ]` array, after the `'scraping' => …` line:

```php
        // Platform refresh fan-out (RefreshConnectionJob, dispatched by integrations:refresh).
        'platform_refresh' => env('PARTNA_QUEUE_PLATFORM_REFRESH', 'platform_refresh'),
```

- [ ] **Step 2: Add the `refresh` config block**

In `config/partna.php`, add a new top-level block adjacent to `'queues'`:

```php
    // Platform connection refresh (SCALE-1). The dispatcher (integrations:refresh)
    // selects connections due per default_ttl_seconds (or the descriptor override)
    // and fans out RefreshConnectionJob; per-provider rate_limits cap outbound
    // pressure; the backlog command alarms when too many fall overdue.
    'refresh' => [
        // Default re-fetch cadence when a platform declares no descriptor override.
        // 86400 preserves the previous daily cadence.
        'default_ttl_seconds' => (int) env('PARTNA_REFRESH_DEFAULT_TTL', 86400),

        // Circuit breaker: skip connections at/above this many consecutive failures
        // (a dead account stops consuming refresh capacity). Reset to 0 on any
        // successful refresh by ScheduledRefresh::run().
        'max_consecutive_failures' => (int) env('PARTNA_REFRESH_MAX_FAILURES', 10),

        // Per-provider outbound rate limit (requests/minute) for the refresh queue,
        // keyed by platform key; falls back to 'default'. Enforced by the
        // 'platform-refresh' RateLimiter (cache-backed → Redis in prod → shared
        // across ALL workers, so the cap is global, not per-process).
        'rate_limits' => [
            'default' => (int) env('PARTNA_REFRESH_RATE_DEFAULT', 60),
            // e.g. 'google-business' => 30,
        ],

        // Staleness alarm thresholds (integrations:refresh-backlog).
        'backlog' => [
            // Overdue = not refreshed within (ttl × grace_multiplier).
            'grace_multiplier' => (float) env('PARTNA_REFRESH_BACKLOG_GRACE', 2),
            // Report to Nightwatch when the overdue count exceeds this.
            'alert_threshold' => (int) env('PARTNA_REFRESH_BACKLOG_THRESHOLD', 500),
        ],
    ],
```

- [ ] **Step 3: Document the env keys**

In `.env.example`, add (near other `PARTNA_QUEUE_*` keys if present, else at the end):

```dotenv
PARTNA_QUEUE_PLATFORM_REFRESH=platform_refresh
PARTNA_REFRESH_DEFAULT_TTL=86400
PARTNA_REFRESH_MAX_FAILURES=10
PARTNA_REFRESH_RATE_DEFAULT=60
PARTNA_REFRESH_BACKLOG_GRACE=2
PARTNA_REFRESH_BACKLOG_THRESHOLD=500
```

- [ ] **Step 4: Verify config loads**

Run: `php artisan config:clear && php artisan tinker --execute="echo config('partna.queues.platform_refresh'), PHP_EOL, config('partna.refresh.default_ttl_seconds');"`
Expected: prints `platform_refresh` then `86400`.

- [ ] **Step 5: Commit**

```bash
php artisan pint config/partna.php
git add config/partna.php .env.example
git commit -m "feat(refresh): platform_refresh queue lane + refresh config block (SCALE-1)"
```

---

## Task 4: `RefreshConnectionJob` (per-connection refresh)

**Files:**
- Create: `app/Jobs/Platforms/RefreshConnectionJob.php`
- Test: `tests/Unit/Jobs/RefreshConnectionJobTest.php`

**Interfaces:**
- Consumes: `PlatformRefresher::refresh(IntegrationConnection): IntegrationConnection` (existing); `config('partna.queues.platform_refresh')` (Task 3).
- Produces: `new RefreshConnectionJob(string $connectionId, string $platform)` with public `$connectionId`, `$platform`; `handle(PlatformRefresher)`; `uniqueId(): string`; `middleware(): array`. `$platform` is read by the rate limiter in Task 5. Dispatched by Task 6.

**Note (SEC-1 test gotcha):** creating a `youtube` connection resolves `PlatformRegistry` in the model's `saving` guard, eagerly wiring scrapers. Any `YoutubeScraper` mock MUST be bound **before** `IntegrationConnection::create(...)`, or the real scraper is captured. (See `reference_integrationconnection_guard_test_timing`.)

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Jobs/RefreshConnectionJobTest.php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function jobUser(): User
{
    return User::create([
        'handle' => 'job', 'handle_lc' => 'job', 'display_name' => 'Job',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'job@example.com',
    ]);
}

it('defines the required queue-hygiene properties', function () {
    $job = new RefreshConnectionJob('id', 'youtube');
    // $tries = 0 (unlimited) is deliberate: RateLimited releases count as attempts,
    // so a finite $tries would fail queued jobs during a burst before they ever ran.
    // Bounded instead by retryUntil() (wall clock) + $maxExceptions (real errors).
    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300])
        ->and($job->timeout)->toBe(120)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('uses the connection id as its uniqueId', function () {
    expect((new RefreshConnectionJob('abc', 'youtube'))->uniqueId())->toBe('abc');
});

it('refreshes a stale YouTube connection through PlatformRefresher', function () {
    $user = jobUser();

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v9', 'name' => 'New Video', 'description' => 'nd', 'link' => 'nl', 'thumbnail' => 'nt'],
        ]);
    });

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan', 'name' => 'Old Video', 'highlights' => [['videoId' => 'h1']]],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->payload['latest']['videoId'])->toBe('v9')
        ->and($conn->last_refresh_status)->toBe('ok')
        ->and($conn->payload['highlights'])->toHaveCount(1); // curated picks preserved
});

it('records unavailable + increments failures when the scraper returns nothing', function () {
    $user = jobUser();
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan'], 'last_refreshed_at' => now()->subWeek(),
    ]);

    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->last_refresh_status)->toBe('unavailable')
        ->and($conn->consecutive_failures)->toBe(1);
});

it('no-ops when the connection is missing or inactive', function () {
    $user = jobUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan'], 'last_refreshed_at' => now()->subWeek(), 'is_active' => false,
    ]);

    // No scraper mock: if handle() tried to refresh an inactive row it would hit the real scraper.
    (new RefreshConnectionJob($conn->id, 'youtube'))->handle(app(PlatformRefresher::class));
    (new RefreshConnectionJob('does-not-exist', 'youtube'))->handle(app(PlatformRefresher::class));

    $conn->refresh();
    expect($conn->last_refresh_status)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Jobs/RefreshConnectionJobTest.php`
Expected: FAIL — `Class "App\Jobs\Platforms\RefreshConnectionJob" not found`.

- [ ] **Step 3: Implement the job**

```php
<?php
// app/Jobs/Platforms/RefreshConnectionJob.php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;

// One connection = one refresh. The dispatcher (integrations:refresh) fans these
// out onto the platform_refresh queue; manual/webhook triggers can reuse the same
// job later (the "any trigger → one job" spine). Wraps PlatformRefresher, which
// owns the fetch + failure bookkeeping. Per-provider outbound pressure is capped by
// the 'platform-refresh' RateLimiter — NOT by worker count — so the supervisor can
// run many processes safely. ShouldBeUnique dedups a cron re-run / manual refresh
// colliding with an in-flight or retrying job.
class RefreshConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Unlimited attempts, bounded by retryUntil() below. Deliberate: the RateLimited
     * middleware RELEASES a job when the provider is over-limit, and every release
     * counts as an attempt — a finite $tries would mass-fail queued jobs during a
     * cold-start burst before they ever executed. Real errors are capped separately
     * by $maxExceptions, so a genuinely broken fetch still fails fast.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** Backoff (seconds) between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    /**
     * Dedup window — matches the retryUntil() horizon so the hourly dispatcher can't
     * enqueue a duplicate while the original is still alive in rate-limit purgatory.
     */
    public int $uniqueFor = 7200;

    public function __construct(
        public string $connectionId,
        public string $platform,
    ) {
        $this->onQueue(config('partna.queues.platform_refresh'));
    }

    /**
     * Wall-clock retry deadline. If a job can't get through its provider's rate limit
     * within 2h, let it lapse — the connection is still due, so the next dispatcher
     * run simply re-creates it. Freshness converges; nothing is lost.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function uniqueId(): string
    {
        return $this->connectionId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-refresh')];
    }

    public function handle(PlatformRefresher $refresher): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);

        // Deleted or deactivated between dispatch and execution — nothing to do.
        if ($connection === null || ! $connection->is_active) {
            return;
        }

        $refresher->refresh($connection);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Jobs/RefreshConnectionJobTest.php`
Expected: PASS (5 passed).

- [ ] **Step 5: Verify job hygiene gate still green**

Run: `php artisan test tests/Feature/Queue/JobHygienePolicyTest.php`
Expected: PASS (the new job defines `$tries`, `$backoff`, `$timeout`).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Jobs/Platforms/RefreshConnectionJob.php tests/Unit/Jobs/RefreshConnectionJobTest.php
git add app/Jobs/Platforms/RefreshConnectionJob.php tests/Unit/Jobs/RefreshConnectionJobTest.php
git commit -m "feat(refresh): RefreshConnectionJob wrapping PlatformRefresher (SCALE-1)"
```

---

## Task 5: Per-provider rate limiter registration

**Files:**
- Modify: `app/Providers/PlatformRegistryServiceProvider.php`
- Test: append to `tests/Unit/Jobs/RefreshConnectionJobTest.php`

**Interfaces:**
- Consumes: `config('partna.refresh.rate_limits.*')` (Task 3); `RefreshConnectionJob::$platform` (Task 4).
- Produces: a named RateLimiter `'platform-refresh'` keyed `platform-refresh:{platform}` at `config('partna.refresh.rate_limits.{platform}', default)` per minute. Applied by `RefreshConnectionJob::middleware()`.

- [ ] **Step 1: Write the failing tests** (append to the Task 4 test file)

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;

it('applies the platform-refresh RateLimited middleware', function () {
    $mw = (new RefreshConnectionJob('id', 'youtube'))->middleware();
    expect($mw)->toHaveCount(1)->and($mw[0])->toBeInstanceOf(RateLimited::class);
});

it('registers a per-provider platform-refresh limiter keyed by platform', function () {
    $callback = RateLimiter::limiter('platform-refresh');
    expect($callback)->not->toBeNull();

    $limit = $callback(new RefreshConnectionJob('id', 'youtube'));
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->key)->toBe('platform-refresh:youtube')
        ->and($limit->maxAttempts)->toBe(60); // config default
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Jobs/RefreshConnectionJobTest.php`
Expected: the middleware test PASSES (already implemented in Task 4); the limiter test FAILS — `RateLimiter::limiter('platform-refresh')` returns null.

- [ ] **Step 3: Register the limiter**

In `app/Providers/PlatformRegistryServiceProvider.php`, add these imports at the top:

```php
use App\Jobs\Platforms\RefreshConnectionJob;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
```

In `boot()` (if the provider has only `register()`, add a `public function boot(): void`), add:

```php
        // Per-provider outbound rate limit for the refresh queue. Cache-backed →
        // Redis in prod → shared across ALL workers, so the cap is global, not
        // per-process. Keyed by platform so one provider can't starve the others.
        RateLimiter::for('platform-refresh', function (RefreshConnectionJob $job) {
            $perMinute = (int) config(
                "partna.refresh.rate_limits.{$job->platform}",
                config('partna.refresh.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('platform-refresh:'.$job->platform);
        });
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Jobs/RefreshConnectionJobTest.php`
Expected: PASS (7 passed).

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Providers/PlatformRegistryServiceProvider.php tests/Unit/Jobs/RefreshConnectionJobTest.php
git add app/Providers/PlatformRegistryServiceProvider.php tests/Unit/Jobs/RefreshConnectionJobTest.php
git commit -m "feat(refresh): per-provider platform-refresh RateLimiter (SCALE-1, SCALE-3 groundwork)"
```

---

## Task 6: Rewrite the cron into a dispatcher

**Files:**
- Modify: `app/Console/Commands/RefreshIntegrationConnectionsCommand.php` (full rewrite)
- Modify: `tests/Feature/Platforms/GoogleBusinessDetailsTest.php:196,223,243` (drop the removed `--throttle-ms` option)
- Test: `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php` (full rewrite)

**Interfaces:**
- Consumes: `PlatformRegistry::refreshable()` (map `platform => descriptor`); `PlatformDescriptor::refreshInterval()` (Task 1); `IntegrationConnection::dueForRefresh()` (Task 2); `RefreshConnectionJob` (Task 4); `config('partna.refresh.*')` (Task 3).
- Produces: `integrations:refresh` dispatches (does not execute) one `RefreshConnectionJob($id, $platform)` per due connection.

**Why the ordering is dropped:** the old command used `ORDER BY last_refreshed_at ASC NULLS FIRST` because a 300-cap made *which* rows got picked matter. The dispatcher fans out **all** due rows, so dispatch order is irrelevant — execution pacing is the RateLimiter's job — and we use memory-safe `lazyById()` (which orders by id) instead. Dropping the Postgres-only `NULLS FIRST` also keeps the query DB-agnostic.

- [ ] **Step 1: Rewrite the test file (failing)**

Replace the entire contents of `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php` with:

```php
<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    Queue::fake();
});

function dispatchUser(): User
{
    return User::create([
        'handle' => 'cron', 'handle_lc' => 'cron', 'display_name' => 'Cron',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cron@example.com',
    ]);
}

function conn(User $user, string $platform, array $attrs): IntegrationConnection
{
    return IntegrationConnection::create(array_merge([
        'user_id' => $user->id, 'platform' => $platform, 'resource_id' => $platform,
        'payload' => ['handle' => 'c'],
    ], $attrs));
}

it('dispatches a job for a stale refreshable connection', function () {
    $user = dispatchUser();
    $c = conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class,
        fn ($j) => $j->connectionId === $c->id && $j->platform === 'youtube');
});

it('dispatches a never-refreshed connection', function () {
    $user = dispatchUser();
    $c = conn($user, 'youtube', ['last_refreshed_at' => null]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertPushed(RefreshConnectionJob::class, fn ($j) => $j->connectionId === $c->id);
});

it('does not dispatch a fresh connection (within TTL)', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subHour()]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch non-refreshable platforms (instagram)', function () {
    $user = dispatchUser();
    conn($user, 'instagram', ['last_refreshed_at' => now()->subYear(), 'payload' => ['username' => 'ig']]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch a connection at the failure cap', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek(), 'consecutive_failures' => 10]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('does not dispatch inactive connections', function () {
    $user = dispatchUser();
    conn($user, 'youtube', ['last_refreshed_at' => now()->subWeek(), 'is_active' => false]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    Queue::assertNotPushed(RefreshConnectionJob::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php`
Expected: FAIL — the old command executes synchronously and doesn't push `RefreshConnectionJob`, so `assertPushed` fails (and `--throttle-ms` is gone).

- [ ] **Step 3: Rewrite the command**

Replace the entire contents of `app/Console/Commands/RefreshIntegrationConnectionsCommand.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

// Dispatcher (not a worker): selects connections DUE for refresh per the platform's
// TTL and fans out one RefreshConnectionJob each onto the platform_refresh queue.
// Replaces the old serial foreach + 300/run cap (SCALE-1). Cheap and frequent — the
// heavy fetching happens on the queue, paced per-provider by the RateLimiter. Due-ness
// is per-connection (last_refreshed_at + per-provider TTL), so capacity scales with the
// fleet instead of a fixed daily cap.
class RefreshIntegrationConnectionsCommand extends Command
{
    protected $signature = 'integrations:refresh';

    protected $description = 'Dispatch a refresh job for every platform connection due per its TTL.';

    public function handle(PlatformRegistry $registry): int
    {
        $defaultTtl = (int) config('partna.refresh.default_ttl_seconds');
        $maxFailures = (int) config('partna.refresh.max_consecutive_failures');
        $dispatched = 0;

        foreach ($registry->refreshable() as $platform => $descriptor) {
            $ttl = $descriptor->refreshInterval() ?? $defaultTtl;
            $cutoff = now()->subSeconds($ttl);

            IntegrationConnection::query()
                ->where('platform', $platform)
                ->dueForRefresh($cutoff, $maxFailures)
                ->lazyById()
                ->each(function (IntegrationConnection $connection) use (&$dispatched) {
                    RefreshConnectionJob::dispatch($connection->id, $connection->platform);
                    $dispatched++;
                });
        }

        $this->info("Platform refresh: dispatched {$dispatched} due connection job(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php`
Expected: PASS (6 passed).

- [ ] **Step 5: Fix the other caller of the old signature**

`tests/Feature/Platforms/GoogleBusinessDetailsTest.php` calls the command with the now-removed option at three sites (~lines 196, 223, 243). In each, change:

```php
$this->artisan('integrations:refresh', ['--throttle-ms' => 0])->assertSuccessful();
```

to:

```php
$this->artisan('integrations:refresh')->assertSuccessful();
```

No other change needed: `phpunit.xml` sets `QUEUE_CONNECTION=sync` and this file does NOT use `Queue::fake()`, so the dispatched `RefreshConnectionJob`s execute inline and the tests keep asserting the same end-to-end payload effects (now exercising dispatcher → job → refresher, which is a better integration test than before).

- [ ] **Step 6: Run the Google Business tests to verify they still pass**

Run: `php artisan test tests/Feature/Platforms/GoogleBusinessDetailsTest.php`
Expected: PASS (all tests in the file).

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Console/Commands/RefreshIntegrationConnectionsCommand.php tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php tests/Feature/Platforms/GoogleBusinessDetailsTest.php
git add app/Console/Commands/RefreshIntegrationConnectionsCommand.php tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php tests/Feature/Platforms/GoogleBusinessDetailsTest.php
git commit -m "feat(refresh): serial cron → per-connection dispatcher (SCALE-1)"
```

---

## Task 7: Backlog / staleness alarm

**Files:**
- Create: `app/Exceptions/Platforms/PlatformRefreshBacklogException.php`
- Create: `app/Console/Commands/CheckPlatformRefreshBacklogCommand.php`
- Test: `tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php`

**Interfaces:**
- Consumes: `PlatformRegistry::refreshable()`, `PlatformDescriptor::refreshInterval()`, `IntegrationConnection::dueForRefresh()`, `config('partna.refresh.*')`.
- Produces: `integrations:refresh-backlog` — `report()`s `PlatformRefreshBacklogException` when the overdue count exceeds `alert_threshold`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Small, deterministic thresholds so we don't have to seed 500 rows.
    config()->set('partna.refresh.backlog.grace_multiplier', 1);
    config()->set('partna.refresh.backlog.alert_threshold', 1);
});

function backlogUser(): User
{
    return User::create([
        'handle' => 'bk', 'handle_lc' => 'bk', 'display_name' => 'BK',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'bk@example.com',
    ]);
}

it('reports a backlog exception when overdue count exceeds the threshold', function () {
    Exceptions::fake();
    $user = backlogUser();

    foreach (['youtube', 'youtube2'] as $i => $rid) {
        IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => $rid,
            'payload' => ['handle' => 'c'], 'last_refreshed_at' => now()->subYear(),
        ]);
    }

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

it('does not report when the backlog is within threshold', function () {
    Exceptions::fake();
    backlogUser(); // no overdue connections

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php`
Expected: FAIL — command `integrations:refresh-backlog` does not exist.

- [ ] **Step 3: Implement the exception**

```php
<?php
// app/Exceptions/Platforms/PlatformRefreshBacklogException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when the count of connections overdue for refresh exceeds
// the configured threshold — the "alert BEFORE N outgrows capacity" signal SCALE-1
// called for. A plain Log line would be an invisible breadcrumb (Nightwatch alerts on
// thrown/reported exceptions, not logs — see reference_nightwatch_alerts).
class PlatformRefreshBacklogException extends RuntimeException
{
    public function __construct(public int $overdueCount, public int $threshold)
    {
        parent::__construct("Platform refresh backlog {$overdueCount} exceeds threshold {$threshold}.");
    }
}
```

- [ ] **Step 4: Implement the command**

```php
<?php
// app/Console/Commands/CheckPlatformRefreshBacklogCommand.php

namespace App\Console\Commands;

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

// Staleness alarm: counts connections overdue by more than (TTL × grace) and reports
// to Nightwatch when the total exceeds the alert threshold. This is how we learn the
// fleet has outgrown refresh capacity BEFORE cards silently go stale (SCALE-1).
class CheckPlatformRefreshBacklogCommand extends Command
{
    protected $signature = 'integrations:refresh-backlog';

    protected $description = 'Alert when too many platform connections are overdue for refresh.';

    public function handle(PlatformRegistry $registry): int
    {
        $defaultTtl = (int) config('partna.refresh.default_ttl_seconds');
        $maxFailures = (int) config('partna.refresh.max_consecutive_failures');
        $grace = (float) config('partna.refresh.backlog.grace_multiplier');
        $threshold = (int) config('partna.refresh.backlog.alert_threshold');

        $overdue = 0;

        foreach ($registry->refreshable() as $platform => $descriptor) {
            $ttl = (int) (($descriptor->refreshInterval() ?? $defaultTtl) * $grace);
            $cutoff = now()->subSeconds($ttl);

            $overdue += IntegrationConnection::query()
                ->where('platform', $platform)
                ->dueForRefresh($cutoff, $maxFailures)
                ->count();
        }

        if ($overdue > $threshold) {
            report(new PlatformRefreshBacklogException($overdue, $threshold));
            $this->warn("Refresh backlog {$overdue} exceeds threshold {$threshold} — reported to Nightwatch.");
        } else {
            $this->info("Refresh backlog {$overdue} within threshold {$threshold}.");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Exceptions/Platforms/PlatformRefreshBacklogException.php app/Console/Commands/CheckPlatformRefreshBacklogCommand.php tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php
git add app/Exceptions/Platforms/PlatformRefreshBacklogException.php app/Console/Commands/CheckPlatformRefreshBacklogCommand.php tests/Feature/Platforms/CheckPlatformRefreshBacklogCommandTest.php
git commit -m "feat(refresh): backlog/staleness Nightwatch alarm (SCALE-1)"
```

---

## Task 8: Wire the schedule + Horizon supervisor

**Files:**
- Modify: `config/horizon.php`
- Modify: `routes/console.php`

**Interfaces:**
- Consumes: `integrations:refresh` (Task 6), `integrations:refresh-backlog` (Task 7), the `platform_refresh` queue (Task 3).
- Produces: an hourly dispatcher run + hourly backlog check + a dedicated worker supervisor that actually consumes the queue.

- [ ] **Step 1: Add the Horizon supervisor**

In `config/horizon.php`, alongside `supervisor-scraping`, add:

```php
        // Platform refresh fan-out (RefreshConnectionJob). Isolated so a refresh burst
        // can't starve user-facing queues. I/O-bound (external APIs), and per-provider
        // outbound pressure is capped by the 'platform-refresh' RateLimiter — NOT by
        // worker count — so process count can be generous. timeout 150 > the job's
        // $timeout=120 and < the redis connection's retry_after=360 (no mid-run re-queue).
        'supervisor-platform-refresh' => [
            'connection' => 'redis',
            'queue' => ['platform_refresh'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,       // retries governed by the job's own $tries/$backoff
            'timeout' => 150,
            'nice' => 10,
        ],
```

- [ ] **Step 2: Wire the supervisor into EVERY environment (critical — do not skip)**

Horizon only runs supervisors listed in the **current environment's** array under `'environments'`; a supervisor defined only in `'defaults'` never runs. This codebase has already been bitten by exactly this (see the `supervisor-scraping` comment: *"previously no supervisor ran it, so InstagramConnectJob never executed in any env"*). Three edits in `config/horizon.php`:

**(a) `environments.production`** — add alongside the other supervisor overrides:

```php
            'supervisor-platform-refresh' => ['minProcesses' => 1, 'maxProcesses' => 2],
```

**(b) `environments.development.supervisor-1`** — this env (the LIVE one on Laravel Cloud) routes all queues through one hard-coded list. Append `'platform_refresh'`:

```php
                'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'scraping', 'platform_refresh'],
```

**(c) `environments.local.supervisor-1`** — same edit, append `'platform_refresh'` to its `queue` array.

- [ ] **Step 3: Update the schedule**

In `routes/console.php`, replace the existing `integrations:refresh` schedule block:

```php
Schedule::command('integrations:refresh')
    ->dailyAt('03:40')
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(60)
    ->onFailure($reportScheduledFailure('integrations:refresh'));
```

with (dispatcher is cheap → run hourly so connections are picked up near their TTL; add the backlog check):

```php
// Dispatcher: fan out a queued RefreshConnectionJob per due connection. Hourly so
// connections are picked up close to their TTL (the heavy work is on the queue, not
// here). Lock < cadence so a slow run can't overlap the next tick.
Schedule::command('integrations:refresh')
    ->hourly()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh'));

// Staleness alarm: page when too many connections fall overdue (SCALE-1).
Schedule::command('integrations:refresh-backlog')
    ->hourly()
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping(50)
    ->onFailure($reportScheduledFailure('integrations:refresh-backlog'));
```

- [ ] **Step 4: Verify the schedule + commands register**

Run: `php artisan schedule:list`
Expected: both `integrations:refresh` and `integrations:refresh-backlog` appear, hourly.

Run: `php artisan config:clear && php artisan horizon:status || true` then confirm the supervisor is defined:
Run: `php artisan tinker --execute="echo collect(config('horizon.defaults'))->keys()->implode(',');"`
Expected: the list includes `supervisor-platform-refresh` (or the env-specific arrays — check whichever the file uses).

- [ ] **Step 5: Full suite (namespace/relocation safety net)**

Run: `composer test`
Expected: PASS — full suite green. (Per `feedback_namespace_relocation_short_refs` / worktree-test gotchas, run the FULL suite in the main checkout, not a filtered subset.)

- [ ] **Step 6: Commit**

```bash
php artisan pint config/horizon.php routes/console.php
git add config/horizon.php routes/console.php
git commit -m "feat(refresh): hourly dispatcher + backlog schedule + platform_refresh supervisor (SCALE-1)"
```

---

## Self-Review

**1. Spec coverage (against strategy doc §8 Plan 1 = spine-only):**
- source-agnostic "refresh one connection" job → Task 4 ✓
- per-connection due-scheduler (migration-free, TTL from descriptor/config) → Tasks 1, 2, 6 ✓
- distributed per-provider limiter → Tasks 3, 5 ✓ (cache-backed → Redis in prod → shared)
- backlog/staleness gauge → Task 7 ✓
- dedicated queue + worker → Tasks 3, 8 ✓
- conditional requests (ETag/304) → **intentionally excluded** (first follow-on, per locked decision) ✓
- manual `RefreshController` async → **intentionally excluded** (JOB-1 follow-on) ✓
- migration → **none, by design** ✓

**2. Placeholder scan:** no TBD / "add error handling" / "write tests for the above" — every step has concrete code and commands. ✓

**3. Type consistency:** `RefreshConnectionJob(string $connectionId, string $platform)` — public props `$connectionId`/`$platform` used identically in Tasks 4, 5, 6. `dueForRefresh($cutoff, $maxFailures)` signature identical in Tasks 2, 6, 7. `refreshInterval(): ?int` used in Tasks 6, 7. Limiter name `'platform-refresh'` identical in Tasks 4 (`middleware()`) and 5 (registration). Queue key `config('partna.queues.platform_refresh')` (Task 3) matches the supervisor queue `platform_refresh` (Task 8). ✓

**Post-merge follow-ons (recorded in strategy doc §8, NOT in this plan):** Bundle A (#SCALE-2 Apify budget on the shared limiter + #SCALE-4 pacing), #JOB-1 (async connect controllers), #SCALE-3 residual per-host config, Bundle B (#OBS-1), then conditional requests (ETag/304). Separate hygiene bundles (C, JOB-2, P3s) run independently via `execute audit`.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-platform-refresh-foundation-plan-1.md`. This is a blocker-gate item (P1) — implementation waits for Josh's sign-off. Two execution options once approved:

**1. Subagent-Driven (recommended)** — a fresh subagent per task, two-stage review between tasks (implement Sonnet → independent Sonnet review), fast iteration. Matches the audit fix-flow.

**2. Inline Execution** — execute tasks in this session with checkpoints for review.
