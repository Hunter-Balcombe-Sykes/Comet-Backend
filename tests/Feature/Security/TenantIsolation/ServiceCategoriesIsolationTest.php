<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Requests\Api\User\Services\ReorderServiceCategoryRequest;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

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
 * LIMITATION CLOSED (2026-08-14). The three isolation cases used to invoke the
 * controller directly, bypassing routing and the middleware pipeline, and so
 * certified the controller's posture rather than the posture the real request
 * path has — a route-level middleware regression could not have shown up. They
 * now go over HTTP through the real routes.
 *
 * The pending-deletion case below is DELIBERATELY still a direct call, and
 * converting it would be a regression rather than a fix: over HTTP,
 * EnforcePendingDeletionReadOnly returns its own 423 before the controller is
 * ever reached, so an HTTP-only version would stay green with the policy gate
 * deleted entirely. It is paired with an HTTP case that pins the middleware's
 * own refusal, so both layers are covered and neither can mask the other.
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

/**
 * The 404 must be the controller's own scoped-lookup miss, not a policy denial.
 *
 * The controller's `abort(404, 'Service category not found.')` message does NOT
 * survive to the wire — bootstrap/app.php:213 rewrites every
 * NotFoundHttpException to the generic 'Endpoint not found', deliberately, so a
 * 404 leaks nothing about what does or does not exist. That genericising is
 * invisible to a direct controller call, which is one more reason these cases
 * belong on the HTTP path: the message the old version asserted was never a
 * message any client could see.
 *
 * What survives is still enough to tell the two refusals apart, because they
 * take different branches: a ContentCollectionPolicy denial arrives as
 * HttpException(404) rather than NotFoundHttpException (bootstrap/app.php:222)
 * and reads 'Resource not found'. So pinning 'Endpoint not found' pins that
 * the SCOPED LOOKUP refused, not the policy.
 *
 * It cannot distinguish a scoped-lookup miss from an unmatched route — nothing
 * on the wire can. Each case's positive control does that instead: the same URL
 * returns 200 for the rightful owner, so the route demonstrably exists.
 */
function expectScopedNotFound(TestResponse $response): void
{
    $response->assertStatus(404);
    expect($response->json('message'))->toBe('Endpoint not found');
}

it('service category show refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $categoryId = isolationCategory($a->id, 'A secret grouping');

    // Behaviour first (so a widened lookup surfaces as a 200 here rather than
    // as the reason pin firing before the route is reached), then the reason.
    expectScopedNotFound(actingAsUser($b)->getJson("/api/service-categories/{$categoryId}"));

    expectInvisibleToStranger($a, $b, $categoryId);

    // POSITIVE CONTROL: the same verb, the same category, the rightful owner
    // — succeeds. Without this the case passes on a route that 404s
    // unconditionally, which refuses everyone and isolates nothing.
    actingAsUser($a)->getJson("/api/service-categories/{$categoryId}")
        ->assertOk()
        ->assertJsonPath('category.id', $categoryId)
        ->assertJsonPath('category.title', 'A secret grouping');
});

it('service category destroy refuses a category belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $categoryId = isolationCategory($a->id, 'A private category');

    expectScopedNotFound(actingAsUser($b)->deleteJson("/api/service-categories/{$categoryId}"));

    expectInvisibleToStranger($a, $b, $categoryId);

    // The EFFECT, not only the refusal: the row must still exist, still be
    // live, and still belong to A.
    $row = DB::table('content.collections')->where('id', $categoryId)->first();
    expect($row)->not->toBeNull();
    expect($row->removed_at)->toBeNull();
    expect($row->user_id)->toBe($a->id);

    // POSITIVE CONTROL: A can delete its own category, so the refusal above
    // is about WHOSE row it is, not about destroy() being broken.
    actingAsUser($a)->deleteJson("/api/service-categories/{$categoryId}")
        ->assertOk()
        ->assertJsonPath('deleted', true);
    expect(DB::table('content.collections')->where('id', $categoryId)->value('removed_at'))->not->toBeNull();
});

it('service category index only returns the authenticated professionals categories', function () {
    [$a, $b] = createTwoTenants();
    $aCategoryId = isolationCategory($a->id, 'A Category');
    $bCategoryId = isolationCategory($b->id, 'B Category');

    // B sees exactly its own — "B sees only its own" is only distinguishable
    // from "B sees nothing" because B actually owns a category here.
    $bTitles = collect(actingAsUser($b)->getJson('/api/service-categories')->assertOk()->json('categories'))
        ->pluck('title')->all();
    expect($bTitles)->toBe(['B Category']);

    // POSITIVE CONTROL / mirror: A sees exactly its own too, so the case
    // cannot pass on an index that returns an empty list for everyone.
    $aRows = collect(actingAsUser($a)->getJson('/api/service-categories')->assertOk()->json('categories'));
    expect($aRows->pluck('title')->all())->toBe(['A Category']);
    expect($aRows->pluck('id')->all())->toBe([$aCategoryId]);
    expect($aRows->pluck('id')->all())->not->toContain($bCategoryId);
});

it('blocks a pending-deletion professional from reordering service categories over HTTP (423)', function () {
    // The request path. EnforcePendingDeletionReadOnly answers first, so this
    // is the MIDDLEWARE's refusal — pinned by its own body, which is how it is
    // told apart from the policy's 423 in the case below. Both layers refuse;
    // this asserts the one a real client actually meets.
    $pro = createTenant('sc-reorder-pending-http');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    actingAsUser($pro)
        ->postJson('/api/service-categories/reorder', ['ids' => [(string) Str::uuid()]])
        ->assertStatus(423)
        ->assertJsonPath('error', 'account_pending_deletion');
});

// SEC-5: reorder() previously never called authorizeForUser, relying solely
// on the HTTP-layer EnforcePendingDeletionReadOnly middleware. This case is
// DELIBERATELY a direct controller call and must stay one: over HTTP that
// middleware returns 423 before the controller runs, so an HTTP version would
// stay green with the policy gate deleted — it would assert the middleware
// twice and the gate never. Since Task 9 the gate is
// ContentCollectionPolicy::update (against a content.collections skeleton)
// rather than ServicePolicy::update; both inherit the same
// BasePolicy::denyIfPendingDeletion, so the 423 is unchanged.
it('blocks a pending-deletion professional from reordering service categories (423, policy gate itself)', function () {
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
