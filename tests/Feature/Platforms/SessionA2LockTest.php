<?php

// PWL-3 / PWL-4: AppleController and EventsPlatformController (Eventbrite +
// Humanitix) wrote to site.platform_connections without taking
// ManagesIntegrationConnection::withConnectionLock() on most paths — only
// AppleController::highlightsFor() and EventsPlatformController::removeEvent()
// locked before this fix. Those unlocked writers raced each other, a sibling
// locked writer, and ScheduledRefresh (which always locks), risking a
// last-write-wins clobber of a just-saved row.
//
// Mirrors PlatformConnectionLockConvergenceTest.php's proof shape: pre-acquire
// the SAME key formula CacheKeyGenerator::platformConnectionLock($platform,
// $userId) a concurrent writer would hold, then prove each newly-wrapped
// method genuinely contends on it (423, ~5s block) instead of sailing through
// and clobbering the row underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real in-process
// ArrayLock — the block(5, ...) wait in withConnectionLock() is genuine wall
// time, not mocked. That wall-clock cost per test IS the proof.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function sessionA2User(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── Apple (PWL-3) ─────────────────────────────────────────────────────────

it('apple music connect (writeAccountConnection write in connectFor) is blocked by a held platform lock and leaves the existing row untouched', function () {
    $user = sessionA2User('aplock1');
    $rid = 'acct-'.substr(sha1('artist'), 0, 16);
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => $rid,
        'canonical_key' => 'artist',
        'payload' => ['input' => 'Artist', 'name' => 'Original Album', 'highlights' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // AppleSearch::fetchAlbums is the network call — it stays outside the lock
    // in connectFor(), so it must be mocked or this test would hit the real
    // iTunes Search API regardless of the pre-held lock.
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => 'a1', 'name' => 'Changed By Connect', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('apple-music', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves the write never got past the lock.
    expect($existing->fresh()->payload['name'])->toBe('Original Album');
});

it('apple forgetMusic (forgetAllConnections write in forgetFor) is blocked by a held platform lock and the row survives', function () {
    $user = sessionA2User('aplock2');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => 'apple-music',
        'payload' => ['input' => 'Artist', 'name' => 'Album', 'highlights' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('apple-music', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/apple/music')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

it('apple removeMusicAccount (forgetConnection write in removeAccountFor) is blocked by a held platform lock and the account survives', function () {
    $user = sessionA2User('aplock3');
    $rid = 'acct-'.substr(sha1('artist'), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'apple-music',
        'resource_id' => $rid,
        'canonical_key' => 'artist',
        'payload' => ['input' => 'Artist', 'name' => 'Album', 'highlights' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('apple-music', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson("/api/platforms/apple/music/accounts/{$rid}")->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

// ── Events: Eventbrite (PWL-4, shared EventsPlatformController base) ───────

it('eventbrite connect (writeAccountConnection write in addAccount) is blocked by a held platform lock and leaves the existing row untouched', function () {
    $user = sessionA2User('eblock1');
    $url = 'https://www.eventbrite.com/o/acme-1';
    $rid = 'acct-'.substr(sha1($url), 0, 16);
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => $rid,
        'canonical_key' => $url,
        'payload' => ['url' => $url, 'organiser' => 'Original Organiser', 'upcoming' => [], 'hiddenEventIds' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // EventbriteScraper::fetchEvents is the scrape — stays outside the lock in
    // addAccount(), so it must be mocked regardless of the pre-held lock.
    $this->mock(EventbriteScraper::class, function ($m) use ($url) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn($url);
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Changed By Connect',
            'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'link' => 'https://www.eventbrite.com/e/1']],
        ]);
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    expect($existing->fresh()->payload['organiser'])->toBe('Original Organiser');
});

it('eventbrite addEvent (writeConnection write in addStandaloneEvent) is blocked by a held platform lock and no row is created', function () {
    $user = sessionA2User('eblock2');
    $eventUrl = 'https://www.eventbrite.com/e/some-event';

    $this->mock(EventbriteScraper::class, function ($m) use ($eventUrl) {
        $m->shouldReceive('normalizeEventUrl')->andReturn($eventUrl);
        $m->shouldReceive('fetchSingleEvent')->andReturn([
            'name' => 'Some Event', 'startDate' => '2099-01-01T00:00:00+00:00', 'link' => $eventUrl,
        ]);
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/eventbrite/events', ['url' => $eventUrl])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    expect(
        IntegrationConnection::where('user_id', $user->id)
            ->where('platform', 'eventbrite')
            ->where('resource_kind', 'event')
            ->exists()
    )->toBeFalse();
});

it('eventbrite removeAccount (forgetConnection write) is blocked by a held platform lock and the account survives', function () {
    $user = sessionA2User('eblock3');
    $url = 'https://www.eventbrite.com/o/acme-3';
    $rid = 'acct-'.substr(sha1($url), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => $rid,
        'canonical_key' => $url,
        'payload' => ['url' => $url, 'organiser' => 'Acme', 'upcoming' => [], 'hiddenEventIds' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson("/api/platforms/eventbrite/accounts/{$rid}")->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

it('eventbrite forget (forgetAllConnections write) is blocked by a held platform lock and the row survives', function () {
    $user = sessionA2User('eblock4');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'eventbrite',
        'payload' => ['url' => 'https://www.eventbrite.com/o/acme-4', 'organiser' => 'Acme', 'upcoming' => [], 'hiddenEventIds' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/eventbrite')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});
