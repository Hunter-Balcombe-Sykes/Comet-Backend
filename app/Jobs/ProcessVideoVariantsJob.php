<?php

namespace App\Jobs;

use App\Jobs\Concerns\GuardsMediaProcessing;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\VideoVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Transcodes a video upload into MP4 + HLS variants and a poster image.
 *
 * Dispatched onto the dedicated "videos" queue (redis_video connection)
 * so transcoding never contends with image processing or HTTP workers.
 *
 * State transitions:
 *   pending → processing (job starts)
 *   processing → ready   (all artifacts stored)
 *   processing → failed  (unrecoverable error after all retries)
 *
 * Worker invocation:
 *   php artisan queue:work redis_video --queue=videos --timeout=3600
 */
// V2: Transcodes video to MP4 + HLS variants with poster. Feature-flagged. Uses dedicated redis_video connection. Queue: videos.
class ProcessVideoVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use GuardsMediaProcessing;

    // 4 = 1 initial attempt + 3 retries, one per $backoff element below (R3-SCALE-3:
    // tries=2 previously meant only backoff[0] (60s) was ever reached — the declared
    // 300s/900s gaps were dead code. $timeout=720 and the redis_video connection's
    // retry_after=3600 both have ample headroom for the longer gaps.
    public int $tries = 4;

    // Exponential backoff (JOB-11): transient R2/transcode failures get progressively longer retry gaps.
    public array $backoff = [60, 300, 900];

    public int $timeout = 720;

    /**
     * @param  string  $mediaId  UUID of the SiteMedia row.
     * @param  string  $originalPath  Storage path of the uploaded original.
     * @param  string  $basePath  Directory prefix under media disk (videos/{proId}/{mediaId}).
     */
    public function __construct(
        public readonly string $mediaId,
        public readonly string $originalPath,
        public readonly string $basePath,
    ) {
        $this->onConnection((string) config('partna.video_queue.connection', 'redis_video'));
        $this->onQueue((string) config('partna.video_queue.name', 'videos'));
    }

    public function handle(VideoVariantService $service): void
    {
        // In-flight lock keyed on media_id (NOT job id) so a crash-then-retry
        // can still re-acquire after the TTL elapses. The terminal-state guard
        // below covers READY/FAILED but not PROCESSING — without this lock, a
        // redelivered job mid-encode would re-enter FFmpeg work in parallel.
        // TTL is timeout + 60s buffer so the lock auto-expires after a crash.
        //
        // Trade-off: a SIGKILL/OOM of the lock holder leaves the lock held until
        // TTL expiry. Retries that land inside the held window silently return
        // and the queue marks the job complete, so the SiteMedia row can be left
        // stuck in PROCESSING for up to (timeout + 60)s after a worker death.
        // A separate cleanup story (cron over rows in PROCESSING past their
        // expected duration) is the right place to reconcile this — adding
        // release-with-delay here would consume `tries` and trigger a spurious
        // `failed()` if the lock holder is still alive when retries exhaust.
        $lockKey = "video:processing-lock:{$this->mediaId}";
        if (! $this->acquireProcessingLock($lockKey)) {
            Log::info('ProcessVideoVariantsJob: another worker is processing this media, skipping.', [
                'media_id' => $this->mediaId,
            ]);

            return;
        }

        try {
            $this->runHandle($service);
        } finally {
            $this->releaseProcessingLock($lockKey);
        }
    }

    private function runHandle(VideoVariantService $service): void
    {
        Log::info('ProcessVideoVariantsJob: starting', [
            'media_id' => $this->mediaId,
            'original_path' => $this->originalPath,
        ]);

        $siteMedia = SiteMedia::withTrashed()->find($this->mediaId);

        if (! $siteMedia) {
            Log::warning('ProcessVideoVariantsJob: SiteMedia row no longer exists, skipping.', [
                'media_id' => $this->mediaId,
            ]);

            return;
        }

        if ($siteMedia->trashed()) {
            Log::info('ProcessVideoVariantsJob: SiteMedia row is soft-deleted, skipping.', [
                'media_id' => $this->mediaId,
            ]);

            return;
        }

        // Guard against redelivered jobs overwriting a terminal state back to processing.
        // At-least-once delivery makes this a certainty rather than a theory on Horizon.
        if ($this->isInTerminalState($siteMedia)) {
            Log::info('ProcessVideoVariantsJob: already in terminal state, skipping.', [
                'media_id' => $this->mediaId,
                'processing_state' => $siteMedia->processing_state,
            ]);

            return;
        }

        SiteMedia::query()
            ->where('id', $this->mediaId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);

        $diskName = $service->resolvedDiskName();
        $disk = Storage::disk($diskName);

        if (! $disk->exists($this->originalPath)) {
            $this->markFailed('Original video file not found on media disk.');
            $this->fail(new \RuntimeException('Original video file not found on media disk.'));

            return;
        }

        $localTmp = null;

        try {
            // Stream the original to a local temp file for FFmpeg processing.
            $ext = pathinfo($this->originalPath, PATHINFO_EXTENSION) ?: 'mp4';
            $localTmp = tempnam(sys_get_temp_dir(), 'sidest_vid_').'.'.$ext;

            $stream = $disk->readStream($this->originalPath);
            if (! $stream) {
                throw new \RuntimeException('Failed to open read stream for original video.');
            }

            $dest = fopen($localTmp, 'wb');
            if (! $dest) {
                fclose($stream);
                throw new \RuntimeException('Failed to open local temp file for writing.');
            }

            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);

            $service->processVariants(
                localOriginalPath: $localTmp,
                mediaId: $this->mediaId,
                basePath: $this->basePath,
            );

            SiteMedia::query()
                ->where('id', $this->mediaId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                    'processing_error' => null,
                ]);

            Log::info('ProcessVideoVariantsJob: completed.', ['media_id' => $this->mediaId]);
        } catch (Throwable $e) {
            Log::error('ProcessVideoVariantsJob: processing failed.', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
            if (isset($localTmp) && file_exists($localTmp)) {
                @unlink($localTmp);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        $this->markFailed($e->getMessage());
        $this->cleanupR2Artifacts();
    }

    // Delete the original (and any partial variants) from R2 after terminal failure
    // so orphaned files don't accumulate on the media disk indefinitely.
    private function cleanupR2Artifacts(): void
    {
        try {
            app(VideoVariantService::class)->deleteVariants($this->mediaId, $this->basePath);
        } catch (Throwable $e) {
            Log::warning('ProcessVideoVariantsJob: R2 orphan cleanup failed.', [
                'media_id' => $this->mediaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markFailed(string $reason): void
    {
        $updated = SiteMedia::query()
            ->where('id', $this->mediaId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
                'processing_error' => $reason,
            ]);

        if ($updated === 0) {
            $siteMedia = SiteMedia::withTrashed()->where('id', $this->mediaId)->first();
            Log::info('ProcessVideoVariantsJob: failed-state update skipped.', [
                'media_id' => $this->mediaId,
                'row_exists' => $siteMedia !== null,
                'is_soft_deleted' => $siteMedia?->trashed() ?? false,
            ]);
        }
    }
}
