<?php

namespace App\Routing;

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;

/**
 * The two exits from the hidden pre-scrape state (A.3, decision 5).
 *
 * A hidden connection has already ingested — the library holds its items —
 * but has never touched a consumer-facing surface. Reveal is the accept:
 * flip visibility and let the observer run the effects the hidden create
 * skipped (KV bump, edge purge, site touch, identity fold, menu fetch,
 * Instagram auto — each of its gates treats the visibility flip as the
 * connect moment). Discard is the dismissal: delete the row and the
 * ingested items nothing else vouches for.
 */
class HiddenConnections
{
    public function reveal(IntegrationConnection $connection): void
    {
        if (! $connection->isHidden()) {
            return;
        }

        $connection->forceFill(['visibility' => IntegrationConnection::VISIBILITY_VISIBLE])->save();
    }

    public function discard(IntegrationConnection $connection): void
    {
        if (! $connection->isHidden()) {
            return;
        }

        DB::transaction(function () use ($connection) {
            // Items whose ONLY live source is this connection and that carry
            // no pin are deleted outright (U30) — same hard-delete mechanism
            // as ItemMerger (facet rows cascade). A pinned item stays: a pin
            // is the owner's own claim on it, whatever happens to the source.
            // An item another source also feeds stays for the same reason.
            $sourceId = DB::table('content.sources')
                ->where('connection_id', $connection->id)
                ->where('kind', 'connection')
                ->value('id');

            if ($sourceId !== null) {
                $itemIds = DB::table('content.source_items as si')
                    ->where('si.source_id', $sourceId)
                    ->whereNotNull('si.item_id')
                    ->whereNotExists(fn ($q) => $q->from('content.source_items as other')
                        ->whereColumn('other.item_id', 'si.item_id')
                        ->where('other.source_id', '!=', $sourceId)
                        ->whereNull('other.removed_at'))
                    ->whereNotExists(fn ($q) => $q->from('site.section_items as pin')
                        ->whereColumn('pin.item_id', 'si.item_id')
                        ->where('pin.state', 'pinned'))
                    ->pluck('si.item_id');

                if ($itemIds->isNotEmpty()) {
                    DB::table('content.items')
                        ->whereIn('id', $itemIds)
                        ->where('user_id', $connection->user_id)
                        ->delete();
                }
            }

            // Soft delete like every other disconnect — the observer's
            // deleted() hook owns the teardown (ingest source, mirrored
            // media). Nothing public referenced the row, so nothing else
            // needs busting.
            $connection->delete();
        });
    }
}
