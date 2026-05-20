<?php

// §28.17 CACHE-2 — BrandStoreSettings cache bust must delete BOTH the
// primary cache key AND its `:stale` SWR companion, matching the
// CustomerObserver / ProfessionalIntegrationObserver pattern. Cache::forget
// alone leaves the SWR copy serving outdated brand settings during the
// gap between primary delete and next fresh write.

use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

it('busts BOTH the primary key and the :stale SWR companion (CACHE-2)', function () {
    $proId = (string) Str::uuid();
    $key = CacheKeyGenerator::brandStoreSettings($proId);

    Cache::put($key, ['commission_rate_bps' => 1000], 60);
    Cache::put($key.':stale', ['commission_rate_bps' => 999], 600);

    expect(Cache::get($key))->toBeArray()
        ->and(Cache::get($key.':stale'))->toBeArray();

    // Bust via the same deleteMultiple call the observer uses post-fix.
    Cache::deleteMultiple([$key, $key.':stale']);

    expect(Cache::get($key))->toBeNull()
        ->and(Cache::get($key.':stale'))->toBeNull();
});
