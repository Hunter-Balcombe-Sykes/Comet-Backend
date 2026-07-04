<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\User\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use App\Http\Resources\LinkBlockResource;
use App\Models\Core\Site\Block;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\LinkBlockFieldBuilder;
use App\Services\Site\ReorderService;
use InvalidArgumentException;

/**
 * V2: CRUD + reorder for link blocks on the professional's mini-site.
 *
 * Supports two write modes (see docs/social-links.md):
 *   - **Social mode**: client sends `platform` + (`handle` OR `url`). The
 *     SocialLinkNormalizer validates and rebuilds a canonical https URL; the
 *     controller stores `platform` as a column and `handle` in settings JSONB.
 *   - **Custom mode**: client sends `title` + `url` (legacy contract preserved).
 *     No platform binding, free-form icon_key.
 *
 * Authorization: ownership on write actions enforced via SitePolicy (authorizeForUser).
 * store() and reorder() use the skeleton pattern (SitePolicy::create) for pending-deletion
 * and ownership guard. update() and destroy() use the existing row (SitePolicy::update/delete).
 */
class UserLinkBlockController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly LinkBlockFieldBuilder $fieldBuilder,
        private readonly ReorderService $reorderService,
    ) {}

    public function index(IndexLinkBlockRequest $request)
    {
        $pro = $this->currentUser($request);

        // Served from SiteCacheService::getSiteLinkBlocks (15-min TTL, single-flight,
        // SWR + jitter). Same helper that powers /api/me's `blocks` payload, so the
        // two endpoints stay consistent. Returns active link blocks only — inactive
        // (is_active=false) blocks aren't surfaced here. BlockObserver / SiteObserver
        // bust the key on every link write through SiteCacheService::invalidateSite.
        // $pro->site rides along on the AUTH-1 cached Professional model, so reading
        // $pro->site->id costs nothing.
        if ($pro->site) {
            return $this->success([
                'blocks' => app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id),
            ]);
        }

        return $this->success(['blocks' => []]);
    }

    public function store(StoreLinkBlockRequest $request)
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        // Skeleton pattern: pre-create ownership + pending-deletion check via SitePolicy::create.
        $skeleton = new Block(['user_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        $data = $request->validated();

        // Social vs custom mode discriminator. Social mode delegates to the
        // normalizer to rebuild a canonical URL and tag settings.platform/handle.
        // Custom mode preserves the legacy field-by-field contract.
        try {
            $blockFields = $this->fieldBuilder->build($data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $linkBlock = InsertWithSortOrder::run(
            Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_LINKS),
            "blocks-links:{$site->id}",
            function (int $next) use ($pro, $site, $blockFields, $data) {
                $linkBlock = new Block(array_merge($blockFields, [
                    'block_group' => Block::GROUP_LINKS,
                    'block_type' => Block::TYPE_LINK,
                    'sort_order' => $next,
                    'is_active' => $data['is_active'] ?? true,
                ]));
                $linkBlock->user_id = $pro->id;
                $linkBlock->site_id = $site->id;
                $linkBlock->save();

                return $linkBlock->fresh();
            },
        );

        return $this->success(['block' => new LinkBlockResource($linkBlock)], 201);
    }

    public function update(UpdateLinkBlockRequest $request, Block $linkBlock)
    {
        $pro = $this->currentUser($request);

        // Type constraint: this endpoint only handles link-type blocks.
        abort_unless($linkBlock->block_group === Block::GROUP_LINKS && $linkBlock->block_type === Block::TYPE_LINK, 404);
        $this->authorizeForUser($pro, 'update', $linkBlock);

        $data = $request->validated();
        unset($data['id']);

        // If the request switches into social mode (or stays in social mode with
        // a new handle/url), re-normalize. Otherwise fall through to the legacy
        // partial-update path that just fills whatever fields were sent.
        if (! empty($data['platform'])) {
            try {
                $normalized = $this->fieldBuilder->build($data);
            } catch (InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }

            // Merge normalized social fields, preserving any other fields the
            // user happened to send (e.g. is_active toggle alongside).
            $linkBlock->fill(array_merge(
                array_intersect_key($data, array_flip(['is_active'])),
                $normalized
            ));
        } else {
            // Strip the social-mode-only keys before fill — 'platform' is never set
            // on this legacy path, and 'handle' (FOUND-35: now a Block column) must
            // only be written via the normalizer above, never as a raw passthrough.
            unset($data['platform'], $data['handle']);
            // Phase 2: category + live_check_enabled are top-level columns; fill()
            // maps them directly. Any settings the client sends no longer carries
            // these keys (rejected by the allowlist in UpdateLinkBlockRequest).
            $linkBlock->fill($data);
        }

        $linkBlock->save();

        return $this->success(['block' => new LinkBlockResource($linkBlock->fresh())]);
    }

    public function destroy(DestroyLinkBlockRequest $request, Block $linkBlock)
    {
        $request->validated();

        $pro = $this->currentUser($request);

        // Type constraint: this endpoint only handles link-type blocks.
        abort_unless($linkBlock->block_group === Block::GROUP_LINKS && $linkBlock->block_type === Block::TYPE_LINK, 404);
        $this->authorizeForUser($pro, 'delete', $linkBlock);

        $linkBlock->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentUser($request);
        $site = $this->currentSite($pro);
        // Skeleton pattern: pre-create ownership + pending-deletion check via SitePolicy::create.
        $skeleton = new Block(['user_id' => $pro->id, 'site_id' => $site->id]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        // Mass-update via the query builder bypasses Eloquent's `updated`
        // event on Block — so BlockObserver's touch-the-Site chain never
        // fires, and the §28.8 backend cache key (`public.profile:{handle}:
        // {site.updated_at}`) wouldn't rotate, and CloudflareCachePurgeJob
        // wouldn't dispatch. Explicit Site touch in afterCommit closes the gap.
        $this->reorderService->reorder(
            $request->input('ids', []),
            Block::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_LINKS)
                ->where('block_type', Block::TYPE_LINK),
            "blocks-links:{$site->id}",
            fn () => $site->touch(),
        );

        return $this->success(['ok' => true]);
    }
}
