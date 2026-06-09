<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\ShopifyScraper;
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

// ── Eventbrite (EventbriteConnectionResource) ────────────────────────────────

it('eventbrite connect returns {url,organiser,next,upcoming} with events verbatim', function () {
    $user = platformContractUser('eb1');
    $this->mock(EventbriteScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme',
            'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => '2099-01-02T00:00:00+00:00']],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => ['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => '2099-01-02T00:00:00+00:00'],
            'upcoming' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => '2099-01-02T00:00:00+00:00']],
        ]);
});

it('eventbrite selection filters past events AND strips unknown keys', function () {
    $user = platformContractUser('eb2');
    seedPlatformConnection($user, 'eventbrite', [
        'url' => 'https://www.eventbrite.com/o/acme-1',
        'organiser' => 'Acme',
        'next' => ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
        'upcoming' => [
            ['name' => 'Past', 'endDate' => '2000-01-01T00:00:00+00:00'],
            ['name' => 'Future', 'endDate' => '2099-01-02T00:00:00+00:00'],
        ],
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/eventbrite/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => ['name' => 'Future', 'endDate' => '2099-01-02T00:00:00+00:00'],
            'upcoming' => [['name' => 'Future', 'endDate' => '2099-01-02T00:00:00+00:00']],
        ]]);
});

// ── Instagram (InstagramConnectionResource — drops internal _folder) ──────────

it('instagram selection drops the internal _folder key', function () {
    $user = platformContractUser('ig1');
    seedPlatformConnection($user, 'instagram', [
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => 'https://media.partna.au/p/profile.jpg',
        'businessCategory' => null, 'followersCount' => 10, 'postsCount' => 3,
        'mode' => 'manual', 'images' => ['https://media.partna.au/p/img-0.jpg'], 'imagesDropped' => 0,
        '_folder' => 'platforms/instagram/123', // internal — must NOT be emitted
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/instagram/selection')
        ->assertOk()
        ->json('selection');

    expect($selection)->not->toHaveKey('_folder');
    expect($selection)->toEqual([
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => 'https://media.partna.au/p/profile.jpg',
        'businessCategory' => null, 'followersCount' => 10, 'postsCount' => 3,
        'mode' => 'manual', 'images' => ['https://media.partna.au/p/img-0.jpg'], 'imagesDropped' => 0,
    ]);
});

it('instagram connectStatus ready payload drops _folder', function () {
    $user = platformContractUser('ig2');
    seedPlatformConnection($user, 'instagram', [
        'username' => 'jane', 'fullName' => 'Jane', 'profilePicUrl' => null,
        'businessCategory' => null, 'followersCount' => 0, 'postsCount' => 0,
        'mode' => 'automatic', 'images' => [], 'imagesDropped' => 0,
        '_folder' => 'platforms/instagram/123',
    ]);

    $body = actingAsUser($user)->getJson('/api/platforms/instagram/connect/status')
        ->assertOk()
        ->json();

    expect($body['status'])->toBe('ready');
    expect($body['connection'])->not->toHaveKey('_folder');
});

// ── Shopify (ShopifyBrandResource — single object + collection) ───────────────

it('shopify addBrand returns the canonical brand object shape', function () {
    $user = platformContractUser('sh1');
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'brand-1', 'name' => 'Brand', 'currency' => 'AUD',
            'favicon' => 'https://b/favicon.ico', 'logo' => 'https://b/logo.png',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shopify/brands', ['url' => 'https://b.example.com'])
        ->assertOk()
        ->assertExactJson([
            'id' => 'brand-1',
            'url' => 'https://b.example.com',
            'name' => 'Brand',
            'currency' => 'AUD',
            'favicon' => 'https://b/favicon.ico',
            'logo' => 'https://b/logo.png',
            'discountCode' => '',
            'products' => [],
        ]);
});

it('shopify brands list strips unknown per-brand keys', function () {
    $user = platformContractUser('sh2');
    seedPlatformConnection($user, 'shopify', [
        'brand-1' => [
            'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
            'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE', 'products' => [],
            '_internalRef' => 'secret', // must be stripped
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/shopify/brands')
        ->assertOk()
        ->assertExactJson(['brands' => [[
            'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
            'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE', 'products' => [],
        ]]]);
});

// ── Fresha (FreshaSelectionResource) ─────────────────────────────────────────

it('fresha selection wraps the nested selection blob and strips unknown keys', function () {
    $user = platformContractUser('fr1');
    seedPlatformConnection($user, 'fresha', [
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
            '_internal' => 'leak',
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://www.fresha.com/a/acme',
            'storeName' => 'Acme',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null],
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
        ]]);
});

it('fresha service-visibility returns the wrapped selection shape', function () {
    $user = platformContractUser('fr2');
    seedPlatformConnection($user, 'fresha', [
        'url' => 'https://www.fresha.com/a/acme',
        'selection' => [
            'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
            'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
            'hiddenServiceIds' => [],
        ],
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1'])
        ->assertJsonPath('storeName', 'Acme')
        ->assertJsonPath('employee.employeeId', 'e1');
});
