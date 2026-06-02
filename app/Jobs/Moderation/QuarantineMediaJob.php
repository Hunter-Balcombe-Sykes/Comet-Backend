<?php

namespace App\Jobs\Moderation;

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sets site_media.processing_state to 'quarantined' for a CSAM-matched media item.
 * Scaffolded in Plan B; called by Plan C's CSAM auto-action pipeline.
 */
class QuarantineMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Enforcement action — must not sit behind a default-queue backlog.
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'moderation_high';
    }

    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case  = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), 'attempts' => $entry->attempts + 1]);

            // Prefer explicit site_media_id from action_target; fall back to reportable_id
            $mediaId = $entry->action_target['site_media_id'] ?? $case->reportable_id;

            DB::update(
                "UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?",
                [$mediaId]
            );

            $entry->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }

    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status'    => 'failed',
            'failed_at' => now(),
        ]);
        Log::error('Moderation enforcement job permanently failed', [
            'job'           => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id'       => $this->caseId,
            'error'         => $e->getMessage(),
        ]);
    }
}
