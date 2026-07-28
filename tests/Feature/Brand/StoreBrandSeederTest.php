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

    $result = app(StoreBrandSeeder::class)->seed($pro, 'https://example.com');

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
