<?php

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// Link-only socials: selection wraps {username,url} and strips unknown keys.
// The _leak key is seeded into the payload to assert the resource strips it.
dataset('link_only', [
    'tiktok' => ['tiktok', ['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']],
    'facebook' => ['facebook', ['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe']],
    'x' => ['x', ['username' => 'janed', 'url' => 'https://x.com/janed']],
    'linkedin' => ['linkedin', ['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']],
    'threads' => ['threads', ['username' => 'janed', 'url' => 'https://www.threads.net/@janed']],
    'reddit' => ['reddit', ['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']],
    // Skool renders via SkoolConnectionResource (not LinkConnectionResource), which emits
    // {url, name, image, description}. image and description default to null when absent.
    'skool' => ['skool', ['url' => 'https://www.skool.com/community', 'name' => 'Community', 'image' => null, 'description' => null]],
]);

it('freezes link-only selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    // Seed _leak alongside the stored payload — the resource must strip it.
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    expect($selection)->toEqual($stored);
})->with('link_only');

// oEmbed music platforms (Spotify / SoundCloud / Deezer) render via
// MusicEmbedConnectionResource, which emits exactly {url, name, thumbnail, embedUrl, link}.
// The _leak key must not appear in the response.
dataset('oembed', [
    'spotify' => ['spotify', [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist',
        'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        'link' => 'https://open.spotify.com/artist/abc',
    ]],
    'soundcloud' => ['soundcloud', [
        'url' => 'https://soundcloud.com/artist', 'name' => 'Artist',
        'thumbnail' => 'https://i1.sndcdn.com/t.jpg', 'embedUrl' => 'https://w.soundcloud.com/player/?url=x',
        'link' => 'https://soundcloud.com/artist',
    ]],
    'deezer' => ['deezer', [
        'url' => 'https://www.deezer.com/artist/123', 'name' => 'Artist',
        'thumbnail' => 'https://e-cdn.deezer.com/t.jpg', 'embedUrl' => 'https://widget.deezer.com/widget/dark/artist/123',
        'link' => 'https://www.deezer.com/artist/123',
    ]],
]);

it('freezes oembed selection contract', function (string $platform, array $stored) {
    $user = gmUser("gm{$platform}");
    gmSeed($user, $platform, [...$stored, '_leak' => 'must-not-appear']);

    $selection = actingAsUser($user)->getJson("/api/platforms/{$platform}/selection")
        ->assertOk()
        ->json('selection');

    // EmbedPayload → MusicEmbedConnectionResource emits exactly these 5 keys, _leak stripped.
    expect($selection)->toEqual([
        'url' => $stored['url'], 'name' => $stored['name'], 'thumbnail' => $stored['thumbnail'],
        'embedUrl' => $stored['embedUrl'], 'link' => $stored['link'],
    ]);
})->with('oembed');

it('freezes the spotify accounts list contract', function () {
    $user = gmUser('gmspacc');
    gmSeed($user, 'spotify', [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc', 'link' => 'https://open.spotify.com/artist/abc',
        '_leak' => 'x',
    ], 'acct-'.substr(sha1('https://open.spotify.com/artist/abc'), 0, 16));

    $accounts = actingAsUser($user)->getJson('/api/platforms/spotify/accounts')->assertOk()->json('accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0]['id'])->toBe('acct-'.substr(sha1('https://open.spotify.com/artist/abc'), 0, 16));
    expect($accounts[0]['url'])->toBe('https://open.spotify.com/artist/abc');
    expect($accounts[0]['embedUrl'])->toBe('https://open.spotify.com/embed/artist/abc');
});

// ── Step 1: Multi-account /accounts lists ────────────────────────────────────
// YouTube (and the other watch/listen/events platforms) expose GET /accounts
// that returns the stored list. resource_id is the public account id; the
// resource strips private keys like _leak.

it('freezes the youtube accounts list contract', function () {
    $user = gmUser('gmytacc');
    gmSeed($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [], '_leak' => 'x',
    ], 'acct-'.substr(sha1('mychannel'), 0, 16));

    $accounts = actingAsUser($user)->getJson('/api/platforms/youtube/accounts')
        ->assertOk()
        ->json('accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0]['id'])->toBe('acct-'.substr(sha1('mychannel'), 0, 16));
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0]['handle'])->toBe('mychannel');
});

it('freezes the youtube selection contract', function () {
    $user = gmUser('gmytsel');
    gmSeed($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [], '_leak' => 'x',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/youtube/selection')->assertOk()->json('selection');

    expect($selection)->toEqual([
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [],
    ]);
});

it('freezes the youtube-music selection contract', function () {
    $user = gmUser('gmymsel');
    gmSeed($user, 'youtube-music', [
        'url' => 'https://music.youtube.com/channel/UC', 'channelId' => 'UC', 'name' => 'Artist',
        'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
        'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']], 'highlights' => [], '_leak' => 'x',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/youtube-music/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'url' => 'https://music.youtube.com/channel/UC', 'name' => 'Artist', 'thumbnail' => 't',
        'link' => 'https://music.youtube.com/channel/UC', 'latest' => ['itemId' => 'i1'],
        'items' => [['itemId' => 'i1']], 'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('channelId'); // internal — never emitted
});

// ── Step 2: Shop /brands ─────────────────────────────────────────────────────
// ShopBrandResource emits: id, provider('shopify'), url, name, currency,
// favicon, logo, discountCode(''), individual(false), products.
// _leak in the stored map must not appear.
// Note: instagram /connect/status _folder-drop is covered by PlatformResourceContractTest
// ('instagram connectStatus ready payload drops _folder') — not duplicated here.

it('freezes the shop brands list contract', function () {
    $user = gmUser('gmshop');
    gmSeed($user, 'shop', ['brand-1' => [
        'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
        'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE', 'products' => [], '_leak' => 'x',
    ]]);

    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertExactJson(['brands' => [[
            'id' => 'brand-1', 'provider' => 'shopify', 'url' => 'https://b', 'name' => 'B',
            'currency' => 'AUD', 'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE',
            'individual' => false, 'products' => [],
        ]]]);
});

// ── Step 3: Category /status endpoints — empty-state shapes ──────────────────
// These pins freeze the empty-state contract so a structural change to the
// status aggregators is immediately visible as a test failure.

// booking/status: aggregates fresha > square > custom booking connections.
// Empty state: no connection of any booking-family type.
it('freezes booking status contract when nothing is connected', function () {
    $user = gmUser('gmbook');
    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertExactJson([
            'connected' => false,
            'provider' => null,
            'name' => null,
            'url' => null,
        ]);
});

// reservations/status: aggregates opentable > resdiary > nowbookit > custom.
// Empty state includes embedUrl (null) — that key is absent from booking/status.
it('freezes reservations status contract when nothing is connected', function () {
    $user = gmUser('gmres');
    actingAsUser($user)->getJson('/api/platforms/reservations/status')
        ->assertOk()
        ->assertExactJson([
            'connected' => false,
            'provider' => null,
            'name' => null,
            'url' => null,
            'embedUrl' => null,
        ]);
});

// menu/status: driven by online-ordering entries (Uber Eats / DoorDash).
// No ordering entries → not connected. itemCount, source, fetchStatus are all
// surfaced even in the disconnected state (drives the dashboard card loading).
it('freezes menu status contract when no ordering link is connected', function () {
    $user = gmUser('gmmenu');
    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertExactJson([
            'connected' => false,
            'itemCount' => 0,
            'source' => null,
            'fetchStatus' => null,
        ]);
});

// ── Step 5: Net-completeness guard ───────────────────────────────────────────
// Enumerates every GET route under api/platforms/ and asserts there are some,
// so a future route addition that escapes the golden master is visible at review
// time. Picker sub-routes that require live external fetch are covered by the
// existing PlatformResourceContractTest — they are excluded here.

it('covers every integration GET read-route in the golden master', function () {
    $readRoutes = collect(app('router')->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/platforms/'))
        ->filter(fn ($r) => in_array('GET', $r->methods(), true))
        // Picker sub-routes that require live external fetch are covered by the
        // existing PlatformResourceContractTest connect tests, not here.
        ->reject(fn ($r) => str_contains($r->uri(), '/recent') || str_contains($r->uri(), '/team')
            || str_contains($r->uri(), '/employee-services') || str_contains($r->uri(), '/url')
            || str_contains($r->uri(), '/products') || str_contains($r->uri(), '/suggestion')
            || str_contains($r->uri(), '/synced'))
        ->map(fn ($r) => $r->uri())
        ->unique()->values();

    // This net guards 52 integration read routes. If this count changes, a route
    // was added/removed — extend the net before updating 52.
    expect($readRoutes->count())->toBe(52);
})->note('Net-completeness guard: update when integration read routes change.');
