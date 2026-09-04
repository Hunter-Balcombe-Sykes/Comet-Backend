<?php

use App\Mail\Account\WelcomeMail;
use App\Services\PreAccount\BuildProgressReader;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    Mail::fake();
});

it('sends the welcome for a settled claimed build inside the window', function () {
    [$user, $site, $build] = makeSettledBuild();
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill(['claimed_at' => now()])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertQueued(WelcomeMail::class, 1);
    expect($build->fresh()->welcomed_at)->not->toBeNull();
});

// The cutover guard. Every pre-existing build is days old, so the window is
// what makes "new builds only" true without a backfill migration.
it('never looks at a build older than the window', function () {
    [$user, $site, $build] = makeSettledBuild();
    $user->forceFill(['primary_email' => 'old@example.com', 'status' => 'active'])->save();
    $build->forceFill([
        'claimed_at' => now(),
        'created_at' => now()->subDays(7),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect($build->fresh()->settled_at)->toBeNull()
        ->and($build->fresh()->welcomed_at)->toBeNull();
});

it('skips a build that already carries a terminal stamp', function () {
    [$user, $site, $build] = makeSettledBuild();
    $user->forceFill(['primary_email' => 'jane@example.com', 'status' => 'active'])->save();
    $build->forceFill([
        'claimed_at' => now(),
        'settled_at' => now(),
        'welcomed_at' => now(),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('stamps a stalled build and sends nothing', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'claimed_at' => now(),
        'created_at' => now()->subMinutes(BuildProgressReader::CEILING_MINUTES + 1),
    ])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    expect($build->fresh()->setup_stalled_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

// One bad build must not stop the tick -- the next build in the batch still
// gets evaluated.
it('keeps going when one build throws', function () {
    [$u1, $s1, $broken] = makeSettledBuild('brokenone');
    $broken->forceFill(['claimed_at' => now()])->save();
    $u1->forceDelete(); // orphan the build so welcomeIfDue hits a null user

    [$u2, $s2, $good] = makeSettledBuild('goodone');
    $u2->forceFill(['primary_email' => 'good@example.com', 'status' => 'active'])->save();
    $good->forceFill(['claimed_at' => now()])->save();

    $this->artisan('builds:settle-sweep')->assertExitCode(0);

    Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->recipientEmail === 'good@example.com');
});
