<?php

// The ShopBrandProfiler successor (WAVE-2C item 2; plan §12): a store's
// display profile derived from what the pipeline already holds — probe
// evidence on the store, owned assets, catalog offers — never a fetch.
//
// The property under test: profile() makes zero HTTP requests. The legacy
// profiler's whole shape (per-provider branches + a deferred job) existed
// because it fetched; if a fetch ever creeps back in, this suite is the alarm.
//
// Re-home Task 10: the probe's evidence lives on content.storefronts now, and
// the bridge from a connection to its store is the connection's own
// resource_id (= the store's external_ref) — content.storefronts carries no
// connection column at all.

use App\Services\Brand\StoreBrandProfiler;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBrandAssetRefsTable();
    Http::fake();
});

function profilerConnection(): string
{
    $pro = createTenant('profiler-'.Str::lower(Str::random(6)));
    $connectionId = (string) Str::uuid();

    DB::table('site.platform_connections')->insert([
        'id' => $connectionId,
        'user_id' => $pro->id,
        // `platform` is a generated alias of surface_key — never inserted.
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'resource_id' => 'store-'.Str::lower(Str::random(6)),
        'payload' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $connectionId;
}

/**
 * The store the profiler reads: a content.collections + content.storefronts
 * pair, written by upsertStore(). It used to be seeded as a site.shop_brands
 * row and mirrored across; that table is gone (20260819000210) and content.*
 * is the only storage a store has, so the fixture writes it directly.
 *
 * externalRef is pinned to the connection's own resource_id rather than
 * randomised — that pairing IS the connection→store bridge the profiler
 * resolves through since Task 10 (content.storefronts carries no connection
 * column), so a random id would leave the store unreachable.
 *
 * $overrides keys are StoreRecord property names: the legacy row's `logo`
 * is `logoUrl`, `source_url` is `sourceUrl`, `brand_id` is `externalRef`.
 */
function profilerStore(string $connectionId, array $overrides = []): StoreRecord
{
    $connection = DB::table('site.platform_connections')->where('id', $connectionId)->first();

    $store = (new StoreRecord(
        externalRef: (string) $connection->resource_id,
        provider: 'shopify',
        position: 0,
        url: 'https://example.com',
        sourceUrl: 'https://example.com',
        isIndividual: false,
    ))->with($overrides);

    app(ShopContentWriter::class)->upsertStore($store, (string) $connection->user_id);

    return $store;
}

function ownedLogo(string $connectionId, string $role, string $path, ?string $attribution = null): void
{
    $assetId = (string) Str::uuid();
    DB::table('content.media_assets')->insert([
        'id' => $assetId,
        'user_id' => (string) Str::uuid(),
        'fingerprint' => hash('sha256', $path),
        'storage_path' => $path,
        'mime_type' => 'image/webp',
        'created_at' => now(),
    ]);
    DB::table('content.brand_asset_refs')->insert([
        'id' => (string) Str::uuid(),
        'connection_id' => $connectionId,
        'role' => $role,
        'asset_id' => $assetId,
        'attribution' => $attribution,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function offerFor(string $connectionId, ?string $currency, string $updatedAt = '2026-07-01 00:00:00'): void
{
    $sourceId = DB::table('content.sources')->where('connection_id', $connectionId)->value('id');
    if ($sourceId === null) {
        $sourceId = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $sourceId,
            'user_id' => (string) Str::uuid(),
            'kind' => 'connection',
            'connection_id' => $connectionId,
            'priority' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(),
        'item_id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'amount_minor' => 1900,
        'currency' => $currency,
        'qualifier' => 'exact',
        'updated_at' => $updatedAt,
    ]);
}

it('profiles a store from stored data without a single request', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['name' => 'The Store', 'currency' => 'AUD', 'logoUrl' => 'https://cdn.example.com/logo.png']);

    $profile = app(StoreBrandProfiler::class)->profile($connectionId);

    expect($profile['name'])->toBe('The Store')
        ->and($profile['currency'])->toBe('AUD')
        ->and($profile['logoUrl'])->toBe('https://cdn.example.com/logo.png')
        ->and($profile['ownedLogo'])->toBeFalse();
    Http::assertNothingSent();
});

it('serves the owned sanitised logo over the hotlinked one', function () {
    // A CDN URL rots and is a live third-party channel onto the page; the
    // bytes the pipeline sanitised are neither. Owned always wins.
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['logoUrl' => 'https://cdn.example.com/theirs.png']);
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/abc.webp');

    $profile = app(StoreBrandProfiler::class)->profile($connectionId);

    expect($profile['ownedLogo'])->toBeTrue()
        ->and($profile['logoUrl'])->toContain('brand-assets/u/abc.webp');
});

it('prefers the full mark over the square one', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId);
    ownedLogo($connectionId, 'logo_square', 'brand-assets/u/square.webp');
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/full.webp');

    expect(app(StoreBrandProfiler::class)->profile($connectionId)['logoUrl'])
        ->toContain('full.webp');
});

it('derives the trading currency from the catalog when the row has none', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['currency' => null]);
    offerFor($connectionId, 'AUD');
    offerFor($connectionId, 'AUD');
    offerFor($connectionId, 'USD');

    expect(app(StoreBrandProfiler::class)->profile($connectionId)['currency'])->toBe('AUD');
});

// The three tests below used to drive settle(), which FILLED an empty
// name/currency onto the brand row and reported whether it had written. Task 10
// deleted that method — it had no production caller, only these tests, and
// content.storefronts is written by upsertStore() alone. The derivation rules it
// encoded are unchanged and still live: profile() applies exactly the same
// fill-if-empty precedence, it just returns the answer instead of persisting it.
// So each test now asserts the SAME rule through profile(); only the "and it
// wrote the row" half is gone, along with the method that did the writing.

it('derives name and currency from the catalog when the store carries neither, fill-if-empty only', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['name' => null, 'currency' => null]);
    offerFor($connectionId, 'NZD');
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/x.webp', 'Acme Prints');

    $profile = app(StoreBrandProfiler::class)->profile($connectionId);

    expect($profile['currency'])->toBe('NZD')
        ->and($profile['name'])->toBe('Acme Prints');
});

it('never competes with a value the probe already earned', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['name' => 'Probe Name', 'currency' => 'AUD']);
    offerFor($connectionId, 'USD');

    $profile = app(StoreBrandProfiler::class)->profile($connectionId);

    expect($profile['name'])->toBe('Probe Name')
        ->and($profile['currency'])->toBe('AUD');
});

it('does not promote a URL-shaped attribution to a store name', function () {
    $connectionId = profilerConnection();
    profilerStore($connectionId, ['name' => null]);
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/y.webp', 'https://example.com');

    expect(app(StoreBrandProfiler::class)->profile($connectionId)['name'])->toBeNull();
});
