<?php

namespace App\Http\Controllers\Api\User\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Uploads\UploadDesignMediaRequest;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Design-layer singleton images: the two brand logos (logo_full, logo_square)
// edited in /account/design, and one cover image per integration (cover_shopify,
// cover_youtube, cover_apple_music, cover_apple_podcast, cover_eventbrite). One
// row per (site, purpose); re-uploading replaces. Free ratio — the pipeline
// resizes preserving aspect, the display frame is the frontend's concern.
// Reuses the gallery image pipeline (MediaUploadService::uploadSingleton → WebP
// variants); the public sitepage reads these via the profile payload's
// siteImages map.
class UserDesignMediaController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(private readonly MediaUploadService $uploadService) {}

    /**
     * GET /api/design-media — the current singleton for every design purpose,
     * keyed by purpose; null when a slot is empty.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $rows = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', SiteMedia::DESIGN_SINGLETON_PURPOSES)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->get()
            ->keyBy('purpose');

        $images = [];
        foreach (SiteMedia::DESIGN_SINGLETON_PURPOSES as $purpose) {
            $media = $rows->get($purpose);
            $images[$purpose] = $media instanceof SiteMedia ? $this->payload($media) : null;
        }

        return $this->success(['images' => $images]);
    }

    /**
     * POST /api/design-media — upload (or replace) one purpose's image.
     *   { purpose: logo_full|logo_square|cover_*, image: <file> }
     */
    public function upload(UploadDesignMediaRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::create gates pending_deletion + verifies site ownership.
        $skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site);
        $this->authorizeForUser($pro, 'create', $skeleton);

        try {
            $media = $this->uploadService->uploadSingleton(
                pro: $pro,
                site: $site,
                file: $request->file('image'),
                purpose: $request->validated('purpose'),
            );
        } catch (OriginalStoreFailedException $e) {
            return $this->error($e->getMessage(), 500);
        }

        return $this->success($this->payload($media), 201);
    }

    /**
     * Build the dashboard payload for one singleton — id (for delete via
     * /api/images/{id}), processing state, and the resolved WebP URL once ready.
     *
     * @return array<string, mixed>
     */
    private function payload(SiteMedia $media): array
    {
        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;
        $variants = $isReady ? $media->variantUrls() : [];

        return [
            'id' => (string) $media->id,
            'purpose' => $media->purpose,
            'processing_state' => $media->processing_state,
            'processing' => in_array($media->processing_state, [
                SiteMedia::PROCESSING_STATE_PENDING,
                SiteMedia::PROCESSING_STATE_PROCESSING,
            ], true),
            'url' => $variants['optimized'] ?? $variants['original'] ?? null,
            'variants' => $variants,
        ];
    }
}
