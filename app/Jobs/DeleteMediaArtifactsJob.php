<?php

namespace App\Jobs;

use App\Services\Media\VideoVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously deletes all artifacts for a deleted video media item.
 *
 * Videos generate many HLS segment files (.ts) that are impractical to
 * delete synchronously during a DELETE request.  The controller soft-deletes
 * the SiteMedia row immediately (keeping the HTTP response fast), then
 * dispatches this job to clean up all storage artifacts and DB rows.
 *
 * Dispatched onto the "videos" queue to avoid blocking image workers.
 */
// V2: Async cleanup of HLS segments and storage artifacts for deleted video media. Queue: videos.
class DeleteMediaArtifactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /**
     * Exponential backoff (seconds) before each retry: 60 → 300 → 900.
     * P1-08: tolerates ~21min of R2 degradation before failed() gives up.
     * Anything still orphaned past that is recovered by the gdpr ledger sweep
     * (paths are recorded in audit.user_deletion_audit at purge time).
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public int $timeout = 120;

    /**
     * @param  string  $mediaId  UUID of the (now soft-deleted) SiteMedia row.
     * @param  string  $basePath  Storage prefix for all video artifacts (videos/{proId}/{mediaId}).
     * @param  string  $usage  Upload usage (for logging context only).
     */
    public function __construct(
        public readonly string $mediaId,
        public readonly string $basePath,
        public readonly string $usage,
    ) {
        $this->onConnection((string) config('partna.video_queue.connection', 'redis_video'));
        $this->onQueue((string) config('partna.video_queue.name', 'videos'));
    }

    public function handle(VideoVariantService $service): void
    {
        Log::info('DeleteMediaArtifactsJob: starting cleanup', [
            'media_id' => $this->mediaId,
            'base_path' => $this->basePath,
        ]);

        try {
            $service->deleteVariants($this->mediaId, $this->basePath);

            Log::info('DeleteMediaArtifactsJob: cleanup complete', [
                'media_id' => $this->mediaId,
            ]);
        } catch (Throwable $e) {
            Log::error('DeleteMediaArtifactsJob: cleanup failed.', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);

        Log::error('DeleteMediaArtifactsJob: cleanup exhausted retries.', [
            'media_id' => $this->mediaId,
            'base_path' => $this->basePath,
            'usage' => $this->usage,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
    }
}
