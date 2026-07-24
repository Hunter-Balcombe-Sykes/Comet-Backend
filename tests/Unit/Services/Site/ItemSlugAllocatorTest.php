<?php

use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupItemSlugsTable();
    $this->alloc = new ItemSlugAllocator;
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

// ── batch helpers (271-DINT-1): one query for a whole rebuilt collection ──

it('forgetMany() hard-deletes every row for each key and frees the slugs', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'New Name'); // k1 now has a retired row too
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k3', 'Kept Dish');

    $this->alloc->forgetMany($this->u, 'menu_item', ['k1', 'k2']);

    // Retired rows go too — the (user_id, slug) unique index is NOT partial, so
    // leaving one behind would keep the slug squatted.
    expect(DB::connection('pgsql')->table('site.item_slugs')->whereIn('item_key', ['k1', 'k2'])->count())->toBe(0);
    expect(currentSlug($this->u, 'menu_item', 'k3'))->toBe('kept-dish');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k9', 'Fish Tacos'))->toBe('fish-tacos');
});

it('forgetMany() scopes to the user and item type, and no-ops on an empty list', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'A Dish');
    $this->alloc->ensureCurrent($this->u, 'event', 'k1', 'An Event');
    $this->alloc->ensureCurrent('user-2', 'menu_item', 'k1', 'Someone Elses Dish');

    $this->alloc->forgetMany($this->u, 'menu_item', []);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(3);

    $this->alloc->forgetMany($this->u, 'menu_item', ['k1']);
    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBeNull();
    expect(currentSlug($this->u, 'event', 'k1'))->toBe('an-event');
    expect(currentSlug('user-2', 'menu_item', 'k1'))->toBe('someone-elses-dish');
});

it('forgetMany() chunks past the bind-parameter ceiling', function () {
    // 2500 keys → 3 chunks of ≤1000 binds. A single whereIn would exceed
    // SQLite's 999-bind limit on older builds and is uncomfortably close to
    // Postgres' 65535 for a large profile.
    $keys = [];
    foreach (range(1, 2500) as $n) {
        $keys[] = "k{$n}";
        DB::connection('pgsql')->table('site.item_slugs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->u, 'item_type' => 'menu_item', 'item_key' => "k{$n}",
            'slug' => "dish-{$n}", 'is_current' => 1, 'created_at' => now()->toDateTimeString(),
        ]);
    }
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2500);

    $this->alloc->forgetMany($this->u, 'menu_item', $keys);

    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});

it('ensureCurrentMany() mints missing slugs and writes nothing for unchanged ones', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Beef Tacos');
    $before = DB::connection('pgsql')->table('site.item_slugs')->orderBy('item_key')->get()->toArray();

    DB::enableQueryLog();
    $this->alloc->ensureCurrentMany($this->u, 'menu_item', [
        'k1' => 'Fish Tacos',      // unchanged
        'k2' => 'Beef Tacos!!!',   // cosmetic edit — same base, still a no-op
        'k3' => 'Pork Tacos',      // new
    ]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(currentSlug($this->u, 'menu_item', 'k3'))->toBe('pork-tacos');
    // k1/k2 rows are byte-identical — no delete-and-remint, no is_current churn.
    $after = DB::connection('pgsql')->table('site.item_slugs')->whereIn('item_key', ['k1', 'k2'])->orderBy('item_key')->get()->toArray();
    expect($after)->toEqual($before);
    // Zero writes for the two no-op keys: the only INSERT is k3's.
    $writes = array_values(array_filter($queries, fn ($q) => preg_match('~^\s*(insert|update|delete)~i', $q['query']) === 1));
    expect($writes)->toHaveCount(1);
});

it('ensureCurrentMany() keeps a live -N suffix instead of re-minting the bare base', function () {
    // Two dishes sharing a base: k2 legitimately lives on fish-tacos-2.
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Fish Tacos');
    expect($this->alloc->ensureCurrent($this->u, 'menu_item', 'k2', 'Fish Tacos'))->toBe('fish-tacos-2');

    $this->alloc->ensureCurrentMany($this->u, 'menu_item', ['k1' => 'Fish Tacos', 'k2' => 'Fish Tacos']);

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('fish-tacos');
    expect(currentSlug($this->u, 'menu_item', 'k2'))->toBe('fish-tacos-2');
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(2);
});

it('ensureCurrentMany() re-slugs a renamed item and keeps the old slug as a redirect', function () {
    $this->alloc->ensureCurrent($this->u, 'menu_item', 'k1', 'Old Name');

    $this->alloc->ensureCurrentMany($this->u, 'menu_item', ['k1' => 'New Name']);

    expect(currentSlug($this->u, 'menu_item', 'k1'))->toBe('new-name');
    expect(DB::connection('pgsql')->table('site.item_slugs')->where('item_key', 'k1')->count())->toBe(2);
});

it('ensureCurrentMany() no-ops on an empty map', function () {
    $this->alloc->ensureCurrentMany($this->u, 'menu_item', []);
    expect(DB::connection('pgsql')->table('site.item_slugs')->count())->toBe(0);
});
