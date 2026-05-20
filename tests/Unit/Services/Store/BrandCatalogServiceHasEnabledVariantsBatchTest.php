<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Coverage for writeHasEnabledVariantsBatch — the batched metafieldsSet writer
// added for F10 so the install-time backfill collapses one-call-per-product
// into one call per 25 products.

function makeBatchIntegration(): ProfessionalIntegration
{
    $integration = new ProfessionalIntegration([
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test',
        'provider_metadata' => ['shop_domain' => 'test-shop.myshopify.com'],
    ]);
    $integration->id = 'int-123';
    $integration->professional_id = 'brand-123';

    return $integration;
}

it('writes every product in the map with one metafieldsSet round-trip', function () {
    Http::fake([
        '*/admin/api/*/graphql.json' => Http::response([
            'data' => ['metafieldsSet' => ['metafields' => [], 'userErrors' => []]],
        ], 200),
    ]);

    $service = app(BrandCatalogService::class);
    $result = $service->writeHasEnabledVariantsBatch(makeBatchIntegration(), [
        'gid://shopify/Product/1' => true,
        'gid://shopify/Product/2' => false,
        'gid://shopify/Product/3' => true,
    ]);

    expect($result['success'])->toBeTrue();
    Http::assertSentCount(1);

    Http::assertSent(function ($request) {
        $metafields = $request['variables']['metafields'] ?? [];

        return count($metafields) === 3
            && $metafields[0]['ownerId'] === 'gid://shopify/Product/1'
            && $metafields[0]['key'] === 'has_enabled_variants'
            && $metafields[0]['namespace'] === 'partna'
            && $metafields[0]['value'] === 'true'
            && $metafields[1]['value'] === 'false';
    });
});

it('returns success false and surfaces userErrors when Shopify rejects the batch', function () {
    Http::fake([
        '*/admin/api/*/graphql.json' => Http::response([
            'data' => ['metafieldsSet' => [
                'metafields' => [],
                'userErrors' => [['field' => ['metafields', '0'], 'message' => 'bad owner']],
            ]],
        ], 200),
    ]);

    $service = app(BrandCatalogService::class);
    $result = $service->writeHasEnabledVariantsBatch(makeBatchIntegration(), [
        'gid://shopify/Product/1' => true,
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['userErrors'])->toHaveCount(1);
});

it('rejects a batch larger than the Shopify 25-input cap before calling the API', function () {
    Http::fake();

    $oversized = [];
    for ($i = 1; $i <= 26; $i++) {
        $oversized["gid://shopify/Product/{$i}"] = true;
    }

    $service = app(BrandCatalogService::class);

    expect(fn () => $service->writeHasEnabledVariantsBatch(makeBatchIntegration(), $oversized))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('makes no HTTP call for an empty product map', function () {
    Http::fake();

    $service = app(BrandCatalogService::class);
    $result = $service->writeHasEnabledVariantsBatch(makeBatchIntegration(), []);

    expect($result['success'])->toBeTrue();
    Http::assertNothingSent();
});
