<?php

use App\Exceptions\Moderation\ModerationTargetMissingException;
use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Exceptions;

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

// #W2-OBS-2: markFailed() is the NON-THROWING sibling of failed(), for enforcement
// jobs that are links in a Bus::chain. Exercised through a bare trait consumer so
// the helper's own contract is pinned independently of any one job.
it('markFailed marks the entry failed, truncates the reason and reports', function () {
    Exceptions::fake();

    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create([
        'action_type' => 'suspend_site',
        'status' => 'dispatched',
    ]);

    $consumer = new class($entry->id, $case->id)
    {
        use HasActionLogLifecycle;

        public function __construct(public readonly string $actionLogId, public readonly string $caseId) {}

        public function callMarkFailed(ActionLogEntry $entry, string $reason): void
        {
            $this->markFailed($entry, $reason);
        }
    };

    $reason = str_repeat('x', 2000);
    $consumer->callMarkFailed($entry, $reason);

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    // Str::limit(…, 1000) keeps 1000 chars and appends '...'.
    expect(strlen((string) $fresh->failure_reason))->toBe(1003);
    Exceptions::assertReported(ModerationTargetMissingException::class);
});
