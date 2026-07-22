<?php

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;

// DISC-8: failed() previously wrote a nonexistent `failed_at` column via a
// query-builder update(), bypassing $fillable and 42703'ing on Postgres for
// every permanently-failed moderation job. Exercises the real hook end to
// end against the SQLite stub, which — like prod — has no failed_at column.
beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('marks the action log entry failed and records the exception message in failure_reason', function () {
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'notify_oncall_staff',
        'status' => 'pending',
    ]);

    $job = new NotifyOnCallStaffJob($entry->id, $case->id);
    $job->failed(new RuntimeException('boom kaboom'));

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain('boom kaboom');
});
