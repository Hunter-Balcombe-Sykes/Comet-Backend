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

it('403s a brand whose routing class the account cannot use', function () {
    // A non-food business has booking but NOT online-ordering. menulog is an
    // ordering brand, so its connect must be closed to them — the capability
    // gate is the point of DerivedDescriptorFactory::CAPABILITY_BY_ROUTING_CLASS.
    $user = brandGuardUser('bg-nofood', 'barber');

    actingAsUser($user)
        ->postJson('/api/platforms/menulog/connect', ['url' => 'https://www.menulog.com.au/restaurants/x'])
        ->assertStatus(403);
});

it('403s an upgraded booking brand for an account without booking', function () {
    // booksy was a hand-written descriptor UPGRADED to Brand. The upgrade path
    // must attach the capability predicate too, not just the routes — a food
    // business has ordering but not booking.
    $user = brandGuardUser('bg-food-booking', 'restaurant');

    actingAsUser($user)
        ->postJson('/api/platforms/booksy/connect', ['url' => 'https://booksy.com/x'])
        ->assertStatus(403);
});

it('lets an eligible account connect an upgraded booking brand', function () {
    $user = brandGuardUser('bg-barber-booking', 'barber');

    actingAsUser($user)
        ->postJson('/api/platforms/booksy/connect', ['url' => 'https://booksy.com/en-gb/x'])
        ->assertSuccessful();
});

it('leaves social brands ungated by any capability', function () {
    // github is routing_class social — no capability applies, so a partna
    // account with no sector must still be able to connect it.
    $user = User::create([
        'handle' => 'bg-social', 'handle_lc' => 'bg-social', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-social@example.com',
    ]);

    actingAsUser($user)
        ->postJson('/api/platforms/github/connect', ['url' => 'https://github.com/someone'])
        ->assertSuccessful();
});

it('enriches a brand card after connect so it does not sit pending forever (F4)', function () {
    // Overnight 2026-08-18 F4: writeBrandCard() stores last_refresh_status
    // 'pending' and connectBrand dispatched nothing to flip it — 43 brand
    // connects in the sweep were pending with the brand label as their name.
    // Booking/reservations/ordering already dispatch EnrichLinkCardJob; the
    // generic brand path must too.
    Queue::fake();
    $user = User::create([
        'handle' => 'bg-enrich', 'handle_lc' => 'bg-enrich', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-enrich@example.com',
    ]);

    actingAsUser($user)
        ->postJson('/api/platforms/github/connect', ['url' => 'https://github.com/someone'])
        ->assertSuccessful();

    Queue::assertPushed(\App\Jobs\Platforms\EnrichLinkCardJob::class, function ($job) use ($user) {
        return $job->userId === (string) $user->id
            && $job->platform === 'github'
            && $job->url === 'https://github.com/someone'
            && $job->surfaceKey !== null;
    });
});

it('accepts a bare handle for brands whose surface has a canonical template (F12)', function () {
    Queue::fake();
    $user = User::create([
        'handle' => 'bg-handle', 'handle_lc' => 'bg-handle', 'display_name' => 'S',
        'first_name' => 'S', 'account_type' => 'partna', 'sector' => null,
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'bg-handle@example.com',
    ]);

    actingAsUser($user)->postJson('/api/platforms/github/connect', ['url' => '@torvalds'])
        ->assertSuccessful()->assertJsonPath('url', 'https://github.com/torvalds');
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'astralcodexten'])
        ->assertSuccessful()->assertJsonPath('url', 'https://astralcodexten.substack.com');
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'astralcodexten.substack.com'])
        ->assertSuccessful()->assertJsonPath('url', 'https://astralcodexten.substack.com');
    // A brand without a template still needs a URL.
    actingAsUser($user)->postJson('/api/platforms/menulog/connect', ['url' => 'somerestaurant'])
        ->assertStatus(422);
    // A foreign host is never a subdomain handle (review: torvalds.github.io was rewritten to *.substack.com).
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'torvalds.github.io'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/substack/connect', ['url' => 'myrestaurant.com.au'])
        ->assertStatus(422);
});
