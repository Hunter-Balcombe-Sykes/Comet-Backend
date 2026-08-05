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

use App\Jobs\Brand\IngestBrandAssetJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Services\Brand\StoreBrandSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
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

it('turns a probed own-domain storefront into a connection and a brand row', function () {
    $pro = createTenant('store-seed');
    storeResponds();

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('placed')
        ->and($result['brandId'])->toBe('4242');

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->firstOrFail();
    expect($connection->surface_key)->toBe('shopify.store');

    $brand = ShopBrand::where('connection_id', $connection->id)->firstOrFail();
    expect($brand->name)->toBe('The Store')
        ->and($brand->currency)->toBe('AUD')
        ->and($brand->provider)->toBe('shopify');
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
        ->and(ShopBrand::count())->toBe(0);
});

it('reports a miss without writing anything', function () {
    $pro = createTenant('store-miss');
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('miss')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('keeps a hand-typed discount code through a re-scan', function () {
    // Carried verbatim from ShopBrandSeeder: the columns default to '' rather
    // than NULL, so a `??` here would read an empty string as "already set"
    // and never apply a scanned code — and a `??` on an existing code would
    // clobber what the user typed.
    $pro = createTenant('store-discount');
    storeResponds();
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    $brand = ShopBrand::firstOrFail();
    $brand->update(['discount_code' => 'TYPED10']);

    Cache::flush();
    storeResponds();
    app(StoreBrandSeeder::class)->seed($pro, 'https://example.com?discount=SCANNED20');

    expect(ShopBrand::firstOrFail()->discount_code)->toBe('TYPED10');
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
    ShopBrand::firstOrFail()->update(['logo' => 'https://cdn.example.com/logo.png']);

    // The seeder queues off the row it wrote; re-running with a logo present is
    // the shape a refreshed brand takes.
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
// directly-inserted brand rows isolate the property under test: the
// aggregate count StoreBrandSeeder itself now guards.
function seedExistingStores(object $user, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $connectionId = (string) Str::uuid();
        DB::table('site.platform_connections')->insert([
            'id' => $connectionId,
            'user_id' => $user->id,
            'surface_key' => "fixture.store{$i}",
            'routing_class' => 'shop',
            'resource_id' => "fixture-{$i}",
            'payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ShopBrand::create([
            'connection_id' => $connectionId,
            'brand_id' => "fixture-brand-{$i}",
            'provider' => 'fixture',
            'url' => "https://fixture{$i}.example.com",
            'source_url' => "https://fixture{$i}.example.com",
            'is_individual' => false,
            'position' => $i,
        ]);
    }
}

it('caps a 6th store the same way the legacy seeder did (MAX_BRANDS parity, WAVE-2C)', function () {
    // Mirrors ShopController::MAX_BRANDS / the legacy ShopBrandSeeder's own
    // copy. StoreBrandSeeder never decides placement itself — but a brand-row
    // cap is genuinely its own concern (the shop_brands row is "the only
    // thing left that is genuinely its own"), so this one thing IS
    // reimplemented here rather than sourced from PlacementPolicy.
    $pro = createTenant('store-capped');
    seedExistingStores($pro, 5);
    storeResponds(['id' => 9999, 'name' => 'The 6th Store', 'currency' => 'AUD']);

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('capped')
        ->and($result['reason'])->toBe('max_brands')
        ->and($result['connectionId'])->toBeNull()
        ->and($result['brandId'])->toBeNull();

    // The placement itself still applies — SourceReconciler's single-writer
    // property is untouched, only the 6th brand row was refused, mirroring
    // the legacy seeder's own ordering (its connection upsert always ran;
    // only the brand write was skipped past the cap).
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->exists())->toBeTrue()
        ->and(ShopBrand::where('is_individual', false)->count())->toBe(5)
        ->and(ShopBrand::where('brand_id', '9999')->exists())->toBeFalse();
});

it('never counts a re-scan of an already-connected store against the cap', function () {
    $pro = createTenant('store-recheck-capped');
    // Four existing stores plus this one lands exactly ON the cap (5), never
    // over it — the interesting case for "a re-scan doesn't count against
    // the cap" is being AT the boundary, not comfortably under it.
    seedExistingStores($pro, 4);
    storeResponds();

    $first = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');
    expect($first['outcome'])->toBe('placed');
    expect(ShopBrand::where('is_individual', false)->count())->toBe(5);

    // Re-scanning the SAME store while the account sits exactly at MAX_BRANDS
    // must still succeed — it's a re-scan of an existing row, not a new one.
    Cache::flush();
    storeResponds();
    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

    expect($result['outcome'])->toBe('placed')
        ->and(ShopBrand::where('is_individual', false)->count())->toBe(5);
});
