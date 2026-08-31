<?php

namespace App\Site\Pools;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE read of `content.f_review` for a set of review items — one query, one
 * ordering, one answer, handed to everybody who needs it.
 *
 * Before this class the reviews lane read the table twice per resolve.
 * `reviewsOutsidePersonScope()` decided what may be published; `itemPayloads()`
 * built what IS published; both spelled `orderBy(updated_at)` and neither
 * agreed with the other about which row of a two-source review was
 * authoritative. Every guard written on top of that reasoned about a row the
 * visitor was not looking at, and each wave of fixes shipped another blocker.
 * The fix is not a fifth guard: the two readings are collapsed into this one,
 * so admission and publication cannot disagree because there is nothing left
 * for them to disagree about.
 *
 * Callers pass the SAME instance to both — see PoolResolver::hasSelection()
 * and ::itemPayloads(). A second `content.f_review` query in this lane is the
 * regression, and PoolResolverPersonScopeTest counts them.
 *
 * TENANCY: `content.f_review` carries no user_id (it hangs off item_id +
 * source_id), so this read is owner-scoped exactly the way both reads it
 * replaces were — by the id list, which upstream built from the site's own
 * curation and rule candidates. It is not a gate and grants nothing; the
 * gates in reviewsOutsidePersonScope() pin their own tenancy.
 */
final class ReviewFacets
{
    /** @var array<string, ReviewFacetSet> */
    private readonly array $sets;

    /** @param array<string, ReviewFacetSet> $sets */
    private function __construct(array $sets)
    {
        $this->sets = $sets;
    }

    /**
     * @param  list<string>  $reviewIds
     */
    public static function forItems(array $reviewIds): self
    {
        if ($reviewIds === []) {
            return new self([]);
        }

        $sets = DB::connection('pgsql')->table('content.f_review')
            ->whereIn('item_id', $reviewIds)
            // A TOTAL order, which `orderBy(updated_at)` alone never was. The
            // PK is (item_id, source_id): a deduped review's two rows are
            // written by two connectors in one ingest window and land on the
            // same second, and "freshest wins" then left the winner to whatever
            // the engine handed back — the published attribution flipping
            // between two requests for one page, the same defect class the
            // stats badge tie-break was made total for. source_id is arbitrary
            // and that is the point: one answer forever beats a stable-looking
            // one. LAST is authoritative, which is what keyBy() used to keep.
            ->orderBy('updated_at')
            ->orderBy('source_id')
            // The union of what the two old reads selected. `staff_name` and
            // `text` answer the scope; the rest render. One column list means
            // the scope can never be shown a row the payload was not built
            // from.
            ->get([
                'item_id', 'source_id', 'author_name', 'author_photo_url', 'author_uri',
                'rating', 'text', 'reviewed_at', 'staff_name', 'updated_at',
            ])
            ->groupBy('item_id')
            ->map(static fn (Collection $rows): ReviewFacetSet => ReviewFacetSet::of($rows->values()->all()))
            ->all();

        return new self($sets);
    }

    /**
     * An item with no facet row gets an EMPTY set rather than null: "this
     * review has nothing that can name anyone" is an answer the scope has to
     * be able to act on (it suppresses), not a branch every caller re-derives.
     */
    public function for(string $itemId): ReviewFacetSet
    {
        return $this->sets[$itemId] ?? ReviewFacetSet::of([]);
    }
}
