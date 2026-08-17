<?php

use App\Jobs\Platforms\ProcessShopBrandLogoJob;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\LogoProcessorClient;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentReader;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ProcessShopBrandLogoJob: runs a connected store's logo through the logo
// processor (background removal + vectorize) and stores the mark URLs on the
// store. Re-home Task 9: the job is dispatched with a content.collections id
// and writes logo_mark_url/logo_mark_svg_url straight onto
// content.storefronts — the legacy site.shop_brands columns are no longer a
// write target. Best-effort: failures leave the raw favicon/logo untouched.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Fix round 1, I1: the marks live on content.* (the dashboard's only source
    // since Task 8), and re-home Task 9 made that the job's ONLY read and write
    // target — the stand-in schema is what the job resolves its store from.
    setupContentTables();
    config()->set('partna.logo_removal.store_enabled', true);
    config()->set('partna.logo_removal.url', 'https://logo-processor.test');
    config()->set('partna.logo_removal.token', 'test-token');
});

afterEach(function () {
    Mockery::close();
});

// The store-logo fetch goes through SafeUrlFetcher (SSRF guard), never Http::get —
// the store's favicon/logo come from scraped HTML and are attacker-controlled. Stub
// it the way the scraper tests do; pass null to simulate the guard rejecting the URL.
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

/**
 * The store the job resolves, and the collection id it is dispatched with.
 *
 * Was logoJobBrand() (a site.shop_brands row) plus a mirroring upsertStore().
 * The re-home dropped that table, so content.collections + content.storefronts
 * IS the store — one write, through the production writer. $overrides take
 * StoreRecord property names (logoUrl/faviconUrl, not logo/favicon).
 *
 * @return array{0: StoreRecord, 1: string}
 */
function logoJobStore(User $user, array $overrides = []): array
{
    $record = (new StoreRecord(
        externalRef: 'test-store.example',
        provider: 'shopify',
        name: 'Test Store',
        url: 'https://test-store.example',
        isIndividual: false,
        logoUrl: 'https://test-store.example/logo.png',
        faviconUrl: 'https://test-store.example/favicon.png',
    ))->with($overrides);

    // The store's own anchor connection — one connection per store, keyed on
    // the store id (convergence Phase 6, owner ruling 4).
    app(ShopConnections::class)->anchor($user, $record->provider, $record->externalRef);

    $collectionId = app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    return [app(ShopConnections::class)->store($user, $record->externalRef), $collectionId];
}

/** The store's content.storefronts row — where the two mark columns live. */
function logoJobStorefront(string $collectionId): object
{
    return DB::table('content.storefronts')->where('collection_id', $collectionId)->first();
}

it('stores processed mark urls on the storefront row', function () {
    Storage::fake('media');
    config()->set('partna.media.disk', 'media');
    $user = logoJobUser('logojob1');
    [$store, $collectionId] = logoJobStore($user);

    Http::fake([
        'logo-processor.test/*' => Http::response([
            'png_transparent' => base64_encode('transparent-png-bytes'),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'meta' => ['vectorized' => true],
        ]),
    ]);

    (new ProcessShopBrandLogoJob($collectionId))
        ->handle(app(LogoProcessorClient::class), logoJobFetcher(logoJobPngResponse()), app(ShopConnections::class));

    // Re-home Task 9: one write, straight onto content.storefronts, where the
    // legacy row and a mirroring upsertStore() used to be two.
    $storefront = logoJobStorefront($collectionId);
    expect($storefront->logo_mark_url)->not->toBeNull();
    expect($storefront->logo_mark_svg_url)->not->toBeNull();
    // Spec §5.1: the R2 prefix is keyed on the collection id now, not on the
    // legacy shop_brands uuid.
    expect($storefront->logo_mark_url)->toContain("shop-brands/{$collectionId}/");

    // The brand payload now carries the conditional mark keys. Read through
    // ShopContentReader since re-home Task 2 deleted toBrandArray() — same
    // assertion, against the map the dashboard actually receives.
    $payload = app(ShopContentReader::class)->brandMap($user)[$store->externalRef];
    expect($payload['logoMark'])->toBe($storefront->logo_mark_url);
    expect($payload['logoMarkSvg'])->toBe($storefront->logo_mark_svg_url);
});

it('no-ops when the store switch is off', function () {
    config()->set('partna.logo_removal.store_enabled', false);
    [, $collectionId] = logoJobStore(logoJobUser('logojob2'));

    Http::fake();
    // shouldNotReceive: the switch must short-circuit BEFORE any outbound fetch.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldNotReceive('tryFetch');

    (new ProcessShopBrandLogoJob($collectionId))->handle(app(LogoProcessorClient::class), $fetcher, app(ShopConnections::class));

    Http::assertNothingSent();
    expect(logoJobStorefront($collectionId)->logo_mark_url)->toBeNull();
});

it('leaves the store untouched when the processor fails', function () {
    $user = logoJobUser('logojob3');
    // The store needs a content.* row for the payload assertion below to mean
    // anything — asserting a key is absent from a map that has no entry for
    // this brand at all would pass whatever the emission rule did.
    [$store, $collectionId] = logoJobStore($user);

    Http::fake([
        'logo-processor.test/*' => Http::response('nope', 500),
    ]);

    (new ProcessShopBrandLogoJob($collectionId))
        ->handle(app(LogoProcessorClient::class), logoJobFetcher(logoJobPngResponse()), app(ShopConnections::class));

    expect(logoJobStorefront($collectionId)->logo_mark_url)->toBeNull();

    $payload = app(ShopContentReader::class)->brandMap($user)[$store->externalRef];
    expect($payload)->not->toHaveKey('logoMark');
});

// SSRF regression. favicon/logo are lifted verbatim from a <link rel="icon"> in
// scraped HTML (PlatformScraper::favicon), so a store the visitor controls can
// aim this at the cloud-metadata endpoint. Uses the REAL SafeUrlFetcher — the
// literal private IP is rejected on inspection, no DNS and no network needed.
it('never fetches a private-network logo url (SSRF)', function () {
    [, $collectionId] = logoJobStore(logoJobUser('logojob5'), [
        'logoUrl' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        'faviconUrl' => null,
    ]);

    Http::fake();

    (new ProcessShopBrandLogoJob($collectionId))
        ->handle(app(LogoProcessorClient::class), app(SafeUrlFetcher::class), app(ShopConnections::class));

    Http::assertNothingSent();
    expect(logoJobStorefront($collectionId)->logo_mark_url)->toBeNull();
});

it('settled brands without marks emit no mark keys (byte identity)', function () {
    $user = logoJobUser('logojob4');
    [$store] = logoJobStore($user);

    // The entry must EXIST for the absence of the two keys to be a real
    // assertion rather than a vacuous one — pin the id first.
    $payload = app(ShopContentReader::class)->brandMap($user)[$store->externalRef];
    expect($payload['id'])->toBe($store->externalRef)
        ->and($payload)->not->toHaveKeys(['logoMark', 'logoMarkSvg']);
});
