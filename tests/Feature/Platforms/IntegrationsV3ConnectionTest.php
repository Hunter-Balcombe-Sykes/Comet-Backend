<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\BooksyScraper;
use App\Services\Platforms\CalendlyApi;
use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\MixcloudApi;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\PodcastFeedService;
use App\Services\Platforms\QuandooScraper;
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
            'embedUrl' => 'https://widget.deezer.com/widget/auto/artist/134790',
            'link' => 'https://www.deezer.com/artist/134790',
        ]);
});

it('tidal connect resolves the oEmbed player and og meta', function () {
    $user = iv3User('td1');

    $this->mock(TidalScraper::class, function ($m) {
        $m->shouldReceive('parseEntity')->andReturn(['type' => 'album', 'id' => '77640617', 'link' => 'https://tidal.com/browse/album/77640617']);
        $m->shouldReceive('fetchMeta')->andReturn(['name' => 'Currents', 'thumbnail' => 'https://resources.tidal.com/c.jpg']);
    });
    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturn(['name' => null, 'thumbnail' => null, 'embedUrl' => 'https://embed.tidal.com/albums/77640617']);
    });

    actingAsUser($user)->postJson('/api/platforms/tidal/connect', ['url' => 'https://tidal.com/browse/album/77640617'])
        ->assertOk()
        ->assertJsonPath('name', 'Currents')
        ->assertJsonPath('embedUrl', 'https://embed.tidal.com/albums/77640617');
});

// ── Podcast + Pinterest ──────────────────────────────────────────────────────

it('podcast connect stores the show and episodes, keeping pageUrl when discovered', function () {
    $user = iv3User('pd1');

    $this->mock(PodcastFeedService::class, function ($m) {
        $m->shouldReceive('fetchFromInput')->andReturn([
            'feedUrl' => 'https://feeds.example/show.rss',
            'show' => ['name' => 'Mock Show', 'thumbnail' => 'https://feeds.example/a.jpg', 'description' => 'A show.', 'link' => 'https://mockshow.example'],
            'episodes' => [
                ['itemId' => 'ep-2', 'title' => 'Episode 2', 'date' => '2026-06-08T10:00:00+00:00', 'duration' => '31 min', 'audioUrl' => 'https://t.example/2.mp3', 'link' => 'https://mockshow.example/2'],
            ],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/podcast/connect', ['url' => 'https://www.buzzsprout.com/231452'])
        ->assertOk()
        ->assertJsonPath('url', 'https://feeds.example/show.rss')
        ->assertJsonPath('pageUrl', 'https://www.buzzsprout.com/231452')
        ->assertJsonPath('latest.audioUrl', 'https://t.example/2.mp3');
});

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

// ── Booking / profile cards ──────────────────────────────────────────────────

it('booksy connect stores the rated business card', function () {
    $user = iv3User('bk1');

    $this->mock(BooksyScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://booksy.com/en-au/12345_fade-lab_barber-shop_30748_sydney');
        $m->shouldReceive('fetchBusiness')->andReturn([
            'name' => 'Fade Lab', 'image' => 'https://booksy.com/i.jpg', 'rating' => 4.97, 'reviewCount' => 232, 'address' => '12 Side St, Sydney',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/booksy/connect', ['url' => 'https://booksy.com/en-au/12345_fade-lab_barber-shop_30748_sydney'])
        ->assertOk()
        ->assertJsonPath('rating', 4.97)
        ->assertJsonPath('reviewCount', 232);
});

it('calendly connect stores the profile with bookable event types', function () {
    $user = iv3User('cl1');

    $this->mock(CalendlyApi::class, function ($m) {
        $m->shouldReceive('parseSlug')->andReturn('mock-pt');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock PT', 'image' => null, 'description' => 'Book a session.']);
        $m->shouldReceive('fetchEventTypes')->andReturn([
            ['name' => 'PT Session', 'slug' => 'pt-session', 'description' => '45 min'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/calendly/connect', ['url' => 'https://calendly.com/mock-pt'])
        ->assertOk()
        ->assertJsonPath('url', 'https://calendly.com/mock-pt')
        ->assertJsonPath('eventTypes.0.slug', 'pt-session');
});

it('quandoo connect stores the restaurant card with its /6 rating scale', function () {
    $user = iv3User('qd1');

    $this->mock(QuandooScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.quandoo.com.au/place/mazzaro-12350');
        $m->shouldReceive('fetchRestaurant')->andReturn([
            'name' => 'Mazzaro', 'image' => null, 'rating' => 5.5, 'bestRating' => 6, 'reviewCount' => 173,
            'cuisines' => ['Mediterranean', 'Italian'], 'address' => '271 Elizabeth St, Sydney',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/quandoo/connect', ['url' => 'https://www.quandoo.com.au/place/mazzaro-12350'])
        ->assertOk()
        ->assertJsonPath('rating', 5.5)
        ->assertJsonPath('bestRating', 6);
});

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
            'lat' => -37.81,
            'lng' => 144.96,
        ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', ['url' => 'https://maps.app.goo.gl/junk'])
        ->assertStatus(422);
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
    foreach (['vimeo', 'twitch', 'mixcloud', 'deezer', 'tidal', 'podcast', 'pinterest', 'booksy', 'calendly', 'quandoo', 'strava', 'google-business'] as $platform) {
        $this->getJson("/api/platforms/{$platform}/selection")->assertUnauthorized();
    }
});
