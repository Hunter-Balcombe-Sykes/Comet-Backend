<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\DB;
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

// EDGE-1: the claim's status flip ('unclaimed' -> 'active') never reaches
// SiteObserver's own purge (PUBLIC_PROFILE_USER_FIELDS excludes 'status'), so
// ClaimSiteService must dispatch the edge purge itself.
it('EDGE-1: purges the Cloudflare edge cache on claim', function () {
    [$user, $site, $build] = makeReadyBuild();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'janedoe' && $job->customDomain === null;
    });
});

it('EDGE-1: includes the active custom domain in the claim-time edge purge', function () {
    [$user, $site, $build] = makeReadyBuild();
    // custom_domain/custom_domain_status are NOT fillable (Site::$fillable) —
    // direct property assignment, matching the codebase's own convention.
    $site->custom_domain = 'mysite.example';
    $site->custom_domain_status = 'active';
    $site->save();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'janedoe' && $job->customDomain === 'mysite.example';
    });
});

it('EDGE-1: omits a non-active custom domain from the claim-time edge purge', function () {
    [$user, $site, $build] = makeReadyBuild();
    $site->custom_domain = 'pending.example';
    $site->custom_domain_status = 'pending';
    $site->save();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'janedoe' && $job->customDomain === null;
    });
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
    $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save(); // B11 SEC-4: build_state no longer fillable

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

it('PRIV-101: does not create a sidest_updates subscription without an explicit opt-in', function () {
    makeReadyBuild();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect(
        DB::connection('pgsql')->table('notifications.email_subscriptions')
            ->where('list_key', 'sidest_updates')
            ->where('email_lc', 'jane@example.com')
            ->exists()
    )->toBeFalse();
});

it('PRIV-101: creates a sidest_updates subscription with consent_source=claim when marketing_opt_in is true', function () {
    makeReadyBuild();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe', true);

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('list_key', 'sidest_updates')
        ->where('email_lc', 'jane@example.com')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('subscribed')
        // The old hardcoded 'bootstrap' literal was misleading once ClaimSiteService
        // (the "claim" flow) became the only live caller of this side effect.
        ->and($row->consent_source)->toBe('claim');
});
