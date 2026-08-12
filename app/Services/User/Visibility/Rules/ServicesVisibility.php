<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Services & Pricing is publishable once there is at least one active, non-deleted
// service with a title and a price > 0 — the "valid enough to show publicly" bar.
class ServicesVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'services';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            // Slice 3a §3.4: owner-authored services live in content.* now.
            // The manual-source filter replaces the old whereNull('source') —
            // Fresha projections never flip the public services section on
            // (they render via the booking blob). title/price_cents>0 become
            // a non-empty headline_cache and an offer with amount_minor>0 —
            // same bar, a $0 ('free') service still does not satisfy it,
            // exactly as `price_cents > 0` did not before.
            'has_priced_service' => DB::connection('pgsql')->table('content.items as i')
                ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
                ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                ->join('content.offers as o', function ($join) {
                    $join->on('o.item_id', '=', 'i.id')->on('o.source_id', '=', 'cs.id');
                })
                ->select(DB::raw('1'))
                ->where('i.user_id', $userId)
                ->where('i.kind', 'service')
                ->whereNull('i.removed_at')
                ->whereNull('si.removed_at')
                ->where('cs.kind', 'manual')
                ->whereNotNull('i.headline_cache')
                ->where('i.headline_cache', '!=', '')
                ->where('o.amount_minor', '>', 0),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        return ($context['has_priced_service'] ?? false)
            ? [true, null]
            : [false, 'Services section requires at least 1 service with a title and price.'];
    }
}
