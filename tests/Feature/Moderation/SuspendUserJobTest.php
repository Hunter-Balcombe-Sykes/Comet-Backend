<?php

use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Core\Site\Site;

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
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
    expect($entry->fresh()->status)->toBe('completed');
    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('is idempotent (running twice does not error)', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    $site = Site::factory()->for($user, 'user')->create();
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'suspend_user']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_user']);

    (new SuspendUserJob($entry->id, $case->id))->handle();
    (new SuspendUserJob($entry->id, $case->id))->handle();

    expect($user->fresh()->status)->toBe('suspended');
});
