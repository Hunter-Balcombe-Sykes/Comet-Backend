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

it('reports counts under --dry-run and provisions nothing at all', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'Only');

    // A fresh tenant has never opened its Shop page — no page/section yet.
    // That is the common case a dry run targets, so --dry-run must not
    // create either while still reporting the pin it WOULD make.
    $this->artisan('content:provision-shop-pins', ['--dry-run' => true])
        ->expectsOutputToContain('would pin 1, left alone 0, across 0 site(s).')
        ->assertSuccessful();

    expect(DB::table('site.pages')->where('site_id', $siteId)->count())->toBe(0)
        ->and(DB::table('site.sections')->where('site_id', $siteId)->count())->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('dry-run against an already-provisioned section reports left-alone without writing', function () {
    [$pro, $siteId] = poolTenant();
    $store = storefront($pro->id, 0, 'Store');
    productIn($pro->id, $store, 0, 'First');

    // Real run provisions the section and pins the one existing product.
    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');
    $pinsBefore = DB::table('site.section_items')->where('section_id', $sectionId)->count();

    // A second product arrives after the section already exists.
    productIn($pro->id, $store, 1, 'Second');

    // A later dry run against a site that already has the section must still
    // look it up (not re-provision) and must not insert the still-missing pin.
    $this->artisan('content:provision-shop-pins', ['--dry-run' => true])
        ->expectsOutputToContain('would pin 1, left alone 1, across 0 site(s).')
        ->assertSuccessful();

    expect(DB::table('site.sections')->where('site_id', $siteId)->count())->toBe(1)
        ->and(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe($pinsBefore);
});

/**
 * Local to this file only — poolItem() cannot take a caller-supplied id (a
 * peer depends on its signature; not touching it). Mirrors what poolItem()
 * writes to content.items/content.source_items/content.f_published, but with
 * an explicit id, so the tie-break test below can force item-id order and
 * physical insertion order to DISAGREE. Str::uuid() is random v4 — leaving
 * ids to chance would make the tie-break assertion pass on a coin flip
 * whether or not the command's orderBy('i.id') actually runs.
 */
function productWithExplicitId(string $userId, string $collectionId, int $position, string $itemId): void
{
    static $manualSources = [];
    $sourceId = $manualSources[$userId] ??= poolSource($userId, null);

    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'product',
        'headline_cache' => 'Tie item', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDays(30), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'x:'.Str::random(8), 'item_id' => $itemId, 'kind' => 'product',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_published')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'published_from' => '2026-08-01T00:00:00Z', 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);
}

it('breaks a tied collection and item position by item id, deterministically', function () {
    [$pro, $siteId] = poolTenant();
    // Same collection position on both stores — c.position alone can't order them.
    $storeA = storefront($pro->id, 0, 'Store A');
    $storeB = storefront($pro->id, 0, 'Store B');

    // Two explicit ids, sorted up front so their lexical order is known
    // before either row exists.
    $idOne = (string) Str::uuid();
    $idTwo = (string) Str::uuid();
    [$lower, $higher] = strcmp($idOne, $idTwo) <= 0 ? [$idOne, $idTwo] : [$idTwo, $idOne];

    // Same collection_item position within each store too — c.position AND
    // ci.position are both genuinely tied, so i.id is the only remaining
    // order key. Insert the HIGHER id first and the LOWER id second: physical
    // insertion order is then the exact reverse of ascending item-id order.
    // If the command's orderBy('i.id') were ever dropped, the tied rows would
    // fall back to something close to insertion order — [higher, lower] —
    // which disagrees with the asserted [lower, higher] on every run, not by
    // chance.
    productWithExplicitId($pro->id, $storeA, 0, $higher);
    productWithExplicitId($pro->id, $storeB, 0, $lower);

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    expect($pins)->toBe([$lower, $higher]);
});

it('pins an item shared across two of the owner\'s stores exactly once, at its earliest position', function () {
    [$pro, $siteId] = poolTenant();
    $storeA = storefront($pro->id, 0, 'Store A');
    $storeB = storefront($pro->id, 1, 'Store B');

    $shared = productIn($pro->id, $storeA, 0, 'Shared item');
    $onlyB = productIn($pro->id, $storeB, 0, 'Store B only');

    // The same item, cross-listed by the second (later) store.
    DB::table('content.collection_items')->insert([
        'collection_id' => $storeB, 'item_id' => $shared, 'source_id' => null, 'position' => 1,
    ]);

    $this->artisan('content:provision-shop-pins')->assertSuccessful();

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');

    // Exactly one section_items row for the cross-listed item — never two.
    expect(DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $shared)->count())->toBe(1);

    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // Pinned at its EARLIEST listing — store A (position 0), ahead of store B's own item.
    expect($pins)->toBe([$shared, $onlyB]);
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
