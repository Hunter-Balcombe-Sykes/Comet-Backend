<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Models\Core\User\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        title TEXT NULL,
        sort_order INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('service category show refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();

    $categoryId = (string) Str::uuid();
    DB::table('site.service_categories')->insert([
        'id' => $categoryId,
        'user_id' => $a->id,
        'title' => 'A secret grouping',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $category = ServiceCategory::query()->findOrFail($categoryId);
    $req = tenantRequestAs($b);

    expect(fn () => app(UserServiceCategoryController::class)->show($req, $category))
        ->toThrow(AuthorizationException::class);
});

it('service category destroy refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();

    $categoryId = (string) Str::uuid();
    DB::table('site.service_categories')->insert([
        'id' => $categoryId,
        'user_id' => $a->id,
        'title' => 'A private category',
        'sort_order' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $category = ServiceCategory::query()->findOrFail($categoryId);
    $req = tenantRequestAs($b, [], 'DELETE');

    expect(fn () => app(UserServiceCategoryController::class)->destroy($req, $category))
        ->toThrow(AuthorizationException::class);

    // Category must still exist, and still belong to A.
    $row = DB::table('site.service_categories')->where('id', $categoryId)->first();
    expect($row)->not->toBeNull();
    expect($row->deleted_at)->toBeNull();
    expect($row->user_id)->toBe($a->id);
});

it('service category index only returns the authenticated professionals categories', function () {
    [$a, $b] = createTwoTenants();

    DB::table('site.service_categories')->insert([
        ['id' => (string) Str::uuid(), 'user_id' => $a->id, 'title' => 'A Category', 'sort_order' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
        ['id' => (string) Str::uuid(), 'user_id' => $b->id, 'title' => 'B Category', 'sort_order' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()],
    ]);

    $req = tenantRequestAs($b);
    $response = app(UserServiceCategoryController::class)->index($req);
    $payload = $response->getData(true);

    $titles = collect($payload['categories'] ?? [])->pluck('title')->all();
    expect($titles)->toContain('B Category');
    expect($titles)->not->toContain('A Category');
});

// SEC-5: reorder() previously never called authorizeForUser, relying solely
// on the HTTP-layer EnforcePendingDeletionReadOnly middleware. Direct
// controller invocation bypasses that middleware so this actually exercises
// the new ServicePolicy::update gate.
it('blocks a pending-deletion professional from reordering service categories (423)', function () {
    $pro = createTenant('sc-reorder-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    $req = tenantRequestAs($pro, ['ids' => [(string) Str::uuid()]], 'POST');
    $formReq = ReorderServiceCategoryRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserServiceCategoryController::class)->reorder($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
