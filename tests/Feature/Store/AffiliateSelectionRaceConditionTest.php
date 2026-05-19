<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Models\Core\Professional\Professional;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Feature tests already use TestCase (configured in Pest.php for Feature/).

beforeEach(function () {
    config(['partna.store.max_featured_products' => 3]);

    DB::purge('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['core', 'brand', 'commerce'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY, handle TEXT, handle_lc TEXT, professional_type TEXT,
        status TEXT DEFAULT "active", deleted_at TEXT, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT,
        brand_professional_id TEXT, slot INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT,
        deleted_at TEXT NULL
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT,
        brand_professional_id TEXT, shopify_product_gid TEXT,
        sort_order INTEGER DEFAULT 0, selected_variant_gids TEXT,
        created_at TEXT, updated_at TEXT,
        UNIQUE(affiliate_professional_id, shopify_product_gid)
    )');
});

it('enforces max_featured_products when the count check is inside the transaction', function () {
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $conn = DB::connection('pgsql');

    $conn->table('core.professionals')->insert([
        'id' => $affiliateId, 'handle' => 'sarah', 'handle_lc' => 'sarah',
        'professional_type' => 'professional', 'status' => 'active',
    ]);
    $conn->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
    ]);

    // Pre-seed 3 selections (== max)
    foreach (range(1, 3) as $i) {
        $conn->table('commerce.affiliate_product_selections')->insert([
            'id' => (string) Str::uuid(),
            'affiliate_professional_id' => $affiliateId,
            'brand_professional_id' => $brandId,
            'shopify_product_gid' => "gid://shopify/Product/{$i}",
            'sort_order' => $i,
        ]);
    }

    // Mock catalog service — product exists in catalog
    $catalog = Mockery::mock(AffiliateProductCatalogService::class);
    $catalog->shouldReceive('isProductInCatalog')->andReturn(true);
    $catalog->shouldReceive('getEnabledVariantGidsForProduct')->andReturn(['gid://shopify/ProductVariant/10']);
    app()->instance(AffiliateProductCatalogService::class, $catalog);

    // Build a Professional model from the raw DB row so the controller resolves it correctly
    $pro = (new Professional)->forceFill([
        'id' => $affiliateId,
        'handle' => 'sarah',
        'handle_lc' => 'sarah',
        'professional_type' => 'professional',
        'status' => 'active',
    ]);

    $request = Request::create('/affiliate/selections', 'POST', [
        'brand_professional_id' => $brandId,
        'shopify_product_gid' => 'gid://shopify/Product/99',
    ]);
    $request->attributes->set('professional', $pro);

    $controller = app(AffiliateProductController::class);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])->toContain('Maximum');
});
