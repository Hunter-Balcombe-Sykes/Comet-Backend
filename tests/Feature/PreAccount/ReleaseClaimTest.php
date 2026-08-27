<?php

/**
 * Staff release-claim: undo a claim without destroying the built site.
 *
 * The pre-existing recovery for a wrongly-claimed site was
 * StaffUserController::forceDestroy (adminPurgeNow) — destructive, and it
 * takes the scraped site with it. release() is the non-destructive lane:
 * it unbinds the auth user and returns the row to 'unclaimed' so the
 * rightful owner can claim it through the normal endpoint.
 *
 * Owner ruling 2026-08-25: the site goes back to OPEN first-come after a
 * release (no email lock), and a release always succeeds — staff are WARNED
 * about attached content rather than blocked by it.
 */

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable();
    Queue::fake();
    Mail::fake();
});

it('releases a claim: unbinds auth, clears the email, returns the row to unclaimed', function () {
    [$user, $site, $build] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');

    app(ClaimSiteService::class)->release($user->fresh());

    $fresh = $user->fresh();
    expect($fresh->auth_user_id)->toBeNull();
    expect($fresh->primary_email)->toBeNull();
    expect($fresh->status)->toBe('unclaimed');
    expect($build->fresh()->claimed_at)->toBeNull();
});

// The whole point of the endpoint: the site must be claimable again, by
// someone else, through the ordinary public path.
it('lets a different person claim the site after a release', function () {
    [$user] = makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('squatter-uid', 'squatter@example.com', 'janedoe');

    $svc->release($user->fresh());
    $result = $svc->claim('owner-uid', 'realowner@example.com', 'janedoe');

    expect($result['professional']->auth_user_id)->toBe('owner-uid');
    expect($result['professional']->primary_email)->toBe('realowner@example.com');
    expect($result['professional']->status)->toBe('active');
});

// claim() gates the welcome EMAIL on is_new_claim, which comes from
// createWelcomeNotification() returning > 0. That insert dedupes on
// (user_id, dedupe_key) — and the user_id SURVIVES a release, because the
// provisional row is reused. So a welcome notification left behind makes the
// next claim return 0 and the rightful owner silently never gets a welcome
// email. The release must delete it.
//
// Asserted on the ROW, not on Mail::assertQueued: the SQLite stand-in for
// `notifications` (tests/Pest.php) has no unique index on dedupe_key, so
// insertOrIgnore never conflicts there and a mail-based assertion passes
// vacuously while the Postgres behaviour is broken.
it('deletes the welcome notification on release, re-arming it for the next claimer', function () {
    [$user] = makeReadyBuild();
    $svc = app(ClaimSiteService::class);
    $svc->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    expect(Notification::query()->where('dedupe_key', 'welcome:'.$user->id)->count())->toBe(1);

    $svc->release($user->fresh());

    expect(Notification::query()->where('dedupe_key', 'welcome:'.$user->id)->count())->toBe(0);
});

// Self-serve builds are provisioned is_published=false
// (PreAccountBuildService::requestBuild -> createSiteForHandle(published: false))
// and claim() flips them true. Leaving that flip in place would leave the site
// MORE publicly exposed after the release than it was before the claim, and
// owned by nobody — PublicSiteResolver gates on is_published.
it('unpublishes a released self-serve site, restoring its pre-claim state', function () {
    [$user, $site] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    expect($site->fresh()->is_published)->toBeTrue();

    app(ClaimSiteService::class)->release($user->fresh());

    expect($site->fresh()->is_published)->toBeFalse();
});

// ...but a site that was ALREADY published pre-claim keeps that state:
// claim() records that it performed no flip (published_by_claim=false), so
// release restores nothing — unpublishing here would be a new state the site
// was never in. (T28: the flag replaced the old isOutreach() heuristic.)
it('leaves a released outreach site published, because that was its pre-claim state', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['built_via' => PreAccountBuild::VIA_STAFF, 'contact_email' => 'squatter@example.com'])->save();
    $site->is_published = true;
    $site->save();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    expect((bool) $build->fresh()->published_by_claim)->toBeFalse();

    app(ClaimSiteService::class)->release($user->fresh());

    expect($site->fresh()->is_published)->toBeTrue();
});

// T28 (issue 22, found live in the 2026-08-27 post-claim round): publish
// intent is a requestBuild() PARAM that only rides the job dispatch — a
// staff/outreach build CAN be provisioned UNPUBLISHED (the whole test fleet
// is). The old `! isOutreach()` guard skipped these on release and left
// is_published=true on an unclaimed row: more exposed than before the claim,
// owned by nobody. The published_by_claim flag restores exactly.
it('unpublishes a released outreach site that the CLAIM published (the fleet shape)', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['built_via' => PreAccountBuild::VIA_STAFF, 'contact_email' => 'squatter@example.com'])->save();
    expect($site->fresh()->is_published)->toBeFalse();

    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    expect($site->fresh()->is_published)->toBeTrue()
        ->and((bool) $build->fresh()->published_by_claim)->toBeTrue();

    app(ClaimSiteService::class)->release($user->fresh());

    expect($site->fresh()->is_published)->toBeFalse()
        ->and((bool) $build->fresh()->published_by_claim)->toBeFalse();
});

// SyncSubdomainToKvJob is the ONLY KV writer. Claim flips the KV entry from an
// expiry-TTL'd unclaimed pointer to a permanent one (status active); skipping
// the re-sync would leave a PERMANENT routing entry for a site that is
// unclaimed again and due to expire.
it('re-syncs KV on release so the routing entry stops being permanent', function () {
    [$user] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    Queue::fake(); // drop the claim's own dispatches so this asserts the RELEASE

    app(ClaimSiteService::class)->release($user->fresh());

    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

// Same EDGE-1 reasoning as claim(): the status flip is invisible to
// SiteObserver (PUBLIC_PROFILE_USER_FIELDS excludes 'status'), so the release
// must purge the edge itself or a squatter's version stays cached at the CDN.
it('purges the Cloudflare edge cache on release', function () {
    [$user] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');
    Queue::fake();

    app(ClaimSiteService::class)->release($user->fresh());

    Queue::assertPushed(CloudflareCachePurgeJob::class, fn ($job) => $job->handle === 'janedoe');
});

it('refuses to release a row that is not claimed', function () {
    [$user] = makeReadyBuild();

    expect(fn () => app(ClaimSiteService::class)->release($user->fresh()))
        ->toThrow(RuntimeException::class, 'NOT_CLAIMED');
});

it('refuses to release a user that has no pre-account build', function () {
    $user = User::factory()->create(['status' => 'active', 'auth_user_id' => 'some-uid']);

    expect(fn () => app(ClaimSiteService::class)->release($user))
        ->toThrow(RuntimeException::class, 'NOT_PRE_ACCOUNT');
});
