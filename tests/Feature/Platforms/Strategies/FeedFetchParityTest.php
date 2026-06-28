<?php

use App\Http\Controllers\Api\Platforms\YoutubeMusicController;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\BandcampFetch;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\TwitchFetch;
use App\Services\Platforms\Strategies\Fetch\VimeoFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\YoutubeScraper;

// gmUser()/gmSeed() are loaded globally by tests/Pest.php:72 (it require_once's
// Feature/Platforms/GoldenMaster/golden_master_helpers.php for the whole suite),
// so no local require is needed — same as IntegrationContractGoldenMasterTest.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('YoutubeFetch produces the same success payload as the refresher (preserves handle + highlights)', function () {
    $videos = [
        ['videoId' => 'v1', 'name' => 'Fresh', 'description' => 'nd', 'link' => 'nl', 'date' => '2026-03-03T00:00:00+00:00', 'thumbnail' => 'nt'],
        ['videoId' => 'v0', 'name' => 'Older', 'description' => 'od', 'link' => 'ol', 'date' => '2026-01-01T00:00:00+00:00', 'thumbnail' => 'ot'],
    ];
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn($videos));

    // Curated highlights + handle MUST survive the refresh (the bug youtubePayload fixes).
    $stored = ['handle' => 'mychannel', 'name' => 'Old', 'description' => 'od', 'link' => 'ol', 'thumbnail' => 'ot', 'highlights' => [['videoId' => 'h1']]];

    $refresherRow = gmSeed(gmUser('gmyt1'), 'youtube', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmyt2'), 'youtube', $stored);
    $result = (new YoutubeFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['highlights'])->toBe([['videoId' => 'h1']]); // curated highlights preserved
    expect($result['latest'])->toBe($videos[0]);
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
    // Realistic uploads-feed rows: YoutubeMusicController::musicItems() reads
    // $v['videoId'], $v['name'], $v['thumbnail'] (+ link/date) on each video — id-only
    // stubs would make both paths fail identically and prove nothing (and trip PHP 8.2
    // undefined-key warnings).
    $videos = [
        ['videoId' => 'v1', 'name' => 'Track 1', 'thumbnail' => 't1', 'link' => 'l1', 'date' => '2026-03-03T00:00:00+00:00'],
        ['videoId' => 'v2', 'name' => 'Track 2', 'thumbnail' => 't2', 'link' => 'l2', 'date' => '2026-02-02T00:00:00+00:00'],
    ];
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchUploadsFeed')->with('UC123', 12)
        ->andReturn(['title' => 'Artist - Topic', 'videos' => $videos]));

    $stored = ['url' => 'https://music.youtube.com/channel/UC123', 'channelId' => 'UC123', 'name' => 'Old', 'highlights' => [['itemId' => 'h1']]];

    $refresherRow = gmSeed(gmUser('gmym1'), 'youtube-music', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmym2'), 'youtube-music', $stored);
    $result = (new YoutubeMusicFetch(app(YoutubeScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('Artist'); // "- Topic" stripped
    expect($result['items'])->toBe(array_slice(YoutubeMusicController::musicItems($videos), 0, 12));
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

    $stored = ['url' => 'https://vimeo.com/pat', 'apiPath' => 'pat', 'name' => 'Old', 'highlights' => [['id' => 'h']]];

    $refresherRow = gmSeed(gmUser('gmvi1'), 'vimeo', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmvi2'), 'vimeo', $stored);
    $result = (new VimeoFetch(app(VimeoApi::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['items'])->toHaveCount(12); // sliced
    expect($result['latest'])->toBe($videos[0]);
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

it('BandcampFetch produces the same success payload as the refresher (preserves url + highlights)', function () {
    $profile = ['name' => 'X', 'items' => [['name' => 'Album', 'thumbnail' => 't', 'link' => 'l']], 'thumbnail' => 'pt'];
    // enrichPrices echoes its argument — the strategy calls enrichPrices([$items[0]]) and reads [0],
    // so the mock receives a single-element array and returns it unchanged.
    $this->mock(BandcampScraper::class, function ($m) use ($profile) {
        $m->shouldReceive('fetchProfile')->andReturn($profile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $stored = ['url' => 'https://artist.bandcamp.com', 'highlights' => [['itemId' => 'h1']]];

    $refresherRow = gmSeed(gmUser('gmbc1'), 'bandcamp', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmbc2'), 'bandcamp', $stored);
    $result = (new BandcampFetch(app(BandcampScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['artist'])->toBe('X');
    expect($result['name'])->toBe('Album');
    expect($result['thumbnail'])->toBe('t');
    expect($result['highlights'])->toBe([['itemId' => 'h1']]); // curated highlights preserved
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

it('TwitchFetch produces the same success payload as the refresher (no latest/items — card only)', function () {
    $channel = ['name' => 'StreamerName', 'image' => 'https://static-cdn.jtvnw.net/avatar.jpg', 'description' => 'Gaming streamer.'];
    $this->mock(TwitchScraper::class, fn ($m) => $m->shouldReceive('fetchChannel')->andReturn($channel));

    // Stored payload includes login + existing stale fields; refresher overwrites name/image/description.
    $stored = ['url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer', 'name' => 'Old Name', 'image' => null, 'description' => null];

    $refresherRow = gmSeed(gmUser('gmtw1'), 'twitch', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmtw2'), 'twitch', $stored);
    $result = (new TwitchFetch(app(TwitchScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('StreamerName');
    expect($result['image'])->toBe('https://static-cdn.jtvnw.net/avatar.jpg');
    expect($result['description'])->toBe('Gaming streamer.');
    expect($result)->not->toHaveKey('latest'); // Twitch is a card; embed is sitepage-side
    expect($result)->not->toHaveKey('items');
});

it('TwitchFetch throws FetchShapeException when login is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmtw3'), 'twitch', ['url' => 'https://www.twitch.tv/streamer', 'name' => 'no login']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmtw4'), 'twitch', ['url' => 'https://www.twitch.tv/streamer', 'name' => 'no login']);
    expect(fn () => (new TwitchFetch(app(TwitchScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('TwitchFetch throws FetchUnavailableException when channel is null (refresher status=unavailable)', function () {
    $this->mock(TwitchScraper::class, fn ($m) => $m->shouldReceive('fetchChannel')->andReturn(null));

    $row = gmSeed(gmUser('gmtw5'), 'twitch', ['url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmtw6'), 'twitch', ['url' => 'https://www.twitch.tv/streamer', 'login' => 'streamer']);
    expect(fn () => (new TwitchFetch(app(TwitchScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});
