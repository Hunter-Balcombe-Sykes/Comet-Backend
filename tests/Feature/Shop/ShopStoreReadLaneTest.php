<?php

use App\Models\Core\User\User;
use App\Services\Platforms\StrandedPendingWindow;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentReader;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;

// The shop re-home's read lane: every dashboard read resolves its store from
// content.* — the legacy site.shop_brands / site.shop_products child tables
// are gone (20260819000200 / 20260819000210) and so is ShopBackfiller, which
// existed only to migrate off them.
//
// The assertions here are OUTCOME assertions on content.*, not shape
// assertions. Shape is already pinned byte-for-byte by ShopEndpointParityTest
// — if a repoint below changed a response body, that file fails, and this one
// is free to ask the narrower question these tasks actually turn on: did the
// write reach content.*, and is that where the read came from.
//
// The original form of the first three tests asked "did any query name
// shop_products?" That question answers itself now that the table cannot
// exist, so each one asserts the positive content.* outcome instead — a check
// that can still go red.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('re-warms a store catalog off content.* without disturbing the saved selection', function (): void {
    [$user, $store] = makeShopStore([
        'externalRef' => 'read-lane-store',
        'provider' => 'woocommerce',
        'url' => 'https://store.test',
        'sourceUrl' => 'https://store.test',
        'fetchMode' => 'client',
    ], withSite: true);
    makeShopStoreProduct($store, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    mockShopService(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('productsFromClient')->andReturn([
            ['productId' => 'c1', 'title' => 'Catalog Product', 'url' => 'https://store.test/c1'],
        ]);
    });

    actingAsUser($user)
        ->postJson("/api/platforms/shop/brands/{$store->externalRef}/catalog", [
            'products' => [['id' => 'c1', 'title' => 'Catalog Product']],
        ])
        ->assertOk();

    // A 200 is itself the read assertion: catalog() resolves the store through
    // ShopContentReader::brandMap(), and a store content.* has never seen 404s
    // (pinned below by "404s a connect poll for a store that content.* has
    // never seen"). What it must NOT do is write — it warms the picker cache
    // only, so the owner's saved selection stays exactly as it was.
    expect(orderedProductIdsFor('read-lane-store'))->toBe(['p1']);
});

it('curates a store selection into content.*, stamping the curation clock', function (): void {
    [$user, $store] = makeShopStore([
        'externalRef' => 'curate-lane-store',
        'url' => 'https://store.test',
        'sourceUrl' => 'https://store.test',
    ], withSite: true);
    makeShopStoreProduct($store, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    // setProducts() re-scrapes on a cold picker cache — stub the provider so
    // this test measures the read lane, not egress.
    fakeProviderCatalog($store, []);

    actingAsUser($user)
        ->putJson("/api/platforms/shop/brands/{$store->externalRef}/selection", ['productIds' => []])
        ->assertOk();

    // An empty selection curates the store down to nothing in content.*, and
    // stamps the curation clock that #SEM-1 reads — both on content.storefronts.
    expect(orderedProductIdsFor('curate-lane-store'))->toBe([])
        ->and(curatedAtFor('curate-lane-store'))->not->toBeNull();
});

it('removes a store from content.*, retiring its items rather than deleting them', function (): void {
    [$user, $store] = makeShopStore([
        'externalRef' => 'remove-lane-store',
        'url' => 'https://store.test',
        'sourceUrl' => 'https://store.test',
    ], withSite: true);
    makeShopStoreProduct($store, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    actingAsUser($user)
        ->deleteJson("/api/platforms/shop/brands/{$store->externalRef}")
        ->assertOk();

    // The storefront and its links are gone from content.*, and the item is
    // RETIRED rather than hard-deleted — analytics.item_views references it.
    expect(DB::table('content.storefronts')->where('external_ref', 'remove-lane-store')->count())->toBe(0)
        ->and(DB::table('content.collection_items')->where('collection_id', $store->collectionId)->count())->toBe(0)
        ->and(DB::table('content.items')->whereNull('removed_at')->count())->toBe(0)
        ->and(DB::table('content.items')->whereNotNull('removed_at')->count())->toBe(1);
});

// Ported from ShopRelationalStorageTest, which pinned this on
// ShopBrand::toBrandArray() — deleted by re-home Task 2. It was that rule's
// ONLY guard anywhere in the suite (ShopContentReaderTest has no
// popularityRank coverage at all), so it moves rather than going with the
// method.
it('keys popularityRank by product HANDLE, matching the scoring pipeline', function (): void {
    // content_popularity_scores keys shop_product rows by the product's handle
    // slug (what beacons and click signals carry) — NEVER by productId. A map
    // keyed by productId must not match; a handle-keyed map must.
    [$user, $store] = makeShopStore([
        'externalRef' => 'rank-lane-store',
        'url' => 'https://store.test',
        'sourceUrl' => 'https://store.test',
    ], withSite: true);
    makeShopStoreProduct($store, [
        'productId' => 'p1',
        'handle' => 'mug',
        'title' => 'Mug',
        'url' => 'https://store.test/products/mug',
    ]);

    $reader = app(ShopContentReader::class);

    $handleKeyed = $reader->brandMap($user, ['mug' => 3])['rank-lane-store'];
    expect($handleKeyed['products'][0]['popularityRank'])->toBe(3);

    $productIdKeyed = $reader->brandMap($user, ['p1' => 3])['rank-lane-store'];
    expect($productIdKeyed['products'][0]['popularityRank'])->toBeNull();
});

// ── Task 3: StoreRecord carries the collection id it was read back from ──
//
// The legacy site.shop_brands uuid PK is what the two async jobs key on today
// (spec §5). It has no content.* twin, so the collection id replaces it — and
// the only place that id is known is the read.

it('carries the collection id when rebuilt from a storefront row', function (): void {
    $row = (object) [
        'collection_id' => '11111111-1111-4111-8111-111111111111',
        'external_ref' => '75102060779',
        'provider' => 'shopify',
        'label' => 'Fear No Evil',
        'position' => 2,
        'url' => 'https://fearnoevil.com.au',
        'source_url' => null,
        'currency' => 'AUD',
        'discount_code' => null,
        'referral_query' => '',
        'is_individual' => false,
        'fetch_mode' => null,
        'connect_status' => null,
        'connect_error' => null,
        'logo_url' => null,
        'favicon_url' => null,
        'logo_mark_url' => null,
        'logo_mark_svg_url' => null,
        'products_curated_at' => null,
    ];

    $record = StoreRecord::fromStorefrontRow($row);

    expect($record->collectionId)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($record->externalRef)->toBe('75102060779')
        ->and($record->name)->toBe('Fear No Evil');
});

// The write direction must NOT carry one: upsertStore() RETURNS the collection
// id, so a record built for a write has nothing to put there yet. A default of
// null is what keeps those two directions from being confusable.
it('defaults the collection id to null for a record built to be written', function (): void {
    $record = new StoreRecord(externalRef: '75102060779', provider: 'shopify');

    expect($record->collectionId)->toBeNull();
});

// ── Task 4: ShopConnections::stores() / store() read content.* ───────────
//
// The old brands()/brand() pair returned a query Builder, so callers chained
// ->where(), ->max('position') and ->count(). A keyed Collection supports all
// three and costs one query for the whole family instead of one per call.

it('lists every store the user owns, ordered, off content.*', function (): void {
    [$user] = makeShopStore([
        'externalRef' => 'zeta', 'url' => 'https://z.test', 'sourceUrl' => 'https://z.test', 'position' => 0,
    ], withSite: true);
    addShopStore($user, [
        'externalRef' => 'alpha', 'url' => 'https://a.test', 'sourceUrl' => 'https://a.test', 'position' => 1,
    ]);

    $stores = app(ShopConnections::class)->stores($user);

    // 'zeta' is position 0 and 'alpha' position 1, so this ordering can only
    // come from c.position — alphabetical order would invert it.
    expect($stores->keys()->all())->toBe(['zeta', 'alpha'])
        ->and($stores->get('zeta'))->toBeInstanceOf(StoreRecord::class)
        ->and($stores->get('zeta')->collectionId)->not->toBeNull()
        ->and($stores->get('zeta')->position)->toBe(0)
        ->and($stores->get('alpha')->position)->toBe(1);
});

it('returns an empty collection for a user with no shop at all', function (): void {
    $handle = 'sbrnoshop';
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);

    expect(app(ShopConnections::class)->stores($user))->toBeEmpty();
});

// Was "issues no query naming shop_brands when listing stores". That table is
// dropped, so no query CAN name it and the original assertion could not fail.
// The live half of the guarantee is that stores() reads content.storefronts —
// pinned positively here, so removing the join or repointing the read goes red.
it('reads the listed store out of content.storefronts', function (): void {
    [$user] = makeShopStore([
        'externalRef' => 'alpha', 'url' => 'https://a.test', 'sourceUrl' => 'https://a.test',
    ], withSite: true);

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    // Non-empty is asserted first: a stores() that returned nothing would
    // satisfy the query assertion below for the wrong reason.
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'storefronts')))
        ->not->toBeEmpty();
});

it('resolves one store by its provider store id', function (): void {
    [$user] = makeShopStore([
        'externalRef' => 'alpha', 'url' => 'https://a.test', 'sourceUrl' => 'https://a.test',
    ], withSite: true);

    $shop = app(ShopConnections::class);

    expect($shop->store($user, 'alpha'))->toBeInstanceOf(StoreRecord::class)
        ->and($shop->store($user, 'nope'))->toBeNull();
});

// stores() and ShopContentReader::brandMap() MUST agree on which store is
// position 0 — they are two reads of the same rows, and a dashboard that
// ordered them differently from the picker would be a real user-visible bug.
it('orders stores identically to ShopContentReader::brandMap()', function (): void {
    [$user] = makeShopStore([
        'externalRef' => 'zeta', 'url' => 'https://z.test', 'sourceUrl' => 'https://z.test', 'position' => 0,
    ], withSite: true);
    foreach ([['alpha', 1], ['mid', 1]] as [$id, $position]) {
        addShopStore($user, [
            'externalRef' => $id,
            'url' => "https://{$id}.test", 'sourceUrl' => "https://{$id}.test",
            'position' => $position,
        ]);
    }

    $keys = app(ShopConnections::class)->stores($user)->keys()->all();

    // Pinned LITERALLY, not just as "equal to brandMap()". Two failures that a
    // self-comparison alone would pass: an empty fixture (both sides []), and
    // an identical change to BOTH queries. The fixture is chosen so position
    // order and alphabetical order disagree — 'zeta' is position 0, and the
    // two position-1 stores tie-break on external_ref.
    expect($keys)->toHaveCount(3)
        ->and($keys)->toBe(['zeta', 'alpha', 'mid'])
        ->and($keys)->toBe(array_keys(app(ShopContentReader::class)->brandMap($user)));
});

// ── Review P1-1, superseded by Task 6 ───────────────────────────────────
//
// Phase 1 shipped a truthful-minimum fallback here: connectStatus() was the one
// repointed payload site with no upsertStore() in its own request, so it could
// observe a store present in site.shop_brands and absent from content.*, and
// answering that with `?? []` emitted a card with id "" and no name or url.
//
// Task 6 made that state unreachable instead of merely survivable: the store is
// resolved through ShopConnections::store(), which reads content.*, so a store
// absent from content.* 404s before any payload is built. The fallback was
// deleted with brandPayload(). This test now pins the REPLACEMENT contract —
// keeping it as a 200-with-blank-card assertion would pin behaviour the code no
// longer has.
//
// The ORIGINAL fixture was a site.shop_brands row with no content.* twin. That
// table is dropped, so that exact half-state cannot be built — but the state
// the 404 actually guards against still can: an ANCHOR CONNECTION whose store
// was never written to content.*. That is the same divergence (the connection
// says a store exists, content.* does not), reached through the surviving
// table, so the assertion moves rather than going away.
it('404s a connect poll for a store that content.* has never seen', function (): void {
    [$user] = makeShopStore(withSite: true);

    // The anchor alone — deliberately NO upsertStore() for this store id.
    app(ShopConnections::class)->anchor($user, 'shopify', 'unbackfilled-store');

    // Non-vacuous: the connection genuinely exists, so the 404 is content.*
    // answering "no such store", not the fixture failing to build one.
    expect(app(ShopConnections::class)->anchorFor($user, 'unbackfilled-store'))->not->toBeNull()
        ->and(DB::table('content.storefronts')->where('external_ref', 'unbackfilled-store')->count())->toBe(0);

    actingAsUser($user)
        ->getJson('/api/platforms/shop/brands/unbackfilled-store/connect/status')
        ->assertNotFound();
});

// ── Task 6: the stale-pending clock moves to content.storefronts ─────────
//
// connectStatus()'s backstop reads updated_at: a worker that dies leaves the
// row 'pending' forever with nothing to flip it, so a poll past the window
// reports 'failed' synthetically. That clock has to survive the repoint, and
// the only column that can carry it is content.storefronts.updated_at.
//
// Spec §12.2 left this open, doubting that upsertStore() would bump the column
// for a byte-identical write. It does: Postgres's ON CONFLICT DO UPDATE writes
// the row unconditionally. The legacy path needed an explicit ->touch() only
// because ELOQUENT's fill()->save() skips a no-op UPDATE — a model-layer dirty
// check, not a database one.

it('carries updated_at when rebuilt from a storefront row', function (): void {
    $record = StoreRecord::fromStorefrontRow((object) [
        'collection_id' => '11111111-1111-4111-8111-111111111111',
        'external_ref' => 'alpha', 'provider' => 'shopify', 'label' => 'Alpha',
        'position' => 0, 'url' => null, 'source_url' => null, 'currency' => null,
        'discount_code' => null, 'referral_query' => '', 'is_individual' => false,
        'fetch_mode' => null, 'connect_status' => 'pending', 'connect_error' => null,
        'logo_url' => null, 'favicon_url' => null, 'logo_mark_url' => null,
        'logo_mark_svg_url' => null, 'products_curated_at' => null,
        'updated_at' => '2026-08-17 01:00:00+00',
    ]);

    expect($record->updatedAt)->not->toBeNull()
        ->and($record->updatedAt->toIso8601String())->toBe('2026-08-17T01:00:00+00:00');
});

it('reports a stranded pending connect as failed, clocked off content.*', function (): void {
    [$user, $store] = makeShopStore([
        'externalRef' => 'stranded-store',
        'url' => 'https://stranded.test', 'sourceUrl' => 'https://stranded.test',
        'connectStatus' => 'pending',
    ], withSite: true);

    // Age the content row past the window. content.storefronts.updated_at IS
    // the clock now — there is no second row anywhere holding a fresher one.
    DB::table('content.storefronts')
        ->where('external_ref', 'stranded-store')
        ->update([
            'connect_status' => 'pending',
            'updated_at' => now()->subMinutes(StrandedPendingWindow::MINUTES + 1),
        ]);

    actingAsUser($user)
        ->getJson("/api/platforms/shop/brands/{$store->externalRef}/connect/status")
        ->assertOk()
        ->assertJsonPath('status', 'failed');
});
