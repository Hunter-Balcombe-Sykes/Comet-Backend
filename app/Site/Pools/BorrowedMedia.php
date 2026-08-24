<?php

namespace App\Site\Pools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Slice 1b D5. A pin promises the owner that a specific item stays where they
 * put it. For a Google Places photo we cannot keep that promise: the photo's
 * resource name is reissued on EVERY Details fetch, so "this item" is a
 * different row a week later, and the underlying photo may leave the place's
 * set entirely.
 *
 * The photo is still shown — the pool's auto half surfaces it through the
 * kind_is(media) rule with no pin required. Only permanence is withheld.
 *
 * The list is an ALLOWLIST of the borrowed, not a denylist of the owned, and
 * that direction is deliberate: a source is poolable unless someone has decided
 * its identity is stable enough to promise. A new borrowed source that nobody
 * remembers to register here would be pinnable and then churn, which is the
 * failure this class exists to prevent — so adding a source that mirrors no
 * bytes means adding it here in the same change.
 */
final class BorrowedMedia
{
    /**
     * Source keys whose media may be displayed but never pinned.
     *
     * EMPTY since 2026-08-18 (owner ruling R6): google_business photo items are
     * now keyed on a stable per-photo id (GoogleBusinessConnector::
     * stablePhotoKey — googleMapsUri / flagContentUri postId, both constant
     * across Details calls; only `photos[].name` rotates), so a pin survives
     * a refetch and D5's reason for refusing it is gone. The class stays as
     * the seam: a future borrowed source with churning identity registers
     * here and is refused again.
     */
    public const BORROWED_SOURCE_KEYS = [];

    public static function isBorrowed(Model $item): bool
    {
        // The allowlist is empty today (R6), and reorder() authorizes up to 200
        // items per drag — a per-item join that can never match is 200 round
        // trips the hot path does not owe anyone.
        if (self::BORROWED_SOURCE_KEYS === []) {
            return false;
        }

        if (($item->getAttribute('kind')) !== 'media') {
            return false;
        }

        return DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as s', 's.id', '=', 'si.source_id')
            ->join('ingest.sources as ing', 'ing.connection_id', '=', 's.connection_id')
            ->where('si.item_id', $item->getAttribute('id'))
            ->whereNull('si.removed_at')
            ->whereIn('ing.source_key', self::BORROWED_SOURCE_KEYS)
            ->exists();
    }
}
