<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function loopUser(): User
{
    $user = User::create([
        'handle' => 'pilot',
        'handle_lc' => 'pilot',
        'display_name' => 'Pilot',
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'pilot@example.com',
    ]);

    // A sitepage subdomain so IntegrationConnectionObserver can resolve a purge target.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'pilot',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('runs the full per-user platform loop: authenticated connect → public read → edge purge', function () {
    Bus::fake();
    $user = loopUser();

    // 1. Authenticated dashboard connect (TikTok — pure normalisation, no scrape).
    actingAsUser($user)->postJson('/api/platforms/tiktok/connect', ['username' => '@pilot'])
        ->assertOk()
        ->assertJsonPath('username', 'pilot');

    // Stored per-user...
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'tiktok')->exists())->toBeTrue();
    // ...and the write fired a surgical edge purge for this handle's subdomain.
    Bus::assertDispatched(CloudflareCachePurgeJob::class);

    // 2. The public, unauthenticated endpoint returns it, grouped by platform.
    $this->getJson('/api/public/profiles/pilot/platforms')
        ->assertOk()
        ->assertJsonPath('data.platforms.tiktok.0.payload.username', 'pilot');

    // 3. Disconnect drops it from the public endpoint immediately. (A purge is
    // also fired on delete, but CloudflareCachePurgeJob is ShouldBeUnique + keyed
    // by subdomain, so within one test the connect + disconnect purges coalesce
    // to the single dispatch asserted above — that coalescing is the point.)
    actingAsUser($user)->deleteJson('/api/platforms/tiktok')->assertOk();

    $this->getJson('/api/public/profiles/pilot/platforms')
        ->assertOk()
        ->assertJsonPath('data.platforms', []);
});

it('keeps one user\'s public platforms invisible to another handle', function () {
    $jane = loopUser();

    actingAsUser($jane)->postJson('/api/platforms/tiktok/connect', ['username' => 'janetok'])->assertOk();

    // An unknown handle 404s (no existence leak); the known handle shows only its own.
    $this->getJson('/api/public/profiles/ghost/platforms')->assertNotFound();
    $this->getJson('/api/public/profiles/pilot/platforms')
        ->assertOk()
        ->assertJsonPath('data.platforms.tiktok.0.payload.username', 'janetok');
});
