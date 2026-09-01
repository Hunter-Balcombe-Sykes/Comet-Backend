<?php

namespace App\Http\Controllers\Api\User\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Uploads\ReorderPoolImagesRequest;
use App\Http\Requests\Api\User\Uploads\UploadImageRequest;
use App\Http\Resources\SiteMediaResource;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\FeatureFlags\FeatureFlagService;
use App\Services\Media\Exceptions\InvalidVideoFileException;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\Exceptions\PoolLimitExceededException;
use App\Services\Media\Exceptions\VideoDispatchFailedException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Media management (images, videos). HTTP layer for the upload → processing
// pipeline. All upload orchestration lives in MediaUploadService; this controller
// handles validation, authorization, response shaping, and the list/reorder/delete
// actions that don't share the upload pipeline.
class UserUploadController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly MediaUploadService $uploadService,
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
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::create gates pending_deletion (423) before any media is created.
        // currentSite() already enforces site→professional ownership, so the skeleton's
        // site_id matches the loaded site relation — spoofing defense is a no-op here.
        $skeleton = (new SiteMedia)->site()->associate($site);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $pool = $request->validated('pool');
        $isVideo = $request->hasFile('video');

        // Gate video uploads behind the per-tenant feature flag (HTTP-layer concern —
        // this is an authorization decision, not part of the upload pipeline itself).
        if ($isVideo && ! app(FeatureFlagService::class)->enabled('video_uploads', $pro)) {
            return $this->error('Video uploads are not enabled for your account.', 403);
        }

        $file = $isVideo ? $request->file('video') : $request->file('image');

        try {
            $media = $this->uploadService->upload(
                pro: $pro,
                site: $site,
                file: $file,
                pool: $pool,
                isVideo: $isVideo,
                altText: $request->validated('alt_text'),
                caption: $request->validated('caption'),
            );
        } catch (PoolLimitExceededException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (InvalidVideoFileException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (OriginalStoreFailedException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (VideoDispatchFailedException $e) {
            return $this->error($e->getMessage(), 503);
        }

        return $this->success((new SiteMediaResource($media, includeVariants: true))->toArray(request()), 201);
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
        $pro = $this->currentUser(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $rawMediaType = strtolower(trim((string) request()->input('media_type', 'image')));
        $mediaTypeFilter = in_array($rawMediaType, SiteMedia::MEDIA_TYPE_FILTERS, true) ? $rawMediaType : 'image';

        $pool = null;
        if (request()->has('pool')) {
            $candidate = strtolower(trim((string) request()->input('pool')));
            if (in_array($candidate, SiteMedia::GALLERY_POOLS, true)) {
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
            $cacheKey = CacheKeyGenerator::siteImagesPolling($site->id, $pool, $mediaTypeFilter, $idsHash);
            $ttl = 5;
        } else {
            $cacheKey = CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaTypeFilter);
            $ttl = 30;
        }

        $payload = app(CacheLockService::class)->rememberLocked(
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

        $items = $query->get()->map(fn (SiteMedia $item) => (new SiteMediaResource($item, includeVariants: true))->toArray(request()));

        return [
            'images' => $items->values()->all(),
            'limits' => [
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
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::update gates pending_deletion (423) before reordering existing media.
        $skeleton = (new SiteMedia)->site()->associate($site);
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
        $pro = $this->currentUser($request);
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

        return $this->success(['deleted' => true]);
    }
}
