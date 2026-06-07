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
});

/**
 * Seeds a second site that holds $subdomain as a subdomain alias with the given
 * expiry, so we can assert whether it blocks another site from claiming it.
 */
function seedForeignAlias(string $subdomain, ?string $expiresAt): string
{
    $otherProId = (string) Str::uuid();
    $otherSiteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $otherProId,
        'handle' => 'other-'.substr($otherProId, 0, 8),
        'handle_lc' => 'other-'.substr($otherProId, 0, 8),
        'display_name' => 'Other Pro',
        'primary_email' => $otherProId.'@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $otherSiteId,
        'user_id' => $otherProId,
        'subdomain' => 'current-'.substr($otherSiteId, 0, 8),
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    SiteSubdomainAlias::create([
        'site_id' => $otherSiteId,
        'subdomain' => $subdomain,
        'reclaim_until' => $expiresAt,
        'expires_at' => $expiresAt,
        'created_at' => now()->subDays(91),
    ]);

    return $otherSiteId;
}

/** Creates our own user+site so we can attempt a rename. */
function seedOwnSite(string $subdomain): \App\Models\Core\User\User
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Mine',
        'primary_email' => $proId.'@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => $subdomain,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return \App\Models\Core\User\User::query()->findOrFail($proId);
}

it('allows claiming a subdomain whose foreign alias has expired (expired aliases do not block re-registration)', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    // Another site holds 'freed' as an EXPIRED alias (past expires_at, not yet pruned).
    seedForeignAlias('freed', now()->subDay()->toDateTimeString());

    $pro = seedOwnSite('mine');

    app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'freed']);

    expect($pro->fresh()->site->subdomain)->toBe('freed');
});

it('still blocks claiming a subdomain whose foreign alias is active', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    // Another site holds 'taken' as an ACTIVE alias (expires in 60 days).
    seedForeignAlias('taken', now()->addDays(60)->toDateTimeString());

    $pro = seedOwnSite('mine');

    expect(fn () => app(UpdateSiteAction::class)->execute($pro, ['subdomain' => 'taken']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

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
