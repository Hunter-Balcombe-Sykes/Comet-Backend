<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyReporterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    public function handle(): void
    {
        $case  = ModerationCase::query()->findOrFail($this->caseId);
        $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
        $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

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

        $entry->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
