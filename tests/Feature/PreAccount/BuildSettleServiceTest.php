<?php

use App\Mail\Account\WelcomeMail;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\BuildProgressReader;
use App\Services\PreAccount\BuildSettleService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    Mail::fake();
});

/** A settled build owned by a claimed account — the welcome's happy path. */
function settledClaimedBuild(string $subdomain = 'janedoe'): PreAccountBuild
{
    [$user, $site, $build] = makeSettledBuild($subdomain);
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill(['claimed_at' => now()])->save();

    return $build->fresh();
}

it('sends the welcome and stamps both marks when settled and claimed', function () {
    $build = settledClaimedBuild();

    $outcome = app(BuildSettleService::class)->evaluate($build);

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_SETTLED)
        ->and($build->fresh()->settled_at)->not->toBeNull()
        ->and($build->fresh()->welcomed_at)->not->toBeNull();
    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'jane@example.com');
});

it('sends exactly one welcome across repeat evaluations', function () {
    $build = settledClaimedBuild();
    $svc = app(BuildSettleService::class);

    $svc->evaluate($build);
    $svc->evaluate($build->fresh());

    Mail::assertQueued(WelcomeMail::class, 1);
});

it('stamps stalled and sends nothing at the ceiling', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'claimed_at' => now(),
        'created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1),
    ])->save();

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_CEILING)
        ->and($build->fresh()->setup_stalled_at)->not->toBeNull()
        ->and($build->fresh()->settled_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('stamps stalled and sends nothing for a failed build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'claimed_at' => now()])->save();

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_FAILED)
        ->and($build->fresh()->setup_stalled_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

it('stamps nothing and sends nothing while pending', function () {
    [$user, $site, $build] = makeReadyBuild(); // content_filled_at null

    $outcome = app(BuildSettleService::class)->evaluate($build->fresh());

    expect($outcome)->toBe(BuildProgressReader::OUTCOME_PENDING)
        ->and($build->fresh()->settled_at)->toBeNull()
        ->and($build->fresh()->setup_stalled_at)->toBeNull();
    Mail::assertNothingQueued();
});

// Settled but unclaimed: no address exists yet. The stamp lands so the sweep
// stops re-evaluating; claim sends later (Task 6).
it('stamps settled but withholds the welcome while unclaimed', function () {
    [$user, $site, $build] = makeSettledBuild();

    app(BuildSettleService::class)->evaluate($build->fresh());

    expect($build->fresh()->settled_at)->not->toBeNull()
        ->and($build->fresh()->welcomed_at)->toBeNull();
    Mail::assertNotQueued(WelcomeMail::class);
});

it('sends the outreach invite for a settled, published, unclaimed build', function () {
    [$user, $site, $build] = makeSettledBuild();
    $site->forceFill(['is_published' => true])->save();
    $build->forceFill([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => 'lead@example.com',
        'auto_invite' => true,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
    expect($build->fresh()->invited_at)->not->toBeNull();
});

// The defect the spec's self-review caught: ClaimNotifier guards on
// invited_at, NOT on claim state, because it was only ever called pre-claim.
// Calling it from the settle path breaks that assumption.
it('never invites a build that is already claimed', function () {
    $build = settledClaimedBuild();
    $build->forceFill([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => 'lead@example.com',
        'auto_invite' => true,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertNotQueued(ClaimInviteMail::class);
});

it('does not invite when auto_invite is false — that build is the staff eyeball lane', function () {
    [$user, $site, $build] = makeSettledBuild();
    $site->forceFill(['is_published' => true])->save();
    $build->forceFill([
        'contact_email' => 'lead@example.com',
        'auto_invite' => false,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertNotQueued(ClaimInviteMail::class);
});

// Spec §6: the outreach gate is claimed_at IS NULL *and published* and
// auto_invite. The publish term used to be structural -- the old call sat
// inside GeneratePreAccountSiteJob's `if ($this->publish)` block -- so moving
// the send to the settle path drops it unless it is restated here.
it('does not invite an unpublished build', function () {
    [$user, $site, $build] = makeSettledBuild();
    $build->forceFill([
        'contact_email' => 'lead@example.com',
        'auto_invite' => true,
    ])->save();

    app(BuildSettleService::class)->evaluate($build->fresh());

    Mail::assertNotQueued(ClaimInviteMail::class);
    expect($build->fresh()->invited_at)->toBeNull();
});
