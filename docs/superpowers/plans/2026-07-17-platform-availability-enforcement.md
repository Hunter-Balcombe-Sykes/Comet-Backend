# Platform Availability Enforcement (staff kill-switch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make staff `integration.<platform>` availability rules actually enforced — block connecting a disabled platform (before it's even scraped) and take existing content off live sites — with zero data loss.

**Architecture:** Two enforcement layers plus a takedown job. (1) A trait guard in `ManagesIntegrationConnection` (the universal persistence net) aborts 503 on any write to a disabled platform. (2) An `EnsurePlatformAvailable` route middleware on the `/connect` family aborts 503 before the controller runs, so a disabled platform is never scraped. A `ReconcilePlatformTakedownJob` flips `is_active=false` in bulk (global) or for a segment's members; it's dispatched by the staff availability upsert and by segment member-adds. No schema change (re-enable never auto-reactivates).

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4 (SQLite in-memory tests), Redis-backed queue (deployed dev runs `QUEUE_CONNECTION=sync`).

## Global Constraints

- **No Laravel migrations.** No schema change is needed in this plan at all.
- **No inline 403/abort authorization in controllers** — but `abort(503, …)` for *service availability* (not authorization) is allowed and is the chosen convention (matches `App\Http\Middleware\FeatureGate`).
- **Resource classes / Form Requests** stay as-is; this plan adds no new API response shapes.
- **Availability predicate is always:** `FeatureAvailability::for($user)->allows('integration.'.$platform)`. Absence of a rule = available.
- **Feature key convention:** `integration.<platform>` where `<platform>` is a `PlatformRegistry` key.
- **Tests:** SQLite mirror needs `setupUsersTable()`, `setupSegmentsTables()`, `setupFeatureAvailabilityTable()` before any availability assertion (the read service fail-opens to "available" if the table is missing — a disabled-rule test would silently pass without these). Always call `FeatureAvailability::flush()` after creating/deleting a rule.
- **Staff HTTP tests** (Tasks 4–5): the `staff.audit` middleware writes to `audit.staff_audit_log` on `terminate()`, so the table must exist or the request throws. Mirror the beforeEach + unpersisted `new PartnaStaff` admin actor + `actingAsStaff()` pattern from `tests/Feature/Staff/StaffBulkStatusTest.php`. Watch the SQLite attached-schema cap (`SQLITE_MAX_ATTACHED=10`) when combining `core`+`audit` schema setup — DETACH unused schemas if it trips (`reference_testing_information_schema_sqlite`).
- **Queued jobs:** declare `$backoff`; never a typed `public bool $afterCommit` property — use `->afterCommit()` on dispatch.
- Run a single test file with `php artisan test <path>`; run the full suite with `composer test` at the end.

---

### Task 1: Persistence net — `ManagesIntegrationConnection` trait guard

The universal guarantee: no disabled platform ever persists or resurrects a connection, for every platform and every mutating verb. Guards the two methods that issue the DB write; `writeAccountConnection()` delegates to `writeConnection()` and is covered transitively.

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
- Test: `tests/Feature/Platforms/PlatformAvailabilityNetTest.php`

**Interfaces:**
- Consumes: `App\Services\FeatureAvailability\FeatureAvailability::for(User): UserFeatureAvailability` with `->allows(string): bool`; `$this->platform(): string` (already on the trait).
- Produces: `private function assertPlatformAvailable(App\Models\Core\User\User $user): void` — aborts 503 when the platform is unavailable for `$user`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Platforms/PlatformAvailabilityNetTest.php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    FeatureAvailability::flush();
});

function netUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('blocks persisting a connection for a disabled platform even after a successful scrape', function () {
    $user = netUser('netblock');

    // Scrape "succeeds" — the point is the net still refuses to persist.
    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    \App\Models\Core\FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'skool')->count())
        ->toBe(0);
});

it('does not resurrect a taken-down connection while the platform stays disabled', function () {
    $user = netUser('netresurrect');

    // Existing connection already taken down (is_active=false).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'skool', 'resource_id' => 'c-res',
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo'], 'is_active' => false,
    ]);

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    \App\Models\Core\FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ]);
    FeatureAvailability::flush();

    // A reconnect attempt while disabled must not flip is_active back to true.
    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    expect($conn->refresh()->is_active)->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/PlatformAvailabilityNetTest.php`
Expected: FAIL — the connection is created and the response is 200/201 (no guard yet), so the 503 assertion fails.

- [ ] **Step 3: Add the guard to the trait**

Add the import near the other `use` statements at the top of `ManagesIntegrationConnection.php`:

```php
use App\Services\FeatureAvailability\FeatureAvailability;
```

Add this private method to the trait (place it just above `writeConnection`):

```php
/**
 * OV-A persistence net: refuse any write to a platform staff have disabled for
 * this user (global or segment rule). 503 matches the FeatureGate convention.
 * Also stops a reconnect/refresh from resurrecting a taken-down connection.
 */
private function assertPlatformAvailable(User $user): void
{
    if (! FeatureAvailability::for($user)->allows('integration.'.$this->platform())) {
        abort(503, 'This integration is currently unavailable.');
    }
}
```

Call it as the FIRST statement inside `writeConnection()` (before `$existing = $this->connectionFor(...)`):

```php
protected function writeConnection(User $user, array $payload, ?string $resourceId = null, ?string $canonicalKey = null, ?string $resourceKind = null): IntegrationConnection
{
    $this->assertPlatformAvailable($user);

    // Determine create vs. update before the upsert so the correct ability fires.
    $existing = $this->connectionFor($user, $resourceId);
    // ... unchanged ...
```

Call it as the FIRST statement inside `writePendingLinkCard()` too (it takes `User $user` as its first parameter):

```php
protected function writePendingLinkCard(User $user, array $payload, ?string $resourceId = null, ?string $resourceKind = null): IntegrationConnection
{
    $this->assertPlatformAvailable($user);
    // ... unchanged ...
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Platforms/PlatformAvailabilityNetTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php tests/Feature/Platforms/PlatformAvailabilityNetTest.php
git commit -m "feat(platforms): persistence-net guard blocks writes to disabled integrations"
```

---

### Task 2: Before-scrape guard — `EnsurePlatformAvailable` middleware

Blocks the `/connect` flow before the controller (and its scraper) runs. Reads platform from a middleware param or the route's `->defaults('platform')`; reads the acting user from the `professional` request attribute (set by `LoadCurrentUser` in the `user.api` group, which precedes route middleware).

**Files:**
- Create: `app/Http/Middleware/Context/EnsurePlatformAvailable.php`
- Modify: `bootstrap/app.php` (alias block near line 89–101)
- Modify: `routes/api/platforms.php` (the `/connect` routes)
- Test: `tests/Feature/Platforms/PlatformAvailabilityConnectGuardTest.php`

**Interfaces:**
- Consumes: `FeatureAvailability::for(User)->allows(...)`; `$request->attributes->get('professional')` (a `User`); route default `platform`.
- Produces: route-middleware alias `platform.available` (optional `:platform` param).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Platforms/PlatformAvailabilityConnectGuardTest.php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    FeatureAvailability::flush();
});

function guardUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('503s a disabled-platform connect WITHOUT invoking the scraper', function () {
    $user = guardUser('guardblock');

    // A bare Mockery mock: any call to the scraper fails the test. Because the
    // middleware 503s before the controller resolves, it is never called.
    $this->mock(SkoolScraper::class);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.skool', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);
});

it('allows a connect when no rule exists', function () {
    $user = guardUser('guardallow');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'skool')->count())
        ->toBe(1);
});

it('blocks a member of a disabled segment but allows an outsider', function () {
    $member = guardUser('guardmember');
    $outsider = guardUser('guardoutsider');

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled', 'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    // Member: blocked before scrape (bare mock = fails if called).
    $this->mock(SkoolScraper::class);
    actingAsUser($member)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertStatus(503);

    // Outsider: allowed (rebind the scraper to return data).
    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/demo');
        $m->shouldReceive('fetchCommunity')->andReturn(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']);
    });
    actingAsUser($outsider)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/demo'])
        ->assertOk();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/PlatformAvailabilityConnectGuardTest.php`
Expected: FAIL — the first test's bare `SkoolScraper` mock receives `normalizeUrl`/`fetchCommunity` (controller runs), raising a Mockery "unexpected method call", because no middleware blocks it yet.

- [ ] **Step 3: Create the middleware**

```php
<?php
// app/Http/Middleware/Context/EnsurePlatformAvailable.php

namespace App\Http\Middleware\Context;

use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OV-A: 503s a platform connect BEFORE the controller runs (hence before any
 * scrape) when staff have disabled that integration for the acting user — global
 * or segment rule. Platform comes from the middleware param or the route's
 * ->defaults('platform'). Complements the ManagesIntegrationConnection persistence
 * net, which blocks the DB write for every platform/verb.
 *
 * Apply as: ->middleware('platform.available') on routes that set a platform
 * default, or ->middleware('platform.available:<platform>') otherwise.
 */
class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next, ?string $platform = null): Response
    {
        $platform ??= $request->route()?->defaults['platform'] ?? null;
        $user = $request->attributes->get('professional');

        // Fail-open when either is missing: user.api guarantees the user on these
        // routes, and connect routes always carry a platform — so this never fires
        // spuriously, and the persistence net is the backstop regardless.
        if ($platform !== null && $user instanceof User
            && ! FeatureAvailability::for($user)->allows('integration.'.$platform)) {
            abort(503, 'This integration is currently unavailable.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, inside the `$middleware->alias([...])` array (near the `'require.aal2' => RequireAal2::class,` line), add:

```php
'platform.available' => \App\Http\Middleware\Context\EnsurePlatformAvailable::class,
```

- [ ] **Step 5: Apply the middleware to the `/connect` family**

In `routes/api/platforms.php`, append `->middleware('platform.available')` to each of these connect routes (they already set a `platform` default, so no param needed):

```php
// Fresha
Route::post('/connect', [FreshaController::class, 'connect'])->defaults('platform', 'fresha')->middleware('platform.available');
// Square
Route::post('/connect', [SquareController::class, 'connect'])->defaults('platform', 'square')->middleware('platform.available');
// Apple music
Route::post('/music/connect', [AppleController::class, 'connectMusic'])->defaults('platform', $musicPlatform)->middleware('platform.available');
// Apple podcast
Route::post('/podcast/connect', [AppleController::class, 'connectPodcast'])->defaults('platform', $podcastPlatform)->middleware('platform.available');
```

In the events loop (currently `Route::post('/connect', [$controller, 'connect'])->defaults('platform', $slug);`):

```php
Route::post('/connect', [$controller, 'connect'])->defaults('platform', $slug)->middleware('platform.available');
```

In the registry-driven generic loop (currently `Route::post('/connect', [$connectController, 'connect'])->defaults('platform', $slug);` — this is the route that serves Skool and every simple/single-selection platform):

```php
Route::post('/connect', [$connectController, 'connect'])->defaults('platform', $slug)->middleware('platform.available');
```

Instagram's connect route sets NO platform default, so give it the explicit param:

```php
Route::post('/connect', [InstagramController::class, 'connect'])->middleware('platform.available:instagram');
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Platforms/PlatformAvailabilityConnectGuardTest.php`
Expected: PASS (all three). If the "block" test still calls the scraper, the middleware isn't seeing the user — confirm `platform.available` runs after the `user.api` group on that route.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/Context/EnsurePlatformAvailable.php bootstrap/app.php routes/api/platforms.php tests/Feature/Platforms/PlatformAvailabilityConnectGuardTest.php
git commit -m "feat(platforms): before-scrape availability middleware on connect routes"
```

---

### Task 3: Takedown job — `ReconcilePlatformTakedownJob`

Flips `is_active=false` on connections of a disabled platform — globally or for a segment's members — via per-model save (fires the observer's per-site cache-bust). No data deleted; re-enable never reactivates.

**Files:**
- Create: `app/Jobs/Platforms/ReconcilePlatformTakedownJob.php`
- Test: `tests/Feature/Platforms/ReconcilePlatformTakedownJobTest.php`

**Interfaces:**
- Consumes: `SegmentResolver->userIds(UserSegment): list<string>`; `IntegrationConnection::scopeActive()`.
- Produces: `App\Jobs\Platforms\ReconcilePlatformTakedownJob::__construct(string $platform, ?string $segmentId = null)` and `handle(SegmentResolver $resolver): void`. Tasks 4 and 5 dispatch it.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Platforms/ReconcilePlatformTakedownJobTest.php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
});

function takedownUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function skoolConn(User $u): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $u->id, 'platform' => 'skool', 'resource_id' => 'c-'.Str::random(5),
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo'], 'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('globally flips is_active=false without deleting data', function () {
    $a = skoolConn(takedownUser('tka'));
    $b = skoolConn(takedownUser('tkb'));

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(App\Services\Segments\SegmentResolver::class));

    $a->refresh();
    $b->refresh();
    expect($a->is_active)->toBeFalse()
        ->and($b->is_active)->toBeFalse()
        ->and($a->payload)->toBe(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']) // data intact
        ->and($a->deleted_at)->toBeNull(); // not soft-deleted
});

it('leaves other platforms untouched', function () {
    $user = takedownUser('tkother');
    $ig = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'ig-1',
        'payload' => ['username' => 'x'], 'is_active' => true,
    ]);

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(App\Services\Segments\SegmentResolver::class));

    expect($ig->refresh()->is_active)->toBeTrue();
});

it('scopes a segment takedown to that segment members only', function () {
    $member = takedownUser('tkmember');
    $outsider = takedownUser('tkoutsider');
    $mConn = skoolConn($member);
    $oConn = skoolConn($outsider);

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    (new ReconcilePlatformTakedownJob('skool', $segment->id))->handle(app(App\Services\Segments\SegmentResolver::class));

    expect($mConn->refresh()->is_active)->toBeFalse()
        ->and($oConn->refresh()->is_active)->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Platforms/ReconcilePlatformTakedownJobTest.php`
Expected: FAIL — `Class "App\Jobs\Platforms\ReconcilePlatformTakedownJob" not found`.

- [ ] **Step 3: Create the job**

```php
<?php
// app/Jobs/Platforms/ReconcilePlatformTakedownJob.php

namespace App\Jobs\Platforms;

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Segments\SegmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * OV-A staff kill-switch takedown. Flips is_active=false on connections of a
 * disabled platform — globally (segmentId null) or for one segment's members —
 * so existing content stops rendering (public payload filters is_active=true).
 * Per-model save fires IntegrationConnectionObserver, busting each site's cache.
 * No data deleted: only the flag changes. Re-enable does NOT reactivate.
 */
class ReconcilePlatformTakedownJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [30, 120, 300];

    public int $tries = 3;

    public function __construct(
        public string $platform,
        public ?string $segmentId = null,
    ) {
        $this->onQueue(config('partna.queues.platform_refresh'));
    }

    public function handle(SegmentResolver $resolver): void
    {
        $query = IntegrationConnection::query()
            ->where('platform', $this->platform)
            ->active();

        if ($this->segmentId !== null) {
            $segment = UserSegment::query()->find($this->segmentId);
            if ($segment === null) {
                return; // segment removed between dispatch and run
            }
            $userIds = $resolver->userIds($segment);
            if ($userIds === []) {
                return;
            }
            $query->whereIn('user_id', $userIds);
        }

        // chunkById is safe while mutating is_active: pages by ascending id, and
        // flipped rows drop out of the active() filter without being revisited.
        $query->chunkById(200, function ($connections): void {
            foreach ($connections as $connection) {
                $connection->is_active = false;
                $connection->save(); // per-model save so the observer busts each site's cache
            }
        });
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Platforms/ReconcilePlatformTakedownJobTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Platforms/ReconcilePlatformTakedownJob.php tests/Feature/Platforms/ReconcilePlatformTakedownJobTest.php
git commit -m "feat(platforms): ReconcilePlatformTakedownJob bulk-disables connections"
```

---

### Task 4: Trigger 6a — availability upsert dispatches the takedown

When staff PUT a `disabled` rule for `integration.<platform>`, dispatch the takedown for its scope. Adds a small model helper reused by Task 5.

**Files:**
- Modify: `app/Models/Core/FeatureAvailabilityRule.php`
- Modify: `app/Http/Controllers/Api/Staff/FeatureAvailability/StaffFeatureAvailabilityController.php`
- Test: `tests/Feature/Staff/FeatureAvailabilityTakedownTriggerTest.php`

**Interfaces:**
- Consumes: `ReconcilePlatformTakedownJob` (Task 3); `PlatformRegistry->has(string): bool`.
- Produces: `FeatureAvailabilityRule::integrationPlatform(): ?string` (returns the platform for an `integration.<platform>` key, else null) — Task 5 consumes it.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Staff/FeatureAvailabilityTakedownTriggerTest.php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    setupPartnaStaffTable();
    // staff.audit middleware writes on terminate() — create the audit table.
    // Copy the audit.staff_audit_log CREATE TABLE verbatim from
    // tests/Feature/Staff/StaffBulkStatusTest.php beforeEach (lines ~26-41),
    // which also attaches the `audit` schema. If the SQLite attached-schema
    // limit bites (SQLITE_MAX_ATTACHED=10), DETACH unused schemas per the
    // harness pattern (see reference_testing_information_schema_sqlite).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    FeatureAvailability::flush();
});

// Unpersisted admin actor — mirrors bulkStatus_makeAdminStaff() in
// tests/Feature/Staff/StaffBulkStatusTest.php. actingAsStaff() stubs the JWT +
// staff resolution, so the row need not be persisted.
function takedownTriggerStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-tk@partna.au';

    return $staff;
}

it('dispatches a global takedown when an integration is disabled', function () {
    Bus::fake();

    actingAsStaff(takedownTriggerStaff())->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ])->assertSuccessful();

    Bus::assertDispatched(ReconcilePlatformTakedownJob::class, fn ($job) =>
        $job->platform === 'skool' && $job->segmentId === null);
});

it('does NOT dispatch when enabling, or for a non-integration key', function () {
    Bus::fake();
    $staff = takedownTriggerStaff();

    actingAsStaff($staff)->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'enabled',
    ])->assertSuccessful();

    actingAsStaff($staff)->putJson('/api/staff/feature-availability', [
        'feature_key' => 'feature.shop', 'mode' => 'disabled',
    ])->assertSuccessful();

    Bus::assertNotDispatched(ReconcilePlatformTakedownJob::class);
});

it('re-enabling does not reactivate a taken-down connection', function () {
    $user = User::create([
        'handle' => 'tkreenable', 'handle_lc' => 'tkreenable', 'display_name' => 'Re',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'tkreenable@example.com',
    ]);
    // Already taken down.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'skool', 'resource_id' => 'c-reenable',
        'payload' => ['url' => 'https://www.skool.com/demo'], 'is_active' => false,
    ]);

    actingAsStaff(takedownTriggerStaff())->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'enabled',
    ])->assertSuccessful();

    expect($conn->refresh()->is_active)->toBeFalse(); // stays off — no auto-reactivation
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Staff/FeatureAvailabilityTakedownTriggerTest.php`
Expected: FAIL — first test: `Bus::assertDispatched` fails (nothing dispatched yet).

- [ ] **Step 3: Add the model helper**

In `app/Models/Core/FeatureAvailabilityRule.php`, add the constant and method:

```php
public const INTEGRATION_PREFIX = 'integration.';

/** Platform key for an integration.<platform> rule, or null for other keys. */
public function integrationPlatform(): ?string
{
    if (! str_starts_with((string) $this->feature_key, self::INTEGRATION_PREFIX)) {
        return null;
    }

    $platform = substr((string) $this->feature_key, strlen(self::INTEGRATION_PREFIX));

    return $platform === '' ? null : $platform;
}
```

- [ ] **Step 4: Dispatch from the controller**

In `StaffFeatureAvailabilityController.php`, add imports:

```php
use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Services\Platforms\Registry\PlatformRegistry;
```

In `upsert()`, immediately after `FeatureAvailability::flush();` (and before `$rule->load(...)`), add:

```php
// OV-A: a newly-disabled integration takes existing content down (global or
// segment scope). Enable/other keys do nothing; re-enable never reactivates.
if ($rule->mode === FeatureAvailabilityRule::MODE_DISABLED
    && ($platform = $rule->integrationPlatform()) !== null
    && app(PlatformRegistry::class)->has($platform)) {
    ReconcilePlatformTakedownJob::dispatch($platform, $rule->segment_id)->afterCommit();
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Staff/FeatureAvailabilityTakedownTriggerTest.php`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Core/FeatureAvailabilityRule.php app/Http/Controllers/Api/Staff/FeatureAvailability/StaffFeatureAvailabilityController.php tests/Feature/Staff/FeatureAvailabilityTakedownTriggerTest.php
git commit -m "feat(staff): disabling an integration dispatches a platform takedown"
```

---

### Task 5: Trigger 6b — segment member-add takes new members down

When staff add members to a segment that already carries a `disabled` integration rule, re-run the segment takedown so the new members' existing content is removed too (idempotent — only still-active rows flip).

**Files:**
- Modify: `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`
- Test: `tests/Feature/Staff/SegmentTakedownTriggerTest.php`

**Interfaces:**
- Consumes: `FeatureAvailabilityRule::integrationPlatform()` (Task 4); `ReconcilePlatformTakedownJob` (Task 3); `PlatformRegistry->has()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Staff/SegmentTakedownTriggerTest.php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    setupPartnaStaffTable();
    // staff.audit middleware writes on terminate() — same audit.staff_audit_log
    // CREATE TABLE as Task 4 / tests/Feature/Staff/StaffBulkStatusTest.php.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    FeatureAvailability::flush();
});

function segmentTriggerStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-seg@partna.au';

    return $staff;
}

it('dispatches a segment takedown when adding members to a segment with a disabled integration rule', function () {
    Bus::fake();
    $staff = segmentTriggerStaff();

    $newbie = User::create([
        'handle' => 'segnew', 'handle_lc' => 'segnew', 'display_name' => 'Seg',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'segnew@example.com',
    ]);

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled', 'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    actingAsStaff($staff)->postJson("/api/staff/segments/{$segment->id}/members", [
        'user_ids' => [$newbie->id],
    ])->assertSuccessful();

    Bus::assertDispatched(ReconcilePlatformTakedownJob::class, function ($job) use ($segment) {
        return $job->platform === 'skool' && $job->segmentId === $segment->id;
    });
});

it('does not dispatch when the segment has no disabled integration rule', function () {
    Bus::fake();
    $staff = segmentTriggerStaff();

    $newbie = User::create([
        'handle' => 'segnone', 'handle_lc' => 'segnone', 'display_name' => 'None',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'segnone@example.com',
    ]);
    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);

    actingAsStaff($staff)->postJson("/api/staff/segments/{$segment->id}/members", [
        'user_ids' => [$newbie->id],
    ])->assertSuccessful();

    Bus::assertNotDispatched(ReconcilePlatformTakedownJob::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Staff/SegmentTakedownTriggerTest.php`
Expected: FAIL — first test: nothing dispatched.

- [ ] **Step 3: Dispatch from `addMembers`**

In `StaffSegmentController.php`, add imports:

```php
use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Services\Platforms\Registry\PlatformRegistry;
```

In `addMembers()`, after `FeatureAvailability::flush();` and before the `return`, add:

```php
// OV-A: new members of a segment that already disables an integration inherit
// the takedown. Re-run the segment takedown (idempotent — only still-active
// rows flip). Only when members were actually added.
if ($added > 0) {
    $registry = app(PlatformRegistry::class);
    FeatureAvailabilityRule::query()
        ->where('segment_id', $segment->id)
        ->where('mode', FeatureAvailabilityRule::MODE_DISABLED)
        ->get()
        ->each(function (FeatureAvailabilityRule $rule) use ($registry): void {
            $platform = $rule->integrationPlatform();
            if ($platform !== null && $registry->has($platform)) {
                ReconcilePlatformTakedownJob::dispatch($platform, $rule->segment_id)->afterCommit();
            }
        });
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Staff/SegmentTakedownTriggerTest.php`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php tests/Feature/Staff/SegmentTakedownTriggerTest.php
git commit -m "feat(staff): adding members to a disabled segment takes their content down"
```

---

### Task 6: Full-suite verification

- [ ] **Step 1: Run the whole suite**

Run: `composer test`
Expected: PASS. Pay attention to `PlatformControllerConvergenceTest`, `IntegrationConnectionGuardTest`, and `FeatureAvailabilityReadSideTest` — the surfaces this plan touches. (Do not run `composer test` concurrently with any review subagent that also runs tests.)

- [ ] **Step 2: Style pass (changed files only)**

Run: `php artisan pint app/Http/Middleware/Context/EnsurePlatformAvailable.php app/Jobs/Platforms/ReconcilePlatformTakedownJob.php app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php app/Models/Core/FeatureAvailabilityRule.php app/Http/Controllers/Api/Staff/FeatureAvailability/StaffFeatureAvailabilityController.php app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`
Expected: no diffs beyond the touched files (revert any unrelated baseline churn).

- [ ] **Step 3: Commit any style fixes**

```bash
git add -A
git commit -m "style: pint on platform-availability-enforcement files"
```

## Notes for the executor

- **Deployed dev runs `QUEUE_CONNECTION=sync`**, so on the real env the takedown runs inline on the staff request — fine at pre-beta volume. Tests use `Bus::fake()` for the trigger tests and call `handle()` directly for the job test, so queue mode doesn't affect them.
- **Skool routing:** the generic loop's `/connect` serves Skool. If a future refactor gives Skool a bespoke route group, add `->middleware('platform.available')` (or `:skool`) there too. The persistence net covers it regardless.
- **v1 scope reminder:** the before-scrape middleware covers the `/connect` family only. Other scraping verbs (Shop `/brands`+`/products`, Booking/Reservations `/detect`, OnlineOrdering `/entries`, CustomLinks `/links`, Menu `/refresh`) are covered by the persistence net (nothing persists) but may still scrape once — see the spec's follow-ups.
