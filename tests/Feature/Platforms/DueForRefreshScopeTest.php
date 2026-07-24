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

// E-5, "any other path" — RefreshController::refresh() (the manual refresh
// button) dispatches RefreshConnectionJob over every active() row for the
// platform WITHOUT filtering by last_refresh_status (unlike the cron's
// dueForRefresh() scope), so the job itself must refuse to act on a pending
// row regardless of who dispatched it.
it('RefreshConnectionJob skips a pending connection outright, regardless of who dispatched it', function () {
    $user = scopeUser();
    $pending = ytConn($user, ['last_refreshed_at' => null, 'last_refresh_status' => 'pending']);

    $refresher = Mockery::mock(PlatformRefresher::class);
    $refresher->shouldNotReceive('refresh');

    (new RefreshConnectionJob($pending->id, 'youtube'))->handle($refresher);

    expect($pending->fresh()->last_refresh_status)->toBe('pending');
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
