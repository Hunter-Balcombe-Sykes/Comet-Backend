<?php

namespace App\Jobs;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Concerns\GuardsMediaProcessing;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Media\UnprocessableImageException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates all WebP variants for a freshly uploaded image.
 *
 * State transitions:
 *   pending → processing (job starts)
 *   processing → ready   (all variants created)
 *   processing → failed  (unrecoverable error after all retries)
 */
// V2: Generates WebP variants for uploaded images. Updates SiteMedia state (pending → ready/failed). Queue: images.
class ProcessImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use GuardsMediaProcessing;

    public int $tries = 3;

    // Exponential backoff (JOB-11): transient R2/transcode failures get progressively longer retry gaps.
    public array $backoff = [30, 120, 600];

    public int $timeout = 120;

    /**
     * @param  string  $originalPath  Path of the original on the media disk.
     * @param  string  $imageId  UUID of the SiteMedia row.
     * @param  string  $basePath  Directory prefix for variant storage.
     * @param  string  $siteId  UUID of the owning Site — threaded into log context for Nightwatch correlation.
     */
    public function __construct(
        public readonly string $originalPath,
        public readonly string $imageId,
        public readonly string $basePath,
        public readonly string $siteId = '',
    ) {
        $this->onQueue(config('partna.queues.images', 'images'));
    }

    public function handle(ImageVariantService $service): void
    {
        // In-flight lock keyed on image_id (NOT job id) so a crash-then-retry
        // can still re-acquire after the TTL elapses. The terminal-state guard
        // below covers READY/FAILED but not PROCESSING — without this lock, a
        // redelivered job mid-process would re-enter variant generation in
        // parallel and race on Storage::put writes.
        // TTL is timeout + 60s buffer so the lock auto-expires after a crash.
        //
        // Trade-off (same as ProcessVideoVariantsJob): a worker death leaves the
        // lock held until TTL expiry; retries during that window silently return
        // and Laravel marks the job complete. A separate cleanup pass over rows
        // stuck in PROCESSING past their expected duration is the right place to
        // reconcile this — release-with-delay here would consume `tries` and
        // surface as a spurious `failed()` if the lock holder is still alive.
        $lockKey = "image:processing-lock:{$this->imageId}";
        if (! $this->acquireProcessingLock($lockKey)) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }

        try {
            $this->runHandle($service);
        } finally {
            $this->releaseProcessingLock($lockKey);
        }
    }

    private function runHandle(ImageVariantService $service): void
    {
        Log::info('ProcessImageVariantsJob: starting', [
            'image_id' => $this->imageId,
            'original_path' => $this->originalPath,
        ]);

        $siteMedia = SiteMedia::withTrashed()->find($this->imageId);

        if (! $siteMedia) {
            Log::warning('ProcessImageVariantsJob: SiteMedia row no longer exists, skipping.', [
                'image_id' => $this->imageId,
                'site_id' => $this->siteId,
            ]);

            return;
        }

        if ($siteMedia->trashed()) {
            Log::info('ProcessImageVariantsJob: SiteMedia row is soft-deleted, skipping.', [
                'image_id' => $this->imageId,
            ]);

            return;
        }

        // Guard against redelivered jobs overwriting a terminal state back to processing.
        // At-least-once delivery makes this a certainty rather than a theory on Horizon.
        if ($this->isInTerminalState($siteMedia)) {
            Log::info('ProcessImageVariantsJob: already in terminal state, skipping.', [
                'image_id' => $this->imageId,
                'processing_state' => $siteMedia->processing_state,
            ]);

            return;
        }

        SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);

        $diskName = $service->resolvedDiskName();
        $disk = Storage::disk($diskName);

        if (! $disk->exists($this->originalPath)) {
            $this->markFailed('Original file not found on media disk.');
            $this->fail(new \RuntimeException('Original file not found on media disk.'));

            return;
        }

        $localTmp = null;

        try {
            $localTmp = tempnam(sys_get_temp_dir(), 'sidest_orig_');
            if (! $localTmp) {
                throw new \RuntimeException('Failed to create temporary file.');
            }

            $content = $disk->get($this->originalPath);
            if (! file_put_contents($localTmp, $content)) {
                throw new \RuntimeException('Failed to write original to temp file.');
            }

            $service->processVariants(
                originalTmpPath: $localTmp,
                imageId: $this->imageId,
                basePath: $this->basePath,
                siteId: $this->siteId !== '' ? $this->siteId : (string) ($siteMedia->site_id ?? ''),
            );

            SiteMedia::query()
                ->where('id', $this->imageId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                    'processing_error' => null,
                ]);

            // Purge the sitepage edge cache now that the image is READY (and thus
            // publicly visible). The ready transition above is a query-builder
            // update, so it bypasses SiteMediaObserver — without this purge, a
            // just-processed gallery / content image OR per-integration cover
            // doesn't appear until the s-maxage window lapses. Wrapped so a purge
            // hiccup can't fail the already-successful job; CloudflareCachePurgeJob
            // is ShouldBeUnique per handle, so concurrent variant jobs dedupe.
            try {
                $subdomain = $siteMedia->site?->subdomain;
                if (is_string($subdomain) && $subdomain !== '') {
                    CloudflareCachePurgeJob::dispatch($subdomain);
                }
            } catch (Throwable $e) {
                Log::warning('ProcessImageVariantsJob: cache purge dispatch failed.', [
                    'image_id' => $this->imageId,
                    'message' => $e->getMessage(),
                ]);
            }

            Log::info('ProcessImageVariantsJob: completed.', ['image_id' => $this->imageId]);
        } catch (UnprocessableImageException $e) {
            // Permanent validation failure (e.g. pixel-count guard rejection).
            // Retrying cannot succeed, so mark failed immediately and skip the
            // retry machinery that $tries = 3 would otherwise trigger.
            Log::warning('ProcessImageVariantsJob: unprocessable image, failing without retry.', [
                'image_id' => $this->imageId,
                'site_id' => $this->siteId !== '' ? $this->siteId : (string) ($siteMedia->site_id ?? ''),
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($e->getMessage());
            $this->fail($e);

            return;
        } catch (Throwable $e) {
            Log::error('ProcessImageVariantsJob: variant generation failed.', [
                'image_id' => $this->imageId,
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
            app(ImageVariantService::class)->deleteVariants($this->imageId, $this->originalPath);
        } catch (Throwable $e) {
            Log::warning('ProcessImageVariantsJob: R2 orphan cleanup failed.', [
                'image_id' => $this->imageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markFailed(string $reason): void
    {
        // Fetch before update so we have site_id for cache bust regardless of
        // update outcome (row may be soft-deleted by a concurrent delete).
        $siteMedia = SiteMedia::withTrashed()->where('id', $this->imageId)->first();

        $updated = SiteMedia::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
                'processing_error' => $reason,
            ]);

        if ($updated === 0) {
            Log::info('ProcessImageVariantsJob: failed-state update skipped.', [
                'image_id' => $this->imageId,
                'row_exists' => $siteMedia !== null,
                'is_soft_deleted' => $siteMedia?->trashed() ?? false,
            ]);
        }

    }
}
