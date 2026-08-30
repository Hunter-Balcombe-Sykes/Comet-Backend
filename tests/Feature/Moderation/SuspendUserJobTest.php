<?php

use App\Exceptions\Moderation\ModerationTargetMissingException;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('flips users.status to suspended and marks action_log completed', function () {
    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
    expect($entry->fresh()->status)->toBe('completed');
    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('is idempotent (running twice does not error)', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();
    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
});

// #W2-JOB-2 / #W2-LIFE-8: an at-least-once queue redelivery of a job whose action
// log entry already completed must not re-apply the suspension. Without the
// completed-status guard, this test fails — the second run flips the reinstated
// user straight back to 'suspended'.
it('does not re-suspend a user reinstated after this action log entry already completed', function () {
    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    SuspendUserJob::dispatch($entry->id, $case->id);

    expect($user->fresh()->status)->toBe('suspended');
    expect($entry->fresh()->status)->toBe('completed');
    $attemptsAfterFirstRun = $entry->fresh()->attempts;

    // Support reinstates the user in between the first delivery and the redelivery.
    // status is deliberately NOT fillable (SEC-2) — forceFill mirrors the real
    // reinstatement writers (AccountDeletionService, StaffUserController).
    $user->fresh()->forceFill(['status' => 'active'])->save();

    // Redelivery of the SAME action_log_id (Horizon at-least-once semantics).
    SuspendUserJob::dispatch($entry->id, $case->id);

    expect($user->fresh()->status)->toBe('active');
    // The guard returns before markDispatched() — the attempt counter must not
    // move either, or the audit trail claims a run that never happened.
    expect($entry->fresh()->attempts)->toBe($attemptsAfterFirstRun);
});

// #W2-OBS-2: the three zero-row outcomes, deliberately split. None of them throws
// — this job is a Bus::chain link and a throw would halt the takedown behind it.
it('marks the entry failed and reports when the owner user row does not exist', function () {
    Exceptions::fake();
    $missingUserId = (string) Str::uuid();

    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => (string) Str::uuid(),
        'reportable_owner_user_id' => $missingUserId,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain($missingUserId);
    Exceptions::assertReported(ModerationTargetMissingException::class);
});

// A soft-deleted owner is excluded by User's default scope, so the UPDATE matches
// zero rows for a legitimate reason. The withTrashed() probe is what keeps this
// from being paged as a failure — remove it and every deleted account alerts.
it('completes without reporting when the owner is already soft-deleted', function () {
    $user = User::factory()->create(['status' => 'active']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    $user->delete();

    // Fake AFTER the delete: UserObserver's cache invalidation reports a
    // QueryException against site.public_site_payload, which this lane's SQLite
    // stand-in does not create. Unrelated to the job under test — faking here
    // keeps assertNothingReported() an assertion about handle() alone.
    Exceptions::fake();

    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($entry->fresh()->status)->toBe('completed');
    Exceptions::assertNothingReported();
});

it('marks the entry failed when the case carries no reportable_owner_user_id', function () {
    Exceptions::fake();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => (string) Str::uuid(),
        'reportable_owner_user_id' => null,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain('no reportable_owner_user_id');
    Exceptions::assertReported(ModerationTargetMissingException::class);
});
