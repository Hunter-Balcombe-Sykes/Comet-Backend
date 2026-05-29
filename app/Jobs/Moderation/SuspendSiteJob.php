<?php

namespace App\Jobs\Moderation;

use App\Models\Core\Site\Site;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SuspendSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {}

    /**
     * Hide the reported site and mark the action log entry as completed.
     * Wrapped in a transaction so the site update and action log update
     * either both commit or both roll back.
     *
     * Only acts when reportable_type is 'Site' — other types are a graceful no-op
     * (the entry is still marked completed so the action log stays consistent).
     */
    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case  = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            // Mark as dispatched and increment the attempt counter before acting —
            // if the site update throws, the action log reflects the attempt.
            $entry->update([
                'status'        => 'dispatched',
                'dispatched_at' => now(),
                'attempts'      => $entry->attempts + 1,
            ]);

            if ($case->reportable_type === 'Site') {
                Site::query()
                    ->where('id', $case->reportable_id)
                    ->update(['moderation_state' => 'hidden']);
            }

            $entry->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        });
    }
}
