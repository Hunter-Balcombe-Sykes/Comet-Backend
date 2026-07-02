<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use App\Http\Resources\SectionBlockResource;
use App\Models\Core\Site\Block;
use App\Models\Core\User\User;
use App\Services\Site\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Staff manages section block visibility (gallery, services, shop, booking, bio) with full control.
class StaffSectionManagementController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function index(User $professional): JsonResponse
    {
        // Return ALL section blocks (active + inactive) so staff can toggle
        $sections = Block::query()
            ->where('user_id', $professional->id)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->orderBy('sort_order')
            ->get();

        return $this->success([
            'user_id' => $professional->id,
            'sections' => SectionBlockResource::collection($sections),
        ]);
    }

    public function upsert(UpsertSectionBlockRequest $request, User $professional, string $blockType): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManageBlock', $professional);

        $site = $this->currentSite($professional);

        $data = $request->validated();

        $block = DB::transaction(function () use ($professional, $site, $data, $blockType) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'user_id' => $professional->id,
                'site_id' => $site->id,
                'block_group' => Block::GROUP_SECTIONS,
                'block_type' => $blockType,
            ]);

            if (array_key_exists('is_active', $data)) {
                $block->is_active = (bool) $data['is_active'];
            }

            if (! $block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', Block::GROUP_SECTIONS)
                    ->max('sort_order');

                $block->sort_order = is_null($maxSort) ? 0 : ((int) $maxSort + 1);
                $block->is_active = $data['is_active'] ?? true;
                $block->settings = $data['settings'] ?? [];
            }

            // PATCH-style merge settings
            if (array_key_exists('settings', $data)) {
                $existing = is_array($block->settings) ? $block->settings : [];
                $incoming = is_array($data['settings']) ? $data['settings'] : [];
                $block->settings = array_replace_recursive($existing, $incoming);
            } elseif (! $block->exists) {
                $block->settings = [];
            }

            $block->save();

            return $block->fresh();
        });

        // Keep professional.bio in sync with the "bio" section text (only when text was sent)
        if (
            $blockType === 'bio'
            && array_key_exists('settings', $data)
            && is_array($data['settings'])
            && array_key_exists('text', $data['settings'])
        ) {
            $professional->bio = data_get($block->settings, 'text'); // merged + saved value
            $professional->save();
        }

        return $this->success([
            'section' => new SectionBlockResource($block->fresh())],
            $block->wasRecentlyCreated ? 201 : 200);

    }

    public function reorder(ReorderBlocksRequest $request, User $professional): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManageBlock', $professional);

        $site = $this->currentSite($professional);

        app(ReorderService::class)->reorder(
            $request->input('ids', []),
            Block::query()
                ->where('user_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', Block::GROUP_SECTIONS),
            "blocks-sections:{$site->id}",
        );

        return $this->success(['ok' => true]);
    }

    public function remove(Request $request, User $professional, string $blockType): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffManageBlock', $professional);

        $site = $professional->site;
        if (! $site) {
            return $this->error('Professional has no site.', 422);
        }

        $block = Block::query()
            ->where('user_id', $professional->id)
            ->where('site_id', $site->id)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', $blockType)
            ->first();

        if (! $block) {
            return $this->success(['ok' => true]);
        }

        $block->is_active = false;
        $block->save();

        return $this->success(['ok' => true]);
    }
}
