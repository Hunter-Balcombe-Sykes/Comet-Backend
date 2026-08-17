<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 8: addBrand() now upserts content.* (ShopContentWriter) for every
    // connect — the stand-in schema must exist for the shop tests below.
    setupContentTables();
});

function iv3User(string $h): User
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

// ── New shop providers through the detector chain ────────────────────────────

it('detects a Squarespace store and stores its provider + products source', function () {
    $user = iv3User('sq1');

    $this->mock(BigCartelScraper::class, fn ($m) => $m->shouldReceive('accountFromUrl')->andReturn(null));
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturn('https://hester.example');
        $m->shouldReceive('probe')->andReturn(false);
        // W9: ShopProviderDetector now calls probeMeta() instead of probe() —
        // false above means "not shopify", so this must be null too.
        $m->shouldReceive('probeMeta')->andReturn(null);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(SquarespaceScraper::class, function ($m) {
        $m->shouldReceive('discoverProductsUrl')->andReturn('https://hester.example/shop');
        // W9 Unit 4: ShopBrandIdentity::for() now calls originOf()+idFromOrigin()
        // — must agree with fetchBrand()'s id below (SquarespaceScraper::fetchBrand()
        // resolves its own id from the SAME origin).
        $m->shouldReceive('originOf')->andReturn('https://hester.example');
        $m->shouldReceive('idFromOrigin')->andReturn('hester-example');
        $m->shouldReceive('fetchBrand')->with('https://hester.example/shop')->andReturn([
            'id' => 'hester-example', 'name' => 'Hester Store', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://hester.example'])
        ->assertOk()
        ->assertJsonPath('provider', 'squarespace')
        ->assertJsonPath('name', 'Hester Store');

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->first();
    // The discovered products-collection URL is kept privately for refreshes.
    // FOUND-25: brands never lived in the connection payload. Re-home Task 7:
    // they no longer live in site.shop_brands either — addBrand() writes
    // content.storefronts, where external_ref is the old brand_id column and
    // source_url keeps its name.
    expect(DB::table('content.storefronts')->where('external_ref', 'hester-example')->value('source_url'))
        ->toBe('https://hester.example/shop');
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

it('twitch connect stores the normalized link, not a scraped card', function () {
    // Was "stores the og-scraped channel card". Phase 1.2's demotion (2026-08-16)
    // made twitch link-only: no TwitchScraper, no fetch, so connect resolves
    // through TwitchNormalizer and stores {username, url} like every other
    // link-only platform. The link and the handle survive; the card does not.
    $user = iv3User('tw1');

    actingAsUser($user)->postJson('/api/platforms/twitch/connect', ['url' => 'twitch.tv/Loserfruit'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'loserfruit',
            'url' => 'https://www.twitch.tv/loserfruit',
        ]);
});

// ── Profile cards ────────────────────────────────────────────────────────────

it('strava connect stores the normalized club link, not a member count', function () {
    // Was "stores the club card with member count". Same demotion as twitch
    // above: StravaClubScraper is no longer wired, so `members`/`location` are
    // not fetched and the club is represented by its link alone.
    $user = iv3User('sv1');

    actingAsUser($user)->postJson('/api/platforms/strava/connect', ['url' => 'https://www.strava.com/clubs/231407'])
        ->assertOk()
        ->assertExactJson([
            'username' => '231407',
            'url' => 'https://www.strava.com/clubs/231407',
        ]);
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

    // `selection.login` became `selection.username` when twitch went link-only
    // (2026-08-16) — the connect/selection/forget ROUND TRIP this test exists to
    // prove is unchanged, only the key it reads back.
    actingAsUser($user)->postJson('/api/platforms/twitch/connect', ['url' => 'twitch.tv/loserfruit'])->assertOk();
    actingAsUser($user)->getJson('/api/platforms/twitch/selection')
        ->assertOk()
        ->assertJsonPath('selection.username', 'loserfruit');
    actingAsUser($user)->deleteJson('/api/platforms/twitch')
        ->assertOk()
        ->assertJsonPath('selection', null);
    actingAsUser($user)->getJson('/api/platforms/twitch/selection')
        ->assertOk()
        ->assertJsonPath('selection', null);
});

it('requires auth on every v3 platform route', function () {
    foreach (['vimeo', 'twitch', 'strava', 'google-business'] as $platform) {
        $this->getJson("/api/platforms/{$platform}/selection")->assertUnauthorized();
    }
});
