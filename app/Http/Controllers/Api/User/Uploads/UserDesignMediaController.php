<?php

namespace App\Http\Controllers\Api\User\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Uploads\UploadDesignMediaRequest;
use App\Http\Resources\DesignMediaResource;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\Exceptions\SingletonConflictException;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Design-layer singleton images: the two brand logos (logo_full, logo_square)
// edited in /account/design, the brand placeholder image (placeholder), and one
// cover image per cover-capable platform
// (cover_youtube, cover_apple_music, cover_apple_podcast, cover_eventbrite —
// registry-derived, see SiteMedia::designSingletonPurposes()). One row per
// (site, purpose); re-uploading replaces. Free ratio — the pipeline resizes
// preserving aspect, the display frame is the frontend's concern.
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
            ->whereIn('purpose', SiteMedia::designSingletonPurposes())
            ->where('is_active', true)
            ->with('mediaVariants')
            ->get()
            ->keyBy('purpose');

        $images = [];
        foreach (SiteMedia::designSingletonPurposes() as $purpose) {
            $media = $rows->get($purpose);
            $images[$purpose] = $media instanceof SiteMedia ? (new DesignMediaResource($media))->toArray(request()) : null;
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
        $skeleton = (new SiteMedia)->site()->associate($site);
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
        } catch (SingletonConflictException $e) {
            // Lost a concurrent-replace race for this purpose — another
            // upload for the same slot won. Nothing of this request's was
            // ever written to storage; the client can just resubmit.
            return $this->error($e->getMessage(), 409, [], ['code' => 'SINGLETON_UPLOAD_CONFLICT']);
        }

        return $this->success((new DesignMediaResource($media))->toArray(request()), 201);
    }
}
