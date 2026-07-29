<?php

/**
 * #TEST-15 residual — DesignKitRestylePolicy has zero dedicated coverage
 * anywhere today (`git grep -l DesignKitRestylePolicy -- tests/` finds
 * nothing besides the ordinary non-owner-404 route test at
 * tests/Feature/Design/RestyleFlowTest.php:143). It carries the identical
 * site_id cross-check as SectionPolicy — see that class's twin file,
 * tests/Unit/Policies/SectionPolicyTest.php, for the full rationale.
 */

use App\Models\Core\Site\DesignKitRestyle;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function restylePolicy_actor(string $status = 'active'): User
{
    $actor = new User;
    $actor->id = 'rp-actor';
    $actor->status = $status;

    return $actor;
}

function restylePolicy_site(string $id, string $userId): Site
{
    $site = new Site;
    $site->id = $id;
    $site->user_id = $userId;

    return $site;
}

function restylePolicy_restyle(string $siteIdColumn, Site $relation): DesignKitRestyle
{
    $restyle = new DesignKitRestyle;
    $restyle->site_id = $siteIdColumn;
    $restyle->setRelation('site', $relation);

    return $restyle;
}

it('view: owner with a matching site → allowed', function () {
    $actor = restylePolicy_actor();
    $site = restylePolicy_site('S-OWN', $actor->id);
    $restyle = restylePolicy_restyle('S-OWN', $site);

    expect(Gate::forUser($actor)->allows('view', $restyle))->toBeTrue();
});

it('view: spoofed site relation whose id does not match the row → 404', function () {
    // Attacker genuinely owns the injected relation — otherwise the denial
    // could come from the final ownership comparison instead of the
    // cross-check at DesignKitRestylePolicy.php:56-60, and the mutation test
    // would go undetected.
    $attacker = restylePolicy_actor();
    $attackerSite = restylePolicy_site('S-ATTACKER', $attacker->id);
    $restyle = restylePolicy_restyle('S-VICTIM', $attackerSite);

    $response = Gate::forUser($attacker)->inspect('view', $restyle);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('update: owner pending_deletion → 423', function () {
    $actor = restylePolicy_actor('pending_deletion');
    $site = restylePolicy_site('S-OWN-2', $actor->id);
    $restyle = restylePolicy_restyle('S-OWN-2', $site);

    // Ownership is otherwise valid, isolating the 423 to the pending-deletion
    // guard rather than a mis-built fixture.
    $activeActor = restylePolicy_actor('active');
    $activeSite = restylePolicy_site('S-OWN-2', $activeActor->id);
    $activeRestyle = restylePolicy_restyle('S-OWN-2', $activeSite);
    expect(Gate::forUser($activeActor)->allows('view', $activeRestyle))->toBeTrue();

    $response = Gate::forUser($actor)->inspect('update', $restyle);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// create — LIFE-22/#TEST-18: non-owner must be a 404, not a bare 403. The
// deny branch is unreachable via HTTP (RestyleController::store() sets
// site_id and setRelation('site', $site) to the SAME site), so these are
// gate-level, mirroring SectionPolicyTest.php's identical coverage.
// ---------------------------------------------------------------------------

it('create: owner with a matching site → allowed', function () {
    $actor = restylePolicy_actor();
    $site = restylePolicy_site('S-CREATE-OWN', $actor->id);
    $skeleton = restylePolicy_restyle('S-CREATE-OWN', $site);

    expect(Gate::forUser($actor)->allows('create', $skeleton))->toBeTrue();
});

it('create: non-owner site → 404, not 403', function () {
    $actor = restylePolicy_actor();
    $otherSite = restylePolicy_site('S-CREATE-OTHER', 'someone-else');
    $skeleton = restylePolicy_restyle('S-CREATE-OTHER', $otherSite);

    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('create: SPOOFED site relation whose id does not match the row → 404', function () {
    $attacker = restylePolicy_actor();
    $attackerSite = restylePolicy_site('S-CREATE-ATTACKER', $attacker->id);
    $skeleton = restylePolicy_restyle('S-CREATE-VICTIM', $attackerSite);

    $response = Gate::forUser($attacker)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('create: null site relation → 404', function () {
    $skeleton = new DesignKitRestyle;
    $skeleton->site_id = 'S-CREATE-SOME-SITE';
    $skeleton->setRelation('site', null);

    $actor = restylePolicy_actor();
    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('create: owner pending_deletion → 423, not 404', function () {
    $actor = restylePolicy_actor('pending_deletion');
    $site = restylePolicy_site('S-CREATE-PENDING', $actor->id);
    $skeleton = restylePolicy_restyle('S-CREATE-PENDING', $site);

    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
