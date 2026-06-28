<?php

use App\Http\Controllers\Api\Platforms\YoutubeMusicController;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
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
