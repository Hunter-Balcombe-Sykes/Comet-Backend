<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseCreatedStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Dispatched whenever a case is created or its signal_count grows.
 * Notifies admin staff at configured thresholds (default 1, 3, 5, 10).
 *
 * On-call routing: all admin-role staff receive the notification.
 * The core.partna_staff table has no is_on_call column — if granular
 * on-call rotation is needed later, add the column + filter here.
 */
class NotifyStaffOfCaseUpdateJob implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Stepped backoff — let transient notification failures settle
    // instead of burning the full backoff window before Horizon alerts.
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    // 5-min lock expiry so a crashed worker can't hold the lock forever.
    public int $uniqueFor = 300;

    public function __construct(public readonly string $caseId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'notifications';
    }

    public function uniqueId(): string
    {
        // Include signal_count in the key so distinct threshold alerts (e.g. 3 then 5) are not swallowed.
        // This query runs at dispatch time; the job MUST stay ShouldQueueAfterCommit (or be dispatched
        // outside a transaction) so signal_count is already committed when we read it here.
        $signalCount = ModerationCase::query()->where('id', $this->caseId)->value('signal_count');

        return $this->caseId.':'.($signalCount ?? 'unknown');
    }

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

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('Moderation staff notification job permanently failed', [
            'job' => static::class,
            'case_id' => $this->caseId,
            'error' => $e->getMessage(),
        ]);
    }
}
