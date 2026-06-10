<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SkoolScraper;
use App\Services\Platforms\WooCommerceScraper;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function iv2User(string $h): User
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

// ── Shop provider detection ──────────────────────────────────────────────────

it('detects a WooCommerce store from the URL alone and stores its provider', function () {
    $user = iv2User('woo1');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(false);
    });
    $this->mock(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'store-example', 'name' => 'Organic Shop', 'currency' => null, 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://store.example'])
        ->assertOk()
        ->assertJsonPath('provider', 'woocommerce')
        ->assertJsonPath('name', 'Organic Shop');

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->first();
    expect($conn)->not->toBeNull();
    expect($conn->payload['store-example']['provider'])->toBe('woocommerce');
});

it('falls through to the generic JSON-LD provider and warms its catalog', function () {
    $user = iv2User('gen1');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => 'https://shop.example');
        $m->shouldReceive('probe')->andReturn(false);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('fetchPage')->andReturn([
            'brand' => ['id' => 'shop-example', 'name' => 'Example Ceramics', 'currency' => 'AUD', 'favicon' => null, 'logo' => null],
            'products' => [
                ['productId' => 'MUG-1', 'title' => 'Ceramic Mug', 'handle' => 'MUG-1', 'vendor' => null, 'image' => null, 'price' => '29.00', 'currency' => 'AUD', 'variantId' => 'MUG-1', 'available' => true, 'url' => 'https://shop.example/products/mug'],
            ],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://shop.example/store'])
        ->assertOk()
        ->assertJsonPath('provider', 'generic');

    // The warmed catalog means selection works without another fetchPage call.
    actingAsUser($user)->putJson('/api/platforms/shop/brands/shop-example/selection', ['productIds' => ['MUG-1']])
        ->assertOk()
        ->assertJsonPath('products.0.url', 'https://shop.example/products/mug');
});

it('rejects a URL no provider recognises with a helpful 422', function () {
    $user = iv2User('none1');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => 'https://blog.example');
        $m->shouldReceive('probe')->andReturn(false);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(GenericShopScraper::class, fn ($m) => $m->shouldReceive('fetchPage')->andReturn(null));

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://blog.example'])
        ->assertStatus(422);
});

// ── Spotify / SoundCloud (oEmbed music embeds) ───────────────────────────────

it('spotify connect canonicalises the link and stores the embed profile', function () {
    $user = iv2User('sp1');

    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturn([
            'name' => 'Coldplay', 'thumbnail' => 'https://i.scdn.co/img.jpg', 'embedUrl' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/spotify/connect', [
        'url' => 'https://open.spotify.com/intl-de/artist/4gzpq5DPGxSnKTe4SA8HAU?si=abc123',
    ])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU',
            'name' => 'Coldplay',
            'thumbnail' => 'https://i.scdn.co/img.jpg',
            // oEmbed gave no iframe — the deterministic embed URL fills in.
            'embedUrl' => 'https://open.spotify.com/embed/artist/4gzpq5DPGxSnKTe4SA8HAU',
            'link' => 'https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU',
        ]);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->exists())->toBeTrue();
});

it('soundcloud connect canonicalises the link with a widget fallback embed', function () {
    $user = iv2User('sc1');

    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturn([
            'name' => 'ODESZA', 'thumbnail' => 'https://i1.sndcdn.com/a.jpg', 'embedUrl' => null,
        ]);
    });

    $link = 'https://soundcloud.com/odesza';
    actingAsUser($user)->postJson('/api/platforms/soundcloud/connect', [
        'url' => 'https://m.soundcloud.com/ODESZA?utm=x',
    ])
        ->assertOk()
        ->assertJsonPath('url', $link)
        ->assertJsonPath('embedUrl', 'https://w.soundcloud.com/player/?url='.rawurlencode($link).'&visual=true');
});

it('rejects non-platform URLs on the music embed connects', function () {
    $user = iv2User('spbad');
    actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://example.com/artist/x'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/soundcloud/connect', ['url' => 'https://example.com/odesza'])
        ->assertStatus(422);
});

// ── Bandcamp ─────────────────────────────────────────────────────────────────

it('bandcamp connect stores the latest release tile and preserves same-page highlights', function () {
    $user = iv2User('bc1');

    $items = [
        ['itemId' => 'album-2', 'name' => 'New Record', 'thumbnail' => 'https://f4.bcbits.com/img/2.jpg', 'link' => 'https://artist.bandcamp.com/album/new'],
        ['itemId' => 'album-1', 'name' => 'Old Record', 'thumbnail' => 'https://f4.bcbits.com/img/1.jpg', 'link' => 'https://artist.bandcamp.com/album/old'],
    ];
    $this->mock(BandcampScraper::class, function ($m) use ($items) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Mock Artist', 'thumbnail' => 'https://f4.bcbits.com/img/avatar.jpg', 'items' => $items,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com/music'])
        ->assertOk()
        ->assertJsonPath('artist', 'Mock Artist')
        ->assertJsonPath('name', 'New Record')
        ->assertJsonPath('latest.itemId', 'album-2')
        ->assertJsonPath('highlights', []);

    // Pick a highlight, then reconnect the SAME page — the highlight survives.
    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-1']])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-1');
    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-1');
});

it('bandcamp connect 404s when the page has no releases', function () {
    $user = iv2User('bc2');

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'X', 'thumbnail' => null, 'items' => []]);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertStatus(404);
});

// ── Humanitix ────────────────────────────────────────────────────────────────

it('humanitix connect stores the host events and selection drops elapsed ones', function () {
    $user = iv2User('hx1');

    $future = now()->addMonth()->toIso8601String();
    $past = now()->subMonth()->toIso8601String();
    $this->mock(HumanitixScraper::class, function ($m) use ($future, $past) {
        $m->shouldReceive('resolveHostUrl')->andReturn('https://events.humanitix.com/host/acme');
        $m->shouldReceive('fetchEvents')->andReturn([
            'organiser' => 'Acme Events',
            'events' => [
                ['name' => 'Soon', 'venue' => null, 'location' => null, 'startDate' => $future, 'endDate' => null, 'price' => null, 'availability' => null, 'image' => null, 'link' => 'https://events.humanitix.com/soon'],
                // A stale event that slipped into storage (e.g. scraped just before it ended).
                ['name' => 'Done', 'venue' => null, 'location' => null, 'startDate' => $past, 'endDate' => $past, 'price' => null, 'availability' => null, 'image' => null, 'link' => 'https://events.humanitix.com/done'],
            ],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/humanitix/connect', ['url' => 'https://events.humanitix.com/host/acme'])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme Events')
        ->assertJsonPath('next.name', 'Soon');

    // READ-time self-clean: the elapsed event is dropped from the selection.
    $selection = actingAsUser($user)->getJson('/api/platforms/humanitix/selection')
        ->assertOk()
        ->json('selection');
    expect(collect($selection['upcoming'])->pluck('name')->all())->toBe(['Soon']);
});

// ── Skool ────────────────────────────────────────────────────────────────────

it('skool connect stores the og-scraped community card', function () {
    $user = iv2User('sk1');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/mock-community');
        $m->shouldReceive('fetchCommunity')->andReturn([
            'url' => 'https://www.skool.com/mock-community',
            'name' => 'Mock Community',
            'image' => 'https://assets.skool.com/x.jpg',
            'description' => 'A community.',
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/mock-community'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.skool.com/mock-community',
            'name' => 'Mock Community',
            'image' => 'https://assets.skool.com/x.jpg',
            'description' => 'A community.',
        ]);
});

it('skool connect 404s when the community cannot be read', function () {
    $user = iv2User('sk2');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.skool.com/private');
        $m->shouldReceive('fetchCommunity')->andReturn(null);
    });

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/private'])
        ->assertStatus(404);
});

// ── External link platforms (Ticketek / Square / Timely) ────────────────────

it('stores ticketek, square, and timely as validated link cards', function () {
    $user = iv2User('lk1');

    actingAsUser($user)->postJson('/api/platforms/ticketek/connect', [
        'url' => 'https://premier.ticketek.com.au/shows/show.aspx?sh=MOCKTOUR26',
        'label' => 'World Tour 2026',
    ])
        ->assertOk()
        ->assertExactJson(['url' => 'https://premier.ticketek.com.au/shows/show.aspx?sh=MOCKTOUR26', 'label' => 'World Tour 2026']);

    actingAsUser($user)->postJson('/api/platforms/square/connect', [
        'url' => 'https://book.squareup.com/appointments/abc123/location',
    ])
        ->assertOk()
        ->assertExactJson(['url' => 'https://book.squareup.com/appointments/abc123/location', 'label' => null]);

    actingAsUser($user)->postJson('/api/platforms/timely/connect', [
        'url' => 'https://book.gettimely.com/Booking/Location/238925',
    ])
        ->assertOk()
        ->assertJsonPath('url', 'https://book.gettimely.com/Booking/Location/238925');

    foreach (['ticketek', 'square', 'timely'] as $platform) {
        expect(IntegrationConnection::where('user_id', $user->id)->where('platform', $platform)->exists())->toBeTrue();
    }
});

it('rejects link-card URLs on the wrong host', function () {
    $user = iv2User('lk2');

    actingAsUser($user)->postJson('/api/platforms/ticketek/connect', ['url' => 'https://ticketmaster.com/x'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => 'https://calendly.com/x'])
        ->assertStatus(422);
    actingAsUser($user)->postJson('/api/platforms/timely/connect', ['url' => 'https://gettimely.com/'])
        ->assertStatus(422);
});

// ── Auth gates on the new routes ─────────────────────────────────────────────

it('requires auth on every new platform route', function () {
    foreach (['shop/brands', 'spotify/selection', 'soundcloud/selection', 'bandcamp/selection', 'humanitix/selection', 'skool/selection', 'ticketek/selection', 'square/selection', 'timely/selection'] as $path) {
        $this->getJson("/api/platforms/{$path}")->assertUnauthorized();
    }
});
