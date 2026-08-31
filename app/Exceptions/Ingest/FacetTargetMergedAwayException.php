<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Reported to Nightwatch whenever ProjectionWriter::writeFacetsRetargeting() has to follow
// content.item_merges to find where a facet write's item went (#W1-DINT-8 / #W2-LIFE-5).
// The identity lock is released at the resolve's COMMIT, so the facet write that follows runs
// unprotected: a concurrent resolve can merge the just-resolved item away and hard-delete it
// between the two. Log::warning would be a breadcrumb and does not reach Nightwatch (CLAUDE.md);
// report() is the house pattern for exactly this — see MergeFoldMediaDroppedException.
//
// RECOVERED by construction: the facets landed on the merge survivor, and content.source_items
// was already repointed at that survivor inside the merging transaction, so nothing is left
// dangling. A SUSTAINED run of these is not data loss — it says the identity lock's boundary is
// being crossed routinely and the lock (not the retarget) is what wants re-sizing.
class FacetTargetMergedAwayException extends RuntimeException
{
    /**
     * @param  array<string, string>  $before  coord => the item the resolve returned
     * @param  array<string, string>  $after  coord => the item the facets were actually written to
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $before,
        public readonly array $after,
    ) {
        $moved = [];
        foreach ($before as $coord => $itemId) {
            $survivor = $after[$coord] ?? $itemId;
            if ($survivor !== $itemId) {
                $moved[] = $itemId.' -> '.$survivor;
            }
        }

        parent::__construct(sprintf(
            'Facet write retargeted through content.item_merges for user %s: %s.',
            $userId,
            $moved === [] ? '(nothing moved)' : implode(', ', array_unique($moved)),
        ));
    }
}
