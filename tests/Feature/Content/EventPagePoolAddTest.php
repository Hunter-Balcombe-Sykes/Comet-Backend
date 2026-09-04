<?php

use App\Services\Content\ManualEventWriter;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\EventPageReader;
use Illuminate\Support\Facades\DB;

// EVENT-FIRST events-pool hand-add (Eventbrite-parity, 2026-08-19): a ticket
// page pasted into POST /content/pools/events/items is read as an EVENT
// (schema.org JSON-LD) and written through ManualEventWriter — the same lane
// the platform addEvent verbs use — so a KNOWN events platform's page (Luma,
// Ticketmaster, Partiful, Meetup…) lands as a real event card with
// dates/venue/price, exactly as an Eventbrite one does. Organiser pages get
// the connect hint. T3 (owner, 2026-08-20) closed the host-agnostic arm: an
// unknown host — a venue's own site included, JSON-LD or not — is refused
// with the Links hand-off; a known host without event markup keeps the card.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

/** A generic ticket page's HTML carrying one schema.org Event node. */
function eppHtml(array $overrides = []): string
{
    $node = array_merge([
        '@context' => 'https://schema.org',
        '@type' => 'MusicEvent',
        'name' => 'Warehouse Rave',
        'url' => 'https://tickets.example/warehouse-rave',
        'startDate' => '2099-03-01T20:00:00+11:00',
        'endDate' => '2099-03-02T04:00:00+11:00',
        'location' => [
            '@type' => 'Place',
            'name' => 'The Warehouse',
            'address' => ['addressLocality' => 'Melbourne'],
        ],
        'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '35.00', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/InStock'],
        'image' => 'https://img.example/rave.jpg',
    ], $overrides);

    return '<html><head><script type="application/ld+json">'.json_encode($node).'</script></head><body></body></html>';
}

function eppMockFetch(?string $body, int $status = 200): void
{
    app()->instance(SafeUrlFetcher::class, Mockery::mock(SafeUrlFetcher::class, function ($m) use ($body, $status) {
        if ($body === null) {
            $m->shouldReceive('tryFetch')->andReturn(null);
        } else {
            $m->shouldReceive('tryFetch')->andReturn([
                'status' => $status, 'body' => $body, 'finalUrl' => 'https://tickets.example/warehouse-rave', 'contentType' => 'text/html',
            ]);
        }
    }));
}

it('reads a known events platform page as a real event — dates, venue, price — and pins it', function () {
    [$user] = makeShopUser(withSite: true);
    eppMockFetch(eppHtml());

    $res = actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://lu.ma/warehouse-rave',
    ])->assertCreated();

    $item = collect($res->json('selection'))->firstWhere('headline', 'Warehouse Rave');
    expect($item)->not->toBeNull()
        ->and($item['kind'])->toBe('event')
        ->and($item['venue'])->toBe('The Warehouse')
        // The wire serves UTC — +11:00 Melbourne doors at 20:00 is 09:00Z,
        // which also pins that the local offset actually got converted.
        ->and($item['startsAt'])->toBe('2099-03-01T09:00:00+00:00');

    // A real f_occurrence row, not a bare link card.
    expect(DB::table('content.f_occurrence')->count())->toBe(1)
        ->and(DB::table('site.section_items')->where('state', 'pinned')->count())->toBe(1);
});

it('keeps the checked title as an override on the event the page became', function () {
    [$user] = makeShopUser(withSite: true);
    eppMockFetch(eppHtml());

    $res = actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://lu.ma/warehouse-rave',
        'title' => 'My Big Night',
    ])->assertCreated();

    $item = collect($res->json('selection'))->firstWhere('headline', 'My Big Night');
    expect($item)->not->toBeNull()
        ->and($item['headlineEdited'] ?? false)->toBeTrue();
});

it('refuses an organiser page with the connect hint', function () {
    [$user] = makeShopUser(withSite: true);

    actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://www.eventbrite.com.au/o/some-organiser-12345',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "That looks like a Eventbrite organiser page, not a single event. Connect it as a platform to bring in its upcoming events, or paste one event's page."]);

    actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://events.humanitix.com/host/some-host',
    ])->assertStatus(422);
});

it('refuses a TryBooking eventlist organiser page with the connect hint (F6, 2026-09-04 overnight sweep)', function () {
    [$user] = makeShopUser(withSite: true);

    // Before the fix, WebsiteLinkHarvester's TryBooking arm is host-only
    // unconditional (no /eventlist/ vs /events/ distinction), and
    // organiserPlatformLabel() had no TryBooking arm at all — so this
    // organiser listing page fell all the way through to the claimed-host
    // card fallback and was written as a bare, dateless 'event' instead of
    // being refused like its Eventbrite/Humanitix siblings.
    actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://www.trybooking.com/eventlist/constantreader',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "That looks like a TryBooking organiser page, not a single event. Connect it as a platform to bring in its upcoming events, or paste one event's page."]);

    expect(DB::connection('pgsql')->table('content.items')->count())->toBe(0);
});

it('falls through to the plain card when a KNOWN platform page carries no event markup', function () {
    [$user] = makeShopUser(withSite: true);
    eppMockFetch('<html><head></head><body>No JSON-LD here.</body></html>');

    $res = actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://lu.ma/no-markup',
    ])->assertCreated();

    // The old card behaviour, kept only for claimed hosts: titled by host.
    $item = collect($res->json('selection'))->firstWhere('headline', 'lu.ma');
    expect($item)->not->toBeNull()
        ->and(DB::table('content.f_occurrence')->count())->toBe(0);
});

it('refuses an unknown venue site even when its page carries event JSON-LD (T3)', function () {
    [$user] = makeShopUser(withSite: true);

    // The owner rule (2026-08-20): "no events or listen items for random
    // foreign links." The venue's-own-site JSON-LD add is deliberately gone —
    // the fetch must not even happen (the mock would have served a valid
    // Event node; the 422 arrives before any read).
    eppMockFetch(eppHtml());
    actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://tickets.example/warehouse-rave',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => "We don't recognise this link as an event — add it to your Links page instead."]);

    expect(DB::table('content.f_occurrence')->count())->toBe(0)
        ->and(DB::connection('pgsql')->table('content.items')->count())->toBe(0);
});

it('enforces the standalone-event cap with the writer’s own limit', function () {
    [$user] = makeShopUser(withSite: true);

    // Fill the cap through the writer itself (no HTTP, no fetch).
    $writer = app(ManualEventWriter::class);
    for ($i = 0; $i < ManualEventWriter::MAX_STANDALONE_EVENTS; $i++) {
        $written = $writer->addStandalone($user, "https://tickets.example/e{$i}", [
            'name' => "Event {$i}", 'startDate' => '2099-01-01T10:00:00+10:00', 'link' => "https://tickets.example/e{$i}",
        ]);
        expect($written)->not->toBeNull();
    }

    eppMockFetch(eppHtml());
    actingAsUser($user)->postJson('/api/content/pools/events/items', [
        'url' => 'https://lu.ma/warehouse-rave',
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'You can add up to '.ManualEventWriter::MAX_STANDALONE_EVENTS.' events.']);
});

it('reads a non-Event node with a stray startDate as no event at all', function () {
    [$user] = makeShopUser(withSite: true);

    // A page whose only startDate sits on a non-Event @type — the generic
    // reader's @type guard must refuse it (Eventbrite's own startDate-only
    // rule is safe only because Eventbrite emits Event subtypes).
    eppMockFetch(eppHtml(['@type' => 'Season']));

    $reader = app(EventPageReader::class);
    expect($reader->read('https://tickets.example/warehouse-rave'))->toBeNull();
});
