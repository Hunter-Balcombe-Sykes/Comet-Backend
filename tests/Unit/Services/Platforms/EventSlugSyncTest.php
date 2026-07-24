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

it('mints slugs for every event in an account payload upcoming list', function () {
    $this->sync->sync('user-1', [
        'url' => 'https://eventbrite.com/o/foo',
        'organiser' => 'Foo',
        'upcoming' => [
            ['id' => 'hexaaa', 'name' => 'Trivia Night'],
            ['id' => 'hexbbb', 'name' => 'Comedy Gala'],
        ],
        'hiddenEventIds' => [],
    ]);
    expect(eventSlug('hexaaa'))->toBe('trivia-night');
    expect(eventSlug('hexbbb'))->toBe('comedy-gala');
});

it('mints a slug for a standalone event payload', function () {
    $this->sync->sync('user-1', ['kind' => 'event', 'id' => 'hexccc', 'name' => 'Sold Out Show']);
    expect(eventSlug('hexccc'))->toBe('sold-out-show');
});

it('is idempotent across re-sync (stable hex → same slug, no new rows)', function () {
    $p = ['kind' => 'event', 'id' => 'hexccc', 'name' => 'Sold Out Show'];
    $this->sync->sync('user-1', $p);
    $this->sync->sync('user-1', $p);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(1);
    expect(eventSlug('hexccc'))->toBe('sold-out-show');
});

it('ignores a non-event payload', function () {
    $this->sync->sync('user-1', ['images' => ['a.jpg'], 'videoUrl' => null]);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});
