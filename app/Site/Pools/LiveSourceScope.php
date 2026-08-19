<?php

namespace App\Site\Pools;

use Illuminate\Database\Query\Builder;

/**
 * Disconnect = HIDE, not delete (overnight 2026-08-18 ruling). A platform's
 * items stay in content.* as landed history, but a pool must not keep
 * publishing — or listing in its library — an item whose every source is a
 * connection the owner removed (deleted_at) or that is inactive.
 *
 * An item is LIVE when it has no source_items at all (nothing to judge), or
 * at least one non-retired source_item whose source is the user's manual
 * source or a connection that is present and active. Applied to the rule
 * candidates (auto half), the pinned half and the library in PoolResolver.
 */
final class LiveSourceScope
{
    /**
     * "This SOURCE is live" — the predicate, on its own, for queries that have
     * already joined content.sources and left-joined site.platform_connections.
     *
     * apply() answers an ITEM-level question (does this item have at least one
     * live source?) via whereExists. Several queries need the SOURCE-level half
     * instead, because they are already joined through a source and simply must
     * not read a dead one: statsFor()'s aggregates, the pool's f_link rows, the
     * per-dish offer links. Those three each shipped WITHOUT any liveness filter
     * (#LIFE-2, #LIFE-4) — which is what happens when the only definition lives
     * inside a helper that answers a different question.
     *
     * So the definition lives here once, and apply() consumes it too. A helper
     * that four call sites forget to call is exactly how this family of bugs was
     * born; the fix is not a fourth hand-written where-clause.
     *
     * NOTE `is_active`: a PAUSED connection is treated the same as a removed one,
     * which is the ruling this class shipped with in W2 and is now applied
     * consistently rather than on three surfaces out of six. Whether pause SHOULD
     * hide already-landed content is a live product question — see
     * docs/superpowers/plans/2026-08-19-P1-overnight-DECISIONS.md §3.1. If it is
     * ever answered "no", this is the ONE place to change it.
     *
     * @param  string  $sources  alias/table for content.sources in the caller's query
     * @param  string  $connections  alias/table for the LEFT-joined site.platform_connections
     */
    public static function constrainToLiveSource(Builder $query, string $sources = 'content.sources', string $connections = 'site.platform_connections'): Builder
    {
        return $query->where(function (Builder $s) use ($sources, $connections) {
            // A manual source has no connection to be disconnected, so it is
            // always live. Everything else must point at a connection that is
            // present, not soft-deleted, and not paused.
            $s->where($sources.'.kind', 'manual')
                ->orWhere(function (Builder $c) use ($connections) {
                    $c->whereNotNull($connections.'.id')
                        ->whereNull($connections.'.deleted_at')
                        ->where($connections.'.is_active', true);
                });
        });
    }

    /** @param string $itemsTable the alias/table the outer query selects items from (has `id`) */
    public static function apply(Builder $query, string $itemsTable = 'content.items'): Builder
    {
        return $query->where(function (Builder $w) use ($itemsTable) {
            $w->whereNotExists(function (Builder $none) use ($itemsTable) {
                $none->from('content.source_items as lss_any')
                    ->whereColumn('lss_any.item_id', $itemsTable.'.id');
            })->orWhereExists(function (Builder $live) use ($itemsTable) {
                $live->from('content.source_items as lss')
                    ->join('content.sources as lsrc', 'lsrc.id', '=', 'lss.source_id')
                    ->leftJoin('site.platform_connections as lpc', 'lpc.id', '=', 'lsrc.connection_id')
                    ->whereColumn('lss.item_id', $itemsTable.'.id')
                    // A retired source_item (absence folding: the source's
                    // exhaustive run no longer lists it — a Fresha service
                    // dropped from the menu, the 5 storewide-only services
                    // after narrowing to one stylist) does not keep an item
                    // live either. Until 2026-08-18 W6 this scope ignored
                    // removed_at and the departed rows stayed in the library
                    // and the pool; ProjectionWriter clears removed_at on
                    // reappearance, so this is hide, never delete.
                    ->whereNull('lss.removed_at');
                self::constrainToLiveSource($live, 'lsrc', 'lpc');
            });
        });
    }
}
