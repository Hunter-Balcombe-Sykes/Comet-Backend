<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
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
