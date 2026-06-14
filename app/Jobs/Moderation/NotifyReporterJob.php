<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class NotifyReporterJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    // 5-min lock expiry so a crashed worker can't hold the lock forever.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'notifications';
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

        $decision = $case->decisions()->latest('decided_at')->firstOrFail();

        // Get unique reporter emails — null values are anonymous reporters (no notification possible)
        $reporters = CaseSignal::query()
            ->where('case_id', $case->id)
            ->whereNotNull('reporter_email')
            ->pluck('reporter_email')
            ->unique();

        foreach ($reporters as $email) {
            Notification::route('mail', $email)->notify(new ReportOutcomeNotification($decision));
        }

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
