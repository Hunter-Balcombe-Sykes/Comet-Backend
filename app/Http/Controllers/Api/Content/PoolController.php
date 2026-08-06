<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Content\Item;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The dashboard's pool lane (platforms-as-sources, 2026-08-05): one GET for
// the whole pool (selection + library + the Latest tag), and the three
// selection verbs — select, deselect, reorder — expressed as pins and
// excludes on the pool's section, which is the ONLY curation store (C4).
//
// Deselect is deliberately asymmetric: a pinned item just unpins, but an
// AUTO item (the rule's rolling latest) writes an EXCLUDE — the owner's
// semantics are "removing the current latest keeps it removed, and nothing
// auto-joins until something newer lands". Selecting an item clears any
// exclude it carried: the last thing the user said wins.
class PoolController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly PoolResolver $resolver,
        private readonly PoolSectionProvisioner $provisioner,
    ) {}

    /** GET /api/content/pools/{pool} */
    public function show(Request $request, string $pool): JsonResponse
    {
        $this->assertPool($pool);
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        return $this->success($this->resolver->resolve($site, $pool));
    }

    /** POST /api/content/pools/{pool}/selection/{item} — pin (hand-pick). */
    public function select(Request $request, string $pool, string $itemId): JsonResponse
    {
        $this->assertPool($pool);
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $item = $this->findPoolItem((string) $user->id, $pool, $itemId);

        $section = $this->provisioner->ensure($site, $pool);

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $item->id)
            ->first() ?? new SectionItem;

        $row->section_id = (string) $section->id;
        $row->item_id = (string) $item->id;
        $row->state = SectionItem::STATE_PINNED;
        $row->sort_key = $row->sort_key ?? $this->nextSortKey((string) $section->id);
        if (! $row->exists) {
            $row->created_at = now();
        }
        $row->save();

        $this->poolChanged($site);

        return $this->success($this->resolver->resolve($site, $pool));
    }

    /**
     * DELETE /api/content/pools/{pool}/selection/{item} — deselect. Pinned
     * items unpin; rule-emitted (auto) items are excluded so the rolling
     * latest stays gone until something newer arrives.
     */
    public function deselect(Request $request, string $pool, string $itemId): JsonResponse
    {
        $this->assertPool($pool);
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $item = $this->findPoolItem((string) $user->id, $pool, $itemId);

        $section = $this->provisioner->ensure($site, $pool);

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $item->id)
            ->first();

        if ($row !== null && $row->state === SectionItem::STATE_PINNED) {
            $row->delete();
        } else {
            $row ??= new SectionItem;
            $row->section_id = (string) $section->id;
            $row->item_id = (string) $item->id;
            $row->state = SectionItem::STATE_EXCLUDED;
            $row->sort_key = null;
            if (! $row->exists) {
                $row->created_at = now();
            }
            $row->save();
        }

        $this->poolChanged($site);

        return $this->success($this->resolver->resolve($site, $pool));
    }

    /**
     * PUT /api/content/pools/{pool}/order {itemIds: [...]} — the drag
     * commit. Every listed item becomes a pin at its position (dragging an
     * auto item into your order IS hand-picking it), and any exclude it
     * carried is cleared.
     */
    public function reorder(Request $request, string $pool): JsonResponse
    {
        $this->assertPool($pool);
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $data = $request->validate([
            'itemIds' => ['required', 'array', 'max:200'],
            'itemIds.*' => ['uuid'],
        ]);

        $ids = array_values(array_unique(array_map('strval', $data['itemIds'])));

        // Owner-scoped AND pool-scoped: a foreign or off-pool id is a 422,
        // not a silently skipped row — the FE sent a list it believes is the
        // pool, and half-applying it would scramble the order it shows.
        $owned = Item::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->pluck('id')
            ->all();

        if (count($owned) !== count($ids)) {
            return $this->error('That list contains items not in this pool.', 422);
        }

        $section = $this->provisioner->ensure($site, $pool);

        DB::connection('pgsql')->transaction(function () use ($section, $ids) {
            SectionItem::query()
                ->where('section_id', $section->id)
                ->whereIn('item_id', $ids)
                ->delete();

            $rows = [];
            foreach ($ids as $index => $itemId) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'section_id' => (string) $section->id,
                    'item_id' => $itemId,
                    'state' => SectionItem::STATE_PINNED,
                    'sort_key' => (float) ($index + 1),
                    'created_at' => now(),
                ];
            }
            SectionItem::query()->insert($rows);
        });

        $this->poolChanged($site);

        return $this->success($this->resolver->resolve($site, $pool));
    }

    /**
     * Every pool mutation ends here: the document build-state bump AND the
     * sitepage edge purge. Option B serves pools LIVE, but the CDN in front
     * still holds the rendered page — without the purge, "the site follows
     * instantly" was true of the payload and false of what a visitor saw
     * (found verifying P2: the deploy's edge cache outlived a pool edit).
     * ShouldBeUnique per handle, so bursts coalesce.
     */
    private function poolChanged(Site $site): void
    {
        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }
    }

    private function assertPool(string $pool): void
    {
        if (! PoolRegistry::isPool($pool)) {
            abort(404, 'Unknown pool.');
        }
    }

    /** Owner- and pool-scoped item lookup: a foreign id is a 404. */
    private function findPoolItem(string $userId, string $pool, string $itemId): Item
    {
        $item = Item::query()
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->first();

        if ($item === null) {
            abort(404, 'Item not found in this pool.');
        }

        return $item;
    }

    private function nextSortKey(string $sectionId): float
    {
        $highest = SectionItem::query()
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }
}
