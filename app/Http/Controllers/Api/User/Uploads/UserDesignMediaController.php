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
// and the brand placeholder image (placeholder) — see
// SiteMedia::designSingletonPurposes(). Per-platform covers were retired
// 2026-08-05 (owner); a cover_* purpose is a 404/validation failure now. One
// row per (site, purpose); re-uploading replaces. Free ratio — the pipeline
// resizes preserving aspect, the display frame is the frontend's concern.
// Reuses the gallery image pipeline (MediaUploadService::uploadSingleton → WebP
// variants). Slice 7 unit E deleted the profile payload's `siteImages` map, so
// these rows currently have no public projection — the rebuilt pages app picks
// one; the dashboard lane here is unaffected.
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
     *   { purpose: logo_full|logo_square|placeholder, image: <file> }
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

    /**
     * DELETE /api/design-media/{purpose} — clear one singleton slot (remove a
     * logo or cover without replacing it). Until now the only way to empty a
     * slot was to upload something else into it. Soft-delete + variant purge,
     * same as the replace path; 404 when the slot is already empty or the
     * purpose isn't a design singleton.
     */
    public function destroy(Request $request, string $purpose): JsonResponse
    {
        if (! in_array($purpose, SiteMedia::designSingletonPurposes(), true)) {
            return $this->error('Unknown design image slot.', 404);
        }

        $pro = $this->currentUser($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // SitePolicy::delete — ownership + the pending-deletion 423 gate,
        // authorized against every live row this purge would touch. The policy
        // resolves SiteMedia ownership through a PRELOADED site relation
        // (never lazy — see resolveOwnerId), so set it explicitly.
        $rows = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->whereNull('deleted_at')
            ->get();
        foreach ($rows as $row) {
            $row->setRelation('site', $site);
            $this->authorizeForUser($pro, 'delete', $row);
        }

        if (! $this->uploadService->removeSingleton($site, $purpose, $rows)) {
            return $this->error('Nothing uploaded for this slot.', 404);
        }

        return $this->success(['purpose' => $purpose, 'removed' => true]);
    }
}
