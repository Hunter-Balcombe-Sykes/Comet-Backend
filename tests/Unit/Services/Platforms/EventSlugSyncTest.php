<?php

use App\Services\Platforms\EventSlugSync;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupItemSlugsTable();
    $this->sync = new EventSlugSync(new ItemSlugAllocator());
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
