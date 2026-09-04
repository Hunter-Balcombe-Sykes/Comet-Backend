<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\MediaPageReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ITEM-FIRST watch/listen hand-add (media parity, 2026-08-20): a pasted
// video/track URL lands as a real pool item — platform-canonical URL, real
// kind from the URL grammar, the page's own title and cover via oEmbed —
// exactly as ticket pages land in the events pool. Profile URLs get the
// connect hint, wrong-pool items get pointed at their own page, and unknown
// hosts are REFUSED with the Links hand-off (T3, owner 2026-08-20: "no
// events or listen items for random foreign links"). Known-but-unreadable
// item URLs keep the card path — the grammar claimed them.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

/** Mock the transport: oEmbed endpoint URLs answer JSON, page URLs answer HTML. */
function mipMockFetch(array $responsesByUrlSubstring): void
{
    app()->instance(SafeUrlFetcher::class, Mockery::mock(SafeUrlFetcher::class, function ($m) use ($responsesByUrlSubstring) {
        $m->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($responsesByUrlSubstring) {
            foreach ($responsesByUrlSubstring as $needle => $body) {
                if (str_contains($url, $needle)) {
                    return $body === null ? null : ['status' => 200, 'body' => $body, 'finalUrl' => $url, 'contentType' => 'application/json'];
                }
            }

            return null;
        });
    }));
}

it('reads a YouTube link as a real video — canonical URL, oEmbed title, thumbnail — and pins it', function () {
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([
        'youtube.com/oembed' => json_encode(['title' => 'Studio Tour 2026', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']),
    ]);

    $res = actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertCreated();

    $item = collect($res->json('selection'))->firstWhere('headline', 'Studio Tour 2026');
    expect($item)->not->toBeNull()
        ->and($item['kind'])->toBe('video')
        // The sheet locates the new item by THIS id — never by re-matching
        // the pasted url, which the canonical rewrite would break.
        ->and($res->json('addedItemId'))->toBe($item['id']);

    // The canonical form — NOT the pasted youtu.be short link — is what the
    // identity spine folds on.
    expect(DB::table('content.f_link')->value('url'))->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and(DB::table('content.item_media')->where('role', 'cover')->count())->toBe(1)
        ->and(DB::table('site.section_items')->where('state', 'pinned')->count())->toBe(1);
});

it('folds two paste shapes of the same video into ONE item', function () {
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([
        'youtube.com/oembed' => json_encode(['title' => 'Same Video', 'thumbnail_url' => null]),
    ]);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
    actingAsUser($user)->postJson('/api/content/pools/watch/items', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&si=share-junk'])->assertCreated();

    expect(DB::connection('pgsql')->table('content.items')->where('kind', 'video')->count())->toBe(1);
});

it('refuses a channel URL with the connect hint', function () {
    [$user] = makeShopUser(withSite: true);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://www.youtube.com/@somecreator',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "That looks like a YouTube profile, not a single video. Connect YouTube as a platform to bring its content in automatically, or paste one video's link."]);

    actingAsUser($user)->postJson('/api/content/pools/listen/items', [
        'url' => 'https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU',
    ])->assertStatus(422);
});

it('points a wrong-pool item at its own page', function () {
    [$user] = makeShopUser(withSite: true);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'That looks like a track — add it on the Listen page instead.']);

    actingAsUser($user)->postJson('/api/content/pools/listen/items', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'That looks like a video — add it on the Watch page instead.']);
});

it('reads a Spotify album into Listen as a release', function () {
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([
        'open.spotify.com/oembed' => json_encode(['title' => 'Currents', 'thumbnail_url' => 'https://i.scdn.co/image/abc']),
    ]);

    $res = actingAsUser($user)->postJson('/api/content/pools/listen/items', [
        'url' => 'https://open.spotify.com/album/79dL7FLiJFOO0EoehUHQBv?si=xyz',
    ])->assertCreated();

    $item = collect($res->json('selection'))->firstWhere('headline', 'Currents');
    expect($item)->not->toBeNull()
        ->and($item['kind'])->toBe('release');
    expect(DB::table('content.f_link')->value('url'))->toBe('https://open.spotify.com/album/79dL7FLiJFOO0EoehUHQBv');
});

it('refuses an unknown host with the Links hand-off (T3)', function () {
    [$user] = makeShopUser(withSite: true);

    // Unknown host: no grammar match, no fetch, no item — the 422 is the
    // contract behind the sheet's refusal band.
    mipMockFetch([]);
    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://some-blog.example/my-video-post',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "We don't recognise this link as a video — add it to your Links page instead."]);

    // The natalieanne.com repro (2026-08-19): a Shopify storefront pasted
    // into Listen was silently added as a bare "track". Never again.
    actingAsUser($user)->postJson('/api/content/pools/listen/items', [
        'url' => 'https://natalieanne.com/pages/education',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "We don't recognise this link as a track — add it to your Links page instead."]);

    expect(DB::connection('pgsql')->table('content.items')->count())->toBe(0);
});

it('refuses a known SOCIAL profile with the connect hint, not "unknown" (T3)', function () {
    [$user] = makeShopUser(withSite: true);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://www.instagram.com/natalieannehair/',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'That looks like an Instagram profile — connect it as a platform to bring its content in automatically, or add it to your Links page.']);
});

it('never calls a social ITEM url a profile — reels get the honest refusal (T3 critic)', function () {
    [$user] = makeShopUser(withSite: true);

    // Instagram has no item grammar: a reel is not a profile, and saying so
    // ("we don't recognise this") is the honest answer. Giving it the profile
    // hand-off would tell someone to connect an account they just pasted a
    // single post from.
    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://www.instagram.com/reel/Cxxxxxxxxxx/',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "We don't recognise this link as a video — add it to your Links page instead."]);
});

it('takes a TikTok video into Watch, and still hands off its profile', function () {
    // TikTok used to sit in the case above, refused as unrecognised. That was
    // a GAP, not a decision (2026-09-03): its /@handle/video/<id> shape is as
    // plain as YouTube's, and its oEmbed is public and unauthenticated. The
    // property the case above defends — an item is never called a profile —
    // is unchanged and now proven from the other side: the video is taken,
    // and only the bare handle gets the connect hand-off.
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([
        'tiktok.com/oembed' => json_encode([
            'title' => 'Rip The Script',
            'thumbnail_url' => 'https://p16.tiktokcdn.com/cover.jpg',
            'author_url' => 'https://www.tiktok.com/@nike',
        ]),
    ]);

    $res = actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://www.tiktok.com/@nike/video/7647200302189251854?is_from_webapp=1',
    ])->assertCreated();

    $item = collect($res->json('selection'))->firstWhere('headline', 'Rip The Script');
    expect($item)->not->toBeNull()
        ->and($item['kind'])->toBe('video');

    // Share junk is stripped: the canonical is what folds a pasted item onto
    // its synced twin, so it must not carry the query string it arrived with.
    expect(DB::table('content.f_link')->value('url'))
        ->toBe('https://www.tiktok.com/@nike/video/7647200302189251854');

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://www.tiktok.com/@nike',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "That looks like a TikTok profile, not a single video. Connect TikTok as a platform to bring its content in automatically, or paste one video's link."]);
});

it('refuses a known store-platform host with the store hand-off (T4)', function () {
    [$user] = makeShopUser(withSite: true);

    actingAsUser($user)->postJson('/api/content/pools/listen/items', [
        'url' => 'https://cool-shop.myshopify.com/products/thing',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'That looks like an online store — connect it on your Sell page to bring in its products, or add it to your Links page.']);
});

it('refuses every URL add into the gallery — nothing claims kind media (T3 critic)', function () {
    [$user] = makeShopUser(withSite: true);

    // The media grid's add sheet is select-only for exactly this reason; the
    // 422 is the server contract behind it.
    actingAsUser($user)->postJson('/api/content/pools/media/items', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertStatus(422);

    actingAsUser($user)->postJson('/api/content/pools/media/items', [
        'url' => 'https://example.com/photo.jpg',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "We don't recognise this link as a gallery item — add it to your Links page instead."]);
});

it('suggests the video\'s CHANNEL beside a pool paste — suggest-only (T9b)', function () {
    setupRoutingTables();
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([
        'youtube.com/oembed' => json_encode([
            'title' => 'Studio Tour 2026', 'thumbnail_url' => null,
            'author_url' => 'https://www.youtube.com/channel/UCparentparentparentpar1',
        ]),
    ]);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertCreated();

    $intent = DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('surface_key', 'youtube.channel')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->identifier)->toBe('UCparentparentparentpar1');
});

it('never re-suggests a DISMISSED parent on a later paste — derived is not direct (T9b critic)', function () {
    setupRoutingTables();
    [$user] = makeShopUser(withSite: true);
    // The user dismissed this channel's suggestion once — dismiss() writes
    // exactly this tombstone. A later paste of ANOTHER video from the same
    // channel must not resurrect the question.
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'source_ref' => 'youtube.channel:UCparentparentparentpar1',
        'scope' => 'this_source',
        'reason' => 'user dismissed the suggestion',
        'created_at' => now(),
    ]);
    mipMockFetch([
        'youtube.com/oembed' => json_encode([
            'title' => 'Another Video', 'thumbnail_url' => null,
            'author_url' => 'https://www.youtube.com/channel/UCparentparentparentpar1',
        ]),
    ]);

    actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://youtu.be/dQw4w9WgXcQ',
    ])->assertCreated();

    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('surface_key', 'youtube.channel')
        ->whereIn('state', ['proposed', 'blocked'])->count())->toBe(0);
});

it('keeps the card path for a claimed item whose read fails', function () {
    [$user] = makeShopUser(withSite: true);
    mipMockFetch([]);

    // Known shape, dead oEmbed + dead page: falls to the card titled by
    // host — the grammar claimed it, so T3 lets it through.
    $res = actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://vimeo.com/123456789',
    ])->assertCreated();
    expect(collect($res->json('selection'))->firstWhere('headline', 'vimeo.com'))->not->toBeNull();
});

/**
 * The dead-page guard. Every og:title below is the REAL string the live site
 * returned on 2026-09-04, captured by the probe that found the leak — a
 * nonexistent Audiomack song answered HTTP 200 and minted a pool item headlined
 * "Audiomack - Music platform empowering artists & fans | Audiomack" onto the
 * public sitepage. The guard had named 11 brands by exact string and never
 * learned the nine this run added, and exact-matching slid past the decorated
 * form entirely.
 */
it('refuses a title that is the site talking about itself', function (string $url, string $ogTitle) {
    mipMockFetch([$url => '<meta property="og:title" content="'.$ogTitle.'">']);

    expect(app(MediaPageReader::class)->read($url))->toBeNull();
})->with([
    'audiomack decorates its site name' => [
        'https://audiomack.com/rob49/song/no-such-song-99zz',
        'Audiomack - Music platform empowering artists &amp; fans | Audiomack',
    ],
    'youtube music is its own site name' => [
        'https://music.youtube.com/watch?v=zzzZZ9zz9Zz',
        'YouTube Music',
    ],
    'the original exact-match rule still holds' => [
        'https://www.twitch.tv/videos/999999999123',
        'Twitch',
    ],
    'a brand added this run, exact' => [
        'https://rumble.com/vzzz9z9-no-such-video.html',
        'Rumble',
    ],
]);

it('keeps a real title that merely mentions a platform', function (string $url, string $ogTitle) {
    mipMockFetch([$url => '<meta property="og:title" content="'.$ogTitle.'">']);

    expect(app(MediaPageReader::class)->read($url)['title'] ?? null)->toBe(html_entity_decode($ogTitle));
})->with([
    // Beatport's GENUINE titles carry the site name as a SUFFIX — a trailing-
    // segment rule would delete every real Beatport track.
    'beatport suffixes its own name' => [
        'https://beatport.com/track/lockup/28901951',
        'Overmono - Lockup (Original Mix) [XL Recordings] | Music &amp; Downloads on Beatport',
    ],
    // The leading-segment rule asks only about the page's OWN site, so an
    // ordinary video named after another platform survives.
    'a youtube video named after another platform' => [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'Spotify - Wrapped 2025 Recap',
    ],
]);

it('classifies the grammar matrix — item vs account vs neither', function (string $url, ?string $expectKind, ?string $expectAccount) {
    $reader = app(MediaPageReader::class);

    expect($reader->classifyItem($url)['kind'] ?? null)->toBe($expectKind)
        ->and($reader->accountPlatformLabel($url))->toBe($expectAccount);
})->with([
    // YouTube
    ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'video', null],
    ['https://www.youtube.com/shorts/AbC123xyz_-', 'video', null],
    ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=42s', 'video', null],
    ['https://www.youtube.com/@somecreator', null, 'YouTube'],
    ['https://www.youtube.com/channel/UCabc123', null, 'YouTube'],
    ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'track', null],
    // Vimeo: digits = video, name = profile
    ['https://vimeo.com/123456789', 'video', null],
    ['https://vimeo.com/somestudio', null, 'Vimeo'],
    // Twitch
    ['https://www.twitch.tv/videos/2233445566', 'video', null],
    ['https://clips.twitch.tv/FunnyClipName-abc', 'video', null],
    ['https://www.twitch.tv/somestreamer', null, 'Twitch'],
    // Spotify
    ['https://open.spotify.com/track/3n3Ppam7vgaVa1iaRUc9Lp', 'track', null],
    ['https://open.spotify.com/intl-de/track/3n3Ppam7vgaVa1iaRUc9Lp', 'track', null],
    ['https://open.spotify.com/album/79dL7FLiJFOO0EoehUHQBv', 'release', null],
    ['https://open.spotify.com/episode/512ojhOuo1ktJprKbVcKyQ', 'episode', null],
    ['https://open.spotify.com/artist/4gzpq5DPGxSnKTe4SA8HAU', null, 'Spotify'],
    // A podcast SHOW is its own connectable brand (`spotify_podcasts.show`,
    // Shelf::Podcast), not `spotify.player` — and this label is what the 422
    // tells the person to connect, so naming the music player here sent them
    // to a platform that cannot bring a show's episodes in. The sibling
    // artist/user/playlist rows must keep saying plain 'Spotify'.
    ['https://open.spotify.com/show/4rOoJ6Egrf8K2IrywzwOMk', null, 'Spotify Podcasts'],
    ['https://open.spotify.com/intl-de/show/4rOoJ6Egrf8K2IrywzwOMk', null, 'Spotify Podcasts'],
    ['https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', null, 'Spotify'],
    ['https://open.spotify.com/user/spotify', null, 'Spotify'],
    // SoundCloud: two segments = track, one = profile, chrome reserved
    ['https://soundcloud.com/artist-name/new-single', 'track', null],
    ['https://soundcloud.com/artist-name', null, 'SoundCloud'],
    ['https://soundcloud.com/artist-name/tracks', null, null],
    // Apple
    ['https://music.apple.com/au/album/some-album/1440857781?i=1440857787', 'track', null],
    ['https://music.apple.com/au/album/some-album/1440857781', 'release', null],
    ['https://music.apple.com/au/artist/tame-impala/353611358', null, 'Apple Music'],
    ['https://podcasts.apple.com/au/podcast/some-show/id123456789?i=1000634566281', 'episode', null],
    ['https://podcasts.apple.com/au/podcast/some-show/id123456789', null, 'Apple Podcasts'],
    // Bandcamp
    ['https://artist.bandcamp.com/track/some-song', 'track', null],
    ['https://artist.bandcamp.com/album/some-record', 'release', null],
    ['https://artist.bandcamp.com', null, 'Bandcamp'],
    // Tidal
    ['https://tidal.com/browse/track/77692131', 'track', null],
    ['https://listen.tidal.com/album/77691994', 'release', null],
    // Neither
    ['https://example.com/watch?v=abc', null, null],
]);

it('classifies pasted links for step-1 guidance — pure, no fetch', function () {
    [$user] = makeShopUser(withSite: true);

    // A track pasted anywhere → belongs on Listen.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://open.spotify.com/track/4hvcQOVrQ4rdaI0zubTwWa?si=3d4123c3f9854ddd',
    ])->assertOk()->assertJson([
        'belongsTo' => ['pool' => 'listen', 'kind' => 'track', 'pageLabel' => 'Listen'],
        'account' => null,
    ]);

    // A channel → connect guidance.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://www.youtube.com/@somecreator',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => 'YouTube']);

    // An event page → Events; an organiser → connect.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://www.eventbrite.com.au/e/some-gig-12345',
    ])->assertOk()->assertJson(['belongsTo' => ['pool' => 'events', 'kind' => 'event', 'pageLabel' => 'Events']]);
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://www.eventbrite.com.au/o/some-org-123',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => 'Eventbrite']);

    // A social profile → connect guidance (T3: the harvester's social arm
    // surfaces through the classifier, so an Instagram profile pasted into
    // a pool says "connect it", not "unknown").
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://www.instagram.com/natalieannehair/',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => 'Instagram']);

    // A social ITEM url is NOT a profile (T3 critic): the classifier's
    // profile-shape check refuses reel/post/video paths that the harvester's
    // loose bio-scan heuristic would have called profiles.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://www.instagram.com/reel/Cxxxxxxxxxx/',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => null, 'store' => null]);

    // A store-platform host → the store answer (T4).
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://cool-shop.myshopify.com/',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => null, 'store' => 'Shopify']);

    // A plain page → neither.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://example.com/blog/post',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => null]);
});
