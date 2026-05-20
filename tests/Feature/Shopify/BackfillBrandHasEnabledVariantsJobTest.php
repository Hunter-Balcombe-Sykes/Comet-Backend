<?php

use App\Jobs\Shopify\BackfillBrandHasEnabledVariantsJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\DB;

// Coverage for the F10 rewrite of BackfillBrandHasEnabledVariantsJob: batched
// metafieldsSet writes (chunks of 25) and cursor checkpointing in
// provider_metadata so a timed-out attempt resumes instead of replaying from
// zero. BrandCatalogService is mocked — it is the Shopify HTTP boundary and the
// job already injects it; its wire behaviour is covered by the unit test.

beforeEach(function () {
    setupProfessionalsTable();
    setupProfessionalIntegrationsTable();
});

/**
 * Seed a brand Professional + Shopify integration and return the integration.
 */
function seedBackfillIntegration(array $providerMeta = []): ProfessionalIntegration
{
    $proId = 'brand-'.uniqid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'account_type' => 'brand',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ProfessionalIntegration::create([
        'id' => 'int-'.uniqid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test_token',
        'provider_metadata' => array_merge(['shop_domain' => 'b.myshopify.com'], $providerMeta),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Build a catalog of $n single-variant-less products needing has_enabled_variants=true.
 * No variants → hasEnabled is trivially true; no existing metafield → needs a write.
 *
 * @return array<int, array{gid: string, variants: array, metafields: array}>
 */
function fakeCatalog(int $n, int $start = 1): array
{
    $products = [];
    for ($i = $start; $i < $start + $n; $i++) {
        $products[] = [
            'gid' => "gid://shopify/Product/{$i}",
            'variants' => [],
            'metafields' => ['has_enabled_variants' => null],
        ];
    }

    return $products;
}

function runBackfill(ProfessionalIntegration $integration): void
{
    app()->call([new BackfillBrandHasEnabledVariantsJob($integration->id), 'handle']);
}

it('writes products in batches of at most 25 per metafieldsSet call', function () {
    $integration = seedBackfillIntegration();

    $batchSizes = [];
    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn(fakeCatalog(30));
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->twice()
        ->andReturnUsing(function ($integration, array $map) use (&$batchSizes) {
            $batchSizes[] = count($map);

            return ['success' => true, 'userErrors' => []];
        });

    runBackfill($integration);

    expect($batchSizes)->toBe([25, 5]);
    foreach ($batchSizes as $size) {
        expect($size)->toBeLessThanOrEqual(25);
    }
});

it('clears the cursor and marks state complete after a fully successful run', function () {
    $integration = seedBackfillIntegration();

    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn(fakeCatalog(10));
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->once()
        ->andReturn(['success' => true, 'userErrors' => []]);

    runBackfill($integration);

    $integration->refresh();
    $meta = $integration->provider_metadata;
    expect($meta['has_enabled_variants_backfill_state'])->toBe('complete');
    expect($meta['has_enabled_variants_backfill_cursor'] ?? [])->toBe([]);
});

it('checkpoints successfully written GIDs into the cursor and keeps them on partial failure', function () {
    $integration = seedBackfillIntegration();

    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn(fakeCatalog(30));
    // First batch (25) succeeds, second batch (5) fails.
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->twice()
        ->andReturnUsing(function ($integration, array $map) {
            static $call = 0;
            $call++;

            return $call === 1
                ? ['success' => true, 'userErrors' => []]
                : ['success' => false, 'userErrors' => [['message' => 'rate limited']]];
        });

    runBackfill($integration);

    $integration->refresh();
    $meta = $integration->provider_metadata;
    expect($meta['has_enabled_variants_backfill_state'])->toBe('partial');
    // The 25 from the successful batch are checkpointed; the failed 5 are not.
    expect($meta['has_enabled_variants_backfill_cursor'])->toHaveCount(25);
    expect($meta['has_enabled_variants_backfill_cursor'])->toContain('gid://shopify/Product/1');
    expect($meta['has_enabled_variants_backfill_cursor'])->not->toContain('gid://shopify/Product/30');
});

it('skips products already recorded in the cursor on a resumed run', function () {
    $cursor = ['gid://shopify/Product/1', 'gid://shopify/Product/2', 'gid://shopify/Product/3'];
    $integration = seedBackfillIntegration(['has_enabled_variants_backfill_cursor' => $cursor]);

    $writtenGids = [];
    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn(fakeCatalog(10));
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->once()
        ->andReturnUsing(function ($integration, array $map) use (&$writtenGids) {
            $writtenGids = array_keys($map);

            return ['success' => true, 'userErrors' => []];
        });

    runBackfill($integration);

    // Products 1-3 were in the cursor → only 4-10 should be written.
    expect($writtenGids)->toHaveCount(7);
    expect($writtenGids)->not->toContain('gid://shopify/Product/1');
    expect($writtenGids)->toContain('gid://shopify/Product/4');
});

it('skips products whose has_enabled_variants metafield already matches', function () {
    $integration = seedBackfillIntegration();

    // Product 1 already has the correct value → no write needed.
    $catalog = [
        ['gid' => 'gid://shopify/Product/1', 'variants' => [], 'metafields' => ['has_enabled_variants' => true]],
        ['gid' => 'gid://shopify/Product/2', 'variants' => [], 'metafields' => ['has_enabled_variants' => null]],
    ];

    $writtenGids = [];
    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn($catalog);
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->once()
        ->andReturnUsing(function ($integration, array $map) use (&$writtenGids) {
            $writtenGids = array_keys($map);

            return ['success' => true, 'userErrors' => []];
        });

    runBackfill($integration);

    expect($writtenGids)->toBe(['gid://shopify/Product/2']);
});

it('propagates a transport exception so the job retries, keeping the cursor from earlier batches', function () {
    $integration = seedBackfillIntegration();

    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn(fakeCatalog(30));
    // First batch (25) succeeds and checkpoints; second batch throws a
    // transport error — this must bubble up so Laravel retries the job.
    $mock->shouldReceive('writeHasEnabledVariantsBatch')
        ->twice()
        ->andReturnUsing(function ($integration, array $map) {
            static $call = 0;
            $call++;

            if ($call === 1) {
                return ['success' => true, 'userErrors' => []];
            }

            throw new RuntimeException('shopify transport error');
        });

    expect(fn () => runBackfill($integration))->toThrow(RuntimeException::class);

    // The 25 products from the successful batch survive for the retry to skip.
    $integration->refresh();
    expect($integration->provider_metadata['has_enabled_variants_backfill_cursor'])->toHaveCount(25);
});

it('marks state failed via the failed() handler', function () {
    $integration = seedBackfillIntegration();

    (new BackfillBrandHasEnabledVariantsJob($integration->id))
        ->failed(new RuntimeException('exhausted retries'));

    $integration->refresh();
    expect($integration->provider_metadata['has_enabled_variants_backfill_state'])->toBe('failed');
});

it('makes no write call and marks complete when every product already matches', function () {
    $integration = seedBackfillIntegration();

    $catalog = [
        ['gid' => 'gid://shopify/Product/1', 'variants' => [], 'metafields' => ['has_enabled_variants' => true]],
    ];

    $mock = $this->mock(BrandCatalogService::class);
    $mock->shouldReceive('fetchBrandCatalog')->once()->andReturn($catalog);
    $mock->shouldNotReceive('writeHasEnabledVariantsBatch');

    runBackfill($integration);

    $integration->refresh();
    expect($integration->provider_metadata['has_enabled_variants_backfill_state'])->toBe('complete');
});
