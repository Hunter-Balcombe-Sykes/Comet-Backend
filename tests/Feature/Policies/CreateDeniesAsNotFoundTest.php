<?php

// #TEST-18. SectionPolicy::create() and DesignKitRestylePolicy::create() must
// deny an owner mismatch as 404, not 403.
//
// CLAUDE.md's rule: "404 when resource doesn't exist/belong to user. 403 only
// for role/type restrictions" — because a 403 confirms the thing exists, which
// is an enumeration oracle. A policy method returning bare `false` produces
// 403; only an explicit denyAsNotFound() Response produces 404. Both methods
// return denyAsNotFound() today. Nothing pinned that, so a future edit
// returning plain `false` would restore the leak while still "denying" and
// still passing any test that only asserted the action was refused.
//
// Asserted at the POLICY, not through a route, deliberately. Both controllers
// build their skeleton from currentSite($user) — SectionController::store()
// sets $section->site_id = $site->id, RestyleController::store() the same — so
// the mismatch branch is UNREACHABLE from the endpoint. It is defence in depth
// against a future caller that builds a skeleton some other way, which is
// exactly the kind of guard that rots silently when untested. Gate::inspect()
// is used rather than allows(): allows() flattens a Response to a boolean and
// would discard the very thing under test, the status code.

use App\Models\Core\Site\DesignKitRestyle;
use App\Models\Core\Site\Section;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionsTables();
});

// site_id is assigned directly, never through mass assignment: it is a tenancy
// FK and therefore deliberately NOT fillable, so `new Section(['site_id' => …])`
// silently leaves it null and the policy reads a null site_id rather than a
// mismatched one — a test that passes for the wrong reason.
dataset('create-policy resources', [
    'SectionPolicy::create' => [function (string $siteId) {
        $section = new Section;
        $section->site_id = $siteId;

        return $section;
    }],
    'DesignKitRestylePolicy::create' => [function (string $siteId) {
        $restyle = new DesignKitRestyle;
        $restyle->site_id = $siteId;

        return $restyle;
    }],
]);

it('denies a foreign-site create as 404, never 403', function (Closure $makeResource) {
    $owner = createTenant('deny404-owner');
    $stranger = createTenant('deny404-stranger');

    // A skeleton for the OWNER's site, inspected as the stranger — the shape a
    // pre-create authorize() check takes.
    $resource = $makeResource((string) $owner->site->id);
    $resource->setRelation('site', $owner->site);

    $verdict = Gate::forUser($stranger)->inspect('create', $resource);

    expect($verdict->allowed())->toBeFalse()
        // The assertion the finding is about. A bare `false` return also fails
        // allowed(), so denied() alone would pass against the bug.
        ->and($verdict->status())->toBe(404)
        ->and($verdict->message())->toBe('Not found.');
})->with('create-policy resources');

it('still allows the owner to create on their own site', function (Closure $makeResource) {
    // The control: without it, a policy that denied EVERYTHING would satisfy
    // the 404 assertion above.
    $owner = createTenant('deny404-allowed');

    $resource = $makeResource((string) $owner->site->id);
    $resource->setRelation('site', $owner->site);

    expect(Gate::forUser($owner)->inspect('create', $resource)->allowed())->toBeTrue();
})->with('create-policy resources');

it('denies as 404 when the skeleton carries no site relation at all', function (Closure $makeResource) {
    // ownerMatches() returns false when getRelation('site') is null. That path
    // must also 404 — it is the one a careless future caller hits by forgetting
    // setRelation(), and a 403 there would leak just as much.
    $owner = createTenant('deny404-norel');

    $verdict = Gate::forUser($owner)->inspect('create', $makeResource((string) $owner->site->id));

    expect($verdict->allowed())->toBeFalse()
        ->and($verdict->status())->toBe(404);
})->with('create-policy resources');
