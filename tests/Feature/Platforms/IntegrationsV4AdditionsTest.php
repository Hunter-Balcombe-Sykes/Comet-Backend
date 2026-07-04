<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\SkoolScraper;
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function iv4User(string $h): User
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

// ── Link-only socials: X / LinkedIn / Threads / Reddit ───────────────────────

it('connects the link-only socials from urls, handles, and messy pastes', function (
    string $platform,
    string $input,
    string $expectedUsername,
    string $expectedUrl,
) {
    $user = iv4User('soc'.substr(md5($platform.$input), 0, 6));

    actingAsUser($user)->postJson("/api/platforms/{$platform}/connect", ['username' => $input])
        ->assertOk()
        ->assertJsonPath('username', $expectedUsername)
        ->assertJsonPath('url', $expectedUrl);

    actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->assertJsonPath('selection.username', $expectedUsername)
        ->assertJsonPath('selection.url', $expectedUrl);
})->with([
    'x via twitter.com + query' => ['x', 'https://twitter.com/jack?ref=home', 'jack', 'https://x.com/jack'],
    'x via scheme-less deep link' => ['x', 'x.com/jack/status/12345', 'jack', 'https://x.com/jack'],
    'x via bare @handle' => ['x', '@jack', 'jack', 'https://x.com/jack'],
    'linkedin personal + trailing junk' => ['linkedin', 'linkedin.com/in/jane-doe-123/extra?utm=1', 'jane-doe-123', 'https://www.linkedin.com/in/jane-doe-123/'],
    'linkedin company on locale subdomain' => ['linkedin', 'https://au.linkedin.com/company/partna', 'partna', 'https://www.linkedin.com/company/partna/'],
    'linkedin bare slug' => ['linkedin', 'jane-doe-123', 'jane-doe-123', 'https://www.linkedin.com/in/jane-doe-123/'],
    'threads via threads.com post link' => ['threads', 'threads.com/@zuck/post/abc', 'zuck', 'https://www.threads.net/@zuck'],
    'threads bare handle' => ['threads', 'zuck', 'zuck', 'https://www.threads.net/@zuck'],
    'reddit user url + query' => ['reddit', 'https://www.reddit.com/user/spez/?utm_source=share', 'spez', 'https://www.reddit.com/user/spez/'],
    'reddit u/ shorthand' => ['reddit', 'u/spez', 'spez', 'https://www.reddit.com/user/spez/'],
    'reddit community' => ['reddit', 'reddit.com/r/laravel', 'laravel', 'https://www.reddit.com/r/laravel/'],
    'reddit bare username' => ['reddit', 'spez', 'spez', 'https://www.reddit.com/user/spez/'],
]);

it('rejects non-profile social inputs', function (string $platform, string $input) {
    $user = iv4User('socbad'.substr(md5($platform.$input), 0, 6));

    actingAsUser($user)->postJson("/api/platforms/{$platform}/connect", ['username' => $input])
        ->assertStatus(422);
})->with([
    'x reserved path' => ['x', 'https://x.com/home'],
    'x overlong handle' => ['x', 'this_handle_is_way_too_long_for_x'],
    'linkedin non-profile url' => ['linkedin', 'https://www.linkedin.com/feed/'],
    'reddit non-profile url' => ['reddit', 'https://www.reddit.com/best/'],
]);

// ── YouTube Music ─────────────────────────────────────────────────────────────

const YTM_CHANNEL = 'UCa1b2c3d4e5f6g7h8i9j0kL';

function ytmFeedXml(string $title, array $videos): string
{
    // $v = [videoId, title, published?]. <published> is the Atom upload timestamp
    // the scraper threads through as the item `date` for chronological sorting.
    $entries = implode('', array_map(fn ($v) => <<<XML
        <entry>
            <yt:videoId>{$v[0]}</yt:videoId>
            <title>{$v[1]}</title>
            <published>{$v[2]}</published>
            <media:description>desc</media:description>
        </entry>
    XML, $videos));

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/">
        <title>{$title}</title>
        <author><name>{$title}</name></author>
        {$entries}
    </feed>
    XML;
}

function fakeYtmFeed(string $title = 'Big Artist - Topic'): void
{
    Http::fake([
        'www.youtube.com/feeds/videos.xml*' => Http::response(ytmFeedXml($title, [
            ['vid00000001', 'Newest Single', '2026-03-03T10:00:00+00:00'],
            ['vid00000002', 'Older Album Cut', '2026-02-02T10:00:00+00:00'],
            ['vid00000003', 'Deep Catalogue', '2026-01-01T10:00:00+00:00'],
        ])),
        // Thumbnail probes — pretend maxres exists for every id.
        'i.ytimg.com/*' => Http::response('img', 200),
        '*' => Http::response('', 404),
    ]);
}

it('connects youtube-music from a music.youtube.com artist channel url', function () {
    fakeYtmFeed();
    $user = iv4User('ytm1');

    $res = actingAsUser($user)->postJson('/api/platforms/youtube-music/connect', [
        'url' => 'https://music.youtube.com/channel/'.YTM_CHANNEL.'?si=share',
    ])->assertOk();

    $res->assertJsonPath('url', 'https://music.youtube.com/channel/'.YTM_CHANNEL)
        // " - Topic" suffix is stripped off auto-generated artist channels.
        ->assertJsonPath('name', 'Big Artist')
        ->assertJsonPath('latest.itemId', 'vid00000001')
        ->assertJsonPath('latest.link', 'https://music.youtube.com/watch?v=vid00000001')
        ->assertJsonPath('latest.embedUrl', 'https://www.youtube.com/embed/vid00000001')
        // The Atom <published> timestamp threads through as the item date.
        ->assertJsonPath('latest.date', '2026-03-03T10:00:00+00:00')
        ->assertJsonPath('items.2.itemId', 'vid00000003')
        ->assertJsonPath('items.2.date', '2026-01-01T10:00:00+00:00');

    // channelId is the private re-fetch input — stored but never emitted.
    expect($res->json())->not->toHaveKey('channelId');
    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube-music')->first();
    expect($conn->payload['channelId'])->toBe(YTM_CHANNEL);
});

it('runs the youtube-music recent + highlights picker flow', function () {
    fakeYtmFeed();
    $user = iv4User('ytm2');

    actingAsUser($user)->postJson('/api/platforms/youtube-music/connect', [
        'url' => 'music.youtube.com/channel/'.YTM_CHANNEL,
    ])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/youtube-music/recent')
        ->assertOk()
        ->assertJsonCount(3, 'videos')
        ->assertJsonPath('videos.0.itemId', 'vid00000001')
        ->assertJsonPath('videos.0.date', '2026-03-03T10:00:00+00:00');

    // Picks save in posted order; unknown ids drop silently.
    actingAsUser($user)->postJson('/api/platforms/youtube-music/highlights', [
        'itemIds' => ['vid00000003', 'vid00000002', 'unknown-id'],
    ])->assertOk()
        ->assertJsonCount(2, 'highlights')
        ->assertJsonPath('highlights.0.itemId', 'vid00000003')
        ->assertJsonPath('highlights.1.itemId', 'vid00000002');

    // Reconnecting the SAME channel keeps the picks.
    actingAsUser($user)->postJson('/api/platforms/youtube-music/connect', [
        'url' => 'https://music.youtube.com/channel/'.YTM_CHANNEL,
    ])->assertOk()->assertJsonCount(2, 'highlights');

    // An empty save clears them.
    actingAsUser($user)->postJson('/api/platforms/youtube-music/highlights', ['itemIds' => []])
        ->assertOk()
        ->assertJsonCount(0, 'highlights');
});

it('exposes youtube-music and the socials on the public endpoint, allowlisted', function () {
    $user = iv4User('ytm3pub');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'youtube-music',
        'resource_id' => 'youtube-music',
        'payload' => [
            'url' => 'https://music.youtube.com/channel/'.YTM_CHANNEL,
            'channelId' => YTM_CHANNEL, // private
            'name' => 'Big Artist',
            'thumbnail' => null,
            'link' => 'https://music.youtube.com/channel/'.YTM_CHANNEL,
            'latest' => ['itemId' => 'vid00000001'],
            'items' => [['itemId' => 'vid00000001']],
            'highlights' => [],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'x',
        'resource_id' => 'x',
        'payload' => ['username' => 'jack', 'url' => 'https://x.com/jack'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $platforms = $this->getJson('/api/public/profiles/ytm3pub/integrations')
        ->assertOk()
        ->json('data.platforms');

    expect($platforms['youtube-music'][0]['payload'])->not->toHaveKey('channelId');
    expect($platforms['youtube-music'][0]['payload']['name'])->toBe('Big Artist');
    expect($platforms['x'][0]['payload'])->toBe(['username' => 'jack', 'url' => 'https://x.com/jack']);
});

// ── Shop: client-assisted WooCommerce connect ────────────────────────────────

function wooClientPayload(): array
{
    return [
        'root' => ['name' => 'FEAR NO EVIL', 'url' => 'https://fearnoevil.example'],
        'products' => [
            [
                'id' => 2964,
                'name' => 'Bulwark Jacket',
                'slug' => 'bulwark-jacket',
                'permalink' => 'https://fearnoevil.example/product/bulwark-jacket/',
                'images' => [['src' => 'https://fearnoevil.example/wp-content/jacket.jpg']],
                'prices' => ['price' => '19900', 'currency_code' => 'aud', 'currency_minor_unit' => 2],
                'is_in_stock' => true,
            ],
            [
                // Foreign-host permalink — must be host-pinned out.
                'id' => 999,
                'name' => 'Spoofed Product',
                'slug' => 'spoof',
                'permalink' => 'https://evil.example/product/spoof/',
                'images' => [],
                'prices' => ['price' => '100', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
            ],
        ],
    ];
}

it('connects a WAF-blocked WooCommerce store via the client-assisted payload', function () {
    // Every server-side probe is blocked — exactly the fearnoevil.com.au case.
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $user = iv4User('wooclient');

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', [
        // Scheme-less paste on purpose — normalised before the `url` rule.
        'url' => 'fearnoevil.example',
        'storeApi' => wooClientPayload(),
    ])->assertOk();

    $res->assertJsonPath('provider', 'woocommerce')
        ->assertJsonPath('name', 'FEAR NO EVIL');

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->first();
    $brand = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'fearnoevil-example')->firstOrFail();
    expect($brand->fetch_mode)->toBe('client');
    expect($brand->url)->toBe('https://fearnoevil.example');

    // The browser-fetched catalog was warmed for the picker — host-pinned, so
    // the spoofed foreign product is gone and the price is decimalised.
    $products = actingAsUser($user)->getJson('/api/platforms/shop/brands/fearnoevil-example/products')
        ->assertOk()
        ->json('products');
    expect($products)->toHaveCount(1);
    expect($products[0]['title'])->toBe('Bulwark Jacket');
    expect($products[0]['price'])->toBe('199.00');
    expect($products[0]['currency'])->toBe('AUD');

    // Selecting from the warmed catalog snapshots the product.
    actingAsUser($user)->putJson('/api/platforms/shop/brands/fearnoevil-example/selection', [
        'productIds' => ['2964'],
    ])->assertOk();
    expect($brand->fresh('products')->products->first()->product_id)->toBe('2964');
});

it('re-warms a client-mode brand catalog via the catalog endpoint', function () {
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $user = iv4User('woowarm');

    actingAsUser($user)->postJson('/api/platforms/shop/brands', [
        'url' => 'https://fearnoevil.example/',
        'storeApi' => wooClientPayload(),
    ])->assertOk();

    // Simulate the picker opening hours later: warmed cache gone.
    Cache::flush();

    actingAsUser($user)->postJson('/api/platforms/shop/brands/fearnoevil-example/catalog', [
        'products' => wooClientPayload()['products'],
    ])->assertOk()->assertJsonCount(1, 'products');

    actingAsUser($user)->getJson('/api/platforms/shop/brands/fearnoevil-example/products')
        ->assertOk()
        ->assertJsonCount(1, 'products');
});

it('still rejects an unsupported store when no client payload is supplied', function () {
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $user = iv4User('woofail');

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://blocked.example'])
        ->assertStatus(422);
});

// ── Input normalisation (pure parsers — no HTTP) ─────────────────────────────

it('normalises scheme-less and bare-token inputs across platform parsers', function () {
    expect(PlatformInput::urlish(' fearnoevil.com.au/shop '))->toBe('https://fearnoevil.com.au/shop');
    expect(PlatformInput::urlish('HTTP://Site.com/x'))->toBe('http://Site.com/x');
    expect(PlatformInput::urlish('@handle'))->toBe('@handle');
    expect(PlatformInput::urlish('plainname'))->toBe('plainname');

    expect(app(SkoolScraper::class)->normalizeUrl('skool.com/my-community?ref=1'))
        ->toBe('https://www.skool.com/my-community');
    expect(app(SkoolScraper::class)->normalizeUrl('my-community'))
        ->toBe('https://www.skool.com/my-community');

    expect(app(StravaClubScraper::class)->normalizeUrl('strava.com/clubs/run-club'))
        ->toBe('https://www.strava.com/clubs/run-club');
    expect(app(StravaClubScraper::class)->normalizeUrl('run-club'))
        ->toBe('https://www.strava.com/clubs/run-club');

    expect(app(BandcampScraper::class)->normalizeOrigin('myband.bandcamp.com/album/x'))
        ->toBe('https://myband.bandcamp.com');
    expect(app(BandcampScraper::class)->normalizeOrigin('myband'))
        ->toBe('https://myband.bandcamp.com');

    expect(app(TwitchScraper::class)->parseLogin('twitch.tv/somestreamer?x=1'))
        ->toBe('somestreamer');

    expect(app(VimeoApi::class)->parseSource('vimeo.com/jacksonharries'))
        ->toMatchArray(['apiPath' => 'jacksonharries']);
    expect(app(VimeoApi::class)->parseSource('jacksonharries'))
        ->toMatchArray(['apiPath' => 'jacksonharries']);
});
