<?php

use App\Jobs\Cache\InvalidateBrandAffiliatesCacheJob;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery as M;

// CACHE-6 + SCALE-3: invalidateSite() brand branch clears Hydrogen affiliate-page
// caches for every connected BrandPartnerLink row, and dispatches a single bulk job
// instead of an O(N) per-affiliate dispatch loop.

afterEach(fn () => M::close());

// ── Helpers ─────────────────────────────────────────────────────────────────

function setupBrandAffiliateTables(): void
{
    setupProfessionalsTable();
    setupSitesTable();
    setupBrandLinkTables();
    setupHandleAliasesTable();
    setupSubdomainAliasesTable();
}

// ── CACHE-6: forgetHydrogenAffiliatesByBrand ────────────────────────────────

it('forgetHydrogenAffiliatesByBrand clears the primary hydrogenAffiliate key but keeps :stale for SWR', function () {
    Cache::flush();
    setupBrandAffiliateTables();

    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $handle = 'test-affiliate';

    DB::table('core.professionals')->insert([
        'id' => $affiliateId,
        'handle' => $handle,
        'primary_email' => 'affiliate@example.com',
        'account_type' => 'partner',
        'professional_type' => 'partner',
    ]);
    DB::table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
    ]);

    $key = CacheKeyGenerator::hydrogenAffiliate($brandId, $handle);
    Cache::put($key, ['page' => 'data'], 600);
    Cache::put($key.':stale', ['page' => 'stale'], 6000);

    $service = new SiteCacheService(new CacheLockService);
    $service->forgetHydrogenAffiliatesByBrand($brandId);

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeTrue();
});

it('forgetHydrogenAffiliatesByBrand also clears alias-based keys', function () {
    Cache::flush();
    setupBrandAffiliateTables();

    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $handle = 'current-handle';
    $alias = 'old-handle';

    DB::table('core.professionals')->insert([
        'id' => $affiliateId,
        'handle' => $handle,
        'primary_email' => 'alias-affiliate@example.com',
        'account_type' => 'partner',
        'professional_type' => 'partner',
    ]);
    DB::table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
    ]);
    DB::table('site.professional_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliateId,
        'handle' => $alias,
        'reclaim_until' => now()->addDays(14)->toDateTimeString(),
        'expires_at' => now()->addDays(90)->toDateTimeString(),
    ]);

    $primaryKey = CacheKeyGenerator::hydrogenAffiliate($brandId, $handle);
    $aliasKey = CacheKeyGenerator::hydrogenAffiliate($brandId, $alias);

    Cache::put($primaryKey, 'data', 600);
    Cache::put($primaryKey.':stale', 'stale', 6000);
    Cache::put($aliasKey, 'data', 600);
    Cache::put($aliasKey.':stale', 'stale', 6000);

    $service = new SiteCacheService(new CacheLockService);
    $service->forgetHydrogenAffiliatesByBrand($brandId);

    expect(Cache::has($primaryKey))->toBeFalse()
        ->and(Cache::has($primaryKey.':stale'))->toBeTrue()
        ->and(Cache::has($aliasKey))->toBeFalse()
        ->and(Cache::has($aliasKey.':stale'))->toBeTrue();
});

it('forgetHydrogenAffiliatesByBrand is a no-op when brand has no links', function () {
    Cache::flush();
    setupBrandAffiliateTables();

    $brandId = (string) Str::uuid();

    // Seed an unrelated key that must survive.
    Cache::put('hydrogen:affiliate:v1:sentinel', 'untouched', 60);

    $service = new SiteCacheService(new CacheLockService);
    $service->forgetHydrogenAffiliatesByBrand($brandId);

    expect(Cache::has('hydrogen:affiliate:v1:sentinel'))->toBeTrue();
})->throwsNoExceptions();

// ── SCALE-3: InvalidateBrandAffiliatesCacheJob ────────────────────────────

it('InvalidateBrandAffiliatesCacheJob clears the primary public payload key but keeps :stale for SWR', function () {
    Cache::flush();
    setupBrandAffiliateTables();

    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $subdomain = 'my-affiliate';

    DB::table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
    ]);
    DB::table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $affiliateId,
        'subdomain' => $subdomain,
    ]);

    $key = CacheKeyGenerator::publicSitePayload($subdomain);
    Cache::put($key, ['published' => true], 600);
    Cache::put($key.':stale', ['published' => true], 6000);

    $job = new InvalidateBrandAffiliatesCacheJob($brandId);
    $job->handle();

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeTrue();
});

it('InvalidateBrandAffiliatesCacheJob runs on the default queue', function () {
    $job = new InvalidateBrandAffiliatesCacheJob((string) Str::uuid());

    expect($job->queue)->toBe('default');
});

it('InvalidateBrandAffiliatesCacheJob has 3 tries and backoff [5, 15, 30]', function () {
    $job = new InvalidateBrandAffiliatesCacheJob((string) Str::uuid());

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 15, 30]);
});

it('InvalidateBrandAffiliatesCacheJob calls report() on failure', function () {
    $job = new InvalidateBrandAffiliatesCacheJob((string) Str::uuid());
    $job->failed(new \RuntimeException('redis down'));
})->throwsNoExceptions();

// ── SCALE-3: invalidateSite dispatches the bulk job (not per-affiliate jobs) ──

it('invalidateSite dispatches InvalidateBrandAffiliatesCacheJob once for a brand site', function () {
    Queue::fake();
    setupBrandAffiliateTables();

    $professionalId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $professionalId,
        'handle' => 'brand-handle',
        'primary_email' => 'brand@example.com',
        'account_type' => 'brand',
        'professional_type' => 'brand',
    ]);

    // Minimal Site mock so we can call invalidateSite() without a DB-backed Site.
    $site = M::mock(Site::class)->makePartial();
    $site->id = $siteId;
    $site->subdomain = 'brand-sub';
    $site->professional_id = $professionalId;
    $site->shouldReceive('wasChanged')->with('subdomain')->andReturn(false);
    $site->shouldReceive('getOriginal')->with('subdomain')->andReturn('brand-sub');

    $service = new SiteCacheService(new CacheLockService);
    $service->invalidateSite($site);

    Queue::assertPushed(InvalidateBrandAffiliatesCacheJob::class, function ($job) use ($professionalId) {
        return $job->brandProfessionalId === $professionalId;
    });
});
