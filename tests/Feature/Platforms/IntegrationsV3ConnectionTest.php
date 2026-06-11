<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\MixcloudApi;
use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\TidalScraper;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function iv3User(string $h): User
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

// ── New shop providers through the detector chain ────────────────────────────

it('detects a Squarespace store and stores its provider + products source', function () {
    $user = iv3User('sq1');

    $this->mock(BigCartelScraper::class, fn ($m) => $m->shouldReceive('accountFromUrl')->andReturn(null));
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturn('https://hester.example');
        $m->shouldReceive('probe')->andReturn(false);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(SquarespaceScraper::class, function ($m) {
        $m->shouldReceive('discoverProductsUrl')->andReturn('https://hester.example/shop');
        $m->shouldReceive('fetchBrand')->with('https://hester.example/shop')->andReturn([
            'id' => 'hester-example', 'name' => 'Hester Store', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://hester.example'])
        ->assertOk()
        ->assertJsonPath('provider', 'squarespace')
        ->assertJsonPath('name', 'Hester Store');

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->first();
    // The discovered products-collection URL is kept privately for refreshes.
    expect($conn->payload['hester-example']['sourceUrl'])->toBe('https://hester.example/shop');
});

it('detects a Big Cartel store from its host alone', function () {
    $user = iv3User('bc9');

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('originOf')->andReturn('https://atakontu.bigcartel.com'));
    $this->mock(BigCartelScraper::class, function ($m) {
        $m->shouldReceive('accountFromUrl')->andReturn('atakontu');
        $m->shouldReceive('fetchStore')->andReturn([
            'id' => 'bigcartel-atakontu', 'name' => 'Atakontu', 'currency' => 'EUR',
            'favicon' => null, 'logo' => null, 'origin' => 'https://atakontu.bigcartel.com',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://atakontu.bigcartel.com/products'])
        ->assertOk()
        ->assertJsonPath('provider', 'bigcartel')
        ->assertJsonPath('name', 'Atakontu')
        ->assertJsonPath('currency', 'EUR');
});

// ── Media platforms ──────────────────────────────────────────────────────────

it('vimeo connect stores the profile and latest videos with embeds', function () {
    $user = iv3User('vm1');

    $videos = [
        ['itemId' => '1030868189', 'name' => 'The Last Observers', 'thumbnail' => 'https://i.vimeocdn.com/v.jpg', 'link' => 'https://vimeo.com/1030868189', 'date' => '2024-11-18 14:03:12', 'embedUrl' => 'https://player.vimeo.com/video/1030868189'],
    ];
    $this->mock(VimeoApi::class, function ($m) use ($videos) {
        $m->shouldReceive('parseSource')->andReturn(['apiPath' => 'patagonia', 'link' => 'https://vimeo.com/patagonia']);
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Patagonia', 'thumbnail' => 'https://i.vimeocdn.com/p.jpg', 'link' => 'https://vimeo.com/patagonia', 'bio' => null]);
        $m->shouldReceive('fetchVideos')->andReturn($videos);
    });

    actingAsUser($user)->postJson('/api/platforms/vimeo/connect', ['url' => 'https://vimeo.com/patagonia'])
        ->assertOk()
        ->assertJsonPath('name', 'Patagonia')
        ->assertJsonPath('latest.embedUrl', 'https://player.vimeo.com/video/1030868189')
        ->assertJsonPath('items.0.itemId', '1030868189');
});

it('twitch connect stores the og-scraped channel card', function () {
    $user = iv3User('tw1');

    $this->mock(TwitchScraper::class, function ($m) {
        $m->shouldReceive('parseLogin')->andReturn('loserfruit');
        $m->shouldReceive('fetchChannel')->andReturn([
            'login' => 'loserfruit', 'name' => 'Loserfruit', 'image' => 'https://static-cdn.jtvnw.net/a.png', 'description' => 'Streams.',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/twitch/connect', ['url' => 'twitch.tv/loserfruit'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.twitch.tv/loserfruit',
            'login' => 'loserfruit',
            'name' => 'Loserfruit',
            'image' => 'https://static-cdn.jtvnw.net/a.png',
            'description' => 'Streams.',
        ]);
});

it('mixcloud connect stores the profile feed embed and latest shows', function () {
    $user = iv3User('mx1');

    $this->mock(MixcloudApi::class, function ($m) {
        $m->shouldReceive('parseUsername')->andReturn('NTSRadio');
        $m->shouldReceive('fetchProfile')->andReturn([
            'username' => 'NTSRadio', 'name' => 'NTS Radio', 'thumbnail' => 'https://thumb.mixcloud.com/p.jpg',
            'link' => 'https://www.mixcloud.com/NTSRadio/', 'followers' => 1000,
        ]);
        $m->shouldReceive('fetchCloudcasts')->andReturn([
            ['itemId' => '/NTSRadio/show-1/', 'name' => 'Show 1', 'thumbnail' => null, 'link' => 'https://www.mixcloud.com/NTSRadio/show-1/', 'date' => null, 'embedUrl' => 'https://player-widget.mixcloud.com/?hide_cover=1&feed=x'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/mixcloud/connect', ['url' => 'https://www.mixcloud.com/NTSRadio/'])
        ->assertOk()
        ->assertJsonPath('username', 'NTSRadio')
        ->assertJsonPath('embedUrl', \App\Services\Platforms\MixcloudApi::embedUrlForFeed('/NTSRadio/'))
        ->assertJsonPath('latest.name', 'Show 1');
});

it('deezer connect stores the music-embed shape with the widget URL', function () {
    $user = iv3User('dz1');

    $this->mock(DeezerApi::class, function ($m) {
        $m->shouldReceive('parseArtistId')->andReturn('134790');
        $m->shouldReceive('fetchArtist')->andReturn([
            'name' => 'Tame Impala', 'thumbnail' => 'https://cdn.dzcdn.net/b.jpg', 'link' => 'https://www.deezer.com/artist/134790', 'fans' => 1,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/deezer/connect', ['url' => 'https://www.deezer.com/artist/134790'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.deezer.com/artist/134790',
            'name' => 'Tame Impala',
            'thumbnail' => 'https://cdn.dzcdn.net/b.jpg',
            'embedUrl' => 'https://widget.deezer.com/widget/auto/artist/134790/top_tracks',
            'link' => 'https://www.deezer.com/artist/134790',
        ]);
});

// ── Pinterest ────────────────────────────────────────────────────────────────

it('pinterest connect stores the profile and latest pins', function () {
    $user = iv3User('pn1');

    $this->mock(PinterestScraper::class, function ($m) {
        $m->shouldReceive('parseUsername')->andReturn('mockmaker');
        $m->shouldReceive('fetchProfile')->andReturn([
            'username' => 'mockmaker', 'name' => 'Mock Maker', 'image' => 'https://i.pinimg.com/me.jpg', 'followers' => 2361,
        ]);
        $m->shouldReceive('fetchPins')->andReturn([
            ['itemId' => '424605071145132476', 'thumbnail' => 'https://i.pinimg.com/564x/pin.jpg', 'link' => 'https://www.pinterest.com/pin/424605071145132476/', 'name' => null, 'date' => null],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/pinterest/connect', ['url' => 'https://au.pinterest.com/mockmaker/'])
        ->assertOk()
        ->assertJsonPath('followers', 2361)
        ->assertJsonPath('items.0.itemId', '424605071145132476');
});

// ── Profile cards ────────────────────────────────────────────────────────────

it('strava connect stores the club card with member count', function () {
    $user = iv3User('sv1');

    $this->mock(StravaClubScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.strava.com/clubs/231407');
        $m->shouldReceive('fetchClub')->andReturn([
            'name' => 'The Strava Club', 'location' => 'San Francisco, California', 'image' => null, 'description' => null, 'members' => 7081174,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/strava/connect', ['url' => 'https://www.strava.com/clubs/231407'])
        ->assertOk()
        ->assertJsonPath('members', 7081174);
});

it('google business connect stores the parsed place and 422s on junk', function () {
    $user = iv3User('gb1');

    $this->mock(GoogleBusinessService::class, function ($m) {
        $m->shouldReceive('resolve')->once()->andReturn([
            'url' => 'https://www.google.com/maps/place/Fade+Lab/@-37.81,144.96,17z', 'name' => 'Fade Lab', 'lat' => -37.81, 'lng' => 144.96,
        ]);
        $m->shouldReceive('resolve')->once()->andReturn(null);
    });

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', ['url' => 'https://maps.app.goo.gl/abc'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.google.com/maps/place/Fade+Lab/@-37.81,144.96,17z',
            'name' => 'Fade Lab',
            'address' => null,
            'lat' => -37.81,
            'lng' => 144.96,
        ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', ['url' => 'https://maps.app.goo.gl/junk'])
        ->assertStatus(422);
});

it('google business connect accepts a places-picker payload', function () {
    $user = iv3User('gb2');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJFbIJpLaxEmsRSrzMZif-DK8',
        'name' => 'The Grounds of Alexandria',
        'address' => '7a/2 Huntley St, Alexandria NSW 2015',
        'lat' => -33.9106777,
        'lng' => 151.1941376,
    ])
        ->assertOk()
        ->assertJsonPath('name', 'The Grounds of Alexandria')
        ->assertJsonPath('address', '7a/2 Huntley St, Alexandria NSW 2015')
        ->assertJsonPath('url', 'https://www.google.com/maps/search/?api=1&query=The%20Grounds%20of%20Alexandria&query_place_id=ChIJFbIJpLaxEmsRSrzMZif-DK8');

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJFbIJpLaxEmsRSrzMZif-DK8',
        'name' => 'Missing coords',
    ])->assertStatus(422);
});

// ── Lifecycle + auth ─────────────────────────────────────────────────────────

it('selection and forget work for the new platforms', function () {
    $user = iv3User('lc1');

    $this->mock(TwitchScraper::class, function ($m) {
        $m->shouldReceive('parseLogin')->andReturn('loserfruit');
        $m->shouldReceive('fetchChannel')->andReturn(['login' => 'loserfruit', 'name' => 'Loserfruit', 'image' => null, 'description' => null]);
    });

    actingAsUser($user)->postJson('/api/platforms/twitch/connect', ['url' => 'twitch.tv/loserfruit'])->assertOk();
    actingAsUser($user)->getJson('/api/platforms/twitch/selection')
        ->assertOk()
        ->assertJsonPath('selection.login', 'loserfruit');
    actingAsUser($user)->deleteJson('/api/platforms/twitch')
        ->assertOk()
        ->assertJsonPath('selection', null);
    actingAsUser($user)->getJson('/api/platforms/twitch/selection')
        ->assertOk()
        ->assertJsonPath('selection', null);
});

it('requires auth on every v3 platform route', function () {
    foreach (['vimeo', 'twitch', 'mixcloud', 'deezer', 'pinterest', 'strava', 'google-business'] as $platform) {
        $this->getJson("/api/platforms/{$platform}/selection")->assertUnauthorized();
    }
});
