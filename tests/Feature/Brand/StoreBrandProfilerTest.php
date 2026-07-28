<?php

// The ShopBrandProfiler successor (WAVE-2C item 2; plan §12): a store's
// display profile derived from what the pipeline already holds — probe
// evidence on the brand row, owned assets, catalog offers — never a fetch.
//
// The property under test: profile() makes zero HTTP requests. The legacy
// profiler's whole shape (per-provider branches + a deferred job) existed
// because it fetched; if a fetch ever creeps back in, this suite is the alarm.

use App\Models\Core\Site\ShopBrand;
use App\Services\Brand\StoreBrandProfiler;
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

function profilerBrand(string $connectionId, array $overrides = []): ShopBrand
{
    return ShopBrand::create(array_merge([
        'connection_id' => $connectionId,
        'brand_id' => 'brand-'.Str::lower(Str::random(6)),
        'provider' => 'shopify',
        'url' => 'https://example.com',
        'source_url' => 'https://example.com',
        'is_individual' => false,
        'position' => 0,
    ], $overrides));
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
    profilerBrand($connectionId, ['name' => 'The Store', 'currency' => 'AUD', 'logo' => 'https://cdn.example.com/logo.png']);

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
    profilerBrand($connectionId, ['logo' => 'https://cdn.example.com/theirs.png']);
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/abc.webp');

    $profile = app(StoreBrandProfiler::class)->profile($connectionId);

    expect($profile['ownedLogo'])->toBeTrue()
        ->and($profile['logoUrl'])->toContain('brand-assets/u/abc.webp');
});

it('prefers the full mark over the square one', function () {
    $connectionId = profilerConnection();
    profilerBrand($connectionId);
    ownedLogo($connectionId, 'logo_square', 'brand-assets/u/square.webp');
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/full.webp');

    expect(app(StoreBrandProfiler::class)->profile($connectionId)['logoUrl'])
        ->toContain('full.webp');
});

it('derives the trading currency from the catalog when the row has none', function () {
    $connectionId = profilerConnection();
    profilerBrand($connectionId, ['currency' => null]);
    offerFor($connectionId, 'AUD');
    offerFor($connectionId, 'AUD');
    offerFor($connectionId, 'USD');

    expect(app(StoreBrandProfiler::class)->profile($connectionId)['currency'])->toBe('AUD');
});

it('settles a pending brand row from the catalog, fill-if-empty only', function () {
    $connectionId = profilerConnection();
    $brand = profilerBrand($connectionId, ['name' => null, 'currency' => null]);
    offerFor($connectionId, 'NZD');
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/x.webp', 'Acme Prints');

    $changed = app(StoreBrandProfiler::class)->settle($brand);

    expect($changed)->toBeTrue()
        ->and($brand->fresh()->currency)->toBe('NZD')
        ->and($brand->fresh()->name)->toBe('Acme Prints');
});

it('never competes with a value the probe already earned', function () {
    $connectionId = profilerConnection();
    $brand = profilerBrand($connectionId, ['name' => 'Probe Name', 'currency' => 'AUD']);
    offerFor($connectionId, 'USD');

    $changed = app(StoreBrandProfiler::class)->settle($brand);

    expect($changed)->toBeFalse()
        ->and($brand->fresh()->name)->toBe('Probe Name')
        ->and($brand->fresh()->currency)->toBe('AUD');
});

it('does not promote a URL-shaped attribution to a store name', function () {
    $connectionId = profilerConnection();
    $brand = profilerBrand($connectionId, ['name' => null]);
    ownedLogo($connectionId, 'logo_full', 'brand-assets/u/y.webp', 'https://example.com');

    app(StoreBrandProfiler::class)->settle($brand);

    expect($brand->fresh()->name)->toBeNull();
});
