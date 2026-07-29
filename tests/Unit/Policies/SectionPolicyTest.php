<?php

/**
 * #TEST-15 residual — SectionPolicy::ownerMatches()'s independent site_id
 * cross-check (app/Policies/SectionPolicy.php:69-73) had zero coverage
 * anywhere. Its docblock (:14-17) states the reason it exists: the caller
 * must setRelation('site', $site) with the ACTOR's own site before
 * authorizing, and without this cross-check a non-owner's site could be
 * injected via setRelation to spoof access.
 *
 * Gate-level, not route-level: the guard is genuinely unreachable through any
 * controller — SectionController::findSection() / findPage() both resolve
 * `where('site_id', $site->id)` and then setRelation() that SAME site, so the
 * relation and the raw column always agree on the HTTP path. Only a
 * hand-built fixture can make them disagree. Pattern copied from
 * tests/Feature/Security/PolicyEnforcement/IntegrationConnectionPolicyEnforcementTest.php.
 *
 * Boots the full app (unlike most of tests/Unit) so Gate::forUser() resolves
 * through the real AppServiceProvider registrations — the last test below
 * exists specifically to prove that wiring, not just the policy in isolation.
 */

use App\Models\Core\Site\Page;
use App\Models\Core\Site\Section;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function sectionPolicy_actor(string $status = 'active'): User
{
    $actor = new User;
    $actor->id = 'sp-actor';
    $actor->status = $status;

    return $actor;
}

/** A Site owned by $userId, built in memory. */
function sectionPolicy_site(string $id, string $userId): Site
{
    $site = new Site;
    $site->id = $id;
    $site->user_id = $userId;

    return $site;
}

/**
 * A Section whose raw site_id attribute is $siteIdColumn but whose `site`
 * relation is setRelation()'d to $relation — these disagree exactly when the
 * caller wants to exercise the spoof-guard branch.
 */
function sectionPolicy_section(string $siteIdColumn, Site $relation): Section
{
    $section = new Section;
    $section->site_id = $siteIdColumn;
    $section->setRelation('site', $relation);

    return $section;
}

// ---------------------------------------------------------------------------
// view — positive control first: every negative test below is worthless if
// the always-allow shape isn't reachable in the first place.
// ---------------------------------------------------------------------------

it('view: owner with a matching site → allowed', function () {
    $actor = sectionPolicy_actor();
    $site = sectionPolicy_site('S-OWN', $actor->id);
    $section = sectionPolicy_section('S-OWN', $site);

    expect(Gate::forUser($actor)->allows('view', $section))->toBeTrue();
});

it('view: SPOOFED site relation whose id does not match the row → 404', function () {
    // The point of this file. $attacker genuinely OWNS $attackerSite — that is
    // what makes the mutation detectable: if the cross-check at
    // SectionPolicy.php:69-73 is deleted, ownerMatches() falls through to
    // `(string) $site->user_id === (string) $actor->id`, which evaluates TRUE
    // for this fixture (the attacker owns the injected relation), so only the
    // cross-check itself produces the denial.
    $attacker = sectionPolicy_actor();
    $attackerSite = sectionPolicy_site('S-ATTACKER', $attacker->id);
    // Raw site_id column says S-VICTIM; relation says $attackerSite (S-ATTACKER).
    $section = sectionPolicy_section('S-VICTIM', $attackerSite);

    $response = Gate::forUser($attacker)->inspect('view', $section);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('view: section whose site relation resolves to null → 404', function () {
    // setRelation('site', null), not simply never touching ->site: this test
    // suite escalates PHP's "undefined array key" warning to an ErrorException
    // (confirmed by running it the naive way first), so an untouched
    // $relations array throws inside Eloquent's getRelation() before
    // SectionPolicy ever runs — that would test PHP's error handler, not the
    // `$site === null` guard at SectionPolicy.php:60-64. Setting the relation
    // to null explicitly is what actually reaches and exercises that branch.
    $section = new Section;
    $section->site_id = 'S-SOME-SITE';
    $section->setRelation('site', null);

    $actor = sectionPolicy_actor();
    $response = Gate::forUser($actor)->inspect('view', $section);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update — pending_deletion fires before ownership.
// ---------------------------------------------------------------------------

it('update: owner pending_deletion → 423 even though they own the section', function () {
    $actor = sectionPolicy_actor('pending_deletion');
    $site = sectionPolicy_site('S-OWN-2', $actor->id);
    $section = sectionPolicy_section('S-OWN-2', $site);

    // Ownership is otherwise valid — `view` on the same fixture is allowed —
    // so the 423 below provably comes from the pending-deletion guard and not
    // from a mis-built fixture. (view() has no pending-deletion check.)
    $activeActor = sectionPolicy_actor('active');
    $activeSite = sectionPolicy_site('S-OWN-2', $activeActor->id);
    $activeSection = sectionPolicy_section('S-OWN-2', $activeSite);
    expect(Gate::forUser($activeActor)->allows('view', $activeSection))->toBeTrue();

    $response = Gate::forUser($actor)->inspect('update', $section);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});

// ---------------------------------------------------------------------------
// delete — delegates to update(); this is the only test that distinguishes
// that delegation from an accidental delegation to view() (which has no
// pending-deletion guard, so such a mistake would allow instead of 423).
// ---------------------------------------------------------------------------

it('delete: delegates to update — pending_deletion → 423, non-owner → 404', function () {
    $pendingActor = sectionPolicy_actor('pending_deletion');
    $ownSite = sectionPolicy_site('S-OWN-3', $pendingActor->id);
    $ownSection = sectionPolicy_section('S-OWN-3', $ownSite);

    $pendingResponse = Gate::forUser($pendingActor)->inspect('delete', $ownSection);
    expect($pendingResponse->denied())->toBeTrue();
    expect($pendingResponse->status())->toBe(423);

    $activeActor = sectionPolicy_actor('active');
    $otherSite = sectionPolicy_site('S-OTHER', 'someone-else');
    $otherSection = sectionPolicy_section('S-OTHER', $otherSite);

    $nonOwnerResponse = Gate::forUser($activeActor)->inspect('delete', $otherSection);
    expect($nonOwnerResponse->denied())->toBeTrue();
    expect($nonOwnerResponse->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// Page resolves through the same policy (AppServiceProvider.php:228). This is
// the one assertion in the file that needs the real Gate wiring rather than a
// direct policy call: tests/Feature/Security/PolicyCoverageTest.php proves the
// registration EXISTS, this proves it points at a policy that actually denies.
// ---------------------------------------------------------------------------

it('view: a Page resource resolves through the same policy', function () {
    $attacker = sectionPolicy_actor();
    $attackerSite = sectionPolicy_site('S-ATTACKER-PAGE', $attacker->id);

    $page = new Page;
    $page->site_id = 'S-VICTIM-PAGE';
    $page->setRelation('site', $attackerSite);

    $response = Gate::forUser($attacker)->inspect('view', $page);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// create — LIFE-22/LIFE-23/#TEST-18: non-owner must be a 404, not a bare
// 403. The deny branch is unreachable via HTTP (SectionController::store()
// sets site_id and setRelation('site', $site) to the SAME site), so these
// are gate-level, mirroring the view()/update() coverage above.
// ---------------------------------------------------------------------------

it('create: owner with a matching site → allowed', function () {
    $actor = sectionPolicy_actor();
    $site = sectionPolicy_site('S-CREATE-OWN', $actor->id);
    $skeleton = sectionPolicy_section('S-CREATE-OWN', $site);

    expect(Gate::forUser($actor)->allows('create', $skeleton))->toBeTrue();
});

it('create: non-owner site → 404, not 403', function () {
    $actor = sectionPolicy_actor();
    $otherSite = sectionPolicy_site('S-CREATE-OTHER', 'someone-else');
    $skeleton = sectionPolicy_section('S-CREATE-OTHER', $otherSite);

    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
    expect($response->status())->not->toBe(403);
});

it('create: SPOOFED site relation whose id does not match the row → 404', function () {
    $attacker = sectionPolicy_actor();
    $attackerSite = sectionPolicy_site('S-CREATE-ATTACKER', $attacker->id);
    $skeleton = sectionPolicy_section('S-CREATE-VICTIM', $attackerSite);

    $response = Gate::forUser($attacker)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('create: null site relation → 404', function () {
    $skeleton = new Section;
    $skeleton->site_id = 'S-CREATE-SOME-SITE';
    $skeleton->setRelation('site', null);

    $actor = sectionPolicy_actor();
    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('create: owner pending_deletion → 423, not 404', function () {
    $actor = sectionPolicy_actor('pending_deletion');
    $site = sectionPolicy_site('S-CREATE-PENDING', $actor->id);
    $skeleton = sectionPolicy_section('S-CREATE-PENDING', $site);

    $response = Gate::forUser($actor)->inspect('create', $skeleton);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
