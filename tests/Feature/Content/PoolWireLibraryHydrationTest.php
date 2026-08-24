<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use App\Site\Pools\PoolWire;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * SCALE-2 (2026-08-24): PoolWire::forSite() used to union libraryIds into
 * selectionIds for every one of PoolRegistry::POOLS' 9 pools before the
 * shared hydrate — up to 9 x LIBRARY_LIMIT (500) item payloads fetched and
 * then thrown away, since the per-pool assemble loop only ever read
 * $resolved['selection']. The fix drops libraryIds from PoolWire's union and
 * passes withLibrary: false into PoolResolver::assemble().
 *
 * itemPayloads() issues a FIXED ~20 facet queries for the whole batch
 * (the f263a284a batching refactor), not one per item, so this fix does not
 * change query COUNT — what it changes is the whereIn binding volume on
 * content.items (and the facet tables joined off it). Assertions here are on
 * BINDINGS, not counts, for that reason. Style precedent for capturing SQL
 * via DB::listen(): tests/Feature/Site/DocumentBuilderQueryCountTest.php.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

/**
 * A library-only item: content.items exists (so PoolResolver::plan()'s
 * libraryIds query picks it up) but explicitly EXCLUDED from the section so
 * it can never land in selectionIds regardless of whether it would also be a
 * rule candidate (each auto-source's newest item).
 */
function poolExcludeItem(string $siteId, string $pool, string $itemId): void
{
    $site = Site::query()->findOrFail($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
    DB::connection('pgsql')->table('site.section_items')->insert([
        'id' => (string) Str::uuid(),
        'section_id' => (string) $section->id,
        'item_id' => $itemId,
        'state' => 'excluded',
        'sort_key' => null,
        'created_at' => now(),
    ]);
}

/** Every binding on a statement whose SQL hits content.items, across $run(). */
function captureContentItemsBindings(callable $run): array
{
    $bindings = [];
    DB::connection('pgsql')->listen(function ($q) use (&$bindings): void {
        if (str_contains(strtolower((string) $q->sql), 'from "content"."items"')) {
            array_push($bindings, ...$q->bindings);
        }
    });

    $run();

    return $bindings;
}

it('hydrates the selected item id but never the library-only one, on the shared PoolWire pass', function () {
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id);
    $sourceId = poolSource($pro->id, $connectionId);

    $selectedId = poolItem($pro->id, $sourceId, 'video', 'Selected', now()->subDay()->toDateTimeString());
    $libraryOnlyId = poolItem($pro->id, $sourceId, 'video', 'Library Only', now()->toDateTimeString());

    poolPin($siteId, 'watch', $selectedId);
    poolExcludeItem($siteId, 'watch', $libraryOnlyId);

    $site = Site::query()->findOrFail($siteId);

    // 3. The control: prove the fixture actually produces a library-only
    // item BEFORE trusting the bindings assertions below — without this, a
    // fixture bug (e.g. the exclude row not landing) would make assertion 2
    // pass for the wrong reason.
    $plan = app(PoolResolver::class)->plan($site, 'watch');
    expect($plan['libraryIds'])->toContain($libraryOnlyId);
    expect($plan['selectionIds'])->toContain($selectedId);
    expect($plan['selectionIds'])->not->toContain($libraryOnlyId);

    $bindings = captureContentItemsBindings(function () use ($site): void {
        app(PoolWire::class)->forSite($site, app(SitepageDataResolverService::class));
    });

    // 1. The listener is wired and the hydrate ran at all.
    expect($bindings)->toContain($selectedId);
    // 2. The actual assertion: the library-only id never reaches the hydrate.
    expect($bindings)->not->toContain($libraryOnlyId);
});

it('does not drop a collection a selected item still references, when the library holds an item in a different store', function () {
    [$pro, $siteId] = poolTenant();
    $storeA = shopStore($pro->id, ['label' => 'Store A']);
    $storeB = shopStore($pro->id, ['label' => 'Store B']);

    $selectedProduct = shopProduct($pro->id, $storeA, 'Selected Hat');
    $libraryOnlyProduct = shopProduct($pro->id, $storeB, 'Library Only Cap');

    poolPin($siteId, 'shop', $selectedProduct);
    poolExcludeItem($siteId, 'shop', $libraryOnlyProduct);

    $site = Site::query()->findOrFail($siteId);

    $out = app(PoolWire::class)->forSite($site, app(SitepageDataResolverService::class));

    expect($out)->toHaveKey('shop');
    // Wire-invariance: the per-pool top-level key set PoolWire has always
    // emitted (items/latestItemId/collections for shop — stats/diningModes
    // stay absent, and `library` was never a key here even before this fix).
    expect(array_keys($out['shop']))->toEqualCanonicalizing(['items', 'latestItemId', 'collections']);
    expect($out['shop']['collections'])->toHaveKey($storeA);
    expect($out['shop']['collections'])->not->toHaveKey($storeB);
});
