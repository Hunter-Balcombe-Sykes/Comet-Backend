<?php

use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: GET /api/platforms/shop/brands reads content.storefronts
    // (ShopContentReader). The legacy site.shop_brands fallback it once had is
    // gone with the table — attach the stand-in schema so that read has
    // something to resolve against on SQLite.
    setupContentTables();
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
    // skool, strava and twitch joined this dataset on 2026-08-16, when Phase 1.2's
    // demotion moved them onto LinkConnectionResource. Skool used to sit here with
    // its own {url, name, image, description} shape; twitch and strava had bespoke
    // selection/accounts contracts of their own further down. All three now freeze
    // the same {username, url} pair as every other link-only platform.
    'skool' => ['skool', ['username' => 'community', 'url' => 'https://www.skool.com/community']],
    'strava' => ['strava', ['username' => 'Midday-Milers', 'url' => 'https://www.strava.com/clubs/Midday-Milers']],
    'twitch' => ['twitch', ['username' => 'streamer', 'url' => 'https://www.twitch.tv/streamer']],
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

// oEmbed music platforms (Spotify / SoundCloud) render via
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

// ── TEST-4: 6 previously unpinned /accounts endpoints ────────────────────────
//
// apple/music, apple/podcast, bandcamp, soundcloud, vimeo, youtube-music
// all route through GenericPlatformController::accountsList → shape() → resource.
// Each parametrized case seeds one row with a _leak key and asserts the exact
// full-row shape via toEqual (catches both missing and extra keys).

dataset('multi_account_unpinned', [
    // apple/music: AppleMusicConnectionResource (TileConnectionResource subclass)
    // emits {input, name, thumbnail, releaseDate, link, latest}.
    'apple-music' => [
        'apple/music',
        'apple-music',
        'acct-'.substr(sha1('Taylor Swift'), 0, 16),
        [
            'input' => 'Taylor Swift', 'name' => 'The Tortured Poets Department',
            'thumbnail' => 'https://is1-ssl.mzstatic.com/t.jpg',
            'releaseDate' => '2024-04-19T00:00:00+00:00',
            'link' => 'https://music.apple.com/au/album/1',
            'latest' => ['collectionId' => 'a1', 'name' => 'The Tortured Poets Department'],
            'highlights' => [], '_leak' => 'x',
        ],
        [
            'input' => 'Taylor Swift', 'name' => 'The Tortured Poets Department',
            'thumbnail' => 'https://is1-ssl.mzstatic.com/t.jpg',
            'releaseDate' => '2024-04-19T00:00:00+00:00',
            'link' => 'https://music.apple.com/au/album/1',
            'latest' => ['collectionId' => 'a1', 'name' => 'The Tortured Poets Department'],
        ],
    ],
    // apple/podcast: ApplePodcastConnectionResource adds `description` to the tile shape.
    'apple-podcast' => [
        'apple/podcast',
        'apple-podcast',
        'acct-'.substr(sha1('Huberman Lab'), 0, 16),
        [
            'input' => 'Huberman Lab', 'name' => 'Dr. Andrew Huberman',
            'thumbnail' => 'https://is1-ssl.mzstatic.com/ep.jpg',
            'description' => 'Science-based tools.',
            'releaseDate' => '2026-03-01T00:00:00+00:00',
            'link' => 'https://podcasts.apple.com/au/podcast/1',
            'latest' => ['trackId' => 'e1', 'name' => 'Dr. Andrew Huberman'],
            'highlights' => [], '_leak' => 'x',
        ],
        [
            'input' => 'Huberman Lab', 'name' => 'Dr. Andrew Huberman',
            'thumbnail' => 'https://is1-ssl.mzstatic.com/ep.jpg',
            'description' => 'Science-based tools.',
            'releaseDate' => '2026-03-01T00:00:00+00:00',
            'link' => 'https://podcasts.apple.com/au/podcast/1',
            'latest' => ['trackId' => 'e1', 'name' => 'Dr. Andrew Huberman'],
        ],
    ],
    // bandcamp: BandcampConnectionResource — {url, artist, name, thumbnail, link, latest}.
    'bandcamp' => [
        'bandcamp',
        'bandcamp',
        'acct-'.substr(sha1('https://artist.bandcamp.com'), 0, 16),
        [
            'url' => 'https://artist.bandcamp.com', 'artist' => 'Artist', 'name' => 'Album Name',
            'thumbnail' => 'https://f4.bcbits.com/img/t.jpg',
            'link' => 'https://artist.bandcamp.com/album/test',
            'latest' => ['name' => 'Album Name', 'link' => 'https://artist.bandcamp.com/album/test'],
            'highlights' => [], '_leak' => 'x',
        ],
        [
            'url' => 'https://artist.bandcamp.com', 'artist' => 'Artist', 'name' => 'Album Name',
            'thumbnail' => 'https://f4.bcbits.com/img/t.jpg',
            'link' => 'https://artist.bandcamp.com/album/test',
            'latest' => ['name' => 'Album Name', 'link' => 'https://artist.bandcamp.com/album/test'],
        ],
    ],
    // soundcloud: MusicEmbedConnectionResource — the shared 5-key music-embed shape.
    'soundcloud' => [
        'soundcloud',
        'soundcloud',
        'acct-'.substr(sha1('https://soundcloud.com/artist'), 0, 16),
        [
            'url' => 'https://soundcloud.com/artist', 'name' => 'Artist',
            'thumbnail' => 'https://i1.sndcdn.com/t.jpg',
            'embedUrl' => 'https://w.soundcloud.com/player/?url=x',
            'link' => 'https://soundcloud.com/artist', '_leak' => 'x',
        ],
        [
            'url' => 'https://soundcloud.com/artist', 'name' => 'Artist',
            'thumbnail' => 'https://i1.sndcdn.com/t.jpg',
            'embedUrl' => 'https://w.soundcloud.com/player/?url=x',
            'link' => 'https://soundcloud.com/artist',
        ],
    ],
    // vimeo: VimeoConnectionResource — {url, name, thumbnail, link, latest, items}.
    'vimeo' => [
        'vimeo',
        'vimeo',
        'acct-'.substr(sha1('https://vimeo.com/pat'), 0, 16),
        [
            'url' => 'https://vimeo.com/pat', 'apiPath' => 'pat', 'name' => 'Pat',
            'thumbnail' => 't', 'link' => 'https://vimeo.com/pat',
            'latest' => ['itemId' => 'v1'], 'items' => [['itemId' => 'v1']], 'highlights' => [], '_leak' => 'x',
        ],
        // apiPath is internal — not in VimeoConnectionResource.toArray(); stripped.
        [
            'url' => 'https://vimeo.com/pat', 'name' => 'Pat',
            'thumbnail' => 't', 'link' => 'https://vimeo.com/pat',
            'latest' => ['itemId' => 'v1'], 'items' => [['itemId' => 'v1']],
        ],
    ],
    // youtube-music: YoutubeMusicConnectionResource — {url, name, thumbnail, link, latest, items}.
    // channelId is internal and must not appear (asserted separately below in the selection test).
    'youtube-music' => [
        'youtube-music',
        'youtube-music',
        'acct-'.substr(sha1('https://music.youtube.com/channel/UC'), 0, 16),
        [
            'url' => 'https://music.youtube.com/channel/UC', 'channelId' => 'UC', 'name' => 'Artist',
            'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
            'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']], 'highlights' => [], '_leak' => 'x',
        ],
        // channelId is internal — YoutubeMusicConnectionResource strips it.
        [
            'url' => 'https://music.youtube.com/channel/UC', 'name' => 'Artist',
            'thumbnail' => 't', 'link' => 'https://music.youtube.com/channel/UC',
            'latest' => ['itemId' => 'i1'], 'items' => [['itemId' => 'i1']],
        ],
    ],
]);

it('freezes the multi-account /accounts contract for unpinned platforms', function (
    string $routeSegment,
    string $platform,
    string $resourceId,
    array $stored,
    array $expectedShape,
) {
    $user = gmUser("gmacc{$platform}");
    gmSeed($user, $platform, $stored, $resourceId);

    $accounts = actingAsUser($user)->getJson("/api/platforms/{$routeSegment}/accounts")
        ->assertOk()
        ->json('accounts');

    expect($accounts)->toHaveCount(1);
    // Full row shape — catches both missing and extra key leaks.
    expect($accounts[0])->not->toHaveKey('_leak');
    expect($accounts[0])->toEqual(['id' => $resourceId, ...$expectedShape]);
})->with('multi_account_unpinned');

it('freezes the youtube selection contract', function () {
    $user = gmUser('gmytsel');
    gmSeed($user, 'youtube', [
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'], 'highlights' => [], '_leak' => 'x',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/youtube/selection')->assertOk()->json('selection');

    expect($selection)->toEqual([
        'handle' => 'mychannel', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't',
        'latest' => ['videoId' => 'v1'],
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
        'items' => [['itemId' => 'i1']],
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
        'items' => [['itemId' => 'v1']],
    ]);
    expect($sel)->not->toHaveKey('apiPath'); // internal — never emitted
});

// Twitch is a card platform (embed is built sitepage-side); selection emits exactly
// {url, login, name, image, description}. Served by GenericPlatformController via
// FeedPayload → TwitchConnectionResource after the Task 6 $migratedReads migration.
// Strava is a single-account feed platform (no /accounts) — multiAccount()
// false, selection served by GenericPlatformController via FeedPayload →
// StravaConnectionResource.
// Apple Music / Apple Podcast selection pins. Both are multi-account tile platforms;
// after Task 8 the GET selection/accounts routes are served by GenericPlatformController
// (platform=apple-music / apple-podcast) via FeedPayload → the platform's resource.
// Routes live at /api/platforms/apple/music/selection and /podcast/selection.
// Music header: {input,name,thumbnail,releaseDate,link,latest}.
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
    // FOUND-25: a store is a content.collections + content.storefronts pair
    // (it was a relational site.shop_brands row until that table was dropped)
    // — fixed columns mean there's no stray key to leak, but ShopBrandResource
    // must still shape the row into exactly this contract.
    gmSeed($user, 'shopify.store', ['storage' => 'relational']);
    // Task 8: brands() reads ShopContentReader with no legacy fallback
    // (hybridBrandMap() is gone) — land this fixture in content.* the same
    // way a real addBrand() connect would.
    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: 'brand-1', provider: 'shopify',
        name: 'B', url: 'https://b', currency: 'AUD',
        discountCode: 'SAVE', logoUrl: null, faviconUrl: null,
    ), (string) $user->id);

    actingAsUser($user)->getJson('/api/platforms/shop/brands')
        ->assertOk()
        ->assertExactJson(['brands' => [[
            'id' => 'brand-1', 'provider' => 'shopify', 'url' => 'https://b', 'name' => 'B',
            'currency' => 'AUD', 'favicon' => null, 'logo' => null, 'discountCode' => 'SAVE',
            // Store link-out fields (2026-07-07): additive, defaulted. Task 8:
            // linkMode now comes from ShopContentReader (site.sites.
            // shop_link_mode) — gmUser() has no site row, so it falls to
            // Site::DEFAULT_SHOP_LINK_MODE ('checkout'), not the 'product'
            // default the retired per-brand link_mode column carried. Same
            // documented divergence as ShopEndpointParityTest's GET /brands.
            'selectionMode' => 'manual', 'autoLatest' => true, 'referralQuery' => '',
            'individual' => false, 'products' => [],
        ]]]);
});

// ── Step 3: Category /status endpoints — empty-state shapes ──────────────────
// These pins freeze the empty-state contract so a structural change to the
// status aggregators is immediately visible as a test failure.

// The booking/reservations category status pins left 2026-08-19 with their
// endpoints (pseudo-platform retirement).
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
        ->unique()->sort()->values();

    // TEST-6: Pin route IDENTITY, not just count.
    // Sorted URI snapshot — catches add/remove AND one-for-one swaps.
    // Count guard kept too for fast failure messaging.
    // Unit 11 W6: 8 new .../connect/status poll routes (bandcamp, pinterest,
    // spotify, strava, twitch, vimeo, youtube, youtube-music) — one per
    // deferred-capable platform (supportsDeferredConnect()), registered
    // regardless of the rollout flag's current value (capability, not
    // activation — see routes/api/platforms.php). 58 -> 66.
    // 5 new link-only socials (snapchat, discord, telegram, kick, medium) — each
    // contributes one .../selection GET route, same LinkOnly shape as the
    // original 6. 66 -> 71.
    // RV-8: the manual dashboard refresh button now queues RefreshConnectionJob
    // instead of blocking the request thread, so the dashboard needs a way to
    // poll for completion — GET {platform}/refresh/status returns the queued/
    // running/done state. 71 -> 72.
    // CA-W3: 2 new .../connect/status poll routes for Apple (music, podcast) —
    // added manually inside the BESPOKE apple route group (routeShape stays
    // Bespoke, so the registry loop's own supportsDeferredConnect() gate above
    // never reaches it). 72 -> 74.
    // CA-W4: 1 new .../connect/status poll route for Skool — added manually
    // inside the loop's SingleSelection branch (that branch returns before the
    // loop's own supportsDeferredConnect() gate too, and Skool has no
    // ConnectStrategy to satisfy that flag's pinned invariant anyway). 74 -> 75.
    // CA-W5: 2 new .../connect/status poll routes for Eventbrite + Humanitix —
    // added manually inside the events foreach group (bespoke, multi-account,
    // same reasoning as Apple/Skool above — neither descriptor gets
    // ->deferredConnect(), so the registry loop's own gate never reaches
    // them either). 75 -> 77.
    // CA-W6: 1 new .../connect/status poll route for Fresha (team mode only —
    // added manually inside its own bespoke route group, same reasoning as
    // Apple/Skool/Eventbrite/Humanitix above; fresha has no ConnectStrategy to
    // satisfy ->deferredConnect()'s pinned invariant). 77 -> 78.
    // W9 (Shop): 2 new BRAND-scoped .../brands/{id}/connect/status poll routes —
    // one per alias (shop + its legacy shopify alias). Brand-scoped, not
    // connection-scoped, because Shop is the only platform where one connection
    // fans out to many content rows. Registered regardless of the rollout flag
    // (capability, not activation). Does NOT touch the settled-brand shape test
    // above ("freezes the shop brands list contract"), which stays unmodified —
    // this is purely a new route appearing in the enumeration. 78 -> 80.
    // 2026-07-28: Pinterest platform decommissioned — its 2 routes
    // (.../connect/status + .../selection) are gone entirely, not migrated. 80 -> 78.
    // 2026-08-05: the legacy /platforms/shopify alias prefix was removed
    // (audit: zero callers) — its 4 GET mirrors vanish from the enumeration. 78 -> 74.
    // 2026-08-16: skool/strava/twitch demoted to link-only (Phase 1.2), so they
    // take PlatformRouteShape::LinkOnly and expose only /selection — the same
    // single route kick and medium have. Their 4 extra GETs (two
    // /connect/status, twitch's /accounts and strava's /connect/status) are
    // gone. 74 -> 70.
    // 2026-08-17 (uniformity Phase B): every connectable, URL-detected catalog
    // brand now carries the platform route contract instead of being reachable
    // only through a family controller. 67 brands take PlatformRouteShape::Brand
    // — 15 newly DERIVED from the catalog (menulog, doordash, uber_eats,
    // deliveroo, calendly, acuity, ...) and 52 hand-written descriptors UPGRADED
    // in place: they were shaped Bespoke, which the loop reads as "keeps its own
    // standalone group", but no such group was ever written for them, so booksy,
    // resy, vagaro, ticketek, patreon and their kind had no routes at all. Each
    // contributes one /selection GET. 70 -> 136.
    //
    // Storefront brands (shopify, woocommerce, squarespace, bigcartel) are
    // deliberately absent: registering 'shopify' would steal the family fallback
    // in PlatformRegistry::forConnection() that scheduled product refresh needs.
    // 2026-08-18 (task #17): gumroad (a shop-class surface we do not sync as a
    // store), mixcloud and tidal (profile / artist link cards; the widget
    // embeds stay dormant) join the Brand shape. 136 -> 139.
    // 2026-08-19: 13 content/events surfaces lost the default 1-account cap
    // (owner: only bookings/reservations/ordering and socials are limited kinds
    // of link — a Mixcloud or a Luma page is one of several a person may run).
    // multiAccount() emits the /accounts pair, so the 11 of them that are not
    // LinkOnly (skool and strava expose /selection alone) each contribute one
    // GET. 139 -> 150.
    // 150 → 141 on 2026-08-19: the nine pseudo-platform category reads and the
    // reservations suggestion left with the retirement.
    // 141 → 142 on 2026-08-20 (F7): gumroad.store joined the multi-account
    // store caps (10, lockstep with MAX_BRANDS), which grows its registry
    // routes by the /accounts read.
    // 142 → 143 on 2026-08-26: square.order became connectable (menu
    // deep-links plan A1) — the upgraded square-ordering descriptor gains
    // its /selection read.
    expect($readRoutes->count())->toBe(143);
    expect($readRoutes->all())->toEqual([
        'api/platforms/acuity/selection',
        'api/platforms/apple/music/accounts',
        'api/platforms/apple/music/connect/status',
        'api/platforms/apple/music/selection',
        'api/platforms/apple/podcast/accounts',
        'api/platforms/apple/podcast/connect/status',
        'api/platforms/apple/podcast/selection',
        'api/platforms/bandcamp/accounts',
        'api/platforms/bandcamp/connect/status',
        'api/platforms/bandcamp/selection',
        'api/platforms/behance/selection',
        'api/platforms/bella-booking/selection',
        'api/platforms/booksy/selection',
        'api/platforms/bopple/selection',
        'api/platforms/boulevard/selection',
        'api/platforms/buymeacoffee/selection',
        'api/platforms/calendly/selection',
        'api/platforms/chope/selection',
        'api/platforms/chownow/selection',
        'api/platforms/circle/accounts',
        'api/platforms/circle/selection',
        'api/platforms/codepen/selection',
        'api/platforms/deliveroo/selection',
        'api/platforms/discord/selection',
        'api/platforms/doordash/selection',
        'api/platforms/dribbble/selection',
        'api/platforms/easi/selection',
        'api/platforms/eat_app/selection',
        'api/platforms/eventbrite/accounts',
        'api/platforms/eventbrite/connect/status',
        'api/platforms/eventbrite/selection',
        'api/platforms/facebook/selection',
        'api/platforms/fresha/connect/status',
        'api/platforms/fresha/selection',
        'api/platforms/genbook/selection',
        'api/platforms/github/selection',
        'api/platforms/gitlab/selection',
        'api/platforms/glossgenius/selection',
        'api/platforms/google-business/selection',
        'api/platforms/grubhub/selection',
        'api/platforms/gumroad/accounts',
        'api/platforms/gumroad/selection',
        'api/platforms/humanitix/accounts',
        'api/platforms/humanitix/connect/status',
        'api/platforms/humanitix/selection',
        'api/platforms/hungrypanda/selection',
        'api/platforms/instagram/connect/status',
        'api/platforms/instagram/selection',
        'api/platforms/just_eat/selection',
        'api/platforms/kajabi/accounts',
        'api/platforms/kajabi/selection',
        'api/platforms/kick/selection',
        'api/platforms/kitomba/selection',
        'api/platforms/ko-fi/selection',
        'api/platforms/linkedin/selection',
        'api/platforms/luma/accounts',
        'api/platforms/luma/selection',
        'api/platforms/mangomint/selection',
        'api/platforms/medium/selection',
        'api/platforms/menu',
        'api/platforms/menu/status',
        'api/platforms/menulog/selection',
        'api/platforms/meta',
        'api/platforms/mindbody/selection',
        'api/platforms/mixcloud/accounts',
        'api/platforms/mixcloud/selection',
        'api/platforms/noterro/selection',
        'api/platforms/nowbookit/selection',
        'api/platforms/opentable/selection',
        'api/platforms/order_online/selection',
        'api/platforms/ordermate/selection',
        'api/platforms/ovatu/selection',
        'api/platforms/oztix/accounts',
        'api/platforms/oztix/selection',
        'api/platforms/partiful/accounts',
        'api/platforms/partiful/selection',
        'api/platforms/patreon/selection',
        'api/platforms/phorest/selection',
        'api/platforms/quandoo/selection',
        'api/platforms/reddit/selection',
        'api/platforms/resdiary/selection',
        'api/platforms/resident-advisor/accounts',
        'api/platforms/resident-advisor/selection',
        'api/platforms/resy/selection',
        'api/platforms/schedulicity/selection',
        'api/platforms/setmore/selection',
        'api/platforms/sevenrooms/selection',
        'api/platforms/shop/brands',
        'api/platforms/shop/brands/{id}/connect/status',
        'api/platforms/shop/selection',
        'api/platforms/shop/settings',
        'api/platforms/shortcuts/selection',
        'api/platforms/simplybook_me/selection',
        'api/platforms/skipthedishes/selection',
        'api/platforms/skool/selection',
        'api/platforms/slice/selection',
        'api/platforms/snapchat/selection',
        'api/platforms/soundcloud/accounts',
        'api/platforms/soundcloud/selection',
        'api/platforms/spotify/accounts',
        'api/platforms/spotify/connect/status',
        'api/platforms/spotify/selection',
        'api/platforms/square-ordering/selection',
        'api/platforms/square/selection',
        'api/platforms/strava/selection',
        'api/platforms/substack/selection',
        'api/platforms/tablecheck/selection',
        'api/platforms/tablein/selection',
        'api/platforms/telegram/selection',
        'api/platforms/thefork/selection',
        'api/platforms/threads/selection',
        'api/platforms/ticketek/accounts',
        'api/platforms/ticketek/selection',
        'api/platforms/ticketmaster/accounts',
        'api/platforms/ticketmaster/selection',
        'api/platforms/tidal/accounts',
        'api/platforms/tidal/selection',
        'api/platforms/tiktok/selection',
        'api/platforms/timely/selection',
        'api/platforms/toast/selection',
        'api/platforms/tock/selection',
        'api/platforms/treatwell/selection',
        'api/platforms/trybooking/accounts',
        'api/platforms/trybooking/selection',
        'api/platforms/twitch/selection',
        'api/platforms/uber_eats/selection',
        'api/platforms/vagaro/selection',
        'api/platforms/vimeo/accounts',
        'api/platforms/vimeo/connect/status',
        'api/platforms/vimeo/selection',
        'api/platforms/whatsapp/selection',
        'api/platforms/wolt/selection',
        'api/platforms/x/selection',
        'api/platforms/youtube-music/accounts',
        'api/platforms/youtube-music/connect/status',
        'api/platforms/youtube-music/selection',
        'api/platforms/youtube/accounts',
        'api/platforms/youtube/connect/status',
        'api/platforms/youtube/selection',
        'api/platforms/zenoti/selection',
        'api/platforms/zomato/selection',
        'api/platforms/{platform}/display-settings',
        'api/platforms/{platform}/refresh/status',
    ]);
})->note('Net-completeness + identity guard: update BOTH the count and the URI list when integration read routes change.');
