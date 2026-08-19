<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\EventPageReader;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Events-parity (2026-08-19): the scan lanes seed a pool EVENT item from ANY
// events brand's single-event page — Luma, Partiful, Ticketmaster, Ticketek,
// Oztix, TryBooking, Resident Advisor — through the one generic schema.org
// reader, exactly as they always did for Eventbrite/Humanitix through the
// bespoke scrapers. classify() hands the lanes the REAL brand keys now (the
// 'events-custom' pseudo-slug refused seeding by construction).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function gesUser(string $h): User
{
    $user = User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h), 'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$h}@example.com",
    ]);
    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('seeds a pool event item from a generic brand through the schema.org reader', function () {
    $user = gesUser('luma'.Str::lower(Str::random(6)));

    app()->instance(EventPageReader::class, Mockery::mock(EventPageReader::class, function ($m) {
        $m->shouldReceive('read')->with('https://lu.ma/abc123')->andReturn([
            'canonical' => 'https://lu.ma/abc123',
            'event' => [
                'name' => 'Rooftop Social', 'venue' => 'The Roof', 'location' => 'Sydney',
                'startDate' => '2099-02-01T18:00:00+11:00', 'endDate' => null,
                'price' => 'Free', 'priceMin' => 0.0, 'currency' => 'AUD',
                'availability' => 'available', 'image' => null, 'link' => 'https://lu.ma/abc123',
            ],
        ]);
    }));

    $written = app(EventsSeeder::class)->seedStandalone($user, 'luma', 'https://lu.ma/abc123');

    expect($written)->toBe('https://lu.ma/abc123');

    $item = DB::connection('pgsql')->table('content.items')
        ->where('user_id', (string) $user->id)->where('kind', 'event')->first();
    expect($item)->not->toBeNull()
        ->and($item->headline_cache)->toBe('Rooftop Social');

    // Pool item ONLY — the standalone connection-row lane is retired (R7).
    expect(DB::table('site.platform_connections')->count())->toBe(0);
});

it('refuses a platform key outside both rosters', function () {
    $user = gesUser('x'.Str::lower(Str::random(6)));

    expect(app(EventsSeeder::class)->seedStandalone($user, 'not-a-platform', 'https://x.test/e/1'))->toBeNull();
});

it('cards nothing when the generic reader finds no event on the page', function () {
    $user = gesUser('y'.Str::lower(Str::random(6)));

    app()->instance(EventPageReader::class, Mockery::mock(EventPageReader::class, function ($m) {
        $m->shouldReceive('read')->andReturn(null);
    }));

    expect(app(EventsSeeder::class)->seedStandalone($user, 'ticketek', 'https://ticketek.com.au/somewhere'))->toBeNull()
        ->and(DB::connection('pgsql')->table('content.items')->count())->toBe(0);
});

it('classifies every events brand to its real key with the account/item split', function (string $url, string $platform, string $category) {
    $classified = app(WebsiteLinkHarvester::class)->classify($url);

    expect($classified)->not->toBeNull()
        ->and($classified['platform'])->toBe($platform)
        ->and($classified['category'])->toBe($category);
})->with([
    // organiser (account) shapes
    ['https://lu.ma/user/some-host', 'luma', 'event-organiser'],
    ['https://partiful.com/u/SomeHost', 'partiful', 'event-organiser'],
    ['https://www.eventbrite.com.au/o/some-org-123', 'eventbrite', 'event-organiser'],
    ['https://events.humanitix.com/host/some-host', 'humanitix', 'event-organiser'],
    // item (event) shapes
    ['https://lu.ma/abc123', 'luma', 'event'],
    ['https://partiful.com/e/AbC123', 'partiful', 'event'],
    ['https://www.ticketmaster.com.au/show-tickets/event/25006', 'ticketmaster', 'event'],
    ['https://premier.ticketek.com.au/shows/show.aspx?sh=X', 'ticketek', 'event'],
    ['https://tickets.oztix.com.au/outlet/event/uuid-here', 'oztix', 'event'],
    ['https://www.trybooking.com/events/landing/12345', 'trybooking', 'event'],
    ['https://ra.co/events/1234567', 'resident-advisor', 'event'],
]);

it('keeps non-event pages on ticket-seller hosts away from the event category', function (string $url) {
    $classified = app(WebsiteLinkHarvester::class)->classify($url);

    expect($classified['category'] ?? null)->not->toBe('event');
})->with([
    'https://www.ticketmaster.com.au/taylor-swift-tickets/artist/1094215', // artist page embeds Event lists
    'https://ra.co/dj/some-artist', // DJ profile embeds Event lists
]);
