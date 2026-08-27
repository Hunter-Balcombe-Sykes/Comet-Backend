<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SuspendUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = ModerationQueue::HIGH;
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

            // Mark as dispatched and increment the attempt counter before acting —
            // if the user update throws, the action log reflects the attempt.
            $this->markDispatched($entry);

            if ($case->reportable_owner_user_id !== null) {
                // Most recent decision on the case determines the target status.
                $decision = $case->decisions()->latest('decided_at')->first();
                $newStatus = match ($decision?->decision_type) {
                    'ban_user' => 'disabled',
                    default => 'suspended',
                };

                User::query()
                    ->where('id', $case->reportable_owner_user_id)
                    ->update(['status' => $newStatus]);
            }

            $this->markCompleted($entry);
        });
    }
}
