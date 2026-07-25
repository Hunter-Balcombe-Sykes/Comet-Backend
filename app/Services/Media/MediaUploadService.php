<?php

namespace App\Services\Media;

use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessLogoVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Media\Exceptions\InvalidVideoFileException;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\Exceptions\PoolLimitExceededException;
use App\Services\Media\Exceptions\SingletonConflictException;
use App\Services\Media\Exceptions\VideoDispatchFailedException;
use App\Support\Concerns\NormalisesOptionalString;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *   - SingletonConflictException     → 409 (uploadSingleton only — lost a concurrent-replace race)
 */
class MediaUploadService
{
    use NormalisesOptionalString;

    public function __construct(
        private readonly ImageVariantService $imageService,
        private readonly VideoVariantService $videoVariant,
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

        // Refresh — sync mode may have already advanced processing_state to 'ready'.
        $media->refresh();
        $media->load('mediaVariants');

        return $media;
    }

    /**
     * Upload a purpose-scoped singleton design image (a brand logo or an
     * integration cover). Images only — no pool count limit: each purpose holds
     * exactly one row per site (DB partial unique indexes + the app-side replace
     * here). Re-uploading replaces: the existing row of that purpose is
     * soft-deleted and its variant + original files purged, then the new row
     * runs the standard image pipeline (→ WebP variants). Free ratio — the
     * pipeline resizes preserving aspect; the display frame is the caller's job.
     *
     * @return SiteMedia fresh, with mediaVariants loaded
     */
    public function uploadSingleton(
        User $pro,
        Site $site,
        UploadedFile $file,
        string $purpose,
    ): SiteMedia {
        Log::info('Singleton media upload started', [
            'pro_id' => $pro->id,
            'site_id' => $site->id,
            'purpose' => $purpose,
            'file_size_kb' => $file->getSize() / 1024,
        ]);

        // Replace any existing singleton of this purpose first — frees the
        // unique slot and purges its files before the new row is created.
        $this->purgeExistingSingleton($site, $purpose);

        $media = $this->createSingletonRowOrConflict($site, $purpose, $file);

        $basePath = "images/{$pro->id}/{$media->id}";

        try {
            // SVG logo originals bypass ImageVariantService (raster-only by
            // design): the logo-processor container rasterizes + vectorizes
            // SVG input itself (verified live 2026-07-23, signup-v2 B0 gate),
            // so the original only needs to land on the media disk for
            // ProcessLogoVariantsJob to stream out. Everything else — raster
            // logos, covers, and any SVG outside the enabled logo pipeline —
            // keeps the storeOriginal path (which rejects SVG, unchanged).
            $originalPath = $this->isSvgLogoUpload($file, $purpose)
                ? $this->storeSvgOriginal($file, $basePath)
                : $this->imageService->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('Failed to store singleton original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            throw new OriginalStoreFailedException('Failed to store file: '.$e->getMessage(), 0, $e);
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchSingletonProcessing($media->id, $originalPath, $basePath, $site, $purpose);

        $media->refresh();
        $media->load('mediaVariants');

        return $media;
    }

    /**
     * Soft-delete + purge files for the existing design singleton of this purpose, if any.
     *
     * ⚠️ KNOWN UNFIXED RACE (pre-existing, tracked in
     * docs/superpowers/plans/2026-07-20-singleton-upload-race-PROMPT.md).
     * This runs OUTSIDE createSingletonRow()'s advisory lock. That is deliberate —
     * the lock is site-wide and shared with the gallery path, and this method does
     * remote R2 deletes, so holding the lock across it would serialize every upload
     * to the site behind network I/O. The cost is a window: if request B's purge
     * lands AFTER request A's INSERT commits but BEFORE A's final
     * update(['path' => ...]), B soft-deletes A's row and A's update still succeeds
     * — Eloquent's save() uses newModelQuery(), which carries no global scopes, so
     * SoftDeletingScope does not block it. A ends up with a soft-deleted row holding
     * a real R2 path and processed variants, invisible to every normal query, while
     * the API returns 201. Silent data loss, reclaimed only by the 30-day
     * PurgeSoftDeleted sweep. QUEUE_CONNECTION=sync widens the window, since image
     * processing runs inline inside it.
     *
     * The unique-violation half of this race IS handled (see
     * createSingletonRowOrConflict). This half needs an optimistic-concurrency token
     * or a redesign — do not "simplify" this method into the lock without reading
     * that plan first.
     */
    private function purgeExistingSingleton(Site $site, string $purpose): void
    {
        $existing = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->whereNull('deleted_at')
            ->get();

        foreach ($existing as $media) {
            $this->imageService->deleteVariants($media->id, $media->path);
            $media->delete();
        }
    }

    /**
     * SVG uploads are accepted ONLY for logo singletons with the logo-removal
     * pipeline on — the processor container handles rasterization, so a
     * pipeline-off SVG would have no path to renderable variants and must keep
     * rejecting exactly as before (via storeOriginal's raster-only MIME gate).
     */
    private function isSvgLogoUpload(UploadedFile $file, string $purpose): bool
    {
        return $file->getMimeType() === 'image/svg+xml'
            && in_array($purpose, [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE], true)
            && (bool) config('partna.logo_removal.enabled', false);
    }

    /**
     * Store an SVG logo original to the media disk — mirrors
     * ImageVariantService::storeOriginal()'s naming/visibility contract
     * (hash-named original_*, 'private' — originals are a re-processing
     * source, the public deliverables are the job's variants) without its
     * raster-only MIME gate. The caller's LogoAutoGrabber::svgIsSafe() pass
     * is the ingest sanitizer; downstream the SVG is only ever served via
     * <img> (see ProcessLogoVariantsJob::storeSvgVariant), so script-in-SVG
     * stays inert end to end.
     */
    private function storeSvgOriginal(UploadedFile $file, string $basePath): string
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new \RuntimeException('Uploaded file is not resolvable to a real path.');
        }

        $hash = substr(hash_file('sha256', $realPath), 0, 16);
        $path = "{$basePath}/original_{$hash}.svg";

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Failed to open uploaded file for streaming.');
        }

        try {
            Storage::disk($this->imageService->resolvedDiskName())->put($path, $stream, 'private');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * Wraps createSingletonRow(), converting a lost concurrent-replace race into a
     * typed 409 instead of an uncaught QueryException.
     *
     * purgeExistingSingleton() (above) runs with NO lock, before
     * createSingletonRow() ever takes the per-site advisory lock. Two
     * concurrent uploads for the same (site, purpose) can therefore both pass
     * the purge before either commits its INSERT — the DB's partial unique
     * index (site_media_design_singleton_purpose_uq) then rejects whichever
     * INSERT lands second. That's expected under contention, not corruption:
     * the winner's row stands as the singleton. Nothing has touched storage
     * yet at this point — storeOriginal() only runs after this method returns
     * successfully — so the loser leaves no orphaned file behind; it simply
     * never wrote one. (We deliberately do NOT move the purge inside the
     * advisory-locked transaction to close the window instead: that lock is
     * site-wide, shared with the plain gallery upload path, and purging calls
     * out to storage (deleteVariants) — holding the lock across that I/O would
     * serialise every upload for the site behind a network round-trip, a worse
     * trade than the narrow race this catch already resolves.)
     */
    private function createSingletonRowOrConflict(Site $site, string $purpose, UploadedFile $file): SiteMedia
    {
        try {
            return $this->createSingletonRow($site, $purpose, $file);
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('Singleton upload lost a concurrent-replace race', [
                'site_id' => $site->id,
                'purpose' => $purpose,
            ]);

            throw new SingletonConflictException(
                'This image was just replaced by another upload. Please try again.',
                0,
                $e
            );
        }
    }

    private function createSingletonRow(Site $site, string $purpose, UploadedFile $file): SiteMedia
    {
        return DB::transaction(function () use ($site, $purpose, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            // A global (site_id, sort_order) unique index spans all pools, so
            // take the next free slot just like createMediaRow does — design
            // singletons have no ordering of their own, but must not collide.
            // The advisory lock above already serialises writes for this site,
            // so a plain max() is race-safe — and Postgres rejects FOR UPDATE
            // with an aggregate, so we must NOT lockForUpdate() on a max().
            $maxSort = SiteMedia::query()
                ->where('site_id', $site->id)
                ->max('sort_order');

            $media = new SiteMedia([
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => $purpose,
                'path' => '',
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
            $media->site()->associate($site);
            $media->save();

            return $media;
        });
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

            $media = new SiteMedia([
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
            $media->site()->associate($site);
            $media->save();

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
            if ($stream === false) {
                throw new \RuntimeException('Failed to open video file for streaming.');
            }
            try {
                // 'private' — original is a re-processing source only; the public
                // deliverable is the HLS/MP4/poster variants. Matches the image
                // path in ImageVariantService::storeOriginal().
                Storage::disk($mediaDisk)->put($path, $stream, 'private');
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $originalPath = $path;
        } else {
            $originalPath = $this->imageService->storeOriginal($file, $basePath);
        }

        Log::info('media.original_stored', ['media_id' => $mediaId, 'disk' => $mediaDisk, 'path' => $originalPath]);

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
    }

    /**
     * Dispatch a media-processing job best-effort with a synchronous fallback,
     * and NEVER throw — a failed dispatch leaves the row PENDING and surfaces via
     * the processing_state poll. Runs inline (dispatchSync) in local/testing or
     * when queue.default is sync; otherwise queues, falling back to dispatchSync
     * if the queue push itself throws. $args is the job's named-constructor arg
     * map, spread as named arguments (PHP 8.1+) so each job's distinct signature
     * still binds; $label only tunes the log wording.
     */
    private function dispatchWithSyncFallback(string $jobClass, array $args, string $label): void
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueConnection === 'sync';

        $imageId = $args['imageId'] ?? null;

        if ($processInline) {
            try {
                $jobClass::dispatchSync(...$args);
            } catch (Throwable $e) {
                Log::error("Inline {$label} processing failed.", [
                    'image_id' => $imageId, 'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        try {
            $jobClass::dispatch(...$args);
        } catch (Throwable $e) {
            Log::error("Queue dispatch failed for {$label}; trying synchronous fallback.", [
                'image_id' => $imageId, 'error' => $e->getMessage(),
            ]);
            try {
                $jobClass::dispatchSync(...$args);
            } catch (Throwable $syncError) {
                report($syncError);
                Log::error("Synchronous {$label} processing also failed.", [
                    'image_id' => $imageId, 'error' => $syncError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Image dispatch is best-effort and NEVER throws — failed dispatch leaves
     * the media row in PENDING and surfaces via the processing_state poll. This
     * asymmetry with video is deliberate: image jobs are tiny and retry-safe,
     * video jobs are expensive and the user is better served by a 503 + rollback.
     */
    private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
    {
        $this->dispatchWithSyncFallback(ProcessImageVariantsJob::class, [
            'originalPath' => $originalPath,
            'imageId' => $imageId,
            'basePath' => $basePath,
        ], 'image variant');
    }

    /**
     * Route singleton processing: design-pool LOGOS (logo_full / logo_square) run
     * through the background-removal + vectorization pipeline when it's enabled;
     * everything else (integration covers) keeps the standard WebP path.
     */
    private function dispatchSingletonProcessing(
        string $mediaId,
        string $originalPath,
        string $basePath,
        Site $site,
        string $purpose,
    ): void {
        $isLogo = in_array($purpose, [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE], true);

        if ($isLogo && (bool) config('partna.logo_removal.enabled', false)) {
            $this->dispatchLogoJob($mediaId, $originalPath, $basePath, (string) $site->id);

            return;
        }

        $this->dispatchImageJob($mediaId, $originalPath, $basePath);
    }

    /**
     * Logo dispatch — same best-effort contract as dispatchImageJob (inline in
     * local/testing, sync fallback on queue failure, never throws). Adds the
     * siteId arg the background-removal logo pipeline needs.
     */
    private function dispatchLogoJob(string $mediaId, string $originalPath, string $basePath, string $siteId): void
    {
        $this->dispatchWithSyncFallback(ProcessLogoVariantsJob::class, [
            'originalPath' => $originalPath,
            'imageId' => $mediaId,
            'basePath' => $basePath,
            'siteId' => $siteId,
        ], 'logo');
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
}
