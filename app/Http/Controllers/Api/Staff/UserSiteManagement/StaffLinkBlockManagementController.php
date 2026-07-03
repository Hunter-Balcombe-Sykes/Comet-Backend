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
use App\Services\Site\InsertWithSortOrder;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $block = InsertWithSortOrder::run(
            Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_LINKS),
            "blocks-links:{$site->id}",
            function (int $next) use ($professional, $site, $data) {
                $block = new Block([
                    'block_group' => Block::GROUP_LINKS,
                    'block_type' => Block::TYPE_LINK,
                    'title' => $data['title'],
                    'url' => $data['url'],
                    'icon_key' => $data['icon_key'] ?? null,
                    'sort_order' => $next,
                    'is_active' => $data['is_active'] ?? true,
                    // Phase 2: category + live_check_enabled are columns; read top-level only.
                    'category' => $data['category'] ?? null,
                    'live_check_enabled' => (bool) ($data['live_check_enabled'] ?? false),
                    'settings' => $data['settings'] ?? [],
                ]);
                $block->user_id = $professional->id;
                $block->site_id = $site->id;
                $block->save();

                return $block->fresh();
            },
        );

        return $this->success(['block' => new LinkBlockResource($block)], 201);
    }

    public function update(StaffUpdateLinkRequest $request, User $professional, Block $linkBlock): JsonResponse
    {
        // Policy gate: admin-only + blocks pending-deletion professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManageBlock', $professional);

        // Business rule: only link-kind blocks belong to this endpoint.
        if ($linkBlock->block_group !== Block::GROUP_LINKS || $linkBlock->block_type !== Block::TYPE_LINK) {
            abort(404);
        }

        // Phase 2: category + live_check_enabled are top-level columns; fill() maps
        // them directly from the validated request. No settings-mirroring needed.
        $linkBlock->fill($request->validated());
        $linkBlock->save();

        return $this->success(['block' => new LinkBlockResource($linkBlock->fresh())]);
    }

    public function destroy(Request $request, User $professional, Block $linkBlock): JsonResponse
    {
        // Policy gate: admin-only + blocks pending-deletion professional.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManageBlock', $professional);

        // Business rule: only link-kind blocks belong to this endpoint.
        if ($linkBlock->block_group !== Block::GROUP_LINKS || $linkBlock->block_type !== Block::TYPE_LINK) {
            abort(404);
        }

        $linkBlock->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(StaffReorderLinkRequest $request, User $professional): JsonResponse
    {
        $site = $this->currentSite($professional);

        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            Block::query()
                ->where('user_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_LINKS)
                ->where('block_type', Block::TYPE_LINK),
            "blocks-links:{$site->id}",
        );

        return $this->success(['ok' => true]);
    }
}
