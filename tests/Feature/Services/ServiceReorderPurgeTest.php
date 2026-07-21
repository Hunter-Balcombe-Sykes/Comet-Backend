<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceRequest;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// user-api/EDGE-1: ReorderService's writes go through a query-builder mass
// update(), which never fires Eloquent events — so ServiceObserver/
// ServiceCategoryObserver never run and the public sitepage keeps serving the
// stale order. UserServiceCategoryController::reorder and
// UserServiceController::reorder/reorderLayout must each touch the site
// explicitly so SiteObserver fires (Redis bust + Cloudflare edge purge).
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable(); // invalidateSite() queries site.site_subdomain_aliases
    setupServicesTable(); // also creates site.service_categories
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
    $svcIds = [];
    foreach ([0, 1] as $sort) {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.services')->insert([
            'id' => $id,
            'user_id' => $userId,
            'title' => "Service {$sort}",
            'price_cents' => 1000,
            'sort_order' => $sort,
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
});

it('service reorder touches the site and dispatches CloudflareCachePurgeJob (user-api/EDGE-1)', function () {
    [$pro, $siteId, , $svcIds] = seedServiceReorderFixture();
    Queue::fake();

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
});

it('reorderLayout touches the site and dispatches CloudflareCachePurgeJob (user-api/EDGE-1)', function () {
    [$pro, $siteId, $catIds, $svcIds] = seedServiceReorderFixture();
    Queue::fake();

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
});
