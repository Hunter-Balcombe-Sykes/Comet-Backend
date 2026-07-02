<?php

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
