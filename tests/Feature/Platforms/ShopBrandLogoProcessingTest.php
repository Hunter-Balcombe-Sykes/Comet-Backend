<?php

use App\Jobs\Platforms\ProcessShopBrandLogoJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\LogoProcessorClient;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ProcessShopBrandLogoJob: runs a connected store's logo through the logo
// processor (background removal + vectorize) and stores the mark URLs on the
// brand row. Best-effort: failures leave the raw favicon/logo untouched.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Fix round 1, I1: the job mirrors the processed marks onto content.*
    // (the dashboard's only source since Task 8), so the stand-in schema has
    // to exist for the job to complete.
    setupContentTables();
    config()->set('partna.logo_removal.store_enabled', true);
    config()->set('partna.logo_removal.url', 'https://logo-processor.test');
    config()->set('partna.logo_removal.token', 'test-token');
});

afterEach(function () {
    Mockery::close();
});

// The store-logo fetch goes through SafeUrlFetcher (SSRF guard), never Http::get —
// $brand->favicon/logo come from scraped HTML and are attacker-controlled. Stub it
// the way the scraper tests do; pass null to simulate the guard rejecting the URL.
function logoJobFetcher(?array $response): SafeUrlFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn($response);

    return $fetcher;
}

function logoJobPngResponse(): array
{
    return [
        'status' => 200,
        'body' => 'rawpngbytes',
        'finalUrl' => 'https://test-store.example/logo.png',
        'contentType' => 'image/png',
    ];
}

function logoJobUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function logoJobBrand(User $user, array $overrides = []): ShopBrand
{
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
    ]);

    return ShopBrand::create(array_merge([
        'connection_id' => $connection->id,
        'brand_id' => 'test-store.example',
        'provider' => 'shopify',
        'url' => 'https://test-store.example',
        'name' => 'Test Store',
        'favicon' => 'https://test-store.example/favicon.png',
        'logo' => 'https://test-store.example/logo.png',
        'is_individual' => false,
    ], $overrides));
}

it('stores processed mark urls on the brand row', function () {
    Storage::fake('media');
    config()->set('partna.media.disk', 'media');
    $brand = logoJobBrand(logoJobUser('logojob1'));

    Http::fake([
        'logo-processor.test/*' => Http::response([
            'png_transparent' => base64_encode('transparent-png-bytes'),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'meta' => ['vectorized' => true],
        ]),
    ]);

    (new ProcessShopBrandLogoJob((string) $brand->id))
        ->handle(app(LogoProcessorClient::class), logoJobFetcher(logoJobPngResponse()), app(ShopContentWriter::class));

    $brand->refresh();
    expect($brand->logo_mark_url)->not->toBeNull();
    expect($brand->logo_mark_svg_url)->not->toBeNull();

    // The brand payload now carries the conditional mark keys.
    $array = $brand->toBrandArray();
    expect($array['logoMark'])->toBe($brand->logo_mark_url);
    expect($array['logoMarkSvg'])->toBe($brand->logo_mark_svg_url);

    // Fix round 1, I1: and so does content.storefronts — the dashboard reads
    // ShopContentReader with no legacy fallback since Task 8, and this job
    // runs AFTER addBrand()/ShopBrandConnectJob's own upsertStore(), so
    // without its own mirror write the marks stayed invisible until the next
    // scheduled sync (6h) or brand edit.
    $storefront = DB::table('content.storefronts')->where('external_ref', $brand->brand_id)->first();
    expect($storefront->logo_mark_url)->toBe($brand->logo_mark_url)
        ->and($storefront->logo_mark_svg_url)->toBe($brand->logo_mark_svg_url);
});

it('no-ops when the store switch is off', function () {
    config()->set('partna.logo_removal.store_enabled', false);
    $brand = logoJobBrand(logoJobUser('logojob2'));

    Http::fake();
    // shouldNotReceive: the switch must short-circuit BEFORE any outbound fetch.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldNotReceive('tryFetch');

    (new ProcessShopBrandLogoJob((string) $brand->id))->handle(app(LogoProcessorClient::class), $fetcher, app(ShopContentWriter::class));

    Http::assertNothingSent();
    expect($brand->refresh()->logo_mark_url)->toBeNull();
});

it('leaves the row untouched when the processor fails', function () {
    $brand = logoJobBrand(logoJobUser('logojob3'));

    Http::fake([
        'logo-processor.test/*' => Http::response('nope', 500),
    ]);

    (new ProcessShopBrandLogoJob((string) $brand->id))
        ->handle(app(LogoProcessorClient::class), logoJobFetcher(logoJobPngResponse()), app(ShopContentWriter::class));

    $brand->refresh();
    expect($brand->logo_mark_url)->toBeNull();
    expect($brand->toBrandArray())->not->toHaveKey('logoMark');
});

// SSRF regression. favicon/logo are lifted verbatim from a <link rel="icon"> in
// scraped HTML (PlatformScraper::favicon), so a store the visitor controls can
// aim this at the cloud-metadata endpoint. Uses the REAL SafeUrlFetcher — the
// literal private IP is rejected on inspection, no DNS and no network needed.
it('never fetches a private-network logo url (SSRF)', function () {
    $brand = logoJobBrand(logoJobUser('logojob5'), [
        'logo' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'favicon' => null,
    ]);

    Http::fake();

    (new ProcessShopBrandLogoJob((string) $brand->id))
        ->handle(app(LogoProcessorClient::class), app(SafeUrlFetcher::class), app(ShopContentWriter::class));

    Http::assertNothingSent();
    expect($brand->refresh()->logo_mark_url)->toBeNull();
});

it('settled brands without marks emit no mark keys (byte identity)', function () {
    $brand = logoJobBrand(logoJobUser('logojob4'));
    expect($brand->toBrandArray())->not->toHaveKeys(['logoMark', 'logoMarkSvg']);
});
