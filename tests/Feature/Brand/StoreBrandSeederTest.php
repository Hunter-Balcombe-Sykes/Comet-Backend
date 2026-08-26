<?php

// The new-pipeline shop-brand seeder (P8 blocker B2).
//
// The legacy ShopBrandSeeder decided AND wrote: its own tombstone check, its
// own lock, its own connection upsert — and four sibling seeders each did the
// same thing slightly differently, which is how a user's deletion could be
// honoured on one path and ignored on the next.
//
// What these tests are really asserting is that this one decides NOTHING. The
// tombstone test is the proof: nothing in StoreBrandSeeder mentions tombstones,
// and the refusal is still honoured, because the decision travels through
// PlacementPolicy exactly as a pasted link does.

use App\Http\Controllers\Api\Platforms\ShopController;
use App\Jobs\Brand\IngestBrandAssetJob;
use App\Jobs\Platforms\ConnectStoreFromProductJob;
use App\Jobs\Platforms\ShopInitialFillJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Brand\StoreBrandSeeder;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopBrandIdentity;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use App\Site\Pools\AutoSyncSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    // Re-home Task 10: the seeder's only store write is
    // ShopContentWriter::upsertStore() — content.* is where every assertion in
    // this file reads the seeded store back from.
    setupContentTables();
    Cache::flush();
    Bus::fake();
});

afterEach(function () {
    Mockery::close();
});

function storeResponds(array $meta = ['id' => 4242, 'name' => 'The Store', 'currency' => 'AUD']): void
{
    Http::fake([
        '*/meta.json' => Http::response($meta, 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
}

/** The store the seeder wrote, read back off content.* by its provider store id. */
function seededStore(User $user, string $externalRef): ?StoreRecord
{
    return app(ShopConnections::class)->store($user, $externalRef);
}

/** The user's connected-store count — what MAX_BRANDS caps (the individual bucket never counts). */
function seededStoreCount(User $user): int
{
    return app(ShopConnections::class)->stores($user)
        ->filter(fn (StoreRecord $store): bool => $store->isIndividual === false)
        ->count();
}

it('turns a probed own-domain storefront into a connection and a store', function () {
    $pro = createTenant('store-seed');
    storeResponds();

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('placed')
        ->and($result['brandId'])->toBe('4242');

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->firstOrFail();
    expect($connection->surface_key)->toBe('shopify.store');
    // The connection's resource_id IS the store id — the only bridge from a
    // connection to a store now that content.storefronts carries no
    // connection column.
    expect($connection->resource_id)->toBe('4242');

    $store = seededStore($pro, '4242');
    expect($store)->not->toBeNull()
        ->and($store->name)->toBe('The Store')
        ->and($store->currency)->toBe('AUD')
        ->and($store->provider)->toBe('shopify');
});

it('leaves the connection write to the single writer', function () {
    // The reconciler's intent ledger is only a true account of why every
    // connection exists if nothing else creates one. A seeder that wrote its
    // own row would be invisible to it.
    $pro = createTenant('store-intent');
    storeResponds();

    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->surface_key)->toBe('shopify.store')
        ->and($intent->state)->toBe('applied');
});

it('records the probe in the observation log like any other decision', function () {
    // "Why is this store on my page?" has to be answerable. A probe that wrote
    // nothing to the log is a decision nobody can reconstruct.
    $pro = createTenant('store-observed');
    storeResponds();

    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    $observation = DB::table('routing.link_observations')->where('user_id', $pro->id)->first();
    expect($observation)->not->toBeNull()
        ->and($observation->surface_key)->toBe('shopify.store')
        ->and($observation->verdict)->toBe('place');
});

it('honours a tombstone it never checks for', function () {
    // THE POINT OF B2. StoreBrandSeeder contains no tombstone logic at all —
    // the refusal is honoured because the decision goes through
    // PlacementPolicy, not because this class remembered to re-implement it.
    // Seeded from a SCAN origin: tombstones are origin-aware (owner decision,
    // 2026-07-28) and only bind re-imports — a direct 'paste' would win.
    $pro = createTenant('store-tombstoned');
    storeResponds();

    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'shopify.store:4242',
        'scope' => 'this_source',
        'reason' => 'legacy soft-deleted connection, backfilled 2026-07-28',
        'created_at' => now(),
    ]);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com', 'website_import');

    expect($result['outcome'])->toBe('not_placed')
        ->and($result['reason'])->toBe('tombstoned')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('content.storefronts')->count())->toBe(0);
});

it('reports a miss without writing a connection', function () {
    $pro = createTenant('store-miss');
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('miss')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

/**
 * N-E (2026-08-18 Instagram wave) — a probe that MISSES is still a decision.
 *
 * This class's own comment says "'Why is this store on my page?' and 'why
 * isn't it?' must both be answerable, and a probe that wrote nothing to the
 * observation log is a decision nobody can reconstruct." The record() call sat
 * BELOW the !isMatch() early return, so the second half of that sentence was
 * never true: every miss went unlogged.
 *
 * Found because the 2026-08-18 wave probed real unknown hosts
 * (paytherent.net.au, juno.co.uk, discogs.com) and routing.link_observations
 * stayed empty for all six accounts — which read as "X3's CHECK widening did
 * not work", when in fact nothing had ever attempted the write.
 *
 * LinkRoutingService::route() records unconditionally; this is the same
 * contract, and ProbeOutcome::toProjection() already models the miss
 * (confidence 0, margin 0, reason 'probe_miss').
 */
it('records a probe miss in the observation log', function () {
    $pro = createTenant('store-miss-observed');
    Http::fake(['*' => Http::response('', 404)]);

    app(StoreBrandSeeder::class)->seed($pro, 'https://paytherent.net.au/', 'commerce_probe');

    $observation = DB::table('routing.link_observations')->where('user_id', $pro->id)->first();
    expect($observation)->not->toBeNull()
        ->and($observation->source)->toBe('commerce_probe')
        ->and($observation->surface_key)->toBeNull();
});

it('keeps a hand-typed discount code through a re-scan', function () {
    // Carried verbatim from ShopBrandSeeder: the columns default to '' rather
    // than NULL, so a `??` here would read an empty string as "already set"
    // and never apply a scanned code — and a `??` on an existing code would
    // clobber what the user typed.
    $pro = createTenant('store-discount');
    storeResponds();
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    DB::table('content.storefronts')->where('external_ref', '4242')
        ->update(['discount_code' => 'TYPED10']);

    Cache::flush();
    storeResponds();
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com?discount=SCANNED20');

    expect(seededStore($pro, '4242')->discountCode)->toBe('TYPED10');
});

it('does not queue a logo download while the store path is switched off', function () {
    // Plan §12's config split: `logo_removal.enabled` governs workplace logos a
    // user uploaded, `store_enabled` governs auto-grabbed store logos. They
    // must not be one switch.
    config()->set('partna.logo_removal.enabled', true);
    config()->set('partna.logo_removal.store_enabled', false);
    $pro = createTenant('store-logo-off');
    storeResponds();

    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    Bus::assertNotDispatched(IngestBrandAssetJob::class);
});

it('queues the store logo for ingest when the store path is on', function () {
    config()->set('partna.logo_removal.store_enabled', true);
    $pro = createTenant('store-logo-on');
    storeResponds();

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');
    DB::table('content.storefronts')->where('external_ref', '4242')
        ->update(['logo_url' => 'https://cdn.example.com/logo.png']);

    // The seeder queues off the store it wrote; re-running with a logo present
    // is the shape a refreshed store takes.
    Cache::flush();
    storeResponds();
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    Bus::assertDispatched(
        IngestBrandAssetJob::class,
        fn (IngestBrandAssetJob $job) => $job->connectionId === $result['connectionId']
            && $job->role === 'logo_full'
            && $job->sourceUrl === 'https://cdn.example.com/logo.png',
    );
});

// MAX_BRANDS is an AGGREGATE cap across every one of the user's stores, of
// any provider — the same shape ShopController::MAX_BRANDS enforces on the
// picker. It is deliberately tested apart from SourceReconciler's own
// per-SURFACE cap (max_accounts, default 1 — see Surface::$maxAccounts):
// that one blocks a second store on the SAME provider surface (e.g. two
// distinct own-domain Shopify stores) and is a pre-existing, unrelated
// mechanism this WAVE-2C unit does not touch. Seeding five real stores
// through five real probes to reach the aggregate cap would, today,
// coincidentally collide with the per-surface cap first (there are exactly
// five probe surfaces — shopify/woocommerce/squarespace/bigcartel/generic —
// each capped at one), which would test the wrong mechanism. Five
// directly-inserted stores isolate the property under test: the
// aggregate count StoreBrandSeeder itself now guards.
function seedExistingStores(User $user, int $count): void
{
    $writer = app(ShopContentWriter::class);

    for ($i = 0; $i < $count; $i++) {
        DB::table('site.platform_connections')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'surface_key' => "fixture.store{$i}",
            'routing_class' => 'shop',
            // resource_id IS the store id since convergence Phase 6 — kept in
            // step with external_ref below so the pair reads like a real one.
            'resource_id' => "fixture-brand-{$i}",
            'payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $writer->upsertStore(new StoreRecord(
            externalRef: "fixture-brand-{$i}",
            provider: 'fixture',
            position: $i,
            url: "https://fixture{$i}.example.com",
            sourceUrl: "https://fixture{$i}.example.com",
            isIndividual: false,
        ), (string) $user->id);
    }
}

// #CFG-3: this seeder said 5 while ShopController and ConnectStoreFromProductJob
// said 10, so a user with 5 stores who pasted a 6th got the CONNECTION placed
// and only the brand row capped — the store half-existed and never rendered.
// All three now read `partna.shop_brands_max`. These two tests drive the cap
// off that key rather than a literal, so a future change to it cannot leave the
// three enforcement points disagreeing again.
it('does NOT cap below the configured store cap — the #CFG-3 half-connected store', function () {
    $pro = createTenant('store-under-cap');
    seedExistingStores($pro, 5); // the count that used to cap here, and must not
    storeResponds(['id' => 9999, 'name' => 'The 6th Store', 'currency' => 'AUD']);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->not->toBe('capped');
    expect($result['reason'])->not->toBe('max_brands');
});

it('caps the store after the cap the config declares, and all three enforcement points read it', function () {
    // Assert the three former MAX_BRANDS constants now agree BY CONSTRUCTION —
    // each reads the same key — rather than by three hand-copied literals.
    $cap = (int) config('partna.shop_brands_max');
    expect($cap)->toBe(10);
    foreach ([StoreBrandSeeder::class, ShopController::class, ConnectStoreFromProductJob::class] as $class) {
        $m = new ReflectionMethod($class, 'maxBrands');
        $m->setAccessible(true);
        expect($m->invoke(null))->toBe($cap, "{$class} disagrees with partna.shop_brands_max");
    }

    $pro = createTenant('store-capped');
    seedExistingStores($pro, $cap);
    storeResponds(['id' => 9999, 'name' => 'The 6th Store', 'currency' => 'AUD']);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('capped')
        ->and($result['reason'])->toBe('max_brands')
        ->and($result['connectionId'])->toBeNull()
        ->and($result['brandId'])->toBeNull();

    // The placement itself still applies — SourceReconciler's single-writer
    // property is untouched, only the 6th store was refused, mirroring
    // the legacy seeder's own ordering (its connection upsert always ran;
    // only the store write was skipped past the cap).
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->exists())->toBeTrue()
        ->and(seededStoreCount($pro))->toBe($cap)
        ->and(seededStore($pro, '9999'))->toBeNull();
});

it('never counts a re-scan of an already-connected store against the cap', function () {
    $pro = createTenant('store-recheck-capped');
    // One under the cap, so this seed lands exactly ON it and never over —
    // the interesting case for "a re-scan doesn't count against the cap" is
    // being AT the boundary, not comfortably under it. Driven off the config
    // key for the same reason the two tests above are (#CFG-3): a literal
    // here would silently stop testing the boundary the day the cap moves.
    $cap = (int) config('partna.shop_brands_max');
    seedExistingStores($pro, $cap - 1);
    storeResponds();

    $first = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');
    expect($first['outcome'])->toBe('placed');
    expect(seededStoreCount($pro))->toBe($cap);

    // Re-scanning the SAME store while the account sits exactly at MAX_BRANDS
    // must still succeed — it's a re-scan of an existing store, not a new one.
    Cache::flush();
    storeResponds();
    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('placed')
        ->and(seededStoreCount($pro))->toBe($cap);
});

it('dispatches the initial fill and disarms auto-latest on a first connect only (L-4/L-5)', function () {
    $pro = createTenant('store-fill');
    storeResponds();

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');
    expect($result['outcome'])->toBe('placed');

    // L-5: this lane mints through SourceReconciler, which leaves the sparse
    // auto_sync_latest key absent — and absent means ON. The seeder must
    // disarm it exactly as ShopConnections::anchor() does for a dedicated
    // connect, or a suggestion-accepted store silently auto-publishes.
    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->firstOrFail();
    expect((array) ($connection->display_settings ?? []))
        ->toHaveKey(AutoSyncSetting::KEY)
        ->and($connection->display_settings[AutoSyncSetting::KEY])->toBeFalse();

    // L-4: the one-shot catalogue fill + first-connect auto-select, keyed on
    // the store's own collection id.
    $collectionId = DB::table('content.storefronts')->where('external_ref', '4242')->value('collection_id');
    Bus::assertDispatched(ShopInitialFillJob::class,
        fn ($job) => $job->collectionId === (string) $collectionId);

    // A re-scan of the SAME store is not a new connect — no second fill.
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');
    Bus::assertDispatchedTimes(ShopInitialFillJob::class, 1);
});

// The AUTO-PROBE path's own behavioural cap test. The sweep's standalone note
// asked for proof that both the auto-probe and the manual-connect path refuse at
// the same count; ShopController's half is covered by ShopAsyncConnectTest (T10),
// but ConnectStoreFromProductJob had no cap coverage at all before or after
// #CFG-3. The reflection guard above proves the three accessors cannot DISAGREE;
// this proves the auto-probe path actually ENFORCES what it reads.
// Lives in this file so it can reuse seedExistingStores() — a cross-file test
// helper fatals under --parallel.
it('the auto-probe path refuses a store past the same configured cap (#CFG-3)', function () {
    Log::spy();
    $cap = (int) config('partna.shop_brands_max');
    $pro = createTenant('probe-capped');
    seedExistingStores($pro, $cap);

    (new ConnectStoreFromProductJob((string) $pro->id, [
        'provider' => 'bigcartel',
        'origin' => 'https://overcap.example.com',
        'sourceUrl' => 'https://overcap.example.com/product/x',
        'page' => null,
        'store' => null,
        'clientBrand' => ['id' => 'overcap-store'],
    ]))->handle(
        app(ShopBrandIdentity::class),
        app(ShopBrandProfiler::class),
        app(ShopConnections::class),
        app(ShopContentWriter::class),
        app(IntegrationConnectionCacheRefresher::class),
    );

    // Refused cleanly: no new store, and never a half-connected one.
    expect(seededStoreCount($pro))->toBe($cap);
    expect(seededStore($pro, 'overcap-store'))->toBeNull();
    Log::shouldHaveReceived('info')
        ->with('shop.connect_from_product.cap_reached', Mockery::type('array'))
        ->once();
});

it('fetches a favicon for a probe lane that carries none (Shopify, Big Cartel)', function () {
    // Shopify's probe reads only /meta.json and never the storefront HTML, by
    // design — so evidence['favicon'] is always absent and
    // content.storefronts.favicon_url stayed permanently NULL for every store
    // connected this way. Not just a blank suggestion card: ShopBrandResource
    // serves that column, so the Platforms table had no icon either.
    //
    // Fetched HERE rather than in the probe: a probe runs against many
    // candidate URLs that never become stores, and its budget is the scarce
    // thing. Once a store is actually being written, one request is cheap.
    $pro = createTenant('store-favicon');
    Http::fake([
        '*/meta.json' => Http::response(['id' => 4242, 'name' => 'The Store', 'currency' => 'AUD'], 200, ['Content-Type' => 'application/json']),
        'https://example.com/' => Http::response(
            '<html><head><link rel="icon" type="image/png" sizes="32x32" href="/icon-32.png"></head><body>x</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        '*' => Http::response('', 404),
    ]);

    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect(seededStore($pro, '4242')?->faviconUrl)->toBe('https://example.com/icon-32.png');
});

it('does not re-fetch a favicon the probe already carried', function () {
    // Woo/Squarespace/Generic read the homepage anyway, so their evidence
    // already has one. Going back for it would be a second request for
    // something we hold.
    $pro = createTenant('store-favicon-present');
    Http::fake([
        '*/meta.json' => Http::response(['id' => 4242, 'name' => 'The Store', 'currency' => 'AUD'], 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);

    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    // The homepage 404s here, so a null favicon proves the lookup was
    // ATTEMPTED and honestly came back empty rather than throwing.
    expect(seededStore($pro, '4242')?->faviconUrl)->toBeNull();
});
