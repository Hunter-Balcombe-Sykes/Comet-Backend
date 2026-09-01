<?php

use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SchemaOrgEventProjector;
use App\Services\Platforms\ScrapeCreators\FacebookEventDetailsNormalizer;
use App\Services\Platforms\ScrapeCreators\FacebookEventsNormalizer;

// Item 11a (2026-09-01): Facebook events → the events pool, pinned against
// RECORDED live payloads (/v1/facebook/profile/events + /v1/facebook/event/
// details answers for The Tote, Collingwood — the exact AU-venue case the
// item exists for). Two properties, the vendor lane's standing frame:
//
//  1. The details normalizer lands the SAME doc vocabulary Eventbrite and
//     Humanitix land, proven by pushing its output through the REAL
//     SchemaOrgEventProjector — one projector, three sources, no new pool
//     semantics.
//  2. Any other answer shape is a vendor miss (null), never an empty
//     calendar — the husk doctrine, gated on shape and vendor flags, not
//     HTTP status or clocks.

function scFbEventsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-profile-events.json')),
        true
    );
}

function scFbEventDetailsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-event-details.json')),
        true
    );
}

// ── (a) Profile-events list: upcoming stubs the detail fetches enumerate ────

it('normalizes recorded profile events into id-keyed upcoming stubs', function () {
    $rows = app(FacebookEventsNormalizer::class)->events(scFbEventsFixture());

    expect($rows)->toHaveCount(8);

    $shonen = collect($rows)->firstWhere('id', '1759413615194443');
    expect($shonen['name'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        ->and($shonen['url'])->toBe('https://www.facebook.com/events/1759413615194443/')
        ->and($shonen['start_timestamp'])->toBe(1791966600);

    // A page-typed place still yields venue + city…
    $drencher = collect($rows)->firstWhere('id', '1053591417622856');
    expect($drencher['venue'])->toBe('The Tote')
        ->and($drencher['city'])->toBe('Melbourne');

    // …and a touring act's event with event_place:null lands with nulls, not a skip.
    $union = collect($rows)->firstWhere('id', '2318347295599702');
    expect($union['venue'])->toBeNull()
        ->and($union['city'])->toBeNull();
});

it('drops cancelled, past, and id-less events on vendor flags alone', function () {
    $rows = app(FacebookEventsNormalizer::class)->events([
        'success' => true, 'credits_charged' => 1,
        'events' => [
            ['id' => '1', 'name' => 'Keeper', 'start_timestamp' => 1789200000],
            ['id' => '2', 'name' => 'Cancelled gig', 'start_timestamp' => 1789200000, 'is_canceled' => true],
            ['id' => '3', 'name' => 'Last year', 'start_timestamp' => 1750000000, 'is_past' => true],
            ['name' => 'No id', 'start_timestamp' => 1789200000],
            ['id' => '5', 'name' => 'No start'],
        ],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('1')
        // No url in the synthesized row — the composed fallback must fill it.
        ->and($rows[0]['url'])->toBe('https://www.facebook.com/events/1/');
});

it('reads an events husk as a vendor miss, never as an empty calendar', function () {
    $empty = json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-profile-events-empty.json')),
        true
    );

    expect(app(FacebookEventsNormalizer::class)->events($empty))->toBeNull()
        ->and(app(FacebookEventsNormalizer::class)->events(['success' => false, 'message' => 'nope']))->toBeNull()
        ->and(app(FacebookEventsNormalizer::class)->events(['success' => true, 'credits_charged' => 1]))->toBeNull();
});

// ── (b) Event details: the shared schema.org-event doc vocabulary ───────────

it('lands recorded event details as the doc shape Eventbrite and Humanitix share', function () {
    $doc = app(FacebookEventDetailsNormalizer::class)->doc(scFbEventDetailsFixture());

    expect($doc['name'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        // The ticket URL is the item's actionable link — it outranks the FB page.
        ->and($doc['url'])->toBe('https://tickets.oztix.com.au/outlet/event/d2b024d0-2d5f-4ad0-9de7-9c84e68698d5')
        // start_timestamp 1791966600 (08:30 UTC) + the sentence's 7:30 PM AEDT wall → +11:00.
        ->and($doc['start_date'])->toBe('2026-10-14T19:30:00+11:00')
        ->and($doc['venue'])->toContain('Johnston Street')
        ->and($doc['locality'])->toBe('Melbourne')
        ->and($doc['description'])->toContain('Shonen Knife')
        ->and($doc['image'])->toStartWith('https://')
        // No structured price on FB events — an offer must never be invented.
        ->and($doc)->not->toHaveKeys(['price_min', 'currency', 'end_date']);
});

it('projects the landed doc through the real events projector unchanged', function () {
    $doc = app(FacebookEventDetailsNormalizer::class)->doc(scFbEventDetailsFixture());

    $item = (new SchemaOrgEventProjector)->project(new RecordView($doc, '1759413615194443'));

    expect($item['kind'])->toBe('event')
        ->and($item['headline'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        ->and($item['facets']['f_link']['url'])->toStartWith('https://tickets.oztix.com.au/')
        ->and($item['facets']['f_occurrence']['starts_at_local'])->toBe('2026-10-14T19:30:00+11:00')
        ->and($item['facets']['f_occurrence']['starts_at_utc'])->toBe('2026-10-14T08:30:00Z')
        ->and($item['facets']['f_occurrence']['zone_confidence'])->toBe('offset_only')
        ->and($item['facets']['f_place']['locality'])->toBe('Melbourne')
        ->and($item['media'][0]['role'])->toBe('cover')
        ->and($item['offers'])->toBe([]);
});

it('degrades to a UTC offset when the day_time_sentence is unparseable', function () {
    $body = scFbEventDetailsFixture();
    unset($body['day_time_sentence']);

    $doc = app(FacebookEventDetailsNormalizer::class)->doc($body);

    // The instant is still exact — only the local display time coarsens.
    expect($doc['start_date'])->toBe('2026-10-14T08:30:00+00:00');
});

it('falls back to the FB event page when no ticket url is present', function () {
    $body = scFbEventDetailsFixture();
    unset($body['ticket_url']);

    expect(app(FacebookEventDetailsNormalizer::class)->doc($body)['url'])
        ->toBe('https://www.facebook.com/events/1759413615194443/');
});

it('reads a details husk, a cancelled event, and a dateless event as vendor misses', function () {
    $normalizer = app(FacebookEventDetailsNormalizer::class);

    $cancelled = scFbEventDetailsFixture();
    $cancelled['is_canceled'] = true;

    $dateless = scFbEventDetailsFixture();
    $dateless['start_timestamp'] = null;

    expect($normalizer->doc(['success' => true, 'credits_charged' => 1]))->toBeNull()
        ->and($normalizer->doc(['success' => false, 'message' => 'nope']))->toBeNull()
        ->and($normalizer->doc($cancelled))->toBeNull()
        ->and($normalizer->doc($dateless))->toBeNull();
});
