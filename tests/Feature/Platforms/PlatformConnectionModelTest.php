<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections
});

function makePlatformUser(string $handle = 'jane'): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
    ]);
}

it('persists a per-user platform connection with a jsonb payload', function () {
    $user = makePlatformUser();

    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify.store',
        'resource_id' => 'brand-abc',
        'payload' => ['brandName' => 'Acme', 'productIds' => ['p1', 'p2']],
        'last_refresh_status' => 'ok',
    ]);

    $fresh = IntegrationConnection::find($conn->id);

    expect($fresh->payload)->toBe(['brandName' => 'Acme', 'productIds' => ['p1', 'p2']]);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->consecutive_failures)->toBe(0);
    expect($fresh->user->is($user))->toBeTrue();
    expect($user->integrationConnections()->count())->toBe(1);
});

it('soft deletes a connection', function () {
    $user = makePlatformUser('sam');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'resource_id' => 'sam-store',
        'payload' => [],
    ]);

    $conn->delete();

    expect(IntegrationConnection::find($conn->id))->toBeNull();
    expect(IntegrationConnection::withTrashed()->find($conn->id))->not->toBeNull();
});

it('scopes to active connections', function () {
    $user = makePlatformUser('lee');
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'a', 'payload' => [], 'is_active' => true]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'b', 'payload' => [], 'is_active' => false]);

    expect(IntegrationConnection::active()->count())->toBe(1);
});

it('purges the sitepage edge cache when a connection is written', function () {
    $user = makePlatformUser('obs');
    // Raw-insert the site (no SiteObserver fire) so we assert OUR observer's
    // purge, not SiteObserver's. The observer resolves user → site → subdomain.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'obs-handle',
    ]);

    Queue::fake();

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify.store',
        'resource_id' => 'b1',
        'payload' => [],
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('does not purge the edge cache on a status-only update (CACHE-2)', function () {
    $user = makePlatformUser('cache2-status');
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'cache2-status',
    ]);

    Queue::fake();
    // 'tiktok' has no registered design factor and isn't an outside-website
    // source platform, so resolveDesignPresets() also no-ops — keeps this
    // purge-only assertion from being muddied by the preset-resolve path.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'resource_id' => 'tt-status',
        'payload' => [],
    ]);

    // Refetch: wasRecentlyCreated is a sticky per-instance flag that never
    // resets after the initial insert, so reusing $conn would spuriously
    // satisfy the guard's create-branch on every subsequent save.
    $conn = IntegrationConnection::find($conn->id);

    // Reset the fake so only the upcoming status-only update is captured —
    // the create() above legitimately purges (wasRecentlyCreated).
    Queue::fake();
    $conn->update(['last_refresh_status' => 'ok']);

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('purges the edge cache when a connection payload changes (CACHE-2)', function () {
    $user = makePlatformUser('cache2-payload');
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'cache2-payload',
    ]);

    Queue::fake();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'resource_id' => 'tt-payload',
        'payload' => ['a' => 1],
    ]);

    $conn = IntegrationConnection::find($conn->id); // see note above on wasRecentlyCreated

    Queue::fake();
    $conn->update(['payload' => ['a' => 2]]);

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
