<?php

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

// Defined and used in THIS file only — a cross-file Pest helper breaks the
// parallel runner in this repo.
function brandGuardUser(string $handle, string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        // business + a food sector is what grants can_use_online_ordering,
        // which the ordering brands below are gated on.
        'account_type' => 'business',
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('rejects a url belonging to a different brand', function () {
    $user = brandGuardUser('bg-cross');

    actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.doordash.com/store/abc-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.url.0', fn ($m) => str_contains(strtolower((string) $m), 'doordash'));
});

it('names the brand the url actually belongs to', function () {
    $user = brandGuardUser('bg-name');

    actingAsUser($user)
        ->postJson('/api/platforms/doordash/connect', ['url' => 'https://www.menulog.com.au/restaurants/x'])
        ->assertStatus(422)
        ->assertJsonPath('errors.url.0', fn ($m) => str_contains(strtolower((string) $m), 'menulog'));
});

it('rejects a url the classifier does not recognise at all', function () {
    $user = brandGuardUser('bg-unknown');

    actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://example.invalid/nothing'])
        ->assertStatus(422);
});

it('accepts a url belonging to the addressed brand', function () {
    $user = brandGuardUser('bg-match');

    actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.menulog.com.au/restaurants/x'])
        ->assertSuccessful();
});

it('leaves hand-written platforms unguarded', function () {
    // tiktok is LinkOnly with its own normalizer and accepts a bare handle,
    // which classify() cannot resolve. If the guard leaked past Brand shape,
    // this would 422.
    $user = brandGuardUser('bg-handwritten');

    actingAsUser($user)
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk();
});
