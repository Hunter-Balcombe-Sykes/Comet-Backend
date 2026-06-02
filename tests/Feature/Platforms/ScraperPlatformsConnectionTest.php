<?php

use App\Models\Core\Site\PlatformConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function scraperUser(string $h): User
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

it('requires auth on the eventbrite + youtube dashboard routes', function () {
    $this->getJson('/api/platforms/eventbrite/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/youtube/selection')->assertUnauthorized();
});

it('connects an Eventbrite organiser scoped to the authenticated user', function () {
    $user = scraperUser('eborg');

    $this->mock(EventbriteScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrgUrl')->andReturn('https://www.eventbrite.com/o/acme-1');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme',
            'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00']],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://www.eventbrite.com/o/acme-1'])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->exists())->toBeTrue();
});

it('connects a YouTube channel scoped to the authenticated user', function () {
    $user = scraperUser('ytuser');

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertJsonPath('handle', 'mychannel');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'youtube')->exists())->toBeTrue();
});

it('requires auth, connects per-user, and rate-limits re-connect on Instagram', function () {
    $this->getJson('/api/platforms/instagram/selection')->assertUnauthorized();

    $user = scraperUser('iguser');
    config(['services.apify.token' => 'test-token']);
    Storage::fake('media');
    Http::fake(['*' => Http::response('img-bytes', 200)]);

    $this->mock(InstagramScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->andReturn(['fullName' => 'Ig User', 'followersCount' => 10, 'postsCount' => 3]);
        $m->shouldReceive('recentCoverImages')->andReturn(['https://cdn.example/1.jpg', 'https://cdn.example/2.jpg']);
        $m->shouldReceive('profilePicUrl')->andReturn('https://cdn.example/p.jpg');
    });

    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'iguser'])
        ->assertOk()
        ->assertJsonPath('mode', 'automatic');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeTrue();

    // Pilot cost guard: an immediate second connect is rate-limited (429).
    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'iguser'])
        ->assertStatus(429);
});

it('requires auth on the fresha dashboard routes', function () {
    $this->getJson('/api/platforms/fresha/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/fresha/team')->assertUnauthorized();
});

it('reads back a per-user Fresha selection + url from the connection payload', function () {
    $user = scraperUser('fresh');
    PlatformConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme-salon',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme-salon',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1', 'displayName' => 'Jo'],
                'services' => [],
            ],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertJsonPath('selection.storeName', 'Acme')
        ->assertJsonPath('selection.employee.employeeId', 'e1');

    actingAsUser($user)->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertJsonPath('url', 'https://www.fresha.com/a/acme-salon');
});

it('requires auth on the apple dashboard routes', function () {
    $this->getJson('/api/platforms/apple/music/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/apple/podcast/selection')->assertUnauthorized();
});

it('stores Apple Music + Podcast as independent per-user connections', function () {
    $user = scraperUser('apl');

    $this->mock(AppleSearch::class, function ($m) {
        $m->shouldReceive('fetchAlbums')->andReturn([
            ['collectionId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'releaseDate' => '2026-01-01', 'link' => 'l'],
        ]);
        $m->shouldReceive('fetchEpisodes')->andReturn([
            ['trackId' => 'e1', 'name' => 'Ep', 'thumbnail' => 't', 'description' => 'd', 'link' => 'l'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/apple/music/connect', ['artist' => 'Artist'])
        ->assertOk()->assertJsonPath('name', 'Album');
    actingAsUser($user)->postJson('/api/platforms/apple/podcast/connect', ['show' => 'Show'])
        ->assertOk()->assertJsonPath('name', 'Ep');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'apple-music')->exists())->toBeTrue();
    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->exists())->toBeTrue();

    // Disconnecting Music leaves Podcast intact (independent rows).
    actingAsUser($user)->deleteJson('/api/platforms/apple/music')->assertOk();
    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'apple-music')->exists())->toBeFalse();
    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'apple-podcast')->exists())->toBeTrue();
});

it('requires auth on the shopify dashboard routes', function () {
    $this->getJson('/api/platforms/shopify/brands')->assertUnauthorized();
    $this->getJson('/api/platforms/shopify/selection')->assertUnauthorized();
});

it('adds Shopify brands per-user (one row, brand map) and caps at 5', function () {
    $user = scraperUser('shop');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('fetchBrand')->andReturnUsing(fn ($origin) => [
            'id' => md5($origin), 'name' => 'Brand', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
        ]);
    });

    foreach (['a', 'b', 'c', 'd', 'e'] as $s) {
        actingAsUser($user)->postJson('/api/platforms/shopify/brands', ['url' => "https://{$s}.example.com"])->assertOk();
    }
    // A 6th distinct brand exceeds the cap.
    actingAsUser($user)->postJson('/api/platforms/shopify/brands', ['url' => 'https://f.example.com'])
        ->assertStatus(422);

    $conn = PlatformConnection::where('user_id', $user->id)->where('platform', 'shopify')->first();
    expect($conn)->not->toBeNull();
    expect(count($conn->payload))->toBe(5);
});
