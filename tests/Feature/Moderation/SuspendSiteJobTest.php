<?php

use App\Exceptions\Moderation\ModerationTargetMissingException;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('flips sites.moderation_state to hidden', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
    expect($entry->fresh()->status)->toBe('completed');
});

it('marks completed_at timestamp on success', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($entry->fresh()->completed_at)->not->toBeNull();
});

it('is idempotent (running twice does not error)', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'hidden']);
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $site->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();
    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
});

it('resolves the owning site for SiteMedia (CSAM) cases and hides it', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create(['moderation_state' => 'active']);

    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'path' => 'quarantine/x.jpg',
        'processing_state' => 'quarantined',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $case = ModerationCase::factory()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $mediaId,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'csam_auto_suspend']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($site->fresh()->moderation_state)->toBe('hidden');
    expect($entry->fresh()->status)->toBe('completed');
});

// A reportable type with no owning site is a GENUINE no-op and stays 'completed'.
// The assertNothingReported() pins the deliberate split against the two failure
// cases below — collapsing them back into one null branch would break this.
it('is a no-op for non-Site reportable types', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    $case = ModerationCase::factory()->create([
        'reportable_type' => 'User',
        'reportable_id' => $user->id,
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    // Should not throw
    (new SuspendSiteJob($entry->id, $case->id))->handle();

    expect($entry->fresh()->status)->toBe('completed');
    Exceptions::assertNothingReported();
});

// #W2-OBS-2: a CSAM case whose media row has gone cannot resolve a site to hide.
// Failure, not success — but marked in place, never thrown: this job is a
// Bus::chain link and a throw would halt the rest of the takedown.
it('marks the entry failed and reports when the SiteMedia row does not exist', function () {
    Exceptions::fake();
    $missingMediaId = (string) Str::uuid();

    $case = ModerationCase::factory()->create([
        'reportable_type' => 'SiteMedia',
        'reportable_id' => $missingMediaId,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'csam_auto_suspend']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain($missingMediaId);
    Exceptions::assertReported(ModerationTargetMissingException::class);
});

it('marks the entry failed and reports when the target site row does not exist', function () {
    Exceptions::fake();
    $missingSiteId = (string) Str::uuid();

    $case = ModerationCase::factory()->create([
        'reportable_type' => 'Site',
        'reportable_id' => $missingSiteId,
    ]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'suspend_site']);

    (new SuspendSiteJob($entry->id, $case->id))->handle();

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain($missingSiteId);
    Exceptions::assertReported(ModerationTargetMissingException::class);
});
