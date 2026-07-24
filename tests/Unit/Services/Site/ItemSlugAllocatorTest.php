<?php

use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupItemSlugsTable();
    $this->alloc = new ItemSlugAllocator();
    $this->u = 'user-1';
});

function currentSlug(string $user, string $type, string $key): ?string
{
    return DB::connection('pgsql')->table('site.item_slugs')
        ->where('user_id', $user)->where('item_type', $type)
        ->where('item_key', $key)->where('is_current', 1)->value('slug');
}

it('mints a bare slug from the name', function () {
    $slug = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Margherita Pizza');
    expect($slug)->toBe('margherita-pizza');
});

it('suffixes collisions -2, -3 within a profile', function () {
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos'))->toBe('fish-tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k3', 'Fish Tacos'))->toBe('fish-tacos-3');
});

it('is idempotent for the same item + name', function () {
    $a = $this->alloc->ensureCurrent($this->u, 'event', 'hex1', 'Trivia Night');
    $b = $this->alloc->ensureCurrent($this->u, 'event', 'hex1', 'Trivia Night');
    expect($a)->toBe('trivia-night')->and($b)->toBe('trivia-night');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(1);
});

it('renames: new current slug, old kept as redirect', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $new = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name');
    expect($new)->toBe('new-name');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('new-name');
    $rows = DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_key', 'k1')->pluck('is_current', 'slug');
    expect((int) $rows['old-name'])->toBe(0)->and((int) $rows['new-name'])->toBe(1);
});

it('no-ops a cosmetic edit that slugs the same', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos!!!');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(1);
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('fish-tacos');
});

it('reactivates a prior slug on rename-back instead of spawning -3', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Alpha');   // alpha
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Beta');    // beta (alpha retired)
    $back = $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Alpha');
    expect($back)->toBe('alpha');
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('alpha');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(2);
});

it('falls back to item-<key> for an unsluggable name', function () {
    $slug = $this->alloc->ensureCurrent($this->u, 'menu_item', 'abcdef123456', '🍕🍕');
    expect($slug)->toBe('item-abcdef');
});

it('isolates slugs per user (no cross-profile collision)', function () {
    expect($this->alloc->ensureCurrent('user-1', 'menu_item', 'k1', 'Opening Night'))->toBe('opening-night');
    expect($this->alloc->ensureCurrent('user-2', 'menu_item', 'k9', 'Opening Night'))->toBe('opening-night');
});

it('forget() removes rows and frees the slug for reuse', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->forget($this->u, 'menu_item', 'k1');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(0);
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos');
});

// ── lookupCurrent(): shared batch read for public API controllers ──

it('lookupCurrent returns slug + aliases (retired slugs + raw key) for each requested item', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Untouched');

    $map = $this->alloc->lookupCurrent($this->u, 'menu_item', ['k1', 'k2', 'k-missing']);

    expect($map['k1']['slug'])->toBe('new-name');
    expect($map['k1']['aliases'])->toEqualCanonicalizing(['old-name', 'k1']);
    expect($map['k2']['slug'])->toBe('untouched');
    expect($map['k2']['aliases'])->toBe(['k2']);
    expect($map)->not->toHaveKey('k-missing');
});

it('lookupCurrent scopes to the given item_type and user', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'A Dish');
    $this->alloc->ensureCurrent($this->u, 'event', 'k1', 'Same Key Different Type');
    $this->alloc->ensureCurrent('user-2', 'menu_item', 'k1', 'Someone Elses Dish');

    $map = $this->alloc->lookupCurrent($this->u, 'menu_item', ['k1']);

    expect($map['k1']['slug'])->toBe('a-dish');
});

it('lookupCurrent returns an empty map for an empty key list', function () {
    expect($this->alloc->lookupCurrent($this->u, 'menu_item', []))->toBe([]);
});
