<?php

namespace App\Services\Content;

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

        return $rows->map(fn ($row) => $this->toWireShape($row, $facets))->values()->all();
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
        // $sourceId (this connection's own content source) and excluding a
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
        return DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.collections as col', 'col.id', '=', 'ci.collection_id')
            ->whereIn('ci.item_id', $itemIds)
            ->where('ci.source_id', $sourceId)
            ->whereNull('col.removed_at')
            ->orderBy('ci.position')
            ->get(['ci.item_id', 'col.label'])
            ->groupBy('item_id')
            ->map(fn (Collection $rows) => $rows->first()->label);
    }

    /** @return array<string, mixed> */
    private function toWireShape(object $row, array $facets): array
    {
        $offer = $facets['offers']->get($row->id);
        $amountMinor = $offer !== null && $offer->amount_minor !== null ? (int) $offer->amount_minor : null;
        $qualifier = $offer->qualifier ?? null;
        $seconds = $facets['durations'][$row->id] ?? null;

        return [
            'name' => (string) ($row->headline_cache ?? ''),
            'price' => $this->displayPrice($qualifier, $amountMinor),
            'category' => $facets['categories'][$row->id] ?? null,
            'currency' => $offer->currency ?? null,
            'duration' => $this->displayDuration($seconds === null ? null : (int) $seconds),
            'serviceId' => (string) $row->record_key,
            'priceValue' => $amountMinor === null ? null : round($amountMinor / 100, 2),
            'description' => $facets['descriptions'][$row->id] ?? null,
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
}
