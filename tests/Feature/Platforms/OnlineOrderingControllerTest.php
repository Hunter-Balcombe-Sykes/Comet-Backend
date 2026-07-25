<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Business + food sector: online ordering is a food-business-only capability
// (2026-07-15 sector gating — partna explicitly lost access). Every test in
// this file exercises the addEntry()/entries endpoints, so this is the one
// persona the whole suite needs; no test here asserts account-type-dependent
// behaviour, so there's no other default to preserve.
function ooUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** Seed $count online-ordering entries for $user, each with a distinct URL. */
function seedOoEntries(User $user, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        $url = "https://www.ubereats.com/store/seed-{$i}";
        $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'online-ordering',
            'resource_id' => $rid,
            'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }
}

// ── addEntry: async 202 path ──────────────────────────────────────────────────

it('addEntry returns 202, sends no HTTP, and dispatches EnrichLinkCardJob + MenuFetchJob', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo1');

    $res = actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x']);

    $res->assertStatus(202)->assertJsonPath('status', 'pending');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class);
    Queue::assertPushed(MenuFetchJob::class);
});

it('addEntry 202 body includes a statusUrl', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo2');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.doordash.com/store/abc'])
        ->assertStatus(202)
        ->assertJsonStructure(['statusUrl']);
});

it('addEntry writes the connection row with status pending', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo3');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/y'])
        ->assertStatus(202);

    $conn = IntegrationConnection::where('user_id', $user->id)
        ->where('platform', 'online-ordering')
        ->first();

    expect($conn)->not->toBeNull();
    expect($conn->last_refresh_status)->toBe('pending');
});

// ── addEntry: MAX_ENTRIES enforced synchronously ──────────────────────────────

it('still enforces MAX_ENTRIES synchronously — 422 before any job is pushed', function () {
    Queue::fake();

    $user = ooUser('oo4');
    // Seed 10 entries (the limit).
    seedOoEntries($user, 10);

    $res = actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.doordash.com/store/z']);

    $res->assertStatus(422);
    Queue::assertNotPushed(EnrichLinkCardJob::class);
    Queue::assertNotPushed(MenuFetchJob::class);
});

// ── addEntry: merge-on-add stays sync ────────────────────────────────────────

it('merge-on-add dispatches EnrichLinkCardJob for the existing row', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo5');
    $url = 'https://www.ubereats.com/store/samepath?diningMode=PICKUP';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    // Plant an existing entry for the same store (delivery variant).
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // Add the delivery URL for the same store (same path, different diningMode).
    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', [
            'url' => 'https://www.ubereats.com/store/samepath?diningMode=DELIVERY',
        ])
        ->assertStatus(202);

    // EnrichLinkCardJob must be dispatched for the merge-targeted row.
    Queue::assertPushed(EnrichLinkCardJob::class);
});

// ── entryStatus: poll endpoint ────────────────────────────────────────────────

it('entryStatus returns pending while the enrichment job is running', function () {
    $user = ooUser('oo6');
    $url = 'https://www.ubereats.com/store/poll1';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

it('entryStatus returns ready with entries when enrichment completes', function () {
    $user = ooUser('oo7');
    $url = 'https://www.ubereats.com/store/poll2';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'name' => 'My Store'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonStructure(['entries']);
});

it('entryStatus returns 404 for a resource the user does not own', function () {
    $owner = ooUser('oo8a');
    $other = ooUser('oo8b');

    $url = 'https://www.ubereats.com/store/owned';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($other)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertStatus(404);
});
