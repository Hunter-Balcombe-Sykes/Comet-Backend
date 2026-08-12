<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceRequest;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// user-api/EDGE-1: ReorderService's writes go through a query-builder mass
// update(), which never fires Eloquent events — so ServiceObserver/
// ServiceCategoryObserver never run and the public sitepage keeps serving the
// stale order. UserServiceCategoryController::reorder and
// UserServiceController::reorder/reorderLayout must each touch the site
// explicitly so SiteObserver fires (Redis bust + Cloudflare edge purge).
//
// COV-TAIL-4: the cache assertions below exist because SiteObserver::saved()
// wraps invalidateSite() in its own catch(\Throwable) -> Log::warning
// (SiteObserver.php:26-36), and CloudflareCachePurgeJob::dispatch() sits in a
// SEPARATE try block right after. So today a total invalidation failure logs
// a warning and nothing else — the pre-existing Queue::assertPushed below
// still passes, because the purge-job dispatch is unrelated to whether the
// cache key was actually forgotten. Asserting the key is null proves nothing
// unless the key was first proven present — a key that was never written is
// also null. Each case therefore seeds the site's payload key AND a foreign
// subdomain's payload key (the control), asserts both are present before the
// act, then asserts the site key was busted and the control key survived
// untouched — which fails if invalidation is ever "fixed" with a blunt
// Cache::flush().
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable(); // invalidateSite() queries site.site_subdomain_aliases
    setupServicesTable(); // also creates site.service_categories
    // Slice 3a Task 5: reorder()/reorderLayout() now also read/write content.*
    // (the owner-authored half) regardless of whether this fixture's rows
    // are Fresha-sourced or not.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
});

function seedServiceReorderFixture(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'service-purge',
        'handle_lc' => 'service-purge',
        'display_name' => 'Service Purge',
        'first_name' => 'Service-purge',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => 'service-purge',
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->subMinute()->toDateTimeString(),
    ]);

    $catIds = [];
    foreach ([0, 1] as $sort) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.service_categories')->insert([
            'id' => $id,
            'user_id' => $userId,
            'title' => "Category {$sort}",
            'sort_order' => $sort,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $catIds[] = $id;
    }

    // One service per category — reorderLayout's `service_ids` validation rule
    // is `required`, which Laravel treats an empty array as failing, so every
    // active category referenced in a layout payload needs >=1 service.
    //
    // Slice 3a Task 5: 'source' => 'fresha' — owner-authored (source IS NULL)
    // services moved to content.* and carry no category concept; reorder()/
    // reorderLayout()'s category+sort_order machinery this file exercises is
    // Fresha-only post-cutover, so an un-migrated source-less fixture would
    // 422 as an unrecognised id in either half.
    $svcIds = [];
    foreach ([0, 1] as $sort) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.services')->insert([
            'id' => $id,
            'user_id' => $userId,
            'title' => "Service {$sort}",
            'price_cents' => 1000,
            'sort_order' => $sort,
            'source' => 'fresha',
            'external_id' => "purge-svc-{$sort}",
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        DB::connection('pgsql')->table('site.service_category_assignments')->insert([
            'service_id' => $id,
            'service_category_id' => $catIds[$sort],
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        $svcIds[] = $id;
    }

    $pro = User::query()->findOrFail($userId);
    $pro->load('site');

    return [$pro, $siteId, $catIds, $svcIds];
}

function buildFormRequest(string $class, Request $request): mixed
{
    $formRequest = $class::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));
    $formRequest->validateResolved();

    return $formRequest;
}

it('category reorder touches the site and dispatches CloudflareCachePurgeJob (user-api/EDGE-1)', function () {
    [$pro, $siteId, $catIds] = seedServiceReorderFixture();
    Queue::fake();

    $siteKey = CacheKeyGenerator::publicSitePayload('service-purge');
    $controlKey = CacheKeyGenerator::publicSitePayload('other-site');
    Cache::put($siteKey, ['sentinel' => true], 600);
    Cache::put($controlKey, ['sentinel' => true], 600);
    expect(Cache::get($siteKey))->not->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();

    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    $request = Request::create('/api/professional/service-categories/reorder', 'POST', ['ids' => array_reverse($catIds)]);
    $request->attributes->set('professional', $pro);
    app()->instance('request', $request);

    $formRequest = buildFormRequest(ReorderServiceCategoryRequest::class, $request);
    $response = app(UserServiceCategoryController::class)->reorder($formRequest);

    expect($response->getStatusCode())->toBe(200);

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    expect($after)->not->toBe($before);

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn (CloudflareCachePurgeJob $job) => $job->handle === 'service-purge');

    expect(Cache::get($siteKey))->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();
});

it('service reorder touches the site and dispatches CloudflareCachePurgeJob (user-api/EDGE-1)', function () {
    [$pro, $siteId, , $svcIds] = seedServiceReorderFixture();
    Queue::fake();

    $siteKey = CacheKeyGenerator::publicSitePayload('service-purge');
    $controlKey = CacheKeyGenerator::publicSitePayload('other-site');
    Cache::put($siteKey, ['sentinel' => true], 600);
    Cache::put($controlKey, ['sentinel' => true], 600);
    expect(Cache::get($siteKey))->not->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();

    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    $request = Request::create('/api/professional/services/reorder', 'POST', ['ids' => array_reverse($svcIds)]);
    $request->attributes->set('professional', $pro);
    app()->instance('request', $request);

    $formRequest = buildFormRequest(ReorderServiceRequest::class, $request);
    $response = app(UserServiceController::class)->reorder($formRequest);

    expect($response->getStatusCode())->toBe(200);

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    expect($after)->not->toBe($before);

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn (CloudflareCachePurgeJob $job) => $job->handle === 'service-purge');

    expect(Cache::get($siteKey))->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();
});

it('reorderLayout touches the site and dispatches CloudflareCachePurgeJob (user-api/EDGE-1)', function () {
    [$pro, $siteId, $catIds, $svcIds] = seedServiceReorderFixture();
    Queue::fake();

    $siteKey = CacheKeyGenerator::publicSitePayload('service-purge');
    $controlKey = CacheKeyGenerator::publicSitePayload('other-site');
    Cache::put($siteKey, ['sentinel' => true], 600);
    Cache::put($controlKey, ['sentinel' => true], 600);
    expect(Cache::get($siteKey))->not->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();

    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    // Full layout: swap which category comes first (a real reorder) while every
    // active category + every active service is accounted for exactly once —
    // required by reorderLayout's own validation, and `service_ids` uses the
    // `required` rule, which Laravel treats an empty array as failing, so
    // every category needs >=1 service (fixture gives each one its own).
    $payload = [
        'categories' => [
            ['id' => $catIds[1], 'service_ids' => [$svcIds[1]]],
            ['id' => $catIds[0], 'service_ids' => [$svcIds[0]]],
        ],
    ];

    $request = Request::create('/api/professional/services/reorder-layout', 'POST', $payload);
    $request->attributes->set('professional', $pro);
    app()->instance('request', $request);

    $formRequest = buildFormRequest(ReorderServiceLayoutRequest::class, $request);
    $response = app(UserServiceController::class)->reorderLayout($formRequest);

    expect($response->getStatusCode())->toBe(200);

    $after = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    expect($after)->not->toBe($before);

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn (CloudflareCachePurgeJob $job) => $job->handle === 'service-purge');

    expect(Cache::get($siteKey))->toBeNull();
    expect(Cache::get($controlKey))->not->toBeNull();
});
