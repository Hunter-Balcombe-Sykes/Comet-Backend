<?php

namespace App\Services\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3a §3.1/§3.5: the ONE place that turns a legacy-shaped service (a
 * `site.services` row, or a dashboard request payload) into a `content.*`
 * projection plus pool curation state. Shared by `ServiceBackfiller` (the
 * one-off migration) and `UserServiceController` (the live write cutover) so
 * the two write paths cannot diverge — a backfiller and a controller writing
 * the same rows through two independent copies of this mapping is exactly
 * the class of bug slice 2 shipped: `removeEvent()` wrote a lane the pool
 * never read, so every hide silently failed until it was caught.
 *
 * Every method here is a raw-write seam; `invalidate()` is the only one that
 * fires the three cache lanes (parent spec §4) — callers batch their writes
 * and invalidate once per touched site, not per row.
 */
class ManualServiceWriter
{
    public function __construct(
        private readonly ProjectionWriter $writer,
        private readonly PoolSectionProvisioner $sections,
    ) {}

    /** Land one item through the slice-0b manual lane. Idempotent on $coord. */
    public function write(string $userId, string $coord, array $projection): string
    {
        return $this->writer->writeManualItem($userId, $coord, $projection);
    }

    /**
     * The legacy-shape → content.* projection mapping (spec §3.1's table).
     * Accepts anything with the legacy service columns as public properties
     * (a `site.services` row from the backfill, or a plain object assembled
     * from a dashboard request) — same mapping either way.
     *
     * @param  list<string>  $forceFacets  B1: field names ('description',
     *                                     'duration_minutes') the CALLER is
     *                                     actively setting THIS request —
     *                                     UserServiceController::update()
     *                                     passes the PATCH payload's own
     *                                     keys. A field in this list writes
     *                                     its facet even when the resolved
     *                                     value is null, so
     *                                     upsertSingletonFacet() (which only
     *                                     touches columns present in its
     *                                     input) actually clears a
     *                                     previously-set value instead of
     *                                     leaving it behind. A field NOT in
     *                                     this list with a null value omits
     *                                     the facet entirely — required so a
     *                                     brand-new create/backfill (nothing
     *                                     to clear, nothing ever forced)
     *                                     doesn't grow a facet row it never
     *                                     had (ServiceBackfillerTest 'omits
     *                                     duration and body rows when the
     *                                     legacy columns are null'). Empty
     *                                     by default — store()/
     *                                     ServiceBackfiller never need it,
     *                                     since a new item has nothing to
     *                                     clear either way.
     * @return array<string, mixed>
     */
    public function projectionFor(object $service, array $forceFacets = []): array
    {
        $title = trim((string) ($service->title ?? ''));
        $description = trim((string) ($service->description ?? ''));

        // 'headline' stays filtered: title is `required` on every write path
        // (never legitimately blank), so an empty title omits the key rather
        // than risk clobbering a real headline with '' turned to null.
        $projection = [
            'kind' => 'service',
            'headline' => $title,
            'facets' => ['f_text' => array_filter([
                'headline' => $title !== '' ? $title : null,
            ], static fn ($v) => $v !== null)],
        ];

        if ($description !== '' || in_array('description', $forceFacets, true)) {
            $projection['facets']['f_text']['body'] = $description !== '' ? $description : null;
        }

        if ($service->duration_minutes !== null || in_array('duration_minutes', $forceFacets, true)) {
            $projection['facets']['f_duration'] = [
                'seconds' => $service->duration_minutes !== null ? ((int) $service->duration_minutes) * 60 : null,
            ];
        }

        if ($service->price_cents !== null) {
            $cents = (int) $service->price_cents;
            $projection['offers'] = [[
                // §1.2: a HAND-ENTERED zero means free. Scraped zeros are 3b's
                // problem and must not be routed through this mapper.
                'qualifier' => $cents === 0 ? 'free' : 'exact',
                'amount_minor' => $cents === 0 ? null : $cents,
                'currency' => $cents === 0 ? null : (string) $service->currency_code,
            ]];
        }

        return $projection;
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
        $section = $this->sections->ensure($site, 'services');

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
        foreach (array_unique($siteIds) as $siteId) {
            BuildState::bump($siteId);
            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);
            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');
            if ($subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
}
