<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * The kind-agnostic half of slice 3a's manual write lane, extracted in slice 7
 * so menus could reuse it: landing an item, curating it into a pool section,
 * removing and restoring it, and the slug bookkeeping either implies.
 *
 * NOTHING here knows what kind it is writing. `freeSlug()`/`remintSlug()` guard
 * on `SLUGGED_KINDS` rather than on a kind literal, and the one pool-specific
 * value — the curation section key — is constructor-pinned by each subclass.
 * The legacy-shape → projection mapping is the ONLY per-kind part, and it lives
 * on the subclass precisely so a menu writer cannot inherit the service one.
 *
 * Every method here is a raw-write seam; `invalidate()` is the only one that
 * fires the three cache lanes (parent spec §4) — callers batch their writes and
 * invalidate once per touched site, not per row.
 */
abstract class ManualPoolWriter
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
        private readonly ContentItemSlugAllocator $slugs,
        // Slice 7 unit A: the ONE pool-specific value in this class. Every
        // other method — the projection write, pin/exclude, removal, slug
        // bookkeeping — is kind-agnostic already (freeSlug guards on
        // SLUGGED_KINDS, not on a kind literal), which is why ManualMenuWriter
        // subclasses this rather than copying 150 lines that would drift.
        private readonly string $pool,
    ) {}

    /** Land one item through the slice-0b manual lane. Idempotent on $coord. */
    public function write(string $userId, string $coord, array $projection): string
    {
        return $this->writer->writeManualItem($userId, $coord, $projection);
    }

    /**
     * Owner ordering lives in the CURATION half (spec §3.3): a pin, not a
     * new ordering operator. Also protects the row — mergeInto()'s
     * hasCuration check reads site.section_items, so a pinned item cannot be
     * hard-deleted by a merge (parent §8.3).
     */
    public function pin(Site $site, string $itemId, float $sortKey): void
    {
        $row = $this->curationRow($site, $itemId);
        $row->state = SectionItem::STATE_PINNED;
        $row->sort_key = $sortKey;
        $row->save();
    }

    /**
     * A live-but-inactive ("hidden") owner service. No sort_key — leaving a
     * stale one behind would resurface it in pin order the moment a later
     * pin() write forgets to clear excluded state first.
     */
    public function exclude(Site $site, string $itemId): void
    {
        $row = $this->curationRow($site, $itemId);
        $row->state = SectionItem::STATE_EXCLUDED;
        $row->sort_key = null;
        $row->save();
    }

    private function curationRow(Site $site, string $itemId): SectionItem
    {
        $section = $this->sections->ensure($site, $this->pool);

        $row = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->first() ?? new SectionItem;

        $row->section_id = (string) $section->id;
        $row->item_id = $itemId;
        if (! $row->exists) {
            $row->created_at = now();
        }

        return $row;
    }

    /**
     * items.removed_at ONLY — never source_items.removed_at, which is
     * cleared on reappearance and would resurrect a service its owner
     * deleted. Idempotent: a row already removed keeps its original
     * timestamp. $removedAt is untyped on purpose — the backfill passes a
     * raw string straight off a `site.services` query-builder row, the live
     * controller path passes nothing (defaults to now()).
     */
    public function markRemoved(string $itemId, mixed $removedAt = null): void
    {
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->whereNull('removed_at')
            ->update(['removed_at' => $removedAt ?? now(), 'updated_at' => now()]);

        $this->freeSlug($itemId);
    }

    /**
     * Clears items.removed_at. Spec §3.5: legitimate ONLY from the explicit
     * restore endpoint — the one-way rule that stops a re-appearing scrape
     * from resurrecting a user-deleted row lives in ProjectionWriter and is
     * untouched by this method.
     */
    public function clearRemoved(string $itemId): void
    {
        DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->update(['removed_at' => null, 'updated_at' => now()]);

        $this->remintSlug($itemId);
    }

    /**
     * Free a removed item's public URL slug (slice 4 §3.4).
     *
     * `idx_item_slugs_unique (user_id, slug)` is NON-partial, so even a retired
     * row squats its name — a removed item that keeps its slug holds that URL
     * forever, and the next dish of the same name is handed `iced-latte-2`
     * permanently. This is the invariant `site.item_slugs` never had (no FK to
     * `site.menu_items`, so a non-Eloquent delete stranded its rows); it lives
     * on the side that survives slice 7's teardown.
     *
     * Reached ONLY from items.removed_at — the owner's delete.
     * source_items.removed_at is projection-level absence, cleared on
     * reappearance, and freeing the name there would break the URL the moment a
     * vendor re-lists the dish.
     *
     * Kind-guarded so the five service call sites pay nothing: services are not
     * in SLUGGED_KINDS and hold no slug rows to delete.
     */
    private function freeSlug(string $itemId): void
    {
        $row = DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->first(['user_id', 'kind']);

        if ($row === null || ! in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)) {
            return;
        }

        $this->slugs->forget((string) $row->user_id, $itemId);
    }

    /**
     * The mirror of freeSlug(): a restored item gets its permalink back.
     *
     * Without this, freeing on removal would leave the explicit restore
     * endpoint returning an item that serves a null slug and resolves only by
     * raw id. The allocator's rename-back arm reactivates the original slug
     * where it survives, so a delete-then-restore round trip is URL-stable
     * unless someone else claimed the name in between.
     */
    private function remintSlug(string $itemId): void
    {
        $row = DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)
            ->first(['user_id', 'kind', 'headline_cache']);

        if ($row === null || ! in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)) {
            return;
        }

        // An unheadlined item would slug to `item-<6 hex>`, which is not a
        // human-readable URL and would then squat that name — the same reason
        // content:backfill-item-slugs skips them.
        $headline = trim((string) ($row->headline_cache ?? ''));
        if ($headline === '') {
            return;
        }

        try {
            $this->slugs->ensureCurrent((string) $row->user_id, $itemId, $headline);
        } catch (\Throwable $e) {
            // Best-effort, matching refreshItemCaches()' own mint: slug
            // bookkeeping must never fail a restore the owner asked for.
            report($e);
        }
    }

    /** The coord an existing item was landed under, on this user's manual source. */
    public function coordFor(string $userId, string $itemId): ?string
    {
        return DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('cs.kind', 'manual')
            ->where('si.item_id', $itemId)
            ->value('si.coord');
    }

    /**
     * Raw-write seam — all three lanes per touched site (parent spec §4).
     * writeManualItem() already bumps build state per item; updated_at and
     * the edge purge are the two lanes it deliberately does not own.
     *
     * @param  list<string>  $siteIds
     */
    public function invalidate(array $siteIds): void
    {
        // The lanes themselves live on SiteCacheLanes so ProjectionWriter's own
        // connector path can fire the same three without depending on this
        // class (which already depends on it). This stays as the named seam
        // callers and the architecture guard reference.
        SiteCacheLanes::bust($siteIds);
    }
}
