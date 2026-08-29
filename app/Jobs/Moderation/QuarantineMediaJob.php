<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Sets site_media.processing_state to 'quarantined' for a CSAM-matched media item.
 * Scaffolded in Plan B; called by Plan C's CSAM auto-action pipeline.
 */
class QuarantineMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Enforcement action — must not sit behind a default-queue backlog.
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = ModerationQueue::HIGH;
    }

    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            $this->markDispatched($entry);

            // Prefer explicit site_media_id from action_target; fall back to reportable_id
            $mediaId = $entry->action_target['site_media_id'] ?? $case->reportable_id;

            // #W2-OBS-2: UPDATE returns rows MATCHED (Postgres and the SQLite stand-in
            // alike), so a re-run against already-quarantined media still returns 1 and
            // 0 unambiguously means "no such media row". markFailed() does NOT throw —
            // this job is the FIRST link of the csam_auto_suspend chain and a throw
            // would strand the suspension/KV actions behind it.
            $affected = DB::update(
                "UPDATE site.site_media SET processing_state = 'quarantined' WHERE id = ?",
                [$mediaId]
            );

            if ($affected === 0) {
                $this->markFailed($entry, "quarantine_media: no site_media row for id {$mediaId}");

                return;
            }

            $this->markCompleted($entry);
        });
    }
}
