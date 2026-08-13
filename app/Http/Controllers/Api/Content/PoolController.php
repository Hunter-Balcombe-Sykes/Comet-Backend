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

        // Slice 6 §4.3: reviews may be hidden but not promoted. This is the
        // SECOND pin path — SectionItemController::upsert() is the other, and
        // gating only that one left EXCLUDE_ONLY_POOLS bypassable through here,
        // which is the route the dashboard actually uses. Checked before the
        // item lookup because it is a rule about the pool, not about the item.
        // A capability rule, not authorization on a resource, so 422 rather
        // than a policy. deselect() is deliberately NOT gated: exclusion is the
        // half of curation reviews DO get.
        if (! PoolRegistry::allowsPin($pool)) {
            abort(422, 'Reviews can be hidden, but not pinned.');
        }

        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $item = $this->findPoolItem((string) $user->id, $pool, $itemId);

        // D5: borrowed media is displayable but not pinnable. Via the policy
        // rather than an inline abort — InlineAuthBypassGuardTest fails the
        // build on any inline 403 in a controller, and it does not care whether
        // the check is ownership or capability.
        $this->authorizeForUser($user, 'pin', $item);

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

        // Slice 6 §4.3: the THIRD pin path. This method's own contract is that
        // dragging an item into an order pins it, so for an exclusion-only pool
        // there is no "reorder without pinning" to preserve — the order comes
        // from SECTION_SHAPE's order_by: recency, not from the owner. Refusing
        // the whole verb is the honest answer; silently downgrading the writes
        // to non-pins would report success for an order the pool will not keep.
        if (! PoolRegistry::allowsPin($pool)) {
            abort(422, 'Reviews can be hidden, but not reordered.');
        }

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
     * Every pool mutation ends here, across all THREE cache lanes: the document
     * build-state bump, the public payload key, and the sitepage edge purge.
     * Option B serves pools LIVE, but the CDN in front still holds the rendered
     * page — without the purge, "the site follows instantly" was true of the
     * payload and false of what a visitor saw (found verifying P2: the deploy's
     * edge cache outlived a pool edit). ShouldBeUnique per handle, so bursts
     * coalesce.
     *
     * The sites.updated_at write is lane 2 and was MISSING until 2026-08-14:
     * the public payload cache key is derived from that column, so a reorder
     * bumped the build state and purged the CDN while the origin happily
     * re-served the stale order from its own cache for the remainder of the
     * TTL. Mirrors ShopController::bumpSiteCache(). A raw update rather than
     * touch() on purpose — touch() fires SiteObserver's full invalidation and
     * KV-sync chain, which the two explicit lanes here already cover.
     */
    private function poolChanged(Site $site): void
    {
        BuildState::bump((string) $site->id);
        DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['updated_at' => now()]);
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
