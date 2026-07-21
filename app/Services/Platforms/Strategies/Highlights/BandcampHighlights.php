<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\Strategies\Contracts\PreparesHighlightItems;

// Bandcamp picker: up to 15 releases; up to 5 curated highlights, each
// price-enriched (bounded concurrent fetch); the "Most recent" tile refreshes
// (price-enriched) on every save. Moved verbatim from BandcampController.
//
// W3 / LIFE-21: pricing used to happen in apply() — TWICE, once for the
// latest tile and once for the highlights — and apply() runs inside
// GenericPlatformController::highlights()'s per-user connection lock. A lock
// is a deliberate bottleneck; holding one across a vendor fetch lets a
// stranger's latency decide how long that bottleneck lasts. prepare()
// (PreparesHighlightItems) now does both fetches as ONE pooled round OUTSIDE
// the lock; apply() only assembles the already-priced items.
class BandcampHighlights implements HighlightsStrategy, PreparesHighlightItems
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        return FeedPayload::fromArray($payload)->url;
    }

    public function recentItems(string $identity): ?array
    {
        $profile = $this->scraper->fetchProfile($identity);
        if ($profile === null) {
            return null;
        }

        return array_slice($profile['items'], 0, 15);
    }

    /**
     * Resolve chosen ids to their INDICES in $items, filtering unresolvable
     * ids first and only then capping at MAX_HIGHLIGHTS — matching the
     * original apply()'s ->filter()->take() order for the case that matters
     * (an unresolvable id must never consume a highlight slot). This is the
     * one place that resolution lives now, shared by prepare() (which needs
     * indices to build a pricing subset) and apply() (which needs the same
     * set to build the final highlights list), so the two can never diverge.
     *
     * UNLIKE the original, this DEDUPES: a repeated id collapses to its one
     * index instead of counting twice — deliberate, a duplicate consuming
     * two of a user's five highlight slots is a bug, not a feature worth
     * preserving. Dedupe happens BEFORE the MAX_HIGHLIGHTS cap is applied, so
     * the cap counts DISTINCT resolvable picks — a duplicate can never crowd
     * out a later distinct one, whatever position it sits at in the request.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  list<string>  $chosenIds
     * @return array<int,true> item indices, first-occurrence chosenIds order, deduped, capped at MAX_HIGHLIGHTS
     */
    private function chosenIndices(array $items, array $chosenIds): array
    {
        $indexById = [];
        foreach ($items as $i => $item) {
            if (is_string($item['itemId'] ?? null)) {
                $indexById[$item['itemId']] = $i;
            }
        }

        // Assigning an existing array key updates it in place without moving
        // its position, so this both dedupes AND preserves first-occurrence
        // order in one pass — dedupe happens here, before the cap below.
        $resolved = [];
        foreach ($chosenIds as $id) {
            if (isset($indexById[$id])) {
                $resolved[$indexById[$id]] = true;
            }
        }

        return array_slice($resolved, 0, self::MAX_HIGHLIGHTS, true);
    }

    /**
     * Vendor I/O, OUTSIDE the per-user connection lock (PreparesHighlightItems).
     * Prices the curated picks (chosenIndices) PLUS index 0 (the "Most recent"
     * tile, priced unconditionally — a release published since connect would
     * otherwise leave `latest` stale) in ONE pooled fetchMany round, capped at
     * 6 URLs (5 highlights + 1 latest tile).
     */
    public function prepare(array $items, array $chosenIds): array
    {
        $indices = $this->chosenIndices($items, $chosenIds);
        if (isset($items[0])) {
            $indices[0] = true;
        }

        // array_intersect_key preserves $items' original keys — enrichPrices()
        // writes enriched entries back onto those same keys, so a sparse-keyed
        // subset merges onto the right indices regardless of iteration order.
        $subset = array_intersect_key($items, $indices);
        if ($subset === []) {
            return $items;
        }

        foreach ($this->scraper->enrichPrices($subset, count($subset)) as $i => $enriched) {
            $items[$i] = $enriched;
        }

        return $items;
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Refresh the "Most recent" tile too — a release published since
        // connect would otherwise leave `latest` stale while only the
        // highlights updated. Already price-enriched by prepare(); apply()
        // itself does no vendor I/O (LIFE-21).
        if (isset($items[0])) {
            $selection = $this->refreshLatestTile($selection, $items[0], self::FLAT_FIELDS);
        }

        // Same resolved-then-take selection prepare() priced (chosenIndices),
        // so the highlights and their prices can never diverge. array_keys()
        // preserves chosenIds order — highlight curation order is user-facing
        // and must survive this rewrite, unlike prepare()'s pricing subset,
        // where order is irrelevant.
        $indices = $this->chosenIndices($items, $chosenIds);
        $selection['highlights'] = array_map(
            fn (int $i) => $items[$i],
            array_keys($indices),
        );

        // Keep the picker's private snapshot (HighlightsPicker::SNAPSHOT_KEY)
        // warm with the items this save was handed, so the picker stays fast
        // even between scheduled refreshes. NOT re-priced beyond what
        // prepare() already did — the curated highlights above are what pay
        // that cost.
        $selection['recent'] = array_slice($items, 0, 15);

        return $selection;
    }

    public function requestField(): string
    {
        return 'itemIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'itemIds' => ['present', 'array', 'max:24'],
            'itemIds.*' => ['string', 'max:50'],
        ];
    }

    public function responseKey(): string
    {
        return 'items';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a Bandcamp page first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent releases.';
    }
}
