<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\StravaFetch;
use App\Services\Platforms\StravaClubScraper;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('StravaFetch produces the same merged card payload as the refresher (nulls keep stored values)', function () {
    // fetchClub() returns {name, location, image, description, members}. Here the
    // scrape yields a fresh name but a null image — null must fall back to the stored value.
    $card = ['name' => 'Fresh Club', 'image' => null, 'members' => 42];
    $this->mock(StravaClubScraper::class, fn ($m) => $m->shouldReceive('fetchClub')->andReturn($card));

    $stored = ['url' => 'https://www.strava.com/clubs/abc', 'name' => 'Old Club', 'image' => 'stored.jpg'];

    $refresherRow = gmSeed(gmUser('gmst1'), 'strava', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmst2'), 'strava', $stored);
    $result = (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
    expect($result['name'])->toBe('Fresh Club');
    expect($result['image'])->toBe('stored.jpg'); // null scrape kept the stored value
    expect($result['members'])->toBe(42);
});

it('StravaFetch throws FetchShapeException when url is missing (refresher status=error)', function () {
    $row = gmSeed(gmUser('gmst3'), 'strava', ['name' => 'no url']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmst4'), 'strava', ['name' => 'no url']);
    expect(fn () => (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow))->toThrow(FetchShapeException::class);
});

it('StravaFetch throws FetchUnavailableException when the scrape is null (refresher status=unavailable)', function () {
    $this->mock(StravaClubScraper::class, fn ($m) => $m->shouldReceive('fetchClub')->andReturn(null));

    $row = gmSeed(gmUser('gmst5'), 'strava', ['url' => 'https://www.strava.com/clubs/abc']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmst6'), 'strava', ['url' => 'https://www.strava.com/clubs/abc']);
    expect(fn () => (new StravaFetch(app(StravaClubScraper::class)))->fetch($strategyRow))->toThrow(FetchUnavailableException::class);
});
