<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Mail\Account\WelcomeMail;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $fresh = $user->fresh();
    expect($result['professional']->id)->toBe($user->id)
        ->and($fresh->auth_user_id)->toBe('auth-uid-1')
        ->and($fresh->primary_email)->toBe('jane@example.com')
        ->and($fresh->status)->toBe('active')
        ->and($build->fresh()->claimed_at)->not->toBeNull();

    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'jane@example.com' && $m->handle === 'janedoe');
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
    Mail::fake();
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $again = $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');
    expect($again['professional']->auth_user_id)->toBe('auth-uid-1');

    // A retry through the idempotency-first branch never sets is_new_claim,
    // so the welcome email must fire exactly once across both calls.
    Mail::assertQueuedCount(1);
});

it('first-come wins: a second claimer gets ALREADY_CLAIMED', function () {
    makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $svc->claim('auth-uid-2', 'mallory@example.com', 'janedoe');
})->throws(RuntimeException::class, 'ALREADY_CLAIMED');

it('claims successfully while the build is still pending — the dashboard, not the claim gate, waits for ready', function () {
    [$user, , $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_PENDING])->save(); // B11 SEC-4: build_state no longer fillable

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($result['professional']->id)->toBe($user->id)
        ->and($user->fresh()->auth_user_id)->toBe('auth-uid-1');
});

it('claims successfully while the build is still building', function () {
    [$user, , $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_BUILDING])->save(); // B11 SEC-4: build_state no longer fillable

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($result['professional']->id)->toBe($user->id)
        ->and($user->fresh()->auth_user_id)->toBe('auth-uid-1');
});

it('rejects a failed build', function () {
    [, , $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED])->save(); // B11 SEC-4: build_state no longer fillable

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'BUILD_FAILED');

it('rejects a claim with no build row at all', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => false]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');
})->throws(RuntimeException::class, 'BUILD_FAILED');

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

it('auto-publishes the site on claim', function () {
    [$user, $site, $build] = makeReadyBuild();
    expect($site->fresh()->is_published)->toBeFalse();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($site->fresh()->is_published)->toBeTrue();
});

// Minor: saveQuietly() on auto-publish skips SiteObserver::saved(), which would
// have dispatched this same pre-warm job — the claim post-commit block must
// replicate it, or a claim-published site is left cold for its first visitor.
it('pre-warms the public site cache on claim-publish', function () {
    makeReadyBuild();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Queue::assertPushed(WarmPublicSiteCacheJob::class, fn (WarmPublicSiteCacheJob $job) => $job->subdomain === 'janedoe');
});

// Review finding: the auto-publish block's plain $site->save() fires
// SiteObserver::saved(), which unconditionally dispatches its own
// CloudflareCachePurgeJob for this handle — on top of the EDGE-1 block's
// explicit dispatch below the transaction. That contradicts EDGE-1's own
// comment ("SiteObserver's own purge never fires for a claim").
//
// Queue::assertPushed(..., 1) alone can't distinguish the buggy plain-save()
// from the fixed saveQuietly(): CloudflareCachePurgeJob implements
// ShouldBeUnique with a 240s coalesce window keyed on handle+customDomain
// (confirmed empirically — a raw double ::dispatch() for the same handle
// collapses to 1 queued push even with no fix applied), so the count assertion
// reads GREEN either way. The event-listener assertion below is what actually
// flips RED->GREEN: it proves SiteObserver::saved() itself does not run for
// the auto-publish write, which is the real fix (saveQuietly bypasses all
// Eloquent model events, not just this one job's accidental dedup).
it('does not fire SiteObserver for the auto-publish write, and purges the edge exactly once', function () {
    makeReadyBuild();

    $siteObserverFired = false;
    Event::listen('eloquent.saved: '.Site::class, function () use (&$siteObserverFired) {
        $siteObserverFired = true;
    });

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($siteObserverFired)->toBeFalse();
    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
});

it('leaves an already-published site untouched (Flow 2 no-op)', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    $site = Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => true]);
    $build = PreAccountBuild::factory()->make(['build_state' => PreAccountBuild::STATE_READY]);
    $build->user()->associate($user);
    $build->save();

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($result['professional']->status)->toBe('active')
        ->and($site->fresh()->is_published)->toBeTrue()
        ->and($site->fresh()->unpublished_at)->toBeNull();
});

it('falls back display_name to the handle when the provisional user has none, and persists it', function () {
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
        'display_name' => '',
    ]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe', 'is_published' => false]);
    $build = PreAccountBuild::factory()->make(['build_state' => PreAccountBuild::STATE_READY]);
    $build->user()->associate($user);
    $build->save();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $fresh = $user->fresh();
    expect($fresh->display_name)->toBe($user->handle)
        ->and(Site::query()->where('user_id', $user->id)->first()->is_published)->toBeTrue();
});

// ── Invite-gate (owner decision, 2026-08-24) ────────────────────────────────
// An OUTREACH build carries a real business's name, photos and hours, scraped
// before they have heard of Partna. The email-gate's "absent contact_email =
// first-come" arm was written for SELF-SERVE builds, where the claimer is the
// person who just built it. On an outreach row that arm hands a stranger a real
// business's site. These tests pin the split.

it('INVITE-GATE: refuses an outreach build that has nobody to invite', function () {
    [$user, $site, $build] = makeReadyBuild();
    $staff = PartnaStaff::factory()->create();
    $build->builtByStaff()->associate($staff);
    $build->forceFill(['contact_email' => null])->save();

    expect(fn () => app(ClaimSiteService::class)
        ->claim('auth-uid-1', 'anyone@example.com', 'janedoe'))
        ->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');

    // And nothing was half-written on the way out.
    expect($user->fresh()->auth_user_id)->toBeNull()
        ->and($user->fresh()->status)->toBe('unclaimed')
        ->and($build->fresh()->claimed_at)->toBeNull();
});

it('INVITE-GATE: treats an early-access build as outreach too', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['built_via' => PreAccountBuild::VIA_EARLY_ACCESS, 'contact_email' => null])->save();

    expect(fn () => app(ClaimSiteService::class)
        ->claim('auth-uid-1', 'anyone@example.com', 'janedoe'))
        ->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('INVITE-GATE: a blank-string contact_email is treated as absent, not as a gate', function () {
    // Guards the shape where a CSV import writes '' rather than NULL. Without
    // trimming, `'' !== null` would satisfy the invite gate AND then fail the
    // email gate for everyone — a site nobody could ever claim.
    [$user, $site, $build] = makeReadyBuild();
    $staff = PartnaStaff::factory()->create();
    $build->builtByStaff()->associate($staff);
    $build->forceFill(['contact_email' => '   '])->save();

    expect(fn () => app(ClaimSiteService::class)
        ->claim('auth-uid-1', 'anyone@example.com', 'janedoe'))
        ->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('INVITE-GATE: an INVITED outreach build still claims normally for the invited address', function () {
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();
    $staff = PartnaStaff::factory()->create();
    $build->builtByStaff()->associate($staff);
    $build->forceFill(['contact_email' => 'Owner@Example.com'])->save();

    // Case-insensitive, as before.
    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'owner@example.com', 'janedoe');

    expect($result['professional']->id)->toBe($user->id)
        ->and($user->fresh()->status)->toBe('active');
});

it('INVITE-GATE: a SELF-SERVE build with no contact_email is untouched by the gate', function () {
    // The person claiming is the person who just built it, in the same session.
    // Blocking this would break every self-serve signup.
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['built_via' => PreAccountBuild::VIA_SIGNUP, 'contact_email' => null])->save();
    expect($build->fresh()->built_by_staff_id)->toBeNull();

    $result = app(ClaimSiteService::class)->claim('auth-uid-1', 'whoever@example.com', 'janedoe');

    expect($result['professional']->id)->toBe($user->id)
        ->and($user->fresh()->status)->toBe('active');
});

it('rejects a claim whose verified email does not match an email-gated build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'owner@example.com'])->save();

    expect(fn () => app(ClaimSiteService::class)
        ->claim('auth-uid-1', 'someone-else@example.com', 'janedoe'))
        ->toThrow(RuntimeException::class, 'CLAIM_EMAIL_MISMATCH');

    expect($user->fresh()->status)->toBe('unclaimed');
});

it('allows a claim whose verified email matches an email-gated build (case-insensitive)', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'Owner@Example.com'])->save();

    $result = app(ClaimSiteService::class)
        ->claim('auth-uid-1', 'owner@example.com', 'janedoe');

    expect($result['professional']->status)->toBe('active')
        ->and($site->fresh()->is_published)->toBeTrue();
});

// REV1: a pre-account build's google-business connection is stripped of
// third-party review PII (stripThirdPartyPii, correct for an unclaimed
// listing). GoogleBusinessFetch already self-heals this on the NEXT scheduled
// refresh once status flips to 'active' — but that refresh is gated on a
// same-mapping-function detailsFetchedAt stamped at BUILD time, so a claim
// within the 40h freshness window (the common case — broken-oven claimed
// same-session as build) makes the next scheduled refresh a no-op cache hit,
// not a real re-fetch. Claim must force it: clear detailsFetchedAt (so the
// freshness check fails open) and dispatch the SAME RefreshConnectionJob the
// hourly cron and the dashboard's manual refresh button already use — reusing
// GoogleBusinessFetch's existing claimed-status-gated strip logic rather than
// duplicating fetch/merge/lock/WS-B2.2-gating code in a new job.

it('REV1: clears detailsFetchedAt and dispatches a manual RefreshConnectionJob for a claimed google-business connection', function () {
    [$user, $site, $build] = makeReadyBuild();
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'is_active' => true,
        'payload' => [
            'placeId' => 'ChIJtestPlaceId',
            'name' => 'Jane Doe Salon',
            'rating' => 4.7,
            'reviewCount' => 12,
            'detailsFetchedAt' => now()->subHours(2)->toIso8601String(), // well within the 40h freshness window
        ],
    ]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    $fresh = $connection->fresh();
    expect($fresh->payload)->not->toHaveKey('detailsFetchedAt')
        // untouched fields survive the same read-modify-write
        ->and($fresh->payload['placeId'])->toBe('ChIJtestPlaceId')
        ->and($fresh->payload['name'])->toBe('Jane Doe Salon');
    expect($fresh->last_refresh_status)->toBe('pending');

    Queue::assertPushed(RefreshConnectionJob::class, fn ($job) => $job->connectionId === $connection->id
        && $job->platform === 'google-business'
        && $job->manual === true);
});

it('REV1: does nothing when the claimed user has no google-business connection', function () {
    makeReadyBuild();

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('REV1: does not touch an INACTIVE (disconnected) google-business connection', function () {
    [$user] = makeReadyBuild();
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'is_active' => false,
        'payload' => ['placeId' => 'p1', 'detailsFetchedAt' => now()->toIso8601String()],
    ]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($connection->fresh()->payload)->toHaveKey('detailsFetchedAt');
    Queue::assertNotPushed(RefreshConnectionJob::class);
});

it('REV1: only refreshes the claimed users own connection, never another users', function () {
    [$user] = makeReadyBuild();
    $otherUser = User::factory()->create(['status' => 'active']);
    $otherConnection = IntegrationConnection::create([
        'user_id' => $otherUser->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'is_active' => true,
        'payload' => ['placeId' => 'other-place', 'detailsFetchedAt' => now()->toIso8601String()],
    ]);

    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', 'janedoe');

    expect($otherConnection->fresh()->payload)->toHaveKey('detailsFetchedAt');
    Queue::assertNotPushed(RefreshConnectionJob::class, fn ($job) => $job->connectionId === $otherConnection->id);
});

// The post-commit block runs against an ALREADY COMMITTED claim, so a fault
// there must not be reported to the claimer as a failed claim. Found while
// building tests/Postgres/ClaimConcurrencyTest.php: an under-provisioned
// fixture made a forked claimer report 42P01 for a claim that had in fact
// succeeded — in production a Redis blip inside invalidateUser() does the same.
it('returns a successful claim even when a post-commit side effect throws, and still runs the steps behind it', function () {
    Mail::fake();
    [$user, $site, $build] = makeReadyBuild();

    $broken = Mockery::mock(UserCacheService::class);
    $broken->shouldReceive('invalidateUser')->andThrow(new RuntimeException('redis is down'));
    app()->instance(UserCacheService::class, $broken);

    $result = app(ClaimSiteService::class)->claim('auth-uid-boom', 'boom@example.com', 'janedoe');

    // The caller is told it worked...
    expect($result['professional']->id)->toBe($user->id);

    // ...and the database agrees: this claim is real and must not be retried
    // into an ALREADY_CLAIMED dead end by a client that saw a 500.
    $fresh = $user->fresh();
    expect($fresh->auth_user_id)->toBe('auth-uid-boom')
        ->and($fresh->status)->toBe('active')
        ->and($build->fresh()->claimed_at)->not->toBeNull();

    // Per-step isolation: the KV sync sits AFTER the failing cache bust, and
    // it is what makes <handle>.partna.au resolve at all. A single try/catch
    // around the whole block would have skipped it.
    Queue::assertPushed(SyncSubdomainToKvJob::class);
});
