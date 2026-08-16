<?php

namespace App\Services\Content;

use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Read + curate side of the `custom_links` pool for the dashboard's link lane.
 *
 * Convergence Phase 6 moved custom links off `partna.custom_link` connections
 * (LinkPoolWriter). This serves the same JSON the connection-backed endpoints
 * always did, so the dashboard keeps working — with one deliberate difference:
 * `favicon` and `logo` are always null. Phase 3 declined to mint
 * content.media_assets for third-party image URLs, and that decision is
 * unchanged; see LinkPoolWriter's docblock.
 *
 * Ordering is the pin order (`sort_key`), which is the same column the public
 * resolver reads — so the dashboard, the payload and the sitepage agree, the
 * property the old sort_order column gave the connection lane.
 */
class LinkPoolReader
{
    public function __construct(private readonly PoolSectionProvisioner $sections) {}

    /**
     * The owner's links, in pin order.
     *
     * @return list<array{id: string, url: ?string, name: ?string, description: ?string, favicon: null, logo: null}>
     */
    public function cards(User $user): array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return [];
        }

        $section = $this->sections->ensure($site, LinkPoolWriter::POOL);

        return $this->cardsInSection((string) $section->id);
    }

    /**
     * The same cards, for a READ path that must not write. `cards()` provisions
     * the section as a side-effect of reading (PoolSectionProvisioner's
     * on-demand design), which is right for a dashboard GET and wrong for
     * SiteActionsService — that runs on every public profile render, and would
     * insert a page + section row for every site that has never held a link.
     *
     * Returns [] when the section does not exist yet, which is the honest answer:
     * no section means no pins means no links.
     *
     * @return list<array{id: string, url: ?string, name: ?string, description: ?string, favicon: null, logo: null}>
     */
    public function cardsForSite(?Site $site): array
    {
        if (! $site instanceof Site) {
            return [];
        }

        $sectionId = DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', PoolRegistry::sectionKey(LinkPoolWriter::POOL))
            ->value('id');

        return $sectionId === null ? [] : $this->cardsInSection((string) $sectionId);
    }

    /**
     * @return list<array{id: string, url: ?string, name: ?string, description: ?string, favicon: null, logo: null}>
     */
    private function cardsInSection(string $sectionId): array
    {
        return DB::connection('pgsql')->table('site.section_items as si')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->leftJoin('content.f_link as fl', 'fl.item_id', '=', 'i.id')
            ->leftJoin('content.f_text as ft', 'ft.item_id', '=', 'i.id')
            ->where('si.section_id', $sectionId)
            ->where('si.state', SectionItem::STATE_PINNED)
            ->where('i.kind', 'link')
            ->whereNull('i.removed_at')
            ->orderBy('si.sort_key')
            ->get(['i.id', 'i.headline_cache', 'fl.url', 'ft.body'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'url' => $row->url,
                'name' => $row->headline_cache,
                'description' => $row->body,
                // See the class docblock — deliberately not carried onto the pool.
                'favicon' => null,
                'logo' => null,
            ])
            ->all();
    }

    /** True when this item is one of the owner's pinned links. */
    public function owns(User $user, string $itemId): bool
    {
        return collect($this->cards($user))->contains(fn (array $card) => $card['id'] === $itemId);
    }

    /**
     * Remove one link from the page.
     *
     * items.removed_at, not an `excluded` curation row: the owner deleting a link
     * they typed means "this is not mine any more", and the pool's kind_is rule
     * would re-select a merely-unpinned item straight back onto the page. An
     * explicit re-add un-deletes it (LinkPoolWriter::add).
     */
    public function remove(User $user, string $itemId): bool
    {
        if (! $this->owns($user, $itemId)) {
            return false;
        }

        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', (string) $user->id)
            ->update(['removed_at' => now(), 'updated_at' => now()]);

        $this->bust($user);

        return true;
    }

    /** Remove every link. */
    public function removeAll(User $user): void
    {
        foreach ($this->cards($user) as $card) {
            DB::connection('pgsql')->table('content.items')
                ->where('id', $card['id'])
                ->where('user_id', (string) $user->id)
                ->update(['removed_at' => now(), 'updated_at' => now()]);
        }

        $this->bust($user);
    }

    /**
     * Persist the owner's order. Ids not named keep their relative order AFTER
     * the listed ones — the same rule the connection lane's sort_order shuffle
     * followed.
     *
     * @param  list<string>  $itemIds
     */
    public function reorder(User $user, array $itemIds): void
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return;
        }

        $section = $this->sections->ensure($site, LinkPoolWriter::POOL);
        $known = array_column($this->cards($user), 'id');

        $ordered = array_values(array_filter($itemIds, fn (string $id) => in_array($id, $known, true)));
        $rest = array_values(array_diff($known, $ordered));

        $position = 1.0;
        foreach ([...$ordered, ...$rest] as $itemId) {
            SectionItem::query()
                ->where('section_id', $section->id)
                ->where('item_id', $itemId)
                ->update(['sort_key' => $position++]);
        }

        $this->bust($user);
    }

    private function bust(User $user): void
    {
        $site = $user->site;
        if ($site instanceof Site) {
            SiteCacheLanes::bust([(string) $site->id]);
        }
    }
}
