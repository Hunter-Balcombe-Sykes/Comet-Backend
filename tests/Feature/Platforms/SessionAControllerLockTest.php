<?php

// PWL-1 / PWL-2 — before this fix, GoogleBusinessController::connect()/
// applySync()/forget() and GenericPlatformController::connect()/removeAccount()/
// forget() wrote a platform_connections row WITHOUT taking
// ManagesIntegrationConnection::withConnectionLock(), even though the
// background writers that race them (GoogleBusinessEnrichJob::persist(),
// ConnectFetchJob, ScheduledRefresh) already lock on the exact same
// CacheKeyGenerator::platformConnectionLock($platform, $userId) key. A
// dashboard save landing while one of those jobs was mid-cycle (or vice versa)
// could silently clobber the other's write (last-write-wins on the JSONB
// payload column).
//
// Mirrors the harness in PlatformConnectionLockConvergenceTest.php /
// HighlightsLockBoundaryTest.php: pre-acquire the exact platform-wide lock key
// a real background writer would hold, hit the HTTP endpoint, and assert BOTH
// the 423 status AND that the stored row's data never changed — never just
// that "a lock exists". CACHE_STORE=array in phpunit.xml, so Cache::lock()
// here is a REAL ArrayLock — the ~5s wall time on the two lock-contention
// tests below is block(5) genuinely blocking before timing out; that wall
// cost IS the proof, not a shortcut to skip.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// HELPER COLLISION WARNING: Pest loads every test file into one process —
// this helper is uniquely prefixed (sac* = Session A Controller) to avoid
// colliding with plcUser()/dcUser()/hlbUser()/gbEnrichUser() etc. declared
// elsewhere under tests/Feature/Platforms.
function sacUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// ── PWL-1: GoogleBusinessController ─────────────────────────────────────────

it('POST /platforms/google-business/connect returns 423 and leaves the row untouched when the platform lock is held (PWL-1)', function () {
    $user = sacUser('gbwl1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['url' => 'https://maps.google.com/?cid=1', 'placeId' => 'ChIJoriginal', 'name' => 'Original Business'],
        'place_id' => 'ChIJoriginal',
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    // No services.google_maps.server_api_key configured in tests, so
    // fetchPlaceDetails() (which runs BEFORE the lock, deliberately — an
    // external fetch must never sit inside the bottleneck) short-circuits to
    // null with zero HTTP calls. apify token is likewise unset, so $enrich is
    // false and GoogleBusinessEnrichJob is never dispatched — this test only
    // has to prove the lock wrap, not the job interaction.
    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
            'placeId' => 'ChIJnew',
            'name' => 'New Business',
            'lat' => -37.0,
            'lng' => 144.0,
        ])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // Unchanged — proves the write never got past the lock, not merely that
    // a lock object exists somewhere.
    $fresh = $connection->fresh();
    expect($fresh->payload['name'])->toBe('Original Business');
    expect($fresh->payload['placeId'])->toBe('ChIJoriginal');
    expect($fresh->place_id)->toBe('ChIJoriginal');
});

it('DELETE /platforms/google-business returns 423 and leaves the connection undeleted when the platform lock is held (PWL-1)', function () {
    $user = sacUser('gbwl2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['url' => 'https://maps.google.com/?cid=2', 'placeId' => 'ChIJkeep', 'name' => 'Keep Me'],
        'place_id' => 'ChIJkeep',
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/google-business')
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // Still present and not soft-deleted — forgetAllConnections() never ran.
    expect(IntegrationConnection::withoutTrashed()->find($connection->id))->not->toBeNull();
});

it('POST /platforms/google-business/synced/apply runs applyFinding OUTSIDE the lock, and returns 423 leaving the finding unflipped when the lock is held (PWL-1 applySync)', function () {
    $user = sacUser('gbwl3');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'placeId' => 'ChIJapply',
            'name' => 'Apply Business',
            'syncFindings' => [
                [
                    'platform' => 'instagram',
                    'resourceId' => 'instagram',
                    'category' => 'social',
                    'label' => 'Instagram',
                    'foundUrl' => 'https://instagram.com/applybiz',
                    'outcome' => 'conflict',
                    'apply' => ['remove' => ['instagram']],
                ],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    // applyFinding() is the FOREIGN/slow half — it writes OTHER platforms'
    // rows and (for a real Instagram conflict) can dispatch a live Apify
    // scrape inline under QUEUE_CONNECTION=sync. Mocked as a no-op spy so
    // this test proves the restructured CONTROL FLOW — that it runs before
    // the lock is even taken — without touching the real Instagram/Apify
    // path. Bound into the container so the controller's method-injected
    // instance IS this mock.
    $this->mock(GoogleBusinessAutoSync::class, function ($m) {
        $m->shouldReceive('applyFinding')->once();
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'instagram'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // The mock's ->once() expectation (verified at test teardown) proves
    // applyFinding ran despite the lock being held throughout the request —
    // i.e. it executes OUTSIDE withConnectionLock, not blocked by it.

    // But the final re-read→flip→write DID block: the stored finding must
    // still read 'conflict', never 'seeded' — proving the write never landed.
    $fresh = $connection->fresh();
    expect($fresh->payload['syncFindings'][0]['outcome'])->toBe('conflict');
});

// ── PWL-2: GenericPlatformController ────────────────────────────────────────

it('POST /platforms/bandcamp/connect (multi-account) returns 423 and leaves the row untouched when the platform lock is held (PWL-2)', function () {
    $user = sacUser('genwl1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-genwl1',
        'canonical_key' => 'https://someartist.bandcamp.com',
        'payload' => ['url' => 'https://someartist.bandcamp.com', 'artist' => 'Original Artist'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    // The vendor scrape (ConnectResolver -> BandcampConnect strategy) runs
    // BEFORE the lock is acquired — same fetch-outside-lock discipline as
    // every other writer in this file — so it must still succeed even though
    // the eventual write is blocked. BandcampConnect::resolve() requires at
    // least one item (an empty list 404s before ever reaching the lock) and
    // always calls enrichPrices() on the latest tile.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://someartist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Changed By Connect',
            'thumbnail' => null,
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('bandcamp', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://someartist.bandcamp.com'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // Unchanged, and no second (or replacement) row was sneaked in — the
    // whole read(preserveHighlights)->write(writeAccountConnection) cycle
    // never ran.
    expect($connection->fresh()->payload['artist'])->toBe('Original Artist');
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->count())->toBe(1);
});

it('DELETE /platforms/bandcamp/accounts/{id} returns 423 and leaves the account undeleted when the platform lock is held (PWL-2)', function () {
    $user = sacUser('genwl2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-genwl2',
        'canonical_key' => 'https://anotherartist.bandcamp.com',
        'payload' => ['url' => 'https://anotherartist.bandcamp.com', 'artist' => 'Still Here'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('bandcamp', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/bandcamp/accounts/acct-genwl2')
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // The pre-check (account exists?) runs OUTSIDE the lock and passes, but
    // the actual delete is wrapped — the row must still be there afterward.
    $fresh = $connection->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->payload['artist'])->toBe('Still Here');
});
