<?php

use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function scopeUser(): User
{
    return User::create([
        'handle' => 'scope', 'handle_lc' => 'scope', 'display_name' => 'Scope',
        'first_name' => 'Scope',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
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

// E-5: a 'pending' row's last_refreshed_at is NULL — before this fix that
// matched the "never refreshed" arm and let the hourly cron race an in-flight
// ConnectFetchJob. An ordinary due row (no status set at all, same as every
// pre-deferred-connect row ever written) must still be selected.
it('excludes a pending row from the refresh selection while a normal due row is still selected', function () {
    $user = scopeUser();
    $cutoff = now()->subDay();

    $pending = ytConn($user, ['last_refreshed_at' => null, 'last_refresh_status' => 'pending']);
    $due = ytConn($user, ['last_refreshed_at' => now()->subWeek(), 'resource_id' => 'youtube-due']);

    $selected = IntegrationConnection::query()->dueForRefresh($cutoff, 10)->pluck('id');

    expect($selected)->not->toContain($pending->id)
        ->toContain($due->id);
});

// CA-SM review fix (E-5 follow-up): scopeDueForRefresh()'s pending exclusion
// above correctly hides pending rows from the CRON, but that means a row
// stranded 'pending' by a dead worker would vanish from monitoring too unless
// something else can still see it. scopeStrandedPending() is that something
// else — a separate, visibility-only scope (see CheckPlatformRefreshBacklogCommand).
it('scopeStrandedPending finds an old pending row but not a fresh one, an ok one, or a null-updated_at one', function () {
    $user = scopeUser();
    $cutoff = now()->subMinutes(5);

    // Direct query builder update, NOT a model save(): 'updated_at' isn't
    // fillable on this model, and a save() would re-stamp it to "now" anyway.
    $stranded = ytConn($user, ['last_refresh_status' => 'pending', 'resource_id' => 'youtube-stranded']);
    IntegrationConnection::query()->where('id', $stranded->id)->update(['updated_at' => now()->subMinutes(10)]);

    $freshPending = ytConn($user, ['last_refresh_status' => 'pending', 'resource_id' => 'youtube-fresh-pending']);
    $ok = ytConn($user, ['last_refresh_status' => 'ok', 'resource_id' => 'youtube-ok']);

    // Can't be proven stale (same reasoning as RefreshController::refreshStatus()'s
    // own stale-pending check), so a NULL updated_at must never count as stranded.
    //
    // #PARITY-1: once supabase/migrations/20260729150016..150018 actually
    // applies, site.platform_connections.updated_at becomes NOT NULL in
    // Postgres and this exact write would fail there too — this branch then
    // guards a state unreachable through the app, kept for defence-in-depth
    // the same way DocumentBuilderTest.php:224's orphan pin is. The SQLite
    // stand-in deliberately stays nullable on this column (see the comment
    // at tests/Pest.php's site.platform_connections definition) so this test
    // keeps exercising it.
    $nullUpdatedAt = ytConn($user, ['last_refresh_status' => 'pending', 'resource_id' => 'youtube-null-updated']);
    IntegrationConnection::query()->where('id', $nullUpdatedAt->id)->update(['updated_at' => null]);

    $found = IntegrationConnection::query()->strandedPending($cutoff)->pluck('id');

    expect($found)->toContain($stranded->id)
        ->not->toContain($freshPending->id)
        ->not->toContain($ok->id)
        ->not->toContain($nullUpdatedAt->id);
});

// CA-SM review fix: a prior revision had this job ALSO bail out on
// last_refresh_status === 'pending', reasoning that a pending row always
// belongs to an in-flight/stranded ConnectFetchJob. That's wrong — the manual
// refresh button (RefreshController::refresh()) writes 'pending' itself
// BEFORE dispatching this exact job, so that guard made the button's own
// happy path unreachable 100% of the time (see RefreshAsyncTest's
// controller→job seam regression). scopeDueForRefresh() above is what keeps
// the hourly CRON from selecting a pending row; the job itself must still act
// on one, because being pending is exactly the state a manual refresh puts a
// row in on the way to running this job.
it('RefreshConnectionJob still refreshes an active connection even when its own status is pending', function () {
    $user = scopeUser();
    $pending = ytConn($user, ['last_refreshed_at' => null, 'last_refresh_status' => 'pending']);

    $refresher = Mockery::mock(PlatformRefresher::class);
    $refresher->shouldReceive('refresh')->once()
        ->with(Mockery::on(fn (IntegrationConnection $c) => $c->id === $pending->id))
        ->andReturn($pending);

    (new RefreshConnectionJob($pending->id, 'youtube'))->handle($refresher);
});

it('RefreshConnectionJob still refreshes an ordinary active, non-pending connection', function () {
    $user = scopeUser();
    $due = ytConn($user, ['last_refreshed_at' => now()->subWeek(), 'last_refresh_status' => 'ok']);

    $refresher = Mockery::mock(PlatformRefresher::class);
    $refresher->shouldReceive('refresh')->once()
        ->with(Mockery::on(fn (IntegrationConnection $c) => $c->id === $due->id))
        ->andReturn($due);

    (new RefreshConnectionJob($due->id, 'youtube'))->handle($refresher);
});
