<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyOnCallStaffJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 30;

    public function __construct(public readonly string $actionLogId, public readonly string $caseId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'moderation_high';
    }

    public function handle(): void
    {
        $case = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $this->markDispatched($entry);

        // On-call routing: all admin staff are treated as on-call.
        // PartnaStaff has no is_on_call column — all role='admin' rows are on-call.
        $oncall = PartnaStaff::query()->where('role', 'admin')->get();
        if ($oncall->isEmpty()) {
            $this->markCompleted($entry);

            return;
        }

        $latestDecision = $case->decisions()->latest('decided_at')->first();

        $notification = match (true) {
            $case->case_type === 'csam_match' => new CsamAutoActionStaffNotification($case),
            $latestDecision !== null && str_starts_with($latestDecision->decision_type, 'escalate_') => new CaseEscalatedStaffNotification($latestDecision),
            default => new CsamAutoActionStaffNotification($case),
        };

        Notification::send($oncall, $notification);

        $this->markCompleted($entry);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        Log::error('Moderation notification job permanently failed', [
            'job' => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id' => $this->caseId,
            'error' => $e->getMessage(),
        ]);
    }
}
