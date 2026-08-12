<?php

namespace App\Services\Content;

use App\Models\Core\Site\Site;
use App\Models\Core\User\Service;
use App\Site\Pools\PoolRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3a §3.4/§3.5: the ONE read for owner-authored (manual) service-kind
 * content items — content.items × content.source_items × content.sources,
 * scoped to the `services` pool's own section so a pin/exclude never fans
 * out across sections (Task 4 review finding m3). `SitepageDataResolverService::
 * buildServicesData()` (the public read) and `UserServiceController` (the
 * dashboard read) both call this instead of each carrying their own copy —
 * two independent copies of the same join is how the two surfaces drift.
 */
class ManualServiceItems
{
    /** Read-only resolve of the site's own 'services' section id — never provisions. */
    public function sectionId(?Site $site): ?string
    {
        if ($site === null) {
            return null;
        }

        return DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', PoolRegistry::sectionKey('services'))
            ->value('id');
    }

    /**
     * @return Collection<int, \stdClass> raw rows: id, headline_cache,
     *                                    removed_at, created_at, updated_at, source_id, sort_key, state
     */
    public function rows(string $userId, ?string $sectionId, bool $includeRemoved = false): Collection
    {
        $query = $this->baseQuery($userId, $sectionId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query
            ->orderByRaw('sec.sort_key ASC NULLS LAST')
            ->orderBy('i.headline_cache')
            ->distinct()
            ->get($this->columns());
    }

    /** Single item, scoped to its owner — the caller decides whether a removed row is reachable. */
    public function find(string $userId, string $itemId, ?string $sectionId, bool $includeRemoved = false): ?object
    {
        $query = $this->baseQuery($userId, $sectionId)->where('i.id', $itemId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query->distinct()->first($this->columns());
    }

    private function baseQuery(string $userId, ?string $sectionId): Builder
    {
        // Task 4 review finding m3: site.section_items is unique on
        // (section_id, item_id), not item_id alone — scope the join by
        // section so an item pinned into a second section can't fan out.
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.section_items as sec', function ($join) use ($sectionId) {
                $join->on('sec.item_id', '=', 'i.id');
                if ($sectionId !== null) {
                    $join->where('sec.section_id', '=', $sectionId);
                } else {
                    // No 'services' section provisioned for this site yet —
                    // nothing can be pinned or excluded, match nothing rather
                    // than fall back to unscoped item_id matching.
                    $join->whereRaw('1 = 0');
                }
            })
            ->where('i.user_id', $userId)
            ->where('i.kind', 'service')
            ->whereNull('si.removed_at')
            ->where('cs.kind', 'manual');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return ['i.id', 'i.headline_cache', 'i.removed_at', 'i.created_at', 'i.updated_at', 'cs.id as source_id', 'sec.sort_key', 'sec.state'];
    }

    /** @return array{descriptions: Collection, durations: Collection, offers: Collection} */
    public function facets(array $itemIds, string $manualSourceId): array
    {
        if ($itemIds === []) {
            return ['descriptions' => collect(), 'durations' => collect(), 'offers' => collect()];
        }

        $descriptions = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $itemIds)
            ->where('source_id', $manualSourceId)
            ->pluck('body', 'item_id');

        $durations = DB::connection('pgsql')->table('content.f_duration')
            ->whereIn('item_id', $itemIds)
            ->where('source_id', $manualSourceId)
            ->pluck('seconds', 'item_id');

        $offers = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $itemIds)
            ->where('source_id', $manualSourceId)
            ->get(['item_id', 'amount_minor', 'currency', 'qualifier'])
            ->keyBy('item_id');

        return compact('descriptions', 'durations', 'offers');
    }

    /**
     * The public payload shape (SitepageDataResolverService::buildServicesData()):
     * live, non-excluded items only, mapped to the seven legacy keys.
     *
     * @return list<array<string, mixed>>
     */
    public function publicList(string $userId, ?Site $site): array
    {
        $sectionId = $this->sectionId($site);
        $rows = $this->rows($userId, $sectionId, includeRemoved: false)
            ->reject(fn ($row) => ($row->state ?? null) === 'excluded')
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows->pluck('id')->all();
        // Every row's source_id is the SAME manual source (idx_content_sources_manual
        // is unique per user) — any row's value identifies it for the facet lookups.
        $facets = $this->facets($itemIds, $rows->first()->source_id);

        return $rows->map(function ($row) use ($facets) {
            [$priceCents, $currencyCode] = $this->priceOf($row, $facets);
            $seconds = $facets['durations'][$row->id] ?? null;

            return [
                'id' => (string) $row->id,
                'title' => (string) ($row->headline_cache ?? ''),
                'description' => $facets['descriptions'][$row->id] ?? null,
                'price_cents' => $priceCents,
                'currency_code' => $currencyCode,
                'duration_minutes' => $seconds === null ? null : (int) ($seconds / 60),
                // Every live category assignment belongs to Fresha (spec
                // §1.1/§2 — owner-authored carries zero), so this reproduces
                // the pre-migration fallback unconditionally. Only honest
                // while ServicePolicy::updateCategory() keeps blocking
                // manual-service category assignment.
                'category' => 'Services',
            ];
        })->values()->all();
    }

    /**
     * The DSAR export shape (DataExportPayloadBuilder::streamServices()):
     * every one of this user's manual/service content items, LIVE or
     * REMOVED — removed_at rows are kept, not filtered, mirroring the
     * pre-cutover site.services stream (a soft-deleted row still surfaces
     * during its 30-day purge grace window; Article 15 covers data still
     * held, not just live data).
     *
     * Field values are raw off the row/facets (dates as stored strings, not
     * re-parsed through Carbon) rather than routed through toServiceModel() —
     * every other section in the builder yields plain DB::table() rows, and
     * going through the Eloquent model here would reformat the date columns
     * to a different string shape than the rest of the export.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(string $userId, ?Site $site): array
    {
        $sectionId = $this->sectionId($site);
        $rows = $this->rows($userId, $sectionId, includeRemoved: true);

        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows->pluck('id')->all();
        $facets = $this->facets($itemIds, $rows->first()->source_id);

        return $rows->map(function ($row) use ($userId, $facets) {
            [$priceCents, $currencyCode] = $this->priceOf($row, $facets);
            $seconds = $facets['durations'][$row->id] ?? null;

            return [
                'id' => (string) $row->id,
                'user_id' => $userId,
                'title' => (string) ($row->headline_cache ?? ''),
                'description' => $facets['descriptions'][$row->id] ?? null,
                'price_cents' => $priceCents,
                'currency_code' => $currencyCode,
                'duration_minutes' => $seconds === null ? null : (int) ($seconds / 60),
                'is_active' => ($row->state ?? null) !== 'excluded',
                // Honest disclosure of what's actually stored: an excluded
                // item's sort_key is genuinely NULL (ManualServiceWriter::
                // exclude()), unlike toServiceModel()'s PHP_INT_MAX stand-in
                // (a UI list-ordering placeholder, not real held data).
                'sort_order' => $row->sort_key !== null ? (int) round((float) $row->sort_key) : null,
                // Owner-authored services carry no legacy provenance.
                'source' => null,
                'is_manual' => false,
                'external_id' => null,
                'deleted_origin' => null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'deleted_at' => $row->removed_at,
                // content.* has no membership concept yet (3b) — mirrors
                // toServiceModel()'s identical empty-categories rule.
                'category_ids' => [],
            ];
        })->values()->all();
    }

    /**
     * Hydrate the ADMIN (ServiceResource) shape: one unpersisted Service
     * model per row, content.* facets copied onto the legacy columns
     * ServiceResource maps to the wire. Shared by UserServiceController and
     * UserCacheService::getDashboardServices() — both serve the same
     * ServiceResource-shaped list, one cached and one not.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<int, Service>
     */
    public function toServiceModels(string $userId, Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $itemIds = $rows->pluck('id')->all();
        $facets = $this->facets($itemIds, $rows->first()->source_id);

        return $rows->map(fn ($row) => $this->hydrate($userId, $row, $facets))->values();
    }

    public function toServiceModel(string $userId, object $row): Service
    {
        return $this->hydrate($userId, $row, $this->facets([$row->id], $row->source_id));
    }

    /** @return array{0: int, 1: string} [price_cents, currency_code] */
    private function priceOf(object $row, array $facets): array
    {
        $offer = $facets['offers']->get($row->id);
        $isFree = $offer !== null && $offer->qualifier === 'free';

        return [
            $offer === null || $isFree ? 0 : (int) $offer->amount_minor,
            // §1.2: a free offer carries no currency (the zero-price rule
            // strips it). 'AUD' is the legacy column's own default.
            $offer === null || $isFree ? 'AUD' : (string) $offer->currency,
        ];
    }

    private function hydrate(string $userId, object $row, array $facets): Service
    {
        [$priceCents, $currencyCode] = $this->priceOf($row, $facets);
        $seconds = $facets['durations'][$row->id] ?? null;

        $service = new Service;
        // Unpersisted: ServiceResource's `$this->resource->exists` check
        // skips the categories eager-load, and the relation is pre-set below
        // anyway — belt and braces, not load-bearing on its own.
        $service->exists = false;
        $service->id = (string) $row->id;
        $service->user_id = $userId;
        $service->title = (string) ($row->headline_cache ?? '');
        $service->description = $facets['descriptions'][$row->id] ?? null;
        $service->price_cents = $priceCents;
        $service->currency_code = $currencyCode;
        $service->duration_minutes = $seconds === null ? null : (int) ($seconds / 60);
        // §3.1: a pool EXCLUDE is the content.* equivalent of is_active=false.
        // Missing curation (a live item never pinned/excluded) defaults
        // visible, matching publicList()'s own "absent state = shown" rule.
        $service->is_active = ($row->state ?? null) !== 'excluded';
        // §NEW-4 (review round 3): a null sort_key (ManualServiceWriter::
        // exclude() nulls it by design for a hidden service) must sort LAST,
        // not first — this transient sort_order is the shared scale
        // UserServiceController's index()/UserCacheService::getDashboardServices()
        // now sort the merged manual+Fresha list by (§NEW-I1), and 0 is the
        // MINIMUM of that scale (reorder()/reorderLayout() number live items
        // from 0), so mapping null to 0 put every hidden service at the
        // HEAD of the list instead of somewhere sane. PHP_INT_MAX is safe
        // here only because this model is never persisted (`exists = false`
        // above) — it never reaches a real INTEGER column.
        $service->sort_order = $row->sort_key !== null ? (int) round((float) $row->sort_key) : PHP_INT_MAX;
        // Owner-authored services carry no legacy provenance.
        $service->source = null;
        $service->is_manual = false;
        $service->external_id = null;
        $service->created_at = $row->created_at !== null ? Carbon::parse($row->created_at) : null;
        $service->updated_at = $row->updated_at !== null ? Carbon::parse($row->updated_at) : null;
        $service->deleted_at = $row->removed_at !== null ? Carbon::parse($row->removed_at) : null;
        // Owner-authored services carry zero live category memberships
        // (spec §1.1/§2) and content.* has no membership concept yet (3b) —
        // pre-set so index()'s grouped branch, which touches ->categories
        // directly, never queries a content-item id against
        // site.service_category_assignments.
        $service->setRelation('categories', collect());

        return $service;
    }
}
