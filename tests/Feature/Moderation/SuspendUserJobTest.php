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
