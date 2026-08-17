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
 * at least one non-removed source_item whose source is the user's manual
 * source or a connection that is present and active. Applied to the rule
 * candidates (auto half), the pinned half and the library in PoolResolver.
 */
final class LiveSourceScope
{
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
                    // Any source_item, retired or not: a source that stopped
                    // listing an item is absence folding's business, not this
                    // scope's — only the CONNECTION's liveness hides here.
                    ->where(function (Builder $s) {
                        $s->where('lsrc.kind', 'manual')
                            ->orWhere(function (Builder $c) {
                                $c->whereNotNull('lpc.id')
                                    ->whereNull('lpc.deleted_at')
                                    ->where('lpc.is_active', true);
                            });
                    });
            });
        });
    }
}
