<?php

use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\AppleMusicFetch;
use App\Services\Platforms\Strategies\Fetch\ApplePodcastFetch;
use App\Services\Platforms\Strategies\Fetch\BandcampFetch;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\VimeoFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// gmUser()/gmSeed() are loaded globally by tests/Pest.php:72 (it require_once's
// Feature/Platforms/GoldenMaster/golden_master_helpers.php for the whole suite),
// so no local require is needed — same as IntegrationContractGoldenMasterTest.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('YoutubeFetch produces the same success payload as the refresher (preserves handle)', function () {
    $videos = [
        ['videoId' => 'v1', 'name' => 'Fresh', 'description' => 'nd', 'link' => 'nl', 'date' => '2026-03-03T00:00:00+00:00', 'thumbnail' => 'nt'],
        ['videoId' => 'v0', 'name' => 'Older', 'description' => 'od', 'link' => 'ol', 'date' => '2026-01-01T00:00:00+00:00', 'thumbnail' => 'ot'],
    ];
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn($videos));

    // The handle MUST survive the refresh (the bug youtubePayload fixes).
    $stored = ['handle' => 'mychannel', 'name' => 'Old', 'description' => 'od', 'link' => 'ol', 'thumbnail' => 'ot'];

    $refresherRow = gmSeed(gmUser('gmyt1'), 'youtube', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmyt2'), 'youtube', $stored);
    $result = (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['latest'])->toBe($videos[0]);
    // Private picker snapshot (LIFE-21..24): up to 15, matching the picker width.
    expect($result['recent'])->toBe(array_slice($videos, 0, 15));
});

it('YoutubeFetch throws FetchShapeException when handle is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmyt3'), 'youtube', ['name' => 'no handle']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmyt4'), 'youtube', ['name' => 'no handle']);
    expect(fn () => (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('YoutubeFetch throws FetchUnavailableException when no videos (refresher status=unavailable)', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $row = gmSeed(gmUser('gmyt5'), 'youtube', ['handle' => 'mychannel']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmyt6'), 'youtube', ['handle' => 'mychannel']);
    expect(fn () => (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

it('YoutubeMusicFetch produces the same success payload as the refresher', function () {
    // Realistic uploads-feed rows: YoutubeMusicItems::map() reads
    // $v['videoId'], $v['name'], $v['thumbnail'] (+ link/date) on each video — id-only
    // stubs would make both paths fail identically and prove nothing (and trip PHP 8.2
    // undefined-key warnings).
    $videos = [
        ['videoId' => 'v1', 'name' => 'Track 1', 'thumbnail' => 't1', 'link' => 'l1', 'date' => '2026-03-03T00:00:00+00:00'],
        ['videoId' => 'v2', 'name' => 'Track 2', 'thumbnail' => 't2', 'link' => 'l2', 'date' => '2026-02-02T00:00:00+00:00'],
    ];
    // 15, not 12 — YoutubeMusicFetch widened its uploads-feed pull to 15 to
    // match the `recent` picker-snapshot width it now also stores.
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->with('UC123', 15, Mockery::any())
        ->andReturn(['title' => 'Artist - Topic', 'videos' => $videos]));

    $stored = ['url' => 'https://music.youtube.com/channel/UC123', 'channelId' => 'UC123', 'name' => 'Old'];

    $refresherRow = gmSeed(gmUser('gmym1'), 'youtube-music', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmym2'), 'youtube-music', $stored);
    $result = (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('Artist'); // "- Topic" stripped
    expect($result['items'])->toBe(array_slice(YoutubeMusicItems::map($videos), 0, 12));
    // Private picker snapshot: the full (up-to-15) feed, not the 12-item public slice.
    expect($result['recent'])->toBe(YoutubeMusicItems::map($videos));
});

it('YoutubeMusicFetch throws FetchShapeException when channelId is missing', function () {
    $row = gmSeed(gmUser('gmym3'), 'youtube-music', ['name' => 'no channel']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmym4'), 'youtube-music', ['name' => 'no channel']);
    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('YoutubeMusicFetch throws FetchUnavailableException when the feed is empty', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->andReturn(['title' => 'X', 'videos' => []]));

    $row = gmSeed(gmUser('gmym5'), 'youtube-music', ['channelId' => 'UC123']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmym6'), 'youtube-music', ['channelId' => 'UC123']);
    expect(fn () => (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

it('VimeoFetch produces the same success payload as the refresher', function () {
    $videos = array_map(fn ($i) => ['id' => $i], range(1, 15));
    $this->mock(VimeoApi::class, function ($m) use ($videos) {
        $m->shouldReceive('fetchVideos')->andReturn($videos);
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Pat', 'thumbnail' => 'nt']);
    });

    $stored = ['url' => 'https://vimeo.com/pat', 'apiPath' => 'pat', 'name' => 'Old'];

    $refresherRow = gmSeed(gmUser('gmvi1'), 'vimeo', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmvi2'), 'vimeo', $stored);
    $result = (new VimeoFetch(app(VimeoApi::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['items'])->toHaveCount(12); // sliced
    expect($result['latest'])->toBe($videos[0]);
    // Private picker snapshot: unsliced (Vimeo's picker wants more than 12).
    expect($result['recent'])->toBe($videos);
});

it('VimeoFetch throws FetchShapeException when apiPath is missing', function () {
    $row = gmSeed(gmUser('gmvi3'), 'vimeo', ['name' => 'no path']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');
    expect(fn () => (new VimeoFetch(app(VimeoApi::class)))->fetch(gmSeed(gmUser('gmvi4'), 'vimeo', ['name' => 'no path'])))
        ->toThrow(FetchShapeException::class);
});

it('VimeoFetch throws FetchUnavailableException when no videos', function () {
    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->andReturn([]));
    $row = gmSeed(gmUser('gmvi5'), 'vimeo', ['apiPath' => 'pat']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');
    expect(fn () => (new VimeoFetch(app(VimeoApi::class)))->fetch(gmSeed(gmUser('gmvi6'), 'vimeo', ['apiPath' => 'pat'])))
        ->toThrow(FetchUnavailableException::class);
});

it('BandcampFetch produces the same success payload as the refresher (preserves url)', function () {
    $profile = ['name' => 'X', 'items' => [['name' => 'Album', 'thumbnail' => 't', 'link' => 'l']], 'thumbnail' => 'pt'];
    // enrichPrices echoes its argument — the strategy calls enrichPrices([$items[0]]) and reads [0],
    // so the mock receives a single-element array and returns it unchanged.
    $this->mock(BandcampScraper::class, function ($m) use ($profile) {
        $m->shouldReceive('fetchProfile')->andReturn($profile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $stored = ['url' => 'https://artist.bandcamp.com'];

    $refresherRow = gmSeed(gmUser('gmbc1'), 'bandcamp', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmbc2'), 'bandcamp', $stored);
    $result = (new BandcampFetch(app(BandcampScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['artist'])->toBe('X');
    expect($result['name'])->toBe('Album');
    expect($result['thumbnail'])->toBe('t');
    // Full releases grid captured from the same fetch (kept stored for internal use).
    expect($result['releases'])->toBe($profile['items']);
    // Private picker snapshot: up to 15 (here just the 1 seeded release).
    expect($result['recent'])->toBe(array_slice($profile['items'], 0, 15));
});

it('BandcampFetch freezes the stored payload (quiet ok) when auto_sync_latest is off', function () {
    // The scraper must never be called — the point is skipping the pull entirely.
    $this->mock(BandcampScraper::class, fn ($m) => $m->shouldReceive('fetchProfile')->never());

    $stored = ['url' => 'https://artist.bandcamp.com', 'name' => 'Frozen Album', 'latest' => ['name' => 'Frozen Album']];
    $row = gmSeed(gmUser('gmbc-sync1'), 'bandcamp', $stored);
    $row->forceFill(['display_settings' => ['auto_sync_latest' => false], 'last_refreshed_at' => now()->subDays(3)])->save();

    app(PlatformRefresher::class)->refresh($row);

    $fresh = $row->fresh();
    expect($fresh->payload)->toEqual($stored);
    // 304 semantics: healthy ok + timestamp bump so the dispatcher moves on.
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refreshed_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('BandcampFetch throws FetchShapeException when url is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmbc3'), 'bandcamp', ['name' => 'no url']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmbc4'), 'bandcamp', ['name' => 'no url']);
    expect(fn () => (new BandcampFetch(app(BandcampScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('BandcampFetch throws FetchUnavailableException when profile is null (refresher status=unavailable)', function () {
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->andReturn(null);
    });

    $row = gmSeed(gmUser('gmbc5'), 'bandcamp', ['url' => 'https://artist.bandcamp.com']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmbc6'), 'bandcamp', ['url' => 'https://artist.bandcamp.com']);
    expect(fn () => (new BandcampFetch(app(BandcampScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

it('AppleMusicFetch produces the same success payload as the refresher (preserves input)', function () {
    $albums = [
        ['collectionId' => 'c1', 'name' => 'Album One', 'thumbnail' => 'nt', 'releaseDate' => '2026-03-01T00:00:00+00:00', 'link' => 'https://music.apple.com/au/album/1'],
        ['collectionId' => 'c0', 'name' => 'Album Two', 'thumbnail' => 'ot', 'releaseDate' => '2026-01-01T00:00:00+00:00', 'link' => 'https://music.apple.com/au/album/0'],
    ];
    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchAlbums')->andReturn($albums));

    // input MUST survive the refresh.
    $stored = ['input' => 'Taylor Swift'];

    $refresherRow = gmSeed(gmUser('gmam1'), 'apple-music', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmam2'), 'apple-music', $stored);
    $result = (new AppleMusicFetch(app(AppleSearch::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['latest'])->toBe($albums[0]);
});

it('AppleMusicFetch captures the artist genre on refresh (#76 Part B)', function () {
    $albums = [
        ['collectionId' => 'c1', 'name' => 'Album One', 'thumbnail' => 'nt', 'releaseDate' => '2026-03-01T00:00:00+00:00', 'link' => 'https://music.apple.com/au/album/1'],
    ];
    $this->mock(AppleSearch::class, function ($m) use ($albums) {
        $m->shouldReceive('fetchAlbums')->andReturn($albums);
        $m->shouldReceive('fetchGenre')->with('Taylor Swift')->andReturn('pop');
    });

    $row = gmSeed(gmUser('gmamg1'), 'apple-music', ['input' => 'Taylor Swift']);
    $result = (new AppleMusicFetch(app(AppleSearch::class)))->fetch($row);

    // Genre lands on the payload so IdentityEvidence::musicGenre() can read it.
    expect($result['genre'])->toBe('pop');
});

it('AppleMusicFetch retains a prior genre when re-fetch yields none (#76 Part B)', function () {
    $albums = [
        ['collectionId' => 'c1', 'name' => 'Album One', 'thumbnail' => 'nt', 'releaseDate' => '2026-03-01T00:00:00+00:00', 'link' => 'https://music.apple.com/au/album/1'],
    ];
    // fetchGenre now returns null (transient miss) — the stored genre must survive.
    $this->mock(AppleSearch::class, function ($m) use ($albums) {
        $m->shouldReceive('fetchAlbums')->andReturn($albums);
        $m->shouldReceive('fetchGenre')->andReturn(null);
    });

    $row = gmSeed(gmUser('gmamg2'), 'apple-music', ['input' => 'Some Artist', 'genre' => 'jazz']);
    $result = (new AppleMusicFetch(app(AppleSearch::class)))->fetch($row);

    expect($result['genre'])->toBe('jazz'); // preserved via the payload spread
});

it('AppleMusicFetch throws FetchShapeException when input is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmam3'), 'apple-music', ['name' => 'no input']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmam4'), 'apple-music', ['name' => 'no input']);
    expect(fn () => (new AppleMusicFetch(app(AppleSearch::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('AppleMusicFetch throws FetchUnavailableException when no albums (refresher status=unavailable)', function () {
    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchAlbums')->andReturn([]));

    $row = gmSeed(gmUser('gmam5'), 'apple-music', ['input' => 'Taylor Swift']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmam6'), 'apple-music', ['input' => 'Taylor Swift']);
    expect(fn () => (new AppleMusicFetch(app(AppleSearch::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

it('ApplePodcastFetch produces the same success payload as the refresher (preserves input)', function () {
    $episodes = [
        ['trackId' => 'e1', 'name' => 'Episode One', 'thumbnail' => 'et1', 'description' => 'Great episode', 'releaseDate' => '2026-03-01T00:00:00+00:00', 'link' => 'https://podcasts.apple.com/ep/1'],
        ['trackId' => 'e0', 'name' => 'Episode Two', 'thumbnail' => 'et0', 'description' => 'Another ep', 'releaseDate' => '2026-01-01T00:00:00+00:00', 'link' => 'https://podcasts.apple.com/ep/0'],
    ];
    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchEpisodes')->andReturn($episodes));

    // input MUST survive the refresh.
    $stored = ['input' => 'Huberman Lab'];

    $refresherRow = gmSeed(gmUser('gmap1'), 'apple-podcast', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmap2'), 'apple-podcast', $stored);
    $result = (new ApplePodcastFetch(app(AppleSearch::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['latest'])->toBe($episodes[0]);
});

it('ApplePodcastFetch throws FetchShapeException when input is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmap3'), 'apple-podcast', ['name' => 'no input']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmap4'), 'apple-podcast', ['name' => 'no input']);
    expect(fn () => (new ApplePodcastFetch(app(AppleSearch::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('ApplePodcastFetch throws FetchUnavailableException when no episodes (refresher status=unavailable)', function () {
    $this->mock(AppleSearch::class, fn ($m) => $m->shouldReceive('fetchEpisodes')->andReturn([]));

    $row = gmSeed(gmUser('gmap5'), 'apple-podcast', ['input' => 'Huberman Lab']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmap6'), 'apple-podcast', ['input' => 'Huberman Lab']);
    expect(fn () => (new ApplePodcastFetch(app(AppleSearch::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

it('GoogleBusinessFetch produces the same success payload as the refresher (stale detailsFetchedAt → re-fetches)', function () {
    $details = ['rating' => 4.5, 'reviewCount' => 10];
    $this->mock(GoogleBusinessService::class, fn ($m) => $m->shouldReceive('fetchPlaceDetails')->with('p1', Mockery::any(), [])->andReturn($details));

    // detailsFetchedAt is 8 days old — past the 6-day freshness window, so both paths re-fetch.
    $stored = ['placeId' => 'p1', 'name' => 'The Cafe', 'detailsFetchedAt' => now()->subDays(8)->toIso8601String()];

    $refresherRow = gmSeed(gmUser('gmgb1'), 'google-business', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmgb2'), 'google-business', $stored);
    $result = (new GoogleBusinessFetch(app(GoogleBusinessService::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['rating'])->toBe(4.5);
    expect($result['reviewCount'])->toBe(10);
});

it('GoogleBusinessFetch short-circuits without an API call when detailsFetchedAt is fresh (refresher status=ok + payload unchanged)', function () {
    // shouldNotReceive verifies neither the refresher nor the strategy calls the API when fresh.
    $this->mock(GoogleBusinessService::class, fn ($m) => $m->shouldNotReceive('fetchPlaceDetails'));

    // detailsFetchedAt is 1 day old — within the 6-day freshness window.
    $stored = ['placeId' => 'p1', 'name' => 'The Cafe', 'detailsFetchedAt' => now()->subDay()->toIso8601String()];

    $refresherRow = gmSeed(gmUser('gmgb3'), 'google-business', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);
    // Refresher records ok + persists the payload unchanged (no API call fired).
    expect($refresherRow->fresh()->last_refresh_status)->toBe('ok');
    expect($refresherRow->fresh()->payload)->toEqual($stored);

    $strategyRow = gmSeed(gmUser('gmgb4'), 'google-business', $stored);
    $result = (new GoogleBusinessFetch(app(GoogleBusinessService::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
});

it('GoogleBusinessFetch throws FetchUnavailableException when placeId is missing (refresher status=unavailable)', function () {
    // Legacy link-paste rows legitimately have no placeId — transient/unavailable, not a shape error.
    $row = gmSeed(gmUser('gmgb5'), 'google-business', ['url' => 'https://g.co/maps/abc', 'name' => 'No Place ID']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmgb6'), 'google-business', ['url' => 'https://g.co/maps/abc', 'name' => 'No Place ID']);
    expect(fn () => (new GoogleBusinessFetch(app(GoogleBusinessService::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});

// Twitch and Strava had six parity cases here until 2026-08-16, when Phase 1.2's
// demotion made them link-only: with no fetch strategy and no refresher path,
// "the strategy agrees with the refresher" has nothing left to compare. The
// parity mechanism itself is unaffected and still covered by the 24 cases above.
