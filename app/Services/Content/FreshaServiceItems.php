<?php

namespace App\Services\Content;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slice 3b Task 12: the ONE read of Fresha-sourced service-kind content
 * items -- content.items x content.source_items x content.sources, scoped
 * to `content.sources.kind = 'connection'`. Mirrors `ManualServiceItems`'
 * structure (slice 3a) but sits on the OTHER side of the two-surface rule:
 * that class scopes to `kind = 'manual'`, this one to `kind = 'connection'`
 * -- the split is structural (two disjoint source kinds), not a runtime
 * filter either read path could accidentally drop. Nothing today lands a
 * `kind = 'service'` item through a connection source except Fresha, so no
 * further platform filter is needed to keep this booking-only.
 *
 * `descriptions`/`durations`/`offers` are read via `ManualServiceItems::facets()`
 * (already public) rather than a second copy of that join -- the Global
 * Constraints forbid a fourth copy of a 3a collaborator's predicates, and
 * three of that slice's final-review blockers were exactly this pattern.
 * `categories` has no equivalent in `ManualServiceItems` (owner-authored
 * services carry no live category yet) and is this class's own lookup.
 *
 * `FreshaSelectionResource::services()` is the sole consumer: the stored
 * selection blob's `services[]` shape, reproduced from the pool instead of
 * the legacy `site.services` projection.
 */
class FreshaServiceItems
{
    public function __construct(private readonly ManualServiceItems $manualServiceItems) {}

    /**
     * The stored selection blob's services[] shape, reproduced from the
     * pool: name, price, category, currency, duration, serviceId,
     * priceValue, description, hasVariants.
     *
     * @return list<array<string, mixed>>
     */
    public function selectionServices(string $userId): array
    {
        $rows = $this->rows($userId);
        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows->pluck('id')->all();
        // Every row's source_id is the SAME connection source
        // (idx_content_sources_connection is unique per platform_connections
        // row, and a user has at most one live Fresha connection) -- any
        // row's value identifies it for the facet lookups, same pattern
        // ManualServiceItems::publicList() uses for its manual source.
        $sourceId = (string) $rows->first()->source_id;
        $facets = $this->manualServiceItems->facets($itemIds, $sourceId);
        $facets['categories'] = $this->categories($itemIds, $sourceId);
        // D3a promised title/description/duration overrides keep working on a
        // Fresha service; nothing folded them onto the booking blob until now,
        // so an edited service still published the vendor's own text.
        $overrides = $this->overridesFor($itemIds);

        return $rows->map(fn ($row) => $this->toWireShape($row, $facets, $overrides))->values()->all();
    }

    /** @return Collection<int, \stdClass> id, headline_cache, record_key, source_id */
    private function rows(string $userId): Collection
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'service')
            ->whereNull('i.removed_at')
            ->whereNull('si.removed_at')
            ->where('cs.kind', 'connection')
            // I1 hazard (ProjectionWriter.php:118-125's own comment): a
            // single ingest batch writes ONE timestamp across every row it
            // lands, so a first_seen_at tie is the normal case here, not an
            // edge case -- ordering on it alone would let the customer-
            // facing booking menu shuffle order between requests. Same
            // tiebreak ProjectionWriter::resolveItems() uses at :569-570.
            // No ->distinct(), and the reason is NOT "there is no LEFT JOIN
            // here so nothing can fan out" -- that rule is false, and a
            // second review finding rested on it. An INNER JOIN fans out
            // exactly as readily: an item with two live source_items on
            // connection sources yields two rows from this query today.
            //
            // The real argument is that the SELECT LIST already
            // differentiates them. si.record_key and cs.id ride along, so
            // two source_items produce two rows that are genuinely
            // DIFFERENT rows -- a whole-row DISTINCT would not collapse
            // them, and si.id in the ORDER BY (which Postgres would then
            // require in the select list) guarantees it could not. DISTINCT
            // would be theatre, not a guard. What actually keeps this
            // one-row-per-service is the data: a user has at most one live
            // Fresha connection, so one connection source, and
            // source_items is unique on (source_id, coord).
            ->orderBy('i.first_seen_at')
            ->orderBy('si.id')
            ->get(['i.id', 'i.headline_cache', 'si.record_key', 'cs.id as source_id']);
    }

    /** @return Collection<string, string> item_id => category label */
    private function categories(array $itemIds, string $sourceId): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        // content.collection_items joined to content.collections, scoped to
        // this connection's own content source PLUS the owner lane (see the
        // precedence note below), and excluding a
        // removed collection (owner deleted the category,
        // ServiceCollections::remove()) -- contributes nothing rather than
        // resurrecting on the booking surface. Deliberately does NOT filter
        // col.kind = 'service_category' or is_user_created = false: every
        // collection reachable through THIS source_id is, in practice,
        // Fresha-derived (Task 5/6/8's writer never attaches a manual/owner
        // collection to a connection source), so the extra filter would be
        // redundant here -- but that is a real absence, not a guard this
        // comment used to (wrongly) claim. Position ascending + first-wins:
        // a service belongs to exactly one category on Fresha's own menu;
        // ties fall to insertion order.
        // The owner lane (source_id IS NULL, written by updateCategory's Fresha
        // branch) outranks the connection lane the projector replaces every
        // run — the same owner-outranks-source precedence ValueResolver
        // applies to values, and the reason the ordering clause puts null
        // source_id first before first-wins takes over.
        return DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.collections as col', 'col.id', '=', 'ci.collection_id')
            ->whereIn('ci.item_id', $itemIds)
            ->where(fn ($q) => $q->whereNull('ci.source_id')->orWhere('ci.source_id', $sourceId))
            ->whereNull('col.removed_at')
            ->orderByRaw('ci.source_id IS NOT NULL')
            ->orderBy('ci.position')
            ->get(['ci.item_id', 'col.label'])
            ->groupBy('item_id')
            ->map(fn (Collection $rows) => $rows->first()->label);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides  overridesFor(), keyed by item id
     * @return array<string, mixed>
     */
    private function toWireShape(object $row, array $facets, array $overrides = []): array
    {
        $offer = $facets['offers']->get($row->id);
        $amountMinor = $offer !== null && $offer->amount_minor !== null ? (int) $offer->amount_minor : null;
        $qualifier = $offer->qualifier ?? null;
        $seconds = $facets['durations'][$row->id] ?? null;

        // ValueResolver precedence 1: the owner's typed value outranks every
        // source. array_key_exists, never isset — a stored null IS an explicit
        // clear, and isset would silently fall back to the vendor's value.
        $itemOverrides = $overrides[(string) $row->id] ?? [];
        $name = array_key_exists('f_text.headline', $itemOverrides)
            ? (string) $itemOverrides['f_text.headline']
            : (string) ($row->headline_cache ?? '');
        $description = array_key_exists('f_text.body', $itemOverrides)
            ? ($itemOverrides['f_text.body'] === null ? null : (string) $itemOverrides['f_text.body'])
            : ($facets['descriptions'][$row->id] ?? null);
        if (array_key_exists('f_duration.seconds', $itemOverrides)) {
            $seconds = $itemOverrides['f_duration.seconds'] === null ? null : (int) $itemOverrides['f_duration.seconds'];
        }

        return [
            'name' => $name,
            'price' => $this->displayPrice($qualifier, $amountMinor),
            'category' => $facets['categories'][$row->id] ?? null,
            'currency' => $offer->currency ?? null,
            'duration' => $this->displayDuration($seconds === null ? null : (int) $seconds),
            'serviceId' => (string) $row->record_key,
            'priceValue' => $amountMinor === null ? null : round($amountMinor / 100, 2),
            'description' => $description,
            // The ingest connector/projector do not capture a variant count
            // today -- only price/duration/description/category are
            // projected (App\Ingest\Projection\FreshaServiceProjector).
            // Honestly false rather than guessed, matching the
            // never-fabricate rule its own offer()/durationSeconds() parsers
            // already follow: an unmodelled fact renders as its safe
            // default, never an invented true.
            'hasVariants' => false,
        ];
    }

    /**
     * qualifier + amount_minor -> the vendor's own display string. The wire
     * has always carried a bare '$' (Fresha emits it and `currency` is null in
     * the stored blob), so this does not invent 'A$' or 'AUD'. Cents render
     * only when non-zero: '$120.00' would be a wire change, '$49.50' would be
     * a data loss if truncated.
     */
    private function displayPrice(?string $qualifier, ?int $amountMinor): ?string
    {
        if ($qualifier === 'free') {
            return 'free';
        }
        if ($amountMinor === null) {
            return null;
        }

        $amount = $amountMinor % 100 === 0
            ? (string) intdiv($amountMinor, 100)
            : number_format($amountMinor / 100, 2, '.', '');

        return ($qualifier === 'from' ? 'from $' : '$').$amount;
    }

    /** seconds -> "1h 30min" / "45min" / "2h"; null passthrough for an unparsed/missing duration. */
    private function displayDuration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $minutes = intdiv($seconds, 60);
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($hours > 0 && $remainder > 0) {
            return "{$hours}h {$remainder}min";
        }

        return $hours > 0 ? "{$hours}h" : "{$remainder}min";
    }

    /**
     * The management-surface query: the booking read's joins plus the services
     * section's curation row (sort_key carries the shared ordering scale, spec
     * §3.4). Section-scoped exactly as ManualServiceItems::baseQuery() scopes
     * its join — an item pinned into a second section must not fan out.
     */
    private function managementQuery(string $userId, ?string $sectionId, bool $liveOnly = true): Builder
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.section_items as sec', function ($join) use ($sectionId) {
                $join->on('sec.item_id', '=', 'i.id');
                if ($sectionId !== null) {
                    $join->where('sec.section_id', '=', $sectionId);
                } else {
                    // No services section provisioned yet — nothing can be
                    // pinned or excluded, so match nothing rather than fall
                    // back to unscoped item_id matching.
                    $join->whereRaw('1 = 0');
                }
            })
            ->where('i.user_id', $userId)
            ->where('i.kind', 'service')
            ->where('cs.kind', 'connection')
            ->when($liveOnly, fn ($q) => $q->whereNull('si.removed_at'));
    }

    /**
     * `si.id` rides along ALIASED and `i.first_seen_at` is selected because
     * managementRows() both DISTINCTs and orders by them: Postgres rejects a
     * SELECT DISTINCT whose ORDER BY expressions are absent from the select
     * list (42P10) where SQLite accepts it silently. The alias is not cosmetic
     * either — a bare `si.id` would land on the output column `id` and
     * overwrite the item id on every row.
     *
     * @return list<string>
     */
    private function managementColumns(): array
    {
        return ['i.id', 'i.headline_cache', 'i.removed_at', 'i.created_at', 'i.updated_at',
            'i.first_seen_at', 'cs.id as source_id', 'si.id as source_item_id', 'si.record_key',
            'sec.sort_key', 'sec.state'];
    }

    /** @return Collection<int, \stdClass> */
    public function managementRows(string $userId, ?string $sectionId, bool $includeRemoved = false): Collection
    {
        $query = $this->managementQuery($userId, $sectionId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query
            ->orderByRaw('sec.sort_key ASC NULLS LAST')
            ->orderBy('i.first_seen_at')
            ->orderBy('si.id')
            ->distinct()
            ->get($this->managementColumns());
    }

    /** Single item, owner-scoped. $liveOnly=false also matches a departed (source_items.removed_at) item — resync's 422 case. */
    public function findRow(string $userId, string $itemId, ?string $sectionId, bool $includeRemoved = false, bool $liveOnly = true): ?object
    {
        $query = $this->managementQuery($userId, $sectionId, $liveOnly)->where('i.id', $itemId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $query->distinct()->first($this->managementColumns());
    }

    /**
     * The stored selection blob's hiddenServiceIds — the hidden state rides
     * the blob (D3a). Same connection scoping FreshaServiceProjector uses.
     *
     * @return list<string>
     */
    public function hiddenServiceIds(string $userId): array
    {
        $payload = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::Fresha->value)
            ->value('payload');
        $hidden = is_array($payload) ? ($payload['selection']['hiddenServiceIds'] ?? null) : null;

        return is_array($hidden) ? array_values(array_filter($hidden, 'is_string')) : [];
    }

    /**
     * A Fresha content item as the legacy-shaped Service model.
     *
     * @param  list<string>|null  $hidden  pass hiddenServiceIds() once per list;
     *                                     null = "not consulting hidden state",
     *                                     which reads active.
     */
    public function toServiceModel(string $userId, \stdClass $row, ?array $hidden = null): Service
    {
        return $this->toServiceModels($userId, collect([$row]), $hidden)->first();
    }

    /**
     * Wraps ManualServiceItems' hydration (which hardcodes the owner-authored
     * answers) and restates the connection lane's own truths: provenance, the
     * vendor id, is_manual (= carries an override), is_active (= not on the
     * blob's hidden list), and the three override-foldable fields.
     *
     * Hydration goes through the PLURAL manual method deliberately: the
     * singular one re-queries three facet tables and the memberships PER ROW,
     * and a 61-service Fresha dashboard is the normal case here.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<string>|null  $hidden
     * @return Collection<int, Service>
     */
    public function toServiceModels(string $userId, Collection $rows, ?array $hidden = null): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $itemIds = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();
        $overrides = $this->overridesFor($itemIds);
        $categories = $this->managementCategories($userId, $itemIds, (string) $rows->first()->source_id);
        $hydrated = $this->manualServiceItems->toServiceModels($userId, $rows)
            ->keyBy(fn (Service $model) => (string) $model->id);

        return $rows->map(function ($row) use ($hydrated, $hidden, $overrides, $categories) {
            $model = $hydrated->get((string) $row->id);
            $model->source = 'fresha';
            $model->external_id = (string) $row->record_key;
            $itemOverrides = $overrides[(string) $row->id] ?? [];
            $model->is_manual = $itemOverrides !== [];
            if (array_key_exists('f_text.headline', $itemOverrides)) {
                $model->title = (string) $itemOverrides['f_text.headline'];
            }
            if (array_key_exists('f_text.body', $itemOverrides)) {
                $model->description = $itemOverrides['f_text.body'] === null ? null : (string) $itemOverrides['f_text.body'];
            }
            if (array_key_exists('f_duration.seconds', $itemOverrides)) {
                $seconds = $itemOverrides['f_duration.seconds'];
                $model->duration_minutes = $seconds === null ? null : (int) ((int) $seconds / 60);
            }
            if ($hidden !== null) {
                $model->is_active = ! in_array((string) $row->record_key, $hidden, true);
            }
            $model->setRelation('categories', collect($categories[(string) $row->id] ?? [])->map(function ($collection) {
                $category = new ServiceCategory;
                $category->exists = false;
                $category->id = (string) $collection->id;
                $category->title = (string) $collection->label;

                return $category;
            })->values());

            return $model;
        })->values();
    }

    /**
     * Override values for the three D3a-supported fields, keyed
     * "facet.column" per item. A stored SQL-null value IS an entry (explicit
     * clear) — array_key_exists, never isset, on the result.
     *
     * @param  list<string>  $itemIds
     * @return array<string, array<string, mixed>>
     */
    private function overridesFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $itemIds)
            ->whereIn(DB::raw("facet || '.' || column_name"), ['f_text.headline', 'f_text.body', 'f_duration.seconds'])
            ->get(['item_id', 'facet', 'column_name', 'value']);
        foreach ($rows as $row) {
            $out[(string) $row->item_id][$row->facet.'.'.$row->column_name] = json_decode((string) $row->value, true);
        }

        return $out;
    }

    /**
     * Management-surface memberships: the owner lane (source_id NULL) WINS
     * over the connection lane the projector replaces every run — same
     * precedence categories() applies to the booking surface.
     *
     * @param  list<string>  $itemIds
     * @return array<string, list<\stdClass>>
     */
    private function managementCategories(string $userId, array $itemIds, string $sourceId): array
    {
        $live = app(ServiceCollections::class)->list($userId)->keyBy(fn ($row) => (string) $row->id);
        if ($live->isEmpty()) {
            return [];
        }

        $memberships = DB::connection('pgsql')->table('content.collection_items')
            ->whereIn('item_id', $itemIds)
            ->where(fn ($q) => $q->whereNull('source_id')->orWhere('source_id', $sourceId))
            ->orderBy('position')
            ->get(['item_id', 'collection_id', 'source_id']);

        $byItem = [];
        foreach ($memberships as $membership) {
            $collection = $live->get((string) $membership->collection_id);
            if ($collection === null) {
                continue;
            }
            $key = (string) $membership->item_id;
            // Owner lane replaces whatever the connection lane contributed.
            if ($membership->source_id === null) {
                $byItem[$key] = ['owner' => true, 'rows' => [...(($byItem[$key]['owner'] ?? false) ? $byItem[$key]['rows'] : []), $collection]];
            } elseif (! ($byItem[$key]['owner'] ?? false)) {
                $byItem[$key] = ['owner' => false, 'rows' => [...($byItem[$key]['rows'] ?? []), $collection]];
            }
        }

        return array_map(fn ($entry) => $entry['rows'], $byItem);
    }
}
