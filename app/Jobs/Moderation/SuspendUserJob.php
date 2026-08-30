<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspendUserJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    // 5-min lock expiry so a crashed worker can't hold the lock forever — same
    // headroom as NotifyOnCallStaffJob's uniqueFor.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = ModerationQueue::HIGH;
    }

    public function uniqueId(): string
    {
        return $this->actionLogId;
    }

    /**
     * Apply a suspension (or ban) to the case owner and mark the action log entry
     * as completed. Wrapped in a transaction so the user status update and the
     * action log update either both commit or both roll back.
     *
     * Decision type mapping:
     *   ban_user     → 'disabled'  (permanent — admin-only to reverse)
     *   suspend_user → 'suspended' (reversible by support)
     */
    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            // Idempotency — an at-least-once redelivery after this entry already
            // completed must not re-suspend a user support has since reinstated.
            if ($entry->status === 'completed') {
                return;
            }

            // Mark as dispatched and increment the attempt counter before acting —
            // if the user update throws, the action log reflects the attempt.
            $this->markDispatched($entry);

            // The dispatcher only queues this action for suspend_user/ban_user
            // decisions, which are meaningless without an owner — a null here is a
            // broken case, not a no-op (#W2-OBS-2).
            if ($case->reportable_owner_user_id === null) {
                $this->markFailed($entry, 'suspend_user: case has no reportable_owner_user_id');

                return;
            }

            // Most recent decision on the case determines the target status.
            $decision = $case->decisions()->latest('decided_at')->first();
            $newStatus = match ($decision?->decision_type) {
                'ban_user' => 'disabled',
                default => 'suspended',
            };

            // UPDATE returns rows MATCHED, so re-suspending an already-suspended user
            // still returns 1. 0 means either the row is gone OR the owner is
            // soft-deleted (User uses SoftDeletes, so the default scope excludes them).
            // Probe with withTrashed() to tell those apart — the WRITE deliberately
            // stays unscoped-for-trashed: users_status_check includes 'pending_deletion'
            // and clobbering it would corrupt the deletion lane.
            $affected = User::query()
                ->where('id', $case->reportable_owner_user_id)
                ->update(['status' => $newStatus]);

            if ($affected === 0) {
                $exists = User::withTrashed()
                    ->whereKey($case->reportable_owner_user_id)
                    ->exists();

                if (! $exists) {
                    $this->markFailed($entry, "suspend_user: no user row for {$case->reportable_owner_user_id}");

                    return;
                }

                // Owner already soft-deleted — nothing left to suspend. Legitimate no-op.
                Log::info('Moderation suspend_user no-op: owner already soft-deleted', [
                    'action_log_id' => $this->actionLogId,
                    'case_id' => $this->caseId,
                    'user_id' => $case->reportable_owner_user_id,
                ]);
            }

            $this->markCompleted($entry);
        });
    }
}
