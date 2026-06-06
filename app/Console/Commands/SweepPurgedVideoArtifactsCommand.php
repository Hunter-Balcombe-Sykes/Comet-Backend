<?php

namespace App\Console\Commands;

use App\Models\Core\User\UserDeletionAuditEntry;
use App\Services\Media\VideoVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * P1-08 (ledger sweep): recover video R2 artifacts orphaned when
 * DeleteMediaArtifactsJob exhausts its retries during an R2 outage AND the
 * owning account has since been hard-deleted — at which point no site_media
 * row remains to locate the files.
 *
 * purge() records each deleted video's R2 base path in the EVENT_PURGED audit
 * row (audit.user_deletion_audit.metadata). This sweep re-deletes any recorded
 * path still present on the disk. It is idempotent: the original job usually
 * succeeded, so nearly every path is already gone and the per-path check is a
 * cheap empty LIST.
 *
 * Bounded to a recent window so the audit-table scan stays cheap; the unbounded
 * prefix GC (media:gc-orphaned-video-artifacts) is the backstop for anything
 * older than the window.
 */
class SweepPurgedVideoArtifactsCommand extends Command
{
    protected $signature = 'gdpr:sweep-purged-video-artifacts';

    protected $description = 'Re-delete video R2 artifacts recorded in purge audit rows but left behind by a failed cleanup job.';

    public function handle(VideoVariantService $videos): int
    {
        $windowDays = (int) config('partna.media_orphan_sweep.ledger_window_days', 30);
        $cutoff = now()->subDays($windowDays);

        $checked = 0;
        $recovered = 0;

        UserDeletionAuditEntry::query()
            ->where('event', UserDeletionAuditEntry::EVENT_PURGED)
            ->where('created_at', '>=', $cutoff)
            // cursor() keeps memory bounded if a backlog of purges accumulates.
            ->cursor()
            ->each(function (UserDeletionAuditEntry $entry) use ($videos, &$checked, &$recovered): void {
                $paths = $entry->metadata['video_artifact_paths'] ?? [];
                if (! is_array($paths)) {
                    return;
                }

                foreach ($paths as $path) {
                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $checked++;
                    $result = $videos->purgeStoragePrefix($path);

                    // A path that still had objects means the original cleanup
                    // never finished — GDPR erasure was completed late, not on
                    // time. Surface it so the outage can be correlated.
                    if ($result['deleted'] > 0) {
                        $recovered++;
                        Log::warning('gdpr.video_artifact_orphan_recovered', [
                            'base_prefix' => $result['basePrefix'],
                            'deleted' => $result['deleted'],
                        ]);
                    }
                }
            });

        $this->info("Checked {$checked} ledgered video path(s); recovered {$recovered} orphan(s).");
        Log::info('gdpr.sweep_purged_video_artifacts.complete', [
            'checked' => $checked,
            'recovered' => $recovered,
        ]);

        return self::SUCCESS;
    }
}
