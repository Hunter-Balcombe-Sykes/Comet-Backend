<?php

namespace App\Http\Controllers\Api\User\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Content\UploadContentImageRequest;
use App\Http\Resources\Content\ContentLibraryUploadResource;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Media\Exceptions\OriginalStoreFailedException;
use App\Services\Media\Exceptions\PoolLimitExceededException;
use App\Services\Media\ImageVariantService;
use App\Services\Media\MediaUploadService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// HTTP layer for the Content dashboard surface — the Library (browse + upload)
// the owner picks imagery from. Sources: manual uploads (content media pool),
// Google Business photos and Instagram post images (both referenced, never
// copied). Media upload reuses MediaUploadService (the gallery multi-add path).
//
// Slice 7 unit E: the ordered "Content Selection" (≤15 picks) and its four
// verbs retired with site.content_selection — curation is pool:media pins now.
// Authorization rides a SiteMedia skeleton bound to the current site (SitePolicy
// resolves SiteMedia ownership through the preloaded site relation).
class ContentController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly MediaUploadService $uploadService,
        private readonly ImageVariantService $imageService,
    ) {}

    /**
     * GET /content/library — everything the user can pick from.
     *
     * @return JsonResponse { uploads: [...], googlePhotos: [{ ref, url }] }
     */
    public function library(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'view', $this->skeleton($site));

        $uploads = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_CONTENT)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->with('mediaVariants')
            ->get()
            ->map(fn (SiteMedia $m) => (new ContentLibraryUploadResource($m))->toArray($request))
            ->values()
            ->all();

        return $this->success([
            'uploads' => $uploads,
            'googlePhotos' => $this->googlePhotoOptions($pro),
            'instagramPhotos' => $this->instagramPhotoOptions($pro),
        ]);
    }

    /**
     * POST /content/uploads — add one image to the content media pool. Reuses
     * the gallery upload pipeline (MediaUploadService::upload → WebP variants).
     * Images only; the request enforces the mime allowlist.
     */
    public function storeUpload(UploadContentImageRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        // SitePolicy::create gates pending_deletion + verifies site ownership.
        $this->authorizeForUser($pro, 'create', $this->skeleton($site));

        try {
            $media = $this->uploadService->upload(
                pro: $pro,
                site: $site,
                file: $request->file('image'),
                pool: SiteMedia::POOL_CONTENT,
                isVideo: false,
                altText: $request->validated('alt_text'),
                caption: $request->validated('caption'),
            );
        } catch (PoolLimitExceededException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (OriginalStoreFailedException $e) {
            return $this->error($e->getMessage(), 500);
        }

        return $this->success((new ContentLibraryUploadResource($media))->toArray($request), 201);
    }

    /**
     * DELETE /content/uploads/{id} — soft-delete a content upload.
     */
    public function destroyUpload(Request $request, SiteMedia $upload): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SEC-10: ownership of $upload itself is enforced via SiteMedia's own
        // SitePolicy (not an inline site_id comparison) — denyAsNotFound() gives
        // the same 404 the 403-vs-404 standard requires for a wrong-site upload.
        // Pool stays a separate inline check: it's a business rule (which media
        // pool this endpoint operates on), not an ownership concern.
        $upload->setRelation('site', $site);
        $this->authorizeForUser($pro, 'delete', $upload);

        if ($upload->pool !== SiteMedia::POOL_CONTENT) {
            return $this->error('Not found.', 404);
        }

        // Synchronous file cleanup (images have 2–3 variant files).
        $this->imageService->deleteVariants($upload->id, $upload->path);
        $upload->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * A bare SiteMedia bound to the current site, for SitePolicy. Ownership
     * resolves through the site relation (and the site_id associate() sets),
     * mirroring UserDesignMediaController.
     */
    private function skeleton(Site $site): SiteMedia
    {
        $skeleton = new SiteMedia;
        $skeleton->site()->associate($site);

        return $skeleton;
    }

    /**
     * The owner's Google Business photos as pick options { ref, url }. Empty when
     * there's no active google-business connection. Read via the typed DTO.
     *
     * @return list<array{ref: string, url: string|null}>
     */
    private function googlePhotoOptions(User $pro): array
    {
        $conn = $pro->integrationConnections()
            ->where('platform', Platform::GoogleBusiness->value)
            ->where('is_active', true)
            ->first();

        if ($conn === null || ! self::googlePhotosAllowed($conn->display_settings)) {
            return [];
        }

        return array_map(
            fn (array $p) => ['ref' => $p['ref'], 'url' => $p['photoPicUrl']],
            GoogleBusinessPayload::fromArray($conn->payload)->photos(),
        );
    }

    /**
     * The Instagram post images the library offers — every R2-mirrored image
     * the connection payload carries, referenced by its URL (which doubles as
     * the ig-photo selection ref). Available whenever Instagram is connected;
     * unlike the two auto slots this needs no toggle.
     *
     * @return list<array{ref: string, url: string}>
     */
    private function instagramPhotoOptions(User $pro): array
    {
        $conn = $pro->integrationConnections()
            ->where('platform', Platform::Instagram->value)
            ->where('is_active', true)
            ->first();

        if ($conn === null) {
            return [];
        }

        $images = InstagramPayload::fromArray($conn->payload)->images;

        return array_values(array_map(
            fn (string $url) => ['ref' => $url, 'url' => $url],
            array_filter($images, fn ($url) => is_string($url) && str_starts_with($url, 'https://')),
        ));
    }

    /**
     * Whether the owner's Google Business photos may flow into the content
     * library. `content_photos` on the GB connection's display_settings —
     * absent/true = included, an explicit false excludes. Slice 7 unit E moved
     * this off ContentSelectionService; the WRITE verb retired with the
     * selection surface, so this now only honours values already stored.
     *
     * @param  array<string, mixed>|null  $displaySettings
     */
    private static function googlePhotosAllowed(?array $displaySettings): bool
    {
        return ($displaySettings['content_photos'] ?? true) !== false;
    }
}
