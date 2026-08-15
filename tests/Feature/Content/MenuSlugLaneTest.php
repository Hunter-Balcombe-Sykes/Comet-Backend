<?php

use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Migration\MenuBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 4 Unit 1 — the 301 lane. 318 live dish permalinks move from
// site.item_slugs (keyed by legacy uuid) to content.item_slugs (keyed by the
// content item id), and ongoing minting re-homes off MenuItemObserver onto
// ProjectionWriter's SLUGGED_KINDS pass.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function landedMenuItemId(): string
{
    return (string) DB::connection('pgsql')->table('content.items')
        ->where('kind', 'menu_item')->value('id');
}

function legacySlug(string $userId, string $dishId, string $slug, bool $isCurrent, ?string $retiredAt = null): void
{
    DB::connection('pgsql')->table('site.item_slugs')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'item_type' => 'menu_item',
        'item_key' => $dishId,
        'slug' => $slug,
        'is_current' => $isCurrent ? 1 : 0,
        'created_at' => now()->subDays(30)->toDateTimeString(),
        'retired_at' => $retiredAt,
    ]);
}

it('mints a slug for a landed dish without anyone calling the allocator', function () {
    // ProjectionWriter::refreshItemCaches() mints for every kind in
    // SLUGGED_KINDS. Widening that const IS the re-homing off MenuItemObserver
    // — there is no new call site and no new observer.
    seedMenuWithDishes(['Iced Latte']);

    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', landedMenuItemId())->where('is_current', true)->value('slug'))
        ->toBe('iced-latte');
});

it('carries the legacy slug, its retired history and its created_at', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte']);
    $dishId = (string) DB::connection('pgsql')->table('site.menu_items')->value('id');

    legacySlug($userId, $dishId, 'iced-latte', isCurrent: true);
    legacySlug($userId, $dishId, 'cold-latte', isCurrent: false, retiredAt: now()->subDays(2)->toDateTimeString());

    app(MenuBackfiller::class)->run();

    $rows = DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', landedMenuItemId())->get()->keyBy('slug');

    expect($rows)->toHaveCount(2)
        ->and((bool) $rows['iced-latte']->is_current)->toBeTrue()
        ->and((bool) $rows['cold-latte']->is_current)->toBeFalse()
        // The 301 is the retired row. Losing retired_at loses the redirect.
        ->and($rows['cold-latte']->retired_at)->not->toBeNull();
});

it('301s a renamed dish through the retired slug', function () {
    // The proof the definition of done asks for. Dev's retired set is EMPTY at
    // slice entry — MenuFetchJob FORGETS a vendor-renamed dish's slug rather
    // than retiring it — so the 301 has to be created, not migrated.
    seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();

    $itemId = landedMenuItemId();
    $userId = (string) DB::connection('pgsql')->table('content.items')->where('id', $itemId)->value('user_id');

    app(ContentItemSlugAllocator::class)->ensureCurrent($userId, $itemId, 'Cold Brew');

    $lookup = app(ContentItemSlugAllocator::class)->lookupCurrent($userId, [$itemId]);

    expect($lookup[$itemId]['slug'])->toBe('cold-brew')
        ->and($lookup[$itemId]['aliases'])->toContain('iced-latte');
});

it('refuses to hand one user\'s slug to a different item', function () {
    // Both tables enforce UNIQUE (user_id, slug), NON-partial, so even a
    // retired row squats its name. A collision is counted and skipped, never an
    // exception mid-run that leaves half the permalinks moved.
    [$userId] = seedMenuWithDishes(['Iced Latte']);
    $dishId = (string) DB::connection('pgsql')->table('site.menu_items')->value('id');

    $otherItem = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $otherItem, 'user_id' => $userId, 'kind' => 'event',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('content.item_slugs')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'item_id' => $otherItem,
        'slug' => 'taken-name', 'is_current' => true, 'created_at' => now()->toDateTimeString(),
    ]);
    legacySlug($userId, $dishId, 'taken-name', isCurrent: true);

    expect(app(MenuBackfiller::class)->run()['slugs_collided'])->toBe(1);
});

it('counts a legacy slug whose dish never landed rather than dropping it', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte']);

    legacySlug($userId, (string) Str::uuid(), 'ghost-dish', isCurrent: true);

    expect(app(MenuBackfiller::class)->run()['slugs_unmapped'])->toBe(1);
});

it('is idempotent — a second run migrates no new slug rows', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte']);
    $dishId = (string) DB::connection('pgsql')->table('site.menu_items')->value('id');
    legacySlug($userId, $dishId, 'iced-latte', isCurrent: true);

    app(MenuBackfiller::class)->run();
    $after = DB::connection('pgsql')->table('content.item_slugs')->count();
    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.item_slugs')->count())->toBe($after);
});
