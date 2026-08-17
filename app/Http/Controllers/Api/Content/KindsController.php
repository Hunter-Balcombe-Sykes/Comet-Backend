<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Services\Content\FacetRegistry;
use App\Services\Content\KindRegistry;
use App\Site\Pools\PoolRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The content schema, as the dashboard's field renderer consumes it.
 *
 * {@see KindRegistry::describe()} has always computed this shape — its own
 * docblock calls `columns` "the shape the kinds endpoint and the field
 * renderer both consume" — but no route ever exposed it, so every client had
 * to hand-copy the facet/column table and drift from it. This is that route.
 *
 * It is a pure projection of three registries. There is no user state in the
 * response at all: what a KIND can carry does not depend on who is asking,
 * and the per-account gating lives on the pool endpoints. That is why this is
 * a plain read with no ownership resolution.
 *
 * Each kind additionally carries the `pool` it belongs to, and the pool's own
 * curation permissions, so a client can answer "may I pin / reorder / hand-add
 * here?" without a second call and without mirroring PoolRegistry too. A
 * poolless kind (`document`, and `channel`/`article` before their retirement)
 * reports `pool: null` and false for all three — it can never be curated.
 */
class KindsController extends ApiController
{
    /** GET /api/content/kinds */
    public function __invoke(): JsonResponse
    {
        $kinds = array_map(
            function (array $described): array {
                $pool = PoolRegistry::poolForKind($described['kind']);

                return [
                    ...$described,
                    'pool' => $pool,
                    // Pool-level curation permissions. `editable`/`mayDelete`
                    // above are the KIND's; these are the POOL's, and the two
                    // genuinely differ — a review is uneditable because of its
                    // profile, while the reviews pool separately refuses pins
                    // and hand-adds.
                    'poolAllowsPin' => $pool !== null && PoolRegistry::allowsPin($pool),
                    'poolAllowsManualAdd' => $pool !== null && PoolRegistry::allowsManualAdd($pool),
                ];
            },
            KindRegistry::all()
        );

        return $this->success([
            'kinds' => $kinds,
            // The flat facet→column→type table, sent once rather than
            // re-derived from every kind's `columns`. A renderer keyed on
            // (facet, column) reads this; a renderer keyed on kind reads the
            // list above. Both are the same source.
            'facets' => FacetRegistry::facets(),
            'columns' => FacetRegistry::columnsForFacets(FacetRegistry::facets()),
        ]);
    }
}
