<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\PreviewSmartLinkRequest;
use App\Http\Requests\Api\User\Site\ReorderSmartLinksRequest;
use App\Http\Requests\Api\User\Site\StoreSmartLinkRequest;
use App\Http\Requests\Api\User\Site\UpdateSmartLinkRequest;
use App\Http\Resources\SmartLinkResource;
use App\Models\Core\Site\SmartLink;
use App\Services\SmartLinks\ResolvedSmartLink;
use App\Services\SmartLinks\SmartLinkImageService;
use App\Services\SmartLinks\SmartLinkRefresher;
use App\Services\SmartLinks\SmartLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD + preview + reorder for smart links (commerce + content families).
 * Preview resolves a pasted URL without saving; store/update persist a row
 * (rehosting commerce images). Ownership via SitePolicy (skeleton pattern on
 * create/reorder, the row itself on update/delete).
 */
class UserSmartLinkController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly SmartLinkResolver $resolver,
        private readonly SmartLinkImageService $imageService,
        private readonly SmartLinkRefresher $refresher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);

        // Bound the query at scale (B18/API-4). True pagination is a frontend-coordinated change, deferred.
        $links = SmartLink::query()
            ->where('site_id', $site->id)
            ->orderBy('family')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        return $this->success(['smart_links' => SmartLinkResource::collection($links)]);
    }

    public function preview(PreviewSmartLinkRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        // Authorize against the site so non-owners can't use our scraper as a proxy.
        $this->currentSite($pro);

        $data = $request->validated();
        $resolved = $this->resolver->resolve($data['url'], $data['selection']);

        if (! $resolved->valid || $resolved->data === null) {
            return $this->success([
                'valid' => false,
                'reason' => $resolved->reason ?? 'We couldn’t read this link.',
            ]);
        }

        return $this->success([
            'valid' => true,
            'preview' => $this->previewShape($resolved),
        ]);
    }

    public function store(StoreSmartLinkRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $skeleton = new SmartLink(['user_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $data = $request->validated();
        $resolved = $this->resolver->resolve($data['url'], $data['selection']);
        if (! $resolved->valid || $resolved->data === null) {
            return $this->error($resolved->reason ?? 'We couldn’t read this link.', 422);
        }

        $rdata = $resolved->data;
        if ($resolved->family === 'commerce') {
            $rdata = $this->imageService->ingestCommerce($rdata, (string) $site->id);
        }

        $discountCode = $this->discountSupported($resolved->type, $resolved->platform)
            ? ($data['discount_code'] ?? null)
            : null;

        $link = DB::transaction(function () use ($pro, $site, $resolved, $rdata, $discountCode) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["smart-links:{$site->id}:{$resolved->family}"]);

            $maxSort = (int) (SmartLink::query()
                ->where('site_id', $site->id)
                ->where('family', $resolved->family)
                ->max('sort_order') ?? -1);

            $link = new SmartLink([
                'user_id' => $pro->id,
                'site_id' => $site->id,
                'family' => $resolved->family,
                'type' => $resolved->type,
                'platform' => $resolved->platform,
                'canonical_url' => $resolved->url->canonical,
                'tracking_query' => $resolved->url->trackingQuery,
                'discount_code' => $discountCode,
                'title' => $rdata->title,
                'image_url' => $rdata->imageUrl,
                'favicon_url' => $rdata->faviconUrl,
                'brand_name' => $rdata->brandName,
                'brand_logo_url' => $rdata->brandLogoUrl,
                'metadata' => $rdata->metadata,
                'image_sources' => $rdata->imageSources,
                'sort_order' => $maxSort + 1,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'consecutive_failures' => 0,
            ]);
            $link->save();

            return $link->fresh();
        });

        return $this->success(['smart_link' => new SmartLinkResource($link)], 201);
    }

    public function update(UpdateSmartLinkRequest $request, SmartLink $smartLink): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'update', $smartLink);

        $data = $request->validated();

        if (array_key_exists('discount_code', $data)
            && $this->discountSupported($smartLink->type, $smartLink->platform)) {
            $smartLink->discount_code = $data['discount_code'];
        }
        if (array_key_exists('is_active', $data)) {
            $smartLink->is_active = (bool) $data['is_active'];
        }
        $smartLink->save();

        return $this->success(['smart_link' => new SmartLinkResource($smartLink->fresh())]);
    }

    public function destroy(Request $request, SmartLink $smartLink): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'delete', $smartLink);

        $smartLink->delete();

        return $this->success(['deleted' => true]);
    }

    public function refresh(Request $request, SmartLink $smartLink): JsonResponse
    {
        $pro = $this->currentUser($request);
        $this->authorizeForUser($pro, 'update', $smartLink);

        $this->refresher->refresh($smartLink);

        return $this->success(['smart_link' => new SmartLinkResource($smartLink->fresh())]);
    }

    public function reorder(ReorderSmartLinksRequest $request): JsonResponse
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        $skeleton = new SmartLink(['user_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $validated = $request->validated();
        $family = $validated['family'];
        $ids = array_values(array_unique($validated['ids']));

        DB::transaction(function () use ($pro, $site, $family, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["smart-links:{$site->id}:{$family}"]);

            $allIds = SmartLink::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('family', $family)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more smart links are invalid');
                }
            }

            $newOrder = array_merge($ids, array_values(array_diff($allIds, $ids)));
            $offset = (int) SmartLink::query()
                ->where('site_id', $site->id)
                ->where('family', $family)
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                SmartLink::query()->where('id', $id)->update(['sort_order' => $offset + $i]);
            }
            foreach ($newOrder as $i => $id) {
                SmartLink::query()->where('id', $id)->update(['sort_order' => $i]);
            }
        });

        $site->touch();

        return $this->success(['ok' => true]);
    }

    /**
     * A discount code is supported only where we can apply it on the visitor
     * click: Shopify (product/collection/brand → /discount/CODE?redirect=) or
     * Eventbrite (event → ?discount=). Elsewhere the code is dropped (§1.9).
     */
    private function discountSupported(string $type, string $platform): bool
    {
        if ($type === 'commerce.event') {
            return $platform === 'eventbrite';
        }

        return $platform === 'shopify'
            && in_array($type, ['commerce.product', 'commerce.collection', 'commerce.brand'], true);
    }

    /** @return array<string,mixed> */
    private function previewShape(ResolvedSmartLink $resolved): array
    {
        $d = $resolved->data;

        return [
            'type' => $resolved->type,
            'family' => $resolved->family,
            'platform' => $resolved->platform,
            'title' => $d->title,
            'image_url' => $d->imageUrl,
            'favicon_url' => $d->faviconUrl,
            'brand_name' => $d->brandName,
            'brand_logo_url' => $d->brandLogoUrl,
            'metadata' => (object) $d->metadata,
        ];
    }
}
