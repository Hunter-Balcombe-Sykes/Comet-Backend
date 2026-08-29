<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\DedupesRecipientSends;
use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification as NotificationMessage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class NotifyOnCallStaffJob implements ShouldBeUnique, ShouldQueue
{
    use DedupesRecipientSends;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 30;

    // 5-min lock expiry so a crashed worker can't hold the lock forever.
    public int $uniqueFor = 300;

    public function __construct(public readonly string $actionLogId, public readonly string $caseId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = ModerationQueue::HIGH;
    }

    public function uniqueId(): string
    {
        return $this->actionLogId;
    }

    public function handle(): void
    {
        $case = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        // Idempotency — a retry after success must not re-send.
        if ($entry->status === 'completed') {
            return;
        }
        $this->markDispatched($entry);

        // On-call routing: all admin staff are treated as on-call.
        // PartnaStaff has no is_on_call column — all role='admin' rows are on-call.
        $oncall = PartnaStaff::query()->where('role', PartnaStaff::ROLE_ADMIN)->get();
        if ($oncall->isEmpty()) {
            // #W2-OBS-1: an empty roster means nobody was paged, so the entry must not
            // read 'completed'. This job is dispatched INDEPENDENTLY (nothing downstream
            // of it in ModerationActionDispatcher), so throwing is safe here — unlike the
            // chained enforcement jobs, which use markFailed(). failed() then writes
            // status='failed' + failure_reason and reports to Nightwatch.
            throw new RuntimeException('No on-call staff available to page for moderation case '.$this->caseId);
        }

        $latestDecision = $case->decisions()->latest('decided_at')->first();

        $notification = match (true) {
            $case->case_type === 'csam_match' => new CsamAutoActionStaffNotification($case),
            $latestDecision !== null && str_starts_with($latestDecision->decision_type, 'escalate_') => new CaseEscalatedStaffNotification($latestDecision),
            default => new CsamAutoActionStaffNotification($case),
        };

        foreach ($oncall as $staff) {
            $recipient = 'staff:'.$staff->id;
            if (! $this->claimRecipient($entry->id, $recipient)) {
                continue;
            }
            try {
                $this->sendTo($staff, $notification);
            } catch (Throwable $e) {
                $this->releaseRecipient($entry->id, $recipient);
                throw $e;
            }
        }

        $this->markCompleted($entry);
    }

    /**
     * Extracted send seam, mirroring NotifyReporterJob::sendTo — per-staff so
     * a crash mid-loop only re-claims/re-pages the staff not yet reached.
     */
    protected function sendTo(PartnaStaff $staff, NotificationMessage $notification): void
    {
        Notification::send($staff, $notification);
    }
}
