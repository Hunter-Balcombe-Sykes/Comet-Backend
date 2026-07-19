<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable(); // SiteCacheService::invalidateSite reads this (post-commit cache bust)
    Queue::fake(); // avoid SyncSubdomainToKvJob actually running under QUEUE_CONNECTION=sync (mirrors BootstrapEmailRaceTest)
});

it('claims: binds auth + email, activates, stamps claimed_at, runs side effects', function () {
    [$user, $site, $build] = makeReadyBuild();

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $fresh = $user->fresh();
    expect($result['professional']->id)->toBe($user->id)
        ->and($fresh->auth_user_id)->toBe('auth-uid-1')
        ->and($fresh->primary_email)->toBe('jane@example.com')
        ->and($fresh->status)->toBe('active')
        ->and($build->fresh()->claimed_at)->not->toBeNull();
});

it('is idempotent for the rightful claimer (double-tap returns success, not 409)', function () {
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $again = $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');
    expect($again['professional']->auth_user_id)->toBe('auth-uid-1');
});

it('first-come wins: a second claimer gets ALREADY_CLAIMED', function () {
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $svc->claim('auth-uid-2', 'mallory@example.com', 'janedoe');
})->throws(RuntimeException::class, 'ALREADY_CLAIMED');

it('rejects a not-ready build', function () {
    [, , $build] = makeReadyBuild();
    $build->update(['build_state' => PreAccountBuild::STATE_BUILDING]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'BUILD_NOT_READY');

it('rejects a claimer who already has an account (one account, one site)', function () {
    makeReadyBuild();
    User::factory()->create(['auth_user_id' => 'auth-uid-1']);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'ACCOUNT_EXISTS');

it('rejects an email already registered to another auth user', function () {
    makeReadyBuild();
    User::factory()->create(['auth_user_id' => 'other', 'primary_email' => 'jane@example.com']);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'EMAIL_ALREADY_REGISTERED');

it('404s an unknown subdomain', function () {
    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'ghost');
})->throws(RuntimeException::class, 'CLAIM_NOT_FOUND');
