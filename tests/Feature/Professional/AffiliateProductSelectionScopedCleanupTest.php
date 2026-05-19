<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Models\Core\Professional\Professional;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    DB::purge('pgsql');

    $conn = DB::connection('pgsql');
    foreach (['brand'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY, affiliate_professional_id TEXT,
        brand_professional_id TEXT, slot INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT,
        deleted_at TEXT NULL
    )');
});

it('rejects store when affiliate is not linked to any brand', function () {
    // brand_partner_links table is empty — no link for this affiliate
    $affiliate = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'professional_type' => 'professional',
        'status' => 'active',
    ]);

    $request = Request::create('/', 'POST', [
        'shopify_product_gid' => 'gid://shopify/Product/123',
        'sort_order' => 0,
    ]);
    $request->attributes->set('professional', $affiliate);

    $catalog = Mockery::mock(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($catalog);
    $response = $controller->store($request);

    expect($response->status())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])->toContain('not linked');
});
