<?php

use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub out the cache service so the action doesn't need Redis.
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

it('stamps reclaim_until and expires_at on a new subdomain alias', function () {
    config(['partna.handle.reclaim_days' => 14, 'partna.handle.redirect_days' => 90]);

    Carbon::setTestNow('2026-06-01 12:00:00');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'oldhandle',
        'handle_lc' => 'oldhandle',
        'display_name' => 'Old Handle',
        'primary_email' => 'old@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'oldhandle',
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = \App\Models\Core\User\User::query()->findOrFail($proId);

    app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'newhandle']);

    $alias = SiteSubdomainAlias::where('subdomain', 'oldhandle')->firstOrFail();

    expect($alias->reclaim_until?->toIso8601String())->toBe('2026-06-15T12:00:00+00:00');
    expect($alias->expires_at?->toIso8601String())->toBe('2026-08-30T12:00:00+00:00');
})->todo(note: 'handle-redirect lifecycle not yet implemented — SiteSubdomainAlias lacks reclaim_until/expires_at in $fillable. See docs/superpowers/plans/2026-05-19-handle-redirect-lifecycle.md');

it('deletes the matching subdomain alias when a user renames back to a subdomain they previously held', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'a',
        'handle_lc' => 'a',
        'display_name' => 'Test Pro',
        'primary_email' => 'test@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'a',
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = \App\Models\Core\User\User::query()->findOrFail($proId);

    // a → b (creates alias for 'a')
    app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'b']);

    // Move time past 30-day cooldown
    Carbon::setTestNow(now()->addDays(31));

    // b → a (should delete the 'a' alias row)
    app(UpdateSiteAction::class)->execute($pro->fresh(), ['subdomain' => 'a']);

    expect(SiteSubdomainAlias::where('subdomain', 'a')->where('site_id', $siteId)->exists())->toBeFalse();

    // And the 'b' alias now exists (since we renamed away from b)
    expect(SiteSubdomainAlias::where('subdomain', 'b')->where('site_id', $siteId)->exists())->toBeTrue();
});
