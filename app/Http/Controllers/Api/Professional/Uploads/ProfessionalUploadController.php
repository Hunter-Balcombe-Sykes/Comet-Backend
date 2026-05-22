<?php

namespace App\Http\Controllers\Api\Professional\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Uploads\ReorderPoolImagesRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\Media\ImageVariantService;
use App\Services\Media\VideoVariantService;
use App\Services\Professional\ConfirmationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

// V2: Media management (images, videos, brand logos, placeholders). Handles upload → processing pipeline → R2 storage.
class ProfessionalUploadController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly VideoVariantService $videoVariant,
    ) {}

    /**
     * Upload an image or video to a pool (gallery or content).
     *
     * Accepts exactly one of: `image` (JPEG/PNG/WebP) or `video` (MP4/MOV/WebM).
     * Returns immediately; processing runs async on the appropriate queue.
     *
     * POST /api/uploads
     *   { pool: gallery|content, image?: <file>, video?: <file>, alt_text?: string }
     */
    public function upload(UploadImageRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::create gates pending_deletion (423) before any media is created.
        // currentSite() already enforces site→professional ownership, so the skeleton's
        // site_id matches the loaded site relation — spoofing defense is a no-op here.
        $skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $pool = $request->validated('pool');
        $isVideo = $request->hasFile('video');

        // Gate video uploads behind the per-tenant feature flag.
        if ($isVideo && ! app(FeatureFlagService::class)->enabled('video_uploads', $pro)) {
            return $this->error('Video uploads are not enabled for your account.', 403);
        }

        $file = $isVideo ? $request->file('video') : $request->file('image');
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
            return $this->error(
                ucfirst($pool)." media limit reached (max {$maxItems}).", 422
            );
        }

        // --- Probe video container before touching DB or storage ---
        // Validates the container with ffprobe while the file is still in PHP's temp dir.
        // Rejects unreadable containers or files with no video stream immediately,
        // before we spend a DB row, R2 storage, or a worker queue slot on them.
        if ($isVideo) {
            try {
                $this->videoVariant->probeAndValidate($file->getRealPath());
            } catch (\RuntimeException $e) {
                return $this->error('Invalid video file: '.$e->getMessage(), 422);
            }
        }

        // --- Create SiteMedia row (with advisory lock for race safety) ---
        $media = DB::transaction(function () use ($site, $pool, $maxItems, $request, $mediaType, $file) {
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
                abort(422, ucfirst($pool)." media limit reached (max {$maxItems}).");
            }

            $maxSort = $siteImages->max('sort_order');

            $media = SiteMedia::create([
                'site_id' => $site->id,
                'pool' => $pool,
                'path' => '',
                'alt_text' => $request->validated('alt_text'),
                'caption' => $this->normaliseOptionalString($request->validated('caption')),
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

        // --- Store original on media disk ---
        $basePath = $isVideo
            ? "videos/{$pro->id}/{$media->id}"
            : "images/{$pro->id}/{$media->id}";

        try {
            $mediaDisk = $this->mediaService->resolvedDiskName();

            Log::info('Storing original to media disk', [
                'media_id' => $media->id,
                'base_path' => $basePath,
                'media_disk' => $mediaDisk,
            ]);

            if ($isVideo) {
                // Stream large video files to avoid loading full content into memory.
                $ext = $this->mediaService->safeExtension($file->getClientOriginalExtension() ?? '', 'mp4');
                $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
                $path = "{$basePath}/original_{$hash}.{$ext}";
                $stream = fopen($file->getRealPath(), 'rb');
                Storage::disk($mediaDisk)->put($path, $stream, 'public');
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $originalPath = $path;
            } else {
                $originalPath = $this->mediaService->storeOriginal($file, $basePath);
            }

            Log::info('Original stored successfully', ['media_id' => $media->id, 'path' => $originalPath]);
        } catch (\Exception $e) {
            Log::error('Failed to store original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            return $this->error('Failed to store file: '.$e->getMessage(), 500);
        }

        $media->update(['path' => $originalPath]);

        // --- Dispatch processing job ---
        if ($isVideo) {
            try {
                $this->dispatchVideoJob($media->id, $originalPath, $basePath);
            } catch (Throwable $e) {
                Log::error('Video upload dispatch failed; rolling back media item.', [
                    'site_id' => $site->id,
                    'media_id' => $media->id,
                    'pool' => $pool,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                $mediaDisk = $this->mediaService->resolvedDiskName();
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
                app(SiteCacheService::class)->invalidateSite($site);

                return $this->error(
                    'Video processing is temporarily unavailable. Please try again.',
                    503
                );
            }
        } else {
            $this->dispatchImageJob($media->id, $originalPath, $basePath);
        }

        app(SiteCacheService::class)->invalidateSite($site);

        // Refresh model state (sync mode may have updated processing_state to 'ready').
        $media->refresh();
        $media->load('mediaVariants');
        $payload = $this->buildMediaPayload($media, includeVariants: true);

        return $this->success($payload, 201);
    }

    /**
     * List media for the authenticated professional.
     *
     * GET /api/images
     *   ?pool=gallery|content          optional pool filter
     *   ?media_type=image|video|all    default: image (backward-compatible)
     *   ?ids[]=uuid,...                optional: return only specific media items (for polling)
     */
    public function index(): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $rawMediaType = strtolower(trim((string) request()->input('media_type', 'image')));
        $mediaTypeFilter = in_array($rawMediaType, ['image', 'video', 'all'], true) ? $rawMediaType : 'image';

        $pool = null;
        if (request()->has('pool')) {
            $candidate = strtolower(trim((string) request()->input('pool')));
            if (in_array($candidate, ['gallery', 'content'], true)) {
                $pool = $candidate;
            }
        }

        $ids = [];
        if (request()->has('ids')) {
            $ids = array_values(array_unique(array_filter(
                (array) request()->input('ids'),
                fn ($id) => is_string($id) && Str::isUuid($id),
            )));
            sort($ids); // canonicalise so different request orderings hit the same key
        }

        // Cache key: gallery views are enumerable and busted by invalidateSite;
        // ?ids[] polling uses a fingerprint key with a 5s TTL only — its
        // cardinality is unbounded so explicit invalidation isn't viable, and
        // the short TTL is enough to surface processing-state transitions
        // within one poll cycle without holding a stampede on the DB.
        if (! empty($ids)) {
            $idsHash = substr(sha1(implode(',', $ids)), 0, 12);
            $cacheKey = \App\Services\Cache\CacheKeyGenerator::siteImagesPolling($site->id, $pool, $mediaTypeFilter, $idsHash);
            $ttl = 5;
        } else {
            $cacheKey = \App\Services\Cache\CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaTypeFilter);
            $ttl = 30;
        }

        $payload = app(\App\Services\Cache\CacheLockService::class)->rememberLocked(
            $cacheKey,
            $ttl,
            fn () => $this->buildIndexPayload($site->id, $pool, $mediaTypeFilter, $ids),
        );

        return $this->success($payload);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array{images: array<int, mixed>, limits: array<string, int>}
     */
    private function buildIndexPayload(string $siteId, ?string $pool, string $mediaTypeFilter, array $ids): array
    {
        $query = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->orderBy('pool')
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if ($mediaTypeFilter !== 'all') {
            $query->where('media_type', $mediaTypeFilter);
        }

        if ($pool !== null) {
            $query->where('pool', $pool);
        }

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $query->with('mediaVariants');

        $items = $query->get()->map(fn (SiteMedia $item) => $this->buildMediaPayload($item, includeVariants: true));

        return [
            'images' => $items->values()->all(),
            'limits' => [
                'gallery' => config('partna.image_pools.gallery.max', 5),
                'content' => config('partna.image_pools.content.max', 5),
            ],
        ];
    }

    /**
     * Reorder active media for a specific pool.
     *
     * POST /api/images/reorder
     *   { pool: gallery|content, media_type?: image|video, ids: [uuid, ...] }
     *
     * Scope is pool + optional media_type:
     *   - `media_type` provided → reorder only items of that type (legacy
     *     behaviour; kept so Content panel's image-only + video-only reorders
     *     still work).
     *   - `media_type` omitted → reorder the *entire pool* across media types.
     *     Required for the unified affiliate gallery grid where photos and
     *     videos share one ordered list of 6 slots.
     */
    public function reorder(ReorderPoolImagesRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::update gates pending_deletion (423) before reordering existing media.
        $skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site);
        $this->authorizeForUser($pro, 'update', $skeleton);

        $pool = $request->validated('pool');
        // null here = mixed-type reorder (unified grid). Don't default to
        // 'image' — that silently drops video ids and corrupts the order.
        $mediaType = $request->validated('media_type');
        $ids = array_values(array_unique($request->validated('ids') ?? []));

        DB::transaction(function () use ($site, $pool, $mediaType, $ids) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $siteImages = SiteMedia::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get(['id', 'pool', 'media_type', 'sort_order', 'is_active']);

            $targetImages = $siteImages
                ->where('is_active', true)
                ->where('pool', $pool)
                ->when($mediaType !== null, fn ($c) => $c->where('media_type', $mediaType))
                ->values();

            if ($targetImages->isEmpty()) {
                abort(422, 'No active media found in this pool.');
            }

            $targetIds = $targetImages->pluck('id')->all();
            $targetSet = array_flip($targetIds);

            foreach ($ids as $id) {
                if (! isset($targetSet[$id])) {
                    abort(422, 'One or more media items are invalid.');
                }
            }

            $remainingTargetIds = array_values(array_diff($targetIds, $ids));
            $reorderedTargetIds = array_merge($ids, $remainingTargetIds);

            $finalIds = $siteImages->pluck('id')->all();
            $targetPositions = [];

            foreach ($siteImages as $index => $image) {
                $matchesPool = $image->is_active && $image->pool === $pool;
                $matchesType = $mediaType === null || $image->media_type === $mediaType;
                if ($matchesPool && $matchesType) {
                    $targetPositions[] = $index;
                }
            }

            foreach ($targetPositions as $index => $position) {
                $finalIds[$position] = $reorderedTargetIds[$index];
            }

            $offset = $siteImages->count() + 1000;

            foreach ($finalIds as $index => $id) {
                SiteMedia::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $index]);
            }

            foreach ($finalIds as $index => $id) {
                SiteMedia::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $index]);
            }
        });

        // Mass-update via the query builder bypasses SiteMediaObserver — so
        // the touch-parent-Site chain we wired into the observer never fires
        // for reorders. Explicit Site touch closes the gap: SiteObserver::
        // saved → CloudflareCachePurgeJob + §28.8 cache key rotation + the
        // local Redis invalidateSite call (which is why we no longer need
        // the explicit invalidateSite() call that lived here before).
        $site->touch();

        return $this->success(['ok' => true]);
    }

    /**
     * Delete a media item and all its artifacts.
     *
     * Images are cleaned up synchronously (small number of files).
     * Videos are cleaned up asynchronously via DeleteMediaArtifactsJob (many HLS segments).
     *
     * DELETE /api/images/{image}
     */
    public function destroy(Request $request, SiteMedia $image): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $image->setRelation('site', $site); // avoid N+1 and lazy-loading violation
        $this->authorizeForUser($pro, 'delete', $image);

        if ($image->media_type === SiteMedia::MEDIA_TYPE_VIDEO) {
            // Dispatch async cleanup – video has many HLS segment files.
            $basePath = is_string($image->path) && trim($image->path) !== ''
                ? dirname($image->path)
                : "videos/{$pro->id}/{$image->id}";

            DeleteMediaArtifactsJob::dispatch($image->id, $basePath, $image->pool);
        } else {
            // Synchronous cleanup for images (only 2–3 variant files).
            $this->mediaService->deleteVariants($image->id, $image->path);
        }

        $image->delete();

        if ($this->shouldRememberConfirmationPreference($request)) {
            app(ConfirmationPreferenceService::class)->enableForProfessional(
                (string) $pro->id,
                ConfirmationPreferenceService::ACTION_DELETE_MEDIA
            );
        }

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['deleted' => true]);
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers */
    /* ------------------------------------------------------------------ */

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

    private function shouldRememberConfirmationPreference(Request $request): bool
    {
        return $request->boolean('remember_confirmation_preference')
            || $request->boolean('always_allow_confirmation')
            || $request->boolean('dont_ask_again');
    }

    /**
     * Trim caption / alt_text-like input and coerce empty strings to null
     * so NULL and "" mean the same thing at rest.
     */
    private function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

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
     * Build a media item payload array suitable for API responses.
     *
     * @param  bool  $includeVariants  Whether to include resolved variant/stream maps.
     * @return array<string, mixed>
     */
    private function buildMediaPayload(SiteMedia $media, bool $includeVariants = false): array
    {
        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;
        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
        $isProcessing = $media->processing_state === SiteMedia::PROCESSING_STATE_PENDING
            || $media->processing_state === SiteMedia::PROCESSING_STATE_PROCESSING;

        $payload = [
            'id' => $media->id,
            'pool' => $media->pool,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'sort_order' => $media->sort_order,
            'media_type' => $media->media_type,
            'processing_state' => $media->processing_state,
            'processing' => $isProcessing, // backward-compat boolean
            'processing_error' => $media->processing_error,
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
        ];

        if ($isVideo) {
            $payload['duration_ms'] = $media->duration_ms;
            $payload['poster'] = null;
        }

        if (! $includeVariants) {
            return $payload;
        }

        if ($isVideo) {
            if ($isReady) {
                $mvList = $media->relationLoaded('mediaVariants')
                    ? $media->mediaVariants
                    : $media->mediaVariants()->get();

                $variants = [];
                $streams = [];
                $poster = null;

                foreach ($mvList as $mv) {
                    if ($mv->artifact_type === 'mp4') {
                        $variants[$mv->variant_key] = $mv->url;
                    } elseif ($mv->artifact_type === 'hls_playlist') {
                        $streams[$mv->variant_key] = $mv->url;
                    } elseif ($mv->artifact_type === 'poster') {
                        $poster = $mv->url;
                    }
                }

                $payload['variants'] = $variants;
                $payload['streams'] = $streams;
                $payload['poster'] = $poster;
            } else {
                $payload['variants'] = [];
                $payload['streams'] = [];
                $payload['poster'] = null;
            }
        } else {
            $payload['variants'] = $isReady ? $media->variantUrls() : [];
        }

        return $payload;
    }
}
