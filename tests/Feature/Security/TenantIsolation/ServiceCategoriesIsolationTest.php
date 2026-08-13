<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant isolation for the seven /service-categories/* routes.
 *
 * Slice 3b Task 9 moved these categories from site.service_categories to
 * content.collections (kind='service_category'), so this file was rewritten
 * against the new store. Two things changed shape, both deliberately:
 *
 *  1. Route-model binding is gone — the controller takes a raw id, so these
 *     tests pass an id string rather than an Eloquent model.
 *  2. The refusal is a 404, not an AuthorizationException. That is this
 *     repo's documented rule (404 when the resource does not exist or does
 *     not belong to the caller; 403 only for role/type restrictions, because
 *     403 confirms existence and hands out an enumeration oracle) and it is
 *     the STRONGER posture: tenant B cannot even distinguish "A's category
 *     exists and I may not see it" from "no such category".
 *
 * Because a 404-for-everything controller would satisfy a refusal-only test
 * trivially, every case below carries a POSITIVE CONTROL — tenant A doing the
 * same verb on the same category and succeeding — and each refusal pins its
 * REASON (the row is invisible to B at the ServiceCollections seam), not just
 * that something was thrown.
 *
 * KNOWN LIMITATION, pre-existing and deliberately left in place (flagged for
 * final review): these cases invoke the controller directly rather than over
 * HTTP, so they bypass routing and the middleware pipeline and therefore
 * certify the controller's posture, not the posture the real request path
 * has. That pattern is shared with the passing sibling
 * ServicesIsolationTest.php; converting either to HTTP calls was out of scope
 * for the Task 9 repair.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    // Task 9: the read/write is content.collections now — without these the
    // controller dies on "no such table: content.collections" long before it
    // can prove anything about isolation.
    setupIngestTables();
    setupContentTables();
    // destroy()'s invalidation dispatches CloudflareCachePurgeJob.
    Queue::fake();
});

/** A live, user-created service_category collection owned by $userId. */
function isolationCategory(string $userId, string $label): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'parent_id' => null,
        'label' => $label, 'kind' => 'service_category', 'external_ref' => null,
        'removed_at' => null, 'position' => 0, 'is_user_created' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

/**
 * The refusal REASON. An outcome-only "it threw" assertion survives deleting
 * the guard — and worse, would still pass if someone widened
 * ServiceCollections::find() to be unscoped and bolted an explicit
 * authorization check on top, which is a materially weaker design (the row
 * would then be readable by the wrong tenant everywhere find() is used, with
 * only the controller standing in the way). These two assertions fail the
 * moment scoping stops being what refuses.
 */
function expectInvisibleToStranger(User $owner, User $stranger, string $categoryId): void
{
    $collections = app(ServiceCollections::class);

    expect($collections->find($stranger->id, $categoryId, includeRemoved: true))->toBeNull();
    expect($collections->find($owner->id, $categoryId, includeRemoved: true))->not->toBeNull();
}

/** Runs $act, requiring the controller's own scoped-lookup 404 — not a policy denial, which is a different class and message. */
function expectScopedNotFound(Closure $act): void
{
    try {
        $act();
        expect(false)->toBeTrue('Expected a 404 refusal, but the call returned');
    } catch (NotFoundHttpException $e) {
        expect($e->getStatusCode())->toBe(404);
        // Pins WHICH refusal fired: a ContentCollectionPolicy denial would
        // surface as an AuthorizationException (denyAsNotFound), never as
        // this exception with this message.
        expect($e->getMessage())->toBe('Service category not found.');
    }
}

it('service category show refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $categoryId = isolationCategory($a->id, 'A secret grouping');

    // Behaviour first (so a widened lookup surfaces as "the call returned"
    // rather than as the reason pin firing before the controller is reached),
    // then the reason.
    expectScopedNotFound(fn () => app(UserServiceCategoryController::class)
        ->show(tenantRequestAs($b), $categoryId));

    expectInvisibleToStranger($a, $b, $categoryId);

    // POSITIVE CONTROL: the same verb, the same category, the rightful owner
    // — succeeds. Without this the case passes on a controller that 404s
    // unconditionally, which refuses everyone and isolates nothing.
    $response = app(UserServiceCategoryController::class)->show(tenantRequestAs($a), $categoryId);
    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true)['category']['id'])->toBe($categoryId);
    expect($response->getData(true)['category']['title'])->toBe('A secret grouping');
});

it('service category destroy refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $categoryId = isolationCategory($a->id, 'A private category');

    expectScopedNotFound(fn () => app(UserServiceCategoryController::class)
        ->destroy(tenantRequestAs($b, [], 'DELETE'), $categoryId));

    expectInvisibleToStranger($a, $b, $categoryId);

    // The EFFECT, not only the throw: the row must still exist, still be
    // live, and still belong to A. (Kept from the pre-cutover version, now
    // pointed at the store the row actually lives in.)
    $row = DB::table('content.collections')->where('id', $categoryId)->first();
    expect($row)->not->toBeNull();
    expect($row->removed_at)->toBeNull();
    expect($row->user_id)->toBe($a->id);

    // POSITIVE CONTROL: A can delete its own category, so the refusal above
    // is about WHOSE row it is, not about destroy() being broken.
    $response = app(UserServiceCategoryController::class)->destroy(tenantRequestAs($a, [], 'DELETE'), $categoryId);
    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true)['deleted'])->toBeTrue();
    expect(DB::table('content.collections')->where('id', $categoryId)->value('removed_at'))->not->toBeNull();
});

it('service category index only returns the authenticated professionals categories', function () {
    [$a, $b] = createTwoTenants();
    $aCategoryId = isolationCategory($a->id, 'A Category');
    $bCategoryId = isolationCategory($b->id, 'B Category');

    // B sees exactly its own — "B sees only its own" is only distinguishable
    // from "B sees nothing" because B actually owns a category here.
    $bTitles = collect(app(UserServiceCategoryController::class)->index(tenantRequestAs($b))->getData(true)['categories'])
        ->pluck('title')->all();
    expect($bTitles)->toBe(['B Category']);

    // POSITIVE CONTROL / mirror: A sees exactly its own too, so the case
    // cannot pass on an index() that returns an empty list for everyone.
    $aRows = collect(app(UserServiceCategoryController::class)->index(tenantRequestAs($a))->getData(true)['categories']);
    expect($aRows->pluck('title')->all())->toBe(['A Category']);
    expect($aRows->pluck('id')->all())->toBe([$aCategoryId]);
    expect($aRows->pluck('id')->all())->not->toContain($bCategoryId);
});

// SEC-5: reorder() previously never called authorizeForUser, relying solely
// on the HTTP-layer EnforcePendingDeletionReadOnly middleware. Direct
// controller invocation bypasses that middleware so this actually exercises
// the policy gate — since Task 9 that is ContentCollectionPolicy::update
// (against a content.collections skeleton) rather than ServicePolicy::update;
// both inherit the same BasePolicy::denyIfPendingDeletion, so the 423 is
// unchanged.
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
