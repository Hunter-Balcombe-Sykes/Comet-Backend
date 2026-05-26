<?php

namespace App\Services\Media;

use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\Exceptions\InvalidVideoFileException;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\Exceptions\PoolLimitExceededException;
use App\Services\Media\Exceptions\VideoDispatchFailedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orchestrates the upload → DB row → R2 store → processing dispatch pipeline.
 *
 * The controller stays thin: HTTP validation, authorization, response shaping.
 * This service owns:
 *   - pool-limit check (pre-tx fast-fail + in-tx authoritative check under advisory lock)
 *   - video container probe (pre-DB so a bad file never spends a row or a queue slot)
 *   - transactional SiteMedia row creation with pg_advisory_xact_lock for race safety
 *   - storing the original to the media disk
 *   - dispatching the variant-processing job (image vs video have different
 *     failure-mode contracts — see dispatchImageJob / dispatchVideoJob below)
 *   - rolling back DB row + storage when video dispatch fails
 *   - invalidating the site cache so the new media surfaces immediately
 *
 * Failure-mode contract (caller maps to HTTP):
 *   - PoolLimitExceededException     → 422
 *   - InvalidVideoFileException      → 422
 *   - OriginalStoreFailedException   → 500
 *   - VideoDispatchFailedException   → 503 (DB row + original file already rolled back)
 */
class MediaUploadService
{
    public function __construct(
        private readonly ImageVariantService $imageService,
        private readonly VideoVariantService $videoVariant,
        private readonly SiteCacheService $siteCache,
    ) {}

    /**
     * Upload one image or video into a site's pool.
     *
     * @return SiteMedia fresh, with mediaVariants relation loaded
     */
    public function upload(
        User $pro,
        Site $site,
        UploadedFile $file,
        string $pool,
        bool $isVideo,
        ?string $altText,
        ?string $caption,
    ): SiteMedia {
        $mediaType = $isVideo ? SiteMedia::MEDIA_TYPE_VIDEO : SiteMedia::MEDIA_TYPE_IMAGE;

        Log::info('Media upload started', [
            'pro_id' => $pro->id,
            'site_id' => $site->id,
            'pool' => $pool,
            'media_type' => $mediaType,
            'file_size_kb' => $file->getSize() / 1024,
        ]);

        // Pool limit is shared across media types (images + videos count toward the same cap).
        // Failed rows are terminal — they never stored a file and occupy no usable slot.
        $maxItems = (int) config("partna.image_pools.{$pool}.max", 5);

        $activeCount = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', $pool)
            ->where('is_active', true)
            ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
            ->count();

        if ($activeCount >= $maxItems) {
            throw new PoolLimitExceededException(
                ucfirst($pool)." media limit reached (max {$maxItems})."
            );
        }

        // Probe video container before touching DB or storage. Validates with
        // ffprobe while the file is still in PHP's temp dir, so we never spend
        // a DB row, R2 storage, or a worker queue slot on an unreadable container.
        if ($isVideo) {
            try {
                $this->videoVariant->probeAndValidate($file->getRealPath());
            } catch (\RuntimeException $e) {
                throw new InvalidVideoFileException('Invalid video file: '.$e->getMessage(), 0, $e);
            }
        }

        $media = $this->createMediaRow($site, $pool, $maxItems, $mediaType, $file, $altText, $caption);

        $basePath = $isVideo
            ? "videos/{$pro->id}/{$media->id}"
            : "images/{$pro->id}/{$media->id}";

        try {
            $originalPath = $this->storeOriginal($file, $basePath, $media->id, $isVideo);
        } catch (Throwable $e) {
            Log::error('Failed to store original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            throw new OriginalStoreFailedException('Failed to store file: '.$e->getMessage(), 0, $e);
        }

        $media->update(['path' => $originalPath]);

        if ($isVideo) {
            try {
                $this->dispatchVideoJob($media->id, $originalPath, $basePath);
            } catch (Throwable $e) {
                $this->rollbackFailedVideoDispatch($media, $originalPath, $site, $pool, $e);

                throw new VideoDispatchFailedException(
                    'Video processing is temporarily unavailable. Please try again.',
                    0,
                    $e
                );
            }
        } else {
            $this->dispatchImageJob($media->id, $originalPath, $basePath);
        }

        $this->siteCache->invalidateSite($site);

        // Refresh — sync mode may have already advanced processing_state to 'ready'.
        $media->refresh();
        $media->load('mediaVariants');

        return $media;
    }

    private function createMediaRow(
        Site $site,
        string $pool,
        int $maxItems,
        string $mediaType,
        UploadedFile $file,
        ?string $altText,
        ?string $caption,
    ): SiteMedia {
        return DB::transaction(function () use ($site, $pool, $maxItems, $mediaType, $file, $altText, $caption) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $siteImages = SiteMedia::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->get(['id', 'pool', 'sort_order', 'is_active', 'processing_state']);

            $activeCount = $siteImages
                ->where('pool', $pool)
                ->where('is_active', true)
                ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
                ->count();

            if ($activeCount >= $maxItems) {
                // Authoritative cap check under the advisory lock. Throwing out
                // of the closure rolls the transaction back automatically.
                throw new PoolLimitExceededException(
                    ucfirst($pool)." media limit reached (max {$maxItems})."
                );
            }

            $maxSort = $siteImages->max('sort_order');

            $media = SiteMedia::create([
                'site_id' => $site->id,
                'pool' => $pool,
                'path' => '',
                'alt_text' => $altText,
                'caption' => $this->normaliseOptionalString($caption),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => $mediaType,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);

            Log::info('SiteMedia row created', ['media_id' => $media->id, 'media_type' => $mediaType]);

            return $media;
        });
    }

    private function storeOriginal(UploadedFile $file, string $basePath, string $mediaId, bool $isVideo): string
    {
        $mediaDisk = $this->imageService->resolvedDiskName();

        Log::info('Storing original to media disk', [
            'media_id' => $mediaId,
            'base_path' => $basePath,
            'media_disk' => $mediaDisk,
        ]);

        if ($isVideo) {
            // Stream large video files to avoid loading full content into memory.
            $ext = $this->imageService->safeExtension($file->getClientOriginalExtension() ?? '', 'mp4');
            $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
            $path = "{$basePath}/original_{$hash}.{$ext}";
            $stream = fopen($file->getRealPath(), 'rb');
            // 'private' — original is a re-processing source only; the public
            // deliverable is the HLS/MP4/poster variants. Matches the image
            // path in ImageVariantService::storeOriginal().
            Storage::disk($mediaDisk)->put($path, $stream, 'private');
            if (is_resource($stream)) {
                fclose($stream);
            }
            $originalPath = $path;
        } else {
            $originalPath = $this->imageService->storeOriginal($file, $basePath);
        }

        Log::info('Original stored successfully', ['media_id' => $mediaId, 'path' => $originalPath]);

        return $originalPath;
    }

    private function rollbackFailedVideoDispatch(
        SiteMedia $media,
        string $originalPath,
        Site $site,
        string $pool,
        Throwable $e,
    ): void {
        Log::error('Video upload dispatch failed; rolling back media item.', [
            'site_id' => $site->id,
            'media_id' => $media->id,
            'pool' => $pool,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        $mediaDisk = $this->imageService->resolvedDiskName();
        try {
            Storage::disk($mediaDisk)->delete($originalPath);
        } catch (Throwable $cleanupError) {
            Log::warning('Failed to cleanup original video after dispatch failure.', [
                'site_id' => $site->id,
                'media_id' => $media->id,
                'pool' => $pool,
                'path' => $originalPath,
                'media_disk' => $mediaDisk,
                'error' => $cleanupError->getMessage(),
            ]);
        }

        $media->delete();
        $this->siteCache->invalidateSite($site);
    }

    /**
     * Image dispatch is best-effort and NEVER throws — failed dispatch leaves
     * the media row in PENDING and surfaces via the processing_state poll. This
     * asymmetry with video is deliberate: image jobs are tiny and retry-safe,
     * video jobs are expensive and the user is better served by a 503 + rollback.
     */
    private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueConnection === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('Inline image variant processing failed.', [
                    'image_id' => $imageId, 'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('Queue dispatch failed for image; trying synchronous fallback.', [
                'image_id' => $imageId, 'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $syncError) {
                Log::error('Synchronous image variant processing also failed.', [
                    'image_id' => $imageId, 'error' => $syncError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Video dispatch THROWS on failure — caller rolls back DB + storage and returns 503.
     */
    private function dispatchVideoJob(string $mediaId, string $originalPath, string $basePath): void
    {
        $queueDefault = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueDefault === 'sync';

        if ($processInline) {
            ProcessVideoVariantsJob::dispatchSync(
                mediaId: $mediaId,
                originalPath: $originalPath,
                basePath: $basePath,
            );

            return;
        }

        // Connection and queue are already set in the job constructor.
        // Do not override via PendingDispatch to avoid redundant/conflicting config reads.
        ProcessVideoVariantsJob::dispatch(
            mediaId: $mediaId,
            originalPath: $originalPath,
            basePath: $basePath,
        );
    }

    /**
     * Trim caption / alt_text-like input and coerce empty strings to null so
     * NULL and "" mean the same thing at rest.
     */
    private function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
