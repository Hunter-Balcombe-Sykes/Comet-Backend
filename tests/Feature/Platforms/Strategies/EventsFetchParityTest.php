<?php

use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('EventbriteFetch (organiser account) matches the refresher and re-applies hidden ids', function () {
    $result = ['organiser' => 'Acme Events', 'events' => [
        ['id' => 'e1', 'name' => 'One'], ['id' => 'e2', 'name' => 'Two'],
    ]];
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn($result));

    $stored = ['url' => 'https://eventbrite.com/o/acme', 'hiddenEventIds' => ['e2']];

    $refresherRow = gmSeed(gmUser('gmev1'), 'eventbrite', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmev2'), 'eventbrite', $stored);
    $out = (new EventbriteFetch(app(EventbriteScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});

it('EventbriteFetch (standalone kind=event) re-scrapes the single event page', function () {
    $event = ['link' => 'https://eventbrite.com/e/show-1', 'name' => 'Show'];
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchSingleEvent')->with('https://eventbrite.com/e/show-1')->andReturn($event));

    $stored = ['kind' => 'event', 'link' => 'https://eventbrite.com/e/show-1', 'name' => 'Old'];

    $refresherRow = gmSeed(gmUser('gmev3'), 'eventbrite', $stored, 'event-show1');
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmev4'), 'eventbrite', $stored, 'event-show1');
    $out = (new EventbriteFetch(app(EventbriteScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});

it('EventbriteFetch throws FetchShapeException when the account url is missing (status=error)', function () {
    $row = gmSeed(gmUser('gmev5'), 'eventbrite', ['name' => 'no url']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    expect(fn () => (new EventbriteFetch(app(EventbriteScraper::class)))->fetch(gmSeed(gmUser('gmev6'), 'eventbrite', ['name' => 'no url'])))
        ->toThrow(FetchShapeException::class);
});

it('EventbriteFetch throws FetchUnavailableException when fetchEvents is null (status=unavailable)', function () {
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn(null));
    $row = gmSeed(gmUser('gmev7'), 'eventbrite', ['url' => 'https://eventbrite.com/o/acme']);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    expect(fn () => (new EventbriteFetch(app(EventbriteScraper::class)))->fetch(gmSeed(gmUser('gmev8'), 'eventbrite', ['url' => 'https://eventbrite.com/o/acme'])))
        ->toThrow(FetchUnavailableException::class);
});

it('HumanitixFetch (organiser account) matches the refresher', function () {
    $result = ['organiser' => 'Town Hall', 'events' => [['id' => 'h1', 'name' => 'Gig']]];
    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->andReturn($result));

    $stored = ['url' => 'https://humanitix.com/host/townhall', 'hiddenEventIds' => []];

    $refresherRow = gmSeed(gmUser('gmhx1'), 'humanitix', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    $strategyRow = gmSeed(gmUser('gmhx2'), 'humanitix', $stored);
    $out = (new HumanitixFetch(app(HumanitixScraper::class)))->fetch($strategyRow);

    expect($out)->toEqual($refresherRow->fresh()->payload);
});
