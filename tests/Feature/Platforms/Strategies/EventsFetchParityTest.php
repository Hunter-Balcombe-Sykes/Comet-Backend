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

// ── auto_sync_latest=false freezes ACCOUNT rows via 304 semantics ──

it('freezes an organiser account (payload untouched, quiet ok) when auto_sync_latest is off', function () {
    // The scraper must never even be CALLED — the whole point is skipping the pull.
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->never());

    $stored = ['url' => 'https://eventbrite.com/o/acme', 'organiser' => 'Acme', 'upcoming' => [['id' => 'e1', 'name' => 'Frozen Fest']], 'hiddenEventIds' => []];
    $row = gmSeed(gmUser('gmev-sync1'), 'eventbrite', $stored);
    $row->forceFill(['display_settings' => ['auto_sync_latest' => false], 'last_refreshed_at' => now()->subDays(3)])->save();

    app(PlatformRefresher::class)->refresh($row);

    $fresh = $row->fresh();
    // Stored events untouched; recorded as a healthy 304 (ok + timestamp bump),
    // so the hourly dispatcher stops re-selecting the row instead of retrying
    // it forever.
    expect($fresh->payload)->toEqual($stored);
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refreshed_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('keeps refreshing STANDALONE event rows when auto_sync_latest is off (sold-out freshness)', function () {
    $event = ['link' => 'https://events.humanitix.com/gig-1', 'name' => 'Gig', 'soldOut' => true];
    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('fetchSingleEvent')->once()->andReturn($event));

    $row = gmSeed(gmUser('gmhx-sync1'), 'humanitix', ['kind' => 'event', 'link' => 'https://events.humanitix.com/gig-1', 'name' => 'Old'], 'event-gig1');
    $row->forceFill(['display_settings' => ['auto_sync_latest' => false]])->save();

    app(PlatformRefresher::class)->refresh($row);

    // The single-event re-scrape ran and persisted — only NEW-event pulling is frozen.
    expect($row->fresh()->payload['name'])->toBe('Gig');
    expect($row->fresh()->payload['soldOut'])->toBeTrue();
});
