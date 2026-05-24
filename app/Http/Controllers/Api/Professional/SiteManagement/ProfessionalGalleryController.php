<?php

namespace App\Http\Controllers\Api\Professional\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ImageGallery\ReorderGalleryImageRequest;
use App\Http\Requests\Api\Professional\ImageGallery\UpdateGalleryImageRequest;
use App\Http\Resources\GalleryImageResource;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Professional\ConfirmationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Gallery image management — listing, reordering, and deletion with variant cleanup.
class ProfessionalGalleryController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    /**
     * List gallery-pool images for the current site, eager-loading variants.
     */
    public function index(): JsonResponse
    {
        $pro = $this->currentProfessional(request());
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

    /**
     * Gallery uploads are now handled by POST /api/uploads with pool=gallery.
     *
     * @deprecated Use POST /api/uploads with pool=gallery instead.
     */
    public function store(): JsonResponse
    {
        return $this->error(
            'Gallery image creation has moved to POST /api/uploads with pool=gallery. '
            .'Upload the image file directly instead of passing bucket/path.',
            410,
        );
    }

    public function reorder(ReorderGalleryImageRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $site = $this->currentSite($pro);

        // SitePolicy::update gates pending_deletion (423) before reordering gallery items.
        $skeleton = (new SiteMedia(['site_id' => $site->id]))->setRelation('site', $site);
        $this->authorizeForUser($pro, 'update', $skeleton);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($site, $ids) {
            $allIds = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more gallery images are invalid.');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                SiteMedia::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        app(SiteCacheService::class)->invalidateSite($site);

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
        $pro = $this->currentProfessional($request);
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

        $changed = false;
        if (! empty($update)) {
            $image->fill($update);
            if ($image->isDirty(['caption', 'alt_text'])) {
                $image->save();
                $changed = true;
            }
        }

        if ($changed) {
            app(SiteCacheService::class)->invalidateSite($site);
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
     * Trim, and coerce empty strings to null so NULL and "" mean the same.
     */
    private function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Soft-delete the gallery image and clean up its variants from storage.
     */
    public function destroy(Request $request, SiteMedia $image): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $site = $this->currentSite($pro);
        $image->setRelation('site', $site); // avoid N+1 and lazy-loading violation
        $this->authorizeForUser($pro, 'delete', $image);

        $this->mediaService->deleteVariants($image->id, $image->path);
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

    private function shouldRememberConfirmationPreference(Request $request): bool
    {
        return $request->boolean('remember_confirmation_preference')
            || $request->boolean('always_allow_confirmation')
            || $request->boolean('dont_ask_again');
    }
}
