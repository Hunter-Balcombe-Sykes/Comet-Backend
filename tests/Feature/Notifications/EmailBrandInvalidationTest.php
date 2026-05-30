<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
});

it('forgets the email_brand key (and :stale) on invalidateSite', function () {
    $user = User::factory()->create(['handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    $key = CacheKeyGenerator::emailBrand($site->id);
    Cache::put($key, ['marker' => 1], 600);
    Cache::put($key.':stale', ['marker' => 1], 600);

    app(SiteCacheService::class)->invalidateSite($site);

    expect(Cache::get($key))->toBeNull()
        ->and(Cache::get($key.':stale'))->toBeNull();
});
