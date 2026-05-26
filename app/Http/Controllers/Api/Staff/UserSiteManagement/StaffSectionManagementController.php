<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\User\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use App\Http\Resources\SectionBlockResource;
use App\Models\Core\User\User;
use App\Models\Core\Site\Block;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// V2: Staff manages section block visibility (gallery, services, shop, booking, bio) with full control.
class StaffSectionManagementController extends ApiController
{
    use ResolveCurrentUser;
    use ResolveCurrentSite;

    public function index(User $professional): JsonResponse
    {
        // Return ALL section blocks (active + inactive) so staff can toggle
        $sections = Block::query()
            ->where('user_id', $professional->id)
            ->where('block_group', 'sections')
            ->orderBy('sort_order')
            ->get();

        return $this->success([
            'user_id' => $professional->id,
            'sections' => SectionBlockResource::collection($sections),
        ]);
    }

    public function upsert(UpsertSectionBlockRequest $request, User $professional, string $blockType): JsonResponse
    {
        $site = $this->currentSite($professional);

        $data = $request->validated();

        $block = DB::transaction(function () use ($professional, $site, $data, $blockType) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $block = Block::query()->firstOrNew([
                'user_id' => $professional->id,
                'site_id' => $site->id,
                'block_group' => 'sections',
                'block_type' => $blockType,
            ]);

            if (array_key_exists('is_active', $data)) {
                $block->is_active = (bool) $data['is_active'];
            }

            if (! $block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
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
        $ids = array_values(array_unique($request->validated()['ids'] ?? []));
        $site = $this->currentSite($professional);

        DB::transaction(function () use ($professional, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);

            $allIds = Block::query()
                ->where('user_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more sections are invalid');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);
            $offset = (int) Block::query()
                ->where('user_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $professional->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('user_id', $professional->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    public function remove(User $professional, string $blockType): JsonResponse
    {
        $site = $professional->site;
        if (! $site) {
            return $this->error('Professional has no site.', 422);
        }

        $block = Block::query()
            ->where('user_id', $professional->id)
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
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
