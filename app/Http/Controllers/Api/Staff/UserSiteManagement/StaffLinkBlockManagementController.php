<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Staff\UserSite\Links\StaffReorderLinkRequest;
use App\Http\Requests\Api\Staff\UserSite\Links\StaffStoreLinkRequest;
use App\Http\Requests\Api\Staff\UserSite\Links\StaffUpdateLinkRequest;
use App\Http\Resources\LinkBlockResource;
use App\Models\Core\Site\Block;
use App\Models\Core\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// V2: Staff manages a professional's custom link blocks (CRUD + reorder).
class StaffLinkBlockManagementController extends ApiController
{
    use ResolveCurrentSite;

    public function index(User $professional): JsonResponse
    {
        return $this->success([
            'blocks' => LinkBlockResource::collection(
                $professional->linkBlocks()->orderBy('sort_order')->get()
            ),
        ]);
    }

    public function store(StaffStoreLinkRequest $request, User $professional): JsonResponse
    {
        $professional->loadMissing('site');
        $site = $this->currentSite($professional);

        $data = $request->validated();

        $block = DB::transaction(function () use ($professional, $site, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $maxSort = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $block = new Block([
                'block_group' => 'links',
                'block_type' => 'link',
                'title' => $data['title'],
                'url' => $data['url'],
                'icon_key' => $data['icon_key'] ?? null,
                'sort_order' => $maxSort + 1,
                'is_active' => $data['is_active'] ?? true,
                'settings' => $data['settings'] ?? [],
            ]);
            $block->user_id = $professional->id;
            $block->site_id = $site->id;
            $block->save();

            return $block->fresh();
        });

        return $this->success(['block' => new LinkBlockResource($block)], 201);
    }

    public function update(StaffUpdateLinkRequest $request, User $professional, Block $linkBlock): JsonResponse
    {
        // scoped binding guarantees ownership, but still enforce correct kind of block
        abort_unless(
            $linkBlock->user_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        $linkBlock->fill($request->validated());
        $linkBlock->save();

        return $this->success(['block' => new LinkBlockResource($linkBlock->fresh())]);
    }

    public function destroy(User $professional, Block $linkBlock): JsonResponse
    {
        abort_unless(
            $linkBlock->user_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        $linkBlock->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(StaffReorderLinkRequest $request, User $professional): JsonResponse
    {
        $ids = array_values(array_unique($request->validated()['ids'] ?? []));
        $site = $this->currentSite($professional);

        DB::transaction(function () use ($professional, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $allIds = Block::query()
                ->where('user_id', $professional->id)
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
                ->where('user_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $professional->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $professional->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }
}
