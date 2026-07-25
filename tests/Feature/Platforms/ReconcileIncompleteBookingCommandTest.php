<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function reconcileUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function reconcileFresha(User $user, ?array $selection, bool $isActive = true): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/anseo-studio', 'selection' => $selection, 'source' => 'instagram'],
        'is_active' => $isActive,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('lists incomplete rows and changes nothing by default', function () {
    $user = reconcileUser('incompl');
    $row = reconcileFresha($user, null);
    $before = $row->updated_at;

    // Order matters here: Mockery's doWrite matcher picks the FIRST-defined
    // expectation whose substring matches a given output line, so a shorter
    // substring registered before a longer one that contains it (here
    // "incompl" is literally a substring of "incomplete") permanently
    // swallows every line the later expectation needed to match. Registering
    // the more specific "1 incomplete" first avoids the collision without
    // changing any expected value.
    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('1 incomplete')
        ->expectsOutputToContain('incompl')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect($row->fresh()->updated_at->eq($before))->toBeTrue();
});

it('ignores complete rows', function () {
    reconcileFresha(reconcileUser('complete'), ['mode' => 'employee', 'services' => []]);

    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('0 incomplete')
        ->assertExitCode(0);
});

it('ignores inactive rows', function () {
    reconcileFresha(reconcileUser('inactive'), null, isActive: false);

    $this->artisan('booking:reconcile-incomplete')
        ->expectsOutputToContain('0 incomplete')
        ->assertExitCode(0);
});

it('never writes last_refresh_status pending', function () {
    $row = reconcileFresha(reconcileUser('nopend'), null);

    $this->artisan('booking:reconcile-incomplete --apply')->assertExitCode(0);

    expect($row->fresh()->last_refresh_status)->toBe('ok');
});
