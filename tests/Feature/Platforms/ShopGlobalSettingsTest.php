<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GLOBAL shop link controls (2026-07-08): the per-brand link-mode + auto-latest
// controls collapsed into ONE site-level choice each, stored on site.sites and
// managed via GET/PATCH /platforms/shop/settings. The public payload stamps
// every brand's linkMode from the global (covered in PublicIntegrationAllowlistTest);
// here we cover the settings endpoint itself + the ShopFetch auto-latest gate.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 6 fix round 2: ShopFetch's real (non-mocked) run and
    // ShopContentWriter::isCurated() both query content.* — the content.*
    // SQLite stand-in schema must be attached or those queries 500 with
    // "no such table".
    setupIngestTables();
    setupContentTables();
});

function shopSettingsUser(string $h): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
    // The settings endpoint reads/writes the user's site row; every logged-in
    // user has one. Insert bare — the columns fall back to their code defaults.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user->fresh();
}

it('GET shop settings returns the direct-to-checkout + auto-latest-on defaults', function () {
    $user = shopSettingsUser('gset1');

    actingAsUser($user)->getJson('/api/platforms/shop/settings')
        ->assertOk()
        ->assertJson(['linkMode' => 'checkout', 'autoLatest' => true]);
});

it('PATCH shop settings persists linkMode + autoLatest to the site row', function () {
    $user = shopSettingsUser('gset2');
    // 2026-08-05: autoLatest persists on the store connection's own
    // display_settings — a store must exist to carry it.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true,
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', [
        'linkMode' => 'product',
        'autoLatest' => false,
    ])
        ->assertOk()
        ->assertJson(['linkMode' => 'product', 'autoLatest' => false]);

    $row = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();
    expect($row->shop_link_mode)->toBe('product');
    // 2026-08-05: auto-latest lives on the store connection's own sparse
    // display_settings now (one toggle grammar) — the site column is gone.
    expect(AutoSyncSetting::isOn((string) $user->id, ShopConnections::surfaces()))->toBeFalse();
});

it('PATCH shop settings applies only the field present (partial update)', function () {
    $user = shopSettingsUser('gset3');

    // Only linkMode → autoLatest keeps its default (true).
    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'product'])
        ->assertOk()
        ->assertJson(['linkMode' => 'product', 'autoLatest' => true]);
});

it('PATCH shop settings rejects an unknown link mode', function () {
    $user = shopSettingsUser('gset4');

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'teleport'])
        ->assertStatus(422);
});

it('PATCH shop settings purges the sitepage edge cache when a shop is connected', function () {
    Bus::fake();
    $user = shopSettingsUser('gset5');
    // A shop connection must exist for the explicit refresh to fire (the marker
    // payload never goes dirty on its own — see ShopController::updateSettings).
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/settings', ['linkMode' => 'checkout'])
        ->assertOk();

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

it('the legacy /shopify alias prefix is gone', function () {
    // Removed 2026-08-05 (platform audit): no caller anywhere used the alias.
    $user = shopSettingsUser('gset6');

    actingAsUser($user)->getJson('/api/platforms/shopify/settings')
        ->assertNotFound();
});

// ── ShopFetch: the global auto-latest gate drives the scheduled sync ──────────

/** A shop connection + a non-individual content.* store for the ShopFetch strategy. */
function shopFetchConnection(User $user, bool $autoLatest): IntegrationConnection
{
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'sf-brand',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
        // 2026-08-05: the auto-latest gate reads the connection's own sparse
        // display_settings (absent = ON) — the site column is gone.
        'display_settings' => $autoLatest ? null : ['auto_sync_latest' => false],
    ]);
    // Re-home Task 7: the store is a content.* row (site.shop_brands is
    // written by nothing), landed through the real writer.
    //
    // #SEM-1: selection_mode is not set because it no longer exists anywhere.
    // It never could distinguish "curated" from "never touched" — 'manual' was
    // its DB default and addBrand() never set it — which is exactly why the
    // gate is products_curated_at, left null here so this store DOES sync when
    // the global auto-latest is on.
    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: 'sf-brand',
        provider: 'shopify',
        position: 0,
        url: 'https://sf.example',
    ), (string) $user->id);

    return $conn;
}

it('ShopFetch syncs every non-individual brand when the global auto-latest is on', function () {
    $user = shopSettingsUser('sfon');
    $conn = shopFetchConnection($user, autoLatest: true);

    $catalog = Mockery::mock(ShopCatalog::class);
    // The manual-mode brand IS synced because the GLOBAL is on.
    $catalog->shouldReceive('syncLatest')->once()->andReturn(3);
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldReceive('refresh')->once();

    $result = (new ShopFetch($catalog, $refresher))->fetch($conn->fresh());
    expect($result)->toBe(['storage' => 'relational']);
});

it('ShopFetch syncs nothing when the global auto-latest is off', function () {
    $user = shopSettingsUser('sfoff');
    $conn = shopFetchConnection($user, autoLatest: false);

    $catalog = Mockery::mock(ShopCatalog::class);
    // Global off → the gate short-circuits before any brand is touched.
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('ShopFetch skips a brand with products_curated_at set even when the global auto-latest is on', function () {
    $user = shopSettingsUser('sfcurated');
    $conn = shopFetchConnection($user, autoLatest: true);
    // #SEM-1's stamp lives on content.storefronts now — setProducts() writes
    // it there directly, and it is what ShopFetch's filter reads.
    DB::table('content.storefronts')->where('external_ref', 'sf-brand')
        ->update(['products_curated_at' => now()]);

    $catalog = Mockery::mock(ShopCatalog::class);
    // The curated brand is the ONLY brand on this connection — with it
    // excluded, $latestBrands is empty, so syncLatest/refresh must never fire.
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    expect(fn () => (new ShopFetch($catalog, $refresher))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

// ── #428: the scheduled refresh died on a lazy load ───────────────────────────
//
// THE INCIDENT (kept, because the reasoning still governs what these tests are
// for): every test above mocks ShopCatalog, so syncLatest() never ran and
// ShopBrand::toBrandArray() was never reached — which is exactly why the suite
// stayed green while every scheduled shop refresh on dev failed, 36 in 24h.
// toBrandArray() materialised $this->products unconditionally; every caller
// loaded that relation first except ShopFetch, which stopped eager-loading it
// in 5a on the stated belief that "syncLatest() no longer reads
// $brand->products". It did. Eloquent's strict mode only arms per INSTANCE and
// Builder::hydrate() sets that flag `if (count($items) > 1)`, so a one-brand
// connection could not reproduce it at all — the failing account held five
// storefronts. That is why the fixtures below still use TWO stores.
//
// RE-HOME TASK 7 CLOSED THE SHAPE, not merely this instance of it:
// syncLatest() takes a StoreRecord — a readonly DTO with no relations at all —
// plus the owner id as a plain string. There is nothing left to lazy-load
// from, and no relation a caller can forget. The tests below are kept and
// rewritten to pin THAT: the structural guarantee, and a real multi-store run
// through the real ShopCatalog. Asserting a hazard that can no longer occur
// would be asserting nothing.

/** Real ShopCatalog whose Shopify scrape returns an empty catalogue. */
function shopCatalogWithEmptyScrape(): ShopCatalog
{
    $shopify = Mockery::mock(ShopifyScraper::class);
    $shopify->shouldReceive('fetchProducts')->andReturn([]);

    return new ShopCatalog(
        $shopify,
        Mockery::mock(WooCommerceScraper::class),
        Mockery::mock(SquarespaceScraper::class),
        Mockery::mock(BigCartelScraper::class),
        Mockery::mock(GenericShopScraper::class),
        app(ShopContentWriter::class),
    );
}

/** A second non-individual store, so the fixture is genuinely multi-store. */
function shopFetchSecondBrand(IntegrationConnection $conn): void
{
    app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: 'sf-brand-2',
        provider: 'shopify',
        position: 1,
        url: 'https://sf2.example',
    ), (string) $conn->user_id);
}

it('runs the scheduled sync end to end for a multi-store user', function () {
    $user = shopSettingsUser('sflazy');
    $conn = shopFetchConnection($user, autoLatest: true);
    shopFetchSecondBrand($conn);

    // Reaching FetchNotModifiedException means syncLatest() ran to completion
    // for BOTH stores: reachable, and genuinely empty. Before #428's fix this
    // threw LazyLoadingViolationException from ShopBrand::toBrandArray()
    // instead, and that exception is caught by nothing in the chain — it
    // failed the job. It cannot throw from there any more (there is no model
    // in the path), so what this now pins is the loop itself: every store the
    // user owns is visited, not just the one whose connection was handed in.
    expect(fn () => (new ShopFetch(shopCatalogWithEmptyScrape(), Mockery::mock(IntegrationConnectionCacheRefresher::class)))
        ->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

// Re-home Task 2 sibling of the test below. syncLatest() had stopped reading
// $brand->products, but it still needed the owner id — and it resolved that
// through $brand->connection, ANOTHER lazy relation on the same strict-mode
// row. dispatchShapeFor() briefly took the owner as a CLOSURE so only the
// fetchMode='client' branch would pay for it, because 'client' is the branch
// that reaches storage before the empty-catalog return.
//
// Task 7 removed the model from the path entirely, so the owner is now a plain
// string the caller already holds and the closure is gone. What still deserves
// a test is the branch itself: client mode is the one that reads content.*
// mid-sync, and it must survive an empty scrape without minting anything.
it('syncLatest handles a CLIENT-mode store, whose fallback reads content.*, without minting a collection', function () {
    $user = shopSettingsUser('sflazy3');
    $conn = shopFetchConnection($user, autoLatest: true);
    shopFetchSecondBrand($conn);
    DB::table('content.storefronts')->where('user_id', (string) $user->id)
        ->update(['fetch_mode' => 'client']);

    $store = app(ShopConnections::class)->store($user, 'sf-brand');
    expect($store->fetchMode)->toBe('client');

    // Client mode dispatches through WooCommerceScraper::fetchProducts, and an
    // empty return sends it down the cached/stored fallback — the path that
    // reads content.* and therefore needs the owner.
    $woo = Mockery::mock(WooCommerceScraper::class);
    $woo->shouldReceive('fetchProducts')->andReturn([]);
    $catalog = new ShopCatalog(
        Mockery::mock(ShopifyScraper::class),
        $woo,
        Mockery::mock(SquarespaceScraper::class),
        Mockery::mock(BigCartelScraper::class),
        Mockery::mock(GenericShopScraper::class),
        app(ShopContentWriter::class),
    );

    expect($catalog->syncLatest($store, (string) $user->id))->toBeNull();
    // dispatchShapeFor() deliberately uses the READ-ONLY collectionIdFor()
    // lookup, never upsertStore() — a store whose fetch then comes up empty
    // must not have been minted as a side effect of dispatching that fetch.
    expect(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(2);
});

// The structural half of #428, replacing "tolerates a brand handed to it with
// products unloaded": that test could only ever catch ONE forgotten
// eager-load. This catches the reintroduction of the whole hazard class — a
// model back on syncLatest()/dispatchShapeFor() is the only way a relation can
// return to this path.
it('syncLatest takes a StoreRecord and a plain owner id — no Eloquent model to lazy-load from', function () {
    $sync = (new ReflectionMethod(ShopCatalog::class, 'syncLatest'))->getParameters();
    expect($sync[0]->getType()?->getName())->toBe(StoreRecord::class)
        ->and($sync[1]->getType()?->getName())->toBe('string');

    // dispatchShapeFor() is the other half — see the note above on why its
    // second argument was briefly a Closure.
    $shape = (new ReflectionMethod(ShopCatalog::class, 'dispatchShapeFor'))->getParameters();
    expect($shape[0]->getType()?->getName())->toBe(StoreRecord::class)
        ->and($shape[1]->getType()?->getName())->toBe('string');

    // And StoreRecord itself carries no relations to forget: readonly data,
    // not an Eloquent model.
    expect((new ReflectionClass(StoreRecord::class))->isReadOnly())->toBeTrue()
        ->and(is_subclass_of(StoreRecord::class, Model::class))->toBeFalse();
});
