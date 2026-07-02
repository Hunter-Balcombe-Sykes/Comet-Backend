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
