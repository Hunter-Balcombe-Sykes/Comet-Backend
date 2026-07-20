<?php

/**
 * TEST-101 — ContentSelectionPolicy enforcement (ContentSelection model).
 *
 * ContentSelectionPolicy is registered: Gate::policy(ContentSelection::class, ContentSelectionPolicy::class).
 *
 * ContentSelection carries no user_id column of its own — ownership resolves
 * through the parent Site's user_id via the loaded `site` relation
 * (setRelation('site', $site)), mirroring SiteMedia's skeleton pattern
 * (see ContentSelectionPolicy class doc, ContentSelectionPolicy.php:41-43).
 *
 * Abilities tested:
 *   - view:   owner → true; non-owner → 404 (denyAsNotFound, enumeration defence).
 *   - manage: owner → true; non-owner → 404; denyIfPendingDeletion (423) runs
 *             BEFORE the ownership check — the actor's own pending-deletion
 *             status gates the write regardless of whose site is attached.
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;

function contentSelectionPolicy_user(string $id, string $status = 'active'): User
{
    $user = new User;
    $user->id = $id;
    $user->status = $status;

    return $user;
}

function contentSelectionPolicy_site(string $ownerUserId): Site
{
    $site = new Site;
    $site->id = 'site-'.$ownerUserId;
    $site->user_id = $ownerUserId;

    return $site;
}

function contentSelectionPolicy_resource(Site $site): ContentSelection
{
    $resource = new ContentSelection;
    // The policy reads $resource->getRelation('site') rather than a user_id
    // column — this is the load-bearing wiring step (ContentSelectionPolicy:41-43).
    $resource->setRelation('site', $site);

    return $resource;
}

// --- view ---

it('allows the site owner to view a content selection entry', function () {
    $site = contentSelectionPolicy_site('owner-1');

    expect(
        Gate::forUser(contentSelectionPolicy_user('owner-1'))
            ->allows('view', contentSelectionPolicy_resource($site))
    )->toBeTrue();
});

it('returns 404 (not 403) when a non-owner tries to view a content selection entry', function () {
    $site = contentSelectionPolicy_site('owner-1');

    $response = Gate::forUser(contentSelectionPolicy_user('intruder-2'))
        ->inspect('view', contentSelectionPolicy_resource($site));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// --- manage ---

it('allows the site owner to manage a content selection entry', function () {
    $site = contentSelectionPolicy_site('owner-1');

    expect(
        Gate::forUser(contentSelectionPolicy_user('owner-1'))
            ->allows('manage', contentSelectionPolicy_resource($site))
    )->toBeTrue();
});

it('returns 404 (not 403) when a non-owner tries to manage a content selection entry', function () {
    $site = contentSelectionPolicy_site('owner-1');

    $response = Gate::forUser(contentSelectionPolicy_user('intruder-2'))
        ->inspect('manage', contentSelectionPolicy_resource($site));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

it('returns 423 when the owner is pending deletion (checked before ownership)', function () {
    $site = contentSelectionPolicy_site('owner-1');
    $actor = contentSelectionPolicy_user('owner-1', 'pending_deletion');

    $response = Gate::forUser($actor)->inspect('manage', contentSelectionPolicy_resource($site));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});

it('still returns 423 (not 404) for a pending-deletion actor against a site they do not own', function () {
    // Proves denyIfPendingDeletion runs BEFORE ownerMatches — the actor's own
    // status gates the write regardless of whose site is attached. This
    // combination isn't reachable in production (the site is always the
    // caller's own resolved site), but it pins the actual check ordering.
    $site = contentSelectionPolicy_site('owner-1');
    $actor = contentSelectionPolicy_user('intruder-2', 'pending_deletion');

    $response = Gate::forUser($actor)->inspect('manage', contentSelectionPolicy_resource($site));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
