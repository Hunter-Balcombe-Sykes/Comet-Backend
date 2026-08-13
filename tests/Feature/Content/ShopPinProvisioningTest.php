<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function storefront(string $userId, int $position, string $label): string
{
    $id = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'storefront', 'label' => $label,
        'is_user_created' => false, 'position' => $position,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function productIn(string $userId, string $collectionId, int $position, string $title): string
{
    // idx_content_sources_manual allows exactly one manual content.sources row
    // per user (20260727140000) — memoize so repeated calls for the same
    // tenant don't collide on it the way a fresh poolSource() call each time would.
    static $manualSources = [];
    $sourceId = $manualSources[$userId] ??= poolSource($userId, null);
    $itemId = poolItem($userId, $sourceId, 'product', $title, '2026-08-01T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);

    return $itemId;
}

it('pins every product in catalogue order across stores', function () {
    [$pro, $siteId] = poolTenant();
    $storeB = storefront($pro->id, 1, 'Second store');
    $storeA = storefront($pro->id, 0, 'First store');

    $a0 = productIn($pro->id, $storeA, 0, 'A first');
    $a1 = productIn($pro->id, $storeA, 1, 'A second');
    $b0 = productIn($pro->id, $storeB, 0, 'B first');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // Store position first, then catalogue position within the store.
    expect($pins)->toBe([$a0, $a1, $b0]);

    expect(DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $a0)->value('sort_key'))->toEqual(1.0);
});

it('is idempotent and never rewrites an existing pin', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    $first = productIn($pro->id, $store, 0, 'First');
    productIn($pro->id, $store, 1, 'Second');

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    // The owner drags the first product to the end.
    DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $first)->update(['sort_key' => 99.0]);

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    expect(DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $first)->value('sort_key'))->toEqual(99.0)
        ->and(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(2);
});

it('reports counts under --dry-run and writes nothing', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'Only');

    $this->artisan('content:provision-shop-pins', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('site.section_items')->count())->toBe(0);
});

it('fires all three cache-invalidation lanes per site', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'Only');

    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $this->travelTo(now()->addMinute());

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    // Lane 2: the payload cache key composes from sites.updated_at.
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    // Lane 1: the document build state.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->exists())->toBeTrue();
    // Lane 3: the CDN outlives the origin write.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
