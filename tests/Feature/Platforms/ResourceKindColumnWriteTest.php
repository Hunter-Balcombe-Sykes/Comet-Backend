<?php

/**
 * FOUND-34 column-write assertions: verifies that standalone event/link writes
 * on site.platform_connections populate the resource_kind discriminator column,
 * and that account (organiser) rows leave it NULL — replacing the old
 * str_starts_with(resource_id, 'event-'/'link-') reads with a column filter.
 *
 * Mirrors tests/Feature/Site/LinkBlockColumnWriteTest.php's column-write-assertion
 * style, one domain over (platform_connections instead of blocks).
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\EventsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: custom links live in the custom_links POOL.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables(); // also creates site.platform_connections
});

// REPOINTED 2026-08-16 (slice 7 Phase 4). The INTERACTIVE add verbs — the
// Tickets & Events card and POST /platforms/{platform}/events — stopped writing
// a connection at all: a standalone event is a content item in the events pool
// now, so there is no resource_kind for them to stamp. That half is asserted as
// its absence in StandaloneEventPoolLaneTest.
//
// EventsSeeder (the link-scan / signup lane) still writes the row, deliberately
// — the synced-modal finding lane resolves against connections — so it is now
// the writer this FOUND-34 column assertion covers. The column and its
// str_starts_with-replacing filter are still live; only the caller moved.
it('seeding a standalone event stamps resource_kind = event', function () {
    $url = 'https://www.eventbrite.com/e/cool-show-123';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeEventUrl')->andReturn($url);
    $scraper->shouldReceive('fetchSingleEvent')->with($url)->andReturn([
        'name' => 'Cool Show', 'venue' => 'The Venue', 'location' => 'Melbourne',
        'startDate' => '2099-01-01T10:00:00+10:00', 'endDate' => null, 'price' => 'Free',
        'availability' => 'available', 'image' => 'https://img.example/e.jpg', 'link' => $url,
    ]);
    app()->instance(EventbriteScraper::class, $scraper);

    $user = createTenant('rk-event');

    expect(app(EventsSeeder::class)->seedStandalone($user, 'eventbrite', $url))->toStartWith('event-');

    $row = IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'eventbrite')
        ->firstOrFail();

    expect($row->resource_id)->toStartWith('event-');
    expect($row->resource_kind)->toBe('event');
});

// Convergence Phase 6 retired this behaviour rather than changing it: a custom
// link is no longer a connection at all, so there is no resource_kind to stamp.
// Kept as the inverse assertion — the column's OTHER writers (events, booking
// and reservation link cards) are covered by the cases around it, and this one
// now guards against a custom link quietly coming BACK as a connection row.
it('adding a custom link writes no connection row at all', function () {
    Queue::fake();
    Http::fake();

    $user = createTenant('rk-link');

    actingAsUser($user)->postJson('/api/platforms/custom/links', ['url' => 'https://www.example.com/x'])
        ->assertStatus(202);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(1);
});

it('connecting an organiser account leaves resource_kind NULL', function () {
    $url = 'https://www.eventbrite.com/o/my-org-456';
    $scraper = Mockery::mock(EventbriteScraper::class);
    $scraper->shouldReceive('normalizeOrgUrl')->andReturn($url);
    $scraper->shouldReceive('fetchEvents')->with($url)->andReturn([
        'organiser' => 'My Org',
        'events' => [],
    ]);
    app()->instance(EventbriteScraper::class, $scraper);

    $user = createTenant('rk-account');

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertOk();

    $row = IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', 'eventbrite')
        ->firstOrFail();

    expect($row->resource_id)->toStartWith('acct-');
    expect($row->resource_kind)->toBeNull();
});
