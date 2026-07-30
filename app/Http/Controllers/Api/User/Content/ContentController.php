<?php

namespace App\Http\Controllers\Api\User\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Content\ReplaceContentSelectionRequest;
use App\Http\Requests\Api\User\Content\SetContentGooglePhotosRequest;
use App\Http\Requests\Api\User\Content\SetContentInstagramAutoRequest;
use App\Http\Requests\Api\User\Content\UploadContentImageRequest;
use App\Http\Resources\Content\ContentLibraryUploadResource;
use App\Models\Core\Site\ContentSelection;
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
use App\Services\Site\ContentSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// HTTP layer for the Content dashboard surface — a Library (browse) + an ordered
// Selection (≤15) of imagery for later use as sitepage backgrounds. Sources:
// manual uploads (content media pool), Google Business photos (referenced), and
// Instagram (auto reel/post). Row logic lives in ContentSelectionService; media
// upload reuses MediaUploadService (the gallery multi-add path). Every action
// authorizes a ContentSelection skeleton whose `site` is the current site.
class ContentController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ContentSelectionService $selection,
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
        $this->authorizeForUser($pro, 'manage', $this->skeleton($site));

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
     * DELETE /content/uploads/{id} — soft-delete a content upload and remove any
     * selection row referencing it. The FK CASCADE only fires on HARD delete, so
     * the referencing content_selection row is deleted explicitly here.
     */
    public function destroyUpload(Request $request, SiteMedia $upload): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'manage', $this->skeleton($site));

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

        // Remove the selection row(s) pointing at this upload BEFORE the soft
        // delete (CASCADE won't fire on a soft delete).
        ContentSelection::query()
            ->where('site_id', $site->id)
            ->where('media_id', $upload->id)
            ->delete();

        // Synchronous file cleanup (images have 2–3 variant files).
        $this->imageService->deleteVariants($upload->id, $upload->path);
        $upload->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * GET /content/selection — the resolved ordered selection + IG flags.
     *
     * @return JsonResponse {
     *                      selection: [...], instagramAutoEnabled: bool, instagramConnected: bool
     *                      }
     */
    public function selection(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'view', $this->skeleton($site));

        return $this->success($this->selectionPayload($pro, $site));
    }

    /**
     * PUT /content/selection — replace the whole ordered selection. Shape/count/
     * ig-placement errors from the service surface as 422.
     */
    public function replaceSelection(ReplaceContentSelectionRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'manage', $this->skeleton($site));

        try {
            $this->selection->replace($site, $request->validated('entries') ?? []);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->selectionPayload($pro, $site));
    }

    /**
     * PUT /content/instagram-auto — flip the auto flag and return the refreshed
     * selection (the toggle reserves/removes the ig-* slots as a side-effect).
     */
    public function setInstagramAuto(SetContentInstagramAutoRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'manage', $this->skeleton($site));

        $this->selection->setInstagramAuto($site, (bool) $request->validated('enabled'));

        return $this->success($this->selectionPayload($pro, $site->refresh()));
    }

    /**
     * PUT /content/google-photos — flip whether the owner's Google Business
     * photos flow into the content pipeline (media library + selection). WS-B2.1:
     * stored as `content_photos` in the GB connection's display_settings, using
     * the same sparse-map contract as the platform display toggles (ON removes
     * the key so the stored map holds only deviations; OFF records false).
     * Requires an active Google Business connection (404 otherwise — nothing to
     * gate without one).
     */
    public function setGooglePhotos(SetContentGooglePhotosRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $this->authorizeForUser($pro, 'manage', $this->skeleton($site));

        $conn = $pro->integrationConnections()
            ->where('platform', Platform::GoogleBusiness->value)
            ->where('is_active', true)
            ->first();

        if ($conn === null) {
            return $this->error('Connect Google Business first.', 404);
        }

        $settings = (array) ($conn->display_settings ?? []);
        if ((bool) $request->validated('enabled')) {
            unset($settings['content_photos']);
        } else {
            $settings['content_photos'] = false;
        }
        $conn->display_settings = $settings === [] ? null : $settings;
        $conn->save();

        // content_photos changes the resolved content selection, which feeds the
        // sitepage BACKGROUNDS (SitepageDataResolverService) — part of the profile
        // payload, NOT the platforms cache the connection observer purges. Touch the
        // site so SiteObserver purges the profile edge cache too (mirrors how
        // setInstagramAuto persists through the site); otherwise the toggle would
        // only reach the live sitepage after the edge TTL lapsed.
        $site->touch();

        return $this->success($this->selectionPayload($pro, $site));
    }

    /**
     * A bare ContentSelection with the current site attached, for the policy.
     * Ownership resolves through the site relation (ContentSelectionPolicy).
     */
    private function skeleton(Site $site): ContentSelection
    {
        return (new ContentSelection)->setRelation('site', $site);
    }

    /**
     * @return array{selection: list<array<string, mixed>>, instagramAutoEnabled: bool, instagramConnected: bool, googlePhotosEnabled: bool, googlePhotosConnected: bool}
     */
    private function selectionPayload(User $pro, Site $site): array
    {
        return [
            'selection' => $this->selection->resolve($site),
            'instagramAutoEnabled' => (bool) $site->content_instagram_auto_enabled,
            'instagramConnected' => $this->hasActiveConnection($pro, Platform::Instagram->value),
            // WS-B2.1: the Google-photos content-inclusion toggle (stored on the GB
            // connection's display_settings). googlePhotosConnected lets the
            // media-settings UI hide the control when there's nothing to gate.
            'googlePhotosEnabled' => $this->googlePhotosEnabled($pro),
            'googlePhotosConnected' => $this->hasActiveConnection($pro, Platform::GoogleBusiness->value),
        ];
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

        if ($conn === null || ! ContentSelectionService::googlePhotosEnabled($conn->display_settings)) {
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
     * Whether Google photos are currently allowed into the content pipeline for
     * this owner. Reads the `content_photos` flag off the GB connection's
     * display_settings via the service's shared default (absent/true = ON).
     * Defaults ON when there's no GB connection (moot — googlePhotosConnected is
     * then false and the UI hides the control).
     */
    private function googlePhotosEnabled(User $pro): bool
    {
        $conn = $pro->integrationConnections()
            ->where('platform', Platform::GoogleBusiness->value)
            ->where('is_active', true)
            ->first(['display_settings']);

        return ContentSelectionService::googlePhotosEnabled($conn?->display_settings);
    }

    private function hasActiveConnection(User $pro, string $platform): bool
    {
        return $pro->integrationConnections()
            ->where('platform', $platform)
            ->where('is_active', true)
            ->exists();
    }
}
