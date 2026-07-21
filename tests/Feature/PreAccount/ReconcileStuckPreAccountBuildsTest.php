<?php

// LIFE-4: builds:reconcile-stuck watchdog. Mirrors tests/Feature/PreAccount/
// PruneExpiredBuildsTest.php's setup/helper conventions.

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    config(['partna.pre_account.stuck_build_sla_minutes' => 30]);
});

/**
 * Seeds a provisional user + site + a PreAccountBuild with the given attrs,
 * with updated_at backdated by $ageMinutes. Returns the build.
 */
function makeReconcileTestBuild(string $handle, array $buildAttrs, int $ageMinutes): PreAccountBuild
{
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null, 'handle' => $handle]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => $handle]);
    $build = PreAccountBuild::factory()->make($buildAttrs);
    $build->user()->associate($user);
    $build->save();

    // Direct attribute assignment (not update()) — 'updated_at' isn't fillable,
    // so update()'s mass-assignment would silently drop it and Eloquent's own
    // updateTimestamps() would re-stamp it to "now" (mirrors PruneExpiredBuildsTest's
    // precedent for the same model).
    $build->updated_at = now()->subMinutes($ageMinutes);
    $build->save();

    return $build;
}

it('fails a build stuck in pending past the SLA', function () {
    $build = makeReconcileTestBuild('stuck-pending', ['build_state' => PreAccountBuild::STATE_PENDING], 31);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($fresh->failure_code)->toBe(PreAccountBuild::FAILURE_STUCK_TIMEOUT);
});

it('fails a build stuck in building past the SLA', function () {
    $build = makeReconcileTestBuild('stuck-building', ['build_state' => PreAccountBuild::STATE_BUILDING], 45);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($fresh->failure_code)->toBe(PreAccountBuild::FAILURE_STUCK_TIMEOUT);
});

it('leaves a pending build within the SLA untouched', function () {
    $build = makeReconcileTestBuild('fresh-pending', ['build_state' => PreAccountBuild::STATE_PENDING], 5);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($fresh->failure_code)->toBeNull();
});

it('leaves ready builds untouched regardless of age', function () {
    $build = makeReconcileTestBuild('old-ready', ['build_state' => PreAccountBuild::STATE_READY], 999);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY);
});

it('leaves already-failed builds untouched', function () {
    $build = makeReconcileTestBuild('old-failed', [
        'build_state' => PreAccountBuild::STATE_FAILED,
        'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED,
    ], 999);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($fresh->failure_code)->toBe(PreAccountBuild::FAILURE_SCRAPE_FAILED);
});

it('leaves claimed builds untouched even if stuck in pending', function () {
    $build = makeReconcileTestBuild('claimed-stuck', [
        'build_state' => PreAccountBuild::STATE_PENDING,
        'claimed_at' => now()->subDay(),
    ], 999);

    $this->artisan('builds:reconcile-stuck')->assertSuccessful();

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_PENDING);
});

it('supports --dry-run without mutating any rows', function () {
    $build = makeReconcileTestBuild('dry-run-stuck', ['build_state' => PreAccountBuild::STATE_PENDING], 60);

    $this->artisan('builds:reconcile-stuck --dry-run')->assertSuccessful();

    $fresh = $build->fresh();
    expect($fresh->build_state)->toBe(PreAccountBuild::STATE_PENDING)
        ->and($fresh->failure_code)->toBeNull();
});
