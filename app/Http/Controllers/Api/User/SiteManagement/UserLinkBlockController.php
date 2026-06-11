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
use App\Services\Site\LinkBlockFieldBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * V2: CRUD + reorder for link blocks on the professional's mini-site.
 *
 * Supports two write modes (see docs/social-links.md):
 *   - **Social mode**: client sends `platform` + (`handle` OR `url`). The
 *     SocialLinkNormalizer validates and rebuilds a canonical https URL; the
 *     controller stores `settings.platform` and `settings.handle` as soft tags.
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
        private readonly LinkBlockFieldBuilder $fieldBuilder
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

        $linkBlock = DB::transaction(function () use ($pro, $site, $blockFields, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $maxSort = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $linkBlock = new Block(array_merge($blockFields, [
                'block_group' => 'links',
                'block_type' => 'link',
                'sort_order' => $maxSort + 1,
                'is_active' => $data['is_active'] ?? true,
            ]));

            $linkBlock->user_id = $pro->id;
            $linkBlock->site_id = $site->id;
            $linkBlock->save();

            return $linkBlock->fresh();
        });

        return $this->success(['block' => new LinkBlockResource($linkBlock)], 201);
    }

    public function update(UpdateLinkBlockRequest $request, Block $linkBlock)
    {
        $pro = $this->currentUser($request);

        // Type constraint: this endpoint only handles link-type blocks.
        abort_unless($linkBlock->block_group === 'links' && $linkBlock->block_type === 'link', 404);
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
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);

            // Category lives in settings JSONB, not as a column. If the client
            // supplied a new category in isolation, merge it into existing settings.
            if (array_key_exists('category', $data)) {
                $existingSettings = is_array($linkBlock->settings) ? $linkBlock->settings : [];
                $existingSettings['category'] = $data['category'];
                $data['settings'] = array_merge($existingSettings, $data['settings'] ?? []);
                unset($data['category']);
            }

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
        abort_unless($linkBlock->block_group === 'links' && $linkBlock->block_type === 'link', 404);
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

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $allIds = Block::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more blocks are invalid');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);
            $offset = (int) Block::query()
                ->where('user_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        // Mass-update via the query builder bypasses Eloquent's `updated`
        // event on Block — so BlockObserver's touch-the-Site chain never
        // fires, and the §28.8 backend cache key (`public.profile:{handle}:
        // {site.updated_at}`) wouldn't rotate, and CloudflareCachePurgeJob
        // wouldn't dispatch. Explicit Site touch closes the gap.
        $site->touch();

        return $this->success(['ok' => true]);
    }
}
