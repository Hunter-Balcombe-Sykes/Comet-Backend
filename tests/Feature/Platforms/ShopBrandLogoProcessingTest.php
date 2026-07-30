<?php

use App\Jobs\Platforms\ProcessShopBrandLogoJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Media\LogoProcessorClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ProcessShopBrandLogoJob: runs a connected store's logo through the logo
// processor (background removal + vectorize) and stores the mark URLs on the
// brand row. Best-effort: failures leave the raw favicon/logo untouched.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    config()->set('partna.logo_removal.store_enabled', true);
    config()->set('partna.logo_removal.url', 'https://logo-processor.test');
    config()->set('partna.logo_removal.token', 'test-token');
});

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
        'test-store.example/*' => Http::response('rawpngbytes', 200, ['Content-Type' => 'image/png']),
        'logo-processor.test/*' => Http::response([
            'png_transparent' => base64_encode('transparent-png-bytes'),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'meta' => ['vectorized' => true],
        ]),
    ]);

    (new ProcessShopBrandLogoJob((string) $brand->id))->handle(app(LogoProcessorClient::class));

    $brand->refresh();
    expect($brand->logo_mark_url)->not->toBeNull();
    expect($brand->logo_mark_svg_url)->not->toBeNull();

    // The brand payload now carries the conditional mark keys.
    $array = $brand->toBrandArray();
    expect($array['logoMark'])->toBe($brand->logo_mark_url);
    expect($array['logoMarkSvg'])->toBe($brand->logo_mark_svg_url);
});

it('no-ops when the store switch is off', function () {
    config()->set('partna.logo_removal.store_enabled', false);
    $brand = logoJobBrand(logoJobUser('logojob2'));

    Http::fake();
    (new ProcessShopBrandLogoJob((string) $brand->id))->handle(app(LogoProcessorClient::class));

    Http::assertNothingSent();
    expect($brand->refresh()->logo_mark_url)->toBeNull();
});

it('leaves the row untouched when the processor fails', function () {
    $brand = logoJobBrand(logoJobUser('logojob3'));

    Http::fake([
        'test-store.example/*' => Http::response('rawpngbytes', 200, ['Content-Type' => 'image/png']),
        'logo-processor.test/*' => Http::response('nope', 500),
    ]);

    (new ProcessShopBrandLogoJob((string) $brand->id))->handle(app(LogoProcessorClient::class));

    $brand->refresh();
    expect($brand->logo_mark_url)->toBeNull();
    expect($brand->toBrandArray())->not->toHaveKey('logoMark');
});

it('settled brands without marks emit no mark keys (byte identity)', function () {
    $brand = logoJobBrand(logoJobUser('logojob4'));
    expect($brand->toBrandArray())->not->toHaveKeys(['logoMark', 'logoMarkSvg']);
});
