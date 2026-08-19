<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\MediaPageReader;
use Illuminate\Support\Facades\DB;

// ITEM-FIRST watch/listen hand-add (media parity, 2026-08-20): a pasted
// video/track URL lands as a real pool item — platform-canonical URL, real
// kind from the URL grammar, the page's own title and cover via oEmbed —
// exactly as ticket pages land in the events pool. Profile URLs get the
// connect hint, wrong-pool items get pointed at their own page, and unknown
// hosts keep the card path byte-identical.

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

it('keeps the card path byte-identical for unknown hosts and failed reads', function () {
    [$user] = makeShopUser(withSite: true);

    // Unknown host: no grammar match, no fetch at all.
    mipMockFetch([]);
    $res = actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://some-blog.example/my-video-post',
    ])->assertCreated();
    $item = collect($res->json('selection'))->firstWhere('headline', 'some-blog.example');
    expect($item)->not->toBeNull()->and($item['kind'])->toBe('video');

    // Known shape, dead oEmbed + dead page: falls to the card titled by host.
    $res = actingAsUser($user)->postJson('/api/content/pools/watch/items', [
        'url' => 'https://vimeo.com/123456789',
    ])->assertCreated();
    expect(collect($res->json('selection'))->firstWhere('headline', 'vimeo.com'))->not->toBeNull();
});

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

    // A plain page → neither.
    actingAsUser($user)->postJson('/api/content/links/classify', [
        'url' => 'https://example.com/blog/post',
    ])->assertOk()->assertJson(['belongsTo' => null, 'account' => null]);
});
