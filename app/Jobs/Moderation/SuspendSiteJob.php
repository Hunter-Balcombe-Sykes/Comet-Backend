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
use Throwable;

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
        $this->queue = 'moderation_high';
    }

    /**
     * Hide the reported site and mark the action log entry as completed.
     * Wrapped in a transaction so the site update and action log update
     * either both commit or both roll back.
     *
     * Human-report cases target the Site directly; CSAM cases target a SiteMedia,
     * so the owning site is resolved from the media row. Any other reportable
     * type is a graceful no-op (the entry is still marked completed so the action
     * log stays consistent).
     */
    public function handle(): void
    {
        DB::connection('pgsql')->transaction(function () {
            $case = ModerationCase::query()->findOrFail($this->caseId);
            $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);

            // Mark as dispatched and increment the attempt counter before acting —
            // if the site update throws, the action log reflects the attempt.
            $this->markDispatched($entry);

            $siteId = $this->resolveSiteId($case);
            if ($siteId !== null) {
                Site::query()
                    ->where('id', $siteId)
                    ->update(['moderation_state' => 'hidden']);
            }

            $this->markCompleted($entry);
        });
    }

    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        Log::error('Moderation enforcement job permanently failed', [
            'job' => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id' => $this->caseId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Resolve which site to hide. 'Site' cases carry the site id directly;
     * 'SiteMedia' cases (CSAM) carry a media id, so look up its owning site_id.
     * Returns null for other reportable types (graceful no-op).
     */
    private function resolveSiteId(ModerationCase $case): ?string
    {
        if ($case->reportable_type === 'Site') {
            return $case->reportable_id;
        }

        if ($case->reportable_type === 'SiteMedia') {
            $media = DB::connection('pgsql')->selectOne(
                'SELECT site_id FROM site.site_media WHERE id = ?',
                [$case->reportable_id]
            );

            return $media?->site_id;
        }

        return null;
    }
}
