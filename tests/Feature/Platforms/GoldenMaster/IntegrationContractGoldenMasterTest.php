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

it('freezes the bandcamp selection contract', function () {
    $user = gmUser('gmbcsel');
    gmSeed($user, 'bandcamp', [
        'url' => 'https://artist.bandcamp.com',
        'artist' => 'Artist',
        'name' => 'Album Name',
        'thumbnail' => 'https://f4.bcbits.com/img/t.jpg',
        'link' => 'https://artist.bandcamp.com/album/test',
        'latest' => ['name' => 'Album Name', 'thumbnail' => 'https://f4.bcbits.com/img/t.jpg', 'link' => 'https://artist.bandcamp.com/album/test'],
        'highlights' => [],
        '_leak' => 'must-not-appear',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/bandcamp/selection')->assertOk()->json('selection');

    // BandcampConnectionResource emits exactly these 7 keys; _leak must be stripped.
    expect($sel)->toEqual([
        'url' => 'https://artist.bandcamp.com',
        'artist' => 'Artist',
        'name' => 'Album Name',
        'thumbnail' => 'https://f4.bcbits.com/img/t.jpg',
        'link' => 'https://artist.bandcamp.com/album/test',
        'latest' => ['name' => 'Album Name', 'thumbnail' => 'https://f4.bcbits.com/img/t.jpg', 'link' => 'https://artist.bandcamp.com/album/test'],
        'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('_leak');
});

it('freezes the vimeo selection contract', function () {
    $user = gmUser('gmvimsel');
    gmSeed($user, 'vimeo', [
        'url' => 'https://vimeo.com/pat', 'apiPath' => 'pat', 'name' => 'Pat',
        'thumbnail' => 't', 'link' => 'https://vimeo.com/pat',
        'latest' => ['itemId' => 'v1'], 'items' => [['itemId' => 'v1']], 'highlights' => [], '_leak' => 'x',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/vimeo/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'url' => 'https://vimeo.com/pat', 'name' => 'Pat', 'thumbnail' => 't',
        'link' => 'https://vimeo.com/pat', 'latest' => ['itemId' => 'v1'],
        'items' => [['itemId' => 'v1']], 'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('apiPath'); // internal — never emitted
});

// Twitch is a card platform (embed is built sitepage-side); selection emits exactly
// {url, login, name, image, description}. Served by GenericPlatformController via
// FeedPayload → TwitchConnectionResource after the Task 6 $migratedReads migration.
it('freezes the twitch selection contract', function () {
    $user = gmUser('gmtwsel');
    gmSeed($user, 'twitch', [
        'url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer',
        'name' => 'StreamerName', 'image' => 'https://static-cdn.jtvnw.net/avatar.jpg',
        'description' => 'Gaming channel', '_leak' => 'must-not-appear',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/twitch/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer',
        'name' => 'StreamerName', 'image' => 'https://static-cdn.jtvnw.net/avatar.jpg',
        'description' => 'Gaming channel',
    ]);
    expect($sel)->not->toHaveKey('_leak');
});

it('freezes the twitch accounts list contract', function () {
    $user = gmUser('gmtwacc');
    gmSeed($user, 'twitch', [
        'url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer',
        'name' => 'StreamerName', 'image' => 'https://static-cdn.jtvnw.net/avatar.jpg',
        'description' => 'Gaming channel', '_leak' => 'x',
    ], 'acct-'.substr(sha1('streamer'), 0, 16));

    $accounts = actingAsUser($user)->getJson('/api/platforms/twitch/accounts')->assertOk()->json('accounts');

    expect($accounts)->toHaveCount(1);
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0]['id'])->toBe('acct-'.substr(sha1('streamer'), 0, 16));
    expect($accounts[0]['url'])->toBe('https://www.twitch.tv/streamer');
    expect($accounts[0]['login'])->toBe('streamer');
});

// Pinterest is a single-account feed platform (no /accounts). After the Task 7
// migration to $migratedReads (multi=false), selection is served by
// GenericPlatformController via FeedPayload → PinterestConnectionResource.
it('freezes the pinterest selection contract', function () {
    $user = gmUser('gmpisel');
    $pin = ['itemId' => 'p1', 'thumbnail' => 'https://i.pinimg.com/564x/p1.jpg', 'link' => 'https://www.pinterest.com/pin/1/', 'name' => 'Pin One', 'date' => '2026-03-03T00:00:00+00:00'];
    gmSeed($user, 'pinterest', [
        'url' => 'https://www.pinterest.com/pinner/',
        'username' => 'pinner',
        'name' => 'Pinner',
        'image' => 'https://i.pinimg.com/avatars/p.jpg',
        'followers' => 500,
        'latest' => $pin,
        'items' => [$pin],
        '_leak' => 'must-not-appear',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/pinterest/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'url' => 'https://www.pinterest.com/pinner/',
        'username' => 'pinner',
        'name' => 'Pinner',
        'image' => 'https://i.pinimg.com/avatars/p.jpg',
        'followers' => 500,
        'latest' => $pin,
        'items' => [$pin],
    ]);
    expect($sel)->not->toHaveKey('_leak');
});

// Apple Music / Apple Podcast selection pins. Both are multi-account tile platforms;
// after Task 8 the GET selection/accounts routes are served by GenericPlatformController
// (platform=apple-music / apple-podcast) via FeedPayload → the platform's resource.
// Routes live at /api/platforms/apple/music/selection and /podcast/selection.
// Music header: {input,name,thumbnail,releaseDate,link,latest,highlights}.
// Podcast adds description between thumbnail and releaseDate.
it('freezes the apple-music selection contract', function () {
    $user = gmUser('gmamsel');
    $album = ['collectionId' => 'c1', 'name' => 'The Tortured Poets Department', 'thumbnail' => 'https://is1-ssl.mzstatic.com/t.jpg', 'releaseDate' => '2024-04-19T00:00:00+00:00', 'link' => 'https://music.apple.com/au/album/1'];
    gmSeed($user, 'apple-music', [
        'input' => 'Taylor Swift',
        'name' => 'The Tortured Poets Department',
        'thumbnail' => 'https://is1-ssl.mzstatic.com/t.jpg',
        'releaseDate' => '2024-04-19T00:00:00+00:00',
        'link' => 'https://music.apple.com/au/album/1',
        'latest' => $album,
        'highlights' => [],
        '_leak' => 'must-not-appear',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/apple/music/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'input' => 'Taylor Swift',
        'name' => 'The Tortured Poets Department',
        'thumbnail' => 'https://is1-ssl.mzstatic.com/t.jpg',
        'releaseDate' => '2024-04-19T00:00:00+00:00',
        'link' => 'https://music.apple.com/au/album/1',
        'latest' => $album,
        'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('_leak');
});

it('freezes the apple-podcast selection contract', function () {
    $user = gmUser('gmapsel');
    $episode = ['trackId' => 'e1', 'name' => 'Dr. Andrew Huberman', 'thumbnail' => 'https://is1-ssl.mzstatic.com/ep.jpg', 'description' => 'Science-based tools for everyday life.', 'releaseDate' => '2026-03-01T00:00:00+00:00', 'link' => 'https://podcasts.apple.com/au/podcast/1'];
    gmSeed($user, 'apple-podcast', [
        'input' => 'Huberman Lab',
        'name' => 'Dr. Andrew Huberman',
        'thumbnail' => 'https://is1-ssl.mzstatic.com/ep.jpg',
        'description' => 'Science-based tools for everyday life.',
        'releaseDate' => '2026-03-01T00:00:00+00:00',
        'link' => 'https://podcasts.apple.com/au/podcast/1',
        'latest' => $episode,
        'highlights' => [],
        '_leak' => 'must-not-appear',
    ]);

    $sel = actingAsUser($user)->getJson('/api/platforms/apple/podcast/selection')->assertOk()->json('selection');

    expect($sel)->toEqual([
        'input' => 'Huberman Lab',
        'name' => 'Dr. Andrew Huberman',
        'thumbnail' => 'https://is1-ssl.mzstatic.com/ep.jpg',
        'description' => 'Science-based tools for everyday life.',
        'releaseDate' => '2026-03-01T00:00:00+00:00',
        'link' => 'https://podcasts.apple.com/au/podcast/1',
        'latest' => $episode,
        'highlights' => [],
    ]);
    expect($sel)->not->toHaveKey('_leak');
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
