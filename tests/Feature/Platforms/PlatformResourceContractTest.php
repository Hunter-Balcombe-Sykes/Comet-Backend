<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function platformContractUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// Seed a stored selection row for the given platform/payload.
function seedPlatformConnection(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId ?? $platform,
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

// ── Facebook / TikTok (LinkConnectionResource) ───────────────────────────────

it('facebook connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('fb1'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'jane.doe'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]);
});

it('facebook selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('fb2');
    seedPlatformConnection($user, 'facebook', [
        'username' => 'jane.doe',
        'url' => 'https://www.facebook.com/jane.doe',
        '_internal' => 'leak', // not on the allowlist
    ]);

    actingAsUser($user)->getJson('/api/platforms/facebook/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]]);
});

it('tiktok connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('tk1'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]);
});

it('tiktok selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('tk2');
    seedPlatformConnection($user, 'tiktok', [
        'username' => 'dancer',
        'url' => 'https://www.tiktok.com/@dancer',
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]]);
});

// ── YouTube (TileConnectionResource) ─────────────────────────────────────────

it('youtube connect returns the canonical tile shape with latest passed through verbatim', function () {
    $user = platformContractUser('yt1');
    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertExactJson([
            'handle' => 'mychannel',
            'name' => 'Vid',
            'description' => 'd',
            'link' => 'l',
            'thumbnail' => 't',
            'latest' => ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
            'highlights' => [],
        ]);
});

it('youtube selection strips unknown top-level keys but keeps nested latest verbatim', function () {
    $user = platformContractUser('yt2');
    seedPlatformConnection($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1', 'extraNested' => 'kept'], 'highlights' => [],
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/youtube/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
            'latest' => ['videoId' => 'v1', 'extraNested' => 'kept'],
            'highlights' => [],
        ]]);
});

// ── Apple Music + Podcast (Tile subclasses) ──────────────────────────────────

it('apple music + podcast connect return their per-platform flat fields', function () {
    $user = platformContractUser('apl1');
    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchEpisodes')->andReturn([
            ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
        ->assertOk()
        ->assertExactJson([
            'input' => 'Artist', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l',
            'latest' => ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
            'highlights' => [],
        ]);

    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Show'])
        ->assertOk()
        ->assertExactJson([
            'input' => 'Show', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l',
            'latest' => ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
            'highlights' => [],
        ]);
});
