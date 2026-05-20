<?php

namespace App\Console\Commands;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * QUEUE-5: reconciles SiteMedia rows orphaned in the PROCESSING state.
 *
 * ProcessImageVariantsJob / ProcessVideoVariantsJob hold an in-flight Redis
 * lock for (timeout + 60)s. If the worker is SIGKILL'd / OOM-killed mid-run,
 * a retry that lands inside the still-held lock window silently returns and the
 * queue marks the job complete — leaving the SiteMedia row stuck in PROCESSING
 * forever. Both jobs deliberately defer this reconciliation to a "separate
 * cleanup story" (see the trade-off notes in their handle() docblocks); this
 * command is that cleanup story.
 *
 * A row is only swept once `updated_at` (stamped when the job flips it to
 * PROCESSING) is older than the worst-case wall time for its media type — past
 * that point the job can no longer be legitimately in flight, so the row can
 * only be the residue of a dead worker.
 */
class CleanupStuckMediaProcessingCommand extends Command
{
    protected $signature = 'media:cleanup-stuck-processing {--dry-run : Report counts without updating}';

    protected $description = 'Fail SiteMedia rows stuck in PROCESSING past the worst-case job duration.';

    /**
     * Worst-case wall time per media type, in minutes: job $timeout + lock
     * buffer + retry/backoff headroom, rounded generously up. Images:
     * 120s timeout, $tries=3. Videos: 720s timeout, $tries=2.
     */
    private const STALE_AFTER_MINUTES = [
        SiteMedia::MEDIA_TYPE_IMAGE => 30,
        SiteMedia::MEDIA_TYPE_VIDEO => 60,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach (self::STALE_AFTER_MINUTES as $mediaType => $minutes) {
            $cutoff = now()->subMinutes($minutes);

            $query = SiteMedia::query()
                ->where('processing_state', SiteMedia::PROCESSING_STATE_PROCESSING)
                ->where('media_type', $mediaType)
                ->where('updated_at', '<', $cutoff);

            if ($dryRun) {
                $count = (clone $query)->count();
                $this->info("[dry-run] {$count} stuck {$mediaType} row(s) older than {$minutes}m.");
                $total += $count;

                continue;
            }

            // chunkById (not chunk): rows leave the PROCESSING filter as we fail
            // them, so an offset-based chunk() would skip every other page.
            $query->chunkById(100, function ($rows) use ($mediaType, $minutes, &$total) {
                foreach ($rows as $media) {
                    $media->update([
                        'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
                        'processing_error' => 'processing watchdog timeout',
                    ]);

                    Log::warning('media.cleanup.swept_stuck', [
                        'media_id' => $media->id,
                        'media_type' => $mediaType,
                        'age_minutes' => $media->updated_at?->diffInMinutes(now()),
                        'threshold_minutes' => $minutes,
                    ]);
                    $total++;
                }
            });
        }

        $this->info($dryRun
            ? "[dry-run] {$total} stuck media row(s) total."
            : "Swept {$total} stuck media row(s) → failed.");

        return self::SUCCESS;
    }
}
