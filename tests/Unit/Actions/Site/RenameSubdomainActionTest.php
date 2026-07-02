<?php

use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\RenameSubdomainAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupHandleChangeLogTable();

    // Stub the cache service — tests here don't need Redis (the site is not
    // saved by the action itself, but observers may fire on the user save).
    $cache = Mockery::mock(SiteCacheService::class);
    $cache->shouldReceive('forgetBrandDesign')->andReturnNull()->byDefault();
    app()->instance(SiteCacheService::class, $cache);
});

/**
 * Seeds a user + site and returns [$user, $site].
 * Uses a unique prefix so repeated calls in one test don't collide.
 */
function seedRenameActionUser(string $subdomain): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Test User',
        'primary_email' => $userId.'@example.test',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $user = User::query()->findOrFail($userId);
    $user->loadMissing('site');

    return [$user, $user->site];
}

it('returns immediately without staging any changes when the new subdomain equals the current one', function () {
    [$user, $site] = seedRenameActionUser('myhandle');

    DB::connection('pgsql')->transaction(function () use ($site, $user) {
        app(RenameSubdomainAction::class)->execute($site, 'myhandle', $user);
    });

    // Model must be unchanged — no staging occurred.
    expect($site->subdomain)->toBe('myhandle');
    expect($site->isDirty())->toBeFalse();
});

it('stages the new subdomain on the model without persisting to the database', function () {
    [$user, $site] = seedRenameActionUser('oldhandle');

    DB::connection('pgsql')->transaction(function () use ($site, $user) {
        app(RenameSubdomainAction::class)->execute($site, 'newhandle', $user);
    });

    // The model was staged with the new subdomain.
    expect($site->subdomain)->toBe('newhandle');

    // But the site DB row was NOT updated by the action itself — caller saves.
    $dbSubdomain = DB::connection('pgsql')
        ->table('site.sites')
        ->where('id', $site->id)
        ->value('subdomain');
    expect($dbSubdomain)->toBe('oldhandle');
});
