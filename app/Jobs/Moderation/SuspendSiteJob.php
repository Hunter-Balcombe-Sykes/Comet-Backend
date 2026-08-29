<?php

namespace App\Jobs\Moderation;

use App\Jobs\Moderation\Concerns\HasActionLogLifecycle;
use App\Models\Core\Site\Site;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\ModerationCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SuspendSiteJob implements ShouldQueue
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

    /**
     * Hide the reported site and mark the action log entry as completed.
     * Wrapped in a transaction so the site update and action log update
     * either both commit or both roll back.
     *
     * Human-report cases target the Site directly; CSAM cases target a SiteMedia,
     * so the owning site is resolved from the media row.
     *
     * Three outcomes, deliberately split (#W2-OBS-2):
     *  - site hidden                        → completed
     *  - reportable type carries no site    → genuine no-op, still completed
     *  - the media row or site row is GONE  → failed + reported (never thrown —
     *    this job is a Bus::chain link; see HasActionLogLifecycle::markFailed())
     */
    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            // Mark as dispatched and increment the attempt counter before acting —
            // if the site update throws, the action log reflects the attempt.
            $this->markDispatched($entry);

            ['siteId' => $siteId, 'missing' => $missing] = $this->resolveSiteId($case);

            if ($missing) {
                $this->markFailed($entry, "suspend_site: no site_media row for {$case->reportable_id}");

                return;
            }

            if ($siteId === null) {
                // Genuine no-op: the reported thing (User, Block, …) has no owning
                // site to hide. Not a failure — logged so it stays visible.
                Log::info('Moderation suspend_site no-op for reportable type', [
                    'action_log_id' => $this->actionLogId,
                    'case_id' => $this->caseId,
                    'reportable_type' => $case->reportable_type,
                ]);
                $this->markCompleted($entry);

                return;
            }

            // UPDATE returns rows MATCHED, so an already-hidden site still returns 1;
            // 0 means the site row itself has gone.
            $affected = Site::query()
                ->where('id', $siteId)
                ->update(['moderation_state' => 'hidden']);

            if ($affected === 0) {
                $this->markFailed($entry, "suspend_site: no site row for {$siteId}");

                return;
            }

            $this->markCompleted($entry);
        });
    }

    /**
     * Resolve which site to hide, distinguishing "there is no site to hide for this
     * reportable type" (missing = false, siteId = null → no-op) from "the media row
     * we were told to trace is gone" (missing = true → failure). Collapsing those
     * two into one null is what made a missing CSAM media row report success.
     *
     * @return array{siteId: ?string, missing: bool}
     */
    private function resolveSiteId(ModerationCase $case): array
    {
        if ($case->reportable_type === 'Site') {
            return ['siteId' => $case->reportable_id, 'missing' => false];
        }

        if ($case->reportable_type === 'SiteMedia') {
            $media = DB::connection('pgsql')->selectOne(
                'SELECT site_id FROM site.site_media WHERE id = ?',
                [$case->reportable_id]
            );

            if ($media === null) {
                return ['siteId' => null, 'missing' => true];
            }

            return ['siteId' => $media->site_id, 'missing' => false];
        }

        return ['siteId' => null, 'missing' => false];
    }
}
