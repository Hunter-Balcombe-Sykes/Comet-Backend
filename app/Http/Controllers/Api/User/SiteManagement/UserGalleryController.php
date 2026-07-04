<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\ImageGallery\ReorderGalleryImageRequest;
use App\Http\Requests\Api\User\ImageGallery\UpdateGalleryImageRequest;
use App\Http\Resources\GalleryImageResource;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Site\ReorderService;
use App\Services\User\ConfirmationPreferenceService;
use App\Support\Concerns\NormalisesOptionalString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Gallery image management — listing, reordering, and deletion with variant cleanup.
class UserGalleryController extends ApiController
{
    use NormalisesOptionalString;
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly ReorderService $reorderService,
    ) {}

    /**
     * List gallery-pool images for the current site, eager-loading variants.
     */
    public function index(): JsonResponse
    {
        $pro = $this->currentUser(request());
        $site = $this->currentSite($pro);

        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        // #P2-34: GalleryImageResource is the explicit allowlist; resolve()
        // collapses the collection to plain arrays so the response shape
        // stays a flat list under 'images' (gallery is bounded, not paginated).
        return $this->success([
            'images' => GalleryImageResource::collection($images)->resolve(),
        ]);
    }

    public function reorder(ReorderGalleryImageRequest $request): JsonResponse
    {
        $pro = $this->currentUser(request());
        $site = $this->currentSite($pro);

        // SitePolicy::update gates pending_deletion (423) before reordering gallery items.
        $skeleton = (new SiteMedia)->site()->associate($site);
        $this->authorizeForUser($pro, 'update', $skeleton);

        // Mass `update()` bypasses Eloquent events, so SiteMediaObserver never
        // touches the site. Touch explicitly in afterCommit to fire SiteObserver —
        // Redis invalidation + Cloudflare edge purge + cache warm.
        $this->reorderService->reorder(
            $request->input('ids', []),
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('is_active', true),
            "site-images:{$site->id}",
            fn () => $site->touch(),
        );

        return $this->success(['ok' => true]);
    }

    /**
     * Update caption and/or alt_text on a gallery image. Trims whitespace;
     * an empty/whitespace-only value is stored as NULL. Invalidates the
     * public-site cache only when a field actually changed — avoids cache
     * churn on autosave-on-blur edits that don't mutate anything.
     */
    public function update(UpdateGalleryImageRequest $request, SiteMedia $image): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $image->setRelation('site', $site); // avoid N+1 and lazy-loading violation
        $this->authorizeForUser($pro, 'update', $image);

        $data = $request->validated();
        $update = [];

        if (array_key_exists('caption', $data)) {
            $update['caption'] = $this->normaliseOptionalString($data['caption']);
        }

        if (array_key_exists('alt_text', $data)) {
            $update['alt_text'] = $this->normaliseOptionalString($data['alt_text']);
        }

        if (! empty($update)) {
            $image->fill($update);
            if ($image->isDirty(['caption', 'alt_text'])) {
                $image->save();
            }
        }

        return $this->success([
            'image' => [
                'id' => $image->id,
                'alt_text' => $image->alt_text,
                'caption' => $image->caption,
            ],
        ]);
    }

    /**
     * Soft-delete the gallery image and clean up its variants from storage.
     */
    public function destroy(Request $request, SiteMedia $image): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $image->setRelation('site', $site); // avoid N+1 and lazy-loading violation
        $this->authorizeForUser($pro, 'delete', $image);

        $this->mediaService->deleteVariants($image->id, $image->path);
        $image->delete();

        $confirmationService = app(ConfirmationPreferenceService::class);
        if ($confirmationService->shouldRemember($request)) {
            $confirmationService->enableForProfessional(
                (string) $pro->id,
                ConfirmationPreferenceService::ACTION_DELETE_MEDIA
            );
        }

        return $this->success(['deleted' => true]);
    }
}
