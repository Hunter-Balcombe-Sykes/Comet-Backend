<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseCreatedStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatched whenever a case is created or its signal_count grows.
 * Notifies admin staff at configured thresholds (default 1, 3, 5, 10).
 *
 * On-call routing: all admin-role staff receive the notification.
 * The core.partna_staff table has no is_on_call column — if granular
 * on-call rotation is needed later, add the column + filter here.
 */
class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $caseId) {}

    public function handle(): void
    {
        $thresholds = config('partna.moderation.reporting.staff_notify_thresholds', [1, 3, 5, 10]);

        $case = ModerationCase::query()->find($this->caseId);
        if ($case === null) {
            return;
        }

        if (! in_array($case->signal_count, $thresholds, strict: true)) {
            return;
        }

        // Route to all admin-role staff. No is_on_call filter (column doesn't exist).
        $oncall = PartnaStaff::query()
            ->where('role', PartnaStaff::ROLE_ADMIN)
            ->get();

        if ($oncall->isEmpty()) {
            return;
        }

        Notification::send($oncall, new CaseCreatedStaffNotification($case));
    }
}
