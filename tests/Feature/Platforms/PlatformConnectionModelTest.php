<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\PlatformConnection;
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
        'account_type' => 'individual',
    ]);
}

it('persists a per-user platform connection with a jsonb payload', function () {
    $user = makePlatformUser();

    $conn = PlatformConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify',
        'resource_id' => 'brand-abc',
        'payload' => ['brandName' => 'Acme', 'productIds' => ['p1', 'p2']],
        'last_refresh_status' => 'ok',
    ]);

    $fresh = PlatformConnection::find($conn->id);

    expect($fresh->payload)->toBe(['brandName' => 'Acme', 'productIds' => ['p1', 'p2']]);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->consecutive_failures)->toBe(0);
    expect($fresh->user->is($user))->toBeTrue();
    expect($user->platformConnections()->count())->toBe(1);
});

it('soft deletes a connection', function () {
    $user = makePlatformUser('sam');
    $conn = PlatformConnection::create([
        'user_id' => $user->id,
        'platform' => 'tiktok',
        'resource_id' => 'sam-store',
        'payload' => [],
    ]);

    $conn->delete();

    expect(PlatformConnection::find($conn->id))->toBeNull();
    expect(PlatformConnection::withTrashed()->find($conn->id))->not->toBeNull();
});

it('scopes to active connections', function () {
    $user = makePlatformUser('lee');
    PlatformConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'a', 'payload' => [], 'is_active' => true]);
    PlatformConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'b', 'payload' => [], 'is_active' => false]);

    expect(PlatformConnection::active()->count())->toBe(1);
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

    PlatformConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify',
        'resource_id' => 'b1',
        'payload' => [],
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
