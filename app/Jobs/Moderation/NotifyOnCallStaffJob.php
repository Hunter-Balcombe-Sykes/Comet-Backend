<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyOnCallStaffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(public readonly string $actionLogId, public readonly string $caseId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'moderation_high';
    }

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

        // On-call routing: all admin staff are treated as on-call.
        // PartnaStaff has no is_on_call column — all role='admin' rows are on-call.
        $oncall = PartnaStaff::query()->where('role', 'admin')->get();
        if ($oncall->isEmpty()) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
            return;
        }

        $latestDecision = $case->decisions()->latest('decided_at')->first();

        $notification = match (true) {
            $case->case_type === 'csam_match' => new CsamAutoActionStaffNotification($case),
            $latestDecision !== null && str_starts_with($latestDecision->decision_type, 'escalate_')
                => new CaseEscalatedStaffNotification($latestDecision),
            default => new CsamAutoActionStaffNotification($case),
        };

        Notification::send($oncall, $notification);

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
