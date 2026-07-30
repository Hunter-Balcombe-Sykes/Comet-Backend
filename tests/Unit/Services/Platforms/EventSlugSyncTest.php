<?php

use App\Services\Platforms\EventSlugSync;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupItemSlugsTable();
    $this->sync = new EventSlugSync(new ItemSlugAllocator);
});

function eventSlug(string $key): ?string
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'event')->where('item_key', $key)
        ->where('is_current', 1)->value('slug');
}

it('mints slugs for every event in the list', function () {
    $this->sync->syncEvents('user-1', [
        ['id' => 'hexaaa', 'name' => 'Trivia Night'],
        ['id' => 'hexbbb', 'name' => 'Comedy Gala'],
    ]);
    expect(eventSlug('hexaaa'))->toBe('trivia-night');
    expect(eventSlug('hexbbb'))->toBe('comedy-gala');
});

it('mints a slug for a single-event list (standalone row shape)', function () {
    $this->sync->syncEvents('user-1', [['id' => 'hexccc', 'name' => 'Sold Out Show']]);
    expect(eventSlug('hexccc'))->toBe('sold-out-show');
});

it('is idempotent across re-sync (stable hex → same slug, no new rows)', function () {
    $events = [['id' => 'hexccc', 'name' => 'Sold Out Show']];
    $this->sync->syncEvents('user-1', $events);
    $this->sync->syncEvents('user-1', $events);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(1);
    expect(eventSlug('hexccc'))->toBe('sold-out-show');
});

it('does not churn an event legitimately parked on a -N suffix across re-syncs', function () {
    // 271-SEM-1 churn guard. syncEvents() calls ensureCurrent() for EVERY event on
    // EVERY connection save with no change gate, so a no-op rule that only accepted
    // the bare base would walk hexbbb up -3, -4, -5… once per save, burning a fresh
    // public URL each time and retiring the last one.
    $events = [
        ['id' => 'hexaaa', 'name' => 'Gig Night'],
        ['id' => 'hexbbb', 'name' => 'Gig Night'],
    ];

    $this->sync->syncEvents('user-1', $events);
    expect(eventSlug('hexaaa'))->toBe('gig-night');
    expect(eventSlug('hexbbb'))->toBe('gig-night-2');

    $this->sync->syncEvents('user-1', $events);
    $this->sync->syncEvents('user-1', $events);
    $this->sync->syncEvents('user-1', $events);

    expect(eventSlug('hexaaa'))->toBe('gig-night');
    expect(eventSlug('hexbbb'))->toBe('gig-night-2');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
});

it('skips malformed entries (missing id/name, or a non-array element)', function () {
    $this->sync->syncEvents('user-1', [
        ['name' => 'No Id'],
        ['id' => 'hexonly'],
        'not-an-array',
        ['id' => 'hexok', 'name' => 'Good Event'],
    ]);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(1);
    expect(eventSlug('hexok'))->toBe('good-event');
});

it('ignores an empty list', function () {
    $this->sync->syncEvents('user-1', []);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});

// ── eventIds() + retireEvents() (271-DINT-4) ──────────────────────────

it('eventIds() plucks ids from an account payload upcoming list', function () {
    $ids = EventSlugSync::eventIds(null, [
        'url' => 'https://x/o/1',
        'organiser' => 'Org',
        'upcoming' => [
            ['id' => 'hexaaa', 'name' => 'Trivia Night'],
            ['id' => 'hexbbb', 'name' => 'Comedy Gala'],
        ],
    ]);
    expect($ids)->toBe(['hexaaa', 'hexbbb']);
});

it('eventIds() plucks the single id from a standalone-event payload', function () {
    $ids = EventSlugSync::eventIds('event', ['kind' => 'event', 'id' => 'hexccc', 'name' => 'Sold Out Show']);
    expect($ids)->toBe(['hexccc']);
});

it('eventIds() skips malformed entries and dedupes', function () {
    $ids = EventSlugSync::eventIds(null, ['upcoming' => [
        ['name' => 'No Id'],
        ['id' => ''],
        ['id' => 123],
        'not-an-array',
        ['id' => 'hexok', 'name' => 'Good'],
        ['id' => 'hexok', 'name' => 'Duplicate'],
    ]]);
    expect($ids)->toBe(['hexok']);
});

it('eventIds() returns an empty list for a null / non-array / shapeless payload', function () {
    expect(EventSlugSync::eventIds(null, null))->toBe([]);
    expect(EventSlugSync::eventIds(null, 'a json string'))->toBe([]);
    expect(EventSlugSync::eventIds(null, []))->toBe([]);
    expect(EventSlugSync::eventIds('event', []))->toBe([]);
});

it('eventIds() keeps an all-digit id as a string (never an int array key)', function () {
    // 16 hex chars can legitimately be all digits (~1 in 2000), and an int here
    // would leak out of the declared list<string> into forgetMany()'s binds.
    $ids = EventSlugSync::eventIds(null, ['upcoming' => [['id' => '1234567890123456', 'name' => 'Numeric']]]);
    expect($ids)->toBe(['1234567890123456']);
    expect($ids[0])->toBeString();
});

it('retireEvents() hard-deletes the named events and leaves the rest alone', function () {
    $this->sync->syncEvents('user-1', [
        ['id' => 'hexaaa', 'name' => 'Trivia Night'],
        ['id' => 'hexbbb', 'name' => 'Comedy Gala'],
    ]);

    $this->sync->retireEvents('user-1', ['hexaaa']);

    expect(eventSlug('hexaaa'))->toBeNull();
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'hexaaa')->count())->toBe(0);
    expect(eventSlug('hexbbb'))->toBe('comedy-gala');
});

it('retireEvents() no-ops on an empty or all-blank list', function () {
    $this->sync->syncEvents('user-1', [['id' => 'hexaaa', 'name' => 'Trivia Night']]);
    $this->sync->retireEvents('user-1', []);
    $this->sync->retireEvents('user-1', ['']);
    expect(eventSlug('hexaaa'))->toBe('trivia-night');
});
