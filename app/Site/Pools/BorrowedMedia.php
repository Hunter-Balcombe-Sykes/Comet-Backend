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
    /** Source keys whose media may be displayed but never pinned. */
    public const BORROWED_SOURCE_KEYS = ['google_business'];

    public static function isBorrowed(Model $item): bool
    {
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
