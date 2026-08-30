<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Sets site_media.processing_state to 'quarantined' for a CSAM-matched media item.
 * Scaffolded in Plan B; called by Plan C's CSAM auto-action pipeline.
 */
class QuarantineMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasActionLogLifecycle;

    public int $timeout = 60;

    // Honest scope of this lock (round-2 review correction — do not restate the
    // old "prevents a crashed worker from holding a stale redelivery" framing):
    // this job is ALWAYS the opening link of ModerationActionDispatcher's
    // csam_auto_suspend Bus::chain, and Bus::chain(...)->dispatch() calls
    // Dispatcher::dispatch() directly for the first link — it never builds a
    // PendingDispatch, so UniqueLock::acquire() (which lives only in
    // PendingDispatch::shouldDispatch(), run from its __destruct()) is NEVER
    // invoked for this job. The lock is therefore inert in every real dispatch.
    // Even where a lock IS acquired (later chain links, via the global dispatch()
    // helper in dispatchNextJobInChain()), it buys nothing against the bug this
    // guard exists for: a Horizon at-least-once redelivery re-enters
    // CallQueuedHandler::call() directly and never touches UniqueLock::acquire()
    // — the lock is a dispatch-time gate only. The `status === 'completed'` check
    // below does 100% of the work that stops a stale redelivery re-quarantining
    // cleared media. ShouldBeUnique only debounces a genuinely concurrent
    // duplicate DISPATCH of the same actionLogId, and no path currently produces
    // one (actionLogId is a fresh Str::uuid() per dispatchFor() call —
    // ModerationActionDispatcher is the only dispatcher). Kept as harmless
    // insurance for a possible future manual-retry endpoint; re-verify this
    // reasoning if one is ever added.
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $actionLogId,
        public readonly string $caseId,
    ) {
        // Enforcement action — must not sit behind a default-queue backlog.
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = ModerationQueue::HIGH;
    }

    public function uniqueId(): string
    {
        return $this->actionLogId;
    }

    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            // Idempotency — an at-least-once redelivery after this entry already
            // completed must not re-quarantine (or matter if the media has since
            // been dequarantined by staff review).
            if ($entry->status === 'completed') {
                return;
            }

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
