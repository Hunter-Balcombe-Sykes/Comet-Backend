<?php

use App\Jobs\Moderation\SuspendSiteJob;
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

it('flips sites.moderation_state to hidden', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
    expect($entry->fresh()->status)->toBe('completed');
});

it('marks completed_at timestamp on success', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('is idempotent (running twice does not error)', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'hidden']);
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'Site',
        'reportable_id'            => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();
    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
});

it('is a no-op for non-Site reportable types', function () {
    $user = User::factory()->create();
    $case = ModerationCase::factory()->create([
        'reportable_type'          => 'User',
        'reportable_id'            => $user->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    // Should not throw
    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($entry->fresh()->status)->toBe('completed');
});
